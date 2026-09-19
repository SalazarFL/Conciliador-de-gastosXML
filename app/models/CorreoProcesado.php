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
 *
 * La marca es POR EMPRESA, no por buzón. Un buzón puede servir a varias
 * empresas del grupo (correo_cuenta_sociedades), y "este correo ya lo trabajé"
 * es una afirmación de cada una: la segunda tiene que poder recorrer los
 * mismos mensajes y quedarse con lo que le toca, sin que la primera se los
 * haya tapado. Dentro de una empresa la marca sigue evitando el duplicado,
 * que es para lo que existe.
 */

class CorreoProcesado extends Model
{
    protected $table = 'correo_procesados';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    /** Las marcas de una empresa: ['clave' => fecha, ...] (se cachean). */
    public function todas($sociedadId = 0)
    {
        $marcas = [];
        $filas = $this->fetchAll(
            "SELECT clave, procesado_en FROM {$this->table} WHERE sociedad_id = ?",
            [max(0, (int) $sociedadId)]
        );
        foreach ($filas as $fila) {
            $marcas[(string) $fila['clave']] = (string) $fila['procesado_en'];
        }
        return $marcas;
    }

    /**
     * Las marcas de ESTAS claves: ['clave' => fecha, …].
     *
     * Es lo que hay que preguntar para pintar una página de resultados. Antes
     * se bajaban todas las de la empresa (`todas()`) para mirar 500: medido,
     * 100 000 marcas son ~10,6 MB en memoria, y la tabla solo crece.
     */
    public function deClaves(array $claves, $sociedadId = 0)
    {
        $claves = array_values(array_unique(array_filter(array_map('strval', $claves), 'strlen')));
        if (empty($claves)) {
            return [];
        }

        $sociedadId = max(0, (int) $sociedadId);
        $marcas = [];
        foreach (array_chunk($claves, 200) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $filas = $this->fetchAll(
                "SELECT clave, procesado_en FROM {$this->table}
                  WHERE sociedad_id = ? AND clave IN ({$marcadores})",
                array_merge([$sociedadId], $lote)
            ) ?: [];
            foreach ($filas as $fila) {
                $marcas[(string) $fila['clave']] = (string) $fila['procesado_en'];
            }
        }
        return $marcas;
    }

    /** Marca un correo como procesado por una empresa (atómico por fila). */
    public function marcar($clave, $sociedadId = 0)
    {
        $this->execute(
            "INSERT INTO {$this->table} (sociedad_id, clave, procesado_en) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE procesado_en = NOW()",
            [max(0, (int) $sociedadId), (string) $clave]
        );
    }

    /**
     * Quita marcas para que esos correos vuelvan a aparecer como pendientes.
     * Solo las de la empresa indicada: destapar los de una no puede destapar
     * los de la otra.
     */
    public function desmarcar(array $claves, $sociedadId = 0)
    {
        $claves = array_values(array_filter(array_map('strval', $claves), 'strlen'));
        if (empty($claves)) {
            return 0;
        }

        $sociedadId = max(0, (int) $sociedadId);
        $quitadas = 0;
        foreach (array_chunk($claves, 200) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $quitadas += (int) $this->execute(
                "DELETE FROM {$this->table} WHERE sociedad_id = ? AND clave IN ({$marcadores})",
                array_merge([$sociedadId], $lote)
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
                    sociedad_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'empresa que lo trabajó',
                    clave VARCHAR(100) NOT NULL COMMENT 'c{cuenta}:uidvalidity:uid del mensaje',
                    procesado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (sociedad_id, clave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->agregarEmpresa();
        $this->importarLegado();
    }

    /**
     * Las marcas nacieron sin empresa, cuando había una sola.
     *
     * Se les agrega la columna y se rehace la clave primaria. Las que ya están
     * NO pueden quedar en 0: eso las volvería "de nadie", y la empresa que sí
     * trabajó esos catorce mil correos los vería reaparecer todos como
     * pendientes. Se le asignan a la empresa por omisión, que es la única que
     * existía cuando se pusieron.
     */
    private function agregarEmpresa()
    {
        try {
            $tiene = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'sociedad_id'",
                [$this->table]
            );
            if ($tiene > 0) {
                return;
            }

            $base = $this->empresaPorOmision();
            $this->query("ALTER TABLE {$this->table}
                          ADD COLUMN sociedad_id INT UNSIGNED NOT NULL DEFAULT 0
                              COMMENT 'empresa que lo trabajó' FIRST");
            if ($base > 0) {
                $this->execute("UPDATE {$this->table} SET sociedad_id = ?", [$base]);
            }
            $this->query("ALTER TABLE {$this->table}
                          DROP PRIMARY KEY, ADD PRIMARY KEY (sociedad_id, clave)");
        } catch (Throwable $e) {
            // Sin permisos de DDL sigue funcionando como antes (una empresa).
        }
    }

    /** Con qué empresa se quedan las marcas que no dicen de quién son. */
    private function empresaPorOmision()
    {
        try {
            $base = (int) $this->fetchColumn(
                'SELECT id FROM sociedades WHERE activa = 1 ORDER BY id LIMIT 1'
            );
            return $base > 0 ? $base : (int) $this->fetchColumn('SELECT MIN(id) FROM sociedades');
        } catch (Throwable $e) {
            return 0;
        }
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

        // El archivo es de cuando había una sola empresa: sus marcas son de la
        // empresa por omisión.
        $base = $this->empresaPorOmision();

        $data = json_decode((string) file_get_contents($archivo), true);
        if (is_array($data) && !empty($data)) {
            foreach (array_chunk(array_keys($data), 200) as $lote) {
                $valores = [];
                $params  = [];
                foreach ($lote as $clave) {
                    $ts = is_string($data[$clave]) ? strtotime($data[$clave]) : false;
                    $valores[] = '(?, ?, ?)';
                    $params[]  = $base;
                    $params[]  = (string) $clave;
                    $params[]  = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
                }
                $this->execute(
                    "INSERT IGNORE INTO {$this->table} (sociedad_id, clave, procesado_en) VALUES "
                    . implode(',', $valores),
                    $params
                );
            }
        }

        @rename($archivo, $archivo . '.migrado');
    }
}
