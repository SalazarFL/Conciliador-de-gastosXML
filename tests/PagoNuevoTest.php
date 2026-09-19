<?php
/**
 * Cargar el pago de un pago que todavía no existe.
 *
 * El formulario de subida ofrece "➕ Nuevo pago…", pero su único botón es
 * "Vista previa": la carga real solo se alcanza confirmando desde ahí. La
 * vista previa exigía un pago que ya existiera, así que elegir esa opción
 * terminaba en "Seleccioná un pago existente" y no había forma de importar
 * nada — ni de crear el pago desde donde el formulario decía que se podía.
 *
 * Lo que se comprueba: que el pago nueva se acepte, que NO se cree en la
 * vista previa (esta pantalla no escribe: se crea al confirmar la carga) y
 * que seguir sin pago siga rechazado, porque el pago se verifica
 * contra las facturas de SU pago y sin pago no hay contra qué.
 */
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/controllers/PorPagarController.php';

function assertPagoNuevo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Sin sesión ni base: solo se ejercita la lectura del formulario. */
class PorPagarPagoSonda extends PorPagarController
{
    public function __construct() { /* sin requireAuth */ }

    /** Devuelve el id resuelto, o el mensaje de error si se rechazó. */
    public function resolver(array $post)
    {
        $_POST = $post;
        $ref = new ReflectionMethod(PorPagarController::class, 'pagoDeLaVistaPrevia');
        $ref->setAccessible(true);
        try {
            return $ref->invoke($this);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}

$sonda = new PorPagarPagoSonda();

// ── El pago nueva pasa, y pasa sin existir ─────────────────────────
// 0 es "todavía no hay pago": lo único que la vista previa necesita saber
// de ella es que no tiene un listado anterior contra el cual acumular.
assertPagoNuevo($sonda->resolver(['pago_id' => 'nueva', 'pago_nuevo' => 'Pago 33']) === 0,
    'elegir "Nuevo pago…" deja pasar la vista previa');
assertPagoNuevo($sonda->resolver(['pago_id' => 'nueva', 'pago_nuevo' => '']) === 0,
    'sin nombre también pasa: el nombre lo resuelve la carga, con su valor por omisión');

// ── Un pago de la lista sigue siendo ese pago ───────────────────
assertPagoNuevo($sonda->resolver(['pago_id' => '17']) === 17,
    'un pago existente se respeta tal cual');

// ── Sin pago no se previsualiza, y se dice cómo salir ──────────────
$mensaje = $sonda->resolver(['pago_id' => '']);
assertPagoNuevo(is_string($mensaje) && strpos($mensaje, 'Nuevo pago') !== false,
    'sin pago se rechaza nombrando las dos salidas, no solo "elegí una existente"');

foreach (['0', '-3', 'abc'] as $basura) {
    assertPagoNuevo(is_string($sonda->resolver(['pago_id' => $basura])),
        "un pago inventada se rechaza: '{$basura}'");
}

echo "OK PagoNuevoTest\n";
