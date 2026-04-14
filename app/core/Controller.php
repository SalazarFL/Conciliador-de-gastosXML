<?php
/**
 * Clase base para controladores
 * Proporciona métodos comunes para renderizado y redirección
 */

class Controller
{
    /**
     * Renderizar vista
     */
    protected function render($view, $data = [])
    {
        // Extraer datos para usar como variables en la vista
        extract($data);
        
        // Construir ruta de vista
        $viewFile = __DIR__ . '/../views/' . str_replace('.', '/', $view) . '.php';
        
        if (!file_exists($viewFile)) {
            throw new Exception("Vista no encontrada: {$view}");
        }
        
        // Capturar contenido de la vista
        ob_start();
        include $viewFile;
        $content = ob_get_clean();
        
        // Renderizar con layout si existe
        $this->renderWithLayout($content, $data);
    }
    
    /**
     * Renderizar con layout
     */
    private function renderWithLayout($content, $data = [])
    {
        extract($data);
        
        $headerFile = __DIR__ . '/../views/layout/header.php';
        $footerFile = __DIR__ . '/../views/layout/footer.php';
        
        // Header
        if (file_exists($headerFile)) {
            include $headerFile;
        }
        
        // Contenido
        echo $content;
        
        // Footer
        if (file_exists($footerFile)) {
            include $footerFile;
        }
    }
    
    /**
     * Renderizar vista sin layout
     */
    protected function renderPartial($view, $data = [])
    {
        extract($data);
        
        $viewFile = __DIR__ . '/../views/' . str_replace('.', '/', $view) . '.php';
        
        if (!file_exists($viewFile)) {
            throw new Exception("Vista no encontrada: {$view}");
        }
        
        include $viewFile;
    }
    
    /**
     * Renderizar JSON
     */
    protected function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Redireccionar a URL
     */
    protected function redirect($url, $statusCode = 302)
    {
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Redireccionar con mensaje flash
     */
    protected function redirectWithMessage($url, $message, $type = 'success', array $details = [])
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['flash_message'] = [
            'message' => $message,
            'type' => $type,
            'details' => $details
        ];
        
        $this->redirect($url);
    }
    
    /**
     * Obtener mensaje flash
     */
    protected function getFlashMessage()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $message;
        }
        
        return null;
    }
    
    /**
     * Validar request POST
     */
    protected function isPost()
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    /**
     * Validar request GET
     */
    protected function isGet()
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }
    
    /**
     * Obtener dato de POST
     */
    protected function post($key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }
    
    /**
     * Obtener dato de GET
     */
    protected function get($key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }
    
    /**
     * Obtener todos los datos de POST
     */
    protected function postData()
    {
        return $_POST;
    }
    
    /**
     * Obtener todos los datos de GET
     */
    protected function getData()
    {
        return $_GET;
    }
    
    /**
     * Sanitizar string
     */
    protected function sanitize($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitize'], $data);
        }
        
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Cargar modelo
     */
    protected function loadModel($modelName)
    {
        $modelFile = __DIR__ . '/../models/' . $modelName . '.php';
        
        if (!file_exists($modelFile)) {
            throw new Exception("Modelo no encontrado: {$modelName}");
        }
        
        require_once $modelFile;
        
        if (!class_exists($modelName)) {
            throw new Exception("Clase de modelo no encontrada: {$modelName}");
        }
        
        return new $modelName();
    }
    
    /**
     * Obtener URL base
     */
    protected function url($path = '')
    {
        $baseUrl = defined('APP_URL') ? APP_URL : 'http://localhost/xmlconcilia/public';
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
