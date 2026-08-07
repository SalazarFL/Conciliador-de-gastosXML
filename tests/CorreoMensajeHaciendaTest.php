<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';

function assertCorreoMh($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ruta = tempnam(sys_get_temp_dir(), 'xmlconcilia_mh_');
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda">'
    . '<Clave>50630062600310101706200100001030010106420130106420</Clave>'
    . '<NombreEmisor>DERIVADOS DE MAIZ ALIMENTICIO, SA</NombreEmisor>'
    . '<NumeroCedulaEmisor>3101017062</NumeroCedulaEmisor>'
    . '<NumeroCedulaReceptor>3101639680</NumeroCedulaReceptor>'
    . '<Mensaje>1</Mensaje><EstadoMensaje>Aceptado</EstadoMensaje>'
    . '<DetalleMensaje>.</DetalleMensaje><MontoTotalImpuesto>46189.26</MontoTotalImpuesto>'
    . '<TotalFactura>718309.29</TotalFactura></MensajeHacienda>';
file_put_contents($ruta, $xml);

try {
    $ref = new ReflectionClass(CorreoController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);

    $resultado = $procesar->invoke(
        $controller,
        [
            'xmls' => [['ruta' => $ruta, 'nombre' => 'respuesta-MH.xml']],
            'pdfs' => [],
            'clave' => 'test-mensaje-hacienda',
            'remitente' => 'hacienda@example.test',
            'asunto' => 'Mensaje de Hacienda',
            'fecha' => '2026-06-30 13:05:20',
        ],
        new stdClass(),
        new stdClass(),
        1,
        ['FE', 'NC'],
        '3101639680'
    );

    assertCorreoMh(($resultado['nuevas'] ?? -1) === 0, 'no crea documentos desde un MensajeHacienda aislado');
    assertCorreoMh(empty($resultado['filas']), 'no agrega el MensajeHacienda a la bandeja');
    assertCorreoMh(!is_file($ruta), 'descarta el XML temporal del MensajeHacienda');
    echo "OK: Correo ignora MensajeHacienda aislado\n";
} finally {
    if (is_file($ruta)) {
        unlink($ruta);
    }
}
