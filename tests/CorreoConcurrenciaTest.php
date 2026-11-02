<?php
/**
 * Correo cuando varios trabajan a la vez.
 *
 * La aplicación corre en la computadora de cada persona contra una misma base,
 * y además hay tareas programadas (sincronizador en varias copias, trabajador
 * de lotes) y el latido del navegador. Los candados de archivo ordenan a los
 * procesos de UNA máquina; entre máquinas solo ordena la base. Estas pruebas
 * miran exactamente esa costura.
 *
 * Los candados entre procesos de una misma máquina ya los prueba
 * CorreoCandadoCuentaTest con procesos de verdad; no se repiten aquí.
 *
 * Uso: php tests/CorreoConcurrenciaTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo con varios trabajando a la vez');

Prueba::caso('captura automática: la toma de la cola es atómica (FOR UPDATE dentro de la transacción)', function () {
    Soporte::$pdo->respuestas['FOR UPDATE'] = [['id' => '8', 'intentos' => '0'], ['id' => '9', 'intentos' => '1']];
    (new CorreoCapturaAutomatica())->tomarPendientes(3, 5, 3);
    $orden = array_map(fn($s) => preg_replace('/\s+/', ' ', substr(trim($s['sql']), 0, 40)), Soporte::$pdo->sentencias);
    $select = array_search(true, array_map(fn($s) => strpos($s['sql'], 'FOR UPDATE') !== false, Soporte::$pdo->sentencias), true);
    $update = array_search(true, array_map(fn($s) => strpos($s['sql'], "SET estado = 'procesando'") !== false, Soporte::$pdo->sentencias), true);
    verificar($select !== false && $update !== false && $select < $update, 'primero se bloquean las filas, después se marcan: ' . implode(' | ', $orden));
    verificar(!Soporte::$pdo->inTransaction(), 'y la transacción se cierra');
});

Prueba::caso('una tanda de lote rescata los correos que quedaron "procesando" más de 10 minutos', function () {
    Soporte::loteEnCurso(1);
    Soporte::$pdo->respuestas["estado = 'procesando' AND iniciado_en <"] = [['id' => '705']];
    (new CorreoLote())->tomarPendientes(50, 3);
    $rescate = Soporte::$pdo->de("SET estado = 'pendiente', iniciado_en = NULL")[0] ?? ['sql' => '', 'params' => []];
    verificar(strpos($rescate['sql'], 'INTERVAL 10 MINUTE') !== false, 'rescate a los 10 minutos');
    // Por clave primaria: sobre el lote entero bloqueaba sus miles de filas y,
    // con varios motores, terminaba en deadlock.
    verificar(strpos($rescate['sql'], 'lote_id') === false && $rescate['params'] === [705],
        'el rescate toca solo los colgados, por id: ' . $rescate['sql']);
});

Prueba::caso('[C1] lotes: dos máquinas que piden tanda a la vez no reciben los mismos correos', function () {
    // El candado lotes.lock es un archivo local: deja un motor por máquina, no
    // uno en toda la oficina. Entre máquinas, la toma depende de la base.
    // Aquí la otra máquina ganó el UPDATE (0 filas afectadas para esta)…
    Soporte::loteEnCurso(3);
    Soporte::$pdo->afectadas["SET estado = 'procesando', intentos"] = 0;
    $tomados = (new CorreoLote())->tomarPendientes(50, 3);
    // …y aun así el SELECT final (WHERE id IN, sin mirar quién los tomó) se los entrega.
    igual(0, count($tomados), 'antes los tres correos se bajan y se importan dos veces');
});

Prueba::caso('[C2] el trabajador de lotes (sin sesión) marca los correos como procesados para la empresa DEL LOTE', function () {
    // cli/procesar_lotes.php arma la conexión con CorreoCuenta::configPara(),
    // que toma la empresa "en curso". Sin sesión, esa es la primera empresa
    // activa, no la del lote.
    Soporte::empresaEnCurso(1);
    $_SESSION = [];
    Soporte::loteEnCurso(1, ['sociedad_id' => '4']);
    $config = (new CorreoCuenta())->configPara(3);
    $buzon = new BuzonFalso($config);

    $r = (new CorreoControllerSonda())->procesarTandaLote(50, 1, 25, $buzon);

    igual(1, $r['procesados_ahora'], 'la tanda procesó el correo');
    $marca = Soporte::$pdo->de('INSERT INTO correo_procesados')[0]['params'] ?? [];
    igual(4, $marca[0] ?? null,
        'antes la marca queda en la empresa 1: la 4 lo vuelve a bajar en su próximo lote y la 1 lo da por visto sin haberlo trabajado');
});

Prueba::caso('el latido del navegador sí marca con la empresa de la sesión, que es la del lote', function () {
    Soporte::empresaEnCurso(4);
    Soporte::loteEnCurso(1, ['sociedad_id' => '4']);
    $buzon = new BuzonFalso((new CorreoCuenta())->configPara(3));
    (new CorreoControllerSonda())->procesarTandaLote(50, 1, 25, $buzon);
    igual(4, Soporte::$pdo->de('INSERT INTO correo_procesados')[0]['params'][0] ?? null, 'empresa 4');
});

Prueba::caso('un lote pausado o cancelado a mitad de camino no se sigue procesando', function () {
    foreach (['pausado', 'cancelado', 'completado'] as $estado) {
        Soporte::limpiarPeticion();
        Soporte::loteEnCurso(2, ['estado' => $estado]);
        $buzon = new BuzonFalso();
        $r = (new CorreoControllerSonda())->procesarTandaLote(50, 2, 25, $buzon);
        igual(0, $r['procesados_ahora'], $estado);
        igual([], $buzon->llamadas, "{$estado}: ni siquiera conecta");
    }
});

Prueba::caso('buzón caído: los correos vuelven a la cola hasta el tercer intento, no se pierden', function () {
    Soporte::loteEnCurso(2);
    Soporte::$pdo->respuestas['FROM correo_lote_items WHERE id IN'] = [
        Soporte::filaItem(701, 101, ['intentos' => '1']), Soporte::filaItem(702, 102, ['intentos' => '3']),
    ];
    $buzon = new class extends BuzonFalso {
        public function estaConectado() { return false; }
        public function conectar() { throw new RuntimeException('No fue posible conectar con el servidor de correo'); }
    };
    $r = (new CorreoControllerSonda())->procesarTandaLote(50, 2, 25, $buzon);
    igual(false, $r['ok'], 'la tanda falla');
    $devueltos = [];
    foreach (Soporte::$pdo->de("SET estado = 'pendiente', iniciado_en = NULL") as $s) {
        if (preg_match('/WHERE\s+id IN/', $s['sql'])) {
            $devueltos = $s['params'];
        }
    }
    igual([701], $devueltos, 'el de 1 intento vuelve a la cola');
    igual(1, Soporte::$pdo->contar('UPDATE correo_lote_items SET estado = ?, documentos_importados'), 'el de 3 intentos se da por perdido');
});

Prueba::caso('[C3] bandeja: importar y descartar la misma fila a la vez no dejan un estado imposible', function () {
    // Ana pulsa Importar y Beto Descartar sobre la misma factura. Ninguna de
    // las dos escrituras mira el estado actual: gana la última, y puede quedar
    // "importada" una fila cuyo XML y PDF ya se borraron y cuyo correo se
    // desmarcó para volver a capturarse.
    $b = new CorreoBandeja();
    $b->marcarImportadas([5], 9);
    $b->marcarDescartadas([5]);
    foreach (Soporte::$pdo->de('UPDATE correo_bandeja') as $s) {
        verificar(preg_match("/WHERE[\\s\\S]*estado\\s*(=|IN)/", $s['sql']) === 1,
            'antes se escribe sin condición de estado: ' . preg_replace('/\s+/', ' ', trim($s['sql'])));
    }
});

Prueba::terminar();
