<?php
/**
 * Clase principal de la aplicación MVC
 * Bootstrap y despachador de rutas
 */

class App
{
    protected $router;
    protected $config;
    
    public function __construct()
    {
        $this->loadConfiguration();
        $this->initErrorHandling();
        $this->initRouter();
    }
    
    /**
     * Cargar configuración de la aplicación
     */
    private function loadConfiguration()
    {
        // Cargar configuración principal
        if (file_exists(__DIR__ . '/../config/config.php')) {
            $this->config = require __DIR__ . '/../config/config.php';
        } else {
            die('Error: Archivo de configuración no encontrado.');
        }
        
        // Definir constantes globales
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $this->config['base_path'] ?? dirname(__DIR__, 2));
        }
        
        if (!defined('APP_URL')) {
            define('APP_URL', $this->config['app_url'] ?? 'http://localhost/xmlconcilia/public');
        }
        
        // Configurar zona horaria
        date_default_timezone_set($this->config['timezone'] ?? 'America/Mexico_City');
        
        // Configurar reporte de errores según ambiente
        if ($this->config['environment'] === 'development') {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
    }
    
    /**
     * Inicializar manejo de errores personalizado
     */
    private function initErrorHandling()
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    /**
     * Inicializar router y cargar rutas
     */
    private function initRouter()
    {
        require_once __DIR__ . '/Router.php';
        $this->router = new Router();
        
        // Cargar definición de rutas con $app disponible
        if (file_exists(__DIR__ . '/../config/routes.php')) {
            $app = $this; // Hacer $this disponible como $app en routes.php
            require __DIR__ . '/../config/routes.php';
        }
    }
    
    /**
     * Ejecutar la aplicación
     */
    public function run()
    {
        // Obtener método HTTP y URI
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $this->getUri();
        
        // Despachar ruta
        $this->router->dispatch($method, $uri);
    }
    
    /**
     * Obtener URI limpia
     */
    private function getUri()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remover query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Remover base path si existe (para subdirectorios)
        $basePath = $this->config['base_uri'] ?? '/xmlconcilia/public';
        if (strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }
        
        // Asegurar que empiece con /
        return '/' . ltrim($uri, '/');
    }
    
    /**
     * Obtener instancia del router
     */
    public function getRouter()
    {
        return $this->router;
    }
    
    /**
     * Manejador de excepciones
     */
    public function handleException($exception)
    {
        $this->logError($exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        $this->showError(500, 'Error interno del servidor', $exception->getMessage());
    }
    
    /**
     * Manejador de errores PHP
     */
    public function handleError($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $this->logError($errstr, [
            'file' => $errfile,
            'line' => $errline,
            'code' => $errno
        ]);
        
        if ($this->config['environment'] === 'development') {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
        
        return true;
    }
    
    /**
     * Manejador de errores fatales
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->logError($error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
            
            $this->showError(500, 'Error fatal', $error['message']);
        }
    }
    
    /**
     * Registrar error en log
     */
    private function logError($message, $context = [])
    {
        $logFile = BASE_PATH . '/storage/logs/app.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] ERROR: {$message} {$contextStr}\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    /**
     * Mostrar pantalla de error
     */
    public function showError($code, $title, $message = '')
    {
        http_response_code($code);
        
        if ($this->config['environment'] === 'production') {
            $message = ''; // No mostrar detalles en producción
        }
        
        include __DIR__ . '/../views/errors/' . $code . '.php';
        exit;
    }
}
