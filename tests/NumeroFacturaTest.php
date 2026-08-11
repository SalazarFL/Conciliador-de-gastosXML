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

// ── similaridadNumero: el atajo no cambia ningún veredicto ───────────
// similaridadNumero() se salta similar_text() cuando la diferencia de largo
// hace imposible llegar al umbral, y devuelve el techo teórico en su lugar.
// Esto compara contra la versión sin atajo: el número exacto puede diferir
// para los no-match, pero el veredicto —pasa el umbral o no— nunca.
require_once __DIR__ . '/../app/helpers/FacturaMatcher.php';

$sinAtajo = function ($rawA, $rawB) {
    // Copia fiel de similaridadNumero() SIN el atajo del techo. Ojo: el núcleo
    // y las secuencias se sacan del texto crudo, no del normalizado.
    $a = FacturaMatcher::normalizarNumero($rawA);
    $b = FacturaMatcher::normalizarNumero($rawB);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 100;
    $coreA = FacturaMatcher::nucleoNumerico($rawA);
    $coreB = FacturaMatcher::nucleoNumerico($rawB);
    if ($coreA !== '' && $coreA === $coreB) return 100;
    if ($coreA !== '' && in_array($coreA, FacturaMatcher::secuenciasNumericas($rawB), true)) return 95;
    if ($coreB !== '' && in_array($coreB, FacturaMatcher::secuenciasNumericas($rawA), true)) return 95;
    if (FacturaMatcher::nucleoTerminaEn($coreB, $coreA) || FacturaMatcher::nucleoTerminaEn($coreA, $coreB)) return 100;
    if (max(strlen($a), strlen($b)) <= 6) {
        return levenshtein($a, $b) === 1 ? 50 : 0;
    }
    similar_text($a, $b, $pct);
    return $pct;
};

$pares = [
    ['FACT-26546', '00100001010000026546'],   // corto contra consecutivo
    ['0000071176', 'FACT-1-1-0000071176-360'],
    ['0000005061', 'FACT-01400020010000005061-3'],
    ['26546', '26547'],                        // vecinos
    ['26546', '99999'],
    ['0000017662', '0000017662'],
    ['FACT-26546', '0000031907'],              // largos parecidos, sin relación
    ['123456789012', '123456789099'],
    ['00100001010000026546', '00100001010000026547'],
    ['ABC-9', '0000000009'],
    ['', '00100001010000026546'],
];
foreach ($pares as $par) {
    [$a, $b] = $par;
    $conAtajo = FacturaMatcher::similaridadNumero($a, $b);
    $referencia = $sinAtajo($a, $b);
    $mismoVeredicto = ($conAtajo >= FacturaMatcher::UMBRAL_NUMERO)
        === ($referencia >= FacturaMatcher::UMBRAL_NUMERO);
    assertNumeroFactura($mismoVeredicto,
        "'{$a}' vs '{$b}': el atajo da el mismo veredicto que similar_text ({$conAtajo} vs {$referencia})");
    if ($referencia >= FacturaMatcher::UMBRAL_NUMERO) {
        // Cuando el par sí puede emparejar, el valor tiene que ser el exacto:
        // se guarda como score_numero de la línea.
        assertNumeroFactura(abs($conAtajo - $referencia) < 0.001,
            "'{$a}' vs '{$b}': cuando importa, el valor es exacto ({$conAtajo} vs {$referencia})");
    } else {
        // Cuando no puede, el atajo devuelve el techo teórico en vez del valor
        // real: es mayor o igual, pero sigue por debajo del umbral, que es lo
        // único que cualquier llamador consulta.
        assertNumeroFactura($conAtajo >= $referencia - 0.001,
            "'{$a}' vs '{$b}': el techo no queda por debajo del valor real ({$conAtajo} < {$referencia})");
        assertNumeroFactura($conAtajo < FacturaMatcher::UMBRAL_NUMERO,
            "'{$a}' vs '{$b}': un no-match nunca se convierte en match ({$conAtajo})");
    }
}

// Y que memorizar no cambie la respuesta la segunda vez.
$primera = FacturaMatcher::similaridadNumero('FACT-26546', '00100001010000026546');
FacturaMatcher::olvidarMemoriaNumeros();
assertNumeroFactura(
    FacturaMatcher::similaridadNumero('FACT-26546', '00100001010000026546') === $primera,
    'la memoria de números devuelve lo mismo que el primer cálculo'
);

echo "OK: Números XML de ocho dígitos\n";
