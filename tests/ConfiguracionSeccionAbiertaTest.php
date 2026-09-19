<?php
/**
 * La pantalla de configuración abre UNA sección, no las nueve apiladas.
 *
 * Lo que se fija acá es la decisión que toma el servidor antes de que corra
 * nada de JavaScript: qué sección nace abierta. Importa por dos motivos.
 *
 *   1. Si nacieran todas abiertas y el JS las cerrara después, se vería el
 *      salto en cada carga —la página larguísima de antes, medio segundo—.
 *   2. Si naciera abierta una que el menú no lista (porque quien mira no tiene
 *      el permiso), la pantalla quedaría mostrando algo sin ninguna entrada
 *      del menú marcada, y sin forma de volver a ella al cambiar de sección.
 *
 * De ahí que también se compruebe lo contrario: que toda entrada del menú
 * tenga su sección. Menú y secciones se escriben en sitios distintos del mismo
 * archivo y es justo el par que se desincroniza al agregar un ajuste nuevo.
 *
 * Uso: php tests/ConfiguracionSeccionAbiertaTest.php
 */

require_once __DIR__ . '/../app/core/Permisos.php';

$fallos = 0;
function verificaCfg($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

/**
 * Renderiza la pantalla como la vería alguien con esos permisos, llegando
 * por ?ir=$destino. La vista es un fragmento: espera estas variables ya
 * resueltas, igual que se las pasa ConfiguracionController.
 */
function renderConfiguracion($destino = '', array $puede = [])
{
    if (!defined('APP_URL')) {
        define('APP_URL', '/xmlconcilia/public');
    }

    $esAdmin         = true;
    $puede           = $puede + [
        'usuarios' => true, 'roles' => true, 'bitacora' => true,
        'respaldo' => true, 'empresas' => true, 'correo' => true, 'archivo' => true,
    ];
    $imapDisponible  = true;
    $configLocal     = ['carpeta_destino' => 'C:\Documentos', 'cuenta_id' => 0];
    $cuentas         = [];
    $cuentaActivaId  = 0;
    $sociedades      = [];
    $sociedadActiva  = null;
    $sociedadEnUsoId = 0;
    $pagos         = [];
    $pagoActivo    = 0;
    $usuarios        = [];
    $roles           = [];
    $actividad       = [];
    $actividadEstado = null;
    $modulosPermisos = [];
    $respaldo        = ['estado' => null, 'archivos' => [], 'automatico' => ['activo' => false, 'hora' => '']];
    $seccionInicial  = $destino;

    ob_start();
    include __DIR__ . '/../app/views/configuracion/index.php';
    return (string) ob_get_clean();
}

/** Los id de las secciones abiertas en ese HTML. Debería ser siempre una. */
function seccionesAbiertas($html)
{
    preg_match_all('/class="cfg-section is-activa"\s*\n\s*id="([a-z]+)"/', $html, $m);
    return $m[1];
}

/** Los id que ofrece el menú de la izquierda. */
function seccionesDelMenu($html)
{
    preg_match_all('/data-cfg-nav="([a-z]+)"/', $html, $m);
    return $m[1];
}

/** Los id de todas las secciones dibujadas, abiertas o no. */
function seccionesDibujadas($html)
{
    preg_match_all('/data-cfg-seccion="([a-z]+)"/', $html, $m);
    return $m[1];
}

// ── Llegada normal, sin destino: se abre la primera y solo la primera ──
$html = renderConfiguracion();
verificaCfg(seccionesAbiertas($html) === ['archivo'],
    'sin destino se abre Archivo de documentos, y nada más: '
    . implode(', ', seccionesAbiertas($html)));

// El resto sigue en la página —con lo que se haya escrito en sus campos—,
// solo que cerrado. Cerrarlas quitándolas obligaría a recargar para volver.
verificaCfg(count(seccionesDibujadas($html)) === 9,
    'las nueve secciones se dibujan; el menú solo cambia cuál se ve');

// ── Llegada dirigida: /configuracion?ir=respaldo ──────────────────────
$html = renderConfiguracion('respaldo');
verificaCfg(seccionesAbiertas($html) === ['respaldo'],
    'con ?ir=respaldo se abre Respaldo, no Archivo');
verificaCfg(preg_match('/data-cfg-nav="respaldo"[^>]*aria-selected="true"/', $html) === 1,
    'y la entrada del menú de Respaldo queda marcada');

// ── Destino inventado: no se queda sin abrir nada ─────────────────────
$html = renderConfiguracion('estonoexiste');
verificaCfg(seccionesAbiertas($html) === ['archivo'],
    'un ?ir= que no corresponde a ninguna sección cae en la primera');

// ── Destino al que no se tiene permiso ────────────────────────────────
//
// El menú no lista Respaldo si no se puede administrar. Abrir la sección de
// todos modos dejaría al usuario mirando algo que el menú no reconoce.
$html = renderConfiguracion('respaldo', ['respaldo' => false]);
verificaCfg(seccionesAbiertas($html) === ['archivo'],
    'sin permiso de respaldo, ?ir=respaldo no abre una sección fuera del menú');

// ── Menú y secciones, sincronizados ───────────────────────────────────
$html   = renderConfiguracion();
$menu   = seccionesDelMenu($html);
$hay    = seccionesDibujadas($html);
foreach ($menu as $id) {
    verificaCfg(in_array($id, $hay, true),
        "la entrada «{$id}» del menú tiene su sección");
}
verificaCfg(count($menu) === count(array_unique($menu)),
    'ninguna sección aparece dos veces en el menú');

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}

echo "OK: la configuración abre una sección a la vez y el menú coincide con lo que hay\n";
