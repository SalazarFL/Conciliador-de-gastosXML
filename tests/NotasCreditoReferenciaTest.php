<?php
/**
 * El puente NC → factura acreditada, para las notas directas.
 *
 * En el reporte del ERP una nota directa se numera con el consecutivo de la
 * FACTURA que corrige, y el XML de la nota cita esa misma factura en su
 * InformacionReferencia. Con las dos puntas apuntando al mismo sitio la nota
 * queda identificada sin depender del monto, que es lo que antes decidía todo.
 *
 * Lo que se comprueba es sobre todo lo que NO debe pasar: la referencia agrega
 * certeza, nunca desplaza una coincidencia de monto ni toca las demás clases.
 */
require_once __DIR__ . '/../app/helpers/NotasCreditoVerificador.php';

function assertReferencia($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class NcReferenciaModeloFalso
{
    public $lineas;
    public $facturas;
    public $referencias;
    public $resultados = [];

    public function __construct(array $lineas, array $facturas, array $referencias)
    {
        $this->lineas = $lineas;
        $this->facturas = $facturas;
        $this->referencias = $referencias;
    }

    public function getListado($id) { return ['id' => $id, 'sociedad_cedula' => '3101000000']; }
    public function getLineasParaMatching($id) { return $this->lineas; }
    public function getFacturasNcSociedad($cedula) { return $this->facturas; }
    public function getReferenciasNcSociedad($cedula) { return $this->referencias; }

    public function actualizarMatch($lineaId, $facturaId, $estado, $diferencia, $metodo, $score, $manual, $motivo, $bloqueo = 0)
    {
        $this->resultados[$lineaId] = compact('facturaId', 'estado', 'metodo', 'motivo', 'diferencia');
    }
}

/** Una línea directa: el número largo del documento es la FACTURA corregida. */
function lineaDirecta($id, $proveedor, $monto, $consecutivoFactura, $fecha = '2026-07-02')
{
    return [
        'id' => $id,
        'clase' => 'directa',
        'documento' => 'NC- 17-1-' . $consecutivoFactura . '-684',
        'proveedor_nombre' => $proveedor,
        'monto' => $monto,
        'monto_conversion' => 0,
        'moneda' => 'CRC',
        'fecha' => $fecha,
        'fecha_nc_proveedor' => null,
        'nc_proveedor' => null,
        'factura_xml_id' => null,
        'match_manual' => 0,
        'bloqueo_automatico' => 0,
        'estado' => 'sin_respaldo',
    ];
}

function ncXml($id, $proveedor, $total, $fecha = '2026-07-05')
{
    return [
        'id' => $id,
        'proveedor_nombre' => $proveedor,
        'proveedor_alias' => null,
        'total' => $total,
        'moneda' => 'CRC',
        'fecha_emision' => $fecha,
        'consecutivo_completo' => '0010000103000000' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
        'numero_factura_asistente' => str_pad((string) $id, 8, '0', STR_PAD_LEFT),
    ];
}

/** Una referencia como la deja el parser cuando el proveedor puso la clave. */
function refA($ncId, $consecutivoFactura)
{
    return ['factura_xml_id' => $ncId, 'consecutivo_ref' => $consecutivoFactura,
            'numero_ref' => str_repeat('5', 21) . $consecutivoFactura . '123456789', 'tipo_doc_ref' => '01'];
}

$FACTURA_A = '00100001010000012473';
$FACTURA_B = '00100001010000012481';

// ── Referencia + proveedor + monto: automática ─────────────────
$m = new NcReferenciaModeloFalso(
    [lineaDirecta(1, 'AGENCIAS JOP S.A.', 17232.50, $FACTURA_A)],
    [ncXml(10, 'AGENCIAS JOP S.A.', 17232.50)],
    [refA(10, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[1]['estado'] === 'coincide', 'referencia + proveedor + monto empareja sola');
assertReferencia($m->resultados[1]['facturaId'] === 10, 'toma la nota que cita la factura');

// ── Referencia + proveedor, monto distinto: queda para revisar ──
$m = new NcReferenciaModeloFalso(
    [lineaDirecta(1, 'AGENCIAS JOP S.A.', 17232.50, $FACTURA_A)],
    [ncXml(10, 'AGENCIAS JOP S.A.', 17232.49)],
    [refA(10, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[1]['estado'] === 'con_diferencia',
    'con la identidad probada el monto solo clasifica, ya no descarta');
assertReferencia($m->resultados[1]['metodo'] === 'referencia', 'queda dicho que la resolvio la referencia');
assertReferencia($m->resultados[1]['facturaId'] === 10, 'la nota queda vinculada aunque el monto no cuadre');

// ── La nota que cita la factura es de otro proveedor ────────────
$m = new NcReferenciaModeloFalso(
    [lineaDirecta(1, 'AGENCIAS JOP S.A.', 17232.50, $FACTURA_A)],
    [ncXml(10, 'DISTRIBUIDORA LA FLORIDA S.A.', 17232.50)],
    [refA(10, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[1]['estado'] === 'sin_respaldo', 'el proveedor sigue siendo obligatorio');
assertReferencia(strpos($m->resultados[1]['motivo'], 'otro proveedor') !== false,
    'dice por que no se acepto la nota referenciada');

// ── La referencia NO desplaza una coincidencia de monto ─────────
// Caso real: una NC consolidada cita tres facturas. Solo puede respaldar una
// linea, y debe quedarse con aquella cuyo monto cuadra, no con la primera que
// la cite por id.
$m = new NcReferenciaModeloFalso(
    [
        lineaDirecta(1, 'SIGMA ALIMENTOS COSTA RICA S.A.', 18577.41, $FACTURA_B),
        lineaDirecta(2, 'SIGMA ALIMENTOS COSTA RICA S.A.', 24393.92, $FACTURA_A),
    ],
    [ncXml(10, 'SIGMA ALIMENTOS COSTA RICA S.A.', 24393.92)],
    [refA(10, $FACTURA_B), refA(10, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[2]['facturaId'] === 10 && $m->resultados[2]['estado'] === 'coincide',
    'la nota consolidada se queda con la linea cuyo monto cuadra');
assertReferencia($m->resultados[1]['facturaId'] === null,
    'la otra linea no le roba la nota solo por citarla antes');

// ── Varias notas con el mismo monto: la referencia desempata ────
$m = new NcReferenciaModeloFalso(
    [lineaDirecta(1, 'AGENCIAS JOP S.A.', 5000.00, $FACTURA_A)],
    [ncXml(10, 'AGENCIAS JOP S.A.', 5000.00), ncXml(11, 'AGENCIAS JOP S.A.', 5000.00)],
    [refA(11, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[1]['facturaId'] === 11 && $m->resultados[1]['estado'] === 'coincide',
    'entre dos notas del mismo monto gana la que cita la factura');

// ── Solo las directas ──────────────────────────────────────────
// Una nota de cambio con un numero largo no significa lo mismo: ese numero no
// es la factura acreditada y el puente no debe activarse.
$lineaCambio = lineaDirecta(1, 'AGENCIAS JOP S.A.', 17232.50, $FACTURA_A);
$lineaCambio['clase'] = 'cambio';
$m = new NcReferenciaModeloFalso(
    [$lineaCambio],
    [ncXml(10, 'AGENCIAS JOP S.A.', 17232.49)],
    [refA(10, $FACTURA_A)]
);
NotasCreditoVerificador::verificarListado(7, $m);
assertReferencia($m->resultados[1]['estado'] === 'sin_respaldo',
    'el puente por referencia no alcanza a las notas de cambio');

// ── Qué referencia es utilizable y cuál no ─────────────────────
assertReferencia(
    NotasCreditoVerificador::consecutivoReferenciado(
        ['consecutivo_ref' => $FACTURA_A, 'numero_ref' => '']) === $FACTURA_A,
    'usa el consecutivo que ya extrajo el parser');
assertReferencia(
    NotasCreditoVerificador::consecutivoReferenciado(
        ['consecutivo_ref' => null, 'numero_ref' => $FACTURA_A]) === $FACTURA_A,
    'acepta el consecutivo pelado de 20 digitos');
assertReferencia(
    NotasCreditoVerificador::consecutivoReferenciado(
        ['consecutivo_ref' => null, 'numero_ref' => str_repeat('5', 21) . $FACTURA_A . '123456789']) === $FACTURA_A,
    'saca el consecutivo de la clave de 50 digitos');
foreach (['BOLETA # D6039', 'SIN DOCUMENTO DE REFERENCIA', '0', '7145393', ''] as $basura) {
    assertReferencia(
        NotasCreditoVerificador::consecutivoReferenciado(['consecutivo_ref' => null, 'numero_ref' => $basura]) === '',
        'no adivina un vinculo con una referencia inservible: ' . var_export($basura, true));
}

// ── El número de la línea sale solo de las directas ────────────
assertReferencia(
    NotasCreditoVerificador::consecutivoFacturaDirecta(
        ['clase' => 'directa', 'documento' => 'NC- 17-1-' . $FACTURA_A . '-684']) === $FACTURA_A,
    'lee la factura corregida del numero del reporte');
assertReferencia(
    NotasCreditoVerificador::consecutivoFacturaDirecta(
        ['clase' => 'ajuste', 'documento' => 'NC- 4945']) === '',
    'un ajuste interno no trae factura que citar');

echo "OK: puente NC directa -> factura por referencia del XML\n";
