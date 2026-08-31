<?php
/**
 * Buscar un documento por su importe.
 *
 * Lo que se comprueba es lo que hace que sirva para lo que se usa: que acepte
 * el importe tal como se copia de la pantalla —con símbolo y comas de millar—,
 * que encuentre ₡127,725.56 escribiendo 127725, y que lo que no sea un número
 * no filtre nada en vez de buscar basura dentro de la columna.
 *
 * Aquí hubo un filtro "de tanto a tanto" y se fue: quien busca un pago tiene
 * el número delante y quiere encontrar ESE documento, no acotar un tramo.
 *
 * Uso: php tests/BusquedaImporteTest.php
 */
require_once __DIR__ . '/../app/helpers/BusquedaImporte.php';

function assertImporte($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── Lo que se escribe en la caja ───────────────────────────────────────────
assertImporte(BusquedaImporte::numero('1000') === '1000', 'un número pelado se toma tal cual');
assertImporte(BusquedaImporte::numero('1000.50') === '1000.50', 'con decimales también');

// Se copia y se pega de la propia pantalla, donde los importes salen así.
assertImporte(BusquedaImporte::numero('₡370,639,934.06') === '370639934.06',
    'un importe copiado de la pantalla, con símbolo y comas de millar, se entiende');
assertImporte(BusquedaImporte::numero(' 1 200 000 ') === '1200000', 'y con espacios de por medio');
assertImporte(BusquedaImporte::numero('$45.90') === '45.90', 'el símbolo de dólar también');
assertImporte(BusquedaImporte::numero('-500') === '-500', 'un negativo es un número: una diferencia puede serlo');

// Lo que no es un número no filtra: mejor traer de más que buscar dentro de la
// columna equivocada.
assertImporte(BusquedaImporte::numero('') === '', 'una caja vacía no es un criterio');
assertImporte(BusquedaImporte::numero('mil') === '', 'ni una palabra');
assertImporte(BusquedaImporte::numero('12ab') === '', 'ni un número a medio escribir');
assertImporte(BusquedaImporte::numero(['1']) === '', 'ni un arreglo llegado por la URL');
assertImporte(BusquedaImporte::numero('.') === '', 'ni un punto suelto');
assertImporte(BusquedaImporte::numero('-') === '', 'ni un signo suelto');

// Los comodines de SQL no sobreviven a la limpieza: si lo hicieran, un '%' en
// la caja traería el listado entero.
assertImporte(BusquedaImporte::numero('%') === '', 'un comodín no es un importe');
assertImporte(BusquedaImporte::numero('1%') === '', 'ni pegado a un número');

assertImporte(BusquedaImporte::hay('₡1,000') && !BusquedaImporte::hay('  '),
    'se sabe si hay algo con lo que buscar');

// ── La condición SQL ───────────────────────────────────────────────────────
$params = [];
$sql = BusquedaImporte::condicion('e.monto', '127725', $params);
assertImporte($sql === 'CAST(e.monto AS CHAR) LIKE ?', 'se busca dentro del número, no por igualdad');
assertImporte($params === ['%127725%'],
    'y con comodines a los lados: 127725 tiene que encontrar 127725.56');

// Este es el caso de uso: se tiene el importe entero, del estado de cuenta.
$params = [];
BusquedaImporte::condicion('e.monto', '₡127,725.56', $params);
assertImporte($params === ['%127725.56%'],
    'pegar el importe completo con formato encuentra su documento');

$params = [];
$sql = BusquedaImporte::condicion('e.monto', '', $params);
assertImporte($sql === '' && $params === [],
    'sin nada que buscar no se devuelve condición ni se ensucian los parámetros');

$params = [];
$sql = BusquedaImporte::condicion('e.monto', 'lo que sea', $params);
assertImporte($sql === '' && $params === [],
    'y lo que no es un número tampoco filtra');

// La columna puede ser una expresión: el saldo de un pago es un COALESCE.
$params = [];
$sql = BusquedaImporte::condicion('COALESCE(e.saldo_pago, e.saldo)', '500', $params);
assertImporte($sql === 'CAST(COALESCE(e.saldo_pago, e.saldo) AS CHAR) LIKE ?',
    'la columna puede ser una expresión, que es como se guarda el saldo de un pago');

// Cada ? con su valor: es el defecto clásico de armar SQL así.
foreach (['1000', '', 'mil', '₡1,000.50'] as $escrito) {
    $params = [];
    $sql = BusquedaImporte::condicion('c.monto', $escrito, $params);
    assertImporte(substr_count($sql, '?') === count($params),
        "los parámetros cuadran con los marcadores para «{$escrito}»");
}

echo "OK BusquedaImporteTest\n";
