<?php
/**
 * Lectura del reporte agrupado del pago semanal.
 *
 * El reporte no trae encabezados de columna: es "Proveedor <código> <nombre>"
 * y debajo las facturas de ese proveedor. El nombre solo cambia cuando se
 * reconoce un encabezado, así que un encabezado que se escapa no produce ni
 * error ni fila perdida: produce facturas atribuidas al proveedor ANTERIOR,
 * que es la peor forma de fallar porque se ve bien.
 *
 * Eso pasó de verdad (semana 130826): la factura 00100001010002629934 de
 * COMPAÑIA AMERICANA DE HELADOS (código 140000076) salió a nombre de COMERCIO
 * E INDUSTRIA GIMA (140000074), el grupo inmediatamente anterior. El ERP
 * exporta en Windows-1252 y la Ñ hacía que preg_match con /u devolviera FALSE
 * —no "no coincide", FALSE— y el encabezado se tomara por una fila más.
 */
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PorPagarController.php';

function assertReporte($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Sin sesión ni base: solo se ejercita la lectura del archivo. */
class PorPagarLector extends PorPagarController
{
    public function __construct() { /* sin requireAuth */ }

    public function leer($dataset)
    {
        foreach (['normalizarDataset', 'extraerReporteAgrupado'] as $metodo) {
            $ref = new ReflectionMethod(PorPagarController::class, $metodo);
            $ref->setAccessible(true);
            $dataset = $ref->invoke($this, $dataset);
        }
        return $dataset;
    }
}

$lector = new PorPagarLector();

// El archivo tal como lo escribe el ERP: Windows-1252, no UTF-8.
$n = chr(0xD1); // Ñ en Windows-1252
$dataset = [
    'header' => ['Reporte de pagos'],
    'rows' => [
        ['Proveedor 140000074 COMERCIO E INDUSTRIA GIMA SOCIEDAD ANONIMA'],
        ['FACT-00100001010000081822-1159', '13/07/2026', '20/07/2026', '₡', '520866.72'],
        ['Proveedor 140000076 COMPA' . $n . 'IA AMERICANA DE HELADOS S.A.'],
        ['FACT-00100001010002629934-822', '11/07/2026', '18/07/2026', '₡', '88437.19'],
        ['Proveedor 140000065 COMERCIAL DINANT DE C.R. S.A.'],
        ['FACT-00400001010000306400-795', '09/07/2026', '16/07/2026', '₡', '130032.46'],
    ],
];

$filas = $lector->leer($dataset);
assertReporte(count($filas) === 3, 'lee las tres facturas (leyó ' . count($filas) . ')');

$porNumero = [];
foreach ($filas as $f) {
    $porNumero[$f['numero']] = $f['proveedor'];
}

assertReporte(
    strpos($porNumero['FACT-00100001010002629934-822'], 'HELADOS') !== false,
    'la factura de HELADOS queda a nombre de HELADOS y no del grupo anterior; '
    . 'salió «' . $porNumero['FACT-00100001010002629934-822'] . '»'
);
assertReporte(
    $porNumero['FACT-00100001010002629934-822'] === 'COMPAÑIA AMERICANA DE HELADOS S.A.',
    'el nombre se guarda en UTF-8, que es como se compara contra el ERP'
);
assertReporte(
    strpos($porNumero['FACT-00100001010000081822-1159'], 'GIMA') !== false,
    'el grupo anterior conserva el suyo'
);
assertReporte(
    strpos($porNumero['FACT-00400001010000306400-795'], 'DINANT') !== false,
    'el grupo siguiente también'
);

// Un archivo que ya viene en UTF-8 no se toca.
$utf8 = $lector->leer([
    'header' => ['Reporte'],
    'rows' => [
        ['Proveedor 140000076 COMPAÑIA AMERICANA DE HELADOS S.A.'],
        ['FACT-00100001010002629934-822', '11/07/2026', '18/07/2026', '₡', '88437.19'],
    ],
]);
assertReporte(count($utf8) === 1 && $utf8[0]['proveedor'] === 'COMPAÑIA AMERICANA DE HELADOS S.A.',
    'lo que ya viene en UTF-8 se respeta');

// Los montos y las fechas sobreviven al saneado.
assertReporte($utf8[0]['total'] === '88437.19', 'el monto se lee igual');
assertReporte($utf8[0]['fecha'] === '11/07/2026', 'la fecha de emisión se lee igual');

echo "OK: el reporte agrupado no le cambia el proveedor a las facturas\n";
