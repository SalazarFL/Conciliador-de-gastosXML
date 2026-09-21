<?php
/**
 * Una devolución se le cuenta a su empresa y a ninguna otra.
 *
 * `Devolucion::resumen()` y `pendientesDeVerificar()` filtraban con
 *
 *     WHERE sociedad_id = ? OR sociedad_id IS NULL
 *
 * y eran el único sitio del sistema con ese patrón. `listar()`, en la misma
 * clase, filtra estricto. El síntoma en pantalla era que los totales de arriba
 * no cuadraban con la lista de abajo: el resumen contaba las devoluciones de
 * la empresa MÁS todas las que no tenían empresa, y las mismas huérfanas se
 * las sumaba a cada empresa del grupo. El verificador, además, las reprocesaba
 * una vez por empresa.
 *
 * No fue un descuido: migration_sociedades_alcance.sql ya asignaba las
 * devoluciones sin empresa a la empresa base, y ese `OR ... IS NULL` era el
 * respaldo por si esa migración no se hubiera corrido. El respaldo sobrevivió
 * a su motivo.
 *
 * Se comprueban las dos mitades del arreglo, porque cada una puede deshacerse
 * sola:
 *
 *   1. Que la columna siga siendo NOT NULL. Es lo que impide que vuelvan a
 *      aparecer huérfanas: mientras admita NULL, cualquier escritura sin
 *      empresa activa crea otra.
 *   2. Que resumen(), listar() y pendientesDeVerificar() den lo MISMO para la
 *      misma empresa, con una devolución de otra empresa como testigo. Que
 *      coincidan es justo lo que no pasaba.
 *
 * Uso: php tests/DevolucionPorEmpresaTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Devolucion.php';
require_once __DIR__ . '/soporte/SociedadDePrueba.php';

$fallas = [];

function comprobarDev($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
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
    echo "SKIP: DevolucionPorEmpresa (sin base de datos disponible)\n";
    exit(0);
}

// ---------------------------------------------------------------------
// 1. La columna es obligatoria
// ---------------------------------------------------------------------
$nulable = (string) $pdo->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones'
        AND COLUMN_NAME = 'sociedad_id'"
)->fetchColumn();

comprobarDev($nulable === 'NO',
    'devoluciones.sociedad_id volvió a admitir NULL. Mientras lo admita, una '
    . 'importación sin empresa activa crea otra devolución de nadie: aplica '
    . 'database/migration_devoluciones_sin_empresa.sql.');

// Y no quedan huérfanas de antes.
comprobarDev(
    (int) $pdo->query('SELECT COUNT(*) FROM devoluciones WHERE sociedad_id IS NULL')->fetchColumn() === 0,
    'Quedan devoluciones sin empresa en la base.'
);

// ---------------------------------------------------------------------
// 2. Resumen, lista y pendientes dicen lo mismo
// ---------------------------------------------------------------------
$marca = 'ZZ dev ' . bin2hex(random_bytes(3));
$sociedadA = null;
$sociedadB = null;

$limpiar = function () use ($pdo, $marca, &$sociedadA, &$sociedadB) {
    try {
        $pdo->prepare('DELETE FROM devoluciones WHERE numero LIKE ?')->execute([$marca . '%']);
        SociedadDePrueba::borrar($pdo, $sociedadA);
        SociedadDePrueba::borrar($pdo, $sociedadB);
        $sociedadA = null;
        $sociedadB = null;
    } catch (Throwable $e) {
        // Lo dirá el informe.
    }
};
$limpiar();

try {
    $sociedadA = SociedadDePrueba::crear($pdo, 'dev A');
    $sociedadB = SociedadDePrueba::crear($pdo, 'dev B');

    $insertar = $pdo->prepare(
        'INSERT INTO devoluciones
           (sociedad_id, tipo, numero, proveedor_nombre_erp, fecha, estado,
            archivo_pdf, hash_pdf)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // Dos de A (una pendiente, una verificada) y una de B como testigo.
    $insertar->execute([$sociedadA, 'boleta_local', $marca . '-A1', $marca, '2026-09-01',
        'pendiente', $marca . '-a1.pdf', hash('sha256', $marca . 'a1')]);
    $insertar->execute([$sociedadA, 'boleta_local', $marca . '-A2', $marca, '2026-09-02',
        'verificada', $marca . '-a2.pdf', hash('sha256', $marca . 'a2')]);
    $insertar->execute([$sociedadB, 'boleta_local', $marca . '-B1', $marca, '2026-09-03',
        'pendiente', $marca . '-b1.pdf', hash('sha256', $marca . 'b1')]);

    $modelo = new Devolucion();

    // El resumen de A cuenta 2: las suyas, ni una más.
    $resumenA = $modelo->resumen($sociedadA);
    comprobarDev((int) $resumenA['total'] === 2,
        'El resumen de la empresa A dice ' . (int) $resumenA['total'] . ' y son 2. '
        . 'Si dice de más, está contando devoluciones que no son suyas: es el '
        . 'defecto del `OR sociedad_id IS NULL`.');
    comprobarDev((int) $resumenA['pendiente'] === 1 && (int) $resumenA['verificada'] === 1,
        'El resumen de A no reparte bien por estado: '
        . json_encode(['pendiente' => $resumenA['pendiente'], 'verificada' => $resumenA['verificada']]));

    // Y el de B cuenta 1, con las mismas huérfanas alrededor si las hubiera.
    $resumenB = $modelo->resumen($sociedadB);
    comprobarDev((int) $resumenB['total'] === 1,
        'El resumen de la empresa B dice ' . (int) $resumenB['total'] . ' y es 1.');

    // Lo que importa: el resumen y la lista tienen que cuadrar.
    $listaA = $modelo->listar(['sociedad_id' => $sociedadA]);
    $listaA = array_values(array_filter($listaA, function ($d) use ($marca) {
        return strpos((string) $d['numero'], $marca) === 0;
    }));
    comprobarDev(count($listaA) === (int) $resumenA['total'],
        'El resumen de A dice ' . (int) $resumenA['total'] . ' y la lista muestra '
        . count($listaA) . '. Los números de arriba tienen que cuadrar con lo que '
        . 'se ve abajo: no cuadrar es exactamente lo que se veía en pantalla.');

    // Y el verificador no toma trabajo ajeno.
    $pendientesA = $modelo->pendientesDeVerificar($sociedadA);
    $idsA = array_column($pendientesA, 'id');
    $ajenas = $pdo->prepare(
        'SELECT COUNT(*) FROM devoluciones WHERE sociedad_id <> ? AND id IN ('
        . ($idsA ? implode(',', array_map('intval', $idsA)) : '0') . ')'
    );
    $ajenas->execute([$sociedadA]);
    comprobarDev((int) $ajenas->fetchColumn() === 0,
        'pendientesDeVerificar de A devolvió devoluciones de otra empresa: el '
        . 'verificador reprocesaría el mismo trabajo una vez por empresa del grupo.');

} catch (Throwable $e) {
    comprobarDev(false, 'La prueba de comportamiento no pudo correr: ' . $e->getMessage());
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

echo "OK: DevolucionPorEmpresa (resumen, lista y pendientes cuadran por empresa)\n";
exit(0);
