<?php
/**
 * El fixture reproduce las trampas reales del reporte "Facturas por Proveedor":
 * el pie de página superpuesto sobre una fila de datos (tanto sobre un cambio
 * de sucursal como sobre una factura entera), facturas sin número con el
 * marcador de ceros, y un mismo número repetido con distinta fecha.
 */
require_once __DIR__ . '/../app/helpers/FacturasErpCsvParser.php';

function assertErp($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertErpIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$r = FacturasErpCsvParser::parseArchivo(__DIR__ . '/fixtures/facturas_erp_reporte.csv');
$f = $r['facturas'];

// ── Lectura completa ──
assertErp($r['ok'], 'el parser debe terminar sin errores');
assertErpIgual([], $r['no_reconocidas'], 'ninguna fila puede quedar sin clasificar');
assertErpIgual(8, count($f), 'cantidad de facturas leídas');
assertErpIgual(3, $r['meta']['proveedores'], 'cantidad de proveedores');

// ── El reporte se cuadra contra sus propios totales ──
assertErp($r['cuadre']['ok'], 'los totales impresos deben cuadrar con lo leído');
assertErpIgual(8, $r['cuadre']['verificados'],
    'totales verificados (4 de sucursal + 3 de proveedor + el Total General)');
assertErpIgual(8, $r['cuadre']['exactos'], 'todos los totales del fixture son exactos');

// El "Total General" del pie cubre el archivo entero de una sola vez.
assertErpIgual(true, $r['cuadre']['saldo_general_ok'], 'el saldo debe cuadrar con el Total General');
assertErpIgual(371200.0, $r['cuadre']['saldo_leido'], 'suma de los saldos leídos');
assertErpIgual(371200.0, $r['cuadre']['saldo_general_impreso'], 'saldo del Total General impreso');

// ── Metadata que viaja dentro del pie de página ──
assertErpIgual('2026-08-06 17:04:58', $r['meta']['impreso_en'], 'fecha de impresión del reporte');
assertErp(strpos((string) $r['meta']['rango_texto'], 'Del 1 de Mayo') === 0, 'periodo declarado');

// ── Trampa 1: el pie se traga el cambio de sucursal ──
// "Usuario: X | Sucursal | CEDI | Página: 92 | Impreso: ..."
$doc12345 = null;
foreach ($f as $x) { if ($x['documento'] === '00100001010000012345') { $doc12345 = $x; } }
assertErp($doc12345 !== null, 'la factura posterior al pie debe leerse');
assertErpIgual('CEDI', $doc12345['sucursal'],
    'el cambio de sucursal incrustado en el pie no se puede perder');

// ── Trampa 2: el pie se traga una factura entera ──
// "Usuario: X | F- 001...346 | 21/07/2026 | Página: 67 | 20/08/2026 | ..."
$doc12346 = null;
foreach ($f as $x) { if ($x['documento'] === '00100001010000012346') { $doc12346 = $x; } }
assertErp($doc12346 !== null, 'la factura incrustada en el pie no se puede perder');
assertErpIgual(300000.0, $doc12346['monto'], 'monto de la factura incrustada en el pie');
assertErpIgual('CEDI', $doc12346['sucursal'], 'sucursal de la factura incrustada en el pie');

// ── Facturas sin número: los ceros son un marcador, no un documento ──
$sinNumero = array_values(array_filter($f, function ($x) { return $x['documento'] === ''; }));
assertErpIgual(2, count($sinNumero), 'facturas sin número de documento');
assertErp(
    $sinNumero[0]['clave'] !== $sinNumero[1]['clave'],
    'dos facturas sin número el mismo día deben distinguirse por su monto'
);
assertErpIgual('140000578|SINDOC|2026-05-09|47616.23', $sinNumero[0]['clave'], 'clave de la factura sin número');

// ── El número solo no identifica: hace falta la fecha de emisión ──
$repetidas = array_values(array_filter($f, function ($x) {
    return $x['documento'] === '00100001010000002395';
}));
assertErpIgual(2, count($repetidas), 'el mismo número aparece dos veces');
assertErp($repetidas[0]['clave'] !== $repetidas[1]['clave'],
    'el mismo número con distinta fecha son dos facturas distintas');

// ── Todas las claves del archivo son únicas ──
$claves = array_column($f, 'clave');
assertErpIgual(count($claves), count(array_unique($claves)), 'las claves no se pueden repetir');

// ── Columnas normalizadas ──
$uno = $f[0];
assertErpIgual('140000003', $uno['proveedor_codigo'], 'código de proveedor');
assertErpIgual('PROVEEDOR UNO S.A.', $uno['proveedor_nombre'], 'nombre de proveedor');
assertErpIgual('2026-05-12', $uno['fecha_emision'], 'fecha de emisión convertida a formato SQL');
assertErpIgual('2026-06-11', $uno['fecha_vence'], 'fecha de vencimiento convertida a formato SQL');
assertErpIgual('Exter', $uno['origen'], 'origen de la compra');
assertErpIgual(100000.0, $uno['monto'], 'monto sin separador de miles');
assertErpIgual(0.0, $uno['saldo'], 'saldo sin separador de miles');

// Solo el consecutivo electrónico de 20 dígitos cruza contra los XML.
assertErpIgual('00012345', $doc12345['numero_corto'], 'número corto de 8 dígitos del consecutivo');
assertErpIgual(null, $uno['numero_corto'], 'un número manual no produce número corto');

// ── Incidencias detectadas al leer ──
$porTipo = [];
foreach ($r['incidencias'] as $i) {
    $porTipo[$i['tipo']] = ($porTipo[$i['tipo']] ?? 0) + 1;
}

// Los dos documentos repetidos generan una incidencia cada uno (2 facturas).
assertErpIgual(2, $porTipo['numero_duplicado'] ?? 0, 'incidencias por número repetido');
assertErpIgual(2, $porTipo['sin_numero'] ?? 0, 'incidencias por factura sin número');
assertErp(!isset($porTipo['descuadre_total']), 'el fixture cuadra, no debe haber descuadres');
assertErp(!isset($porTipo['moneda_extranjera']), 'el fixture es todo en colones');

// La incidencia de duplicado debe decir cuánto saldo está en juego.
$dup = null;
foreach ($r['incidencias'] as $i) { if ($i['tipo'] === 'numero_duplicado') { $dup = $i; break; } }
assertErpIgual('alerta', $dup['severidad'], 'un número repetido es alerta, no aviso');
assertErp(strpos($dup['detalle'], 'aparece 2 veces') !== false, 'el detalle indica cuántas veces aparece');
assertErp(strpos($dup['detalle'], '113,000.00') !== false, 'el detalle suma el saldo pendiente en juego');

// La de "sin número" explica cómo se identifica esa factura.
$sn = null;
foreach ($r['incidencias'] as $i) { if ($i['tipo'] === 'sin_numero') { $sn = $i; break; } }
assertErpIgual('aviso', $sn['severidad'], 'una factura sin número es aviso');
assertErpIgual('140000578', $sn['proveedor_codigo'], 'la incidencia conserva el proveedor');

// Todas las incidencias declaran un tipo conocido y una severidad válida.
foreach ($r['incidencias'] as $i) {
    assertErp(isset(FacturasErpCsvParser::TIPOS_INCIDENCIA[$i['tipo']]),
        'tipo de incidencia no catalogado: ' . $i['tipo']);
    assertErp(in_array($i['severidad'], ['alerta', 'aviso'], true),
        'severidad inválida en ' . $i['tipo']);
}

// Un descuadre de totales también tiene que quedar como incidencia.
$conDescuadre = FacturasErpCsvParser::analizarIncidencias([], ['descuadres' => [[
    'ambito' => 'proveedor', 'proveedor' => '140000984', 'sucursal' => '',
    'leido_monto' => 39035.85, 'impreso_monto' => 62325.15, 'diferencia' => 23289.30,
]]]);
assertErpIgual(1, count($conDescuadre), 'un descuadre produce una incidencia');
assertErpIgual('descuadre_total', $conDescuadre[0]['tipo'], 'tipo de la incidencia de descuadre');
assertErpIgual('alerta', $conDescuadre[0]['severidad'], 'un descuadre es alerta');

// ── Un archivo que no es este reporte no debe pasar por válido ──
$malo = FacturasErpCsvParser::parseTexto("una,cosa,cualquiera\n1,2,3\n", 'otro.csv');
assertErp(!$malo['ok'], 'un CSV ajeno no puede darse por bueno');
assertErpIgual([], $malo['facturas'], 'un CSV ajeno no produce facturas');

echo "OK: FacturasErpCsvParser\n";
