<?php
/** La retención poda solo la caché y deja un conteo estable para el sync. */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';

function assertRetencion($condicion, $mensaje)
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
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: CorreoIndiceRetencion (sin base de datos disponible)\n";
    exit(0);
}

new CorreoCuenta();
new CorreoIndice();
$sufijo = str_replace('.', '', uniqid('', true));
$carpeta = '__retencion_' . $sufijo;
$cuentaId = 0;

$limpiar = function () use ($pdo, &$cuentaId, $carpeta) {
    if ($cuentaId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM correo_indice WHERE cuenta_id = ?')->execute([$cuentaId]);
    $pdo->prepare('DELETE FROM correo_carpetas WHERE cuenta_id = ? AND carpeta = ?')->execute([$cuentaId, $carpeta]);
    $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([$cuentaId]);
};

try {
    $pdo->prepare(
        "INSERT INTO correo_cuentas
            (nombre, host, puerto, usuario, password, carpeta, dias_atras,
             indice_retencion_dias, solo_no_leidos)
         VALUES (?, 'test.invalid', 993, ?, '', 'INBOX', 0, 1825, 0)"
    )->execute(['__test_retencion_' . $sufijo, 'retencion_' . $sufijo . '@test.invalid']);
    $cuentaId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO correo_carpetas
            (cuenta_id, carpeta, uidvalidity, ultimo_uid, mensajes,
             mensajes_omitidos, retencion_dias, ultima_sync)
         VALUES (?, ?, 77, 2, 2, 0, 1825, NOW())"
    )->execute([$cuentaId, $carpeta]);

    $insert = $pdo->prepare(
        "INSERT INTO correo_indice
            (cuenta_id, carpeta, carpeta_nombre, uid, uidvalidity, clave,
             remitente, cc, reply_to, asunto, adjuntos, fecha, timestamp)
         VALUES (?, ?, 'Prueba', ?, 77, ?, 'proveedor@test.invalid', '', '', ?, '', ?, ?)"
    );
    $viejo = strtotime('2019-01-02 10:00:00');
    $reciente = strtotime('2026-08-01 10:00:00');
    $insert->execute([$cuentaId, $carpeta, 1, '77:1', 'viejo', date('Y-m-d H:i:s', $viejo), $viejo]);
    $insert->execute([$cuentaId, $carpeta, 2, '77:2', 'reciente', date('Y-m-d H:i:s', $reciente), $reciente]);

    $indice = (new CorreoIndice())->setCuenta($cuentaId);
    assertRetencion($indice->podarAntesDe(strtotime('2021-01-01'), 100) === 1,
        'poda exactamente el encabezado anterior al corte');
    assertRetencion($indice->contarTotal() === 1, 'conserva el encabezado reciente');

    $estado = $indice->getEstadoCarpeta($carpeta);
    assertRetencion((int) $estado['mensajes'] === 2, 'conserva el total informado por IMAP');
    assertRetencion((int) $estado['mensajes_omitidos'] === 1,
        'registra la fila podada para que la sincronización no la restaure');
    assertRetencion($indice->podarAntesDe(strtotime('2021-01-01'), 100) === 0,
        'volver a ejecutar la poda es idempotente');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: la retención poda solo encabezados vencidos\n";
