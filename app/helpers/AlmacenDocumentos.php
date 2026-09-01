<?php
/**
 * De dónde salen los XML y los PDF, sea cual sea el lugar donde estén.
 *
 * Hasta ahora los documentos se leían del disco a secas: `is_file()` para saber
 * si estaban, `fopen()` para abrirlos, y esas llamadas repartidas por media
 * docena de archivos. Funcionaba mientras la carpeta compartida fuera una
 * carpeta de verdad, y dejó de funcionar en cuanto pasó a ser una biblioteca de
 * SharePoint sincronizada con OneDrive: de los documentos que guarda solo en la
 * nube queda en el disco un marcador que existe, pesa lo que pesa el original y
 * hasta dice que se puede leer, pero cuyo contenido nunca llega —Windows se lo
 * niega a un programa que corre como servicio—. Hoy son 249 de 38.847
 * archivos; con 17 sociedades y unos 18 GB, van a ser muchos más.
 *
 * De ahí esta puerta. Todo el sistema pregunta acá, y detrás puede haber:
 *
 *   AlmacenEnDisco       Los documentos en el disco del servidor. Rápido, sin
 *                        depender de internet, sin pedirle permisos a nadie.
 *
 *   (por implementar)    SharePoint por Microsoft Graph, cuando la empresa
 *                        prefiera que SharePoint sea el original y no una
 *                        copia. Necesita que el administrador registre la
 *                        aplicación; ver docs/ALMACENAMIENTO_DOCUMENTOS.md.
 *
 * Cuál se usa es una decisión de cada instalación, no nuestra. Por eso la
 * puerta y no un `if` repartido por todos lados.
 *
 * ── Sobre rutaLocal() ──
 *
 * Hay cosas que necesitan un archivo de verdad en el disco y no un chorro de
 * bytes: armar un ZIP es la principal. Con los documentos en disco eso es la
 * ruta misma y no cuesta nada; con los documentos en SharePoint hay que
 * bajarlos a un temporal. Por eso rutaLocal() viene en par con soltarLocal():
 * quien pide un archivo local tiene que devolverlo, y así el que baja
 * temporales puede limpiarlos sin que el que llama sepa si hubo temporal.
 */

require_once __DIR__ . '/RutaDocumento.php';

abstract class AlmacenDocumentos
{
    /** @var AlmacenDocumentos|null */
    private static $actual = null;

    // ── Lo que cada almacén tiene que saber hacer ─────────────────────────

    /** ¿El documento está y se puede leer? */
    abstract public function existe($ruta);

    /**
     * Abre el documento para leerlo de verdad.
     *
     * @return resource|false El manejador —hay que cerrarlo— o false si el
     *         contenido no está disponible.
     */
    abstract public function abrir($ruta);

    /** Tamaño en bytes, o null si no se sabe. */
    abstract public function tamano($ruta);

    /**
     * Un archivo real en el disco de esta máquina, para lo que no sirve un
     * chorro de bytes. Puede ser un temporal: hay que devolverlo con
     * soltarLocal() al terminar.
     *
     * @return string|null La ruta, o null si el documento no está disponible.
     */
    abstract public function rutaLocal($ruta);

    /** Devuelve lo que dio rutaLocal(). Sin temporales de por medio, no hace nada. */
    public function soltarLocal($rutaLocal)
    {
    }

    /**
     * Por qué no se puede leer un documento que la base dice que existe,
     * contado para quien usa la aplicación y no administra el servidor.
     */
    abstract public function porQueNoSeLee($ruta);

    /** Cómo se llama este almacén en pantalla y en los registros. */
    abstract public function descripcion();

    // ── Cuál se usa ───────────────────────────────────────────────────────

    /**
     * El almacén de esta instalación.
     *
     * Por ahora siempre es el disco: es lo que hay montado y lo que la
     * aplicación viene haciendo desde siempre. Cuando exista el de SharePoint,
     * acá se elige por configuración —y solo acá—.
     */
    public static function actual()
    {
        if (self::$actual === null) {
            require_once __DIR__ . '/AlmacenEnDisco.php';
            self::$actual = new AlmacenEnDisco();
        }

        return self::$actual;
    }

    /** Para las pruebas y para elegirlo a propósito. */
    public static function fijar(AlmacenDocumentos $almacen)
    {
        self::$actual = $almacen;
    }

    /** Vuelve al de por omisión. */
    public static function olvidar()
    {
        self::$actual = null;
    }
}
