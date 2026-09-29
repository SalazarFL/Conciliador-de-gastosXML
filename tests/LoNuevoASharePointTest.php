<?php
/**
 * Todo lo nuevo va a SharePoint; lo viejo se busca donde está.
 *
 * Hasta el 27/09/2026 cada empresa elegía entre la carpeta compartida y
 * SharePoint. Ya no: SharePoint es obligatorio y la carpeta compartida solo
 * sigue existiendo para lo que se archivó ahí antes y no se migró. Lo que se
 * comprueba acá son las reglas que hacen que eso sea seguro:
 *
 *   - Lo NUEVO se archiva en SharePoint, de la empresa que sea, y también
 *     lo que todavía no se sabe de quién es. Nunca en disco.
 *   - Sin SharePoint en esta PC, archivar LANZA con un motivo que dice qué
 *     falta: nunca cae al disco en silencio, que sería archivar donde las
 *     demás PC no van a buscar.
 *   - Lo que YA ESTÁ se busca donde dice su fila —NULL es disco, que es todo
 *     lo de antes—, y una dirección ya hidratada dice sola a quién pertenece.
 *   - Un listado no se cae por nada de eso: la fila queda sin abrir, nada más.
 *   - Contra una base de pruebas, la biblioteca de verdad no se toca.
 *
 * No necesita base ni red: el SharePoint es el almacén de mentira.
 *
 * Uso: php tests/LoNuevoASharePointTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';

$fallas = [];

function comprobar($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

function lanza(callable $f)
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lo_nuevo_sharepoint_' . uniqid();
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . 'tmp');
$xml = tempnam(sys_get_temp_dir(), 'xml');
file_put_contents($xml, '<FacturaElectronica>nube</FacturaElectronica>');
$pdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($pdf, '%PDF-1.4 nube');
$doc = ['tipo_documento' => 'FE', 'fecha_emision' => '2026-07-25', 'numero_factura_asistente' => '4354'];

try {
    // ── Sin SharePoint en esta PC ────────────────────────────────────────
    AlmacenDocumentos::olvidar();

    $disco = AlmacenDocumentos::actual();
    comprobar($disco instanceof AlmacenEnDisco,
        'lo que no dice dónde está es de la carpeta compartida de antes');
    comprobar(!AlmacenDocumentos::hayPrincipal(), 'sin credenciales no hay dónde archivar');

    foreach ([5, 6, 0, null] as $sociedad) {
        $e = lanza(function () use ($sociedad) { AlmacenDocumentos::para($sociedad); });
        comprobar($e instanceof RuntimeException && strpos($e->getMessage(), 'local.graph.php') !== false,
            'archivar lo nuevo sin SharePoint lanza y dice qué falta (sociedad ' . var_export($sociedad, true) . ')');
    }
    $e = lanza(function () { AlmacenDocumentos::principal(); });
    comprobar($e instanceof RuntimeException, 'principal() tampoco devuelve el disco en su lugar');

    $sinNube = new DocumentoArchivo();
    comprobar($sinNube->almacen() === null,
        'el archivo se puede armar —hay pantallas que solo preguntan—, pero sin almacén');
    $e = lanza(function () use ($sinNube, $xml, $doc) { $sinNube->archivar($xml, null, $doc, 'Proveedor S.A.'); });
    comprobar($e instanceof RuntimeException && $e->getMessage() === AlmacenDocumentos::SIN_SHAREPOINT,
        'y archivar con él dice que falta SharePoint, no que falta una carpeta');
    $e = lanza(function () use ($sinNube) { $sinNube->paraSociedad(5, 'Empresa'); });
    comprobar($e instanceof RuntimeException, 'paraSociedad() tampoco cae al disco en silencio');
    comprobar(!is_dir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA'), 'y no se escribió nada en el disco');

    $e = lanza(function () { RutaDocumento::carpetaTrabajo('BANDEJA/xml'); });
    comprobar($e instanceof RuntimeException, 'la bandeja tampoco tiene a dónde ir: no hay carpeta de repuesto');

    $e = lanza(function () { AlmacenDocumentos::paraFila(['almacen' => 'sharepoint']); });
    comprobar($e instanceof RuntimeException, 'leer una fila de SharePoint sin credenciales lanza');
    $hidratada = RutaDocumento::hidratarFila(
        ['almacen' => 'sharepoint', 'ruta_xml' => 'SISTEMA/X/a.xml'], ['ruta_xml']);
    comprobar($hidratada['ruta_xml'] === 'SISTEMA/X/a.xml', 'pero un listado no se cae: la ruta queda como está');

    // ── Con SharePoint en esta PC ────────────────────────────────────────
    $nube = new AlmacenDePrueba();
    $nube->prefijo = 'sharepoint:';
    AlmacenDocumentos::registrar('sharepoint', function () use ($nube) { return $nube; });
    comprobar(AlmacenDocumentos::disponible('SharePoint'), 'el tipo no distingue mayúsculas');
    comprobar(AlmacenDocumentos::hayPrincipal(), 'con credenciales, hay dónde archivar');

    comprobar(AlmacenDocumentos::principal() === $nube, 'lo nuevo va a SharePoint');
    comprobar(AlmacenDocumentos::para(5) === $nube && AlmacenDocumentos::para(6) === $nube,
        'de cualquier empresa: ya no hay empresas en disco');
    comprobar(AlmacenDocumentos::para(0) === $nube && AlmacenDocumentos::para(null) === $nube,
        'y también lo que no se sabe de quién es');

    // Lo que ya está, donde dice su fila.
    comprobar(AlmacenDocumentos::paraFila(['almacen' => null, 'sociedad_id' => 5]) === $disco,
        'NULL es disco: es lo archivado antes, y se lee de donde quedó');
    comprobar(AlmacenDocumentos::paraFila(['almacen' => 'sharepoint', 'sociedad_id' => 6]) === $nube,
        'una fila en SharePoint se busca ahí');
    comprobar(AlmacenDocumentos::paraFila(['sociedad_id' => 5]) === $disco,
        'sin columna, disco: una ruta sin prefijo es de disco, y lo de SharePoint se guarda con el suyo');
    // El 19/09/2026: una empresa con 14.043 documentos en disco se pasó a
    // SharePoint desde Configuración, y toda consulta que no pedía la
    // columna `almacen` leía sus rutas como de SharePoint. Que todo lo nuevo
    // vaya a SharePoint no puede volver a hacer lo mismo.
    $historico = RutaDocumento::hidratarFila(
        ['sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/A/2026/06 JUNIO/Facturas/FE_1.xml'], ['ruta_xml']);
    comprobar($historico['ruta_xml'] === RutaDocumento::absoluta('SISTEMA/A/2026/06 JUNIO/Facturas/FE_1.xml'),
        'lo archivado en disco se sigue leyendo del disco aunque todo lo nuevo vaya a SharePoint: '
        . $historico['ruta_xml']);
    comprobar(AlmacenDocumentos::paraFila([]) === $disco, 'sin nada, disco');

    // La dirección dice a quién pertenece.
    comprobar(AlmacenDocumentos::de($raiz . DIRECTORY_SEPARATOR . 'a.xml') === $disco,
        'una dirección absoluta es de disco');
    comprobar(AlmacenDocumentos::de('sharepoint:SISTEMA/a.xml') === $nube, 'una con prefijo sharepoint: es de SharePoint');
    comprobar(AlmacenDocumentos::de('SISTEMA/a.xml') === $disco,
        'y una relativa SIN prefijo sigue siendo de disco: una ruta que se le escapó a alguien no sale a la red');
    comprobar(AlmacenDocumentos::de('C:\\raiz\\a.xml') === $disco, 'una unidad de Windows no es un almacén');

    // La hidratación: disco antepone la raíz; SharePoint deja la ruta.
    $filas = RutaDocumento::hidratarFilas([
        ['almacen' => null, 'sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/A/a.xml', 'ruta_pdf' => null],
        ['almacen' => 'sharepoint', 'sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/B/b.xml', 'ruta_pdf' => ''],
    ], ['ruta_xml', 'ruta_pdf']);
    comprobar($filas[0]['ruta_xml'] === RutaDocumento::absoluta('SISTEMA/A/a.xml'),
        'la fila de disco sale con la raíz de esta máquina');
    comprobar($filas[1]['ruta_xml'] === 'sharepoint:SISTEMA/B/b.xml',
        'la de SharePoint sale con su prefijo y la ruta de la biblioteca');
    comprobar(RutaDocumento::relativa($filas[1]['ruta_xml']) === 'sharepoint:SISTEMA/B/b.xml',
        'y al volver a la base se guarda CON el prefijo: la cadena dice sola dónde está');
    comprobar(RutaDocumento::relativa('SharePoint:SISTEMA\\B\\b.xml') === 'sharepoint:SISTEMA/B/b.xml',
        'normalizado: prefijo en minúsculas y barras de Windows convertidas');
    comprobar($filas[0]['ruta_pdf'] === null && $filas[1]['ruta_pdf'] === '',
        'una ruta vacía o NULL se queda como está');

    // ── Archivar ─────────────────────────────────────────────────────────
    $archivo = new DocumentoArchivo();
    comprobar($archivo->almacen() === $nube && $archivo->raiz() === 'sharepoint:',
        'sin carpeta pedida a propósito, el archivo es la biblioteca');

    foreach ([5 => 'Empresa Nube', 6 => 'Empresa Tierra'] as $sociedad => $nombre) {
        $r = $archivo->paraSociedad($sociedad, $nombre);
        comprobar($r->almacen() === $nube, "la {$sociedad} archiva en SharePoint");
        $hecho = $r->archivar($xml, $pdf, $doc, 'Proveedor Prueba S.A.');
        $clave = substr(str_replace('\\', '/', $hecho['ruta_xml']), strlen('sharepoint:'));
        comprobar(strpos($hecho['ruta_xml'], 'sharepoint:SISTEMA') === 0
            && strpos($clave, 'SISTEMA/' . strtoupper($nombre) . '/2026/07 JULIO/Facturas/') === 0,
            'con el mismo árbol de siempre, sin separador al principio: ' . $hecho['ruta_xml']);
        comprobar(isset($nube->documentos[$clave]) && strpos((string) $hecho['ruta_pdf'], 'sharepoint:') === 0,
            'el XML y el PDF quedan en SharePoint');
        DocumentoArchivo::eliminarCreados($hecho);
        comprobar(!isset($nube->documentos[$clave]), 'deshacer el archivado borra en SharePoint, donde se escribió');
    }

    // Lo que no se sabe de quién es: el importador lo archiva sin sociedad.
    $sinEmpresa = $archivo->paraEmpresa(DocumentoArchivo::EMPRESA_SIN_DEFINIR)
        ->archivar($xml, null, $doc, 'Proveedor Prueba S.A.');
    comprobar(strpos($sinEmpresa['ruta_xml'], 'sharepoint:SISTEMA') === 0
        && strpos(str_replace('\\', '/', $sinEmpresa['ruta_xml']), '/SIN EMPRESA/') !== false,
        'lo que no se sabe de quién es también va a SharePoint, a SIN EMPRESA: ' . $sinEmpresa['ruta_xml']);
    comprobar(!is_dir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA'), 'y en la carpeta compartida no quedó nada');

    // La zona de trabajo: de todas las PC, y en SharePoint aunque no haya empresa.
    comprobar(RutaDocumento::carpetaTrabajo('BANDEJA/xml/2026-09') === 'sharepoint:_TRABAJO/BANDEJA/xml/2026-09',
        'la bandeja sin empresa está en SharePoint: ' . RutaDocumento::carpetaTrabajo('BANDEJA/xml/2026-09'));
    comprobar(RutaDocumento::carpetaTrabajo('DEVOLUCIONES/2026', 6) === 'sharepoint:_TRABAJO/DEVOLUCIONES/2026',
        'y con empresa, igual');
    comprobar(!is_dir($raiz . DIRECTORY_SEPARATOR . RutaDocumento::TRABAJO),
        'sin crear nada en la carpeta compartida');

    // Un PDF que llega tarde para un XML que quedó en la carpeta de antes no
    // se escribe allá: va a SharePoint, con el nombre del XML.
    $viejo = $raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'FE_VIEJO_250726_00004354.xml';
    mkdir(dirname($viejo), 0777, true);
    file_put_contents($viejo, '<FacturaElectronica>vieja</FacturaElectronica>');
    $pdfTarde = $archivo->paraSociedad(5, 'Empresa Nube')->archivarPdfPara(
        $doc + ['ruta_xml' => $viejo, 'proveedor_nombre' => 'Proveedor Prueba S.A.'], $pdf);
    comprobar(strpos($pdfTarde['ruta_pdf'], 'sharepoint:SISTEMA') === 0
        && basename(str_replace('\\', '/', $pdfTarde['ruta_pdf'])) === 'FE_VIEJO_250726_00004354.pdf',
        'el PDF de un XML viejo va a SharePoint con el nombre del XML: ' . $pdfTarde['ruta_pdf']);
    comprobar(!is_file(dirname($viejo) . DIRECTORY_SEPARATOR . 'FE_VIEJO_250726_00004354.pdf'),
        'y no a la carpeta compartida');
    @unlink($viejo);
    @rmdir(dirname($viejo));

    // Una carpeta de disco pedida a propósito sigue siendo disco: es lo que
    // usan las pruebas de la mecánica del archivo y el organizador de lo viejo.
    $aProposito = new DocumentoArchivo($raiz);
    comprobar($aProposito->almacen() === $disco && $aProposito->raiz() === $raiz,
        'una carpeta de disco nombrada a propósito se respeta');
    comprobar($aProposito->paraSociedad(5, 'Empresa')->almacen() === $nube,
        'pero lo que se archiva para una empresa va igual a SharePoint');

    // ── usando(): acotado y siempre se devuelve ─────────────────────────
    $otro = new AlmacenDePrueba();
    $e = lanza(function () use ($otro) {
        AlmacenDocumentos::usando($otro, function () use ($otro) {
            comprobar(AlmacenDocumentos::actual() === $otro && AlmacenDocumentos::de('C:\\x') === $otro
                && AlmacenDocumentos::principal() === $otro,
                'dentro de usando() manda el elegido');
            throw new RuntimeException('a mitad');
        });
    });
    comprobar($e !== null && AlmacenDocumentos::actual() === $disco && AlmacenDocumentos::principal() === $nube,
        'y al salir, aunque sea por una excepción, vuelve el de antes');

    // ── soltarEnTodos(): una sola vez aunque el forzado sea uno de los construidos
    $nube->documentos['SISTEMA/c.xml'] = 'c';
    $a = $nube->rutaLocal('SISTEMA/c.xml');
    $b = $nube->rutaLocal('SISTEMA/c.xml');
    AlmacenDocumentos::usando($nube, function () use ($a) {
        RutaDocumento::soltarLocal($a);
    });
    comprobar(is_file($b), 'soltar una vez no se lleva el temporal que otro todavía usa');
    RutaDocumento::soltarLocal($b);
    comprobar(!is_file($b), 'soltarlo la segunda vez sí lo borra');

    // ── Contra una base de pruebas no se toca la biblioteca de verdad ───
    // Con una base cuyo nombre dice test o cert, ni siquiera una PC con
    // local.graph.php da de alta SharePoint: las pruebas que archivan sin
    // elegir almacén fallan en vez de dejar huérfanos en la biblioteca.
    $config = $raiz . DIRECTORY_SEPARATOR . 'local.cert.php';
    file_put_contents($config, "<?php return ['database' => ['host' => '127.0.0.1', 'database' => 'algo_cert',"
        . " 'username' => 'nadie', 'password' => '']];\n");
    $antesConfig = getenv('XMLCONCILIA_CONFIG_FILE');
    $antesReal = getenv('XMLCONCILIA_SHAREPOINT_REAL');
    putenv('XMLCONCILIA_CONFIG_FILE=' . $config);
    putenv('XMLCONCILIA_SHAREPOINT_REAL');
    AlmacenDocumentos::olvidar();
    $porDescubrir = new ReflectionProperty(AlmacenDocumentos::class, 'porDescubrir');
    $porDescubrir->setValue(null, true);
    comprobar(!AlmacenDocumentos::disponible('sharepoint') && !AlmacenDocumentos::hayPrincipal(),
        'con una base de certificación, SharePoint no se da de alta aunque la PC tenga credenciales');
    putenv($antesConfig === false ? 'XMLCONCILIA_CONFIG_FILE' : 'XMLCONCILIA_CONFIG_FILE=' . $antesConfig);
    putenv($antesReal === false ? 'XMLCONCILIA_SHAREPOINT_REAL' : 'XMLCONCILIA_SHAREPOINT_REAL=' . $antesReal);
} finally {
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    RutaDocumento::olvidarRaiz();
    @unlink($xml);
    @unlink($pdf);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($raiz);
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: lo nuevo va a SharePoint, lo viejo se busca donde está, y sin SharePoint no se archiva en ningún lado\n";
