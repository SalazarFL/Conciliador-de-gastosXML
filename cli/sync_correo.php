<?php
/**
 * Sincronización y captura automática de correo — se ejecuta SIN el módulo
 * abierto, disparada por la Tarea Programada de Windows "XMLConcilia_SyncCorreo"
 * (se activa/desactiva desde el ⚙ del módulo Correo).
 *
 * Reserva los buzones que puede, los recorre en round-robin dándole a cada uno
 * tandas cortas (12s) de CorreoSync hasta que quedan al día o hasta agotar el
 * tope total de la corrida. El tope por defecto es 4 min: con un rezago grande
 * de adjuntos/CC un tope corto deja el índice trabajando solo una fracción del
 * tiempo y la cola no termina nunca.
 *
 * ── Qué hace con lo que encuentra ──
 *
 * Una corrida completa deja el trabajo hecho, no preparado:
 *
 *   1. Índice al día: qué correos hay en cada buzón.
 *   2. Captura: los comprobantes de los correos nuevos bajan a la Bandeja.
 *   3. Entrada: los que están limpios entran solos al sistema —se archivan en
 *      la carpeta de su mes, se registran y se enganchan con su factura del
 *      ERP—. En la Bandeja solo queda lo que una persona tiene que resolver.
 *   4. Listados: se cruzan los pagos y las notas de crédito de las
 *      empresas que recibieron algo, una vez por corrida.
 *   5. Archivo local: reconciliar y acomodar la carpeta compartida.
 *
 * El paso 3 se puede apagar desde Configuración → Automatización, y entonces
 * todo espera en la Bandeja como antes. Ver app/helpers/EntradaAutomatica.php.
 *
 * ── Varias copias a la vez ──
 *
 * Este script está pensado para correr en paralelo consigo mismo. Hablarle al
 * servidor de correo es esperar —105 ms por viaje contra mail.bm.cr— y con 42
 * buzones esa espera en fila no cabe en ninguna ventana razonable. Con varias
 * copias, la espera se solapa y el reloj se divide; la CPU casi no se entera,
 * porque son procesos bloqueados en la red.
 *
 * No hay coordinador ni reparto previo: cada copia toma el candado de los
 * buzones que encuentra libres y trabaja con esos. El candado ES el reparto.
 * Así una copia que se muera no deja a nadie esperando —su candado se suelta
 * con el proceso— y agregar o quitar copias no requiere reconfigurar nada.
 *
 * Lo que sigue habiendo es un candado por buzón: dos procesos nunca tocan el
 * mismo. Ver CorreoSync::adquirirLock().
 *
 * Deja el resultado en storage/correo/sync_estado.json (lo lee el ⚙ para
 * mostrar "última actualización") y un registro en storage/correo/sync_auto.log.
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
require_once $ROOT . '/app/models/CorreoBandeja.php';
require_once $ROOT . '/app/models/Sociedad.php';
require_once $ROOT . '/app/controllers/CorreoController.php';
require_once $ROOT . '/app/helpers/EntradaAutomatica.php';
require_once $ROOT . '/app/helpers/OrganizadorDocumentos.php';

$topeSegundos = isset($argv[1]) ? max(30, min(3600, (int) $argv[1])) : 240; // 4 min por defecto
$presupuestoTanda = 12; // segundos por tanda de una cuenta

$dirCorreo = MailFetcher::storagePath();
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
// Que los comprobantes limpios entren solos, sin esperar a que alguien los
// autorice en la Bandeja. Es lo normal; se apaga desde Configuración.
$entradaAutomatica = EntradaAutomatica::activa($autoCfg);
$capturaCorrida = [
    'activa' => $capturaActiva, 'detectados' => 0, 'procesados' => 0,
    'documentos' => 0, 'sin_documentos' => 0, 'errores' => 0,
    // Con entrada automática, en qué terminó cada documento capturado.
    'entrada_automatica' => $entradaAutomatica,
    'ingresados' => 0, 'duplicados' => 0, 'retenidos' => 0,
    'fallidos' => 0, 'pdf_pendientes' => 0,
];

/*
 * Ya no hay un candado que abarque la corrida entera: cada buzón se reserva
 * por separado, y esta corrida trabaja con los que consiga. Varias copias de
 * este script pueden correr a la vez —esa es la idea— y se reparten los
 * buzones solas, sin coordinador: el que agarra el candado de un buzón se lo
 * queda, y el que lo encuentra tomado sigue al siguiente.
 *
 * Se conserva $locks para soltarlos todos al final, pase lo que pase.
 */
$locks = [];
$soltarLocks = function () use (&$locks) {
    foreach ($locks as $lock) {
        CorreoSync::liberarLock($lock);
    }
    $locks = [];
};
register_shutdown_function($soltarLocks);

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

        // La reserva del buzón. Si otro trabajador ya lo tiene, este se saltea
        // en silencio: no es un error, es el reparto funcionando.
        $lock = CorreoSync::adquirirLock((int) $c['id']);
        if ($lock === null) {
            continue;
        }
        $locks[(int) $c['id']] = $lock;

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
                'documentos_revision' => 0, 'documentos_ingresados' => 0,
                'capturas_error' => 0,
                'completado' => false, 'error' => null,
            ],
        ];
    }

    if (empty($cola)) {
        // Con varios trabajadores esto es lo normal para el que llega último:
        // los buzones ya están todos repartidos.
        registrar($rutaLog, 'Sin buzones que atender: o no hay credenciales, o los tienen otros trabajadores.');
    } else {
        registrar($rutaLog, 'Inicio: ' . count($cola) . ' cuenta(s) reservada(s), tope ' . $topeSegundos . 's.');
    }

    // Con correos esperando captura, el índice no se lleva la corrida entera.
    // La captura va después y solo corre si sobra tiempo: el 05/10/2026, tras
    // cuatro días con la computadora apagada, un buzón con 2.000 adjuntos por
    // indexar gastó el tope completo corrida tras corrida, y en casi una hora
    // no entró ni un comprobante de ninguno de los otros buzones, que ya
    // estaban al día. Sin nada en cola el índice sigue usando todo el tiempo.
    $topeIndice = $topeSegundos;
    if ($capturaActiva) {
        try {
            if ((new CorreoCapturaAutomatica())->resumen()['pendiente'] > 0) {
                $topeIndice = $topeSegundos * 0.6;
            }
        } catch (Throwable $e) {
            // Sin poder contar la cola, el reparto queda como estaba.
        }
    }

    // Round-robin: una tanda por cuenta, ciclando hasta que todas queden al
    // día o se agote el tope de la corrida. Reparte el tiempo con justicia
    // aunque una cuenta tenga un rezago enorme de adjuntos.
    while (!empty($cola) && (microtime(true) - $inicioCorrida) < $topeIndice) {
        foreach ($cola as $id => &$acc) {
            if ((microtime(true) - $inicioCorrida) >= $topeIndice) {
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
                // Y en la campana: el log de la tarea programada no lo lee
                // nadie hasta que algo ya se rompió.
                CorreoSync::anotarFallo($e->getMessage(), $acc['config']);
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

// Captura: consume solamente la cola de UIDs nuevos que dejó CorreoSync y baja
// sus comprobantes a correo_bandeja. Con la entrada automática activa, los que
// están limpios siguen de largo y entran al sistema en el acto; la Bandeja se
// queda con lo que hay que mirar.
//
// Lo que sigue sin tocar es el buzón: no se marca el correo como procesado
// (eso es del modo Descargas, que trabaja con correo_procesados) ni se le pone
// bandera de leído. Lo que se lee no cambia de aspecto para quien abra el
// buzón desde Outlook.
$resumenCapturas = [
    'pendiente' => 0, 'procesando' => 0, 'capturado' => 0,
    'sin_documentos' => 0, 'error' => 0, 'documentos' => 0,
];
if ($capturaActiva && isset($cuentasModel, $cuentas)
    && (microtime(true) - $inicioCorrida) < $topeSegundos) {
    try {
        // La Bandeja y el archivo están en SharePoint: sin sus credenciales en
        // esta computadora no hay dónde dejar lo que se capture.
        if (!AlmacenDocumentos::hayPrincipal()) {
            throw new RuntimeException('La captura automática guarda en SharePoint. ' . AlmacenDocumentos::SIN_SHAREPOINT);
        }

        $capturas = new CorreoCapturaAutomatica();
        $correoController = new CorreoController();
        $sociedadesPorId = [];
        foreach ((new Sociedad())->getAll() as $sociedad) {
            $sociedadesPorId[(int) $sociedad['id']] = $sociedad;
        }

        // Quien mete al sistema lo que se va capturando. Es uno solo para toda
        // la corrida a propósito: va anotando qué empresas recibieron algo, y
        // al final cruza sus listados de una vez en vez de por cada correo.
        $entrada = $entradaAutomatica
            ? new EntradaAutomatica(new CorreoBandeja(), $sociedadesPorId)
            : null;

        // Lo que una corrida anterior dejó a medias en esta computadora: se
        // sube antes de empezar, para que lo alcance cualquiera. Cuesta una
        // consulta y casi siempre no hay nada.
        if ($entrada !== null) {
            $rescatadas = $entrada->rescatarLoQueQuedoAqui();
            if ($rescatadas > 0) {
                registrar(
                    $rutaLog,
                    'Subidas a la bandeja compartida ' . $rescatadas
                    . ' fila(s) que habían quedado en esta computadora.'
                );
            }
        }

        // Tandas cortas en round-robin: una cuenta con mucho tráfico no
        // monopoliza toda la corrida ni retrasa a los demás buzones.
        //
        // Solo los buzones que este trabajador reservó. La cola de capturas se
        // reparte sola por la base —tomarPendientes reclama sus filas con FOR
        // UPDATE—, así que dos trabajadores no se pisarían; pero cada uno abre
        // su propia conexión IMAP, y no hay razón para gastar dos contra el
        // mismo buzón.
        $cuentasCaptura = array_values(array_filter($cuentas, function ($c) use ($locks) {
            return isset($locks[(int) ($c['id'] ?? 0)]);
        }));
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
                                // Los avisos dicen si había adjuntos y se
                                // descartaron (un ZIP desmedido, por ejemplo):
                                // sin ellos, esto parecería un correo vacío.
                                $detalle = 'El correo no contiene XML ni PDF.';
                                if (!empty($mensaje['avisos'])) {
                                    $detalle .= ' ' . implode(' ', (array) $mensaje['avisos']);
                                }
                                $capturas->finalizar(
                                    (int) $pendiente['id'],
                                    'sin_documentos',
                                    $detalle
                                );
                                $capturaCorrida['sin_documentos']++;
                                $capturaCorrida['procesados']++;
                                continue;
                            }

                            $resultado = $correoController->capturarMensajeAutomatico(
                                $mensaje,
                                $cuentaId,
                                $sociedadesCuenta,
                                $entrada,
                                [
                                    'carpeta' => (string) $pendiente['carpeta'],
                                    'uidvalidity' => (int) $pendiente['uidvalidity'],
                                    'uid' => (int) $pendiente['uid'],
                                    'fecha_correo' => $mensaje['fecha'] ?? null,
                                ]
                            );
                            $documentos = count($resultado['filas'] ?? []);
                            $ingreso = is_array($resultado['entrada'] ?? null) ? $resultado['entrada'] : null;
                            $avisos = $resultado['errores'] ?? [];
                            if ($ingreso) {
                                $capturaCorrida['ingresados'] += (int) $ingreso['ingresados'];
                                $capturaCorrida['duplicados'] += (int) $ingreso['duplicados'];
                                $capturaCorrida['retenidos'] += (int) $ingreso['retenidos'];
                                $capturaCorrida['fallidos'] += (int) $ingreso['fallidos'];
                                $capturaCorrida['pdf_pendientes'] += (int) $ingreso['pdf_pendientes'];
                                $avisos = array_merge($avisos, $ingreso['errores']);
                            }
                            $detalle = implode(' | ', array_slice($avisos, 0, 8));
                            if ($documentos > 0) {
                                $capturas->finalizar(
                                    (int) $pendiente['id'],
                                    'capturado',
                                    $detalle !== '' ? $detalle : ($ingreso
                                        ? 'Ingresó al sistema: ' . (int) $ingreso['ingresados']
                                            . ' documento(s), ' . (int) $ingreso['duplicados'] . ' ya estaban.'
                                        : 'Disponible en la Bandeja para revisión manual.'),
                                    $documentos
                                );
                                $capturaCorrida['documentos'] += $documentos;
                                if (isset($resumenCuentas[$cuentaId])) {
                                    $resumenCuentas[$cuentaId]['documentos_revision'] += $documentos;
                                    $resumenCuentas[$cuentaId]['documentos_ingresados']
                                        += $ingreso ? (int) $ingreso['ingresados'] : 0;
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

        // Todo lo de esta corrida ya entró: ahora, una sola vez, se cruzan los
        // listados de las empresas que recibieron algo. Antes esto solo pasaba
        // cuando alguien abría Por Pagar y pulsaba «Verificar de nuevo», así
        // que una factura podía estar en la base y su fila del ERP seguir
        // diciendo «sin respaldo» durante días.
        if ($entrada instanceof EntradaAutomatica && $entrada->hayQueVerificar()) {
            $cruce = $entrada->verificarListados();
            registrar($rutaLog, sprintf(
                'Listados cruzados: %d empresa(s) con facturas, %d con notas de crédito; %d fila(s) del ERP quedaron con su respaldo.%s',
                (int) $cruce['sociedades_fe'],
                (int) $cruce['sociedades_nc'],
                (int) $cruce['pagos_resueltos'],
                $cruce['errores'] ? ' [' . implode('; ', array_slice($cruce['errores'], 0, 3)) . ']' : ''
            ));
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
//    la del pago si le toca. Antes había que pedirlo desde
//    Configuración, con una previsualización de por medio; se volvió
//    automático porque la carpeta de un documento la deciden su fecha y su
//    tipo —que no cambian nunca— y su pago de pago, así que no hay nada que
//    decidir ni nada que previsualizar.
//
// Ordenar NO borra: solo mueve el par a su carpeta y copia a la del pago. Y
// alcanza únicamente a los documentos registrados: lo que alguien haya dejado
// en la carpeta por su cuenta se queda donde está.
//
// Un error de cualquiera de los dos pasos no invalida el correo.
//
// Antes, el barrido de los documentos bajados que alguien no devolvió: un
// proceso que murió a la mitad —un tiempo agotado de Apache, un corte de luz—
// no llegó a soltarlos. Con los documentos en disco no hay nada que barrer.
$barridos = AlmacenDocumentos::barrerTemporales(3600);
if ($barridos > 0) {
    registrar($rutaLog, 'Temporales de documentos: ' . $barridos . ' olvidados, borrados.');
}

// Y lo que se quiso deshacer en SharePoint y no se pudo: un documento subido
// cuya fila no se guardó. Ver app/models/AlmacenPendiente.php.
try {
    require_once $ROOT . '/app/models/AlmacenPendiente.php';
    $compensados = (new AlmacenPendiente())->drenar(50);
    if ($compensados['hechos'] > 0 || $compensados['fallidos'] > 0) {
        registrar($rutaLog, 'Borrados pendientes en el almacén: ' . $compensados['hechos'] . ' hechos, '
            . $compensados['fallidos'] . ' siguen fallando.');
    }
} catch (Throwable $e) {
    registrar($rutaLog, 'No se pudieron revisar los borrados pendientes: ' . $e->getMessage());
}

// Aquí corría el organizador de la carpeta compartida: reconciliaba rutas
// movidas a mano y acomodaba cada documento en su mes y en la carpeta de su
// pago. Desde el 27/09/2026 no se archiva nada en esa carpeta, y lo que
// quedó ahí se migra a SharePoint (cli/migrar_documentos_a_graph.php) en
// vez de seguir ordenándolo. Para hacerlo a mano sobre lo viejo sigue
// estando cli/organizar_documentos.php --carpeta=RUTA.

// Las facturas del ERP que tienen su XML cargado y ningún enlace. Cada camino
// que engancha —importar el XML, cargar el ERP, cambiar el catálogo, verificar
// un pago— mira en su momento; lo que se suelta después (un pago borrado, un
// vínculo manual quitado) no lo volvía a mirar nadie. El 30/09 eran 205. Es
// una vuelta sobre toda la tabla, así que va cada seis horas y no en cada
// corrida.
try {
    $marcaEnganche = $ROOT . '/storage/cache/reenganche_facturas.marca';
    clearstatcache(true, $marcaEnganche);
    if (!is_file($marcaEnganche) || time() - (int) filemtime($marcaEnganche) >= 6 * 3600) {
        // Antes de correr, como los recordatorios: si falla, repetirlo en la
        // corrida siguiente no lo arregla.
        @touch($marcaEnganche);
        require_once $ROOT . '/app/helpers/PorPagarVerificador.php';
        require_once $ROOT . '/app/models/FacturaErp.php';
        require_once $ROOT . '/app/models/Factura.php';
        $reenganchadas = 0;
        foreach ((new Sociedad())->getAll() as $sociedad) {
            $sid = (int) $sociedad['id'];
            $r = PorPagarVerificador::engancharSinRespaldo(
                (new FacturaErp())->setSociedad($sid),
                (new Factura())->setSociedad($sid)
            );
            $reenganchadas += (int) $r['enganchadas'];
        }
        registrar($rutaLog, 'Facturas del ERP con su XML ya cargado: ' . $reenganchadas . ' enganchadas.');
    }
} catch (Throwable $e) {
    registrar($rutaLog, 'Enganche de facturas sueltas: ERROR ' . $e->getMessage());
}

// Los documentos que viven en SharePoint no los ve el organizador: de esos se
// ocupa su reconciliador, que lee los cambios de la biblioteca en vez de
// recorrerla. Sin empresas ni documentos allá, no sale a la red.
try {
    require_once $ROOT . '/app/helpers/ReconciliadorGraph.php';
    if (ReconciliadorGraph::hayQueCorrer()) {
        $nube = (new ReconciliadorGraph(AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO)))->ejecutar(false);
        if (!empty($nube['omitido_por_bloqueo'])) {
            registrar($rutaLog, 'SharePoint: omitido, otra PC está revisando la biblioteca.');
        } else {
            registrar($rutaLog, sprintf(
                'SharePoint: %d cambios leídos%s; %d documentos revisados; %d rutas corregidas; %d sin archivo; %d volvieron; %d con otro contenido; %d copias al pago (%d ya estaban, %d para la próxima vuelta%s); %d errores.',
                (int) $nube['cambios_leidos'],
                $nube['delta_reiniciado'] ? ' (el enlace de cambios venció: se reinició)'
                    : (!empty($nube['sin_enlace_de_cambios']) ? ' (SharePoint no da el enlace de cambios de la carpeta: solo revisión por id)' : ''),
                (int) $nube['documentos_revisados'],
                (int) $nube['rutas_actualizadas'],
                (int) $nube['marcados_sin_archivo'],
                (int) $nube['desmarcados'],
                (int) $nube['editados'],
                (int) $nube['copias_pago'],
                (int) $nube['copias_pago_vigentes'],
                (int) $nube['copias_pago_para_despues'],
                !empty($nube['copias_en_otro_proceso']) ? '; las estaba haciendo cli/copiar_pagos.php' : '',
                (int) $nube['errores'] + (int) $nube['errores_copia_pago']
            ));
        }
    }
} catch (Throwable $e) {
    registrar($rutaLog, 'SharePoint: ERROR ' . $e->getMessage());
}

$duracion = round(microtime(true) - $inicioCorrida, 1);

// Registro resumido por cuenta
foreach ($resumenCuentas as $r) {
    registrar($rutaLog, sprintf(
        'Cuenta "%s": +%d encabezados, +%d adjuntos, +%d CC/Reply-To, +%d documento(s) capturado(s) (%d al sistema), pendientes=%s, %s%s',
        $r['nombre'],
        (int) $r['nuevos'],
        (int) $r['adjuntos'],
        (int) $r['cc'],
        (int) ($r['documentos_revision'] ?? 0),
        (int) ($r['documentos_ingresados'] ?? 0),
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

/*
 * El estado se FUNDE con lo que haya, no se pisa.
 *
 * Cada trabajador atiende solo los buzones que reservó, así que escribir el
 * archivo entero dejaría al ⚙ mostrando una parte —y cambiando de parte según
 * cuál trabajador terminó último—. Se conservan las cuentas de los demás y se
 * reemplazan únicamente las propias.
 *
 * El candado es sobre el archivo de estado, no sobre los buzones: es corto y
 * solo ordena la escritura entre trabajadores que terminan a la vez.
 */
$fpEstado = @fopen($rutaEstado, 'c+');
if ($fpEstado !== false) {
    try {
        if (flock($fpEstado, LOCK_EX)) {
            $previo = json_decode((string) stream_get_contents($fpEstado), true);
            $previo = is_array($previo) ? $previo : [];

            $cuentasFundidas = [];
            foreach (($previo['cuentas'] ?? []) as $c) {
                if (is_array($c) && isset($c['id'])) {
                    $cuentasFundidas[(int) $c['id']] = $c;
                }
            }
            foreach ($resumenCuentas as $c) {
                $cuentasFundidas[(int) $c['id']] = $c;
            }
            ksort($cuentasFundidas);

            $estado['cuentas'] = array_values($cuentasFundidas);
            $estado['pendientes_total'] = array_sum(array_map(function ($r) {
                return max(0, (int) ($r['metadatos_pendientes'] ?? 0));
            }, $cuentasFundidas));
            $estado['todo_al_dia'] = $errorGlobal === null
                && !array_filter($cuentasFundidas, function ($r) {
                    return empty($r['completado']);
                });

            ftruncate($fpEstado, 0);
            rewind($fpEstado);
            fwrite($fpEstado, json_encode(
                $estado,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            fflush($fpEstado);
            flock($fpEstado, LOCK_UN);
        }
    } finally {
        fclose($fpEstado);
    }
}

$soltarLocks();

exit($errorGlobal === null ? 0 : 1);
