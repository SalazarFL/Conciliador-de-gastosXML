<?php
/**
 * Los buscadores del checklist del pago semanal.
 *
 * Buscan sobre las facturas del ERP marcadas para la semana, que es donde
 * viven ahora los datos. Lo que se comprueba es que cada filtro llegue al SQL
 * con la columna correcta: un filtro que apunte a la columna equivocada no da
 * error, da resultados que parecen buenos.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertPorPagarBuscador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class PorPagarBuscadoresFalso extends FacturaErp
{
    public $sql = '';
    public $params = [];

    public function __construct() {}

    protected function fetchAll($sql, $params = [])
    {
        $this->sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->params = $params;
        return [];
    }
}

$modelo = new PorPagarBuscadoresFalso();
$modelo->getFacturasPago(12, [
    'q' => '00100001010000045587',
    'proveedor' => 'JOP',
    'estado' => 'con_diferencia',
    'vinculo' => 'manual',
    'fecha_desde' => '2026-07-01',
    'fecha_hasta' => '2026-07-31',
    'monto_desde' => '1000',
    'monto_hasta' => '90000',
]);

$sql = $modelo->sql;

assertPorPagarBuscador($modelo->params[0] === 12, 'el checklist se limita al pago pedido');
assertPorPagarBuscador(strpos($sql, 'e.porpagar_listado_id = ?') !== false,
    'las líneas del pago son las facturas del ERP marcadas con ese pago');

// El buscador de número mira las dos formas del ERP y las dos del XML: quien
// busca escribe la que tiene a mano, no la que el sistema usa por dentro.
foreach (['e.documento LIKE ?', 'e.numero_corto LIKE ?',
          'x.numero_factura_asistente LIKE ?', 'x.consecutivo_completo LIKE ?'] as $columna) {
    assertPorPagarBuscador(strpos($sql, $columna) !== false, "el buscador de número cubre {$columna}");
}

assertPorPagarBuscador(strpos($sql, 'e.proveedor_nombre LIKE ?') !== false
    && strpos($sql, 'p.razon_social LIKE ?') !== false,
    'el proveedor se busca en el nombre del ERP y en el del comprobante');

assertPorPagarBuscador(strpos($sql, 'e.estado_respaldo = ?') !== false,
    'el semáforo filtra por estado_respaldo, no por el estado de asignación');
assertPorPagarBuscador(strpos($sql, 'e.estado = ?') === false,
    'no se confunde con `estado`, que dice si la factura está asignada a una semana');

assertPorPagarBuscador(strpos($sql, 'e.match_manual = 1') !== false, 'filtra los vínculos hechos a mano');
assertPorPagarBuscador(strpos($sql, 'e.fecha_emision >= ?') !== false
    && strpos($sql, 'e.fecha_emision <= ?') !== false,
    'las fechas son las de emisión de la factura del ERP');

// El importe filtra por el saldo del pago, no por el saldo vivo: si no, una
// factura ya pagada (saldo 0) desaparecería del checklist de su propia semana.
assertPorPagarBuscador(strpos($sql, 'COALESCE(e.saldo_pago, e.saldo) >= ?') !== false
    && strpos($sql, 'COALESCE(e.saldo_pago, e.saldo) <= ?') !== false,
    'el importe filtra por el saldo con que la factura entró al pago');

assertPorPagarBuscador($modelo->params === [
    12,
    '%00100001010000045587%', '%00100001010000045587%', '%00100001010000045587%', '%00100001010000045587%',
    '%JOP%', '%JOP%',
    'con_diferencia',
    '2026-07-01', '2026-07-31',
    1000.0, 90000.0,
], 'los parámetros llegan en el orden de las condiciones');

// ── Sin filtros ──────────────────────────────────────────────────
$limpio = new PorPagarBuscadoresFalso();
$limpio->getFacturasPago(12, []);
assertPorPagarBuscador($limpio->params === [12], 'sin filtros solo se acota el pago');
assertPorPagarBuscador(strpos($limpio->sql, 'LIKE') === false, 'sin texto no se arma ningún LIKE');

// ── Un filtro inválido no se cuela ───────────────────────────────
$raro = new PorPagarBuscadoresFalso();
$raro->getFacturasPago(12, ['estado' => 'inventado', 'vinculo' => 'raro', 'monto_desde' => 'x']);
assertPorPagarBuscador($raro->params === [12], 'los valores que no existen se ignoran, no se pasan al SQL');

echo "OK: buscadores del checklist del pago semanal\n";
