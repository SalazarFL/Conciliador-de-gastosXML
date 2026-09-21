<?php
/**
 * Sin empresa seleccionada no se ve NADA (antes se veía todo).
 *
 * El alcance por sociedad resolvía a un id y, si ese id era 0, no ponía
 * filtro. Pero al 0 se llegaba por dos caminos que no significan lo mismo:
 *
 *   - alguien escribió `setSociedad(0)`: quiere ver todas las empresas;
 *   - la resolución no encontró ninguna —ni en la sesión, ni en la
 *     preferencia del usuario, ni una activa a la que caer—.
 *
 * El segundo caso es ignorancia, no permiso, y sin embargo abría el listado
 * entero: los documentos de todas las empresas en la pantalla de alguien de
 * quien no se sabe a cuál pertenece.
 *
 * Aquí se comprueban los tres alcances contra la misma base y los mismos
 * documentos, porque lo que se está probando es exactamente la diferencia
 * entre ellos.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/soporte/SociedadDePrueba.php';

/**
 * Los tres alcances necesitan un modelo cuya resolución automática sea
 * predecible. Se sobreescribe el único punto por el que el alcance pregunta
 * "¿en qué empresa estamos?", que es lo que hace real el caso a probar: en
 * producción a ese 0 se llega por una sesión sin empresa o por una base sin
 * ninguna activa, y ninguna de las dos cosas se puede montar aquí sin
 * estropearle la base a las demás pruebas.
 */
class FacturaSinEmpresa extends Factura
{
    public static function sociedadSeleccionadaId() { return 0; }
}
class FacturaErpSinEmpresa extends FacturaErp
{
    public static function sociedadSeleccionadaId() { return 0; }
}

$fallas = [];
function comprueba(&$fallas, $condicion, $mensaje)
{
    if (!$condicion) { $fallas[] = $mensaje; }
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
    echo "SKIP: SinEmpresaNoSeVeNada (sin base de datos disponible)\n";
    exit(0);
}

$marca       = 'ZZ sinempresa ' . bin2hex(random_bytes(3));
$sociedadId  = 0;
$proveedorId = 0;

$limpiar = function () use ($pdo, $marca, &$sociedadId, &$proveedorId) {
    try {
        if ($sociedadId > 0) {
            $pdo->prepare('DELETE FROM facturas_xml WHERE sociedad_id = ?')->execute([$sociedadId]);
            $pdo->prepare('DELETE FROM facturas_erp WHERE sociedad_id = ?')->execute([$sociedadId]);
        }
        SociedadDePrueba::borrar($pdo, $sociedadId);
        if ($proveedorId > 0) {
            $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([$proveedorId]);
        }
    } catch (Throwable $e) {
        // Se llama desde el cierre, donde ya puede haber un fallo que contar.
    }
};

try {
    $sociedadId = SociedadDePrueba::crear($pdo, 'sinempresa');

    // Proveedor propio y no el primero que haya: la base de trabajo está
    // vacía, y una prueba que se salte por falta de datos no prueba nada.
    $pdo->prepare('INSERT INTO proveedores (rfc, razon_social, razon_social_normalizada) VALUES (?, ?, ?)')
        ->execute(['3' . random_int(100000000, 999999999), $marca, strtoupper($marca)]);
    $proveedorId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, '3199000000009', ?, ?, 'FE', '00991009', ?, '2026-08-01', 1000.00, ?)"
    )->execute([$sociedadId, '0010000101' . $marca, 'CLV' . $marca, $proveedorId, $marca . '.xml']);

    // Una nota de crédito también, que tiene su propio listado y su conteo.
    $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, '3199000000009', ?, ?, 'NC', '00991009', ?, '2026-08-01', 500.00, ?)"
    )->execute([$sociedadId, '0010000103' . $marca, 'NCC' . $marca, $proveedorId, $marca . '_nc.xml']);

    $pdo->prepare(
        "INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre, sucursal, tipo,
                                   documento, numero_corto, fecha_emision, origen, moneda, monto, saldo)
         VALUES (?, ?, '140099999', ?, 'CEDI', 'F', ?, '00991009', '2026-08-01', 'Local', '¢', 1000.00, 1000.00)"
    )->execute(['ERP' . $marca, $sociedadId, $marca, '0010000101' . $marca]);

    // ── 1. Sin empresa: nada ──────────────────────────────────────────────
    $ciego = new FacturaSinEmpresa();
    comprueba($fallas, $ciego->sociedadId() === 0,
        'el modelo sin empresa resuelve a 0 (si no, el resto de la prueba no prueba nada)');
    comprueba($fallas, $ciego->contarFacturas() === 0,
        'sin empresa el conteo de facturas es 0');
    comprueba($fallas, count((new FacturaSinEmpresa())->buscarConImportacion()) === 0,
        'sin empresa el listado de facturas viene vacío');
    comprueba($fallas, (int) (new FacturaSinEmpresa())->getTotalMonto() === 0,
        'sin empresa el total en colones es 0');
    comprueba($fallas, (new FacturaSinEmpresa())->countNotasXml() === 0,
        'sin empresa no se cuentan notas de crédito');
    comprueba($fallas, (new FacturaErpSinEmpresa())->contar() === 0,
        'sin empresa el ERP no cuenta nada');

    // findById es el camino por el que se abre un documento con su id en la
    // barra de direcciones: es el que de verdad filtra datos de una empresa.
    $idXml = (int) $pdo->query('SELECT id FROM facturas_xml WHERE archivo_xml = ' . $pdo->quote($marca . '.xml'))->fetchColumn();
    comprueba($fallas, (new FacturaSinEmpresa())->findById($idXml) === null,
        'sin empresa no se abre un documento aunque se sepa su id');

    // ── 2. Con su empresa: lo suyo ────────────────────────────────────────
    comprueba($fallas, (new Factura())->setSociedad($sociedadId)->contarFacturas() === 1,
        'la empresa dueña sí ve su factura');
    comprueba($fallas, (new FacturaErp())->setSociedad($sociedadId)->contar() === 1,
        'la empresa dueña sí ve su factura del ERP');

    // ── 3. setSociedad(0) a propósito: todas ──────────────────────────────
    // Se pide desde una subclase que resuelve a 0 justamente para separar las
    // dos cosas: aquí el 0 viene de quien llama, no de la resolución.
    comprueba($fallas, (new FacturaSinEmpresa())->setSociedad(0)->contarFacturas() >= 1,
        'setSociedad(0) sigue viéndolo todo: es una decisión, no una ausencia');
    comprueba($fallas, (new FacturaSinEmpresa())->setSociedad(0)->alcanceEsTodas() === true
        && (new FacturaSinEmpresa())->alcanceEsTodas() === false,
        'el modelo distingue "todas" de "ninguna"');
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();

if ($fallas) {
    foreach ($fallas as $falla) { fwrite(STDERR, "FAIL: {$falla}\n"); }
    exit(1);
}
echo "OK: sin empresa seleccionada no se ve nada, y setSociedad(0) sigue viéndolo todo\n";
