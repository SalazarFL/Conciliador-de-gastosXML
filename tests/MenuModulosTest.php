<?php
/**
 * Que el menú no ofrezca puertas que el enrutador va a cerrar.
 *
 * config/menu.php dice qué módulos se enseñan —en la barra lateral y en el
 * panel principal— y con qué llave. config/permisos.php dice qué llave exige
 * de verdad cada ruta. Si las dos listas se separan, lo que pasa no es un
 * error visible: es una tarjeta bonita en el panel que al pulsarla contesta
 * "no tienes permiso". Eso se lee como un sistema averiado.
 *
 * Así que aquí se comprueban las tres cosas que pueden separarse:
 *   · que la ruta de cada módulo exista de verdad en routes.php;
 *   · que la llave que pide el menú sea la MISMA que exige esa ruta;
 *   · que la entrada esté completa (nombre, icono, resumen, tono, segmento),
 *     porque un campo que falta no rompe la página, solo la deja coja.
 *
 * No necesita base de datos.
 *
 * Uso: php tests/MenuModulosTest.php
 */
require_once __DIR__ . '/../app/core/Permisos.php';
require_once __DIR__ . '/../app/helpers/Navegacion.php';

$fallos = 0;

function assertMenu($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

// ── Las rutas que se registran de verdad ──────────────────────────────────

$espia = new class {
    public array $vistas = [];
    public function get($uri, $callback)    { $this->vistas[] = 'GET ' . $uri;    return $this; }
    public function post($uri, $callback)   { $this->vistas[] = 'POST ' . $uri;   return $this; }
    public function put($uri, $callback)    { $this->vistas[] = 'PUT ' . $uri;    return $this; }
    public function delete($uri, $callback) { $this->vistas[] = 'DELETE ' . $uri; return $this; }
};

$app = new class ($espia) {
    private $router;
    public function __construct($router) { $this->router = $router; }
    public function getRouter() { return $this->router; }
};

require __DIR__ . '/../app/config/routes.php';

$registradas = $espia->vistas;
$reglas      = Permisos::catalogo()['rutas'] ?? [];
$todas       = Permisos::todas();

// ── Cada entrada del menú ─────────────────────────────────────────────────

$catalogo = Navegacion::catalogo();
assertMenu(count($catalogo) > 0, 'config/menu.php declara secciones');

$tonos     = ['navy', 'oro', 'verde', 'gris'];
$idsVistos = [];
$cuantas   = 0;

foreach ($catalogo as $idSeccion => $seccion) {
    assertMenu(!empty($seccion['titulo']),
        "la sección {$idSeccion} tiene un título que ponerle al encabezado");
    assertMenu(!empty($seccion['entradas']),
        "la sección {$idSeccion} ofrece al menos un módulo");

    foreach ($seccion['entradas'] ?? [] as $id => $entrada) {
        $cuantas++;

        assertMenu(!in_array($id, $idsVistos, true),
            "el módulo '{$id}' está declarado dos veces");
        $idsVistos[] = $id;

        foreach (['ruta', 'segmento', 'titulo', 'icono', 'ayuda', 'resumen', 'permiso', 'tono'] as $campo) {
            assertMenu(!empty($entrada[$campo]),
                "al módulo '{$id}' le falta '{$campo}'");
        }

        assertMenu(in_array($entrada['tono'] ?? '', $tonos, true),
            "el módulo '{$id}' usa un tono que no existe en el CSS ("
            . implode(', ', $tonos) . ')');

        // El icono se escribe sin el 'fas', que lo pone la plantilla.
        assertMenu(strpos((string) ($entrada['icono'] ?? ''), 'fa-') === 0,
            "el icono del módulo '{$id}' se escribe como 'fa-algo', sin el 'fas'");

        // El segmento es el trozo con el que se marca "estás aquí", y sale de
        // la propia ruta: si no coincidiera, el módulo nunca se vería activo.
        assertMenu('/' . ($entrada['segmento'] ?? '') === ($entrada['ruta'] ?? ''),
            "el segmento del módulo '{$id}' no es el de su propia ruta");

        // ── La ruta existe ────────────────────────────────────────────────
        $ruta = 'GET ' . ($entrada['ruta'] ?? '');
        assertMenu(in_array($ruta, $registradas, true),
            "el módulo '{$id}' lleva a {$ruta}, que routes.php no registra");

        // ── Y pide exactamente lo que esa ruta exige ──────────────────────
        $pideElMenu   = (array) ($entrada['permiso'] ?? []);
        $exigeLaRuta  = (array) ($reglas[$ruta]['permiso'] ?? []);

        foreach ($pideElMenu as $permiso) {
            assertMenu(in_array($permiso, $todas, true),
                "el módulo '{$id}' pide '{$permiso}', que no existe en el catálogo de permisos");
        }

        sort($pideElMenu);
        sort($exigeLaRuta);
        assertMenu($pideElMenu === $exigeLaRuta,
            "el módulo '{$id}' se ofrece con [" . implode(', ', $pideElMenu)
            . '] pero ' . $ruta . ' exige [' . implode(', ', $exigeLaRuta)
            . ']: se vería el atajo y al pulsarlo saldría "no tienes permiso"');
    }
}

assertMenu($cuantas > 0, 'el menú ofrece módulos');

// ── El filtro ─────────────────────────────────────────────────────────────

/*
 * En CLI, Permisos::puede() concede todo —los procesos de cli/ no son nadie—,
 * así que esto es el menú de un administrador: tiene que salir entero. Es la
 * comprobación de que filtrar no se come nada por su cuenta.
 */
$secciones = Navegacion::secciones();
assertMenu(count($secciones) === count($catalogo),
    'con todos los permisos se ofrecen todas las secciones');
assertMenu(Navegacion::cuantos() === $cuantas,
    'con todos los permisos se ofrecen todos los módulos');

foreach ($secciones as $seccion) {
    foreach ($seccion['entradas'] as $id => $entrada) {
        assertMenu(($entrada['id'] ?? '') === $id,
            "la entrada '{$id}' llega con su id puesto, para colgarle un contador");
    }
}

if ($fallos > 0) {
    fwrite(STDERR, "MenuModulos: {$fallos} fallas\n");
    exit(1);
}

echo "OK: MenuModulos ({$cuantas} módulos en " . count($catalogo) . " secciones)\n";
exit(0);
