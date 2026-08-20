<?php
/**
 * La marca de "la base promete un archivo que no está".
 *
 * Lo que se comprueba es que ponerla y quitarla no cueste una consulta por
 * documento ni mande diez mil identificadores en cada revisión: el organizador
 * corre cada pocos minutos sobre el archivo entero, y esto se ejecuta siempre.
 *
 * Y sobre todo, que fuera de lo revisado no se toque nada. Revisar una semana
 * no dice nada de los documentos de las demás; borrarles la marca porque no
 * aparecieron en esa lista sería decir que su archivo está, sin haberlo mirado.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Factura.php';

function assertArchivoFaltante($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

class FacturaMarcaFalsa extends Factura
{
    public $consultas = [];

    public function __construct() {}

    protected function execute($sql, $params = [])
    {
        $this->consultas[] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'params' => $params];
        return 1;
    }
}

// ── Poner y quitar en una revisión completa ─────────────────────────────────
$modelo = new FacturaMarcaFalsa();
$modelo->marcarArchivosFaltantes([12, 40, 40, 0, 7], []);

assertArchivoFaltante(count($modelo->consultas) === 2,
    'poner y quitar son dos consultas, no una por documento');

$pone = $modelo->consultas[0];
assertArchivoFaltante(strpos($pone['sql'], 'archivo_faltante_en = NOW()') !== false
    && strpos($pone['sql'], 'archivo_faltante_en IS NULL') !== false,
    'solo se marca lo que no estaba marcado, para no renovar la fecha en cada corrida');
assertArchivoFaltante($pone['params'] === [12, 40, 7],
    'los identificadores repetidos y el cero no llegan a la consulta');

$quita = $modelo->consultas[1];
assertArchivoFaltante(strpos($quita['sql'], 'archivo_faltante_en = NULL') !== false
    && strpos($quita['sql'], 'NOT IN') !== false,
    'la limpieza va por negación: los revisados que no faltan');
assertArchivoFaltante(strpos($quita['sql'], ' IN (') === strpos($quita['sql'], ' NOT IN (') + 4,
    'sin ámbito no se acota por identificadores: la revisión fue de todo');

// ── Revisando solo unos cuantos ────────────────────────────────────────────
$parcial = new FacturaMarcaFalsa();
$parcial->marcarArchivosFaltantes([40], [12, 40, 7]);

$quitaParcial = $parcial->consultas[1];
assertArchivoFaltante($quitaParcial['params'] === [12, 40, 7, 40],
    'la limpieza se encierra en lo revisado y excluye a los que sí faltan');

// ── Nada que marcar ────────────────────────────────────────────────────────
$limpio = new FacturaMarcaFalsa();
$limpio->marcarArchivosFaltantes([], [5, 6]);
assertArchivoFaltante(count($limpio->consultas) === 1,
    'sin faltantes no se ejecuta la consulta que marca');
assertArchivoFaltante(strpos($limpio->consultas[0]['sql'], 'NOT IN') === false
    && $limpio->consultas[0]['params'] === [5, 6],
    'y la limpieza alcanza a todo lo revisado');

// ── Muchos faltantes ───────────────────────────────────────────────────────
// Un pago semanal entero son cientos de documentos; el marcado va por grupos
// para no armar una consulta con mil parámetros.
$muchos = new FacturaMarcaFalsa();
$muchos->marcarArchivosFaltantes(range(1, 1200), []);
$grupos = array_slice($muchos->consultas, 0, count($muchos->consultas) - 1);
assertArchivoFaltante(count($grupos) === 3, 'los faltantes se marcan de a 500');
assertArchivoFaltante(count($grupos[0]['params']) === 500 && count($grupos[2]['params']) === 200,
    'y el último grupo lleva lo que sobra');

echo "OK: Marca de archivo faltante\n";
