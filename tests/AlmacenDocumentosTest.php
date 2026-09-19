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
require_once __DIR__ . '/soporte/AlmacenDePrueba.php';

function assertAlmacen($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
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
// pago dejaría cientos de archivos sueltos en el disco del servidor.

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

// ── Escribir por la puerta ────────────────────────────────────────────────
//
// Archivar dos veces el mismo comprobante es lo NORMAL —un proveedor reenvía
// el correo, una importación se reintenta— y no puede ser un error. Pero dos
// documentos distintos con el mismo nombre sí tienen que frenar, porque el
// segundo taparía al primero sin que nadie se entere.

$origen = tempnam(sys_get_temp_dir(), 'org');
file_put_contents($origen, '<NotaCredito/>');
$hashOrigen = hash('sha256', '<NotaCredito/>');

$escrito = $almacen->escribir('2026/09/NC_00000500.xml', $origen);
assertAlmacen($escrito['creado'] === true && $escrito['hash'] === $hashOrigen,
    'escribir() deja el documento y dice que lo creó');
assertAlmacen($almacen->existe('2026/09/NC_00000500.xml'),
    'y desde ese momento el almacén lo da por existente');

$otraVez = $almacen->escribir('2026/09/NC_00000500.xml', $origen);
assertAlmacen($otraVez['creado'] === false,
    'escribir lo MISMO otra vez no es un error: no se reescribe y se avisa que ya estaba');

$distinto = tempnam(sys_get_temp_dir(), 'org');
file_put_contents($distinto, '<NotaCredito otra="1"/>');
$choco = false;
try {
    $almacen->escribir('2026/09/NC_00000500.xml', $distinto);
} catch (AlmacenConflicto $e) {
    $choco = true;
    assertAlmacen($e->hashExistente() === $hashOrigen,
        'el conflicto dice el hash de lo que ya estaba, para que quien llama decida');
}
assertAlmacen($choco, 'escribir algo DISTINTO donde ya hay un documento tiene que frenar');

// Mover conserva el contenido y deja libre el origen. Es lo que hace el
// organizador cuando un documento cambia de carpeta.
$almacen->mover('2026/09/NC_00000500.xml', '2026/09/Notas/NC_00000500.xml');
assertAlmacen(!$almacen->existe('2026/09/NC_00000500.xml')
    && $almacen->existe('2026/09/Notas/NC_00000500.xml'),
    'mover() deja el documento en el destino y lo saca del origen');

// Copiar conserva los dos. Es lo que hace la carpeta de un pago, y la
// diferencia importa: mover ahí dentro perdió los respaldos de un pago entero.
$almacen->copiar('2026/09/Notas/NC_00000500.xml', 'PAGOS/semana 40/NC_00000500.xml');
assertAlmacen($almacen->existe('2026/09/Notas/NC_00000500.xml')
    && $almacen->existe('PAGOS/semana 40/NC_00000500.xml'),
    'copiar() deja el original donde estaba: la carpeta del pago es material de entrega');

$identidad = $almacen->identidad('2026/09/Notas/NC_00000500.xml');
assertAlmacen(is_array($identidad) && $identidad['tamano'] === strlen('<NotaCredito/>'),
    'identidad() describe el documento sin traer su contenido');
assertAlmacen($almacen->identidad('2026/09/NO_ESTA.xml') === null,
    'y de lo que no está no hay identidad que dar');

assertAlmacen($almacen->borrar('PAGOS/semana 40/NC_00000500.xml') === true,
    'borrar() se lleva el documento');
assertAlmacen($almacen->borrar('PAGOS/semana 40/NC_00000500.xml') === false,
    'y borrar lo que ya no está NO es un fallo: lo que se pedía era que no estuviera');

@unlink($origen);
@unlink($distinto);

// ── El almacén de disco sigue siendo el de por omisión ────────────────────

AlmacenDocumentos::olvidar();
assertAlmacen(AlmacenDocumentos::actual() instanceof AlmacenEnDisco,
    'sin configurar nada, los documentos siguen saliendo del disco como siempre');

echo "OK: Una sola puerta para los documentos\n";
