<?php
/**
 * Dos sociedades no comparten listados.
 *
 * La validación al importar ya impedía que entrara un XML dirigido a otra
 * empresa, pero eso controla la ENTRADA. Las consultas de Facturas, Por pagar
 * y Devoluciones pedían todo lo que hubiera en la tabla, así que con una sola
 * sociedad el filtro era implícito y con dos se habrían mezclado.
 *
 * Se montan dos sociedades de prueba con un documento cada una y se comprueba
 * que ninguna vea lo de la otra, en cada módulo. Todo se borra al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/models/Semana.php';
require_once __DIR__ . '/../app/models/PorPagar.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';

function assertAislamiento($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
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
    echo "SKIP: SociedadAislamiento (sin base de datos disponible)\n";
    exit(0);
}

$marca = '__test_aislamiento__';
$socA = 0;
$socB = 0;
$proveedorId = 0;

$limpiar = function () use ($pdo, $marca, &$socA, &$socB) {
    foreach ([$socA, $socB] as $id) {
        if ($id <= 0) { continue; }
        $pdo->prepare('DELETE FROM porpagar_facturas WHERE listado_id IN (SELECT id FROM porpagar_listados WHERE sociedad_id = ?)')->execute([$id]);
        $pdo->prepare('DELETE FROM porpagar_listados WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_xml WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_erp WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM semanas WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM correo_cuenta_sociedades WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sociedades WHERE id = ? AND nombre LIKE ?')->execute([$id, $marca . '%']);
    }
};

$proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
if ($proveedorId <= 0) {
    echo "SKIP: SociedadAislamiento (sin proveedores registrados)
";
    exit(0);
}

try {
    // Dos empresas con cédulas que no existen en los datos reales.
    $ins = $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)');
    $ins->execute([$marca . ' A', '3199000000001']);
    $socA = (int) $pdo->lastInsertId();
    $ins->execute([$marca . ' B', '3199000000002']);
    $socB = (int) $pdo->lastInsertId();

    // Una factura XML para cada una, con el mismo proveedor y monto: si algo
    // se filtra, no será por diferencias en los datos.
    $insXml = $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, ?, ?, ?, 'FE', ?, ?, '2026-08-01', 1000.00, ?)"
    );
    $insXml->execute([$socA, '3199000000001', '00100001010000991001', 'A1', '00991001', $proveedorId, $marca . '_a.xml']);
    $insXml->execute([$socB, '3199000000002', '00100001010000991002', 'B1', '00991002', $proveedorId, $marca . '_b.xml']);

    // Una NC para cada una, para el listado de Notas XML.
    $insNc = $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, ?, ?, ?, 'NC', ?, ?, '2026-08-01', 500.00, ?)"
    );
    $insNc->execute([$socA, '3199000000001', '00100001030000991001', 'A2', '00991001', $proveedorId, $marca . '_nca.xml']);
    $insNc->execute([$socB, '3199000000002', '00100001030000991002', 'B2', '00991002', $proveedorId, $marca . '_ncb.xml']);

    // Una factura del ERP para cada una, con EL MISMO documento: es el caso
    // que rompería el cierre semanal si el alcance no funcionara.
    $insErp = $pdo->prepare(
        "INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre, sucursal, tipo,
                                   documento, numero_corto, fecha_emision, origen, moneda, monto, saldo)
         VALUES (?, ?, '140099999', ?, 'CEDI', 'F', '00100001010000991001', '00991001', '2026-08-01', 'Local', '¢', 1000.00, 1000.00)"
    );
    $insErp->execute([$marca . '_a', $socA, $marca . ' PROV']);
    $insErp->execute([$marca . '_b', $socB, $marca . ' PROV']);

    $facturas = new Factura();
    $erp = new FacturaErp();
    $semanas = new Semana();
    $porPagar = new PorPagar();

    // ── Facturas ──
    $listaA = $facturas->setSociedad($socA)->buscarConImportacion();
    $listaB = (new Factura())->setSociedad($socB)->buscarConImportacion();
    assertAislamiento(count($listaA) === 1, 'la sociedad A ve exactamente su factura');
    assertAislamiento(count($listaB) === 1, 'la sociedad B ve exactamente su factura');
    assertAislamiento($listaA[0]['archivo_xml'] === $marca . '_a.xml', 'A ve la suya, no la de B');
    assertAislamiento($listaB[0]['archivo_xml'] === $marca . '_b.xml', 'B ve la suya, no la de A');

    assertAislamiento((new Factura())->setSociedad($socA)->contarFacturas() === 1, 'el conteo de A no incluye a B');
    assertAislamiento((int) (new Factura())->setSociedad($socA)->getTotalMonto() === 1000,
        'el total de A no suma las facturas de B');

    // ── Notas XML ──
    assertAislamiento((new Factura())->setSociedad($socA)->countNotasXml() === 1, 'las NC de A no incluyen las de B');
    $ncA = (new Factura())->setSociedad($socA)->getNotasXml();
    assertAislamiento(count($ncA) === 1 && $ncA[0]['archivo_xml'] === $marca . '_nca.xml',
        'el listado de notas de A trae solo la suya');

    // ── Facturas ERP ──
    assertAislamiento((new FacturaErp())->setSociedad($socA)->contar() === 1, 'el ERP de A no cuenta las filas de B');
    $resumenA = (new FacturaErp())->setSociedad($socA)->resumen();
    assertAislamiento((int) $resumenA['saldo'] === 1000, 'el saldo del ERP de A no suma el de B');

    // ── Matching del pago semanal ──
    // Ambas tienen una factura con el mismo consecutivo: A solo debe ver la suya.
    $candidatasA = (new PorPagar())->setSociedad($socA)->getFacturasParaMatching(0);
    assertAislamiento(count($candidatasA) === 1, 'el matching de A considera solo sus facturas');
    assertAislamiento($candidatasA[0]['consecutivo_completo'] === '00100001010000991001',
        'el matching de A toma su propia factura');

    // ── Semanas ──
    $semanaA = (int) (new Semana())->setSociedad($socA)->crear('Semana A ' . $marca, $socA);
    $semanaB = (int) (new Semana())->setSociedad($socB)->crear('Semana B ' . $marca, $socB);
    assertAislamiento($semanaA > 0 && $semanaB > 0, 'se crean semanas para ambas sociedades');
    assertAislamiento(count((new Semana())->setSociedad($socA)->getAll()) === 1, 'A ve solo su semana');
    assertAislamiento((new Semana())->setSociedad($socA)->existePara($semanaB) === false,
        'A no puede seleccionar una semana de B');
    assertAislamiento((new Semana())->setSociedad($socA)->existePara($semanaA) === true,
        'A sí puede seleccionar la suya');

    // ── Listados por pagar ──
    $listadoA = (int) (new PorPagar())->setSociedad($socA)->crearListado('Listado A', $socA, $marca . '.xlsx', $semanaA);
    (new PorPagar())->setSociedad($socB)->crearListado('Listado B', $socB, $marca . '.xlsx', $semanaB);
    assertAislamiento(count((new PorPagar())->setSociedad($socA)->getListados(50)) === 1,
        'A ve solo su listado por pagar');
    assertAislamiento((new PorPagar())->setSociedad($socB)->getListado($listadoA) === null,
        'B no puede abrir un listado de A por id');

    // ── Buzones de correo ──
    $cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
    if ($cuentaId > 0) {
        $pdo->prepare('INSERT IGNORE INTO correo_cuenta_sociedades (cuenta_id, sociedad_id) VALUES (?, ?)')
            ->execute([$cuentaId, $socA]);
        $cuentas = new CorreoCuenta();
        assertAislamiento(count($cuentas->setSociedad($socA)->getVisibles()) === 1,
            'A ve el buzón que tiene asignado');
        assertAislamiento(count((new CorreoCuenta())->setSociedad($socB)->getVisibles()) === 0,
            'B no ve un buzón que no tiene asignado');
        assertAislamiento((new CorreoCuenta())->setSociedad($socA)->perteneceASociedad($cuentaId) === true,
            'el buzón pertenece a A');
        assertAislamiento((new CorreoCuenta())->setSociedad($socB)->perteneceASociedad($cuentaId) === false,
            'el mismo buzón no pertenece a B');

        // Un buzón puede servir a varias: al agregar B, ambas lo ven.
        $cuentas->asignarSociedades($cuentaId, array_merge($cuentas->sociedadesDe($cuentaId), [$socB]));
        assertAislamiento(count((new CorreoCuenta())->setSociedad($socB)->getVisibles()) === 1,
            'un mismo buzón puede servir a dos sociedades');
    }
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: los módulos no mezclan datos entre sociedades\n";
