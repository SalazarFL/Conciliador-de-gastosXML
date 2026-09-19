<?php
/**
 * Seguimiento con dos personas —o dos cargas de página— a la vez.
 *
 * PHP corre en un solo hilo, así que la simultaneidad se arma a mano: una
 * tabla en memoria compartida por dos modelos, y un gancho que, en cuanto el
 * primero toca `seguimiento`, hace entrar al segundo. La tabla respeta los
 * candados como InnoDB: si el primero leyó con FOR UPDATE, escribió dentro de
 * su transacción o tomó un GET_LOCK, el segundo espera a que confirme. Por eso
 * estas pruebas no dependen de cómo se arregle la carrera: con cualquier
 * bloqueo correcto pasan, y sin bloqueo reproducen lo que pasa hoy.
 */
require_once __DIR__ . '/soporte/SeguimientoSoporte.php';

Prueba::iniciar('Seguimiento con escrituras simultáneas');

final class TablaCompartida
{
    /** 'origen|id' => ['id' => int, 'estado' => ?string, 'avisado_en' => ?string] */
    public $filas = [];
    /** Filas de bitácora, asociativas y en orden de inserción. */
    public $bitacora = [];
    public $siguienteId = 1000;
    /** Candados de fila vivos: clave => dueño. */
    public $candados = [];
    /** GET_LOCK: nombre => dueño. */
    public $candadosConNombre = [];
    /** Trabajos esperando a que un dueño suelte: dueño => [callable]. */
    public $enEspera = [];

    /** Corre el trabajo ya, o lo deja esperando si el dueño retiene alguna clave. */
    public function cuandoLibre(array $claves, string $dueno, callable $trabajo): void
    {
        foreach ($claves as $clave) {
            if (($this->candados[$clave] ?? null) === $dueno) {
                $this->enEspera[$dueno][] = $trabajo;
                return;
            }
        }
        $trabajo();
    }
}

class SeguimientoConcurrente extends SeguimientoGrabadora
{
    /** Se ejecuta una vez, justo después de la primera sentencia sobre `seguimiento`. */
    public $alTocarSeguimiento = null;
    private $tabla;
    private $nombre;
    private $enTransaccion = false;

    public function __construct(TablaCompartida $tabla, string $nombre)
    {
        $this->tabla = $tabla;
        $this->nombre = $nombre;
    }

    public function begin()
    {
        parent::begin();
        $this->enTransaccion = true;
        return true;
    }

    public function commit()
    {
        parent::commit();
        $this->soltar();
        return true;
    }

    public function rollback()
    {
        parent::rollback();
        $this->soltar();
        return true;
    }

    protected function fetchAll($sql, $params = [])
    {
        parent::fetchAll($sql, $params);
        if (!preg_match('/FROM\s+seguimiento\b/i', $sql)) {
            return [];
        }
        $paraActualizar = stripos($sql, 'FOR UPDATE') !== false;
        $filas = [];

        if (stripos($sql, '(origen, referencia_id) IN') !== false) {
            for ($i = 0; $i + 1 < count($params); $i += 2) {
                $clave = $params[$i] . '|' . (int) $params[$i + 1];
                if (isset($this->tabla->filas[$clave])) {
                    $f = $this->tabla->filas[$clave];
                    $filas[] = ['id' => $f['id'], 'origen' => $params[$i], 'referencia_id' => (int) $params[$i + 1], 'estado' => $f['estado']];
                    if ($paraActualizar) {
                        $this->bloquear($clave);
                    }
                }
            }
        } elseif (stripos($sql, 'recordar_en') !== false) {
            foreach ($this->tabla->filas as $clave => $f) {
                if ($f['estado'] === 'revision' && $f['avisado_en'] === null) {
                    [$origen, $ref] = explode('|', $clave);
                    $filas[] = ['id' => $f['id'], 'origen' => $origen, 'referencia_id' => $ref, 'motivo' => 'No contesta',
                                'responsable' => null, 'recordar_en' => '2026-09-01 08:00:00', 'recordar_cada' => null];
                    if ($paraActualizar) {
                        $this->bloquear($clave);
                    }
                }
            }
        }
        $this->tocar();
        return $filas;
    }

    protected function fetchColumn($sql, $params = [])
    {
        parent::fetchColumn($sql, $params);
        if (stripos($sql, 'GET_LOCK') !== false) {
            $nombre = (string) ($params[0] ?? 'seguimiento');
            $dueno = $this->tabla->candadosConNombre[$nombre] ?? null;
            if ($dueno === null || $dueno === $this->nombre) {
                $this->tabla->candadosConNombre[$nombre] = $this->nombre;
                return 1;
            }
            return 0;
        }
        if (stripos($sql, 'RELEASE_LOCK') !== false) {
            unset($this->tabla->candadosConNombre[(string) ($params[0] ?? 'seguimiento')]);
            return 1;
        }
        return 0;
    }

    protected function execute($sql, $params = [])
    {
        parent::execute($sql, $params);
        $afectadas = 1;

        if (preg_match('/INSERT\s+(IGNORE\s+)?INTO\s+seguimiento\s*\(([^)]*)\)/i', $sql, $m)) {
            $columnas = array_map('trim', explode(',', $m[2]));
            $pisaEstado = empty($m[1]) && stripos($sql, 'estado = VALUES(estado)') !== false;
            foreach (array_chunk($params, count($columnas)) as $valores) {
                $fila = array_combine($columnas, $valores);
                $clave = $fila['origen'] . '|' . (int) $fila['referencia_id'];
                if (!isset($this->tabla->filas[$clave])) {
                    $this->tabla->filas[$clave] = ['id' => ++$this->tabla->siguienteId, 'estado' => $fila['estado'] ?? null, 'avisado_en' => null];
                } elseif ($pisaEstado) {
                    $this->tabla->filas[$clave]['estado'] = $fila['estado'];
                }
                $this->bloquear($clave);
            }
            $this->tocar();
        } elseif (preg_match('/INSERT\s+INTO\s+seguimiento_bitacora\s*\(([^)]*)\)/i', $sql, $m)) {
            $columnas = array_map('trim', explode(',', $m[1]));
            foreach (array_chunk($params, count($columnas)) as $valores) {
                $this->tabla->bitacora[] = array_combine($columnas, $valores);
            }
        } elseif (preg_match('/UPDATE\s+seguimiento\s+SET\s+avisado_en/i', $sql)) {
            $soloSinAvisar = stripos($sql, 'avisado_en IS NULL') !== false;
            $afectadas = 0;
            foreach ($this->tabla->filas as $clave => $f) {
                if (in_array($f['id'], array_map('intval', $params), true) && (!$soloSinAvisar || $f['avisado_en'] === null)) {
                    $this->tabla->filas[$clave]['avisado_en'] = 'ahora';
                    $this->bloquear($clave);
                    $afectadas++;
                }
            }
            $this->tocar();
        }
        return $afectadas;
    }

    private function bloquear(string $clave): void
    {
        if ($this->enTransaccion) {
            $this->tabla->candados[$clave] = $this->nombre;
        }
    }

    private function tocar(): void
    {
        if ($this->alTocarSeguimiento) {
            $gancho = $this->alTocarSeguimiento;
            $this->alTocarSeguimiento = null;
            $gancho();
        }
    }

    private function soltar(): void
    {
        $this->enTransaccion = false;
        foreach ($this->tabla->candados as $clave => $dueno) {
            if ($dueno === $this->nombre) {
                unset($this->tabla->candados[$clave]);
            }
        }
        $trabajos = $this->tabla->enEspera[$this->nombre] ?? [];
        unset($this->tabla->enEspera[$this->nombre]);
        foreach ($trabajos as $trabajo) {
            $trabajo();
        }
    }
}

const BETO = ['id' => 8, 'nombre' => 'Beto Prueba'];

// ── Lo que ya se cumple ─────────────────────────────────────────────────────

Prueba::caso('la lectura del estado previo, el upsert y la bitácora viven dentro de una transacción', function () {
    $m = (new SeguimientoGrabadora())->existentes(['factura|7' => ['id' => 70, 'estado' => 'revision']]);
    $m->aplicar(['factura|7'], ['estado' => 'cerrada', 'comentario' => 'Pagada'], ['id' => 7, 'nombre' => 'Ana']);
    $inicio = $m->de('begin')[0]['n'];
    $fin = $m->de('commit')[0]['n'];
    $dentro = 0;
    foreach ($m->consultas as $c) {
        if (in_array($c['tipo'], ['execute', 'fetchAll'], true)) {
            verificar($c['n'] > $inicio && $c['n'] < $fin, 'sentencia fuera de la transacción: ' . substr(trim($c['sql']), 0, 50));
            $dentro++;
        }
    }
    igual(4, $dentro, 'estado previo, upsert, ids y bitácora');
});

Prueba::caso('leer la cola, el resumen, el expediente y la bitácora no escribe ni abre transacciones', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo'], 1, 50);
    $m->resumen(['vista' => 'todo']);
    $m->uno('factura', 7);
    $m->bitacora('factura', 7);
    $m->dimensionesParaFiltro(4);
    igual([], array_values(array_filter($m->tipos(), fn($t) => !in_array($t, ['fetchAll', 'fetchOne', 'fetchColumn'], true))),
        'solo lecturas');
});

Prueba::caso('la tabla de prueba sí detecta un bloqueo correcto (control del propio montaje)', function () {
    // Un modelo que lee con FOR UPDATE: el segundo tiene que esperar al commit
    // y la bitácora tiene que quedar encadenada. Si esto fallara, las pruebas
    // de abajo no demostrarían nada.
    $tabla = new TablaCompartida();
    $tabla->filas['factura|7'] = ['id' => 70, 'estado' => 'revision', 'avisado_en' => null];
    $conCandado = new class ($tabla, 'A') extends SeguimientoConcurrente {
        protected function fetchAll($sql, $params = [])
        {
            if (strpos($sql, 'SELECT origen, referencia_id, estado FROM seguimiento') !== false) {
                $sql .= ' FOR UPDATE';
            }
            return parent::fetchAll($sql, $params);
        }
    };
    $b = new SeguimientoConcurrente($tabla, 'B');
    $conCandado->alTocarSeguimiento = function () use ($tabla, $b) {
        $tabla->cuandoLibre(['factura|7'], 'A', fn() => $b->aplicar(['factura|7'], ['estado' => 'lista'], BETO));
    };
    $conCandado->aplicar(['factura|7'], ['estado' => 'cerrada'], ['id' => 7, 'nombre' => 'Ana']);
    igual([['revision', 'cerrada'], ['cerrada', 'lista']],
        array_map(fn($f) => [$f['estado_anterior'], $f['estado_nuevo']], $tabla->bitacora), 'encadenada');
    igual('lista', $tabla->filas['factura|7']['estado'], 'gana el que entró después');
});

// ── Defectos cerrados: cada uno fue un DEFECTO ABIERTO de docs/REVISION_SEGUIMIENTO.md ──

Prueba::caso('[C1] dos personas moviendo el mismo renglón a la vez dejan una bitácora encadenada', function () {
    $tabla = new TablaCompartida();
    $tabla->filas['factura|7'] = ['id' => 70, 'estado' => 'revision', 'avisado_en' => null];
    $ana = new SeguimientoConcurrente($tabla, 'A');
    $beto = new SeguimientoConcurrente($tabla, 'B');
    // Ana lee el estado; antes de que escriba, Beto hace su cambio completo.
    $ana->alTocarSeguimiento = function () use ($tabla, $beto) {
        $tabla->cuandoLibre(['factura|7'], 'A', fn() => $beto->aplicar(['factura|7'], ['estado' => 'lista'], BETO));
    };
    $ana->aplicar(['factura|7'], ['estado' => 'cerrada'], ['id' => 7, 'nombre' => 'Ana']);

    igual([], $tabla->enEspera, 'nadie quedó esperando');
    $cadena = array_map(fn($f) => [$f['estado_anterior'], $f['estado_nuevo']], $tabla->bitacora);
    for ($i = 1; $i < count($cadena); $i++) {
        igual($cadena[$i - 1][1], $cadena[$i][0],
            'cada anotación parte de donde dejó la anterior; bitácora: ' . json_encode($cadena));
    }
    igual(end($cadena)[1], $tabla->filas['factura|7']['estado'], 'la última anotación dice el estado final');
});

Prueba::caso('[C2] dos cargas de página simultáneas no avisan dos veces el mismo recordatorio', function () {
    $tabla = new TablaCompartida();
    $tabla->filas['factura|100'] = ['id' => 5, 'estado' => 'revision', 'avisado_en' => null];
    $tabla->filas['factura|101'] = ['id' => 6, 'estado' => 'revision', 'avisado_en' => null];
    $a = new SeguimientoConcurrente($tabla, 'A');
    $b = new SeguimientoConcurrente($tabla, 'B');
    $a->alTocarSeguimiento = function () use ($tabla, $b) {
        $tabla->cuandoLibre(['factura|100', 'factura|101'], 'A', fn() => $b->generarRecordatorios());
    };
    $a->generarRecordatorios();

    $veces = [];
    foreach (Soporte::$pdo->sentencias as $s) {
        if (strpos($s['sql'], 'INSERT INTO notificaciones') !== false) {
            foreach ($s['params'] as $p) {
                if (is_string($p) && strpos($p, 'seguimiento:') === 0) {
                    $veces[$p] = ($veces[$p] ?? 0) + 1;
                }
            }
        }
    }
    igual(['seguimiento:factura:100' => 1, 'seguimiento:factura:101' => 1], $veces, 'avisos por recordatorio');
});

Prueba::caso('[C3] las tandas escriben en un orden fijo, para no interbloquearse cuando se solapan', function () {
    $m = (new SeguimientoGrabadora())->existentes([
        'factura|9' => ['id' => 1, 'estado' => null], 'nota_credito|5' => ['id' => 2, 'estado' => null],
        'factura|2' => ['id' => 3, 'estado' => null], 'factura|7' => ['id' => 4, 'estado' => null],
    ]);
    $m->aplicar(['factura|9', 'nota_credito|5', 'factura|2', 'factura|7'], ['estado' => 'cerrada'], ['id' => 7, 'nombre' => 'Ana']);
    $params = $m->de('execute', "INSERT INTO seguimiento\n")[0]['params'];
    $orden = [];
    foreach (array_chunk($params, 8) as $fila) {
        $orden[] = $fila[0] . '|' . $fila[1];
    }
    igual(['factura|2', 'factura|7', 'factura|9', 'nota_credito|5'], $orden, 'orden de escritura');
});

Prueba::caso('[C4] un interbloqueo (1213) se reintenta en vez de devolverle el error a la persona', function () {
    $m = new class extends SeguimientoGrabadora {
        public $fallosPendientes = 1;

        protected function execute($sql, $params = [])
        {
            if ($this->fallosPendientes > 0 && strpos($sql, "INSERT INTO seguimiento\n") !== false) {
                $this->fallosPendientes--;
                $e = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
                $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
                throw $e;
            }
            return parent::execute($sql, $params);
        }
    };
    $m->existentes(['factura|7' => ['id' => 70, 'estado' => 'revision']]);
    igual(['afectados' => 1], $m->aplicar(['factura|7'], ['estado' => 'cerrada'], ['id' => 7, 'nombre' => 'Ana']), 'resultado');
    $transaccion = array_values(array_filter($m->tipos(), fn($t) => in_array($t, ['begin', 'commit', 'rollback'], true)));
    igual('commit', end($transaccion), 'termina confirmada');
});

Prueba::terminar();
