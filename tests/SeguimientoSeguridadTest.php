<?php
/**
 * Seguimiento frente a entradas hostiles.
 *
 * Dos clases de pruebas. Las de RESISTENCIA pasaban desde el principio y
 * tienen que seguir pasando: inyección SQL por cada filtro de la URL, por la
 * selección y por los textos libres; HTML y JavaScript metidos en cada dato
 * que la vista pinta. Las marcadas [S1]–[S4] fueron huecos abiertos —leer y
 * escribir renglones de otra empresa, cambiar estados desde otro sitio sin
 * token, mensajes internos de la base devueltos al navegador— y ahora protegen
 * su arreglo.
 *
 * Las cargas llevan la marca ZQX9: si aparece dentro del texto SQL, algo se
 * interpoló en vez de viajar como parámetro.
 */
require_once __DIR__ . '/soporte/SeguimientoSoporte.php';

Prueba::iniciar('Seguimiento frente a entradas hostiles');

const MARCA = 'ZQX9';

function cargasSql(): array
{
    return [
        "' OR '1'='1' -- ZQX9",
        '1; DROP TABLE seguimiento; -- ZQX9',
        "%' UNION SELECT username, password FROM usuarios -- ZQX9",
        "\\'); DELETE FROM seguimiento_bitacora; # ZQX9",
        '" OR ""=" ZQX9',
        "ZQX9' AND SLEEP(5) AND '1'='1",
        "ZQX9\0' OR 1=1 --",
        "𝐙𝐐𝐗 ZQX9' OR 1=1 -- ",
        'ZQX9' . str_repeat("'", 3000),
    ];
}

/** Ningún texto SQL lleva la marca, y cada «?» tiene su parámetro. */
function sinInterpolar(array $consultas, string $contexto): void
{
    foreach ($consultas as $c) {
        if ($c['sql'] === '') {
            continue;
        }
        verificar(strpos($c['sql'], MARCA) === false, "{$contexto}: la carga quedó dentro del SQL");
        igual(substr_count($c['sql'], '?'), count($c['params']), "{$contexto}: marcadores y parámetros");
    }
}

function paramsConMarca(array $consultas): int
{
    $n = 0;
    foreach ($consultas as $c) {
        foreach ($c['params'] as $p) {
            if (is_string($p) && strpos($p, MARCA) !== false) {
                $n++;
            }
        }
    }
    return $n;
}

// ── Inyección SQL ───────────────────────────────────────────────────────────

Prueba::caso('ningún filtro de la URL llega interpolado al SQL, en ningún modo', function () {
    $claves = ['vista', 'origen', 'llegada', 'tarea', 'marca', 'clase', 'aplicacion', 'responsable', 'proveedor',
               'sucursal', 'contexto_id', 'desde', 'hasta', 'monto_min', 'condicion_saldo', 'q', 'col_documento',
               'col_proveedor', 'col_monto', 'col_saldo', 'col_respaldo', 'col_tarea', 'orden', 'modo'];
    $libres = ['responsable', 'sucursal', 'q', 'col_documento', 'col_proveedor', 'proveedor'];
    $revisadas = 0;
    foreach ([Seguimiento::MODO_SISTEMA, Seguimiento::MODO_CORREO] as $modo) {
        foreach ($claves as $clave) {
            foreach (cargasSql() as $i => $carga) {
                $valor = $clave === 'proveedor' ? 'nom:' . $carga : $carga;
                $f = Seguimiento::filtrosDesde([$clave => $valor, 'modo' => $modo, 'vista' => 'todo'] + [$clave => $valor], 4);
                $m = (new SeguimientoGrabadora())->enModo($modo);
                $m->cola($f, 1, 50);
                $m->resumen($f);
                igual(2, count($m->consultas), 'página (con su total) y resumen');
                sinInterpolar($m->consultas, "modo {$modo}, filtro {$clave}, carga {$i}");
                $revisadas += count($m->consultas);
                if (in_array($clave, $libres, true) && !($clave === 'proveedor' && $modo === 'correo')) {
                    verificar(paramsConMarca($m->consultas) > 0, "modo {$modo}, {$clave}: el texto libre sí viaja, como parámetro");
                }
            }
        }
    }
    igual(2 * 24 * count(cargasSql()) * 2, $revisadas, 'consultas revisadas en el barrido');
});

Prueba::caso('la selección hostil no llega ni a consultarse', function () {
    $m = new SeguimientoGrabadora();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['estado' => 'cerrada', 'items' => [
        "factura|1 OR 1=1 -- ZQX9", 'factura|1;DROP TABLE seguimiento', "factura' OR '1'='1|1",
        '{"origen":"factura\' OR \'1\'=\'1","referencia_id":1}', 'usuarios|1', 'factura|0x1A', "factura|1\0",
    ]];
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $m;
    igual([422, ['ok' => false, 'message' => 'No se seleccionó ningún renglón.']], $c->responder('actualizar'), 'respuesta');
    igual([], $m->consultas, 'ninguna consulta');
});

Prueba::caso('motivo y anotación hostiles se guardan tal cual, siempre como parámetros', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $m->aplicar(['factura|7'], [
        'estado' => 'revision', 'responsable' => "Ana'); DROP TABLE usuarios; -- ZQX9",
        'motivo' => "'); DROP TABLE seguimiento; -- ZQX9", 'comentario' => '<script>alert(1)</script> ZQX9',
    ], ['id' => 7, 'nombre' => "O'Brien ZQX9"]);
    sinInterpolar($m->consultas, 'escritura');
    verificar(paramsConMarca($m->consultas) >= 4, 'los textos llegan completos a sus parámetros');
});

Prueba::caso('orden, página y límites no admiten SQL', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(Seguimiento::filtrosDesde(['orden' => 'c.fecha; DROP TABLE seguimiento -- ZQX9', 'vista' => 'todo']),
        '2 UNION SELECT 1 -- ZQX9', '50) OR (1=1');
    $m->bitacora("factura' -- ZQX9", '10 OR 1=1', "5; DELETE FROM seguimiento -- ZQX9");
    $m->generarRecordatorios('1; DROP TABLE seguimiento -- ZQX9');
    sinInterpolar($m->consultas, 'orden, página y límites');
});

// ── XSS: la vista con datos envenenados ────────────────────────────────────

function pintarVista(string $modo, array $filtros, array $filas, array $proveedores, array $sucursales): string
{
    $modoSeguimiento = $modo;
    $estados = Seguimiento::estadosDe($modo);
    $tareas = Seguimiento::tareasDe($modo);
    $origenes = Seguimiento::origenesDe($modo);
    $llegadas = Seguimiento::LLEGADAS;
    $paginacion = ['total' => count($filas), 'pagina' => 2, 'paginas' => 3, 'por_pagina' => 50];
    $resumen = (new SeguimientoGrabadora())->resumen(['vista' => 'todo']);
    $proveedoresFiltro = $proveedores;
    ob_start();
    try {
        include __DIR__ . '/../app/views/seguimiento/index.php';
    } finally {
        $html = ob_get_clean();
    }
    return $html;
}

Prueba::caso('cada dato que la vista pinta sale escapado, en los dos modos', function () {
    $veneno = '"><img src=x onerror=alert(1)>' . "'><svg onload=alert(2)>" . '</script><script>alert(3)</script>';
    $crudos = ['<img src=x onerror=alert(1)>', '<svg onload=alert(2)>', '<script>alert(3)</script>'];

    $filtros = [
        'q' => $veneno, 'col_documento' => $veneno, 'col_proveedor' => $veneno, 'sucursal' => $veneno,
        'responsable' => $veneno, 'proveedor' => 'nom:' . $veneno, 'col_respaldo' => $veneno, 'col_tarea' => $veneno,
    ];
    $comunes = [
        'documento' => $veneno, 'proveedor' => $veneno, 'sucursal' => $veneno, 'clase' => $veneno, 'contexto' => $veneno,
        'motivo' => $veneno, 'seguimiento_estado' => 'revision', 'estado_a_mano' => 'revision',
        'estado_calculado' => 'pendiente', 'responsable' => $veneno, 'recordar_en' => $veneno, 'recordar_cada' => '7',
        'motivo_match' => $veneno, 'consecutivo' => $veneno, 'proveedor_cedula' => $veneno,
        'proveedor_codigo' => $veneno, 'nc_proveedor' => $veneno, 'anotaciones' => '2', 'diferencia' => '10',
        'sistema_doc' => $veneno, 'aplicacion_factura_doc' => $veneno, 'aplicacion_factura_saldo' => '1',
    ];
    $escenarios = [
        Seguimiento::MODO_SISTEMA => [
            Soporte::filaCola(['origen' => 'nota_credito', 'aplicacion_estado' => AplicacionNotaCredito::APLICABLE,
                               'aplicacion_factura_id' => '5'] + $comunes),
            Soporte::filaCola(['origen' => 'factura', 'notas_vivas' => '2', 'notas_vivas_saldo' => '3'] + $comunes),
        ],
        Seguimiento::MODO_CORREO => [
            Soporte::filaCola(['origen' => 'xml_nota', 'tarea' => 'completo', 'llegada' => 'correo'] + $comunes),
            Soporte::filaCola(['origen' => 'xml_factura', 'tarea' => 'sin_sistema', 'llegada' => 'carga'] + $comunes),
        ],
    ];
    foreach ($escenarios as $modo => $filas) {
        $f = Seguimiento::filtrosDesde($filtros + ['modo' => $modo, 'vista' => 'todo'], 4);
        $decoradas = (new SeguimientoControllerSonda())->privado('decorar', $filas, $modo);
        $html = pintarVista($modo, $f, $decoradas,
            [['clave' => $f['proveedor'], 'codigos' => [$veneno], 'cedula' => $veneno, 'nombre' => $veneno, 'n' => 3]],
            [$veneno => 2]);
        verificar(strlen($html) > 10000, "modo {$modo}: la vista se pintó entera");
        // Sin esto la prueba pasaría también si la vista no pintara los datos.
        verificar(substr_count($html, '&lt;img src=x onerror=alert(1)&gt;') >= 5,
            "modo {$modo}: el veneno sí se pintó, escapado");
        foreach ($crudos as $crudo) {
            verificar(strpos($html, $crudo) === false, "modo {$modo}: «{$crudo}» salió sin escapar");
        }
    }
});

// ── Defectos cerrados: cada uno fue un DEFECTO ABIERTO de docs/REVISION_SEGUIMIENTO.md ──

/** Una empresa activa (4) y un renglón que es de otra (1138). */
function sondaDeEmpresa(SeguimientoGrabadora $m): SeguimientoControllerSonda
{
    $_SESSION['sociedad_id'] = 4;
    Soporte::empresaEnCurso(4);
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $m;
    $c->modelos['Sociedad'] = new class {
        public function getActiva() { return ['id' => 4, 'nombre' => 'Empresa de la sesión']; }
        public function seleccionadaId() { return 4; }
    };
    return $c;
}

/** Un POST a actualizar() con estas cabeceras; devuelve [estado HTTP, cuerpo, modelo]. */
function postearCon(array $cabeceras, ?string $tokenDeSesion = 'token-de-prueba'): array
{
    foreach (array_keys($_SERVER) as $clave) {
        if (strpos($clave, 'HTTP_') === 0) {
            unset($_SERVER[$clave]);
        }
    }
    foreach ($cabeceras as $clave => $valor) {
        $_SERVER[$clave] = $valor;
    }
    $_SESSION['csrf_token'] = $tokenDeSesion;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['items' => ['factura|7'], 'estado' => 'cerrada'];
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => 'revision']]);
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $m;
    [$estado, $cuerpo] = $c->responder('actualizar');
    return [$estado, $cuerpo, $m];
}

Prueba::caso('[S1] el expediente de un documento de otra empresa no se entrega', function () {
    $m = new SeguimientoGrabadora();
    // Si la consulta llega a acotar por empresa, este doble lo respeta. Se
    // mira la condición sobre la unión (c.), no la columna: sociedad_id ya
    // aparece en el SELECT de cada rama.
    $m->respuestas['WHERE c.origen = ? AND c.referencia_id = ?'] = function ($sql, $params) {
        if (preg_match('/\bc\.sociedad_id\s*(=|IN\b)/i', $sql) && !in_array(1138, $params)) {
            return false;
        }
        return Soporte::filaCola(['sociedad_id' => '1138', 'documento' => 'FE-DE-OTRA-EMPRESA', 'motivo' => 'Nota interna']);
    };
    $m->respuestas['FROM seguimiento_bitacora b'] = [['comentario' => 'Lo que anotó la otra empresa']];
    $_GET = ['origen' => 'factura', 'referencia_id' => '12'];
    [$estado, $cuerpo] = sondaDeEmpresa($m)->responder('detalle');
    verificar($estado >= 400 && empty($cuerpo['fila']),
        "respondió {$estado} con el documento " . ($cuerpo['fila']['documento'] ?? '—') . ' y su bitácora');
    igual('El renglón no existe o ya no está en la cola.', $cuerpo['message'], 'lo mismo que un renglón que no existe');
    igual([], $m->de('fetchAll', 'FROM seguimiento_bitacora'), 'la bitácora ni se consultó');
    igual(['factura', 12, 4], $m->de('fetchOne')[0]['params'], 'se pidió acotado a la empresa de la sesión');

    // El de la propia empresa sí se entrega.
    $m = new SeguimientoGrabadora();
    $m->respuestas['WHERE c.origen = ? AND c.referencia_id = ?'] = Soporte::filaCola(['sociedad_id' => '4', 'documento' => 'FE-PROPIA']);
    [$estado, $cuerpo] = sondaDeEmpresa($m)->responder('detalle');
    igual([200, 'FE-PROPIA'], [$estado, $cuerpo['fila']['documento'] ?? null], 'el expediente propio');
});

Prueba::caso('[S2] no se escribe sobre un renglón que no existe o no es de la empresa activa', function () {
    $m = new SeguimientoGrabadora();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['items' => ['factura|999999', 'nota_credito|4294967295'], 'comentario' => 'Anotación fantasma'];
    [$estado, $cuerpo] = sondaDeEmpresa($m)->responder('actualizar');
    igual(0, count($m->de('execute', 'INSERT INTO seguimiento')), 'sentencias de escritura sobre renglones ajenos');
    igual([422, 'Ninguno de los renglones seleccionados está en la cola de la empresa activa.'], [$estado, $cuerpo['message']],
        'se dice por qué');
    $pertenencia = $m->de('fetchAll', 'AS en_cola');
    igual(1, count($pertenencia), 'se preguntó una vez por los dos');
    igual([999999, 4, 4294967295, 4], $pertenencia[0]['params'], 'con la empresa de la sesión, no una del POST');

    // Mezclados: se escribe lo propio y se avisa cuántos se descartaron.
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $_POST = ['items' => ['factura|7', 'factura|999999'], 'comentario' => 'Revisado', 'sociedad_id' => '1138'];
    igual([200, ['ok' => true, 'afectados' => 1, 'descartados' => 1]], sondaDeEmpresa($m)->responder('actualizar'), 'mezclados');
    igual(8, count(upsertParams($m)), 'un solo renglón escrito');
});

function upsertParams(SeguimientoGrabadora $m): array
{
    $upserts = $m->de('execute', "INSERT INTO seguimiento\n");
    return $upserts ? $upserts[0]['params'] : [];
}

Prueba::caso('[S3] un cambio de estado sin token y desde otro sitio se rechaza', function () {
    // Desde otro sitio se rechaza aunque trajera el token.
    [$estado, $cuerpo, $m] = postearCon(['HTTP_ORIGIN' => 'https://sitio-ajeno.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site',
                                          'HTTP_X_CSRF_TOKEN' => 'token-de-prueba']);
    igual(403, $estado, 'otro sitio');
    igual('csrf', $cuerpo['error'] ?? null, 'dice qué pasó');
    igual([], $m->consultas, 'ni se consultó');

    $rechazos = [
        'sin token ni cabeceras del navegador' => [[]],
        'token equivocado' => [['HTTP_X_CSRF_TOKEN' => 'otro']],
        'token pero la sesión no tiene' => [['HTTP_X_CSRF_TOKEN' => 'token-de-prueba'], null],
        'un subdominio (same-site)' => [['HTTP_SEC_FETCH_SITE' => 'same-site']],
        'Origin de otro puerto' => [['HTTP_ORIGIN' => 'http://localhost:8080', 'HTTP_HOST' => 'localhost']],
        'Origin "null" y sin token' => [['HTTP_ORIGIN' => 'null']],
    ];
    foreach ($rechazos as $como => $caso) {
        [$estado, , $m] = postearCon(...$caso);
        igual(403, $estado, $como);
        igual([], $m->de('execute'), "{$como}: nada escrito");
    }

    $aceptados = [
        'el token de la sesión' => [['HTTP_X_CSRF_TOKEN' => 'token-de-prueba']],
        'Sec-Fetch-Site same-origin (pestaña con token viejo)' => [['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_CSRF_TOKEN' => 'viejo']],
        'Origin igual al Host' => [['HTTP_ORIGIN' => 'http://192.168.1.20:8080', 'HTTP_HOST' => '192.168.1.20:8080']],
        'Origin sin el puerto por omisión que el Host sí escribe' => [['HTTP_ORIGIN' => 'http://localhost', 'HTTP_HOST' => 'localhost:80']],
    ];
    foreach ($aceptados as $como => $caso) {
        [$estado] = postearCon(...$caso);
        igual(200, $estado, $como);
    }
    unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
});

Prueba::caso('[S3] el token de la sesión se crea una vez y no cambia entre pedidos', function () {
    unset($_SESSION['csrf_token']);
    $primero = Csrf::token();
    igual(64, strlen($primero), 'token de 32 bytes en hexadecimal');
    igual($primero, Csrf::token(), 'el mismo en la misma sesión');
});

Prueba::caso('[S4] un error de la base no llega al navegador con su SQLSTATE ni sus columnas', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $m->fallarEn = "INSERT INTO seguimiento\n";
    $falla = new PDOException("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'motivo' at row 1");
    $m->falla = $falla;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    // Un motivo corto: el largo ya lo ataja L5 antes de la base, y aquí se
    // quiere el camino en el que la base sí falla.
    $_POST = ['items' => ['factura|7'], 'estado' => 'revision', 'motivo' => 'No contesta'];
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $m;
    [$estado, $cuerpo] = $c->responder('actualizar');
    verificar(stripos($cuerpo['message'], 'SQLSTATE') === false && stripos($cuerpo['message'], 'column') === false,
        'el navegador recibió: ' . $cuerpo['message']);
    igual(500, $estado, 'es un fallo del sistema, no de lo que se pidió');
    igual(1, count($c->fallosRegistrados), 'y queda en el log para quien mantiene la aplicación');
    verificar($c->fallosRegistrados[0][1] === $falla, 'con el error original');

    // Un error de programación tampoco se asoma.
    $m = new SeguimientoGrabadora();
    $m->respuestas['WHERE c.origen = ? AND c.referencia_id = ?'] = function () {
        throw new TypeError('Seguimiento::uno(): Argument #1 must be of type string, array given in C:\\WebApps\\xmlconcilia\\app\\models\\Seguimiento.php');
    };
    $_GET = ['origen' => 'factura', 'referencia_id' => '12'];
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $m;
    [$estado, $cuerpo] = $c->responder('detalle');
    igual(500, $estado, 'expediente con fallo interno');
    verificar(strpos($cuerpo['message'], 'WebApps') === false, 'sin rutas del servidor: ' . $cuerpo['message']);
});

Prueba::terminar();
