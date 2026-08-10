<?php
/**
 * Clase base para modelos
 * Proporciona acceso a base de datos con PDO
 */

// Alcance por sociedad: lo usan los modelos que guardan documentos de una
// empresa. Se carga aquí porque Model.php es lo primero que entra siempre.
require_once __DIR__ . '/AlcanceSociedad.php';
require_once __DIR__ . '/Esquema.php';
require_once __DIR__ . '/../helpers/RutaDocumento.php';

class Model
{
    protected static $db = null;
    protected $table;

    /**
     * Respuestas ya obtenidas durante ESTA petición.
     *
     * Una carga de Correo preguntaba once veces lo mismo a
     * correo_cuenta_sociedades, siete veces la misma fila de correo_cuentas y
     * cinco veces el mismo COUNT. Con la base al lado eran microsegundos; a
     * 85 ms de distancia son segundos de espera por respuestas idénticas.
     */
    private static $memoria = [];

    private static $recordar = null;

    /**
     * Solo se recuerda en peticiones web, que duran milisegundos y terminan.
     * Los comandos de cli/ corren durante minutos junto a otros procesos que
     * escriben en la misma base: ahí recordar es arriesgarse a trabajar sobre
     * datos que otro ya cambió.
     */
    private static function recordar()
    {
        if (self::$recordar === null) {
            self::$recordar = PHP_SAPI !== 'cli';
        }
        return self::$recordar;
    }

    /** Vaciar lo recordado. La usan las pruebas. */
    public static function olvidarMemoria()
    {
        self::$memoria = [];
    }

    /**
     * Responde una pregunta cara una sola vez por petición.
     *
     * Para consultas propias de un modelo que se repiten dentro de la misma
     * carga. Cualquier escritura la invalida sola, porque pasa por query().
     */
    protected function recordado($clave, callable $obtener)
    {
        $clave = $this->table . '|' . $clave;
        if (array_key_exists($clave, self::$memoria)) {
            return self::$memoria[$clave];
        }

        $valor = $obtener();
        if (self::recordar()) {
            self::$memoria[$clave] = $valor;
        }
        return $valor;
    }

    /**
     * Columnas de este modelo que guardan la ruta de un documento.
     *
     * En la base se guardan relativas a la carpeta compartida; al leerlas se
     * convierten a la ruta real de esta máquina. Declararlo aquí evita que
     * cada consulta tenga que acordarse: basta con nombrar la columna una vez.
     * Al escribir sí hay que llamar a RutaDocumento::relativa() en el
     * INSERT/UPDATE, porque los parámetros van por posición y el modelo no
     * puede adivinar cuál de ellos es una ruta.
     */
    protected $camposRuta = [];

    /**
     * Obtener conexión a base de datos
     */
    protected static function getDB()
    {
        if (self::$db === null) {
            $config = require __DIR__ . '/../config/database.php';
            
            try {
                self::$db = new PDO($config['dsn'], $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Emulación activada: manda la consulta ya armada, en un solo
                    // viaje. Con la base en el servidor cada viaje cuesta la
                    // latencia de la red, y las preparadas nativas cuestan dos.
                    PDO::ATTR_EMULATE_PREPARES => true,
                    // Sin esto, un servidor inalcanzable deja la pantalla colgada
                    // hasta que el sistema operativo se rinde.
                    PDO::ATTR_TIMEOUT => 10,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']}"
                ]);
            } catch (PDOException $e) {
                throw new Exception("Error de conexión a base de datos: " . $e->getMessage());
            }
        }
        
        return self::$db;
    }
    
    /**
     * Ejecutar query preparada
     */
    protected function query($sql, $params = [])
    {
        // Cualquier escritura deja obsoleto lo recordado. Se vacía todo y no
        // solo la tabla escrita: un UPDATE con JOIN toca varias, y equivocarse
        // aquí significa mostrar datos viejos, que es peor que repetir una
        // consulta.
        if (self::$memoria && preg_match('/^\s*\(*\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql)) {
            self::$memoria = [];
        }

        $db = self::getDB();

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError($sql, $params, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtener todos los registros
     */
    protected function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $filas = $stmt->fetchAll();
        return $this->camposRuta
            ? RutaDocumento::hidratarFilas($filas, $this->camposRuta)
            : $filas;
    }

    /**
     * Obtener un registro
     */
    protected function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $fila = $stmt->fetch();
        return $this->camposRuta && is_array($fila)
            ? RutaDocumento::hidratarFila($fila, $this->camposRuta)
            : $fila;
    }
    
    /**
     * Obtener valor de una columna
     */
    protected function fetchColumn($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Ejecutar INSERT y retornar ID
     */
    protected function insert($sql, $params = [])
    {
        $this->query($sql, $params);
        return self::getDB()->lastInsertId();
    }
    
    /**
     * Ejecutar UPDATE/DELETE y retornar filas afectadas
     */
    protected function execute($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Buscar por ID
     */
    public function findById($id)
    {
        $clave = $this->table . '|id|' . $id;
        if (array_key_exists($clave, self::$memoria)) {
            return self::$memoria[$clave];
        }

        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);
        if (self::recordar()) {
            self::$memoria[$clave] = $fila;
        }
        return $fila;
    }

    /**
     * Contar registros
     */
    public function count($where = '', $params = [])
    {
        $clave = $this->table . '|count|' . $where . '|' . json_encode($params);
        if (array_key_exists($clave, self::$memoria)) {
            return self::$memoria[$clave];
        }

        $sql = "SELECT COUNT(*) FROM {$this->table}";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        $total = (int) $this->fetchColumn($sql, $params);
        if (self::recordar()) {
            self::$memoria[$clave] = $total;
        }
        return $total;
    }

    /**
     * Registrar error en log
     */
    private function logError($sql, $params, $error)
    {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/app.log';
        $timestamp = date('Y-m-d H:i:s');
        $paramsStr = json_encode($params);
        $logMessage = "[{$timestamp}] DB ERROR: {$error}\nSQL: {$sql}\nParams: {$paramsStr}\n\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
