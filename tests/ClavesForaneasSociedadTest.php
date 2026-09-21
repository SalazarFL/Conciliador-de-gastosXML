<?php
/**
 * Borrar una empresa ya no se lleva sus documentos por delante.
 *
 * Era el agujero más caro de la base: `SociedadesController::eliminar` hacía
 * un DELETE pelado y dieciocho tablas con `sociedad_id` no tenían clave
 * foránea, así que borrar una empresa con catorce mil facturas no daba ningún
 * error. Las facturas se quedaban con un `sociedad_id` inexistente: invisibles
 * en todas las pantallas —filtran por empresa—, imposibles de reasignar desde
 * la interfaz, y sin forma de saber de quién eran.
 *
 * Lo que se comprueba, en tres capas, porque cada una puede romperse sola:
 *
 *   1. Que las diecinueve claves estén, CON SU REGLA. Una regla equivocada es
 *      peor que ninguna clave: un CASCADE donde iba un RESTRICT borra las
 *      facturas en vez de proteger la empresa, en silencio y sin vuelta.
 *   2. Que actúen de verdad. Se crea una empresa de mentira, se le cuelga un
 *      pago, un permiso y un renglón de bitácora, y se intenta borrarla.
 *   3. Que el modelo conteste en español. La clave sola contesta SQLSTATE
 *      1451, que no le dice nada a quien está en la pantalla de empresas.
 *
 * Uso: php tests/ClavesForaneasSociedadTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';

$fallas = [];

function comprobarSoc($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/**
 * La regla que le toca a cada tabla, y por qué.
 *
 *   RESTRICT — documentos y dinero. No se pierden nunca: la clave impide el
 *              borrado y obliga a vaciar primero.
 *   CASCADE  — permisos y bandejas de trabajo. No significan nada sin su
 *              empresa y hoy se quedaban tirados.
 *   SET NULL — rastro que sobrevive: auditoría, y la empresa con la que cada
 *              usuario está trabajando, que es una preferencia y no un dato.
 */
$esperadas = [
    ['facturas_xml',                   'RESTRICT'],
    ['facturas_erp',                   'RESTRICT'],
    ['devoluciones',                   'RESTRICT'],
    ['pagos',                          'RESTRICT'],
    ['notas_credito_cargas',           'RESTRICT'],
    ['correo_bandeja',                 'RESTRICT'],
    ['correo_lotes',                   'RESTRICT'],   // ya existía
    ['notas_credito_listados',         'RESTRICT'],   // ya existía
    ['usuario_sociedades',             'CASCADE'],
    ['almacen_migracion',              'CASCADE'],
    ['facturas_erp_revision',          'CASCADE'],
    ['facturas_erp_revision_memoria',  'CASCADE'],
    ['facturas_erp_descartes',         'CASCADE'],
    ['notas_credito_revision',         'CASCADE'],
    ['notas_credito_revision_memoria', 'CASCADE'],
    ['correo_cuenta_sociedades',       'CASCADE'],    // ya existía
    ['bitacora',                       'SET NULL'],
    ['usuarios',                       'SET NULL'],
    ['facturas_erp_cargas',            'SET NULL'],
];

/**
 * Las dos que se quedan sin clave a propósito, por su centinela 0.
 *
 * Están aquí para que el día que alguien se las ponga sin pensarlo, la prueba
 * lo diga: en `notificaciones` el 0 significa "aviso del grupo" y sostiene la
 * unicidad `uk_firma`, que es la que junta el mismo aviso repetido en un solo
 * renglón; con NULL, MySQL consideraría distinta cada repetición.
 */
$sinClaveAProposito = ['notificaciones', 'correo_procesados'];

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: ClavesForaneasSociedad (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. Las claves y su regla
// ---------------------------------------------------------------------
$declaradas = [];
foreach ($pdo->query(
    "SELECT k.TABLE_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'sociedades'"
)->fetchAll() as $f) {
    $declaradas[$f['TABLE_NAME']] = $f['DELETE_RULE'];
}

foreach ($esperadas as [$tabla, $regla]) {
    if (!isset($declaradas[$tabla])) {
        comprobarSoc(false,
            "{$tabla}.sociedad_id no tiene clave foránea. Aplica "
            . 'database/migration_claves_sociedad.sql.');
        continue;
    }
    $porque = $regla === 'RESTRICT'
        ? ' Son documentos: borrar la empresa no puede llevárselos.'
        : ($regla === 'CASCADE'
            ? ' No significa nada sin su empresa y quedaría tirado.'
            : ' Es rastro o preferencia: sobrevive, pero suelta la empresa.');
    comprobarSoc(
        $declaradas[$tabla] === $regla,
        "{$tabla}.sociedad_id borra con {$declaradas[$tabla]} y le toca {$regla}." . $porque
    );
}

foreach ($sinClaveAProposito as $tabla) {
    comprobarSoc(
        !isset($declaradas[$tabla]),
        "{$tabla}.sociedad_id tiene clave foránea y no debería: usa 0 como "
        . 'centinela ("del grupo"/"sin determinar") y ninguna empresa tiene id 0. '
        . 'Si de verdad se quiere la clave, primero hay que retirar el centinela.'
    );
}

$sobran = array_diff(array_keys($declaradas), array_column($esperadas, 0));
foreach ($sobran as $tabla) {
    comprobarSoc(false,
        "{$tabla}.sociedad_id apunta a sociedades y no está en la lista de esta "
        . 'prueba. Si es una tabla nueva, decide su regla de borrado y agrégala aquí.');
}

// ---------------------------------------------------------------------
// 2 y 3. Que actúen, y que el modelo conteste en español
// ---------------------------------------------------------------------
$marca = 'ZZ prueba claves ' . bin2hex(random_bytes(3));
$sociedadId = null;
$usuarioId = null;

$limpiar = function () use ($pdo, $marca, &$sociedadId, &$usuarioId) {
    try {
        if ($sociedadId !== null) {
            $pdo->prepare('DELETE FROM pagos WHERE sociedad_id = ?')->execute([$sociedadId]);
            $pdo->prepare('DELETE FROM usuario_sociedades WHERE sociedad_id = ?')->execute([$sociedadId]);
            $pdo->prepare('DELETE FROM bitacora WHERE sociedad_id = ?')->execute([$sociedadId]);
            $pdo->prepare('DELETE FROM sociedades WHERE id = ?')->execute([$sociedadId]);
        }
        if ($usuarioId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);
        }
        $pdo->prepare('DELETE FROM bitacora WHERE accion = ?')->execute([$marca]);
    } catch (Throwable $e) {
        // Lo dirá el informe de abajo.
    }
};
$limpiar();

try {
    $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)')
        ->execute([$marca, '000000000']);
    $sociedadId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO usuarios (nombre, username, password_hash, activo) VALUES (?, ?, ?, 0)')
        ->execute([$marca, $marca, 'x']);
    $usuarioId = (int) $pdo->lastInsertId();

    // Un documento (RESTRICT), un permiso (CASCADE) y un rastro (SET NULL).
    $pdo->prepare('INSERT INTO pagos (sociedad_id, nombre) VALUES (?, ?)')
        ->execute([$sociedadId, $marca]);
    $pdo->prepare('INSERT INTO usuario_sociedades (usuario_id, sociedad_id) VALUES (?, ?)')
        ->execute([$usuarioId, $sociedadId]);
    $pdo->prepare('INSERT INTO bitacora (sociedad_id, accion, modulo) VALUES (?, ?, ?)')
        ->execute([$sociedadId, $marca, 'prueba']);

    // 2a. Una empresa de mentira no entra donde se espera una de verdad.
    $entroBasura = true;
    try {
        $pdo->prepare('INSERT INTO pagos (sociedad_id, nombre) VALUES (?, ?)')
            ->execute([2147483600, $marca]);
    } catch (Throwable $e) {
        $entroBasura = false;
    }
    comprobarSoc(!$entroBasura,
        'Se pudo crear un pago de una empresa que no existe. La clave está '
        . 'declarada pero no actúa: revisa FOREIGN_KEY_CHECKS y que la tabla sea InnoDB.');

    // 2b. Con el pago colgando, la base tiene que negarse.
    $borroConDocumentos = true;
    try {
        $pdo->prepare('DELETE FROM sociedades WHERE id = ?')->execute([$sociedadId]);
    } catch (Throwable $e) {
        $borroConDocumentos = false;
    }
    comprobarSoc(!$borroConDocumentos,
        'Se borró una empresa que todavía tenía un pago. Es el agujero original: '
        . 'los documentos quedan con un sociedad_id inexistente y no los ve nadie.');

    // 3. Y el modelo tiene que decir por qué, con números.
    $modelo = new Sociedad();
    $motivos = $modelo->loQueImpideBorrar($sociedadId);
    comprobarSoc($motivos !== [],
        'Sociedad::loQueImpideBorrar no ve el pago que impide borrar la empresa.');
    comprobarSoc(
        $motivos !== [] && strpos(implode(' ', $motivos), 'pago') !== false,
        'El motivo no nombra los pagos: ' . implode(' | ', $motivos));

    $mensaje = '';
    try {
        $modelo->eliminar($sociedadId);
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
    }
    comprobarSoc($mensaje !== '',
        'Sociedad::eliminar no lanzó con documentos colgando: la pantalla dejaría intentarlo.');
    comprobarSoc(
        stripos($mensaje, 'SQLSTATE') === false && stripos($mensaje, '1451') === false,
        'El mensaje que llegaría a la pantalla es el error crudo de la base: ' . $mensaje);

    // 2c. Sin documentos, sí se borra, y arrastra lo que le toca.
    $pdo->prepare('DELETE FROM pagos WHERE sociedad_id = ?')->execute([$sociedadId]);
    $modelo->eliminar($sociedadId);

    $quedaPermiso = $pdo->prepare('SELECT COUNT(*) FROM usuario_sociedades WHERE sociedad_id = ?');
    $quedaPermiso->execute([$sociedadId]);
    comprobarSoc((int) $quedaPermiso->fetchColumn() === 0,
        'El permiso de acceso sobrevivió a su empresa. Eso es lo que dejaba '
        . 'accesos colgados de empresas que ya no existen.');

    $enBitacora = $pdo->prepare('SELECT sociedad_id FROM bitacora WHERE accion = ?');
    $enBitacora->execute([$marca]);
    $rastro = $enBitacora->fetchAll();
    comprobarSoc(count($rastro) === 1,
        'Borrar la empresa borró su renglón de la bitácora. La auditoría tiene '
        . 'que sobrevivir a la empresa.');
    comprobarSoc(count($rastro) === 1 && $rastro[0]['sociedad_id'] === null,
        'El renglón de la bitácora quedó apuntando a una empresa borrada en vez de en NULL.');

    $sociedadId = null; // ya no existe
} catch (Throwable $e) {
    comprobarSoc(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
} finally {
    $limpiar();
}

// ---------------------------------------------------------------------
if ($fallas) {
    foreach ($fallas as $f) {
        fwrite(STDERR, "FAIL: {$f}\n");
    }
    exit(1);
}

echo 'OK: ClavesForaneasSociedad (' . count($esperadas) . ' claves con su regla, '
    . count($sinClaveAProposito) . " exentas, y comprobadas en uso)\n";
exit(0);
