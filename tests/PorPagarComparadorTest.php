<?php
/**
 * Comparación de un archivo nuevo contra el pago que la semana ya tiene.
 *
 * Comparar se volvió una resta de conjuntos: cada fila del archivo se resolvió
 * a una factura del ERP, la semana tiene otras marcadas, y la diferencia dice
 * cuáles entran y cuáles salen. El estado 'modificada' desapareció con la copia
 * de los datos: no hay dos versiones de una factura que puedan discrepar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/PagoSemanalResolutor.php';
require_once __DIR__ . '/../app/helpers/PorPagarComparador.php';

function assertComparador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Una fila tal como la deja el resolutor. */
function filaResuelta($erpId, $documento, $proveedor, $saldoArchivo, $saldoErp = null)
{
    return [
        'estado' => 'resuelta',
        'motivo' => '',
        'numero' => $documento,
        'proveedor' => $proveedor,
        'saldo' => $saldoArchivo,
        'factura_erp_id' => $erpId,
        'erp' => [
            'documento' => $documento,
            'proveedor' => $proveedor,
            'fecha' => '2026-07-15',
            'monto' => $saldoErp === null ? $saldoArchivo : $saldoErp,
            'saldo' => $saldoErp === null ? $saldoArchivo : $saldoErp,
        ],
    ];
}

function filaNoResuelta($estado, $documento, $motivo)
{
    return ['estado' => $estado, 'motivo' => $motivo, 'numero' => $documento,
            'proveedor' => 'PROVEEDOR', 'saldo' => 100.0, 'factura_erp_id' => null, 'erp' => null];
}

/** Una factura tal como la devuelve getFacturasPago(). */
function asignada($id, $documento, $proveedor, $saldo, $estadoRespaldo = 'respaldada')
{
    return ['id' => $id, 'documento' => $documento, 'proveedor_nombre' => $proveedor,
            'fecha_emision' => '2026-07-15', 'saldo' => $saldo, 'saldo_pago' => $saldo,
            'estado' => $estadoRespaldo, 'factura_xml_id' => 500 + $id];
}

// ── Entra, se queda y sale ───────────────────────────────────────
$resolucion = ['filas' => [
    filaResuelta(10, '00100001010000000010', 'PROVEEDOR UNO', 100.00),   // ya estaba
    filaResuelta(11, '00100001010000000011', 'PROVEEDOR DOS', 200.00),   // nueva
], 'resumen' => [], 'ids' => [10, 11]];

$asignadas = [
    asignada(10, '00100001010000000010', 'PROVEEDOR UNO', 100.00),
    asignada(12, '00100001010000000012', 'PROVEEDOR TRES', 300.00),      // ya no viene
];

$r = PorPagarComparador::comparar($resolucion, $asignadas);
$x = $r['resumen'];
assertComparador($x['igual'] === 1, 'la que ya estaba y sigue viniendo no cambia');
assertComparador($x['nueva'] === 1, 'la que no estaba entra');
assertComparador($x['faltante'] === 1, 'la que ya no viene sale');

$porEstado = [];
foreach ($r['lineas'] as $l) { $porEstado[$l['estado']][] = $l; }
assertComparador($porEstado['nueva'][0]['factura_erp_id'] === 11, 'nombra qué factura del ERP entra');
assertComparador($porEstado['faltante'][0]['factura_erp_id'] === 12, 'nombra qué factura del ERP sale');
assertComparador($porEstado['faltante'][0]['numero'] === '00100001010000000012',
    'la que sale se identifica con el documento del ERP');

// ── No existe 'modificada' ───────────────────────────────────────
// El archivo trae otro saldo para una factura que ya estaba. Antes eso era una
// "modificación" que había que aplicar; ahora el ERP manda y no hay nada que
// actualizar: sigue siendo la misma factura y sigue en el pago.
$r = PorPagarComparador::comparar(
    ['filas' => [filaResuelta(10, '00100001010000000010', 'PROVEEDOR UNO', 999.00, 100.00)],
     'resumen' => [], 'ids' => [10]],
    [asignada(10, '00100001010000000010', 'PROVEEDOR UNO', 100.00)]
);
assertComparador($r['resumen']['igual'] === 1, 'un saldo distinto en el archivo no cambia el pago');
assertComparador(!isset($r['resumen']['modificada']), 'el estado modificada ya no existe');
$linea = $r['lineas'][0];
assertComparador($linea['saldo_erp'] === 100.00 && $linea['saldo'] === 999.00,
    'se conservan ambos saldos para poder avisar de la discrepancia');

// ── Lo que no resolvió se informa, no se convierte en diferencia ──
$r = PorPagarComparador::comparar(
    ['filas' => [
        filaNoResuelta('ausente', 'FACT-404', 'No está en el listado de facturas del ERP.'),
        filaNoResuelta('ambigua', 'FACT-777', 'El ERP tiene 2 facturas con ese número.'),
        filaNoResuelta('error', '', 'Número de documento vacío.'),
    ], 'resumen' => [], 'ids' => []],
    [asignada(10, '00100001010000000010', 'PROVEEDOR UNO', 100.00)]
);
$x = $r['resumen'];
assertComparador($x['ausente'] === 1 && $x['ambigua'] === 1 && $x['error'] === 1,
    'los problemas del archivo se informan con su propio estado');
assertComparador($x['faltante'] === 1,
    'la factura del pago que el archivo no menciona sigue contándose como saliente');
assertComparador($x['nueva'] === 0, 'nada sin resolver entra al pago');

// ── Un pago vacío: todo entra ────────────────────────────────────
$r = PorPagarComparador::comparar(
    ['filas' => [filaResuelta(10, '00100001010000000010', 'PROVEEDOR UNO', 100.00)],
     'resumen' => [], 'ids' => [10]],
    []
);
assertComparador($r['resumen']['nueva'] === 1 && $r['resumen']['faltante'] === 0,
    'contra una semana sin pago, todo lo resuelto entra');

echo "OK: comparador del pago semanal contra Facturas ERP\n";
