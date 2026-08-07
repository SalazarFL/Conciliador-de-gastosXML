<?php
/**
 * Backfill de referencias y líneas de los XML ya importados.
 *
 * Uso: php cli/backfill_xml_detalle.php [--dry-run] [--tipo=NC|ALL]
 *      [--limite=N] [--forzar]
 *
 * --tipo    Tipo de documento a procesar (por defecto NC, que es donde vive
 *           InformacionReferencia). ALL procesa también FE/ND.
 * --limite  Máximo de documentos en esta corrida (0 = todos los pendientes).
 * --forzar  Reprocesa aunque detalle_extraido_en ya esté marcado.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaXmlDetalle.php';

$dryRun = in_array('--dry-run', $argv, true);
$forzar = in_array('--forzar', $argv, true);
$tipo = 'NC';
$limite = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--tipo=') === 0) {
        $tipo = strtoupper(substr($arg, 7));
    } elseif (strpos($arg, '--limite=') === 0) {
        $limite = max(0, (int) substr($arg, 9));
    }
}

$modelo = new FacturaXmlDetalle();

if ($forzar && !$dryRun) {
    $modelo->reabrirParaReproceso($tipo);
}

$resumen = [
    'tipo' => $tipo,
    'pendientes_iniciales' => $modelo->contarPendientes($tipo),
    'procesados' => 0,
    'con_referencias' => 0,
    'con_clave_referenciada' => 0,
    'referencias_insertadas' => 0,
    'lineas_insertadas' => 0,
    'errores' => 0,
    'detalle_errores' => [],
];

if ($dryRun) {
    echo json_encode(array_merge($resumen, ['dry_run' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$ultimoId = 0;
while (true) {
    if ($limite > 0 && $resumen['procesados'] >= $limite) {
        break;
    }
    $lote = $modelo->pendientes($ultimoId, 100, $tipo);
    if (!$lote) {
        break;
    }

    foreach ($lote as $fila) {
        $ultimoId = (int) $fila['id'];
        if ($limite > 0 && $resumen['procesados'] >= $limite) {
            break;
        }

        try {
            if (!is_file((string) $fila['ruta_xml'])) {
                throw new RuntimeException('Archivo XML no existe en disco: ' . $fila['ruta_xml']);
            }
            $r = $modelo->extraerYGuardar((int) $fila['id'], (string) $fila['ruta_xml']);
            $resumen['procesados']++;
            $resumen['referencias_insertadas'] += $r['referencias'];
            $resumen['lineas_insertadas'] += $r['lineas'];
            if ($r['referencias'] > 0) {
                $resumen['con_referencias']++;
            }
        } catch (Throwable $e) {
            $resumen['errores']++;
            if (count($resumen['detalle_errores']) < 50) {
                $resumen['detalle_errores'][] = ['id' => (int) $fila['id'], 'error' => $e->getMessage()];
            }
        }
    }
}

$resumen['con_clave_referenciada'] = $modelo->contarConClaveReferenciada();
$resumen['pendientes_finales'] = $modelo->contarPendientes($tipo);

echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($resumen['errores'] > 0 ? 1 : 0);
