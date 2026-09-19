<?php
/**
 * El controlador de Seguimiento llevado al borde: lo que llega por POST y GET
 * sin pasar por la pantalla, la frecuencia y la hora del recordatorio, y lo
 * que se le agrega a cada renglón antes de pintarlo.
 *
 * Lo que se protege: que la respuesta a un pedido raro sea un 4xx con un
 * mensaje entendible y no un cambio a medias; que el responsable salga de la
 * sesión y no del formulario; y que el expediente no mande al navegador las
 * rutas del disco de la oficina.
 *
 * Sin base ni exit: json() graba la respuesta y el modelo graba el SQL.
 */
require_once __DIR__ . '/soporte/SeguimientoSoporte.php';

Prueba::iniciar('controlador de Seguimiento en el borde');

function sonda(?SeguimientoGrabadora $modelo = null): SeguimientoControllerSonda
{
    $c = new SeguimientoControllerSonda();
    $c->modelos['Seguimiento'] = $modelo ?? new SeguimientoGrabadora();
    return $c;
}

function postear(array $datos): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $datos;
}

function upsertControlador(SeguimientoGrabadora $m): array
{
    $upserts = $m->de('execute', "INSERT INTO seguimiento\n");
    igual(1, count($upserts), 'un upsert');
    return $upserts[0];
}

/** El recordatorio que se espera para dentro de N días a una hora. */
function momentoEsperado(int $dias, string $hora): array
{
    // Dos candidatos por si la prueba cruza la medianoche entre el cálculo y
    // la comparación.
    $antes = date('Y-m-d', strtotime("+{$dias} days")) . " {$hora}:00";
    return [$antes, date('Y-m-d', strtotime("+{$dias} days", time() + 5)) . " {$hora}:00"];
}

// ── Frecuencia y hora del recordatorio ──────────────────────────────────────

Prueba::caso('la frecuencia solo admite los plazos del desplegable', function () {
    $c = sonda();
    foreach ([1, 3, 7, 15, 30, '1', '30'] as $valida) {
        igual((int) $valida, $c->privado('frecuencia', $valida), 'plazo ' . json_encode($valida));
    }
    foreach ([0, 2, 31, -7, 'abc', null, '', PHP_INT_MAX, [], '99999999999999999999'] as $invalida) {
        igual(0, $c->privado('frecuencia', $invalida), 'sin recordatorio para ' . json_encode($invalida));
    }
});

Prueba::caso('la hora del recordatorio: válida se respeta, cualquier otra cosa son las 08:00', function () {
    $c = sonda();
    verificar(in_array($c->privado('momentoRecordatorio', 7, '07:45'), momentoEsperado(7, '07:45'), true), '07:45 en 7 días');
    verificar(in_array($c->privado('momentoRecordatorio', 1, ' 23:59 '), momentoEsperado(1, '23:59'), true), 'con espacios');
    foreach (['24:00', '12:60', '', null, "08:00' OR '1'='1", '08:00:00', '7:5', '٠٨:٠٠'] as $mala) {
        verificar(in_array($c->privado('momentoRecordatorio', 3, $mala), momentoEsperado(3, '08:00'), true),
            'hora ' . json_encode($mala, JSON_UNESCAPED_UNICODE) . ' cae en 08:00');
    }
});

// ── La selección que viaja en el POST ───────────────────────────────────────

Prueba::caso('items: arreglo, JSON, JSON roto, escalares y anidamiento absurdo', function () {
    $c = sonda();
    $casos = [
        [['factura|7', ['origen' => 'nota_credito', 'referencia_id' => '3']], 2],
        ['["factura|7","nota_credito|3"]', 2],
        ['{"cualquier":"factura|7"}', 1],
        ['{', 0],
        ['null', 0],
        ['factura|7', 0],
        [5, 0],
        [str_repeat('[', 600) . '"factura|7"' . str_repeat(']', 600), 0],
    ];
    foreach ($casos as [$items, $cuantos]) {
        $_POST = ['items' => $items];
        igual($cuantos, count($c->privado('itemsDelPost')), 'items ' . mb_substr(json_encode($items), 0, 40));
    }
    $_POST = [];
    igual([], $c->privado('itemsDelPost'), 'sin items');
});

Prueba::caso('quién es el usuario: nombre, si no el usuario, si no "Sistema"', function () {
    $c = sonda();
    $_SESSION = ['user_id' => 3, 'user_username' => 'ana.p'];
    igual(['id' => 3, 'nombre' => 'ana.p'], $c->privado('usuario'), 'sin nombre usa el usuario');
    $_SESSION = [];
    igual(['id' => null, 'nombre' => 'Sistema'], $c->privado('usuario'), 'sesión vacía');
});

// ── actualizar() ────────────────────────────────────────────────────────────

Prueba::caso('actualizar() por GET es 405 y no toca el modelo', function () {
    $m = new SeguimientoGrabadora();
    [$estado, $cuerpo] = sonda($m)->responder('actualizar');
    igual(405, $estado, 'estado HTTP');
    igual(false, $cuerpo['ok'], 'ok');
    igual([], $m->consultas, 'sin consultas');
});

Prueba::caso('actualizar() sin renglones o sin cambios es 422 con un mensaje entendible', function () {
    postear(['estado' => 'cerrada']);
    igual([422, ['ok' => false, 'message' => 'No se seleccionó ningún renglón.']], sonda()->responder('actualizar'), 'sin renglones');

    postear(['items' => ['basura', 'usuarios|1'], 'estado' => 'cerrada']);
    igual(422, sonda()->responder('actualizar')[0], 'solo renglones inválidos');

    $m = new SeguimientoGrabadora();
    postear(['items' => ['factura|7'], 'estado' => '  ', 'comentario' => "  \n "]);
    igual([422, ['ok' => false, 'message' => 'No hay ningún cambio que guardar.']], sonda($m)->responder('actualizar'), 'nada que guardar');
    igual([], $m->de('execute'), 'nada escrito');
});

Prueba::caso('camino feliz: a revisión con motivo, recordatorio y responsable de la sesión', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    postear([
        'modo' => 'sistema', 'items' => ['factura|7'], 'estado' => 'revision', 'motivo' => ' No contesta ',
        'responsable' => 'Mallory', 'recordar_cada' => '7', 'recordar_hora' => '07:30', 'comentario' => 'Llamé',
    ]);
    igual([200, ['ok' => true, 'afectados' => 1]], sonda($m)->responder('actualizar'), 'respuesta');
    $p = upsertControlador($m)['params'];
    igual('Ana Prueba', $p[3], 'el responsable es quien tiene la sesión, no el que vino en el POST');
    verificar(in_array($p[4], momentoEsperado(7, '07:30'), true), 'recordatorio en 7 días a las 07:30: ' . $p[4]);
    igual(7, $p[5], 'frecuencia');
    igual('No contesta', $p[6], 'motivo sin espacios de sobra');
});

Prueba::caso('una frecuencia fuera del desplegable apaga el recordatorio; una hora rota cae en 08:00', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    postear(['items' => ['factura|7'], 'estado' => 'revision', 'motivo' => 'x', 'recordar_cada' => '5']);
    sonda($m)->responder('actualizar');
    igual([null, null], array_slice(upsertControlador($m)['params'], 4, 2), 'cada 5 días no existe: sin recordatorio');

    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    postear(['items' => ['factura|7'], 'estado' => 'revision', 'motivo' => 'x', 'recordar_cada' => '3', 'recordar_hora' => '25:99']);
    sonda($m)->responder('actualizar');
    verificar(in_array(upsertControlador($m)['params'][4], momentoEsperado(3, '08:00'), true), 'hora rota → 08:00');
});

Prueba::caso('el modo viaja con la tanda: lista no existe en correo', function () {
    $m = new SeguimientoGrabadora();
    postear(['modo' => 'correo', 'items' => ['xml_factura|5'], 'estado' => 'lista']);
    [$estado, $cuerpo] = sonda($m)->responder('actualizar');
    igual(422, $estado, 'estado HTTP');
    verificar(strpos($cuerpo['message'], 'no válido') !== false, 'dice por qué: ' . $cuerpo['message']);
    igual([], $m->de('execute'), 'nada escrito');
});

Prueba::caso('los items también llegan como JSON en una sola cadena', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => 'lista']]);
    postear(['items' => '["factura|7"]', 'comentario' => 'Revisado']);
    igual([200, ['ok' => true, 'afectados' => 1]], sonda($m)->responder('actualizar'), 'anotado');
});

// ── detalle() ───────────────────────────────────────────────────────────────

Prueba::caso('el expediente trae la fila decorada y la bitácora, pero no las rutas del disco', function () {
    $m = new SeguimientoGrabadora();
    $m->respuestas['WHERE c.origen = ? AND c.referencia_id = ? LIMIT 1'] =
        Soporte::filaCola(['ruta_xml' => 'Prov/FE-12.xml', 'ruta_pdf' => 'Prov/FE-12.pdf', 'tarea' => 'falta_pdf']);
    $m->respuestas['FROM seguimiento_bitacora b'] = [['id' => '1', 'comentario' => 'Llamé', 'usuario_nombre' => 'Ana']];
    Soporte::$almacen->archivos['Prov/FE-12.xml'] = true;
    $_GET = ['origen' => 'factura', 'referencia_id' => '12', 'modo' => 'sistema'];

    [$estado, $cuerpo] = sonda($m)->responder('detalle');
    igual(200, $estado, 'estado HTTP');
    verificar(!array_key_exists('ruta_xml', $cuerpo['fila']) && !array_key_exists('ruta_pdf', $cuerpo['fila']),
        'las rutas no viajan al navegador');
    igual([true, false, true], [$cuerpo['fila']['xml_ok'], $cuerpo['fila']['pdf_ok'], $cuerpo['fila']['pdf_perdido']],
        'XML presente, PDF registrado pero perdido');
    igual('Llamé', $cuerpo['bitacora'][0]['comentario'], 'la bitácora viene con la fila');
    igual(['factura', 12], $m->de('fetchOne')[0]['params'], 'el id se pidió como entero');
});

Prueba::caso('un expediente que no existe es 422 y no repite lo que se pidió', function () {
    $_GET = ['origen' => '<script>alert(1)</script>', 'referencia_id' => '1 OR 1=1'];
    [$estado, $cuerpo] = sonda()->responder('detalle');
    igual(422, $estado, 'estado HTTP');
    igual('El renglón no existe o ya no está en la cola.', $cuerpo['message'], 'mensaje fijo');
});

// ── decorar(): lo que se le agrega a cada renglón ───────────────────────────

Prueba::caso('archivos: presente, perdido, nunca registrado e histórico', function () {
    Soporte::$almacen->archivos['a.xml'] = true;
    $filas = sonda()->privado('decorar', [
        Soporte::filaCola(['ruta_xml' => 'a.xml', 'ruta_pdf' => 'a.pdf', 'recuperable' => '1']),
        Soporte::filaCola(['ruta_xml' => '', 'ruta_pdf' => null, 'estado_pdf' => 'no_disponible_historico', 'recuperable' => '1']),
        Soporte::filaCola(['ruta_xml' => '   ', 'ruta_pdf' => '']),
    ], 'sistema');
    igual([true, false, false, true, true], [$filas[0]['xml_ok'], $filas[0]['pdf_ok'], $filas[0]['xml_perdido'],
        $filas[0]['pdf_perdido'], $filas[0]['recuperable']], 'XML sí, PDF perdido y recuperable');
    igual([false, false, false, true, false], [$filas[1]['xml_ok'], $filas[1]['xml_perdido'], $filas[1]['pdf_perdido'],
        $filas[1]['pdf_historico'], $filas[1]['recuperable']], 'sin rutas no hay nada perdido ni que recuperar');
    igual([false, false], [$filas[2]['xml_perdido'], $filas[2]['xml_ok']], 'una ruta de puros espacios es no tener ruta');
});

Prueba::caso('vencido solo mientras sigue en revisión y ya pasó la hora', function () {
    $filas = sonda()->privado('decorar', [
        Soporte::filaCola(['recordar_en' => '2000-01-01 00:00:00', 'seguimiento_estado' => 'revision']),
        Soporte::filaCola(['recordar_en' => '2000-01-01 00:00:00', 'seguimiento_estado' => 'cerrada']),
        Soporte::filaCola(['recordar_en' => '2999-01-01 00:00:00', 'seguimiento_estado' => 'revision']),
        Soporte::filaCola(['recordar_en' => null, 'seguimiento_estado' => 'revision']),
    ], 'sistema');
    igual([true, false, false, false], array_column($filas, 'vencido'), 'vencidos');
});

Prueba::caso('desajustada solo cuando hay marca a mano y el cálculo dice otra cosa', function () {
    $filas = sonda()->privado('decorar', [
        Soporte::filaCola(['estado_a_mano' => 'revision', 'estado_calculado' => 'pendiente']),
        Soporte::filaCola(['estado_a_mano' => null, 'estado_calculado' => 'pendiente']),
        Soporte::filaCola(['estado_a_mano' => 'lista', 'estado_calculado' => 'lista']),
    ], 'sistema');
    igual([true, false, false], array_column($filas, 'desajustada'), 'desajustadas');
});

Prueba::caso('en correo el renglón se abre en su ficha y no se busca; en sistema al revés', function () {
    $c = sonda();
    $correo = $c->privado('decorar', [
        Soporte::filaCola(['origen' => 'xml_nota', 'referencia_id' => '12']),
        Soporte::filaCola(['origen' => 'xml_factura', 'referencia_id' => '13']),
    ], 'correo');
    igual(['/notas-xml/ver/12', '/facturas/ver/13'], array_column($correo, 'ver_ruta'), 'ficha según el tipo');
    igual(['', ''], array_column($correo, 'busqueda'), 'nada que buscar');

    $sistema = $c->privado('decorar', [
        Soporte::filaCola(['origen' => 'nota_credito', 'nc_proveedor' => '', 'proveedor' => 'ACME S.A.', 'fecha' => 'no-es-fecha']),
    ], 'sistema');
    igual(['', 'proveedor', ''], [$sistema[0]['ver_ruta'], $sistema[0]['busqueda_por'], $sistema[0]['busqueda_fecha']],
        'una nota sin número se busca por proveedor; una fecha rota no inventa la ventana');
});

Prueba::caso('decorar pregunta al almacén a lo sumo dos veces por renglón', function () {
    $filas = [];
    for ($i = 0; $i < 50; $i++) {
        $filas[] = Soporte::filaCola(['ruta_xml' => "d/{$i}.xml", 'ruta_pdf' => "d/{$i}.pdf"]);
    }
    sonda()->privado('decorar', $filas, 'sistema');
    verificar(Soporte::$almacen->preguntas <= 100, Soporte::$almacen->preguntas . ' preguntas para 50 renglones');
});

Prueba::terminar();
