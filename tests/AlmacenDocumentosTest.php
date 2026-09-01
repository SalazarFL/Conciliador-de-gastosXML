<?php
/**
 * Que los documentos se pidan por una sola puerta, y que esa puerta se pueda
 * cambiar sin tocar a quien la usa.
 *
 * Es lo único que hace posible ofrecerle al cliente elegir dónde viven sus
 * documentos —el disco del servidor o SharePoint— sin reescribir el sistema
 * cada vez. Si algún camino se salta la puerta y va al disco por su cuenta,
 * ese camino se rompe el día que los documentos no estén en un disco; y como
 * se rompería en silencio —diciendo "archivo perdido" de algo que está—, la
 * forma de fijarlo es esta: se pone un almacén de mentira que NO toca disco y
 * se comprueba que todos contesten lo que él dice.
 *
 * Uso: php tests/AlmacenDocumentosTest.php
 */
require_once __DIR__ . '/../app/helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/EstadoArchivo.php';

function assertAlmacen($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/**
 * Un almacén que vive en memoria. No existe ningún archivo detrás: si algo
 * contesta bien con esto puesto, es que preguntó por la puerta.
 */
class AlmacenDePrueba extends AlmacenDocumentos
{
    public $documentos = [];   // ruta => contenido
    public $enLaNube = [];     // rutas que existen pero no sueltan contenido
    public $soltados = [];

    public function existe($ruta)
    {
        $ruta = $this->clave($ruta);
        return isset($this->documentos[$ruta]) || in_array($ruta, $this->enLaNube, true);
    }

    public function abrir($ruta)
    {
        $ruta = $this->clave($ruta);
        if (!isset($this->documentos[$ruta])) {
            return false;
        }
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $this->documentos[$ruta]);
        rewind($fp);
        return $fp;
    }

    public function tamano($ruta)
    {
        $ruta = $this->clave($ruta);
        return isset($this->documentos[$ruta]) ? strlen($this->documentos[$ruta]) : null;
    }

    public function rutaLocal($ruta)
    {
        $ruta = $this->clave($ruta);
        if (!isset($this->documentos[$ruta])) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'alm');
        file_put_contents($tmp, $this->documentos[$ruta]);
        return $tmp;
    }

    public function soltarLocal($rutaLocal)
    {
        $this->soltados[] = $rutaLocal;
        @unlink($rutaLocal);
    }

    public function porQueNoSeLee($ruta)
    {
        return 'Está en el almacén de prueba y no suelta contenido.';
    }

    public function descripcion()
    {
        return 'el almacén de prueba';
    }

    private function clave($ruta)
    {
        return ltrim(str_replace('\\', '/', (string) $ruta), '/');
    }
}

$almacen = new AlmacenDePrueba();
$almacen->documentos['2026/08/FE_00000123.xml'] = '<FacturaElectronica/>';
$almacen->enLaNube[] = '2026/08/FE_00000999.pdf';
AlmacenDocumentos::fijar($almacen);

// ── RutaDocumento pregunta por la puerta ──────────────────────────────────

assertAlmacen(RutaDocumento::existe('2026/08/FE_00000123.xml'),
    'existe() contesta lo que dice el almacén, sin mirar el disco');

assertAlmacen(!RutaDocumento::existe('2026/08/NO_ESTA.xml'),
    'y contesta que no de lo que el almacén no tiene');

$fp = RutaDocumento::abrirParaLeer('2026/08/FE_00000123.xml');
assertAlmacen($fp !== false && stream_get_contents($fp) === '<FacturaElectronica/>',
    'abrirParaLeer() entrega el contenido que da el almacén');
fclose($fp);

assertAlmacen(RutaDocumento::tamano('2026/08/FE_00000123.xml') === 21,
    'tamano() sale del almacén y no de filesize()');

// ── Existir no es poder leerse ────────────────────────────────────────────
//
// La distinción que costó el error de los PDF vacíos: un documento que está
// pero cuyo contenido no llega. Tiene que decir que SÍ está —no está perdido,
// no hay que ir a buscarlo al correo— y a la vez negarse a abrirse.

assertAlmacen(RutaDocumento::existe('2026/08/FE_00000999.pdf'),
    'un documento que está pero no suelta contenido SIGUE estando');

assertAlmacen(RutaDocumento::abrirParaLeer('2026/08/FE_00000999.pdf') === false,
    'pero no se puede abrir, y quien lo sirve tiene que enterarse antes de mandar cabeceras');

assertAlmacen(strpos(RutaDocumento::porQueNoSeLee('2026/08/FE_00000999.pdf'), 'prueba') !== false,
    'la explicación la da el almacén: depende de dónde vivan los documentos');

// ── La marca de "archivo perdido" también pasa por la puerta ──────────────
//
// EstadoArchivo decide esa marca en todos los listados. Si mirara el disco por
// su cuenta, con los documentos en SharePoint diría "perdido" de todo.

$estado = EstadoArchivo::de([
    'ruta_xml' => '2026/08/FE_00000123.xml',
    'ruta_pdf' => '2026/08/FE_00000999.pdf',
]);
assertAlmacen($estado['xml_ok'] === true && $estado['pdf_ok'] === true,
    'EstadoArchivo ve los dos archivos a través de la puerta');
assertAlmacen($estado['perdido'] === false,
    'y no los da por perdidos: perdido es que no estén, no que estén lejos');

$estado = EstadoArchivo::de([
    'ruta_xml' => '2026/08/NO_ESTA.xml',
    'ruta_pdf' => '',
]);
assertAlmacen($estado['perdido'] === true,
    'lo que el almacén no tiene sí es un archivo perdido');

// ── rutaLocal() y su devolución ───────────────────────────────────────────
//
// El ZIP necesita archivos de verdad. Con los documentos lejos hay que bajarlos
// a un temporal, y ese temporal hay que limpiarlo: si no, armar el ZIP de un
// pago semanal dejaría cientos de archivos sueltos en el disco del servidor.

$local = RutaDocumento::rutaLocal('2026/08/FE_00000123.xml');
assertAlmacen($local !== null && is_file($local),
    'rutaLocal() entrega un archivo de verdad aunque el almacén no sea un disco');
assertAlmacen(file_get_contents($local) === '<FacturaElectronica/>',
    'y ese archivo tiene el contenido correcto');

RutaDocumento::soltarLocal($local);
assertAlmacen($almacen->soltados === [$local] && !is_file($local),
    'soltarLocal() le avisa al almacén, que limpia su temporal');

assertAlmacen(RutaDocumento::rutaLocal('2026/08/NO_ESTA.xml') === null,
    'de lo que no está no hay archivo local que dar');

// ── El almacén de disco sigue siendo el de por omisión ────────────────────

AlmacenDocumentos::olvidar();
assertAlmacen(AlmacenDocumentos::actual() instanceof AlmacenEnDisco,
    'sin configurar nada, los documentos siguen saliendo del disco como siempre');

echo "OK: Una sola puerta para los documentos\n";
