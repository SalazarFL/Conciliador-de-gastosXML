<?php
/**
 * Marcas de "correo ya procesado" (deduplicación entre corridas).
 *
 * Antes vivían en storage/correo/procesados.json, pero el archivo se
 * reescribía COMPLETO en cada marca (leer-modificar-escribir): con varios
 * usuarios procesando a la vez, dos peticiones podían pisarse y perder
 * marcas (correos que reaparecen como pendientes o se importan dos veces).
 * En MySQL cada marca es una fila y el INSERT/DELETE es atómico.
 *
 * La clave es "c{cuenta}:uidvalidity:uid" (el prefijo de cuenta evita
 * colisiones entre buzones; ver MailFetcher::claveMensaje). Al crear la
 * tabla se importa el procesados.json existente y el archivo se renombra
 * a .migrado como respaldo: la tabla queda como única fuente.
 */

class CorreoProcesado extends Model
{
    protected $table = 'correo_procesados';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    /** Todas las marcas: ['clave' => fecha, ...] (para cachear en memoria). */
    public function todas()
    {
        $marcas = [];
        foreach ($this->fetchAll("SELECT clave, procesado_en FROM {$this->table}") as $fila) {
            $marcas[(string) $fila['clave']] = (string) $fila['procesado_en'];
        }
        return $marcas;
    }

    /** Marca un correo como procesado (idempotente, atómico por fila). */
    public function marcar($clave)
    {
        $this->execute(
            "INSERT INTO {$this->table} (clave, procesado_en) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE procesado_en = NOW()",
            [(string) $clave]
        );
    }

    /** Quita marcas para que esos correos vuelvan a aparecer como pendientes. */
    public function desmarcar(array $claves)
    {
        $claves = array_values(array_filter(array_map('strval', $claves), 'strlen'));
        if (empty($claves)) {
            return 0;
        }

        $quitadas = 0;
        foreach (array_chunk($claves, 200) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $quitadas += (int) $this->execute(
                "DELETE FROM {$this->table} WHERE clave IN ({$marcadores})",
                $lote
            );
        }
        return $quitadas;
    }

    /**
     * Prefija las claves sin cuenta ("uidvalidity:uid" → "c{id}:...") al
     * sembrar la cuenta principal desde el archivo de configuración.
     * Las claves con cuenta empiezan con "c"; las legado, con dígito.
     */
    public function prefijarCuenta($cuentaId)
    {
        return $this->execute(
            "UPDATE {$this->table} SET clave = CONCAT('c', ?, ':', clave) WHERE clave NOT LIKE 'c%'",
            [(int) $cuentaId]
        );
    }

    // ── Tabla + importación única del procesados.json legado ───────

    private function ensureTable()
    {
        $this->query("CREATE TABLE IF NOT EXISTS {$this->table} (
                    clave VARCHAR(100) NOT NULL COMMENT 'c{cuenta}:uidvalidity:uid del mensaje',
                    procesado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (clave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->importarLegado();
    }

    /**
     * Importa el procesados.json anterior (si sigue en disco) y lo renombra
     * a .migrado como respaldo. INSERT IGNORE: si la clave ya existe en la
     * tabla manda la tabla, que es la fuente viva tras la migración.
     */
    private function importarLegado()
    {
        $archivo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'correo' . DIRECTORY_SEPARATOR . 'procesados.json';
        if (!is_file($archivo)) {
            return;
        }

        $data = json_decode((string) file_get_contents($archivo), true);
        if (is_array($data) && !empty($data)) {
            foreach (array_chunk(array_keys($data), 200) as $lote) {
                $valores = [];
                $params  = [];
                foreach ($lote as $clave) {
                    $ts = is_string($data[$clave]) ? strtotime($data[$clave]) : false;
                    $valores[] = '(?, ?)';
                    $params[]  = (string) $clave;
                    $params[]  = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
                }
                $this->execute(
                    "INSERT IGNORE INTO {$this->table} (clave, procesado_en) VALUES " . implode(',', $valores),
                    $params
                );
            }
        }

        @rename($archivo, $archivo . '.migrado');
    }
}
