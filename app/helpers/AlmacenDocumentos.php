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
 *   AlmacenEnGraph       SharePoint por Microsoft Graph: SharePoint es el
 *                        original y no una copia sincronizada. Es donde se
 *                        archiva TODO lo nuevo, de todas las empresas, y por
 *                        eso cada PC necesita app/config/local.graph.php; ver
 *                        docs/SHAREPOINT.md.
 *
 *   AlmacenEnDisco       La carpeta compartida de antes. Ya no se archiva
 *                        nada ahí: queda para abrir lo que se archivó antes
 *                        de SharePoint y todavía no se migró
 *                        (cli/migrar_documentos_a_graph.php).
 *
 * Cuál se usa lo dice cada documento —ver "Cuál se usa", al final—. Por eso
 * la puerta y no un `if` repartido por todos lados.
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
     * Averigua de una vez lo que hay en una carpeta, para que identidad() de
     * lo que tenga dentro no tenga que preguntar uno por uno. En disco
     * preguntar no cuesta nada: no hace falta.
     */
    public function conocerCarpeta($ruta)
    {
    }

    /**
     * Varias copias. Donde cada una es una espera de red se hacen a la vez;
     * acá, de a una.
     *
     * @param array $pares clave => [rutaOrigen, rutaDestino]
     * @return array clave => lo que da copiar(), o el Throwable que la impidió
     */
    public function copiarVarios(array $pares)
    {
        $salida = [];
        foreach ($pares as $clave => $par) {
            try {
                $salida[$clave] = $this->copiar($par[0], $par[1]);
            } catch (Throwable $e) {
                $salida[$clave] = $e;
            }
        }
        return $salida;
    }

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
    //   ¿Dónde se archiva lo NUEVO?
    //       En SharePoint, de todas las empresas → principal(), para()
    //
    //   ¿Dónde está ESTE documento ahora mismo?
    //       facturas_xml.almacen (por fila) → paraFila($fila)
    //
    // Hasta el 27/09/2026 cada empresa elegía entre la carpeta compartida y
    // SharePoint (sociedades.almacen_documentos). Ya no: la carpeta compartida
    // dependía de que OneDrive tuviera los archivos bajados en cada PC, y
    // SharePoint pasó a ser obligatorio. Esa columna quedó en la base, pero
    // nada la lee.
    //
    // Lo que sí sigue: lo archivado ANTES en la carpeta compartida sigue ahí
    // hasta que se migre, y para leerlo manda la fila, como siempre. Sin la
    // columna por fila el paso habría sido todo o nada, sin poder retomarlo a
    // la mitad.
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
    // Lo que NO se hace nunca es deducir dónde está un documento de dónde se
    // archiva lo nuevo: el 19/09/2026 se pasó a SharePoint, desde
    // Configuración, una empresa con 14.043 documentos en disco; el
    // organizador pedía sus filas sin la columna, las leyó como de SharePoint,
    // no las encontró en el disco, las marcó a todas "sin archivo" y repuntó
    // 1.580 a las copias de PAGOS.

    /** La carpeta compartida de antes: lo que significa un NULL o una ruta sin prefijo. */
    public const DISCO = 'disco';

    /** Donde se archiva todo lo nuevo, de todas las empresas. */
    public const SHAREPOINT = 'sharepoint';

    /** @var AlmacenDocumentos|null Elegido a propósito: manda sobre todo. */
    private static $forzado = null;

    /** @var array tipo => callable que lo construye */
    private static $fabricas = [];

    /** ¿Falta mirar qué almacenes tiene configurados esta máquina? */
    private static $porDescubrir = true;

    /** @var array tipo => instancia. Una por tipo, no por sociedad: comparten cliente y token. */
    private static $instancias = [];

    /**
     * El almacén de lo que no dice dónde está: una fila sin columna `almacen`
     * o una ruta relativa sin prefijo. Es la carpeta compartida de antes, y
     * solo para LEER lo que se archivó ahí y todavía no se migró: nada nuevo
     * va a parar a ella. Para escribir, principal().
     */
    public static function actual()
    {
        return self::$forzado ?? self::tipo(self::DISCO);
    }

    /**
     * Donde se archiva todo lo nuevo: la biblioteca de SharePoint, o la
     * carpeta de disco si local.php dice 'almacen_principal' => 'disco'
     * (ver tipoPrincipal()).
     *
     * Lanza si esta computadora no tiene ese almacén. No hay a dónde caer
     * solo: guardar en el disco lo que el resto busca en SharePoint es perder
     * el documento sin que nadie se entere. El disco se elige a propósito.
     *
     * @throws RuntimeException Sin app/config/local.graph.php.
     */
    public static function principal()
    {
        return self::$forzado ?? self::tipo(self::tipoPrincipal());
    }

    /** @var string|null 'sharepoint' o 'disco'; null = falta leerlo de config.php. */
    private static $tipoPrincipal = null;

    /**
     * Dónde se archiva lo nuevo según la configuración: 'sharepoint' (lo
     * normal) o 'disco'. Se lee una vez por proceso. Es una decisión de la
     * instalación, no de cada documento: lo ya archivado se lee de donde
     * dice su fila (paraFila), cambie esto o no.
     */
    public static function tipoPrincipal()
    {
        if (self::$tipoPrincipal === null) {
            $tipo = self::SHAREPOINT;
            try {
                $config = require dirname(__DIR__) . '/config/config.php';
                if (is_array($config) && ($config['almacen_principal'] ?? '') === self::DISCO) {
                    $tipo = self::DISCO;
                }
            } catch (Throwable $e) {
                // Sin configuración legible, lo de siempre: SharePoint.
            }
            self::$tipoPrincipal = $tipo;
        }

        return self::$tipoPrincipal;
    }

    /** Para las pruebas: elegir el modo sin pasar por config.php. */
    public static function fijarTipoPrincipal($tipo)
    {
        self::$tipoPrincipal = self::normalizarTipo($tipo) === self::DISCO ? self::DISCO : self::SHAREPOINT;
    }

    /** ¿Lo nuevo se archiva en disco? */
    public static function archivaEnDisco()
    {
        return self::tipoPrincipal() === self::DISCO;
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
            if ($tipo === self::SHAREPOINT) {
                throw new RuntimeException(self::SIN_SHAREPOINT);
            }
            throw new RuntimeException('Esta computadora no está configurada para leer ni escribir '
                . 'documentos en "' . $tipo . '".');
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
     * Dónde se archiva lo nuevo de una sociedad: el principal (SharePoint o,
     * si así se configuró, el disco), sea cual sea la sociedad.
     *
     * Recibe la sociedad aunque no la use porque quien escribe la tiene a
     * mano y es la pregunta que hace; que la respuesta sea la misma para
     * todas es una decisión de ahora, no de la firma. Ver principal().
     */
    public static function para($sociedadId)
    {
        return self::principal();
    }

    /**
     * Dónde está el documento de esta fila, para una ruta guardada SIN
     * prefijo (una con prefijo ya lo dice sola: ver RutaDocumento::hidratarFila).
     *
     *   1. Si la fila trae la columna `almacen`, manda ella. NULL es disco:
     *      es todo lo que se archivó antes de que existiera la columna.
     *   2. Si no la trae, disco: una ruta sin prefijo es de disco.
     *
     * Nunca dónde se archiva lo nuevo: lo que se archivó en la carpeta
     * compartida sigue ahí hasta que se migra. Ver "Cuál se usa", arriba.
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

    /**
     * ¿Esta computadora puede archivar? Con SharePoint, si están las
     * credenciales; con disco, si hay carpeta configurada. No sale a la red
     * ni toca el disco más que para leer la configuración, y por eso se puede
     * preguntar en cada petición.
     */
    public static function hayPrincipal()
    {
        if (self::$forzado !== null) {
            return true;
        }
        if (self::archivaEnDisco()) {
            return RutaDocumento::raiz() !== '';
        }

        return self::disponible(self::SHAREPOINT);
    }

    /**
     * Lo que se le dice a quien quiere guardar o abrir un documento en una
     * computadora sin SharePoint. Para el mensaje que corresponde al modo
     * configurado, sinPrincipal().
     */
    public const SIN_SHAREPOINT = 'Esta computadora no tiene SharePoint configurado, y los XML y PDF de todas '
        . 'las empresas se guardan ahí. Falta app/config/local.graph.php: ver Configuración → Archivo de '
        . 'documentos, o docs/SHAREPOINT.md.';

    /** Lo mismo, cuando lo nuevo se archiva en disco y no hay carpeta. */
    public const SIN_CARPETA_DISCO = 'Los XML y PDF se están archivando en disco, y esta computadora no tiene '
        . 'carpeta configurada. Falta carpeta_destino en storage/correo/config.json: ver Configuración → '
        . 'Archivo de documentos.';

    /** El motivo de no poder archivar, según dónde se archiva lo nuevo. */
    public static function sinPrincipal()
    {
        return self::archivaEnDisco() ? self::SIN_CARPETA_DISCO : self::SIN_SHAREPOINT;
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
     *
     * Contra una base aislada —la de las pruebas y la certificación, que por
     * regla lleva "test" o "cert" en el nombre (scripts/verificar-php84.ps1)—
     * la biblioteca de verdad NO se da de alta: sus filas son de usar y tirar,
     * y lo que subieran quedaría huérfano en la biblioteca de la empresa. Una
     * prueba que archiva sin elegir almacén falla con "no tiene SharePoint" en
     * vez de escribir ahí. Quien quiera, a propósito, correr una prueba contra
     * la biblioteca real lo pide con XMLCONCILIA_SHAREPOINT_REAL=1.
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
        if (self::baseAislada() && getenv('XMLCONCILIA_SHAREPOINT_REAL') !== '1') {
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

    /** ¿Esta computadora está apuntando a una base de pruebas o de certificación? */
    private static function baseAislada()
    {
        try {
            $base = (string) ((require dirname(__DIR__) . '/config/database.php')['database'] ?? '');
        } catch (Throwable $e) {
            return false;
        }
        return (bool) preg_match('/test|cert/i', $base);
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
        self::$tipoPrincipal = self::SHAREPOINT;
    }
}
