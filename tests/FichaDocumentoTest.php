<?php
/**
 * La ficha que se lee en el cuadro emergente.
 *
 * Lo que se comprueba es lo que el visor NO puede arreglar desde el navegador:
 * que una nota de crédito no se anuncie como factura, que las fechas se lean
 * como se escriben acá, que los botones de archivo solo aparezcan cuando hay
 * algo que abrir, y que un documento que se archivó y desapareció lo diga con
 * las mismas palabras que los listados.
 */
require_once __DIR__ . '/../app/helpers/FichaDocumento.php';

function assertFicha($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** El campo con esa etiqueta, para no depender del orden en cada prueba. */
function campoFicha(array $ficha, $etiqueta)
{
    foreach ($ficha['campos'] as $campo) {
        if ($campo['etiqueta'] === $etiqueta) {
            return $campo;
        }
    }
    return null;
}

function archivoFicha(array $ficha, $tipo)
{
    foreach ($ficha['archivos'] as $archivo) {
        if ($archivo['tipo'] === $tipo) {
            return $archivo;
        }
    }
    return null;
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_ficha_' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
$xml = $dir . DIRECTORY_SEPARATOR . 'FE.xml';
$pdf = $dir . DIRECTORY_SEPARATOR . 'FE.pdf';
file_put_contents($xml, '<FacturaElectronica/>');
file_put_contents($pdf, '%PDF');

$base = '/xmlconcilia/public';

try {
    // ── Una factura con sus dos archivos ───────────────────────────────────
    $fila = [
        'id' => 42,
        'tipo_documento' => 'FE',
        'numero_factura_asistente' => '12345',
        'consecutivo_completo' => '00100001010000012473',
        'clave' => str_repeat('9', 50),
        'receptor_id' => '3101123456',
        'proveedor_nombre' => 'DISTRIBUIDORA EJEMPLO S.A.',
        'proveedor_cedula' => '3101999888',
        'fecha_emision' => '2026-03-04',
        'fecha_correo' => '2026-03-05 09:07:33',
        'archivado_en' => '2026-03-05 09:08:01',
        'subtotal' => 1000,
        'iva' => 130,
        'total' => 1130,
        'moneda' => 'CRC',
        'estado_pdf' => 'disponible',
        'archivo_xml' => 'FE.xml',
        'archivo_pdf' => 'FE.pdf',
        'ruta_xml' => $xml,
        'ruta_pdf' => $pdf,
    ];
    $ficha = FichaDocumento::de($fila, $base);

    assertFicha($ficha['titulo'] === 'Factura electrónica' && $ficha['tipo'] === 'FE',
        'una FE se anuncia como factura electrónica');
    assertFicha($ficha['numero'] === '00012345',
        'el número corto sale normalizado a ocho dígitos, como en los listados');
    assertFicha($ficha['simbolo'] === '₡' && $ficha['total'] === '1,130.00',
        'el total lleva el símbolo de su moneda');
    assertFicha($ficha['estado']['resumen']['tono'] === 'ok'
        && $ficha['estado']['resumen']['texto'] === 'XML + PDF',
        'con los dos archivos en su sitio la marca dice XML + PDF');
    assertFicha($ficha['url_detalle'] === $base . '/facturas/ver/42',
        'la salida a la pantalla completa apunta al módulo de facturas');

    // Las fechas, como se leen acá y no como las guarda la base.
    assertFicha(campoFicha($ficha, 'Fecha de emisión')['valor'] === '04/03/2026',
        'la fecha de emisión se lee en día/mes/año');
    assertFicha(campoFicha($ficha, 'Llegó por correo el')['valor'] === '05/03/2026 09:07',
        'la fecha con hora pierde los segundos, que nadie mira');

    // Los archivos: hay qué abrir, así que hay a dónde ir.
    assertFicha(archivoFicha($ficha, 'xml')['url'] === $base . '/documentos/xml/42'
        && archivoFicha($ficha, 'pdf')['url'] === $base . '/documentos/pdf/42',
        'los dos archivos presentes traen su enlace');

    // La clave se copia; el número asistente, de ocho dígitos, no hace falta.
    assertFicha(campoFicha($ficha, 'Clave')['copiar'] === true,
        'la clave trae botón de copiar: son cincuenta dígitos');
    assertFicha(campoFicha($ficha, 'Número asistente')['copiar'] === false,
        'el número corto no lo necesita');

    // ── La misma fila, pero es una nota de crédito ─────────────────────────
    $nota = FichaDocumento::de(['tipo_documento' => 'NC'] + $fila, $base);
    assertFicha($nota['titulo'] === 'Nota de crédito' && $nota['tipo'] === 'NC',
        'una NC no se anuncia como factura: manda a buscarla al listado equivocado');
    assertFicha($nota['url_detalle'] === $base . '/notas-xml/ver/42',
        'y su pantalla completa está en el módulo de notas XML');

    // ── El que se archivó y desapareció ────────────────────────────────────
    // La fila conserva la ruta, el archivo ya no está: eso desmiente a
    // cualquier otra marca y tiene que decirlo con las palabras del resto.
    $perdida = FichaDocumento::de(
        ['ruta_pdf' => $dir . DIRECTORY_SEPARATOR . 'no_existe.pdf'] + $fila,
        $base
    );
    assertFicha($perdida['estado']['perdido'] && $perdida['estado']['resumen']['tono'] === 'perdido',
        'un archivo que ya no está en la carpeta se anuncia como perdido');
    assertFicha($perdida['estado']['resumen']['texto'] === 'Falta el PDF',
        'y dice cuál de los dos falta');
    assertFicha(archivoFicha($perdida, 'pdf')['url'] === ''
        && archivoFicha($perdida, 'pdf')['ok'] === false,
        'sin archivo no se ofrece un botón que lleva a un 404');
    assertFicha(archivoFicha($perdida, 'xml')['url'] !== '',
        'pero el que sí está se sigue pudiendo abrir');

    // ── El histórico que nunca llegó ───────────────────────────────────────
    $historica = FichaDocumento::de(
        ['ruta_xml' => '', 'ruta_pdf' => '', 'archivo_pdf' => '',
         'estado_pdf' => 'no_disponible_historico'] + $fila,
        $base
    );
    assertFicha(!$historica['estado']['perdido']
        && $historica['estado']['resumen']['texto'] === 'Sin archivos',
        'sin ruta no hay promesa que romper: falta el comprobante, no se perdió');
    assertFicha(campoFicha($historica, 'Estado del PDF')['valor'] === 'No existe (documento histórico)',
        'el estado del PDF se dice en palabras, no con el código de la base');

    // ── Lo que puede venir vacío ───────────────────────────────────────────
    $pelada = FichaDocumento::de([
        'id' => 7,
        'tipo_documento' => 'FE',
        'numero_factura_asistente' => '1',
        'moneda' => 'EUR',
        'total' => 0,
    ], $base);
    assertFicha($pelada['proveedor'] === '' && $pelada['cedula'] === '',
        'una fila sin proveedor no inventa uno');
    assertFicha($pelada['simbolo'] === '',
        'una moneda sin símbolo conocido no lo inventa tampoco');
    assertFicha(campoFicha($pelada, 'Clave')['copiar'] === false,
        'no se ofrece copiar un campo vacío');
    assertFicha(campoFicha($pelada, 'Estado del PDF')['valor'] === 'Pendiente',
        'sin estado_pdf el documento está pendiente');
    assertFicha($pelada['url_detalle'] === $base . '/facturas/ver/7',
        'la salida sigue apuntando a su módulo');

    echo "OK FichaDocumentoTest\n";
} finally {
    @unlink($xml);
    @unlink($pdf);
    @rmdir($dir);
}
