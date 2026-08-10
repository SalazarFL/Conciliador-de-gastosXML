<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';

function assertCorreoReproceso($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class CorreoBandejaReprocesoFalsa
{
    public $previa;
    public $revivida;
    public $creadas = 0;

    public function getPorUidHash($uid, $hash)
    {
        return $this->previa;
    }

    public function revivir($id, $data)
    {
        $this->revivida = ['id' => $id] + $data;
        return 1;
    }

    public function crear($data)
    {
        $this->creadas++;
        return 99;
    }
}

$clave = '50607062600011321045500100001010000001910143537651';
$xmlRuta = tempnam(sys_get_temp_dir(), 'correo_re_xml_');
$pdfRuta = tempnam(sys_get_temp_dir(), 'correo_re_pdf_');
$xmlAnterior = tempnam(sys_get_temp_dir(), 'correo_old_xml_');
$pdfAnterior = tempnam(sys_get_temp_dir(), 'correo_old_pdf_');
$raizTrabajo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_reproceso_' . uniqid();
mkdir($raizTrabajo, 0777, true);
RutaDocumento::fijarRaiz($raizTrabajo);
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
    . '<Clave>' . $clave . '</Clave>'
    . '<NumeroConsecutivo>00100001010000001910</NumeroConsecutivo>'
    . '<FechaEmision>2026-06-07T13:09:00-06:00</FechaEmision>'
    . '<Emisor><Nombre>PROVEEDOR PRUEBA</Nombre><Identificacion><Numero>113210455</Numero></Identificacion></Emisor>'
    . '<Receptor><Nombre>GRUPO BM SP SA</Nombre><Identificacion><Numero>3101639680</Numero></Identificacion></Receptor>'
    . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
    . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
    . '</FacturaElectronica>';
file_put_contents($xmlRuta, $xml);
file_put_contents($pdfRuta, 'pdf-nuevo');
file_put_contents($xmlAnterior, 'xml-anterior');
file_put_contents($pdfAnterior, 'pdf-anterior');

$bandeja = new CorreoBandejaReprocesoFalsa();
$bandeja->previa = [
    'id' => 77,
    'estado' => 'importada',
    'tipo_doc' => 'FE',
    'archivo_xml' => $xmlAnterior,
    'archivo_pdf' => $pdfAnterior,
];

try {
    $ref = new ReflectionClass(CorreoController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);
    $resultado = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $xmlRuta, 'nombre' => $clave . '.xml']],
            'pdfs' => [['ruta' => $pdfRuta, 'nombre' => 'Factura_' . $clave . '.pdf']],
            'clave' => 'correo-reprocesable',
            'remitente' => 'factura@example.test',
            'asunto' => 'Factura reprocesable',
            'fecha' => '2026-06-07 13:09:00',
        ],
        $bandeja,
        new stdClass(),
        1,
        ['FE'],
        '',
        0,
        [['id' => 42, 'cedula' => '3101639680']],
        'automatica'
    );

    assertCorreoReproceso($bandeja->creadas === 0, 'reutiliza la fila del mismo correo y XML');
    assertCorreoReproceso(($bandeja->revivida['id'] ?? 0) === 77, 'reactiva la fila importada');
    assertCorreoReproceso(($bandeja->revivida['estado'] ?? '') === 'pendiente', 'la deja lista para importar otra vez');
    assertCorreoReproceso(($bandeja->revivida['origen'] ?? '') === 'automatica',
        'identifica que la fila fue capturada por el worker');
    assertCorreoReproceso((int) ($bandeja->revivida['sociedad_id'] ?? 0) === 42,
        'asigna la sociedad según la cédula receptora del XML');
    assertCorreoReproceso(is_file($bandeja->revivida['archivo_xml'] ?? ''), 'conserva el nuevo XML');
    assertCorreoReproceso(is_file($bandeja->revivida['archivo_pdf'] ?? ''), 'conserva el nuevo PDF');
    assertCorreoReproceso(!is_file($xmlAnterior) && !is_file($pdfAnterior), 'limpia los temporales anteriores');
    assertCorreoReproceso(($resultado['nuevas'] ?? 0) === 1, 'reporta el correo reprocesado en la bandeja');
    echo "OK: Correo permite reprocesar una factura importada\n";
} finally {
    foreach ([$xmlRuta, $pdfRuta, $xmlAnterior, $pdfAnterior,
              $bandeja->revivida['archivo_xml'] ?? '', $bandeja->revivida['archivo_pdf'] ?? ''] as $ruta) {
        if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
            unlink($ruta);
        }
    }
    foreach (['BANDEJA/xml', 'BANDEJA/pdf', 'BANDEJA/sin-identificar', 'BANDEJA', ''] as $sub) {
        $dir = $raizTrabajo . DIRECTORY_SEPARATOR . RutaDocumento::TRABAJO;
        if ($sub !== '') {
            $dir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
        }
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
    @rmdir($raizTrabajo);
    RutaDocumento::olvidarRaiz();
}
