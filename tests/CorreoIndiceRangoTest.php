<?php
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';

function assertCorreoIndiceRango($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class CorreoIndiceRangoFalso extends CorreoIndice
{
    public $sqlConteo = '';
    public $paramsConteo = [];

    public function __construct() {}

    protected function fetchColumn($sql, $params = [])
    {
        $this->sqlConteo = $sql;
        $this->paramsConteo = $params;
        return 0;
    }

    protected function fetchAll($sql, $params = [])
    {
        return [];
    }
}

$modelo = (new CorreoIndiceRangoFalso())->setCuenta(4);
$modelo->buscarPorNumero(
    '64291', 0, 500, '', '', 0,
    '2026-06-21', '2026-07-21'
);

assertCorreoIndiceRango(
    strpos($modelo->sqlConteo, 'timestamp >= ? AND timestamp < ?') !== false,
    'la lupa de tarjeta aplica un rango absoluto'
);
assertCorreoIndiceRango(
    $modelo->paramsConteo === [
        4,
        '64291',
        strtotime('2026-06-21 00:00:00'),
        strtotime('2026-07-21 +1 day 00:00:00'),
    ],
    'el rango incluye completos los quince dias anteriores y posteriores'
);

$modelo->buscarPorNumero('64291', 30);
assertCorreoIndiceRango(
    strpos($modelo->sqlConteo, 'timestamp >= ? AND timestamp < ?') === false
        && strpos($modelo->sqlConteo, 'timestamp >= ?') !== false,
    'la busqueda de bandeja conserva su filtro relativo independiente'
);

echo "OK: Correo separa rango de tarjeta y filtros de bandeja\n";
