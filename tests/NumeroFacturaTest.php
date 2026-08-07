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
    public function __construct() {}
    protected function insert($sql, $params = [])
    {
        $this->paramsInsertados = $params;
        return 1;
    }
}

$modelo = new FacturaNumeroFalsa();
$modelo->crear([
    'numero_factura_asistente' => '0000017662',
    'proveedor_id' => 1,
    'fecha_emision' => '2026-07-29',
]);
assertNumeroFactura(
    ($modelo->paramsInsertados[6] ?? null) === '00017662',
    'el modelo guarda el numero XML con ocho digitos'
);

echo "OK: Números XML de ocho dígitos\n";
