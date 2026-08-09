<?php
/**
 * Las rutas de los documentos se guardan relativas a la carpeta compartida.
 *
 * El problema que resuelve: la aplicación se instala en la computadora de cada
 * persona y todas comparten una base de datos. La carpeta sincronizada de
 * SharePoint está en un lugar distinto en cada máquina, así que guardar la ruta
 * completa hacía que un documento solo se abriera donde se importó.
 *
 * Lo que más se prueba aquí no es la conversión —es corta— sino que sea
 * TOLERANTE: una fila que se quedó sin convertir tiene que seguir abriendo, y
 * la migración tiene que poder correrse dos veces sin estropear nada. Esa
 * tolerancia es lo que evita que un descuido se convierta en pantallas rotas.
 */
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';

$fallos = 0;
function verificaRuta($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$S = DIRECTORY_SEPARATOR;
$raiz = 'C:\\Users\\ana\\Empresa\\Documentos compartidos';
RutaDocumento::fijarRaiz($raiz);

$rutaCompleta = $raiz . '\\2026\\07 JULIO\\Facturas\\EN SISTEMA\\FE_PROVEEDOR_010726_00000123.xml';
$relativa = '2026/07 JULIO/Facturas/EN SISTEMA/FE_PROVEEDOR_010726_00000123.xml';

// ── Ida y vuelta ──────────────────────────────────────────────────────
verificaRuta(RutaDocumento::relativa($rutaCompleta) === $relativa,
    'la ruta guardada es relativa a la carpeta compartida y con "/"');
verificaRuta(RutaDocumento::absoluta($relativa) === str_replace(['\\', '/'], $S, $rutaCompleta),
    'la ruta relativa se expande a la ruta real de esta computadora');

// ── Idempotencia: convertir dos veces no cambia nada ──────────────────
verificaRuta(RutaDocumento::relativa(RutaDocumento::relativa($rutaCompleta)) === $relativa,
    'convertir a relativa dos veces da lo mismo (la migración es repetible)');
verificaRuta(RutaDocumento::absoluta(RutaDocumento::absoluta($relativa))
    === RutaDocumento::absoluta($relativa),
    'expandir dos veces da lo mismo');

// ── La misma fila vista desde otra computadora ────────────────────────
RutaDocumento::fijarRaiz('D:\\SharePoint\\Facturas');
verificaRuta(RutaDocumento::absoluta($relativa)
    === str_replace('/', $S, 'D:\\SharePoint\\Facturas/' . $relativa),
    'la misma fila abre en otra computadora, donde la carpeta está en otro lugar');
RutaDocumento::fijarRaiz($raiz);

// ── Tolerancia: una fila sin convertir no debe romper la pantalla ─────
verificaRuta(RutaDocumento::absoluta($rutaCompleta) === str_replace(['\\', '/'], $S, $rutaCompleta),
    'una ruta que quedó completa se devuelve tal cual, no se le antepone la raíz');

// ── Lo que está fuera de la carpeta compartida se deja en paz ─────────
$ajena = 'C:\\Users\\ana\\Escritorio\\suelto.xml';
verificaRuta(RutaDocumento::relativa($ajena) === str_replace(['\\', '/'], $S, $ajena),
    'un archivo fuera de la carpeta compartida se conserva completo, no se recorta');
verificaRuta(RutaDocumento::dentroDeRaiz($ajena) === false,
    'y se reconoce como fuera de la carpeta compartida');
verificaRuta(RutaDocumento::dentroDeRaiz($rutaCompleta) === true,
    'lo que sí está adentro se reconoce como adentro');

// Un prefijo parecido no es la misma carpeta: "…compartidos2" no está dentro
// de "…compartidos". Sin el separador al comparar, esto pasaría por bueno.
verificaRuta(RutaDocumento::dentroDeRaiz($raiz . '2\\x.xml') === false,
    'una carpeta con nombre parecido no cuenta como dentro de la compartida');

// ── Mayúsculas: Windows no distingue, la comparación tampoco ──────────
verificaRuta(RutaDocumento::relativa(strtoupper($raiz) . '\\2026\\a.xml') === '2026/a.xml',
    'la raíz se reconoce aunque venga en otras mayúsculas (Windows no distingue)');

// ── Vacíos y nulos: nunca convertirlos en "la raíz a secas" ───────────
verificaRuta(RutaDocumento::relativa('') === '', 'una ruta vacía sigue vacía al guardar');
verificaRuta(RutaDocumento::absoluta('') === '', 'una ruta vacía sigue vacía al leer');
verificaRuta(RutaDocumento::absoluta(null) === '', 'un nulo no se convierte en la carpeta raíz');

// ── Sin carpeta configurada: la aplicación no debe fingir que sí hay ──
RutaDocumento::fijarRaiz('');
verificaRuta(RutaDocumento::absoluta($relativa) === '',
    'sin carpeta configurada no se inventa una ruta: se responde "no disponible"');
verificaRuta(RutaDocumento::relativa($rutaCompleta) === $rutaCompleta,
    'sin carpeta configurada tampoco se recorta nada (se perdería la ubicación)');
RutaDocumento::fijarRaiz($raiz);

// ── Hidratar filas: lo que hace Model al leer de la base ──────────────
$filas = [
    ['id' => 1, 'ruta_xml' => $relativa,  'ruta_pdf' => null],
    ['id' => 2, 'ruta_xml' => '',         'ruta_pdf' => '2026/07 JULIO/x.pdf'],
];
$hidratadas = RutaDocumento::hidratarFilas($filas, ['ruta_xml', 'ruta_pdf']);
verificaRuta($hidratadas[0]['ruta_xml'] === RutaDocumento::absoluta($relativa),
    'al leer, la ruta llega ya expandida al disco de esta computadora');
verificaRuta($hidratadas[0]['ruta_pdf'] === null,
    'una ruta nula se queda nula, no se convierte en la carpeta raíz');
verificaRuta($hidratadas[1]['ruta_xml'] === '',
    'una ruta vacía se queda vacía');
verificaRuta($hidratadas[1]['ruta_pdf'] === RutaDocumento::absoluta('2026/07 JULIO/x.pdf'),
    'se expanden todas las columnas declaradas, no solo la primera');

$sinDeclarar = RutaDocumento::hidratarFilas($filas, []);
verificaRuta($sinDeclarar[0]['ruta_xml'] === $relativa,
    'un modelo que no declara columnas de ruta no sufre ninguna conversión');

// ── Detección de rutas completas ──────────────────────────────────────
foreach (['C:\\x\\y.xml', 'C:/x/y.xml', '\\\\servidor\\carpeta\\y.xml', '/var/docs/y.xml'] as $abs) {
    verificaRuta(RutaDocumento::esAbsoluta($abs), "se reconoce como ruta completa: {$abs}");
}
foreach (['2026/07/a.xml', '2026\\07\\a.xml', 'a.xml'] as $rel) {
    verificaRuta(!RutaDocumento::esAbsoluta($rel), "se reconoce como ruta relativa: {$rel}");
}

RutaDocumento::olvidarRaiz();

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: las rutas se guardan relativas y la misma fila abre en cualquier computadora\n";
