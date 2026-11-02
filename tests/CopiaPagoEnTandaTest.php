<?php
/**
 * La carpeta de un pago se llena de a muchos, no de a uno.
 *
 * El 28/09/2026 un pago de 308 facturas tardó veinticinco minutos en quedar en
 * su carpeta: cada archivo eran cuatro llamadas seguidas (mirar el original,
 * preguntar si ya estaba en la carpeta, bajarlo, subirlo). Esto fija lo que
 * cambió, sin red y sin base:
 *
 *   - La carpeta del pago se lista UNA vez; nadie pregunta archivo por archivo.
 *   - Lo que falta se baja y se sube en tandas de a EN_PARALELO.
 *   - Las reglas de siempre siguen valiendo: lo que ya está no se vuelve a
 *     subir, otro contenido con el mismo nombre queda con _DUP_, y un original
 *     que no está deja la factura sin copia —para reintentar— en vez de romper
 *     la tanda.
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

/** La misma biblioteca, pero que recibe peticiones de a varias, como TransporteCurl. */
class GraphEnMemoriaEnTandas extends GraphEnMemoria implements TransporteGraphParalelo
{
    /** Cuántas peticiones llegó cada vez. */
    public $tandas = [];

    public function enviarVarios(array $peticiones)
    {
        $this->tandas[] = count($peticiones);
        $respuestas = [];
        foreach ($peticiones as $id => $peticion) {
            $respuestas[$id] = $this->enviar($peticion);
        }
        return $respuestas;
    }
}

$fallas = [];

function comprobarTanda($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

function almacenEnTandas(GraphEnMemoria $biblioteca)
{
    return new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca,
        ['carpeta_estado' => null, 'registro' => null]
    ));
}

/** Lo que se pidió después de la posición $desde, filtrado. */
function pedidos(GraphEnMemoria $biblioteca, $desde, callable $filtro)
{
    return array_values(array_filter(array_slice($biblioteca->registro, $desde), $filtro));
}

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tanda_' . uniqid();
mkdir($raiz, 0777, true);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . 'tmp');

try {
    $biblioteca = new GraphEnMemoriaEnTandas();
    $almacen = almacenEnTandas($biblioteca);
    // El camino de siempre baja el original por el almacén registrado.
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::registrar('sharepoint', function () use ($almacen) { return $almacen; });

    // Doce facturas archivadas: más de una tanda.
    $trabajos = [];
    $local = $raiz . DIRECTORY_SEPARATOR . 'origen.tmp';
    for ($i = 1; $i <= 12; $i++) {
        $xml = "SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_{$i}.xml";
        $pdf = "SISTEMA/EMPRESA/2026/09 SETIEMBRE/Facturas/FE_{$i}.pdf";
        file_put_contents($local, "<FE>{$i}</FE>");
        $almacen->escribir($xml, $local);
        file_put_contents($local, "%PDF-1.4 factura {$i}");
        $almacen->escribir($pdf, $local);
        $doc = ['tipo_documento' => 'FE', 'fecha_emision' => '2026-09-10', 'numero_factura_asistente' => (string) (1000 + $i)];
        $trabajos[$i] = [AlmacenEnGraph::PREFIJO . $xml, AlmacenEnGraph::PREFIJO . $pdf, $doc, 'Pago 28 09', 'Proveedor ' . $i];
    }

    // ── La primera vez: todo en tanda ───────────────────────────────────
    $archivo = DocumentoArchivo::sobreAlmacen(almacenEnTandas($biblioteca));   // otro proceso: no sabe nada
    $desde = count($biblioteca->registro);
    $salidas = $archivo->copiarVariosAPago($trabajos);

    $enteras = array_filter($salidas, function ($s) {
        return is_array($s) && $s['ruta_xml'] !== '' && $s['ruta_pdf'] !== '' && $s['copiados'] === 2;
    });
    comprobarTanda(count($enteras) === 12, 'las doce quedan con sus dos archivos copiados: ' . count($enteras));

    $base = DocumentoArchivo::nombreBase($trabajos[5][2], 'Proveedor 5');
    comprobarTanda($biblioteca->contenido('PAGOS/Pago 28 09/' . $base . '.xml') === '<FE>5</FE>'
        && $biblioteca->contenido('PAGOS/Pago 28 09/' . $base . '.pdf') === '%PDF-1.4 factura 5',
        'lo que queda en la carpeta del pago es el contenido del original');

    $listados = pedidos($biblioteca, $desde, function ($l) { return strpos($l, '/children') !== false; });
    comprobarTanda(count($listados) === 1, 'la carpeta del pago se lista una sola vez: ' . count($listados));
    $preguntas = pedidos($biblioteca, $desde, function ($l) {
        return strpos($l, 'GET') === 0 && strpos($l, '/children') === false && substr($l, -8) !== '/content';
    });
    comprobarTanda($preguntas === [], 'nadie pregunta archivo por archivo si está: ' . json_encode(array_slice($preguntas, 0, 3)));
    comprobarTanda(max($biblioteca->tandas) <= AlmacenEnGraph::EN_PARALELO && array_sum($biblioteca->tandas) === 48,
        'bajadas y subidas van de a ' . AlmacenEnGraph::EN_PARALELO . ' como mucho, 48 en total: ' . json_encode($biblioteca->tandas));

    // ── Otra vez: ya está todo, no se sube nada ─────────────────────────
    $otraVez = DocumentoArchivo::sobreAlmacen(almacenEnTandas($biblioteca));
    $desde = count($biblioteca->registro);
    $salidas = $otraVez->copiarVariosAPago($trabajos);
    $subidas = pedidos($biblioteca, $desde, function ($l) { return strpos($l, 'PUT') === 0; });
    $vigentes = array_filter($salidas, function ($s) { return is_array($s) && $s['copiados'] === 0 && $s['ruta_xml'] !== ''; });
    comprobarTanda($subidas === [] && count($vigentes) === 12, 'lo que ya está en la carpeta no se vuelve a subir');

    // ── Otro contenido con el mismo nombre: _DUP_, como siempre ─────────
    $nuevo = $trabajos[3];
    $nuevo[3] = 'Pago con choque';
    $baseChoque = DocumentoArchivo::nombreBase($nuevo[2], $nuevo[4]);
    file_put_contents($local, '<FE>otra factura con el mismo nombre</FE>');
    $almacen->escribir('PAGOS/Pago con choque/' . $baseChoque . '.xml', $local);
    $salida = DocumentoArchivo::sobreAlmacen(almacenEnTandas($biblioteca))->copiarVariosAPago(['x' => $nuevo])['x'];
    comprobarTanda(is_array($salida) && strpos($salida['ruta_xml'], '_DUP_') !== false && $salida['ruta_pdf'] !== '',
        'si el nombre está ocupado por otro contenido, la copia queda con _DUP_: ' . ($salida instanceof Throwable ? get_class($salida) . ": " . $salida->getMessage() : json_encode($salida)));

    // ── Un original que no está no rompe la tanda ───────────────────────
    $sinOriginal = $trabajos[1];
    $sinOriginal[0] = AlmacenEnGraph::PREFIJO . 'SISTEMA/EMPRESA/NO/ESTA.xml';
    $sinOriginal[3] = 'Pago sin original';
    $mezcla = ['falta' => $sinOriginal, 'bien' => array_replace($trabajos[2], [3 => 'Pago sin original'])];
    $salidas = DocumentoArchivo::sobreAlmacen(almacenEnTandas($biblioteca))->copiarVariosAPago($mezcla);
    comprobarTanda(is_array($salidas['falta']) && $salidas['falta']['ruta_xml'] === '' && $salidas['falta']['ruta_pdf'] !== '',
        'sin el original, esa factura queda sin XML para reintentar: ' . json_encode($salidas['falta']));
    comprobarTanda(is_array($salidas['bien']) && $salidas['bien']['copiados'] === 2,
        'y la otra de la tanda se copia igual');

    // ── Lo que dice un listado ──────────────────────────────────────────
    $a = almacenEnTandas($biblioteca);
    $a->conocerCarpeta('PAGOS/Pago 28 09');
    $antes = $biblioteca->llamadas;
    $enMayusculas = $a->identidad('PAGOS/Pago 28 09/' . strtoupper($base) . '.xml');
    $noEsta = $a->identidad('PAGOS/Pago 28 09/NO_ESTA.xml');
    comprobarTanda($biblioteca->llamadas === $antes && $enMayusculas !== null && $noEsta === null,
        'con la carpeta listada, identidad() contesta sin red y sin distinguir mayúsculas');
    $e = null;
    try {
        file_put_contents($local, '<FE>distinto</FE>');
        $a->escribir('PAGOS/Pago 28 09/' . $base . '.xml', $local);
    } catch (AlmacenConflicto $x) {
        $e = $x;
    }
    comprobarTanda($e !== null && $e->hashExistente() === hash('sha256', '<FE>5</FE>'),
        'un choque dentro de una carpeta listada se resuelve preguntando de verdad, no con el listado');
} finally {
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
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

echo "OK: la carpeta del pago se llena de a muchos, con las mismas reglas de siempre\n";
