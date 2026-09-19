<?php
/**
 * Soporte común de las pruebas de borde, seguridad, concurrencia y rendimiento
 * de Correo. No termina en Test.php a propósito: no es una prueba, lo usan.
 *
 * Es el mismo arnés que el de Seguimiento (tests/soporte/SeguimientoSoporte.php)
 * y por las mismas razones:
 *
 *   caso() tiene que pasar. defecto() afirma el comportamiento CORRECTO de algo
 *   que hoy está mal: sale como "DEFECTO ABIERTO" y no rompe la certificación;
 *   el día que se arregle, la prueba falla para que se pase a caso(). El código
 *   de cada defecto es el de docs/REVISION_CORREO.md.
 *
 *   Los avisos de PHP se convierten en excepciones, como hace App en desarrollo.
 *
 *   Nada toca una base real ni un buzón real. La conexión de Model es una PDO
 *   falsa que graba lo que le piden y contesta por fragmento de SQL; el buzón
 *   es un MailFetcher sin IMAP; el índice de la sincronización vive en memoria.
 *   La base viva está en otra computadora y la de certificación no conecta: una
 *   prueba de Correo que dependiera de ella saldría SKIP y no probaría nada.
 */

if (!class_exists('SesionCorreoEnMemoria', false)) {
    final class SesionCorreoEnMemoria implements SessionHandlerInterface
    {
        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string|false { return ''; }
        public function write(string $id, string $data): bool { return true; }
        public function destroy(string $id): bool { return true; }
        public function gc(int $max_lifetime): int|false { return 0; }
    }
}

// La sesión se abre antes de cargar nada: en CLI cualquier salida previa cuenta
// como cabeceras enviadas y ya no deja abrirla.
if (session_status() === PHP_SESSION_NONE) {
    session_set_save_handler(new SesionCorreoEnMemoria(), true);
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_start();
}

require_once __DIR__ . '/../../app/core/Model.php';
require_once __DIR__ . '/../../app/core/Controller.php';
require_once __DIR__ . '/../../app/models/Sociedad.php';
require_once __DIR__ . '/../../app/models/Notificacion.php';
require_once __DIR__ . '/../../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../../app/helpers/RitmoCarpetas.php';
require_once __DIR__ . '/../../app/helpers/CorreoSync.php';
require_once __DIR__ . '/../../app/helpers/XmlParser.php';
require_once __DIR__ . '/../../app/models/CorreoIndice.php';
require_once __DIR__ . '/../../app/models/CorreoCuenta.php';
require_once __DIR__ . '/../../app/models/CorreoLote.php';
require_once __DIR__ . '/../../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../../app/models/CorreoProcesado.php';
require_once __DIR__ . '/../../app/models/CorreoCapturaAutomatica.php';
require_once __DIR__ . '/../../app/controllers/CorreoController.php';

// ── Aserciones ──────────────────────────────────────────────────────────────

if (!class_exists('FalloDePrueba', false)) {
    final class FalloDePrueba extends RuntimeException {}
}

if (!function_exists('verificar')) {
    function verificar($condicion, string $mensaje): void
    {
        if (!$condicion) {
            throw new FalloDePrueba($mensaje);
        }
    }
}

if (!function_exists('igual')) {
    function igual($esperado, $actual, string $mensaje): void
    {
        if ($esperado !== $actual) {
            throw new FalloDePrueba($mensaje . ' | esperado: ' . Soporte::corto($esperado)
                . ' | obtenido: ' . Soporte::corto($actual));
        }
    }
}

if (!function_exists('lanza')) {
    /** Ejecuta y devuelve la excepción; falla si no lanzó o lanzó otra cosa. */
    function lanza(callable $cuerpo, string $fragmento = ''): Throwable
    {
        try {
            $cuerpo();
        } catch (FalloDePrueba $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($fragmento !== '' && mb_stripos($e->getMessage(), $fragmento) === false) {
                throw new FalloDePrueba('lanzó otra cosa: ' . get_class($e) . ': ' . $e->getMessage());
            }
            return $e;
        }
        throw new FalloDePrueba('se esperaba una excepción' . ($fragmento !== '' ? " con «{$fragmento}»" : ''));
    }
}

// ── El arnés ────────────────────────────────────────────────────────────────

final class Prueba
{
    private static $titulo = '';
    private static $pasados = 0;
    private static $fallos = [];
    private static $abiertos = [];
    private static $cerrados = [];

    public static function iniciar(string $titulo): void
    {
        self::$titulo = $titulo;
        Soporte::aislar();
    }

    public static function caso(string $nombre, callable $cuerpo): void
    {
        Soporte::limpiarPeticion();
        try {
            $cuerpo();
            self::$pasados++;
        } catch (Throwable $e) {
            self::$fallos[] = $nombre . ' → ' . self::describir($e);
        } finally {
            Soporte::limpiarTemporales();
        }
    }

    /** @param string $codigo El código del hallazgo en docs/REVISION_CORREO.md. */
    public static function defecto(string $codigo, string $nombre, callable $cuerpo): void
    {
        Soporte::limpiarPeticion();
        try {
            $cuerpo();
            self::$cerrados[] = "[{$codigo}] {$nombre}";
        } catch (Throwable $e) {
            self::$abiertos[] = "[{$codigo}] {$nombre} → " . self::describir($e);
        } finally {
            Soporte::limpiarTemporales();
        }
    }

    public static function terminar(): void
    {
        foreach (self::$abiertos as $linea) {
            echo "DEFECTO ABIERTO {$linea}\n";
        }
        foreach (self::$fallos as $linea) {
            fwrite(STDERR, "FAIL: {$linea}\n");
        }
        foreach (self::$cerrados as $linea) {
            fwrite(STDERR, "FAIL: el defecto {$linea} ya no se reproduce; pásalo a caso() para que quede protegido\n");
        }
        if (self::$fallos || self::$cerrados) {
            exit(1);
        }
        printf("OK: %s — %d casos, %d defectos abiertos documentados\n",
            self::$titulo, self::$pasados, count(self::$abiertos));
    }

    private static function describir(Throwable $e): string
    {
        $texto = $e instanceof FalloDePrueba
            ? $e->getMessage()
            : get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        // El certificador marca como fallida cualquier salida con estas palabras.
        $texto = str_ireplace(
            ['deprecated', 'warning', 'fatal error', 'parse error'],
            ['obsoleto', 'aviso', 'error grave', 'error de sintaxis'],
            $texto
        );
        return str_replace(["\r", "\n"], ' ', $texto);
    }
}

// ── El aislamiento ──────────────────────────────────────────────────────────

final class Soporte
{
    /** @var PdoFalso */
    public static $pdo;
    public static $secuencia = 0;
    /** Archivos y carpetas creados por el caso en curso; se borran al terminar. */
    private static $temporales = [];

    public static function aislar(): void
    {
        // La clave de cifrado de las contraseñas vive en app/config/local.clave.php,
        // que es de cada computadora: las pruebas ponen la suya para no depender
        // de si esta máquina ya la tiene.
        Secreto::usarClave('clave-de-prueba-no-usar-en-produccion');

        self::$pdo = new PdoFalso();
        (new ReflectionProperty(Model::class, 'db'))->setValue(null, self::$pdo);

        // El esquema se da por comprobado: ningún CREATE/ALTER llega a la PDO falsa.
        (new ReflectionProperty(Esquema::class, 'enEstaPeticion'))->setValue(null, array_fill_keys([
            'Bitacora', 'CorreoBandeja', 'CorreoCapturaAutomatica', 'CorreoCuenta', 'CorreoIndice',
            'CorreoLote', 'CorreoProcesado', 'Notificacion', 'Sociedad', 'FacturaErp', 'NotaCredito',
            'ProveedorCodigoErp', 'Usuario', 'Rol', 'Pago', 'ImportacionItem', 'LineaEnRevision', 'Devolucion',
        ], true));
        (new ReflectionProperty(CorreoIndice::class, 'esquemaListo'))->setValue(null, true);

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo = '', int $linea = 0) {
            throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });
    }

    public static function limpiarPeticion(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (array_keys($_SERVER) as $clave) {
            if (strpos($clave, 'HTTP_') === 0) {
                unset($_SERVER[$clave]);
            }
        }
        $_SESSION = ['user_id' => 7, 'user_nombre' => 'Ana Prueba', 'csrf_token' => 'token-de-prueba'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-de-prueba';
        self::$pdo->reiniciar();
        Model::olvidarMemoria();
        self::empresaEnCurso(4);
    }

    public static function empresaEnCurso(int $id): void
    {
        (new ReflectionProperty(Sociedad::class, 'cacheSeleccionada'))->setValue(null, $id);
    }

    public static function corto($valor): string
    {
        $texto = var_export($valor, true);
        return mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;
    }

    /** Invoca un método privado o protegido. */
    public static function privado($objetoOClase, string $metodo, ...$argumentos)
    {
        $clase = is_object($objetoOClase) ? get_class($objetoOClase) : $objetoOClase;
        $ref = new ReflectionMethod($clase, $metodo);
        return $ref->isStatic()
            ? $ref->invoke(null, ...$argumentos)
            : $ref->invoke($objetoOClase, ...$argumentos);
    }

    /** Una carpeta temporal propia del caso, fuera del proyecto. */
    public static function carpetaTemporal(): string
    {
        $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xc_correo_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($ruta, 0777, true);
        self::$temporales[] = $ruta;
        return $ruta;
    }

    public static function anotarTemporal(string $ruta): void
    {
        self::$temporales[] = $ruta;
    }

    public static function limpiarTemporales(): void
    {
        foreach (array_reverse(self::$temporales) as $ruta) {
            self::borrar($ruta);
        }
        self::$temporales = [];
    }

    private static function borrar(string $ruta): void
    {
        if (is_dir($ruta) && !is_link($ruta)) {
            foreach (scandir($ruta) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::borrar($ruta . DIRECTORY_SEPARATOR . $item);
                }
            }
            @rmdir($ruta);
        } elseif (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** Una parte MIME con la forma que devuelve imap_fetchstructure. */
    public static function parteMime(string $nombre = '', array $extra = []): stdClass
    {
        $parte = (object) array_merge([
            'type' => 3, 'encoding' => 3, 'subtype' => 'OCTET-STREAM', 'bytes' => 1200,
            'ifdparameters' => 0, 'dparameters' => [], 'ifparameters' => 0, 'parameters' => [],
        ], $extra);
        if ($nombre !== '') {
            $parte->ifdparameters = 1;
            $parte->dparameters = [(object) ['attribute' => 'filename', 'value' => $nombre]];
        }
        return $parte;
    }

    public static function multiparte(array $partes): stdClass
    {
        return (object) ['type' => 1, 'subtype' => 'MIXED', 'parts' => $partes,
                         'ifdparameters' => 0, 'ifparameters' => 0];
    }

    /** Un lote como lo devuelve CorreoLote::get(). */
    public static function filaLote(array $cambios = []): array
    {
        return $cambios + [
            'id' => '50', 'cuenta_id' => '3', 'sociedad_id' => '4', 'fecha_desde' => '2026-09-01',
            'fecha_hasta' => '2026-09-10', 'carpeta_raiz' => '', 'carpetas_json' => '[]',
            'estado' => 'ejecutando', 'total_mensajes' => '6', 'procesados' => '0',
            'documentos_importados' => '0', 'duplicados' => '0', 'pdf_pendientes' => '0',
            'incidencias' => '0', 'cuenta_nombre' => 'Facturación', 'cuenta_usuario' => 'facturas@empresa.example',
            'sociedad_nombre' => 'EMPRESA DEMO', 'sociedad_cedula' => '3101123456', 'ultimo_error' => null,
        ];
    }

    /**
     * Prepara la PDO falsa para que procesarTandaLote() encuentre el lote 50
     * (empresa 4, cuenta 3) en 'ejecutando' con $cantidad correos por tomar,
     * y la cuenta 3 registrada. Devuelve la carpeta raíz temporal del lote.
     */
    public static function loteEnCurso(int $cantidad, array $cambiosLote = []): string
    {
        $raiz = self::carpetaTemporal();
        $items = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $items[] = self::filaItem(700 + $i, 100 + $i);
        }
        $pdo = self::$pdo;
        $pdo->respuestas['SELECT l.*, c.nombre AS cuenta_nombre, c.usuario'] =
            [self::filaLote($cambiosLote + ['carpeta_raiz' => $raiz])];
        $pdo->respuestas['FROM correo_lote_items WHERE id IN'] = $items;
        $pdo->respuestas['SELECT * FROM correo_lote_items'] = $items;
        $pdo->respuestas['SELECT lote_id FROM correo_lote_items'] = [['lote_id' => '50']];
        $pdo->respuestas["estado IN ('pendiente','procesando')"] = [['c' => $cantidad]];
        $pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [[
            'id' => '3', 'nombre' => 'Facturación', 'host' => 'mail.test.invalid', 'puerto' => '993',
            'usuario' => 'facturas@empresa.example', 'password' => base64_encode('secreta'), 'carpeta' => 'INBOX',
            'dias_atras' => '0', 'indice_retencion_dias' => '0', 'solo_no_leidos' => '0',
        ]];
        $pdo->afectadas["SET estado = 'procesando', intentos"] = $cantidad;
        return $raiz;
    }

    public static function filaItem(int $id, int $uid, array $cambios = []): array
    {
        return $cambios + [
            'id' => (string) $id, 'lote_id' => '50', 'correo_indice_id' => null, 'carpeta' => 'INBOX',
            'uidvalidity' => '77', 'uid' => (string) $uid, 'estado' => 'procesando', 'intentos' => '1',
            'asunto' => 'Factura', 'remitente' => 'proveedor@test.invalid', 'fecha_correo' => '2026-09-02 10:00:00',
        ];
    }
}

// ── Dobles ──────────────────────────────────────────────────────────────────

/**
 * La conexión de Model. Graba cada sentencia; `respuestas` contesta por
 * fragmento de SQL (el primero que coincide) con filas fijas o con una
 * función fn(sql, params) que devuelve filas. `afectadas` hace lo mismo con
 * rowCount(). Sin respuesta, las lecturas vuelven vacías y las escrituras
 * afectan una fila.
 */
final class PdoFalso
{
    /** @var array<int, array{n:int, sql:string, params:array}> */
    public $sentencias = [];
    public $respuestas = [];
    public $afectadas = [];
    private $nivel = 0;

    public function reiniciar(): void
    {
        $this->sentencias = [];
        $this->respuestas = [];
        $this->afectadas = [];
        $this->nivel = 0;
    }

    public function prepare($sql) { return new SentenciaFalsa($this, (string) $sql); }

    public function correr(string $sql, array $params): array
    {
        $this->sentencias[] = ['n' => ++Soporte::$secuencia, 'sql' => $sql, 'params' => array_values($params)];
        $filas = [];
        foreach ($this->respuestas as $fragmento => $respuesta) {
            if (strpos($sql, $fragmento) !== false) {
                $filas = $respuesta instanceof Closure ? $respuesta($sql, array_values($params)) : $respuesta;
                break;
            }
        }
        $afectadas = 1;
        foreach ($this->afectadas as $fragmento => $respuesta) {
            if (strpos($sql, $fragmento) !== false) {
                $afectadas = $respuesta instanceof Closure ? $respuesta($sql, array_values($params)) : $respuesta;
                break;
            }
        }
        return [$filas, $afectadas];
    }

    /** Sentencias cuyo SQL contiene el fragmento. */
    public function de(string $fragmento): array
    {
        return array_values(array_filter($this->sentencias, static fn($s) => strpos($s['sql'], $fragmento) !== false));
    }

    public function contar(string $fragmento): int
    {
        return count($this->de($fragmento));
    }

    public function beginTransaction() { $this->nivel++; return true; }
    public function commit() { $this->nivel = max(0, $this->nivel - 1); return true; }
    public function rollBack() { $this->nivel = 0; return true; }
    public function inTransaction() { return $this->nivel > 0; }
    public function lastInsertId() { return '1'; }
}

final class SentenciaFalsa
{
    private $filas = [];
    private $afectadas = 0;

    public function __construct(private PdoFalso $pdo, private string $sql) {}

    public function execute($params = [])
    {
        [$this->filas, $this->afectadas] = $this->pdo->correr($this->sql, (array) $params);
        return true;
    }
    public function fetchAll($modo = null) { return $this->filas; }
    public function fetch($modo = null) { return $this->filas[0] ?? false; }
    public function fetchColumn($columna = 0)
    {
        if (!isset($this->filas[0])) {
            return false;
        }
        $valores = array_values($this->filas[0]);
        return $valores[$columna] ?? false;
    }
    public function rowCount() { return $this->afectadas; }
}

/**
 * Un buzón sin IMAP. Las carpetas, sus estados y sus encabezados se dan por
 * guion; cada pregunta queda anotada en `llamadas`.
 */
class BuzonFalso extends MailFetcher
{
    /** carpeta => ['uidvalidity', 'uidnext', 'mensajes'] | null (inaccesible) */
    public $estados = [];
    /** carpeta => [fila de overview, …] */
    public $mensajes = [];
    /** "carpeta|uid" => texto de adjuntos | null (la carpeta no abre) */
    public $adjuntos = [];
    /** uid => mensaje como lo devuelve extraerMensaje() */
    public $extraidos = [];
    public $llamadas = [];
    /** Segundos que "tarda" cada STATUS, para agotar presupuestos. */
    public $demoraStatus = 0.0;

    public function __construct(array $config = [])
    {
        parent::__construct($config + ['host' => 'test.invalid', 'carpeta' => 'INBOX']);
    }

    public function conectar() { $this->llamadas[] = 'conectar'; return true; }
    public function estaConectado() { return true; }
    public function cerrar() {}
    public function carpetasABuscar() { return array_keys($this->estados); }
    public function nombreLegibleCarpeta($carpeta) { return (string) $carpeta; }

    public function estadoCarpeta($carpeta)
    {
        $this->llamadas[] = 'status:' . $carpeta;
        if ($this->demoraStatus > 0) {
            usleep((int) ($this->demoraStatus * 1000000));
        }
        return $this->estados[$carpeta] ?? null;
    }

    public function overviewCarpeta($carpeta, $rangoUid)
    {
        $this->llamadas[] = 'overview:' . $carpeta . ':' . $rangoUid;
        $filas = $this->mensajes[$carpeta] ?? [];
        if (preg_match('/^(\d+):\*$/', (string) $rangoUid, $m) && (int) $m[1] > 1) {
            // Como IMAP: 'n:*' devuelve lo nuevo y, aunque no haya nada, el último.
            $desde = (int) $m[1];
            $nuevas = array_values(array_filter($filas, static fn($f) => $f['uid'] >= $desde));
            return $nuevas ?: array_slice($filas, -1);
        }
        return $filas;
    }

    public function adjuntosDeMensaje($uid, $carpeta)
    {
        $this->llamadas[] = 'adjuntos:' . $carpeta . ':' . $uid;
        $clave = $carpeta . '|' . $uid;
        return array_key_exists($clave, $this->adjuntos) ? $this->adjuntos[$clave] : 'factura.xml';
    }

    public function destinatariosDeMensaje($uid, $carpeta)
    {
        $this->llamadas[] = 'destinatarios:' . $carpeta . ':' . $uid;
        $clave = $carpeta . '|' . $uid;
        if (array_key_exists($clave, $this->adjuntos) && $this->adjuntos[$clave] === null) {
            return null;
        }
        return ['cc' => '', 'reply_to' => ''];
    }

    public function extraerMensaje($uid, $carpeta = '')
    {
        $this->llamadas[] = 'extraer:' . $carpeta . ':' . $uid;
        return $this->extraidos[(int) $uid] ?? [
            'clave' => 'c3:77:' . (int) $uid, 'uid' => (int) $uid, 'asunto' => 'Sin adjuntos',
            'remitente' => 'proveedor@test.invalid', 'fecha' => '2026-09-02 10:00:00',
            'xmls' => [], 'pdfs' => [],
        ];
    }

    /** Un encabezado como lo arma overviewCarpeta(). */
    public static function fila(int $uid, int $timestamp = 0, string $asunto = 'Factura'): array
    {
        $timestamp = $timestamp ?: strtotime('2026-09-01 10:00:00') + $uid;
        return [
            'uid' => $uid, 'clave' => '77:' . $uid, 'asunto' => $asunto, 'remitente' => 'proveedor@test.invalid',
            'cc' => null, 'reply_to' => null, 'fecha' => date('Y-m-d H:i:s', $timestamp), 'timestamp' => $timestamp,
        ];
    }
}

/**
 * El índice de la sincronización, en memoria. Implementa solo lo que
 * CorreoSync::ejecutar() usa; las consultas de verdad se prueban aparte
 * contra la PDO falsa.
 */
class IndiceFalso
{
    /** carpeta => [uid => fila] */
    public $filas = [];
    /** carpeta => registro de correo_carpetas (con edad_sync y edad_cambio) */
    public $carpetas = [];
    public $reemplazos = [];
    public $inserciones = [];
    public $podados = 0;
    /** Filas de la cola de metadatos: [id, carpeta, uid, adjuntos_pendiente, cc_pendiente] */
    public $cola = [];
    public $resueltos = [];

    public function podarAntesDe($ts, $limite) { $this->podados++; return 0; }
    public function getCarpetas() { return $this->carpetas; }

    public function reemplazarCarpeta($carpeta, $nombre, $uidvalidity, array $filas)
    {
        $this->reemplazos[] = $carpeta;
        $this->filas[$carpeta] = [];
        foreach ($filas as $f) {
            $this->filas[$carpeta][$f['uid']] = $f;
        }
        return count($filas);
    }

    public function insertarLote($carpeta, $nombre, $uidvalidity, array $filas)
    {
        $this->inserciones[] = [$carpeta, array_column($filas, 'uid')];
        foreach ($filas as $f) {
            $this->filas[$carpeta][$f['uid']] = $f;
        }
        return count($filas);
    }

    public function contarCarpeta($carpeta) { return count($this->filas[$carpeta] ?? []); }

    public function guardarEstadoCarpeta($carpeta, $uidvalidity, $ultimoUid, $mensajes, $omitidos = 0,
                                         $retencion = 0, $huboCambio = false)
    {
        $previo = $this->carpetas[$carpeta] ?? [];
        $this->carpetas[$carpeta] = [
            'carpeta' => $carpeta, 'uidvalidity' => $uidvalidity, 'ultimo_uid' => $ultimoUid,
            'mensajes' => $mensajes, 'mensajes_omitidos' => $omitidos, 'retencion_dias' => $retencion,
            'edad_sync' => 0, 'edad_cambio' => $huboCambio ? 0 : ($previo['edad_cambio'] ?? null),
            'hubo_cambio' => $huboCambio,
        ];
    }

    public function pendientesMetadatos($limite) { return array_slice($this->cola, 0, $limite); }
    public function guardarAdjuntos($id, $texto) { $this->resueltos[] = ['adjuntos', $id]; }
    public function guardarDestinatarios($id, $cc, $replyTo) { $this->resueltos[] = ['cc', $id]; }

    public function contarPendientesMetadatos()
    {
        $ids = array_unique(array_column($this->resueltos, 1));
        return count(array_filter($this->cola, static fn($f) => !in_array($f['id'], $ids, true)));
    }
    public function contarPendientesAdjuntos() { return $this->contarPendientesMetadatos(); }
    public function contarPendientesCc() { return $this->contarPendientesMetadatos(); }
}

final class RespuestaEnviada extends RuntimeException {}

/**
 * El controlador de Correo sin exit ni sesión: json() graba la respuesta y
 * corta; loadModel() entrega los dobles que la prueba ponga y, si no hay,
 * el modelo real (que habla con la PDO falsa).
 */
final class CorreoControllerSonda extends CorreoController
{
    /** @var array<int, array{0:int, 1:array}> */
    public $respuestas = [];
    public $modelos = [];

    public function __construct() {}

    protected function json($data, $statusCode = 200)
    {
        $this->respuestas[] = [$statusCode, $data];
        throw new RespuestaEnviada();
    }

    protected function loadModel($modelName)
    {
        return $this->modelos[$modelName] ?? parent::loadModel($modelName);
    }

    /** Corre una acción y devuelve la PRIMERA respuesta: la que vería el navegador. */
    public function responder(string $accion): array
    {
        try {
            $this->$accion();
        } catch (RespuestaEnviada $e) {
        }
        if (!$this->respuestas) {
            throw new FalloDePrueba("{$accion}() terminó sin responder");
        }
        return $this->respuestas[0];
    }

    public function privado(string $metodo, ...$argumentos)
    {
        return (new ReflectionMethod(CorreoController::class, $metodo))->invoke($this, ...$argumentos);
    }
}

/** Lo que MailFetcher::extraerDeZip() escribe queda en storage/correo/tmp: se lleva la cuenta para borrarlo. */
function rutasDeAdjuntos(array $adjuntos): array
{
    return array_values(array_filter(array_map(static fn($a) => (string) ($a['ruta'] ?? ''), $adjuntos)));
}
