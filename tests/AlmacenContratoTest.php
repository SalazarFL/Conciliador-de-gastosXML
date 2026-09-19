<?php
/**
 * Que los dos almacenes se comporten IGUAL.
 *
 * La puerta (AlmacenDocumentos) promete que se puede cambiar dónde viven los
 * documentos sin tocar a quien los pide. Esa promesa no la cumple la interfaz:
 * la cumple que las implementaciones contesten lo mismo ante lo mismo. Una
 * diferencia chica —que una devuelva false donde la otra lanza, que una acepte
 * escribir encima y la otra no— se paga el día del corte, en producción, con
 * documentos de verdad.
 *
 * Por eso esto es UN solo juego de aserciones que se corre contra cada
 * almacén. AlmacenEnGraph entró así: se agregó abajo sin escribir una
 * sola aserción nueva. Si pasa esto, se puede enchufar.
 *
 * Uso: php tests/AlmacenContratoTest.php
 */
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function comprobar($condicion, $mensaje, $almacen)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $almacen . ': ' . $mensaje;
    }
}

/** Un archivo local con ese contenido, que es lo que recibe escribir(). */
function archivoLocal($contenido)
{
    $ruta = tempnam(sys_get_temp_dir(), 'contrato');
    file_put_contents($ruta, $contenido);

    return $ruta;
}

function contrato(AlmacenDocumentos $a, $nombre)
{
    $xml = archivoLocal('<FacturaElectronica/>');
    $otro = archivoLocal('<FacturaElectronica distinta="1"/>');
    $hash = hash('sha256', '<FacturaElectronica/>');
    $base = 'SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/';

    // ── Escribir ──────────────────────────────────────────────────────────

    $r = $a->escribir($base . 'FE_00000001.xml', $xml);
    comprobar($r['creado'] === true, 'escribir() crea el documento', $nombre);
    comprobar($r['hash'] === $hash, 'escribir() devuelve el SHA-256 del contenido', $nombre);
    comprobar($r['tamano'] === strlen('<FacturaElectronica/>'), 'escribir() devuelve el tamaño', $nombre);
    comprobar($a->existe($base . 'FE_00000001.xml'), 'lo escrito existe', $nombre);

    // Las carpetas intermedias se crean solas: quien archiva no tiene por qué
    // acordarse de pedir la carpeta antes de cada documento.
    comprobar($a->identidad($base . 'FE_00000001.xml') !== null,
        'las carpetas del camino se crean solas', $nombre);

    // ── Leer lo que se escribió ───────────────────────────────────────────

    $fp = $a->abrir($base . 'FE_00000001.xml');
    comprobar($fp !== false && stream_get_contents($fp) === '<FacturaElectronica/>',
        'abrir() devuelve exactamente lo que se escribió', $nombre);
    if (is_resource($fp)) {
        fclose($fp);
    }
    comprobar($a->tamano($base . 'FE_00000001.xml') === strlen('<FacturaElectronica/>'),
        'tamano() coincide con lo escrito', $nombre);

    // ── Idempotencia: archivar dos veces lo mismo no es un error ─────────

    $r2 = $a->escribir($base . 'FE_00000001.xml', $xml);
    comprobar($r2['creado'] === false,
        'escribir el MISMO contenido otra vez no reescribe y avisa que ya estaba', $nombre);

    // ── Conflicto: otro contenido con el mismo nombre tiene que frenar ───

    $freno = false;
    $hashDicho = null;
    try {
        $a->escribir($base . 'FE_00000001.xml', $otro);
    } catch (AlmacenConflicto $e) {
        $freno = true;
        $hashDicho = $e->hashExistente();
    }
    comprobar($freno, 'escribir contenido DISTINTO donde ya hay algo lanza AlmacenConflicto', $nombre);
    comprobar($hashDicho === $hash || $hashDicho === '',
        'el conflicto dice el hash de lo que ya estaba, o nada si no lo sabe', $nombre);

    // ── Mover ─────────────────────────────────────────────────────────────

    $destino = 'SISTEMA/EMPRESA/2026/10 OCTUBRE/Facturas/FE_00000001.xml';
    $a->mover($base . 'FE_00000001.xml', $destino);
    comprobar(!$a->existe($base . 'FE_00000001.xml'), 'mover() deja libre el origen', $nombre);
    comprobar($a->existe($destino), 'mover() deja el documento en el destino', $nombre);

    $fp = $a->abrir($destino);
    comprobar($fp !== false && stream_get_contents($fp) === '<FacturaElectronica/>',
        'y el contenido llega intacto al destino', $nombre);
    if (is_resource($fp)) {
        fclose($fp);
    }

    // Mover al mismo sitio es no hacer nada, no un error. El organizador
    // recalcula el destino en cada corrida y casi siempre es el mismo.
    $sinCambio = true;
    try {
        $a->mover($destino, $destino);
    } catch (Throwable $e) {
        $sinCambio = false;
    }
    comprobar($sinCambio, 'mover un documento a donde ya está no falla', $nombre);

    // ── Copiar: el original se queda ──────────────────────────────────────

    $copia = 'PAGOS/semana 40/FE_00000001.xml';
    $a->copiar($destino, $copia);
    comprobar($a->existe($destino) && $a->existe($copia),
        'copiar() deja los dos: la carpeta del pago es material de entrega', $nombre);

    // ── Identidad ─────────────────────────────────────────────────────────

    $id = $a->identidad($destino);
    comprobar(is_array($id) && $id['tamano'] === strlen('<FacturaElectronica/>'),
        'identidad() describe el documento sin traer su contenido', $nombre);
    comprobar($a->identidad('SISTEMA/NO_ESTA.xml') === null,
        'identidad() de lo que no está es null', $nombre);

    // ── Borrar ────────────────────────────────────────────────────────────

    comprobar($a->borrar($copia) === true, 'borrar() se lleva el documento', $nombre);
    comprobar($a->existe($copia) === false, 'y después ya no existe', $nombre);
    comprobar($a->borrar($copia) === false,
        'borrar lo que ya no está NO es un fallo: el resultado que se pedía es el que hay', $nombre);

    // ── Lo que el almacén dice de sí mismo ────────────────────────────────

    comprobar(is_bool($a->recorreElArbolBarato()), 'dice si recorrer el árbol es barato', $nombre);
    comprobar(is_bool($a->tieneIdentidadPermanente()), 'dice si sus identificadores sobreviven', $nombre);
    comprobar($a->descripcion() !== '', 'sabe cómo llamarse en pantalla', $nombre);

    @unlink($xml);
    @unlink($otro);
}

// ── El almacén de mentira ─────────────────────────────────────────────────

contrato(new AlmacenDePrueba(), 'AlmacenDePrueba');

// ── El almacén de disco, sobre una carpeta temporal ───────────────────────

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contrato_almacen_' . uniqid();
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);

contrato(new AlmacenEnDisco(), 'AlmacenEnDisco');

// Y una comprobación que solo tiene sentido en disco: que no queden restos del
// patrón de escritura. Un `.partial` olvidado es un documento a medias con
// nombre de documento, y el organizador lo indexaría como si fuera bueno.
$restos = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $item) {
    if ($item->isFile() && strpos($item->getFilename(), '.partial_') !== false) {
        $restos[] = $item->getPathname();
    }
}
comprobar($restos === [], 'no queda ningún archivo .partial_ tirado', 'AlmacenEnDisco');

RutaDocumento::olvidarRaiz();

// ── SharePoint por Graph, contra una biblioteca en memoria ────────────────
//
// Dos veces: con la columna HashSha256 y sin ella. Sin la columna el almacén
// no sabe el hash de lo que ya estaba y, para decidir si "archivar otra vez"
// es lo mismo o un conflicto, tiene que bajarlo y compararlo. El contrato
// tiene que cumplirse igual.

foreach (['con la columna del hash' => true, 'sin la columna del hash' => false] as $como => $conColumna) {
    $biblioteca = new GraphEnMemoria();
    $biblioteca->conColumnaHash = $conColumna;
    $graph = new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca,
        ['carpeta_estado' => null, 'registro' => null]
    );
    contrato(new AlmacenEnGraph($graph), 'AlmacenEnGraph ' . $como);
}

// Limpieza de la carpeta temporal.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($raiz);

// ── Resultado ─────────────────────────────────────────────────────────────

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: Los almacenes cumplen el mismo contrato\n";
