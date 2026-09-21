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
 *   AlmacenEnGraph       SharePoint por Microsoft Graph: SharePoint es el
 *                        original y no una copia sincronizada. Se activa solo
 *                        en las PC con app/config/local.graph.php; ver
 *                        docs/ALMACENAMIENTO_DOCUMENTOS.md.
 *
 * Cuál se usa se decide por sociedad y por documento —ver "Cuál se usa", al
 * final—. Por eso la puerta y no un `if` repartido por todos lados.
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
require_once __DIR__ . '/AlmacenConflicto.php';

abstract class AlmacenDocumentos
{
    /** @var string|null */
    private static $carpetaTemporales = null;

    /** ruta pedida => ['local' => temporal, 'usos' => cuántos lo tienen] */
    private $temporales = [];

    // ── Lo que cada almacén tiene que saber hacer ─────────────────────────

    /** ¿El documento está y se puede leer? */
    abstract public function existe($ruta);

    /**
     * ¿Está el documento de esta columna de esta fila? Es la pregunta de los
     * listados, que se hace una vez por fila y por archivo.
     *
     * Por omisión es existe(). Un almacén al que cada pregunta le cuesta un
     * viaje por la red la contesta con lo que la fila ya sabe: cien filas son
     * doscientas preguntas, y a un segundo cada una no hay listado que aguante.
     */
    public function existeEnFila(array $fila, $campo)
    {
        $ruta = trim((string) ($fila[$campo] ?? ''));

        return $ruta !== '' && $this->existe($ruta);
    }

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
     * Lo de acá es lo que hace un almacén que NO es un disco: baja el
     * documento a storage/tmp/almacen. El de disco lo reemplaza, porque ahí el
     * archivo local ya es el archivo.
     *
     * Dos pedidos del mismo documento durante la misma petición comparten el
     * temporal —armar el ZIP de un pago pide el mismo XML que después lee el
     * parser—, así que se lleva la cuenta: el temporal se borra cuando el
     * último que lo pidió lo suelta.
     *
     * @return string|null La ruta, o null si el documento no está disponible.
     */
    public function rutaLocal($ruta)
    {
        $clave = (string) $ruta;
        if (isset($this->temporales[$clave])) {
            $this->temporales[$clave]['usos']++;

            return $this->temporales[$clave]['local'];
        }

        $origen = $this->abrir($ruta);
        if ($origen === false) {
            return null;
        }

        $carpeta = self::carpetaTemporales();
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
            fclose($origen);
            throw new RuntimeException('No se pudo crear la carpeta de temporales ' . $carpeta . '.');
        }

        // El nombre conserva la extensión: pdftotext y ZipArchive no la miran,
        // pero quien abra la carpeta para ver qué pasó sí.
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $clave)));
        $local = $carpeta . DIRECTORY_SEPARATOR . uniqid('doc_', true) . '_' . $nombre;
        $destino = @fopen($local, 'wb');
        if ($destino === false) {
            fclose($origen);
            throw new RuntimeException('No se pudo escribir el temporal ' . $local . '.');
        }
        $copiados = stream_copy_to_stream($origen, $destino);
        fclose($origen);
        fclose($destino);
        if ($copiados === false) {
            @unlink($local);

            return null;
        }

        $this->temporales[$clave] = ['local' => $local, 'usos' => 1];

        return $local;
    }

    /**
     * Devuelve lo que dio rutaLocal(). Si no era un temporal —con los
     * documentos en disco nunca lo es—, no hace nada.
     */
    public function soltarLocal($rutaLocal)
    {
        foreach ($this->temporales as $clave => $temporal) {
            if ($temporal['local'] !== $rutaLocal) {
                continue;
            }
            if (--$this->temporales[$clave]['usos'] <= 0) {
                @unlink($temporal['local']);
                unset($this->temporales[$clave]);
            }

            return;
        }
    }

    /**
     * soltarLocal() para quien no sabe de qué almacén salió el temporal
     * —RutaDocumento, que solo tiene la ruta local—. Cada almacén reconoce
     * los suyos y no toca los ajenos.
     */
    public static function soltarEnTodos($rutaLocal)
    {
        // Por objeto y no por lista: el forzado puede ser uno de los ya
        // construidos, y soltarlo dos veces le robaría el uso a otro.
        $almacenes = [];
        foreach (array_merge(array_values(self::$instancias), [self::$forzado]) as $almacen) {
            if ($almacen !== null) {
                $almacenes[spl_object_id($almacen)] = $almacen;
            }
        }
        foreach ($almacenes as $almacen) {
            $almacen->soltarLocal($rutaLocal);
        }
    }

    /**
     * La red de seguridad. Hoy olvidar soltarLocal() no cuesta nada, y por eso
     * nadie lo nota; con los documentos lejos es un disco que se llena —y el
     * de esta instalación ya anda justo—.
     */
    public function __destruct()
    {
        foreach ($this->temporales as $temporal) {
            @unlink($temporal['local']);
        }
        $this->temporales = [];
    }

    /**
     * Borra los temporales viejos que haya dejado un proceso que murió antes
     * de soltarlos —un corte de luz, un tiempo agotado de Apache—. Lo llama la
     * tarea programada.
     *
     * @return int Cuántos se borraron.
     */
    public static function barrerTemporales($segundos = 3600)
    {
        $carpeta = self::carpetaTemporales();
        if (!is_dir($carpeta)) {
            return 0;
        }
        $limite = time() - (int) $segundos;
        $borrados = 0;
        foreach (glob($carpeta . DIRECTORY_SEPARATOR . 'doc_*') ?: [] as $archivo) {
            if (is_file($archivo) && (int) @filemtime($archivo) < $limite && @unlink($archivo)) {
                $borrados++;
            }
        }

        return $borrados;
    }

    /** Dónde van los documentos bajados. Local a esta máquina, siempre. */
    public static function carpetaTemporales()
    {
        return self::$carpetaTemporales !== null
            ? self::$carpetaTemporales
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'almacen';
    }

    /** Para las pruebas: que no ensucien la carpeta de la instalación. */
    public static function fijarCarpetaTemporales($carpeta)
    {
        self::$carpetaTemporales = $carpeta === null ? null : rtrim((string) $carpeta, '\\/');
    }

    /**
     * Por qué no se puede leer un documento que la base dice que existe,
     * contado para quien usa la aplicación y no administra el servidor.
     */
    abstract public function porQueNoSeLee($ruta);

    /** Cómo se llama este almacén en pantalla y en los registros. */
    abstract public function descripcion();

    // ── Escribir ──────────────────────────────────────────────────────────
    //
    // El criterio de estos métodos: cada uno es una INTENCIÓN del sistema, no
    // una llamada del sistema operativo. Por eso no hay `renombrar()` aparte
    // de `mover()` —contra SharePoint son la misma petición—, ni forma de
    // abrir un archivo para escribirle de a poco —allá no existe añadir al
    // final: quien construye un archivo lo arma local y después lo entrega—,
    // ni recorrido recursivo —exponerlo invitaría a que alguien recorra 38.847
    // archivos por red y deje la instalación de rodillas—.
    //
    // Y sobre todo: **que nunca se vea un archivo a medias es problema del
    // almacén**, no de quien llama. En disco eso cuesta escribir a un lado y
    // renombrar al final; contra SharePoint sale gratis. Quien llama no se
    // entera de la diferencia, que es justamente el punto.

    /**
     * Deja el documento en $ruta con el contenido del archivo local $origenLocal.
     *
     * Es idempotente a propósito: si en el destino ya está EXACTAMENTE el
     * mismo contenido, no se escribe nada y se contesta `creado => false`.
     * Archivar dos veces el mismo comprobante es lo normal —un proveedor
     * reenvía el correo, una importación se reintenta— y no puede ser un error.
     *
     * Si en el destino hay algo DISTINTO, se lanza AlmacenConflicto y decide
     * quien llama: la política de nombres (el sufijo `_DUP_`) es del archivo,
     * no del almacén.
     *
     * @param string $ruta        Destino, relativo a la raíz o absoluto.
     * @param string $origenLocal Archivo de esta máquina que se va a subir.
     * @param array  $opciones    'hash' => SHA-256 ya calculado, para no
     *                            releer un archivo que quien llama acaba de leer.
     * @return array ruta, hash, creado, tamano, item_id, etag
     * @throws AlmacenConflicto Si el destino está ocupado por otro contenido.
     */
    abstract public function escribir($ruta, $origenLocal, array $opciones = []);

    /**
     * El almacén se queda con un archivo que hasta ahora era de esta máquina.
     *
     * Es lo que pasa con cada adjunto que baja del correo: MailFetcher lo deja
     * en storage/correo/tmp —disco local, y así tiene que seguir siendo— y de
     * ahí pasa a la bandeja, que es compartida porque la fila que lo apunta la
     * ve todo el grupo.
     *
     * Existe como operación propia porque quien la hacía a mano con rename()
     * se olvidaba de la mitad: contra un almacén remoto no hay un rename que
     * mueva y limpie de una vez, son subir y después borrar el temporal, y el
     * temporal olvidado es un disco que se llena. Acá se hace una sola vez.
     *
     * El original local se borra solo si el almacén se quedó con el contenido.
     */
    public function adoptar($ruta, $origenLocal, array $opciones = [])
    {
        $escrito = $this->escribir($ruta, $origenLocal, $opciones);
        @unlink($origenLocal);

        return $escrito;
    }

    /**
     * Mueve un documento. Cubre también renombrar: en SharePoint es la misma
     * operación, y tratarlas aparte solo duplicaría el camino.
     *
     * @return string La ruta donde quedó.
     */
    abstract public function mover($rutaOrigen, $rutaDestino);

    /** Deja una copia en otro sitio, conservando el original. */
    abstract public function copiar($rutaOrigen, $rutaDestino);

    /**
     * Borra un documento. Que ya no esté NO es un fallo: el resultado que se
     * pedía —que no esté— es el que hay.
     *
     * Se puede pedir por ruta o por identificador. Por identificador es lo que
     * necesita deshacer un archivado cuando la base falló: para entonces la
     * ruta pudo haber cambiado, y el identificador no cambia nunca.
     */
    abstract public function borrar($ruta = '', $itemId = '');

    /** Se asegura de que la carpeta exista, con todos sus niveles. */
    abstract public function asegurarCarpeta($ruta);

    /**
     * Lo que hay en una carpeta, UN nivel. Nunca recursivo, ver arriba.
     *
     * @return array [['nombre','es_carpeta','tamano','item_id','modificado_en'], …]
     */
    abstract public function listarCarpeta($ruta);

    /**
     * Quién es este documento para el almacén, sin traer su contenido.
     *
     * @return array|null item_id, etag, tamano, modificado_en — o null si no está.
     */
    abstract public function identidad($ruta);

    /**
     * ¿Se puede escribir de verdad?
     *
     * Con la carpeta compartida hay que comprobarlo escribiendo, porque
     * is_writable() miente sobre las carpetas de OneDrive. Contra SharePoint
     * la respuesta la da el permiso de la aplicación y no hace falta ensuciar
     * la biblioteca con archivos de prueba.
     */
    abstract public function permiteEscritura($ruta = '');

    /**
     * La cadena con la que ESTE almacén se dirige al documento, partiendo de
     * la ruta relativa que guarda la base.
     *
     * En disco es la raíz de esta máquina más la ruta; contra SharePoint es la
     * ruta tal cual, porque la biblioteca es la misma para todos. Es el punto
     * donde deja de importar en qué computadora se está.
     */
    abstract public function direccion($rutaRelativa);

    // ── Qué se puede dar por sentado de este almacén ─────────────────────
    //
    // Para que quien orquesta no tenga que preguntar "¿eres Graph?", que es la
    // pregunta que convierte una puerta en un `if` repartido por todos lados.

    /** ¿Recorrer el árbol entero es barato? En disco sí; por red, no. */
    abstract public function recorreElArbolBarato();

    /**
     * ¿Escribir un documento crea solo las carpetas de su camino?
     *
     * En disco no: hay que crearlas antes. En SharePoint sí, y pedirlas antes
     * es preguntar por cada nivel y crear cada uno —nueve llamadas por red
     * para el primer comprobante de un mes nuevo, una para cada uno de los
     * siguientes— antes de una subida que las habría creado igual.
     */
    public function creaCarpetasAlEscribir()
    {
        return false;
    }

    /**
     * ¿Los documentos tienen un identificador que sobrevive a que los muevan?
     *
     * De esto depende cómo se reconcilia. Sin identidad permanente hay que
     * salir a buscar el archivo por hash y por nombre; con ella, basta
     * preguntar dónde quedó.
     */
    abstract public function tieneIdentidadPermanente();

    // ── Cuál se usa ───────────────────────────────────────────────────────
    //
    // El almacén no es "dónde está la aplicación": es DÓNDE ESTÁ ESTE
    // DOCUMENTO. Hay dos preguntas distintas y no se pueden confundir:
    //
    //   ¿Dónde se archiva lo NUEVO de esta sociedad?
    //       sociedades.almacen_documentos → para($sociedadId)
    //
    //   ¿Dónde está ESTE documento ahora mismo?
    //       facturas_xml.almacen (por fila) → paraFila($fila)
    //
    // Mientras se pasa una sociedad de un almacén a otro, las dos respuestas
    // discrepan durante horas, y está bien: para leer manda la fila. Sin la
    // columna por fila, el paso sería todo o nada, sin poder retomarlo a la
    // mitad ni volver atrás.
    //
    // El ajuste va en la base y no en local.php porque hay varias PC contra la
    // misma base: con un archivo por máquina, una archivaría en SharePoint lo
    // que otra archiva en disco, para la misma sociedad, y nadie se enteraría
    // hasta que faltara un documento. Lo que sí es de cada máquina son las
    // credenciales; por eso un almacén puede estar "elegido" en la base y no
    // estar disponible en esta PC —y entonces se dice, no se improvisa—.
    //
    // Y una vez que una ruta ya salió de la base convertida en dirección, la
    // dirección misma dice a quién pertenece: en disco es absoluta (la raíz de
    // esta máquina más la ruta) y en SharePoint lleva el prefijo
    // `sharepoint:` delante de la ruta de la biblioteca. Eso es de(): los
    // cincuenta sitios que reciben una ruta ya hidratada no tienen que saber
    // de qué sociedad era.
    //
    // Una relativa SIN prefijo es de disco, como fue siempre. No se adivina:
    // en una PC con credenciales, una ruta relativa que se le escapó a alguien
    // tiene que ir al disco y fallar como fallaba, no salir a la red.
    //
    // Y por eso todo lo que se guarda en SharePoint se guarda CON su prefijo,
    // también en facturas_xml: la cadena sola dice dónde están los bytes, y
    // una consulta que no pidió la columna `almacen` no tiene que adivinar.
    // Lo que NO se hace nunca es deducirlo del ajuste de la sociedad: el
    // 19/09/2026 se pasó a SharePoint, desde Configuración, una empresa con
    // 14.043 documentos en disco; el organizador pedía sus filas sin la
    // columna, las leyó como de SharePoint, no las encontró en el disco, las
    // marcó a todas "sin archivo" y repuntó 1.580 a las copias de PAGOS.

    /** El valor que el sistema tuvo siempre, y el que significa un NULL. */
    public const DISCO = 'disco';

    /** @var AlmacenDocumentos|null Elegido a propósito: manda sobre todo. */
    private static $forzado = null;

    /** @var array tipo => callable que lo construye */
    private static $fabricas = [];

    /** ¿Falta mirar qué almacenes tiene configurados esta máquina? */
    private static $porDescubrir = true;

    /** @var array tipo => instancia. Una por tipo, no por sociedad: comparten cliente y token. */
    private static $instancias = [];

    /** @var callable|null Devuelve [sociedad_id => tipo]. */
    private static $fuenteAjustes = null;

    /** @var array|null [sociedad_id => tipo], y cuándo se leyó. */
    private static $ajustes = null;
    private static $ajustesLeidosEn = 0;

    /** Cuánto se confía en el ajuste leído antes de volver a preguntarlo. */
    private const VIGENCIA_AJUSTES = 60;

    /**
     * El almacén por omisión de esta instalación: el disco, que es lo que la
     * aplicación viene haciendo desde siempre.
     *
     * Es lo que se usa cuando no hay de dónde deducir otra cosa —una fila sin
     * sociedad, la carpeta de trabajo, una prueba—.
     */
    public static function actual()
    {
        return self::$forzado ?? self::tipo(self::DISCO);
    }

    /**
     * El almacén de un tipo. Lanza si esta máquina no lo tiene: quien pidió
     * SharePoint y no tiene credenciales tiene que enterarse, no recibir el
     * disco en su lugar y archivar donde nadie más va a mirar.
     */
    public static function tipo($tipo)
    {
        if (self::$forzado !== null) {
            return self::$forzado;
        }
        self::descubrir();
        $tipo = self::normalizarTipo($tipo);
        if (isset(self::$instancias[$tipo])) {
            return self::$instancias[$tipo];
        }
        if ($tipo === self::DISCO && !isset(self::$fabricas[$tipo])) {
            require_once __DIR__ . '/AlmacenEnDisco.php';

            return self::$instancias[$tipo] = new AlmacenEnDisco();
        }
        if (!isset(self::$fabricas[$tipo])) {
            throw new RuntimeException('Esta computadora no está configurada para leer ni escribir '
                . 'documentos en "' . $tipo . '". Si es SharePoint, falta app/config/local.graph.php '
                . 'con las credenciales de la aplicación.');
        }

        return self::$instancias[$tipo] = (self::$fabricas[$tipo])();
    }

    /** ¿Esta máquina puede usar ese almacén? */
    public static function disponible($tipo)
    {
        self::descubrir();
        $tipo = self::normalizarTipo($tipo);

        return $tipo === self::DISCO || isset(self::$fabricas[$tipo]);
    }

    /**
     * Da de alta un almacén en esta máquina. Se construye recién cuando
     * alguien lo pide, para que tenerlo configurado no cueste nada en las
     * páginas que no lo usan.
     */
    public static function registrar($tipo, callable $fabrica)
    {
        $tipo = self::normalizarTipo($tipo);
        self::$fabricas[$tipo] = $fabrica;
        unset(self::$instancias[$tipo]);
    }

    /**
     * Dónde se archiva lo nuevo de una sociedad.
     *
     * Es la pregunta de las ESCRITURAS, que es donde equivocarse destruye
     * datos, así que siempre se le hace a la base.
     */
    public static function para($sociedadId)
    {
        return self::tipo(self::tipoDeSociedad($sociedadId));
    }

    /**
     * Dónde está el documento de esta fila, para una ruta guardada SIN
     * prefijo (una con prefijo ya lo dice sola: ver RutaDocumento::hidratarFila).
     *
     *   1. Si la fila trae la columna `almacen`, manda ella. NULL es disco:
     *      es todo lo que se archivó antes de que existiera la columna.
     *   2. Si no la trae, disco: una ruta sin prefijo es de disco.
     *
     * Nunca el ajuste de la sociedad. Ese ajuste dice dónde va lo NUEVO, y
     * una empresa pasada a SharePoint sigue teniendo en disco todo lo de
     * antes hasta que se migra. Ver "Cuál se usa", arriba.
     */
    public static function paraFila(array $fila)
    {
        if (self::$forzado !== null) {
            return self::$forzado;
        }
        if (array_key_exists('almacen', $fila)) {
            return self::tipo($fila['almacen'] ?? self::DISCO);
        }

        return self::actual();
    }

    /**
     * A quién pertenece una dirección que ya salió de la base.
     *
     * Absoluta es de disco; relativa, del almacén remoto de esta máquina si lo
     * hay. Sin almacén remoto, una relativa también es de disco —se resuelve
     * contra la raíz—, que es exactamente lo que pasaba antes de existir esto.
     */
    public static function de($direccion)
    {
        if (self::$forzado !== null) {
            return self::$forzado;
        }
        $tipo = self::tipoDeDireccion($direccion);

        return self::tipo($tipo === '' ? self::DISCO : $tipo);
    }

    /**
     * El almacén que nombra una dirección con prefijo ('sharepoint:…'), o ''
     * si no lo lleva —y entonces es de disco: absoluta, o relativa a la raíz
     * como fue siempre—.
     *
     * El prefijo tiene dos letras o más a propósito: `C:` es una unidad de
     * Windows, no un almacén.
     */
    public static function tipoDeDireccion($direccion)
    {
        return preg_match('~^([a-z][a-z0-9]+):(?![\\\\/]{2})~i', (string) $direccion, $m)
            && !RutaDocumento::esAbsoluta((string) $direccion)
            ? strtolower($m[1])
            : '';
    }

    /**
     * Corre $bloque con $almacen como el de todo, y lo devuelve a como estaba
     * pase lo que pase. Para los comandos de cli/ que trabajan una sociedad.
     */
    public static function usando(AlmacenDocumentos $almacen, callable $bloque)
    {
        $antes = self::$forzado;
        self::$forzado = $almacen;
        try {
            return $bloque($almacen);
        } finally {
            self::$forzado = $antes;
        }
    }

    /** El tipo elegido para una sociedad ('disco' si no hay otro). */
    public static function tipoDeSociedad($sociedadId)
    {
        $ajustes = self::ajustes();
        $tipo = $ajustes[(int) $sociedadId] ?? self::DISCO;

        return self::normalizarTipo($tipo);
    }

    /**
     * De dónde sale el ajuste de cada sociedad. Por omisión, de la base; las
     * pruebas le pasan una lista.
     */
    public static function fijarFuenteAjustes(?callable $fuente)
    {
        self::$fuenteAjustes = $fuente;
        self::$ajustes = null;
    }

    /** Para que el próximo pedido vuelva a leer el ajuste (tras cambiarlo). */
    public static function olvidarAjustes()
    {
        self::$ajustes = null;
    }

    private static function ajustes()
    {
        // Se relee cada tanto y no una vez por proceso: la tarea programada
        // corre durante horas, y alguien puede cambiar de almacén una sociedad
        // a mitad de camino.
        if (self::$ajustes !== null && time() - self::$ajustesLeidosEn < self::VIGENCIA_AJUSTES) {
            return self::$ajustes;
        }
        self::$ajustesLeidosEn = time();
        $fuente = self::$fuenteAjustes ?? [self::class, 'ajustesDeLaBase'];
        try {
            $leidos = $fuente();
        } catch (Throwable $e) {
            // Sin base no se archiva nada de todos modos: la importación que
            // pidió esto falla en su propio INSERT, un paso más allá.
            $leidos = [];
        }

        return self::$ajustes = is_array($leidos) ? $leidos : [];
    }

    /** [sociedad_id => tipo], o [] si la columna todavía no existe. */
    private static function ajustesDeLaBase()
    {
        if (!class_exists('Sociedad')) {
            require_once dirname(__DIR__) . '/core/Model.php';
            require_once dirname(__DIR__) . '/models/Sociedad.php';
        }

        return (new Sociedad())->almacenesPorSociedad();
    }

    private static function normalizarTipo($tipo)
    {
        $tipo = strtolower(trim((string) $tipo));

        return $tipo === '' ? self::DISCO : $tipo;
    }

    /**
     * Da de alta lo que esta máquina tiene configurado, una vez por proceso.
     *
     * Hoy es solo SharePoint, y solo si existe app/config/local.graph.php con
     * las credenciales. Mirarlo cuesta un is_file(); el cliente y el token se
     * crean recién cuando alguien pide el almacén.
     */
    private static function descubrir()
    {
        if (!self::$porDescubrir) {
            return;
        }
        self::$porDescubrir = false;
        if (isset(self::$fabricas['sharepoint'])
            || !is_file(dirname(__DIR__) . '/config/local.graph.php')) {
            return;
        }
        require_once __DIR__ . '/ClienteGraph.php';
        $config = ClienteGraph::configuracion();
        if ($config === null) {
            return;
        }
        self::$fabricas['sharepoint'] = function () use ($config) {
            require_once __DIR__ . '/AlmacenEnGraph.php';

            return new AlmacenEnGraph(new ClienteGraph($config), $config);
        };
    }

    /** Para las pruebas y para elegirlo a propósito: manda sobre todo lo demás. */
    public static function fijar(AlmacenDocumentos $almacen)
    {
        self::$forzado = $almacen;
    }

    /**
     * Vuelve a como viene de fábrica: el disco, y ningún otro registrado —ni
     * siquiera el que esta máquina tenga configurado—. Es para las pruebas,
     * que tienen que dar lo mismo en una PC con credenciales y en una sin.
     */
    public static function olvidar()
    {
        self::$porDescubrir = false;
        self::$forzado = null;
        self::$fabricas = [];
        self::$instancias = [];
        self::$ajustes = null;
        self::$fuenteAjustes = null;
    }
}
