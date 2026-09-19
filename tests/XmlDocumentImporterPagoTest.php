<?php
require_once __DIR__ . '/../app/core/Model.php';

/*
 * El listado de proveedores, de mentira: sin él el importador no deja pasar
 * nada, y acá lo que se prueba es el reparto del comprobante entre pagos.
 */
class ProveedorCodigoErp
{
    public static function hayListado() { return true; }
    public static function conoceCedula($cedula) { return true; }
    public static function normalizarCedula($c) { return preg_replace("/\D/", "", (string) $c); }
    public static function veredicto($codigo, $proveedorId) { return 'propio'; }
}

require_once __DIR__ . '/../app/helpers/XmlDocumentImporter.php';

function assertImporterPago($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class FacturaImporterPagoFalsa
{
    public $existente;
    public $pagoAsignado = null;
    public $commits = 0;
    public $rollbacks = 0;

    public function findByHashRecord($hash) { return $this->existente; }
    public function findByConsecutivoRecord($consecutivo, $proveedor, $fecha) { return $this->existente; }
    public function actualizarArchivos($id, array $data) { return 1; }
    public function asignarPago($id, $pago) { $this->pagoAsignado = (int) $pago; return 1; }
    public function begin() { return true; }
    public function commit() { $this->commits++; return true; }
    public function rollback() { $this->rollbacks++; return true; }

    // Si alguien vuelve a enchufar el organizador en la importación, este
    // contador deja de ser cero y la prueba lo delata.
    public $consultasOrganizador = 0;
    public function getParaOrganizarArchivos(array $ids = [])
    {
        $this->consultasOrganizador++;
        return [];
    }
}

class ProveedorImporterPagoFalso
{
    public function obtenerOCrear($cedula, $nombre) { return 10; }
}

class ArchivoImporterPagoFalso
{
    private $raiz;
    public function __construct($raiz) { $this->raiz = $raiz; }
    public function raiz() { return $this->raiz; }
}

function importerPagoCon(FacturaImporterPagoFalsa $facturas, $raiz)
{
    $ref = new ReflectionClass(XmlDocumentImporter::class);
    $importer = $ref->newInstanceWithoutConstructor();
    foreach ([
        'facturas' => $facturas,
        'proveedores' => new ProveedorImporterPagoFalso(),
        'archivo' => new ArchivoImporterPagoFalso($raiz),
    ] as $propiedad => $valor) {
        $prop = $ref->getProperty($propiedad);
        $prop->setAccessible(true);
        $prop->setValue($importer, $valor);
    }
    return $importer;
}

$dir = sys_get_temp_dir();
$xmlRuta = tempnam($dir, 'pago_xml_');
$pdfRuta = tempnam($dir, 'pago_pdf_');
$clave = '50607062600011321045500100001010000001910143537651';
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
file_put_contents($pdfRuta, 'pdf');

$baseExistente = [
    'id' => 25,
    'tipo_documento' => 'FE',
    'proveedor_id' => 10,
    'proveedor_nombre' => 'PROVEEDOR PRUEBA',
    'fecha_emision' => '2026-06-07',
    'ruta_xml' => $xmlRuta,
    'ruta_pdf' => $pdfRuta,
];

try {
    $misma = new FacturaImporterPagoFalsa();
    $misma->existente = $baseExistente + ['pago_id' => 12];
    $resultadoMisma = importerPagoCon($misma, $dir)->importar($xmlRuta, $pdfRuta, [
        'pago_id' => 12,
        'tipos_permitidos' => ['FE'],
    ]);
    assertImporterPago(($resultadoMisma['estado'] ?? '') === 'duplicado_pago', 'detecta duplicado en el mismo pago');
    assertImporterPago($misma->pagoAsignado === null, 'no reasigna si ya está en el pago');

    $otra = new FacturaImporterPagoFalsa();
    $otra->existente = $baseExistente + ['pago_id' => 11];
    $resultadoOtra = importerPagoCon($otra, $dir)->importar($xmlRuta, $pdfRuta, [
        'pago_id' => 12,
        'tipos_permitidos' => ['FE'],
    ]);
    assertImporterPago(($resultadoOtra['estado'] ?? '') === 'movido_pago', 'permite procesar si estaba en otro pago');
    assertImporterPago($otra->pagoAsignado === 12, 'mueve la factura al pago seleccionada');
    assertImporterPago(($resultadoOtra['pago_anterior'] ?? 0) === 11, 'informa el pago anterior para revalidarla');
    assertImporterPago($otra->commits === 1 && $otra->rollbacks === 0, 'confirma el cambio de pago en transacción');

    // Importar NO reacomoda el árbol. Mover lo ya archivado es una orden
    // explícita de la persona; que ocurriera sola hacía que una carpeta
    // ordenada a mano se reacomodara sin que nadie lo pidiera.
    assertImporterPago(is_file($xmlRuta) && is_file($pdfRuta),
        'importar deja el par donde estaba: no lo mueve');
    assertImporterPago($misma->consultasOrganizador === 0 && $otra->consultasOrganizador === 0,
        'importar ni siquiera consulta al organizador');

    echo "OK: XmlDocumentImporter valida duplicados por pago y no mueve archivos\n";
} finally {
    foreach ([$xmlRuta, $pdfRuta] as $ruta) {
        if (is_file($ruta)) { unlink($ruta); }
    }
}
