<?php
/**
 * La carpeta de entrega cambia de nombre sin dejar documentos atrás.
 *
 * En el disco compartido la carpeta se llamaba `PAGOS SEMANALES` y adentro
 * están los respaldos de pagos anteriores, que alguien todavía puede
 * necesitar. Al dejar de ser semanal el pago, la carpeta pasa a `PAGOS`, y
 * eso hay que hacerlo renombrando: crear una nueva al lado y dejar la vieja
 * con lo de antes es exactamente lo que hace que después nadie encuentre nada.
 *
 * Lo que se fija acá:
 *
 *   · Si está la vieja y no la nueva, se renombra —con todo lo que tenga
 *     dentro— la primera vez que alguien la necesita.
 *   · Si están las dos, no se toca ninguna. Mezclar el contenido de dos
 *     carpetas es una decisión de quien administra el disco, no de este
 *     código, y hacerlo mal no se deshace.
 *   · Si no hay ninguna, se usa la nueva y se crea cuando haga falta.
 *
 * Uso: php tests/PagoCarpetaRenombradaTest.php
 */

require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

$fallos = 0;
function verificaCarpeta($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

/** Una raíz de juguete, con las carpetas que se le pidan ya creadas. */
function raizCon(array $carpetas, array $archivos = [])
{
    $raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagos_' . uniqid();
    mkdir($raiz, 0777, true);
    foreach ($carpetas as $c) {
        mkdir($raiz . DIRECTORY_SEPARATOR . $c, 0777, true);
    }
    foreach ($archivos as $rel => $contenido) {
        $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($ruta), 0777, true);
        file_put_contents($ruta, $contenido);
    }
    return $raiz;
}

function borrarArbol($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($ruta);
}

$VIEJA = DocumentoArchivo::CARPETA_PAGOS_VIEJA;   // 'PAGOS SEMANALES'
$NUEVA = DocumentoArchivo::CARPETA_PAGOS;         // 'PAGOS'

verificaCarpeta($VIEJA !== $NUEVA, 'el nombre nuevo de la carpeta es distinto del viejo');
verificaCarpeta($NUEVA === 'PAGOS', 'la carpeta de entrega se llama PAGOS');

// ── Instalación que viene de antes: se renombra con todo adentro ───────
$raiz = raizCon([$VIEJA], [
    $VIEJA . '/Pago Semana 200826/FE_PROVEEDOR_200826_00004354.xml' => '<x/>',
    $VIEJA . '/Pago Semana 200826/FE_PROVEEDOR_200826_00004354.pdf' => '%PDF',
]);
$archivo = new DocumentoArchivo($raiz);
$base = $archivo->carpetaBasePagos();

verificaCarpeta($base === $raiz . DIRECTORY_SEPARATOR . $NUEVA,
    'la carpeta base pasa a llamarse PAGOS');
verificaCarpeta(!is_dir($raiz . DIRECTORY_SEPARATOR . $VIEJA),
    'y la vieja deja de existir: se renombró, no se copió');
verificaCarpeta(
    is_file($raiz . DIRECTORY_SEPARATOR . $NUEVA
        . DIRECTORY_SEPARATOR . 'Pago Semana 200826'
        . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200826_00004354.xml'),
    'los respaldos que había dentro siguen ahí, en su carpeta de siempre'
);
verificaCarpeta(in_array('Pago Semana 200826', $archivo->carpetasPago(), true),
    'y el selector los sigue ofreciendo para reutilizar');

// Llamarla otra vez no hace nada raro: ya está renombrada.
verificaCarpeta($archivo->carpetaBasePagos() === $base,
    'la segunda llamada devuelve lo mismo, sin volver a renombrar');
borrarArbol($raiz);

// ── Las dos carpetas a la vez: no se toca ninguna ──────────────────────
//
// Pasa si alguien creó "PAGOS" a mano antes de actualizar. Juntarlas puede
// pisar carpetas del mismo nombre con contenido distinto, y eso no se deshace.
$raiz = raizCon([$VIEJA, $NUEVA], [
    $VIEJA . '/Pago viejo/a.xml' => '<x/>',
    $NUEVA . '/Pago nuevo/b.xml' => '<x/>',
]);
$archivo = new DocumentoArchivo($raiz);
$base = $archivo->carpetaBasePagos();

verificaCarpeta($base === $raiz . DIRECTORY_SEPARATOR . $NUEVA,
    'con las dos presentes se trabaja sobre la nueva');
verificaCarpeta(is_dir($raiz . DIRECTORY_SEPARATOR . $VIEJA),
    'y la vieja se deja intacta: juntarlas lo decide quien administra el disco');
verificaCarpeta(is_file($raiz . DIRECTORY_SEPARATOR . $VIEJA . DIRECTORY_SEPARATOR . 'Pago viejo' . DIRECTORY_SEPARATOR . 'a.xml'),
    'sin perder nada de lo que tenía dentro');
borrarArbol($raiz);

// ── Instalación nueva: no hay nada que renombrar ───────────────────────
$raiz = raizCon([]);
$archivo = new DocumentoArchivo($raiz);
verificaCarpeta($archivo->carpetaBasePagos() === $raiz . DIRECTORY_SEPARATOR . $NUEVA,
    'sin carpeta previa se usa PAGOS directamente');
verificaCarpeta($archivo->carpetasPago() === [],
    'y no hay carpetas de pago que ofrecer todavía');

$creada = $archivo->prepararCarpetaPago('Pago 08/09/2026');
verificaCarpeta(is_dir($creada), 'la carpeta de un pago se crea cuando se pide');
verificaCarpeta(strpos($creada, DIRECTORY_SEPARATOR . $NUEVA . DIRECTORY_SEPARATOR) !== false,
    'y cuelga de PAGOS, no de la carpeta vieja');
borrarArbol($raiz);

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}

echo "OK: la carpeta de entrega se renombra sin dejar documentos atrás\n";
