<?php
/**
 * Un buzón solo se puede usar desde la empresa a la que atiende.
 *
 * El error que fija: al cambiar de sociedad, el módulo decía "no hay cuentas
 * de correo" —correcto, ninguna atendía a esa empresa— pero el selector de la
 * barra seguía ofreciendo los buzones de TODAS. Peor que la confusión: elegir
 * uno funcionaba. El id de la cuenta viaja en el POST y el servidor solo
 * comprobaba que existiera, no que le correspondiera a la empresa en curso.
 * Bastaba con cambiar de sociedad para leer el correo de otra.
 *
 * Aquí se comprueban las dos capas:
 *   · el modelo sabe qué buzón atiende a qué empresa;
 *   · y ese saber es el que gobierna, sin depender de lo que ofrezca la
 *     pantalla — que es lo que fallaba.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';
require_once __DIR__ . '/../app/models/Sociedad.php';

$fallos = 0;
function verificaBuzon($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
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
    echo "SKIP: CorreoBuzonPorSociedad (sin base de datos disponible)\n";
    exit(0);
}

$marca = '__test_buzon_soc__';
$socA = $socB = $cuentaA = 0;
$_SESSION = [];

$limpiar = function () use ($pdo, $marca, &$socA, &$socB, &$cuentaA) {
    if ($cuentaA > 0) {
        $pdo->prepare('DELETE FROM correo_cuenta_sociedades WHERE cuenta_id = ?')->execute([$cuentaA]);
        $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ? AND nombre LIKE ?')->execute([$cuentaA, $marca . '%']);
    }
    foreach ([$socA, $socB] as $id) {
        if ($id > 0) {
            $pdo->prepare('UPDATE usuarios SET sociedad_id = NULL WHERE sociedad_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM correo_cuenta_sociedades WHERE sociedad_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sociedades WHERE id = ? AND nombre LIKE ?')->execute([$id, $marca . '%']);
        }
    }
};

try {
    $ins = $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)');
    $ins->execute([$marca . ' A', '3197000000001']);
    $socA = (int) $pdo->lastInsertId();
    $ins->execute([$marca . ' B', '3197000000002']);
    $socB = (int) $pdo->lastInsertId();

    // Un buzón que atiende SOLO a la empresa A.
    $pdo->prepare('INSERT INTO correo_cuentas (nombre, host, puerto, usuario, password, carpeta)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$marca . ' buzón', 'mail.test.local', 993, 'a@test.local', 'x', 'INBOX']);
    $cuentaA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO correo_cuenta_sociedades (cuenta_id, sociedad_id) VALUES (?, ?)')
        ->execute([$cuentaA, $socA]);

    $cuentas = new CorreoCuenta();

    // ── Desde la empresa A: el buzón es suyo ──────────────────────────
    $_SESSION = ['sociedad_id' => $socA];
    Sociedad::olvidarSeleccion();
    $cuentas = new CorreoCuenta();

    verificaBuzon($cuentas->perteneceASociedad($cuentaA) === true,
        'desde su empresa, el buzón se reconoce como propio');
    $idsVisibles = array_map('intval', array_column($cuentas->getVisibles(), 'id'));
    verificaBuzon(in_array($cuentaA, $idsVisibles, true),
        'desde su empresa, el buzón aparece entre los que se pueden usar');

    // ── Desde la empresa B: no es suyo, y no basta con pedirlo ────────
    $_SESSION = ['sociedad_id' => $socB];
    Sociedad::olvidarSeleccion();
    $cuentas = new CorreoCuenta();

    verificaBuzon($cuentas->perteneceASociedad($cuentaA) === false,
        'desde otra empresa, el buzón NO se reconoce como propio (aunque el id sea válido)');
    $idsVisibles = array_map('intval', array_column($cuentas->getVisibles(), 'id'));
    verificaBuzon(!in_array($cuentaA, $idsVisibles, true),
        'desde otra empresa, el buzón no aparece entre los que se pueden usar');

    // El buzón SÍ sigue existiendo: el ⚙ tiene que poder mostrarlo para
    // corregir su asignación. Ocultarlo del todo lo dejaría inalcanzable.
    verificaBuzon($cuentas->getById($cuentaA) !== null,
        'el buzón sigue existiendo y el ⚙ puede mostrarlo para reasignarlo');
    $idsTodos = array_map('intval', array_column($cuentas->getAll(), 'id'));
    verificaBuzon(in_array($cuentaA, $idsTodos, true),
        'getAll() lo sigue incluyendo: es la lista que administra el ⚙');

    // ── Asignarlo a B también: entonces sí ────────────────────────────
    $cuentas->asignarSociedades($cuentaA, [$socA, $socB]);
    verificaBuzon($cuentas->perteneceASociedad($cuentaA) === true,
        'tras marcar la empresa en el ⚙, el buzón queda disponible sin registrarlo de nuevo');
    verificaBuzon(count($cuentas->sociedadesDe($cuentaA)) === 2,
        'un buzón puede atender a varias empresas a la vez');

    // Y sigue sirviendo a la primera: asignar no es mover.
    $_SESSION = ['sociedad_id' => $socA];
    Sociedad::olvidarSeleccion();
    verificaBuzon((new CorreoCuenta())->perteneceASociedad($cuentaA) === true,
        'asignarlo a la segunda empresa no se lo quita a la primera');
} catch (Throwable $e) {
    $_SESSION = [];
    Sociedad::olvidarSeleccion();
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$_SESSION = [];
Sociedad::olvidarSeleccion();
$limpiar();

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: cada buzón solo se usa desde las empresas a las que atiende\n";
