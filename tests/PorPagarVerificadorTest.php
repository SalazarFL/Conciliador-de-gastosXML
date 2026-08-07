<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/PorPagar.php';
require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';

function assertPorPagar($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class PorPagarModeloFalso
{
    public $resultado = [];
    public $asignacion = null;

    public function getLineas($listadoId)
    {
        return [[
            'id' => 1, 'numero' => '167', 'proveedor_texto' => 'Proveedor Demo S.A.',
            'total' => 100.00, 'match_manual' => 0, 'factura_xml_id' => null,
            'estado' => 'sin_respaldo',
        ]];
    }

    public function getListado($listadoId)
    {
        return ['id' => $listadoId, 'semana_id' => 9];
    }

    public function getFacturasParaMatching($semanaId)
    {
        return [[
            'id' => 50, 'semana_id' => null, 'numero_factura_asistente' => '00000167',
            'consecutivo_completo' => '00100001010000000167', 'total' => 100.00,
            'proveedor_nombre' => 'Proveedor Demo, S.A.',
        ]];
    }

    public function actualizarMatch($lineaId, $facturaXmlId, $estado, $diferencia, $scoreNumero, $scoreProveedor)
    {
        $this->resultado = compact('lineaId', 'facturaXmlId', 'estado', 'diferencia');
    }

    public function asignarRespaldadasASemana($listadoId, $semanaId)
    {
        $this->asignacion = compact('listadoId', 'semanaId');
        return 1;
    }
}

$modelo = new PorPagarModeloFalso();
$stats = PorPagarVerificador::verificarListado(3, $modelo);
assertPorPagar($modelo->resultado['facturaXmlId'] === 50, 'una factura general puede respaldar el listado semanal');
assertPorPagar($modelo->resultado['estado'] === 'respaldada', 'el monto exacto queda respaldado');
assertPorPagar($modelo->asignacion === ['listadoId' => 3, 'semanaId' => 9], 'la coincidencia correcta se asigna a la semana');
assertPorPagar($stats['respaldada'] === 1, 'contabiliza la coincidencia semanal');

class PorPagarAsignacionSqlFalsa extends PorPagar
{
    public $sqlAsignacion = '';
    public $paramsAsignacion = [];

    public function __construct() {}

    protected function execute($sql, $params = [])
    {
        $this->sqlAsignacion = $sql;
        $this->paramsAsignacion = $params;
        return 1;
    }
}

$asignacionSql = new PorPagarAsignacionSqlFalsa();
$asignacionSql->asignarRespaldadasASemana(3, 9);
assertPorPagar(strpos($asignacionSql->sqlAsignacion, "pf.estado = 'con_diferencia'") !== false,
    'asigna a la semana una coincidencia automática aunque cambie el monto');
assertPorPagar(strpos($asignacionSql->sqlAsignacion, 'pf.match_manual = 0') !== false,
    'no aplica esta regla a una vinculación manual');

echo "OK: PorPagarVerificador semanal\n";
