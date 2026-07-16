<?php
/**
 * Sincronización automática del índice de correo — se ejecuta SIN el módulo
 * abierto, disparada por la Tarea Programada de Windows "XMLConcilia_SyncCorreo"
 * (se activa/desactiva desde el ⚙ del módulo Correo).
 *
 * Recorre TODAS las cuentas registradas en round-robin, dándole a cada una
 * tandas cortas (25s) de CorreoSync hasta que todas quedan al día o hasta
 * agotar el tope total de la corrida (por defecto 8 min, para no encimarse
 * con la siguiente ejecución de cada 10 min). Un lock de archivo evita que
 * dos corridas se solapen. Deja el resultado en storage/correo/sync_estado.json
 * (lo lee el ⚙ para mostrar "última actualización") y un registro en
 * storage/correo/sync_auto.log.
 *
 * Uso manual (para probar):  php cli\sync_correo.php [tope_segundos]
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
require_once $ROOT . '/app/helpers/MailFetcher.php';
require_once $ROOT . '/app/helpers/CorreoSync.php';
require_once $ROOT . '/app/models/CorreoCuenta.php';
require_once $ROOT . '/app/models/CorreoIndice.php';

$topeSegundos = isset($argv[1]) ? max(30, min(3600, (int) $argv[1])) : 480; // 8 min por defecto
$presupuestoTanda = 25; // segundos por tanda de una cuenta

$dirCorreo = MailFetcher::storagePath();
$rutaLock   = CorreoSync::rutaLock(); // mismo lock que la sincronización web
$rutaEstado = $dirCorreo . DIRECTORY_SEPARATOR . 'sync_estado.json';
$rutaLog    = $dirCorreo . DIRECTORY_SEPARATOR . 'sync_auto.log';

$inicioCorrida = microtime(true);

// Lock: si otra corrida sigue viva, esta se retira en silencio
$fpLock = @fopen($rutaLock, 'c');
if ($fpLock === false || !flock($fpLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Otra sincronización sigue en curso; se omite esta corrida.\n");
    exit(0);
}

/** Escribe una línea con marca de tiempo al log (recortándolo si crece). */
function registrar($rutaLog, $texto)
{
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $texto . "\n";
    @file_put_contents($rutaLog, $linea, FILE_APPEND | LOCK_EX);
    echo $linea;

    // Recorte: si pasa de ~256 KB, conservar las últimas 400 líneas
    if (@filesize($rutaLog) > 262144) {
        $lineas = @file($rutaLog, FILE_IGNORE_NEW_LINES);
        if (is_array($lineas) && count($lineas) > 400) {
            @file_put_contents($rutaLog, implode("\n", array_slice($lineas, -400)) . "\n", LOCK_EX);
        }
    }
}

$resumenCuentas = [];
$errorGlobal = null;

try {
    $cuentasModel = new CorreoCuenta();
    $cuentasModel->seedDesdeArchivo();
    $cuentas = $cuentasModel->getAll();

    // Preparar la cola de cuentas con configuración válida
    $cola = [];
    foreach ($cuentas as $c) {
        $cfg = $cuentasModel->configPara((int) $c['id']);
        if ($cfg === null || !MailFetcher::configurado($cfg)) {
            $resumenCuentas[(int) $c['id']] = [
                'id' => (int) $c['id'], 'nombre' => (string) $c['nombre'],
                'nuevos' => 0, 'adjuntos' => 0, 'adjuntos_pendientes' => null,
                'completado' => true, 'error' => 'Cuenta sin credenciales completas',
            ];
            continue;
        }

        $cola[(int) $c['id']] = [
            'config' => $cfg,
            'indice' => (new CorreoIndice())->setCuenta((int) $c['id']),
            'stats'  => [
                'id' => (int) $c['id'], 'nombre' => (string) $c['nombre'],
                'nuevos' => 0, 'adjuntos' => 0, 'adjuntos_pendientes' => null,
                'completado' => false, 'error' => null,
            ],
        ];
    }

    if (empty($cola)) {
        registrar($rutaLog, 'No hay cuentas con credenciales para sincronizar.');
    } else {
        registrar($rutaLog, 'Inicio: ' . count($cola) . ' cuenta(s), tope ' . $topeSegundos . 's.');
    }

    // Round-robin: una tanda por cuenta, ciclando hasta que todas queden al
    // día o se agote el tope de la corrida. Reparte el tiempo con justicia
    // aunque una cuenta tenga un rezago enorme de adjuntos.
    while (!empty($cola) && (microtime(true) - $inicioCorrida) < $topeSegundos) {
        foreach ($cola as $id => &$acc) {
            if ((microtime(true) - $inicioCorrida) >= $topeSegundos) {
                break;
            }

            try {
                $stats = CorreoSync::ejecutar($acc['config'], $acc['indice'], $presupuestoTanda);
                $acc['stats']['nuevos'] += (int) ($stats['nuevos'] ?? 0);
                $acc['stats']['adjuntos'] += (int) ($stats['adjuntos'] ?? 0);
                $acc['stats']['adjuntos_pendientes'] = $stats['adjuntos_pendientes'] ?? $acc['stats']['adjuntos_pendientes'];
                $acc['stats']['completado'] = (bool) ($stats['completado'] ?? true);

                if ($acc['stats']['completado']) {
                    // Al día por ahora: sale de la cola de esta corrida
                    $resumenCuentas[$id] = $acc['stats'];
                    unset($cola[$id]);
                }
            } catch (Throwable $e) {
                $acc['stats']['error'] = $e->getMessage();
                $acc['stats']['completado'] = false;
                $resumenCuentas[$id] = $acc['stats'];
                registrar($rutaLog, 'Cuenta "' . $acc['stats']['nombre'] . '": ERROR ' . $e->getMessage());
                unset($cola[$id]);
            }
        }
        unset($acc);
    }

    // Las que quedaron en la cola es porque se agotó el tope (siguen pendientes)
    foreach ($cola as $id => $acc) {
        $resumenCuentas[$id] = $acc['stats'];
    }
} catch (Throwable $e) {
    $errorGlobal = $e->getMessage();
    registrar($rutaLog, 'ERROR GLOBAL: ' . $e->getMessage());
}

$duracion = round(microtime(true) - $inicioCorrida, 1);

// Registro resumido por cuenta
foreach ($resumenCuentas as $r) {
    registrar($rutaLog, sprintf(
        'Cuenta "%s": +%d encabezados, +%d adjuntos, pendientes=%s, %s%s',
        $r['nombre'],
        (int) $r['nuevos'],
        (int) $r['adjuntos'],
        $r['adjuntos_pendientes'] === null ? '?' : (string) (int) $r['adjuntos_pendientes'],
        $r['completado'] ? 'al día' : 'quedan pendientes',
        $r['error'] ? ' [' . $r['error'] . ']' : ''
    ));
}
registrar($rutaLog, 'Fin en ' . $duracion . 's.');

// Estado para el ⚙ (última corrida + detalle por cuenta)
$estado = [
    'ultima_corrida'   => date('Y-m-d H:i:s'),
    'duracion_seg'     => $duracion,
    'disparo'          => 'tarea_programada',
    'tope_seg'         => $topeSegundos,
    'error'            => $errorGlobal,
    'cuentas'          => array_values($resumenCuentas),
    'pendientes_total' => array_sum(array_map(function ($r) {
        return max(0, (int) $r['adjuntos_pendientes']);
    }, $resumenCuentas)),
    'todo_al_dia'      => $errorGlobal === null && !array_filter($resumenCuentas, function ($r) {
        return !$r['completado'];
    }),
];
@file_put_contents($rutaEstado, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

flock($fpLock, LOCK_UN);
fclose($fpLock);

exit($errorGlobal === null ? 0 : 1);
