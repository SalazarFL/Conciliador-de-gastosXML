<?php
/**
 * Worker de los lotes del modo Descargas de Correo — termina las cargas SIN el
 * módulo abierto, disparado por la Tarea Programada de Windows
 * "XMLConcilia_ProcesarLotes".
 *
 * Por qué existe: el bucle que hacía avanzar un lote vivía solo en el
 * JavaScript de app/views/correo/index.php. Cerrar la pestaña —o dejarla en
 * segundo plano, donde el navegador limita setTimeout a una vez por minuto—
 * congelaba el lote en 'ejecutando' indefinidamente. El lote #10 tardó cinco
 * días en llegar al 98% en cinco ráfagas sueltas, una por cada visita a la
 * página.
 *
 * Encadena tandas de CorreoController::procesarTandaLote() reutilizando UNA
 * sola conexión IMAP para toda la corrida: el saludo TLS cuesta 1-3 s y por
 * HTTP se pagaba en cada viaje, por solo 6 correos. Toma los lotes más viejos
 * primero y solo los deja cuando quedan al día o se agota el tope.
 *
 * Uso manual:  php cli\procesar_lotes.php [tope_segundos] [lote_id]
 *   tope_segundos  tope total de la corrida (30..3600, por defecto 240)
 *   lote_id        procesar solo ese lote; si se omite, todos los que estén
 *                  en 'pendiente' o 'ejecutando'
 */

// Solo por línea de comandos: nunca por navegador
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo corre por línea de comandos.\n");
}

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
date_default_timezone_set('America/Mexico_City');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/core/Model.php';
require_once $ROOT . '/app/core/Controller.php';
require_once $ROOT . '/app/helpers/MailFetcher.php';
require_once $ROOT . '/app/controllers/CorreoController.php';
require_once $ROOT . '/app/models/CorreoLote.php';
require_once $ROOT . '/app/models/CorreoCuenta.php';

$topeSegundos = isset($argv[1]) ? max(30, min(3600, (int) $argv[1])) : 240;
$loteUnico    = isset($argv[2]) ? (int) $argv[2] : 0;

// Tanda amplia: sin el timeout de Apache encima no hay razón para viajes
// cortos, y cada tanda amortiza mejor la conexión ya abierta.
$porTanda        = 10;
$presupuestoTanda = 60;

$dirCorreo  = MailFetcher::storagePath();
$rutaLock   = CorreoLote::rutaLock(); // el mismo que toma el latido del navegador
$rutaLog    = $dirCorreo . DIRECTORY_SEPARATOR . 'lotes_worker.log';
$rutaEstado = $dirCorreo . DIRECTORY_SEPARATOR . 'lotes_estado.json';

$inicioCorrida = microtime(true);

// Lock propio (no el de la sincronización del índice: son trabajos distintos
// y pueden convivir). Si otra corrida sigue viva, esta se retira en silencio.
$fpLock = @fopen($rutaLock, 'c');
if ($fpLock === false || !flock($fpLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Otra corrida de lotes sigue en curso; se omite esta.\n");
    exit(0);
}

/** Escribe una línea con marca de tiempo al log (recortándolo si crece). */
function registrar($rutaLog, $texto)
{
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $texto . "\n";
    @file_put_contents($rutaLog, $linea, FILE_APPEND | LOCK_EX);
    echo $linea;

    if (@filesize($rutaLog) > 262144) {
        $lineas = @file($rutaLog, FILE_IGNORE_NEW_LINES);
        if (is_array($lineas) && count($lineas) > 400) {
            @file_put_contents($rutaLog, implode("\n", array_slice($lineas, -400)) . "\n", LOCK_EX);
        }
    }
}

$resumen = [];
$errorGlobal = null;

try {
    $lotes = new CorreoLote();
    $cuentas = new CorreoCuenta();
    $correo = new CorreoController();

    $pendientes = $lotes->pendientes($loteUnico);

    if (!$pendientes) {
        registrar($rutaLog, $loteUnico > 0
            ? "El lote #{$loteUnico} no está pendiente ni ejecutando; no hay nada que hacer."
            : 'No hay lotes pendientes.');
    } else {
        registrar($rutaLog, 'Inicio: ' . count($pendientes) . ' lote(s), tope ' . $topeSegundos . 's.');
    }

    foreach ($pendientes as $fila) {
        if ((microtime(true) - $inicioCorrida) >= $topeSegundos) {
            break;
        }

        $loteId = (int) $fila['id'];
        $stats = [
            'id' => $loteId, 'procesados_ahora' => 0, 'tandas' => 0,
            'total' => (int) $fila['total_mensajes'], 'estado' => (string) $fila['estado'],
            'completado' => false, 'error' => null,
        ];

        // Una conexión IMAP por lote (cada lote es de una cuenta distinta).
        $config = $cuentas->configPara((int) $fila['cuenta_id']);
        if ($config === null || !MailFetcher::configurado($config)) {
            $stats['error'] = 'La cuenta ' . (int) $fila['cuenta_id'] . ' no tiene credenciales IMAP completas.';
            registrar($rutaLog, "Lote #{$loteId}: " . $stats['error']);
            $resumen[] = $stats;
            continue;
        }

        $fetcher = new MailFetcher($config);
        try {
            while ((microtime(true) - $inicioCorrida) < $topeSegundos) {
                $r = $correo->procesarTandaLote($loteId, $porTanda, $presupuestoTanda, $fetcher);
                $stats['tandas']++;

                if (empty($r['ok'])) {
                    // Fallo de conexión: los items de la tanda ya quedaron
                    // marcados con su incidencia. Se corta este lote y la
                    // siguiente corrida lo retoma.
                    $stats['error'] = (string) $r['message'];
                    break;
                }

                $stats['procesados_ahora'] += (int) $r['procesados_ahora'];
                $estadoLote = (string) ($r['lote']['estado'] ?? '');
                $stats['estado'] = $estadoLote;

                // Sin items en la tanda: o el lote terminó (tomarPendientes ya
                // lo marcó 'completado') o alguien lo pausó/canceló desde la
                // interfaz. En ambos casos no hay más que hacer aquí.
                if ((int) $r['procesados_ahora'] === 0) {
                    $stats['completado'] = $estadoLote === 'completado';
                    break;
                }
            }
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            registrar($rutaLog, "Lote #{$loteId}: ERROR " . $e->getMessage());
        }
        $fetcher->cerrar();

        $actual = $lotes->get($loteId);
        registrar($rutaLog, sprintf(
            'Lote #%d: +%d correos en %d tanda(s); %d/%d; estado=%s%s',
            $loteId,
            $stats['procesados_ahora'],
            $stats['tandas'],
            (int) ($actual['procesados'] ?? 0),
            (int) ($actual['total_mensajes'] ?? 0),
            (string) ($actual['estado'] ?? '?'),
            $stats['error'] ? ' [' . $stats['error'] . ']' : ''
        ));
        $stats['estado'] = (string) ($actual['estado'] ?? $stats['estado']);
        $stats['completado'] = $stats['estado'] === 'completado';
        $resumen[] = $stats;
    }
} catch (Throwable $e) {
    $errorGlobal = $e->getMessage();
    registrar($rutaLog, 'ERROR GLOBAL: ' . $e->getMessage());
}

$duracion = round(microtime(true) - $inicioCorrida, 1);
registrar($rutaLog, 'Fin en ' . $duracion . 's.');

// Estado de la última corrida (mismo formato que sync_estado.json)
@file_put_contents($rutaEstado, json_encode([
    'ultima_corrida' => date('Y-m-d H:i:s'),
    'duracion_seg'   => $duracion,
    'disparo'        => 'tarea_programada',
    'tope_seg'       => $topeSegundos,
    'error'          => $errorGlobal,
    'lotes'          => $resumen,
    'todo_al_dia'    => $errorGlobal === null && !array_filter($resumen, function ($r) {
        return !$r['completado'];
    }),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

flock($fpLock, LOCK_UN);
fclose($fpLock);

exit($errorGlobal === null ? 0 : 1);
