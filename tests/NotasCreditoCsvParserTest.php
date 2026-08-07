<?php
require_once __DIR__ . '/../app/helpers/NotasCreditoCsvParser.php';

function assertSameNc($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nEsperado: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$result = NotasCreditoCsvParser::parse(__DIR__ . '/fixtures/notas_credito_reporte.csv');

assertSameNc('Grupo BM SP S.A', $result['empresa'], 'empresa');
assertSameNc('2026-01-01', $result['periodo_desde'], 'inicio del período');
assertSameNc('2026-07-23', $result['periodo_hasta'], 'fin del período');
assertSameNc(2, $result['estadisticas']['total'], 'cantidad de notas');
assertSameNc(1, $result['estadisticas']['proveedores'], 'cantidad de proveedores');
assertSameNc(1, $result['estadisticas']['sucursales'], 'cantidad de sucursales');
assertSameNc(1, $result['estadisticas']['crc'], 'cantidad CRC');
assertSameNc(1, $result['estadisticas']['usd'], 'cantidad USD');
assertSameNc([], $result['errores'], 'errores del parser');
assertSameNc(
    'NC- 1-1-00100001030000000001-1',
    $result['lineas'][0]['documento'],
    'reconstrucción de documento partido'
);
assertSameNc(
    '00100001030000000002',
    $result['lineas'][1]['nc_proveedor'],
    'NC Proveedor'
);
assertSameNc('2026-07-22', $result['lineas'][1]['fecha_nc_proveedor'], 'fecha NC Proveedor');
assertSameNc('ENTRADA-2', $result['lineas'][1]['entrada_asociada'], 'entrada asociada');
assertSameNc('USD', $result['lineas'][1]['moneda'], 'moneda dólar en formato contable');
assertSameNc(50.85, $result['lineas'][1]['monto'], 'monto USD');

$standardResult = NotasCreditoCsvParser::parse(
    __DIR__ . '/fixtures/notas_credito_reporte_estandar.csv'
);

assertSameNc(1, $standardResult['estadisticas']['total'], 'cantidad de notas en CSV estándar');
assertSameNc([], $standardResult['errores'], 'errores del CSV estándar');
assertSameNc(
    'NC- 1-1-00100001030000000003-1',
    $standardResult['lineas'][0]['documento'],
    'documento partido en CSV estándar'
);
assertSameNc('PROVEEDOR ESTANDAR S.A.', $standardResult['lineas'][0]['proveedor_nombre'], 'proveedor estándar');
assertSameNc('CRC', $standardResult['lineas'][0]['moneda'], 'moneda del CSV estándar');
assertSameNc(1234.56, $standardResult['lineas'][0]['monto'], 'monto del CSV estándar');

echo "OK: NotasCreditoCsvParser\n";
