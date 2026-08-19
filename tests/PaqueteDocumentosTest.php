<?php
/**
 * El guardado masivo de la bandeja: un ZIP con el mismo nombrado del archivo.
 *
 * Lo que se comprueba es lo que se puede perder sin darse cuenta: que el
 * nombre sea el del archivo durable (FE_/NC_), que dos filas con el mismo
 * nombre no se pisen dentro del ZIP —la segunda desaparecería en silencio— y
 * que una fila sin PDF no impida guardar las demás.
 */
require_once __DIR__ . '/../app/helpers/PaqueteDocumentos.php';

function assertPaquete($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paquete_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$crearArchivo = function ($nombre, $contenido) use ($tmp) {
    $ruta = $tmp . DIRECTORY_SEPARATOR . $nombre;
    file_put_contents($ruta, $contenido);
    return $ruta;
};

$xmlA = $crearArchivo('a.xml', '<FacturaElectronica/>');
$pdfA = $crearArchivo('a.pdf', '%PDF-1.4');
$xmlB = $crearArchivo('b.xml', '<NotaCreditoElectronica/>');
$xmlC = $crearArchivo('c.xml', '<FacturaElectronica>otra</FacturaElectronica>');

$filas = [
    // Factura con su par completo.
    ['tipo_doc' => 'FE', 'proveedor' => 'MARJAVA SUPERMERCADOS S.A', 'fecha_emision' => '2026-06-08',
     'numero_corto' => '62755', 'archivo_xml' => $xmlA, 'archivo_pdf' => $pdfA],
    // Nota de crédito del mismo proveedor y día: cambia el prefijo.
    ['tipo_doc' => 'NC', 'proveedor' => 'MARJAVA SUPERMERCADOS S.A', 'fecha_emision' => '2026-06-08',
     'numero_corto' => '900', 'archivo_xml' => $xmlB, 'archivo_pdf' => ''],
    // El mismo comprobante capturado dos veces: no puede pisar al primero.
    ['tipo_doc' => 'FE', 'proveedor' => 'MARJAVA SUPERMERCADOS S.A', 'fecha_emision' => '2026-06-08',
     'numero_corto' => '62755', 'archivo_xml' => $xmlC, 'archivo_pdf' => ''],
    // Fila cuyo archivo ya no está en disco: se cuenta y no detiene nada.
    ['tipo_doc' => 'FE', 'proveedor' => 'OTRO PROVEEDOR S.A', 'fecha_emision' => '2026-06-09',
     'numero_corto' => '11', 'archivo_xml' => $tmp . DIRECTORY_SEPARATOR . 'no_existe.xml', 'archivo_pdf' => ''],
];

$zipRuta = $tmp . DIRECTORY_SEPARATOR . 'lote.zip';
$resumen = PaqueteDocumentos::crear($filas, $zipRuta);

assertPaquete($resumen['documentos'] === 3, 'entran los tres documentos que sí tienen archivo');
assertPaquete($resumen['xml'] === 3 && $resumen['pdf'] === 1, 'se cuentan los XML y los PDF por separado');
assertPaquete($resumen['sin_xml'] === 1, 'la fila cuyo XML no está en disco se cuenta como faltante');
assertPaquete($resumen['sin_pdf'] === 3, 'las tres filas sin PDF se cuentan como faltantes');

$zip = new ZipArchive();
assertPaquete($zip->open($zipRuta) === true, 'el ZIP queda abierto y legible');
$entradas = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entradas[] = $zip->getNameIndex($i);
}
$zip->close();
sort($entradas);

assertPaquete($entradas === [
    'FE_MARJAVA_SUPERMERCADOS_080626_00062755.pdf',
    'FE_MARJAVA_SUPERMERCADOS_080626_00062755.xml',
    'FE_MARJAVA_SUPERMERCADOS_080626_00062755_2.xml',
    'NC_MARJAVA_SUPERMERCADOS_080626_00000900.xml',
], 'los nombres son los del archivo durable y el repetido se numera: ' . implode(', ', $entradas));

// Un lote sin ningún archivo en disco no puede terminar en un ZIP vacío:
// parecería un guardado bueno y no se llevaría nada.
$vacio = false;
try {
    PaqueteDocumentos::crear([
        ['tipo_doc' => 'FE', 'proveedor' => 'X', 'fecha_emision' => '2026-06-08',
         'numero_corto' => '1', 'archivo_xml' => $tmp . DIRECTORY_SEPARATOR . 'nada.xml', 'archivo_pdf' => ''],
    ], $tmp . DIRECTORY_SEPARATOR . 'vacio.zip');
} catch (RuntimeException $e) {
    $vacio = true;
}
assertPaquete($vacio, 'sin ningún archivo en disco el guardado avisa en vez de bajar un ZIP vacío');
assertPaquete(!is_file($tmp . DIRECTORY_SEPARATOR . 'vacio.zip'), 'el ZIP fallido no queda tirado en disco');

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') as $archivo) {
    @unlink($archivo);
}
@rmdir($tmp);

echo "OK PaqueteDocumentosTest\n";
