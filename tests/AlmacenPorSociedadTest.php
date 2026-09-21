<?php
/**
 * Dónde está cada documento, cuando no todos están en el mismo lugar.
 *
 * El paso a SharePoint es por sociedad y de a poco, así que durante semanas
 * va a haber documentos en los dos almacenes a la vez. Lo que se comprueba
 * acá son las reglas que hacen que eso sea seguro:
 *
 *   - Lo NUEVO se archiva donde dice el ajuste de la sociedad.
 *   - Lo que YA ESTÁ se busca donde dice su fila, aunque la sociedad ya se
 *     haya pasado —y NULL es disco, que es todo lo de antes—.
 *   - Una dirección ya hidratada dice sola a quién pertenece.
 *   - Si esta PC no puede usar el almacén de una sociedad, archivar LANZA:
 *     nunca cae al disco en silencio, que sería archivar donde las demás PC
 *     no van a buscar.
 *   - Un listado no se cae por eso: la fila queda sin abrir, nada más.
 *
 * No necesita base ni red: el ajuste de las sociedades se le pasa como lista
 * y el SharePoint es el almacén de mentira.
 *
 * Uso: php tests/AlmacenPorSociedadTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';

$fallas = [];

function comprobarSociedad($condicion, $mensaje)
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

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'almacen_sociedad_' . uniqid();
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . 'tmp');

$ajustes = [5 => 'sharepoint'];
$consultas = 0;
$fuente = function () use (&$ajustes, &$consultas) {
    $consultas++;
    return $ajustes;
};

try {
    // ── De fábrica: todo es disco, como siempre ─────────────────────────
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarFuenteAjustes($fuente);

    $disco = AlmacenDocumentos::actual();
    comprobarSociedad($disco instanceof AlmacenEnDisco, 'el almacén por omisión es el disco');
    comprobarSociedad(AlmacenDocumentos::tipo('disco') === $disco && AlmacenDocumentos::tipo('') === $disco,
        'una sola instancia por tipo, y un tipo vacío es disco');
    comprobarSociedad(AlmacenDocumentos::paraFila(['sociedad_id' => 5]) === $disco,
        'sin otro almacén en esta PC, una fila sin columna es de disco');
    comprobarSociedad($consultas === 0,
        'y contestarlo no le cuesta una consulta a la base a cada listado');
    comprobarSociedad(AlmacenDocumentos::de('SISTEMA/a.xml') === $disco,
        'sin otro almacén, una ruta relativa también es de disco (se resuelve contra la raíz)');

    // Esta PC no tiene SharePoint, y la sociedad 5 está en SharePoint.
    $e = lanza(function () { AlmacenDocumentos::para(5); });
    comprobarSociedad($e instanceof RuntimeException && strpos($e->getMessage(), 'local.graph.php') !== false,
        'archivar para una sociedad en SharePoint sin credenciales lanza y dice qué falta');
    comprobarSociedad(AlmacenDocumentos::para(6) === $disco, 'otra sociedad sigue en disco');

    $archivo = new DocumentoArchivo($raiz);
    $e = lanza(function () use ($archivo) { $archivo->paraSociedad(5, 'Empresa Nube'); });
    comprobarSociedad($e instanceof RuntimeException,
        'DocumentoArchivo tampoco cae al disco en silencio');

    $e = lanza(function () { AlmacenDocumentos::paraFila(['almacen' => 'sharepoint']); });
    comprobarSociedad($e instanceof RuntimeException, 'leer una fila de SharePoint sin credenciales lanza');
    $hidratada = RutaDocumento::hidratarFila(
        ['almacen' => 'sharepoint', 'ruta_xml' => 'SISTEMA/X/a.xml'], ['ruta_xml']);
    comprobarSociedad($hidratada['ruta_xml'] === 'SISTEMA/X/a.xml',
        'pero un listado no se cae: la ruta queda como está');

    // ── Con SharePoint en esta PC ────────────────────────────────────────
    $nube = new AlmacenDePrueba();
    $nube->prefijo = 'sharepoint:';
    AlmacenDocumentos::registrar('sharepoint', function () use ($nube) { return $nube; });
    comprobarSociedad(AlmacenDocumentos::disponible('SharePoint'), 'el tipo no distingue mayúsculas');

    comprobarSociedad(AlmacenDocumentos::para(5) === $nube, 'lo nuevo de la 5 va a SharePoint');
    comprobarSociedad(AlmacenDocumentos::para(6) === $disco, 'lo nuevo de la 6 sigue en disco');
    comprobarSociedad(AlmacenDocumentos::para(0) === $disco, 'sin sociedad, disco');

    // La fila manda sobre la sociedad, en los dos sentidos.
    comprobarSociedad(AlmacenDocumentos::paraFila(['almacen' => null, 'sociedad_id' => 5]) === $disco,
        'NULL es disco aunque la sociedad ya se haya pasado: es lo archivado antes');
    comprobarSociedad(AlmacenDocumentos::paraFila(['almacen' => 'sharepoint', 'sociedad_id' => 6]) === $nube,
        'una fila en SharePoint se busca ahí aunque su sociedad esté en disco');
    comprobarSociedad(AlmacenDocumentos::paraFila(['sociedad_id' => 5]) === $disco,
        'sin columna, disco aunque la sociedad esté en SharePoint: una ruta sin prefijo es de disco, '
        . 'y lo de SharePoint se guarda con el suyo');
    // El 19/09/2026: una empresa con 14.043 documentos en disco se pasó a
    // SharePoint desde Configuración, y toda consulta que no pedía la
    // columna `almacen` leía sus rutas como de SharePoint.
    $historico = RutaDocumento::hidratarFila(
        ['sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/A/2026/06 JUNIO/Facturas/FE_1.xml'], ['ruta_xml']);
    comprobarSociedad($historico['ruta_xml'] === RutaDocumento::absoluta('SISTEMA/A/2026/06 JUNIO/Facturas/FE_1.xml'),
        'lo archivado en disco antes de pasar la empresa se sigue leyendo del disco, aunque la consulta '
        . 'no traiga la columna: ' . $historico['ruta_xml']);
    comprobarSociedad(AlmacenDocumentos::paraFila([]) === $disco, 'sin nada, disco');

    // La dirección dice a quién pertenece.
    comprobarSociedad(AlmacenDocumentos::de($raiz . DIRECTORY_SEPARATOR . 'a.xml') === $disco,
        'una dirección absoluta es de disco');
    comprobarSociedad(AlmacenDocumentos::de('sharepoint:SISTEMA/a.xml') === $nube,
        'una con prefijo sharepoint: es de SharePoint');
    comprobarSociedad(AlmacenDocumentos::de('SISTEMA/a.xml') === $disco,
        'y una relativa SIN prefijo sigue siendo de disco, aunque esta PC tenga SharePoint: '
        . 'una ruta que se le escapó a alguien no puede salir a la red');
    comprobarSociedad(AlmacenDocumentos::de('C:\\raiz\\a.xml') === $disco, 'una unidad de Windows no es un almacén');

    // La hidratación: disco antepone la raíz; SharePoint deja la ruta.
    $filas = RutaDocumento::hidratarFilas([
        ['almacen' => null, 'sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/A/a.xml', 'ruta_pdf' => null],
        ['almacen' => 'sharepoint', 'sociedad_id' => 5, 'ruta_xml' => 'SISTEMA/B/b.xml', 'ruta_pdf' => ''],
    ], ['ruta_xml', 'ruta_pdf']);
    comprobarSociedad($filas[0]['ruta_xml'] === RutaDocumento::absoluta('SISTEMA/A/a.xml'),
        'la fila de disco sale con la raíz de esta máquina');
    comprobarSociedad($filas[1]['ruta_xml'] === 'sharepoint:SISTEMA/B/b.xml',
        'la de SharePoint sale con su prefijo y la ruta de la biblioteca');
    comprobarSociedad(RutaDocumento::relativa($filas[1]['ruta_xml']) === 'sharepoint:SISTEMA/B/b.xml',
        'y al volver a la base se guarda CON el prefijo: la cadena dice sola dónde está');
    comprobarSociedad(RutaDocumento::relativa('SharePoint:SISTEMA\\B\\b.xml') === 'sharepoint:SISTEMA/B/b.xml',
        'normalizado: prefijo en minúsculas y barras de Windows convertidas');
    $guardada = RutaDocumento::hidratarFila(
        ['almacen' => null, 'sociedad_id' => 6, 'archivo_xml' => 'sharepoint:_TRABAJO/BANDEJA/xml/a.xml'], ['archivo_xml']);
    comprobarSociedad($guardada['archivo_xml'] === 'sharepoint:_TRABAJO/BANDEJA/xml/a.xml',
        'una ruta guardada con prefijo manda sobre la columna y la sociedad: dice dónde están los bytes');
    comprobarSociedad(RutaDocumento::absoluta('sharepoint:SISTEMA/B/b.xml') === 'sharepoint:SISTEMA/B/b.xml',
        'a una dirección de SharePoint no se le pega la raíz de esta máquina');
    comprobarSociedad($filas[0]['ruta_pdf'] === null && $filas[1]['ruta_pdf'] === '',
        'una ruta vacía o NULL se queda como está');

    // ── Archivar: el documento va al almacén de su sociedad ─────────────
    $xml = tempnam(sys_get_temp_dir(), 'xml');
    file_put_contents($xml, '<FacturaElectronica>nube</FacturaElectronica>');
    $doc = ['tipo_documento' => 'FE', 'fecha_emision' => '2026-07-25', 'numero_factura_asistente' => '4354'];

    $enNube = $archivo->paraSociedad(5, 'Empresa Nube');
    comprobarSociedad($enNube->almacen() === $nube && $enNube->raiz() === 'sharepoint:',
        'para la 5, el archivo apunta a SharePoint y su raíz es la biblioteca');
    comprobarSociedad($archivo->almacen() === $disco, 'el original no cambia: paraSociedad devuelve una copia');
    $r = $enNube->archivar($xml, null, $doc, 'Proveedor Prueba S.A.');
    $clave = substr(str_replace('\\', '/', $r['ruta_xml']), strlen('sharepoint:'));
    comprobarSociedad(strpos($r['ruta_xml'], 'sharepoint:SISTEMA') === 0
        && strpos($clave, 'SISTEMA/EMPRESA NUBE/2026/07 JULIO/Facturas/') === 0,
        'se archiva en SharePoint con el mismo árbol de siempre, sin separador al principio: ' . $r['ruta_xml']);
    comprobarSociedad(isset($nube->documentos[$clave]), 'y el contenido está en SharePoint');
    comprobarSociedad(!is_dir($raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'EMPRESA NUBE'),
        'y no dejó nada en el disco');

    DocumentoArchivo::eliminarCreados($r);
    comprobarSociedad(!isset($nube->documentos[$clave]),
        'deshacer el archivado borra en SharePoint, que es donde se escribió');

    $enDisco = $archivo->paraSociedad(6, 'Empresa Tierra');
    comprobarSociedad($enDisco->almacen() === $disco && $enDisco->raiz() === $archivo->raiz(),
        'para la 6, todo sigue como antes');
    $r = $enDisco->archivar($xml, null, $doc, 'Proveedor Prueba S.A.');
    comprobarSociedad(is_file($r['ruta_xml']) && RutaDocumento::esAbsoluta($r['ruta_xml']),
        'y se archiva en disco');
    @unlink($xml);

    // ── El ajuste se relee, pero no a cada pregunta ─────────────────────
    $antes = $consultas;
    AlmacenDocumentos::para(5);
    AlmacenDocumentos::para(6);
    comprobarSociedad($consultas === $antes, 'el ajuste se lee una vez y se recuerda un rato');
    $ajustes = [];
    AlmacenDocumentos::olvidarAjustes();
    comprobarSociedad(AlmacenDocumentos::para(5) === $disco && $consultas === $antes + 1,
        'tras olvidarlo se vuelve a leer: la 5 volvió a disco');

    // Sin base, se contesta disco y no se cae nada.
    AlmacenDocumentos::fijarFuenteAjustes(function () { throw new RuntimeException('sin base'); });
    comprobarSociedad(AlmacenDocumentos::para(5) === $disco, 'sin poder leer el ajuste, disco');
    AlmacenDocumentos::fijarFuenteAjustes($fuente);

    // ── usando(): acotado y siempre se devuelve ─────────────────────────
    $otro = new AlmacenDePrueba();
    $e = lanza(function () use ($otro) {
        AlmacenDocumentos::usando($otro, function () use ($otro) {
            comprobarSociedad(AlmacenDocumentos::actual() === $otro && AlmacenDocumentos::de('C:\\x') === $otro,
                'dentro de usando() manda el elegido');
            throw new RuntimeException('a mitad');
        });
    });
    comprobarSociedad($e !== null && AlmacenDocumentos::actual() === $disco,
        'y al salir, aunque sea por una excepción, vuelve el de antes');

    // ── soltarEnTodos(): una sola vez aunque el forzado sea uno de los construidos
    $nube->documentos['SISTEMA/c.xml'] = 'c';
    $a = $nube->rutaLocal('SISTEMA/c.xml');
    $b = $nube->rutaLocal('SISTEMA/c.xml');
    AlmacenDocumentos::usando($nube, function () use ($a) {
        RutaDocumento::soltarLocal($a);
    });
    comprobarSociedad(is_file($b), 'soltar una vez no se lleva el temporal que otro todavía usa');
    RutaDocumento::soltarLocal($b);
    comprobarSociedad(!is_file($b), 'soltarlo la segunda vez sí lo borra');

    // ── Sociedad::definirAlmacen ────────────────────────────────────────
    $sociedad = new class extends Sociedad {
        public $sentencias = [];
        public $baseMigrada = true;
        public function __construct() {}
        protected function execute($sql, $params = [])
        {
            $this->sentencias[] = $params;
            return 1;
        }
        protected function fetchColumn($sql, $params = [])
        {
            return $this->baseMigrada ? 1 : false;
        }
    };
    $e = lanza(function () use ($sociedad) { $sociedad->definirAlmacen(5, 'dropbox'); });
    comprobarSociedad($e instanceof InvalidArgumentException, 'un almacén desconocido no se acepta');

    $sociedad->baseMigrada = false;
    $e = lanza(function () use ($sociedad) { $sociedad->definirAlmacen(5, 'sharepoint'); });
    comprobarSociedad($e instanceof RuntimeException && strpos($e->getMessage(), 'migration_almacen_graph') !== false
        && $sociedad->sentencias === [],
        'sin la columna por fila en la base, no se deja pasar a SharePoint: lo archivado se leería como de disco');
    $sociedad->baseMigrada = true;

    $sociedad->definirAlmacen(5, 'SharePoint');
    comprobarSociedad($sociedad->sentencias === [['sharepoint', 5]],
        'con SharePoint en esta PC, el cambio se guarda');

    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarFuenteAjustes($fuente);
    $sociedad->sentencias = [];
    $e = lanza(function () use ($sociedad) { $sociedad->definirAlmacen(5, 'sharepoint'); });
    comprobarSociedad($e instanceof RuntimeException && $sociedad->sentencias === [],
        'sin SharePoint en esta PC, se niega y no toca la base');
    $sociedad->definirAlmacen(5, 'disco');
    comprobarSociedad($sociedad->sentencias === [['disco', 5]], 'volver a disco se puede siempre');
} finally {
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    RutaDocumento::olvidarRaiz();
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

echo "OK: cada documento se busca donde está, y lo nuevo va donde dice su sociedad\n";
