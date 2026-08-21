<?php
/**
 * Si el documento todavía tiene sus archivos.
 *
 * Es la pregunta que ahora hacen todos los módulos —pago semanal, seguimiento,
 * comprobantes, notas—, así que la respuesta tiene que ser la misma en todos.
 * Lo que se comprueba es la distinción que dio origen a esto: una fila sin
 * ruta nunca tuvo archivo y no es un faltante; una fila CON ruta y sin archivo
 * al final de ella sí, y esa casi siempre se puede volver a bajar del correo.
 */
require_once __DIR__ . '/../app/helpers/EstadoArchivo.php';

function assertEstadoArchivo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_estado_' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$xml = $dir . DIRECTORY_SEPARATOR . 'FE.xml';
$pdf = $dir . DIRECTORY_SEPARATOR . 'FE.pdf';
file_put_contents($xml, '<FacturaElectronica/>');
file_put_contents($pdf, '%PDF');

try {
    // ── El par completo ────────────────────────────────────────────────────
    $ok = EstadoArchivo::de(['ruta_xml' => $xml, 'ruta_pdf' => $pdf]);
    assertEstadoArchivo($ok['xml_ok'] && $ok['pdf_ok'] && !$ok['perdido'],
        'con los dos archivos en su sitio no hay nada perdido');
    assertEstadoArchivo($ok['entregable'] && $ok['nunca_llego'] === '',
        'y el par completo es lo único que se puede entregar en un pago');

    // ── El histórico que nunca se archivó ──────────────────────────────────
    // Sin ruta no hay promesa que romper: falta el comprobante, que es otro
    // problema y se resuelve consiguiéndolo, no reponiéndolo.
    $historico = EstadoArchivo::de(['ruta_xml' => '', 'ruta_pdf' => null, 'correo_uid' => 900, 'hash_xml' => str_repeat('a', 64)]);
    assertEstadoArchivo(!$historico['perdido'] && !$historico['recuperable'],
        'una fila sin ruta no cuenta como archivo perdido');

    // Que no sea "perdido" no quiere decir que esté completo. El pago semanal
    // no copia esta factura a su carpeta, y hasta ahora eso no se decía en
    // ningún lado porque `perdido` y `que_falta` callaban los dos.
    assertEstadoArchivo(!$historico['entregable'],
        'lo que nunca llegó tampoco se puede entregar');
    assertEstadoArchivo($historico['nunca_llego'] === 'XML y PDF',
        'y se nombra lo que nunca llegó, aparte de lo que se perdió');

    // El caso corriente de verdad: el proveedor mandó el XML y nunca el PDF.
    $soloXml = EstadoArchivo::de(['ruta_xml' => $xml, 'ruta_pdf' => '']);
    assertEstadoArchivo(!$soloXml['perdido'] && $soloXml['que_falta'] === '',
        'un PDF que nunca llegó no es un archivo perdido: no hay nada que reponer');
    assertEstadoArchivo(!$soloXml['entregable'] && $soloXml['nunca_llego'] === 'PDF',
        'pero sí es la razón por la que esa factura no llega a la carpeta del pago');

    // ── La ruta apunta al vacío ────────────────────────────────────────────
    unlink($pdf);
    $medio = EstadoArchivo::de(['ruta_xml' => $xml, 'ruta_pdf' => $pdf]);
    assertEstadoArchivo($medio['perdido'] && $medio['xml_ok'] && !$medio['pdf_ok'],
        'perder solo el PDF ya es perder un archivo');
    assertEstadoArchivo($medio['que_falta'] === 'PDF',
        'y se dice cuál falta, que es lo que se lee en el renglón');
    assertEstadoArchivo(!$medio['entregable'] && $medio['nunca_llego'] === '',
        'un PDF borrado no es un PDF que nunca llegó: se recupera, no se pide');

    unlink($xml);
    $ambos = EstadoArchivo::de(['ruta_xml' => $xml, 'ruta_pdf' => $pdf]);
    assertEstadoArchivo($ambos['que_falta'] === 'XML y PDF', 'con los dos, se nombran los dos');

    // ── Se puede volver a bajar ────────────────────────────────────────────
    assertEstadoArchivo(!$ambos['recuperable'],
        'sin el correo del que salió no hay de dónde bajarlo');

    $conCorreo = EstadoArchivo::de([
        'ruta_xml' => $xml, 'ruta_pdf' => $pdf,
        'correo_uid' => 900, 'hash_xml' => str_repeat('a', 64),
    ]);
    assertEstadoArchivo($conCorreo['recuperable'],
        'con mensaje de origen y huella guardada, sí');

    $sinHuella = EstadoArchivo::de(['ruta_xml' => $xml, 'correo_uid' => 900, 'hash_xml' => '']);
    assertEstadoArchivo(!$sinHuella['recuperable'],
        'sin huella no se puede probar que lo que baje sea el mismo archivo');

    // La columna que traen las consultas de listado manda sobre las crudas:
    // es la misma pregunta resuelta en SQL para no arrastrar el hash entero.
    $porColumna = EstadoArchivo::de(['ruta_xml' => $xml, 'recuperable' => 1]);
    assertEstadoArchivo($porColumna['recuperable'],
        'la columna calculada del listado vale igual que el hash en crudo');
    $negada = EstadoArchivo::de([
        'ruta_xml' => $xml, 'recuperable' => 0,
        'correo_uid' => 900, 'hash_xml' => str_repeat('a', 64),
    ]);
    assertEstadoArchivo(!$negada['recuperable'],
        'y si la columna dice que no, no se contradice con las crudas');

    // ── Sobre una lista ────────────────────────────────────────────────────
    $filas = EstadoArchivo::decorar([
        ['id' => 1, 'ruta_xml' => $xml],
        ['id' => 2, 'ruta_xml' => ''],
    ]);
    assertEstadoArchivo(!empty($filas[0]['archivo_perdido']) && empty($filas[1]['archivo_perdido']),
        'decorar una lista deja el estado con el prefijo que leen las vistas');
    assertEstadoArchivo(array_key_exists('id', $filas[0]) && $filas[0]['id'] === 1,
        'y no pierde lo que la fila ya traía');

    assertEstadoArchivo(strpos(EstadoArchivo::columnaRecuperable('x.'), 'x.correo_uid') !== false,
        'la columna se arma con el alias que le pasen');

    echo "OK: EstadoArchivo\n";
} finally {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) { unlink($f); }
    rmdir($dir);
}
