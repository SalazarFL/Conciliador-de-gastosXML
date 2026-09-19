<?php
/**
 * La escritura de Seguimiento llevada al borde: la selección que llega del
 * navegador, el cambio de estado en tanda, la bitácora, la transacción y los
 * recordatorios de la campana.
 *
 * Lo que se protege: que una casilla rara no toque un renglón que nadie
 * eligió, que la bitácora diga exactamente lo que cambió, que un fallo a mitad
 * no deje nada a medias, y que la campana se entere de lo que tiene que
 * enterarse.
 *
 * Sin base: el modelo graba lo que habría enviado y Notificacion escribe en la
 * PDO falsa del soporte.
 */
require_once __DIR__ . '/soporte/SeguimientoSoporte.php';

Prueba::iniciar('escritura, bitácora y recordatorios de Seguimiento en el borde');

const ANA = ['id' => 7, 'nombre' => 'Ana Prueba'];

/** Los parámetros de la única sentencia que contiene el fragmento. */
function unica(SeguimientoGrabadora $m, string $fragmento): array
{
    $sentencias = $m->de('execute', $fragmento);
    igual(1, count($sentencias), "una sola sentencia con «{$fragmento}»");
    return $sentencias[0];
}

function upsertDe(SeguimientoGrabadora $m): array
{
    return unica($m, "INSERT INTO seguimiento\n");
}

function bitacoraDe(SeguimientoGrabadora $m): array
{
    return $m->de('execute', 'INSERT INTO seguimiento_bitacora');
}

// ── La selección que llega del navegador ───────────────────────────────────

Prueba::caso('el origen con espacios alrededor se acepta; en mayúsculas no', function () {
    igual([['origen' => 'factura', 'referencia_id' => 7]], Seguimiento::normalizarItems([' factura |7']), 'con espacios');
    igual([], Seguimiento::normalizarItems(['FACTURA|7']), 'en mayúsculas');
});

Prueba::caso('espacios y ceros a la izquierda en el id son el mismo número (y se deduplican)', function () {
    igual([['origen' => 'factura', 'referencia_id' => 12]], Seguimiento::normalizarItems(['factura| 12', 'factura|0012']),
        'uno solo');
});

Prueba::caso('una barra de más no se recorta a un id válido', function () {
    igual([], Seguimiento::normalizarItems(['factura|7|8']), 'factura|7|8');
});

Prueba::caso('nulos, vacíos y medias casillas se descartan', function () {
    igual([], Seguimiento::normalizarItems([
        null, '', '|7', 'factura|', ['origen' => null, 'referencia_id' => null],
        ['origen' => 'factura'], ['referencia_id' => 7], [], false, 3.5,
    ]), 'nada de esto es un renglón');
});

Prueba::caso('tipos: 7.0 es 7; true, 0 y negativos no son ids', function () {
    igual([['origen' => 'factura', 'referencia_id' => 7]], Seguimiento::normalizarItems([
        ['origen' => 'factura', 'referencia_id' => 7.0],
        ['origen' => 'factura', 'referencia_id' => true],
        ['origen' => 'factura', 'referencia_id' => 0],
        ['origen' => 'nota_credito', 'referencia_id' => PHP_INT_MIN],
    ]), 'solo el entero positivo');
});

Prueba::caso('el mismo id en orígenes distintos son cuatro renglones, en el orden elegido', function () {
    igual([
        ['origen' => 'xml_nota', 'referencia_id' => 5], ['origen' => 'nota_credito', 'referencia_id' => 5],
        ['origen' => 'xml_factura', 'referencia_id' => 5], ['origen' => 'factura', 'referencia_id' => 5],
    ], Seguimiento::normalizarItems(['xml_nota|5', 'nota_credito|5', 'xml_factura|5', 'factura|5']), 'cuatro');
});

Prueba::caso('cinco mil casillas repetidas son un renglón', function () {
    igual(1, count(Seguimiento::normalizarItems(array_fill(0, 5000, 'factura|9'))), 'deduplicado');
});

// ── aplicar(): validación antes de tocar nada ───────────────────────────────

Prueba::caso('sin renglones válidos no se abre transacción ni se escribe', function () {
    foreach ([[], ['basura', 'factura|0']] as $items) {
        $m = new SeguimientoGrabadora();
        igual(['afectados' => 0], $m->aplicar($items, ['estado' => 'cerrada'], ANA), 'afectados');
        igual([], $m->consultas, 'ninguna consulta');
    }
});

Prueba::caso('un estado inventado se rechaza antes de abrir la transacción', function () {
    foreach (['resuelto', 'REVISION', ' lista', '', 'cerrada;'] as $estado) {
        $m = new SeguimientoGrabadora();
        lanza(fn() => $m->aplicar(['factura|7'], ['estado' => $estado], ANA), 'no válido');
        igual([], $m->consultas, 'nada se consultó con el estado ' . json_encode($estado));
    }
});

Prueba::caso('revisión sin describir el problema, en todas sus formas vacías', function () {
    foreach ([[], ['motivo' => null], ['motivo' => ''], ['motivo' => " \t\n "]] as $extra) {
        $m = new SeguimientoGrabadora();
        lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'revision'] + $extra, ANA), 'describir el problema');
        igual([], $m->consultas, 'nada se escribió');
    }
});

Prueba::caso('en correo no existe la pestaña lista; revisión sí', function () {
    $m = (new SeguimientoGrabadora())->enModo('correo');
    lanza(fn() => $m->aplicar(['xml_factura|5'], ['estado' => 'lista'], ANA), 'no válido');
    $m = (new SeguimientoGrabadora())->enModo('correo')->existentes(['xml_factura|5' => ['id' => 50, 'estado' => null]]);
    igual(['afectados' => 1], $m->aplicar(['xml_factura|5'], ['estado' => 'revision', 'motivo' => 'Sin registro'], ANA), 'revisión');
});

// ── aplicar(): lo que se escribe ────────────────────────────────────────────

Prueba::caso('camino feliz: revisión con motivo, recordatorio y anotación', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $r = $m->aplicar(['factura|7'], [
        'estado' => 'revision', 'responsable' => 'Ana Prueba', 'motivo' => 'No contesta',
        'recordar_en' => '2026-09-20 08:00:00', 'recordar_cada' => 7, 'comentario' => 'Llamé',
    ], ANA);
    igual(['afectados' => 1], $r, 'afectados');

    $upsert = upsertDe($m);
    igual(['factura', 7, 'revision', 'Ana Prueba', '2026-09-20 08:00:00', 7, 'No contesta', 'Ana Prueba'],
        $upsert['params'], 'una sola fila con todo');
    foreach (['estado = VALUES(estado)', 'responsable = VALUES(responsable)', 'recordar_en = VALUES(recordar_en)',
              'avisado_en = NULL', 'motivo = VALUES(motivo)'] as $set) {
        verificar(strpos($upsert['sql'], $set) !== false, "el UPDATE pisa: {$set}");
    }

    $bitacora = bitacoraDe($m);
    igual(1, count($bitacora), 'una anotación');
    igual([70, 7, 'Ana Prueba', 'auto', 'revision', "Problema: No contesta\nLlamé"], $bitacora[0]['params'],
        'la bitácora dice de dónde a dónde, quién, y copia el motivo');

    igual('begin', $m->tipos()[0], 'lo primero es abrir la transacción');
    verificar(in_array('commit', $m->tipos(), true) && !in_array('rollback', $m->tipos(), true), 'y termina en commit');
    igual(0, Soporte::$pdo->contar('UPDATE notificaciones'), 'mandar a revisión no apaga avisos');
});

Prueba::caso('quitar la marca guarda NULL, apaga el recordatorio y se anota como sin marca', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 71, 'estado' => 'cerrada']]);
    $m->aplicar(['factura|7'], ['estado' => Seguimiento::SIN_MARCA], ANA);
    $upsert = upsertDe($m);
    igual([null, null], [$upsert['params'][2], $upsert['params'][4]], 'estado y recordatorio en NULL');
    verificar(strpos($upsert['sql'], 'estado = VALUES(estado)') !== false, 'y sí se pisan');
    igual([71, 7, 'Ana Prueba', 'cerrada', 'auto', null], bitacoraDe($m)[0]['params'], 'cerrada → sin marca');
});

Prueba::caso('una anotación suelta no toca estado, responsable, recordatorio ni motivo', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 72, 'estado' => 'revision']]);
    $m->aplicar(['factura|7'], ['comentario' => 'Se escribió al proveedor'], ANA);
    $upsert = upsertDe($m);
    $update = substr($upsert['sql'], strpos($upsert['sql'], 'ON DUPLICATE KEY UPDATE'));
    igual('ON DUPLICATE KEY UPDATE actualizado_por = VALUES(actualizado_por), actualizado_en = CURRENT_TIMESTAMP',
        trim(preg_replace('/\s+/', ' ', $update)), 'solo se sella quién y cuándo');
    igual([72, 7, 'Ana Prueba', null, null, 'Se escribió al proveedor'], bitacoraDe($m)[0]['params'], 'sin cambio de estado');
});

Prueba::caso('repetir el estado que ya tiene, sin comentario, no ensucia la bitácora', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 73, 'estado' => 'cerrada']]);
    $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA);
    igual([], bitacoraDe($m), 'cerrada → cerrada no se anota');
});

Prueba::caso('más de 200 renglones se parten en tandas sin perder ninguno', function () {
    $mapa = [];
    $items = [];
    for ($i = 1; $i <= 201; $i++) {
        $mapa["factura|{$i}"] = ['id' => $i, 'estado' => 'pendiente'];
        $items[] = "factura|{$i}";
    }
    $m = (new SeguimientoGrabadora())->existentes($mapa);
    igual(['afectados' => 201], $m->aplicar($items, ['estado' => 'cerrada', 'comentario' => 'Pagadas'], ANA), 'afectados');
    igual([1600, 8], array_map(fn($s) => count($s['params']), $m->de('execute', "INSERT INTO seguimiento\n")),
        'dos upserts: 200 y 1');
    igual([1200, 6], array_map(fn($s) => count($s['params']), bitacoraDe($m)), 'dos tandas de bitácora');
    igual(1, count($m->de('fetchAll', 'SELECT origen, referencia_id, estado')), 'los estados previos, en una lectura');
});

Prueba::caso('si falla la bitácora se revierte todo y no se tocan los avisos', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 74, 'estado' => 'revision']]);
    $m->fallarEn = 'INSERT INTO seguimiento_bitacora';
    lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA), 'falla simulada');
    verificar(in_array('rollback', $m->tipos(), true), 'hubo rollback');
    verificar(!in_array('commit', $m->tipos(), true), 'no hubo commit');
    igual(0, Soporte::$pdo->contar('UPDATE notificaciones'), 'la campana no se enteró de un cambio que no ocurrió');
});

Prueba::caso('afectados cuenta renglones distintos, no casillas', function () {
    $m = (new SeguimientoGrabadora())->existentes([
        'factura|7' => ['id' => 1, 'estado' => null], 'factura|8' => ['id' => 2, 'estado' => null],
    ]);
    igual(['afectados' => 2], $m->aplicar(['factura|7', 'factura|7', ' factura|8'], ['comentario' => 'x'], ANA), 'dos');
});

Prueba::caso('sin responsable en el cambio no se borra el que había', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 75, 'estado' => 'revision']]);
    $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA);
    verificar(strpos(upsertDe($m)['sql'], 'responsable = VALUES(responsable)') === false, 'el responsable no se pisa');
});

Prueba::caso('recordatorio en cero se guarda como ninguno', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 76, 'estado' => null]]);
    $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => 'x', 'recordar_en' => null, 'recordar_cada' => 0], ANA);
    igual([null, null], [upsertDe($m)['params'][4], upsertDe($m)['params'][5]], 'momento y frecuencia en NULL');
});

Prueba::caso('sin nombre de usuario: NULL en quién actualizó y en la bitácora, nunca un nombre inventado', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 77, 'estado' => null]]);
    $m->aplicar(['factura|7'], ['comentario' => 'x'], []);
    igual(null, upsertDe($m)['params'][7], 'actualizado_por');
    igual([77, null, null, null, null, 'x'], bitacoraDe($m)[0]['params'], 'bitácora anónima');
});

Prueba::caso('comentar(): vacío se rechaza; con texto es una anotación', function () {
    $m = new SeguimientoGrabadora();
    lanza(fn() => $m->comentar('factura', 7, "  \n ", ANA), 'no puede ir vacío');
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 78, 'estado' => 'lista']]);
    igual(['afectados' => 1], $m->comentar('factura', '7', ' Listo ', ANA), 'anotado');
    igual('Listo', bitacoraDe($m)[0]['params'][5], 'el comentario se guarda sin espacios de sobra');
});

Prueba::caso('salir de revisión apaga el aviso de cada renglón, después del commit', function () {
    $m = (new SeguimientoGrabadora())->existentes([
        'factura|7' => ['id' => 1, 'estado' => 'revision'], 'nota_credito|9' => ['id' => 2, 'estado' => 'revision'],
    ]);
    $m->aplicar(['factura|7', 'nota_credito|9'], ['estado' => 'cerrada'], ANA);
    $cierres = array_filter(Soporte::$pdo->sentencias, fn($s) => strpos($s['sql'], 'UPDATE notificaciones') !== false);
    verificar($cierres !== [], 'se cerraron avisos');
    $firmas = array_merge(...array_map(fn($s) => $s['params'], array_values($cierres)));
    foreach (['seguimiento:factura:7', 'seguimiento:nota_credito:9'] as $firma) {
        verificar(in_array($firma, $firmas, true), "se cerró {$firma}");
    }
    $commit = array_values($m->de('commit'))[0]['n'];
    verificar(min(array_column($cierres, 'n')) > $commit, 'la campana se toca solo después de confirmar');
});

// ── Recordatorios ───────────────────────────────────────────────────────────

Prueba::caso('dos vencidos: título, detalle, empresa y una sola marca de avisado', function () {
    $m = new SeguimientoGrabadora();
    $m->respuestas['s.recordar_en IS NOT NULL'] = [
        ['id' => '5', 'origen' => 'factura', 'referencia_id' => '100', 'motivo' => ' No contesta ', 'responsable' => 'Ana',
         'recordar_en' => '2026-09-01 08:00:00', 'recordar_cada' => '7'],
        ['id' => '6', 'origen' => 'xml_nota', 'referencia_id' => '99', 'motivo' => '', 'responsable' => null,
         'recordar_en' => '2026-09-02 08:00:00', 'recordar_cada' => null],
    ];
    $m->respuestas["'factura' AS origen"] = [
        ['origen' => 'factura', 'referencia_id' => '100', 'documento' => 'FE-100', 'proveedor' => 'ACME', 'sociedad_id' => '4'],
    ];
    igual(2, $m->generarRecordatorios(), 'dos avisos');

    $inserts = array_values(array_filter(Soporte::$pdo->sentencias, fn($s) => strpos($s['sql'], 'INSERT INTO notificaciones') !== false));
    igual(1, count($inserts), 'los dos en una sola escritura');
    // Nueve columnas por aviso, en el orden del INSERT.
    $avisos = array_chunk($inserts[0]['params'], 9);
    igual(2, count($avisos), 'uno por vencido');
    igual([4, 'seguimiento_recordatorio', 'media', 'Recordatorio de seguimiento: FE-100', 'ACME — No contesta (a cargo: Ana)',
           'seguimiento:factura:100', 'seguimiento', 'factura|100'], array_slice($avisos[0], 0, 8), 'con documento');
    igual([0, 'Recordatorio de seguimiento: xml_nota|99', '', 'seguimiento:xml_nota:99'],
        [$avisos[1][0], $avisos[1][3], $avisos[1][4], $avisos[1][5]],
        'sin documento encontrado el título no queda vacío');
    igual([5, 6], unica($m, 'UPDATE seguimiento SET avisado_en')['params'], 'se marcan los dos juntos');
    $candados = array_map(fn($c) => trim($c['sql']), $m->de('fetchColumn'));
    igual(['SELECT GET_LOCK(?, 0)', 'SELECT RELEASE_LOCK(?)'], $candados, 'barre con el candado tomado y lo suelta');
});

Prueba::caso('el límite de recordatorios es un entero positivo', function () {
    foreach ([[0, 'LIMIT 1'], ['abc', 'LIMIT 1'], [500, 'LIMIT 500']] as [$limite, $esperado]) {
        $m = new SeguimientoGrabadora();
        igual(0, $m->generarRecordatorios($limite), 'sin vencidos no hay avisos');
        verificar(substr(trim($m->de('fetchAll')[0]['sql']), -strlen($esperado)) === $esperado, 'límite ' . json_encode($limite));
        igual([], $m->de('execute'), 'sin vencidos no se escribe');
    }
});

Prueba::caso('si la campana falla, igual se marca avisado: no se reintenta en cada carga', function () {
    Soporte::$pdo->responder = function ($sql) {
        if (strpos($sql, 'INSERT INTO notificaciones') !== false) {
            throw new RuntimeException('campana caída');
        }
        return [[], 1];
    };
    $m = new SeguimientoGrabadora();
    $m->respuestas['s.recordar_en IS NOT NULL'] = [
        ['id' => '8', 'origen' => 'factura', 'referencia_id' => '1', 'motivo' => 'x', 'responsable' => null,
         'recordar_en' => '2026-09-01 08:00:00', 'recordar_cada' => '1'],
    ];
    igual(0, $m->generarRecordatorios(), 'ningún aviso dejado');
    igual([8], unica($m, 'UPDATE seguimiento SET avisado_en')['params'], 'pero sí marcado');
});

// ── Defectos cerrados: cada uno fue un DEFECTO ABIERTO de docs/REVISION_SEGUIMIENTO.md ──

Prueba::caso('[L6] un id con decimales, notación científica o fuera de rango no toca otro renglón', function () {
    igual([], Seguimiento::normalizarItems([
        'factura|7.9', 'factura|1e3', 'factura|4294967296', ['origen' => 'factura', 'referencia_id' => 7.5],
        'factura|-3', 'factura|+3', 'factura|0x1A', "factura|1\0", 'factura|١٢', ['origen' => 'factura', 'referencia_id' => INF],
    ]), '7.9 → 7, 1e3 → 1000, y un id que la columna INT UNSIGNED no admite');
    igual([['origen' => 'factura', 'referencia_id' => 4294967295], ['origen' => 'nota_credito', 'referencia_id' => 1]],
        Seguimiento::normalizarItems(['factura|4294967295', ['origen' => 'nota_credito', 'referencia_id' => 1.0]]),
        'el mayor INT UNSIGNED y un 1.0 de JSON sí son ids');
});

Prueba::caso('[L7] una casilla con un arreglo donde va el origen se descarta sin tumbar la tanda', function () {
    igual([['origen' => 'factura', 'referencia_id' => 8]],
        Seguimiento::normalizarItems([['origen' => ['factura'], 'referencia_id' => 7], 'factura|8',
            ['origen' => 'factura', 'referencia_id' => ['7']], ['origen' => new stdClass(), 'referencia_id' => 9]]),
        'la válida sigue');
});

Prueba::caso('[L2] la primera acción sobre un renglón que nadie tocó no rompe fuera de producción', function () {
    $m = (new SeguimientoGrabadora())->existentes([]);
    $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => 'No contesta'], ANA);
    igual('auto', bitacoraDe($m)[0]['params'][3] ?? null, 'se anota como sin marca → revisión');
    igual('revision', bitacoraDe($m)[0]['params'][4] ?? null, 'hacia revisión');
});

Prueba::caso('[L3] una marca que no existe en el modo del renglón no se cuela por el otro modo', function () {
    $m = (new SeguimientoGrabadora())->existentes(['xml_factura|5' => ['id' => 5, 'estado' => null]]);
    lanza(fn() => $m->enModo('sistema')->aplicar(['xml_factura|5', 'factura|1'], ['estado' => 'lista'], ANA), 'no válido');
    igual([], $m->consultas, 'nada se consultó');

    // Lo que sí existe en los dos modos pasa, y SIN_MARCA no es una pestaña.
    $m = (new SeguimientoGrabadora())->existentes(['xml_factura|5' => ['id' => 5, 'estado' => 'revision']]);
    igual(['afectados' => 1], $m->enModo('sistema')->aplicar(['xml_factura|5'], ['estado' => 'cerrada'], ANA), 'cerrada');
    $m = (new SeguimientoGrabadora())->existentes(['xml_factura|5' => ['id' => 5, 'estado' => 'cerrada']]);
    igual(['afectados' => 1], $m->enModo('correo')->aplicar(['xml_factura|5'], ['estado' => Seguimiento::SIN_MARCA], ANA), 'sin marca');
});

Prueba::caso('[L4] un motivo invisible (espacio duro, ancho cero) no cuenta como describir el problema', function () {
    foreach (["\u{00A0}", "\u{200B}\u{2003}", "\u{3000}", "\u{FEFF}\t\u{2028}"] as $motivo) {
        $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
        lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => $motivo], ANA), 'describir el problema');
        igual([], $m->consultas, 'nada se consultó');
    }

    // Un motivo de verdad se guarda sin lo invisible de los bordes, y lo de
    // adentro —una tilde, un salto de línea del comentario— se respeta.
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
    $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => "\u{200B}\u{00A0}Nota sin XML\u{00A0}",
        'comentario' => "\u{3000}Llamé\ny no contestó "], ANA);
    igual('Nota sin XML', upsertDe($m)['params'][6], 'motivo limpio');
    igual("Problema: Nota sin XML\nLlamé\ny no contestó", bitacoraDe($m)[0]['params'][5], 'bitácora limpia');

    // Con bytes que no son UTF-8 no se cae: se limpia como antes.
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
    $m->aplicar(['factura|7'], ['comentario' => " roto \xA0\xFF "], ANA);
    igual("roto \xA0\xFF", bitacoraDe($m)[0]['params'][5], 'texto sin codificación válida');
});

Prueba::caso('[L5] lo que no cabe en la columna se rechaza con un mensaje propio antes de llegar a la base', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
    $e = lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => str_repeat('ñ', 256)], ANA), '256 caracteres');
    verificar($e instanceof InvalidArgumentException, 'es un rechazo para la persona, no un fallo del sistema');
    igual([], $m->consultas, 'motivo de 256 caracteres (VARCHAR 255): nada consultado ni escrito');

    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
    lanza(fn() => $m->aplicar(['factura|7'], ['comentario' => str_repeat('x', 70000)], ANA), 'demasiado larga');
    igual([], $m->consultas, 'anotación de 70 KB (TEXT admite 65 535 bytes): nada escrito');

    // En el borde exacto sí cabe: 255 caracteres de dos bytes.
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => null]]);
    igual(['afectados' => 1], $m->aplicar(['factura|7'], ['estado' => 'revision', 'motivo' => str_repeat('ñ', 255)], ANA),
        '255 caracteres entran');
});

Prueba::caso('[S5] una tanda tiene tope: veinte mil renglones se rechazan sin escribir', function () {
    $mapa = [];
    $items = [];
    for ($i = 1; $i <= 20000; $i++) {
        $mapa["factura|{$i}"] = ['id' => $i, 'estado' => 'pendiente'];
        $items[] = "factura|{$i}";
    }
    $m = (new SeguimientoGrabadora())->existentes($mapa);
    lanza(fn() => $m->aplicar($items, ['estado' => 'cerrada'], ANA), 'como mucho se cambian 2000');
    igual([], $m->consultas, 'ni se consultó ni se escribió');

    $m = (new SeguimientoGrabadora())->existentes(array_slice($mapa, 0, Seguimiento::TOPE_TANDA, true));
    igual(['afectados' => Seguimiento::TOPE_TANDA],
        $m->aplicar(array_slice($items, 0, Seguimiento::TOPE_TANDA), ['estado' => 'cerrada'], ANA), 'justo el tope sí');
});

Prueba::caso('[L11] un recordatorio fuera de revisión no se guarda (quedaría mudo y resucitaría al volver)', function () {
    foreach (['cerrada', 'pendiente', Seguimiento::SIN_MARCA] as $estado) {
        $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => 'revision']]);
        $m->aplicar(['factura|7'], ['estado' => $estado, 'recordar_en' => '2026-09-20 08:00:00', 'recordar_cada' => 7], ANA);
        igual([null, null], array_slice(upsertDe($m)['params'], 4, 2), "recordatorio de un renglón que pasa a {$estado}");
        verificar(strpos(upsertDe($m)['sql'], 'avisado_en = NULL') !== false, 'y se apaga el que tenía');
    }
});

Prueba::caso('[L12] no se puede vaciar el motivo de algo en revisión sin dejar rastro', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => 'revision']]);
    lanza(fn() => $m->aplicar(['factura|7'], ['motivo' => "\u{00A0}"], ANA), 'conservar la descripción');
    igual([], $m->de('execute'), 'nada escrito');
    verificar(in_array('rollback', $m->tipos(), true) && !in_array('commit', $m->tipos(), true), 'la transacción se deshizo');

    // Fuera de revisión el motivo sí se puede vaciar, y cambiarlo por otro también.
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => 'lista']]);
    igual(['afectados' => 1], $m->aplicar(['factura|7'], ['motivo' => ''], ANA), 'fuera de revisión');
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 1, 'estado' => 'revision']]);
    igual(['afectados' => 1], $m->aplicar(['factura|7'], ['motivo' => 'Otro problema'], ANA), 'corregir el motivo');
    igual('Problema: Otro problema', bitacoraDe($m)[0]['params'][5], 'la corrección queda en la bitácora');
});

Prueba::caso('[R4] cerrar los avisos de una tanda de 50 es una sola ida a la base, no 50', function () {
    $mapa = [];
    $items = [];
    for ($i = 1; $i <= 50; $i++) {
        $mapa["factura|{$i}"] = ['id' => $i, 'estado' => 'revision'];
        $items[] = "factura|{$i}";
    }
    (new SeguimientoGrabadora())->existentes($mapa)->aplicar($items, ['estado' => 'cerrada'], ANA);
    $cierres = array_values(array_filter(Soporte::$pdo->sentencias, fn($s) => strpos($s['sql'], 'UPDATE notificaciones') !== false));
    igual(1, count($cierres), 'UPDATE a notificaciones');
    igual(52, count($cierres[0]['params']), 'estado, quién y las 50 firmas');
    verificar(in_array('seguimiento:factura:50', $cierres[0]['params'], true), 'con la firma de cada renglón');
});

Prueba::caso('[R5] cincuenta recordatorios vencidos se dejan en una sola escritura, no en 50', function () {
    $vencidos = [];
    for ($i = 1; $i <= 50; $i++) {
        $vencidos[] = ['id' => (string) $i, 'origen' => 'factura', 'referencia_id' => (string) $i, 'motivo' => 'x',
                       'responsable' => null, 'recordar_en' => '2026-09-01 08:00:00', 'recordar_cada' => '1'];
    }
    $m = new SeguimientoGrabadora();
    $m->respuestas['s.recordar_en IS NOT NULL'] = $vencidos;
    igual(50, $m->generarRecordatorios(), 'cincuenta avisos dejados');
    igual(1, Soporte::$pdo->contar('INSERT INTO notificaciones'), 'INSERT a notificaciones');
});

// ── Lo que trajo el arreglo de los defectos ─────────────────────────────────

Prueba::caso('[S2] acotado a una empresa, solo se escribe lo que está en su cola; lo demás se cuenta como descartado', function () {
    $m = (new SeguimientoGrabadora())->acotadoA(4)
        ->existentes(['factura|7' => ['id' => 70, 'estado' => null], 'xml_nota|3' => ['id' => 30, 'estado' => null]]);
    igual(['afectados' => 2, 'descartados' => 2],
        $m->aplicar(['factura|7', 'factura|999', 'xml_nota|3', 'nota_credito|12'], ['comentario' => 'x'], ANA), 'resultado');
    $pertenencia = $m->de('fetchAll', 'AS en_cola')[0];
    igual([7, 999, 4, 12, 4, 3, 4], $pertenencia['params'], 'ids por origen, cada rama con su empresa');
    verificar(strpos($pertenencia['sql'], 'SELECT MAX(actual.id) FROM notas_credito_listados actual') !== false,
        'las notas, solo del último acumulado, como en la cola');
    verificar(strpos($pertenencia['sql'], "d.tipo_documento = 'NC'") !== false, 'un XML de nota, por su tipo');
    igual(['factura', 7, null, null, null, null, null, 'Ana Prueba', 'xml_nota', 3, null, null, null, null, null, 'Ana Prueba'],
        upsertDe($m)['params'], 'exactamente los dos de la cola');

    // Acotado a 0 (sin empresa en curso) igual exige que el documento exista.
    $m = (new SeguimientoGrabadora())->acotadoA(0);
    lanza(fn() => $m->aplicar(['factura|999'], ['comentario' => 'x'], ANA), 'Ninguno de los renglones');
    igual([], $m->de('execute'), 'nada escrito');
    verificar(strpos($m->de('fetchAll', 'AS en_cola')[0]['sql'], 'sociedad_id = ?') === false, 'sin empresa no se acota por ella');
});

Prueba::caso('[C4] un error que no es de candados no se reintenta; un interbloqueo que se repite se rinde a la tercera', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $m->fallarEn = "INSERT INTO seguimiento\n";
    lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA), 'falla simulada');
    igual(1, count($m->de('begin')), 'un error cualquiera: un solo intento');

    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $m->fallarEn = "INSERT INTO seguimiento\n";
    $m->falla = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
    $m->falla->errorInfo = ['40001', 1213, 'Deadlock found'];
    lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA), 'Deadlock');
    igual(3, count($m->de('begin')), 'tres intentos');
    igual(3, count($m->de('rollback')), 'cada uno deshecho');

    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => null]]);
    $m->fallarEn = "INSERT INTO seguimiento\n";
    $m->falla = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
    $m->falla->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
    lanza(fn() => $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ANA), 'Lock wait');
    igual(2, count($m->de('begin')), 'una espera agotada se reintenta una sola vez');
});

Prueba::caso('[P2] la campana barre como mucho una vez cada cinco minutos por computadora', function () {
    $marca = sys_get_temp_dir() . '/seguimiento_recordatorios_' . getmypid() . '.marca';
    if (is_file($marca)) {
        unlink($marca);
    }
    try {
        $m = new SeguimientoGrabadora();
        $m->generarRecordatoriosSiToca($marca);
        igual(1, count($m->de('fetchAll', 's.recordar_en IS NOT NULL')), 'sin marca: barre');
        verificar(is_file($marca), 'y deja la marca');

        $m = new SeguimientoGrabadora();
        igual(0, $m->generarRecordatoriosSiToca($marca), 'con la marca fresca no deja nada');
        igual([], $m->consultas, 'ni pregunta a la base');

        touch($marca, time() - Seguimiento::RECORDATORIOS_CADA_SEGUNDOS - 1);
        $m = new SeguimientoGrabadora();
        $m->generarRecordatoriosSiToca($marca);
        igual(1, count($m->de('fetchAll', 's.recordar_en IS NOT NULL')), 'pasados cinco minutos vuelve a barrer');
    } finally {
        if (is_file($marca)) {
            unlink($marca);
        }
    }
});

Prueba::caso('[C2] si otro proceso ya está barriendo, este no hace nada', function () {
    $m = new SeguimientoGrabadora();
    $m->respuestas = ['SELECT GET_LOCK(' => 0] + $m->respuestas;
    $m->respuestas['s.recordar_en IS NOT NULL'] = [
        ['id' => '5', 'origen' => 'factura', 'referencia_id' => '100', 'motivo' => 'x', 'responsable' => null,
         'recordar_en' => '2026-09-01 08:00:00', 'recordar_cada' => null],
    ];
    igual(0, $m->generarRecordatorios(), 'ningún aviso');
    igual(['fetchColumn'], $m->tipos(), 'solo pidió el candado: ni leyó vencidos ni soltó lo que no tomó');
});

Prueba::terminar();
