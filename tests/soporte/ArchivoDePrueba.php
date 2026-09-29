<?php
/**
 * El archivo de documentos de una prueba que no trata sobre el archivo.
 * No termina en Test.php a propósito: no es una prueba, lo usan.
 *
 * Las pruebas del correo, de los pagos o de la reposición archivan como
 * efecto secundario. Hasta el 27/09/2026 eso caía en la carpeta compartida
 * configurada en la PC —la de OneDrive de verdad, si la prueba no fijaba
 * otra—. Desde entonces todo lo nuevo va a SharePoint, y contra la base de
 * pruebas SharePoint no está dado de alta (ver AlmacenDocumentos::descubrir):
 * archivar sin elegir almacén falla, que es lo que se quiere.
 *
 * Esto les da un almacén explícito y desechable: una carpeta temporal de
 * disco, fijada como el almacén de todo. Es un doble, como el SharePoint en
 * memoria; lo que se prueba con él es lo del correo o el pago, no dónde
 * quedan los bytes —eso lo prueban SinCarpetaCompartidaTest y
 * PuntaAPuntaSharePointTest contra la biblioteca en memoria—.
 */

require_once __DIR__ . '/../../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../../app/helpers/RutaDocumento.php';

final class ArchivoDePrueba
{
    /**
     * Una carpeta temporal nueva, como raíz y como almacén de todo.
     *
     * @return string La carpeta.
     */
    public static function enDisco($prefijo = 'archivo')
    {
        $raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefijo . '_' . bin2hex(random_bytes(4));
        if (!is_dir($raiz) && !@mkdir($raiz, 0777, true) && !is_dir($raiz)) {
            throw new RuntimeException('No se pudo crear la carpeta de prueba ' . $raiz);
        }
        RutaDocumento::fijarRaiz($raiz);
        AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');
        AlmacenDocumentos::fijar(new AlmacenEnDisco());

        return $raiz;
    }

    /** Deja todo como estaba y borra la carpeta. Silencioso: va en los finally. */
    public static function soltar($raiz)
    {
        AlmacenDocumentos::olvidar();
        AlmacenDocumentos::fijarCarpetaTemporales(null);
        RutaDocumento::olvidarRaiz();
        if (!is_string($raiz) || $raiz === '' || !is_dir($raiz)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($raiz);
    }
}
