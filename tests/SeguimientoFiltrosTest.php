<?php
/**
 * Los filtros de las celdas deben ejecutarse en SQL para cubrir la cola
 * completa, no solamente los cincuenta renglones visibles de una página.
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
    'col_estado' => 'en_gestion',
    'orden' => 'monto',
], 1, 50);

assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'l.saldo AS saldo') !== false,
    'el saldo de las notas llega a la cola');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'pf.total AS saldo') !== false,
    'el total pendiente del pago semanal llega como saldo operativo');
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
    'falta_pdf', 'en_gestion',
], 'los filtros se envían como parámetros en el orden correcto');
assertSeguimientoFiltro($modelo->paramsConteo === $modelo->paramsFilas,
    'conteo y filas usan exactamente los mismos filtros');

$modelo->cola([
    'vista' => 'todo',
    'condicion_saldo' => 'canceladas',
    'col_respaldo' => 'inventado',
    'col_tarea' => 'inventada',
    'col_estado' => 'inventado',
], 1, 50);

assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'ABS(c.saldo) <= 0.005') !== false,
    'canceladas exige saldo cero');
assertSeguimientoFiltro(strpos($modelo->sqlFilas, 'c.factura_xml_id IS NOT NULL') === false,
    'ignora filtros de respaldo manipulados');
assertSeguimientoFiltro($modelo->paramsFilas === [],
    'los valores no permitidos no llegan como parámetros');

echo "OK: Filtros y saldo de seguimiento\n";
