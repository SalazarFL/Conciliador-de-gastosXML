<?php
/**
 * Modelo de la bandeja de facturas capturadas del correo (IMAP).
 *
 * Cada fila es un XML encontrado en el buzón (con su PDF emparejado si llegó
 * en el mismo correo), a la espera de que el usuario decida importarla.
 */

class CorreoBandeja extends Model
{
    // Zona de paso: el XML baja aquí antes de importarse. Aun así el listado
    // debe respetar la empresa en curso, porque un mismo buzón recibe
    // documentos de varias.
    use AlcanceSociedad;

    protected $table = 'correo_bandeja';

    // El XML y el PDF capturados esperan en la carpeta compartida, no dentro
    // del proyecto: si esperaran en storage/ de una computadora, la fila
    // existiría para todos pero el archivo solo para quien abrió el correo.
    protected $camposRuta = ['archivo_xml', 'archivo_pdf'];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table}
                (cuenta_id, sociedad_id, uid_correo, remitente, asunto, fecha_correo, archivo_xml, archivo_pdf,
                 numero_corto, tipo_doc, proveedor, fecha_emision, total, hash_xml, estado, origen)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $sociedadId = !empty($data['sociedad_id']) ? (int) $data['sociedad_id'] : $this->sociedadId();
        $origen = in_array(($data['origen'] ?? 'manual'), ['manual', 'automatica', 'descargas'], true)
            ? (string) ($data['origen'] ?? 'manual') : 'manual';

        return $this->insert($sql, [
            (int) ($data['cuenta_id'] ?? 0),
            $sociedadId > 0 ? $sociedadId : null,
            (string) ($data['uid_correo'] ?? ''),
            $data['remitente'] ?? null,
            $data['asunto'] ?? null,
            $data['fecha_correo'] ?? null,
            RutaDocumento::relativa($data['archivo_xml'] ?? '') ?: null,
            RutaDocumento::relativa($data['archivo_pdf'] ?? '') ?: null,
            $data['numero_corto'] ?? null,
            (string) ($data['tipo_doc'] ?? 'FE'),
            $data['proveedor'] ?? null,
            $data['fecha_emision'] ?? null,
            isset($data['total']) ? (float) $data['total'] : null,
            $data['hash_xml'] ?? null,
            (string) ($data['estado'] ?? 'pendiente'),
            $origen,
        ]);
    }

    /**
     * Filas visibles en la bandeja: pendientes, rechazadas por Hacienda y de
     * otra cédula. Las dos últimas no se pueden importar, pero el usuario
     * debe enterarse de por qué. Las que ya están en el sistema ni siquiera
     * llegan a la bandeja (ver CorreoController::procesarMensaje).
     */
    public function getActivas()
    {
        // Orden por id: lo último cargado a la bandeja aparece arriba,
        // sin importar la fecha del correo (una factura vieja recién
        // traída del buzón debe salir de primera).
        $params = [];
        $sql = "SELECT * FROM {$this->table}
                WHERE estado IN ('pendiente', 'rechazada', 'otra_cedula')"
             . $this->condicionSociedad('', $params) . "
                ORDER BY id DESC";
        return $this->fetchAll($sql, $params);
    }

    /**
     * Borra las filas 'ya_existe' que dejó la versión anterior de la bandeja,
     * cuando una factura ya registrada se quedaba ahí en gris sin poder
     * importarse. Devuelve las filas borradas para que el llamador elimine
     * también sus archivos.
     */
    public function purgarYaExisten()
    {
        $filas = $this->fetchAll("SELECT * FROM {$this->table} WHERE estado = 'ya_existe'");
        if (!empty($filas)) {
            $this->execute("DELETE FROM {$this->table} WHERE estado = 'ya_existe'");
        }

        return $filas;
    }

    /**
     * Historial: importadas y descartadas (oculto por defecto en la vista).
     */
    public function getHistorial($limit = 200)
    {
        $limit = max(1, min(1000, (int) $limit));
        $params = [];
        $sql = "SELECT * FROM {$this->table}
                WHERE estado IN ('importada', 'descartada')"
             . $this->condicionSociedad('', $params) . "
                ORDER BY id DESC
                LIMIT {$limit}";
        return $this->fetchAll($sql, $params);
    }

    public function getByIds(array $ids, $estado = null)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})";
        $params = $ids;

        if ($estado !== null) {
            $sql .= " AND estado = ?";
            $params[] = (string) $estado;
        }

        return $this->fetchAll($sql, $params);
    }

    public function getPorUidHash($uidCorreo, $hashXml)
    {
        $sql = "SELECT * FROM {$this->table} WHERE uid_correo = ? AND hash_xml = ? LIMIT 1";
        return $this->fetchOne($sql, [(string) $uidCorreo, (string) $hashXml]);
    }

    /**
     * Reactiva cualquier captura previa con los XML/PDF recién descargados.
     * La tabla tiene UNIQUE (uid_correo, hash_xml), por eso reprocesar el
     * mismo correo reutiliza la fila y la devuelve a estado pendiente.
     */
    public function revivir($id, $data)
    {
        $origen = in_array(($data['origen'] ?? 'manual'), ['manual', 'automatica', 'descargas'], true)
            ? (string) ($data['origen'] ?? 'manual') : 'manual';
        $sql = "UPDATE {$this->table} SET
                    cuenta_id = ?, sociedad_id = ?, remitente = ?, asunto = ?, fecha_correo = ?,
                    archivo_xml = ?, archivo_pdf = ?, numero_corto = ?, tipo_doc = ?,
                    proveedor = ?, fecha_emision = ?, total = ?, estado = ?, origen = ?,
                    importacion_id = NULL
                WHERE id = ?";

        return $this->execute($sql, [
            (int) ($data['cuenta_id'] ?? 0),
            !empty($data['sociedad_id']) ? (int) $data['sociedad_id'] : null,
            $data['remitente'] ?? null,
            $data['asunto'] ?? null,
            $data['fecha_correo'] ?? null,
            RutaDocumento::relativa($data['archivo_xml'] ?? '') ?: null,
            RutaDocumento::relativa($data['archivo_pdf'] ?? '') ?: null,
            $data['numero_corto'] ?? null,
            (string) ($data['tipo_doc'] ?? 'FE'),
            $data['proveedor'] ?? null,
            $data['fecha_emision'] ?? null,
            isset($data['total']) ? (float) $data['total'] : null,
            (string) ($data['estado'] ?? 'pendiente'),
            $origen,
            (int) $id,
        ]);
    }

    public function existePorHash($hashXml)
    {
        $sql = "SELECT 1 FROM {$this->table}
                WHERE hash_xml = ? AND estado IN ('pendiente', 'importada')
                LIMIT 1";
        return (bool) $this->fetchColumn($sql, [(string) $hashXml]);
    }

    public function marcarImportadas(array $ids, $importacionId)
    {
        return $this->actualizarEstado($ids, 'importada', (int) $importacionId);
    }

    public function marcarImportadasGeneral(array $ids)
    {
        return $this->actualizarEstado($ids, 'importada');
    }

    public function marcarDescartadas(array $ids)
    {
        return $this->actualizarEstado($ids, 'descartada');
    }

    private function actualizarEstado(array $ids, $estado, $importacionId = null)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($importacionId !== null) {
            $sql = "UPDATE {$this->table} SET estado = ?, importacion_id = ? WHERE id IN ({$placeholders})";
            $params = array_merge([(string) $estado, (int) $importacionId], $ids);
        } else {
            $sql = "UPDATE {$this->table} SET estado = ? WHERE id IN ({$placeholders})";
            $params = array_merge([(string) $estado], $ids);
        }

        return $this->execute($sql, $params);
    }

    public function contarPorEstado()
    {
        $params = [];
        $rows = $this->fetchAll(
            "SELECT estado, COUNT(*) AS total FROM {$this->table} WHERE 1=1"
            . $this->condicionSociedad('', $params) . ' GROUP BY estado',
            $params
        );

        $conteo = ['pendiente' => 0, 'importada' => 0, 'descartada' => 0, 'ya_existe' => 0, 'rechazada' => 0, 'otra_cedula' => 0];
        foreach ($rows as $row) {
            $conteo[(string) $row['estado']] = (int) $row['total'];
        }

        return $conteo;
    }

    private function ensureTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    cuenta_id INT UNSIGNED NOT NULL DEFAULT 0,
                    sociedad_id INT UNSIGNED NULL DEFAULT NULL,
                    uid_correo VARCHAR(64) NOT NULL COMMENT 'uidvalidity:uid del mensaje IMAP (dedup)',
                    remitente VARCHAR(255) NULL DEFAULT NULL,
                    asunto VARCHAR(255) NULL DEFAULT NULL,
                    fecha_correo DATETIME NULL DEFAULT NULL,
                    archivo_xml VARCHAR(500) NULL DEFAULT NULL,
                    archivo_pdf VARCHAR(500) NULL DEFAULT NULL,
                    numero_corto VARCHAR(50) NULL DEFAULT NULL,
                    tipo_doc VARCHAR(4) NOT NULL DEFAULT 'FE',
                    proveedor VARCHAR(255) NULL DEFAULT NULL,
                    fecha_emision DATE NULL DEFAULT NULL,
                    total DECIMAL(15,2) NULL DEFAULT NULL,
                    hash_xml CHAR(64) NULL DEFAULT NULL,
                    estado ENUM('pendiente','importada','descartada','ya_existe','rechazada','otra_cedula') NOT NULL DEFAULT 'pendiente',
                    origen ENUM('manual','automatica','descargas') NOT NULL DEFAULT 'manual',
                    importacion_id INT UNSIGNED NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_uid_xml (uid_correo, hash_xml),
                    KEY idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->query($sql);
        $tieneOrigen = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'origen'",
            [$this->table]
        ) > 0;
        if (!$tieneOrigen) {
            $this->query("ALTER TABLE {$this->table}
                          ADD COLUMN origen ENUM('manual','automatica','descargas')
                          NOT NULL DEFAULT 'manual' AFTER estado");
        }
    }
}
