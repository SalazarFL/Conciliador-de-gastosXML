<?php
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

function assertArchivo($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function borrarArbolPrueba($dir) {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($dir);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_archivo_' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$xml1 = $root . DIRECTORY_SEPARATOR . 'uno.xml';
$xml2 = $root . DIRECTORY_SEPARATOR . 'dos.xml';
$pdf = $root . DIRECTORY_SEPARATOR . 'uno.pdf';
file_put_contents($xml1, '<?xml version="1.0"?><FacturaElectronica><A>1</A></FacturaElectronica>');
file_put_contents($xml2, '<?xml version="1.0"?><FacturaElectronica><A>2</A></FacturaElectronica>');
file_put_contents($pdf, '%PDF-1.4 prueba');

try {
    $servicio = new DocumentoArchivo($root);
    $doc = ['tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'4354'];
    $nombreNc = DocumentoArchivo::nombreBase(
        ['tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'0000000167'],
        'Proveedor Prueba S.A.'
    );
    $nombreFe = DocumentoArchivo::nombreBase(
        ['tipo_documento'=>'FE', 'fecha_emision'=>'2026-06-08', 'numero_factura_asistente'=>'0000077153'],
        'DADA TEXTIL S.A.'
    );
    $primero = $servicio->archivar($xml1, $pdf, $doc, 'Proveedor Prueba S.A.');
    $repetido = $servicio->archivar($xml1, $pdf, $doc, 'Proveedor Prueba S.A.');
    $colision = $servicio->archivar($xml2, $pdf, $doc, 'Proveedor Prueba S.A.');
    $incompleto = $servicio->archivar(
        $xml2,
        null,
        ['tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'987'],
        'Proveedor Prueba S.A.'
    );

    assertArchivo(is_file($primero['ruta_xml']) && is_file($primero['ruta_pdf']), 'crea el par XML/PDF');
    assertArchivo(substr($nombreNc, -8) === '00000167', 'las notas de crédito usan exactamente 8 dígitos');
    assertArchivo($nombreFe === 'FE_DADA_TEXTIL_080626_00077153', 'las facturas conservan proveedor y fecha con consecutivo de 8 dígitos');
    assertArchivo(strpos($primero['ruta_xml'], 'SISTEMA' . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Facturas') !== false, 'estructura SISTEMA/año/mes/tipo');
    // Lo archivado cuelga de SISTEMA y la advertencia queda a la vista: la
    // raíz es una carpeta compartida donde también se trabaja, y el árbol de
    // años suelto ahí no decía de quién era ni que no se puede tocar.
    $sistema = $root . DIRECTORY_SEPARATOR . 'SISTEMA';
    assertArchivo(dirname($primero['ruta_xml'], 4) === $sistema, 'el árbol de años cuelga de SISTEMA');
    assertArchivo(is_file($sistema . DIRECTORY_SEPARATOR . 'LEEME - NO MODIFICAR.txt'), 'deja la nota de advertencia dentro de SISTEMA');
    assertArchivo(!is_dir($root . DIRECTORY_SEPARATOR . '2026'), 'no queda un árbol de años en la raíz');
    assertArchivo($repetido['xml_creado'] === false && $repetido['ruta_xml'] === $primero['ruta_xml'], 'reutiliza el mismo hash');
    assertArchivo(strpos($colision['archivo_xml'], '_DUP_') !== false, 'no sobrescribe una colisión');
    // La carpeta sale de la fecha de emisión y el tipo, y de nada más: debajo
    // del mes está el tipo y ahí termina el árbol. Antes había además una
    // subcarpeta por estado, y era la causa de que los archivos tuvieran que
    // reacomodarse cada vez que la factura avanzaba.
    assertArchivo(basename(dirname($primero['ruta_xml'])) === 'Facturas'
        && basename(dirname(dirname($primero['ruta_xml']))) === '07 JULIO',
        'la carpeta es año/mes/tipo, sin subcarpeta de estado debajo');
    assertArchivo(basename(dirname($incompleto['ruta_xml'])) === 'Notas de crédito'
        && basename(dirname(dirname($incompleto['ruta_xml']))) === '07 JULIO',
        'un documento incompleto no se aparta: va con su tipo, al mismo nivel');
    assertArchivo($incompleto['ruta_pdf'] === null, 'no inventa un PDF para un documento incompleto');
    assertArchivo(hash_file('sha256', $primero['ruta_xml']) === hash_file('sha256', $xml1), 'valida SHA-256');
    echo "OK: DocumentoArchivo\n";
} finally {
    borrarArbolPrueba($root);
}
