<?php
/**
 * Que cada fila diga dónde quedó su documento.
 *
 * Es la otra mitad de "manda la fila": si una empresa ya archiva en
 * SharePoint y la fila se guarda sin `almacen`, se lee como de disco y el
 * documento queda perdido para el sistema aunque esté en la biblioteca. Por
 * eso la anotación vive en el modelo que guarda las rutas y no en cada camino
 * que archiva.
 *
 * Y la regla que la hace posible: el PDF va siempre al almacén de su XML,
 * aunque la empresa se haya pasado a otro desde que llegó el XML. Una fila
 * dice un solo almacén; un par partido en dos tendría la mitad perdida.
 *
 * Uso: php tests/AlmacenEnLaFilaTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function comprobarFila($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

class FacturaQueAnota extends Factura
{
    public $consultas = [];
    public $conColumna = true;
    public $falla = false;

    public function __construct() {}

    protected function insert($sql, $params = [])
    {
        $this->consultas[] = ['INSERT', []];
        return 41;
    }

    protected function execute($sql, $params = [])
    {
        if ($this->falla && strpos($sql, 'almacen') !== false) {
            throw new PDOException("Unknown column 'almacen'");
        }
        $this->consultas[] = [preg_replace('/\s+/', ' ', trim($sql)), $params];
        return 1;
    }

    protected function fetchColumn($sql, $params = [])
    {
        $this->consultas[] = ['COLUMNA?', []];
        return $this->conColumna ? 1 : false;
    }

    public static function olvidarColumna()
    {
        (function () { self::$columnaAlmacen = null; })->bindTo(null, Factura::class)();
    }
}

function anotaciones(FacturaQueAnota $f)
{
    return array_values(array_filter($f->consultas, function ($c) {
        return strpos($c[0], 'SET almacen') !== false || strpos($c[0], 'item_') !== false;
    }));
}

$base = ['sociedad_id' => 3, 'consecutivo_completo' => '1', 'numero_factura_asistente' => '1', 'proveedor_id' => 1,
         'fecha_emision' => '2026-07-25', 'total' => 1];

// ── Filas nuevas ────────────────────────────────────────────────────────
FacturaQueAnota::olvidarColumna();
$f = new FacturaQueAnota();
$f->crear($base + ['ruta_xml' => 'C:\\raiz\\SISTEMA\\a.xml']);
comprobarFila(count($f->consultas) === 1, 'una fila nueva en disco no cuesta ni una consulta de más: NULL ya es disco');

$f = new FacturaQueAnota();
$f->crear($base + ['ruta_xml' => 'sharepoint:SISTEMA/a.xml', 'item_xml_id' => 'X1', 'etag_xml' => 'c1',
                   'item_pdf_id' => 'P1', 'etag_pdf' => 'c2']);
$a = anotaciones($f);
comprobarFila(count($a) === 1 && strpos($a[0][0], 'almacen = ?') !== false
    && $a[0][1] === ['sharepoint', 'X1', 'c1', 'P1', 'c2', 41],
    'una fila nueva de SharePoint anota el almacén y los ids: ' . json_encode($a));

// ── Filas que se reparan ────────────────────────────────────────────────
$f = new FacturaQueAnota();
$f->actualizarArchivos(41, ['ruta_pdf' => 'sharepoint:SISTEMA/a.pdf', 'item_pdf_id' => 'P9', 'etag_pdf' => 'c9']);
$a = anotaciones($f);
comprobarFila(count($a) === 1 && strpos($a[0][0], 'almacen') === false && $a[0][1] === ['P9', 'c9', 41],
    'completar solo el PDF anota su id y no toca el almacén de la fila');

FacturaQueAnota::olvidarColumna();
$f = new FacturaQueAnota();
$f->actualizarArchivos(41, ['ruta_xml' => 'C:\\raiz\\SISTEMA\\a.xml']);
$a = anotaciones($f);
comprobarFila(count($a) === 1 && $a[0][1] === [null, 41] && strpos($a[0][0], 'item_xml_id = NULL') !== false,
    'un XML repuesto en disco vuelve la fila a disco y olvida su id de SharePoint');

FacturaQueAnota::olvidarColumna();
$f = new FacturaQueAnota();
$f->conColumna = false;
$f->actualizarArchivos(41, ['ruta_xml' => 'C:\\raiz\\SISTEMA\\a.xml']);
comprobarFila(anotaciones($f) === [],
    'sin la migración corrida, reponer en disco no intenta anotar nada (ni deja errores en el registro)');

$f = new FacturaQueAnota();
$f->falla = true;
$e = null;
try {
    $f->crear($base + ['ruta_xml' => 'sharepoint:SISTEMA/a.xml', 'item_xml_id' => 'X1']);
} catch (RuntimeException $x) {
    $e = $x;
}
comprobarFila($e !== null && strpos($e->getMessage(), 'migration_almacen_graph') !== false,
    'un documento de SharePoint que no se puede anotar para la importación: sería uno perdido');

// ── El PDF va al almacén de su XML ──────────────────────────────────────
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'enlafila_' . uniqid();
mkdir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA', 0777, true);
$xmlEnDisco = $raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'FE_1.xml';
file_put_contents($xmlEnDisco, '<FE/>');
$pdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($pdf, '%PDF');

try {
    $nube = new GraphEnMemoria();
    $graph = new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $nube, ['carpeta_estado' => null, 'registro' => null]));
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::registrar('sharepoint', function () use ($graph) { return $graph; });
    AlmacenDocumentos::fijarFuenteAjustes(function () { return [3 => 'sharepoint']; });
    RutaDocumento::fijarRaiz($raiz);

    // La empresa ya se pasó a SharePoint, pero el XML llegó antes: está en disco.
    $archivo = (new DocumentoArchivo($raiz))->paraSociedad(3, 'Empresa');
    $r = $archivo->archivarPdfPara(['ruta_xml' => $xmlEnDisco], $pdf);
    comprobarFila(is_file($raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'FE_1.pdf')
        && $r['item_pdf_id'] === '' && $nube->llamadas === 0,
        'el PDF se archiva al lado de su XML, en disco, aunque la empresa ya esté en SharePoint');

    // Y al revés: un XML de SharePoint lleva su PDF a SharePoint.
    $local = tempnam(sys_get_temp_dir(), 'xml');
    file_put_contents($local, '<FE>nube</FE>');
    $graph->escribir('SISTEMA/EMPRESA/FE_2.xml', $local);
    @unlink($local);
    $r = $archivo->archivarPdfPara(['ruta_xml' => 'sharepoint:SISTEMA/EMPRESA/FE_2.xml'], $pdf);
    comprobarFila($r['ruta_pdf'] === 'sharepoint:SISTEMA/EMPRESA/FE_2.pdf' && $r['item_pdf_id'] !== ''
        && $nube->contenido('SISTEMA/EMPRESA/FE_2.pdf') === '%PDF',
        'y el de un XML de SharePoint va a SharePoint, con su id: ' . $r['ruta_pdf']);
} finally {
    AlmacenDocumentos::olvidar();
    RutaDocumento::olvidarRaiz();
    @unlink($pdf);
    @unlink($xmlEnDisco);
    @unlink($raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'FE_1.pdf');
    @rmdir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA');
    @rmdir($raiz);
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: cada fila dice dónde quedó su documento\n";
