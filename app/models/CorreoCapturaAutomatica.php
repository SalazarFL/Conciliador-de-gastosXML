<?php
/**
 * Cola persistente de correos nuevos que la tarea automática debe revisar.
 *
 * Es deliberadamente distinta de correo_procesados: aquella tabla pertenece
 * al modo Descargas y significa "ya se importó"; aquí "capturado" significa
 * solamente que los adjuntos llegaron a correo_bandeja y todavía requieren
 * la decisión de una persona.
 */
class CorreoCapturaAutomatica extends Model
{
    protected $table = 'correo_capturas_auto';
    private static $schemaLista = false;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    /** Registra encabezados recién observados por CorreoSync, sin duplicarlos. */
    public function registrarNuevos($cuentaId, $carpeta, $uidvalidity, array $filas,
                                    $detectadosDesde = '')
    {
        $cuentaId = (int) $cuentaId;
        $uidvalidity = (int) $uidvalidity;
        $desdeTs = trim((string) $detectadosDesde) !== ''
            ? strtotime((string) $detectadosDesde) : false;
        $registrados = 0;

        foreach (array_chunk($filas, 200) as $lote) {
            $values = [];
            $params = [];
            foreach ($lote as $fila) {
                $uid = (int) ($fila['uid'] ?? 0);
                $timestamp = (int) ($fila['timestamp'] ?? 0);
                if ($cuentaId <= 0 || $uid <= 0) {
                    continue;
                }
                // En una indexación inicial no se arrastra el buzón histórico:
                // solo lo recibido desde que el usuario activó la captura.
                if ($desdeTs !== false && ($timestamp <= 0 || $timestamp < $desdeTs)) {
                    continue;
                }

                $values[] = '(?, ?, ?, ?, ?, ?, \'pendiente\')';
                $params[] = $cuentaId;
                $params[] = mb_substr((string) $carpeta, 0, 255, 'UTF-8');
                $params[] = $uidvalidity;
                $params[] = $uid;
                $params[] = 'c' . $cuentaId . ':' . $uidvalidity . ':' . $uid;
                $params[] = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : null;
            }
            if (!$values) {
                continue;
            }

            $stmt = $this->query(
                "INSERT IGNORE INTO {$this->table}
                    (cuenta_id, carpeta, uidvalidity, uid, clave, fecha_correo, estado)
                 VALUES " . implode(',', $values),
                $params
            );
            $registrados += (int) $stmt->rowCount();
        }

        return $registrados;
    }

    /**
     * Resuelve sin descargar los mensajes cuya estructura MIME ya confirma
     * que no contienen XML ni ZIP. Siguen constando como revisados.
     */
    public function resolverSinDocumentos($cuentaId)
    {
        return $this->execute(
            "UPDATE {$this->table} q
             INNER JOIN correo_indice i
                ON i.cuenta_id = q.cuenta_id
               AND i.carpeta = q.carpeta
               AND i.uidvalidity = q.uidvalidity
               AND i.uid = q.uid
             SET q.estado = 'sin_documentos',
                 q.detalle = 'Sin XML/ZIP adjunto.',
                 q.terminado_en = NOW(), q.reintentar_en = NULL
             WHERE q.cuenta_id = ?
               AND q.estado IN ('pendiente','error')
               AND i.adjuntos IS NOT NULL
               AND LOWER(i.adjuntos) NOT REGEXP '\\.(xml|zip)([[:space:]]|$)'",
            [(int) $cuentaId]
        );
    }

    /** Toma una tanda de forma atómica; errores transitorios se reintentan. */
    public function tomarPendientes($cuentaId, $limite = 10, $maxIntentos = 3)
    {
        $cuentaId = (int) $cuentaId;
        $limite = max(1, min(100, (int) $limite));
        $maxIntentos = max(1, min(10, (int) $maxIntentos));

        $this->execute(
            "UPDATE {$this->table}
             SET estado = 'error', detalle = 'Intento interrumpido; se reintentará.',
                 reintentar_en = NOW()
             WHERE cuenta_id = ? AND estado = 'procesando'
               AND iniciado_en < DATE_SUB(NOW(), INTERVAL 20 MINUTE)",
            [$cuentaId]
        );

        $db = self::getDB();
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->beginTransaction();
        }

        try {
            $filas = $this->fetchAll(
                "SELECT * FROM {$this->table}
                 WHERE cuenta_id = ?
                   AND estado IN ('pendiente','error')
                   AND intentos < ?
                   AND (reintentar_en IS NULL OR reintentar_en <= NOW())
                 ORDER BY id ASC LIMIT {$limite} FOR UPDATE",
                [$cuentaId, $maxIntentos]
            ) ?: [];
            if ($filas) {
                $ids = array_map('intval', array_column($filas, 'id'));
                $marcas = implode(',', array_fill(0, count($ids), '?'));
                $this->execute(
                    "UPDATE {$this->table}
                     SET estado = 'procesando', intentos = intentos + 1,
                         iniciado_en = NOW(), detalle = NULL
                     WHERE id IN ({$marcas})",
                    $ids
                );
                foreach ($filas as &$fila) {
                    $fila['intentos'] = (int) $fila['intentos'] + 1;
                    $fila['estado'] = 'procesando';
                }
                unset($fila);
            }
            if ($propia) {
                $db->commit();
            }
            return $filas;
        } catch (Throwable $e) {
            if ($propia && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function finalizar($id, $estado, $detalle = '', $documentos = 0)
    {
        $permitidos = ['capturado', 'sin_documentos', 'error'];
        $estado = in_array($estado, $permitidos, true) ? $estado : 'error';
        $detalle = mb_substr(trim((string) $detalle), 0, 1000, 'UTF-8');
        $reintentar = null;
        if ($estado === 'error') {
            $intentos = (int) $this->fetchColumn(
                "SELECT intentos FROM {$this->table} WHERE id = ? LIMIT 1",
                [(int) $id]
            );
            $reintentar = date('Y-m-d H:i:s', time() + min(60, max(5, $intentos * 5)) * 60);
        }

        return $this->execute(
            "UPDATE {$this->table}
             SET estado = ?, detalle = ?, documentos = ?, terminado_en = NOW(),
                 reintentar_en = ?
             WHERE id = ?",
            [$estado, $detalle !== '' ? $detalle : null, max(0, (int) $documentos),
             $reintentar, (int) $id]
        );
    }

    public function resumen()
    {
        $resumen = [
            'pendiente' => 0, 'procesando' => 0, 'capturado' => 0,
            'sin_documentos' => 0, 'error' => 0, 'documentos' => 0,
        ];
        foreach ($this->fetchAll(
            "SELECT estado, COUNT(*) total, COALESCE(SUM(documentos),0) documentos
             FROM {$this->table} GROUP BY estado"
        ) ?: [] as $fila) {
            $estado = (string) $fila['estado'];
            if (array_key_exists($estado, $resumen)) {
                $resumen[$estado] = (int) $fila['total'];
            }
            $resumen['documentos'] += (int) $fila['documentos'];
        }
        return $resumen;
    }

    public function get($id)
    {
        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [(int) $id]);
        return $fila ?: null;
    }

    private function ensureTable()
    {
        if (self::$schemaLista) {
            return;
        }
        $existe = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$this->table]
        ) > 0;
        if (!$existe) {
            $this->query("CREATE TABLE {$this->table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cuenta_id INT UNSIGNED NOT NULL,
                carpeta VARCHAR(255) NOT NULL,
                uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uid INT UNSIGNED NOT NULL,
                clave VARCHAR(100) NOT NULL,
                fecha_correo DATETIME NULL,
                estado ENUM('pendiente','procesando','capturado','sin_documentos','error')
                    NOT NULL DEFAULT 'pendiente',
                intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
                documentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                detalle VARCHAR(1000) NULL,
                detectado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                iniciado_en DATETIME NULL,
                terminado_en DATETIME NULL,
                reintentar_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_captura_clave (clave),
                KEY idx_captura_cuenta_estado
                    (cuenta_id, estado, reintentar_en, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        self::$schemaLista = true;
    }
}
