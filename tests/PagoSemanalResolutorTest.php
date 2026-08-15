<?php
/**
 * Resolución del archivo de pago contra Facturas ERP.
 *
 * Este es el corazón del módulo desde que el pago semanal dejó de guardar los
 * datos del archivo. Antes el archivo ERA el listado: lo que dijera, eso valía.
 * Ahora el archivo solo dice CUÁLES facturas se pagan, y la factura del ERP
 * pone los datos. Si esta resolución falla, el pago carga facturas que no son.
 *
 * Lo que se comprueba es sobre todo cuándo NO se resuelve: preferir dejar una
 * fila sin resolver a asignar la factura equivocada es la regla de la casa,
 * porque una asignación errónea paga lo que no se debe.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/PagoSemanalResolutor.php';

function assertResolutor($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function erpFila($id, $documento, $proveedor, $monto, $saldo = null, $corto = null, $listado = null)
{
    return [
        'id' => $id,
        'documento' => $documento,
        'numero_corto' => $corto,
        'proveedor_nombre' => $proveedor,
        'fecha_emision' => '2026-07-15',
        'monto' => $monto,
        'saldo' => $saldo === null ? $monto : $saldo,
        'semana_id' => $listado ? 9 : null,
        'porpagar_listado_id' => $listado,
    ];
}

function filaArchivo($numero, $proveedor, $saldo)
{
    return ['estado' => 'leida', 'numero' => $numero, 'proveedor' => $proveedor, 'saldo' => $saldo];
}

$CONS = '00200001010000045587';

// ── El consecutivo largo del archivo cruza contra `documento` ────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['resuelta'] === 1, 'el consecutivo de 20 dígitos encuentra la factura');
assertResolutor($r['ids'] === [10], 'devuelve el id del ERP para marcarlo');
assertResolutor($r['filas'][0]['erp']['documento'] === $CONS, 'los datos que salen son los del ERP');

// El sufijo correlativo no puede correr la ventana de veinte dígitos: si se
// leyera "los últimos 20 dígitos de todo", este caso fallaría en silencio.
assertResolutor($r['filas'][0]['factura_erp_id'] === 10, 'el sufijo -1377 no desplaza el consecutivo');

// ── El número interno corto cruza contra `numero_corto` ──────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339')]
);
assertResolutor($r['resumen']['resuelta'] === 1, 'el número interno del ERP también resuelve');

// numero_corto vacío: la llave se deriva del documento, que es uno de cada
// cuatro renglones del reporte real.
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-0000039547', 'PROVEEDOR X', 100.00)],
    [erpFila(12, '39547', 'PROVEEDOR X', 100.00, null, null)]
);
assertResolutor($r['resumen']['resuelta'] === 1, 'sin numero_corto la llave sale del documento');

// ── No está en el ERP ────────────────────────────────────────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-99999999999999999999-1', 'QUIEN SEA', 10.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['ausente'] === 1, 'lo que no está en el ERP se marca ausente');
assertResolutor($r['ids'] === [], 'una ausente no se marca en ninguna parte');
assertResolutor(strpos($r['filas'][0]['motivo'], 'reporte') !== false,
    'el motivo dice qué hacer: cargar el reporte que la incluya');

// ── Mismo número, dos proveedores: desempata el proveedor ────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339'),
        erpFila(12, 'FACT-12339', 'DISTRIBUIDORA LA FLORIDA S.A.', 800.00, null, '12339'),
    ]
);
assertResolutor($r['filas'][0]['factura_erp_id'] === 11, 'el proveedor separa dos facturas con el mismo número');

// ── Mismo número y mismo proveedor: desempata el saldo ───────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 500.00, null, '12339'),
        erpFila(12, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339'),
    ]
);
assertResolutor($r['filas'][0]['factura_erp_id'] === 12, 'el saldo separa lo que el proveedor no pudo');

// ── Ni el proveedor ni el saldo distinguen: no se elige ──────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, 800.00, '12339'),
        erpFila(12, 'FACT-12339', 'MERCORICA S.A.', 800.00, 800.00, '12339'),
    ]
);
assertResolutor($r['resumen']['ambigua'] === 1, 'sin forma de distinguir no se asigna al azar');
assertResolutor($r['ids'] === [], 'una ambigua no entra al pago');

// ── La misma factura dos veces en el archivo ─────────────────────
$r = PagoSemanalResolutor::resolver(
    [
        filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00),
        filaArchivo('FACT-' . $CONS, 'AGENCIAS JOP S.A.', 50000.00),
    ],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['resuelta'] === 1 && $r['resumen']['repetida'] === 1,
    'la segunda vez que viene la misma factura no la duplica');
assertResolutor($r['ids'] === [10], 'una factura entra al pago una sola vez');

// ── Ya la reclama otro pago ──────────────────────────────────────
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00, null, null, 7)],
    9
);
assertResolutor($r['resumen']['en_otro_pago'] === 1, 'no se le roba una factura al pago de otra semana');
assertResolutor(strpos($r['filas'][0]['motivo'], '#7') !== false, 'dice de qué pago es');

// Pero si el pago es el mismo que se está cargando, no hay conflicto: es
// recargar el archivo de la semana en curso, que es lo normal.
$r = PagoSemanalResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00, null, null, 9)],
    9
);
assertResolutor($r['resumen']['resuelta'] === 1, 'recargar el archivo de la misma semana no da conflicto');

// ── Las filas ilegibles pasan de largo ───────────────────────────
$r = PagoSemanalResolutor::resolver(
    [['estado' => 'error', 'motivo' => 'Número de documento vacío.', 'numero' => '', 'proveedor' => '', 'saldo' => 0]],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['error'] === 1, 'una fila ilegible se conserva como error');
assertResolutor($r['ids'] === [], 'una fila ilegible no marca nada');

// ── Tope defensivo ───────────────────────────────────────────────
try {
    PagoSemanalResolutor::resolver(array_fill(0, PagoSemanalResolutor::MAX_FILAS + 1, filaArchivo('X', 'Y', 1)), []);
    assertResolutor(false, 'un archivo abusivamente grande debe rechazarse');
} catch (InvalidArgumentException $e) {
    assertResolutor(true, 'tope de filas');
}

echo "OK: resolución del pago semanal contra Facturas ERP\n";
