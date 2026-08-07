<?php
/**
 * Prueba de extracción de reportes PDF del ERP (Boleta Local / Devolución
 * a Proveedor). Imprime el resultado parseado y validado como JSON.
 *
 * Uso: php cli/parse_reporte_erp.php <archivo.pdf> [otro.pdf ...]
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/ReporteErpParser.php';

$archivos = array_slice($argv, 1);
if (!$archivos) {
    fwrite(STDERR, "Uso: php cli/parse_reporte_erp.php <archivo.pdf> [otro.pdf ...]\n");
    exit(2);
}

$huboError = false;
$resultados = [];
foreach ($archivos as $ruta) {
    try {
        $r = ReporteErpParser::parseArchivo($ruta);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'archivo' => basename($ruta), 'errores' => [$e->getMessage()]];
    }
    if (empty($r['ok'])) {
        $huboError = true;
    }
    $resultados[] = $r;
}

echo json_encode(count($resultados) === 1 ? $resultados[0] : $resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($huboError ? 1 : 0);
