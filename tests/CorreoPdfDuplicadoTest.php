<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/soporte/ArchivoDePrueba.php';

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
// La bandeja va a algún lado: a una carpeta de prueba, no a la de verdad.
$raizPrueba = ArchivoDePrueba::enDisco('correo_pdf_duplicado');
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

    // Una nota de crédito procesada a mano no se captura (por ahí solo entran
    // facturas), pero su PDF no es un PDF huérfano: el XML sí vino. El
    // 05/10/2026 ese PDF terminaba en sin_identificar/ con el aviso "vino sin
    // su XML", y se leía como un problema de PDF en un correo que estaba bien.
    $claveNota = '50605102600310102007000100003030000750141100750141';
    $xmlNota = tempnam(sys_get_temp_dir(), 'correo_xml_nc_');
    $pdfNota = tempnam(sys_get_temp_dir(), 'correo_pdf_nc_');
    file_put_contents($xmlNota, str_replace(
        ['FacturaElectronica', 'facturaElectronica', $clave, '00100001010000001910'],
        ['NotaCreditoElectronica', 'notaCreditoElectronica', $claveNota, '00100003030000750141'],
        $xml
    ));
    file_put_contents($pdfNota, 'nota-de-credito');

    $bandejaNota = new CorreoBandejaPdfFalsa();
    $resultadoNota = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $xmlNota, 'nombre' => $claveNota . '.xml']],
            'pdfs' => [['ruta' => $pdfNota, 'nombre' => $claveNota . '.pdf']],
            'clave' => 'test-nota-a-mano',
            'remitente' => 'fe@example.test',
            'asunto' => 'Nota de Crédito Electrónica número 750141',
            'fecha' => '2026-10-05 21:32:37',
        ],
        $bandejaNota,
        new stdClass(),
        1,
        ['FE'],
        '3101639680'
    );

    assertCorreoPdf($bandejaNota->fila === null, 'la nota de crédito no entra a la bandeja a mano');
    assertCorreoPdf(($resultadoNota['pdfs_sin_identificar'] ?? 0) === 0, 'el PDF de la nota no va a sin_identificar');
    assertCorreoPdf(!is_file($pdfNota), 'el temporal del PDF de la nota se borra');
    assertCorreoPdf(count($resultadoNota['errores']) === 1
        && strpos($resultadoNota['errores'][0], 'Nota de crédito 750141') === 0,
        'un solo aviso, y dice que es una nota de crédito: ' . json_encode($resultadoNota['errores'], JSON_UNESCAPED_UNICODE));
    assertCorreoPdf(strpos(implode(' ', $resultadoNota['errores']), 'sin su XML') === false,
        'no dice que el PDF vino sin su XML');
    echo "OK: una nota de crédito procesada a mano se nombra como tal y su PDF no queda huérfano\n";

    // Con las notas admitidas —que es como procesa ahora el botón manual—, la
    // nota espera en la bandeja igual que una factura: pendiente, con su tipo
    // y con su PDF emparejado.
    file_put_contents($xmlNota, str_replace(
        ['FacturaElectronica', 'facturaElectronica', $clave, '00100001010000001910'],
        ['NotaCreditoElectronica', 'notaCreditoElectronica', $claveNota, '00100003030000750141'],
        $xml
    ));
    file_put_contents($pdfNota, 'nota-de-credito');
    $bandejaNota = new CorreoBandejaPdfFalsa();
    $resultadoNota = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $xmlNota, 'nombre' => $claveNota . '.xml']],
            'pdfs' => [['ruta' => $pdfNota, 'nombre' => $claveNota . '.pdf']],
            'clave' => 'test-nota-a-mano',
            'remitente' => 'fe@example.test',
            'asunto' => 'Nota de Crédito Electrónica número 750141',
            'fecha' => '2026-10-05 21:32:37',
        ],
        $bandejaNota,
        new stdClass(),
        1,
        EntradaAutomatica::TIPOS,
        '3101639680'
    );
    assertCorreoPdf(($bandejaNota->fila['tipo_doc'] ?? '') === 'NC' && ($bandejaNota->fila['estado'] ?? '') === 'pendiente',
        'la nota entra a la bandeja como NC pendiente: ' . json_encode($resultadoNota['errores'], JSON_UNESCAPED_UNICODE));
    assertCorreoPdf(($bandejaNota->fila['numero_corto'] ?? '') === '750141', 'con su número');
    assertCorreoPdf(($resultadoNota['nuevas'] ?? 0) === 1 && ($resultadoNota['notas_nuevas'] ?? 0) === 1,
        'cuenta como nueva y como nota');
    assertCorreoPdf(($resultadoNota['pdfs_guardados'] ?? 0) === 1 && is_file($bandejaNota->fila['archivo_pdf'] ?? '')
        && file_get_contents($bandejaNota->fila['archivo_pdf']) === 'nota-de-credito', 'con su PDF emparejado');
    assertCorreoPdf(empty($resultadoNota['errores']), 'y sin avisos');
    echo "OK: una nota de crédito espera en la bandeja igual que una factura\n";

    // Admitir notas de crédito no abre la puerta a las de débito: esas no
    // entran por ningún camino, y su PDF tampoco queda dando vueltas.
    file_put_contents($xmlNota, str_replace(
        ['FacturaElectronica', 'facturaElectronica', $clave, '00100001010000001910'],
        ['NotaDebitoElectronica', 'notaDebitoElectronica', $claveNota, '00100003020000750141'],
        $xml
    ));
    file_put_contents($pdfNota, 'nota-de-debito');
    $bandejaDebito = new CorreoBandejaPdfFalsa();
    $resultadoDebito = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $xmlNota, 'nombre' => $claveNota . '.xml']],
            'pdfs' => [['ruta' => $pdfNota, 'nombre' => $claveNota . '.pdf']],
            'clave' => 'test-debito-a-mano',
            'remitente' => 'fe@example.test',
            'asunto' => 'Nota de Débito Electrónica',
            'fecha' => '2026-10-05 21:32:37',
        ],
        $bandejaDebito,
        new stdClass(),
        1,
        EntradaAutomatica::TIPOS,
        '3101639680'
    );
    assertCorreoPdf($bandejaDebito->fila === null && ($resultadoDebito['nuevas'] ?? 0) === 0,
        'una nota de débito no entra a la bandeja: ' . json_encode($resultadoDebito['errores'], JSON_UNESCAPED_UNICODE));
    assertCorreoPdf(strpos(implode(' ', $resultadoDebito['errores']), 'Nota de débito descartada') !== false,
        'y se dice que se descartó');
    assertCorreoPdf(($resultadoDebito['pdfs_sin_identificar'] ?? 0) === 0 && !is_file($pdfNota) && !is_file($xmlNota),
        'sin dejar su XML ni su PDF en ningún lado');
    echo "OK: una nota de débito sigue sin entrar, se procese como se procese\n";

    // La cola importa cada fila con su tipo. La importación de la bandeja
    // declara los que lleva; las demás siguen con el único que tenían.
    require_once __DIR__ . '/../app/helpers/InvoiceImportQueue.php';
    assertCorreoPdf(InvoiceImportQueue::tiposDe(['tipo_documento' => 'FE', 'tipos_permitidos' => ['fe', 'NC']]) === ['FE', 'NC'],
        'una importación mixta admite facturas y notas');
    assertCorreoPdf(InvoiceImportQueue::tiposDe(['tipo_documento' => 'nc']) === ['NC'], 'una de notas, solo notas');
    assertCorreoPdf(InvoiceImportQueue::tiposDe([]) === ['FE'], 'sin nada dicho, facturas, como siempre');
    assertCorreoPdf(InvoiceImportQueue::tiposDe(['tipo_documento' => 'FE', 'tipos_permitidos' => []]) === ['FE'],
        'una lista vacía no deja la importación sin tipos');
    echo "OK: la cola de importación admite los tipos que declara la importación\n";
} finally {
    foreach ([$xmlRuta, $pdfInterpretacion, $pdfPrincipal, $xmlNota ?? '', $pdfNota ?? '', $bandeja->fila['archivo_xml'] ?? '', $bandeja->fila['archivo_pdf'] ?? '', $bandejaNota->fila['archivo_xml'] ?? '', $bandejaNota->fila['archivo_pdf'] ?? ''] as $ruta) {
        if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
            unlink($ruta);
        }
    }
    ArchivoDePrueba::soltar($raizPrueba);
}
