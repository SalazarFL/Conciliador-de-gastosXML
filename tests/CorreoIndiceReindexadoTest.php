<?php
/**
 * Reindexar una carpeta no puede tirar lo que ya se leyó del buzón.
 *
 * Basta que alguien mueva o borre unos correos para que la carpeta entera se
 * reconstruya. Si eso vacía los nombres de adjuntos y los destinatarios de
 * los mensajes que siguen ahí, la cola vuelve a llenarse con miles de correos
 * y buscar un número de factura deja de contestarse desde el índice: se va a
 * una búsqueda TEXT por IMAP, carpeta por carpeta, que tarda un minuto.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';

function assertReindex($condicion, $mensaje)
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
    echo "SKIP: CorreoIndiceReindexado (sin base de datos disponible)\n";
    exit(0);
}

new CorreoCuenta();
new CorreoIndice();
$sufijo = str_replace('.', '', uniqid('', true));
$carpeta = '__reindex_' . $sufijo;
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
    )->execute(['__test_reindex_' . $sufijo, 'reindex_' . $sufijo . '@test.invalid']);
    $cuentaId = (int) $pdo->lastInsertId();
    register_shutdown_function($limpiar);

    $indice = (new CorreoIndice())->setCuenta($cuentaId);
    $cuando = strtotime('2026-08-01 10:00:00');

    // Tres correos ya completos: el índice les leyó adjuntos y destinatarios.
    $completos = [];
    for ($uid = 1; $uid <= 3; $uid++) {
        $completos[] = [
            'uid' => $uid, 'clave' => '77:' . $uid,
            'remitente' => 'proveedor@test.invalid',
            'cc' => 'copia@test.invalid', 'reply_to' => 'responder@test.invalid',
            'asunto' => 'Factura', 'adjuntos' => 'FE-' . $uid . '.xml',
            'fecha' => date('Y-m-d H:i:s', $cuando), 'timestamp' => $cuando,
        ];
    }
    $indice->insertarLote($carpeta, 'Prueba', 77, $completos);
    assertReindex($indice->contarPendientesMetadatos() === 0,
        'los tres correos arrancan completos');

    // Alguien borró el uid 2 del buzón y llegó el uid 4. La carpeta se
    // reconstruye con los encabezados recién bajados, que NO traen adjuntos
    // ni destinatarios: eso cuesta un viaje aparte por mensaje.
    $overview = [];
    foreach ([1, 3, 4] as $uid) {
        $overview[] = [
            'uid' => $uid, 'clave' => '77:' . $uid,
            'remitente' => 'proveedor@test.invalid',
            'asunto' => 'Factura',
            'fecha' => date('Y-m-d H:i:s', $cuando), 'timestamp' => $cuando,
        ];
    }
    $indice->reemplazarCarpeta($carpeta, 'Prueba', 77, $overview);

    assertReindex($indice->contarCarpeta($carpeta) === 3,
        'la carpeta queda con lo que hay en el buzón');
    assertReindex($indice->contarPendientesMetadatos() === 1,
        'solo el correo nuevo entra a la cola, no la carpeta entera');

    $filas = $pdo->prepare('SELECT uid, adjuntos, cc, reply_to FROM correo_indice
                            WHERE cuenta_id = ? AND carpeta = ? ORDER BY uid');
    $filas->execute([$cuentaId, $carpeta]);
    $porUid = [];
    foreach ($filas->fetchAll() as $fila) {
        $porUid[(int) $fila['uid']] = $fila;
    }
    assertReindex($porUid[1]['adjuntos'] === 'FE-1.xml' && $porUid[3]['adjuntos'] === 'FE-3.xml',
        'los que siguen en el buzón conservan sus adjuntos');
    assertReindex($porUid[1]['cc'] === 'copia@test.invalid'
        && $porUid[1]['reply_to'] === 'responder@test.invalid',
        'y también sus destinatarios');
    assertReindex($porUid[4]['adjuntos'] === null,
        'el correo nuevo sí queda pendiente de leer');
    assertReindex(!isset($porUid[2]), 'el correo borrado del buzón sale del índice');

    // El servidor renumeró la carpeta: los uid viejos ya no identifican nada.
    $indice->reemplazarCarpeta($carpeta, 'Prueba', 88, $overview);
    assertReindex($indice->contarPendientesMetadatos() === 3,
        'con UIDVALIDITY nuevo no se conserva nada: los uid son de otros correos');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "OK: reindexar una carpeta conserva lo que ya se había leído\n";
