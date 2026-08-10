<?php
/**
 * Modelo de items en cola para importaciones masivas de facturas.
 */

class ImportacionItem extends Model
{
    protected $table = 'importacion_items';

    // Copia del XML a la espera de su turno en la cola, en la carpeta
    // compartida: la cola se procesa en varias peticiones y puede continuarla
    // otra persona desde otra computadora.
    protected $camposRuta = ['ruta_archivo'];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table}
                (importacion_id, archivo_original, archivo_guardado, ruta_archivo, extension, tamano, estado, intentos, factura_id, error_texto, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        return $this->insert($sql, [
            (int) ($data['importacion_id'] ?? 0),
            (string) ($data['archivo_original'] ?? ''),
            $data['archivo_guardado'] ?? null,
            RutaDocumento::relativa($data['ruta_archivo'] ?? ''),
            strtolower((string) ($data['extension'] ?? '')),
            (int) ($data['tamano'] ?? 0),
            (string) ($data['estado'] ?? 'pendiente'),
            (int) ($data['intentos'] ?? 0),
            isset($data['factura_id']) ? (int) $data['factura_id'] : null,
            $data['error_texto'] ?? null,
            isset($data['metadata']) ? $this->encodeMetadata($data['metadata']) : null,
        ]);
    }

    public function getStats($importacionId)
    {
        $rows = $this->fetchAll(
            "SELECT estado, COUNT(*) AS total
             FROM {$this->table}
             WHERE importacion_id = ?
             GROUP BY estado",
            [(int) $importacionId]
        );

        $stats = [
            'total' => 0,
            'pendiente' => 0,
            'procesando' => 0,
            'importado' => 0,
            'duplicado' => 0,
            'sin_plantilla' => 0,
            'error' => 0,
        ];

        foreach ($rows as $row) {
            $estado = (string) ($row['estado'] ?? '');
            $total = (int) ($row['total'] ?? 0);

            if (!array_key_exists($estado, $stats)) {
                $stats[$estado] = 0;
            }

            $stats[$estado] += $total;
            $stats['total'] += $total;
        }

        $stats['terminales'] = $stats['importado'] + $stats['duplicado'] + $stats['sin_plantilla'] + $stats['error'];
        $stats['pendientes_reales'] = $stats['pendiente'] + $stats['procesando'];

        return $stats;
    }

    public function getRecentIssues($importacionId, $limit = 10)
    {
        $limit = max(1, min(50, (int) $limit));
        $sql = "SELECT id, archivo_original, estado, error_texto, processed_at
                FROM {$this->table}
                WHERE importacion_id = ?
                  AND estado IN ('duplicado', 'sin_plantilla', 'error')
                ORDER BY id DESC
                LIMIT {$limit}";

        return $this->fetchAll($sql, [(int) $importacionId]);
    }

    public function claimPendingBatch($importacionId, $limit = 5)
    {
        $limit = max(1, min(50, (int) $limit));
        $sql = "SELECT *
                FROM {$this->table}
                WHERE importacion_id = ?
                  AND estado = 'pendiente'
                ORDER BY id ASC
                LIMIT {$limit}";

        $rows = $this->fetchAll($sql, [(int) $importacionId]);
        $claimed = [];

        foreach ($rows as $row) {
            $updated = $this->execute(
                "UPDATE {$this->table}
                 SET estado = 'procesando', intentos = intentos + 1
                 WHERE id = ? AND estado = 'pendiente'",
                [(int) $row['id']]
            );

            if ($updated > 0) {
                $row['estado'] = 'procesando';
                $row['intentos'] = ((int) ($row['intentos'] ?? 0)) + 1;
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function requeueStaleProcessing($olderThanSeconds = 180, $importacionId = null)
    {
        $seconds = max(60, (int) $olderThanSeconds);
        $params = [];
        $where = "estado = 'procesando' AND updated_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)";

        if ($importacionId !== null) {
            $where .= " AND importacion_id = ?";
            $params[] = (int) $importacionId;
        }

        $sql = "UPDATE {$this->table}
                SET estado = 'pendiente'
                WHERE {$where}";

        return $this->execute($sql, $params);
    }

    public function claimPendingBatchGlobal($limit = 5)
    {
        $limit = max(1, min(50, (int) $limit));
        $sql = "SELECT *
                FROM {$this->table}
                WHERE estado = 'pendiente'
                ORDER BY id ASC
                LIMIT {$limit}";

        $rows = $this->fetchAll($sql);
        $claimed = [];

        foreach ($rows as $row) {
            $updated = $this->execute(
                "UPDATE {$this->table}
                 SET estado = 'procesando', intentos = intentos + 1
                 WHERE id = ? AND estado = 'pendiente'",
                [(int) $row['id']]
            );

            if ($updated > 0) {
                $row['estado'] = 'procesando';
                $row['intentos'] = ((int) ($row['intentos'] ?? 0)) + 1;
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function marcarResultado($id, $estado, $errorTexto = null, $facturaId = null, $metadata = null)
    {
        $sql = "UPDATE {$this->table}
                SET estado = ?, error_texto = ?, factura_id = ?, metadata = ?, processed_at = NOW()
                WHERE id = ?";

        return $this->execute($sql, [
            (string) $estado,
            $errorTexto,
            $facturaId !== null ? (int) $facturaId : null,
            $metadata !== null ? $this->encodeMetadata($metadata) : null,
            (int) $id,
        ]);
    }

    private function ensureTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    importacion_id BIGINT UNSIGNED NOT NULL,
                    archivo_original VARCHAR(255) NOT NULL,
                    archivo_guardado VARCHAR(255) DEFAULT NULL,
                    ruta_archivo VARCHAR(500) NOT NULL,
                    extension VARCHAR(10) NOT NULL,
                    tamano BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    estado VARCHAR(30) NOT NULL DEFAULT 'pendiente',
                    intentos INT UNSIGNED NOT NULL DEFAULT 0,
                    factura_id BIGINT UNSIGNED DEFAULT NULL,
                    error_texto TEXT DEFAULT NULL,
                    metadata LONGTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    processed_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_importacion_estado (importacion_id, estado, id),
                    KEY idx_estado_updated (estado, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->query($sql);
    }

    private function encodeMetadata($metadata)
    {
        if ($metadata === null) {
            return null;
        }

        if (is_string($metadata)) {
            return $metadata;
        }

        return json_encode($metadata, JSON_UNESCAPED_UNICODE);
    }
}
