<?php
/**
 * El enrutador no deja pasar un POST que no pruebe salir de la aplicación.
 *
 * Seguimiento lo repite en su acción, pero la protección de todas las demás
 * pantallas —pagos, usuarios, correo— vive en Router::dispatch(). Esto
 * comprueba esa puerta: con cada combinación de token y cabeceras, si la
 * acción llega a ejecutarse o no, y que un GET nunca se ve afectado.
 *
 * Csrf::exigir() termina la petición con exit, así que cada caso corre en un
 * PHP aparte. Sin base de datos.
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo CLI.\n");
}

$fallos = 0;
function comprobar(bool $condicion, string $mensaje): void
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$registro = tempnam(sys_get_temp_dir(), 'csrf');

/** Despacha una petición en otro proceso; devuelve lo que imprimió, con el código HTTP al final. */
function despachar(string $metodo, array $servidor, array $post = [], ?string $tokenSesion = 'token-bueno'): string
{
    global $registro;
    $raiz = var_export(dirname(__DIR__), true);
    $datos = var_export(['metodo' => $metodo, 'servidor' => $servidor, 'post' => $post, 'token' => $tokenSesion,
                         'registro' => $registro], true);
    $codigo = <<<PHP
\$d = {$datos};
ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();
\$_SESSION = ['user_id' => 7, 'csrf_token' => \$d['token']];
\$_SERVER = array_merge(\$_SERVER, \$d['servidor'], ['REQUEST_METHOD' => \$d['metodo']]);
\$_POST = \$d['post'];
require {$raiz} . '/app/core/Router.php';
Csrf::\$archivoRegistro = \$d['registro'];
register_shutdown_function(function () { echo '|HTTP:' . (int) http_response_code(); });
\$r = new Router();
\$r->post('/pagos/borrar/{id}', function (\$id) { echo "EJECUTADA {\$id}"; });
\$r->get('/pagos', function () { echo 'LISTADO'; });
\$r->dispatch(\$d['metodo'], \$d['metodo'] === 'GET' ? '/pagos' : '/pagos/borrar/5');
PHP;
    $proceso = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', '-r', $codigo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
    $salida = stream_get_contents($tubos[1]) . stream_get_contents($tubos[2]);
    proc_close($proceso);
    return $salida;
}

$casos = [
    // [descripción, método, cabeceras, POST, token de sesión, ¿se ejecuta?]
    ['POST con el token en la cabecera', 'POST', ['HTTP_X_CSRF_TOKEN' => 'token-bueno'], [], 'token-bueno', true],
    ['POST con el token en el campo del formulario', 'POST', [], ['csrf_token' => 'token-bueno'], 'token-bueno', true],
    ['POST del navegador desde la misma pantalla (Sec-Fetch-Site)', 'POST', ['HTTP_SEC_FETCH_SITE' => 'same-origin'], [], 'token-bueno', true],
    ['POST con Origin igual al Host', 'POST', ['HTTP_ORIGIN' => 'http://nexo.local', 'HTTP_HOST' => 'nexo.local'], [], 'token-bueno', true],
    ['POST sin nada que lo pruebe', 'POST', [], [], 'token-bueno', false],
    ['POST con un token equivocado', 'POST', ['HTTP_X_CSRF_TOKEN' => 'token-malo'], [], 'token-bueno', false],
    ['POST con token vacío y sesión sin token', 'POST', ['HTTP_X_CSRF_TOKEN' => ''], ['csrf_token' => ''], null, false],
    ['POST desde otro sitio, aunque traiga el token', 'POST',
        ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://malo.example', 'HTTP_X_CSRF_TOKEN' => 'token-bueno'], [], 'token-bueno', false],
    ['POST con Origin de otro host', 'POST', ['HTTP_ORIGIN' => 'http://malo.example', 'HTTP_HOST' => 'nexo.local'], [], 'token-bueno', false],
    ['POST con el token en un arreglo', 'POST', [], ['csrf_token' => ['token-bueno']], 'token-bueno', false],
    ['GET sin token ni cabeceras', 'GET', [], [], 'token-bueno', true],
];

foreach ($casos as [$como, $metodo, $cabeceras, $post, $token, $pasa]) {
    $salida = despachar($metodo, $cabeceras, $post, $token);
    $ejecutada = strpos($salida, $metodo === 'GET' ? 'LISTADO' : 'EJECUTADA 5') !== false;
    comprobar($ejecutada === $pasa, "{$como}: " . ($pasa ? 'debía ejecutarse' : 'no debía ejecutarse') . " — salida: {$salida}");
    if (!$pasa) {
        comprobar(strpos($salida, '|HTTP:403') !== false, "{$como}: responde 403");
        comprobar(strpos($salida, 'no salió de una pantalla de Nexo Fiscal') !== false, "{$como}: y lo dice");
    }
    comprobar(!preg_match('/(deprecated|warning|fatal error|notice)/i', $salida), "{$como}: sin avisos de PHP — {$salida}");
}

$desdeLlamada = despachar('POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
comprobar(strpos($desdeLlamada, '"error":"csrf"') !== false, 'a una llamada de fondo le responde JSON: ' . $desdeLlamada);

$anotado = (string) file_get_contents($registro);
comprobar(substr_count($anotado, 'CSRF: POST /pagos/borrar/{id} rechazado') === 7, "cada rechazo queda anotado:\n{$anotado}");
comprobar(strpos($anotado, 'Origin: https://malo.example') !== false, 'con el origen que lo delató');
unlink($registro);

if ($fallos > 0) {
    exit(1);
}
echo "OK: el enrutador exige que los POST salgan de la aplicación\n";
