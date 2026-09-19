<?php
/**
 * Traduce entre la ruta que se guarda en la base de datos y la ruta real del
 * disco de cada computadora.
 *
 * Por qué existe: los XML y los PDF viven en una carpeta compartida
 * (SharePoint sincronizado), pero cada máquina la ve en un lugar distinto —
 * C:\Users\ana\..., C:\Users\luis\...—. Si la base guarda la ruta completa,
 * el documento solo se abre en la computadora que lo importó. Guardando la
 * ruta relativa a la raíz, la misma fila sirve para todos:
 *
 *   en la base   SISTEMA/2026/07 JULIO/Facturas/FE_PROVEEDOR_010726_00000123.xml
 *   en el disco  <raíz de esa máquina>\SISTEMA\2026\07 JULIO\Facturas\FE_...xml
 *
 * Las dos conversiones son idempotentes y toleran la forma contraria: pedir
 * absoluta() sobre algo ya absoluto lo devuelve igual, y relativa() sobre algo
 * ya relativo también. Así una fila que se quedó sin convertir sigue abriendo
 * en la máquina que la escribió en vez de romper la pantalla, y `cli/
 * migrar_rutas_relativas.php` puede volver a correrse cuantas veces haga falta.
 */

class RutaDocumento
{
    /** Subcarpeta de la raíz para lo que aún no es un documento archivado. */
    public const TRABAJO = '_TRABAJO';

    private static $raiz = null;

    /**
     * Carpeta compartida donde viven todos los XML y PDF de la empresa.
     * Cadena vacía cuando todavía no se ha configurado.
     */
    public static function raiz()
    {
        if (self::$raiz === null) {
            if (!class_exists('DocumentoArchivo', false)) {
                require_once __DIR__ . '/DocumentoArchivo.php';
            }
            self::$raiz = rtrim(str_replace('/', DIRECTORY_SEPARATOR, DocumentoArchivo::raizConfigurada()), '\\/');
        }
        return self::$raiz;
    }

    /** La usan las pruebas y el instalador tras cambiar la configuración. */
    public static function olvidarRaiz()
    {
        self::$raiz = null;
    }

    /** Fija la raíz sin pasar por la configuración (pruebas, migraciones). */
    public static function fijarRaiz($ruta)
    {
        self::$raiz = rtrim(str_replace('/', DIRECTORY_SEPARATOR, (string) $ruta), '\\/');
    }

    /**
     * Ruta como debe guardarse: relativa a la raíz, con "/" para que no
     * dependa del sistema operativo.
     *
     * Lo que quede fuera de la raíz se devuelve tal cual. No es un error que
     * se pueda resolver aquí —el archivo realmente está fuera de la carpeta
     * compartida—, y perderlo sería peor que guardarlo sin convertir: el
     * diagnóstico lo reporta y la migración lo deja anotado.
     */
    public static function relativa($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '') {
            return '';
        }
        // Una dirección de otro almacén ('sharepoint:SISTEMA/…') se guarda
        // CON su prefijo: la cadena dice sola dónde está el archivo, en
        // cualquier tabla. Es lo que hace segura la bandeja y la cola, que no
        // tienen columna `almacen`: si la empresa cambia de almacén con
        // documentos en tránsito, cada uno se sigue buscando donde quedó.
        $prefijo = self::prefijoDeAlmacen($ruta);
        if ($prefijo !== '') {
            return strtolower($prefijo) . self::normalizarRelativa(substr($ruta, strlen($prefijo)));
        }
        if (!self::esAbsoluta($ruta)) {
            return self::normalizarRelativa($ruta);
        }

        $raiz = self::raiz();
        if ($raiz === '') {
            return $ruta;
        }

        $ruta = self::normalizarSeparadores($ruta);
        $prefijo = rtrim($raiz, '\\/') . DIRECTORY_SEPARATOR;
        if (!self::empiezaCon($ruta, $prefijo)) {
            return $ruta;
        }
        return self::normalizarRelativa(substr($ruta, strlen($prefijo)));
    }

    /**
     * Ruta como debe usarse contra el disco de esta máquina.
     * Devuelve '' si la ruta es relativa y todavía no hay raíz configurada:
     * quien la reciba ya trata la cadena vacía como "no disponible".
     */
    public static function absoluta($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '') {
            return '';
        }
        if (self::esAbsoluta($ruta)) {
            return self::normalizarSeparadores($ruta);
        }
        // De otro almacén: no hay raíz de esta máquina que ponerle delante.
        if (self::prefijoDeAlmacen($ruta) !== '') {
            return $ruta;
        }

        $raiz = self::raiz();
        if ($raiz === '') {
            return '';
        }
        return $raiz . DIRECTORY_SEPARATOR . self::normalizarSeparadores(ltrim($ruta, '\\/'));
    }

    /**
     * ¿El archivo existe? Acepta las dos formas.
     *
     * Se le pregunta al almacén y no al disco: los documentos pueden no estar
     * en un disco. Ver AlmacenDocumentos.
     */
    public static function existe($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->existe($ruta);
    }

    /**
     * Abre el archivo para leer su contenido de verdad.
     *
     * No basta con is_file(): de los documentos que solo tiene en la nube,
     * OneDrive deja en el disco un marcador que existe, pesa lo que pesa el
     * original y hasta contesta que sí a is_readable(). El contenido llega
     * solo si Windows lo baja al abrirlo, y esa descarga se le niega a quien
     * corre como servicio —Apache es LocalSystem, no la persona que
     * sincroniza la carpeta—, así que fopen() falla con "Invalid argument".
     *
     * Sin esta comprobación, quien servía el archivo mandaba las cabeceras
     * con el tamaño completo y después cero bytes: el navegador solo decía
     * "error al cargar el documento PDF", sin pista de qué había pasado.
     *
     * Devuelve el manejador abierto —hay que cerrarlo— o false si el
     * contenido no está disponible.
     */
    public static function abrirParaLeer($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->abrir($ruta);
    }

    /** Tamaño del documento en bytes, o null si no se sabe. */
    public static function tamano($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->tamano($ruta);
    }

    /**
     * Un archivo real en el disco, para lo que no sirve un chorro de bytes
     * —armar un ZIP, sobre todo—. Puede ser un temporal: hay que devolverlo
     * con soltarLocal() al terminar. Ver AlmacenDocumentos.
     *
     * @return string|null
     */
    public static function rutaLocal($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->rutaLocal($ruta);
    }

    /** Devuelve lo que dio rutaLocal(). */
    public static function soltarLocal($rutaLocal)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        // Un temporal es un archivo de esta máquina, así que su dirección no
        // dice de qué almacén vino. Se le avisa a todos los que tengan
        // temporales: solo el que lo dio lo reconoce.
        AlmacenDocumentos::soltarEnTodos($rutaLocal);
    }

    /**
     * Le pasa a $usar un archivo de esta máquina con el contenido de $ruta, y
     * lo suelta al terminar pase lo que pase.
     *
     * Es la forma de pedir un archivo local sin poder olvidarse de devolverlo:
     * el par rutaLocal()/soltarLocal() deja la devolución a cargo de quien
     * llama, y una excepción en el medio se la saltea.
     *
     * Acepta las dos clases de ruta que circulan por el sistema. Un archivo que
     * ya está en esta máquina —un adjunto recién bajado del correo, algo que
     * subió una persona— se usa tal cual; uno del almacén se le pide al
     * almacén. Así quien llama no tiene que saber de dónde vino lo que recibió.
     *
     * @return mixed Lo que devuelva $usar.
     */
    public static function conArchivoLocal($ruta, callable $usar)
    {
        $ruta = (string) $ruta;
        if ($ruta !== '' && self::esAbsoluta($ruta) && is_file($ruta)) {
            return $usar($ruta);
        }

        $local = self::rutaLocal($ruta);
        if ($local === null) {
            throw new RuntimeException('No se pudo leer ' . basename(str_replace('\\', '/', $ruta)) . '. '
                . self::porQueNoSeLee($ruta));
        }
        try {
            return $usar($local);
        } finally {
            self::soltarLocal($local);
        }
    }

    /**
     * Por qué no se puede leer un archivo que sí está registrado, dicho para
     * quien usa la aplicación y no administra el servidor. La explicación
     * depende de dónde vivan los documentos, así que la da el almacén.
     */
    public static function porQueNoSeLee($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->porQueNoSeLee($ruta);
    }

    /**
     * Una subcarpeta de _TRABAJO: lo que todavía no es un documento archivado
     * —la bandeja del correo, la cola de importación—.
     *
     * Con $sociedadId, la del almacén de esa empresa. El material en tránsito
     * tiene que estar donde lo alcancen todas las PC —una captura el correo y
     * otra lo importa—, así que para una empresa en SharePoint la bandeja
     * también está en SharePoint. Sin empresa, o con la empresa en disco, es
     * la carpeta compartida de siempre.
     *
     * En SharePoint no se crea de antemano: la biblioteca crea las carpetas
     * del camino al subir, y preguntar por ella costaría una llamada.
     */
    public static function carpetaTrabajo($sub = '', $sociedadId = null)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';
        $sub = trim(str_replace('\\', '/', (string) $sub), '/');
        $relativa = self::TRABAJO . ($sub !== '' ? '/' . $sub : '');

        if ($sociedadId !== null && (int) $sociedadId > 0) {
            require_once __DIR__ . '/AlmacenEnDisco.php';
            $almacen = AlmacenDocumentos::para((int) $sociedadId);
            if (!$almacen instanceof AlmacenEnDisco) {
                return $almacen->direccion($relativa);
            }
        }

        $raiz = self::raiz();
        if ($raiz === '') {
            throw new RuntimeException('Configura la carpeta compartida de documentos en Correo.');
        }
        $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
        AlmacenDocumentos::de($ruta)->asegurarCarpeta($ruta);

        return $ruta;
    }

    /** La subcarpeta del mes: una carpeta con miles de archivos se vuelve lenta, en disco y en SharePoint. */
    public static function delMes()
    {
        return date('Y-m');
    }

    /**
     * ¿De verdad se puede escribir?
     *
     * La respuesta depende de dónde vivan los documentos, así que la da el
     * almacén. Con la carpeta compartida hay que comprobarlo escribiendo de
     * verdad, porque is_writable() miente sobre las carpetas de OneDrive;
     * contra SharePoint lo dice el permiso de la aplicación y no hace falta
     * ensuciar la biblioteca con archivos de prueba.
     */
    public static function permiteEscritura($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';

        return AlmacenDocumentos::de($ruta)->permiteEscritura($ruta);
    }

    /** ¿La ruta apunta adentro de la carpeta compartida? */
    public static function dentroDeRaiz($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '') {
            return false;
        }
        if (!self::esAbsoluta($ruta)) {
            return true;
        }
        $raiz = self::raiz();
        if ($raiz === '') {
            return false;
        }
        return self::empiezaCon(
            self::normalizarSeparadores($ruta),
            rtrim($raiz, '\\/') . DIRECTORY_SEPARATOR
        );
    }

    /**
     * Convierte a absolutas las columnas de ruta de las filas leídas de la
     * base. Lo llama Model con la lista que cada modelo declara en
     * $camposRuta, para que ninguna consulta tenga que acordarse de hacerlo.
     */
    public static function hidratarFilas($filas, array $campos)
    {
        if (!is_array($filas) || !$campos) {
            return $filas;
        }
        foreach ($filas as $i => $fila) {
            if (is_array($fila)) {
                $filas[$i] = self::hidratarFila($fila, $campos);
            }
        }
        return $filas;
    }

    /**
     * Es el único punto del sistema donde coinciden la ruta, el almacén de la
     * fila y su sociedad: por eso se decide acá a qué almacén pertenece el
     * documento y se le pide su dirección. En disco es la raíz de esta
     * máquina más la ruta, como siempre; en SharePoint, la ruta tal cual. De
     * acá en adelante la cadena que viaja ya dice a quién pertenece, y los
     * que la reciben no tienen que saber de qué sociedad era.
     */
    public static function hidratarFila($fila, array $campos)
    {
        if (!is_array($fila)) {
            return $fila;
        }
        $almacen = null;
        foreach ($campos as $campo) {
            // array_key_exists y no isset: una ruta NULL debe quedarse NULL,
            // no convertirse en la raíz a secas.
            if (array_key_exists($campo, $fila) && is_string($fila[$campo]) && $fila[$campo] !== '') {
                // Guardada con prefijo, la ruta ya es la dirección: manda
                // sobre la columna y sobre la sociedad, porque dice dónde
                // están los bytes.
                if (self::prefijoDeAlmacen($fila[$campo]) !== '') {
                    continue;
                }
                if ($almacen === null) {
                    require_once __DIR__ . '/AlmacenDocumentos.php';
                    try {
                        $almacen = AlmacenDocumentos::paraFila($fila);
                    } catch (RuntimeException $e) {
                        // La fila es de un almacén que esta PC no tiene. Un
                        // listado no puede caerse por eso: la ruta queda como
                        // está y ese documento, sin abrir.
                        $almacen = false;
                    }
                }
                if ($almacen !== false) {
                    $fila[$campo] = $almacen->direccion($fila[$campo]);
                }
            }
        }
        return $fila;
    }

    /** 'sharepoint:' si la dirección lo lleva, '' si es de disco. */
    private static function prefijoDeAlmacen($ruta)
    {
        require_once __DIR__ . '/AlmacenDocumentos.php';
        $tipo = AlmacenDocumentos::tipoDeDireccion($ruta);

        return $tipo === '' ? '' : substr((string) $ruta, 0, strlen($tipo) + 1);
    }

    public static function esAbsoluta($ruta)
    {
        $ruta = (string) $ruta;
        return (bool) preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $ruta);
    }

    private static function normalizarSeparadores($ruta)
    {
        return str_replace(DIRECTORY_SEPARATOR === '\\' ? '/' : '\\', DIRECTORY_SEPARATOR, (string) $ruta);
    }

    private static function normalizarRelativa($ruta)
    {
        return trim(str_replace('\\', '/', (string) $ruta), '/');
    }

    private static function empiezaCon($ruta, $prefijo)
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($ruta, $prefijo, strlen($prefijo)) === 0
            : strncmp($ruta, $prefijo, strlen($prefijo)) === 0;
    }
}
