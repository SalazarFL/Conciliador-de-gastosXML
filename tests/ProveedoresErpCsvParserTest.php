<?php
/**
 * El listado "Lista de Proveedores" del ERP: lo único que dice sin adivinar a
 * qué cédula pertenece cada código.
 *
 * El fixture reproduce las trampas del archivo real de 1.696 proveedores:
 * columnas que se corren de una fila a otra, el pie de página cada tantas
 * líneas, el punto que el ERP imprime donde no hay dato, una cédula escrita
 * con guiones, sociedades que se llaman por su propia cédula, y varios
 * códigos colgando de la misma cédula —que NO es un error: es una sociedad
 * con varios puntos de venta—.
 */
require_once __DIR__ . '/../app/helpers/ProveedoresErpCsvParser.php';

function assertProv($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertProvIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

/** Una fila del reporte: celdas sueltas en las posiciones que las pone el ERP. */
function filaProv(array $celdas, $ancho = 15)
{
    $f = array_fill(0, $ancho, '');
    foreach ($celdas as $i => $v) { $f[$i] = $v; }
    return '"' . implode('","', $f) . '"';
}

$texto = implode("\r\n", [
    filaProv([7 => 'Grupo BM SP S.A']),
    filaProv([7 => 'Lista de Proveedores']),
    filaProv([2 => 'Código', 3 => 'Cédula', 5 => 'Nombre', 6 => 'Teléfono', 12 => 'Email']),
    filaProv([2 => 'Grupo:', 4 => 'Comerciales']),

    // Columna del nombre en la 5 (como la trae el archivo real en unas filas).
    filaProv([1 => '140000003', 3 => '3101011167', 5 => '3-101-011167 S.A.', 6 => '.', 10 => '30', 12 => 'st_cald91@hotm']),
    // Y en la 4 en otras: por eso no se puede leer por posición.
    filaProv([1 => '140001222', 3 => '110470877', 4 => 'HIDALGO ABARCA OLMAN', 5 => '.', 9 => '30'], 14),
    // Cédula escrita con guiones: sin limpiarla, este proveedor se quedaba sin
    // cédula y con el nombre corrido de columna.
    filaProv([1 => '140000741', 3 => '3-101-155686', 4 => 'MAR PROFUNDO INTERNACIONAL'], 14),
    // Sin cédula: no se puede anclar, pero tampoco se pierde en silencio.
    filaProv([1 => '140001068', 4 => 'CLARO RECARGAS'], 14),

    // El pie se imprime cada tantas filas y no es un proveedor.
    filaProv([0 => 'Usuario: fcarrera', 7 => 'Página: 1', 14 => 'Impreso: 10/09/2026 23:12:22']),

    // Tres códigos de la misma cédula: una sociedad con tres puntos de venta.
    filaProv([1 => '140000585', 3 => '3101639680', 4 => 'GRUPO BM SP SA CEDI', 9 => '30'], 14),
    filaProv([1 => '140001688', 3 => '3101639680', 4 => 'GRUPO BM SP SA MAYOREO SV', 9 => '30'], 14),
    filaProv([1 => '140000755', 3 => '3101639680', 4 => 'GRUPO BM SP SA PROMOCIONES', 9 => '30'], 14),

    // Cédula DIMEX de residente extranjero: doce dígitos, es válida.
    filaProv([1 => '140001170', 3 => '115600435025', 4 => 'FENG ZHIYONG'], 14),
]) . "\r\n";

$r = ProveedoresErpCsvParser::parseTexto($texto, 'proveedores.csv');

// ── Lectura ──
assertProv($r['ok'], 'el archivo se tiene que poder leer');
assertProvIgual([], $r['errores'], 'sin errores');
assertProvIgual(7, count($r['proveedores']), 'proveedores con cédula');
assertProvIgual([], $r['no_reconocidas'], 'los títulos y el pie no son filas ilegibles');
assertProvIgual(['Comerciales'], $r['meta']['grupos'], 'el grupo que trae el archivo');
assertProvIgual('2026-09-10 23:12:22', $r['meta']['impreso_en'], 'fecha de impresión del pie');

$porCodigo = [];
foreach ($r['proveedores'] as $p) { $porCodigo[$p['codigo']] = $p; }

// ── La columna se corre de fila en fila: hay que leer por patrón ──
assertProvIgual('3101011167', $porCodigo['140000003']['cedula'], 'cédula con el nombre en la celda 5');
assertProvIgual('3-101-011167 S.A.', $porCodigo['140000003']['nombre'],
    'una sociedad que se llama por su cédula no puede confundir el nombre con la cédula');
assertProvIgual('110470877', $porCodigo['140001222']['cedula'], 'cédula con el nombre en la celda 4');
assertProvIgual('HIDALGO ABARCA OLMAN', $porCodigo['140001222']['nombre'], 'nombre con las columnas corridas');

// ── La cédula con guiones ──
assertProvIgual('3101155686', $porCodigo['140000741']['cedula'], 'la cédula con guiones se limpia');
assertProvIgual('MAR PROFUNDO INTERNACIONAL', $porCodigo['140000741']['nombre'],
    'y el nombre no se corre de columna por culpa de ella');

// ── DIMEX ──
assertProvIgual('115600435025', $porCodigo['140001170']['cedula'], 'una DIMEX de doce dígitos es válida');

// ── Sin cédula: fuera del listado, pero contado ──
assertProv(!isset($porCodigo['140001068']), 'sin cédula no hay nada que enseñarle al mapa');
assertProvIgual(1, count($r['sin_cedula']), 'pero se cuenta para poder decirlo');
assertProvIgual('140001068', $r['sin_cedula'][0]['codigo'], 'y se dice cuál es');

// ── Varios códigos, una cédula: se aprenden TODOS ──
//
// Es el caso de una sociedad con varios puntos de venta. Quedarse con uno
// dejaría a los otros dos sin guarda, que es justo lo que hay que evitar.
assertProvIgual(1, count($r['cedulas_compartidas']), 'una cédula con más de un código');
assertProvIgual(3, count($r['cedulas_compartidas']['3101639680']), 'los tres puntos de venta');
foreach (['140000585', '140001688', '140000755'] as $cod) {
    assertProvIgual('3101639680', $porCodigo[$cod]['cedula'], "el código {$cod} cuelga de la misma cédula");
}

// ── El punto es "vacío", no un dato ──
foreach ($r['proveedores'] as $p) {
    assertProv($p['nombre'] !== '.' && $p['cedula'] !== '.', 'el punto del ERP no puede entrar como dato');
}

// ── Un archivo que no es este reporte ──
$malo = ProveedoresErpCsvParser::parseTexto("una,cosa,cualquiera\n1,2,3\n", 'otro.csv');
assertProv(!$malo['ok'], 'un CSV ajeno no puede darse por bueno');
assertProvIgual([], $malo['proveedores'], 'y no produce proveedores');

echo "OK: ProveedoresErpCsvParser\n";
