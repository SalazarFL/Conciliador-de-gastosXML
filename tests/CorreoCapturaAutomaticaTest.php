<?php
/** La cola automática acepta solo UIDs nuevos, es idempotente y no importa. */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';
require_once __DIR__ . '/../app/models/CorreoCapturaAutomatica.php';

function assertCapturaAuto($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
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
    echo "SKIP: CorreoCapturaAutomatica (sin base de datos disponible)\n";
    exit(0);
}

$sufijo = random_int(100000, 999999);
$cuentaId = 900000000 + $sufijo;
$carpeta = '__captura_auto_' . $sufijo;
$uidvalidity = 700000 + $sufijo;
$uids = [11, 12, 13];

$limpiar = function () use ($pdo, $cuentaId, $carpeta) {
    $pdo->prepare('DELETE FROM correo_capturas_auto WHERE cuenta_id = ?')->execute([$cuentaId]);
    $pdo->prepare('DELETE FROM correo_indice WHERE cuenta_id = ? AND carpeta = ?')->execute([$cuentaId, $carpeta]);
};

try {
    $indice = (new CorreoIndice())->setCuenta($cuentaId);
    $cola = new CorreoCapturaAutomatica();
    $ahora = time();
    $filas = [
        [
            'uid' => $uids[0], 'timestamp' => $ahora - 86400,
            'fecha' => date('Y-m-d H:i:s', $ahora - 86400),
            'clave' => $uidvalidity . ':' . $uids[0],
            'asunto' => 'Histórico', 'adjuntos' => 'factura_vieja.xml',
            'cc' => '', 'reply_to' => '',
        ],
        [
            'uid' => $uids[1], 'timestamp' => $ahora,
            'fecha' => date('Y-m-d H:i:s', $ahora),
            'clave' => $uidvalidity . ':' . $uids[1],
            'asunto' => 'Nuevo sin comprobante', 'adjuntos' => 'cotizacion.pdf logo.png',
            'cc' => '', 'reply_to' => '',
        ],
        [
            'uid' => $uids[2], 'timestamp' => $ahora - 172800,
            'fecha' => date('Y-m-d H:i:s', $ahora - 172800),
            'clave' => $uidvalidity . ':' . $uids[2],
            'asunto' => 'UID incremental con Date antiguo', 'adjuntos' => 'FACTURA.XML factura.pdf',
            'cc' => '', 'reply_to' => '',
        ],
    ];
    $indice->insertarLote($carpeta, 'Prueba captura', $uidvalidity, $filas);

    $registradas = $cola->registrarNuevos(
        $cuentaId,
        $carpeta,
        $uidvalidity,
        [$filas[0], $filas[1]],
        date('Y-m-d H:i:s', $ahora - 60)
    );
    assertCapturaAuto($registradas === 1,
        'la foto inicial excluye mensajes anteriores a la activación');

    $registradasIncrementales = $cola->registrarNuevos(
        $cuentaId,
        $carpeta,
        $uidvalidity,
        [$filas[2]]
    );
    assertCapturaAuto($registradasIncrementales === 1,
        'un UID realmente nuevo se acepta aunque su cabecera Date sea antigua');

    assertCapturaAuto(
        $cola->registrarNuevos($cuentaId, $carpeta, $uidvalidity, [$filas[1], $filas[2]]) === 0,
        'registrar la misma tanda otra vez no duplica la cola'
    );

    assertCapturaAuto($cola->resolverSinDocumentos($cuentaId) === 1,
        'resuelve sin descargar el correo que no anuncia XML ni ZIP');

    $sinDocumentos = $pdo->prepare(
        "SELECT estado FROM correo_capturas_auto WHERE cuenta_id = ? AND uid = ?"
    );
    $sinDocumentos->execute([$cuentaId, $uids[1]]);
    assertCapturaAuto($sinDocumentos->fetchColumn() === 'sin_documentos',
        'la cola conserva el resultado sin_documentos');

    $tomadas = $cola->tomarPendientes($cuentaId, 10, 3);
    assertCapturaAuto(count($tomadas) === 1 && (int) $tomadas[0]['uid'] === $uids[2],
        'solo reclama el mensaje candidato a comprobante');
    assertCapturaAuto((int) $tomadas[0]['intentos'] === 1,
        'cada reclamo incrementa el contador de intentos');

    $cola->finalizar((int) $tomadas[0]['id'], 'capturado', 'En revisión manual.', 2);
    $final = $cola->get((int) $tomadas[0]['id']);
    assertCapturaAuto(($final['estado'] ?? '') === 'capturado',
        'capturado es un estado terminal de la cola');
    assertCapturaAuto((int) ($final['documentos'] ?? 0) === 2,
        'registra cuántos documentos quedaron para revisar');

    $marca = $pdo->prepare('SELECT COUNT(*) FROM correo_procesados WHERE clave = ?');
    $marca->execute(['c' . $cuentaId . ':' . $uidvalidity . ':' . $uids[2]]);
    assertCapturaAuto((int) $marca->fetchColumn() === 0,
        'capturar no marca el correo como importado/procesado');

    $bandeja = $pdo->prepare('SELECT COUNT(*) FROM correo_bandeja WHERE cuenta_id = ?');
    $bandeja->execute([$cuentaId]);
    assertCapturaAuto((int) $bandeja->fetchColumn() === 0,
        'encolar por sí solo no importa ni crea documentos');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: la captura automática encola solo correo nuevo y exige revisión\n";
