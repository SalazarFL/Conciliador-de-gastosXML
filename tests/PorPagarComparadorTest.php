<?php
require_once __DIR__ . '/../app/helpers/PorPagarComparador.php';

function assertComparador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$actuales = [
    ['id' => 1, 'fecha' => '2026-04-01', 'numero' => 'FAC-100', 'proveedor_texto' => 'Proveedor Uno S.A.', 'total' => 100.00],
    ['id' => 2, 'fecha' => '2026-04-02', 'numero' => 'FAC-200', 'proveedor_texto' => 'Proveedor Dos', 'total' => 200.00],
    ['id' => 3, 'fecha' => '2026-04-03', 'numero' => 'FAC-300', 'proveedor_texto' => 'Proveedor Tres', 'total' => 300.00],
    ['id' => 4, 'fecha' => '2026-04-07', 'numero' => 'FAC-777', 'proveedor_texto' => 'Proveedor A', 'total' => 777.00],
];
$entrantes = [
    ['estado' => 'repetida', 'fecha' => '2026-04-01', 'numero' => 'FAC-100', 'proveedor' => 'Proveedor Uno S.A.', 'total' => 100.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-05', 'numero' => 'FAC-200', 'proveedor' => 'Proveedor Dos S.A.', 'total' => 250.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-04', 'numero' => 'FAC-400', 'proveedor' => 'Proveedor Cuatro', 'total' => 400.00],
    ['estado' => 'repetida', 'fecha' => '2026-04-04', 'numero' => 'FAC-400', 'proveedor' => 'Proveedor Cuatro', 'total' => 400.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-07', 'numero' => 'FAC-777', 'proveedor' => 'Proveedor B', 'total' => 777.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-08', 'numero' => 'FAC-888', 'proveedor' => 'Proveedor X', 'total' => 888.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-08', 'numero' => 'FAC-888', 'proveedor' => 'Proveedor Y', 'total' => 888.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-09', 'numero' => 'SERIE-A-100', 'proveedor' => 'Proveedor Serie', 'total' => 100.00],
    ['estado' => 'nueva', 'fecha' => '2026-04-09', 'numero' => 'SERIE-B-100', 'proveedor' => 'Proveedor Serie', 'total' => 100.00],
    ['estado' => 'error', 'motivo' => 'Total invalido.', 'fecha' => null, 'numero' => 'FAC-500', 'proveedor' => 'Proveedor Cinco', 'total' => 0.0],
];

$resultado = PorPagarComparador::comparar($actuales, $entrantes);
$resumen = $resultado['resumen'];

assertComparador($resumen['igual'] === 1, 'reconoce la factura sin cambios');
assertComparador($resumen['modificada'] === 1, 'reconoce la factura modificada');
assertComparador($resumen['nueva'] === 6, 'reconoce las facturas nuevas sin unir proveedores o series distintas');
assertComparador($resumen['duplicada'] === 1, 'reconoce el duplicado dentro del archivo');
assertComparador($resumen['faltante'] === 2, 'informa las facturas que faltan en el archivo nuevo');
assertComparador($resumen['error'] === 1, 'conserva las filas ilegibles');

$modificada = array_values(array_filter($resultado['lineas'], fn($l) => $l['estado'] === 'modificada'))[0];
assertComparador(isset($modificada['cambios']['proveedor']), 'detalla el proveedor anterior y nuevo');
assertComparador(isset($modificada['cambios']['fecha']), 'detalla la fecha anterior y nueva');
assertComparador(isset($modificada['cambios']['total']), 'detalla el total anterior y nuevo');
assertComparador($modificada['anterior']['total'] === 200.0, 'incluye los datos actuales de la semana');

$faltante = array_values(array_filter($resultado['lineas'], fn($l) => $l['estado'] === 'faltante'))[0];
assertComparador($faltante['numero'] === 'FAC-300', 'identifica cual factura actual no vino');
assertComparador(strpos($faltante['motivo'], 'No se eliminará') !== false, 'aclara que comparar no elimina');

$numeroCompartido = array_values(array_filter(
    $resultado['lineas'],
    fn($l) => $l['numero'] === 'FAC-777'
));
assertComparador(count($numeroCompartido) === 2, 'conserva ambas facturas cuando dos proveedores comparten numero');
assertComparador(
    array_column($numeroCompartido, 'estado') === ['nueva', 'faltante'],
    'un numero y monto iguales no bastan para unir proveedores distintos'
);

$mismoNumeroMonto = array_values(array_filter(
    $resultado['lineas'],
    fn($l) => $l['numero'] === 'FAC-888'
));
assertComparador(
    array_column($mismoNumeroMonto, 'estado') === ['nueva', 'nueva'],
    'dos proveedores del archivo pueden usar el mismo numero y monto sin ser duplicados'
);

$series = array_values(array_filter(
    $resultado['lineas'],
    fn($l) => str_starts_with($l['numero'], 'SERIE-')
));
assertComparador(
    array_column($series, 'estado') === ['nueva', 'nueva'],
    'dos series con el mismo nucleo numerico no se consideran duplicadas'
);

$formatoNumero = PorPagarComparador::comparar(
    [['fecha' => '2026-04-10', 'numero' => '71176', 'proveedor_texto' => 'Proveedor Formato', 'total' => 50.00]],
    [['estado' => 'nueva', 'fecha' => '2026-04-10', 'numero' => 'FAC-71176-123456', 'proveedor' => 'Proveedor Formato', 'total' => 50.00]]
);
assertComparador(
    $formatoNumero['resumen']['modificada'] === 1,
    'mantiene el matching cuando el numero corto aparece como secuencia del formato largo'
);

try {
    PorPagarComparador::comparar([], array_fill(0, PorPagarComparador::MAX_LINEAS + 1, []));
    assertComparador(false, 'rechaza un archivo demasiado grande');
} catch (InvalidArgumentException $e) {
    assertComparador(strpos($e->getMessage(), '10,000') !== false, 'explica el limite del comparador');
}

echo "OK: Comparador de listados por pagar\n";
