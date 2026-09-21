<?php
/**
 * Enrutador de la aplicación
 * Maneja registro y despacho de rutas
 */

// La comprobación de permisos y el registro de la bitácora ocurren ANTES de
// que exista un controlador, así que ambos se cargan aquí y no por loadModel.
require_once __DIR__ . '/Permisos.php';
require_once __DIR__ . '/Requisitos.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/../models/Bitacora.php';

class Router
{
    protected $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => []
    ];
    
    protected $currentController = 'HomeController';
    protected $currentMethod = 'index';
    protected $params = [];
    
    /**
     * Registrar ruta GET
     */
    public function get($uri, $callback)
    {
        $this->addRoute('GET', $uri, $callback);
        return $this;
    }
    
    /**
     * Registrar ruta POST
     */
    public function post($uri, $callback)
    {
        $this->addRoute('POST', $uri, $callback);
        return $this;
    }
    
    /**
     * Registrar ruta PUT
     */
    public function put($uri, $callback)
    {
        $this->addRoute('PUT', $uri, $callback);
        return $this;
    }
    
    /**
     * Registrar ruta DELETE
     */
    public function delete($uri, $callback)
    {
        $this->addRoute('DELETE', $uri, $callback);
        return $this;
    }
    
    /**
     * Agregar ruta al registro
     */
    private function addRoute($method, $uri, $callback)
    {
        // Normalizar URI
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }
        
        // Convertir parámetros de ruta {id} a regex
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $uri);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[$method][$pattern] = [
            'callback' => $callback,
            'uri' => $uri
        ];
    }
    
    /**
     * Despachar ruta
     */
    public function dispatch($method, $uri)
    {
        // Normali zar URI
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }
        
        // Buscar coincidencia en rutas registradas
        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $pattern => $route) {
                if (preg_match($pattern, $uri, $matches)) {
                    // Extraer parámetros nombrados
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                    /*
                     * El único sitio por el que pasa TODO. Por eso la
                     * comprobación de permisos vive aquí y no en cada
                     * controlador: una pantalla nueva que se olvide de
                     * llamar a requireAlgo() no queda desprotegida, y quien
                     * escriba la dirección a mano choca con la misma puerta
                     * que quien pulsó el botón.
                     *
                     * Se comprueba contra la ruta REGISTRADA
                     * ('/facturas/ver/{id}'), no contra la pedida, para que
                     * el permiso no dependa del id que traiga.
                     */
                    Permisos::guardia($method, $route['uri']);

                    /*
                     * Y después de "quién eres", "si hay adónde entrar": hay
                     * tres cosas —el catálogo de proveedores, una empresa y
                     * un buzón— sin las cuales un módulo no trabaja, solo lo
                     * aparenta. Va aquí por el mismo motivo: el menú los
                     * dibuja apagados, pero escribir la dirección a mano
                     * tiene que chocar con la misma puerta.
                     */
                    Requisitos::guardia($method, $route['uri']);

                    // Y todo lo que escribe tiene que venir de una pantalla
                    // de la aplicación, no de una página ajena que aprovecha
                    // la sesión abierta. Mismo motivo para ponerlo aquí: una
                    // acción nueva queda protegida sin acordarse de nada.
                    if ($method !== 'GET' && $method !== 'HEAD') {
                        Csrf::exigir($route['uri']);
                    }

                    // Y aquí se deja programado el renglón de la bitácora, si
                    // esta ruta cambia algo. Se escribe al terminar la
                    // petición: casi todos los controladores acaban en un
                    // exit y nada puesto "después" llegaría a correr.
                    Bitacora::vigilar($method, $route['uri'], $params);

                    return $this->execute($route['callback'], $params);
                }
            }
        }
        
        // Si no hay coincidencia, 404
        $this->notFound();
    }
    
    /**
     * Ejecutar callback de ruta
     */
    private function execute($callback, $params = [])
    {
        // Si es string, parsear controlador@método
        if (is_string($callback)) {
            $parts = explode('@', $callback);
            $controller = $parts[0];
            $method = $parts[1] ?? 'index';
            
            return $this->callController($controller, $method, $params);
        }
        
        // Si es callable (closure), ejecutar directamente
        if (is_callable($callback)) {
            return call_user_func_array($callback, $params);
        }
        
        throw new Exception("Callback de ruta inválido");
    }
    
    /**
     * Llamar controlador y método
     */
    private function callController($controllerName, $methodName, $params = [])
    {
        $controllerFile = __DIR__ . '/../controllers/' . $controllerName . '.php';
        
        if (!file_exists($controllerFile)) {
            throw new Exception("Controlador no encontrado: {$controllerName}");
        }
        
        require_once $controllerFile;
        
        if (!class_exists($controllerName)) {
            throw new Exception("Clase de controlador no encontrada: {$controllerName}");
        }
        
        $controller = new $controllerName();
        
        if (!method_exists($controller, $methodName)) {
            throw new Exception("Método no encontrado: {$controllerName}@{$methodName}");
        }
        
        // Llamar método con parámetros
        return call_user_func_array([$controller, $methodName], array_values($params));
    }
    
    /**
     * Página no encontrada (404)
     */
    private function notFound()
    {
        global $app;
        
        if (isset($app)) {
            $app->showError(404, 'Página no encontrada', 'La ruta solicitada no existe.');
        } else {
            http_response_code(404);
            echo '404 - Página no encontrada';
        }
        
        exit;
    }
    
    /**
     * Obtener todas las rutas registradas
     */
    public function getRoutes()
    {
        return $this->routes;
    }
}
