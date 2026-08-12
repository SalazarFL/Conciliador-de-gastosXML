<?php
require_once __DIR__ . '/../app/helpers/ClaseNotaCredito.php';

function assertSameClase($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nEsperado: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Los cuatro formatos reales del reporte, tomados de la base.
$casos = [
    // Directas: el número largo es el consecutivo de la factura que corrigen.
    'NC- 17-1-00100001010000012473-684'  => ClaseNotaCredito::DIRECTA,
    'NC- 1-1-99900001010000670607-189'   => ClaseNotaCredito::DIRECTA,
    'NC- 1-1-00100006010004835954-1046'  => ClaseNotaCredito::DIRECTA,
    'NC- 00100001030002690469'           => ClaseNotaCredito::DIRECTA,

    // De costo: la D va suelta entre guiones. Se comprueba antes que el
    // consecutivo, porque estas también lo llevan.
    'NC- 1-1-D-99900001010000670607-189' => ClaseNotaCredito::COSTO,
    'NC- 17-1-D-00100002010000008252-683' => ClaseNotaCredito::COSTO,

    // De cambio: cortas, con guiones y sin consecutivo.
    'NC- 17-1-132-0'                     => ClaseNotaCredito::CAMBIO,
    'NC- 1-2-75-0'                       => ClaseNotaCredito::CAMBIO,
    'NC- 17-1-44-0'                      => ClaseNotaCredito::CAMBIO,

    // De ajuste interno: número pelado, con o sin "NC" intercalado.
    'NC- 4945'                           => ClaseNotaCredito::AJUSTE,
    'NC- 45792'                          => ClaseNotaCredito::AJUSTE,
    'NC- 26'                             => ClaseNotaCredito::AJUSTE,
    'NC- 00NC336241'                     => ClaseNotaCredito::AJUSTE,
    'NC- 0000NC2954'                     => ClaseNotaCredito::AJUSTE,
    'NC- 000NC18213'                     => ClaseNotaCredito::AJUSTE,

    // Basura conocida: el reporte parte la celda y pega el nombre del
    // proveedor al número. Se limpia y se clasifica por lo que quedó.
    'NC- 4017Grupo BM SP S.A'            => ClaseNotaCredito::AJUSTE,
    'NC- 1-2-68-0Grupo BM SP S.A'        => ClaseNotaCredito::CAMBIO,

    // Lo que no se reconoce se manda a revisar, nunca se descarta callado.
    'NC- 000NCX3125'                     => ClaseNotaCredito::REVISAR,
    'NC- NC22973.12'                     => ClaseNotaCredito::REVISAR,
    ''                                   => ClaseNotaCredito::REVISAR,
    'NC-'                                => ClaseNotaCredito::REVISAR,
];

foreach ($casos as $documento => $esperada) {
    assertSameClase(
        $esperada,
        ClaseNotaCredito::clasificar($documento),
        "clasificación de \"{$documento}\""
    );
}

// La D de costo no se debe confundir con la D de una letra pegada.
assertSameClase(
    ClaseNotaCredito::AJUSTE,
    ClaseNotaCredito::clasificar('NC- 4017DIST'),
    'una palabra de cuatro letras al final se limpia, no convierte en nota de costo'
);

// Solo tres clases se persiguen.
assertSameClase(true, ClaseNotaCredito::llevaRespaldo('NC- 17-1-00100001010000012473-684'), 'la directa lleva respaldo');
assertSameClase(true, ClaseNotaCredito::llevaRespaldo('NC- 1-1-D-99900001010000670607-189'), 'la de costo lleva respaldo');
assertSameClase(true, ClaseNotaCredito::llevaRespaldo('NC- 17-1-132-0'), 'la de cambio lleva respaldo');
assertSameClase(false, ClaseNotaCredito::llevaRespaldo('NC- 4945'), 'la de ajuste no lleva respaldo');
assertSameClase(false, ClaseNotaCredito::llevaRespaldo('NC- 000NCX3125'), 'lo que está por revisar no se persigue todavía');

echo "ClaseNotaCreditoTest OK\n";
