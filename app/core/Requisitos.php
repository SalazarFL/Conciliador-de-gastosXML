<?php
/**
 * Si hay adónde entrar. La otra mitad de la puerta.
 *
 * `Permisos` contesta quién puede entrar a un módulo. Esta clase contesta algo
 * anterior: si ese módulo tiene con qué funcionar. Son preguntas distintas y
 * por eso son dos guardias: al administrador no se le abre un módulo por ser
 * administrador —tampoco a él le sirve sin el catálogo cargado—, y a nadie se
 * le cierra por su rol.
 *
 * Lo que hace falta, y qué módulo necesita qué, está en config/requisitos.php.
 * Aquí solo está el mecanismo: resolverlo una vez por petición, cerrar la ruta
 * en el enrutador y decirle al menú cuál de sus entradas dibujar apagada.
 *
 * ── Se ve, pero no se entra ───────────────────────────────────────────────
 * El módulo al que le falta un paso NO desaparece del menú. Desaparecer es lo
 * que hace un permiso que no se tiene, y significa "esto no es para ti". Aquí
 * el mensaje es el contrario: esto SÍ es para ti, y falta un paso para poder
 * usarlo. Un módulo escondido solo hace que alguien pregunte por qué se
 * perdió Correo; uno apagado que dice "falta dar de alta un buzón" se arregla
 * sin preguntarle a nadie.
 *
 * ── Una vez por petición, y sin guardarlo en la sesión ────────────────────
 * Mismo motivo que en Permisos: quien acaba de importar el catálogo tiene que
 * encontrarse los módulos abiertos en la pantalla siguiente, no cuando se le
 * ocurra volver a entrar.
 *
 * ── Si la base no contesta ────────────────────────────────────────────────
 * Se da el requisito por cumplido y queda anotado en storage/logs/app.log. No
 * se puede distinguir "no hay ninguna empresa" de "no se pudo preguntar", y de
 * las dos lecturas posibles, la que cierra el sistema entero por un servidor
 * caído es la peor: deja a la oficina sin trabajar y encima diciéndole que le
 * falta configurar algo que ya tenía hecho.
 */

require_once __DIR__ . '/Permisos.php';

class Requisitos
{
    /** El catálogo, leído del disco una sola vez. */
    private static $catalogo = null;

    /** id => true|false, resuelto una vez por petición. */
    private static $estado = null;

    // ── El catálogo ───────────────────────────────────────────────────────

    /** Lo que declara config/requisitos.php, tal cual. */
    public static function catalogo(): array
    {
        if (self::$catalogo === null) {
            $archivo = __DIR__ . '/../config/requisitos.php';
            $leido = is_file($archivo) ? require $archivo : [];
            self::$catalogo = is_array($leido) ? $leido : [];
        }
        return self::$catalogo;
    }

    /** Los pasos declarados, en el orden en que conviene hacerlos. */
    public static function declarados(): array
    {
        $lista = self::catalogo()['requisitos'] ?? [];
        return is_array($lista) ? $lista : [];
    }

    /** Lo que declara un requisito, con su id puesto, o null si no existe. */
    public static function declarado(string $id): ?array
    {
        $uno = self::declarados()[$id] ?? null;
        return is_array($uno) ? $uno + ['id' => $id] : null;
    }

    /** Qué necesita un módulo. Lista vacía = no se cierra nunca. */
    public static function exigeElModulo(string $modulo): array
    {
        $porModulo = self::catalogo()['modulos'] ?? [];
        return array_values((array) ($porModulo[$modulo] ?? []));
    }

    // ── Cómo está la instalación ──────────────────────────────────────────

    /**
     * id => true si el paso está hecho. Una consulta corta por requisito, y
     * solo la primera vez que se pregunta en esta petición.
     */
    public static function estado(): array
    {
        if (self::$estado !== null) {
            return self::$estado;
        }

        self::$estado = [];
        foreach (self::declarados() as $id => $requisito) {
            $comprobar = $requisito['comprobar'] ?? null;
            if (!is_callable($comprobar)) {
                // Un requisito que no sabe comprobarse no puede cerrar nada.
                self::$estado[$id] = true;
                continue;
            }
            try {
                self::$estado[$id] = (bool) $comprobar();
            } catch (Throwable $e) {
                self::$estado[$id] = true; // no se pudo preguntar: se deja pasar
                self::anotar('No se pudo comprobar el requisito ' . $id, $e);
            }
        }

        return self::$estado;
    }

    /** Si un paso concreto está hecho. */
    public static function cumplido(string $id): bool
    {
        return (bool) (self::estado()[$id] ?? true);
    }

    /** Los pasos que faltan, ya declarados: ['empresa' => ['titulo' => …]]. */
    public static function faltantes(): array
    {
        $faltan = [];
        foreach (self::estado() as $id => $cumplido) {
            if (!$cumplido && ($uno = self::declarado($id))) {
                $faltan[$id] = $uno;
            }
        }
        return $faltan;
    }

    /** Si la instalación tiene todos los pasos hechos. */
    public static function completa(): bool
    {
        return self::faltantes() === [];
    }

    // ── Lo que necesita un módulo ─────────────────────────────────────────

    /** Lo que le falta a un módulo para abrirse. Vacío = abierto. */
    public static function faltanDelModulo(string $modulo): array
    {
        $faltan = [];
        foreach (self::exigeElModulo($modulo) as $id) {
            if (!self::cumplido($id) && ($uno = self::declarado($id))) {
                $faltan[$id] = $uno;
            }
        }
        return $faltan;
    }

    /** Si el módulo está cerrado por falta de configuración. */
    public static function moduloCerrado(string $modulo): bool
    {
        return self::faltanDelModulo($modulo) !== [];
    }

    /**
     * Si este permiso es el que ARREGLA alguno de los pasos.
     *
     * Estas rutas no se cierran nunca, y no es una excepción cómoda: es el
     * candado por fuera. Dar de alta un buzón se hace con 'correo.configurar',
     * que vive en el módulo 'correo' —el mismo que se cierra por no tener
     * buzones—, así que sin esto el paso "da de alta el primer buzón" sería
     * imposible de hacer: el sistema pediría para abrirse algo que solo se
     * puede hacer con el sistema abierto.
     */
    public static function esArreglo(string $permiso): bool
    {
        if ($permiso === '') {
            return false;
        }
        foreach (self::declarados() as $paso) {
            if ((string) ($paso['permiso'] ?? '') === $permiso) {
                return true;
            }
        }
        return false;
    }

    /**
     * El módulo al que pertenece una ruta, deducido del permiso que exige.
     *
     * El catálogo de permisos ya agrupa por módulo ('correo.ver' vive en
     * 'correo'), así que no hace falta una tercera lista de rutas que se pueda
     * desincronizar: una pantalla nueva queda cubierta con solo declarar su
     * permiso, como siempre.
     *
     * Una lista de permisos —la usan las pantallas que hospedan secciones de
     * varios dueños, como /configuracion— se resuelve por el primero, que es
     * el del dueño de la pantalla.
     */
    public static function moduloDeRuta(string $metodo, string $uri): string
    {
        // Las que se declararon abiertas: viven en un módulo que se cierra
        // pero a quien sirven es a Configuración. Ver config/requisitos.php.
        $abiertas = (array) (self::catalogo()['abiertas'] ?? []);
        if (in_array(strtoupper($metodo) . ' ' . $uri, $abiertas, true)) {
            return '';
        }

        $regla   = Permisos::reglaDeRuta($metodo, $uri);
        $permiso = $regla['permiso'] ?? null;

        if ($permiso === null || $permiso === 'publica' || $permiso === 'libre') {
            return '';
        }
        if (is_array($permiso)) {
            $permiso = reset($permiso);
        }
        // La ruta con la que se arregla un paso no pertenece, a estos
        // efectos, a ningún módulo: es la salida. Ver esArreglo().
        if (self::esArreglo((string) $permiso)) {
            return '';
        }
        return Permisos::moduloDe((string) $permiso);
    }

    // ── La guardia del enrutador ──────────────────────────────────────────

    /**
     * Se ejecuta justo después de la de permisos: primero quién eres, después
     * si hay adónde entrar. Si falta un paso, corta aquí.
     *
     * @param string $metodo GET, POST…
     * @param string $uri    La ruta REGISTRADA, con sus {id}.
     */
    public static function guardia(string $metodo, string $uri): void
    {
        // Los procesos de cli/ no pasan por aquí: son tareas del sistema, y
        // las que dependen del catálogo ya se niegan solas a trabajar sin él
        // (ver XmlDocumentImporter y Controller::exigirListadoProveedores).
        if (PHP_SAPI === 'cli') {
            return;
        }

        $modulo = self::moduloDeRuta($metodo, $uri);
        if ($modulo === '') {
            return;
        }

        $faltan = self::faltanDelModulo($modulo);
        if ($faltan === []) {
            return;
        }

        self::rechazar($modulo, $faltan);
    }

    /**
     * Cortar la petición. Una pantalla recibe una página que dice qué paso
     * falta y lleva a hacerlo; una llamada de fondo recibe JSON, porque un
     * HTML de error dentro de un fetch() solo produce un "error inesperado"
     * sin sentido.
     *
     * El código es 409 y no 403: no es que no se pueda, es que todavía no.
     */
    private static function rechazar(string $modulo, array $faltan): void
    {
        $base = rtrim(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', '/');

        if (self::esLlamadaDeFondo()) {
            $primero = reset($faltan);
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'error'   => 'sin_configurar',
                'falta'   => array_keys($faltan),
                'message' => trim(
                    ($primero['falta'] ?? 'Falta un paso de configuración.')
                    . ' ' . ($primero['porque'] ?? '')
                    . ' Está en ' . ($primero['donde'] ?? 'Configuración') . '.'
                ),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(409);
        $vista = __DIR__ . '/../views/errors/requisito.php';
        if (is_file($vista)) {
            $moduloCerrado       = $modulo;
            $requisitosQueFaltan = $faltan;
            $baseUrl             = $base;
            include $vista;
        } else {
            echo 'Falta configurar el sistema antes de usar esta pantalla.';
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

    /** Olvida lo resuelto. Lo llaman los arreglos y las pruebas. */
    public static function olvidar(): void
    {
        self::$estado = null;
    }

    /**
     * Dar por hecho un estado sin preguntarle a la base. SOLO para pruebas:
     * comprobar que un módulo se cierra no puede depender de que la máquina
     * donde corre tenga —o no tenga— una empresa registrada.
     */
    public static function suponer(?array $estado): void
    {
        self::$estado = $estado === null ? null : array_map('boolval', $estado);
    }

    private static function anotar(string $donde, Throwable $e): void
    {
        try {
            $archivo = dirname(__DIR__, 2) . '/storage/logs/app.log';
            $carpeta = dirname($archivo);
            if (!is_dir($carpeta)) {
                @mkdir($carpeta, 0777, true);
            }
            @file_put_contents(
                $archivo,
                sprintf(
                    "[%s] REQUISITOS: %s — %s: %s\n",
                    date('Y-m-d H:i:s'), $donde, get_class($e), $e->getMessage()
                ),
                FILE_APPEND
            );
        } catch (Throwable $otro) {
            // Ni el registro puede tumbar la comprobación.
        }
    }
}
