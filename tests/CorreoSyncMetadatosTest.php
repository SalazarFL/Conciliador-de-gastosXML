<?php
/**
 * La cola de metadatos avanza aunque queden carpetas por revisar.
 *
 * En un buzón con 150 carpetas, la vuelta completa (un STATUS por carpeta)
 * no cabe en una tanda. Cuando la fase de metadatos solo corría al terminar
 * esa vuelta, la cola se quedaba parada días enteros y el navegador seguía
 * pidiendo tandas sin fin: "actualizando índice" no terminaba nunca.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';
require_once __DIR__ . '/../app/helpers/CorreoSync.php';

function assertSync($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Buzón de mentira: muchas carpetas y cada consulta cuesta tiempo. */
class BuzonLento extends MailFetcher
{
    private $lista;

    public function __construct(array $lista)
    {
        parent::__construct(['host' => 'test.invalid', 'carpeta' => 'INBOX']);
        $this->lista = $lista;
    }

    public function conectar() { return true; }
    public function estaConectado() { return true; }
    public function cerrar() {}
    public function carpetasABuscar() { return $this->lista; }
    public function nombreLegibleCarpeta($carpeta) { return (string) $carpeta; }

    public function estadoCarpeta($carpeta)
    {
        usleep(60000); // lo que cuesta un viaje al servidor de correo
        return ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0];
    }

    public function overviewCarpeta($carpeta, $rangoUid) { return []; }
    public function adjuntosDeMensaje($uid, $carpeta) { return 'factura.xml'; }
    public function destinatariosDeMensaje($uid, $carpeta)
    {
        return ['cc' => 'copia@test.invalid', 'reply_to' => 'responder@test.invalid'];
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
    echo "SKIP: CorreoSyncMetadatos (sin base de datos disponible)\n";
    exit(0);
}

new CorreoCuenta();
new CorreoIndice();
$sufijo = str_replace('.', '', uniqid('', true));
$carpetaCola = '__cola_' . $sufijo;
$cuentaId = 0;

$limpiar = function () use ($pdo, &$cuentaId) {
    if ($cuentaId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM correo_indice WHERE cuenta_id = ?')->execute([$cuentaId]);
    $pdo->prepare('DELETE FROM correo_carpetas WHERE cuenta_id = ?')->execute([$cuentaId]);
    $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([$cuentaId]);
};

try {
    $pdo->prepare(
        "INSERT INTO correo_cuentas
            (nombre, host, puerto, usuario, password, carpeta, dias_atras,
             indice_retencion_dias, solo_no_leidos)
         VALUES (?, 'test.invalid', 993, ?, '', 'INBOX', 0, 0, 0)"
    )->execute(['__test_sync_' . $sufijo, 'sync_' . $sufijo . '@test.invalid']);
    $cuentaId = (int) $pdo->lastInsertId();
    // También al salir por una aserción fallida: nada de prueba se queda en la base.
    register_shutdown_function($limpiar);

    // Correos esperando adjuntos y destinatarios, en una carpeta que no está
    // en la vuelta: la cola es lo único que la fase 2 tiene que resolver.
    $insert = $pdo->prepare(
        "INSERT INTO correo_indice
            (cuenta_id, carpeta, carpeta_nombre, uid, uidvalidity, clave,
             remitente, cc, reply_to, asunto, adjuntos, fecha, timestamp)
         VALUES (?, ?, 'Cola', ?, 77, ?, 'proveedor@test.invalid',
                 NULL, NULL, 'pendiente', NULL, ?, ?)"
    );
    $cuando = strtotime('2026-08-01 10:00:00');
    for ($uid = 1; $uid <= 30; $uid++) {
        $insert->execute([$cuentaId, $carpetaCola, $uid, '77:' . $uid,
                          date('Y-m-d H:i:s', $cuando), $cuando]);
    }

    $carpetas = [];
    for ($i = 1; $i <= 40; $i++) {
        $carpetas[] = '__vuelta_' . $sufijo . '_' . $i;
    }

    $indice = (new CorreoIndice())->setCuenta($cuentaId);
    assertSync($indice->contarPendientesMetadatos() === 30,
        'la cola arranca con los 30 correos incompletos');

    $cfg = ['cuenta_id' => $cuentaId, 'carpeta' => 'INBOX', 'indice_retencion_dias' => 0];
    $stats = CorreoSync::ejecutar($cfg, $indice, 2, new BuzonLento($carpetas));

    assertSync($stats['restantes'] > 0,
        'la vuelta por las carpetas no cabe entera en una tanda de 2 s');
    assertSync($stats['metadatos_resueltos'] > 0,
        'la cola de metadatos avanza aunque queden carpetas por revisar');
    assertSync($stats['completado'] === false,
        'con carpetas y cola pendientes, el que llama tiene que volver');
    assertSync($stats['metadatos_pendientes'] === 30 - $stats['metadatos_resueltos'],
        'los correos completados salen de la cola');

    // Tandas siguientes: termina la vuelta y vacía la cola.
    for ($i = 0; $i < 6 && !$stats['completado']; $i++) {
        $stats = CorreoSync::ejecutar($cfg, $indice, 2, new BuzonLento($carpetas));
    }

    assertSync($stats['completado'] === true,
        'unas cuantas tandas después la sincronización se da por terminada');
    assertSync($indice->contarPendientesMetadatos() === 0,
        'ningún correo se queda pegado en la cola');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: la cola de metadatos avanza aunque queden carpetas por revisar\n";
