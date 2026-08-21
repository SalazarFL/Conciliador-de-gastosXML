<?php
/**
 * Sincronización y captura automática de correo — se ejecuta SIN el módulo
 * abierto, disparada por la Tarea Programada de Windows "XMLConcilia_SyncCorreo"
 * (se activa/desactiva desde el ⚙ del módulo Correo).
 *
 * Recorre TODAS las cuentas registradas en round-robin, dándole a cada una
 * tandas cortas (12s) de CorreoSync hasta que todas quedan al día o hasta
 * agotar el tope total de la corrida. El tope por defecto es 4 min: con un
 * rezago grande de adjuntos/CC un tope corto deja el índice trabajando solo
 * una fracción del tiempo y la cola no termina nunca. Solaparse es imposible:
 * el lock de archivo hace que una corrida nueva se retire si la anterior
 * sigue viva. Deja el resultado en storage/correo/sync_estado.json
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
require_once $ROOT . '/app/core/Controller.php';
require_once $ROOT . '/app/helpers/MailFetcher.php';
require_once $ROOT . '/app/helpers/CorreoSync.php';
require_once $ROOT . '/app/helpers/XmlParser.php';
require_once $ROOT . '/app/models/CorreoCuenta.php';
require_once $ROOT . '/app/models/CorreoIndice.php';
require_once $ROOT . '/app/models/CorreoCapturaAutomatica.php';
require_once $ROOT . '/app/models/Sociedad.php';
require_once $ROOT . '/app/controllers/CorreoController.php';
require_once $ROOT . '/app/helpers/OrganizadorDocumentos.php';

$topeSegundos = isset($argv[1]) ? max(30, min(3600, (int) $argv[1])) : 240; // 4 min por defecto
$presupuestoTanda = 12; // segundos por tanda de una cuenta

$dirCorreo = MailFetcher::storagePath();
$rutaLock   = CorreoSync::rutaLock(); // mismo lock que la sincronización web
$rutaEstado = $dirCorreo . DIRECTORY_SEPARATOR . 'sync_estado.json';
$rutaLog    = $dirCorreo . DIRECTORY_SEPARATOR . 'sync_auto.log';

$inicioCorrida = microtime(true);

$configLocal = [];
$rutaConfigLocal = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'config.json';
if (is_file($rutaConfigLocal)) {
    $leida = json_decode((string) file_get_contents($rutaConfigLocal), true);
    $configLocal = is_array($leida) ? $leida : [];
}
$autoCfg = is_array($configLocal['auto_sync'] ?? null) ? $configLocal['auto_sync'] : [];
$capturaActiva = !empty($autoCfg['activo']) && !empty($autoCfg['capturar_nuevos']);
$capturaMax = max(1, min(200, (int) ($autoCfg['max_correos_corrida'] ?? 20)));
$capturaIntentos = max(1, min(10, (int) ($autoCfg['max_intentos'] ?? 3)));
$capturaActivadaEn = trim((string) ($autoCfg['captura_activada_en'] ?? ''));
$capturaCorrida = [
    'activa' => $capturaActiva, 'detectados' => 0, 'procesados' => 0,
    'documentos' => 0, 'sin_documentos' => 0, 'errores' => 0,
];

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
                'cc' => 0, 'cc_pendientes' => null, 'metadatos_pendientes' => null,
                'completado' => true, 'error' => 'Cuenta sin credenciales completas',
            ];
            continue;
        }

        $cfg['captura_automatica'] = $capturaActiva;
        $cfg['captura_activada_en'] = $capturaActivadaEn;

        $cola[(int) $c['id']] = [
            'config' => $cfg,
            'indice' => (new CorreoIndice())->setCuenta((int) $c['id']),
            // Conexión IMAP compartida entre tandas de la misma cuenta: el
            // saludo TLS cuesta 1-3 s y antes se pagaba en cada tanda de 12 s.
            'fetcher' => new MailFetcher($cfg),
            'stats'  => [
                'id' => (int) $c['id'], 'nombre' => (string) $c['nombre'],
                'nuevos' => 0, 'adjuntos' => 0, 'adjuntos_pendientes' => null,
                'cc' => 0, 'cc_pendientes' => null, 'metadatos_pendientes' => null,
                'capturas_detectadas' => 0, 'capturas_procesadas' => 0,
                'documentos_revision' => 0, 'capturas_error' => 0,
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
                $stats = CorreoSync::ejecutar($acc['config'], $acc['indice'], $presupuestoTanda, $acc['fetcher']);
                $acc['stats']['nuevos'] += (int) ($stats['nuevos'] ?? 0);
                $acc['stats']['adjuntos'] += (int) ($stats['adjuntos'] ?? 0);
                $acc['stats']['adjuntos_pendientes'] = $stats['adjuntos_pendientes'] ?? $acc['stats']['adjuntos_pendientes'];
                $acc['stats']['cc'] += (int) ($stats['cc'] ?? 0);
                $acc['stats']['cc_pendientes'] = $stats['cc_pendientes'] ?? $acc['stats']['cc_pendientes'];
                // Correos incompletos, contados una sola vez (adjuntos + CC
                // del mismo mensaje se resuelven en la misma visita).
                $acc['stats']['metadatos_pendientes'] = $stats['metadatos_pendientes'] ?? $acc['stats']['metadatos_pendientes'];
                $acc['stats']['capturas_detectadas'] += (int) ($stats['capturas_detectadas'] ?? 0);
                $capturaCorrida['detectados'] += (int) ($stats['capturas_detectadas'] ?? 0);
                $acc['stats']['completado'] = (bool) ($stats['completado'] ?? true);

                if ($acc['stats']['completado']) {
                    // Al día por ahora: sale de la cola de esta corrida
                    $acc['fetcher']->cerrar();
                    $resumenCuentas[$id] = $acc['stats'];
                    unset($cola[$id]);
                }
            } catch (Throwable $e) {
                $acc['stats']['error'] = $e->getMessage();
                $acc['stats']['completado'] = false;
                $resumenCuentas[$id] = $acc['stats'];
                registrar($rutaLog, 'Cuenta "' . $acc['stats']['nombre'] . '": ERROR ' . $e->getMessage());
                $acc['fetcher']->cerrar();
                unset($cola[$id]);
            }
        }
        unset($acc);
    }

    // Las que quedaron en la cola es porque se agotó el tope (siguen pendientes)
    foreach ($cola as $id => $acc) {
        $acc['fetcher']->cerrar();
        $resumenCuentas[$id] = $acc['stats'];
    }
} catch (Throwable $e) {
    $errorGlobal = $e->getMessage();
    registrar($rutaLog, 'ERROR GLOBAL: ' . $e->getMessage());
}

// Captura: consume solamente la cola de UIDs nuevos que dejó CorreoSync y
// lleva sus comprobantes a correo_bandeja. Deliberadamente no marca el correo
// como importado/procesado ni llama a importar(): esa decisión sigue siendo
// manual desde la Bandeja.
$resumenCapturas = [
    'pendiente' => 0, 'procesando' => 0, 'capturado' => 0,
    'sin_documentos' => 0, 'error' => 0, 'documentos' => 0,
];
if ($capturaActiva && isset($cuentasModel, $cuentas)
    && (microtime(true) - $inicioCorrida) < $topeSegundos) {
    try {
        if (DocumentoArchivo::raizConfigurada() === '') {
            throw new RuntimeException(
                'La captura automática necesita la carpeta compartida de documentos configurada.'
            );
        }

        $capturas = new CorreoCapturaAutomatica();
        $correoController = new CorreoController();
        $sociedadesPorId = [];
        foreach ((new Sociedad())->getAll() as $sociedad) {
            $sociedadesPorId[(int) $sociedad['id']] = $sociedad;
        }

        // Tandas cortas en round-robin: una cuenta con mucho tráfico no
        // monopoliza toda la corrida ni retrasa a los demás buzones.
        $cuentasCaptura = array_values($cuentas);
        $huboTrabajo = true;
        while ($huboTrabajo
            && $capturaCorrida['procesados'] < $capturaMax
            && (microtime(true) - $inicioCorrida) < $topeSegundos) {
            $huboTrabajo = false;

            foreach ($cuentasCaptura as $cuenta) {
                if ($capturaCorrida['procesados'] >= $capturaMax
                    || (microtime(true) - $inicioCorrida) >= $topeSegundos) {
                    break;
                }

                $cuentaId = (int) ($cuenta['id'] ?? 0);
                $cfg = $cuentasModel->configPara($cuentaId);
                if ($cuentaId <= 0 || $cfg === null || !MailFetcher::configurado($cfg)) {
                    continue;
                }

                $capturas->resolverSinDocumentos($cuentaId);
                $limite = min(5, $capturaMax - $capturaCorrida['procesados']);
                $pendientes = $capturas->tomarPendientes(
                    $cuentaId,
                    $limite,
                    $capturaIntentos
                );
                if (!$pendientes) {
                    continue;
                }
                $huboTrabajo = true;

                $sociedadesCuenta = [];
                foreach ($cuentasModel->sociedadesDe($cuentaId) as $sociedadId) {
                    if (isset($sociedadesPorId[$sociedadId])) {
                        $sociedadesCuenta[] = $sociedadesPorId[$sociedadId];
                    }
                }

                $fetcher = new MailFetcher($cfg);
                try {
                    $fetcher->conectar();
                    foreach ($pendientes as $pendiente) {
                        $mensaje = null;
                        try {
                            $mensaje = $fetcher->extraerMensaje(
                                (int) $pendiente['uid'],
                                (string) $pendiente['carpeta']
                            );

                            if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                                $capturas->finalizar(
                                    (int) $pendiente['id'],
                                    'sin_documentos',
                                    'El correo no contiene XML ni PDF.'
                                );
                                $capturaCorrida['sin_documentos']++;
                                $capturaCorrida['procesados']++;
                                continue;
                            }

                            $resultado = $correoController->capturarMensajeParaRevision(
                                $mensaje,
                                $cuentaId,
                                $sociedadesCuenta
                            );
                            $documentos = count($resultado['filas'] ?? []);
                            $detalle = implode(' | ', array_slice($resultado['errores'] ?? [], 0, 8));
                            if ($documentos > 0) {
                                $capturas->finalizar(
                                    (int) $pendiente['id'],
                                    'capturado',
                                    $detalle !== '' ? $detalle : 'Disponible en la Bandeja para revisión manual.',
                                    $documentos
                                );
                                $capturaCorrida['documentos'] += $documentos;
                                if (isset($resumenCuentas[$cuentaId])) {
                                    $resumenCuentas[$cuentaId]['documentos_revision'] += $documentos;
                                }
                            } else {
                                $capturas->finalizar(
                                    (int) $pendiente['id'],
                                    'sin_documentos',
                                    $detalle !== '' ? $detalle : 'No se encontró un comprobante XML importable.'
                                );
                                $capturaCorrida['sin_documentos']++;
                            }
                            $capturaCorrida['procesados']++;
                            if (isset($resumenCuentas[$cuentaId])) {
                                $resumenCuentas[$cuentaId]['capturas_procesadas']++;
                            }
                        } catch (Throwable $e) {
                            // Un fallo antes de que procesarMensaje mueva los
                            // adjuntos no debe dejar temporales abandonados.
                            if (is_array($mensaje)) {
                                foreach (array_merge($mensaje['xmls'] ?? [], $mensaje['pdfs'] ?? []) as $adjunto) {
                                    $ruta = (string) ($adjunto['ruta'] ?? '');
                                    if ($ruta !== '' && is_file($ruta)) {
                                        @unlink($ruta);
                                    }
                                }
                            }
                            $capturas->finalizar(
                                (int) $pendiente['id'],
                                'error',
                                $e->getMessage()
                            );
                            $capturaCorrida['errores']++;
                            $capturaCorrida['procesados']++;
                            if (isset($resumenCuentas[$cuentaId])) {
                                $resumenCuentas[$cuentaId]['capturas_error']++;
                            }
                            registrar(
                                $rutaLog,
                                'Captura cuenta "' . (string) ($cuenta['nombre'] ?? $cuentaId)
                                . '", UID ' . (int) $pendiente['uid'] . ': ERROR ' . $e->getMessage()
                            );
                        }
                    }
                } catch (Throwable $e) {
                    // Si ni siquiera fue posible conectar, devolver cada fila
                    // tomada al ciclo de reintentos persistente.
                    foreach ($pendientes as $pendiente) {
                        $actual = $capturas->get((int) $pendiente['id']);
                        if ($actual && ($actual['estado'] ?? '') === 'procesando') {
                            $capturas->finalizar((int) $pendiente['id'], 'error', $e->getMessage());
                            $capturaCorrida['errores']++;
                            $capturaCorrida['procesados']++;
                            if (isset($resumenCuentas[$cuentaId])) {
                                $resumenCuentas[$cuentaId]['capturas_error']++;
                            }
                        }
                    }
                    registrar(
                        $rutaLog,
                        'Captura cuenta "' . (string) ($cuenta['nombre'] ?? $cuentaId)
                        . '": ERROR ' . $e->getMessage()
                    );
                } finally {
                    $fetcher->cerrar();
                }
            }
        }

        $resumenCapturas = $capturas->resumen();
    } catch (Throwable $e) {
        $capturaCorrida['errores']++;
        registrar($rutaLog, 'Captura automática: ERROR ' . $e->getMessage());
    }
} else {
    try {
        $resumenCapturas = (new CorreoCapturaAutomatica())->resumen();
    } catch (Throwable $e) {
        // La sincronización del índice sigue siendo válida aunque la tabla
        // de captura aún no exista o el usuario la haya desactivado.
    }
}

// La misma tarea programada mantiene el archivo local, en dos pasos.
//
// 1. Reconciliar: busca los archivos que alguien movió a mano y corrige la
//    base para seguirlos. No toca ningún archivo.
// 2. Ordenar: lleva cada documento a la carpeta de su mes y deja una copia en
//    la del pago semanal si le toca. Antes había que pedirlo desde
//    Configuración, con una previsualización de por medio; se volvió
//    automático porque la carpeta de un documento la deciden su fecha y su
//    tipo —que no cambian nunca— y su semana de pago, así que no hay nada que
//    decidir ni nada que previsualizar.
//
// Ordenar NO borra: solo mueve el par a su carpeta y copia a la del pago. Y
// alcanza únicamente a los documentos registrados: lo que alguien haya dejado
// en la carpeta por su cuenta se queda donde está.
//
// Un error de cualquiera de los dos pasos no invalida el correo.
try {
    if (DocumentoArchivo::raizConfigurada() !== '') {
        $organizador = new OrganizadorDocumentos();
        $completo = $organizador->requiereReconciliacionCompleta(86400);
        $orden = $organizador->reconciliar(false, $completo);
        registrar($rutaLog, sprintf(
            'Archivo local (%s): %d registrados; %d rutas actualizadas; %d archivos relocalizados; %d no encontrados; %d hashes calculados; %d sin archivo; %d errores.',
            $completo ? 'revisión completa' : 'revisión rápida',
            (int) ($orden['registrados_revisados'] ?? 0),
            (int) ($orden['rutas_actualizadas'] ?? 0),
            (int) ($orden['archivos_relocalizados'] ?? 0),
            (int) ($orden['archivos_no_encontrados'] ?? 0),
            (int) ($orden['hashes_calculados'] ?? 0),
            (int) ($orden['marcados_sin_archivo'] ?? 0),
            (int) ($orden['errores'] ?? 0)
        ));

        // No en cada corrida: la carpeta que le toca a un documento solo
        // cambia cuando entra uno nuevo o cuando cambia su semana de pago, y
        // recorrer el archivo entero cada cinco minutos sería tener la carpeta
        // compartida en danza para nada.
        if ($organizador->requiereOrden(900)) {
            $acomodo = $organizador->ejecutar(false, false);
            if (!empty($acomodo['omitido_por_bloqueo'])) {
                registrar($rutaLog, 'Acomodo del archivo: omitido, hay otra corrida en curso.');
            } else {
                registrar($rutaLog, sprintf(
                    'Acomodo del archivo: %d movidos a su mes; %d copias al pago semanal (%d ya estaban); %d ya ordenados; %d carpetas vacías podadas; %d sin archivo en disco; %d errores.',
                    (int) ($acomodo['movidos'] ?? 0),
                    (int) ($acomodo['copias_pago'] ?? 0),
                    (int) ($acomodo['copias_pago_vigentes'] ?? 0),
                    (int) ($acomodo['ya_ordenados'] ?? 0),
                    (int) ($acomodo['carpetas_podadas'] ?? 0),
                    (int) ($acomodo['faltantes_en_disco'] ?? 0),
                    (int) ($acomodo['errores'] ?? 0) + (int) ($acomodo['errores_copia_pago'] ?? 0)
                ));
            }
        }
    }
} catch (Throwable $e) {
    registrar($rutaLog, 'Archivo local: ERROR ' . $e->getMessage());
}

$duracion = round(microtime(true) - $inicioCorrida, 1);

// Registro resumido por cuenta
foreach ($resumenCuentas as $r) {
    registrar($rutaLog, sprintf(
        'Cuenta "%s": +%d encabezados, +%d adjuntos, +%d CC/Reply-To, +%d a revisión, pendientes=%s, %s%s',
        $r['nombre'],
        (int) $r['nuevos'],
        (int) $r['adjuntos'],
        (int) $r['cc'],
        (int) ($r['documentos_revision'] ?? 0),
        $r['metadatos_pendientes'] === null
            ? '?'
            : (string) (int) $r['metadatos_pendientes'],
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
    'captura'          => [
        'corrida' => $capturaCorrida,
        'cola' => $resumenCapturas,
    ],
    'pendientes_total' => array_sum(array_map(function ($r) {
        return max(0, (int) $r['metadatos_pendientes']);
    }, $resumenCuentas)),
    'todo_al_dia'      => $errorGlobal === null && !array_filter($resumenCuentas, function ($r) {
        return !$r['completado'];
    }),
];
@file_put_contents($rutaEstado, json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

flock($fpLock, LOCK_UN);
fclose($fpLock);

exit($errorGlobal === null ? 0 : 1);
