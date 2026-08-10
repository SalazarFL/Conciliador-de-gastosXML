<?php
/**
 * El lote del modo Descargas debe poder avanzar y cerrarse SIN el navegador.
 *
 * Fija la trampa que dejó al lote #10 cinco días en 'ejecutando': con todos
 * sus correos ya procesados, el lote seguía sin marcarse 'completado' porque
 * nadie volvía a pedir una tanda. También cubre el rescate de los items que
 * quedaron a medias cuando se cerró la pestaña.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoLote.php';

function assertLoteWorker($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: CorreoLoteWorker (sin base de datos disponible)\n";
    exit(0);
}

// correo_lotes tiene FK a correo_cuentas y sociedades: se reutilizan las
// existentes. El lote de prueba nunca llega a conectarse al buzón — todos sus
// casos se resuelven antes de tocar IMAP.
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
$sociedadId = (int) $pdo->query('SELECT MIN(id) FROM sociedades')->fetchColumn();
if ($cuentaId <= 0 || $sociedadId <= 0) {
    echo "SKIP: CorreoLoteWorker (sin cuentas de correo ni sociedades registradas)\n";
    exit(0);
}

$lotes = new CorreoLote();
$correo = new CorreoController();

$loteId = 0;
$limpiar = function () use ($pdo, &$loteId) {
    if ($loteId > 0) {
        $pdo->prepare('DELETE FROM correo_incidencias WHERE lote_id = ?')->execute([$loteId]);
        $pdo->prepare('DELETE FROM correo_lote_items WHERE lote_id = ?')->execute([$loteId]);
        $pdo->prepare('DELETE FROM correo_lotes WHERE id = ?')->execute([$loteId]);
    }
};

try {
    $pdo->prepare(
        "INSERT INTO correo_lotes (cuenta_id, sociedad_id, fecha_desde, fecha_hasta,
                                   carpeta_raiz, carpetas_json, estado, total_mensajes, procesados)
         VALUES (?, ?, '2026-07-01', '2026-07-02', '__test__', '[]', 'ejecutando', 2, 2)"
    )->execute([$cuentaId, $sociedadId]);
    $loteId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare(
        "INSERT INTO correo_lote_items (lote_id, correo_indice_id, carpeta, uidvalidity, uid, estado, iniciado_en)
         VALUES (?, NULL, 'INBOX', 1, ?, ?, ?)"
    );
    $insertItem->execute([$loteId, 9001, 'completado', null]);
    $insertItem->execute([$loteId, 9002, 'completado', null]);

    // Sin pendientes, la tanda no toca IMAP y cierra el lote sola. Antes esto
    // solo ocurría si alguien volvía a abrir el módulo.
    $r = $correo->procesarTandaLote($loteId, 6, 25);
    assertLoteWorker(!empty($r['ok']), 'la tanda responde ok con el lote agotado');
    assertLoteWorker((int) $r['procesados_ahora'] === 0, 'no reporta trabajo donde no lo hay');
    assertLoteWorker(($r['lote']['estado'] ?? '') === 'completado',
        'un lote sin pendientes se marca completado sin el navegador abierto');

    // Devuelve datos, no JSON: es lo que permite encadenar tandas desde cli/.
    assertLoteWorker(is_array($r) && array_key_exists('incidencias', $r),
        'la tanda devuelve un array en vez de emitir la respuesta HTTP');

    // Pestaña cerrada a media tanda: el item queda 'procesando'. Pasados los
    // 10 minutos debe volver a la cola en vez de perderse.
    $pdo->prepare("UPDATE correo_lotes SET estado = 'ejecutando', terminado_en = NULL WHERE id = ?")
        ->execute([$loteId]);
    $pdo->prepare(
        "UPDATE correo_lote_items
            SET estado = 'procesando', iniciado_en = DATE_SUB(NOW(), INTERVAL 30 MINUTE)
          WHERE lote_id = ? AND uid = 9002"
    )->execute([$loteId]);

    $tomados = $lotes->tomarPendientes($loteId, 6);
    assertLoteWorker(count($tomados) === 1, 'rescata el item que quedó a medias de una corrida interrumpida');
    assertLoteWorker((int) $tomados[0]['uid'] === 9002, 'rescata exactamente el item interrumpido');

    // Un item tomado hace un momento sigue siendo de quien lo tomó: dos
    // corridas simultáneas no pueden procesar el mismo correo dos veces.
    assertLoteWorker(count($lotes->tomarPendientes($loteId, 6)) === 0,
        'no vuelve a entregar un item recién tomado');

    // Pausado desde la interfaz: el worker debe respetarlo y no tomar nada.
    $pdo->prepare("UPDATE correo_lote_items SET estado = 'pendiente', iniciado_en = NULL WHERE lote_id = ?")
        ->execute([$loteId]);
    $lotes->cambiarEstado($loteId, 'pausado');
    $r = $correo->procesarTandaLote($loteId, 6, 25);
    assertLoteWorker(!empty($r['ok']) && (int) $r['procesados_ahora'] === 0,
        'un lote pausado no avanza aunque el worker lo visite');
    assertLoteWorker(($r['lote']['estado'] ?? '') === 'pausado', 'el worker no reanuda lo que el usuario pausó');

    $inexistente = $correo->procesarTandaLote(0, 6, 25);
    assertLoteWorker(empty($inexistente['ok']) && (int) $inexistente['status'] === 404,
        'informa 404 cuando el lote no existe');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: el lote del modo Descargas avanza y cierra sin el navegador\n";
