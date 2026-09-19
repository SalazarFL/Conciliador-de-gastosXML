<?php
/**
 * Que todo archivo local que se pide se devuelva.
 *
 * Con los documentos en disco, rutaLocal() entrega el archivo mismo y olvidar
 * soltarLocal() no cuesta nada: por eso nadie lo nota. Con los documentos en
 * SharePoint cada pedido es una descarga a storage/tmp/almacen, y un pedido
 * sin devolución es un archivo que se queda ahí para siempre. Armar el ZIP de
 * un pago son cientos de pedidos, y el disco de esta instalación ya anda justo.
 *
 * Esto se prueba ANTES de que existan descargas de verdad, con un almacén de
 * mentira que usa la misma rutaLocal() que va a usar el de Graph.
 *
 * Uso: php tests/RutaLocalSeSueltaTest.php
 */
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/PaqueteDocumentos.php';
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';

function assertSuelta($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function temporalesEn($carpeta)
{
    return is_dir($carpeta) ? (glob($carpeta . DIRECTORY_SEPARATOR . 'doc_*') ?: []) : [];
}

$carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ruta_local_' . uniqid();
AlmacenDocumentos::fijarCarpetaTemporales($carpeta);

$almacen = new AlmacenDePrueba();
$almacen->documentos['SISTEMA/E/2026/09 SETIEMBRE/Facturas/FE_A_00000001.xml'] = '<FacturaElectronica/>';
$almacen->documentos['SISTEMA/E/2026/09 SETIEMBRE/Facturas/FE_A_00000001.pdf'] = '%PDF-1.4 prueba';
AlmacenDocumentos::fijar($almacen);

$xml = 'SISTEMA/E/2026/09 SETIEMBRE/Facturas/FE_A_00000001.xml';

// ── Un pedido, una devolución ─────────────────────────────────────────────

$local = RutaDocumento::rutaLocal($xml);
assertSuelta($local !== null && is_file($local), 'rutaLocal() baja el documento a un archivo de verdad');
assertSuelta(strpos($local, $carpeta) === 0, 'y lo deja en la carpeta de temporales, no en cualquier lado');
assertSuelta(file_get_contents($local) === '<FacturaElectronica/>', 'con el contenido exacto');

RutaDocumento::soltarLocal($local);
assertSuelta(!is_file($local), 'soltarLocal() borra el temporal');
assertSuelta(temporalesEn($carpeta) === [], 'y la carpeta queda vacía');

// ── Dos pedidos del mismo documento comparten el temporal ────────────────
//
// El ZIP de un pago pide el XML y después lo vuelve a pedir el parser. Sin
// esto se descarga dos veces; y peor, el primero que suelta le borra el
// archivo al segundo, que todavía lo está leyendo.

$a = RutaDocumento::rutaLocal($xml);
$b = RutaDocumento::rutaLocal($xml);
assertSuelta($a === $b, 'dos pedidos del mismo documento reciben el mismo temporal');
assertSuelta(count(temporalesEn($carpeta)) === 1, 'y se bajó una sola vez');

RutaDocumento::soltarLocal($a);
assertSuelta(is_file($b), 'soltar el primero NO le borra el archivo al segundo');
RutaDocumento::soltarLocal($b);
assertSuelta(!is_file($b), 'recién el último en soltarlo lo borra');

// ── conArchivoLocal() suelta aunque falle lo de adentro ─────────────────

$visto = null;
try {
    RutaDocumento::conArchivoLocal($xml, function ($local) use (&$visto) {
        $visto = $local;
        throw new RuntimeException('se cayó a la mitad');
    });
} catch (RuntimeException $e) {
    // esperado
}
assertSuelta($visto !== null && !is_file($visto),
    'conArchivoLocal() suelta el temporal aunque lo de adentro lance una excepción');

$leido = RutaDocumento::conArchivoLocal($xml, function ($local) {
    return file_get_contents($local);
});
assertSuelta($leido === '<FacturaElectronica/>', 'y devuelve lo que devuelve lo de adentro');
assertSuelta(temporalesEn($carpeta) === [], 'sin dejar nada atrás');

// Un archivo que ya está en esta máquina se usa tal cual: no tiene sentido
// pedírselo al almacén, y no es suyo para borrarlo.
$propio = tempnam(sys_get_temp_dir(), 'propio');
file_put_contents($propio, 'mío');
RutaDocumento::conArchivoLocal($propio, function ($local) use ($propio) {
    assertSuelta($local === $propio, 'un archivo local se usa tal cual, sin copiarlo');
});
assertSuelta(is_file($propio), 'y no se borra: no era un temporal del almacén');
@unlink($propio);

$fallo = false;
try {
    RutaDocumento::conArchivoLocal('SISTEMA/NO_ESTA.xml', function ($local) {
        return $local;
    });
} catch (RuntimeException $e) {
    $fallo = strpos($e->getMessage(), 'prueba') !== false;
}
assertSuelta($fallo, 'de lo que no se puede leer se avisa, con la explicación del almacén');

// ── El ZIP de un pago no deja nada ────────────────────────────────────────

$zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paquete_' . uniqid() . '.zip';
PaqueteDocumentos::crear([[
    'archivo_xml' => $xml,
    'archivo_pdf' => 'SISTEMA/E/2026/09 SETIEMBRE/Facturas/FE_A_00000001.pdf',
]], $zip);
assertSuelta(is_file($zip), 'el ZIP se arma con documentos que no están en un disco');
assertSuelta(temporalesEn($carpeta) === [], 'y no deja ni un temporal después de cerrarlo');
@unlink($zip);

// ── La red de seguridad ───────────────────────────────────────────────────

$olvidado = RutaDocumento::rutaLocal($xml);
touch($olvidado, time() - 7200);
assertSuelta(AlmacenDocumentos::barrerTemporales(3600) === 1,
    'el barrido se lleva el temporal que alguien olvidó soltar');
assertSuelta(!is_file($olvidado), 'y el archivo ya no está');

AlmacenDocumentos::olvidar();
AlmacenDocumentos::fijarCarpetaTemporales(null);
@rmdir($carpeta);

echo "OK: todo archivo local que se pide se devuelve\n";
