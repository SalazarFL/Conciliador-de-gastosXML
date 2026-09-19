<?php
/**
 * El progreso que una tarea larga deja para quien la está esperando.
 *
 * Lo que se prueba acá es sobre todo que no haga daño: el progreso es un
 * extra —si se pierde, la carga sigue igual— así que ninguna de sus fallas
 * puede propagarse a la tarea que lo reporta. Y el token llega por la URL y
 * termina siendo un nombre de archivo, así que tiene que ser imposible que
 * apunte fuera de su carpeta.
 */
require_once __DIR__ . '/../app/helpers/ProgresoTarea.php';

function assertProg($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertProgIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$pasos = ['Preparando', 'Leyendo', 'Asignando', 'Emparejando', 'Acomodando'];
$token = bin2hex(random_bytes(16));

try {
    // ── Nace en la primera fase, sin que nadie se lo pida ──
    // Importa: entre que la pantalla aparece y la tarea reporta algo hay un
    // hueco, y sin este primer escrito la pantalla no tendría qué mostrar.
    $p = ProgresoTarea::para($token, $pasos);
    $e = ProgresoTarea::leer($token);
    assertProg($e !== null, 'abrir el progreso ya deja algo que leer');
    assertProgIgual('corriendo', $e['estado'], 'arranca corriendo');
    assertProgIgual(1, $e['paso'], 'en la primera fase');
    assertProgIgual(5, $e['de'], 'y sabe cuántas son en total');
    assertProgIgual('Preparando', $e['etiqueta'], 'con el nombre de la fase');

    // ── Avanza, y el detalle viaja con la fase ──
    $p->paso('Leyendo', '529 facturas');
    $e = ProgresoTarea::leer($token);
    assertProgIgual(2, $e['paso'], 'avanzó a la segunda');
    assertProgIgual('529 facturas', $e['detalle'], 'el detalle es lo que hace útil la fase');

    // Agregar detalle no avanza: la fase larga puede contar cosas sin mentir
    // sobre cuánto falta.
    $p->detalle('312 comprobantes');
    $e = ProgresoTarea::leer($token);
    assertProgIgual(2, $e['paso'], 'el detalle no adelanta la fase');
    assertProgIgual('312 comprobantes', $e['detalle'], 'pero sí cambia lo que se lee');

    // ── Terminar deja todo cerrado ──
    $p->terminar('529 facturas en el pago');
    $e = ProgresoTarea::leer($token);
    assertProgIgual('listo', $e['estado'], 'la pantalla necesita saber cuándo parar de preguntar');
    assertProgIgual(5, $e['paso'], 'y que no quedó ninguna fase a medias');

    // ── Fallar también se avisa ──
    // Sin esto la pantalla se queda girando sobre una carga ya muerta y el
    // error aparece detrás de ella, sin que nadie lo vea.
    $otro = bin2hex(random_bytes(16));
    ProgresoTarea::para($otro, $pasos)->fallar('El archivo no se pudo leer.');
    $e = ProgresoTarea::leer($otro);
    assertProgIgual('error', $e['estado'], 'un fallo se reporta como fallo');
    assertProgIgual('El archivo no se pudo leer.', $e['detalle'], 'y dice por qué');
    ProgresoTarea::olvidar($otro);

    // ── Olvidar borra de verdad ──
    ProgresoTarea::olvidar($token);
    assertProgIgual(null, ProgresoTarea::leer($token), 'olvidado es olvidado');

    // ── Un token que no se puede leer no es un error ──
    // La pantalla pregunta antes de que la tarea escriba nada: eso es normal.
    assertProgIgual(null, ProgresoTarea::leer(bin2hex(random_bytes(16))),
        'preguntar por algo que no existe devuelve null, no revienta');

} finally {
    ProgresoTarea::olvidar($token);
}

// ── El token no puede salirse de su carpeta ──
//
// Llega por la URL y termina siendo un nombre de archivo. Se aceptan solo
// hexadecimales: cualquier otra cosa no escribe ni lee nada.
$venenos = [
    '../../../app/config/database', '..\\..\\evil', 'a/b', 'a.b', '',
    'MAYUSCULAS1234567', 'corto', str_repeat('a', 65), 'zzzz1234zzzz1234',
];
foreach ($venenos as $malo) {
    $t = ProgresoTarea::para($malo, ['X']);
    $t->paso('Y', 'Z');
    $t->terminar();
    assertProgIgual(null, ProgresoTarea::leer($malo),
        'un token inválido no puede guardar ni leer nada: ' . var_export($malo, true));
}

// Y ninguno de esos dejó archivos sueltos por ahí.
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'progreso';
foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
    assertProg(
        preg_match('/^[a-f0-9]{16,64}\.json$/', basename($archivo)) === 1,
        'apareció un archivo con nombre inesperado: ' . basename($archivo)
    );
}

echo "OK: ProgresoTarea\n";
