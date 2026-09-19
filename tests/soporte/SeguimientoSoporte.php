<?php
/**
 * Soporte común de las pruebas de borde, seguridad y concurrencia de
 * Seguimiento. No termina en Test.php a propósito: no es una prueba, lo usan.
 *
 * Tres cosas que las pruebas de siempre no tenían:
 *
 *   El arnés no se detiene en el primer fallo, y separa dos clases de casos.
 *   caso() tiene que pasar. defecto() afirma el comportamiento CORRECTO de
 *   algo que hoy está mal: mientras el defecto siga, se informa como
 *   "DEFECTO ABIERTO" y no rompe la certificación; el día que se arregle, la
 *   prueba falla para que se pase a caso() y la lista de defectos no mienta.
 *
 *   Los avisos de PHP se convierten en excepciones, igual que hace App en
 *   desarrollo. Producción los calla (error_reporting 0), así que un
 *   "Undefined array key" puede funcionar allá por casualidad y tumbar la
 *   misma acción en cualquier copia de desarrollo o de certificación.
 *
 *   Nada toca una base real: la conexión de Model es una PDO falsa que graba
 *   lo que le piden, el esquema se da por comprobado, la empresa en curso ya
 *   está resuelta y los documentos viven en un almacén en memoria.
 */

final class SesionEnMemoria implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}

// La sesión se abre antes de cargar nada: en CLI, cualquier salida previa —un
// aviso de PHP al compilar un archivo de la aplicación— cuenta como cabeceras
// enviadas y ya no deja abrirla. usuario() la abre si la encuentra cerrada.
session_set_save_handler(new SesionEnMemoria(), true);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

require_once __DIR__ . '/../../app/core/Model.php';
require_once __DIR__ . '/../../app/core/Controller.php';
require_once __DIR__ . '/../../app/models/Sociedad.php';
require_once __DIR__ . '/../../app/models/Notificacion.php';
require_once __DIR__ . '/../../app/models/Seguimiento.php';
require_once __DIR__ . '/../../app/models/ProveedorCatalogo.php';
require_once __DIR__ . '/../../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/AlmacenDePrueba.php';
require_once __DIR__ . '/../../app/controllers/SeguimientoController.php';

// ── Aserciones ──────────────────────────────────────────────────────────────

final class FalloDePrueba extends RuntimeException {}

function verificar($condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new FalloDePrueba($mensaje);
    }
}

function igual($esperado, $actual, string $mensaje): void
{
    if ($esperado !== $actual) {
        throw new FalloDePrueba($mensaje . ' | esperado: ' . Soporte::corto($esperado)
            . ' | obtenido: ' . Soporte::corto($actual));
    }
}

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
        }
    }

    /**
     * @param string $codigo El código con el que figura el hallazgo en la
     *                       revisión del módulo, para poder cruzarlos.
     */
    public static function defecto(string $codigo, string $nombre, callable $cuerpo): void
    {
        Soporte::limpiarPeticion();
        try {
            $cuerpo();
            self::$cerrados[] = "[{$codigo}] {$nombre}";
        } catch (Throwable $e) {
            self::$abiertos[] = "[{$codigo}] {$nombre} → " . self::describir($e);
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
            : get_class($e) . ': ' . $e->getMessage();
        // El certificador marca como fallida cualquier salida con estas
        // palabras. Un defecto abierto las puede traer en el mensaje de PHP
        // que lo demuestra, y eso no es un fallo de la prueba.
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
    /** @var AlmacenEnMemoria */
    public static $almacen;
    /** Un solo reloj para ordenar lo que graban el modelo y la PDO falsa. */
    public static $secuencia = 0;

    public static function aislar(): void
    {
        self::$pdo = new PdoFalso();
        (new ReflectionProperty(Model::class, 'db'))->setValue(null, self::$pdo);

        (new ReflectionProperty(Esquema::class, 'enEstaPeticion'))->setValue(null, array_fill_keys(
            ['Seguimiento', 'Notificacion', 'Sociedad', 'Bitacora', 'ProveedorCodigoErp'],
            true
        ));
        self::empresaEnCurso(0);

        self::$almacen = new AlmacenEnMemoria();
        AlmacenDocumentos::fijar(self::$almacen);

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo = '', int $linea = 0) {
            throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });
    }

    public static function limpiarPeticion(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach (array_keys($_SERVER) as $clave) {
            if (strpos($clave, 'HTTP_') === 0) {
                unset($_SERVER[$clave]);
            }
        }
        // El token de la sesión y la cabecera con la que app.js lo manda en
        // cada fetch: una petición de prueba es, salvo que el caso diga otra
        // cosa, una que salió de la propia pantalla.
        $_SESSION = ['user_id' => 7, 'user_nombre' => 'Ana Prueba', 'csrf_token' => 'token-de-prueba'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-de-prueba';
        self::$pdo->reiniciar();
        self::$almacen->archivos = [];
        self::$almacen->preguntas = 0;
        self::empresaEnCurso(0);
    }

    public static function empresaEnCurso(int $id): void
    {
        (new ReflectionProperty(Sociedad::class, 'cacheSeleccionada'))->setValue(null, $id);
    }

    /** Evita que ProveedorCatalogo vaya a la base a resolver una clave. */
    public static function proveedorResuelto(string $clave, array $identidad): void
    {
        $prop = new ReflectionProperty(ProveedorCatalogo::class, 'resueltas');
        $actuales = $prop->getValue();
        $actuales[$clave] = $identidad + [
            'clave' => $clave, 'cedula' => '', 'proveedor_ids' => [], 'codigos' => [], 'nombres' => [],
        ];
        $prop->setValue(null, $actuales);
    }

    public static function corto($valor): string
    {
        $texto = var_export($valor, true);
        return mb_strlen($texto) > 300 ? mb_substr($texto, 0, 300) . '…' : $texto;
    }

    /** Un renglón como lo devuelve sqlBase(), con todas sus columnas. */
    public static function filaCola(array $cambios = []): array
    {
        return $cambios + [
            'origen' => 'factura', 'referencia_id' => '12', 'documento' => 'FE-00012',
            'nc_proveedor' => null, 'clase' => null, 'proveedor' => 'PROVEEDOR DEMO S.A.',
            'proveedor_codigo' => 'P001', 'proveedor_cedula' => null, 'proveedor_id' => '3',
            'sucursal' => 'CENTRAL', 'fecha' => '2026-09-01', 'moneda' => 'CRC',
            'monto' => '1500.00', 'saldo' => '1500.00', 'diferencia' => null, 'motivo_match' => null,
            'factura_xml_id' => '44', 'contexto_id' => null, 'contexto' => null, 'sociedad_id' => '4',
            'ruta_xml' => '', 'ruta_pdf' => '', 'estado_pdf' => null, 'recuperable' => '0',
            'consecutivo' => null, 'tarea' => 'falta_xml', 'en_juego' => '1500.00',
            'llegada' => null, 'sistema_doc' => null,
            'aplicacion_estado' => null, 'aplicacion_factura_id' => null,
            'aplicacion_factura_doc' => null, 'aplicacion_factura_saldo' => null,
            'notas_vivas' => '0', 'notas_vivas_saldo' => '0',
            'seguimiento_id' => null, 'seguimiento_estado' => 'pendiente',
            'estado_calculado' => 'pendiente', 'estado_a_mano' => null, 'responsable' => null,
            'recordar_en' => null, 'recordar_cada' => null, 'avisado_en' => null, 'motivo' => null,
            'actualizado_por' => null, 'seguimiento_actualizado_en' => null, 'anotaciones' => '0',
        ];
    }
}

// ── Dobles ──────────────────────────────────────────────────────────────────

/**
 * Un almacén que solo sabe decir si un documento está, y cuenta cuántas veces
 * se lo preguntaron. Eso es lo que miran estas pruebas: que pintar un listado
 * no dispare una pregunta por fila —con los documentos en SharePoint, cien
 * filas serían cien viajes por red—.
 *
 * Lo demás lo hereda de AlmacenDePrueba, que guarda en memoria. No se usa
 * acá, pero tenerlo evita que agregar una operación a la puerta rompa las
 * pruebas de Seguimiento, que no tienen nada que ver.
 */
final class AlmacenEnMemoria extends AlmacenDePrueba
{
    /** ruta => true */
    public $archivos = [];
    public $preguntas = 0;

    public function existe($ruta) { $this->preguntas++; return isset($this->archivos[$ruta]); }
    public function abrir($ruta) { return false; }
    public function tamano($ruta) { return null; }
    public function rutaLocal($ruta) { return null; }
    public function porQueNoSeLee($ruta) { return 'almacén de pruebas'; }
    public function descripcion() { return 'memoria de pruebas'; }
}

/**
 * La conexión de Model. La usan los modelos que se instancian por dentro
 * —Notificacion, al cerrar o dejar avisos—, que no se pueden sustituir.
 */
final class PdoFalso
{
    /** @var array<int, array{n:int, sql:string, params:array}> */
    public $sentencias = [];
    /** @var callable|null fn(string $sql, array $params): array{0: array, 1: int} */
    public $responder = null;
    private $nivel = 0;

    public function reiniciar(): void
    {
        $this->sentencias = [];
        $this->responder = null;
        $this->nivel = 0;
    }

    public function prepare($sql) { return new SentenciaFalsa($this, (string) $sql); }

    public function correr(string $sql, array $params): array
    {
        $this->sentencias[] = ['n' => ++Soporte::$secuencia, 'sql' => $sql, 'params' => $params];
        return $this->responder ? ($this->responder)($sql, $params) : [[], 1];
    }

    public function contar(string $fragmento): int
    {
        return count(array_filter($this->sentencias, static fn($s) => strpos($s['sql'], $fragmento) !== false));
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
 * El modelo de Seguimiento con sus consultas grabadas en vez de enviadas.
 *
 * `respuestas` contesta por fragmento de SQL (el primero que coincide), con
 * un valor fijo o con una función que recibe (sql, params). Sin respuesta,
 * las lecturas vuelven vacías y el COUNT devuelve `total`.
 */
class SeguimientoGrabadora extends Seguimiento
{
    /** @var array<int, array{n:int, tipo:string, sql:string, params:array}> */
    public $consultas = [];
    /** Los candados con nombre están libres, como en una base sin nadie más. */
    public $respuestas = ['SELECT GET_LOCK(' => 1, 'SELECT RELEASE_LOCK(' => 1];
    public $total = 0;
    /** Fragmento de SQL de escritura que debe fallar, y con qué. */
    public $fallarEn = null;
    public $falla = null;

    public function __construct() {}

    protected function fetchAll($sql, $params = [])
    {
        return $this->contestar('fetchAll', $sql, $params) ?? [];
    }

    protected function fetchOne($sql, $params = [])
    {
        return $this->contestar('fetchOne', $sql, $params) ?? false;
    }

    protected function fetchColumn($sql, $params = [])
    {
        return $this->contestar('fetchColumn', $sql, $params) ?? $this->total;
    }

    protected function execute($sql, $params = [])
    {
        $this->grabar('execute', $sql, $params);
        if ($this->fallarEn !== null && strpos($sql, $this->fallarEn) !== false) {
            throw $this->falla ?? new PDOException('falla simulada');
        }
        $respuesta = $this->buscar($sql, $params, 'execute:');
        return $respuesta ?? 1;
    }

    public function begin()    { $this->grabar('begin', '', []); return true; }
    public function commit()   { $this->grabar('commit', '', []); return true; }
    public function rollback() { $this->grabar('rollback', '', []); return true; }

    public function privado(string $metodo, ...$argumentos)
    {
        return (new ReflectionMethod(Seguimiento::class, $metodo))->invoke($this, ...$argumentos);
    }

    /** Las sentencias de un tipo cuyo SQL contiene el fragmento. */
    public function de(string $tipo, string $fragmento = ''): array
    {
        return array_values(array_filter($this->consultas, static fn($c) =>
            $c['tipo'] === $tipo && ($fragmento === '' || strpos($c['sql'], $fragmento) !== false)));
    }

    public function tipos(): array
    {
        return array_column($this->consultas, 'tipo');
    }

    /**
     * Renglones que ya tienen fila en `seguimiento`: clave "origen|id" =>
     * ['id' => .., 'estado' => ..]. Los que no estén se tratan como nuevos:
     * no salen en la lectura del estado anterior, pero sí tienen id después
     * del upsert, que es lo que pasa de verdad.
     */
    public function existentes(array $mapa): self
    {
        $pares = static function (array $params) {
            $claves = [];
            for ($i = 0; $i + 1 < count($params); $i += 2) {
                $claves[] = $params[$i] . '|' . (int) $params[$i + 1];
            }
            return $claves;
        };
        $this->respuestas['SELECT origen, referencia_id, estado FROM seguimiento'] =
            static function ($sql, $params) use ($mapa, $pares) {
                $filas = [];
                foreach ($pares($params) as $clave) {
                    if (isset($mapa[$clave])) {
                        [$origen, $ref] = explode('|', $clave);
                        $filas[] = ['origen' => $origen, 'referencia_id' => $ref, 'estado' => $mapa[$clave]['estado']];
                    }
                }
                return $filas;
            };
        $this->respuestas['SELECT id, origen, referencia_id FROM seguimiento'] =
            static function ($sql, $params) use ($mapa, $pares) {
                $filas = [];
                $nuevo = 9000;
                foreach ($pares($params) as $clave) {
                    [$origen, $ref] = explode('|', $clave);
                    $filas[] = ['id' => $mapa[$clave]['id'] ?? ++$nuevo, 'origen' => $origen, 'referencia_id' => $ref];
                }
                return $filas;
            };
        return $this->enCola(array_keys($mapa));
    }

    /**
     * Renglones que existen en la cola de la empresa ("origen|id"). Un renglón
     * con fila de seguimiento existe; los demás, solo si se declaran aquí.
     */
    public function enCola(array $claves): self
    {
        $this->respuestas['AS en_cola'] = static function () use ($claves) {
            return array_map(static function ($clave) {
                [$origen, $ref] = explode('|', $clave);
                return ['origen' => $origen, 'referencia_id' => $ref, 'en_cola' => 1];
            }, $claves);
        };
        return $this;
    }

    private function contestar(string $tipo, string $sql, array $params)
    {
        $this->grabar($tipo, $sql, $params);
        return $this->buscar($sql, $params);
    }

    private function buscar(string $sql, array $params, string $prefijo = '')
    {
        foreach ($this->respuestas as $fragmento => $respuesta) {
            $esDeEscritura = strpos($fragmento, 'execute:') === 0;
            if ($prefijo === 'execute:' ? !$esDeEscritura : $esDeEscritura) {
                continue;
            }
            $busca = $esDeEscritura ? substr($fragmento, strlen('execute:')) : $fragmento;
            if (strpos($sql, $busca) !== false) {
                return $respuesta instanceof Closure ? $respuesta($sql, $params) : $respuesta;
            }
        }
        return null;
    }

    private function grabar(string $tipo, string $sql, array $params): void
    {
        $this->consultas[] = ['n' => ++Soporte::$secuencia, 'tipo' => $tipo, 'sql' => $sql, 'params' => $params];
    }
}

final class RespuestaEnviada extends RuntimeException {}

/**
 * El controlador sin exit ni base: json() graba la respuesta y corta, y
 * loadModel() entrega los dobles que la prueba haya puesto.
 */
final class SeguimientoControllerSonda extends SeguimientoController
{
    /** @var array<int, array{0:int, 1:array}> */
    public $respuestas = [];
    public $modelos = [];
    /** Lo que habría ido a storage/logs/app.log: [donde, excepción]. */
    public $fallosRegistrados = [];

    public function __construct() {}

    protected function registrarFallo(string $donde, Throwable $e): void
    {
        $this->fallosRegistrados[] = [$donde, $e];
    }

    protected function json($data, $statusCode = 200)
    {
        $this->respuestas[] = [$statusCode, $data];
        throw new RespuestaEnviada();
    }

    protected function loadModel($modelName)
    {
        if (!isset($this->modelos[$modelName])) {
            throw new RuntimeException("La prueba no preparó el modelo {$modelName}");
        }
        return $this->modelos[$modelName];
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
        $ref = new ReflectionMethod(SeguimientoController::class, $metodo);
        return $ref->isStatic() ? $ref->invoke(null, ...$argumentos) : $ref->invoke($this, ...$argumentos);
    }
}
