<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/PorPagar.php';

function assertPorPagarBuscador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class PorPagarBuscadoresFalso extends PorPagar
{
    public $sql = '';
    public $params = [];

    public function __construct() {}

    protected function fetchAll($sql, $params = [])
    {
        $this->sql = $sql;
        $this->params = $params;
        return [];
    }
}

$modelo = new PorPagarBuscadoresFalso();
$modelo->getLineas(12, [
    'q' => 'FAC-900',
    'proveedor' => 'Textiles Demo',
    'estado' => 'con_diferencia',
    'vinculo' => 'manual',
    'fecha_desde' => '2026-07-01',
    'fecha_hasta' => '2026-07-31',
    'monto_desde' => '100.50',
    'monto_hasta' => '900.75',
]);

assertPorPagarBuscador(strpos($modelo->sql, 'pf.numero LIKE ?') !== false,
    'busca por numero del listado');
assertPorPagarBuscador(strpos($modelo->sql, 'f.numero_factura_asistente LIKE ?') !== false,
    'busca tambien por numero XML');
assertPorPagarBuscador(strpos($modelo->sql, 'pf.proveedor_texto LIKE ?') !== false
    && strpos($modelo->sql, 'p.razon_social LIKE ?') !== false,
    'busca proveedor en listado y XML');
assertPorPagarBuscador(strpos($modelo->sql, 'pf.estado = ?') !== false,
    'filtra por estado');
assertPorPagarBuscador(strpos($modelo->sql, 'pf.fecha >= ?') !== false
    && strpos($modelo->sql, 'pf.fecha <= ?') !== false,
    'filtra por rango de fechas');
assertPorPagarBuscador(strpos($modelo->sql, 'pf.total >= ?') !== false
    && strpos($modelo->sql, 'pf.total <= ?') !== false,
    'filtra por rango de monto');
assertPorPagarBuscador(strpos($modelo->sql, 'pf.match_manual = 1') !== false,
    'filtra vinculaciones manuales');
assertPorPagarBuscador($modelo->params === [
    12,
    '%FAC-900%', '%FAC-900%', '%FAC-900%',
    '%Textiles Demo%', '%Textiles Demo%',
    'con_diferencia', '2026-07-01', '2026-07-31', 100.5, 900.75,
], 'envia los filtros como parametros SQL en el orden correcto');

$modelo->getLineas(5, ['estado' => 'inventado', 'vinculo' => 'inventado']);
assertPorPagarBuscador(strpos($modelo->sql, 'pf.estado = ?') === false,
    'ignora estados no permitidos');
assertPorPagarBuscador(strpos($modelo->sql, 'match_manual') === false,
    'ignora tipos de vinculo no permitidos');
assertPorPagarBuscador($modelo->params === [5],
    'una consulta sin filtros conserva solo el listado');

echo "OK: Buscadores de facturas por pagar\n";
