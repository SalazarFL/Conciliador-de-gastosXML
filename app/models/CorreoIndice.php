<?php
/**
 * Índice local de encabezados del buzón IMAP.
 *
 * Buscar por asunto/remitente contra el servidor de correo cuesta varios
 * viajes de red POR CARPETA (30+ carpetas = búsquedas de muchos segundos).
 * Este índice guarda los encabezados en MySQL y se sincroniza incremental
 * (solo baja lo nuevo por carpeta), así la búsqueda es una consulta local
 * de milisegundos. El contenido de los correos NO se indexa; sí se guardan
 * los NOMBRES de los adjuntos (léase sin bajar el archivo, solo la estructura
 * MIME), porque el número/clave de la factura suele venir en el nombre del
 * XML/PDF aunque el asunto sea genérico. La búsqueda "+ contenido" sigue
 * yendo al servidor.
 *
 * Cada fila pertenece a una cuenta de correo (cuenta_id): antes de usar el
 * modelo hay que llamar setCuenta() con la cuenta elegida.
 */

class CorreoIndice extends Model
{
    protected $table = 'correo_indice';

    /** Cuenta de correo a la que se limitan todas las consultas. */
    private $cuentaId = 0;

    public function __construct()
    {
        $this->ensureTables();
    }

    public function setCuenta($cuentaId)
    {
        $this->cuentaId = (int) $cuentaId;
        return $this;
    }

    // ── Búsqueda local (rápida) ────────────────────────────────────

    /**
     * Busca en el índice. $texto se parte en términos y TODOS deben
     * aparecer (substring, sin distinguir mayúsculas) en el campo del
     * ámbito: 'asunto', 'remitente' o 'asunto_remitente' (este último
     * incluye también el nombre de los adjuntos, así el número de factura
     * se encuentra aunque solo venga en el nombre del XML/PDF).
     *
     * Devuelve ['total' => coincidencias, 'correos' => filas] con la misma
     * forma que MailFetcher::listarMensajes (sin 'procesado').
     */
    public function buscar($texto, $ambito, $dias, $limite = 500, $mes = '')
    {
        $where = ['cuenta_id = ?'];
        $params = [$this->cuentaId];

        $dias = (int) $dias;
        if ($dias > 0) {
            $where[] = "fecha >= ?";
            $params[] = date('Y-m-d 00:00:00', strtotime("-{$dias} days"));
        }

        // Mes prioritario 'YYYY-MM' (lo manda la tarjeta de por-pagar con la
        // fecha de la factura): acota por timestamp para aprovechar su índice
        // y escanear solo los correos de ese mes en vez de todo el buzón.
        $mes = trim((string) $mes);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $inicioMes = strtotime($mes . '-01 00:00:00');
            $where[] = "timestamp >= ? AND timestamp < ?";
            $params[] = $inicioMes;
            $params[] = strtotime('+1 month', $inicioMes);
        }

        $terminos = preg_split('/\s+/', trim((string) $texto), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terminos as $termino) {
            $like = '%' . addcslashes($termino, '%_\\') . '%';

            if ($ambito === 'asunto') {
                $where[] = "asunto LIKE ?";
                $params[] = $like;
            } elseif ($ambito === 'remitente') {
                $where[] = "remitente LIKE ?";
                $params[] = $like;
            } else {
                // Por defecto también se busca en el nombre de los adjuntos:
                // ahí suele estar el número/clave de la factura.
                $where[] = "(asunto LIKE ? OR remitente LIKE ? OR adjuntos LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}{$sqlWhere}", $params);

        $limite = max(1, min(1000, (int) $limite));
        $filas = $this->fetchAll(
            "SELECT uid, clave, carpeta, carpeta_nombre, asunto, remitente, fecha, timestamp
             FROM {$this->table}{$sqlWhere}
             ORDER BY timestamp DESC, id DESC
             LIMIT {$limite}",
            $params
        );

        foreach ($filas as &$fila) {
            $fila['uid'] = (int) $fila['uid'];
            $fila['timestamp'] = (int) $fila['timestamp'];
        }
        unset($fila);

        return ['total' => $total, 'correos' => $filas];
    }

    public function contarTotal()
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE cuenta_id = ?",
            [$this->cuentaId]
        );
    }

    // ── Nombres de adjuntos (se rellenan por tandas tras indexar) ──
    // adjuntos = NULL significa "aún no leído"; '' significa "sin adjuntos".

    /**
     * Correos sin los nombres de adjuntos leídos, los más recientes primero
     * (son los que más se buscan). Agrupados por carpeta para abrir cada
     * carpeta IMAP una sola vez por tanda.
     */
    public function pendientesAdjuntos($limite = 300)
    {
        $limite = max(1, min(1000, (int) $limite));
        return $this->fetchAll(
            "SELECT id, carpeta, uid FROM (
                SELECT id, carpeta, uid, timestamp FROM {$this->table}
                WHERE cuenta_id = ? AND adjuntos IS NULL
                ORDER BY timestamp DESC LIMIT {$limite}
             ) t ORDER BY carpeta, uid",
            [$this->cuentaId]
        ) ?: [];
    }

    public function contarPendientesAdjuntos()
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE cuenta_id = ? AND adjuntos IS NULL",
            [$this->cuentaId]
        );
    }

    public function guardarAdjuntos($id, $texto)
    {
        return $this->execute(
            "UPDATE {$this->table} SET adjuntos = ? WHERE id = ?",
            [(string) $texto, (int) $id]
        );
    }

    public function ultimaSync()
    {
        return $this->fetchColumn(
            "SELECT MAX(ultima_sync) FROM correo_carpetas WHERE cuenta_id = ?",
            [$this->cuentaId]
        );
    }

    // ── Sincronización ─────────────────────────────────────────────

    public function getEstadoCarpeta($carpeta)
    {
        return $this->fetchOne(
            "SELECT * FROM correo_carpetas WHERE cuenta_id = ? AND carpeta = ? LIMIT 1",
            [$this->cuentaId, (string) $carpeta]
        );
    }

    /**
     * Estado de todas las carpetas de la cuenta, indexado por carpeta.
     */
    public function getCarpetas()
    {
        $mapa = [];
        foreach ($this->fetchAll("SELECT * FROM correo_carpetas WHERE cuenta_id = ?", [$this->cuentaId]) as $fila) {
            $mapa[(string) $fila['carpeta']] = $fila;
        }
        return $mapa;
    }

    public function guardarEstadoCarpeta($carpeta, $uidvalidity, $ultimoUid, $mensajes)
    {
        $sql = "INSERT INTO correo_carpetas (cuenta_id, carpeta, uidvalidity, ultimo_uid, mensajes, ultima_sync)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    uidvalidity = VALUES(uidvalidity),
                    ultimo_uid = VALUES(ultimo_uid),
                    mensajes = VALUES(mensajes),
                    ultima_sync = NOW()";

        return $this->execute($sql, [$this->cuentaId, (string) $carpeta, (int) $uidvalidity, (int) $ultimoUid, (int) $mensajes]);
    }

    public function vaciarCarpeta($carpeta)
    {
        return $this->execute(
            "DELETE FROM {$this->table} WHERE cuenta_id = ? AND carpeta = ?",
            [$this->cuentaId, (string) $carpeta]
        );
    }

    public function contarCarpeta($carpeta)
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE cuenta_id = ? AND carpeta = ?",
            [$this->cuentaId, (string) $carpeta]
        );
    }

    /**
     * Inserta encabezados en lotes (200 filas por INSERT). Filas ya
     * existentes (misma cuenta+carpeta+uidvalidity+uid) se actualizan.
     */
    public function insertarLote($carpeta, $carpetaNombre, $uidvalidity, array $filas)
    {
        $insertadas = 0;

        foreach (array_chunk($filas, 200) as $lote) {
            $values = [];
            $params = [];

            foreach ($lote as $fila) {
                $uid = (int) ($fila['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }

                $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params[] = $this->cuentaId;
                $params[] = (string) $carpeta;
                $params[] = (string) $carpetaNombre;
                $params[] = $uid;
                $params[] = (int) $uidvalidity;
                $params[] = (string) ($fila['clave'] ?? ((int) $uidvalidity . ':' . $uid));
                $params[] = $fila['remitente'] ?? null;
                $params[] = $fila['asunto'] ?? null;
                $params[] = $fila['adjuntos'] ?? null;
                $params[] = $fila['fecha'] ?? null;
                $params[] = (int) ($fila['timestamp'] ?? 0);
            }

            if (empty($values)) {
                continue;
            }

            $sql = "INSERT INTO {$this->table}
                    (cuenta_id, carpeta, carpeta_nombre, uid, uidvalidity, clave, remitente, asunto, adjuntos, fecha, timestamp)
                    VALUES " . implode(', ', $values) . "
                    ON DUPLICATE KEY UPDATE
                        carpeta_nombre = VALUES(carpeta_nombre),
                        clave = VALUES(clave),
                        remitente = VALUES(remitente),
                        asunto = VALUES(asunto),
                        adjuntos = VALUES(adjuntos),
                        fecha = VALUES(fecha),
                        timestamp = VALUES(timestamp)";

            $this->query($sql, $params);
            $insertadas += count($values);
        }

        return $insertadas;
    }

    private function ensureTables()
    {
        $this->query("CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    cuenta_id INT UNSIGNED NOT NULL DEFAULT 0,
                    carpeta VARCHAR(255) NOT NULL,
                    carpeta_nombre VARCHAR(255) NOT NULL DEFAULT '',
                    uid INT UNSIGNED NOT NULL,
                    uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    clave VARCHAR(64) NOT NULL,
                    remitente VARCHAR(255) NULL DEFAULT NULL,
                    asunto VARCHAR(255) NULL DEFAULT NULL,
                    adjuntos VARCHAR(1024) NULL DEFAULT NULL,
                    fecha DATETIME NULL DEFAULT NULL,
                    timestamp INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_cuenta_carpeta_uid (cuenta_id, carpeta(170), uidvalidity, uid),
                    KEY idx_timestamp (timestamp)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Autocurativo para índices ya creados antes de esta columna (MariaDB
        // soporta IF NOT EXISTS; si no, se ignora el error y queda la migración).
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS adjuntos VARCHAR(1024) NULL DEFAULT NULL AFTER asunto");
        } catch (Throwable $e) {
            // La columna ya existe o el motor no soporta IF NOT EXISTS: sin efecto.
        }

        $this->query("CREATE TABLE IF NOT EXISTS correo_carpetas (
                    cuenta_id INT UNSIGNED NOT NULL DEFAULT 0,
                    carpeta VARCHAR(180) NOT NULL,
                    uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    ultimo_uid INT UNSIGNED NOT NULL DEFAULT 0,
                    mensajes INT UNSIGNED NOT NULL DEFAULT 0,
                    ultima_sync DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (cuenta_id, carpeta)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
