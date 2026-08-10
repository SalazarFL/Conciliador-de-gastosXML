<?php
/**
 * Reconcilia rutas; opcionalmente ordena el archivo por orden explícita.
 *
 * Sin banderas RECONCILIA: busca los archivos que alguien movió a mano y
 * corrige la base de datos, sin tocar ni un archivo. Mover archivos siempre
 * hay que pedirlo, con --forzar-orden o --semana.
 *
 *   php cli/organizar_documentos.php                    reconciliar (rápido)
 *   php cli/organizar_documentos.php --completo         reconciliar releyendo todo
 *   php cli/organizar_documentos.php --forzar-orden     MUEVE: ordena lo registrado
 *   php cli/organizar_documentos.php --semana=12        MUEVE: solo esa semana
 *   php cli/organizar_documentos.php --forzar-orden --dry-run   ver sin mover
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

$conocidas = ['--dry-run', '--solo-registrados', '--forzar-orden', '--completo', '--reconciliar'];
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, $conocidas, true) && strpos($arg, '--semana=') !== 0) {
        fwrite(STDERR, "Bandera desconocida: {$arg}\n"
            . "Válidas: " . implode(' ', $conocidas) . " --semana=N\n");
        exit(2);
    }
}

$dryRun          = in_array('--dry-run', $argv, true);
$soloRegistrados = in_array('--solo-registrados', $argv, true);
$forzarOrden     = in_array('--forzar-orden', $argv, true);
$completo        = in_array('--completo', $argv, true);

$semanaId = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--semana=') === 0) {
        $semanaId = (int) substr($arg, strlen('--semana='));
    }
}

try {
    $organizador = new OrganizadorDocumentos();

    if ($semanaId > 0) {
        // Ordena únicamente las facturas de esa semana, a su carpeta de pago.
        require_once $root . '/app/models/Factura.php';
        $ids = (new Factura())->getIdsPorSemana($semanaId);
        if (!$ids) {
            fwrite(STDERR, "La semana {$semanaId} no tiene facturas asignadas.\n");
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
    fwrite(STDERR, 'No se pudo procesar el archivo local: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
