<?php
/**
 * Las reglas de la marca a mano, que es lo que separa este módulo de un
 * listado: el estado que alguien pone manda sobre el cálculo y no se mueve
 * solo, mandar algo a revisión exige explicar por qué, y siempre se puede
 * devolver un renglón al cálculo.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertMarca($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Recoge lo que se habría escrito, sin base. */
class SeguimientoMarcaFalso extends Seguimiento
{
    public $escrituras = [];

    public function __construct() {}

    protected function execute($sql, $params = [])
    {
        $this->escrituras[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }
    protected function fetchAll($sql, $params = []) { return []; }
    protected function fetchOne($sql, $params = []) { return null; }
    public function begin()    { return true; }
    public function commit()   { return true; }
    public function rollback() { return true; }

    /** El INSERT del upsert, que es donde se ve qué estado se guardó. */
    public function insertSeguimiento()
    {
        foreach ($this->escrituras as $e) {
            if (strpos($e['sql'], 'INSERT INTO seguimiento') === 0
                && strpos($e['sql'], 'seguimiento_bitacora') === false) {
                return $e;
            }
        }
        return null;
    }
}

$items = [['origen' => 'factura', 'referencia_id' => 7]];
$usuario = ['id' => 1, 'nombre' => 'Ana'];

// ── Revisión exige decir cuál es el problema ────────────────────────────────
$fallo = '';
try {
    (new SeguimientoMarcaFalso())->aplicar($items, ['estado' => 'revision'], $usuario);
} catch (Throwable $e) {
    $fallo = $e->getMessage();
}
assertMarca($fallo !== '', 'no deja mandar a revisión sin describir el problema');

$fallo = '';
try {
    (new SeguimientoMarcaFalso())->aplicar($items, ['estado' => 'revision', 'motivo' => '   '], $usuario);
} catch (Throwable $e) {
    $fallo = $e->getMessage();
}
assertMarca($fallo !== '', 'un motivo de puros espacios no cuenta como descripción');

$m = new SeguimientoMarcaFalso();
$m->aplicar($items, ['estado' => 'revision', 'motivo' => 'El proveedor no contesta'], $usuario);
assertMarca(in_array('revision', $m->insertSeguimiento()['params'], true),
    'con motivo sí guarda la marca de revisión');

// Los demás estados no exigen motivo: exigirlo volvería lenta una tanda de
// cincuenta renglones que se cierran de golpe.
$m = new SeguimientoMarcaFalso();
$m->aplicar($items, ['estado' => 'cerrada'], $usuario);
assertMarca(in_array('cerrada', $m->insertSeguimiento()['params'], true),
    'cerrar no exige motivo');

// ── Quitar la marca guarda NULL, no un estado ───────────────────────────────
$m = new SeguimientoMarcaFalso();
$m->aplicar($items, ['estado' => Seguimiento::SIN_MARCA], $usuario);
$insert = $m->insertSeguimiento();
assertMarca(!in_array(Seguimiento::SIN_MARCA, $insert['params'], true),
    'SIN_MARCA no se guarda como si fuera un estado');
assertMarca(in_array(null, $insert['params'], true),
    'quitar la marca escribe NULL: el renglón vuelve al cálculo');
assertMarca(strpos($insert['sql'], 'estado = VALUES(estado)') !== false,
    'quitar la marca sí pisa la columna, no la deja como estaba');

// ── Una anotación suelta no toca el estado ──────────────────────────────────
$m = new SeguimientoMarcaFalso();
$m->aplicar($items, ['comentario' => 'Se le escribió al proveedor'], $usuario);
assertMarca(strpos($m->insertSeguimiento()['sql'], 'estado = VALUES(estado)') === false,
    'anotar no congela el estado de un renglón que nadie clasificó');

// ── Un estado inventado no llega a la base ──────────────────────────────────
$fallo = '';
try {
    (new SeguimientoMarcaFalso())->aplicar($items, ['estado' => 'resuelto'], $usuario);
} catch (Throwable $e) {
    $fallo = $e->getMessage();
}
assertMarca($fallo !== '', 'los estados retirados ya no se aceptan');

echo "OK: Marca a mano del seguimiento\n";
