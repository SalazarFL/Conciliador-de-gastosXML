<?php
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

function assertArchivo($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function borrarArbolPrueba($dir) {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($dir);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_archivo_' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$xml1 = $root . DIRECTORY_SEPARATOR . 'uno.xml';
$xml2 = $root . DIRECTORY_SEPARATOR . 'dos.xml';
$pdf = $root . DIRECTORY_SEPARATOR . 'uno.pdf';
file_put_contents($xml1, '<?xml version="1.0"?><FacturaElectronica><A>1</A></FacturaElectronica>');
file_put_contents($xml2, '<?xml version="1.0"?><FacturaElectronica><A>2</A></FacturaElectronica>');
file_put_contents($pdf, '%PDF-1.4 prueba');

try {
    $servicio = new DocumentoArchivo($root);
    $doc = ['tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'4354'];
    $nombreNc = DocumentoArchivo::nombreBase(
        ['tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'0000000167'],
        'Proveedor Prueba S.A.'
    );
    $nombreFe = DocumentoArchivo::nombreBase(
        ['tipo_documento'=>'FE', 'fecha_emision'=>'2026-06-08', 'numero_factura_asistente'=>'0000077153'],
        'DADA TEXTIL S.A.'
    );
    $primero = $servicio->archivar($xml1, $pdf, $doc, 'Proveedor Prueba S.A.');
    $repetido = $servicio->archivar($xml1, $pdf, $doc, 'Proveedor Prueba S.A.');
    $colision = $servicio->archivar($xml2, $pdf, $doc, 'Proveedor Prueba S.A.');
    $incompleto = $servicio->archivar(
        $xml2,
        null,
        ['tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-25', 'numero_factura_asistente'=>'987'],
        'Proveedor Prueba S.A.'
    );

    assertArchivo(is_file($primero['ruta_xml']) && is_file($primero['ruta_pdf']), 'crea el par XML/PDF');
    assertArchivo(substr($nombreNc, -8) === '00000167', 'las notas de crédito usan exactamente 8 dígitos');
    assertArchivo($nombreFe === 'FE_DADA_TEXTIL_080626_00077153', 'las facturas conservan proveedor y fecha con consecutivo de 8 dígitos');

    /*
     * La Ñ en el nombre del archivo.
     *
     * "COMPAÑIA RIO JAVA" se archivaba como FE_COMPA_NIA_RIO_JAVA: iconv no
     * reemplazaba la letra sino que le anteponía una marca ("~N"), y el filtro
     * de caracteres convertía esa marca en separador. No era cosa de la Ñ: a
     * "JOSÉ PÉREZ" le pasaba lo mismo (JOS_E_P_EREZ), y en nombres de
     * proveedor las tildes son la regla, no la excepción.
     *
     * Ahora la Ñ se conserva —es una letra, no un acento— y las tildes se
     * quitan de verdad, con un mapa que dice exactamente qué sale.
     */
    $conEnie = function ($proveedor) {
        return DocumentoArchivo::nombreBase(
            ['tipo_documento' => 'FE', 'fecha_emision' => '2026-07-17', 'numero_factura_asistente' => '00016987'],
            $proveedor
        );
    };

    assertArchivo($conEnie('COMPAÑIA RIO JAVA') === 'FE_COMPAÑIA_RIO_JAVA_170726_00016987',
        'la Ñ se queda en el nombre del archivo, no se parte en _N');
    assertArchivo($conEnie('MUÑOZ & MUÑOZ') === 'FE_MUÑOZ_MUÑOZ_170726_00016987',
        'varias eñes en el mismo nombre, todas intactas');
    assertArchivo($conEnie('ÑANDU EXPRESS') === 'FE_ÑANDU_EXPRESS_170726_00016987',
        'también cuando la palabra empieza con Ñ');
    assertArchivo($conEnie('compañía peñaranda') === 'FE_COMPAÑIA_PEÑARANDA_170726_00016987',
        'la minúscula sube a Ñ y la tilde de la í se va: son dos cosas distintas');

    assertArchivo($conEnie('DISTRIBUIDORA JOSÉ PÉREZ') === 'FE_DISTRIBUIDORA_JOSE_PEREZ_170726_00016987',
        'las tildes se quitan sin partir la palabra');
    assertArchivo($conEnie('PANADERÍA LA ESPIGA') === 'FE_PANADERIA_LA_ESPIGA_170726_00016987',
        'incluida la í, que era la que más aparecía rota');
    assertArchivo($conEnie('ÜBER ÖSTERREICH') === 'FE_UBER_OSTERREICH_170726_00016987',
        'la diéresis tampoco deja marca');

    // El nombre no puede depender de la máquina: la carpeta es compartida y
    // la aplicación corre en varias. Con iconv, lo que salía dependía de la
    // biblioteca de cada equipo.
    assertArchivo($conEnie('COMPAÑIA RIO JAVA') === $conEnie('COMPAÑIA RIO JAVA'),
        'el mismo proveedor da siempre el mismo nombre');

    // Sin nombre utilizable queda el marcador, no un nombre vacío.
    assertArchivo($conEnie('') === 'FE_PROVEEDOR_170726_00016987', 'sin proveedor hay marcador');
    assertArchivo($conEnie('S.A.') === 'FE_PROVEEDOR_170726_00016987',
        'un nombre que es solo sufijo societario también');

    // El tope de largo cuenta letras, no bytes: cortar una Ñ por la mitad
    // dejaría un byte suelto en el nombre de un archivo del disco.
    $largo = $conEnie(str_repeat('AÑ', 30));
    assertArchivo(mb_check_encoding($largo, 'UTF-8'),
        'recortar un nombre largo no parte una Ñ en dos');
    assertArchivo(strpos($primero['ruta_xml'], 'SISTEMA' . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Facturas') !== false, 'estructura SISTEMA/año/mes/tipo');
    // Lo archivado cuelga de SISTEMA y la advertencia queda a la vista: la
    // raíz es una carpeta compartida donde también se trabaja, y el árbol de
    // años suelto ahí no decía de quién era ni que no se puede tocar.
    $sistema = $root . DIRECTORY_SEPARATOR . 'SISTEMA';
    assertArchivo(dirname($primero['ruta_xml'], 4) === $sistema, 'el árbol de años cuelga de SISTEMA');
    assertArchivo(is_file($sistema . DIRECTORY_SEPARATOR . 'LEEME - NO MODIFICAR.txt'), 'deja la nota de advertencia dentro de SISTEMA');
    assertArchivo(!is_dir($root . DIRECTORY_SEPARATOR . '2026'), 'no queda un árbol de años en la raíz');
    assertArchivo($repetido['xml_creado'] === false && $repetido['ruta_xml'] === $primero['ruta_xml'], 'reutiliza el mismo hash');
    assertArchivo(strpos($colision['archivo_xml'], '_DUP_') !== false, 'no sobrescribe una colisión');
    // La carpeta sale de la fecha de emisión y el tipo, y de nada más: debajo
    // del mes está el tipo y ahí termina el árbol. Antes había además una
    // subcarpeta por estado, y era la causa de que los archivos tuvieran que
    // reacomodarse cada vez que la factura avanzaba.
    assertArchivo(basename(dirname($primero['ruta_xml'])) === 'Facturas'
        && basename(dirname(dirname($primero['ruta_xml']))) === '07 JULIO',
        'la carpeta es año/mes/tipo, sin subcarpeta de estado debajo');
    assertArchivo(basename(dirname($incompleto['ruta_xml'])) === 'Notas de crédito'
        && basename(dirname(dirname($incompleto['ruta_xml']))) === '07 JULIO',
        'un documento incompleto no se aparta: va con su tipo, al mismo nivel');
    assertArchivo($incompleto['ruta_pdf'] === null, 'no inventa un PDF para un documento incompleto');
    assertArchivo(hash_file('sha256', $primero['ruta_xml']) === hash_file('sha256', $xml1), 'valida SHA-256');
    echo "OK: DocumentoArchivo\n";
} finally {
    borrarArbolPrueba($root);
}
