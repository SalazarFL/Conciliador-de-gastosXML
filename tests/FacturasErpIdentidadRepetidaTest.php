<?php
/**
 * Dos facturas del reporte que resultan indistinguibles no tumban el archivo:
 * se van las dos a la bandeja de revisión y el resto se carga.
 *
 * El caso salió de un reporte real: el ERP imprime "sin número" como una
 * ristra de ceros —que el parser descarta a propósito, o todas las facturas
 * sin número colapsarían en una— y dos facturas del mismo proveedor, la misma
 * fecha y el mismo monto quedan entonces idénticas sobre el papel. Eran dos
 * filas que entre las dos movían cero colones de saldo, y dejaban sin cargar
 * las 8.581 facturas del archivo.
 */
require_once __DIR__ . '/../app/helpers/FacturasErpCsvParser.php';

function assertIdent($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertIdentIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ── Constructores de las bandas del reporte ────────────────────────────────
// El reporte no es una tabla: cada dato vive en una columna distinta según la
// banda, así que las filas se arman igual que las imprime el ERP.

function filaCsv(array $celdas)
{
    $fila = array_fill(0, 29, '');
    foreach ($celdas as $i => $v) {
        $fila[$i] = $v;
    }
    return '"' . implode('","', $fila) . '"';
}

function bandaProveedor($codigo, $nombre)
{
    return [
        filaCsv([0 => 'Proveedor', 3 => $codigo, 4 => $nombre]),
        filaCsv([1 => 'Documento', 6 => 'Fecha', 10 => 'Vence', 13 => 'Compra',
                 15 => 'Moneda', 19 => 'Monto', 21 => 'Saldo', 24 => 'Colones']),
    ];
}

function bandaSucursal($nombre)
{
    return [filaCsv([2 => 'Sucursal', 3 => $nombre])];
}

/** Una factura, con su línea de saldo en colones y, si va sin número, los ceros. */
function bandaFactura($documento, $emision, $vence, $monto, $saldo)
{
    $filas = [
        filaCsv([1 => 'F- ' . $documento, 7 => $emision, 12 => $vence, 14 => 'Exter',
                 16 => '¢', 20 => $monto, 26 => $saldo]),
        filaCsv([22 => $saldo]),
    ];
    if ($documento === '') {
        $filas[] = filaCsv([1 => '00000000000000000000000000000']);
    }
    return $filas;
}

function bandaTotal($monto, $saldo)
{
    return [filaCsv([11 => 'Total', 17 => $monto, 27 => $saldo])];
}

function bandaTotalProveedor($monto, $saldo)
{
    return [filaCsv([5 => 'Total Proveedor', 18 => $monto, 26 => $saldo])];
}

function bandaTotalGeneral($monto, $saldo)
{
    return [filaCsv([5 => 'Total General', 18 => $monto, 27 => $saldo])];
}

function reporte(array $bandas)
{
    return implode("\r\n", array_merge(...$bandas)) . "\r\n";
}

// ── Caso 1: dos filas idénticas sin número ─────────────────────────────────
//
// Las gemelas están pagadas (saldo 0) y una tercera factura del mismo
// proveedor sí tiene saldo: es la que tiene que entrar igual.

$gemela = function () {
    return bandaFactura('', '27/04/2026', '27/05/2026', '135,600.00', '0.00');
};

$texto = reporte([
    bandaProveedor('140000900', 'PROVEEDOR GEMELO S.A.'),
    bandaSucursal('Indefinido'),
    $gemela(),
    $gemela(),
    bandaFactura('5001', '30/04/2026', '30/05/2026', '100,000.00', '25,000.00'),
    bandaTotal('371,200.00', '25,000.00'),
    bandaTotalProveedor('371,200.00', '25,000.00'),
    bandaTotalGeneral('371,200.00', '25,000.00'),
]);

$r = FacturasErpCsvParser::parseTexto($texto, 'gemelas.csv');

// Lo que antes era un error que negaba el archivo entero.
assertIdentIgual([], $r['errores'], 'una identidad repetida ya no es un error del archivo');
assertIdent($r['ok'], 'el archivo se tiene que poder aplicar');
assertIdentIgual([], $r['no_reconocidas'], 'las gemelas se leyeron bien: no son filas ilegibles');

// Las dos gemelas salen del listado; la factura con saldo entra.
assertIdentIgual(1, count($r['facturas']), 'solo entra la factura que sí se puede identificar');
assertIdentIgual('5001', $r['facturas'][0]['documento'], 'la que entra es la numerada');

// Y las dos van a preguntar, no una: ninguna es "la primera".
assertIdentIgual(2, count($r['revision']), 'las dos filas del grupo van a la bandeja');

$firmas = array_column($r['revision'], 'firma');
assertIdentIgual(2, count(array_unique($firmas)),
    'cada gemela necesita su propia firma, o la bandeja se queda con una sola');

// El grupo es lo que las junta en pantalla: sin él se decidirían de a una, y
// "cuál de estas entra" no se puede contestar mirando una sola.
$grupos = array_column($r['revision'], 'grupo');
assertIdentIgual(1, count(array_unique($grupos)), 'las dos gemelas son un solo enredo');
assertIdentIgual('140000900|SINDOC|2026-04-27|135600.00', $grupos[0],
    'el grupo es la clave que las choca');

foreach ($r['revision'] as $linea) {
    assertIdent(strpos($linea['motivo'], 'El proveedor 140000900 trae 2 filas') === 0,
        'el motivo dice cuántas son y de quién: ' . $linea['motivo']);
    assertIdent(strpos($linea['motivo'], 'ninguna trae número de documento') !== false,
        'el motivo explica por qué no se distinguen');
    assertIdent(!empty($linea['celdas']), 'la bandeja guarda la fila cruda');
    assertIdent($linea['fila_origen'] > 0, 'la línea recuerda de qué fila del archivo salió');

    // Ya se leyó entera: quien revise solo tiene que ponerle el número.
    $campos = $linea['campos'];
    assertIdentIgual('140000900', $campos['proveedor_codigo'], 'campo prellenado: proveedor');
    assertIdentIgual('Indefinido', $campos['sucursal'], 'campo prellenado: sucursal');
    assertIdentIgual('2026-04-27', $campos['fecha_emision'], 'campo prellenado: emisión');
    assertIdentIgual('2026-05-27', $campos['fecha_vence'], 'campo prellenado: vencimiento');
    assertIdentIgual('135600.00', $campos['monto'], 'campo prellenado: monto');
    assertIdentIgual('0.00', $campos['saldo'], 'campo prellenado: saldo');
    assertIdentIgual('', $campos['documento'], 'el documento queda vacío: es lo que falta');
}

// El descuadre tiene que salir: lo que no entró es plata que el reporte
// imprime y el listado no tiene. Callarlo sería el descuadre cómodo.
$descuadres = $r['cuadre']['descuadres'];
assertIdent(count($descuadres) > 0, 'faltan dos filas: el cuadre lo tiene que decir');
$sucursal = null;
foreach ($descuadres as $d) { if ($d['ambito'] === 'sucursal') { $sucursal = $d; } }
assertIdent($sucursal !== null, 'el total de la sucursal no puede cuadrar sin las gemelas');
assertIdentIgual(271200.0, $sucursal['diferencia'], 'la diferencia es justo lo que se apartó');

// El saldo sí cuadra: las gemelas están pagadas y no mueven la columna que
// el módulo existe para vigilar.
assertIdentIgual(true, $r['cuadre']['saldo_general_ok'], 'el saldo general sigue cuadrando');

// ── Caso 2: tres iguales ───────────────────────────────────────────────────
// El grupo se aparta entero, no "las que sobran".

$texto3 = reporte([
    bandaProveedor('140000900', 'PROVEEDOR GEMELO S.A.'),
    bandaSucursal('Indefinido'),
    $gemela(),
    $gemela(),
    $gemela(),
    bandaTotal('406,800.00', '0.00'),
    bandaTotalProveedor('406,800.00', '0.00'),
    bandaTotalGeneral('406,800.00', '0.00'),
]);

$r3 = FacturasErpCsvParser::parseTexto($texto3, 'trillizas.csv');
assertIdent($r3['ok'], 'tres iguales tampoco tumban el archivo');
assertIdentIgual(0, count($r3['facturas']), 'ninguna de las tres se puede identificar');
assertIdentIgual(3, count($r3['revision']), 'las tres van a la bandeja');
assertIdentIgual(3, count(array_unique(array_column($r3['revision'], 'firma'))),
    'las tres necesitan firmas distintas');
assertIdent(strpos($r3['revision'][0]['motivo'], 'trae 3 filas') !== false,
    'el motivo cuenta las tres');

// ── Caso 3: el duplicado también puede venir numerado ──────────────────────
// Mismo proveedor, mismo documento y misma fecha de emisión: la clave se
// repite igual que sin número, y el motivo tiene que decir cuál documento.

$textoNum = reporte([
    bandaProveedor('140000901', 'PROVEEDOR CON NUMERO S.A.'),
    bandaSucursal('CEDI'),
    bandaFactura('00100001010000012117', '23/07/2026', '06/09/2026', '80,000.00', '80,000.00'),
    bandaFactura('00100001010000012117', '23/07/2026', '06/09/2026', '80,000.00', '80,000.00'),
    bandaTotal('160,000.00', '160,000.00'),
    bandaTotalProveedor('160,000.00', '160,000.00'),
    bandaTotalGeneral('160,000.00', '160,000.00'),
]);

$rn = FacturasErpCsvParser::parseTexto($textoNum, 'numeradas.csv');
assertIdent($rn['ok'], 'un duplicado numerado tampoco tumba el archivo');
assertIdentIgual(0, count($rn['facturas']), 'las dos numeradas iguales se apartan');
assertIdentIgual(2, count($rn['revision']), 'las dos van a la bandeja');
assertIdent(
    strpos($rn['revision'][0]['motivo'], 'el mismo documento (00100001010000012117)') !== false,
    'el motivo nombra el documento repetido: ' . $rn['revision'][0]['motivo']
);
assertIdentIgual('00100001010000012117', $rn['revision'][0]['campos']['documento'],
    'el número que sí trae se conserva prellenado');

// ── Caso 4: sin duplicados, nada cambia ────────────────────────────────────

$limpio = reporte([
    bandaProveedor('140000902', 'PROVEEDOR LIMPIO S.A.'),
    bandaSucursal('CEDI'),
    bandaFactura('7001', '01/06/2026', '01/07/2026', '10,000.00', '10,000.00'),
    bandaFactura('7002', '02/06/2026', '02/07/2026', '20,000.00', '0.00'),
    bandaTotal('30,000.00', '10,000.00'),
    bandaTotalProveedor('30,000.00', '10,000.00'),
    bandaTotalGeneral('30,000.00', '10,000.00'),
]);

$rl = FacturasErpCsvParser::parseTexto($limpio, 'limpio.csv');
assertIdent($rl['ok'], 'un reporte sin duplicados se lee igual que siempre');
assertIdentIgual(2, count($rl['facturas']), 'entran las dos facturas');
assertIdentIgual(0, count($rl['revision']), 'no hay nada que preguntar');
assertIdentIgual(0, count($rl['cuadre']['descuadres']), 'y cuadra contra sus totales');

echo "OK: FacturasErpIdentidadRepetida\n";
