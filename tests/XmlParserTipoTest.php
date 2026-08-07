<?php
require_once __DIR__ . '/../app/helpers/XmlParser.php';

$tmp = tempnam(sys_get_temp_dir(), 'ncxml_');
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<NotaCreditoElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.3/notaCreditoElectronica">'
    . '<Clave>50625072600310163968000100001030000000001100000001</Clave>'
    . '<NumeroConsecutivo>00100001030000000001</NumeroConsecutivo><FechaEmision>2026-07-25T10:00:00-06:00</FechaEmision>'
    . '<Emisor><Nombre>Proveedor NC S.A.</Nombre><Identificacion><Numero>3101000000</Numero></Identificacion></Emisor>'
    . '<Receptor><Nombre>Grupo BM</Nombre><Identificacion><Numero>3101639680</Numero></Identificacion></Receptor>'
    . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda><TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
    . '</NotaCreditoElectronica>';
file_put_contents($tmp, $xml);
try {
    $doc = XmlInvoiceParser::parseCfdiFromFile($tmp);
    if ($doc['tipo_documento'] !== 'NC' || $doc['moneda'] !== 'CRC' || $doc['receptor_id'] !== '3101639680'
        || $doc['numero_factura_asistente'] !== '00000001' || abs($doc['total'] - 113.0) > 0.001) {
        fwrite(STDERR, 'FAIL: XmlParser no identificó correctamente la NC.' . PHP_EOL); exit(1);
    }
    echo "OK: XmlParserTipo NC\n";
} finally { if (is_file($tmp)) { unlink($tmp); } }
