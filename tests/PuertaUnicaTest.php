<?php
/**
 * Que nadie vuelva a ir al disco por su cuenta.
 *
 * Todo lo que toca un documento —guardarlo, moverlo, borrarlo, saber si
 * está— pasa por AlmacenDocumentos. Esa es la única razón por la que se puede
 * cambiar dónde viven los documentos sin reescribir el sistema. El problema es
 * que saltarse la puerta es gratis y no se nota: con los documentos en disco,
 * un is_file() suelto funciona igual. Se nota el día del corte a SharePoint,
 * en producción, diciendo "archivo perdido" de algo que está.
 *
 * Así que esto lleva la cuenta. En los archivos que manejan documentos, cada
 * función que va directo al disco tiene un tope, y cada tope tiene su
 * explicación abajo: lo que queda es local de verdad —la configuración, un
 * adjunto recién bajado del correo, lo que subió una persona, un ZIP
 * temporal—. Si alguien agrega una llamada más, esto falla y lo obliga a
 * mirar si es local o si debería haber pasado por la puerta.
 *
 * Si un número BAJA, la prueba avisa para que se baje el tope: si no, queda
 * un hueco por donde una llamada nueva entraría sin que nadie la vea.
 *
 * Los comentarios no cuentan: explicar por qué no se usa is_file() no es usar
 * is_file().
 *
 * Uso: php tests/PuertaUnicaTest.php
 */

$raiz = dirname(__DIR__);

/*
 * archivo => [función => tope]
 *
 * Por qué queda cada cosa, archivo por archivo:
 */
$topes = [
    // file_get_contents/is_file: storage/correo/config.json, que es de esta
    //   máquina. is_file (el resto) y hash_file: los ORÍGENES de archivar,
    //   que son siempre locales —importar() los consigue antes—.
    // file_put_contents/unlink: el archivo local con el que se arma la nota
    //   de SISTEMA antes de entregársela al almacén.
    // rename: PAGOS SEMANALES → PAGOS, que solo existe en instalaciones
    //   viejas sobre la carpeta compartida (ver carpetaBasePagos).
    'app/helpers/DocumentoArchivo.php' => [
        'file_get_contents' => 1, 'file_put_contents' => 1, 'hash_file' => 1,
        'is_file' => 4, 'rename' => 1, 'unlink' => 1,
    ],
    // Los adjuntos que baja del correo para comprobar su hash: locales.
    'app/helpers/RecuperadorDocumentos.php' => ['hash_file' => 2, 'is_file' => 2, 'unlink' => 1],
    'app/helpers/InvoiceImportQueue.php' => [],
    // El PDF que subió una persona, antes de archivarlo.
    'app/helpers/DevolucionImporter.php' => ['hash_file' => 1],
    // El modelo de proveedores (código, no documento), y dos comprobaciones
    // de que un archivo ya es de esta máquina antes de pedírselo al almacén.
    'app/helpers/XmlDocumentImporter.php' => ['is_file' => 3],
    'app/helpers/EntradaAutomatica.php' => [],
    // El ZIP temporal que se arma para descargar.
    'app/helpers/PaqueteDocumentos.php' => ['unlink' => 2],
    'app/helpers/EstadoArchivo.php' => [],
    // conArchivoLocal(): si ya es un archivo de esta máquina, se usa tal cual.
    'app/helpers/RutaDocumento.php' => ['is_file' => 1],
    'app/models/FacturaXmlDetalle.php' => [],
    'app/controllers/DocumentosController.php' => [],
    // El PDF subido, que se borra después de importarlo.
    'app/controllers/DevolucionesController.php' => ['unlink' => 1],
    // Todo local: los adjuntos en storage/correo/tmp, el ZIP de la bandeja,
    // los scripts .ps1/.vbs de la tarea programada y config.json. Los últimos
    // unlink son adjuntos recién bajados que no van a ningún almacén: los de
    // un correo que otra ventana ya está guardando (procesarMensaje) y los de
    // un comprobante que ya estaba cargado, que ni entra a la Bandeja. (El
    // explorador y el selector de la carpeta compartida, que también tocaban
    // el disco, se fueron con ella el 27/09/2026.)
    'app/controllers/CorreoController.php' => [
        'file_get_contents' => 2, 'file_put_contents' => 3, 'filemtime' => 1,
        'filesize' => 1, 'is_file' => 7, 'mkdir' => 1, 'readfile' => 1,
        'simplexml_load_file' => 1, 'unlink' => 13,
    ],
];

/*
 * Fuera de la cuenta, con motivo:
 *
 *   OrganizadorDocumentos  Recorre el árbol entero y rehashea: es de disco por
 *                          diseño, y solo recibe filas de disco (almacen IS
 *                          NULL). Los documentos de SharePoint los sigue
 *                          ReconciliadorGraph, por su id y los cambios de la
 *                          biblioteca, sin tocar el disco.
 */

$patron = '/\b(copy|rename|unlink|mkdir|rmdir|is_file|file_exists|filesize|filemtime|hash_file'
    . '|realpath|simplexml_load_file|file_put_contents|file_get_contents|readfile|DirectoryIterator)\s*\(/';

$fallas = [];
$avisos = [];

foreach ($topes as $archivo => $tope) {
    $codigo = '';
    foreach (token_get_all((string) file_get_contents($raiz . '/' . $archivo)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codigo .= is_array($token) ? $token[1] : $token;
    }

    preg_match_all($patron, $codigo, $m);
    $cuenta = array_count_values($m[1]);

    foreach ($cuenta as $funcion => $veces) {
        $permitido = (int) ($tope[$funcion] ?? 0);
        if ($veces > $permitido) {
            $fallas[] = "{$archivo}: {$funcion}() aparece {$veces} veces y el tope es {$permitido}. "
                . 'Si es un documento, tiene que pasar por AlmacenDocumentos; si es de verdad '
                . 'un archivo local, sube el tope y explica por qué.';
        }
    }
    foreach ($tope as $funcion => $permitido) {
        $veces = (int) ($cuenta[$funcion] ?? 0);
        if ($veces < $permitido) {
            $avisos[] = "{$archivo}: {$funcion}() bajó a {$veces} (tope {$permitido}). Baja el tope.";
        }
    }
}

if ($fallas || $avisos) {
    foreach (array_merge($fallas, $avisos) as $linea) {
        fwrite(STDERR, "FAIL: {$linea}\n");
    }
    exit(1);
}

echo "OK: los documentos se tocan por una sola puerta\n";
