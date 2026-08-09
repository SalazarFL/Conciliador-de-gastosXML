<?php
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Factura.php';

function assertNumeroFactura($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$casos = [
    '0000017662' => '00017662',
    '0000319066' => '00319066',
    '0003411654' => '03411654',
    '0000000854' => '00000854',
    '1234567890' => '34567890',
    '854' => '00000854',
    '0000000000' => '00000000',
];

foreach ($casos as $entrada => $esperado) {
    assertNumeroFactura(
        NumeroFactura::xmlOchoDigitos($entrada) === $esperado,
        "normaliza {$entrada} como {$esperado}"
    );
}

class FacturaNumeroFalsa extends Factura
{
    public $paramsInsertados = [];
    public $sqlInsertado = '';
    public function __construct() {}
    protected function insert($sql, $params = [])
    {
        $this->sqlInsertado = $sql;
        $this->paramsInsertados = $params;
        return 1;
    }
    // Sin base de datos no hay sociedad que resolver.
    public static function sociedadSeleccionadaId() { return 0; }
}

$modelo = new FacturaNumeroFalsa();
$modelo->crear([
    'numero_factura_asistente' => '0000017662',
    'proveedor_id' => 1,
    'fecha_emision' => '2026-07-29',
]);

// La posición se deduce de la lista de columnas del INSERT: agregar una
// columna nueva no debe romper esta prueba, que es sobre el número.
$columnas = [];
if (preg_match('/\(([^)]*)\)\s*VALUES/i', $modelo->sqlInsertado, $m)) {
    $columnas = array_map('trim', explode(',', preg_replace('/\s+/', ' ', $m[1])));
}
$indice = array_search('numero_factura_asistente', $columnas, true);

assertNumeroFactura($indice !== false, 'el INSERT incluye la columna del número');
assertNumeroFactura(
    ($modelo->paramsInsertados[$indice] ?? null) === '00017662',
    'el modelo guarda el numero XML con ocho digitos'
);

echo "OK: Números XML de ocho dígitos\n";
