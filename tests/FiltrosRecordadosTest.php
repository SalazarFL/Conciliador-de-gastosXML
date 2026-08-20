<?php
/**
 * Los filtros que cada módulo recuerda.
 *
 * Lo que se comprueba es lo que hace útil la memoria: que entrar por el menú
 * devuelva lo último que se filtró, que filtrar de nuevo mande —incluso para
 * vaciar un criterio—, que "Limpiar" olvide de verdad, y que lo guardado en
 * un módulo no se meta en otro, porque "estado" no significa lo mismo en la
 * cola de trabajo que en el pago semanal.
 */
require_once __DIR__ . '/../app/core/Controller.php';

session_start();

class FiltrosRecordadosSonda extends Controller
{
    public function aplicar(string $modulo, array $claves): void
    {
        $this->recordarFiltros($modulo, $claves);
    }
}

function assertFiltro($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$sonda   = new FiltrosRecordadosSonda();
$CLAVES  = ['q', 'proveedor', 'estado'];

/** Una petición nueva: $_GET tal como llegaría del navegador. */
$pedir = function (array $get) use ($sonda, $CLAVES) {
    $_GET = $get;
    $sonda->aplicar('modulo_a', $CLAVES);
    return $_GET;
};

$_SESSION = [];

// ── Filtrar guarda ───────────────────────────────────────────────────
// La barra se envía por GET con TODAS sus claves, vengan llenas o vacías.
$pedir(['q' => 'ACME', 'proveedor' => 'cod:140000003', 'estado' => '']);
assertFiltro($_SESSION['filtros_modulo']['modulo_a'] === ['q' => 'ACME', 'proveedor' => 'cod:140000003'],
    'al filtrar se guarda lo que trae valor, y nada más');

// ── Entrar por el menú devuelve lo guardado ──────────────────────────
$get = $pedir([]);
assertFiltro($get['q'] === 'ACME' && $get['proveedor'] === 'cod:140000003',
    'entrar sin criterios devuelve lo último filtrado');
assertFiltro(!isset($get['estado']),
    'lo que estaba vacío no se inventa al volver');

// ── Vaciar un criterio manda sobre lo guardado ───────────────────────
// Es la razón de mirar si la clave viene y no si trae valor: si mirara el
// valor, borrar el buscador sería imposible —volvería solo—.
$get = $pedir(['q' => '', 'proveedor' => 'cod:140000003', 'estado' => '']);
assertFiltro($get['q'] === '' && $_SESSION['filtros_modulo']['modulo_a'] === ['proveedor' => 'cod:140000003'],
    'vaciar un campo lo borra de la memoria en vez de resucitarlo');

// ── Un contexto no es un filtro ──────────────────────────────────────
// Llegar por un enlace que solo elige QUÉ se ve (una importación, un
// listado) sigue siendo "entrar": los filtros vuelven puestos.
$get = $pedir(['listado_id' => '7']);
assertFiltro($get['listado_id'] === '7' && $get['proveedor'] === 'cod:140000003',
    'una clave ajena a los filtros no cuenta como filtrar');

// ── Limpiar olvida ───────────────────────────────────────────────────
$pedir(['limpiar' => 1]);
assertFiltro(!isset($_SESSION['filtros_modulo']['modulo_a']),
    'Limpiar borra lo guardado');
$get = $pedir([]);
assertFiltro(!isset($get['proveedor']),
    'después de limpiar, entrar por el menú no resucita nada');

// ── Cada módulo con lo suyo ──────────────────────────────────────────
$_GET = ['q' => 'ACME', 'proveedor' => '', 'estado' => 'con_diferencia'];
$sonda->aplicar('modulo_a', $CLAVES);
$_GET = ['q' => '', 'proveedor' => '', 'estado' => 'sin_respaldo'];
$sonda->aplicar('modulo_b', $CLAVES);

$_GET = [];
$sonda->aplicar('modulo_a', $CLAVES);
assertFiltro($_GET === ['q' => 'ACME', 'estado' => 'con_diferencia'],
    'el módulo A recupera lo suyo, sin nada del B');
$_GET = [];
$sonda->aplicar('modulo_b', $CLAVES);
assertFiltro($_GET === ['estado' => 'sin_respaldo'],
    'el módulo B recupera lo suyo, sin nada del A');

// ── Solo se devuelve lo que el módulo pidió ──────────────────────────
// Si mañana se retira un filtro de la pantalla, lo que quedó guardado de la
// versión anterior no se sigue aplicando a escondidas.
$_GET = [];
$sonda->aplicar('modulo_a', ['q']);
assertFiltro($_GET === ['q' => 'ACME'],
    'un filtro que ya no existe en la pantalla no se aplica');

// ── Una visita de paso no cambia lo guardado ─────────────────────────
// Con 'ctx' se llega buscando el electrónico de UN documento: el buscador
// trae su número, no un criterio que alguien eligió para este listado.
$_SESSION = [];
$pedir(['q' => 'ACME', 'proveedor' => '', 'estado' => '']);
assertFiltro($_SESSION['filtros_modulo']['modulo_a'] === ['q' => 'ACME'],
    'lo filtrado a mano se guarda');

$get = $pedir(['q' => '00100001010000045587', 'ctx' => 'seguimiento']);
assertFiltro($get['q'] === '00100001010000045587',
    'la búsqueda de paso sí se aplica a la pantalla');
assertFiltro($_SESSION['filtros_modulo']['modulo_a'] === ['q' => 'ACME'],
    'pero no pisa lo que el módulo tenía guardado');

$get = $pedir([]);
assertFiltro($get['q'] === 'ACME',
    'al volver por el menú aparece lo de siempre, no el documento de paso');

echo "OK FiltrosRecordadosTest\n";
