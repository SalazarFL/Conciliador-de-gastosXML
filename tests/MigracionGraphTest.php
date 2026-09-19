<?php
/**
 * Pasar una empresa de la carpeta compartida a SharePoint, y volver.
 *
 * Contra la base aislada de certificación, una carpeta compartida temporal y
 * la biblioteca en memoria:
 *
 *   - el inventario lee la base; lo que ya no está en disco queda omitido y
 *     no frena nada;
 *   - --dry-run no deja rastro;
 *   - la subida se hace por tandas, se corta sola si se cae la red y se
 *     retoma donde quedó;
 *   - un archivo del disco que no es el que la base archivó no se sube;
 *   - la verificación compara tamaño y hash declarado, y el muestreo baja y
 *     rehashea de verdad;
 *   - una fila que cambió desde el inventario no se reescribe;
 *   - la empresa NO se corta mientras quede algo pendiente, y se corta sola
 *     cuando ya no queda;
 *   - después del corte cada documento se abre desde SharePoint, con la misma
 *     ruta relativa de siempre;
 *   - revertir sin bajar lo que se archivó después del corte se niega, sin
 *     tocar nada; con --bajando lo baja primero y deja todo en disco.
 *   - el disco nunca se toca: los originales siguen ahí al final.
 *
 * Deja la base como la encontró.
 *
 * Uso: php tests/MigracionGraphTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/MigracionGraph.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function paso($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: MigracionGraph (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    echo "SKIP: MigracionGraph (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
$proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
if ($proveedorId <= 0) {
    echo "SKIP: MigracionGraph (sin proveedores en la base aislada)\n";
    exit(0);
}

// ── La biblioteca, con una red que se puede cortar ────────────────────────
$biblioteca = new GraphEnMemoria();
$red = new class($biblioteca) implements TransporteGraph {
    private $real;
    public $caida = false;
    public function __construct($real) { $this->real = $real; }
    public function enviar(array $p)
    {
        if ($this->caida && $p['metodo'] === 'PUT') {
            return ['codigo' => 0, 'cabeceras' => [], 'cuerpo' => '', 'error' => 'Connection timed out'];
        }
        return $this->real->enviar($p);
    }
};
$vigente = null;
// Cada corrida del comando es un proceso nuevo: cliente, cortacircuitos y
// memoria del almacén empiezan de cero.
$nuevaNube = function () use ($red, &$vigente) {
    $vigente = new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $red, ['carpeta_estado' => null, 'registro' => null, 'presupuesto' => 2, 'dormir' => function () {}]
    ));
    AlmacenDocumentos::registrar('sharepoint', function () use (&$vigente) { return $vigente; });
    return $vigente;
};

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migracion_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::olvidar();
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');
AlmacenDocumentos::fijarFuenteAjustes(null);
$disco = AlmacenDocumentos::actual();

$marca = strtoupper(bin2hex(random_bytes(3)));
$empresa = 'MIGRA ' . $marca;
$mes = 'SISTEMA/' . $empresa . '/2026/06 JUNIO/Facturas';
$sociedadId = 0;
$facturaIds = [];
$devolucionId = 0;
$migracion = function () use (&$sociedadId, $disco, $nuevaNube) {
    Model::olvidarMemoria();
    AlmacenDocumentos::olvidarAjustes();
    return new MigracionGraph($sociedadId, $disco, $nuevaNube());
};
$fila = function ($tabla, $id) use ($pdo) {
    return $pdo->query("SELECT * FROM {$tabla} WHERE id = " . (int) $id)->fetch() ?: [];
};
$enDisco = function ($relativa) use ($raiz) {
    $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
    return is_file($ruta) ? file_get_contents($ruta) : null;
};

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa, almacen_documentos) VALUES (?, ?, 0, 'disco')")
        ->execute([$empresa, '3109' . random_int(100000, 999999)]);
    $sociedadId = (int) $pdo->lastInsertId();

    // ── La empresa, en la carpeta compartida ─────────────────────────────
    //   A: XML y PDF.  B: XML, y un PDF que la base promete y no está.
    //   C: un XML que en disco no es el que la base archivó.
    //   D: el PDF de una devolución.
    $contenidos = [];
    $archivar = function ($relativa, $contenido) use ($raiz, &$contenidos) {
        $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
        @mkdir(dirname($ruta), 0777, true);
        file_put_contents($ruta, $contenido);
        $contenidos[$relativa] = $contenido;
        return hash('sha256', $contenido);
    };
    $factura = function ($letra, $xml, $pdf, $hashXml, $hashPdf) use ($pdo, $proveedorId, &$sociedadId, $marca) {
        $pdo->prepare("INSERT INTO facturas_xml
                (sociedad_id, consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision, tipo_documento,
                 ruta_xml, archivo_xml, ruta_pdf, archivo_pdf, hash_xml, hash_pdf)
                VALUES (?, ?, ?, ?, '2026-06-07', 'FE', ?, ?, ?, ?, ?, ?)")
            ->execute([$sociedadId, 'MIG' . $marca . $letra, substr($letra . $marca, 0, 8), $proveedorId,
                $xml, basename($xml), $pdf, $pdf ? basename($pdf) : null, $hashXml, $hashPdf]);
        return (int) $pdo->lastInsertId();
    };
    $a = $factura('A', "$mes/FE_A.xml", "$mes/FE_A.pdf",
        $archivar("$mes/FE_A.xml", '<FE>A' . $marca . '</FE>'), $archivar("$mes/FE_A.pdf", '%PDF A' . $marca));
    $b = $factura('B', "$mes/FE_B.xml", "$mes/FE_B.pdf", $archivar("$mes/FE_B.xml", '<FE>B' . $marca . '</FE>'), str_repeat('b', 64));
    $hashRealC = $archivar("$mes/FE_C.xml", '<FE>C' . $marca . '</FE>');
    $c = $factura('C', "$mes/FE_C.xml", null, str_repeat('c', 64), null);
    $facturaIds = [$a, $b, $c];
    $rutaDev = 'DEVOLUCIONES/2026/DEV_' . $marca . '.pdf';
    $pdo->prepare("INSERT INTO devoluciones (sociedad_id, tipo, numero, archivo_pdf, ruta_pdf, hash_pdf)
                   VALUES (?, 'DEV', ?, ?, ?, ?)")
        ->execute([$sociedadId, 'D' . $marca, basename($rutaDev), $rutaDev, $archivar($rutaDev, '%PDF devolución ' . $marca)]);
    $devolucionId = (int) $pdo->lastInsertId();

    // ── 1. Inventario ────────────────────────────────────────────────────
    $r = $migracion()->inventario(true);
    paso((int) $pdo->query('SELECT COUNT(*) FROM almacen_migracion WHERE sociedad_id = ' . $sociedadId)->fetchColumn() === 0,
        'el inventario con --dry-run no anota nada');
    $r = $migracion()->inventario();
    paso($r['inventariados'] === 5 && $r['omitidos'] === 1,
        'cinco archivos que pasar y uno que ya no estaba en disco: ' . json_encode($r));

    // ── 2. Subir, con la red caída al principio ──────────────────────────
    $red->caida = true;
    $r = $migracion()->subir();
    $red->caida = false;
    paso($r['cortado'] !== '' && $r['fallidos'] === 3 && $r['subidos'] === 0,
        'sin red, la tanda se corta sola a los tres fallos seguidos: ' . json_encode($r));
    paso((int) $pdo->query("SELECT COUNT(*) FROM almacen_migracion WHERE sociedad_id = $sociedadId
                            AND estado = 'inventariado' AND intentos = 1")->fetchColumn() === 3,
        'y los tres quedan en la fila, con su intento anotado');
    $r = $migracion()->subir(2);
    paso($r['subidos'] === 2, 'vuelta la red, una tanda de dos: ' . json_encode($r));
    $r = $migracion()->subir();
    paso($r['subidos'] === 2 && $r['conflictos'] === 1,
        'se retoma donde quedó; el XML que no es el archivado no se sube: ' . json_encode($r));
    paso($biblioteca->contenido("$mes/FE_C.xml") === null, 'C no llegó a SharePoint');
    paso($biblioteca->contenido("$mes/FE_A.xml") === '<FE>A' . $marca . '</FE>',
        'lo subido está en la misma ruta relativa, con el mismo contenido');

    // ── 3. Verificar ────────────────────────────────────────────────────
    $r = $migracion()->verificar(0, 2);
    paso($r['verificados'] === 4 && $r['muestreo_bien'] === 2 && $r['distintos'] === 0,
        'se verifican los cuatro y el muestreo baja y confirma dos: ' . json_encode($r));

    // ── 4. Reescribir: una fila que cambió, y el corte que no llega ─────
    $pdo->exec("UPDATE devoluciones SET ruta_pdf = 'DEVOLUCIONES/2026/OTRO.pdf' WHERE id = " . $devolucionId);
    $r = $migracion()->reescribir();
    paso($r['filas_pasadas'] === 2 && $r['filas_cambiadas'] === 1,
        'A y B pasan; la devolución cambió desde el inventario y no se toca: ' . json_encode($r));
    paso(!$r['empresa_cortada'] && strpos($r['falta_para_cortar'], 'sin terminar') !== false,
        'con C y la devolución pendientes, la empresa NO se corta: ' . json_encode($r));
    paso(AlmacenDocumentos::tipoDeSociedad($sociedadId) === 'disco', 'y sigue archivando en disco');
    $filaA = $fila('facturas_xml', $a);
    paso($filaA['almacen'] === 'sharepoint' && $filaA['ruta_xml'] === "$mes/FE_A.xml"
        && $filaA['item_xml_id'] !== null && $filaA['item_pdf_id'] !== null,
        'la fila de A anota SharePoint y sus ids, sin tocar la ruta: ' . json_encode($filaA));
    paso($fila('devoluciones', $devolucionId)['almacen'] === null, 'la devolución que cambió sigue en disco');

    // Se arregla: la devolución vuelve a su ruta y C a su hash verdadero.
    $pdo->prepare('UPDATE devoluciones SET ruta_pdf = ? WHERE id = ?')->execute([$rutaDev, $devolucionId]);
    $pdo->prepare('UPDATE facturas_xml SET hash_xml = ? WHERE id = ?')->execute([$hashRealC, $c]);
    $migracion()->reintentar();
    $m = $migracion();
    $inv = $m->inventario();
    $sub = $m->subir();
    $ver = $m->verificar();
    $r = $m->reescribir();
    paso($sub['subidos'] === 1 && $sub['ya_estaban'] === 1 && $ver['verificados'] === 2,
        'la segunda pasada sube C y encuentra la devolución ya subida: ' . json_encode([$inv, $sub, $ver]));
    paso($r['empresa_cortada'] && AlmacenDocumentos::tipoDeSociedad($sociedadId) === 'sharepoint',
        'sin nada pendiente, la empresa se corta sola: ' . json_encode($r));

    // ── 5. Después del corte ─────────────────────────────────────────────
    $leida = (new Factura())->setSociedad(0)->findById($a);
    paso(strpos((string) $leida['ruta_xml'], 'sharepoint:') === 0, 'al leerla, A apunta a SharePoint: ' . $leida['ruta_xml']);
    $fp = RutaDocumento::abrirParaLeer($leida['ruta_xml']);
    paso($fp !== false && stream_get_contents($fp) === '<FE>A' . $marca . '</FE>', 'y se abre desde allá');
    if (is_resource($fp)) {
        fclose($fp);
    }
    paso($fila('facturas_xml', $b)['almacen'] === 'sharepoint',
        'B pasa aunque su PDF ya faltaba: sigue igual de faltante, ahora en SharePoint');
    $dev = $fila('devoluciones', $devolucionId);
    paso($dev['almacen'] === 'sharepoint' && $dev['item_pdf_id'] !== null, 'la devolución también pasó');
    $r = $migracion()->inventario();
    paso($r['inventariados'] === 0, 'correrlo otra vez no encuentra nada que pasar: ' . json_encode($r));

    // Un documento que llega después del corte vive solo en SharePoint.
    $local = tempnam(sys_get_temp_dir(), 'mig_');
    file_put_contents($local, '<FE>E' . $marca . '</FE>');
    $nuevo = $nuevaNube()->escribir("SISTEMA/$empresa/2026/09 SEPTIEMBRE/Facturas/FE_E.xml", $local);
    @unlink($local);
    $e = $factura('E', $nuevo['ruta'], null, $nuevo['hash'], null);
    $facturaIds[] = $e;
    $pdo->prepare("UPDATE facturas_xml SET almacen = 'sharepoint', item_xml_id = ? WHERE id = ?")->execute([$nuevo['item_id'], $e]);

    // ── 6. Revertir ─────────────────────────────────────────────────────
    $r = $migracion()->revertir(false);
    paso($r['rehusado'] !== '' && $r['por_bajar'] === 1,
        'sin --bajando se niega: E solo existe en SharePoint: ' . json_encode($r));
    paso(AlmacenDocumentos::tipoDeSociedad($sociedadId) === 'sharepoint' && $fila('facturas_xml', $a)['almacen'] === 'sharepoint',
        'y no toca nada');

    $r = $migracion()->revertir(true);
    paso($r['rehusado'] === '' && $r['bajados'] === 1 && $r['filas'] === 5,
        'con --bajando baja E y devuelve las cinco filas: ' . json_encode($r));
    paso(AlmacenDocumentos::tipoDeSociedad($sociedadId) === 'disco', 'la empresa vuelve a archivar en disco');
    $filaA = $fila('facturas_xml', $a);
    $filaE = $fila('facturas_xml', $e);
    paso($filaA['almacen'] === null && $filaA['item_xml_id'] === null && $filaA['ruta_xml'] === "$mes/FE_A.xml",
        'A vuelve a disco, a su ruta de siempre: ' . json_encode($filaA));
    paso($filaE['almacen'] === null && $filaE['ruta_xml'] === "SISTEMA/$empresa/2026/09 SEPTIEMBRE/Facturas/FE_E.xml"
        && $enDisco($filaE['ruta_xml']) === '<FE>E' . $marca . '</FE>',
        'E quedó en disco, en la misma ruta relativa: ' . json_encode($filaE));
    paso($fila('devoluciones', $devolucionId)['almacen'] === null, 'la devolución también vuelve');

    // ── Y el disco, intacto de principio a fin ──────────────────────────
    $intactos = true;
    foreach ($contenidos as $relativa => $contenido) {
        $intactos = $intactos && $enDisco($relativa) === $contenido;
    }
    paso($intactos, 'ningún original del disco se borró ni se cambió');
} catch (Throwable $e) {
    $fallas[] = 'se cortó la prueba: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    foreach ($facturaIds as $id) {
        $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . (int) $id);
    }
    if ($devolucionId > 0) {
        $pdo->exec('DELETE FROM devoluciones WHERE id = ' . $devolucionId);
    }
    if ($sociedadId > 0) {
        $pdo->exec('DELETE FROM almacen_migracion WHERE sociedad_id = ' . $sociedadId);
        $pdo->exec('DELETE FROM sociedades WHERE id = ' . $sociedadId);
    }
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::olvidarAjustes();
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

echo "OK: una empresa pasa a SharePoint por pasos que se retoman, y vuelve sin perder nada\n";
