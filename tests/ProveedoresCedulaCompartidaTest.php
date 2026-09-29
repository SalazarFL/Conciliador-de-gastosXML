<?php
/**
 * Los códigos que comparten cédula entran todos al catálogo.
 *
 * Antes la importación obligaba a elegir uno por cédula y dejaba fuera a los
 * demás. Pero CEDI, PROMOCIONES y TECNOLOGIA Y FRIO son puntos de venta de la
 * misma empresa, y un código que no está en el catálogo no engancha con
 * nada: el 28/09 eran 44 facturas de un pago con su XML en la base, mismo
 * consecutivo y mismo monto. Ahora entran todos y se desmarca solo el alta
 * duplicada.
 *
 * Sin base de datos: solo la decisión de qué filas pasan a la importación.
 */

require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/ConfiguracionController.php';
require_once __DIR__ . '/../app/helpers/ProveedoresErpCsvParser.php';

$fallos = 0;
function compartida($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) { fwrite(STDERR, "FAIL: {$mensaje}\n"); $fallos++; }
}

$csv = implode("\r\n", [
    '"","","","","","","","Grupo BM SP S.A","","","","","","",""',
    '"","","","","","","","Lista de Proveedores","","","","","","",""',
    '"","140000585","","3101639680","GRUPO BM SP SA CEDI","21012868","","","","30","","","",""',
    '"","140000755","","3101639680","GRUPO BM SP SA PROMOCIONES","2773-4395","","","","30","","","",""',
    '"","140001752","","3101639680","GRUPO BM SP SA TECNOLOGIA","2773-4395","","","","30","","","",""',
    '"","140000118","","3101111111","ALPELMUSA S.A","","","","","30","","","",""',
    '"","140000119","","3101111111","ALPEMUSA S.A.","","","","","30","","","",""',
    '"","140000003","","3101011167","","3-101-011167 S.A.",".","",".","","30","","","",""',
]);
$lectura = ProveedoresErpCsvParser::parseTexto($csv, 'lista.csv');
compartida(count($lectura['cedulas_compartidas']) === 2, 'el archivo trae dos cédulas repartidas');

$controlador = (new ReflectionClass('ConfiguracionController'))->newInstanceWithoutConstructor();
$resolver = new ReflectionMethod('ConfiguracionController', 'resolverCedulasCompartidas');
$resolver->setAccessible(true);

/** Los códigos que pasan a la importación con este formulario. */
function importados($controlador, $resolver, array $lectura, array $post)
{
    $_POST = $post;
    $codigos = array_column($resolver->invoke($controlador, $lectura), 'codigo');
    sort($codigos);
    return $codigos;
}

$todos = ['140000003', '140000118', '140000119', '140000585', '140000755', '140001752'];

// ── 1. Con todo marcado, que es como llega la pantalla, entran todos ───────
$r = importados($controlador, $resolver, $lectura, ['incluir' => [
    '3101639680' => ['', '140000585', '140000755', '140001752'],
    '3101111111' => ['', '140000118', '140000119'],
]]);
compartida($r === $todos, 'todos marcados: entran los seis ' . json_encode($r));

// ── 2. Un formulario que no dice nada de las cédulas: entran todos ─────────
$r = importados($controlador, $resolver, $lectura, []);
compartida($r === $todos, 'sin decir nada, no se descarta ninguno ' . json_encode($r));

// ── 3. Se desmarca el alta duplicada ──────────────────────────────────────
$r = importados($controlador, $resolver, $lectura, ['incluir' => [
    '3101639680' => ['', '140000585', '140000755', '140001752'],
    '3101111111' => ['', '140000118'],
]]);
compartida(!in_array('140000119', $r, true), 'el desmarcado no entra');
compartida(in_array('140000118', $r, true), 'su hermano sí');
compartida(count($r) === 5, 'y el resto entra igual ' . json_encode($r));

// ── 4. Una cédula no puede quedarse sin ningún código ─────────────────────
$error = '';
try {
    importados($controlador, $resolver, $lectura, ['incluir' => [
        '3101639680' => [''],
        '3101111111' => ['', '140000118'],
    ]]);
} catch (Exception $e) {
    $error = $e->getMessage();
}
compartida(strpos($error, '3101639680') !== false,
    'una cédula sin ningún código marcado no se importa: ' . $error);

// ── 5. Un código que no es de esa cédula no se cuela ──────────────────────
$error = '';
try {
    importados($controlador, $resolver, $lectura, ['incluir' => [
        '3101639680' => ['', '999999999'],
    ]]);
} catch (Exception $e) {
    $error = $e->getMessage();
}
compartida($error !== '', 'marcar un código ajeno a la cédula es como no marcar ninguno');

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} fallo(s)\n");
    exit(1);
}
echo "OK: los códigos que comparten cédula entran todos\n";
