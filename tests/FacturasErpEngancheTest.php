<?php
/**
 * Cargar el reporte del ERP le busca comprobante a lo que no lo tiene.
 *
 * Hasta ahora el emparejador solo corría dentro de un pago semanal, así que
 * subir el reporte con los XML ya en la base dejaba todo en "sin respaldo"
 * hasta que alguien armara el pago. Esta es la vuelta contraria de
 * `engancharXml`: la factura que llega y busca el comprobante que ya estaba.
 *
 * Lo que se prueba acá es sobre todo lo que NO hace: no le roba el XML a una
 * factura que ya lo tiene, no se lo da a dos, y no borra el estado de las que
 * no encuentran nada.
 */
require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';

function assertEnganche($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Doble de FacturaErp: guarda las filas en memoria y responde las consultas
 * paginadas con el mismo filtro que la real —sin XML, sin match manual—.
 */
class ErpEngancheFalso
{
    public $filas;
    public $escrito = [];
    public $semanasSincronizadas = [];

    public function __construct(array $filas) { $this->filas = $filas; }

    public function getFacturasSinRespaldoParaMatching($desdeId = 0, $limite = 2000)
    {
        $libres = array_filter($this->filas, function ($f) use ($desdeId) {
            return (int) $f['id'] > (int) $desdeId
                && empty($f['factura_xml_id'])
                && empty($f['match_manual'])
                && !isset($this->escrito[(int) $f['id']]);
        });
        usort($libres, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_slice(array_values($libres), 0, max(1, (int) $limite));
    }

    public function idsXmlEnganchados()
    {
        $ids = [];
        foreach ($this->filas as $f) {
            if (!empty($f['factura_xml_id'])) { $ids[] = (int) $f['factura_xml_id']; }
        }
        return array_values(array_unique($ids));
    }

    public function actualizarRespaldoLote(array $filas)
    {
        foreach ($filas as $f) { $this->escrito[(int) $f['id']] = $f; }
        return count($filas);
    }

    public function sincronizarSemanaXml($listadoId)
    {
        $this->semanasSincronizadas[] = (int) $listadoId;
        return 1;
    }
}

class FacturasEngancheFalso
{
    public $xml;
    public function __construct(array $xml) { $this->xml = $xml; }
    public function getCandidatasParaPago() { return $this->xml; }
}

function filaErp($id, $documento, $proveedor, $monto, $opciones = [])
{
    return array_merge([
        'id' => $id, 'documento' => $documento, 'numero_corto' => null,
        'proveedor_codigo' => '', 'proveedor_nombre' => $proveedor,
        'monto' => $monto, 'saldo' => $monto, 'saldo_pago' => $monto,
        'factura_xml_id' => null, 'estado' => 'sin_respaldo', 'diferencia' => null,
        'match_manual' => 0, 'porpagar_listado_id' => null,
    ], $opciones);
}

function filaXml($id, $consecutivo, $proveedor, $total, $corto = null)
{
    return ['id' => $id, 'consecutivo_completo' => $consecutivo,
            'numero_factura_asistente' => $corto ?? substr($consecutivo, -8),
            'total' => $total, 'fecha_emision' => '2026-08-10',
            'proveedor_nombre' => $proveedor, 'proveedor_id' => 0];
}

$A = '00200001010000045587';
$B = '00200001010000045588';

// ── El caso que motivó todo: XML cargado, factura recién llegada ──
$erp = new ErpEngancheFalso([filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertEnganche($r['enganchadas'] === 1, 'la factura del ERP encuentra el XML que ya estaba');
assertEnganche($erp->escrito[1]['factura_xml_id'] === 100, 'engancha el comprobante correcto');
assertEnganche($erp->escrito[1]['estado_respaldo'] === 'respaldada', 'con el monto al colón queda respaldada');

// ── Sin pago semanal de por medio: no hace falta armar la semana ──
assertEnganche($erp->semanasSincronizadas === [], 'una factura fuera de un pago no sincroniza semanas');

// ── El monto que no cuadra no impide el enganche: lo clasifica ────
$erp = new ErpEngancheFalso([filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 47500.00),
]));
assertEnganche($erp->escrito[1]['estado_respaldo'] === 'con_diferencia', 'el monto distinto queda con diferencia');
assertEnganche($erp->escrito[1]['diferencia'] === 2500.00, 'y anota cuánto falta');

// ── No le roba el comprobante a una factura que ya lo tiene ───────
// La 2 ya está enganchada al XML 100 (la trae de una carga anterior). La 1
// tiene el mismo consecutivo y llega ahora: debe quedarse sin nada.
$erp = new ErpEngancheFalso([
    filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00),
    filaErp(2, $A, 'AGENCIAS JOP S.A.', 50000.00, ['factura_xml_id' => 100, 'estado' => 'respaldada']),
]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertEnganche($r['enganchadas'] === 0, 'un XML ya tomado no se reparte otra vez');
assertEnganche($erp->escrito === [], 'y no se escribe nada');

// ── Tampoco se lo da a dos facturas dentro de la misma corrida ────
$erp = new ErpEngancheFalso([
    filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00),
    filaErp(2, $A, 'AGENCIAS JOP S.A.', 50000.00),
]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertEnganche($r['enganchadas'] === 1, 'el comprobante respalda una sola factura');
assertEnganche(isset($erp->escrito[1]) && !isset($erp->escrito[2]), 'la primera se lo lleva; la segunda queda como estaba');

// ── Solo agrega: lo que no encuentra pareja no se toca ────────────
$erp = new ErpEngancheFalso([
    filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00),
    filaErp(2, $B, 'DISTRIBUIDORA XYZ S.A.', 12000.00),
]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertEnganche($r['enganchadas'] === 1, 'engancha la que tiene comprobante');
assertEnganche($r['revisadas'] === 2, 'y revisa las dos');
assertEnganche(!isset($erp->escrito[2]), 'la que no tiene XML no se reescribe como sin respaldo');

// ── La factura que ya está en un pago le hereda la semana al XML ──
$erp = new ErpEngancheFalso([
    filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00, ['porpagar_listado_id' => 7]),
]);
PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([
    filaXml(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertEnganche($erp->semanasSincronizadas === [7], 'sincroniza la semana del pago al que pertenece');

// ── Recorre todas las tandas, no solo la primera ──────────────────
$filas = [];
$xmls = [];
for ($i = 1; $i <= 7; $i++) {
    $consecutivo = '002000010100000455' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $filas[] = filaErp($i, $consecutivo, 'PROVEEDOR ' . $i, 1000.00 * $i);
    $xmls[] = filaXml(100 + $i, $consecutivo, 'PROVEEDOR ' . $i, 1000.00 * $i);
}
$erp = new ErpEngancheFalso($filas);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso($xmls), 2);
assertEnganche($r['enganchadas'] === 7, 'la paginación no deja facturas atrás');
assertEnganche($r['revisadas'] === 7, 'ni las revisa dos veces');

// ── Sin comprobantes cargados no hay nada que hacer ───────────────
$erp = new ErpEngancheFalso([filaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$r = PorPagarVerificador::engancharSinRespaldo($erp, new FacturasEngancheFalso([]));
assertEnganche($r === ['enganchadas' => 0, 'revisadas' => 0], 'sin XML no se recorre la tabla');

echo "OK FacturasErpEngancheTest\n";
