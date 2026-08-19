<?php
/**
 * Lo que llega de la tabla al servidor cuando alguien selecciona renglones.
 *
 * Se prueba sin base: Seguimiento::normalizarItems() es lógica pura, y es la
 * puerta por la que entra una selección que puede venir manipulada.
 */

// El modelo extiende Model, que abre conexión al instanciarse. Aquí solo se
// usa un método estático, así que basta con que la clase padre exista.
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertSameSeg($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nEsperado: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Las casillas de la tabla mandan "origen|id".
assertSameSeg(
    [['origen' => 'nota_credito', 'referencia_id' => 12]],
    Seguimiento::normalizarItems(['nota_credito|12']),
    'acepta la forma "origen|id" de las casillas'
);

// El panel de detalle manda el arreglo.
assertSameSeg(
    [['origen' => 'factura', 'referencia_id' => 7]],
    Seguimiento::normalizarItems([['origen' => 'factura', 'referencia_id' => 7]]),
    'acepta la forma de arreglo'
);

// El origen viejo del pago semanal ya no existe: aceptarlo escribiría filas
// que la consulta de la cola nunca vuelve a encontrar.
assertSameSeg(
    [],
    Seguimiento::normalizarItems(['pago_semanal|7']),
    'descarta el origen retirado pago_semanal'
);

// Un renglón repetido escribiría dos anotaciones idénticas en la bitácora.
assertSameSeg(
    [['origen' => 'nota_credito', 'referencia_id' => 5]],
    Seguimiento::normalizarItems(['nota_credito|5', 'nota_credito|5', ['origen' => 'nota_credito', 'referencia_id' => '5']]),
    'quita los repetidos aunque vengan en formatos distintos'
);

// Un origen inventado no debe llegar nunca a la consulta.
assertSameSeg(
    [],
    Seguimiento::normalizarItems(['facturas_erp|9', 'otra_cosa|1', ['origen' => 'usuarios', 'referencia_id' => 1]]),
    'descarta orígenes que no existen'
);

// "12abc" convertido con (int) tocaría el renglón 12, que nadie eligió.
assertSameSeg(
    [],
    Seguimiento::normalizarItems(['nota_credito|12abc', ['origen' => 'nota_credito', 'referencia_id' => '3 OR 1=1']]),
    'descarta ids que no son números en vez de recortarlos'
);

assertSameSeg(
    [],
    Seguimiento::normalizarItems(['nota_credito|0', 'nota_credito|-4', 'nota_credito', '', 'sin_barra']),
    'descarta ids no positivos y cadenas sin la forma esperada'
);

// Una casilla rara no debe tumbar la tanda entera: se descarta y sigue.
assertSameSeg(
    [
        ['origen' => 'nota_credito', 'referencia_id' => 1],
        ['origen' => 'factura', 'referencia_id' => 2],
    ],
    Seguimiento::normalizarItems(['nota_credito|1', 'basura', null, 42, 'factura|2']),
    'lo inválido se descarta sin arrastrar lo válido'
);

// Los estados que la pantalla ofrece tienen que existir en la columna ENUM.
foreach (array_keys(Seguimiento::ESTADOS) as $estado) {
    assertSameSeg(
        true,
        preg_match('/^[a-z_]+$/', $estado) === 1,
        "el estado '{$estado}' tiene una clave usable en SQL"
    );
}

// El estado que se entra a mano y el que borra la marca tienen que existir tal
// como los manda la pantalla: si no coincidieran, los botones no harían nada.
assertSameSeg(
    true,
    isset(Seguimiento::ESTADOS[Seguimiento::ESTADO_A_MANO]),
    'el estado que se pone a mano está declarado en ESTADOS'
);
assertSameSeg(
    false,
    isset(Seguimiento::ESTADOS[Seguimiento::SIN_MARCA]),
    'quitar la marca no es un estado: no puede colarse en las pestañas'
);

echo "OK: SeguimientoItems\n";
