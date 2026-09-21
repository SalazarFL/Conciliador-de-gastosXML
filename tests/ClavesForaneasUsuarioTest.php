<?php
/**
 * Las diecisiete referencias a `usuarios` están amarradas de verdad.
 *
 * Durante mucho tiempo no se pudieron amarrar: `usuarios.id` era INT(11) con
 * signo y las diecisiete columnas que lo referencian son INT UNSIGNED, y a
 * InnoDB los tipos le tienen que coincidir. El resultado era que se podía
 * escribir un id de usuario inventado, y que borrar a una persona dejaba sus
 * diecisiete rastros apuntando al vacío sin que nada avisara.
 *
 * `database/migration_usuarios_id_unsigned.sql` cambia el tipo del padre y
 * crea las claves. Esta prueba comprueba que siga siendo verdad, porque es un
 * arreglo fácil de deshacer sin notarlo: basta que alguien cree una tabla
 * nueva con un `usuario_id` y se olvide de la clave, o que una restauración
 * de respaldo viejo devuelva el tipo con signo.
 *
 * Se comprueban tres cosas distintas, y las tres hacen falta:
 *
 *   1. Que el tipo del padre siga sin signo. Es lo que permite todo lo demás.
 *   2. Que las diecisiete claves existan CON SU REGLA DE BORRADO. Una clave
 *      con la regla equivocada es peor que ninguna: un CASCADE donde iba un
 *      SET NULL borra la carga entera cuando se da de baja a quien la hizo.
 *   3. Que funcionen de verdad, no solo que estén declaradas. Se crea un
 *      usuario de mentira, se le cuelgan filas de las dos clases —autoría y
 *      permiso—, se le borra, y se mira qué quedó.
 *
 * Uso: php tests/ClavesForaneasUsuarioTest.php
 */

require_once __DIR__ . '/soporte/SociedadDePrueba.php';

$fallas = [];

function comprobarFk($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/**
 * Qué clave le toca a cada columna.
 *
 * CASCADE solo en el permiso de acceso: si la persona se va, el permiso se va
 * con ella. Todo lo demás es autoría —quién cargó, quién descartó, quién
 * resolvió— y el hecho tiene que sobrevivir a la persona, así que SET NULL.
 */
$esperadas = [
    ['usuario_sociedades',             'usuario_id',     'CASCADE'],
    ['bitacora',                       'usuario_id',     'SET NULL'],
    ['correo_incidencias',             'descartada_por', 'SET NULL'],
    ['correo_incidencias_descartes',   'usuario_id',     'SET NULL'],
    ['facturas_erp_cargas',            'usuario_id',     'SET NULL'],
    ['facturas_erp_descartes',         'usuario_id',     'SET NULL'],
    ['facturas_erp_incidencias',       'descartada_por', 'SET NULL'],
    ['facturas_erp_revision',          'resuelta_por',   'SET NULL'],
    ['facturas_erp_revision_memoria',  'usuario_id',     'SET NULL'],
    ['notas_credito_cargas',           'usuario_id',     'SET NULL'],
    ['notas_credito_revision',         'resuelta_por',   'SET NULL'],
    ['notas_credito_revision_memoria', 'usuario_id',     'SET NULL'],
    ['notificaciones',                 'resuelta_por',   'SET NULL'],
    ['proveedor_alias',                'creado_por',     'SET NULL'],
    ['proveedor_cedula_ignorada',      'usuario_id',     'SET NULL'],
    ['proveedor_codigo_erp',           'confirmado_por', 'SET NULL'],
    ['seguimiento_bitacora',           'usuario_id',     'SET NULL'],
];

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: ClavesForaneasUsuario (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. El tipo del padre
// ---------------------------------------------------------------------
$tipo = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'id'"
)->fetchColumn();

comprobarFk(
    stripos((string) $tipo, 'unsigned') !== false,
    "usuarios.id volvió a tener signo ({$tipo}). Sin eso ninguna de las "
    . 'diecisiete claves se puede crear: aplica database/migration_usuarios_id_unsigned.sql.'
);

// Si el tipo está mal, las claves no pueden existir y el resto del informe
// sería ruido repetido diecisiete veces.
if ($fallas) {
    foreach ($fallas as $f) {
        fwrite(STDERR, "FAIL: {$f}\n");
    }
    exit(1);
}

// ---------------------------------------------------------------------
// 2. Las diecisiete, con su regla
// ---------------------------------------------------------------------
$declaradas = [];
$filas = $pdo->query(
    "SELECT k.TABLE_NAME, k.COLUMN_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'usuarios'"
)->fetchAll();

foreach ($filas as $f) {
    $declaradas[$f['TABLE_NAME'] . '.' . $f['COLUMN_NAME']] = $f['DELETE_RULE'];
}

foreach ($esperadas as [$tabla, $columna, $regla]) {
    $llave = $tabla . '.' . $columna;
    if (!isset($declaradas[$llave])) {
        comprobarFk(false, "{$llave} no tiene clave foránea hacia usuarios.id.");
        continue;
    }
    comprobarFk(
        $declaradas[$llave] === $regla,
        "{$llave} borra con {$declaradas[$llave]} y le toca {$regla}."
        . ($regla === 'SET NULL'
            ? ' Es una columna de autoría: dar de baja a quien hizo algo no puede borrar lo que hizo.'
            : ' Es un permiso de acceso: tiene que irse con la persona.')
    );
}

$sobran = array_diff(array_keys($declaradas), array_map(
    function ($e) { return $e[0] . '.' . $e[1]; },
    $esperadas
));
foreach ($sobran as $llave) {
    comprobarFk(
        false,
        "{$llave} apunta a usuarios.id y no está en la lista de esta prueba. "
        . 'Si es una columna nueva, decide su regla de borrado y agrégala aquí.'
    );
}

// ---------------------------------------------------------------------
// 3. Que funcionen de verdad
// ---------------------------------------------------------------------
// Una clave declarada y una clave que actúa no son lo mismo: si alguien deja
// la sesión con FOREIGN_KEY_CHECKS apagado, o la tabla termina en MyISAM, la
// declaración sigue en el catálogo y no hace nada.

$marca = 'fk_test_' . bin2hex(random_bytes(4));
$usuarioId = null;
$sociedadId = null;

$limpiar = function () use ($pdo, $marca, &$usuarioId, &$sociedadId) {
    try {
        // LIKE y no '=': también se lleva el renglón de basura del paso 3a,
        // que lleva marca propia y solo llega a existir cuando la clave falta.
        $pdo->prepare('DELETE FROM bitacora WHERE accion LIKE ?')->execute([$marca . '%']);
        $pdo->prepare('DELETE FROM proveedor_cedula_ignorada WHERE cedula = ?')->execute([$marca]);
        if ($usuarioId !== null) {
            $pdo->prepare('DELETE FROM usuario_sociedades WHERE usuario_id = ?')->execute([$usuarioId]);
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);
        }
        SociedadDePrueba::borrar($pdo, $sociedadId);
        $sociedadId = null;
    } catch (Throwable $e) {
        // Limpiar es cortesía; si falla, lo dice el informe de abajo.
    }
};
$limpiar();

try {
    $pdo->prepare(
        'INSERT INTO usuarios (nombre, username, password_hash, activo) VALUES (?, ?, ?, 0)'
    )->execute(['Prueba claves foráneas', $marca, 'x']);
    $usuarioId = (int) $pdo->lastInsertId();

    // Autoría (SET NULL) y permiso (CASCADE), una de cada clase.
    $pdo->prepare('INSERT INTO bitacora (usuario_id, accion, modulo) VALUES (?, ?, ?)')
        ->execute([$usuarioId, $marca, 'prueba']);
    $pdo->prepare('INSERT INTO proveedor_cedula_ignorada (cedula, usuario_id) VALUES (?, ?)')
        ->execute([$marca, $usuarioId]);
    // Una empresa de verdad: desde migration_claves_sociedad.sql esa columna
    // también tiene clave, así que un id inventado ya no entra.
    $sociedadId = SociedadDePrueba::crear($pdo, 'fk usuario');
    $pdo->prepare('INSERT INTO usuario_sociedades (usuario_id, sociedad_id) VALUES (?, ?)')
        ->execute([$usuarioId, $sociedadId]);

    // 3a. Un id de usuario inventado ya no entra.
    // Lleva marca propia: cuando la clave NO está, este INSERT sí entra, y si
    // compartiera marca con el de arriba el conteo del paso 3b daría dos y
    // culparía al borrado de algo que no hizo.
    $marcaBasura = $marca . '_basura';
    $entroBasura = true;
    try {
        $pdo->prepare('INSERT INTO bitacora (usuario_id, accion, modulo) VALUES (?, ?, ?)')
            ->execute([2147483600, $marcaBasura, 'prueba']);
    } catch (Throwable $e) {
        $entroBasura = false;
    }
    comprobarFk(
        !$entroBasura,
        'Se pudo escribir en bitacora un usuario_id que no existe. La clave está '
        . 'declarada pero no actúa: revisa FOREIGN_KEY_CHECKS y que la tabla sea InnoDB.'
    );

    // 3b. Borrar al usuario.
    $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);

    $enBitacora = $pdo->prepare('SELECT usuario_id FROM bitacora WHERE accion = ?');
    $enBitacora->execute([$marca]);
    $rastro = $enBitacora->fetchAll();

    comprobarFk(
        count($rastro) === 1,
        'Borrar al usuario borró su renglón de la bitácora. La bitácora es el '
        . 'registro de auditoría: tiene que sobrevivir a la persona.'
    );
    comprobarFk(
        count($rastro) === 1 && $rastro[0]['usuario_id'] === null,
        'El renglón de la bitácora quedó apuntando a un usuario borrado en vez de en NULL.'
    );

    $enCedulas = $pdo->prepare('SELECT usuario_id FROM proveedor_cedula_ignorada WHERE cedula = ?');
    $enCedulas->execute([$marca]);
    $cedula = $enCedulas->fetchAll();

    comprobarFk(
        count($cedula) === 1 && $cedula[0]['usuario_id'] === null,
        'La cédula ignorada no quedó en NULL al borrar a quien la marcó.'
    );

    $permisos = $pdo->prepare('SELECT COUNT(*) FROM usuario_sociedades WHERE usuario_id = ?');
    $permisos->execute([$usuarioId]);

    comprobarFk(
        (int) $permisos->fetchColumn() === 0,
        'El permiso de acceso a la empresa sobrevivió al usuario. Eso es lo que '
        . 'dejaba accesos colgados de gente que ya no está.'
    );

    $usuarioId = null; // ya no existe; que la limpieza no lo busque
} catch (Throwable $e) {
    comprobarFk(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
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

echo "OK: ClavesForaneasUsuario (" . count($esperadas) . " claves, con su regla de borrado y comprobadas en uso)\n";
exit(0);
