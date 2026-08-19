<?php
/**
 * Con qué se manda a buscar cada renglón en el correo.
 *
 * El número de una nota de crédito directa es el consecutivo de la FACTURA
 * que corrige, no el de la nota: buscarlo abría siempre el correo equivocado.
 * Aquí se fija que la nota nunca busque por ese número —solo por el suyo
 * propio si lo trae, y si no por proveedor y fecha— y que la factura del ERP
 * siga buscándose por el suyo, que sí es el suyo.
 */
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/controllers/SeguimientoController.php';

function assertBusqueda($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$clase = new ReflectionClass('SeguimientoController');
$controlador = $clase->newInstanceWithoutConstructor();
$metodo = $clase->getMethod('decorarBusqueda');
$metodo->setAccessible(true);

$decorar = function (array $fila) use ($controlador, $metodo) {
    $argumentos = [&$fila];
    $metodo->invokeArgs($controlador, $argumentos);
    return $fila;
};

// 1. Nota directa sin consecutivo propio: el 62755 del documento es de la
//    factura corregida y no puede acabar en el buscador del correo.
$nota = $decorar([
    'origen' => 'nota_credito',
    'documento' => 'NC- 1-1-00100001010000062755-71',
    'nc_proveedor' => null,
    'proveedor' => 'MARJAVA SUPERMERCADOS S.A',
    'fecha' => '2026-06-08',
]);
assertBusqueda($nota['busqueda_por'] === 'proveedor',
    'una nota sin consecutivo propio se busca por proveedor');
assertBusqueda(strpos($nota['busqueda'], '62755') === false,
    'el número de la factura corregida no viaja al buscador del correo');
assertBusqueda($nota['busqueda'] === 'MARJAVA SUPERMERCADOS',
    'el proveedor va sin el sufijo societario, que no aparece en el correo');
assertBusqueda($nota['busqueda_fecha'] === '08/06/2026',
    'la fecha de la nota acota la búsqueda a los días de alrededor');

// 2. La misma nota con el consecutivo del proveedor: ese sí la identifica.
$conNumero = $decorar([
    'origen' => 'nota_credito',
    'documento' => 'NC- 1-1-00100001010000062755-71',
    'nc_proveedor' => '00NC336241',
    'proveedor' => 'MARJAVA SUPERMERCADOS S.A',
    'fecha' => '2026-06-08',
]);
assertBusqueda($conNumero['busqueda_por'] === 'numero',
    'con consecutivo propio la nota vuelve a buscarse por número');
assertBusqueda($conNumero['busqueda'] === '336241',
    'se busca el consecutivo de la nota, no el de la factura');
assertBusqueda($conNumero['busqueda_fecha'] === '',
    'una búsqueda por número no necesita acotarse por fecha');

// 3. La factura del ERP no cambia: su número es el suyo.
$factura = $decorar([
    'origen' => 'factura',
    'documento' => 'FACT-01400020010000005061-3',
    'nc_proveedor' => null,
    'proveedor' => 'MARJAVA SUPERMERCADOS S.A',
    'fecha' => '2026-06-08',
]);
assertBusqueda($factura['busqueda_por'] === 'numero' && $factura['busqueda'] === '5061',
    'la factura sigue buscándose por su propio número');

// 4. Sin proveedor no queda nada que buscar: la vista no debe pintar el botón.
$huerfana = $decorar([
    'origen' => 'nota_credito',
    'documento' => 'NC- 1-1-00100001010000062755-71',
    'nc_proveedor' => '',
    'proveedor' => '',
    'fecha' => '',
]);
assertBusqueda($huerfana['busqueda'] === '',
    'sin proveedor ni consecutivo propio no se ofrece búsqueda');

echo "OK SeguimientoBusquedaCorreoTest\n";
