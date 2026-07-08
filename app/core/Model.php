<?php
/**
 * Clase base para modelos
 * Proporciona acceso a base de datos con PDO
 */

class Model
{
    protected static $db = null;
    protected $table;
    
    /**
     * Obtener conexión a base de datos
     */
    protected static function getDB()
    {
        if (self::$db === null) {
            $config = require __DIR__ . '/../config/database.php';
            
            try {
                $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
                
                self::$db = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Emulación activada: necesario para compatibilidad con ProxySQL (InfinityFree)
                    // y para que lastInsertId() funcione correctamente en hosting compartido.
                    PDO::ATTR_EMULATE_PREPARES => true,
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
        return $stmt->fetchAll();
    }
    
    /**
     * Obtener un registro
     */
    protected function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
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
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        return $this->fetchOne($sql, [$id]);
    }

    /**
     * Contar registros
     */
    public function count($where = '', $params = [])
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}";

        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        return (int) $this->fetchColumn($sql, $params);
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
