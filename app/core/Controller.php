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
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            http_response_code(500);
            $json = '{"ok":false,"message":"No fue posible generar la respuesta JSON."}';
        }

        echo $json;
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
        // Sin REQUEST_METHOD (workers de cli/) no hay petición: no es POST.
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /**
     * Validar request GET
     */
    protected function isGet()
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
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
     * Filtros recordados por módulo.
     *
     * Cada listado guarda en sesión lo último que se filtró y lo vuelve a
     * aplicar al volver, para no reescribir los mismos criterios cada vez que
     * se pasa por otra pantalla. Lo guardado es de ESE módulo: los filtros de
     * Facturas XML no se meten en Seguimiento, porque "estado" o "qué falta"
     * no significan lo mismo en cada uno. Lo único compartido sigue siendo la
     * semana de trabajo, que sí es la misma pregunta en todos lados.
     *
     * Se llama al principio del index(), antes de leer nada: rellena $_GET con
     * lo recordado y el resto del controlador sigue leyendo con get() sin
     * enterarse.
     *
     * Cómo distingue "acabo de filtrar" de "acabo de entrar": las barras de
     * filtro se envían por GET, así que al filtrar llegan TODAS sus claves,
     * aunque vengan vacías. Que llegue alguna es la señal de que la persona
     * está filtrando ahora —y entonces eso es lo nuevo que se guarda—; que no
     * llegue ninguna significa que entró por el menú, y ahí se le devuelve lo
     * que dejó puesto. Por eso se mira si la clave viene, no si trae valor:
     * mirar el valor haría imposible vaciar un filtro.
     *
     * El botón "Limpiar" llega con ?limpiar=1 y sin criterios: es la única
     * forma de olvidar, y por eso borra lo guardado en vez de resucitarlo.
     *
     * $claves lleva solo los filtros de la barra. Lo que elige QUÉ se está
     * viendo —el listado, la importación, la carga, la semana— se queda fuera:
     * son contextos a los que se llega por un enlace, y recordarlos dejaría a
     * alguien mirando el listado de hace tres días sin ningún control en
     * pantalla que le diga por qué.
     */
    protected function recordarFiltros(string $modulo, array $claves): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Los workers de cli/ instancian controladores solo para
            // reutilizar su lógica: ahí no hay petición ni sesión de nadie
            // que recordar, y abrir una sería inventarse un usuario.
            if (PHP_SAPI === 'cli') { return; }
            session_start();
        }

        if (isset($_GET['limpiar'])) {
            unset($_SESSION['filtros_modulo'][$modulo]);
            return;
        }

        /*
         * Una visita de paso no cambia lo que este módulo recuerda.
         *
         * Con 'ctx' se llega buscando el electrónico de UN documento, desde el
         * pago semanal o desde la cola de seguimiento: el buscador trae el
         * número de ese documento, no un criterio que la persona haya elegido
         * para este listado. Guardarlo dejaría la pantalla filtrada por una
         * factura suelta la próxima vez que se abra desde el menú.
         */
        if (isset($_GET['ctx'])) {
            return;
        }

        $filtrando = false;
        foreach ($claves as $clave) {
            if (array_key_exists($clave, $_GET)) { $filtrando = true; break; }
        }

        if ($filtrando) {
            $guardar = [];
            foreach ($claves as $clave) {
                $valor = $_GET[$clave] ?? '';
                // Un filtro vacío es "sin filtro": no se guarda, para que la
                // sesión no crezca con ruido y el vaciado sea el olvido.
                if (is_array($valor) || (string) $valor === '') { continue; }
                $guardar[$clave] = (string) $valor;
            }
            if ($guardar) {
                $_SESSION['filtros_modulo'][$modulo] = $guardar;
            } else {
                unset($_SESSION['filtros_modulo'][$modulo]);
            }
            return;
        }

        foreach (($_SESSION['filtros_modulo'][$modulo] ?? []) as $clave => $valor) {
            if (in_array($clave, $claves, true) && !isset($_GET[$clave])) {
                $_GET[$clave] = $valor;
            }
        }
    }

    /**
     * Deja constancia de un fallo que la pantalla decidió aguantar.
     *
     * Hay catch que existen para degradar con elegancia: si un adorno de la
     * pantalla no se puede armar, se sigue sin él. El problema es que ese
     * mismo catch se traga los errores de programación —un método que ya no
     * existe, por ejemplo— y entonces el adorno no aparece nunca y nadie sabe
     * por qué. Esto no cambia el comportamiento: solo escribe qué pasó en
     * storage/logs/app.log, donde ya viven los demás errores.
     */
    protected function registrarFallo(string $donde, Throwable $e): void
    {
        try {
            $archivo = dirname(__DIR__, 2) . '/storage/logs/app.log';
            $carpeta = dirname($archivo);
            if (!is_dir($carpeta)) {
                mkdir($carpeta, 0777, true);
            }
            file_put_contents($archivo, sprintf(
                "[%s] AVISO: %s — %s: %s en %s:%d
",
                date('Y-m-d H:i:s'),
                $donde,
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ), FILE_APPEND);
        } catch (Throwable $otro) {
            // Ni el registro puede tumbar la pantalla que se estaba salvando.
        }
    }

    /**
     * Requerir sesión activa — redirige a /login si no hay sesión
     */
    protected function requireAuth(): void
    {
        // Los workers de cli/ instancian controladores para reutilizar su
        // lógica: ahí no hay petición HTTP que proteger ni sesión que abrir,
        // y el propio script ya se niega a correr fuera de la línea de
        // comandos. Redirigir a /login desde CLI solo mataría el proceso.
        if (PHP_SAPI === 'cli') {
            return;
        }
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
