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

// Las notas de costo traen el consecutivo en el mismo formato, y se cruzan
// por el mismo camino.
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

// Los ajustes y lo que no se pudo clasificar no se aplican contra facturas.
foreach (['ajuste', 'revisar'] as $clase) {
    assertApl(
        AplicacionNotaCredito::NO_APLICA,
        AplicacionNotaCredito::estado($clase, $doc, 42219.63, $viva),
        "la clase {$clase} no se cruza contra una factura"
    );
}

// Las de costo se cruzan como las directas, pero solo mientras les queden
// más de ₡10.000 por aplicar: es contra el saldo, no contra el monto.
$docCosto = 'NC- 1-1-D-00700001010000546576-307';
assertApl(
    AplicacionNotaCredito::APLICABLE,
    AplicacionNotaCredito::estado('costo', $docCosto, 10000.01, $viva),
    'una de costo con más de ₡10.000 de saldo es aplicable'
);
assertApl(
    AplicacionNotaCredito::NO_APLICA,
    AplicacionNotaCredito::estado('costo', $docCosto, 10000.00, $viva),
    'una de costo con ₡10.000 justos no cuenta: tiene que ser MAYOR'
);
assertApl(
    AplicacionNotaCredito::NO_APLICA,
    AplicacionNotaCredito::estado('costo', $docCosto, 3000.0, $viva),
    'una de costo de ₡15.000 a la que le quedan ₡3.000 ya no cuenta'
);
assertApl(
    AplicacionNotaCredito::APLICADA,
    AplicacionNotaCredito::estado('costo', $docCosto, 0.0, $viva),
    'una de costo sin saldo sigue diciendo que ya se aplicó, no "—"'
);
assertApl(
    AplicacionNotaCredito::FACTURA_LIQUIDADA,
    AplicacionNotaCredito::estado('costo', $docCosto, 25000.0, $enCero),
    'una de costo grande cuya factura ya está en cero'
);
assertApl(
    AplicacionNotaCredito::SIN_FACTURA,
    AplicacionNotaCredito::estado('costo', $docCosto, 25000.0, null),
    'una de costo grande cuya factura no está cargada'
);

// Las de cambio no nombran factura: valen para cualquiera del proveedor, del
// monto que sean. Lo único que importa es si el proveedor tiene alguna con
// saldo donde aplicarla.
assertApl(
    AplicacionNotaCredito::APLICABLE,
    AplicacionNotaCredito::estado('cambio', 'NC- 17-1-132-0', 150.0, null, true),
    'una de cambio chica es aplicable si el proveedor tiene facturas con saldo'
);
assertApl(
    AplicacionNotaCredito::FACTURA_LIQUIDADA,
    AplicacionNotaCredito::estado('cambio', 'NC- 17-1-132-0', 57239.27, null, false),
    'una de cambio sin factura con saldo del proveedor no tiene dónde aplicarse'
);
assertApl(
    AplicacionNotaCredito::APLICADA,
    AplicacionNotaCredito::estado('cambio', 'NC- 17-1-132-0', 0.0, null, true),
    'una de cambio sin saldo ya se aplicó'
);

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
