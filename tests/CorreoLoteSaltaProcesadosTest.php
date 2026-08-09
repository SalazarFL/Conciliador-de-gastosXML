<?php
/**
 * Un lote nuevo no debe volver a bajar por IMAP los correos que ya se
 * procesaron en una corrida anterior.
 *
 * El modo Descargas escribe una marca por correo en correo_procesados
 * ("c{cuenta}:{uidvalidity}:{uid}") al terminar cada uno, pero nadie la
 * consultaba al armar el lote: rangos traslapados repetían el trabajo
 * completo — 1296 de los 2490 correos del lote #10. Descartar de la bandeja
 * quita la marca, así que reprocesar a propósito debe seguir siendo posible,
 * igual que marcar "volver a revisar todo".
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoLote.php';
require_once __DIR__ . '/../app/models/CorreoProcesado.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';

function assertSaltaProcesados($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: CorreoLoteSaltaProcesados (sin base de datos disponible)\n";
    exit(0);
}

$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
$sociedadId = (int) $pdo->query('SELECT MIN(id) FROM sociedades')->fetchColumn();
if ($cuentaId <= 0 || $sociedadId <= 0) {
    echo "SKIP: CorreoLoteSaltaProcesados (sin cuentas de correo ni sociedades registradas)\n";
    exit(0);
}

// Fuera de cualquier rango real para no cruzarse con los datos del cliente.
$uidValidity = 987654321;
$uids = [770001, 770002, 770003];
$desde = '2019-03-01';
$hasta = '2019-03-02';
$ts = strtotime('2019-03-01 10:00:00');
$claveDe = function ($uid) use ($cuentaId, $uidValidity) {
    return 'c' . $cuentaId . ':' . $uidValidity . ':' . $uid;
};

$lotesCreados = [];
$limpiar = function () use ($pdo, &$lotesCreados, $cuentaId, $uidValidity, $uids, $claveDe) {
    foreach ($lotesCreados as $id) {
        $pdo->prepare('DELETE FROM correo_lote_items WHERE lote_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM correo_lotes WHERE id = ?')->execute([$id]);
    }
    $pdo->prepare('DELETE FROM correo_indice WHERE cuenta_id = ? AND uidvalidity = ?')
        ->execute([$cuentaId, $uidValidity]);
    foreach ($uids as $uid) {
        $pdo->prepare('DELETE FROM correo_procesados WHERE clave = ?')->execute([$claveDe($uid)]);
    }
};

$limpiar();
$lotes = new CorreoLote();
new CorreoProcesado(); // garantiza correo_procesados

$crear = function ($incluirProcesados) use ($lotes, $cuentaId, $sociedadId, $desde, $hasta, &$lotesCreados) {
    $lote = $lotes->crear([
        'cuenta_id' => $cuentaId,
        'sociedad_id' => $sociedadId,
        'fecha_desde' => $desde,
        'fecha_hasta' => $hasta,
        'carpeta_raiz' => '__test__',
        'incluir_procesados' => $incluirProcesados,
        'carpetas' => [],
    ]);
    $lotesCreados[] = (int) $lote['id'];
    return (int) $lote['total_mensajes'];
};

try {
    $insertIndice = $pdo->prepare(
        "INSERT INTO correo_indice (cuenta_id, carpeta, carpeta_nombre, uidvalidity, uid,
                                    asunto, remitente, fecha, timestamp, adjuntos)
         VALUES (?, 'INBOX', 'Entrada', ?, ?, ?, 'proveedor@test.local', ?, ?, 'factura.xml')"
    );
    foreach ($uids as $i => $uid) {
        $insertIndice->execute([
            $cuentaId, $uidValidity, $uid,
            '__test__ comprobante ' . $uid, date('Y-m-d H:i:s', $ts), $ts,
        ]);
    }

    $conteo = $lotes->estimar($cuentaId, $desde, $hasta);
    assertSaltaProcesados($conteo['total'] === 3, 've los tres correos del rango');
    assertSaltaProcesados($conteo['procesados'] === 0, 'ninguno viene marcado todavía');
    assertSaltaProcesados($conteo['nuevos'] === 3, 'los tres son nuevos la primera vez');
    assertSaltaProcesados($crear(false) === 3, 'el primer lote se lleva los tres');

    // Dos de ellos terminan de procesarse.
    $marcas = new CorreoProcesado();
    $marcas->marcar($claveDe($uids[0]));
    $marcas->marcar($claveDe($uids[1]));

    $conteo = $lotes->estimar($cuentaId, $desde, $hasta);
    assertSaltaProcesados($conteo['total'] === 3, 'el total del rango no cambia');
    assertSaltaProcesados($conteo['procesados'] === 2, 'reconoce los dos ya procesados');
    assertSaltaProcesados($conteo['nuevos'] === 1, 'solo queda uno nuevo por revisar');

    assertSaltaProcesados($crear(false) === 1,
        'el lote siguiente solo se lleva el correo que nadie ha procesado');

    // La casilla "volver a revisar todo" los recupera.
    assertSaltaProcesados($crear(true) === 3,
        'con incluir_procesados se vuelven a revisar los tres');

    // Descartar de la bandeja quita la marca: el correo vuelve a estar disponible.
    MailFetcher::desmarcarProcesados([$claveDe($uids[0])]);
    $conteo = $lotes->estimar($cuentaId, $desde, $hasta);
    assertSaltaProcesados($conteo['nuevos'] === 2, 'desmarcar devuelve el correo a la cola');
    assertSaltaProcesados($crear(false) === 2, 'y el lote siguiente lo vuelve a tomar');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: el lote salta los correos ya procesados\n";
