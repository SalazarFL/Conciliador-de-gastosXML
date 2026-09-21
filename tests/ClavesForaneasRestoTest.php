<?php
/**
 * Las ocho referencias que quedaban sueltas: roles, pagos e importaciones.
 *
 * Tercer y último grupo de columnas `_id` sin clave foránea. Cada una dejaba
 * un agujero distinto, y dos de ellas —las de importacion_items— ni siquiera
 * se podían amarrar: eran BIGINT contra padres INT UNSIGNED, el mismo choque
 * de tipos que tenía usuarios.id.
 *
 * Aparte de las claves se comprueba aquí el arreglo de Rol::eliminar, porque
 * es el mismo asunto visto desde el código. La protección de los roles del
 * sistema estaba SOLO en la última de sus tres sentencias:
 *
 *     UPDATE usuarios SET rol_id = NULL WHERE rol_id = ?      <- sin proteger
 *     DELETE FROM rol_permisos WHERE rol_id = ?               <- sin proteger
 *     DELETE FROM roles WHERE id = ? AND es_sistema = 0       <- protegida
 *
 * Pedir el borrado de un rol del sistema corría las dos primeras igual: el rol
 * se quedaba vivo y sin ningún permiso, y su gente sin rol. Nada fallaba y
 * nada lo decía. Lo peor del caso es que no deja rastro de qué permisos tenía.
 *
 * Uso: php tests/ClavesForaneasRestoTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Rol.php';

$fallas = [];

function comprobarResto($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/** tabla.columna => [tabla referida, regla de borrado]. */
$esperadas = [
    'usuarios.rol_id'                  => ['roles',        'SET NULL'],
    'rol_permisos.rol_id'              => ['roles',        'CASCADE'],
    'facturas_xml.pago_id'             => ['pagos',        'SET NULL'],
    'facturas_erp.pago_id'             => ['pagos',        'SET NULL'],
    'almacen_copias_pago.factura_id'   => ['facturas_xml', 'CASCADE'],
    'correo_bandeja.importacion_id'    => ['importaciones', 'SET NULL'],
    'importacion_items.importacion_id' => ['importaciones', 'CASCADE'],
    'importacion_items.factura_id'     => ['facturas_xml', 'SET NULL'],
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
    echo "SKIP: ClavesForaneasResto (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. Los dos tipos que bloqueaban sus claves
// ---------------------------------------------------------------------
foreach (['importacion_id', 'factura_id'] as $columna) {
    $tipo = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'importacion_items'
            AND COLUMN_NAME = '{$columna}'"
    )->fetchColumn();

    comprobarResto(
        stripos($tipo, 'bigint') === false,
        "importacion_items.{$columna} volvió a ser {$tipo}. Sus padres son "
        . 'INT UNSIGNED y con tipos distintos InnoDB no deja crear la clave: '
        . 'aplica database/migration_claves_rol_pago_importacion.sql.'
    );
}

// ---------------------------------------------------------------------
// 2. Las ocho claves, con su regla
// ---------------------------------------------------------------------
$declaradas = [];
foreach ($pdo->query(
    "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = DATABASE()
        AND k.REFERENCED_TABLE_NAME IN ('roles','pagos','importaciones','facturas_xml')"
)->fetchAll() as $f) {
    $declaradas[$f['TABLE_NAME'] . '.' . $f['COLUMN_NAME']] =
        [$f['REFERENCED_TABLE_NAME'], $f['DELETE_RULE']];
}

foreach ($esperadas as $llave => [$referida, $regla]) {
    if (!isset($declaradas[$llave])) {
        comprobarResto(false, "{$llave} no tiene clave foránea hacia {$referida}.id.");
        continue;
    }
    [$referidaReal, $reglaReal] = $declaradas[$llave];
    comprobarResto($referidaReal === $referida,
        "{$llave} apunta a {$referidaReal} y debería apuntar a {$referida}.");
    comprobarResto(
        $reglaReal === $regla,
        "{$llave} borra con {$reglaReal} y le toca {$regla}."
        . ($regla === 'SET NULL'
            ? ' La fila tiene que sobrevivir a su padre; solo suelta la referencia.'
            : ' Sin su padre no significa nada y quedaría tirada.')
    );
}

// ---------------------------------------------------------------------
// 3. Rol::eliminar y los roles del sistema
// ---------------------------------------------------------------------
$marca = 'ZZ rol prueba ' . bin2hex(random_bytes(3));
$rolSistemaId = null;
$rolNormalId = null;
$usuarioId = null;

$limpiar = function () use ($pdo, $marca, &$rolSistemaId, &$rolNormalId, &$usuarioId) {
    try {
        if ($usuarioId !== null) {
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);
        }
        foreach ([$rolSistemaId, $rolNormalId] as $id) {
            if ($id !== null) {
                $pdo->prepare('DELETE FROM rol_permisos WHERE rol_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            }
        }
    } catch (Throwable $e) {
        // Lo dirá el informe de abajo.
    }
};
$limpiar();

try {
    $modelo = new Rol();

    // ── Un rol del sistema, con permisos y con gente ──
    $pdo->prepare('INSERT INTO roles (nombre, es_sistema) VALUES (?, 1)')
        ->execute([$marca . ' sistema']);
    $rolSistemaId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso) VALUES (?, ?)')
        ->execute([$rolSistemaId, 'prueba.uno']);
    $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso) VALUES (?, ?)')
        ->execute([$rolSistemaId, 'prueba.dos']);
    $pdo->prepare('INSERT INTO usuarios (nombre, username, password_hash, activo, rol_id) VALUES (?, ?, ?, 0, ?)')
        ->execute([$marca, $marca, 'x', $rolSistemaId]);
    $usuarioId = (int) $pdo->lastInsertId();

    $lanzo = false;
    try {
        $modelo->eliminar($rolSistemaId);
    } catch (Throwable $e) {
        $lanzo = true;
    }
    comprobarResto($lanzo, 'Rol::eliminar no lanzó con un rol del sistema.');

    $permisos = $pdo->prepare('SELECT COUNT(*) FROM rol_permisos WHERE rol_id = ?');
    $permisos->execute([$rolSistemaId]);
    comprobarResto((int) $permisos->fetchColumn() === 2,
        'Intentar borrar un rol del SISTEMA le vació los permisos. Es el defecto '
        . 'original: la protección estaba solo en el DELETE del rol, y las dos '
        . 'limpiezas de antes corrían igual. El rol queda vivo y sin nada, y no '
        . 'hay registro de qué permisos tenía.');

    $conRol = $pdo->prepare('SELECT rol_id FROM usuarios WHERE id = ?');
    $conRol->execute([$usuarioId]);
    comprobarResto((int) $conRol->fetchColumn() === $rolSistemaId,
        'Intentar borrar un rol del sistema dejó sin rol a su gente.');

    $vive = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE id = ?');
    $vive->execute([$rolSistemaId]);
    comprobarResto((int) $vive->fetchColumn() === 1,
        'El rol del sistema desapareció, y no debía.');

    // ── Un rol normal sí se borra, y se lleva lo suyo ──
    $pdo->prepare('INSERT INTO roles (nombre, es_sistema) VALUES (?, 0)')
        ->execute([$marca . ' normal']);
    $rolNormalId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso) VALUES (?, ?)')
        ->execute([$rolNormalId, 'prueba.tres']);
    $pdo->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')
        ->execute([$rolNormalId, $usuarioId]);

    $modelo->eliminar($rolNormalId);

    $permisos->execute([$rolNormalId]);
    comprobarResto((int) $permisos->fetchColumn() === 0,
        'Los permisos del rol borrado siguen ahí.');

    $conRol->execute([$usuarioId]);
    comprobarResto($conRol->fetchColumn() === null,
        'Quien tenía el rol borrado no quedó sin rol. Dejarlo en blanco es a '
        . 'propósito: pasarlo a otro rol le cambiaría los permisos sin que '
        . 'nadie se entere.');

    $vive->execute([$rolNormalId]);
    comprobarResto((int) $vive->fetchColumn() === 0, 'El rol normal no se borró.');
    $rolNormalId = null;

    // ── Y un rol_id inventado ya no entra ──
    $entroBasura = true;
    try {
        $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso) VALUES (?, ?)')
            ->execute([2147483600, 'prueba.fantasma']);
    } catch (Throwable $e) {
        $entroBasura = false;
    }
    comprobarResto(!$entroBasura,
        'Se pudo dar un permiso a un rol que no existe. La clave está declarada '
        . 'pero no actúa: revisa FOREIGN_KEY_CHECKS y que la tabla sea InnoDB.');
    if ($entroBasura) {
        $pdo->prepare('DELETE FROM rol_permisos WHERE permiso = ?')->execute(['prueba.fantasma']);
    }
} catch (Throwable $e) {
    comprobarResto(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
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

echo 'OK: ClavesForaneasResto (' . count($esperadas)
    . " claves con su regla, y los roles del sistema a salvo)\n";
exit(0);
