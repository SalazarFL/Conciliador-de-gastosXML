<?php
/**
 * El recordatorio: solo lo pide "Mandar a revisión", se calcula con la hora
 * elegida, insiste cada tantos días y se apaga cuando el documento sale de
 * revisión.
 *
 * Se prueba sin base: lo que importa es qué SQL y qué valores salen.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertRecordatorio($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

class SeguimientoRecordatorioFalso extends Seguimiento
{
    public $escrituras = [];
    public $consultas = [];
    public $vencidos = [];

    public function __construct() {}

    protected function execute($sql, $params = [])
    {
        $this->escrituras[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }
    protected function fetchAll($sql, $params = [])
    {
        $this->consultas[] = $sql;
        return strpos($sql, 'FROM seguimiento s') !== false ? $this->vencidos : [];
    }
    protected function fetchOne($sql, $params = []) { return null; }
    public function begin()    { return true; }
    public function commit()   { return true; }
    public function rollback() { return true; }

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

// ── Se guardan momento y frecuencia juntos ──────────────────────────────────
$m = new SeguimientoRecordatorioFalso();
$m->aplicar($items, [
    'estado' => 'revision',
    'motivo' => 'El proveedor no contesta',
    'recordar_en' => '2026-08-25 07:30:00',
    'recordar_cada' => 7,
], $usuario);
$insert = $m->insertSeguimiento();
assertRecordatorio(in_array('2026-08-25 07:30:00', $insert['params'], true),
    'el momento del recordatorio se guarda con su hora');
assertRecordatorio(in_array(7, $insert['params'], true),
    'la frecuencia se guarda junto al momento');
assertRecordatorio(strpos($insert['sql'], 'avisado_en = NULL') !== false,
    'cambiar el recordatorio reinicia el último aviso; si no, seguiría callado');

// ── Salir de revisión apaga el recordatorio ─────────────────────────────────
$m = new SeguimientoRecordatorioFalso();
$m->aplicar($items, ['estado' => 'lista'], $usuario);
$insert = $m->insertSeguimiento();
assertRecordatorio(strpos($insert['sql'], 'recordar_en = VALUES(recordar_en)') !== false,
    'marcar lista apaga el recordatorio en vez de dejarlo sonando');

// Una anotación suelta no lo toca: no es un cambio de estado.
$m = new SeguimientoRecordatorioFalso();
$m->aplicar($items, ['comentario' => 'Ya le escribí'], $usuario);
// Se mira el ON DUPLICATE, no el INSERT: la lista de columnas nombra
// recordar_en siempre, pero solo el UPDATE decide si lo pisa.
assertRecordatorio(strpos($m->insertSeguimiento()['sql'], 'recordar_en = VALUES(recordar_en)') === false,
    'anotar no borra el recordatorio de algo que sigue en revisión');

// ── A quién se le avisa y cuándo ────────────────────────────────────────────
$m = new SeguimientoRecordatorioFalso();
assertRecordatorio($m->generarRecordatorios() === 0, 'sin vencidos no escribe nada');
assertRecordatorio($m->escrituras === [], 'sin vencidos no toca la base');

$sql = $m->consultas[0];
assertRecordatorio(strpos($sql, "s.estado = 'revision'") !== false,
    'solo recuerda lo que está en revisión');
assertRecordatorio(strpos($sql, 's.recordar_en <= NOW()') !== false,
    'el momento se compara con la hora, no con el día');
assertRecordatorio(strpos($sql, 'DATE_ADD(s.avisado_en, INTERVAL s.recordar_cada DAY) <= NOW()') !== false,
    'la insistencia se mide desde el último aviso');
assertRecordatorio(strpos($sql, 's.avisado_en IS NULL') !== false,
    'lo que nunca se avisó se avisa la primera vez');

echo "OK: Recordatorios de seguimiento\n";
