<?php
/**
 * Que un módulo sin su configuración se vea, pero no se abra — y que se vea
 * cerrado exactamente en los mismos casos en que el enrutador lo cierra.
 *
 * Hay tres cosas sin las cuales el sistema no trabaja, solo lo aparenta: el
 * catálogo de proveedores, una empresa y un buzón de correo. Lo que se
 * comprueba aquí es el mecanismo que las exige (app/core/Requisitos.php):
 *
 *   · que cada paso esté declarado entero —qué es, por qué hace falta, dónde
 *     se hace y cómo se comprueba—, porque un paso sin el "dónde" deja a
 *     quien lo lee sin saber qué hacer;
 *   · que los módulos que se cierran existan de verdad en el catálogo de
 *     permisos, y que Configuración y Administración NO se cierren nunca:
 *     son la salida, y cerrarlas dejaría el sistema sin arreglo posible;
 *   · que el módulo que el MENÚ dibuja apagado sea el mismo que la GUARDIA
 *     corta. Si se separaran, volvería el atajo que contesta un error al
 *     pulsarlo, que es justo lo que esto viene a evitar;
 *   · que un paso que no se puede comprobar —base caída— se dé por cumplido:
 *     un servidor sin contestar no puede dejar a la oficina fuera del sistema
 *     diciéndole que le falta configurar lo que ya tenía hecho.
 *
 * No necesita base de datos: el estado se supone con Requisitos::suponer().
 *
 * Uso: php tests/RequisitosDeArranqueTest.php
 */
require_once __DIR__ . '/../app/core/Permisos.php';
require_once __DIR__ . '/../app/core/Requisitos.php';
require_once __DIR__ . '/../app/helpers/Navegacion.php';

$fallos = 0;

function assertReq($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

// ── Los pasos están declarados enteros ────────────────────────────────────

$declarados = Requisitos::declarados();
assertReq(count($declarados) >= 3,
    'config/requisitos.php declara los pasos de puesta en marcha');

foreach (['proveedores', 'empresa', 'correo'] as $id) {
    assertReq(isset($declarados[$id]),
        "falta el paso '{$id}', que es uno de los tres sin los que nada funciona");
}

$permisos = Permisos::todas();

foreach ($declarados as $id => $paso) {
    foreach (['titulo', 'falta', 'porque', 'donde', 'ruta', 'permiso', 'comprobar'] as $campo) {
        assertReq(!empty($paso[$campo]), "al paso '{$id}' le falta '{$campo}'");
    }

    assertReq(is_callable($paso['comprobar'] ?? null),
        "el paso '{$id}' no sabe comprobarse: cerraría módulos sin poder decir cuándo abrirlos");

    // La llave para arreglarlo tiene que existir, o la página del paso le
    // ofrecería un botón a quien no puede usarlo.
    assertReq(in_array((string) ($paso['permiso'] ?? ''), $permisos, true),
        "el paso '{$id}' se arregla con '" . ($paso['permiso'] ?? '')
        . "', que no existe en el catálogo de permisos");

    // El 'dónde' lleva a Configuración, que es la única pantalla que nunca
    // se cierra por requisitos.
    assertReq(strpos((string) ($paso['ruta'] ?? ''), '/configuracion') === 0,
        "el paso '{$id}' lleva a una pantalla que podría estar cerrada ella misma");
}

// ── Qué módulos se cierran ────────────────────────────────────────────────

$modulosDelSistema = array_keys(Permisos::modulos());
$porModulo = Requisitos::catalogo()['modulos'] ?? [];

assertReq($porModulo !== [], 'algún módulo declara qué necesita para abrirse');

foreach ($porModulo as $modulo => $exige) {
    assertReq(in_array($modulo, $modulosDelSistema, true),
        "se cierra el módulo '{$modulo}', que no existe en config/permisos.php");

    foreach ((array) $exige as $id) {
        assertReq(isset($declarados[$id]),
            "el módulo '{$modulo}' exige el paso '{$id}', que nadie declara");
    }
}

// La salida no se puede cerrar por fuera.
foreach (['configuracion', 'administracion'] as $salida) {
    assertReq(!isset($porModulo[$salida]),
        "'{$salida}' no puede tener requisitos: es donde se arreglan, y cerrarla "
        . 'dejaría el sistema sin forma de ponerse en marcha');
}

// ── Con todo hecho, nada se cierra ────────────────────────────────────────

Requisitos::suponer(['proveedores' => true, 'empresa' => true, 'correo' => true]);
Navegacion::olvidar();

assertReq(Requisitos::completa(), 'con los tres pasos hechos la instalación está completa');
assertReq(Requisitos::faltantes() === [], 'con los tres pasos hechos no falta ninguno');
assertReq(Navegacion::cuantosBloqueados() === 0,
    'con los tres pasos hechos el menú no apaga ningún módulo');

foreach ($porModulo as $modulo => $exige) {
    assertReq(!Requisitos::moduloCerrado($modulo),
        "'{$modulo}' sigue cerrado con todos los pasos hechos");
}

// ── Sin nada hecho, se cierra lo que depende y solo eso ───────────────────

Requisitos::suponer(['proveedores' => false, 'empresa' => false, 'correo' => false]);
Navegacion::olvidar();

assertReq(!Requisitos::completa(), 'sin ningún paso hecho la instalación no está completa');
assertReq(count(Requisitos::faltantes()) === count($declarados),
    'sin ningún paso hecho faltan todos');

foreach ($porModulo as $modulo => $exige) {
    assertReq(Requisitos::moduloCerrado($modulo),
        "'{$modulo}' se abre sin tener [" . implode(', ', (array) $exige) . ']');
}

foreach (['configuracion', 'administracion', 'avisos', 'documentos'] as $abierto) {
    assertReq(!Requisitos::moduloCerrado($abierto),
        "'{$abierto}' se cerró: tiene que seguir abierto aunque no haya nada configurado");
}

// El menú sigue enseñándolos: apagados, no escondidos. En CLI los permisos se
// conceden todos, así que esto es el menú de un administrador.
$entradas = Navegacion::entradas();
assertReq(count($entradas) > 0, 'el menú sigue ofreciendo módulos aunque falte configurar');
assertReq(Navegacion::cuantosBloqueados() > 0,
    'sin nada configurado el menú tendría que apagar módulos, y no apaga ninguno');

foreach ($entradas as $id => $entrada) {
    assertReq(array_key_exists('bloqueada', $entrada),
        "la entrada '{$id}' llega sin decir si está bloqueada");
    if (!empty($entrada['bloqueada'])) {
        assertReq(!empty($entrada['falta']),
            "'{$id}' se dibuja apagado pero no dice qué paso falta");
    }
}

// Configuración se sigue pudiendo abrir desde el menú: es la salida.
assertReq(isset($entradas['configuracion']) && empty($entradas['configuracion']['bloqueada']),
    'Configuración quedó apagada en el menú: no habría por dónde arreglar nada');

// ── El menú y la guardia cierran lo mismo ─────────────────────────────────

/*
 * Las dos deducen el módulo del permiso, pero por caminos distintos —el menú
 * desde config/menu.php, la guardia desde la ruta registrada—. Si un día se
 * separaran, el menú ofrecería un atajo que la guardia corta.
 */
foreach ($entradas as $id => $entrada) {
    $cierraLaGuardia = Requisitos::moduloDeRuta('GET', (string) $entrada['ruta']) !== ''
        && Requisitos::moduloCerrado(Requisitos::moduloDeRuta('GET', (string) $entrada['ruta']));

    assertReq(!empty($entrada['bloqueada']) === $cierraLaGuardia,
        "'{$id}': el menú lo dibuja " . (!empty($entrada['bloqueada']) ? 'apagado' : 'abierto')
        . ' y el enrutador lo ' . ($cierraLaGuardia ? 'cierra' : 'deja pasar'));
}

// Las rutas sin dueño no se cierran nunca: el login y el panel de inicio son
// justamente donde se aterriza cuando no se puede entrar a nada más.
foreach ([['GET', '/login'], ['GET', '/'], ['GET', '/home'], ['GET', '/logout']] as [$metodo, $uri]) {
    assertReq(Requisitos::moduloDeRuta($metodo, $uri) === '',
        "{$metodo} {$uri} quedó atada a un módulo: podría cerrarse y dejar sin dónde aterrizar");
}

// Y la pantalla donde se arregla todo tampoco.
assertReq(!Requisitos::moduloCerrado(Requisitos::moduloDeRuta('GET', '/configuracion')),
    'GET /configuracion se cerraría: es donde se hacen los tres pasos');

/*
 * El candado por fuera, que es el que de verdad se puede cerrar sin querer:
 * dar de alta el primer buzón se hace con 'correo.configurar', que vive en el
 * módulo 'correo' — el mismo que se cierra por no tener ningún buzón. Si esas
 * rutas se cerraran, el paso sería imposible de hacer.
 */
$reglas = Permisos::catalogo()['rutas'] ?? [];
foreach (Requisitos::declarados() as $id => $paso) {
    assertReq(Requisitos::esArreglo((string) $paso['permiso']),
        "'{$paso['permiso']}' arregla el paso '{$id}' y no está reconocido como tal");
}

foreach ($reglas as $clave => $regla) {
    $permiso = $regla['permiso'] ?? null;
    if (!is_string($permiso) || !Requisitos::esArreglo($permiso)) {
        continue;
    }
    [$metodo, $uri] = array_pad(explode(' ', $clave, 2), 2, '');
    assertReq(Requisitos::moduloDeRuta($metodo, $uri) === '',
        "{$clave} se cierra, y es con lo que se arregla un paso: el sistema pediría "
        . 'para abrirse algo que solo se puede hacer con el sistema abierto');
}

// En concreto el que se cerraba: el alta del primer buzón.
assertReq(Requisitos::moduloDeRuta('POST', '/correo/cuentas/guardar') === '',
    'dar de alta un buzón quedaría cerrado por no haber buzones');

/*
 * Y las que se declararon abiertas a mano: viven en CorreoController pero a
 * quien sirven es a la pantalla de Configuración. Si alguna dejara de existir,
 * la lista estaría mintiendo.
 */
foreach ((array) (Requisitos::catalogo()['abiertas'] ?? []) as $clave) {
    assertReq(isset($reglas[$clave]),
        "'{$clave}' se declara abierta y no existe en config/permisos.php");
    [$metodo, $uri] = array_pad(explode(' ', $clave, 2), 2, '');
    assertReq(Requisitos::moduloDeRuta($metodo, $uri) === '',
        "'{$clave}' se declaró abierta y aun así se cierra");
}

// ── Faltando uno solo, se cierra solo lo que lo necesita ──────────────────

Requisitos::suponer(['proveedores' => true, 'empresa' => true, 'correo' => false]);
Navegacion::olvidar();

assertReq(Requisitos::moduloCerrado('correo'),
    'Correo se abre sin ningún buzón dado de alta');
assertReq(!Requisitos::moduloCerrado('por_pagar'),
    'Pagos se cerró por falta de buzón, que no necesita');
assertReq(array_keys(Requisitos::faltanDelModulo('correo')) === ['correo'],
    'a Correo le falta exactamente el buzón, ni más ni menos');

// ── La base caída no cierra nada ──────────────────────────────────────────

/*
 * Un requisito que revienta al preguntarse es exactamente lo que pasa con la
 * base sin contestar. Tiene que darse por CUMPLIDO: no se puede distinguir
 * "no hay ninguna empresa" de "no se pudo preguntar", y la lectura que cierra
 * el sistema entero por un servidor caído es la peor de las dos.
 *
 * Se le cambia el catálogo por reflexión y no con un método de la clase: el
 * código que corre en producción no necesita una puerta para esto.
 */
$campoCatalogo = new ReflectionProperty(Requisitos::class, 'catalogo');
$catalogoReal  = $campoCatalogo->getValue();

$campoCatalogo->setValue(null, [
    'requisitos' => [
        'reventon' => [
            'titulo' => 'Uno que no se puede comprobar',
            'falta'  => '', 'porque' => '', 'donde' => '', 'ruta' => '', 'permiso' => '',
            'comprobar' => static function (): bool {
                throw new RuntimeException('la base no contesta');
            },
        ],
    ],
    'modulos' => ['correo' => ['reventon']],
]);
Requisitos::suponer(null); // que vuelva a comprobar de verdad
Navegacion::olvidar();

assertReq(Requisitos::cumplido('reventon'),
    'un requisito que no se puede comprobar se da por cumplido, para que una base '
    . 'caída no deje a la oficina fuera del sistema');
assertReq(!Requisitos::moduloCerrado('correo'),
    'una base que no contesta cierra módulos, que es justo lo que no puede pasar');

$campoCatalogo->setValue(null, $catalogoReal);
Requisitos::suponer(['proveedores' => true, 'empresa' => true, 'correo' => true]);
Navegacion::olvidar();

if ($fallos > 0) {
    fwrite(STDERR, "RequisitosDeArranque: {$fallos} fallas\n");
    exit(1);
}

echo 'OK: RequisitosDeArranque (' . count($declarados) . ' pasos, '
    . count($porModulo) . " módulos que dependen de ellos)\n";
exit(0);
