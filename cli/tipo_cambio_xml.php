<?php
/**
 * Completa el tipo de cambio de los comprobantes en otra moneda y vuelve a
 * medir la diferencia de las facturas del sistema que respaldan.
 *
 * Hasta el 28/09/2026 el XML se leía con su moneda pero sin su tipo de cambio,
 * y una factura en dólares se comparaba contra la del sistema —que está en
 * colones— sin convertir. Cada una quedaba "con diferencia" por el monto
 * entero: TRACTOMOTRIZ, US$927,68 contra ₡421.964,52, que es exactamente
 * 927,68 × 454,86. Eran 23 de las 24 enganchadas a un XML en dólares.
 *
 * Lo nuevo que entra ya trae el tipo de cambio (XmlInvoiceParser lo lee al importar).
 * Esto es para lo que ya estaba:
 *
 *   1. lee el <TipoCambio> de cada XML en otra moneda que no lo tenga
 *      guardado, del archivo archivado (disco o SharePoint);
 *   2. vuelve a medir la diferencia de las facturas del sistema enganchadas a
 *      esos XML —respaldada si cuadra al colón, con diferencia si no—, sin
 *      tocar el enganche ni la marca de "a mano";
 *   3. busca comprobante para las facturas sin respaldo, porque la vía del
 *      número corto exige el monto y ahora hay monto con qué compararlo.
 *
 * Uso:
 *   php cli/tipo_cambio_xml.php            # simulación (no escribe)
 *   php cli/tipo_cambio_xml.php --aplicar  # escribe
 */

if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Esquema.php';
require_once __DIR__ . '/../app/core/AlcanceSociedad.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/FacturaMatcher.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';

$aplicar = in_array('--aplicar', $argv, true);

class ConsultaTipoCambio extends Model
{
    protected $table = 'facturas_xml';
    public function __construct() {}
    public function q($sql, $p = []) { return $this->fetchAll($sql, $p) ?: []; }
    public function x($sql, $p = []) { return $this->execute($sql, $p); }
}

// La columna la agrega FacturaErp si falta: se instancia antes de consultarla.
new FacturaErp();
$db = new ConsultaTipoCambio();

// ── 1. El tipo de cambio de cada XML en otra moneda ─────────────────────
$sinTipo = $db->q(
    "SELECT id, consecutivo_completo, moneda, total, ruta_xml
       FROM facturas_xml
      WHERE moneda <> 'CRC' AND moneda <> '' AND tipo_cambio IS NULL
      ORDER BY id"
);
printf("Comprobantes en otra moneda sin tipo de cambio: %d\n", count($sinTipo));

$leidos = 0;
$fallidos = [];
foreach ($sinTipo as $x) {
    $local = $x['ruta_xml'] ? RutaDocumento::rutaLocal($x['ruta_xml']) : null;
    if (!$local) {
        $fallidos[] = $x['consecutivo_completo'] . ' (no se pudo abrir el archivo)';
        continue;
    }
    try {
        $doc = XmlInvoiceParser::parseCfdiFromFile($local);
    } catch (Throwable $e) {
        $doc = [];
    } finally {
        RutaDocumento::soltarLocal($local);
    }
    $tipo = $doc['tipo_cambio'] ?? null;
    if (!$tipo) {
        $fallidos[] = $x['consecutivo_completo'] . ' (el XML no trae tipo de cambio)';
        continue;
    }
    $leidos++;
    if ($aplicar) {
        $db->x('UPDATE facturas_xml SET tipo_cambio = ? WHERE id = ?', [$tipo, (int) $x['id']]);
    }
}
printf("  con tipo de cambio leído: %d\n", $leidos);
foreach (array_slice($fallidos, 0, 20) as $f) {
    echo "  sin leer: {$f}\n";
}

// ── 2. La diferencia de las facturas enganchadas a esos XML ─────────────
// En simulación el tipo de cambio todavía no está en la base: se mide con
// lo que se acaba de leer para poder decir qué cambiaría.
$enganchadas = $db->q(
    "SELECT e.id, e.documento, e.proveedor_nombre, e.moneda AS moneda_erp, e.monto,
            e.estado_respaldo, e.diferencia,
            x.id AS xml_id, x.total, x.moneda, x.tipo_cambio, x.ruta_xml
       FROM facturas_erp e
       JOIN facturas_xml x ON x.id = e.factura_xml_id
      WHERE x.moneda <> 'CRC' AND x.moneda <> ''
      ORDER BY e.id"
);
$cambian = 0;
$siguen = [];
foreach ($enganchadas as $f) {
    $dif = round((float) $f['monto'] - FacturaMatcher::totalComparable($f, $f['moneda_erp']), 2);
    $estado = abs($dif) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';
    $nuevaDif = $estado === 'con_diferencia' ? $dif : null;
    if ($estado === 'con_diferencia') {
        $siguen[] = $f;
    }
    $difActual = $f['diferencia'] === null ? null : round((float) $f['diferencia'], 2);
    if ($estado === $f['estado_respaldo'] && $nuevaDif === $difActual) {
        continue;
    }
    $cambian++;
    if ($aplicar) {
        $db->x(
            'UPDATE facturas_erp SET estado_respaldo = ?, diferencia = ? WHERE id = ?',
            [$estado, $nuevaDif, (int) $f['id']]
        );
    }
}
printf("\nFacturas del sistema enganchadas a un XML en otra moneda: %d\n", count($enganchadas));
printf("  cambian de estado o de diferencia: %d\n", $cambian);
if (!$aplicar) {
    echo "  (en simulación se mide con el tipo de cambio ya guardado; corré con --aplicar)\n";
}
foreach ($siguen as $f) {
    printf("  sigue con diferencia: %-22s %-28s ₡%s  XML %s %s × TC %s\n",
        $f['documento'], mb_substr((string) $f['proveedor_nombre'], 0, 28),
        number_format((float) $f['monto'], 2, ',', '.'),
        $f['moneda'], number_format((float) $f['total'], 2, ',', '.'),
        $f['tipo_cambio'] === null ? '—' : number_format((float) $f['tipo_cambio'], 2, ',', '.'));
}

// ── 3. Lo que ahora puede encontrar su comprobante ──────────────────────
if ($aplicar) {
    $n = 0;
    foreach ($db->q('SELECT id FROM sociedades') as $s) {
        $n += PorPagarVerificador::trasCambioDeCatalogo(
            (new FacturaErp())->setSociedad((int) $s['id']),
            (new Factura())->setSociedad((int) $s['id'])
        );
    }
    printf("\nFacturas sin respaldo que encontraron su comprobante: %d\n", $n);
} else {
    echo "\nSIMULACIÓN: no se escribió nada.\n";
}
