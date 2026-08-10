<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/XmlDocumentImporter.php';

function assertImporterSemana($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class FacturaImporterSemanaFalsa
{
    public $existente;
    public $semanaAsignada = null;
    public $commits = 0;
    public $rollbacks = 0;

    public function findByHashRecord($hash) { return $this->existente; }
    public function findByConsecutivoRecord($consecutivo, $proveedor, $fecha) { return $this->existente; }
    public function actualizarArchivos($id, array $data) { return 1; }
    public function asignarSemana($id, $semana) { $this->semanaAsignada = (int) $semana; return 1; }
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

class ProveedorImporterSemanaFalso
{
    public function obtenerOCrear($cedula, $nombre) { return 10; }
}

class ArchivoImporterSemanaFalso
{
    private $raiz;
    public function __construct($raiz) { $this->raiz = $raiz; }
    public function raiz() { return $this->raiz; }
}

function importerSemanaCon(FacturaImporterSemanaFalsa $facturas, $raiz)
{
    $ref = new ReflectionClass(XmlDocumentImporter::class);
    $importer = $ref->newInstanceWithoutConstructor();
    foreach ([
        'facturas' => $facturas,
        'proveedores' => new ProveedorImporterSemanaFalso(),
        'archivo' => new ArchivoImporterSemanaFalso($raiz),
    ] as $propiedad => $valor) {
        $prop = $ref->getProperty($propiedad);
        $prop->setAccessible(true);
        $prop->setValue($importer, $valor);
    }
    return $importer;
}

$dir = sys_get_temp_dir();
$xmlRuta = tempnam($dir, 'semana_xml_');
$pdfRuta = tempnam($dir, 'semana_pdf_');
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
    $misma = new FacturaImporterSemanaFalsa();
    $misma->existente = $baseExistente + ['semana_id' => 12];
    $resultadoMisma = importerSemanaCon($misma, $dir)->importar($xmlRuta, $pdfRuta, [
        'semana_id' => 12,
        'tipos_permitidos' => ['FE'],
    ]);
    assertImporterSemana(($resultadoMisma['estado'] ?? '') === 'duplicado_semana', 'detecta duplicado en la misma semana');
    assertImporterSemana($misma->semanaAsignada === null, 'no reasigna si ya está en la semana');

    $otra = new FacturaImporterSemanaFalsa();
    $otra->existente = $baseExistente + ['semana_id' => 11];
    $resultadoOtra = importerSemanaCon($otra, $dir)->importar($xmlRuta, $pdfRuta, [
        'semana_id' => 12,
        'tipos_permitidos' => ['FE'],
    ]);
    assertImporterSemana(($resultadoOtra['estado'] ?? '') === 'movida_semana', 'permite procesar si estaba en otra semana');
    assertImporterSemana($otra->semanaAsignada === 12, 'mueve la factura a la semana seleccionada');
    assertImporterSemana(($resultadoOtra['semana_anterior'] ?? 0) === 11, 'informa la semana anterior para revalidarla');
    assertImporterSemana($otra->commits === 1 && $otra->rollbacks === 0, 'confirma el cambio de semana en transacción');

    // Importar NO reacomoda el árbol. Mover lo ya archivado es una orden
    // explícita de la persona; que ocurriera sola hacía que una carpeta
    // ordenada a mano se reacomodara sin que nadie lo pidiera.
    assertImporterSemana(is_file($xmlRuta) && is_file($pdfRuta),
        'importar deja el par donde estaba: no lo mueve');
    assertImporterSemana($misma->consultasOrganizador === 0 && $otra->consultasOrganizador === 0,
        'importar ni siquiera consulta al organizador');

    echo "OK: XmlDocumentImporter valida duplicados por semana y no mueve archivos\n";
} finally {
    foreach ([$xmlRuta, $pdfRuta] as $ruta) {
        if (is_file($ruta)) { unlink($ruta); }
    }
}
