<?php
/**
 * El listado de Inicio marca la empresa de ESTE usuario, no la marca global.
 *
 * El error que fija: la selección de empresa pasó a ser por usuario, pero el
 * listado siguió leyendo la columna `sociedades.activa` —el valor por omisión
 * del sistema, uno solo para todos—. Con dos empresas registradas eso daba dos
 * síntomas a la vez:
 *
 *   1. El check verde aparecía en la fila equivocada, contradiciendo el nombre
 *      que la barra superior mostraba como empresa en uso.
 *   2. Peor: la fila marcada se dibuja SIN botón (ya está elegida), así que la
 *      empresa por omisión quedaba imposible de seleccionar. El usuario veía
 *      una empresa que no podía elegir y otra que decía estar activa sin
 *      estarlo.
 *
 * La prueba renderiza la vista de verdad y comprueba las dos cosas: dónde cae
 * el check y que exista botón para TODA empresa que no sea la que se está
 * usando.
 */

$fallos = 0;
function verificaHome($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

/** Renderiza el listado de Inicio con las sociedades y la empresa en uso dadas. */
function renderInicio(array $sociedades, $sociedadActiva)
{
    // La vista es un fragmento: espera estas variables ya resueltas.
    $stats = ['total_facturas' => 0, 'bandeja_pendientes' => 0];
    $ultimoListado = null;
    $resumenListado = [];
    if (!defined('APP_URL')) {
        define('APP_URL', '/xmlconcilia/public');
    }

    ob_start();
    include __DIR__ . '/../app/views/home/index.php';
    return (string) ob_get_clean();
}

$grupo      = ['id' => 4,  'nombre' => 'GRUPO BM SP S.A',              'cedula' => '3101639680', 'activa' => 1];
$alternativa = ['id' => 23, 'nombre' => 'EMPRESA DE SERVICIOS PZ S.A', 'cedula' => '3101123456', 'activa' => 0];
$sociedades = [$grupo, $alternativa];

// ── Caso del error: el usuario trabaja con la que NO es la de por omisión ──
$html = renderInicio($sociedades, $alternativa);

verificaHome(
    strpos($html, 'action="/xmlconcilia/public/sociedades/activar/4"') !== false,
    'la empresa por omisión se puede seleccionar cuando no es la que se está usando'
);
verificaHome(
    strpos($html, 'action="/xmlconcilia/public/sociedades/activar/23"') === false,
    'la empresa en uso no ofrece botón para volver a elegirla'
);

// El check verde tiene que caer en la fila de la empresa en uso (23), no en la
// de la marca global (4). Se comprueba por posición: el check aparece después
// del formulario para elegir la 4.
$posBotonGrupo = strpos($html, 'sociedades/activar/4');
$posCheck      = strpos($html, 'fa-circle-check');
verificaHome($posCheck !== false, 'hay una empresa marcada como en uso');
verificaHome($posCheck > $posBotonGrupo,
    'el check cae en la empresa elegida, no en GRUPO BM SP (la de por omisión)');

// ── Caso normal: el usuario trabaja con la de por omisión ─────────────
$html = renderInicio($sociedades, $grupo);
verificaHome(
    strpos($html, 'action="/xmlconcilia/public/sociedades/activar/23"') !== false,
    'la otra empresa se puede seleccionar'
);
verificaHome(
    strpos($html, 'action="/xmlconcilia/public/sociedades/activar/4"') === false,
    'la empresa en uso sigue sin botón, también cuando coincide con la de por omisión'
);

// ── Sin empresa resuelta: nada marcado, pero todo elegible ────────────
$html = renderInicio($sociedades, null);
verificaHome(strpos($html, 'fa-circle-check') === false,
    'sin empresa en uso no se marca ninguna al azar');
foreach ([4, 23] as $id) {
    verificaHome(strpos($html, "sociedades/activar/{$id}") !== false,
        "sin empresa en uso, la {$id} se puede elegir (si no, no habría forma de salir del estado)");
}

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: Inicio marca la empresa de este usuario y deja elegir todas las demás\n";
