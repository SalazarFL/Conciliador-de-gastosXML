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

/**
 * Importar desde Correo tiene que cruzar sola las facturas nuevas contra los
 * pagos semanales abiertos. Antes no lo hacía: el listado se quedaba "sin
 * respaldo" aunque el XML ya estuviera en la base, y solo se arreglaba
 * entrando a Por Pagar a darle a "Verificar de nuevo".
 */
class PorPagarAbiertosFalso extends PorPagarModeloFalso
{
    public $verificados = [];
    private $sinRespaldo;

    public function __construct(array $sinRespaldo)
    {
        $this->sinRespaldo = $sinRespaldo;
    }

    /**
     * Imita lo que hace la consulta real: solo abiertos, solo con líneas sin
     * respaldo. El 1 está cerrado y el 2 ya está completo, así que ninguno
     * llega hasta aquí.
     */
    public function idsAbiertosConFaltantes($limite = 10)
    {
        $ids = [];
        foreach ($this->sinRespaldo as $id => $faltan) {
            if ($faltan > 0 && $id !== 1) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, $limite);
    }

    public function resumenPorEstado($listadoId)
    {
        return ['sin_respaldo' => $this->sinRespaldo[$listadoId] ?? 0];
    }

    public function getListado($listadoId)
    {
        return ['id' => $listadoId, 'semana_id' => 9, 'estado' => 'abierto'];
    }

    public function getLineas($listadoId)
    {
        $this->verificados[] = (int) $listadoId;
        return parent::getLineas($listadoId);
    }
}

// El 1 está cerrado, el 2 ya tiene todo respaldado y al 3 le falta una línea.
$abiertos = new PorPagarAbiertosFalso([1 => 5, 2 => 0, 3 => 1]);
PorPagarVerificador::verificarAbiertos($abiertos);

assertPorPagar(in_array(3, $abiertos->verificados, true),
    'un listado abierto al que le falta respaldo se vuelve a verificar');
assertPorPagar(!in_array(1, $abiertos->verificados, true),
    'un pago ya cerrado no se recalcula');
assertPorPagar(!in_array(2, $abiertos->verificados, true),
    'un listado ya completo no paga el coste de verificarse otra vez');

echo "OK: PorPagarVerificador semanal\n";
