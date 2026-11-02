<?php
/**
 * Quién puede hacer qué. La respuesta se da UNA vez por petición y todo el
 * sistema pregunta aquí: el enrutador antes de ejecutar, el menú antes de
 * dibujar un enlace, y cada pantalla antes de ofrecer un botón.
 *
 * Por qué está en el core y no en un modelo: la comprobación ocurre antes de
 * que exista un controlador —en Router::dispatch—, así que no puede depender
 * de nada que se cargue más tarde. El catálogo de permisos vive en
 * config/permisos.php; la asignación (qué permisos tiene cada rol, qué rol
 * tiene cada persona, a qué empresas llega) vive en la base.
 *
 * ── Esconder no es proteger ───────────────────────────────────────────────
 * El menú oculta lo que alguien no puede usar, pero eso es cortesía, no
 * seguridad: quien escriba la dirección a mano llegaría igual. Por eso la
 * comprobación de verdad está en el enrutador, sobre la ruta registrada, y
 * ocurre aunque la pantalla que la llamó no exista ya.
 *
 * ── Si la base no contesta ────────────────────────────────────────────────
 * Una instalación recién actualizada todavía no tiene las tablas, y un
 * servidor caído no tiene ninguna. En ese caso NO se cierra el sistema: se
 * vuelve al reparto de antes —administrador todo, el resto todo menos lo de
 * administración—, que es exactamente como se comportaba requireAdmin(). Un
 * fallo de infraestructura no debe dejar a la oficina sin poder trabajar, y
 * tampoco debe abrirle a nadie lo que antes no tenía. Queda anotado en
 * storage/logs/app.log.
 */

class Permisos
{
    /** Permisos del usuario de esta petición: ['clave' => true]. */
    private static $permisos = null;

    /** Rol resuelto: ['id','nombre','es_admin'] o null. */
    private static $rol = null;

    /** Ids de empresa a las que llega. Vacío = todas. */
    private static $sociedades = null;

    /** true cuando no se pudo consultar la base y manda el reparto viejo. */
    private static $heredado = false;

    /** El catálogo, leído del disco una sola vez. */
    private static $catalogo = null;

    // ── El catálogo ───────────────────────────────────────────────────────

    /** Módulos y rutas tal como los declara config/permisos.php. */
    public static function catalogo(): array
    {
        if (self::$catalogo === null) {
            $archivo = __DIR__ . '/../config/permisos.php';
            $leido = is_file($archivo) ? require $archivo : [];
            self::$catalogo = is_array($leido) ? $leido : [];
        }
        return self::$catalogo;
    }

    /** Los módulos con sus permisos, para dibujar la pantalla de roles. */
    public static function modulos(): array
    {
        return self::catalogo()['modulos'] ?? [];
    }

    /** Todas las claves de permiso que existen, en una sola lista plana. */
    public static function todas(): array
    {
        $claves = [];
        foreach (self::modulos() as $modulo) {
            foreach (array_keys($modulo['permisos'] ?? []) as $clave) {
                $claves[] = $clave;
            }
        }
        return $claves;
    }

    /** El texto en castellano de un permiso ('Ver la cola de trabajo'). */
    public static function etiqueta(string $clave): string
    {
        foreach (self::modulos() as $modulo) {
            if (isset($modulo['permisos'][$clave])) {
                return (string) $modulo['permisos'][$clave];
            }
        }
        return $clave;
    }

    /**
     * A qué módulo pertenece un permiso ('seguimiento.editar' →
     * 'seguimiento'). Es lo que la bitácora usa para agrupar.
     */
    public static function moduloDe(string $clave): string
    {
        foreach (self::modulos() as $id => $modulo) {
            if (isset($modulo['permisos'][$clave])) {
                return (string) $id;
            }
        }
        return 'general';
    }

    /** Lo declarado para una ruta, o null si nadie la declaró. */
    public static function reglaDeRuta(string $metodo, string $uri): ?array
    {
        $rutas = self::catalogo()['rutas'] ?? [];
        $clave = strtoupper($metodo) . ' ' . $uri;
        return isset($rutas[$clave]) && is_array($rutas[$clave]) ? $rutas[$clave] : null;
    }

    /**
     * Las llaves que abre una ruta, siempre como lista: lo que el menú
     * necesita para decidir si la ofrece. Vacía si es 'publica', 'libre' o
     * nadie la declaró.
     */
    public static function llavesDeRuta(string $metodo, string $uri): array
    {
        $permiso = self::reglaDeRuta($metodo, $uri)['permiso'] ?? null;
        if ($permiso === null || $permiso === 'publica' || $permiso === 'libre') {
            return [];
        }
        return array_values((array) $permiso);
    }

    /**
     * Si el usuario pasaría la guardia de una ruta, sin cortar nada. Es la
     * pregunta que hace un enlace antes de dibujarse: contestarla con la
     * misma regla que el enrutador es lo que evita una lista copiada a mano
     * que se desactualiza sola.
     */
    public static function puedeRuta(string $metodo, string $uri): bool
    {
        $llaves = self::llavesDeRuta($metodo, $uri);
        return $llaves === [] || self::puedeAlguno($llaves);
    }

    /**
     * Qué le da de verdad a alguien una lista de permisos, módulo por módulo.
     * No mira la base ni la sesión: lo usan la lista de roles, el formulario
     * y las pruebas, sobre la lista que tengan delante.
     *
     * Devuelve:
     *   'modulos'      [id => ['marcados' => [claves], 'total' => n]]
     *   'total'        cuántos permisos del catálogo concede
     *   'desconocidos' claves guardadas que el catálogo ya no tiene: no abren
     *                  nada y se caen solas la próxima vez que se guarde el rol
     *   'sin_base'     [id => [claves]] lo marcado en un módulo cuya base no
     *                  está: permisos que existen en el papel y en ninguna
     *                  pantalla
     *   'delicados'    los de Administración, que reparten los accesos de los
     *                  demás
     *
     * @param bool $esAdmin El rol que lo puede todo: se le cuentan todos.
     */
    public static function analizarRol(array $claves, bool $esAdmin = false): array
    {
        $modulos = self::modulos();
        $todas   = self::todas();
        $claves  = $esAdmin ? $todas : array_values(array_unique(array_map('strval', $claves)));
        $tiene   = array_flip($claves);

        $porModulo = [];
        $sinBase   = [];
        foreach ($modulos as $id => $modulo) {
            $delModulo = array_keys($modulo['permisos'] ?? []);
            $marcados  = array_values(array_filter($delModulo, fn($c) => isset($tiene[$c])));
            $porModulo[$id] = ['marcados' => $marcados, 'total' => count($delModulo)];

            $base = $modulo['base'] ?? null;
            if ($base !== null && !isset($tiene[$base])) {
                $dependen = array_diff($marcados, (array) ($modulo['sueltos'] ?? []));
                if ($dependen) {
                    $sinBase[$id] = array_values($dependen);
                }
            }
        }

        return [
            'modulos'      => $porModulo,
            'total'        => count(array_intersect($claves, $todas)),
            'desconocidos' => array_values(array_diff($claves, $todas)),
            'sin_base'     => $sinBase,
            'delicados'    => $esAdmin ? [] : ($porModulo['administracion']['marcados'] ?? []),
        ];
    }

    /**
     * Completa una lista de permisos con la base de cada módulo que la
     * necesita. Es lo que hace la pantalla de roles al marcar una casilla; se
     * repite al guardar porque la pantalla puede no tener JS.
     */
    public static function conBases(array $claves): array
    {
        $claves = array_values(array_unique(array_map('strval', $claves)));
        foreach (self::analizarRol($claves)['sin_base'] as $id => $_) {
            $claves[] = (string) self::modulos()[$id]['base'];
        }
        return $claves;
    }

    // ── Lo que puede el usuario de esta petición ──────────────────────────

    /**
     * Resuelve el rol, sus permisos y sus empresas. Dos consultas por
     * petición, no una por pregunta: el menú pregunta doce veces y el
     * resultado no puede cambiar a mitad de una petición.
     *
     * No se guarda en la sesión a propósito. Quitarle un permiso a un rol
     * tiene que surtir efecto en la siguiente pantalla de quien lo tenía, no
     * cuando se le ocurra volver a entrar: una sesión de tres días guardando
     * permisos revocados es justo lo que este control viene a evitar.
     */
    private static function resolver(): void
    {
        if (self::$permisos !== null) {
            return;
        }

        self::$permisos   = [];
        self::$sociedades = [];
        self::$rol        = null;
        self::$heredado   = false;

        $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
        if ($usuarioId <= 0) {
            return; // sin sesión no hay nada que resolver
        }

        try {
            if (!class_exists('Rol')) {
                require_once __DIR__ . '/../models/Rol.php';
            }
            $rolModel = new Rol();

            $filas = $rolModel->permisosDeUsuario($usuarioId);
            self::$rol = $rolModel->rolDeUsuario($usuarioId);
            foreach ($filas as $clave) {
                self::$permisos[$clave] = true;
            }

            self::$sociedades = $rolModel->sociedadesDeUsuario($usuarioId);
        } catch (Throwable $e) {
            self::$heredado = true;
            self::anotar('No se pudo resolver el rol del usuario ' . $usuarioId, $e);
        }
    }

    /** Si el usuario tiene un permiso concreto. */
    public static function puede(string $clave): bool
    {
        // Los procesos de cli/ no son nadie: corren tareas del sistema y ya se
        // niegan solos a ejecutarse desde la web. Pedirles un rol los dejaría
        // sin poder trabajar.
        if (PHP_SAPI === 'cli') {
            return true;
        }

        self::resolver();

        if (self::$heredado) {
            // Reparto de antes de los roles: admin todo, el resto todo menos
            // lo de administración.
            if (!empty($_SESSION['user_is_admin'])) {
                return true;
            }
            return !in_array($clave, self::deAdministracion(), true);
        }

        if (self::esAdmin()) {
            return true;
        }

        return isset(self::$permisos[$clave]);
    }

    /** Si tiene AL MENOS uno de varios permisos (para agrupar un menú). */
    public static function puedeAlguno(array $claves): bool
    {
        foreach ($claves as $clave) {
            if (self::puede($clave)) {
                return true;
            }
        }
        return false;
    }

    /**
     * El administrador del sistema: su rol lleva la marca `es_admin`, que
     * significa "todos los permisos, también los que se inventen mañana".
     *
     * La columna vieja `usuarios.is_admin` sigue contando: es lo que sostiene
     * al primer administrador mientras se asignan los roles, y lo que evita
     * quedarse fuera del sistema si alguien borra el rol equivocado.
     */
    public static function esAdmin(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }
        if (!empty($_SESSION['user_is_admin'])) {
            return true;
        }
        self::resolver();
        return !empty(self::$rol['es_admin']);
    }

    /** El rol del usuario: ['id','nombre','es_admin'] o null si no tiene. */
    public static function rol(): ?array
    {
        self::resolver();
        return self::$rol;
    }

    /** Nombre del rol para mostrar ('Administrador', 'Operación', '—'). */
    public static function nombreRol(): string
    {
        $rol = self::rol();
        return $rol ? (string) $rol['nombre'] : 'Sin rol';
    }

    /** Todas las claves que tiene el usuario, para pintarlas en su ficha. */
    public static function claves(): array
    {
        self::resolver();
        return array_keys(self::$permisos);
    }

    // ── Alcance por empresa ───────────────────────────────────────────────

    /**
     * Las empresas a las que llega este usuario. Vacío = todas.
     *
     * "Sin filas registradas" significa todas, no ninguna: si significara
     * ninguna, el día que esto se instala todo el mundo se quedaría sin ver
     * nada hasta que un administrador fuera usuario por usuario. El
     * administrador restringe a quien quiera restringir; el resto sigue como
     * estaba.
     */
    public static function sociedadesPermitidas(): array
    {
        if (PHP_SAPI === 'cli') {
            return [];
        }
        self::resolver();
        if (self::esAdmin()) {
            return []; // el administrador trabaja con todas
        }
        return self::$sociedades ?: [];
    }

    /** Si puede trabajar con una empresa concreta. */
    public static function puedeSociedad($sociedadId): bool
    {
        $permitidas = self::sociedadesPermitidas();
        if (!$permitidas) {
            return true;
        }
        return in_array((int) $sociedadId, $permitidas, true);
    }

    // ── La guardia del enrutador ──────────────────────────────────────────

    /**
     * Se ejecuta antes que cualquier controlador. Si la ruta no está
     * permitida, corta aquí: ni se instancia el controlador ni se toca la
     * base.
     *
     * @param string $metodo GET, POST…
     * @param string $uri    La ruta REGISTRADA, con sus {id} — no la pedida.
     */
    public static function guardia(string $metodo, string $uri): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $regla   = self::reglaDeRuta($metodo, $uri);
        $permiso = $regla['permiso'] ?? null;

        if ($permiso === 'publica') {
            return;
        }

        if ($permiso === null) {
            // Ruta sin declarar: no se deja sin protección —sigue pidiendo
            // sesión— pero tampoco se cierra, porque negar lo que nadie
            // declaró rompería cualquier pantalla nueva sin decir por qué.
            // Queda anotado para que el olvido se note.
            self::anotarTexto('Ruta sin permiso declarado en config/permisos.php: ' . $metodo . ' ' . $uri);
        }

        if (empty($_SESSION['user_id'])) {
            self::rechazar($uri, 'sesion');
        }

        if ($permiso === null || $permiso === 'libre') {
            return;
        }

        // Una lista de permisos significa "con cualquiera basta": lo usan las
        // pantallas que hospedan secciones de varios dueños, como
        // /configuracion. Ver config/permisos.php.
        if (is_array($permiso)) {
            if (!self::puedeAlguno($permiso)) {
                self::rechazar($uri, (string) reset($permiso));
            }
            return;
        }

        if (!self::puede($permiso)) {
            self::rechazar($uri, $permiso);
        }
    }

    /**
     * Cortar la petición. Una pantalla recibe una página que explica qué
     * pasó; una llamada de fondo recibe JSON, porque un HTML de error dentro
     * de un fetch() solo produce un "error inesperado" sin sentido.
     */
    private static function rechazar(string $uri, string $permiso): void
    {
        $sinSesion = ($permiso === 'sesion');

        if (!$sinSesion) {
            // Un intento de entrar a donde no se puede es justo lo que un
            // administrador quiere ver en la bitácora.
            try {
                if (!class_exists('Bitacora')) {
                    require_once __DIR__ . '/../models/Bitacora.php';
                }
                Bitacora::denegado($uri, $permiso);
            } catch (Throwable $e) {
                // El registro nunca decide si se entra o no.
            }
        }

        if (self::esLlamadaDeFondo()) {
            http_response_code($sinSesion ? 401 : 403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'error'   => $sinSesion ? 'sin_sesion' : 'sin_permiso',
                'message' => $sinSesion
                    ? 'Tu sesión terminó. Vuelve a entrar.'
                    : 'No tienes permiso para esta acción.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $base = rtrim(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', '/');

        if ($sinSesion) {
            http_response_code(302);
            header('Location: ' . $base . '/login');
            exit;
        }

        http_response_code(403);
        $vista = __DIR__ . '/../views/errors/403.php';
        if (is_file($vista)) {
            $permisoFaltante = $permiso;
            $baseUrl = $base;
            include $vista;
        } else {
            echo '403 - No tienes permiso para esta pantalla.';
        }
        exit;
    }

    /** Un fetch() de la propia aplicación, no una pantalla del navegador. */
    private static function esLlamadaDeFondo(): bool
    {
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
            return true;
        }
        // fetch() manda Sec-Fetch-Mode: cors|same-origin; la barra de
        // direcciones manda navigate.
        $modo = strtolower($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        if ($modo !== '' && $modo !== 'navigate') {
            return true;
        }
        return strpos(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /** Olvida lo resuelto. Lo llaman los cambios de rol y las pruebas. */
    public static function olvidar(): void
    {
        self::$permisos   = null;
        self::$rol        = null;
        self::$sociedades = null;
        self::$heredado   = false;
    }

    /** Los permisos que en el reparto viejo eran solo del administrador. */
    private static function deAdministracion(): array
    {
        $modulos = self::modulos();
        $claves  = array_keys($modulos['administracion']['permisos'] ?? []);
        $claves[] = 'diagnostico.ver';
        return $claves;
    }

    private static function anotar(string $donde, Throwable $e): void
    {
        self::anotarTexto($donde . ' — ' . get_class($e) . ': ' . $e->getMessage());
    }

    private static function anotarTexto(string $mensaje): void
    {
        try {
            $archivo = dirname(__DIR__, 2) . '/storage/logs/app.log';
            $carpeta = dirname($archivo);
            if (!is_dir($carpeta)) {
                @mkdir($carpeta, 0777, true);
            }
            @file_put_contents(
                $archivo,
                sprintf("[%s] PERMISOS: %s\n", date('Y-m-d H:i:s'), $mensaje),
                FILE_APPEND
            );
        } catch (Throwable $otro) {
            // Ni el registro puede tumbar la comprobación.
        }
    }
}
