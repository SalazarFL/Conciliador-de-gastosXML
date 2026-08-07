<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';

function assertCorreoPdf($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class CorreoBandejaPdfFalsa
{
    public $fila;

    public function getPorUidHash($uid, $hash)
    {
        return null;
    }

    public function crear($data)
    {
        $this->fila = $data;
        return 1;
    }
}

$clave = '50607062600011321045500100001010000001910143537651';
$xmlRuta = tempnam(sys_get_temp_dir(), 'correo_xml_');
$pdfInterpretacion = tempnam(sys_get_temp_dir(), 'correo_pdf_i_');
$pdfPrincipal = tempnam(sys_get_temp_dir(), 'correo_pdf_f_');
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
    . '<Clave>' . $clave . '</Clave>'
    . '<NumeroConsecutivo>00100001010000001910</NumeroConsecutivo>'
    . '<FechaEmision>2026-06-07T13:09:00-06:00</FechaEmision>'
    . '<Emisor><Nombre>MANUEL UREÑA BARRANTES</Nombre><Identificacion><Numero>113210455</Numero></Identificacion></Emisor>'
    . '<Receptor><Nombre>GRUPO BM SP SA</Nombre><Identificacion><Numero>3101639680</Numero></Identificacion></Receptor>'
    . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
    . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
    . '</FacturaElectronica>';
file_put_contents($xmlRuta, $xml);
file_put_contents($pdfInterpretacion, 'interpretacion');
file_put_contents($pdfPrincipal, 'factura-principal');

$bandeja = new CorreoBandejaPdfFalsa();
try {
    $ref = new ReflectionClass(CorreoController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);
    $corresponde = $ref->getMethod('pdfCorrespondeFactura');
    $corresponde->setAccessible(true);
    assertCorreoPdf($corresponde->invoke($controller, 'Interpretacion_' . $clave . '.PDF', ['numero_corto' => '1910']), 'identifica la representación duplicada');
    assertCorreoPdf(!$corresponde->invoke($controller, 'Factura_9999.pdf', ['numero_corto' => '1910']), 'no confunde un PDF de otra factura con un duplicado');
    $resultado = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $xmlRuta, 'nombre' => $clave . '.xml']],
            // Interpretacion llega primero para comprobar que no desplace al PDF principal.
            'pdfs' => [
                ['ruta' => $pdfInterpretacion, 'nombre' => 'Interpretacion_' . $clave . '.PDF'],
                ['ruta' => $pdfPrincipal, 'nombre' => 'FC-' . $clave . '.pdf'],
            ],
            'clave' => 'test-pdf-duplicado',
            'remitente' => 'fe@example.test',
            'asunto' => 'Documento Electrónico - MANUEL UREÑA BARRANTES',
            'fecha' => '2026-06-07 13:09:00',
        ],
        $bandeja,
        new stdClass(),
        1,
        ['FE'],
        '3101639680'
    );

    assertCorreoPdf(($resultado['pdfs_guardados'] ?? 0) === 1, 'guarda un solo PDF para la factura');
    assertCorreoPdf(($resultado['pdfs_duplicados_omitidos'] ?? 0) === 1, 'reconoce el PDF de interpretación como duplicado');
    assertCorreoPdf(($resultado['pdfs_sin_identificar'] ?? 0) === 0, 'no manda el PDF duplicado a sin_identificar');
    assertCorreoPdf(empty($resultado['errores']), 'no genera una incidencia por el PDF duplicado');
    assertCorreoPdf(!is_file($pdfInterpretacion), 'elimina el temporal de interpretación ya cubierto');
    assertCorreoPdf(is_file($bandeja->fila['archivo_pdf'] ?? ''), 'conserva el PDF principal emparejado');
    assertCorreoPdf(file_get_contents($bandeja->fila['archivo_pdf']) === 'factura-principal', 'prefiere FC sobre Interpretacion');
    echo "OK: Correo omite PDF duplicado del mismo comprobante\n";
} finally {
    foreach ([$xmlRuta, $pdfInterpretacion, $pdfPrincipal, $bandeja->fila['archivo_xml'] ?? '', $bandeja->fila['archivo_pdf'] ?? ''] as $ruta) {
        if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
            unlink($ruta);
        }
    }
}
