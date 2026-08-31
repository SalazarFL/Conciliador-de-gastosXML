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

        $raiz = self::raiz();
        if ($raiz === '') {
            return '';
        }
        return $raiz . DIRECTORY_SEPARATOR . self::normalizarSeparadores(ltrim($ruta, '\\/'));
    }

    /** ¿El archivo existe en esta máquina? Acepta las dos formas. */
    public static function existe($ruta)
    {
        $absoluta = self::absoluta($ruta);
        return $absoluta !== '' && is_file($absoluta);
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
     * contenido no está disponible en esta máquina.
     */
    public static function abrirParaLeer($rutaAbsoluta)
    {
        $rutaAbsoluta = trim((string) $rutaAbsoluta);
        if ($rutaAbsoluta === '' || !is_file($rutaAbsoluta)) {
            return false;
        }
        return @fopen($rutaAbsoluta, 'rb');
    }

    /**
     * Por qué no se puede leer un archivo que sí está en la carpeta, dicho
     * para quien usa la aplicación y no administra el servidor.
     */
    public static function porQueNoSeLee($rutaAbsoluta)
    {
        return 'El archivo está en la carpeta compartida, pero OneDrive lo tiene solo en la nube '
            . 'y el servidor no puede bajarlo: corre como servicio de Windows y esa descarga se le '
            . 'niega. Abrí la carpeta compartida en el Explorador, clic derecho sobre ella y elegí '
            . '"Conservar siempre en este dispositivo". Para este documento suelto alcanza con '
            . 'abrirlo una vez desde el Explorador.';
    }

    /** Ruta absoluta a una subcarpeta de trabajo dentro de la raíz. */
    public static function carpetaTrabajo($sub = '')
    {
        $raiz = self::raiz();
        if ($raiz === '') {
            throw new RuntimeException('Configura la carpeta compartida de documentos en Correo.');
        }
        $ruta = $raiz . DIRECTORY_SEPARATOR . self::TRABAJO;
        $sub = trim(str_replace('/', DIRECTORY_SEPARATOR, (string) $sub), '\\/');
        if ($sub !== '') {
            $ruta .= DIRECTORY_SEPARATOR . $sub;
        }
        if (!is_dir($ruta) && !@mkdir($ruta, 0777, true) && !is_dir($ruta)) {
            throw new RuntimeException('No se pudo crear la carpeta de trabajo: ' . $ruta);
        }
        return $ruta;
    }

    /**
     * ¿De verdad se puede escribir en esta carpeta?
     *
     * No se usa is_writable(): en Windows contesta false para las carpetas que
     * OneDrive sincroniza desde SharePoint. Vienen con el atributo de solo
     * lectura —que en una carpeta no significa "no se puede escribir", sino
     * que Windows la trata distinto— y encima son puntos de reanálisis, así
     * que la comprobación de PHP falla aunque los permisos sí alcancen. La
     * única respuesta fiable es intentar escribir un archivo y borrarlo.
     */
    public static function permiteEscritura($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '' || !is_dir($ruta)) {
            return false;
        }
        $prueba = rtrim($ruta, '\\/') . DIRECTORY_SEPARATOR
            . '.xmlconcilia_prueba_' . getmypid() . '_' . uniqid();
        if (@file_put_contents($prueba, 'ok') === false) {
            return false;
        }
        @unlink($prueba);
        return true;
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

    public static function hidratarFila($fila, array $campos)
    {
        if (!is_array($fila)) {
            return $fila;
        }
        foreach ($campos as $campo) {
            // array_key_exists y no isset: una ruta NULL debe quedarse NULL,
            // no convertirse en la raíz a secas.
            if (array_key_exists($campo, $fila) && is_string($fila[$campo]) && $fila[$campo] !== '') {
                $fila[$campo] = self::absoluta($fila[$campo]);
            }
        }
        return $fila;
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
