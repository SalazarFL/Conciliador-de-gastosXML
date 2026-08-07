<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/PorPagar.php';

function assertEliminarLinea($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class PorPagarEliminarLineaFalso extends PorPagar
{
    public $linea = [
        'id' => 7,
        'listado_id' => 4,
        'numero' => 'FAC-100',
        'factura_xml_id' => 25,
    ];
    public $sqlEjecutado = '';
    public $paramsEjecutados = [];
    public $listadoActualizado = null;

    public function __construct() {}

    public function getLinea($id)
    {
        return $this->linea;
    }

    protected function execute($sql, $params = [])
    {
        $this->sqlEjecutado = $sql;
        $this->paramsEjecutados = $params;
        return 1;
    }

    public function actualizarTotalLineas($listadoId)
    {
        $this->listadoActualizado = $listadoId;
        return 1;
    }
}

$modelo = new PorPagarEliminarLineaFalso();
$eliminada = $modelo->eliminarLinea(7);

assertEliminarLinea($eliminada['numero'] === 'FAC-100', 'devuelve la factura eliminada para informar y redirigir');
assertEliminarLinea(strpos($modelo->sqlEjecutado, 'DELETE FROM porpagar_facturas') !== false,
    'elimina unicamente la linea de porpagar_facturas');
assertEliminarLinea(strpos($modelo->sqlEjecutado, 'facturas_xml') === false,
    'no elimina la factura XML vinculada');
assertEliminarLinea($modelo->paramsEjecutados === [7, 4], 'limita la eliminacion a la linea y su listado');
assertEliminarLinea($modelo->listadoActualizado === 4, 'actualiza el total del listado correspondiente');

$modelo->linea = null;
$modelo->sqlEjecutado = '';
$modelo->listadoActualizado = null;
assertEliminarLinea($modelo->eliminarLinea(999) === null, 'una linea inexistente no se considera eliminada');
assertEliminarLinea($modelo->sqlEjecutado === '', 'una linea inexistente no ejecuta DELETE');
assertEliminarLinea($modelo->listadoActualizado === null, 'una linea inexistente no cambia totales');

echo "OK: eliminacion individual de factura por pagar\n";
