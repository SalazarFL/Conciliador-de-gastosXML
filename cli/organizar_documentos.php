<?php
/** Reconcilia rutas; opcionalmente fuerza el orden por estado. */
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

$dryRun = in_array('--dry-run', $argv, true);
$soloRegistrados = in_array('--solo-registrados', $argv, true);
$forzarOrden = in_array('--forzar-orden', $argv, true);
$completo = in_array('--completo', $argv, true);

try {
    $organizador = new OrganizadorDocumentos();
    // El modo seguro es el predeterminado: descubre movimientos/renombres y
    // actualiza rutas sin tocar los archivos. --completo relee todos los hashes.
    // La conducta histórica queda disponible solo con --forzar-orden.
    $resumen = $forzarOrden
        ? $organizador->ejecutar($dryRun, !$soloRegistrados)
        : $organizador->reconciliar($dryRun, $completo);
    echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(($resumen['errores'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo procesar el archivo local: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
