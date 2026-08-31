<?php
/**
 * Cuándo se pregunta de qué proveedor y cuándo no.
 *
 * Lo que se comprueba es lo que hace que esto ahorre en vez de estorbar: que
 * la pregunta salga al abrir un listado grande desde el menú, que "todos" sea
 * una respuesta que hay que dar a propósito, y sobre todo que NO aparezca
 * cuando se llega persiguiendo un documento concreto —ahí ya se sabe qué se
 * busca, y una pregunta en medio del camino es un estorbo.
 *
 * Uso: php tests/AlcanceProveedorTest.php
 */
require_once __DIR__ . '/../app/helpers/AlcanceProveedor.php';

function assertAlcance($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── Abrir el módulo desde el menú ──────────────────────────────────────────
assertAlcance(AlcanceProveedor::hayQuePreguntar([], ''),
    'entrar sin decir nada pregunta antes de traer el listado');

// ── Ya hay proveedor elegido ───────────────────────────────────────────────
// Da igual si viene de la URL o de lo que el módulo recuerda: recordarFiltros
// repone lo guardado en $_GET antes de que esto se pregunte.
assertAlcance(!AlcanceProveedor::hayQuePreguntar(['proveedor' => 'ced:3101337371'], 'ced:3101337371'),
    'con un proveedor elegido no se pregunta');
assertAlcance(!AlcanceProveedor::hayQuePreguntar([], 'cod:1234'),
    'y basta con que el controlador lo haya resuelto, aunque no esté en la URL');
assertAlcance(AlcanceProveedor::hayQuePreguntar(['proveedor' => ''], '  '),
    'un proveedor en blanco no es una elección');

// ── "Todos" es una respuesta, no lo que pasa solo ──────────────────────────
$todos = [AlcanceProveedor::PARAM => AlcanceProveedor::TODOS];
assertAlcance(!AlcanceProveedor::hayQuePreguntar($todos, ''),
    'pedir el listado entero a propósito lo trae entero');
assertAlcance(AlcanceProveedor::pidioTodos($todos),
    'y queda dicho que se pidieron todos');
assertAlcance(AlcanceProveedor::hayQuePreguntar([AlcanceProveedor::PARAM => 'otra cosa'], ''),
    'cualquier otro valor no cuenta como respuesta');
assertAlcance(!AlcanceProveedor::pidioTodos([AlcanceProveedor::PARAM => ['todos']]),
    'ni un arreglo en la URL, que ni siquiera es un valor');

// ── Persiguiendo un documento no se pregunta ───────────────────────────────
// Se llega con el botón "buscar en los XML cargados" de la cola de
// seguimiento o del pago semanal: la tarjeta ya dice qué documento se busca y
// el buscador trae su número. Preguntar aquí sería cortar el camino.
assertAlcance(!AlcanceProveedor::hayQuePreguntar(['ctx' => 'seguimiento'], ''),
    'viniendo de la cola de seguimiento no se pregunta');
assertAlcance(!AlcanceProveedor::hayQuePreguntar(['ctx' => 'pago', 'ctx_item' => '12'], ''),
    'ni viniendo del pago semanal');
assertAlcance(!AlcanceProveedor::hayQuePreguntar(['pp_listado' => '5'], ''),
    'ni por los enlaces viejos del pago, que siguen andando');
assertAlcance(!AlcanceProveedor::hayQuePreguntar(['ctx_item' => 'factura:40'], ''),
    'ni con solo el documento visible, que es lo que deja app.js en la URL');

// Un 'ctx' vacío no es venir de ningún sitio.
assertAlcance(AlcanceProveedor::hayQuePreguntar(['ctx' => '', 'ctx_item' => ''], ''),
    'un contexto vacío no cuenta como venir persiguiendo nada');
assertAlcance(AlcanceProveedor::hayQuePreguntar(['ctx' => []], ''),
    'ni uno que llega como arreglo');

echo "OK AlcanceProveedorTest\n";
