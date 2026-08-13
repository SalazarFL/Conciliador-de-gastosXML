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

// ── El nombre guardado con basura pegada ───────────────────────
// Durante meses las anotaciones a mano del reporte entraron pegadas al
// nombre del proveedor. Al limpiar la lectura, esas facturas dejaron de
// reconocerse y 82 renglones ya cargados de la semana 130826 salieron como
// "nuevas": peor que el problema original, porque invita a duplicarlos.
$anotado = PorPagarComparador::comparar(
    [['fecha' => '2026-06-08', 'numero' => 'FACT-00100001010000062756-55',
      'proveedor_texto' => 'MARJAVA SUPERMERCADOS S.A YA DESCARGADO Y REVISADO', 'total' => 149328.68]],
    [['estado' => 'nueva', 'fecha' => '2026-06-08', 'numero' => 'FACT-00100001010000062756-55',
      'proveedor' => 'MARJAVA SUPERMERCADOS S.A', 'total' => 149328.68]]
);
assertComparador(
    $anotado['resumen']['nueva'] === 0,
    'una factura ya cargada no vuelve a ser nueva porque le limpiaron el nombre al proveedor'
);

// El ERP corta el nombre al ancho de la columna: el mismo proveedor viene
// entero en un archivo y truncado en otro.
$truncado = PorPagarComparador::comparar(
    [['fecha' => '2026-07-13', 'numero' => 'FACT-00100001010000081822-1159',
      'proveedor_texto' => 'COMERCIO E INDUSTRIA GIMA SOCIEDAD', 'total' => 520866.72]],
    [['estado' => 'nueva', 'fecha' => '2026-07-13', 'numero' => 'FACT-00100001010000081822-1159',
      'proveedor' => 'COMERCIO E INDUSTRIA GIMA SOCIEDAD ANONIMA', 'total' => 520866.72]]
);
assertComparador($truncado['resumen']['nueva'] === 0, 'el nombre truncado por el ERP sigue siendo el mismo proveedor');

// Pero la tolerancia no puede tragarse un proveedor distinto: eso es lo que
// pasó cuando una factura heredó el nombre del grupo anterior, y tiene que
// salir a la vista, no taparse.
$distinto = PorPagarComparador::comparar(
    [['fecha' => '2026-08-06', 'numero' => 'FACT-00100001010000000552',
      'proveedor_texto' => 'RIVERA CHAVARRIA CARLOS ALBERT', 'total' => 48025.00]],
    [['estado' => 'nueva', 'fecha' => '2026-08-06', 'numero' => 'FACT-00100001010000000552',
      'proveedor' => 'ROBLES CARMONA RONALDO', 'total' => 48025.00]]
);
assertComparador($distinto['resumen']['nueva'] === 1, 'dos proveedores distintos siguen siendo distintos');

// Compartir la primera palabra no alcanza.
$primera = PorPagarComparador::comparar(
    [['fecha' => '2026-08-06', 'numero' => 'FACT-991', 'proveedor_texto' => 'GRUPO BM', 'total' => 100.00]],
    [['estado' => 'nueva', 'fecha' => '2026-08-06', 'numero' => 'FACT-991',
      'proveedor' => 'GRUPO NACION', 'total' => 100.00]]
);
assertComparador($primera['resumen']['nueva'] === 1, 'compartir la primera palabra no los hace el mismo proveedor');

try {
    PorPagarComparador::comparar([], array_fill(0, PorPagarComparador::MAX_LINEAS + 1, []));
    assertComparador(false, 'rechaza un archivo demasiado grande');
} catch (InvalidArgumentException $e) {
    assertComparador(strpos($e->getMessage(), '10,000') !== false, 'explica el limite del comparador');
}

echo "OK: Comparador de listados por pagar\n";
