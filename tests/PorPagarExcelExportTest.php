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

$vista = (string) file_get_contents(__DIR__ . '/../app/views/porpagar/index.php');
assertPorPagarExcel(strpos($vista, 'fa-file-excel') !== false && strpos($vista, 'Exportar Excel') !== false,
    'el botón identifica claramente la descarga de Excel');

echo "OK: Exportación XLSX de facturas por pagar\n";
