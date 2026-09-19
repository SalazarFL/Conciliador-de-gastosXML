<?php
/**
 * El catálogo de permisos tiene que cuadrar con las rutas de verdad.
 *
 * Esta es la prueba que sostiene todo el control de accesos, y no necesita
 * base de datos: si una ruta se registra en routes.php y nadie la declara en
 * permisos.php, queda abierta a cualquiera que haya entrado —y nadie se
 * entera, porque la pantalla funciona igual—. Al revés también importa: un
 * permiso declarado para una ruta que ya no existe se sigue ofreciendo en la
 * pantalla de roles como si protegiera algo.
 *
 * Se comprueban las dos direcciones más lo básico del catálogo: que ningún
 * permiso esté repetido, que todos tengan texto en castellano y que una ruta
 * no pida un permiso inventado.
 */
require_once __DIR__ . '/../app/core/Permisos.php';

$fallos = 0;

function assertPermisos($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

// ── El catálogo por dentro ────────────────────────────────────────────────

$modulos = Permisos::modulos();
assertPermisos(count($modulos) > 0, 'el catálogo declara módulos');

$todas = Permisos::todas();
assertPermisos(count($todas) === count(array_unique($todas)),
    'ninguna clave de permiso está declarada dos veces');

foreach ($todas as $clave) {
    assertPermisos(Permisos::etiqueta($clave) !== $clave,
        "el permiso {$clave} tiene un texto en castellano que explique qué concede");
    assertPermisos(strpos($clave, '.') !== false,
        "el permiso {$clave} usa la forma modulo.accion");
    assertPermisos(Permisos::moduloDe($clave) !== 'general',
        "el permiso {$clave} pertenece a un módulo del catálogo");
}

// ── Las rutas que se registran de verdad ──────────────────────────────────

/*
 * routes.php se carga con un enrutador de mentira que solo apunta lo que se
 * le registra. Es la única forma de comparar contra las rutas REALES en vez
 * de contra una segunda lista escrita a mano, que es exactamente el error que
 * esta prueba busca.
 */
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
$declaradas  = array_keys(Permisos::catalogo()['rutas'] ?? []);

assertPermisos(count($registradas) > 0, 'routes.php registra rutas');

foreach (array_diff($registradas, $declaradas) as $sinDeclarar) {
    assertPermisos(false,
        "la ruta {$sinDeclarar} existe pero no dice qué permiso exige "
        . '(añádela a app/config/permisos.php)');
}

foreach (array_diff($declaradas, $registradas) as $sinRuta) {
    assertPermisos(false,
        "app/config/permisos.php declara {$sinRuta}, pero esa ruta ya no se registra");
}

// ── Que ninguna ruta pida un permiso inventado ────────────────────────────

foreach ((Permisos::catalogo()['rutas'] ?? []) as $ruta => $regla) {
    foreach ((array) ($regla['permiso'] ?? '') as $permiso) {
        if ($permiso === 'publica' || $permiso === 'libre') {
            continue;
        }
        assertPermisos(in_array($permiso, $todas, true),
            "la ruta {$ruta} exige '{$permiso}', que no existe en el catálogo");
    }

    // Un texto de bitácora sin permiso, o al revés, no rompe nada, pero sí una
    // acción que cambia datos y no deja rastro.
    if (isset($regla['registrar'])) {
        assertPermisos(is_string($regla['registrar']) && trim($regla['registrar']) !== '',
            "la ruta {$ruta} tiene un texto de bitácora vacío");
    }
}

// ── Que el login siga siendo alcanzable sin sesión ────────────────────────

$rutas = Permisos::catalogo()['rutas'] ?? [];
assertPermisos(($rutas['GET /login']['permiso'] ?? '') === 'publica',
    'la pantalla de entrada es pública: si pidiera permiso, nadie podría entrar nunca');
assertPermisos(($rutas['POST /login']['permiso'] ?? '') === 'publica',
    'el envío del login es público');
assertPermisos(($rutas['GET /']['permiso'] ?? '') === 'libre',
    'el inicio es libre: es la pantalla a la que se cae quien no puede entrar a otra');

if ($fallos > 0) {
    fwrite(STDERR, "PermisosCatalogo: {$fallos} fallas\n");
    exit(1);
}

echo "OK: PermisosCatalogo (" . count($registradas) . " rutas, " . count($todas) . " permisos)\n";
exit(0);
