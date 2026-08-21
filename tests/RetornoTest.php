<?php
/**
 * A dónde vuelve el botón "Volver" del detalle de un documento.
 *
 * Al detalle se entra desde cuatro pantallas, así que el destino sale del
 * Referer. Y como el Referer lo escribe el navegador de quien pide la página y
 * lo que se devuelve termina en un href, la mitad de lo que se comprueba acá
 * es lo que NO se acepta: otro servidor, otra aplicación del mismo servidor, o
 * una ruta que parece relativa y salta de host.
 */
require_once __DIR__ . '/../app/helpers/Retorno.php';

function assertRetorno($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$base = '/xmlconcilia/public/';
$aqui = '/xmlconcilia/public/facturas/ver/5877';

/** Una petición al detalle, llegando desde $desde. */
function peticion($desde, $aqui)
{
    $server = ['HTTP_HOST' => 'auxiliar-06c', 'REQUEST_URI' => $aqui];
    if ($desde !== null) {
        $server['HTTP_REFERER'] = $desde;
    }
    return $server;
}

// ── Se vuelve a donde se venía, con los filtros puestos ──────────
// La cadena de consulta es lo que hace que volver sea volver: sin ella se
// aterriza en el listado de siempre, en la página uno y sin filtros.
$r = Retorno::anterior(
    peticion('http://auxiliar-06c/xmlconcilia/public/por-pagar?listado_id=7&estado=sin_respaldo', $aqui),
    $base,
    '/facturas'
);
assertRetorno($r['url'] === '/xmlconcilia/public/por-pagar?listado_id=7&estado=sin_respaldo',
    'vuelve al checklist del pago tal como estaba');
assertRetorno($r['titulo'] === 'Volver al pago semanal', 'y el tooltip nombra la pantalla');

// ── Sin Referer: el destino por omisión ─────────────────────────
// Pasa de verdad: un marcador guardado, un navegador que no lo manda.
$r = Retorno::anterior(peticion(null, $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    'sin saber de dónde se vino queda el listado del módulo');

$r = Retorno::anterior(peticion('', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas', 'un Referer vacío es no tener Referer');

// ── Otro servidor: no ─────────────────────────────────────────────
$r = Retorno::anterior(peticion('http://sitio-ajeno.com/trampa', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    'un Referer de otro host no decide a dónde va un enlace nuestro');

// ── Misma máquina, otra aplicación: tampoco ──────────────────────
$r = Retorno::anterior(peticion('http://auxiliar-06c/otra-app/panel', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    'lo que está fuera de la aplicación no es una pantalla anterior');

// Y el prefijo tiene que ser una carpeta completa, no un pedazo de nombre.
$r = Retorno::anterior(peticion('http://auxiliar-06c/xmlconcilia/publico-falso/x', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    '"/xmlconcilia/publico-falso" no cuenta como dentro de "/xmlconcilia/public"');

// ── La ruta que salta de host disfrazada de relativa ─────────────
// "//sitio-ajeno.com/x" es una ruta para parse_url y un cambio de servidor
// para el navegador.
$r = Retorno::anterior(peticion('//sitio-ajeno.com/xmlconcilia/public/facturas', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    'una ruta que empieza en // no llega al href');

// ── Volver a donde ya se está no es volver ──────────────────────
// Se recarga el detalle o se llega desde otro detalle: "Volver" no puede
// quedarse dando vueltas en la misma pantalla.
$r = Retorno::anterior(peticion('http://auxiliar-06c' . $aqui, $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/facturas',
    'el Referer a la propia pantalla se descarta');

// ── Los nombres de pantalla no se pisan entre sí ─────────────────
// "/facturas" es prefijo de "/facturas-erp" y "/notas-credito" empieza igual
// que "/notas-xml": el más largo tiene que ganar.
$casos = [
    '/facturas-erp'                  => 'Volver a Facturas',
    '/facturas?pagina=2'             => 'Volver a Facturas XML',
    '/notas-credito?listado_id=3'    => 'Volver a Notas de crédito',
    '/notas-xml'                     => 'Volver a Notas de crédito XML',
    '/devoluciones/detalle/12'       => 'Volver a Devoluciones',
    '/seguimiento'                   => 'Volver a Seguimiento',
    '/correo?buscar=123'             => 'Volver a Correo',
];
foreach ($casos as $ruta => $esperado) {
    $r = Retorno::anterior(peticion('http://auxiliar-06c/xmlconcilia/public' . $ruta, $aqui), $base, '/facturas');
    assertRetorno($r['titulo'] === $esperado,
        "«{$ruta}» se nombra «{$esperado}» y no «{$r['titulo']}»");
}

// Una pantalla que no está en la lista tampoco se queda sin nombre.
$r = Retorno::anterior(peticion('http://auxiliar-06c/xmlconcilia/public/configuracion', $aqui), $base, '/facturas');
assertRetorno($r['url'] === '/xmlconcilia/public/configuracion', 'se vuelve igual');
assertRetorno($r['titulo'] === 'Volver a la pantalla anterior', 'con un nombre genérico');

// ── Instalado en la raíz del servidor ───────────────────────────
// Sin prefijo de carpeta no hay nada contra qué comparar, y todo lo del mismo
// host es de la aplicación.
$r = Retorno::anterior(
    ['HTTP_HOST' => 'nexo', 'REQUEST_URI' => '/facturas/ver/1', 'HTTP_REFERER' => 'http://nexo/por-pagar?listado_id=2'],
    '/',
    '/facturas'
);
assertRetorno($r['url'] === '/por-pagar?listado_id=2', 'funciona instalada en la raíz del servidor');

echo "OK RetornoTest\n";
