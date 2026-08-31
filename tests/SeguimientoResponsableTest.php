<?php
/**
 * El responsable de un renglón es quien lo manda a revisión, no un nombre que
 * alguien teclea.
 *
 * Antes había un campo en el diálogo y un desplegable en la barra. Los dos
 * sobraban: había que escribir el propio nombre para decirle al sistema algo
 * que ya sabía, y nada impedía escribir el de otra persona —a mano o armando
 * la petición—. Ahora lo pone el servidor con quien tiene la sesión abierta.
 *
 * Se comprueba la regla directamente, y que en la pantalla no quede ningún
 * control capaz de mandar un responsable: si volviera uno, el servidor lo
 * ignoraría y la persona creería haber asignado a alguien.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/SeguimientoController.php';

function assertResponsable($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// La regla es estática y pura: se llega a ella sin sesión ni base.
$responsableDe = new ReflectionMethod('SeguimientoController', 'responsableDe');
$responsableDe->setAccessible(true);
$quien = function ($estado, array $usuario) use ($responsableDe) {
    return $responsableDe->invoke(null, $estado, $usuario);
};

$sofia = ['id' => 4, 'nombre' => 'Sofía'];

// ── Mandar a revisión es hacerse cargo ──────────────────────────────────────
assertResponsable($quien(Seguimiento::ESTADO_A_MANO, $sofia) === 'Sofía',
    'quien manda un renglón a revisión queda a cargo de él');

// ── Las demás acciones no ponen nombre a nadie ──────────────────────────────
foreach (['lista', 'cerrada', 'pendiente', Seguimiento::SIN_MARCA] as $estado) {
    assertResponsable($quien($estado, $sofia) === null,
        "'{$estado}' no toca el responsable: nadie está persiguiendo nada ahí");
}

// Una anotación suelta llega sin estado. Comentar no es hacerse cargo.
assertResponsable($quien('', $sofia) === null,
    'anotar no cambia el responsable');

// ── Sin nombre en la sesión, mejor vacío que inventado ──────────────────────
assertResponsable($quien(Seguimiento::ESTADO_A_MANO, ['id' => 9]) === '',
    'sin nombre se guarda vacío, que el modelo convierte en NULL');
assertResponsable($quien(Seguimiento::ESTADO_A_MANO, ['nombre' => '  Ana  ']) === 'Ana',
    'el nombre se guarda sin espacios de sobra');

// ── En la pantalla no queda forma de mandar un responsable ──────────────────
$vista = file_get_contents(__DIR__ . '/../app/views/seguimiento/index.php');

assertResponsable(strpos($vista, 'name="responsable"') === false,
    'no queda ningún control que mande un responsable al servidor');
assertResponsable(strpos($vista, 'dlg-responsable') === false,
    'el diálogo ya no pide teclear un responsable');
assertResponsable(strpos($vista, 'responsable: true') === false,
    'ninguna acción declara que haya que pedir responsable');

// Y el servidor tampoco lo lee de la petición, así que uno colado no vale.
$controlador = file_get_contents(__DIR__ . '/../app/controllers/SeguimientoController.php');
assertResponsable(strpos($controlador, "post('responsable'") === false,
    'el servidor no acepta un responsable venido de la petición');

echo "OK: el responsable es quien usa el sistema\n";
