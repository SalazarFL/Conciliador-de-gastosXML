<?php
/**
 * Un seguimiento no sobrevive al documento del que habla.
 *
 * `seguimiento` apunta a tres tablas distintas según diga `origen`, y una
 * clave foránea no puede apuntar a tres tablas. Por eso `referencia_id` nunca
 * tuvo ninguna, y nada limpiaba los seguimientos cuando su documento se
 * borraba.
 *
 * Lo grave era la cadena: `notas_credito_lineas` se borra EN CASCADA al borrar
 * su listado, así que una carga entera se llevaba sus líneas de golpe y dejaba
 * los seguimientos —responsable, motivo, recordatorio— apuntando a ids que ya
 * no existen. Un trigger no habría servido: en MySQL y MariaDB los triggers no
 * se disparan en los borrados por cascada.
 *
 * La solución no parte el par polimórfico en tres columnas —son 72 usos en un
 * modelo de 2.200 líneas— sino que le añade tres columnas GENERADAS que
 * derivan de él, una por tabla destino, cada una con su clave. Valen NULL
 * cuando el origen no es el suyo, y una clave foránea ignora los NULL.
 *
 * Lo que se comprueba aquí es lo que hace que esa idea funcione o no:
 *
 *   1. Que las tres columnas sigan siendo GENERADAS. Si alguien las convierte
 *      en columnas normales, dejan de derivarse solas y a la primera escritura
 *      que no las ponga quedan en NULL: la clave deja de mirar nada y el
 *      arreglo desaparece sin que nada falle.
 *   2. Que cada origen mire SOLO su tabla. Una nota de crédito con el id 40 y
 *      una factura con el id 40 son cosas distintas.
 *   3. Que la cascada llegue EN CADENA: listado -> líneas -> seguimiento.
 *      Es el caso que motivó todo.
 *   4. Que un `referencia_id` inventado ya no entre.
 *
 * Uso: php tests/SeguimientoAmarradoTest.php
 */
require_once __DIR__ . '/soporte/SociedadDePrueba.php';

$fallas = [];

function comprobarSeg($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

/** columna derivada => [tabla a la que amarra, orígenes que la llenan]. */
$derivadas = [
    'ref_factura_xml' => ['facturas_xml',         ['xml_factura', 'xml_nota']],
    'ref_nota_linea'  => ['notas_credito_lineas', ['nota_credito']],
    'ref_factura_erp' => ['facturas_erp',         ['factura']],
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
    echo "SKIP: SeguimientoAmarrado (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. Las columnas siguen siendo generadas, y cada una con su clave
// ---------------------------------------------------------------------
$columnas = [];
foreach ($pdo->query(
    "SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento'"
)->fetchAll() as $f) {
    $columnas[$f['COLUMN_NAME']] = $f;
}

$claves = [];
foreach ($pdo->query(
    "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
        AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
      WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'seguimiento'
        AND k.REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchAll() as $f) {
    $claves[$f['COLUMN_NAME']] = [$f['REFERENCED_TABLE_NAME'], $f['DELETE_RULE']];
}

foreach ($derivadas as $columna => [$tabla, $origenes]) {
    if (!isset($columnas[$columna])) {
        comprobarSeg(false,
            "seguimiento.{$columna} no existe. Aplica "
            . 'database/migration_seguimiento_referencias.sql.');
        continue;
    }

    comprobarSeg(
        stripos((string) $columnas[$columna]['EXTRA'], 'STORED GENERATED') !== false,
        "seguimiento.{$columna} dejó de ser una columna generada STORED "
        . "(EXTRA = '{$columnas[$columna]['EXTRA']}'). Si es una columna normal, "
        . 'ya no se deriva sola: la primera escritura que no la ponga la deja en '
        . 'NULL, la clave deja de mirar nada y el amarre desaparece en silencio.'
    );

    // Que la fórmula siga nombrando a sus orígenes y a ningún otro.
    $formula = (string) $columnas[$columna]['GENERATION_EXPRESSION'];
    foreach ($origenes as $o) {
        comprobarSeg(strpos($formula, $o) !== false,
            "La fórmula de {$columna} ya no menciona el origen '{$o}': "
            . "los seguimientos de ese origen quedarían sin amarrar. [{$formula}]");
    }

    if (!isset($claves[$columna])) {
        comprobarSeg(false, "seguimiento.{$columna} no tiene clave foránea hacia {$tabla}.");
        continue;
    }
    [$referida, $regla] = $claves[$columna];
    comprobarSeg($referida === $tabla,
        "{$columna} apunta a {$referida} y le toca {$tabla}.");
    comprobarSeg($regla === 'CASCADE',
        "{$columna} borra con {$regla} y le toca CASCADE. Un seguimiento sin "
        . 'documento no se puede mostrar ni reasignar: no hay pantalla que lo liste.');
}

// ---------------------------------------------------------------------
// 2, 3 y 4. Que funcione, y que funcione EN CADENA
// ---------------------------------------------------------------------
$marca = 'ZZ seg ' . bin2hex(random_bytes(3));
$sociedadId = null;
$listadoId = null;
$lineaId = null;
$proveedorId = null;
$facturaId = null;

$limpiar = function () use ($pdo, $marca, &$sociedadId, &$listadoId, &$facturaId, &$proveedorId) {
    try {
        if ($facturaId !== null) {
            $pdo->prepare('DELETE FROM facturas_xml WHERE id = ?')->execute([$facturaId]);
        }
        if ($listadoId !== null) {
            $pdo->prepare('DELETE FROM notas_credito_listados WHERE id = ?')->execute([$listadoId]);
        }
        if ($proveedorId !== null) {
            $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([$proveedorId]);
        }
        SociedadDePrueba::borrar($pdo, $sociedadId);
        $sociedadId = null;
    } catch (Throwable $e) {
        // Lo dirá el informe de abajo.
    }
};
$limpiar();

try {
    $sociedadId = SociedadDePrueba::crear($pdo, 'seguimiento');

    // Un listado con una línea dentro: la cadena que importa.
    $pdo->prepare(
        'INSERT INTO notas_credito_listados
           (sociedad_id, nombre, archivo_origen, archivo_ruta, archivo_hash)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$sociedadId, $marca, $marca . '.pdf', 'x/' . $marca, str_repeat('a', 64)]);
    $listadoId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO notas_credito_lineas
           (listado_id, fila_origen, proveedor_nombre, documento, fecha, moneda, monto)
         VALUES (?, 1, ?, ?, ?, ?, 0)'
    )->execute([$listadoId, $marca, $marca, '2026-09-01', 'CRC']);
    $lineaId = (int) $pdo->lastInsertId();

    // Y una factura XML, para comprobar que los orígenes no se mezclan.
    $pdo->prepare('INSERT INTO proveedores (razon_social, razon_social_normalizada) VALUES (?, ?)')
        ->execute([$marca, $marca]);
    $proveedorId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO facturas_xml
           (consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision, archivo_xml, sociedad_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$marca, '00000001', $proveedorId, '2026-09-01', $marca . '.xml', $sociedadId]);
    $facturaId = (int) $pdo->lastInsertId();

    $ponerSeguimiento = $pdo->prepare(
        'INSERT INTO seguimiento (origen, referencia_id, estado, responsable) VALUES (?, ?, ?, ?)'
    );
    $ponerSeguimiento->execute(['nota_credito', $lineaId, 'pendiente', $marca]);
    $ponerSeguimiento->execute(['xml_factura', $facturaId, 'pendiente', $marca]);

    // 2. Cada origen mira solo su tabla: las derivadas lo demuestran.
    $ver = $pdo->prepare(
        'SELECT origen, referencia_id, ref_factura_xml, ref_nota_linea, ref_factura_erp
           FROM seguimiento WHERE responsable = ? ORDER BY origen'
    );
    $ver->execute([$marca]);
    $filas = $ver->fetchAll();

    comprobarSeg(count($filas) === 2, 'no se guardaron los dos seguimientos de prueba.');
    foreach ($filas as $f) {
        if ($f['origen'] === 'nota_credito') {
            comprobarSeg((int) $f['ref_nota_linea'] === $lineaId
                && $f['ref_factura_xml'] === null && $f['ref_factura_erp'] === null,
                'Un seguimiento de nota_credito llenó una derivada que no es la suya. '
                . 'Los ids de tablas distintas se pisarían entre sí.');
        } else {
            comprobarSeg((int) $f['ref_factura_xml'] === $facturaId
                && $f['ref_nota_linea'] === null && $f['ref_factura_erp'] === null,
                'Un seguimiento de xml_factura llenó una derivada que no es la suya.');
        }
    }

    // 4. Un id inventado, con un origen que sí amarra, ya no entra.
    $entroBasura = true;
    try {
        $ponerSeguimiento->execute(['nota_credito', 2147483600, 'pendiente', $marca]);
    } catch (Throwable $e) {
        $entroBasura = false;
    }
    comprobarSeg(!$entroBasura,
        'Se pudo crear un seguimiento de una línea de nota que no existe. La clave '
        . 'está declarada pero no actúa: revisa FOREIGN_KEY_CHECKS y que la tabla sea InnoDB.');

    // 3. LA CADENA: borrar el listado borra sus líneas por cascada, y eso
    //    tiene que borrar sus seguimientos por cascada también.
    $pdo->prepare('DELETE FROM notas_credito_listados WHERE id = ?')->execute([$listadoId]);
    $listadoId = null;

    $quedanLineas = $pdo->prepare('SELECT COUNT(*) FROM notas_credito_lineas WHERE id = ?');
    $quedanLineas->execute([$lineaId]);
    comprobarSeg((int) $quedanLineas->fetchColumn() === 0,
        'Borrar el listado no borró su línea: la cascada de siempre dejó de funcionar '
        . 'y esta prueba no está comprobando lo que cree.');

    $ver->execute([$marca]);
    $quedan = $ver->fetchAll();
    $deNota = array_filter($quedan, fn($f) => $f['origen'] === 'nota_credito');

    comprobarSeg(count($deNota) === 0,
        'El seguimiento de la nota sobrevivió al listado que se llevó su línea. '
        . 'Es el caso exacto que motivó la migración: una carga entera borrada deja '
        . 'los seguimientos apuntando a ids que ya no existen.');

    // Y el de la factura XML, que no tenía nada que ver, sigue donde estaba.
    comprobarSeg(count($quedan) === 1,
        'La cascada del listado se llevó por delante un seguimiento de otro origen.');

} catch (Throwable $e) {
    comprobarSeg(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
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

echo "OK: SeguimientoAmarrado (3 derivadas con su clave, y la cascada llega en cadena)\n";
exit(0);
