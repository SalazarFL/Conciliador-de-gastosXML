<?php
/**
 * Marcar y desmarcar facturas del ERP como pago de un pago.
 *
 * Reemplaza a las pruebas de `porpagar_facturas`: crear, borrar y cerrar
 * líneas propias del pago. Ya no hay líneas propias — hay facturas del ERP
 * marcadas—, así que lo que se comprueba es que marcarlas y desmarcarlas no
 * pierda información ni toque lo que no debe.
 *
 * Lo delicado acá es lo que NO se borra: la factura del ERP es el registro del
 * sistema de la empresa y el XML es un documento fiscal. Sacar una factura de
 * un pago no puede eliminar ninguno de los dos.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertAsignacion($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class FacturaErpFalso extends FacturaErp
{
    public $consultas = [];
    public function __construct() {}
    protected function execute($sql, $params = [])
    {
        $this->consultas[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'params' => $params];
        return count($params);
    }
}

// ── Marcar como pago del pago ────────────────────────────────
$m = new FacturaErpFalso();
$m->asignarAPago([7, 9, 9, '11'], 4);
$sql = $m->consultas[0]['sql'];

assertAsignacion(count($m->consultas) === 1, 'una sola consulta para todas las facturas');
assertAsignacion(strpos($sql, "estado = 'asignada_pago'") !== false, 'quedan marcadas como asignadas');
// Un solo apuntador. Antes eran dos —el del pago y el de su listado— que
// decían lo mismo y se escribían en la misma línea; si alguno se olvidaba,
// la factura quedaba en un pago según una columna y en otro según la otra.
assertAsignacion(substr_count($sql, 'pago_id = ?') === 1,
    'guarda a qué pago pertenece, una sola vez');
assertAsignacion($m->consultas[0]['params'] === [4, 7, 9, 11],
    'el pago primero; los ids repetidos se colapsan');

// El saldo se congela al entrar: el del ERP baja a cero al pagar, y volver a
// cargar el reporte después dejaría el pago entero en ₡0 sin tocarla.
assertAsignacion(strpos($sql, 'saldo_pago = COALESCE(saldo_pago, saldo)') !== false,
    'toma la foto del saldo al entrar al pago');
assertAsignacion(strpos($sql, 'COALESCE(saldo_pago') !== false,
    'y no la vuelve a tomar si ya la tenía: recargar el archivo no reescribe la historia');

// ── Sacar del pago ───────────────────────────────────────────────
$m = new FacturaErpFalso();
$m->quitarDePago([7, 8], 30);
$sql = $m->consultas[0]['sql'];

assertAsignacion(strpos($sql, 'UPDATE facturas_erp') === 0, 'sacar del pago es un UPDATE, no un DELETE');
assertAsignacion(strpos($sql, 'DELETE') === false, 'la factura del ERP nunca se elimina');
assertAsignacion(strpos($sql, "estado = 'pendiente'") !== false, 'vuelve a quedar pendiente de pago');
assertAsignacion(strpos($sql, 'pago_id = NULL') !== false
    && strpos($sql, 'pago_id = NULL') !== false
    && strpos($sql, 'saldo_pago = NULL') !== false,
    'suelta el pago, el pago y la foto del saldo');

// El vínculo con el XML se suelta solo si lo hizo una persona dentro del pago.
// El automático es de la factura: hasta el 30/09 se soltaba también, y borrar
// cinco pagos dejó 199 facturas "sin respaldo" con su XML cargado.
assertAsignacion(strpos($sql, 'factura_xml_id = IF(match_manual = 1, NULL, factura_xml_id)') !== false,
    'suelta el XML solo si el vínculo era manual');
assertAsignacion(strpos($sql, "estado_respaldo = IF(match_manual = 1 OR factura_xml_id IS NULL, 'sin_respaldo', estado_respaldo)") !== false,
    'y el semáforo vuelve a cero solo si se queda sin XML');
assertAsignacion(strpos($sql, 'estado_respaldo = IF') < strpos($sql, 'factura_xml_id = IF')
    && strpos($sql, 'factura_xml_id = IF') < strpos($sql, 'match_manual = 0'),
    'MySQL asigna de izquierda a derecha: se mira match_manual y el XML antes de cambiarlos');
assertAsignacion(strpos($sql, 'facturas_xml') === false, 'el comprobante electrónico no se toca');

// El pago va en el WHERE aunque los id ya sean únicos: una lista de ids de
// otro pago no puede desmarcar nada.
assertAsignacion(strpos($sql, 'WHERE pago_id = ?') !== false,
    'la operación no puede salirse del pago que se está editando');
assertAsignacion($m->consultas[0]['params'] === [30, 7, 8], 'el pago primero, después los ids');

// ── Nada que hacer ───────────────────────────────────────────────
$m = new FacturaErpFalso();
$m->asignarAPago([], 4);
$m->quitarDePago([], 30);
assertAsignacion($m->consultas === [], 'sin facturas no se escribe nada');

// ── Vínculo manual ───────────────────────────────────────────────
$m = new FacturaErpFalso();
$m->actualizarRespaldoManual(7, 100, 'con_diferencia', 250.50);
$sql = $m->consultas[0]['sql'];
assertAsignacion(strpos($sql, 'match_manual = 1') !== false,
    'la marca manual protege el vínculo de la verificación automática');
assertAsignacion(strpos($sql, 'score_numero = NULL') !== false,
    'un vínculo a mano no tiene puntaje: lo decidió una persona');
assertAsignacion($m->consultas[0]['params'] === [100, 'con_diferencia', 250.50, 7],
    'guarda el XML, el estado y la diferencia');

// ── Escritura por tandas de la verificación ──────────────────────
$m = new FacturaErpFalso();
$m->actualizarRespaldoLote([
    ['id' => 1, 'factura_xml_id' => 10, 'estado_respaldo' => 'respaldada', 'diferencia' => null,
     'score_numero' => 100.0, 'score_proveedor' => 100.0],
    ['id' => 2, 'factura_xml_id' => null, 'estado_respaldo' => 'sin_respaldo', 'diferencia' => null,
     'score_numero' => null, 'score_proveedor' => null],
]);
$sql = $m->consultas[0]['sql'];
assertAsignacion(count($m->consultas) === 1, 'la verificación escribe todo de una vez');
foreach (['factura_xml_id = CASE id', 'estado_respaldo = CASE id', 'diferencia = CASE id'] as $columna) {
    assertAsignacion(strpos($sql, $columna) !== false, 'actualiza ' . $columna);
}
assertAsignacion(strpos($sql, 'match_manual = 0') !== false,
    'lo que reescribe la verificación deja de ser manual');
assertAsignacion(strpos($sql, 'pago_id') === false && strpos($sql, 'pago_id =') === false,
    'verificar no cambia a qué pago pertenece una factura');

echo "OK: asignación de facturas del ERP al pago\n";
