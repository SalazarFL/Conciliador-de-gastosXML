<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoLote.php';

function assertCorreoGeneralFiltro($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class CorreoLoteFiltroFalso extends CorreoLote
{
    public $ultimaConsulta = '';
    public $ultimosParametros = [];

    public function __construct()
    {
    }

    protected function fetchColumn($sql, $params = [])
    {
        $this->ultimaConsulta = $sql;
        $this->ultimosParametros = $params;
        return 7;
    }
}

$modelo = new CorreoLoteFiltroFalso();
$total = $modelo->estimar(4, '2026-07-01', '2026-07-31', ' Facturas@Proveedor.test ');

assertCorreoGeneralFiltro($total === 7, 'devuelve el total calculado');
assertCorreoGeneralFiltro(substr_count($modelo->ultimaConsulta, 'LIKE ?') === 3, 'filtra remitente, CC y Reply-To');
assertCorreoGeneralFiltro(
    array_slice($modelo->ultimosParametros, -3) === array_fill(0, 3, '%facturas@proveedor.test%'),
    'normaliza y aplica la misma direccion a los tres campos'
);

$modelo->estimar(4, '2026-07-01', '2026-07-31');
assertCorreoGeneralFiltro(substr_count($modelo->ultimaConsulta, 'LIKE ?') === 0, 'sin correo conserva el modo general completo');

$invalido = false;
try {
    $modelo->estimar(4, '2026-07-01', '2026-07-31', 'correo-invalido');
} catch (InvalidArgumentException $e) {
    $invalido = true;
}
assertCorreoGeneralFiltro($invalido, 'rechaza un correo de busqueda invalido');

echo "OK: Correo General filtra los lotes por direccion\n";
