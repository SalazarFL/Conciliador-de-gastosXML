<?php
/**
 * Lo que el almacén de SharePoint tiene que hacer además del contrato.
 *
 * El contrato (AlmacenContratoTest) comprueba que se comporta como el disco.
 * Esto comprueba lo que lo hace usable a un segundo por llamada:
 *
 *   - Los listados no salen a la red: cien filas, cero llamadas.
 *   - Archivar un documento cuesta lo mínimo: subir y declarar el hash.
 *   - El id sobrevive a mover, que es por lo que se eligió Graph.
 *   - El hash se declara en la columna; sin la columna, todo sigue andando.
 *   - Una ruta de disco que llega acá es un error de quien llama, y se dice.
 *   - Archivar de punta a punta por DocumentoArchivo, sufijo _DUP_ incluido.
 *
 * Uso: php tests/AlmacenEnGraphTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../app/helpers/EstadoArchivo.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function comprobarEnGraph($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

function almacenEnMemoria(GraphEnMemoria $biblioteca)
{
    return new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca,
        ['carpeta_estado' => null, 'registro' => null]
    ));
}

function local($contenido)
{
    $ruta = tempnam(sys_get_temp_dir(), 'engraph');
    file_put_contents($ruta, $contenido);
    return $ruta;
}

$temporales = [];
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'engraph_' . uniqid();
mkdir($raiz, 0777, true);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . 'tmp');

try {
    // ── Archivar cuesta lo mínimo ───────────────────────────────────────
    $biblioteca = new GraphEnMemoria();
    $a = almacenEnMemoria($biblioteca);
    $temporales[] = $xml = local('<FE>1</FE>');
    $r = $a->escribir('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_1.xml', $xml);
    comprobarEnGraph($biblioteca->llamadas === 3,
        'el primer documento: token, subir y declarar el hash (' . $biblioteca->llamadas . ' llamadas)');
    comprobarEnGraph($r['ruta'] === 'sharepoint:SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_1.xml',
        'devuelve la dirección con su prefijo');
    comprobarEnGraph($r['item_id'] !== '' && $r['etag'] !== '', 'y el id y la etiqueta de SharePoint');
    comprobarEnGraph($biblioteca->hashDeclarado('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_1.xml')
        === hash('sha256', '<FE>1</FE>'), 'el SHA-256 queda declarado en la columna');

    $antes = $biblioteca->llamadas;
    $temporales[] = $xml2 = local('<FE>2</FE>');
    $a->escribir('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml', $xml2);
    comprobarEnGraph($biblioteca->llamadas - $antes === 2, 'los siguientes: dos llamadas, sin preguntar por carpetas');
    $antes = $biblioteca->llamadas;
    $a->existe('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml');
    comprobarEnGraph($biblioteca->llamadas === $antes, 'lo recién escrito se sabe que está sin volver a preguntar');

    // ── Los listados no salen a la red ──────────────────────────────────
    $filas = [];
    for ($i = 0; $i < 100; $i++) {
        $filas[] = ['ruta_xml' => 'sharepoint:SISTEMA/X/FE_' . $i . '.xml', 'ruta_pdf' => 'sharepoint:SISTEMA/X/FE_' . $i . '.pdf',
            'archivo_faltante_en' => $i === 7 ? '2026-09-18 10:00:00' : null];
    }
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::registrar('sharepoint', function () use ($a) { return $a; });
    $antes = $biblioteca->llamadas;
    $estados = EstadoArchivo::decorar($filas);
    comprobarEnGraph($biblioteca->llamadas === $antes, 'cien filas de listado, cero llamadas a SharePoint');
    comprobarEnGraph($estados[0]['archivo_xml_ok'] && $estados[0]['archivo_entregable'],
        'una fila sin marca está completa');
    comprobarEnGraph($estados[7]['archivo_perdido'] && $estados[7]['archivo_que_falta'] === 'XML y PDF',
        'la que el reconciliador marcó como faltante sale como perdida');

    // ── El id sobrevive a mover ─────────────────────────────────────────
    $origen = 'SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_1.xml';
    $id = $a->identidad($origen)['item_id'];
    $a->mover($origen, 'SISTEMA/EMPRESA/2026/10 OCTUBRE/Facturas/FE_1.xml');
    comprobarEnGraph($a->identidad('SISTEMA/EMPRESA/2026/10 OCTUBRE/Facturas/FE_1.xml')['item_id'] === $id,
        'mover conserva el id: es lo que hace que en SharePoint mover no rompa nada');
    comprobarEnGraph($a->identidad($origen) === null, 'y el origen queda libre');

    // ── Conflicto e idempotencia ────────────────────────────────────────
    $temporales[] = $distinto = local('<FE>otro</FE>');
    $e = null;
    try {
        $a->escribir('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml', $distinto);
    } catch (AlmacenConflicto $x) {
        $e = $x;
    }
    comprobarEnGraph($e !== null && $e->hashExistente() === hash('sha256', '<FE>2</FE>'),
        'con la columna, el conflicto dice el hash de lo que estaba sin bajarlo');

    // Sin la columna: lo mismo se reconoce bajándolo, y el hash se completa.
    $vieja = new GraphEnMemoria();
    $vieja->conColumnaHash = false;
    $b = almacenEnMemoria($vieja);
    $b->escribir('SISTEMA/A.xml', $xml);
    comprobarEnGraph($vieja->hashDeclarado('SISTEMA/A.xml') === '', 'sin la columna no se declara nada, y no falla');
    $b2 = almacenEnMemoria($vieja);   // otro proceso: no recuerda nada
    $r = $b2->escribir('SISTEMA/A.xml', $xml);
    $bajadas = count(array_filter($vieja->registro, function ($l) { return strpos($l, 'GET') === 0 && substr($l, -8) === '/content'; }));
    comprobarEnGraph($r['creado'] === false && $bajadas === 1,
        'archivar otra vez lo mismo se reconoce bajándolo una vez y comparando');

    // ── Lo que no es de SharePoint ──────────────────────────────────────
    $e = null;
    try {
        $a->escribir('C:\\carpeta\\FE.xml', $xml);
    } catch (InvalidArgumentException $x) {
        $e = $x;
    }
    comprobarEnGraph($e !== null, 'una ruta de disco que llega a SharePoint es un error, no un archivo raro');
    comprobarEnGraph($a->direccion('SISTEMA\\X\\a.xml') === 'sharepoint:SISTEMA/X/a.xml',
        'la dirección normaliza las barras de Windows');

    // ── Carpetas y listados de carpeta ──────────────────────────────────
    $a->asegurarCarpeta('PAGOS/Pago 12');
    $antes = $biblioteca->llamadas;
    $a->asegurarCarpeta('PAGOS/Pago 12');
    comprobarEnGraph($biblioteca->llamadas === $antes, 'una carpeta ya asegurada no se vuelve a preguntar');
    $a->asegurarCarpeta('PAGOS/Pago 13');
    $nombres = array_map(function ($i) { return $i['nombre'] . ($i['es_carpeta'] ? '/' : ''); },
        $a->listarCarpeta('PAGOS'));
    sort($nombres);
    comprobarEnGraph($nombres === ['Pago 12/', 'Pago 13/'], 'listarCarpeta ve las carpetas: ' . json_encode($nombres));
    comprobarEnGraph($a->listarCarpeta('NO/EXISTE') === [], 'una carpeta que no está se lista vacía');

    // ── Un archivo local para lo que lo necesita ────────────────────────
    $local = $a->rutaLocal('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml');
    comprobarEnGraph($local !== null && file_get_contents($local) === '<FE>2</FE>', 'rutaLocal() lo baja a un temporal');
    $a->soltarLocal($local);
    comprobarEnGraph(!is_file($local), 'y soltarlo lo borra');

    // ── Borrar por id ───────────────────────────────────────────────────
    $idBorrar = $a->identidad('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml')['item_id'];
    comprobarEnGraph($a->borrar('', $idBorrar) === true, 'se puede borrar por id, que es lo que usa deshacer');
    comprobarEnGraph($a->existe('SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_2.xml') === false,
        'y lo que se sabía de esa ruta se olvida');

    // ── De punta a punta: DocumentoArchivo archivando en SharePoint ────
    $nube = new GraphEnMemoria();
    $almacen = almacenEnMemoria($nube);
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::registrar('sharepoint', function () use ($almacen) { return $almacen; });

    $archivo = (new DocumentoArchivo($raiz))->paraSociedad(9, 'Empresa Nube');
    $doc = ['tipo_documento' => 'FE', 'fecha_emision' => '2026-07-25', 'numero_factura_asistente' => '4354'];
    $temporales[] = $pdf = local('%PDF-1.4 uno');
    $primero = $archivo->archivar($xml, $pdf, $doc, 'Proveedor Prueba S.A.');
    comprobarEnGraph(strpos($primero['ruta_xml'], 'sharepoint:SISTEMA/EMPRESA NUBE/2026/07 JULIO/Facturas/') === 0
        && $nube->contenido(substr($primero['ruta_xml'], 11)) === '<FE>1</FE>',
        'DocumentoArchivo archiva en SharePoint: ' . $primero['ruta_xml']);
    comprobarEnGraph(RutaDocumento::relativa($primero['ruta_pdf']) === $primero['ruta_pdf'],
        'y lo que va a la base conserva el prefijo: la cadena dice sola dónde está');

    $otraVez = $archivo->archivar($xml, $pdf, $doc, 'Proveedor Prueba S.A.');
    comprobarEnGraph($otraVez['ruta_xml'] === $primero['ruta_xml'] && !$otraVez['xml_creado'],
        'archivar lo mismo otra vez no duplica');

    $temporales[] = $xmlOtro = local('<FE>mismo numero, otro contenido</FE>');
    $dup = $archivo->archivar($xmlOtro, null, $doc, 'Proveedor Prueba S.A.');
    comprobarEnGraph(strpos($dup['ruta_xml'], '_DUP_') !== false && $dup['xml_creado'],
        'otro contenido con el mismo nombre queda con _DUP_, igual que en disco: ' . $dup['ruta_xml']);

    DocumentoArchivo::eliminarCreados($dup);
    comprobarEnGraph($nube->contenido(substr($dup['ruta_xml'], 11)) === null,
        'deshacer borra en SharePoint lo que se acaba de crear');
    comprobarEnGraph(!is_dir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA'), 'y en el disco no quedó nada');
} finally {
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    foreach ($temporales as $t) {
        @unlink($t);
    }
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

echo "OK: SharePoint archiva, mueve y lista sin gastar llamadas de más\n";
