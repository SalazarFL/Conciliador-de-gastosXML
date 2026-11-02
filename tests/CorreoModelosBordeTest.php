<?php
/**
 * Los modelos de Correo en sus bordes: qué SQL arman y con qué parámetros
 * cuando les llega lo normal, lo vacío, lo nulo y lo desmesurado.
 *
 * La PDO es falsa (tests/soporte/CorreoSoporte.php): se revisa lo que se le
 * habría mandado a la base, no lo que la base habría contestado.
 *
 * Uso: php tests/CorreoModelosBordeTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo: modelos en sus bordes');

function indice(int $cuenta = 3): CorreoIndice
{
    return (new CorreoIndice())->setCuenta($cuenta);
}

// ── CorreoIndice::buscar ───────────────────────────────────────────────────

Prueba::caso('buscar: cada término es un grupo de LIKE parametrizado, acotado a la cuenta', function () {
    $r = indice()->buscar('factura 12345', 'asunto_remitente', 0);
    igual(['total' => 0, 'correos' => []], $r, 'sin filas');
    $select = Soporte::$pdo->de('SELECT uid, clave')[0];
    igual(3, $select['params'][0], 'el primer parámetro es la cuenta');
    igual(11, count($select['params']), 'cuenta + 5 LIKE por término');
    igual(substr_count($select['sql'], '?'), count($select['params']), 'marcadores = parámetros');
    igual('%factura%', $select['params'][1], 'substring');
});

Prueba::caso('buscar: %, _ y \\ viajan como texto, no como comodines', function () {
    indice()->buscar('50%_A\\', 'asunto', 0);
    igual('%50\\%\\_A\\\\%', Soporte::$pdo->de('SELECT uid, clave')[0]['params'][1], 'escapados');
});

Prueba::caso('buscar: límite y desplazamiento se acotan a enteros sanos', function () {
    $i = indice();
    $i->buscar('', 'asunto', 0, 0, '', '', -40);
    $i->buscar('', 'asunto', 0, 999999, '', '', '12abc');
    $i->buscar('', 'asunto', 0, '1; DROP TABLE correo_indice', '', '', '0 UNION SELECT 1');
    $sql = array_column(Soporte::$pdo->de('SELECT uid, clave'), 'sql');
    verificar(strpos($sql[0], 'LIMIT 1 OFFSET 0') !== false, 'límite 0 → 1, desplazamiento negativo → 0');
    verificar(strpos($sql[1], 'LIMIT 1000 OFFSET 12') !== false, 'límite enorme → 1000');
    verificar(strpos($sql[2], 'LIMIT 1 OFFSET 0') !== false, 'basura → enteros');
});

Prueba::caso('buscar: texto vacío o solo espacios no agrega condiciones de texto', function () {
    indice()->buscar(" \t\n ", 'todo', 0);
    igual([3], Soporte::$pdo->de('SELECT COUNT(*)')[0]['params'], 'solo la cuenta');
});

Prueba::caso('buscar: rango de fechas válido manda sobre el mes; un solo extremo no filtra', function () {
    $i = indice();
    $i->buscar('', 'asunto', 0, 50, '2026-01', '', 0, '2026-09-01', '2026-09-15');
    $i->buscar('', 'asunto', 0, 50, '', '', 0, '2026-09-01', '');
    $i->buscar('', 'asunto', 0, 50, '2026-13', '', 0);
    $conteos = Soporte::$pdo->de('SELECT COUNT(*)');
    igual([3, strtotime('2026-09-01 00:00:00'), strtotime('2026-09-16 00:00:00')], $conteos[0]['params'],
        'el hasta es inclusivo (hasta el día siguiente exclusivo)');
    igual([3], $conteos[1]['params'], 'un solo extremo se ignora');
    igual([3], $conteos[2]['params'], 'mes 13 se ignora');
});

Prueba::caso('[L2] buscar: una fecha que no existe (30 de febrero) se rechaza en vez de correrse a marzo', function () {
    indice()->buscar('', 'asunto', 0, 50, '', '', 0, '2026-02-30', '2026-02-30');
    igual([3], Soporte::$pdo->de('SELECT COUNT(*)')[0]['params'],
        'antes busca del 2 al 3 de marzo sin decir nada');
});

// ── CorreoIndice::buscarPorNumero y extracción del consecutivo ─────────────

Prueba::caso('buscarPorNumero: menos de 4 o más de 20 dígitos no consulta', function () {
    $i = indice();
    igual(['total' => 0, 'correos' => []], $i->buscarPorNumero('FE-123', 0), '3 dígitos');
    igual(['total' => 0, 'correos' => []], $i->buscarPorNumero(str_repeat('9', 21), 0), '21 dígitos');
    igual(['total' => 0, 'correos' => []], $i->buscarPorNumero(null, 0), 'null');
    igual(0, count(Soporte::$pdo->sentencias), 'ninguna consulta');
});

Prueba::caso('buscarPorNumero: 20 dígitos por consecutivo; corto por número sin ceros', function () {
    $i = indice();
    $i->buscarPorNumero('00100001010000012345', 0);
    $i->buscarPorNumero('FE 0000-12345', 0);
    $i->buscarPorNumero('0000', 0);
    $c = Soporte::$pdo->de('SELECT COUNT(*)');
    verificar(strpos($c[0]['sql'], 'consecutivo = ?') !== false, 'consecutivo completo');
    igual('12345', $c[1]['params'][1], 'los guiones y ceros a la izquierda no importan');
    igual('0', $c[2]['params'][1], '"0000" busca el número 0, no la cadena vacía');
});

Prueba::caso('extraer consecutivo: clave de 50, consecutivo de 20, y corridas más largas', function () {
    $i = indice();
    $clave = '506' . '010926' . '003101123456' . '00100001010000012345' . '1' . '12345678';
    igual(50, strlen($clave), 'la clave de prueba mide 50');
    igual('00100001010000012345', Soporte::privado($i, 'extraerConsecutivo', "FE_{$clave}.xml"), 'desde la clave');
    igual('00100001010000012345', Soporte::privado($i, 'extraerConsecutivo', 'Factura 00100001010000012345'), 'suelto');
    igual(null, Soporte::privado($i, 'extraerConsecutivo', str_repeat('1', 51)), '51 dígitos no es ni clave ni consecutivo');
    igual(null, Soporte::privado($i, 'extraerConsecutivo', null), 'null');
    igual('12345', Soporte::privado($i, 'extraerNumeroCorto', '00100001010000012345'), 'número corto');
    igual('0', Soporte::privado($i, 'extraerNumeroCorto', str_repeat('0', 20)), 'todo ceros');
    igual(null, Soporte::privado($i, 'extraerNumeroCorto', null), 'null');
});

// ── CorreoIndice: escritura ────────────────────────────────────────────────

Prueba::caso('insertarLote: de 200 en 200, sin UID inválidos, 15 parámetros por fila', function () {
    $filas = [];
    for ($uid = 1; $uid <= 450; $uid++) {
        $filas[] = ['uid' => $uid, 'asunto' => 'Factura ' . $uid];
    }
    $filas[10]['uid'] = 0;
    $filas[20]['uid'] = -7;
    unset($filas[30]['uid']);

    $n = indice()->insertarLote('INBOX', 'INBOX', 77, $filas);

    $inserts = Soporte::$pdo->de('INSERT INTO correo_indice');
    igual(3, count($inserts), 'tres sentencias');
    igual(447, $n, 'los tres UID inválidos no cuentan');
    foreach ($inserts as $insert) {
        igual(0, count($insert['params']) % 15, '15 parámetros por fila');
        igual(substr_count($insert['sql'], '?'), count($insert['params']), 'marcadores = parámetros');
    }
});

Prueba::caso('insertarLote: nulos se quedan nulos y los textos largos multibyte se recortan por carácter', function () {
    indice()->insertarLote('INBOX', 'INBOX', 77, [['uid' => 5, 'remitente' => str_repeat('ñ', 300), 'cc' => null]]);
    $p = Soporte::$pdo->de('INSERT INTO correo_indice')[0]['params'];
    igual(255, mb_strlen($p[6], 'UTF-8'), 'remitente a 255 caracteres, no bytes');
    igual(null, $p[7], 'cc null significa "aún no leído": no se convierte en cadena vacía');
    igual('77:5', $p[5], 'la clave por omisión');
    igual(0, $p[14], 'timestamp faltante es 0');
});

Prueba::caso('insertarLote: una lista vacía no escribe', function () {
    igual(0, indice()->insertarLote('INBOX', 'INBOX', 77, []), 'cero');
    igual(0, count(Soporte::$pdo->sentencias), 'sin sentencias');
});

Prueba::caso('podarAntesDe: sin cuenta o sin corte no hace nada; con filas, en una transacción', function () {
    igual(0, indice(0)->podarAntesDe(time(), 10), 'cuenta 0');
    igual(0, indice()->podarAntesDe(0, 10), 'corte 0');
    igual(0, count(Soporte::$pdo->sentencias), 'sin consultas');

    Soporte::$pdo->respuestas['SELECT id, carpeta FROM correo_indice'] = [
        ['id' => 1, 'carpeta' => 'A'], ['id' => 2, 'carpeta' => 'A'], ['id' => 3, 'carpeta' => 'B'],
    ];
    Soporte::$pdo->afectadas['DELETE FROM correo_indice'] = 3;
    igual(3, indice()->podarAntesDe(time(), 999999), 'tres podadas');
    verificar(strpos(Soporte::$pdo->de('SELECT id, carpeta')[0]['sql'], 'LIMIT 5000') !== false, 'tope 5000');
    igual(2, Soporte::$pdo->contar('UPDATE correo_carpetas'), 'un ajuste de omitidos por carpeta');
    verificar(!Soporte::$pdo->inTransaction(), 'la transacción se cierra');
});

Prueba::caso('guardarEstadoCarpeta: mirar no toca ultimo_cambio; encontrar algo sí', function () {
    $i = indice();
    $i->guardarEstadoCarpeta('INBOX', 77, 10, 10, -3, -1, false);
    $i->guardarEstadoCarpeta('INBOX', 77, 11, 11, 0, 0, true);
    $s = Soporte::$pdo->de('INSERT INTO correo_carpetas');
    verificar(strpos($s[0]['sql'], 'ultimo_cambio = ultimo_cambio') !== false, 'sin cambio se conserva');
    verificar(strpos($s[1]['sql'], 'ultimo_cambio = NOW()') !== false, 'con cambio se marca');
    igual([0, 0], [$s[0]['params'][5], $s[0]['params'][6]], 'omitidos y retención negativos se vuelven 0');
});

Prueba::caso('pendientesMetadatos: el tope se acota entre 1 y 1000', function () {
    indice()->pendientesMetadatos(0);
    indice()->pendientesMetadatos(10 ** 9);
    $s = Soporte::$pdo->de('adjuntos_pendiente = 1 OR destinatarios_pendientes = 1');
    verificar(strpos($s[0]['sql'], 'LIMIT 1') !== false, '0 → 1');
    verificar(strpos($s[1]['sql'], 'LIMIT 1000') !== false, 'enorme → 1000');
});

// ── CorreoLote ─────────────────────────────────────────────────────────────

Prueba::caso('lote: rango al revés o fecha ilegible se rechaza antes de escribir', function () {
    $datos = ['cuenta_id' => 3, 'sociedad_id' => 4, 'carpeta_raiz' => 'X'];
    lanza(fn() => (new CorreoLote())->crear($datos + ['fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-01']), 'rango');
    lanza(fn() => (new CorreoLote())->crear($datos + ['fecha_desde' => 'ayer', 'fecha_hasta' => 'hoy?']), 'rango');
    igual(0, Soporte::$pdo->contar('INSERT INTO correo_lotes'), 'nada escrito');
});

Prueba::caso('lote: por omisión salta lo ya procesado por ESA empresa; a pedido lo incluye', function () {
    $datos = ['cuenta_id' => 3, 'sociedad_id' => 4, 'carpeta_raiz' => 'X',
              'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01'];
    (new CorreoLote())->crear($datos);
    (new CorreoLote())->crear($datos + ['incluir_procesados' => true]);
    $items = Soporte::$pdo->de('INSERT IGNORE INTO correo_lote_items');
    verificar(strpos($items[0]['sql'], 'NOT EXISTS (SELECT 1 FROM correo_procesados p') !== false
        && strpos($items[0]['sql'], 'p.sociedad_id = 4') !== false, 'salta los de la empresa 4');
    verificar(strpos($items[1]['sql'], 'correo_procesados') === false, 'incluir_procesados no filtra');
    igual(strtotime('2026-09-02 00:00:00'), $items[0]['params'][3], 'un solo día: hasta el día siguiente');
});

Prueba::caso('[L7] lote: un correo de búsqueda inválido se rechaza ANTES de crear el lote', function () {
    lanza(fn() => (new CorreoLote())->crear(['cuenta_id' => 3, 'sociedad_id' => 4, 'carpeta_raiz' => 'X',
        'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-02', 'correo_busqueda' => 'no-es-correo']), 'no es válido');
    igual(0, Soporte::$pdo->contar('INSERT INTO correo_lotes'), 'antes queda un lote huérfano en "pendiente" con 0 correos');
});

Prueba::caso('estimar: fechas ilegibles devuelven ceros sin consultar; la empresa entra como entero', function () {
    igual(['total' => 0, 'procesados' => 0, 'nuevos' => 0], (new CorreoLote())->estimar(3, 'no-fecha', '31/31/31'), 'ceros');
    igual(0, count(Soporte::$pdo->sentencias), 'sin consulta');
    Soporte::$pdo->respuestas['SELECT COUNT(*) AS total'] = [['total' => '10', 'procesados' => '4']];
    igual(['total' => 10, 'procesados' => 4, 'nuevos' => 6],
        (new CorreoLote())->estimar(3, '2026-09-01', '2026-09-02', '', '4 OR 1=1'), 'cuenta');
    verificar(preg_match('/p\.sociedad_id = 4\s/', Soporte::$pdo->de('SELECT COUNT(*) AS total')[0]['sql']) === 1,
        '"4 OR 1=1" se reduce a 4');
});

Prueba::caso('cambiarEstado: solo pausado, ejecutando o cancelado; cancelar cierra sus items', function () {
    lanza(fn() => (new CorreoLote())->cambiarEstado(50, 'completado'), 'no permitido');
    lanza(fn() => (new CorreoLote())->cambiarEstado(50, ''), 'no permitido');
    (new CorreoLote())->cambiarEstado(50, 'cancelado');
    igual(1, Soporte::$pdo->contar("UPDATE correo_lote_items SET estado = 'cancelado'"), 'los items se cancelan');
});

Prueba::caso('firma de incidencia: estable, distinta por mensaje y tolerante al tipo', function () {
    $a = CorreoLote::firmaIncidencia('receptor', 'INBOX', 77, 5, 'otra cédula');
    igual(40, strlen($a), 'sha1');
    igual($a, CorreoLote::firmaIncidencia('receptor', 'INBOX', '77', '5', 'otra cédula'), 'texto o número da igual');
    verificar($a !== CorreoLote::firmaIncidencia('receptor', 'INBOX', 77, 5, 'otra cédula '), 'otro texto, otra firma');
    igual(40, strlen(CorreoLote::firmaIncidencia(null, null, null, null, null)), 'nulos no revientan');
});

Prueba::caso('incidencia: tipo y mensaje se recortan; un descarte previo la hace nacer descartada', function () {
    Soporte::$pdo->respuestas['FROM correo_incidencias_descartes'] = [['motivo' => 'ya revisado']];
    (new CorreoLote())->incidencia(50, 0, str_repeat('t', 100), str_repeat('m', 5000));
    $p = Soporte::$pdo->de('INSERT INTO correo_incidencias')[0]['params'];
    igual(40, mb_strlen($p[2]), 'tipo a 40');
    igual(1000, mb_strlen($p[3]), 'mensaje a 1000');
    igual(null, $p[1], 'sin item: NULL, no 0');
    igual(1, $p[7], 'nace descartada');
    igual('ya revisado', $p[9], 'con el motivo del descarte');
});

Prueba::caso('historial de incidencias: página y tamaño se acotan', function () {
    Soporte::$pdo->respuestas['SELECT COUNT(*)'] = [['c' => 10]];
    $r = (new CorreoLote())->historialIncidencias(3, ['ver' => 'raro'], 999, 5);
    igual(1, $r['page'], 'la página se acota a la última');
    igual(20, $r['per_page'], 'mínimo 20');
    igual('raro', $r['ver'], 'el modelo devuelve lo pedido…');
    verificar(strpos(Soporte::$pdo->de('SELECT COUNT(*)')[0]['sql'], 'x.descartada = 0') !== false,
        '…pero filtra como "pendientes"');
});

Prueba::caso('[S9] historial de incidencias: % y _ del buscador viajan como texto', function () {
    (new CorreoLote())->historialIncidencias(3, ['q' => '100%_'], 1, 50);
    igual('%100\\%\\_%', Soporte::$pdo->de('SELECT COUNT(*)')[0]['params'][1], 'antes son comodines');
});

// ── CorreoProcesado ────────────────────────────────────────────────────────

Prueba::caso('desmarcar: vacíos y nulos fuera, de 200 en 200, empresa negativa es 0', function () {
    $m = new CorreoProcesado();
    igual(0, $m->desmarcar([null, '', false]), 'nada útil');
    igual(0, count(Soporte::$pdo->sentencias), 'sin consultas');

    $claves = array_map(fn($i) => "c3:77:{$i}", range(1, 450));
    Soporte::$pdo->afectadas['DELETE FROM correo_procesados'] = 1;
    $m->desmarcar($claves, -9);
    $deletes = Soporte::$pdo->de('DELETE FROM correo_procesados');
    igual(3, count($deletes), 'tres tandas');
    igual(0, $deletes[0]['params'][0], 'empresa negativa → 0');
    igual(201, count($deletes[0]['params']), 'empresa + 200 claves');
});

// ── CorreoCapturaAutomatica ────────────────────────────────────────────────

Prueba::caso('registrarNuevos: sin cuenta, UID inválido o fecha anterior no entran', function () {
    $m = new CorreoCapturaAutomatica();
    igual(0, $m->registrarNuevos(0, 'INBOX', 77, [['uid' => 1, 'timestamp' => time()]]), 'cuenta 0');
    igual(0, count(Soporte::$pdo->sentencias), 'sin escritura');

    Soporte::$pdo->afectadas['INSERT IGNORE INTO correo_capturas_auto'] = 1;
    $m->registrarNuevos(3, str_repeat('C', 300), 77, [
        ['uid' => 0, 'timestamp' => time()], ['uid' => 9, 'timestamp' => time()], ['uid' => 10],
    ], 'esto no es una fecha');
    $p = Soporte::$pdo->de('INSERT IGNORE INTO correo_capturas_auto')[0]['params'];
    igual(12, count($p), 'dos filas (uid 0 fuera); una fecha ilegible no filtra');
    igual(255, mb_strlen($p[1]), 'carpeta recortada');
    igual(null, $p[11], 'sin timestamp: fecha NULL');
});

Prueba::caso('finalizar: estado inventado es error, con reintento escalonado entre 5 y 60 minutos', function () {
    $m = new CorreoCapturaAutomatica();
    Soporte::$pdo->respuestas['SELECT intentos'] = [['intentos' => 0]];
    $antes = time();
    $m->finalizar(1, 'hackeado', str_repeat(' ', 5));
    $p = Soporte::$pdo->de('UPDATE correo_capturas_auto')[0]['params'];
    igual('error', $p[0], 'estado');
    igual(null, $p[1], 'detalle en blanco → NULL');
    verificar(abs(strtotime($p[3]) - ($antes + 300)) <= 2, 'primer reintento a 5 min');

    Soporte::$pdo->reiniciar();
    Soporte::$pdo->respuestas['SELECT intentos'] = [['intentos' => 200]];
    $m->finalizar(1, 'error');
    verificar(abs(strtotime(Soporte::$pdo->de('UPDATE correo_capturas_auto')[0]['params'][3]) - (time() + 3600)) <= 2,
        'nunca más de una hora');

    Soporte::$pdo->reiniciar();
    $m->finalizar(1, 'capturado', 'ok', -4);
    $p = Soporte::$pdo->de('UPDATE correo_capturas_auto')[0]['params'];
    igual([0, null], [$p[2], $p[3]], 'documentos negativos → 0; sin reintento');
});

Prueba::caso('tomarPendientes de captura: tope 1..100, FOR UPDATE y la transacción se cierra', function () {
    $m = new CorreoCapturaAutomatica();
    $m->tomarPendientes(3, 0, 0);
    verificar(strpos(Soporte::$pdo->de('FOR UPDATE')[0]['sql'], 'LIMIT 1 FOR UPDATE') !== false, 'tope mínimo');
    igual(0, Soporte::$pdo->contar("SET estado = 'procesando'"), 'sin filas no marca nada');

    Soporte::$pdo->reiniciar();
    Soporte::$pdo->respuestas['FOR UPDATE'] = [['id' => '8', 'intentos' => '2', 'estado' => 'error']];
    $filas = $m->tomarPendientes(3, 5000, 99);
    verificar(strpos(Soporte::$pdo->de('FOR UPDATE')[0]['sql'], 'LIMIT 100 FOR UPDATE') !== false, 'tope máximo');
    igual(10, Soporte::$pdo->de('FOR UPDATE')[0]['params'][1], 'intentos máximos a 10');
    igual([3, 'procesando'], [$filas[0]['intentos'], $filas[0]['estado']], 'la fila devuelta ya refleja la toma');
    verificar(!Soporte::$pdo->inTransaction(), 'commit');
});

// ── CorreoCuenta ───────────────────────────────────────────────────────────

Prueba::caso('configPara: cuenta inexistente es null; la contraseña cifrada se descifra', function () {
    igual(null, (new CorreoCuenta())->configPara(999), 'no existe');
    Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [[
        'id' => '3', 'host' => 'mail.x', 'puerto' => '993', 'usuario' => 'a@x',
        'password' => Secreto::cifrar('clavé'),
        'carpeta' => 'INBOX', 'dias_atras' => '0', 'indice_retencion_dias' => null, 'solo_no_leidos' => '0',
    ]];
    $c = (new CorreoCuenta())->configPara('3');
    igual('clavé', $c['password'], 'descifrada');
    igual(1825, $c['indice_retencion_dias'], 'retención nula → 5 años');
    igual(4, $c['sociedad_id'], 'la empresa en curso');
});

/*
 * Antes esto era al revés: una contraseña en base64 se decodificaba y la
 * cuenta funcionaba. Era la rampa para las cuentas anteriores al cifrado, y su
 * precio era que el camino de lectura de todos los días aceptaba para siempre
 * contraseñas sin cifrar —legibles desde la tabla, desde un respaldo o desde un
 * app.log— sin que nada avisara. Se cortó cuando ya no quedaba ninguna fila
 * así; migrarlas es cosa de cli/cifrar_claves_correo.php, que es el único que
 * sigue sabiendo leer el formato viejo.
 */
Prueba::caso('configPara: una contraseña sin cifrar se vuelve vacía (y la cuenta deja de estar configurada)', function () {
    foreach ([
        'base64 (el formato viejo)' => base64_encode('clavé'),
        'texto plano'               => 'clave plana!',
    ] as $caso => $guardada) {
        Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [[
            'id' => '3', 'host' => 'mail.x', 'puerto' => '993', 'usuario' => 'a@x', 'password' => $guardada,
            'carpeta' => 'INBOX', 'dias_atras' => '0', 'indice_retencion_dias' => '0', 'solo_no_leidos' => '0',
        ]];
        $c = (new CorreoCuenta())->configPara(3);
        igual('', $c['password'], "no se lee: {$caso}");
        verificar(!MailFetcher::configurado($c), "sale como \"sin credenciales completas\": {$caso}");
    }
});

Prueba::caso('crear cuenta: retención acotada, carpeta vacía es INBOX, empresa en curso por omisión', function () {
    (new CorreoCuenta())->crear(['nombre' => 'N', 'host' => 'h', 'usuario' => 'u', 'password' => 'p',
        'carpeta' => '   ', 'indice_retencion_dias' => 99999, 'dias_atras' => -3]);
    $p = Soporte::$pdo->de('INSERT INTO correo_cuentas')[0]['params'];
    igual(['INBOX', 0, 3650], [$p[5], $p[6], $p[7]], 'carpeta, días atrás y retención');
    igual([1, 4], Soporte::$pdo->de('INSERT IGNORE INTO correo_cuenta_sociedades')[0]['params'], 'ligada a la empresa 4');
});

Prueba::caso('[L8] crear cuenta: un puerto vacío o 0 usa el 993, no el 1', function () {
    (new CorreoCuenta())->crear(['nombre' => 'N', 'host' => 'h', 'usuario' => 'u', 'password' => 'p', 'puerto' => '']);
    igual(993, Soporte::$pdo->de('INSERT INTO correo_cuentas')[0]['params'][2], 'antes guarda el puerto 1');
});

// ── CorreoBandeja ──────────────────────────────────────────────────────────

Prueba::caso('getByIds: solo enteros positivos; lista vacía no consulta', function () {
    $b = new CorreoBandeja();
    igual([], $b->getByIds([]), 'vacía');
    igual([], $b->getByIds(['abc', 0, -3, null]), 'nada válido');
    igual(0, count(Soporte::$pdo->sentencias), 'sin consultas');
    $b->getByIds(['1', '2.9', 'x', 1], 'pendiente');
    igual([1, 2, 1, 'pendiente'], Soporte::$pdo->sentencias[0]['params'], 'enteros y el estado al final');
});

Prueba::caso('fila activa por id: acotada a la empresa en curso y solo lo que se ve en la bandeja', function () {
    igual(null, (new CorreoBandeja())->getActivaPorId('7; DROP'), 'sin fila es null, no false');
    $s = Soporte::$pdo->de('FROM correo_bandeja')[0];
    igual([7, 4], $s['params'], 'el id como entero y la empresa 4');
    verificar(strpos($s['sql'], "estado IN ('pendiente', 'rechazada', 'otra_cedula')") !== false,
        'una importada o descartada no abre ningún correo');
});

// ── CorreoIndice::ubicarMensaje (el correo de una fila de la bandeja) ──────

Prueba::caso('ubicar mensaje: una sola fila del índice dice la carpeta, acotada a la cuenta', function () {
    Soporte::$pdo->respuestas['FROM correo_indice'] = [['carpeta' => 'INBOX.Facturas', 'asunto' => 'FE 1']];
    igual('INBOX.Facturas', indice()->ubicarMensaje('77', '5'), 'la carpeta');
    igual([3, 77, 5], Soporte::$pdo->de('FROM correo_indice')[0]['params'], 'cuenta, numeración y UID');
});

Prueba::caso('ubicar mensaje: misma numeración en dos carpetas se decide por el asunto, o no se decide', function () {
    Soporte::$pdo->respuestas['FROM correo_indice'] = [
        ['carpeta' => 'INBOX', 'asunto' => 'Boletín'],
        ['carpeta' => 'INBOX.Facturas', 'asunto' => 'Factura 123'],
    ];
    igual('INBOX.Facturas', indice()->ubicarMensaje(77, 5, ' Factura 123 '), 'el del mismo asunto');
    igual(null, indice()->ubicarMensaje(77, 5, 'Otro asunto'), 'ninguno coincide');
    igual(null, indice()->ubicarMensaje(77, 5), 'sin asunto no se adivina: abrir otro correo es peor');
});

Prueba::caso('ubicar mensaje: fuera del índice vale la carpeta de esa numeración solo si es una', function () {
    Soporte::$pdo->respuestas['FROM correo_carpetas'] = [['carpeta' => 'INBOX']];
    igual('INBOX', indice()->ubicarMensaje(77, 5), 'la única carpeta con esa numeración');
    igual([3, 77], Soporte::$pdo->de('FROM correo_carpetas')[0]['params'], 'cuenta y numeración');

    Soporte::$pdo->respuestas['FROM correo_carpetas'] = [['carpeta' => 'INBOX'], ['carpeta' => 'INBOX.Viejo']];
    igual(null, indice()->ubicarMensaje(77, 5), 'dos carpetas: no se sabe');
    Soporte::$pdo->respuestas = [];
    igual(null, indice()->ubicarMensaje(77, 5), 'ninguna: no se sabe');
});

Prueba::caso('crear en bandeja: origen desconocido es manual; sin empresa usa la de la sesión', function () {
    (new CorreoBandeja())->crear(['uid_correo' => 'c3:77:5', 'origen' => "automatica' OR 1=1", 'total' => 'no numérico']);
    $p = Soporte::$pdo->de('INSERT INTO correo_bandeja')[0]['params'];
    igual(4, $p[1], 'empresa 4');
    igual('manual', $p[15], 'origen');
    igual(0.0, $p[12], 'un total no numérico es 0.0, no un error');
});

Prueba::caso('[M4] bandeja activa: el listado tiene tope (lo rechazado se acumula para siempre)', function () {
    (new CorreoBandeja())->getActivas();
    verificar(strpos(Soporte::$pdo->de('FROM correo_bandeja')[0]['sql'], 'LIMIT') !== false,
        'antes trae todas las filas pendientes, rechazadas y de otra cédula');
});

Prueba::terminar();
