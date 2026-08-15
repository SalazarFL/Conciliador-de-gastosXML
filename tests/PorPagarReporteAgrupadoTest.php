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
 * E INDUSTRIA GIMA (140000074), el grupo inmediatamente anterior.
 *
 * La causa es que el reporte se trabaja a mano antes de subirlo: quien prepara
 * el pago marca proveedores con una "x" en la primera columna y deja
 * recordatorios al final ("CAMBIAR TXT"). El lector pegaba la fila entera y
 * exigía que empezara con "Proveedor"; empezando con "x", el encabezado se
 * tomaba por una fila más. Dos de los 140 grupos de ese archivo.
 */
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/core/Model.php';
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

// Las filas tal como vienen en el archivo real de la semana 130826: el
// encabezado repartido en celdas, y el de HELADOS con la "x" del preparador
// delante y su recordatorio detrás.
$dataset = [
    'header' => ['Reporte de pagos'],
    'rows' => [
        ['', '', 'Proveedor', '140000074', 'COMERCIO E INDUSTRIA GIMA SOCIEDAD '],
        ['', 'Documento'],
        ['', 'FACT-00100001010000081822-1159', '', '', '', '13/07/2026', '12/08/2026', '520866.72'],
        ['', '', '', '', '', '', '', '520866.72'],
        ['x', '', 'Proveedor', '140000076', 'COMPAÑIA AMERICANA DE HELADOS S.A.', '', '', '', 'CAMBIAR TXT'],
        ['', 'Documento'],
        ['', 'FACT-00100001010002629934-822', '', '', '', '11/07/2026', '10/08/2026', '88437.19'],
        ['', '', '', '', '', '', '', '88437.19'],
        ['', '', 'Proveedor', '140000065', 'COMERCIAL DINANT DE C.R. S.A.'],
        ['', 'FACT-00400001010000306400-795', '', '', '', '09/07/2026', '16/07/2026', '130032.46'],
        // Cierre del reporte: NO abre un grupo llamado "Total Proveedores".
        ['247474871.51499984', 'Total Proveedores'],
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
    'el nombre entra limpio: sin la "x" ni el recordatorio de al lado, porque '
    . 'así se compara contra el proveedor guardado y contra el del ERP'
);
assertReporte(
    strpos($porNumero['FACT-00100001010000081822-1159'], 'GIMA') !== false,
    'el grupo anterior conserva el suyo'
);
assertReporte(
    strpos($porNumero['FACT-00400001010000306400-795'], 'DINANT') !== false,
    'el grupo siguiente también'
);

// El cierre del reporte no puede pasar por proveedor: si pasara, las filas
// que vinieran después entrarían a nombre de "Total Proveedores".
assertReporte(!isset($porNumero['Total Proveedores']), 'el total del reporte no abre un grupo');

// El encabezado también viene todo en una celda según de dónde se exporte.
$unaCelda = $lector->leer([
    'header' => ['Reporte'],
    'rows' => [
        ['Proveedor 140000076 COMPAÑIA AMERICANA DE HELADOS S.A.'],
        ['FACT-00100001010002629934-822', '11/07/2026', '18/07/2026', '₡', '88437.19'],
    ],
]);
assertReporte(count($unaCelda) === 1 && $unaCelda[0]['proveedor'] === 'COMPAÑIA AMERICANA DE HELADOS S.A.',
    'el encabezado en una sola celda se sigue entendiendo');
assertReporte($unaCelda[0]['total'] === '88437.19', 'el monto se lee igual');
assertReporte($unaCelda[0]['fecha'] === '11/07/2026', 'la fecha de emisión se lee igual');

// El ERP exporta el CSV en Windows-1252. No fue la causa de este error, pero
// la Ñ en esos bytes hace que preg_match con /u devuelva FALSE, y eso rompería
// el encabezado de la misma forma silenciosa.
$latin1 = $lector->leer([
    'header' => ['Reporte'],
    'rows' => [
        ['Proveedor', '140000076', 'COMPA' . chr(0xD1) . 'IA AMERICANA DE HELADOS S.A.'],
        ['FACT-00100001010002629934-822', '11/07/2026', '18/07/2026', '₡', '88437.19'],
    ],
]);
assertReporte(count($latin1) === 1 && $latin1[0]['proveedor'] === 'COMPAÑIA AMERICANA DE HELADOS S.A.',
    'Windows-1252 se convierte antes de leer, no después');

// Un rótulo de columna suelto no abre un grupo ni le roba el nombre al que
// está abierto.
$rotulo = $lector->leer([
    'header' => ['Reporte'],
    'rows' => [
        ['Proveedor', '140000065', 'COMERCIAL DINANT DE C.R. S.A.'],
        ['Proveedor', 'Documento', 'Fecha'],
        ['FACT-00400001010000306400-795', '09/07/2026', '16/07/2026', '130032.46'],
    ],
]);
assertReporte(count($rotulo) === 1 && $rotulo[0]['proveedor'] === 'COMERCIAL DINANT DE C.R. S.A.',
    'un "Proveedor" sin código es rótulo de columna, no un grupo nuevo');

echo "OK: el reporte agrupado no le cambia el proveedor a las facturas\n";
