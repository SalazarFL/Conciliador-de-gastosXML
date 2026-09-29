<?php
/**
 * Una factura en dólares se compara en colones, con el tipo de cambio del XML.
 *
 * El caso real: TRACTOMOTRIZ facturó US$927,68 con tipo de cambio 454,86, y el
 * sistema lo anotó en colones como ₡421.964,52, que es exactamente 927,68 ×
 * 454,86. Se comparaban sin convertir y la factura quedaba "con diferencia"
 * por ₡421.036,84. Eran 23 de las 24 enganchadas a un XML en dólares.
 *
 * Sin base de datos: el lector del XML, la conversión, y las dos vías que
 * comparan montos —la verificación del pago y el enganche al importar—.
 */

/** Catálogo que no opina: esta prueba es sobre montos, no sobre cédulas. */
class ProveedorCodigoErp
{
    public static function hayListado() { return false; }
    public static function veredicto() { return 'desconocido'; }
    public static function fueraDelCatalogo() { return false; }
}

require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/helpers/FacturaMatcher.php';
require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

$fallos = 0;
function tipoCambio($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) { fwrite(STDERR, "FAIL: {$mensaje}\n"); $fallos++; }
}

// ── 1. El lector saca el tipo de cambio del XML ─────────────────────────────
$xmlUsd = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">
  <Clave>50606082600310101391200100001010000046815100000001</Clave>
  <NumeroConsecutivo>00100001010000046815</NumeroConsecutivo>
  <FechaEmision>2026-08-06T16:36:00</FechaEmision>
  <Emisor><Nombre>TRACTOMOTRIZ S.A.</Nombre><Identificacion><Tipo>02</Tipo><Numero>3101013912</Numero></Identificacion></Emisor>
  <Receptor><Nombre>GRUPO BM SP</Nombre><Identificacion><Tipo>02</Tipo><Numero>3101639680</Numero></Identificacion></Receptor>
  <ResumenFactura>
    <CodigoTipoMoneda><CodigoMoneda>USD</CodigoMoneda><TipoCambio>454.86000</TipoCambio></CodigoTipoMoneda>
    <TotalVenta>820.96</TotalVenta><TotalImpuesto>106.72</TotalImpuesto>
    <TotalComprobante>927.68000</TotalComprobante>
  </ResumenFactura>
</FacturaElectronica>
XML;
$xmlCrc = str_replace(
    ['<CodigoMoneda>USD</CodigoMoneda><TipoCambio>454.86000</TipoCambio>'],
    ['<CodigoMoneda>CRC</CodigoMoneda><TipoCambio>1</TipoCambio>'],
    $xmlUsd
);

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc_' . uniqid();
file_put_contents($tmp . '_usd.xml', $xmlUsd);
file_put_contents($tmp . '_crc.xml', $xmlCrc);
$usd = XmlInvoiceParser::parseCfdiFromFile($tmp . '_usd.xml');
$crc = XmlInvoiceParser::parseCfdiFromFile($tmp . '_crc.xml');
@unlink($tmp . '_usd.xml');
@unlink($tmp . '_crc.xml');

tipoCambio(($usd['moneda'] ?? '') === 'USD', 'la moneda del XML se lee');
tipoCambio(($usd['tipo_cambio'] ?? null) === 454.86, 'y el tipo de cambio también: ' . var_export($usd['tipo_cambio'] ?? null, true));
tipoCambio(array_key_exists('tipo_cambio', $crc) && $crc['tipo_cambio'] === null,
    'en colones no se guarda: 1 no dice nada');

// ── 2. La conversión ────────────────────────────────────────────────────────
$xml = ['total' => 927.68, 'moneda' => 'USD', 'tipo_cambio' => 454.86];
tipoCambio(FacturaMatcher::totalComparable($xml, '¢') === 421964.52,
    'en dólares se compara el total por su tipo de cambio');
tipoCambio(FacturaMatcher::totalComparable(['total' => 5000.0, 'moneda' => 'CRC'], '¢') === 5000.0,
    'en colones no se toca');
tipoCambio(FacturaMatcher::totalComparable($xml, '$ (') === 927.68,
    'si la factura del sistema también está en dólares, no se convierte');
tipoCambio(FacturaMatcher::totalComparable(['total' => 927.68, 'moneda' => 'USD'], '¢') === 927.68,
    'sin tipo de cambio no se inventa uno: la diferencia sigue saliendo');

// ── 3. La verificación del pago ─────────────────────────────────────────────
class ErpTipoCambio
{
    public $escrito = [];
    public $lineas;
    public function __construct(array $l) { $this->lineas = $l; }
    public function getFacturasPagoParaMatching($id) { return $this->lineas; }
    public function actualizarRespaldoLote(array $filas)
    {
        foreach ($filas as $f) { $this->escrito[(int) $f['id']] = $f; }
        return count($filas);
    }
}
class XmlTipoCambio
{
    public $xml;
    public function __construct(array $x) { $this->xml = $x; }
    public function getCandidatasParaPago() { return $this->xml; }
}

$linea = [
    'id' => 1, 'documento' => '00100001010000046815', 'numero_corto' => '00046815',
    'proveedor_codigo' => '140001744', 'proveedor_nombre' => 'TRACTOMOTRIZ S.A.',
    'moneda' => '¢', 'monto' => 421964.52, 'estado' => 'sin_respaldo',
    'factura_xml_id' => null, 'match_manual' => 0,
];
$comprobante = [
    'id' => 1663, 'consecutivo_completo' => '00100001010000046815', 'numero_factura_asistente' => '00046815',
    'proveedor_id' => 5, 'proveedor_nombre' => 'TRACTOMOTRIZ S.A.',
    'total' => 927.68, 'moneda' => 'USD', 'tipo_cambio' => 454.86,
];
$erp = new ErpTipoCambio([$linea]);
PorPagarVerificador::verificarListado(1, $erp, new XmlTipoCambio([$comprobante]));
tipoCambio(($erp->escrito[1]['estado_respaldo'] ?? '') === 'respaldada',
    'la factura en dólares que cuadra con el tipo de cambio queda respaldada, no con diferencia');

// Con otro tipo de cambio en el sistema, la diferencia sale en colones.
$erp = new ErpTipoCambio([array_merge($linea, ['monto' => 425000.00])]);
PorPagarVerificador::verificarListado(1, $erp, new XmlTipoCambio([$comprobante]));
tipoCambio(($erp->escrito[1]['estado_respaldo'] ?? '') === 'con_diferencia'
    && abs((float) $erp->escrito[1]['diferencia'] - 3035.48) < 0.005,
    'si el sistema usó otro tipo de cambio, la diferencia es la de colones: '
    . var_export($erp->escrito[1]['diferencia'] ?? null, true));

// ── 4. El enganche al importar ──────────────────────────────────────────────
// Por el número corto el monto es lo que confirma: en dólares hay que
// convertirlo antes, o no cuadra nunca.
$erpCorto = [
    'id' => 2, 'documento' => 'FACT-46815', 'numero_corto' => '00046815',
    'proveedor_codigo' => '140001744', 'proveedor_nombre' => 'TRACTOMOTRIZ S.A.',
    'moneda' => '¢', 'monto' => 421964.52, 'factura_xml_id' => null,
];
$r = FacturaErp::elegirCandidata($comprobante, [$erpCorto]);
tipoCambio(($r['erp']['id'] ?? 0) === 2,
    'por el número corto, el monto en dólares convertido confirma la factura');

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} fallo(s)\n");
    exit(1);
}
echo "OK: las facturas en dólares se comparan con el tipo de cambio del XML\n";
