<?php
/**
 * Que un POST venga de una pantalla de la propia aplicación.
 *
 * Sin esto, cualquier página que consiguiera que el navegador de alguien con
 * sesión abierta mandara un POST podía mover la cola de Seguimiento, borrar un
 * pago o cambiar un usuario: la cookie viaja sola. `SameSite=Lax` frenaba el
 * formulario de terceros, pero era la única barrera.
 *
 * Se aceptan dos pruebas, y basta una:
 *
 *   El token de la sesión, en la cabecera X-CSRF-Token o en el campo
 *   csrf_token. El layout lo publica en <meta name="csrf-token"> y app.js se
 *   lo pone a cada fetch, XMLHttpRequest y formulario que va al mismo origen,
 *   así que ninguna pantalla tiene que acordarse.
 *
 *   Lo que el propio navegador declara: Sec-Fetch-Site: same-origin, o un
 *   Origin igual al Host. Son cabeceras que una página ajena no puede
 *   falsificar. Cubren lo que el token no alcanza —una pestaña abierta antes
 *   de volver a entrar, que trae un token viejo— sin abrir ningún hueco.
 *
 * Y una sola cosa rechaza siempre, traiga lo que traiga: que el navegador
 * diga que la petición sale de otro sitio.
 */
final class Csrf
{
    public const CAMPO = 'csrf_token';

    /** El token de esta sesión; lo crea la primera vez que se pide. */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                return '';
            }
            session_start();
        }
        if (empty($_SESSION[self::CAMPO]) || !is_string($_SESSION[self::CAMPO])) {
            $_SESSION[self::CAMPO] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CAMPO];
    }

    /**
     * null si la petición es de la aplicación; si no, el motivo del rechazo
     * ('otro_sitio', 'token_invalido' o 'sin_prueba').
     */
    public static function motivoRechazo(): ?string
    {
        $sitio = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($sitio !== '' && $sitio !== 'same-origin' && $sitio !== 'none') {
            return 'otro_sitio';
        }

        // Origin "null" no dice de dónde viene (un iframe aislado, una
        // redirección): no prueba nada, pero tampoco acusa.
        $origen = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $origenPropio = false;
        if ($origen !== '' && strtolower($origen) !== 'null') {
            if (!self::esElMismoHost($origen)) {
                return 'otro_sitio';
            }
            $origenPropio = true;
        }

        $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST[self::CAMPO] ?? '');
        $esperado = $_SESSION[self::CAMPO] ?? '';
        $hayToken = is_string($enviado) && $enviado !== '';
        if ($hayToken && is_string($esperado) && $esperado !== '' && hash_equals($esperado, $enviado)) {
            return null;
        }

        if ($sitio === 'same-origin' || $origenPropio) {
            return null;
        }
        return $hayToken ? 'token_invalido' : 'sin_prueba';
    }

    public const MENSAJE = 'La petición no salió de una pantalla de Nexo Fiscal, así que no se hizo nada. '
        . 'Recarga la página e inténtalo de nuevo.';

    /**
     * Corta la petición si no es de la aplicación. Lo llama el enrutador en
     * todo lo que no es GET, antes de instanciar ningún controlador.
     */
    public static function exigir(string $ruta = ''): void
    {
        $motivo = self::motivoRechazo();
        if ($motivo === null) {
            return;
        }

        self::anotar($ruta, $motivo);
        http_response_code(403);
        $modo = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        $deFondo = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || ($modo !== '' && $modo !== 'navigate')
            || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($deFondo) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => self::MENSAJE], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Petición rechazada</title>'
            . '<p style="font-family:sans-serif;max-width:40em;margin:3em auto">'
            . htmlspecialchars(self::MENSAJE) . '</p>';
        exit;
    }

    /** Dónde se anotan los rechazos; null = storage/logs/app.log. Las pruebas lo desvían. */
    public static $archivoRegistro = null;

    /** Un rechazo es justo lo que quien mantiene la aplicación quiere poder ver. */
    private static function anotar(string $ruta, string $motivo): void
    {
        try {
            $archivo = self::$archivoRegistro ?? dirname(__DIR__, 2) . '/storage/logs/app.log';
            file_put_contents($archivo, sprintf(
                "[%s] CSRF: POST %s rechazado (%s) — Origin: %s, Sec-Fetch-Site: %s\n",
                date('Y-m-d H:i:s'),
                $ruta,
                $motivo,
                substr((string) ($_SERVER['HTTP_ORIGIN'] ?? '-'), 0, 200),
                substr((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '-'), 0, 40)
            ), FILE_APPEND);
        } catch (Throwable $e) {
            // El registro nunca decide nada.
        }
    }

    private static function esElMismoHost(string $origen): bool
    {
        $partes = parse_url($origen);
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (!is_array($partes) || empty($partes['host']) || $host === '') {
            return false;
        }
        $delOrigen = strtolower($partes['host']) . (isset($partes['port']) ? ':' . $partes['port'] : '');
        // El Host puede traer el puerto por omisión escrito y el Origin no.
        $sinPuertoPorOmision = preg_replace('/:(80|443)$/', '', $host);
        return hash_equals($host, $delOrigen) || hash_equals($sinPuertoPorOmision, $delOrigen);
    }
}
