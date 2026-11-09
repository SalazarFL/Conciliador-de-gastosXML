<?php
/**
 * Archivar lo nuevo en disco ('almacen_principal' => 'disco' en local.php).
 *
 * Es el modo para seguir trabajando sin SharePoint. Lo que tiene que valer:
 * lo nuevo va al disco, lo ya archivado en SharePoint se sigue pidiendo a
 * SharePoint (lo dice su fila, no el modo), y sin carpeta no se archiva en
 * ningún lado —con un motivo que dice qué falta—.
 *
 * Uso: php tests/AlmacenPrincipalDiscoTest.php
 */
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';

function assertDisco($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nexo_disco_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
$limpiar = function ($dir) use (&$limpiar) {
    foreach ((array) @scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === false) { continue; }
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        is_dir($p) ? $limpiar($p) : @unlink($p);
    }
    @rmdir($dir);
};

try {
    // ── De fábrica: SharePoint ─────────────────────────────────────────────

    AlmacenDocumentos::olvidar();
    assertDisco(AlmacenDocumentos::tipoPrincipal() === AlmacenDocumentos::SHAREPOINT,
        'sin configurar nada, lo nuevo se archiva en SharePoint, como desde el 27/09');
    assertDisco(!AlmacenDocumentos::hayPrincipal(),
        'y sin credenciales no hay dónde archivar');
    assertDisco(AlmacenDocumentos::sinPrincipal() === AlmacenDocumentos::SIN_SHAREPOINT,
        'con el motivo de siempre: faltan las credenciales');

    // ── Modo disco sin carpeta ─────────────────────────────────────────────

    AlmacenDocumentos::fijarTipoPrincipal('disco');
    RutaDocumento::fijarRaiz('');
    assertDisco(AlmacenDocumentos::archivaEnDisco(), 'el modo disco se puede elegir');
    assertDisco(!AlmacenDocumentos::hayPrincipal(),
        'en disco y sin carpeta, NO hay dónde archivar: nada termina en una ruta relativa cualquiera');
    assertDisco(AlmacenDocumentos::sinPrincipal() === AlmacenDocumentos::SIN_CARPETA_DISCO,
        'y el motivo habla de la carpeta, no de SharePoint');

    // ── Modo disco con carpeta ─────────────────────────────────────────────

    RutaDocumento::fijarRaiz($raiz);
    assertDisco(AlmacenDocumentos::hayPrincipal(), 'con carpeta, sí');
    assertDisco(AlmacenDocumentos::principal() instanceof AlmacenEnDisco,
        'principal() es el disco');
    assertDisco(AlmacenDocumentos::para(7) instanceof AlmacenEnDisco,
        'y para() también, sea cual sea la empresa');
    assertDisco(AlmacenDocumentos::principal()->direccion('') === $raiz,
        'la dirección de la raíz es la carpeta misma, no una cadena vacía');
    assertDisco(RutaDocumento::carpetaTrabajo('BANDEJA') === $raiz . DIRECTORY_SEPARATOR . '_TRABAJO' . DIRECTORY_SEPARATOR . 'BANDEJA',
        'la Bandeja compartida vive dentro de la carpeta');

    $archivo = new DocumentoArchivo();
    assertDisco($archivo->raiz() === $raiz,
        'DocumentoArchivo archiva en la carpeta de disco sin que nadie se la pase');

    // Escribir de verdad por la puerta.
    $origen = tempnam(sys_get_temp_dir(), 'nx');
    file_put_contents($origen, '<FacturaElectronica/>');
    $datos = AlmacenDocumentos::principal()->escribir('SISTEMA/EMPRESA/2026/11 NOVIEMBRE/FE_1.xml', $origen);
    @unlink($origen);
    assertDisco(is_file($raiz . '/SISTEMA/EMPRESA/2026/11 NOVIEMBRE/FE_1.xml'),
        'escribir() deja el archivo dentro de la carpeta');
    assertDisco(AlmacenDocumentos::tipoDeDireccion(RutaDocumento::relativa($datos['ruta'])) === '',
        'y su ruta se guarda SIN prefijo: la fila queda como de disco (almacen NULL)');

    // ── Lo de SharePoint sigue siendo de SharePoint ────────────────────────

    $nube = new AlmacenDePrueba();
    $nube->documentos['SISTEMA/EMPRESA/2026/10 OCTUBRE/FE_9.xml'] = '<x/>';
    AlmacenDocumentos::registrar('sharepoint', function () use ($nube) { return $nube; });
    assertDisco(AlmacenDocumentos::paraFila(['almacen' => 'sharepoint']) === $nube,
        'una fila que dice sharepoint se pide a SharePoint aunque lo nuevo vaya al disco');
    assertDisco(AlmacenDocumentos::de('sharepoint:SISTEMA/EMPRESA/2026/10 OCTUBRE/FE_9.xml') === $nube,
        'y una dirección con prefijo también');
    assertDisco(AlmacenDocumentos::principal() instanceof AlmacenEnDisco,
        'tener SharePoint configurado no le gana al modo disco: lo elige local.php');
} finally {
    AlmacenDocumentos::olvidar();
    RutaDocumento::olvidarRaiz();
    $limpiar($raiz);
}

echo "OK: Archivar en disco mientras no hay SharePoint\n";
