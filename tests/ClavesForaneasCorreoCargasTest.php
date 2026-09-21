<?php
/**
 * Buzones, proveedores, cargas y listados: las veintiséis que quedaban.
 *
 * El resto del inventario, después de usuarios, sociedades y
 * rol/pago/importación. Dos cosas concretas que arregla y que conviene tener
 * en una prueba:
 *
 *   · `CorreoCuenta::eliminar` era un DELETE pelado, igual que lo fue el de
 *     sociedades. Borrar un buzón dejaba su índice entero —134.681 mensajes en
 *     la copia de agosto—, sus carpetas y su cola apuntando a una cuenta que ya
 *     no existe, y nada de eso sale en pantalla porque todo filtra por cuenta.
 *
 *   · `notas_credito_historial` sobrevivía a las líneas de las que habla:
 *     `notas_credito_lineas` se borra en cascada al borrar su listado, y el
 *     historial no tenía clave.
 *
 * La prueba comprueba las veintiséis con su regla, y además el comportamiento
 * de las dos familias donde una regla equivocada se nota tarde: que borrar un
 * buzón se lleve su índice pero NO sus documentos sin importar, y que el
 * historial de notas se vaya con su listado.
 *
 * Uso: php tests/ClavesForaneasCorreoCargasTest.php
 */
require_once __DIR__ . '/soporte/SociedadDePrueba.php';

$fallas = [];

function comprobarCC($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/** tabla.columna => [tabla referida, regla]. */
$esperadas = [
    // Buzones
    'correo_indice.cuenta_id'                  => ['correo_cuentas', 'CASCADE'],
    'correo_carpetas.cuenta_id'                => ['correo_cuentas', 'CASCADE'],
    'correo_capturas_auto.cuenta_id'           => ['correo_cuentas', 'CASCADE'],
    'correo_bandeja.cuenta_id'                 => ['correo_cuentas', 'RESTRICT'],
    'facturas_xml.correo_cuenta_id'            => ['correo_cuentas', 'SET NULL'],
    // Proveedores
    'devoluciones.proveedor_id'                => ['proveedores', 'SET NULL'],
    'proveedor_codigo_erp.proveedor_id'        => ['proveedores', 'CASCADE'],
    // Cargas del ERP
    'facturas_erp.carga_id'                    => ['facturas_erp_cargas', 'SET NULL'],
    'facturas_erp.carga_cambio_id'             => ['facturas_erp_cargas', 'SET NULL'],
    'facturas_erp.factura_xml_id'              => ['facturas_xml', 'SET NULL'],
    'facturas_erp_incidencias.carga_id'        => ['facturas_erp_cargas', 'CASCADE'],
    'facturas_erp_revision.carga_id'           => ['facturas_erp_cargas', 'SET NULL'],
    'facturas_erp_revision.resultado_id'       => ['facturas_erp', 'SET NULL'],
    // Notas de crédito
    'notas_credito_cargas.listado_id'          => ['notas_credito_listados', 'SET NULL'],
    'notas_credito_lineas.carga_id'            => ['notas_credito_cargas', 'SET NULL'],
    'notas_credito_lineas.carga_cambio_id'     => ['notas_credito_cargas', 'SET NULL'],
    'notas_credito_lineas.listado_origen_id'   => ['notas_credito_listados', 'SET NULL'],
    'notas_credito_lineas.factura_erp_id'      => ['facturas_erp', 'SET NULL'],
    'notas_credito_revision.carga_id'          => ['notas_credito_cargas', 'SET NULL'],
    'notas_credito_revision.contenedor_id'     => ['notas_credito_listados', 'SET NULL'],
    'notas_credito_revision.resultado_id'      => ['notas_credito_lineas', 'SET NULL'],
    'notas_credito_verificaciones.listado_id'  => ['notas_credito_listados', 'CASCADE'],
    'notas_credito_historial.listado_id'       => ['notas_credito_listados', 'CASCADE'],
    'notas_credito_historial.verificacion_id'  => ['notas_credito_verificaciones', 'CASCADE'],
    'notas_credito_historial.linea_id'         => ['notas_credito_lineas', 'SET NULL'],
    // Seguimiento
    'seguimiento_bitacora.seguimiento_id'      => ['seguimiento', 'CASCADE'],
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
    echo "SKIP: ClavesForaneasCorreoCargas (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. Las veintiséis, con su regla
// ---------------------------------------------------------------------
$declaradas = [];
foreach ($pdo->query(
    "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchAll() as $f) {
    $declaradas[$f['TABLE_NAME'] . '.' . $f['COLUMN_NAME']] =
        [$f['REFERENCED_TABLE_NAME'], $f['DELETE_RULE']];
}

foreach ($esperadas as $llave => [$referida, $regla]) {
    if (!isset($declaradas[$llave])) {
        comprobarCC(false,
            "{$llave} no tiene clave foránea hacia {$referida}.id. Aplica "
            . 'database/migration_claves_correo_cargas.sql.');
        continue;
    }
    [$referidaReal, $reglaReal] = $declaradas[$llave];
    comprobarCC($referidaReal === $referida,
        "{$llave} apunta a {$referidaReal} y le toca {$referida}.");
    comprobarCC($reglaReal === $regla,
        "{$llave} borra con {$reglaReal} y le toca {$regla}.");
}

// `facturas_erp_revision.contenedor_id` se quedó sin clave a propósito: el
// módulo de facturas del ERP nunca pasa contenedor y la columna vale NULL
// siempre. Si alguien empieza a llenarla, esto lo recuerda.
comprobarCC(
    !isset($declaradas['facturas_erp_revision.contenedor_id']),
    'facturas_erp_revision.contenedor_id tiene clave y se había dejado fuera '
    . 'porque nadie la llena. Si ahora se llena, apúntala en esta lista con su regla.'
);

// ---------------------------------------------------------------------
// 2. Que actúen: un buzón y un listado de mentira
// ---------------------------------------------------------------------
$marca = 'ZZ cc ' . bin2hex(random_bytes(3));
$sociedadId = null;
$cuentaId = null;
$listadoId = null;

$limpiar = function () use ($pdo, $marca, &$sociedadId, &$cuentaId, &$listadoId) {
    try {
        if ($cuentaId !== null) {
            // La bandeja es RESTRICT: hay que vaciarla antes del buzón.
            $pdo->prepare('DELETE FROM correo_bandeja WHERE cuenta_id = ?')->execute([$cuentaId]);
            $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([$cuentaId]);
        }
        if ($listadoId !== null) {
            $pdo->prepare('DELETE FROM notas_credito_listados WHERE id = ?')->execute([$listadoId]);
        }
        SociedadDePrueba::borrar($pdo, $sociedadId);
        $sociedadId = null;
    } catch (Throwable $e) {
        // Lo dirá el informe.
    }
};
$limpiar();

try {
    $sociedadId = SociedadDePrueba::crear($pdo, 'correo cargas');

    // ── Un buzón con índice, carpeta y un documento en la bandeja ──
    $pdo->prepare('INSERT INTO correo_cuentas (nombre, host, usuario, password) VALUES (?, ?, ?, ?)')
        ->execute([$marca, 'imap.ejemplo', $marca, '']);
    $cuentaId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO correo_indice (cuenta_id, carpeta, uid, clave) VALUES (?, ?, 1, ?)')
        ->execute([$cuentaId, 'INBOX', $marca]);
    $pdo->prepare('INSERT INTO correo_carpetas (cuenta_id, carpeta) VALUES (?, ?)')
        ->execute([$cuentaId, 'INBOX']);
    $pdo->prepare('INSERT INTO correo_bandeja (cuenta_id, sociedad_id, uid_correo, archivo_xml) VALUES (?, ?, ?, ?)')
        ->execute([$cuentaId, $sociedadId, $marca, 'x/' . $marca . '.xml']);

    // Con un documento en la bandeja, el buzón NO se puede borrar.
    $borro = true;
    try {
        $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([$cuentaId]);
    } catch (Throwable $e) {
        $borro = false;
    }
    comprobarCC(!$borro,
        'Se borró un buzón que todavía tenía documentos sin importar en su bandeja. '
        . 'Esos documentos quedan con un cuenta_id inexistente y no los ve nadie.');

    // Vaciada la bandeja, sí se borra, y se lleva índice y carpetas.
    $pdo->prepare('DELETE FROM correo_bandeja WHERE cuenta_id = ?')->execute([$cuentaId]);
    $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([$cuentaId]);

    $enIndice = $pdo->prepare('SELECT COUNT(*) FROM correo_indice WHERE cuenta_id = ?');
    $enIndice->execute([$cuentaId]);
    comprobarCC((int) $enIndice->fetchColumn() === 0,
        'El índice del buzón sobrevivió al buzón. Son los mensajes que ninguna '
        . 'pantalla puede mostrar ya, porque todas filtran por cuenta.');

    $enCarpetas = $pdo->prepare('SELECT COUNT(*) FROM correo_carpetas WHERE cuenta_id = ?');
    $enCarpetas->execute([$cuentaId]);
    comprobarCC((int) $enCarpetas->fetchColumn() === 0,
        'Las carpetas del buzón sobrevivieron al buzón.');
    $cuentaId = null;

    // ── Un listado con línea, verificación e historial ──
    $pdo->prepare(
        'INSERT INTO notas_credito_listados
           (sociedad_id, nombre, archivo_origen, archivo_ruta, archivo_hash)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$sociedadId, $marca, $marca . '.pdf', 'x/' . $marca, str_repeat('b', 64)]);
    $listadoId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO notas_credito_lineas
           (listado_id, fila_origen, proveedor_nombre, documento, fecha, moneda, monto)
         VALUES (?, 1, ?, ?, ?, ?, 0)'
    )->execute([$listadoId, $marca, $marca, '2026-09-01', 'CRC']);
    $lineaId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO notas_credito_verificaciones (listado_id) VALUES (?)')
        ->execute([$listadoId]);
    $verificacionId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO notas_credito_historial
           (verificacion_id, listado_id, linea_id, estado_anterior, estado_nuevo)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$verificacionId, $listadoId, $lineaId, 'sin_respaldo', 'coincide']);

    $pdo->prepare('DELETE FROM notas_credito_listados WHERE id = ?')->execute([$listadoId]);
    $listadoId = null;

    $historial = $pdo->prepare('SELECT COUNT(*) FROM notas_credito_historial WHERE verificacion_id = ?');
    $historial->execute([$verificacionId]);
    comprobarCC((int) $historial->fetchColumn() === 0,
        'El historial de notas sobrevivió al listado que se llevó sus líneas. Es el '
        . 'caso del informe: el historial habla de líneas que ya no existen.');

    $verif = $pdo->prepare('SELECT COUNT(*) FROM notas_credito_verificaciones WHERE id = ?');
    $verif->execute([$verificacionId]);
    comprobarCC((int) $verif->fetchColumn() === 0,
        'La verificación sobrevivió a su listado.');

} catch (Throwable $e) {
    comprobarCC(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
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

echo 'OK: ClavesForaneasCorreoCargas (' . count($esperadas)
    . " claves con su regla, y comprobadas en uso)\n";
exit(0);
