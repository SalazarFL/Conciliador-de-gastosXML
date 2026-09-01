<?php
/**
 * Los documentos en el disco al que llega esta máquina.
 *
 * Es lo que la aplicación viene haciendo desde siempre, ahora detrás de la
 * puerta: la carpeta compartida se abre con las funciones de archivos de PHP y
 * ya. Sirve tanto si esa carpeta es del disco del servidor como si es una
 * biblioteca de SharePoint sincronizada con OneDrive —que es el montaje de
 * hoy—, y de ahí viene la única sutileza de esta clase.
 *
 * Ver AlmacenDocumentos para el porqué de la puerta.
 */

require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/RutaDocumento.php';

class AlmacenEnDisco extends AlmacenDocumentos
{
    /**
     * Existir no es lo mismo que poder leerse, y acá se responde lo primero.
     *
     * Se conserva `is_file()` a propósito, aunque de un marcador de OneDrive
     * conteste que sí: es lo que hace falta para saber si el documento sigue
     * registrado en su sitio o si alguien lo movió o lo borró —que es la
     * pregunta que hacen los listados para marcar "archivo perdido"—. Un
     * documento que está en la nube NO está perdido: está, y hay que decir
     * otra cosa de él.
     *
     * Quien necesita el contenido usa abrir(), que sí distingue.
     */
    public function existe($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);

        return $absoluta !== '' && is_file($absoluta);
    }

    /**
     * Abre el documento de verdad.
     *
     * Acá está la diferencia con existe(). De los documentos que guarda solo
     * en la nube, OneDrive deja en el disco un marcador que existe, pesa lo
     * que pesa el original y hasta contesta que sí a is_readable(). El
     * contenido llega solo si Windows lo baja al abrirlo, y esa descarga se le
     * niega a quien corre como servicio —Apache es LocalSystem, no la persona
     * que sincroniza la carpeta—, así que fopen() falla con "Invalid argument".
     *
     * Sin abrirlo antes de contestar, quien servía el archivo mandaba las
     * cabeceras con el tamaño completo y después cero bytes: el navegador solo
     * decía "error al cargar el documento PDF", sin pista de qué había pasado.
     */
    public function abrir($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return false;
        }

        return @fopen($absoluta, 'rb');
    }

    public function tamano($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return null;
        }
        $bytes = @filesize($absoluta);

        return $bytes === false ? null : (int) $bytes;
    }

    /**
     * En disco el archivo local ES el archivo: no hay nada que bajar ni nada
     * que limpiar después. Se comprueba que se pueda abrir para no entregar la
     * ruta de un marcador que después no se va a poder leer.
     */
    public function rutaLocal($ruta)
    {
        $manejador = $this->abrir($ruta);
        if ($manejador === false) {
            return null;
        }
        fclose($manejador);

        return RutaDocumento::absoluta($ruta);
    }

    public function porQueNoSeLee($ruta)
    {
        return 'El archivo está en la carpeta compartida, pero OneDrive lo tiene solo en la nube '
            . 'y el servidor no puede bajarlo: corre como servicio de Windows y esa descarga se le '
            . 'niega. Abrí la carpeta compartida en el Explorador, clic derecho sobre ella y elegí '
            . '"Conservar siempre en este dispositivo". Para este documento suelto alcanza con '
            . 'abrirlo una vez desde el Explorador.';
    }

    public function descripcion()
    {
        return 'la carpeta compartida de documentos';
    }
}
