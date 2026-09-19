<?php
/**
 * La subida de archivos de más de 4 MiB, que casi nunca corre.
 *
 * Con 81 KB de promedio por documento, la sesión de carga es la excepción, y
 * justamente por eso hay que probarla: el código que no corre todos los días
 * es el que se rompe sin que nadie lo vea hasta el día que hace falta.
 *
 * Lo que se comprueba:
 *   - Hasta 4 MiB exactos, un PUT; un byte más, sesión de carga.
 *   - Trozos de 10 MiB con su Content-Range, el último más corto.
 *   - Los trozos NO llevan Authorization (Graph los rechaza si la llevan).
 *   - Se sigue desde donde dice nextExpectedRanges, no desde donde se creía.
 *   - Si un trozo falla del todo, se pregunta a la sesión qué falta y se
 *     retoma, en vez de empezar de nuevo.
 *   - Un conflicto al cerrar es AlmacenConflicto, y la sesión se cancela.
 *
 * Uso: php tests/SubidaGrandeTest.php
 */
require_once __DIR__ . '/../app/helpers/ClienteGraph.php';
require_once __DIR__ . '/soporte/TransporteGuionado.php';

$fallas = [];

function comprobarSubida($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

$config = ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'];

function clienteSubida(TransporteGraph $t)
{
    global $config;
    $reloj = 1000000.0;
    return new ClienteGraph($config, $t, [
        'presupuesto' => 300, 'carpeta_estado' => null, 'registro' => null,
        'reloj' => function () use (&$reloj) { return $reloj; },
        'dormir' => function ($s) use (&$reloj) { $reloj += $s; },
    ]);
}

function archivoDe($bytes)
{
    $ruta = tempnam(sys_get_temp_dir(), 'grande');
    $fp = fopen($ruta, 'wb');
    // Contenido que cambia con la posición: un trozo mandado desde el byte
    // equivocado se nota.
    $bloque = '';
    for ($i = 0; $i < 256; $i++) {
        $bloque .= chr($i);
    }
    $escritos = 0;
    while ($escritos < $bytes) {
        $parte = substr(str_repeat($bloque, 4096), 0, min(1048576, $bytes - $escritos));
        fwrite($fp, $parte);
        $escritos += strlen($parte);
    }
    fclose($fp);
    return $ruta;
}

$sesion = 'https://tenant.sharepoint.com/_api/v2.0/drives/d/items/raiz/uploadSession?guid=1&tempauth=X';
$abrirSesion = ['codigo' => 200, 'cuerpo' => ['uploadUrl' => $sesion]];
$MiB = 1048576;
$archivos = [];

try {
    // ── El límite ───────────────────────────────────────────────────────
    $archivos[] = $justo = archivoDe(4 * $MiB);
    $t = (new TransporteGuionado())->conToken()
        ->luego('PUT', '~/root:/SISTEMA/justo\.pdf:/content\?@microsoft\.graph\.conflictBehavior=fail$~',
            ['codigo' => 201, 'cuerpo' => ['id' => 'ITEM-JUSTO']]);
    $item = clienteSubida($t)->subir('SISTEMA/justo.pdf', $justo);
    comprobarSubida($item['id'] === 'ITEM-JUSTO' && strlen($t->recibidas[1]['cuerpo']) === 4 * $MiB,
        '4 MiB exactos van en un solo PUT');

    // ── 25 MiB: tres trozos ─────────────────────────────────────────────
    $archivos[] = $grande = archivoDe(25 * $MiB);
    $total = 25 * $MiB;
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~/root:/SISTEMA/grande\.pdf:/createUploadSession$~', $abrirSesion)
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['10485760-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['20971520-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 201, 'cuerpo' => ['id' => 'ITEM-GRANDE', 'size' => $total]]);
    $item = clienteSubida($t)->subir('SISTEMA/grande.pdf', $grande);

    comprobarSubida($item['id'] === 'ITEM-GRANDE', 'el id llega con el último trozo');
    $pedido = json_decode($t->recibidas[1]['cuerpo'], true);
    comprobarSubida(($pedido['item']['@microsoft.graph.conflictBehavior'] ?? '') === 'fail',
        'la sesión se abre pidiendo que un nombre ocupado falle, no que se invente otro');
    $rangos = array_map(function ($p) { return $p['cabeceras']['Content-Range'] ?? ''; }, array_slice($t->recibidas, 2));
    comprobarSubida($rangos === [
        'bytes 0-10485759/26214400',
        'bytes 10485760-20971519/26214400',
        'bytes 20971520-26214399/26214400',
    ], 'trozos de 10 MiB y el último más corto: ' . json_encode($rangos));
    comprobarSubida(ClienteGraph::TROZO % 327680 === 0, 'el trozo es múltiplo de 320 KiB, como exige Graph');
    $conAuth = array_filter(array_slice($t->recibidas, 2), function ($p) {
        return isset($p['cabeceras']['Authorization']);
    });
    comprobarSubida(!$conAuth, 'ningún trozo lleva Authorization');
    $contenido = (string) file_get_contents($grande);
    comprobarSubida($t->recibidas[3]['cuerpo'] === substr($contenido, 10485760, 10485760),
        'cada trozo manda los bytes de su rango');

    // ── nextExpectedRanges manda ────────────────────────────────────────
    // SharePoint dice que del primer trozo solo guardó la mitad.
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~createUploadSession~', $abrirSesion)
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['5242880-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['15728640-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 201, 'cuerpo' => ['id' => 'ITEM']]);
    clienteSubida($t)->subir('SISTEMA/grande.pdf', $grande);
    comprobarSubida(($t->recibidas[3]['cabeceras']['Content-Range'] ?? '') === 'bytes 5242880-15728639/26214400',
        'se sigue desde donde dice la sesión, no desde donde se creía');

    // ── Un trozo que falla del todo: se retoma ─────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~createUploadSession~', $abrirSesion)
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['10485760-']]])
        ->repetir(5, 'PUT', '~uploadSession~', ['codigo' => 503])
        ->luego('GET', '~uploadSession~', ['codigo' => 200, 'cuerpo' => ['nextExpectedRanges' => ['10485760-26214399']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['20971520-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 201, 'cuerpo' => ['id' => 'ITEM-RETOMADO']]);
    $item = clienteSubida($t)->subir('SISTEMA/grande.pdf', $grande);
    $pregunta = $t->recibidas[8];
    comprobarSubida($item['id'] === 'ITEM-RETOMADO' && $t->pendientes() === 0,
        'tras cinco 503 en un trozo se pregunta qué falta y se termina');
    comprobarSubida($pregunta['metodo'] === 'GET' && !isset($pregunta['cabeceras']['Authorization'])
        && ($t->recibidas[9]['cabeceras']['Content-Range'] ?? '') === 'bytes 10485760-20971519/26214400',
        'sin volver a empezar: se retoma en el trozo que faltaba');

    // ── Conflicto al cerrar ─────────────────────────────────────────────
    $t = (new TransporteGuionado())->conToken()
        ->luego('POST', '~createUploadSession~', $abrirSesion)
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['10485760-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 202, 'cuerpo' => ['nextExpectedRanges' => ['20971520-']]])
        ->luego('PUT', '~uploadSession~', ['codigo' => 409,
            'cuerpo' => ['error' => ['code' => 'nameAlreadyExists', 'message' => 'exists']]])
        ->luego('DELETE', '~uploadSession~', ['codigo' => 204]);
    $e = null;
    try {
        clienteSubida($t)->subir('SISTEMA/grande.pdf', $grande);
    } catch (Throwable $x) {
        $e = $x;
    }
    comprobarSubida($e instanceof AlmacenConflicto && $e->rutaOcupada() === 'SISTEMA/grande.pdf',
        'un nombre ocupado al cerrar es AlmacenConflicto con la ruta del documento');
    comprobarSubida($t->pendientes() === 0 && $t->recibidas[5]['metodo'] === 'DELETE',
        'y la sesión a medias se cancela, para que no ocupe cuota');
} finally {
    foreach ($archivos as $archivo) {
        @unlink($archivo);
    }
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: los archivos grandes suben por partes y se retoman donde quedaron\n";
