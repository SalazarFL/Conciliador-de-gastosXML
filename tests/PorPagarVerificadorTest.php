<?php
/**
 * Verificación del pago semanal contra los comprobantes electrónicos.
 *
 * El emparejamiento dejó de ser difuso. La línea del pago es la factura del
 * ERP, y esa fila trae en `documento` el consecutivo electrónico de veinte
 * dígitos: el mismo número que el XML lleva en `consecutivo_completo`. Eso es
 * una igualdad, no un parecido, y por eso desaparecieron los umbrales, los
 * rescates y el aprendizaje de alias que hacían falta cuando lo que se cruzaba
 * era el texto transcrito del archivo.
 *
 * Queda una vía difusa —el número interno corto, que sí se repite entre
 * emisores— y ahí el proveedor sigue siendo obligatorio.
 */
require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';

function assertVerificador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class ErpFalso
{
    public $lineas;
    public $escrito = [];
    public $semanaSincronizada = null;

    public function __construct(array $lineas) { $this->lineas = $lineas; }

    public function getFacturasPagoParaMatching($listadoId) { return $this->lineas; }

    public function actualizarRespaldoLote(array $filas)
    {
        foreach ($filas as $f) { $this->escrito[(int) $f['id']] = $f; }
        return count($filas);
    }

    public function sincronizarSemanaXml($listadoId) { $this->semanaSincronizada = $listadoId; return 1; }
}

class FacturasFalso
{
    public $xml;
    public function __construct(array $xml) { $this->xml = $xml; }
    public function getCandidatasParaPago() { return $this->xml; }
}

function lineaErp($id, $documento, $proveedor, $monto, $corto = null, $manual = 0, $xmlId = null)
{
    return ['id' => $id, 'documento' => $documento, 'numero_corto' => $corto,
            'proveedor_nombre' => $proveedor, 'monto' => $monto, 'saldo' => $monto,
            'saldo_pago' => $monto, 'factura_xml_id' => $xmlId,
            'estado' => $xmlId ? 'respaldada' : 'sin_respaldo', 'diferencia' => null,
            'match_manual' => $manual];
}

function xmlFila($id, $consecutivo, $proveedor, $total, $corto = null)
{
    return ['id' => $id, 'consecutivo_completo' => $consecutivo,
            'numero_factura_asistente' => $corto ?? substr($consecutivo, -8),
            'total' => $total, 'fecha_emision' => '2026-07-15', 'proveedor_nombre' => $proveedor];
}

$A = '00200001010000045587';
$B = '00200001010000045588';

// ── Consecutivo igual y monto que cuadra: respaldada ─────────────
$erp = new ErpFalso([lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertVerificador($stats['respaldada'] === 1, 'el consecutivo igual respalda la factura');
assertVerificador($erp->escrito[1]['factura_xml_id'] === 100, 'vincula el XML correcto');
assertVerificador($erp->escrito[1]['score_numero'] === 100.0, 'el cruce por consecutivo vale 100');
assertVerificador($erp->semanaSincronizada === 9, 'deja anotada la semana del comprobante');

// ── El proveedor NO se comprueba cuando el consecutivo es igual ──
// El consecutivo lo emite Hacienda y es único a nivel país: si coincide, es la
// misma factura aunque los nombres estén escritos de forma distinta. Exigir el
// proveedor acá solo dejaría sin respaldo lo que ya está probado.
$erp = new ErpFalso([lineaErp(1, $A, 'COOPEAGRI', 50000.00)]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'COOPERATIVA AGRICOLA INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL', 50000.00),
]));
assertVerificador($stats['respaldada'] === 1, 'un nombre irreconocible no rompe el cruce por consecutivo');

// ── El monto no identifica: clasifica ────────────────────────────
$erp = new ErpFalso([lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'AGENCIAS JOP S.A.', 49000.00),
]));
assertVerificador($stats['con_diferencia'] === 1, 'el monto distinto no descarta el XML: lo marca');
assertVerificador($erp->escrito[1]['factura_xml_id'] === 100, 'la factura queda vinculada igual');
assertVerificador($erp->escrito[1]['diferencia'] === 1000.00, 'guarda la diferencia contra el monto del ERP');

// ── Sin XML ──────────────────────────────────────────────────────
$erp = new ErpFalso([lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00)]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $B, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertVerificador($stats['sin_respaldo'] === 1, 'otro consecutivo no sirve de respaldo');
assertVerificador($erp->escrito[1]['factura_xml_id'] === null, 'no se inventa un vínculo');

// ── Un XML respalda una sola factura ─────────────────────────────
$erp = new ErpFalso([
    lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00),
    lineaErp(2, $A, 'AGENCIAS JOP S.A.', 50000.00),
]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertVerificador($stats['respaldada'] === 1 && $stats['sin_respaldo'] === 1,
    'el mismo XML no puede respaldar dos facturas del pago');

// ── Número corto: ahí sí manda el proveedor ──────────────────────
$erp = new ErpFalso([lineaErp(1, 'FACT-12339', 'MERCORICA S.A.', 800.00, '12339')]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, '00100001010000012339', 'DISTRIBUIDORA LA FLORIDA S.A.', 800.00, '00012339'),
]));
assertVerificador($stats['sin_respaldo'] === 1,
    'con número corto y proveedor distinto no se empareja: ese número lo repiten muchos emisores');

$erp = new ErpFalso([lineaErp(1, 'FACT-12339', 'MERCORICA S.A.', 800.00, '12339')]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, '00100001010000012339', 'MERCORICA SOCIEDAD ANONIMA', 800.00, '00012339'),
]));
assertVerificador($stats['respaldada'] === 1, 'con número corto y el mismo proveedor sí empareja');

// Dos candidatas igual de parecidas por número corto: no se elige ninguna.
$erp = new ErpFalso([lineaErp(1, 'FACT-12339', 'MERCORICA S.A.', 800.00, '12339')]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, '00100001010000012339', 'MERCORICA S.A.', 800.00, '00012339'),
    xmlFila(101, '00200001010000012339', 'MERCORICA S.A.', 800.00, '00012339'),
]));
assertVerificador($stats['sin_respaldo'] === 1, 'un empate por número corto no se resuelve al azar');

// ── Los vínculos manuales no se tocan ────────────────────────────
$erp = new ErpFalso([lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00, null, 1, 999)]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertVerificador(!isset($erp->escrito[1]), 'una factura vinculada a mano no se reescribe');
assertVerificador($stats['respaldada'] === 1, 'pero sí cuenta en el resumen');

// Y su XML no queda disponible para otra factura del mismo pago.
$erp = new ErpFalso([
    lineaErp(1, $A, 'AGENCIAS JOP S.A.', 50000.00, null, 1, 100),
    lineaErp(2, $A, 'AGENCIAS JOP S.A.', 50000.00),
]);
PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([
    xmlFila(100, $A, 'AGENCIAS JOP S.A.', 50000.00),
]));
assertVerificador($erp->escrito[2]['factura_xml_id'] === null,
    'el XML de un vínculo manual no se le puede quitar a quien lo tiene');

// ── Un pago vacío no hace nada ───────────────────────────────────
$erp = new ErpFalso([]);
$stats = PorPagarVerificador::verificarListado(9, $erp, new FacturasFalso([]));
assertVerificador($stats === ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0],
    'un pago sin facturas devuelve ceros y no escribe');
assertVerificador($erp->escrito === [], 'y no escribe nada');

echo "OK: verificador del pago semanal por consecutivo\n";
