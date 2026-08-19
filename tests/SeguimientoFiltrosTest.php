<?php
/**
 * Los filtros de las celdas deben ejecutarse en SQL para cubrir la cola
 * completa, no solamente los cincuenta renglones visibles de una página.
 *
 * Y la pestaña tiene que compararse contra el ESTADO EFECTIVO —la marca a mano
 * si la hay, el cálculo si no—, porque es lo que la persona ve en pantalla.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertSeguimientoFiltro($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

class SeguimientoFiltrosFalso extends Seguimiento
{
    public $sqlConteo = '';
    public $sqlFilas = '';
    public $paramsConteo = [];
    public $paramsFilas = [];

    public function __construct() {}

    protected function fetchColumn($sql, $params = [])
    {
        $this->sqlConteo = $sql;
        $this->paramsConteo = $params;
        return 0;
    }

    protected function fetchAll($sql, $params = [])
    {
        $this->sqlFilas = $sql;
        $this->paramsFilas = $params;
        return [];
    }
}

$modelo = new SeguimientoFiltrosFalso();
$modelo->cola([
    'vista' => 'todo',
    'sociedad_id' => 9,
    'condicion_saldo' => 'activas',
    'col_documento' => 'FAC-120',
    'col_proveedor' => 'Proveedor Demo',
    'col_monto' => '₡1,200',
    'col_saldo' => '75.50',
    'col_respaldo' => 'completo',
    'col_tarea' => 'falta_pdf',
    'orden' => 'monto',
], 1, 50);

assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'l.saldo AS saldo') !== false,
    'el saldo de las notas llega a la cola');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'COALESCE(pe.saldo_pago, pe.saldo) AS saldo') !== false,
    'el saldo del renglón sale de la factura del ERP, no de una copia');

// El pago semanal dejó de alimentar documentos: solo marca cuáles facturas se
// pagan esta semana. Si la cola volviera a entrar por él, una factura sin
// respaldo que nadie metió todavía en un pago no aparecería nunca.
assertSeguimientoFiltro(strpos($modelo->sqlFilas, "'factura' AS origen") !== false,
    'el origen de la factura del ERP se llama factura');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'LEFT JOIN porpagar_listados li') !== false,
    'el pago semanal es contexto opcional, no la puerta de entrada');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'li.estado') === false,
    'no se lee el estado del pago: los pagos ya no se cierran y la columna no siempre existe');

// Las facturas sin saldo son las que llenan la pestaña Cerradas: filtrarlas en
// la unión las dejaría fuera de la pantalla entera.
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'ABS(COALESCE(pe.saldo_pago, pe.saldo)) > 0.005') === false,
    'la unión no descarta las facturas sin saldo');

assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'ABS(c.saldo) > 0.005') !== false,
    'activas exige un saldo distinto de cero');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'c.documento LIKE ?') !== false
    && strpos($modelo->sqlFilas, 'c.contexto LIKE ?') !== false,
    'la celda de documento busca también los datos secundarios que muestra');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'c.proveedor LIKE ? OR c.sucursal LIKE ?') !== false,
    'la celda de proveedor incluye la sucursal visible');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'CAST(c.monto AS CHAR) LIKE ?') !== false
    && strpos($modelo->sqlFilas, 'CAST(c.saldo AS CHAR) LIKE ?') !== false,
    'monto y saldo tienen buscadores propios');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'c.factura_xml_id IS NOT NULL') !== false
    && strpos($modelo->sqlFilas, "c.estado_pdf = 'no_disponible_historico'") !== false,
    'el filtro de respaldo completo considera XML, PDF e históricos');
assertSeguimientoFiltro($modelo->paramsFilas === [
    9,
    '%FAC-120%', '%FAC-120%', '%FAC-120%', '%FAC-120%',
    '%Proveedor Demo%', '%Proveedor Demo%',
    '%1200%', '%75.50%',
    'falta_pdf',
], 'los filtros se envían como parámetros en el orden correcto');
assertSeguimientoFiltro($modelo->paramsConteo === $modelo->paramsFilas,
    'conteo y filas usan exactamente los mismos filtros');

// ── Las pestañas son estados ────────────────────────────────────────────────

$modelo->cola(['vista' => 'pendiente', 'orden' => 'monto'], 1, 50);
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'COALESCE(s.estado,') !== false
    && strpos($modelo->sqlFilas, "ABS(c.saldo) <= 0.005 THEN 'cerrada'") !== false,
    'la pestaña compara contra el estado efectivo: marca a mano o cálculo');
assertSeguimientoFiltro($modelo->paramsFilas === ['pendiente'],
    'la pestaña viaja como parámetro, no interpolada');

// El saldo manda sobre el respaldo: una factura pagada está cerrada aunque le
// falte el XML, y una respaldada sin saldo también.
$posCerrada  = strpos($modelo->sqlFilas, "THEN 'cerrada'");
$posPendiente = strpos($modelo->sqlFilas, "THEN 'pendiente'");
assertSeguimientoFiltro($posCerrada !== false && $posPendiente !== false && $posCerrada < $posPendiente,
    'el saldo se evalúa antes que el respaldo');

$modelo->cola(['vista' => 'todo', 'marca' => 'desajuste', 'orden' => 'monto'], 1, 50);
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 's.estado IS NOT NULL AND s.estado <>') !== false,
    'el filtro de desajuste compara la marca a mano contra el cálculo');

$modelo->cola([
    'vista' => 'inventada',
    'marca' => 'inventada',
    'condicion_saldo' => 'canceladas',
    'col_respaldo' => 'inventado',
    'col_tarea' => 'inventada',
], 1, 50);

assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'ABS(c.saldo) <= 0.005') !== false,
    'canceladas exige saldo cero');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'c.factura_xml_id IS NOT NULL') === false,
    'ignora filtros de respaldo manipulados');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 's.estado IS NOT NULL') === false,
    'ignora marcas manipuladas');
assertSeguimientoFiltro($modelo->paramsFilas === [],
    'los valores no permitidos no llegan como parámetros');

echo "OK: Filtros y estados de seguimiento\n";
