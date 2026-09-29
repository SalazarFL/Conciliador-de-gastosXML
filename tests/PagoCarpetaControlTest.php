<?php
/**
 * La carpeta del pago, contada contra el pago.
 *
 * El pago del 28/09/2026 tenía 308 facturas y su carpeta 614 archivos: a una
 * le faltaba el PDF, no se copió —bien— y nada lo dijo —mal—. La pantalla
 * decía que las 308 estaban respaldadas. ControlCarpetaPago mira lo que hay
 * en la carpeta y nombra cuáles faltan y por qué; acá se comprueba el cruce.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/ControlCarpetaPago.php';

function assertControl($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function lineaPago($id, $xmlId, $numero, $proveedor)
{
    return ['id' => $id, 'factura_xml_id' => $xmlId, 'numero_corto' => $numero,
            'documento' => '0010000101' . $numero, 'proveedor_nombre' => $proveedor];
}

function docPago($id, $numero, $proveedor, $fecha, array $extra = [])
{
    return $extra + [
        'id' => $id, 'tipo_documento' => 'FE', 'fecha_emision' => $fecha,
        'numero_factura_asistente' => $numero, 'proveedor_nombre' => $proveedor,
        'ruta_xml' => 'sharepoint:SISTEMA/x.xml', 'ruta_pdf' => 'sharepoint:SISTEMA/x.pdf',
        'almacen' => 'sharepoint',
    ];
}

$lineas = [
    lineaPago(1, 11, '00001334', '3-102-946482'),
    lineaPago(2, 12, '00000065', 'GRANADOS FALLAS ANTONIO'),
];
$docs = [
    11 => docPago(11, '00001334', '3-102-946482 SRL', '2026-08-03'),
    12 => docPago(12, '00000065', 'GRANADOS FALLAS ANTONIO', '2026-08-31', ['ruta_pdf' => null]),
];
$carpeta = [
    'FE_3_102_946482_030826_00001334.xml',
    'FE_3_102_946482_030826_00001334.pdf',
];

// ── El caso de ese pago: una factura sin PDF ─────────────────────
$r = ControlCarpetaPago::comparar($lineas, $docs, $carpeta);
assertControl($r['total'] === 2 && $r['completas'] === 1, 'cuenta 1 de 2 completas');
assertControl($r['archivos'] === 2, 'cuenta los archivos que hay de verdad');
assertControl(count($r['faltan']) === 1, 'nombra la que falta');
assertControl($r['faltan'][0]['numero'] === '00000065', 'por su número corto');
assertControl($r['faltan'][0]['proveedor'] === 'GRANADOS FALLAS ANTONIO', 'y su proveedor');
assertControl($r['faltan'][0]['motivo'] === 'sin_pdf', 'y dice que es el PDF lo que nunca llegó');
assertControl(strpos(ControlCarpetaPago::explicar('sin_pdf'), 'pedírselo') !== false,
    'y qué hacer: pedírselo al proveedor');
assertControl($r['sobran'] === [], 'no inventa sobrantes');

// ── Completa ─────────────────────────────────────────────────────
$docs[12]['ruta_pdf'] = 'sharepoint:SISTEMA/y.pdf';
$completa = array_merge($carpeta, [
    'FE_GRANADOS_FALLAS_ANTONIO_310826_00000065.xml',
    'FE_GRANADOS_FALLAS_ANTONIO_310826_00000065.pdf',
]);
$r = ControlCarpetaPago::comparar($lineas, $docs, $completa);
assertControl($r['completas'] === 2 && !$r['faltan'] && !$r['sobran'], 'con los dos pares, está completa');

// ── Con el par completo en la base pero sin copia en la carpeta ──
$r = ControlCarpetaPago::comparar($lineas, $docs, $carpeta);
assertControl($r['faltan'][0]['motivo'] === 'sin_copiar',
    'un par completo que no está en la carpeta se reconoce como copia pendiente');

// ── Media copia: el XML está, el PDF no ─────────────────────────
$r = ControlCarpetaPago::comparar($lineas, $docs, array_merge($carpeta, ['FE_GRANADOS_FALLAS_ANTONIO_310826_00000065.xml']));
assertControl($r['completas'] === 1, 'medio par no cuenta como factura en la carpeta');
assertControl($r['sobran'] === ['FE_GRANADOS_FALLAS_ANTONIO_310826_00000065.xml'],
    'y la mitad suelta se enseña');

// ── SharePoint no distingue mayúsculas ──────────────────────────
$r = ControlCarpetaPago::comparar($lineas, $docs, array_map('strtolower', $completa));
assertControl($r['completas'] === 2, 'los nombres se comparan sin distinguir mayúsculas');

// ── Dos facturas con el mismo nombre: la segunda va con _DUP_ ───
$lineasDup = [lineaPago(1, 21, '00000100', 'ACME'), lineaPago(2, 22, '00000100', 'ACME')];
$docsDup = [
    21 => docPago(21, '00000100', 'ACME', '2026-08-01'),
    22 => docPago(22, '00000100', 'ACME', '2026-08-01'),
];
$r = ControlCarpetaPago::comparar($lineasDup, $docsDup, [
    'FE_ACME_010826_00000100.xml', 'FE_ACME_010826_00000100.pdf',
]);
assertControl($r['completas'] === 1 && count($r['faltan']) === 1,
    'un solo par no alcanza para dos facturas del mismo nombre');
$r = ControlCarpetaPago::comparar($lineasDup, $docsDup, [
    'FE_ACME_010826_00000100.xml', 'FE_ACME_010826_00000100.pdf',
    'FE_ACME_010826_00000100_DUP_A1B2C3D4.xml', 'FE_ACME_010826_00000100_DUP_A1B2C3D4.pdf',
]);
assertControl($r['completas'] === 2 && !$r['sobran'], 'la copia con _DUP_ cuenta para la segunda');

// ── Sin comprobante, perdido, en disco ──────────────────────────
$r = ControlCarpetaPago::comparar([lineaPago(9, null, '00000009', 'X')], [], []);
assertControl($r['faltan'][0]['motivo'] === 'sin_comprobante', 'una línea sin XML se nombra como sin comprobante');

$r = ControlCarpetaPago::comparar([lineaPago(1, 11, '00001334', 'X')],
    [11 => docPago(11, '00001334', 'X', '2026-08-03', ['archivo_faltante_en' => '2026-09-01 10:00:00'])], []);
assertControl($r['faltan'][0]['motivo'] === 'perdido', 'un archivo borrado del almacén se nombra como perdido');

$r = ControlCarpetaPago::comparar([lineaPago(1, 11, '00001334', 'X')],
    [11 => docPago(11, '00001334', 'X', '2026-08-03', ['almacen' => 'disco'])], []);
assertControl($r['faltan'][0]['motivo'] === 'en_disco', 'lo que sigue en el disco de antes se dice');

// ── Lo que no es del pago ───────────────────────────────────────
$r = ControlCarpetaPago::comparar($lineas, $docs, array_merge($completa, [
    'FE_OTRO_010826_00000001.xml', 'FE_OTRO_010826_00000001.pdf', 'desktop.ini',
]));
assertControl($r['completas'] === 2, 'lo ajeno no estorba a las que sí están');
assertControl(count($r['sobran']) === 2, 'los comprobantes ajenos se enseñan; lo que no es XML ni PDF se ignora');

// ── La carga del pago nombra las incompletas también en SharePoint ─
$fuente = (string) file_get_contents(__DIR__ . '/../app/controllers/PorPagarController.php');
preg_match('/function reubicarArchivos\(.*?\n    }\n/s', $fuente, $m);
assertControl(!empty($m[0]) && strpos($m[0], "'sharepoint'") !== false
    && strpos($m[0], "\$resultado['incompletos'][]") !== false,
    'reubicarArchivos() anota las incompletas de SharePoint, no solo las cuenta');

// ── Y la pantalla del pago hace el control ─────────────────────
preg_match('/function index\(.*?\n    }\n/s', $fuente, $m);
assertControl(!empty($m[0]) && strpos($m[0], 'ControlCarpetaPago::revisar(') !== false,
    'la pantalla del pago revisa la carpeta');

echo "OK PagoCarpetaControlTest\n";
