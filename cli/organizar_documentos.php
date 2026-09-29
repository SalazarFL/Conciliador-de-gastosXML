<?php
/**
 * Pone la base al día con lo que la gente hizo en el archivo de documentos.
 *
 * Por omisión revisa SharePoint, que es donde se archiva todo desde el
 * 27/09/2026 (ver ReconciliadorGraph): documentos movidos, borrados o
 * restaurados desde el navegador, y las copias de la carpeta de cada pago.
 *
 *   php cli/organizar_documentos.php                 revisar SharePoint
 *   php cli/organizar_documentos.php --dry-run       qué haría, sin tocar nada
 *
 * La carpeta compartida de antes solo se toca nombrándola, para ordenar lo
 * que quedó ahí mientras no se migra:
 *
 *   php cli/organizar_documentos.php --carpeta=RUTA                   reconciliar (rápido)
 *   php cli/organizar_documentos.php --carpeta=RUTA --completo        reconciliar releyendo todo
 *   php cli/organizar_documentos.php --carpeta=RUTA --forzar-orden    MUEVE: ordena lo registrado
 *   php cli/organizar_documentos.php --carpeta=RUTA --pago=12         MUEVE: solo ese pago
 *
 * --sharepoint se sigue aceptando: está escrito en notas y tareas de antes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo corre por línea de comandos.\n");
}

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
require_once $root . '/app/core/Model.php';
require_once $root . '/app/helpers/OrganizadorDocumentos.php';

$conocidas = ['--dry-run', '--solo-registrados', '--forzar-orden', '--completo', '--reconciliar', '--sharepoint'];

// La bandera se llamaba --semana=N. Se sigue aceptando: está escrita en tareas
// programadas y en notas de instalación que nadie va a revisar hoy, y fallar
// con "bandera desconocida" convertiría un renombrado en una tarea rota.
$prefijosPago = ['--pago=', '--semana='];
$traeValor = function ($arg) use ($prefijosPago) {
    if (strpos($arg, '--carpeta=') === 0) {
        return true;
    }
    foreach ($prefijosPago as $prefijo) {
        if (strpos($arg, $prefijo) === 0) {
            return true;
        }
    }
    return false;
};

foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, $conocidas, true) && !$traeValor($arg)) {
        fwrite(STDERR, "Bandera desconocida: {$arg}\n"
            . "Válidas: " . implode(' ', $conocidas) . " --carpeta=RUTA --pago=N\n");
        exit(2);
    }
}

$dryRun          = in_array('--dry-run', $argv, true);
$soloRegistrados = in_array('--solo-registrados', $argv, true);
$forzarOrden     = in_array('--forzar-orden', $argv, true);
$completo        = in_array('--completo', $argv, true);

$pagoId = 0;
$carpeta = '';
foreach ($argv as $arg) {
    foreach ($prefijosPago as $prefijo) {
        if (strpos($arg, $prefijo) === 0) {
            $pagoId = (int) substr($arg, strlen($prefijo));
        }
    }
    if (strpos($arg, '--carpeta=') === 0) {
        $carpeta = trim(substr($arg, strlen('--carpeta=')));
    }
}

// Lo de la carpeta compartida de antes, sin nombrarla, no corre: ahí ya no
// se archiva nada, y mover archivos en ella por costumbre es lo último que
// hace falta.
if ($carpeta === '' && ($pagoId > 0 || $forzarOrden || $completo || in_array('--reconciliar', $argv, true))) {
    fwrite(STDERR, "Eso es para la carpeta compartida de antes: nómbrala con --carpeta=RUTA.\n"
        . "Los documentos de hoy están en SharePoint; para revisarlos, corre esto sin banderas.\n");
    exit(2);
}

if ($carpeta === '') {
    require_once $root . '/app/helpers/ReconciliadorGraph.php';
    try {
        if (!AlmacenDocumentos::disponible(AlmacenEnGraph::TIPO)) {
            fwrite(STDERR, "Esta computadora no tiene app/config/local.graph.php: no puede hablar con SharePoint.\n");
            exit(2);
        }
        $resumen = (new ReconciliadorGraph(AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO)))->ejecutar($dryRun);
        echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(($resumen['errores'] ?? 0) > 0 ? 1 : 0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'No se pudo revisar SharePoint: ' . $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

try {
    if (!is_dir($carpeta)) {
        fwrite(STDERR, "No existe la carpeta {$carpeta}.\n");
        exit(2);
    }
    RutaDocumento::fijarRaiz($carpeta);
    $organizador = new OrganizadorDocumentos($carpeta);

    if ($pagoId > 0) {
        // Ordena únicamente las facturas de ese pago, a su carpeta de pago.
        require_once $root . '/app/models/Factura.php';
        $ids = (new Factura())->getIdsPorPago($pagoId);
        if (!$ids) {
            fwrite(STDERR, "El pago {$pagoId} no tiene facturas asignadas.\n");
            exit(1);
        }
        $resumen = $organizador->organizarIds($ids, $dryRun);
    } elseif ($forzarOrden) {
        $resumen = $organizador->ejecutar($dryRun, !$soloRegistrados);
    } else {
        // Modo seguro y predeterminado: descubre movimientos y renombres
        // hechos a mano y actualiza las rutas sin mover ningún archivo.
        $resumen = $organizador->reconciliar($dryRun, $completo);
    }

    echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(($resumen['errores'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo procesar la carpeta compartida de antes: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
