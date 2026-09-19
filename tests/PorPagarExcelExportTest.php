<?php
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

function assertPorPagarExcel($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$archivo = XlsxWriter::generate(
    ['Fecha', 'Número', 'Proveedor', 'Total listado', 'Número XML', 'Total XML', 'Diferencia', 'Estado'],
    [['2026-07-29', 'FAC-100', 'Proveedor Demo', 1500.25, '00017662', 1500.25, 0, 'Respaldada']],
    'Facturas por pagar',
    [[7 => 3]],
    [13, 20, 38, 16, 22, 16, 16, 18]
);

assertPorPagarExcel(is_file($archivo), 'genera un archivo temporal XLSX');

$zip = new ZipArchive();
assertPorPagarExcel($zip->open($archivo) === true, 'el archivo generado es un contenedor XLSX válido');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$workbook = (string) $zip->getFromName('xl/workbook.xml');
$sharedStrings = (string) $zip->getFromName('xl/sharedStrings.xml');
$zip->close();
@unlink($archivo);

assertPorPagarExcel(strpos($sheet, '<autoFilter ref="A1:H2"/>') !== false, 'incluye autofiltro para las ocho columnas');
assertPorPagarExcel(strpos($workbook, 'Facturas por pagar') !== false, 'usa el nombre esperado para la hoja');
assertPorPagarExcel(strpos($sharedStrings, '<t>00017662</t>') !== false,
    'conserva los ceros iniciales del número XML de ocho dígitos');

/*
 * Lo que baja el Excel ya no está escrito dentro del controlador: las columnas
 * del pago viven en el catálogo (app/config/columnas.php) y el
 * controlador solo dice "bajá este listado con estas columnas". Así que lo que
 * se comprueba de aquí abajo es el catálogo, que es donde de verdad se decide,
 * y se comprueba por lo que HACE y no por cómo está escrito.
 */
require_once __DIR__ . '/../app/helpers/Columnas.php';

$controlador = (string) file_get_contents(__DIR__ . '/../app/controllers/PorPagarController.php');
$inicio = strpos($controlador, 'public function exportar()');
$fin = strpos($controlador, 'private function ejecutarMatching', $inicio);
$metodo = substr($controlador, $inicio, $fin - $inicio);

assertPorPagarExcel(strpos($metodo, "Columnas::descargar('porpagar'") !== false,
    'la exportación por pagar baja por el catálogo de columnas');
assertPorPagarExcel(strpos($metodo, 'text/csv') === false && strpos($metodo, 'fputcsv') === false,
    'la exportación ya no genera CSV');

// La línea de ejemplo con la que se miran las columnas.
$linea = [
    'estado' => 'respaldada',
    'fecha_emision' => '2026-07-29',
    'documento' => 'FAC-100',
    'proveedor_nombre' => 'Proveedor Demo',
    'sucursal' => 'CEDI',
    'monto' => 1500.25,
    'saldo_pago' => 900.00,
    'saldo' => 0.00,          // el vivo: bajó a cero al pagar
    'xml_numero' => '17662',
    'xml_total' => 1500.25,
    'diferencia' => 0,
];

$catalogo = Columnas::catalogo('porpagar');
$titulos = array_map(static function ($c) { return $c['titulo']; }, $catalogo);

// Monto y saldo son dos cosas, y las dos bajan.
//
// El monto es el que cierra la fila: la diferencia que sale al lado es
// monto − total del XML. El saldo estuvo fuera un tiempo —en medio de esos dos
// números la fila no se leía— y volvió en columna propia, que es donde no
// estorba: mirar lo que se debe es justamente para lo que se abre un pago.
assertPorPagarExcel(in_array('Monto del sistema', $titulos, true) && in_array('Saldo', $titulos, true),
    'el catálogo conserva las dos columnas: el monto de la factura y su saldo');

$soloSaldo = Columnas::tabla('porpagar', [$linea], 'saldo');
assertPorPagarExcel($soloSaldo['filas'][0][0] === 900.0,
    'el saldo que baja es el congelado al entrar al pago, no el de hoy');

$soloXml = Columnas::tabla('porpagar', [$linea], 'xml');
assertPorPagarExcel($soloXml['filas'][0][0] === '00017662',
    'la columna Factura XML se normaliza a ocho dígitos');

// La sucursal, en columna propia y no pegada al nombre del proveedor: pegada
// no se puede filtrar por ella una vez abierto el archivo, que es justo para
// lo que se baja. En pantalla viene apagada, pero está a un clic.
assertPorPagarExcel(isset($catalogo['sucursal']),
    'la sucursal es una columna propia del pago');
$conSucursal = Columnas::tabla('porpagar', [$linea], 'proveedor,sucursal');
assertPorPagarExcel($conSucursal['filas'][0][1] === 'CEDI',
    'y se llena con la sucursal de la línea');

/*
 * El estado se pinta de color. Antes esto se comprobaba mirando que el índice
 * de la celda coloreada fuera el 9: ese número dependía de cuántas columnas
 * hubiera antes, y al meter la sucursal hubo que corregirlo a mano. Ahora las
 * columnas las elige quien exporta, así que se comprueba lo que importa: el
 * color cae en la celda del estado, esté donde esté.
 */
foreach (['estado', 'documento,estado', 'estado,sucursal,monto'] as $pedidas) {
    $tabla = Columnas::tabla('porpagar', [$linea], $pedidas);
    $donde = array_search('estado', $tabla['ids'], true);
    assertPorPagarExcel(($tabla['estilos'][0][$donde] ?? 0) === 3,
        "con «{$pedidas}» el verde cae en la columna del estado y no en la de al lado");
}

$sinRespaldo = Columnas::tabla('porpagar', [['estado' => 'sin_respaldo']], 'estado');
assertPorPagarExcel(($sinRespaldo['estilos'][0][0] ?? 0) === 2,
    'y lo que no tiene respaldo baja en amarillo');

// Cada columna con su ancho, sea cual sea la combinación que se pida.
$tabla = Columnas::tabla('porpagar', [$linea], 'estado,proveedor,sucursal,monto');
assertPorPagarExcel(count($tabla['anchos']) === count($tabla['cabeceras']),
    'cada columna que baja lleva su ancho');

$vista = (string) file_get_contents(__DIR__ . '/../app/views/porpagar/index.php');
assertPorPagarExcel(strpos($vista, 'fa-file-excel') !== false && strpos($vista, 'Exportar Excel') !== false,
    'el botón identifica claramente la descarga de Excel');
assertPorPagarExcel(strpos($vista, 'data-exportar="porpagar"') !== false,
    'y el enlace se deja marcar por app.js para que lleve las columnas puestas');

$inicioTabla = strpos($vista, '<th class="right" data-col="monto">Monto</th>');
assertPorPagarExcel($inicioTabla !== false,
    'el listado titula la columna por lo que muestra: el monto de la factura');
$finTabla = strpos($vista, '</tbody>', $inicioTabla);
$tablaVista = substr($vista, $inicioTabla, $finTabla - $inicioTabla);
assertPorPagarExcel(strpos($tablaVista, "number_format((float) \$linea['monto'], 2)") !== false,
    'y la celda pinta el monto');

// El de la pantalla es el mismo que el del Excel: el congelado al entrar al
// pago. El saldo vivo baja a cero al pagar y vaciaría la columna justo cuando
// hay que archivar el pago.
assertPorPagarExcel(strpos($tablaVista, "\$linea['saldo_pago'] ?? \$linea['saldo']") !== false,
    'el saldo de la pantalla es el congelado al entrar al pago, no el de hoy');

echo "OK: Exportación XLSX de facturas por pagar
";
