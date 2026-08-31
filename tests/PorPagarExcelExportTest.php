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

$controlador = (string) file_get_contents(__DIR__ . '/../app/controllers/PorPagarController.php');
$inicio = strpos($controlador, 'public function exportar()');
$fin = strpos($controlador, 'private function ejecutarMatching', $inicio);
$metodo = substr($controlador, $inicio, $fin - $inicio);

assertPorPagarExcel(strpos($metodo, 'XlsxWriter::generate') !== false, 'la exportación por pagar usa el generador XLSX');
assertPorPagarExcel(strpos($metodo, 'NumeroFactura::xmlOchoDigitos') !== false,
    'la columna Número XML se normaliza a ocho dígitos');
assertPorPagarExcel(strpos($metodo, "'.xlsx'") !== false, 'la descarga usa extensión xlsx');
assertPorPagarExcel(strpos($metodo, 'text/csv') === false && strpos($metodo, 'fputcsv') === false,
    'la exportación ya no genera CSV');

// Monto y saldo son dos cosas, y ahora las dos están en los dos sitios.
//
// El monto va primero en pantalla porque es el que cierra la fila: la
// diferencia que sale al lado es monto − total del XML. El saldo estuvo fuera
// un tiempo —en medio de esos dos números la fila no se leía— y volvió en
// columna propia, que es donde no estorba: mirar lo que se debe es justamente
// para lo que se abre un pago.
assertPorPagarExcel(strpos($metodo, "'Monto ERP', 'Saldo'") !== false,
    'la exportación conserva las dos columnas: el monto de la factura y su saldo');
assertPorPagarExcel(strpos($metodo, "\$linea['saldo_pago']") !== false,
    'y el saldo que exporta es el congelado al entrar al pago, no el de hoy');

$vista = (string) file_get_contents(__DIR__ . '/../app/views/porpagar/index.php');
assertPorPagarExcel(strpos($vista, 'fa-file-excel') !== false && strpos($vista, 'Exportar Excel') !== false,
    'el botón identifica claramente la descarga de Excel');

$inicioTabla = strpos($vista, '<th class="right">Monto</th>');
assertPorPagarExcel($inicioTabla !== false,
    'el listado titula la columna por lo que muestra: el monto de la factura');
$finTabla = strpos($vista, '</tbody>', $inicioTabla);
$tabla = substr($vista, $inicioTabla, $finTabla - $inicioTabla);
assertPorPagarExcel(strpos($tabla, "number_format((float) \$linea['monto'], 2)") !== false,
    'y la celda pinta el monto');
assertPorPagarExcel(strpos($tabla, "\$linea['saldo_pago']") !== false,
    'y el saldo tiene su propia columna al lado');

// El de la pantalla es el mismo que el del Excel: el congelado al entrar al
// pago. El saldo vivo baja a cero al pagar y vaciaría la columna justo cuando
// hay que archivar el pago.
assertPorPagarExcel(strpos($tabla, "\$linea['saldo_pago'] ?? \$linea['saldo']") !== false,
    'el saldo de la pantalla es el congelado al entrar al pago, no el de hoy');

echo "OK: Exportación XLSX de facturas por pagar\n";
