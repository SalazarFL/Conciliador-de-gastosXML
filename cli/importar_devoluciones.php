<?php
/**
 * Importa reportes PDF del ERP (Boleta Local / Devolución a Proveedor) y
 * corre la verificación contra las NC electrónicas.
 *
 * Uso: php cli/importar_devoluciones.php <archivo.pdf|carpeta> [más rutas...]
 *      php cli/importar_devoluciones.php --verificar   (solo re-verificar pendientes)
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/DevolucionImporter.php';

$args = array_slice($argv, 1);
$soloVerificar = in_array('--verificar', $args, true);
$rutas = array_values(array_filter($args, function ($a) { return strpos($a, '--') !== 0; }));

$modelo = new Devolucion();

if ($soloVerificar) {
    $stats = DevolucionVerificador::verificarPendientes($modelo);
    echo json_encode(['verificacion' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

if (!$rutas) {
    fwrite(STDERR, "Uso: php cli/importar_devoluciones.php <archivo.pdf|carpeta> [...]\n");
    exit(2);
}

$archivos = [];
foreach ($rutas as $ruta) {
    if (is_dir($ruta)) {
        foreach (glob(rtrim($ruta, '/\\') . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $pdf) {
            $archivos[] = $pdf;
        }
    } elseif (is_file($ruta)) {
        $archivos[] = $ruta;
    } else {
        fwrite(STDERR, "Ruta no encontrada: {$ruta}\n");
    }
}

$importer = new DevolucionImporter($modelo);
$resultados = [];
$conteo = ['importada' => 0, 'duplicada' => 0, 'rechazada' => 0, 'error' => 0];

foreach ($archivos as $pdf) {
    try {
        $r = $importer->importar($pdf);
    } catch (Throwable $e) {
        $r = ['estado' => 'error', 'archivo' => basename($pdf), 'errores' => [$e->getMessage()]];
    }
    $conteo[$r['estado']] = ($conteo[$r['estado']] ?? 0) + 1;
    $resultados[] = $r;
}

echo json_encode(['conteo' => $conteo, 'resultados' => $resultados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit(($conteo['rechazada'] + $conteo['error']) > 0 ? 1 : 0);
