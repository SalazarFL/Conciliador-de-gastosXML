<?php
/**
 * La vista previa del listado del ERP tiene que prometer exactamente lo que
 * la carga después hace.
 *
 * Es la única garantía que la hace útil: si el conteo de la pantalla y el de
 * la importación se separan aunque sea en una fila, mirar antes de aplicar
 * deja de servir y es peor que no mirar, porque da confianza falsa. Por eso
 * la prueba no comprueba números fijos, sino que los dos caminos —el que lee
 * y el que escribe— coinciden en las cuatro situaciones que existen: entrar
 * por primera vez, recargar lo mismo, mover saldos y sumar una factura.
 *
 * Usa un proveedor sintético (990000002) para no tocar datos reales, y borra
 * lo suyo al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertPrevia($condicion, $mensaje)
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
    echo "SKIP: FacturaErpPrevia (sin base de datos disponible)\n";
    exit(0);
}

$PROV = '990000002';
$ARCHIVO = '__test_previa__.csv';
$SELLO = '2019-03-14 09:12:00';   // una fecha de impresión que no usa nadie
$modelo = new FacturaErp();
$SOC = (int) $modelo->sociedadId();

$limpiar = function () use ($pdo, $PROV, $ARCHIVO) {
    $pdo->prepare("DELETE FROM facturas_erp WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_incidencias WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_descartes WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_cargas WHERE archivo_origen = ?")->execute([$ARCHIVO]);
};
$limpiar();

$meta = ['archivo' => $ARCHIVO, 'impreso_en' => $SELLO, 'rango_texto' => null, 'sociedad_id' => $SOC];
$cuadre = ['verificados' => 0, 'descuadres' => []];

$factura = function ($doc, $fecha, $monto, $saldo) use ($PROV) {
    return [
        'proveedor_codigo' => $PROV, 'proveedor_nombre' => 'PROVEEDOR DE PRUEBA S.A.',
        'sucursal' => 'CEDI', 'tipo' => 'F', 'documento' => $doc,
        'numero_corto' => substr($doc, -8), 'fecha_emision' => $fecha,
        'fecha_vence' => '2019-04-01', 'origen' => 'Local', 'moneda' => '¢',
        'monto' => $monto, 'saldo' => $saldo, 'saldo_colones' => $saldo,
        'clave' => $PROV . '|' . $doc . '|' . $fecha,
    ];
};

/**
 * Mira, aplica, y exige que lo mirado sea lo aplicado.
 *
 * Las facturas de otros proveedores viven en la misma tabla y también las
 * cuenta la vista previa, así que la comparación es sobre la diferencia que
 * produce este lote: se resta lo que ya había.
 */
$mirarYAplicar = function (array $lote, $situacion) use ($modelo, $meta, $cuadre, $SOC) {
    $previo = $modelo->previsualizarImportacion($SOC, $lote);
    $real = $modelo->importar($lote, $meta, $cuadre, null);

    assertPrevia($previo['nuevas'] === $real['insertadas'],
        "{$situacion}: la vista previa anuncia {$previo['nuevas']} nuevas y la carga inserta {$real['insertadas']}");
    assertPrevia($previo['actualizadas'] === $real['actualizadas'],
        "{$situacion}: la vista previa anuncia {$previo['actualizadas']} saldos que cambian y la carga cambia {$real['actualizadas']}");
    assertPrevia($previo['sin_cambio'] === $real['sin_cambio'],
        "{$situacion}: la vista previa anuncia {$previo['sin_cambio']} sin cambios y la carga deja quietas {$real['sin_cambio']}");

    return $previo;
};

try {
    $lote = [
        $factura('00100001010000990001', '2019-03-01', 100000.00, 100000.00),
        $factura('00100001010000990002', '2019-03-02', 50000.00, 0.00),
        $factura('00100001010000990003', '2019-03-03', 25000.00, 25000.00),
    ];

    // ── 1. Todo es nuevo ──
    $p = $mirarYAplicar($lote, 'primera carga');
    assertPrevia($p['nuevas'] === 3, 'primera carga: las 3 facturas se anuncian como nuevas');
    assertPrevia(abs($p['saldo_nuevas'] - 125000.00) < 0.005,
        'primera carga: se anuncia el saldo que entra con las facturas nuevas');
    assertPrevia($p['cambios'] === [], 'primera carga: no hay saldos que cambien, solo altas');

    // ── 2. El mismo archivo otra vez: no cambia nada ──
    $p = $mirarYAplicar($lote, 'recarga idéntica');
    assertPrevia($p['nuevas'] === 0 && $p['actualizadas'] === 0,
        'recarga idéntica: la vista previa avisa que no va a pasar nada');
    assertPrevia(abs($p['saldo_sube']) < 0.005 && abs($p['saldo_baja']) < 0.005,
        'recarga idéntica: ningún dinero se mueve');

    // ── 3. Una se paga del todo y otra recibe un abono ──
    $lote2 = $lote;
    $lote2[0]['saldo'] = 0.00;
    $lote2[0]['saldo_colones'] = 0.00;
    $lote2[2]['saldo'] = 12500.00;
    $lote2[2]['saldo_colones'] = 12500.00;

    $p = $mirarYAplicar($lote2, 'saldos que se mueven');
    assertPrevia($p['actualizadas'] === 2, 'saldos que se mueven: se anuncian las dos que cambian');
    assertPrevia($p['cambios_total'] === 2, 'saldos que se mueven: el total dice cuántas son de verdad');
    assertPrevia(abs($p['saldo_baja'] - 112500.00) < 0.005,
        'saldos que se mueven: se anuncia cuánto baja (100.000 pagados + 12.500 abonados)');
    assertPrevia(abs($p['saldo_sube']) < 0.005, 'saldos que se mueven: nada sube');

    // El detalle nombra la factura y dice de cuánto a cuánto, que es lo que
    // se necesita para reconocer una carga equivocada antes de aplicarla.
    $porDocumento = [];
    foreach ($p['cambios'] as $c) { $porDocumento[$c['documento']] = $c; }
    assertPrevia(isset($porDocumento['00100001010000990001']), 'el detalle nombra la factura que se pagó');
    assertPrevia(abs($porDocumento['00100001010000990001']['anterior'] - 100000.00) < 0.005,
        'el detalle trae el saldo que la factura tiene hoy');
    assertPrevia(abs($porDocumento['00100001010000990001']['nuevo']) < 0.005,
        'y el que traería el reporte');
    assertPrevia(abs($porDocumento['00100001010000990001']['diferencia'] + 100000.00) < 0.005,
        'la diferencia va con signo: bajar es negativo');
    assertPrevia(abs($p['cambios'][0]['diferencia']) >= abs($p['cambios'][1]['diferencia']),
        'los saldos que más mueven van primero');

    // ── 4. Aparece una factura nueva sobre lo ya cargado ──
    $lote3 = $lote2;
    $lote3[] = $factura('00100001010000990004', '2019-03-04', 80000.00, 80000.00);
    $p = $mirarYAplicar($lote3, 'una factura nueva');
    assertPrevia($p['nuevas'] === 1 && $p['actualizadas'] === 0 && $p['sin_cambio'] === 3,
        'una factura nueva: entra una y las otras tres quedan como estaban');

    // ── El reporte que ya se aplicó se reconoce por su sello de impresión ──
    $repetida = $modelo->cargaDelMismoReporte($SOC, $SELLO);
    assertPrevia($repetida !== null, 'un reporte ya aplicado se reconoce por su fecha de impresión');
    assertPrevia((string) $repetida['archivo_origen'] === $ARCHIVO,
        'y se dice con qué archivo se había cargado');
    assertPrevia($modelo->cargaDelMismoReporte($SOC, '') === null,
        'un reporte sin sello de impresión no dispara la alarma');
    assertPrevia($modelo->cargaDelMismoReporte($SOC, '1970-01-01 00:00:00') === null,
        'un sello que nunca se cargó tampoco');

    // ── Mirar no escribe ──
    $antes = $pdo->prepare("SELECT COUNT(*) FROM facturas_erp_cargas WHERE archivo_origen = ?");
    $antes->execute([$ARCHIVO]);
    $cargasAntes = (int) $antes->fetchColumn();
    $modelo->previsualizarImportacion($SOC, $lote3);
    $antes->execute([$ARCHIVO]);
    assertPrevia((int) $antes->fetchColumn() === $cargasAntes,
        'la vista previa no deja rastro: no registra una carga');

    $filas = $pdo->prepare("SELECT COUNT(*) FROM facturas_erp WHERE proveedor_codigo = ?");
    $filas->execute([$PROV]);
    assertPrevia((int) $filas->fetchColumn() === 4,
        'ni inserta facturas: siguen las 4 que aplicó la carga');

    echo "OK: FacturaErpPrevia (la vista previa promete lo que la carga hace)\n";
} finally {
    $limpiar();
}
