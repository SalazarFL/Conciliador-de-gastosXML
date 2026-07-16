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

        // Forzar escritura en disco antes del redirect; en hosting compartido
        // PHP puede no escribir la sesión a tiempo si solo se depende del shutdown.
        session_write_close();

        $this->redirect($url);
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
        $baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Semana de trabajo activa, compartida entre módulos (Carga XML, Correo,
     * Facturas por pagar). Se guarda en sesión para que al pasar de un módulo
     * a otro se recuerde la última semana elegida. 0 = "Sin semana".
     */
    protected function semanaActiva(): int
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return max(0, (int) ($_SESSION['semana_activa'] ?? 0));
    }

    /**
     * Fijar la semana de trabajo activa compartida (0 = "Sin semana").
     */
    protected function setSemanaActiva($semanaId): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['semana_activa'] = max(0, (int) $semanaId);
    }

    /**
     * Requerir sesión activa — redirige a /login si no hay sesión
     */
    protected function requireAuth(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['user_id'])) {
            $this->redirect($this->url('/login'));
        }
    }

    /**
     * Requerir sesión de administrador — redirige a inicio si no es admin
     */
    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (empty($_SESSION['user_is_admin'])) {
            $this->redirect($this->url('/'));
        }
    }
}
