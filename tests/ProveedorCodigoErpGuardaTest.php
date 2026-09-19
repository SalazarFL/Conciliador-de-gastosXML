<?php
/**
 * La guarda de cédula, ahora que hay catálogo.
 *
 * Lo que protege: el consecutivo de veinte dígitos NO es único a nivel país
 * —lo arma cada emisor empezando en 1— así que dos proveedores pueden
 * compartirlo. Sin guarda, `buscarXml()` se llevaba el primero de la lista y
 * el comprobante correcto, que estaba más abajo, se perdía.
 *
 * Lo que cambió: antes la equivalencia código↔cédula se DEDUCÍA de las
 * facturas que cuadraban de monto, con contadores, conflictos y un estado de
 * "disputa" en el que la guarda se abstenía. Todo eso suplía un dato que no
 * se tenía. Ahora el catálogo del ERP lo trae escrito, así que la regla es
 * binaria: o la cédula del emisor es la del código, o no.
 *
 * Usa códigos y cédulas sintéticos (99xxxxxxx) para no tocar datos reales, y
 * borra lo suyo al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';

function assertGuarda($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertGuardaIgual($esperado, $actual, $mensaje)
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
    echo "SKIP: ProveedorCodigoErpGuarda (sin base de datos disponible)\n";
    exit(0);
}

// Una sociedad con dos puntos de venta, y un proveedor ajeno.
$COD_1 = '990000001';
$COD_2 = '990000002';   // mismo dueño que COD_1, otro punto de venta
$COD_FUERA = '990000009';   // no se da de alta en el catálogo
$CEDULA_A = '9990000001';
$CEDULA_B = '9990000002';

$limpiar = function () use ($pdo, $COD_1, $COD_2, $COD_FUERA, $CEDULA_A, $CEDULA_B) {
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?,?,?)')
        ->execute([$COD_1, $COD_2, $COD_FUERA]);
    $pdo->prepare('DELETE FROM proveedor_cedula_ignorada WHERE cedula IN (?,?)')
        ->execute([$CEDULA_A, $CEDULA_B]);
    $pdo->prepare('DELETE FROM proveedores WHERE rfc IN (?,?)')->execute([$CEDULA_A, $CEDULA_B]);
    ProveedorCodigoErp::olvidarMapa();
};
$limpiar();

try {
    $mapa = new ProveedorCodigoErp();

    // ── Sin catálogo, la guarda no opina ───────────────────────────────
    //
    // La red de seguridad: en una instalación a la que todavía no se le cargó
    // el listado del ERP, "el código no está" y "no hay catálogo" son cosas
    // distintas, y la segunda no autoriza a rechazar nada.
    $hayCatalogo = (int) $pdo->query('SELECT COUNT(*) n FROM proveedor_codigo_erp')->fetch()['n'] > 0;
    if (!$hayCatalogo) {
        assertGuardaIgual('desconocido', ProveedorCodigoErp::veredicto($COD_1, 1),
            'sin catálogo cargado la guarda se abstiene');
    }

    // ── Dos códigos, una cédula ────────────────────────────────────────
    //
    // Es el caso real: una sociedad con varios puntos de venta tiene un código
    // por punto y una sola cédula. Los dos tienen que emparejar con sus XML.
    $r = $mapa->guardarCodigo($COD_1, $CEDULA_A, 'PUNTO DE VENTA UNO');
    assertGuarda($r['ok'], 'se puede dar de alta un código a mano: ' . $r['message']);
    $r = $mapa->guardarCodigo($COD_2, $CEDULA_A, 'PUNTO DE VENTA DOS');
    assertGuarda($r['ok'], 'y otro código de la misma cédula');
    $r = $mapa->guardarCodigo('990000003', $CEDULA_B, 'PROVEEDOR AJENO');
    assertGuarda($r['ok'], 'y uno de otra cédula');
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute(['990000003']);

    $provA = (int) $pdo->query("SELECT id FROM proveedores WHERE rfc = '{$CEDULA_A}'")->fetch()['id'];
    $provB = (int) $pdo->query("SELECT id FROM proveedores WHERE rfc = '{$CEDULA_B}'")->fetch()['id'];
    ProveedorCodigoErp::olvidarMapa();

    // ── Los veredictos ─────────────────────────────────────────────────
    //
    // Dos códigos con la misma cédula ya no dan 'propio'. La cédula prueba que
    // el comprobante salió de esa empresa y nada más: cuál de sus dos puntos
    // lo emitió no está escrito en ninguna parte, y engancharlo al que venga
    // primero es exactamente lo que hay que dejar de hacer.
    assertGuardaIgual('compartido', ProveedorCodigoErp::veredicto($COD_1, $provA),
        'con la cédula repartida entre dos códigos, la guarda no elige');
    assertGuardaIgual('compartido', ProveedorCodigoErp::veredicto($COD_2, $provA),
        'y tampoco por el otro lado');
    assertGuardaIgual('ajeno', ProveedorCodigoErp::veredicto($COD_1, $provB),
        'el emisor de otro proveedor se rechaza');

    // Con la cédula en un solo código sí identifica, que es el caso corriente:
    // 1 653 de los 1 684 del listado real.
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute([$COD_2]);
    ProveedorCodigoErp::olvidarMapa();
    assertGuardaIgual('propio', ProveedorCodigoErp::veredicto($COD_1, $provA),
        'con un solo código por cédula, el emisor que corresponde se acepta');
    $mapa->guardarCodigo($COD_2, $CEDULA_A, 'PUNTO DE VENTA DOS');
    ProveedorCodigoErp::olvidarMapa();

    // El cambio de regla más importante de todos.
    assertGuardaIgual('ajeno', ProveedorCodigoErp::veredicto($COD_FUERA, $provA),
        'un código que el catálogo no tiene no empareja con nada');

    assertGuardaIgual('desconocido', ProveedorCodigoErp::veredicto($COD_1, 0),
        'un XML sin emisor no se puede juzgar');
    assertGuardaIgual('desconocido', ProveedorCodigoErp::veredicto('', $provA),
        'ni una línea sin código');

    // ── La pregunta al revés: ¿este comprobante es de alguien del ERP? ──
    assertGuardaIgual(true, ProveedorCodigoErp::conoceCedula($CEDULA_A),
        'una cédula del catálogo se conoce');
    // El ERP a veces las imprime con guiones ("3-101-155686") y los XML nunca.
    $conGuiones = substr($CEDULA_A, 0, 1) . '-' . substr($CEDULA_A, 1, 3) . '-' . substr($CEDULA_A, 4);
    assertGuardaIgual(true, ProveedorCodigoErp::conoceCedula($conGuiones),
        'los guiones no la vuelven otra cédula');
    assertGuardaIgual(false, ProveedorCodigoErp::conoceCedula('9995550000'),
        'una cédula que no está en ningún código, no');

    // ── "Este proveedor no nos factura" ────────────────────────────────
    //
    // Sin dónde anotarlo, la misma pregunta volvería con cada comprobante que
    // llegara de esa cédula.
    $r = $mapa->ignorarCedula('9995550000', 'PUBLICIDAD S.A.', 'No nos factura');
    assertGuarda($r['ok'], 'se puede mandar a callar una cédula');
    ProveedorCodigoErp::olvidarMapa();
    assertGuardaIgual(true, ProveedorCodigoErp::conoceCedula('9995550000'),
        'una cédula ignorada cuenta como contestada: no se vuelve a preguntar');

    $mapa->dejarDeIgnorar('9995550000');
    ProveedorCodigoErp::olvidarMapa();
    assertGuardaIgual(false, ProveedorCodigoErp::conoceCedula('9995550000'),
        'y se puede volver atrás');

    // Darle un código a una cédula ignorada la saca del silencio: son dos
    // respuestas a la misma pregunta y no pueden convivir.
    $mapa->ignorarCedula($CEDULA_B, 'PROVEEDOR AJENO');
    $mapa->guardarCodigo($COD_FUERA, $CEDULA_B, 'PROVEEDOR AJENO');
    $n = $pdo->query("SELECT COUNT(*) n FROM proveedor_cedula_ignorada WHERE cedula = '{$CEDULA_B}'")->fetch()['n'];
    assertGuardaIgual(0, (int) $n, 'darle un código deja sin efecto el "no procesar"');

    // ── Corregir y borrar ──────────────────────────────────────────────
    //
    // Se saca antes el otro código de esa cédula: si no, al mudar COD_1 la
    // cédula B queda con dos y el veredicto sería 'compartido', que es cierto
    // pero no es lo que esta prueba quiere comprobar.
    $mapa->eliminarCodigo($COD_FUERA);
    $r = $mapa->guardarCodigo($COD_1, $CEDULA_B, 'CAMBIÓ DE DUEÑO');
    assertGuarda($r['ok'], 'un código se puede corregir');
    ProveedorCodigoErp::olvidarMapa();
    assertGuardaIgual('propio', ProveedorCodigoErp::veredicto($COD_1, $provB),
        'y desde entonces vale la cédula nueva');
    assertGuardaIgual('ajeno', ProveedorCodigoErp::veredicto($COD_1, $provA),
        'la vieja pasa a ser ajena');

    $r = $mapa->eliminarCodigo($COD_1);
    assertGuarda($r['ok'], 'un código se puede borrar');
    ProveedorCodigoErp::olvidarMapa();
    assertGuardaIgual('ajeno', ProveedorCodigoErp::veredicto($COD_1, $provB),
        'y borrado deja de emparejar con nada');
    assertGuarda(!$mapa->eliminarCodigo($COD_1)['ok'], 'borrar lo que no está lo dice');

    // ── Lo que no se admite ────────────────────────────────────────────
    assertGuarda(!$mapa->guardarCodigo('', $CEDULA_A)['ok'], 'sin código no se guarda');
    assertGuarda(!$mapa->guardarCodigo('99000X', '123')['ok'], 'una cédula corta no se guarda');
    assertGuarda(!$mapa->guardarCodigo('con espacio', $CEDULA_A)['ok'], 'un código con espacios tampoco');

    echo "OK: ProveedorCodigoErpGuarda\n";
} finally {
    $limpiar();
}
