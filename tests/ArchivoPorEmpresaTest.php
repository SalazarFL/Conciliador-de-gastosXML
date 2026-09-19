<?php
/**
 * El archivo de documentos separa las empresas.
 *
 * El árbol es RAIZ/SISTEMA/EMPRESA/AAAA/MM MES/Facturas. Antes no tenía el
 * nivel de la empresa y los documentos de las dos caían revueltos en la misma
 * carpeta del mes: la base sabía de quién era cada uno, pero quien abría la
 * carpeta compartida no.
 *
 * Se comprueban las tres cosas que sostienen esa separación: cómo se convierte
 * el nombre de la empresa en nombre de carpeta, que el archivo la use, y que
 * dos empresas con el MISMO documento no se pisen el archivo.
 *
 * Uso: php tests/ArchivoPorEmpresaTest.php
 */

require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

$fallos = 0;
function compruebaArchivo($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        echo 'FAIL: ' . $mensaje . PHP_EOL;
        $fallos++;
    }
}

function borrarArbol($dir)
{
    if (!is_dir($dir)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// ── El nombre de la empresa como carpeta ────────────────────────────────────

compruebaArchivo(DocumentoArchivo::carpetaEmpresa('GRUPO BM SP S.A') === 'GRUPO BM SP SA',
    'el punto de "S.A" se borra en vez de partir la palabra en dos');
compruebaArchivo(DocumentoArchivo::carpetaEmpresa('Compañía Río Java') === 'COMPAÑIA RIO JAVA',
    'los acentos se van y la Ñ se queda: es una letra, no un acento');
compruebaArchivo(DocumentoArchivo::carpetaEmpresa('A/B: "C" <D>') === 'A B C D',
    'los signos que Windows rechaza en un nombre de carpeta se van');
compruebaArchivo(DocumentoArchivo::carpetaEmpresa('/\\ *?|"<>') === DocumentoArchivo::EMPRESA_SIN_DEFINIR,
    'un nombre que es solo signos no deja una carpeta sin nombre');
compruebaArchivo(DocumentoArchivo::carpetaEmpresa('  ') === '',
    'sin nombre no se separa: es como se comportaba antes de que hubiera dos empresas');
compruebaArchivo(
    mb_strlen(DocumentoArchivo::carpetaEmpresa(str_repeat('A', 200)), 'UTF-8') <= DocumentoArchivo::MAX_CARPETA_EMPRESA,
    'un nombre larguísimo se recorta: la ruta completa tiene un tope en Windows'
);

// Lo que SharePoint rechaza además de lo que rechaza Windows. Con el nombre
// de una empresa, que escribe una persona, puede aparecer; y si aparece, la
// subida falla recién en producción.
compruebaArchivo(DocumentoArchivo::carpetaEmpresa('~$EMPRESA') === '$EMPRESA',
    'un nombre no empieza por ~: SharePoint lo reserva para los bloqueos de Office');
compruebaArchivo(stripos(DocumentoArchivo::carpetaEmpresa('MI_VTI_EMPRESA'), '_VTI_') === false,
    'y no lleva _vti_, que SharePoint usa por dentro');
compruebaArchivo(substr(DocumentoArchivo::normalizarCarpetaPago('Semana 40...'), -1) !== '.',
    'el nombre de un pago no termina en punto');
compruebaArchivo(
    substr(DocumentoArchivo::normalizarCarpetaPago(str_repeat('a', 99) . '.b'), -1) !== '.',
    'ni siquiera cuando el recorte a 100 letras deja el punto al final'
);

// ── El archivo, con dos empresas ────────────────────────────────────────────

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_empresas_' . bin2hex(random_bytes(5));
mkdir($raiz, 0700, true);

try {
    $archivo = new DocumentoArchivo($raiz);

    $sep = DIRECTORY_SEPARATOR;
    $sistema = $raiz . $sep . 'SISTEMA';

    compruebaArchivo($archivo->carpetaSistema() === $sistema,
        'la raíz del árbol sigue siendo SISTEMA, sin empresa: ahí va la advertencia');
    compruebaArchivo($archivo->carpetaEmpresaSistema() === $sistema,
        'sin empresa dicha, el árbol es el de siempre');

    $deA = $archivo->paraEmpresa('Empresa A');
    $deB = $archivo->paraEmpresa('Empresa B');

    compruebaArchivo($archivo->empresa() === '',
        'paraEmpresa devuelve otro archivo y no cambia el original: el mismo objeto '
        . 'archiva documentos de varias empresas seguidas');
    compruebaArchivo($deA->empresa() === 'EMPRESA A', 'la copia sí queda apuntando a su empresa');

    $carpetaA = $deA->carpetaDocumento('2026-07-20', 'FE');
    $carpetaB = $deB->carpetaDocumento('2026-07-20', 'FE');
    compruebaArchivo(
        $carpetaA === $sistema . $sep . 'EMPRESA A' . $sep . '2026' . $sep . '07 JULIO' . $sep . 'Facturas',
        'la empresa va justo bajo SISTEMA, antes del año'
    );
    compruebaArchivo($carpetaA !== $carpetaB,
        'la misma fecha y el mismo tipo van a carpetas distintas si son de empresas distintas');

    // ── El mismo documento en las dos ──
    //
    // Es el caso que importa: dos empresas del grupo le compran a los mismos
    // proveedores, así que el nombre del archivo —tipo, proveedor, fecha y
    // número— se repite. Con una sola carpeta, el segundo tenía que
    // renombrarse para no pisar al primero; separados, cada uno se llama como
    // le toca y ninguno estorba al otro.
    $tmp = $raiz . $sep . '_entrada';
    mkdir($tmp, 0700, true);
    $xmlA = $tmp . $sep . 'a.xml';
    $xmlB = $tmp . $sep . 'b.xml';
    file_put_contents($xmlA, '<FacturaElectronica>de A</FacturaElectronica>');
    file_put_contents($xmlB, '<FacturaElectronica>de B</FacturaElectronica>');

    $doc = [
        'tipo_documento' => 'FE',
        'fecha_emision' => '2026-07-20',
        'numero_factura_asistente' => '00000167',
    ];

    $guardadoA = $deA->archivar($xmlA, null, $doc, 'PROVEEDOR COMUN S.A.');
    $guardadoB = $deB->archivar($xmlB, null, $doc, 'PROVEEDOR COMUN S.A.');

    compruebaArchivo(is_file($guardadoA['ruta_xml']) && is_file($guardadoB['ruta_xml']),
        'los dos quedan archivados');
    compruebaArchivo($guardadoA['archivo_xml'] === $guardadoB['archivo_xml'],
        'y con el mismo nombre: ninguno tuvo que renombrarse para caber');
    compruebaArchivo(dirname($guardadoA['ruta_xml']) !== dirname($guardadoB['ruta_xml']),
        'porque cada uno está en la carpeta de su empresa');
    compruebaArchivo(file_get_contents($guardadoA['ruta_xml']) === '<FacturaElectronica>de A</FacturaElectronica>',
        'y el de la empresa A sigue siendo el de la empresa A');
    compruebaArchivo(file_get_contents($guardadoB['ruta_xml']) === '<FacturaElectronica>de B</FacturaElectronica>',
        'y el de la B, el de la B');

    // Lo que no se sabe de quién es va aparte, no revuelto con una de ellas.
    $huerfano = $archivo->paraEmpresa(DocumentoArchivo::EMPRESA_SIN_DEFINIR)
        ->carpetaDocumento('2026-07-20', 'FE');
    compruebaArchivo(strpos($huerfano, $sep . 'SIN EMPRESA' . $sep) !== false,
        'un documento sin empresa conocida se archiva en SIN EMPRESA');
} catch (Throwable $e) {
    echo 'FAIL: excepción inesperada: ', $e->getMessage(), PHP_EOL;
    $fallos++;
}

borrarArbol($raiz);

if ($fallos > 0) {
    echo PHP_EOL, $fallos, ' comprobaciones fallaron', PHP_EOL;
    exit(1);
}
echo 'OK: el archivo separa los documentos de cada empresa', PHP_EOL;
