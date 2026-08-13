<?php
/**
 * Lo que el sistema NO guarda, y que ya se coló una vez.
 *
 * 1. Un mensaje de Hacienda no es un comprobante. Durante un tiempo se
 *    reconstruía una factura a partir del acuse cuando el proveedor mandaba
 *    solo eso y el PDF; quedaban 36 documentos cuyo "XML de la factura" no era
 *    la factura, sin líneas de detalle y sin nada en la base que los
 *    distinguiera. Se rechaza en el parser porque es el único punto por el que
 *    pasan todos los caminos de importación.
 *
 * 2. Las notas de débito no se guardan. Se rechazan por encima de
 *    'tipos_permitidos' para que ningún módulo pueda volver a habilitarlas
 *    pasando la lista equivocada.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/XmlParser.php';
require_once __DIR__ . '/../app/helpers/XmlDocumentImporter.php';

function assertDescarte($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class FacturaDescarteFalsa
{
    public $consultas = 0;
    public function findByHashRecord($hash) { $this->consultas++; return null; }
    public function findByConsecutivoRecord($c, $p, $f) { $this->consultas++; return null; }
    public function begin() { return true; }
    public function commit() { return true; }
    public function rollback() { return true; }
}

class ProveedorDescarteFalso
{
    public $consultas = 0;
    public function obtenerOCrear($cedula, $nombre) { $this->consultas++; return 10; }
}

class ArchivoDescarteFalso
{
    public $archivados = 0;
    public function raiz() { return sys_get_temp_dir(); }
    public function archivar($xml, $pdf, $doc, $proveedor)
    {
        $this->archivados++;
        return ['xml' => $xml, 'pdf' => $pdf];
    }
}

function importerDescarteCon($facturas, $proveedores, $archivo)
{
    $ref = new ReflectionClass(XmlDocumentImporter::class);
    $importer = $ref->newInstanceWithoutConstructor();
    foreach (['facturas' => $facturas, 'proveedores' => $proveedores, 'archivo' => $archivo] as $nombre => $valor) {
        $prop = $ref->getProperty($nombre);
        $prop->setAccessible(true);
        $prop->setValue($importer, $valor);
    }
    return $importer;
}

/** Clave de 50 dígitos: 506 + ddmmaa + cédula(12) + consecutivo(20) + situación(1) + código(8) */
function claveDescarte($consecutivo)
{
    return '506' . '070726' . '003101017062' . $consecutivo . '1' . '10322839';
}

$mh = tempnam(sys_get_temp_dir(), 'desc_mh_');
$nd = tempnam(sys_get_temp_dir(), 'desc_nd_');

// El acuse real de la factura 00322839 de DEMASA, recortado: es el que se
// guardó como factura el 25 de julio.
file_put_contents($mh, '<?xml version="1.0" encoding="UTF-8"?>'
    . '<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda">'
    . '<Clave>' . claveDescarte('00100001010000322839') . '</Clave>'
    . '<NombreEmisor>DERIVADOS DE MAIZ ALIMENTICIO, SA</NombreEmisor>'
    . '<NumeroCedulaEmisor>3101017062</NumeroCedulaEmisor>'
    . '<NumeroCedulaReceptor>3101639680</NumeroCedulaReceptor>'
    . '<Mensaje>1</Mensaje><EstadoMensaje>Aceptado</EstadoMensaje>'
    . '<MontoTotalImpuesto>565262.29</MontoTotalImpuesto>'
    . '<TotalFactura>5271106.10</TotalFactura></MensajeHacienda>');

// Nota de débito: comprobante válido, pero de los que no se guardan. El "02"
// en la posición 8 del consecutivo es lo que la marca como ND.
file_put_contents($nd, '<?xml version="1.0" encoding="UTF-8"?>'
    . '<NotaDebitoElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.3/notaDebitoElectronica">'
    . '<Clave>' . claveDescarte('00100001020000000055') . '</Clave>'
    . '<NumeroConsecutivo>00100001020000000055</NumeroConsecutivo>'
    . '<FechaEmision>2026-07-07T10:00:00-06:00</FechaEmision>'
    . '<Emisor><Nombre>PROVEEDOR ND</Nombre><Identificacion><Numero>3101017062</Numero></Identificacion></Emisor>'
    . '<Receptor><Nombre>GRUPO BM</Nombre><Identificacion><Numero>3101639680</Numero></Identificacion></Receptor>'
    . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
    . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
    . '</NotaDebitoElectronica>');

try {
    // ── 1. El mensaje de Hacienda no llega a ser un comprobante ────
    $errorMh = null;
    try {
        XmlInvoiceParser::parseCfdiFromFile($mh);
    } catch (Throwable $e) {
        $errorMh = $e->getMessage();
    }
    assertDescarte($errorMh !== null, 'el parser rechaza un MensajeHacienda en vez de reconstruir una factura');
    assertDescarte(stripos($errorMh, 'mensaje de Hacienda') !== false,
        'el error dice qué es el archivo, no solo que falló');

    // El importador ni siquiera consulta la base: falla antes.
    $facturas = new FacturaDescarteFalsa();
    $proveedores = new ProveedorDescarteFalso();
    $archivo = new ArchivoDescarteFalso();
    $lanzo = false;
    try {
        importerDescarteCon($facturas, $proveedores, $archivo)->importar($mh, null, [
            'tipos_permitidos' => ['FE', 'NC'],
        ]);
    } catch (Throwable $e) {
        $lanzo = true;
    }
    assertDescarte($lanzo, 'el importador rechaza el MensajeHacienda');
    assertDescarte($archivo->archivados === 0, 'no archiva el MensajeHacienda en la carpeta compartida');
    assertDescarte($facturas->consultas === 0 && $proveedores->consultas === 0,
        'rechaza antes de tocar la base: ni crea el proveedor ni busca duplicados');

    // ── 2. La nota de débito se descarta aunque se la permitan ─────
    $doc = XmlInvoiceParser::parseCfdiFromFile($nd);
    assertDescarte(($doc['tipo_documento'] ?? '') === 'ND',
        'el parser sigue sabiendo leer una ND: se descarta al guardar, no al leer');

    $facturasNd = new FacturaDescarteFalsa();
    $archivoNd = new ArchivoDescarteFalso();
    $errorNd = null;
    try {
        // A propósito con 'ND' en la lista: la regla tiene que ganarle.
        importerDescarteCon($facturasNd, new ProveedorDescarteFalso(), $archivoNd)->importar($nd, null, [
            'tipos_permitidos' => ['FE', 'NC', 'ND'],
        ]);
    } catch (Throwable $e) {
        $errorNd = $e->getMessage();
    }
    assertDescarte($errorNd !== null, 'descarta la ND aunque tipos_permitidos la incluya');
    assertDescarte(stripos($errorNd, 'débito') !== false, 'el error nombra la nota de débito');
    assertDescarte($archivoNd->archivados === 0, 'no archiva la ND');
    assertDescarte(in_array('ND', XmlDocumentImporter::NUNCA_SE_GUARDAN, true),
        'la lista de lo que nunca se guarda sigue incluyendo ND');

    echo "OK: se descartan los mensajes de Hacienda y las notas de débito\n";
} finally {
    foreach ([$mh, $nd] as $ruta) {
        if (is_file($ruta)) { unlink($ruta); }
    }
}
