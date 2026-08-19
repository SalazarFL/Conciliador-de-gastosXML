<?php
/**
 * Cargar el pago de una semana que todavía no existe.
 *
 * El formulario de subida ofrece "➕ Nueva semana…", pero su único botón es
 * "Vista previa": la carga real solo se alcanza confirmando desde ahí. La
 * vista previa exigía una semana que ya existiera, así que elegir esa opción
 * terminaba en "Seleccioná una semana existente" y no había forma de importar
 * nada — ni de crear la semana desde donde el formulario decía que se podía.
 *
 * Lo que se comprueba: que la semana nueva se acepte, que NO se cree en la
 * vista previa (esta pantalla no escribe: se crea al confirmar la carga) y
 * que seguir sin semana siga rechazado, porque el pago semanal se verifica
 * contra las facturas de SU semana y sin semana no hay contra qué.
 */
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/controllers/PorPagarController.php';

function assertSemanaNueva($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Sin sesión ni base: solo se ejercita la lectura del formulario. */
class PorPagarSemanaSonda extends PorPagarController
{
    public function __construct() { /* sin requireAuth */ }

    /** Devuelve el id resuelto, o el mensaje de error si se rechazó. */
    public function resolver(array $post)
    {
        $_POST = $post;
        $ref = new ReflectionMethod(PorPagarController::class, 'semanaDeLaVistaPrevia');
        $ref->setAccessible(true);
        try {
            return $ref->invoke($this);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}

$sonda = new PorPagarSemanaSonda();

// ── La semana nueva pasa, y pasa sin existir ─────────────────────────
// 0 es "todavía no hay semana": lo único que la vista previa necesita saber
// de ella es que no tiene un listado anterior contra el cual acumular.
assertSemanaNueva($sonda->resolver(['semana_id' => 'nueva', 'semana_nueva' => 'Semana 33']) === 0,
    'elegir "Nueva semana…" deja pasar la vista previa');
assertSemanaNueva($sonda->resolver(['semana_id' => 'nueva', 'semana_nueva' => '']) === 0,
    'sin nombre también pasa: el nombre lo resuelve la carga, con su valor por omisión');

// ── Una semana de la lista sigue siendo esa semana ───────────────────
assertSemanaNueva($sonda->resolver(['semana_id' => '17']) === 17,
    'una semana existente se respeta tal cual');

// ── Sin semana no se previsualiza, y se dice cómo salir ──────────────
$mensaje = $sonda->resolver(['semana_id' => '']);
assertSemanaNueva(is_string($mensaje) && strpos($mensaje, 'Nueva semana') !== false,
    'sin semana se rechaza nombrando las dos salidas, no solo "elegí una existente"');

foreach (['0', '-3', 'abc'] as $basura) {
    assertSemanaNueva(is_string($sonda->resolver(['semana_id' => $basura])),
        "una semana inventada se rechaza: '{$basura}'");
}

echo "OK PagoSemanalSemanaNuevaTest\n";
