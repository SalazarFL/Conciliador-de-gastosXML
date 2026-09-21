<?php
/**
 * Resolución del archivo de pago contra Facturas ERP.
 *
 * Este es el corazón del módulo desde que el pago dejó de guardar los
 * datos del archivo. Antes el archivo ERA el listado: lo que dijera, eso valía.
 * Ahora el archivo solo dice CUÁLES facturas se pagan, y la factura del ERP
 * pone los datos. Si esta resolución falla, el pago carga facturas que no son.
 *
 * Lo que se comprueba es sobre todo cuándo NO se resuelve: preferir dejar una
 * fila sin resolver a asignar la factura equivocada es la regla de la casa,
 * porque una asignación errónea paga lo que no se debe.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/PagoResolutor.php';

function assertResolutor($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function erpFila($id, $documento, $proveedor, $monto, $saldo = null, $corto = null, $listado = null, $codigo = '')
{
    return [
        'id' => $id,
        'documento' => $documento,
        'numero_corto' => $corto,
        'proveedor_codigo' => $codigo,
        'proveedor_nombre' => $proveedor,
        'fecha_emision' => '2026-07-15',
        'monto' => $monto,
        'saldo' => $saldo === null ? $monto : $saldo,
        'pago_id' => $listado,
    ];
}

function filaArchivo($numero, $proveedor, $saldo, $codigo = '')
{
    return [
        'estado' => 'leida',
        'numero' => $numero,
        'proveedor' => $proveedor,
        'codigo' => $codigo,
        'saldo' => $saldo,
    ];
}

$CONS = '00200001010000045587';

// ── El consecutivo largo del archivo cruza contra `documento` ────
$r = PagoResolutor::resolver(
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
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339')]
);
assertResolutor($r['resumen']['resuelta'] === 1, 'el número interno del ERP también resuelve');

// numero_corto vacío: la llave se deriva del documento, que es uno de cada
// cuatro renglones del reporte real.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-0000039547', 'PROVEEDOR X', 100.00)],
    [erpFila(12, '39547', 'PROVEEDOR X', 100.00, null, null)]
);
assertResolutor($r['resumen']['resuelta'] === 1, 'sin numero_corto la llave sale del documento');

// ── No está en el ERP ────────────────────────────────────────────
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-99999999999999999999-1', 'QUIEN SEA', 10.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['ausente'] === 1, 'lo que no está en el ERP se marca ausente');
assertResolutor($r['ids'] === [], 'una ausente no se marca en ninguna parte');
assertResolutor(strpos($r['filas'][0]['motivo'], 'reporte') !== false,
    'el motivo dice qué hacer: cargar el reporte que la incluya');

// ── Mismo número, dos proveedores: desempata el proveedor ────────
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339'),
        erpFila(12, 'FACT-12339', 'DISTRIBUIDORA LA FLORIDA S.A.', 800.00, null, '12339'),
    ]
);
assertResolutor($r['filas'][0]['factura_erp_id'] === 11, 'el proveedor separa dos facturas con el mismo número');

// ── El código del proveedor manda sobre el nombre ────────────────
// El ERP imprime los nombres cortados y a su manera, así que dos grupos
// distintos pueden parecerse. El código no se parece: es igual o no lo es.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA SOCIEDAD ANONIMA', 800.00, '140000283')],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339', null, '140000283'),
        erpFila(12, 'FACT-12339', 'MERCORICA DEL SUR S.A.', 800.00, null, '12339', null, '140000999'),
    ]
);
assertResolutor($r['filas'][0]['factura_erp_id'] === 11, 'el código separa dos proveedores de nombre parecido');

// El monto ya no desempata: dos facturas del mismo código con el mismo número
// son indistinguibles aunque una valga lo que dice el archivo. Antes se elegía
// la del monto más cercano, que es elegir por una coincidencia.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00, '140000283')],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 500.00, null, '12339', null, '140000283'),
        erpFila(12, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339', null, '140000283'),
    ]
);
assertResolutor($r['resumen']['ambigua'] === 1, 'el monto no desempata dos facturas del mismo código');
assertResolutor($r['ids'] === [], 'una ambigua no entra al pago');

// ── El número es de otro código: ausente, no ambigua ─────────────
// No es un empate: ese número, en el sistema, le pertenece a otro proveedor.
// El motivo nombra al dueño porque lo que hay que corregir está en el archivo
// o en el reporte del ERP, no en esta pantalla.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00, '140000283')],
    [erpFila(11, 'FACT-12339', 'DISTRIBUIDORA LA FLORIDA S.A.', 800.00, null, '12339', null, '140000777')]
);
assertResolutor($r['resumen']['ausente'] === 1, 'un número de otro código no se asigna');
assertResolutor(strpos($r['filas'][0]['motivo'], '140000777') !== false,
    'el motivo nombra al código que sí tiene esa factura');
assertResolutor(strpos($r['filas'][0]['motivo'], '140000283') !== false,
    'y el que venía en el archivo, para poder compararlos');

// ── Sin código en el archivo se cae al nombre, como antes ────────
// Es el camino de las tablas armadas a mano y de los archivos viejos.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339', null, '140000283'),
        erpFila(12, 'FACT-12339', 'DISTRIBUIDORA LA FLORIDA S.A.', 800.00, null, '12339', null, '140000777'),
    ]
);
assertResolutor($r['filas'][0]['factura_erp_id'] === 11, 'sin código, el nombre sigue separando');

// Pero sin código y con el mismo nombre ya no queda nada: antes lo resolvía el
// saldo, y resolverlo así es lo que se quitó.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00)],
    [
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 500.00, null, '12339', null, '140000283'),
        erpFila(12, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339', null, '140000283'),
    ]
);
assertResolutor($r['resumen']['ambigua'] === 1, 'sin forma de distinguir no se asigna al azar');
assertResolutor($r['ids'] === [], 'una ambigua no entra al pago');

// ── El reporte la lista sin monto: no se paga ────────────────────
// Quien arma el pago borra el monto de lo que deja fuera, y el subtotal del
// grupo lo confirma. Se cuenta para poder verla antes de confirmar, pero no
// entra al pago ni impide cargarlo.
$r = PagoResolutor::resolver(
    [
        ['estado' => 'sin_monto', 'numero' => 'FACT-' . $CONS, 'proveedor' => 'AGENCIAS JOP S.A.',
         'codigo' => '140000014', 'saldo' => 0.0],
        filaArchivo('FACT-12339', 'MERCORICA S.A.', 800.00, '140000283'),
    ],
    [
        erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00, null, null, null, '140000014'),
        erpFila(11, 'FACT-12339', 'MERCORICA S.A.', 800.00, null, '12339', null, '140000283'),
    ]
);
assertResolutor($r['resumen']['sin_monto'] === 1, 'la fila sin monto se cuenta aparte');
assertResolutor($r['ids'] === [11], 'y no entra al pago, aunque su factura exista en el sistema');
assertResolutor($r['resumen']['ausente'] === 0 && $r['resumen']['ambigua'] === 0,
    'una fila sin monto no es un problema que impida cargar');

// ── La misma factura dos veces en el archivo ─────────────────────
$r = PagoResolutor::resolver(
    [
        filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00),
        filaArchivo('FACT-' . $CONS, 'AGENCIAS JOP S.A.', 50000.00),
    ],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['resuelta'] === 1 && $r['resumen']['repetida'] === 1,
    'la segunda vez que viene la misma factura no la duplica');
assertResolutor($r['ids'] === [10], 'una factura entra al pago una sola vez');

// ── Ya la tiene el pago de otro pago ───────────────────────────
// Antes esto cortaba la carga entera y había que ir a soltar la factura a
// mano en la otro pago. Una factura se paga una sola vez: dos pagos que la
// reclaman no son dos verdades, es que se movió, y el archivo que se está
// cargando es el más reciente.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00, null, null, 7)],
    9
);
assertResolutor($r['resumen']['resuelta'] === 1, 'la que estaba en otro pago se resuelve igual');
assertResolutor($r['ids'] === [10], 'y entra al pago que se está cargando');
assertResolutor($r['resumen']['reasignada'] === 1, 'se cuenta aparte que viene de otro pago');
assertResolutor($r['filas'][0]['movida_desde'] === 7, 'la fila dice de qué pago se la lleva');
assertResolutor(strpos($r['filas'][0]['motivo'], '#7') !== false, 'y el motivo lo nombra');

// Si el pago es el mismo que se está cargando, no se movió nada: es recargar
// el archivo del pago en curso, que es lo normal. Marcarla como movida
// haría que la carga suelte su saldo congelado sin motivo.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00, null, null, 9)],
    9
);
assertResolutor($r['resumen']['resuelta'] === 1, 'recargar el archivo del mismo pago no da conflicto');
assertResolutor($r['resumen']['reasignada'] === 0 && $r['filas'][0]['movida_desde'] === null,
    'recargar el mismo pago no cuenta como mover');

// Una factura suelta —sin pago— tampoco viene de ningún lado.
$r = PagoResolutor::resolver(
    [filaArchivo('FACT-' . $CONS . '-1377', 'AGENCIAS JOP S.A.', 50000.00)],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)],
    9
);
assertResolutor($r['resumen']['reasignada'] === 0 && $r['filas'][0]['movida_desde'] === null,
    'una factura que no estaba en ningún pago no se marca como movida');

// ── Las filas ilegibles pasan de largo ───────────────────────────
$r = PagoResolutor::resolver(
    [['estado' => 'error', 'motivo' => 'Número de documento vacío.', 'numero' => '', 'proveedor' => '', 'saldo' => 0]],
    [erpFila(10, $CONS, 'AGENCIAS JOP S.A.', 50000.00)]
);
assertResolutor($r['resumen']['error'] === 1, 'una fila ilegible se conserva como error');
assertResolutor($r['ids'] === [], 'una fila ilegible no marca nada');

// ── Tope defensivo ───────────────────────────────────────────────
try {
    PagoResolutor::resolver(array_fill(0, PagoResolutor::MAX_FILAS + 1, filaArchivo('X', 'Y', 1)), []);
    assertResolutor(false, 'un archivo abusivamente grande debe rechazarse');
} catch (InvalidArgumentException $e) {
    assertResolutor(true, 'tope de filas');
}

echo "OK: resolución del pago contra Facturas ERP\n";
