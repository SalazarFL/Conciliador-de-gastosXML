<?php
/**
 * A qué factura corrige cada nota y si todavía se puede aplicar.
 *
 * Los casos salen del listado real: 939 notas directas, de las cuales 916
 * traen el consecutivo de su factura y 23 traen el suyo propio.
 */

require_once __DIR__ . '/../app/helpers/AplicacionNotaCredito.php';

function assertApl($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// ------------------------------------------------------------------
// 1. De qué factura habla el número
// ------------------------------------------------------------------

// Directa normal: el consecutivo lleva tipo 01 (factura electrónica).
assertApl(
    '00100001010000012473',
    AplicacionNotaCredito::consecutivoFactura('NC- 17-1-00100001010000012473-684'),
    'la directa nombra la factura que corrige'
);

// El mismo formato de las notas de costo, que hoy no se usan pero traen el
// consecutivo igual: el día que entren no hace falta código nuevo.
assertApl(
    '00700001010000546576',
    AplicacionNotaCredito::consecutivoFactura('NC- 1-1-D-00700001010000546576-307'),
    'la de costo también nombra su factura'
);

// Tipo 03 = nota de crédito: ese número es el de la nota, no el de ninguna
// factura. Son 23 en el listado real. Sin mirar el tipo se buscarían como si
// fueran facturas y engancharían con la que casualmente terminara igual.
assertApl(
    null,
    AplicacionNotaCredito::consecutivoFactura('NC- 00100001030002690469'),
    'un consecutivo de nota NO es una referencia a factura'
);

// Las de cambio no nombran ninguna: 545 de 545 en el listado real. No es un
// fallo de lectura, es que una nota de cambio va contra cualquier factura.
assertApl(null, AplicacionNotaCredito::consecutivoFactura('NC- 17-1-132-0'),
    'una nota de cambio no nombra factura');
assertApl(null, AplicacionNotaCredito::consecutivoFactura('NC- 4945'),
    'un ajuste tampoco');

// Si el documento arrastra los dos números pegados, gana el de la factura.
assertApl(
    '00100001010000012473',
    AplicacionNotaCredito::consecutivoFactura('NC- 00100001030002690469 00100001010000012473'),
    'entre varios consecutivos se elige el de la factura'
);

// ------------------------------------------------------------------
// 2. En qué situación queda la nota
// ------------------------------------------------------------------

$viva = ['saldo' => 250000.00];
$enCero = ['saldo' => 0.00];
$doc = 'NC- 17-1-00100001010000012473-684';

// El caso que sirve: hay nota y hay factura contra la cual descontarla.
assertApl(
    AplicacionNotaCredito::APLICABLE,
    AplicacionNotaCredito::estado('directa', $doc, 42219.63, $viva),
    'nota con saldo + factura con saldo = lista para aplicar'
);

// La factura ya se pagó completa. Es una situación normal —la nota se puede
// aplicar a otra factura del proveedor—, así que informa y no alarma.
assertApl(
    AplicacionNotaCredito::FACTURA_LIQUIDADA,
    AplicacionNotaCredito::estado('directa', $doc, 42219.63, $enCero),
    'nota con saldo + factura en cero = su factura ya está liquidada'
);
assertApl(false, AplicacionNotaCredito::avisaAlPagar(AplicacionNotaCredito::FACTURA_LIQUIDADA),
    'esa situación no tiene por qué frenar a nadie: es normal');
assertApl(true, AplicacionNotaCredito::avisaAlPagar(AplicacionNotaCredito::APLICABLE),
    'la que sí avisa al pagar es la que todavía se puede aplicar');

// Una nota sin saldo ya la aplicó el ERP: no hay nada que decidir, aunque su
// factura siga viva. Son 299 en el listado real y no deben hacer ruido.
assertApl(
    AplicacionNotaCredito::APLICADA,
    AplicacionNotaCredito::estado('directa', $doc, 0.0, $viva),
    'una nota sin saldo ya fue aplicada'
);
assertApl(
    AplicacionNotaCredito::APLICADA,
    AplicacionNotaCredito::estado('directa', $doc, 0.004, $enCero),
    'medio centavo no es saldo'
);

// Nombra una factura que no está en el listado del ERP.
assertApl(
    AplicacionNotaCredito::SIN_FACTURA,
    AplicacionNotaCredito::estado('directa', $doc, 42219.63, null),
    'la factura que nombra no está cargada'
);

// Trae su propio número: no se puede saber contra qué va sin abrir el XML.
assertApl(
    AplicacionNotaCredito::SIN_REFERENCIA,
    AplicacionNotaCredito::estado('directa', 'NC- 00100001030002690469', 5000.0, null),
    'no dice a qué factura va'
);

// Las clases que no apuntan a una factura no entran en este análisis. Las de
// costo quedan fuera por decisión de negocio, no porque no se pueda.
foreach (['cambio', 'ajuste', 'costo', 'revisar'] as $clase) {
    assertApl(
        AplicacionNotaCredito::NO_APLICA,
        AplicacionNotaCredito::estado($clase, $doc, 42219.63, $viva),
        "la clase {$clase} no se cruza contra una factura"
    );
}

// ------------------------------------------------------------------
// 3. La clave con la que se cruza contra el ERP
// ------------------------------------------------------------------

assertApl('00012473', AplicacionNotaCredito::clavesCorta('00100001010000012473'),
    'se cruza por los ocho dígitos finales, como el resto del sistema');

// ------------------------------------------------------------------
// 4. Todo estado tiene nombre y color
// ------------------------------------------------------------------

foreach (AplicacionNotaCredito::ESTADOS as $estado => $info) {
    assertApl(true, is_string(AplicacionNotaCredito::etiqueta($estado)) && $info[0] !== '',
        "el estado {$estado} tiene etiqueta");
    assertApl(true, in_array(AplicacionNotaCredito::color($estado), ['ok', 'aviso', 'neutro'], true),
        "el estado {$estado} tiene un color conocido");
}

echo "OK: AplicacionNotaCredito\n";
