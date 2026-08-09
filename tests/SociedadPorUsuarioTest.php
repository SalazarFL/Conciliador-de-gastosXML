<?php
/**
 * Cambiar de empresa es cosa de cada usuario, no del sistema.
 *
 * Antes la selección era la columna `sociedades.activa`: un UPDATE global que
 * apagaba una y encendía otra. Si dos personas trabajaban a la vez, la que
 * cambiaba movía también la empresa de la otra, sin aviso — y como cada
 * documento se sella con la sociedad del momento, quedaban registros mal
 * etiquetados. Ahora se resuelve por sesión → preferencia del usuario →
 * valor por omisión.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/Factura.php';

function assertSocUsuario($condition, $message)
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
    echo "SKIP: SociedadPorUsuario (sin base de datos disponible)\n";
    exit(0);
}

$marca = '__test_por_usuario__';
$socA = 0;
$socB = 0;
$usuarioId = 0;

$limpiar = function () use ($pdo, $marca, &$socA, &$socB, &$usuarioId) {
    if ($usuarioId > 0) {
        $pdo->prepare('DELETE FROM usuarios WHERE id = ? AND username LIKE ?')->execute([$usuarioId, $marca . '%']);
    }
    foreach ([$socA, $socB] as $id) {
        if ($id > 0) {
            $pdo->prepare('UPDATE usuarios SET sociedad_id = NULL WHERE sociedad_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sociedades WHERE id = ? AND nombre LIKE ?')->execute([$id, $marca . '%']);
        }
    }
};

// El modelo lee $_SESSION; en CLI no existe hasta que se declara.
$_SESSION = [];

try {
    $porOmision = (int) $pdo->query('SELECT id FROM sociedades WHERE activa = 1 ORDER BY id LIMIT 1')->fetchColumn();
    assertSocUsuario($porOmision > 0, 'hay una sociedad por omisión en el sistema');

    $ins = $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)');
    $ins->execute([$marca . ' A', '3198000000001']);
    $socA = (int) $pdo->lastInsertId();
    $ins->execute([$marca . ' B', '3198000000002']);
    $socB = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO usuarios (nombre, username, email, password_hash, activo) VALUES (?, ?, ?, ?, 1)')
        ->execute([$marca, $marca . '_u', $marca . '@test.local', 'x']);
    $usuarioId = (int) $pdo->lastInsertId();

    $modelo = new Sociedad();

    // ── Sin sesión: manda el valor por omisión del sistema ──
    Sociedad::olvidarSeleccion();
    assertSocUsuario($modelo->seleccionadaId() === $porOmision,
        'sin sesión se trabaja con la sociedad por omisión');

    // ── Con usuario que tiene preferencia guardada ──
    $pdo->prepare('UPDATE usuarios SET sociedad_id = ? WHERE id = ?')->execute([$socB, $usuarioId]);
    $_SESSION = ['user_id' => $usuarioId];
    Sociedad::olvidarSeleccion();
    assertSocUsuario($modelo->seleccionadaId() === $socB,
        'al entrar se recupera la empresa que el usuario dejó elegida');
    assertSocUsuario((int) ($_SESSION['sociedad_id'] ?? 0) === $socB,
        'la preferencia queda en la sesión para no reconsultarla');

    // ── Cambiar de empresa: afecta a este usuario, no a la marca global ──
    $modelo->seleccionar($socA);
    assertSocUsuario((int) $_SESSION['sociedad_id'] === $socA, 'el cambio tiene efecto inmediato en la sesión');
    assertSocUsuario($modelo->seleccionadaId() === $socA, 'la empresa en curso es la recién elegida');

    $guardada = (int) $pdo->query("SELECT sociedad_id FROM usuarios WHERE id = {$usuarioId}")->fetchColumn();
    assertSocUsuario($guardada === $socA, 'el cambio queda guardado para el próximo ingreso');

    $activaAhora = (int) $pdo->query('SELECT id FROM sociedades WHERE activa = 1 ORDER BY id LIMIT 1')->fetchColumn();
    assertSocUsuario($activaAhora === $porOmision,
        'cambiar de empresa NO mueve la marca global: los demás usuarios siguen donde estaban');

    // ── Otro usuario en la misma base sigue en lo suyo ──
    $otro = (int) $pdo->query("SELECT id FROM usuarios WHERE id <> {$usuarioId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($otro > 0) {
        $suya = (int) $pdo->query("SELECT sociedad_id FROM usuarios WHERE id = {$otro}")->fetchColumn();
        assertSocUsuario($suya !== $socA,
            'el otro usuario no fue arrastrado a la empresa que este eligió');
    }

    // ── El alcance de los modelos sigue el cambio dentro de la misma petición ──
    Sociedad::olvidarSeleccion();
    assertSocUsuario((new Factura())->sociedadId() === $socA,
        'un modelo nuevo queda acotado a la empresa en curso');
    $modelo->seleccionar($socB);
    assertSocUsuario((new Factura())->sociedadId() === $socB,
        'tras cambiar de empresa, el caché se limpia y el alcance la sigue');

    // ── Una empresa inexistente no se puede seleccionar ──
    $rechazo = false;
    try {
        $modelo->seleccionar(987654);
    } catch (InvalidArgumentException $e) {
        $rechazo = true;
    }
    assertSocUsuario($rechazo, 'no se puede seleccionar una sociedad que no existe');
    assertSocUsuario((int) $_SESSION['sociedad_id'] === $socB, 'el rechazo deja la selección anterior intacta');
} catch (Throwable $e) {
    $_SESSION = [];
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$_SESSION = [];
Sociedad::olvidarSeleccion();
$limpiar();
echo "OK: cada usuario elige su empresa sin mover la de los demás\n";
