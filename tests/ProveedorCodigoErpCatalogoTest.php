<?php
/**
 * La importación del catálogo de proveedores del ERP.
 *
 * Dos cosas se prueban acá:
 *
 *  1. Varios códigos con la misma cédula se aprenden TODOS. No es un caso raro
 *     ni un error del ERP: una sociedad con varios puntos de venta se da de
 *     alta una vez por punto —mismo dueño, mismos comprobantes, códigos
 *     distintos— y quedarse con uno solo dejaría a los demás sin guarda.
 *
 *  2. Lo que una persona corrigió a mano no lo pisa el archivo. El listado del
 *     ERP es la fuente normal, pero si alguien tuvo que arreglar un código es
 *     porque sabía algo que el archivo no decía, y volver a exportarlo no
 *     puede deshacerlo en silencio.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';

function assertCat($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertCatIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: ProveedorCodigoErpCatalogo (sin base de datos disponible)\n";
    exit(0);
}

// Códigos y cédulas que no existen: nueve dígitos que empiezan en 9990, fuera
// del rango 1400xxxxx que usa el ERP de verdad.
$COD = ['999900001', '999900002', '999900003', '999900004'];
$CED_BUENA = '999888777';
$CED_OTRA  = '999111222';

$limpiar = function () use ($pdo, $COD, $CED_BUENA, $CED_OTRA) {
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?,?,?,?)')->execute($COD);
    $pdo->prepare('DELETE FROM proveedores WHERE rfc IN (?,?)')->execute([$CED_BUENA, $CED_OTRA]);
    ProveedorCodigoErp::olvidarMapa();
};
$limpiar();

try {
    $modelo = new ProveedorCodigoErp();

    // El catálogo: cuatro códigos de una sola cédula.
    $catalogo = [];
    foreach ($COD as $k => $c) {
        $catalogo[] = ['codigo' => $c, 'cedula' => $CED_BUENA, 'nombre' => 'PUNTO DE VENTA ' . ($k + 1)];
    }

    // ── Simular no escribe nada ────────────────────────────────────────
    $plan = $modelo->importarDesdeErp($catalogo, true);
    assertCatIgual(4, $plan['leidos'], 'el plan lee las cuatro filas');
    assertCatIgual(4, $plan['nuevos'], 'las cuatro son códigos que el catálogo no tenía');
    assertCatIgual(1, $plan['proveedores_creados'], 'y una sola cédula que dar de alta');

    $cuantos = $pdo->prepare('SELECT COUNT(*) n FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?,?,?,?)');
    $cuantos->execute($COD);
    assertCatIgual(0, (int) $cuantos->fetch()['n'], 'simular no puede escribir nada');

    // ── Importar de verdad ─────────────────────────────────────────────
    $r = $modelo->importarDesdeErp($catalogo);
    assertCatIgual(1, $r['proveedores_creados'], 'la cédula nueva se da de alta una sola vez');

    $cuantos->execute($COD);
    assertCatIgual(4, (int) $cuantos->fetch()['n'], 'los cuatro códigos quedan en el catálogo');

    // ── Los cuatro cuelgan de la misma cédula ──────────────────────────
    $s = $pdo->prepare('SELECT proveedor_codigo, proveedor_id, cedula, origen
                          FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?,?,?,?)');
    $s->execute($COD);
    $ids = [];
    foreach ($s->fetchAll() as $f) {
        assertCatIgual($CED_BUENA, $f['cedula'], "el código {$f['proveedor_codigo']} toma la cédula del ERP");
        assertCatIgual('erp', $f['origen'], 'y queda marcado como dato del ERP');
        $ids[(int) $f['proveedor_id']] = true;
    }
    assertCatIgual(1, count($ids), 'los cuatro apuntan al mismo proveedor');

    // Y la guarda los reconoce a los cuatro como del mismo grupo, sin elegir
    // entre ellos: un comprobante de esa cédula salió de la empresa, pero de
    // cuál de sus cuatro puntos no lo dice la cédula. Lo elige una persona.
    ProveedorCodigoErp::olvidarMapa();
    $provId = (int) array_key_first($ids);
    foreach ($COD as $c) {
        assertCatIgual('compartido', ProveedorCodigoErp::veredicto($c, $provId),
            "el punto de venta {$c} reconoce la cédula, pero no se adjudica el comprobante");
    }

    // ── Volver a importar confirma, no duplica ─────────────────────────
    $r2 = $modelo->importarDesdeErp($catalogo);
    assertCatIgual(4, $r2['confirmados'], 'la segunda importación confirma lo que ya estaba');
    assertCatIgual(0, $r2['nuevos'], 'y no inventa códigos nuevos');
    $cuantos->execute($COD);
    assertCatIgual(4, (int) $cuantos->fetch()['n'], 'siguen siendo cuatro');

    // ── Lo corregido a mano le gana al archivo ─────────────────────────
    //
    // Si una persona tuvo que arreglar un código es porque sabía algo que el
    // archivo no decía. Volver a exportar el listado no puede deshacerlo.
    $modelo->guardarCodigo($COD[0], $CED_OTRA, 'CORREGIDO A MANO');
    ProveedorCodigoErp::olvidarMapa();

    $modelo->importarDesdeErp($catalogo);
    $s->execute($COD);
    foreach ($s->fetchAll() as $f) {
        if ($f['proveedor_codigo'] === $COD[0]) {
            assertCatIgual($CED_OTRA, $f['cedula'], 'el archivo no pisa la corrección a mano');
            assertCatIgual('manual', $f['origen'], 'y conserva que fue una persona quien lo puso');
        } else {
            assertCatIgual($CED_BUENA, $f['cedula'], 'los demás sí se actualizan desde el archivo');
        }
    }

    // ── Una fila sin cédula no enseña nada ─────────────────────────────
    $plan = $modelo->importarDesdeErp([['codigo' => '999900009', 'cedula' => '', 'nombre' => 'SIN CEDULA']], true);
    assertCatIgual(1, $plan['sin_cedula'], 'una fila sin cédula se cuenta');
    assertCatIgual(0, $plan['nuevos'], 'pero no entra al catálogo: no hay a qué anclarla');

    echo "OK: ProveedorCodigoErpCatalogo\n";
} finally {
    $limpiar();
}
