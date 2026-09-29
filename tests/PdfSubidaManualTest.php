<?php
/**
 * Subir a mano el PDF que el proveedor no adjuntó.
 *
 * Antonio Granados Fallas mandó el PDF de la 00000065 como enlace de
 * OneDrive: el correo traía solo el XML y el pago del 28/09/2026 salió con una
 * factura de menos. No había dónde subir un PDF suelto. Ahora hay un botón, y
 * antes de guardar se comprueba que el PDF nombre a la factura (PdfDeFactura):
 * subir el PDF de otra dejaría la carpeta del pago "completa" con el respaldo
 * equivocado.
 */
require_once __DIR__ . '/../app/helpers/PdfDeFactura.php';

function assertSubidaPdf($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$fila = [
    'clave' => '50631082600010495064200100003010000000065133187681',
    'consecutivo_completo' => '00100003010000000065',
];

// ── Qué PDF es de qué factura ───────────────────────────────────
assertSubidaPdf(PdfDeFactura::corresponde("Clave: 5063108260001049506420010000301\n0000000065133187681", $fila) === true,
    'la clave partida en dos renglones se reconoce');
assertSubidaPdf(PdfDeFactura::corresponde('Consecutivo 001-00003-01-0000000065', $fila) === true,
    'el consecutivo con guiones se reconoce');
assertSubidaPdf(PdfDeFactura::corresponde('Consecutivo 00100003010000000066', $fila) === false,
    'el PDF de la factura siguiente no pasa');
assertSubidaPdf(PdfDeFactura::corresponde('Factura 65 por 380.000', $fila) === false,
    'el número corto solo no alcanza: "65" está en cualquier PDF');
assertSubidaPdf(PdfDeFactura::corresponde("\f  \n", $fila) === null,
    'un PDF escaneado, sin texto, no se puede comprobar: se dice, no se frena');
assertSubidaPdf(PdfDeFactura::corresponde('00100003010000000065', ['clave' => '', 'consecutivo_completo' => '']) === false,
    'sin clave ni consecutivo en la fila no hay con qué dar por buena la coincidencia');

// ── Que sea un PDF de verdad ─────────────────────────────────────
$tmp = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmp, "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n");
assertSubidaPdf(PdfDeFactura::esPdf($tmp), 'un PDF se reconoce por su contenido');
file_put_contents($tmp, '<?xml version="1.0"?><FacturaElectronica/>');
assertSubidaPdf(!PdfDeFactura::esPdf($tmp), 'un XML renombrado a .pdf no pasa');
unlink($tmp);

// ── La puerta existe y tiene su permiso ─────────────────────────
$rutas = (string) file_get_contents(__DIR__ . '/../app/config/routes.php');
assertSubidaPdf(strpos($rutas, "'/documentos/pdf/{id}/subir', 'DocumentosController@subirPdf'") !== false,
    'la ruta para subir el PDF existe');
$permisos = require __DIR__ . '/../app/config/permisos.php';
$plano = var_export($permisos, true);
assertSubidaPdf(strpos($plano, "'POST /documentos/pdf/{id}/subir'") !== false,
    'la ruta pide permiso: sin él, cualquiera con sesión podría cambiar respaldos');

$controlador = (string) file_get_contents(__DIR__ . '/../app/controllers/DocumentosController.php');
assertSubidaPdf(strpos($controlador, 'PdfDeFactura::corresponde(') !== false,
    'el controlador comprueba que el PDF sea de la factura antes de guardarlo');
assertSubidaPdf(strpos($controlador, 'ya tiene su PDF') !== false,
    'y no reemplaza un PDF que ya está');

echo "OK PdfSubidaManualTest\n";
