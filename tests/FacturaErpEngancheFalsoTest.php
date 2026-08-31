<?php
/**
 * El XML recién importado no puede quedarse con la línea del ERP equivocada.
 *
 * El fallo que motivó esta prueba: la línea `00110191010000065118` (Pipasa,
 * ₡90.950,31) quedó respaldada por el comprobante `00100001010000065118`
 * (Coopeagropal, ₡9.769.985,83). Lo único que comparten es que sus últimos
 * ocho dígitos son `00065118`, y esa vía —la del enganche al importar— se
 * conformaba con eso: no miraba el consecutivo completo, no consultaba el mapa
 * de códigos y solo usaba el monto para desempatar cuando había más de una
 * candidata. Con una sola candidata la tomaba, aunque el monto se diferenciara
 * en nueve millones y medio, y encima anotaba 100/100 en los dos scores, así
 * que el emparejamiento falso se veía perfecto en todas las pantallas.
 *
 * Lo que se fija aquí es sobre todo lo que NO debe pasar: no emparejar por la
 * cola del número cuando los dos consecutivos están y no coinciden, no
 * emparejar contra un código que el mapa dice que es de otro proveedor, y no
 * dar por buena una coincidencia de número corto que el monto desmiente.
 */

/**
 * Doble del mapa de códigos. Se declara ANTES de cargar el modelo para que la
 * carga perezosa lo encuentre y no vaya a la base: la decisión es pura y esta
 * prueba tiene que poder correr sin base de datos.
 */
class ProveedorCodigoErp
{
    /** codigo => proveedor_id que el mapa da por confirmado */
    public static $conocidos = [];

    public static function veredicto($codigo, $proveedorIdXml)
    {
        $codigo = trim((string) $codigo);
        $proveedorIdXml = (int) $proveedorIdXml;
        if ($codigo === '' || $proveedorIdXml <= 0 || !isset(self::$conocidos[$codigo])) {
            return 'desconocido';
        }
        return self::$conocidos[$codigo] === $proveedorIdXml ? 'propio' : 'ajeno';
    }
}

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

$fallos = 0;
function verificaEnganche($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$linea = function ($id, $documento, $codigo, $nombre, $monto) {
    return [
        'id' => $id, 'documento' => $documento, 'numero_corto' => substr($documento, -8),
        'proveedor_codigo' => $codigo, 'proveedor_nombre' => $nombre,
        'monto' => $monto, 'factura_xml_id' => null,
    ];
};

// ── El caso real que se coló ──────────────────────────────────────────
$coopeagropal = [
    'id' => 7966, 'consecutivo_completo' => '00100001010000065118',
    'numero_factura_asistente' => '00065118', 'total' => 9769985.83,
    'proveedor_id' => 154, 'proveedor_nombre' => 'COOPEAGROPAL R.L.',
];
$lineaPipasa = $linea(1558, '00110191010000065118', '140000101', 'CORPORACION PIPASA .S.R.L.', 90950.31);

ProveedorCodigoErp::$conocidos = [];
$r = FacturaErp::elegirCandidata($coopeagropal, [$lineaPipasa]);
verificaEnganche($r['erp'] === null,
    'el consecutivo completo distinto descarta la línea aunque terminen igual (el fallo que se corrige)');
verificaEnganche($r['motivo'] === 'sin_erp',
    'y se informa como "sin_erp", no como emparejada');

// El mismo caso con el mapa cargado: la cédula lo habría vetado también.
ProveedorCodigoErp::$conocidos = ['140000101' => 145];
verificaEnganche(FacturaErp::elegirCandidata($coopeagropal, [$lineaPipasa])['erp'] === null,
    'la guarda de cédula también rechaza al emisor ajeno');

// ── Lo que sí tiene que seguir enganchando ────────────────────────────
$pipasa = [
    'id' => 7199, 'consecutivo_completo' => '00110191010000065118',
    'numero_factura_asistente' => '00065118', 'total' => 90950.31,
    'proveedor_id' => 145, 'proveedor_nombre' => 'Corporación Pipasa, S.R.L.',
];
$r = FacturaErp::elegirCandidata($pipasa, [$lineaPipasa]);
verificaEnganche(($r['erp']['id'] ?? 0) === 1558,
    'el comprobante que sí es de esa línea se engancha igual que antes');
verificaEnganche($r['score_numero'] === 100.0,
    'el consecutivo exacto vale 100 de número');
verificaEnganche($r['score_proveedor'] === 100.0,
    'con la cédula confirmando el código, el proveedor vale 100');

// El consecutivo exacto manda aunque el monto no cuadre: es el mismo
// documento y la diferencia es justo lo que hay que reportar.
$pipasaOtroMonto = $pipasa;
$pipasaOtroMonto['total'] = 88000.00;
verificaEnganche((FacturaErp::elegirCandidata($pipasaOtroMonto, [$lineaPipasa])['erp']['id'] ?? 0) === 1558,
    'con consecutivo exacto, la diferencia de monto se reporta pero no rompe el enganche');

// ── La vía del número corto, cuando el ERP no trae consecutivo ────────
ProveedorCodigoErp::$conocidos = [];
$interna = $linea(2001, 'FACT-12339', '700000001', 'FERRETERIA EJEMPLO S.A.', 45000.00);
$xmlCorto = [
    'id' => 8100, 'consecutivo_completo' => '00100001010000012339',
    'numero_factura_asistente' => '00012339', 'total' => 45000.00,
    'proveedor_id' => 300, 'proveedor_nombre' => 'FERRETERIA EJEMPLO S.A.',
];
$r = FacturaErp::elegirCandidata($xmlCorto, [$interna]);
verificaEnganche(($r['erp']['id'] ?? 0) === 2001,
    'sin consecutivo en el ERP se cae al número corto y el monto lo confirma');
verificaEnganche($r['score_numero'] === 60.0,
    'esa vía vale 60 de número, no 100: el número corto no identifica');

// Mismo número corto, monto que no cuadra y mapa que no opina: no alcanza.
$xmlOtroMonto = $xmlCorto;
$xmlOtroMonto['total'] = 61234.00;
verificaEnganche(FacturaErp::elegirCandidata($xmlOtroMonto, [$interna])['erp'] === null,
    'el número corto solo no basta: si el monto no acompaña, no se engancha');

// Pero con la cédula confirmando el código, el monto deja de ser necesario:
// es la misma factura con una diferencia que hay que reportar.
ProveedorCodigoErp::$conocidos = ['700000001' => 300];
verificaEnganche((FacturaErp::elegirCandidata($xmlOtroMonto, [$interna])['erp']['id'] ?? 0) === 2001,
    'con la cédula confirmando, el número corto sí alcanza aunque el monto difiera');

// ── Dos candidatas idénticas: mejor no elegir ─────────────────────────
ProveedorCodigoErp::$conocidos = [];
$gemela = $linea(2002, 'FACT-12339', '700000002', 'OTRA FERRETERIA S.A.', 45000.00);
$r = FacturaErp::elegirCandidata($xmlCorto, [$interna, $gemela]);
verificaEnganche($r['erp'] === null && $r['motivo'] === 'ambigua',
    'dos líneas con el mismo número corto y el mismo monto quedan ambiguas, no al azar');

ProveedorCodigoErp::$conocidos = [];

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: el enganche al importar exige consecutivo, cédula o monto antes de emparejar\n";
