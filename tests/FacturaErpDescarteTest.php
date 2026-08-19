<?php
/**
 * Verifica que descartar una incidencia la oculte de verdad.
 *
 * Lo importante es que el descarte sobreviva a la siguiente carga: cada carga
 * vuelve a evaluar el archivo completo, así que si el descarte no persistiera
 * la misma incidencia reaparecería y descartar no serviría de nada.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/helpers/FacturasErpCsvParser.php';

function assertDescarte($condicion, $mensaje)
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
    echo "SKIP: FacturaErpDescarte (sin base de datos disponible)\n";
    exit(0);
}

$PROV = '990000002';
// Las consultas ya no filtran por el código pelado sino por la clave del
// catálogo de proveedores: 'cod:' es un código del ERP del que todavía no se
// sabe a qué proveedor pertenece.
$PROV_FILTRO = 'cod:' . $PROV;
$modelo = new FacturaErp();

$limpiar = function () use ($pdo, $PROV) {
    $pdo->prepare("DELETE FROM facturas_erp WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_incidencias WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->prepare("DELETE FROM facturas_erp_descartes WHERE proveedor_codigo = ?")->execute([$PROV]);
    $pdo->exec("DELETE FROM facturas_erp_cargas WHERE archivo_origen = '__test_descarte__.csv'");
};
$limpiar();

$meta = ['archivo' => '__test_descarte__.csv', 'impreso_en' => null, 'rango_texto' => null];
$cuadre = ['verificados' => 0, 'descuadres' => []];

// Dos facturas del mismo proveedor: una sin número y otra en dólares.
$facturas = [
    ['proveedor_codigo' => $PROV, 'proveedor_nombre' => 'PROVEEDOR DESCARTE S.A.', 'sucursal' => 'CEDI',
     'tipo' => 'F', 'documento' => '', 'numero_corto' => null, 'fecha_emision' => '2026-05-09',
     'fecha_vence' => '2026-06-08', 'origen' => 'Exter', 'moneda' => '¢',
     'monto' => 1000.00, 'saldo' => 1000.00, 'saldo_colones' => 1000.00,
     'clave' => $PROV . '|SINDOC|2026-05-09|1000.00'],
    ['proveedor_codigo' => $PROV, 'proveedor_nombre' => 'PROVEEDOR DESCARTE S.A.', 'sucursal' => 'CEDI',
     'tipo' => 'F', 'documento' => '00100001010000990001', 'numero_corto' => '00990001',
     'fecha_emision' => '2026-05-10', 'fecha_vence' => '2026-06-09', 'origen' => 'Local', 'moneda' => '$ (',
     'monto' => 50.00, 'saldo' => 0.00, 'saldo_colones' => 0.00,
     'clave' => $PROV . '|00100001010000990001|2026-05-10'],
];

$contar = function (array $filtros) use ($modelo, $PROV_FILTRO) {
    return $modelo->contarIncidencias(array_merge(['proveedor' => $PROV_FILTRO], $filtros));
};

try {
    $incidencias = FacturasErpCsvParser::analizarIncidencias($facturas, $cuadre);
    assertDescarte(count($incidencias) === 2, 'el juego de prueba produce 2 incidencias');

    // ── 1ª carga ──
    $modelo->importar($facturas, $meta, $cuadre, null, $incidencias);
    assertDescarte($contar([]) === 2, '1ª carga: las 2 incidencias están vigentes');
    assertDescarte($contar(['ver' => 'descartadas']) === 0, '1ª carga: ninguna nace descartada');

    // ── Descartar la de "sin número" de forma permanente ──
    $ids = $modelo->idsIncidencias(['proveedor' => $PROV_FILTRO, 'tipo' => 'sin_numero']);
    assertDescarte(count($ids) === 1, 'hay una incidencia de factura sin número');

    $r = $modelo->descartarIncidencias($ids, 'Captura manual ya revisada', 7, true);
    assertDescarte($r['descartadas'] === 1, 'se descarta una incidencia');
    assertDescarte($r['firmas'] === 1, 'se recuerda una firma para las próximas cargas');
    assertDescarte($contar([]) === 1, 'queda una sola incidencia vigente');
    assertDescarte($contar(['ver' => 'descartadas']) === 1, 'la descartada se ve en su pestaña');
    assertDescarte($contar(['tipo' => 'sin_numero']) === 0, 'la descartada ya no sale entre las vigentes');

    // El motivo y el autor quedan registrados.
    $q = $pdo->prepare("SELECT motivo, descartada_por FROM facturas_erp_incidencias WHERE id = ?");
    $q->execute([$ids[0]]);
    $fila = $q->fetch();
    assertDescarte($fila['motivo'] === 'Captura manual ya revisada', 'se guarda el motivo del descarte');
    assertDescarte((int) $fila['descartada_por'] === 7, 'se guarda quién descartó');

    // ── 2ª carga del mismo archivo: la descartada NO puede reaparecer ──
    $modelo->importar($facturas, $meta, $cuadre, null, $incidencias);
    assertDescarte($contar(['tipo' => 'sin_numero']) === 0,
        'tras recargar, la incidencia descartada sigue oculta (el descarte persiste entre cargas)');
    assertDescarte($contar(['tipo' => 'moneda_extranjera']) === 2,
        'la que no se descartó sí vuelve a aparecer en la carga nueva');
    assertDescarte($contar(['ver' => 'descartadas']) === 2,
        'la nueva aparición de la descartada entra ya marcada: queda el registro, pero no molesta');

    // ── Restaurar: vuelve a verse en todas sus apariciones ──
    $descartadas = $modelo->idsIncidencias(['proveedor' => $PROV_FILTRO, 'tipo' => 'sin_numero', 'ver' => 'descartadas']);
    $modelo->restaurarIncidencias([$descartadas[0]]);
    assertDescarte($contar(['tipo' => 'sin_numero']) === 2,
        'restaurar devuelve la incidencia en todas las cargas donde apareció');
    assertDescarte($contar(['ver' => 'descartadas']) === 0, 'ya no queda ninguna descartada');

    $q2 = $pdo->prepare("SELECT COUNT(*) FROM facturas_erp_descartes WHERE proveedor_codigo = ?");
    $q2->execute([$PROV]);
    assertDescarte((int) $q2->fetchColumn() === 0, 'restaurar levanta también el descarte permanente');

    // ── Descarte temporal: no recuerda la firma ──
    $ids2 = $modelo->idsIncidencias(['proveedor' => $PROV_FILTRO, 'tipo' => 'sin_numero']);
    $r2 = $modelo->descartarIncidencias([$ids2[0]], '', null, false);
    assertDescarte($r2['descartadas'] === 1, 'el descarte temporal oculta la fila');
    assertDescarte($r2['firmas'] === 0, 'el descarte temporal NO recuerda la firma');

    $modelo->importar($facturas, $meta, $cuadre, null, $incidencias);
    assertDescarte($contar(['tipo' => 'sin_numero']) >= 2,
        'lo descartado "solo esta vez" vuelve a aparecer en la carga siguiente');

    echo "OK: FacturaErpDescarte\n";
} finally {
    $limpiar();
}
