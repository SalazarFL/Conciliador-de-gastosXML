<?php
/**
 * El pago semanal no inventa facturas: paga las que el ERP ya reportó.
 *
 * La comprobación existía solo al CERRAR el listado, que es demasiado tarde:
 * para entonces ya se emparejó la semana entera. Ahora corre al cargar, y
 * bloquea la carga completa si falta alguna.
 *
 * Lo que esta prueba vigila de verdad es la lectura del número. El listado del
 * pago semanal escribe el documento de tres formas según de dónde lo
 * exportaron, y una regla que solo entendiera la primera rechazaría listados
 * enteros que sí están en el ERP (medido sobre los datos del cliente: los
 * listados de julio traen 84 de 214 líneas en forma corta).
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertRespaldoErp($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** El reporte del ERP, sin base de datos. */
class FacturaErpFalsa extends FacturaErp
{
    public $filas = [];
    public $consultas = 0;

    public function __construct() { /* sin conexión */ }

    protected function fetchAll($sql, $params = [])
    {
        $this->consultas++;
        return $this->filas;
    }

    protected function condicionSociedad($alias = '', ?array &$params = null)
    {
        return '';
    }
}

// ── 1. Las tres formas del número ──────────────────────────────
$casos = [
    // consecutivo electrónico + correlativo del ERP
    'FACT-00200001010000045587-1377' => ['00200001010000045587', '45587'],
    // número interno del ERP, corto
    'FACT-12339'                     => ['', '12339'],
    // el mismo interno, rellenado con ceros hasta parecer un consecutivo
    'FACT-00000000000000000000000000000000000000000000039547' => ['', '39547'],
];
foreach ($casos as $numero => $esperado) {
    $llaves = FacturaErp::llavesDeNumero($numero);
    assertRespaldoErp($llaves['consecutivo'] === $esperado[0],
        "consecutivo de «{$numero}»: esperaba «{$esperado[0]}», salió «{$llaves['consecutivo']}»");
    assertRespaldoErp($llaves['corto'] === $esperado[1],
        "número corto de «{$numero}»: esperaba «{$esperado[1]}», salió «{$llaves['corto']}»");
}

// Una tira de ceros no es un consecutivo: si se tomara como tal, ninguna
// factura vieja cruzaría nunca y el listado entero quedaría bloqueado.
$ceros = FacturaErp::llavesDeNumero('FACT-00000000000000000000');
assertRespaldoErp($ceros['consecutivo'] === '', 'veinte ceros no son un consecutivo');

// ── 2. Qué falta y qué no ──────────────────────────────────────
$modelo = new FacturaErpFalsa();
$modelo->filas = [
    ['documento' => '00200001010000045587', 'numero_corto' => '0000045587'],
    ['documento' => '00100001010000322839', 'numero_corto' => '0000322839'],
    ['documento' => '',                     'numero_corto' => '0000012339'],
];

$faltantes = $modelo->faltantesEnErp([
    'FACT-00200001010000045587-1377',                             // por consecutivo
    'FACT-12339',                                                 // por número corto
    'FACT-00000000000000000000000000000000000000000000012339',    // corto con relleno
    'FACT-00300001010000034399-220',                              // no está
    'FACT-99999',                                                 // no está
]);

assertRespaldoErp(count($faltantes) === 2, 'encuentra exactamente las dos que no están (salieron ' . count($faltantes) . ')');
assertRespaldoErp(in_array('FACT-00300001010000034399-220', $faltantes, true), 'reporta la que no está por consecutivo');
assertRespaldoErp(in_array('FACT-99999', $faltantes, true), 'reporta la que no está por número corto');
assertRespaldoErp($modelo->consultas === 1,
    'lee el reporte de una sola vez: con la base a 100 ms, una consulta por línea es media semana esperando');

// Sin números que revisar no se consulta nada.
$vacio = new FacturaErpFalsa();
assertRespaldoErp($vacio->faltantesEnErp([]) === [], 'sin líneas no hay nada que faltar');
assertRespaldoErp($vacio->consultas === 0, 'sin líneas ni siquiera consulta');

// Un listado íntegro no reporta nada: es el caso normal y el que no debe
// molestar. (La semana 060826 real cerró con 266/266.)
$todas = $modelo->faltantesEnErp(['FACT-00200001010000045587-1377', 'FACT-12339']);
assertRespaldoErp($todas === [], 'un listado completo no reporta faltantes');

echo "OK: el pago semanal exige que cada factura esté en un listado del ERP\n";
