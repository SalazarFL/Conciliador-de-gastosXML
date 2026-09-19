<?php
/**
 * ClienteGraph en los días malos de Microsoft, sin red y sin credenciales.
 *
 * Lo que se comprueba es la conducta que protege a la instalación:
 *
 *   - El token se pide una vez y se reusa, también entre procesos.
 *   - Un 429 se respeta al pie de la letra; un 503 se reintenta con espera
 *     creciente; un POST no se repite a ciegas.
 *   - Un 409 "ya existe" es AlmacenConflicto, no un error, y no se reintenta.
 *   - Ninguna operación se come más tiempo que su presupuesto.
 *   - Tras varios fallos seguidos se deja de insistir un rato.
 *   - Los lotes se parten de a 20 y reintentan lo que Microsoft frenó.
 *   - En el registro no queda ni un token ni un permiso de subida.
 *
 * El reloj es de mentira: dormir() lo adelanta. Nadie espera 60 segundos.
 *
 * Uso: php tests/ClienteGraphTest.php
 */
require_once __DIR__ . '/../app/helpers/ClienteGraph.php';
require_once __DIR__ . '/soporte/TransporteGuionado.php';

$fallas = [];

function comprobarGraph($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

function lanzaGraph(callable $f)
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

$carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliente_graph_' . uniqid();
mkdir($carpeta, 0777, true);

$config = ['tenant_id' => 'tenant', 'client_id' => 'app', 'client_secret' => 'secreto-que-no-se-anota',
           'drive_id' => 'b!unidad'];

/** Un cliente con reloj de mentira. $esperas anota cada dormir(). */
function cliente(TransporteGraph $t, &$esperas, array $opciones = [])
{
    global $config, $carpeta;
    $esperas = [];
    $reloj = $opciones['reloj_inicial'] ?? 1000000.0;
    $ahora = &$reloj;
    return new ClienteGraph($config, $t, $opciones + [
        'presupuesto' => 300,
        'carpeta_estado' => $carpeta . DIRECTORY_SEPARATOR . 'estado_' . uniqid(),
        'registro' => null,
        'reloj' => function () use (&$ahora) { return $ahora; },
        'dormir' => function ($s) use (&$ahora, &$esperas) { $esperas[] = $s; $ahora += $s; },
    ]);
}

$ok = ['codigo' => 200, 'cuerpo' => ['id' => 'x']];

try {
    // ── Token: una vez, y reusado ───────────────────────────────────────
    $t = (new TransporteGuionado())->conToken('tok-A')
        ->luego('GET', '~/drives/~', $ok)->luego('GET', '~/drives/~', $ok);
    $c = cliente($t, $esperas);
    $c->pedir('GET', '/drives/b!unidad');
    $c->pedir('GET', '/drives/b!unidad');
    comprobarGraph($t->pendientes() === 0, 'dos pedidos, un solo token');
    comprobarGraph(($t->recibidas[1]['cabeceras']['Authorization'] ?? '') === 'Bearer tok-A',
        'el token viaja en Authorization');

    // Otro proceso (otro cliente, misma carpeta) lo lee del archivo.
    $estado = $carpeta . DIRECTORY_SEPARATOR . 'compartido';
    $t = (new TransporteGuionado())->conToken('tok-B')->luego('GET', '~/drives/~', $ok);
    cliente($t, $esperas, ['carpeta_estado' => $estado])->pedir('GET', '/drives/x');
    $t2 = (new TransporteGuionado())->luego('GET', '~/drives/~', $ok);
    cliente($t2, $esperas, ['carpeta_estado' => $estado])->pedir('GET', '/drives/x');
    comprobarGraph(($t2->recibidas[0]['cabeceras']['Authorization'] ?? '') === 'Bearer tok-B',
        'un proceso nuevo reusa el token guardado, sin pedir otro');
    $guardado = glob($estado . DIRECTORY_SEPARATOR . 'graph_token_*.json');
    comprobarGraph(count($guardado) === 1 && strpos((string) file_get_contents($guardado[0]), 'secreto') === false,
        'se guarda el token, nunca el secreto');

    // Uno a punto de vencer se renueva antes de que venza.
    $t = (new TransporteGuionado())->conToken('corto', 200)->luego('GET', '~/drives/~', $ok)
        ->conToken('nuevo')->luego('GET', '~/drives/~', $ok);
    $c = cliente($t, $esperas);
    $c->pedir('GET', '/drives/x');
    $c->pedir('GET', '/drives/x');
    comprobarGraph($t->pendientes() === 0, 'un token con menos de 5 minutos se renueva antes de usarlo');

    // ── 401: se tira el token y se pide otro, UNA vez ───────────────────
    $t = (new TransporteGuionado())->conToken('viejo')
        ->luego('GET', '~/drives/~', ['codigo' => 401])
        ->conToken('fresco')->luego('GET', '~/drives/~', $ok);
    $r = cliente($t, $esperas)->pedir('GET', '/drives/x');
    comprobarGraph($r['codigo'] === 200 && ($t->recibidas[3]['cabeceras']['Authorization'] ?? '') === 'Bearer fresco',
        'un 401 renueva el token y reintenta');

    $t = (new TransporteGuionado())->conToken()->luego('GET', '~/drives/~', ['codigo' => 401])
        ->conToken()->luego('GET', '~/drives/~', ['codigo' => 401]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas)->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && $e->codigoHttp() === 401 && $t->pendientes() === 0,
        'si el token nuevo también da 401, el problema es la aplicación: se para');

    // ── 429: lo que diga Retry-After, exacto ────────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->luego('GET', '~/drives/~', ['codigo' => 429, 'cabeceras' => ['Retry-After' => '7']])
        ->luego('GET', '~/drives/~', $ok);
    cliente($t, $esperas)->pedir('GET', '/drives/x');
    comprobarGraph($esperas === [7.0], 'un 429 espera exactamente lo que pide Retry-After');

    // Un POST frenado por 429 sí se repite: 429 garantiza que no se hizo.
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~/copy~', ['codigo' => 429, 'cabeceras' => ['Retry-After' => '2']])
        ->luego('POST', '~/copy~', ['codigo' => 202]);
    $r = cliente($t, $esperas)->pedir('POST', '/drives/x/items/1/copy', ['json' => []]);
    comprobarGraph($r['codigo'] === 202, 'un POST frenado por 429 se reintenta');

    // ── 503: espera creciente ───────────────────────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->repetir(2, 'GET', '~/drives/~', ['codigo' => 503])->luego('GET', '~/drives/~', $ok);
    cliente($t, $esperas)->pedir('GET', '/drives/x');
    comprobarGraph(count($esperas) === 2 && $esperas[0] >= 1 && $esperas[0] <= 1.5
        && $esperas[1] >= 2 && $esperas[1] <= 2.5, 'un 503 espera 1 y después 2 segundos, con algo de azar');

    $t = (new TransporteGuionado())->conToken()->repetir(5, 'GET', '~/drives/~', ['codigo' => 503]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas)->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && $e->esTransitorio() && $t->pendientes() === 0,
        'a los 5 intentos se rinde, con un error que dice que es pasajero');

    // Red caída = como un 503.
    $t = (new TransporteGuionado())->conToken()
        ->luego('GET', '~/drives/~', ['codigo' => 0, 'error' => 'Could not resolve host'])
        ->luego('GET', '~/drives/~', $ok);
    comprobarGraph(cliente($t, $esperas)->pedir('GET', '/drives/x')['codigo'] === 200,
        'sin red, se reintenta');

    // Un POST que falla con 503 NO se repite: podría haberse hecho.
    $t = (new TransporteGuionado())->conToken()->luego('POST', '~/copy~', ['codigo' => 503]);
    $e = lanzaGraph(function () use ($t) {
        cliente($t, $esperas)->pedir('POST', '/drives/x/items/1/copy', ['json' => []]);
    });
    comprobarGraph($e instanceof ErrorGraph && $t->pendientes() === 0 && count($t->recibidas) === 2,
        'un POST /copy que da 503 no se repite a ciegas: serían dos copias');

    // 500: dos reintentos y basta, solo en lo idempotente.
    $t = (new TransporteGuionado())->conToken()->repetir(3, 'GET', '~/drives/~', ['codigo' => 500]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas)->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && $t->pendientes() === 0 && count($t->recibidas) === 4,
        'un 500 se reintenta dos veces, no cinco');

    // ── 409 y 507: respuestas, no fallas que reintentar ─────────────────
    $t = (new TransporteGuionado())->conToken()->luego('PUT', '~/content~', ['codigo' => 409,
        'cuerpo' => ['error' => ['code' => 'nameAlreadyExists', 'message' => 'exists']]]);
    $e = lanzaGraph(function () use ($t) {
        cliente($t, $esperas)->pedir('PUT', '/drives/x/root:/a.xml:/content', ['cuerpo' => 'x', 'ruta' => 'SISTEMA/a.xml']);
    });
    comprobarGraph($e instanceof AlmacenConflicto && $e->rutaOcupada() === 'SISTEMA/a.xml'
        && $e->hashExistente() === '' && $t->pendientes() === 0,
        'un 409 "ya existe" es AlmacenConflicto con la ruta, sin hash (no se sabe) y sin reintentar');

    $t = (new TransporteGuionado())->conToken()->luego('PUT', '~/content~', ['codigo' => 507,
        'cuerpo' => ['error' => ['code' => 'quotaLimitReached', 'message' => 'full']]]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas)->pedir('PUT', '/drives/x/root:/a.xml:/content', ['cuerpo' => 'x']); });
    comprobarGraph($e instanceof ErrorGraph && strpos($e->getMessage(), 'sin espacio') !== false
        && $e->detalle() === 'full', 'un 507 dice que se acabó el espacio, y guarda lo que dijo Microsoft');

    $t = (new TransporteGuionado())->conToken()->luego('GET', '~/items/~', ['codigo' => 404]);
    $r = cliente($t, $esperas)->pedir('GET', '/drives/x/items/1', ['aceptar' => [404]]);
    comprobarGraph($r['codigo'] === 404, 'un 404 esperado se devuelve, no se lanza');

    // Credenciales mal: el mensaje dice qué hacer.
    $t = (new TransporteGuionado())->luego('POST', '~login~', ['codigo' => 401,
        'cuerpo' => ['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret']]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas)->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && strpos($e->getMessage(), 'secreto') !== false,
        'un secreto vencido se explica como tal');

    $e = ErrorGraph::deRespuesta(['codigo' => 0, 'error' => 'SSL certificate problem: unable to get local issuer']);
    comprobarGraph(strpos($e->getMessage(), 'curl.cainfo') !== false, 'sin certificados raíz, se dice cuál es el arreglo');

    // ── Presupuesto ─────────────────────────────────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->luego('GET', '~/drives/~', ['codigo' => 429, 'cabeceras' => ['Retry-After' => '30']]);
    $e = lanzaGraph(function () use ($t) { cliente($t, $esperas, ['presupuesto' => 8])->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && $esperas === [] && strpos($e->getMessage(), '30 s') !== false,
        'con 8 s de presupuesto, un Retry-After de 30 no se espera: se avisa enseguida');

    $t = (new TransporteGuionado())->conToken()->luego('GET', '~/drives/~', $ok);
    cliente($t, $esperas, ['presupuesto' => 8])->pedir('GET', '/drives/x');
    comprobarGraph($t->recibidas[1]['timeout'] <= 8, 'a cada llamada se le pasa solo lo que queda del presupuesto');

    // ── Corte tras fallos seguidos ──────────────────────────────────────
    $estadoCorte = $carpeta . DIRECTORY_SEPARATOR . 'corte';
    $reloj = 5000000.0;
    $opcionesCorte = ['carpeta_estado' => $estadoCorte, 'presupuesto' => 300,
        'reloj' => function () use (&$reloj) { return $reloj; },
        'dormir' => function ($s) use (&$reloj) { $reloj += $s; }];
    $t = (new TransporteGuionado())->conToken();
    for ($i = 0; $i < 5; $i++) {
        $t->luego('POST', '~/copy~', ['codigo' => 503]);
    }
    $c = new ClienteGraph($config, $t, $opcionesCorte + ['registro' => null]);
    for ($i = 0; $i < 5; $i++) {
        lanzaGraph(function () use ($c) { $c->pedir('POST', '/drives/x/items/1/copy', ['json' => []]); });
    }
    $e = lanzaGraph(function () use ($c) { $c->pedir('GET', '/drives/x'); });
    comprobarGraph($e instanceof ErrorGraph && strpos($e->getMessage(), 'dejó de insistir') !== false
        && $t->pendientes() === 0, 'tras 5 fallos seguidos se contesta al instante, sin salir a la red');

    // Otro proceso lo ve igual: el corte está en un archivo.
    $t2 = new TransporteGuionado();
    $e = lanzaGraph(function () use ($config, $t2, $opcionesCorte) {
        (new ClienteGraph($config, $t2, $opcionesCorte + ['registro' => null]))->pedir('GET', '/drives/x');
    });
    comprobarGraph($e instanceof ErrorGraph && !$t2->recibidas, 'el corte vale también para otro proceso');

    $reloj += 61;
    $t->luego('GET', '~/drives/~', $ok);
    comprobarGraph($c->pedir('GET', '/drives/x')['codigo'] === 200, 'pasado el minuto se vuelve a probar');

    // ── Rutas ───────────────────────────────────────────────────────────
    $c = cliente(new TransporteGuionado(), $esperas);
    comprobarGraph($c->rutaItem('SISTEMA\\EMPRESA ÑANDÚ\\2026\\a b#1.xml')
        === '/drives/b%21unidad/root:/SISTEMA/EMPRESA%20%C3%91AND%C3%9A/2026/a%20b%231.xml:',
        'cada tramo se codifica aparte y se aceptan las dos barras: ' . $c->rutaItem('SISTEMA\\EMPRESA ÑANDÚ\\2026\\a b#1.xml'));
    comprobarGraph($c->rutaItem('') === '/drives/b%21unidad/root', 'la raíz de la biblioteca');

    // ── Lotes ───────────────────────────────────────────────────────────
    $peticiones = [];
    for ($i = 1; $i <= 25; $i++) {
        $peticiones[$i] = ['url' => '/drives/x/items/' . $i];
    }
    $responder = function ($frenar) {
        return function ($p) use ($frenar) {
            $pedidos = json_decode($p['cuerpo'], true)['requests'];
            $respuestas = [];
            foreach ($pedidos as $pedido) {
                $respuestas[] = in_array($pedido['id'], $frenar, true)
                    ? ['id' => $pedido['id'], 'status' => 429, 'headers' => ['Retry-After' => '3']]
                    : ['id' => $pedido['id'], 'status' => 200, 'body' => ['id' => 'item' . $pedido['id']]];
            }
            return ['codigo' => 200, 'cuerpo' => ['responses' => $respuestas]];
        };
    };
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~/\$batch$~', $responder(['4']))
        ->luego('POST', '~/\$batch$~', $responder([]))
        ->luego('POST', '~/\$batch$~', $responder([]));
    $r = cliente($t, $esperas)->lote($peticiones);
    $tamanos = array_map(function ($p) { return count(json_decode($p['cuerpo'], true)['requests']); },
        array_slice($t->recibidas, 1));
    comprobarGraph($tamanos === [20, 5, 1], 'se parte de a 20 y se reintenta solo lo frenado: ' . json_encode($tamanos));
    comprobarGraph(count($r) === 25 && $r['4']['codigo'] === 200 && $r['4']['json']['id'] === 'item4',
        'cada respuesta vuelve con su id');
    comprobarGraph($esperas === [3.0], 'esperando lo que pidió el Retry-After del lote');

    // ── Bajar ───────────────────────────────────────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->luego('GET', '~/content$~', function ($p) {
            fwrite($p['guardar_en'], 'A MEDIAS');   // se cortó a la mitad
            return ['codigo' => 0, 'error' => 'Connection reset'];
        })
        ->luego('GET', '~/content$~', ['codigo' => 200, 'cuerpo' => '<xml>completo</xml>']);
    $destino = fopen('php://temp', 'w+b');
    $esta = cliente($t, $esperas)->bajar('/drives/x/items/1', $destino);
    rewind($destino);
    comprobarGraph($esta && stream_get_contents($destino) === '<xml>completo</xml>',
        'un intento cortado no deja basura delante del documento');
    comprobarGraph(!empty($t->recibidas[1]['seguir']), 'se siguen las redirecciones a la URL firmada');
    fclose($destino);

    $t = (new TransporteGuionado())->conToken()->luego('GET', '~/content$~', ['codigo' => 404]);
    $destino = fopen('php://temp', 'w+b');
    comprobarGraph(cliente($t, $esperas)->bajar('/drives/x/items/1', $destino) === false,
        'un documento que no está devuelve false');
    fclose($destino);

    // ── Registro ────────────────────────────────────────────────────────
    $registro = $carpeta . DIRECTORY_SEPARATOR . 'graph.log';
    $t = (new TransporteGuionado())->conToken('tok-secreto')->luego('GET', '~/drives/~', $ok)
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['5-']]]);
    $c = cliente($t, $esperas, ['registro' => $registro]);
    $c->pedir('GET', '/drives/x');
    $c->pedir('PUT',
        'https://tenant.sharepoint.com/_api/v2.0/drives/x/items/1/uploadSession?guid=1&tempauth=PERMISO', [
            'autenticar' => false, 'cuerpo' => 'x']);
    $log = (string) file_get_contents($registro);
    comprobarGraph(substr_count($log, "\n") === 3, 'una línea por llamada, el token incluido');
    comprobarGraph(strpos($log, "GET\t/v1.0/drives/x\t200") !== false, 'las de Graph, con su ruta: ' . $log);
    comprobarGraph(strpos($log, "PUT\ttenant.sharepoint.com/_api/v2.0/drives/x/items/1/uploadSession\t202") !== false,
        'con verbo, ruta y estado: ' . $log);
    comprobarGraph(strpos($log, 'tempauth') === false && strpos($log, 'PERMISO') === false
        && strpos($log, 'tok-secreto') === false && strpos($log, 'secreto-que') === false,
        'y sin un solo permiso, token ni secreto');

    // ── La configuración de esta máquina ────────────────────────────────
    comprobarGraph(ClienteGraph::configuracion($carpeta . DIRECTORY_SEPARATOR . 'no-existe.php') === null,
        'sin local.graph.php no hay cliente');
    $plantilla = $carpeta . DIRECTORY_SEPARATOR . 'local.graph.php';
    file_put_contents($plantilla, "<?php return ['tenant_id'=>'t','client_id'=>'c','client_secret'=>'PEGA-ACA','drive_id'=>'d'];");
    comprobarGraph(ClienteGraph::configuracion($plantilla) === null, 'una plantilla sin completar tampoco');
} finally {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($carpeta);
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: el cliente de Graph aguanta los días malos de Microsoft\n";
