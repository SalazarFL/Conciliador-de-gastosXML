<?php
/**
 * La guarda de cédula del emparejador del pago semanal.
 *
 * Lo que protege: el consecutivo de veinte dígitos NO es único a nivel país
 * —lo arma cada emisor empezando en 1— así que dos proveedores pueden
 * compartirlo. Sin guarda, `buscarXml()` se llevaba el primero de la lista y
 * el comprobante correcto, que estaba más abajo, se perdía.
 *
 * Usa códigos y cédulas sintéticos (99xxxxxxx) para no tocar datos reales, y
 * borra lo suyo al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';

function assertGuarda($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
    echo "  ok  {$mensaje}\n";
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "SKIP: sin base de datos (" . $e->getMessage() . ")\n");
    exit(0);
}

$CODIGO_FUERTE = '99000001';   // el mapa lo sabe con muchas confirmaciones
$CODIGO_DEBIL  = '99000002';   // el mapa lo sabe con una sola
$CEDULA_A = '9990000001';
$CEDULA_B = '9990000002';

$limpiar = function () use ($pdo, $CODIGO_FUERTE, $CODIGO_DEBIL, $CEDULA_A, $CEDULA_B) {
    $pdo->exec("DELETE FROM proveedor_codigo_conflictos WHERE proveedor_codigo IN ('{$CODIGO_FUERTE}','{$CODIGO_DEBIL}')");
    $pdo->exec("DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo IN ('{$CODIGO_FUERTE}','{$CODIGO_DEBIL}')");
    $pdo->exec("DELETE FROM notificaciones WHERE firma LIKE 'codigo_proveedor|99%'");
    $pdo->exec("DELETE FROM proveedores WHERE rfc IN ('{$CEDULA_A}','{$CEDULA_B}')");
};
$limpiar();

// Dos proveedores de prueba.
$crearProveedor = function ($rfc, $nombre) use ($pdo) {
    $pdo->prepare('INSERT INTO proveedores (rfc, razon_social, razon_social_normalizada) VALUES (?, ?, ?)')
        ->execute([$rfc, $nombre, strtoupper($nombre)]);
    return (int) $pdo->lastInsertId();
};
$provA = $crearProveedor($CEDULA_A, 'PROVEEDOR PRUEBA A');
$provB = $crearProveedor($CEDULA_B, 'PROVEEDOR PRUEBA B');

$mapa = new ProveedorCodigoErp();

// ── El mapa sabe, y sabe bien ──────────────────────────────────────
$pdo->prepare("INSERT INTO proveedor_codigo_erp
        (proveedor_codigo, proveedor_id, cedula, nombre_erp, veces_confirmado, origen)
     VALUES (?, ?, ?, 'PRUEBA FUERTE', 20, 'cosecha')")
    ->execute([$CODIGO_FUERTE, $provA, $CEDULA_A]);
$pdo->prepare("INSERT INTO proveedor_codigo_erp
        (proveedor_codigo, proveedor_id, cedula, nombre_erp, veces_confirmado, origen)
     VALUES (?, ?, ?, 'PRUEBA DEBIL', 1, 'cosecha')")
    ->execute([$CODIGO_DEBIL, $provA, $CEDULA_A]);
ProveedorCodigoErp::olvidarMapa();

echo "\nVeredictos\n";
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, $provA) === 'propio',
    'el emisor que corresponde al código se acepta');
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, $provB) === 'ajeno',
    'el emisor de otro proveedor se rechaza');
assertGuarda(ProveedorCodigoErp::veredicto('99999999', $provA) === 'desconocido',
    'un código que el mapa no conoce no se bloquea');
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, 0) === 'desconocido',
    'un XML sin emisor no se bloquea');

// ── Un veto sin monto que cuadre es rutina: ni avisa ni cede ───────
echo "\nVeto rutinario (colisión de consecutivo)\n";
$mapa->registrarVeto([
    'codigo' => $CODIGO_FUERTE, 'proveedor_id_propuesto' => $provB,
    'monto_cuadraba' => false,
]);
$fila = $pdo->query("SELECT en_disputa FROM proveedor_codigo_erp WHERE proveedor_codigo='{$CODIGO_FUERTE}'")->fetch();
assertGuarda((int) $fila['en_disputa'] === 0, 'el mapa no cede');
$avisos = (int) $pdo->query("SELECT COUNT(*) c FROM notificaciones WHERE firma LIKE 'codigo_proveedor|99%'")->fetch()['c'];
assertGuarda($avisos === 0, 'no molesta a nadie');

// ── Con monto que cuadra sí avisa, pero el mapa firme aguanta ──────
echo "\nVeto sospechoso sobre un mapa firme (20 confirmaciones)\n";
$mapa->registrarVeto([
    'codigo' => $CODIGO_FUERTE, 'proveedor_id_propuesto' => $provB,
    'monto_cuadraba' => true,
]);
ProveedorCodigoErp::olvidarMapa();
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, $provB) === 'ajeno',
    'sigue rechazando: 20 confirmaciones no las tumba un dato');
$aviso = $pdo->query("SELECT severidad, veces FROM notificaciones WHERE firma='codigo_proveedor|{$CODIGO_FUERTE}|{$provB}'")->fetch();
assertGuarda($aviso !== false && $aviso['severidad'] === 'media', 'avisa, con severidad media');

// ── Empate: una confirmación contra una, el mapa se abstiene ───────
echo "\nEmpate (el mapa tenía una sola confirmación)\n";
$mapa->registrarVeto([
    'codigo' => $CODIGO_DEBIL, 'proveedor_id_propuesto' => $provB,
    'monto_cuadraba' => true,
]);
ProveedorCodigoErp::olvidarMapa();
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_DEBIL, $provB) === 'desconocido',
    'deja de bloquear: sin saber más, no puede vetar');
$aviso = $pdo->query("SELECT severidad FROM notificaciones WHERE firma='codigo_proveedor|{$CODIGO_DEBIL}|{$provB}'")->fetch();
assertGuarda($aviso !== false && $aviso['severidad'] === 'alta', 'avisa con severidad alta');

// ── El mismo hecho repetido es UN aviso ────────────────────────────
echo "\nRepetición\n";
$mapa->registrarVeto([
    'codigo' => $CODIGO_FUERTE, 'proveedor_id_propuesto' => $provB, 'monto_cuadraba' => true,
]);
$fila = $pdo->query("SELECT COUNT(*) c, MAX(veces) v FROM notificaciones WHERE firma='codigo_proveedor|{$CODIGO_FUERTE}|{$provB}'")->fetch();
assertGuarda((int) $fila['c'] === 1, 'no se duplica el renglón');
assertGuarda((int) $fila['v'] === 2, 'sube el contador de veces');

// ── La persona manda sobre el contador ─────────────────────────────
echo "\nConfirmación humana\n";
assertGuarda($mapa->confirmarManual($CODIGO_FUERTE, $provB, 0), 'se guarda');
ProveedorCodigoErp::olvidarMapa();
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, $provB) === 'propio',
    'el proveedor declarado a mano pasa a ser el bueno');
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_FUERTE, $provA) === 'ajeno',
    'y el anterior pasa a ser el ajeno');

$mapa->cosechar([$CODIGO_FUERTE]);
$fila = $pdo->query("SELECT cedula, origen FROM proveedor_codigo_erp WHERE proveedor_codigo='{$CODIGO_FUERTE}'")->fetch();
assertGuarda($fila['origen'] === 'manual' && $fila['cedula'] === $CEDULA_B,
    'la cosecha no pisa lo confirmado a mano');

// ── Liberar sin decidir ────────────────────────────────────────────
echo "\nLiberar sin decidir\n";
$mapa->ponerEnDisputa($CODIGO_DEBIL, true);
ProveedorCodigoErp::olvidarMapa();
assertGuarda(ProveedorCodigoErp::veredicto($CODIGO_DEBIL, $provB) === 'desconocido',
    'un código liberado no bloquea a nadie');

$limpiar();
echo "\nProveedorCodigoErpGuardaTest: todo bien.\n";
