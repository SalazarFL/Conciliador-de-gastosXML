<?php
/**
 * Verifica la regla de reconciliación entre cargas:
 *   factura nueva            -> se inserta
 *   saldo distinto           -> se actualiza y se guarda el saldo anterior
 *   mismo saldo              -> la fila NO se toca
 *
 * Usa códigos de proveedor sintéticos (99000000x) para no chocar con datos
 * reales, y borra lo suyo al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertUpsert($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
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
    echo "SKIP: FacturaErpUpsert (sin base de datos disponible)\n";
    exit(0);
}

$PROV = '990000001';
$modelo = new FacturaErp();

$limpiar = function () use ($pdo, $PROV) {
    $pdo->prepare("DELETE FROM facturas_erp WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_incidencias WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_descartes WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->exec("DELETE FROM facturas_erp_cargas WHERE archivo_origen = '__test_upsert__.csv'");
};
$limpiar();

$meta = ['archivo' => '__test_upsert__.csv', 'impreso_en' => '2026-08-06 17:04:31', 'rango_texto' => null];
$cuadre = ['verificados' => 0, 'descuadres' => []];

$factura = function ($doc, $fecha, $monto, $saldo) use ($PROV) {
    return [
        'proveedor_codigo' => $PROV, 'proveedor_nombre' => 'PROVEEDOR DE PRUEBA S.A.',
        'sucursal' => 'CEDI', 'tipo' => 'F', 'documento' => $doc,
        'numero_corto' => substr($doc, -8), 'fecha_emision' => $fecha,
        'fecha_vence' => '2026-09-01', 'origen' => 'Local', 'moneda' => '¢',
        'monto' => $monto, 'saldo' => $saldo, 'saldo_colones' => $saldo,
        'clave' => $PROV . '|' . $doc . '|' . $fecha,
    ];
};

$lote = [
    $factura('00100001010000900001', '2026-07-01', 100000.00, 100000.00),
    $factura('00100001010000900002', '2026-07-02', 50000.00, 0.00),
    $factura('00100001010000900003', '2026-07-03', 25000.00, 25000.00),
];

try {
    // ── 1ª carga: todo es nuevo ──
    $r1 = $modelo->importar($lote, $meta, $cuadre, null);
    assertUpsert($r1['insertadas'] === 3, '1ª carga: deben insertarse las 3 facturas');
    assertUpsert($r1['actualizadas'] === 0, '1ª carga: no puede haber actualizaciones');

    $antes = $pdo->prepare("SELECT clave, saldo, saldo_anterior, saldo_cambiado_en, creado_en
                              FROM facturas_erp WHERE proveedor_codigo = ? ORDER BY documento");
    $antes->execute([$PROV]);
    $filasAntes = $antes->fetchAll();
    assertUpsert(count($filasAntes) === 3, 'quedan 3 filas guardadas');
    foreach ($filasAntes as $f) {
        assertUpsert($f['saldo_cambiado_en'] === null, 'una factura recién insertada no tiene cambio de saldo');
    }

    // ── 2ª carga idéntica: nada se toca ──
    $r2 = $modelo->importar($lote, $meta, $cuadre, null);
    assertUpsert($r2['insertadas'] === 0, '2ª carga: no puede insertar nada');
    assertUpsert($r2['actualizadas'] === 0, '2ª carga: no puede actualizar nada');
    assertUpsert($r2['sin_cambio'] >= 3, '2ª carga: las 3 facturas cuentan como sin cambio');

    $antes->execute([$PROV]);
    assertUpsert($filasAntes === $antes->fetchAll(), 'recargar el mismo listado no altera ninguna fila');

    // ── 3ª carga: una se paga del todo y otra baja a la mitad ──
    $lote2 = $lote;
    $lote2[0]['saldo'] = 0.00;          // pagada
    $lote2[0]['saldo_colones'] = 0.00;
    $lote2[2]['saldo'] = 12500.00;      // abono parcial
    $lote2[2]['saldo_colones'] = 12500.00;

    $r3 = $modelo->importar($lote2, $meta, $cuadre, null);
    assertUpsert($r3['insertadas'] === 0, '3ª carga: no hay facturas nuevas');
    assertUpsert($r3['actualizadas'] === 2, '3ª carga: solo cambian las dos con saldo distinto');

    $q = $pdo->prepare("SELECT saldo, saldo_anterior, saldo_cambiado_en FROM facturas_erp WHERE clave = ?");
    $q->execute([$lote[0]['clave']]);
    $pagada = $q->fetch();
    assertUpsert(abs((float) $pagada['saldo']) < 0.005, 'la factura pagada queda en saldo cero');
    assertUpsert(abs((float) $pagada['saldo_anterior'] - 100000.00) < 0.005, 'se conserva el saldo anterior');
    assertUpsert($pagada['saldo_cambiado_en'] !== null, 'se registra cuándo cambió el saldo');

    // La que no cambió sigue intacta, incluso después de una carga con cambios.
    $q->execute([$lote[1]['clave']]);
    $quieta = $q->fetch();
    assertUpsert($quieta['saldo_cambiado_en'] === null, 'la factura sin cambios nunca se marca como actualizada');

    // ── 4ª carga: aparece una factura nueva ──
    $lote3 = $lote2;
    $lote3[] = $factura('00100001010000900004', '2026-07-04', 80000.00, 80000.00);
    $r4 = $modelo->importar($lote3, $meta, $cuadre, null);
    assertUpsert($r4['insertadas'] === 1, '4ª carga: entra solo la factura nueva');
    assertUpsert($r4['actualizadas'] === 0, '4ª carga: las demás siguen igual que en la carga anterior');

    // ── Cada saldo modificado deja constancia en el historial ──
    $inc = $pdo->prepare("SELECT tipo, saldo_anterior, saldo_nuevo, detalle
                            FROM facturas_erp_incidencias
                           WHERE proveedor_codigo = ? AND tipo = 'saldo_modificado'
                           ORDER BY id");
    $inc->execute([$PROV]);
    $modificados = $inc->fetchAll();
    assertUpsert(count($modificados) === 2,
        'solo los dos saldos que cambiaron generan incidencia (una recarga idéntica no genera ninguna)');
    assertUpsert(abs((float) $modificados[0]['saldo_anterior'] - 100000.00) < 0.005,
        'la incidencia guarda el saldo anterior');
    assertUpsert(abs((float) $modificados[0]['saldo_nuevo']) < 0.005,
        'la incidencia guarda el saldo nuevo');
    assertUpsert(strpos($modificados[0]['detalle'], 'El saldo pasó de') === 0,
        'la incidencia explica el cambio en texto');

    // Las incidencias del parser también se guardan, atadas a su carga.
    $r5 = $modelo->importar($lote3, $meta, $cuadre, null, [[
        'tipo' => 'numero_duplicado', 'severidad' => 'alerta',
        'proveedor_codigo' => $PROV, 'proveedor_nombre' => 'PROVEEDOR DE PRUEBA S.A.',
        'documento' => '00100001010000900001', 'clave' => '', 'fecha_emision' => '2026-07-01',
        'monto' => 100000.00, 'saldo_anterior' => null, 'saldo_nuevo' => 0.0,
        'detalle' => 'Incidencia de prueba.',
    ]]);
    $q2 = $pdo->prepare("SELECT COUNT(*) FROM facturas_erp_incidencias WHERE carga_id = ? AND tipo = 'numero_duplicado'");
    $q2->execute([$r5['carga_id']]);
    assertUpsert((int) $q2->fetchColumn() === 1, 'la incidencia del parser queda atada a su carga');
    assertUpsert($r5['incidencias'] === 1, 'el resumen de la carga cuenta sus incidencias');

    echo "OK: FacturaErpUpsert\n";
} finally {
    $limpiar();
}
