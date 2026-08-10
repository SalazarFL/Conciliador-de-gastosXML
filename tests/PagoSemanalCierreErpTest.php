<?php
/** Integración del cierre semanal con el estado de Facturas ERP. */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/PorPagar.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertCierreErp($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
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
    echo "SKIP: PagoSemanalCierreErp (sin base de datos disponible)\n";
    exit(0);
}

$codigo = '990099981';
// Código interno del ERP: no es la cédula del proveedor, igual que en producción.
$codigoErp = '140099981';
$documentos = ['00100001010000881001', '00100001010000881002', '00100001010000881003'];
$claves = [$codigo . '|' . $documentos[0] . '|2026-08-01'];
$listados = [];
$semanas = [];
$xmlIds = [];
$cargaIds = [];
$proveedorId = null;
$fallo = false;

$limpiar = function () use (&$listados, &$semanas, &$xmlIds, &$cargaIds, &$proveedorId, $pdo, $codigo, $codigoErp) {
    foreach ($listados as $id) {
        $pdo->prepare('DELETE FROM porpagar_facturas WHERE listado_id = ?')->execute([(int) $id]);
        $pdo->prepare('DELETE FROM porpagar_listados WHERE id = ?')->execute([(int) $id]);
    }
    foreach ($xmlIds as $id) {
        $pdo->prepare('DELETE FROM facturas_xml WHERE id = ?')->execute([(int) $id]);
    }
    $pdo->prepare('DELETE FROM facturas_erp_incidencias WHERE proveedor_codigo IN (?, ?)')->execute([$codigo, $codigoErp]);
    $pdo->prepare('DELETE FROM facturas_erp WHERE proveedor_codigo IN (?, ?)')->execute([$codigo, $codigoErp]);
    foreach ($cargaIds as $id) {
        $pdo->prepare('DELETE FROM facturas_erp_cargas WHERE id = ?')->execute([(int) $id]);
    }
    foreach ($semanas as $id) {
        $pdo->prepare('DELETE FROM semanas WHERE id = ?')->execute([(int) $id]);
    }
    if ($proveedorId !== null) {
        $pdo->prepare('DELETE FROM proveedores WHERE id = ?')->execute([(int) $proveedorId]);
    }
};

try {
    $porPagar = new PorPagar();
    $erp = new FacturaErp();

    $pdo->prepare('DELETE FROM facturas_erp_incidencias WHERE proveedor_codigo = ?')->execute([$codigo]);
    $pdo->prepare('DELETE FROM facturas_erp WHERE proveedor_codigo = ?')->execute([$codigo]);
    $pdo->prepare('DELETE FROM proveedores WHERE rfc = ?')->execute([$codigo]);

    $q = $pdo->prepare(
        'INSERT INTO proveedores (rfc, razon_social, razon_social_normalizada) VALUES (?, ?, ?)'
    );
    $q->execute([$codigo, 'PROVEEDOR CIERRE ERP S.A.', 'PROVEEDOR CIERRE ERP SA']);
    $proveedorId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO semanas (nombre) VALUES ('Semana test cierre ERP')");
    $semanaId = (int) $pdo->lastInsertId();
    $semanas[] = $semanaId;

    $insertXml = $pdo->prepare(
        "INSERT INTO facturas_xml
            (consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision,
             subtotal, iva, total, moneda, tipo_comprobante, archivo_xml, tipo_documento, semana_id)
         VALUES (?, ?, ?, ?, ?, 0, ?, 'CRC', 'I', ?, 'FE', ?)"
    );
    $insertXml->execute([
        $documentos[0], substr($documentos[0], -8), $proveedorId, '2026-08-01',
        125000.00, 125000.00, '__test_cierre_erp_1.xml', $semanaId,
    ]);
    $xmlIds[] = (int) $pdo->lastInsertId();

    $facturaErp = [
        'proveedor_codigo' => $codigo, 'proveedor_nombre' => 'PROVEEDOR CIERRE ERP S.A.',
        'sucursal' => 'CEDI', 'tipo' => 'F', 'documento' => $documentos[0],
        'numero_corto' => substr($documentos[0], -8), 'fecha_emision' => '2026-08-01',
        'fecha_vence' => '2026-08-31', 'origen' => 'Local', 'moneda' => '¢',
        'monto' => 125000.00, 'saldo' => 125000.00, 'saldo_colones' => 125000.00,
        'clave' => $claves[0],
    ];
    $carga = $erp->importar([$facturaErp], ['archivo' => '__test_cierre_erp__.csv'], ['descuadres' => []]);
    $cargaIds[] = (int) $carga['carga_id'];

    $listadoId = (int) $porPagar->crearListado('Pago test cierre ERP', null, '__test__.csv', $semanaId);
    $listados[] = $listadoId;
    $lineaId = (int) $porPagar->crearLinea($listadoId, [
        'fecha' => '2026-08-01', 'numero' => $documentos[0],
        'proveedor' => 'PROVEEDOR CIERRE ERP S.A.', 'total' => 125000.00,
    ]);
    $porPagar->actualizarMatch($lineaId, $xmlIds[0], 'respaldada', null, 100, 100);
    $porPagar->actualizarTotalLineas($listadoId);

    $resultado = $erp->cerrarPagoSemanal($listadoId, null);
    assertCierreErp($resultado['asignadas'] === 1, 'asigna una factura ERP al cerrar');

    $q = $pdo->prepare('SELECT estado, semana_id, porpagar_listado_id FROM facturas_erp WHERE clave = ?');
    $q->execute([$claves[0]]);
    $asignada = $q->fetch();
    assertCierreErp($asignada['estado'] === 'asignada_semana', 'la factura queda asignada a una semana');
    assertCierreErp((int) $asignada['semana_id'] === $semanaId, 'guarda la semana correcta en ERP');
    assertCierreErp((int) $asignada['porpagar_listado_id'] === $listadoId, 'guarda el pago que hizo la asignación');

    $estadoListado = $pdo->prepare('SELECT estado FROM porpagar_listados WHERE id = ?');
    $estadoListado->execute([$listadoId]);
    assertCierreErp($estadoListado->fetchColumn() === 'cerrado', 'el pago semanal queda cerrado');
    $q = $pdo->prepare('SELECT factura_erp_id FROM porpagar_facturas WHERE id = ?');
    $q->execute([$lineaId]);
    assertCierreErp((int) $q->fetchColumn() > 0, 'la línea conserva el vínculo ERP para auditoría');

    // Recargar el reporte ERP no debe borrar la asignación del cierre.
    $recarga = $erp->importar([$facturaErp], ['archivo' => '__test_cierre_erp__.csv'], ['descuadres' => []]);
    $cargaIds[] = (int) $recarga['carga_id'];
    $estadoListado->execute([$listadoId]);
    assertCierreErp($estadoListado->fetchColumn() === 'cerrado', 'una recarga ERP no reabre el pago');
    $q = $pdo->prepare('SELECT estado, semana_id FROM facturas_erp WHERE clave = ?');
    $q->execute([$claves[0]]);
    $trasRecarga = $q->fetch();
    assertCierreErp(
        $trasRecarga['estado'] === 'asignada_semana' && (int) $trasRecarga['semana_id'] === $semanaId,
        'una recarga ERP conserva la asignación de la factura'
    );

    // El ERP registra la factura con SU fecha de recepción (no la de emisión
    // del XML) y bajo un código interno de proveedor que no es la cédula. El
    // cierre debe cruzar igual, porque la identidad es el consecutivo de 20
    // dígitos. Antes se exigía fecha y proveedor idénticos y esto no cerraba.
    $pdo->exec("INSERT INTO semanas (nombre) VALUES ('Semana test ERP fecha distinta')");
    $semanaOtraFecha = (int) $pdo->lastInsertId();
    $semanas[] = $semanaOtraFecha;
    $insertXml->execute([
        $documentos[2], substr($documentos[2], -8), $proveedorId, '2026-06-17',
        77000.00, 77000.00, '__test_cierre_erp_3.xml', $semanaOtraFecha,
    ]);
    $xmlOtraFecha = (int) $pdo->lastInsertId();
    $xmlIds[] = $xmlOtraFecha;

    $claves[] = $codigoErp . '|' . $documentos[2] . '|2026-07-03';
    $cargaOtraFecha = $erp->importar([[
        'proveedor_codigo' => $codigoErp, 'proveedor_nombre' => 'PROV. CIERRE ERP SOCIEDAD ANONIMA',
        'sucursal' => 'CEDI', 'tipo' => 'F', 'documento' => $documentos[2],
        'numero_corto' => substr($documentos[2], -8),
        'fecha_emision' => '2026-07-03', // 16 días después de la emisión del XML
        'fecha_vence' => '2026-08-03', 'origen' => 'Local', 'moneda' => '¢',
        'monto' => 77000.00, 'saldo' => 77000.00, 'saldo_colones' => 77000.00,
        'clave' => $claves[1],
    ]], ['archivo' => '__test_cierre_erp__.csv'], ['descuadres' => []]);
    $cargaIds[] = (int) $cargaOtraFecha['carga_id'];

    $listadoOtraFecha = (int) $porPagar->crearListado('Pago con fecha ERP distinta', null, '__test__.csv', $semanaOtraFecha);
    $listados[] = $listadoOtraFecha;
    $lineaOtraFecha = (int) $porPagar->crearLinea($listadoOtraFecha, [
        'fecha' => '2026-06-17', 'numero' => $documentos[2],
        'proveedor' => 'PROVEEDOR CIERRE ERP S.A.', 'total' => 77000.00,
    ]);
    $porPagar->actualizarMatch($lineaOtraFecha, $xmlOtraFecha, 'respaldada', null, 100, 100);
    $porPagar->actualizarTotalLineas($listadoOtraFecha);

    $resultadoOtraFecha = $erp->cerrarPagoSemanal($listadoOtraFecha, null);
    assertCierreErp(
        $resultadoOtraFecha['asignadas'] === 1,
        'cierra aunque la fecha del ERP y el código de proveedor no coincidan con el XML'
    );
    $q = $pdo->prepare('SELECT estado, semana_id FROM facturas_erp WHERE clave = ?');
    $q->execute([$claves[1]]);
    $porConsecutivo = $q->fetch();
    assertCierreErp(
        $porConsecutivo['estado'] === 'asignada_semana'
            && (int) $porConsecutivo['semana_id'] === $semanaOtraFecha,
        'la factura cruzada por consecutivo queda asignada a su semana'
    );

    // Si el XML emparejó pero la factura no está en ERP, todo el cierre se revierte.
    $pdo->exec("INSERT INTO semanas (nombre) VALUES ('Semana test ERP faltante')");
    $semanaFaltante = (int) $pdo->lastInsertId();
    $semanas[] = $semanaFaltante;
    $insertXml->execute([
        $documentos[1], substr($documentos[1], -8), $proveedorId, '2026-08-02',
        50000.00, 50000.00, '__test_cierre_erp_2.xml', $semanaFaltante,
    ]);
    $xmlFaltante = (int) $pdo->lastInsertId();
    $xmlIds[] = $xmlFaltante;
    $listadoFaltante = (int) $porPagar->crearListado('Pago con ERP faltante', null, '__test__.csv', $semanaFaltante);
    $listados[] = $listadoFaltante;
    $lineaFaltante = (int) $porPagar->crearLinea($listadoFaltante, [
        'fecha' => '2026-08-02', 'numero' => $documentos[1],
        'proveedor' => 'PROVEEDOR CIERRE ERP S.A.', 'total' => 50000.00,
    ]);
    $porPagar->actualizarMatch($lineaFaltante, $xmlFaltante, 'respaldada', null, 100, 100);

    $bloqueado = false;
    try {
        $erp->cerrarPagoSemanal($listadoFaltante, null);
    } catch (Throwable $e) {
        $bloqueado = strpos($e->getMessage(), 'no están en Facturas ERP') !== false;
    }
    assertCierreErp($bloqueado, 'bloquea el cierre si una factura emparejada falta en ERP');
    $estadoListado->execute([$listadoFaltante]);
    assertCierreErp($estadoListado->fetchColumn() === 'abierto', 'el cierre fallido deja el pago abierto');

    echo "OK: cierre semanal y asignación en Facturas ERP\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $fallo = true;
} finally {
    $limpiar();
}

if ($fallo) {
    exit(1);
}
