<?php
/**
 * Índice local de encabezados del buzón IMAP.
 *
 * Buscar por asunto/remitente/CC contra el servidor de correo cuesta varios
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

    /** Indica si está disponible el índice que acelera direcciones completas. */
    private $fulltextDestinatarios = false;

    /**
     * La verificación de esquema corre UNA vez por proceso. Antes cada
     * instancia ejecutaba CREATE TABLE + varios ALTER "IF NOT EXISTS": aunque
     * no cambien nada, cada DDL toma un metadata lock que espera a que
     * terminen las transacciones largas de sincronización (reemplazo de
     * carpetas completas), congelando las peticiones del módulo.
     */
    private static $esquemaListo = false;
    private static $fulltextDetectado = false;

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
     * ámbito: 'asunto', 'remitente' o 'asunto_remitente'. Remitente incluye
     * tanto FROM como CC; el último ámbito
     * incluye también el nombre de los adjuntos, así el número de factura
     * se encuentra aunque solo venga en el nombre del XML/PDF).
     *
     * Devuelve ['total' => coincidencias, 'correos' => filas] con la misma
     * forma que MailFetcher::listarMensajes (sin 'procesado').
     */
    public function buscar($texto, $ambito, $dias, $limite = 500, $mes = '', $carpeta = '', $offset = 0,
                           $fechaDesde = '', $fechaHasta = '')
    {
        $where = ['cuenta_id = ?'];
        $params = [$this->cuentaId];

        $carpeta = trim((string) $carpeta);
        if ($carpeta !== '') {
            $where[] = 'carpeta = ?';
            $params[] = $carpeta;
        }

        $dias = (int) $dias;
        if ($dias > 0) {
            $where[] = "timestamp >= ?";
            $params[] = (int) strtotime(date('Y-m-d 00:00:00', strtotime("-{$dias} days")));
        }

        $fechaDesde = trim((string) $fechaDesde);
        $fechaHasta = trim((string) $fechaHasta);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $where[] = "timestamp >= ? AND timestamp < ?";
            $params[] = strtotime($fechaDesde . ' 00:00:00');
            $params[] = strtotime($fechaHasta . ' +1 day 00:00:00');
        } else {
            // Compatibilidad con enlaces antiguos que priorizan un mes.
            $mes = trim((string) $mes);
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $inicioMes = strtotime($mes . '-01 00:00:00');
            $where[] = "timestamp >= ? AND timestamp < ?";
            $params[] = $inicioMes;
            $params[] = strtotime('+1 month', $inicioMes);
            }
        }

        $textoLimpio = trim((string) $texto);
        $terminos = preg_split('/\s+/', $textoLimpio, -1, PREG_SPLIT_NO_EMPTY);

        // Una dirección completa se busca en los campos de destinatarios,
        // aunque el selector general también incluya asunto/archivo. El LIKE
        // conserva la coincidencia exacta; MATCH solo reduce los candidatos.
        $esCorreoCompleto = $ambito !== 'asunto'
            && filter_var($textoLimpio, FILTER_VALIDATE_EMAIL) !== false;
        if ($esCorreoCompleto && $this->fulltextDestinatarios) {
            $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($textoLimpio, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
            usort($tokens, function ($a, $b) {
                return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
            });
            $token = $tokens[0] ?? '';

            // El mínimo predeterminado de FULLTEXT en MariaDB/MySQL es 3.
            if (mb_strlen($token, 'UTF-8') >= 3) {
                $like = '%' . addcslashes($textoLimpio, '%_\\') . '%';
                $where[] = "MATCH(remitente, cc, reply_to) AGAINST (? IN BOOLEAN MODE)
                            AND (remitente LIKE ? OR cc LIKE ? OR reply_to LIKE ?)";
                $params[] = '+' . $token;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $terminos = [];
            }
        }

        foreach ($terminos as $termino) {
            $like = '%' . addcslashes($termino, '%_\\') . '%';

            if ($ambito === 'asunto') {
                $where[] = "asunto LIKE ?";
                $params[] = $like;
            } elseif ($ambito === 'remitente') {
                $where[] = "(remitente LIKE ? OR cc LIKE ? OR reply_to LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            } else {
                // Por defecto también se busca en el nombre de los adjuntos:
                // ahí suele estar el número/clave de la factura.
                $where[] = "(asunto LIKE ? OR remitente LIKE ? OR cc LIKE ? OR reply_to LIKE ? OR adjuntos LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}{$sqlWhere}", $params);

        $limite = max(1, min(1000, (int) $limite));
        $offset = max(0, (int) $offset);
        $filas = $this->fetchAll(
            "SELECT uid, clave, carpeta, carpeta_nombre, asunto, remitente, cc, reply_to, adjuntos, fecha, timestamp
             FROM {$this->table}{$sqlWhere}
             ORDER BY timestamp DESC, id DESC
             LIMIT {$limite} OFFSET {$offset}",
            $params
        );

        foreach ($filas as &$fila) {
            $fila['uid'] = (int) $fila['uid'];
            $fila['timestamp'] = (int) $fila['timestamp'];
        }
        unset($fila);

        return ['total' => $total, 'correos' => $filas];
    }

    /**
     * Búsqueda optimizada por consecutivo de factura. El número completo de
     * 20 dígitos usa el índice B-Tree; un número corto escanea únicamente esta
     * columna compacta, no asunto/adjuntos de hasta 1 KB.
     */
    public function buscarPorNumero($numero, $dias, $limite = 500, $mes = '', $carpeta = '', $offset = 0,
                                    $fechaDesde = '', $fechaHasta = '')
    {
        $numero = preg_replace('/\D+/', '', (string) $numero);
        if (strlen($numero) < 4 || strlen($numero) > 20) {
            return ['total' => 0, 'correos' => []];
        }

        $where = ['cuenta_id = ?'];
        $params = [$this->cuentaId];

        $carpeta = trim((string) $carpeta);
        if ($carpeta !== '') {
            $where[] = 'carpeta = ?';
            $params[] = $carpeta;
        }

        if (strlen($numero) === 20) {
            $where[] = 'consecutivo = ?';
            $params[] = $numero;
        } else {
            $where[] = 'numero_corto = ?';
            $params[] = ltrim($numero, '0') !== '' ? ltrim($numero, '0') : '0';
        }

        $dias = (int) $dias;
        if ($dias > 0) {
            $where[] = 'timestamp >= ?';
            $params[] = (int) strtotime(date('Y-m-d 00:00:00', strtotime("-{$dias} days")));
        }

        $fechaDesde = trim((string) $fechaDesde);
        $fechaHasta = trim((string) $fechaHasta);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $where[] = 'timestamp >= ? AND timestamp < ?';
            $params[] = strtotime($fechaDesde . ' 00:00:00');
            $params[] = strtotime($fechaHasta . ' +1 day 00:00:00');
        } else {
            $mes = trim((string) $mes);
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $inicioMes = strtotime($mes . '-01 00:00:00');
            $where[] = 'timestamp >= ? AND timestamp < ?';
            $params[] = $inicioMes;
            $params[] = strtotime('+1 month', $inicioMes);
            }
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}{$sqlWhere}", $params);
        $limite = max(1, min(1000, (int) $limite));
        $offset = max(0, (int) $offset);
        $filas = $this->fetchAll(
            "SELECT uid, clave, carpeta, carpeta_nombre, asunto, remitente, cc, reply_to, adjuntos, fecha, timestamp
             FROM {$this->table}{$sqlWhere}
             ORDER BY timestamp DESC, id DESC LIMIT {$limite} OFFSET {$offset}",
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
                WHERE cuenta_id = ? AND adjuntos_pendiente = 1
                ORDER BY timestamp DESC LIMIT {$limite}
             ) t ORDER BY carpeta, uid",
            [$this->cuentaId]
        ) ?: [];
    }

    public function contarPendientesAdjuntos()
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE cuenta_id = ? AND adjuntos_pendiente = 1",
            [$this->cuentaId]
        );
    }

    public function guardarAdjuntos($id, $texto)
    {
        $consecutivo = $this->extraerConsecutivo($texto);
        $numeroCorto = $this->extraerNumeroCorto($consecutivo);
        return $this->execute(
            "UPDATE {$this->table}
             SET adjuntos = ?, consecutivo = COALESCE(consecutivo, ?), numero_corto = COALESCE(numero_corto, ?)
             WHERE id = ?",
            [(string) $texto, $consecutivo, $numeroCorto, (int) $id]
        );
    }

    /** Guarda los nombres leídos al abrir un correo y sanea el pendiente. */
    public function guardarAdjuntosPorMensaje($carpeta, $uid, $texto)
    {
        $consecutivo = $this->extraerConsecutivo($texto);
        $numeroCorto = $this->extraerNumeroCorto($consecutivo);
        return $this->execute(
            "UPDATE {$this->table}
             SET adjuntos = ?, consecutivo = COALESCE(consecutivo, ?), numero_corto = COALESCE(numero_corto, ?)
             WHERE cuenta_id = ? AND carpeta = ? AND uid = ?",
            [(string) $texto, $consecutivo, $numeroCorto, $this->cuentaId, (string) $carpeta, (int) $uid]
        );
    }

    /**
     * Metadatos pendientes de leer por mensaje. cc = NULL significa "aún no
     * leído"; cadena vacía significa que el mensaje no tiene destinatarios CC.
     */
    public function pendientesMetadatos($limite = 400)
    {
        $limite = max(1, min(1000, (int) $limite));
        return $this->fetchAll(
            "SELECT id, carpeta, uid,
                    adjuntos_pendiente,
                    destinatarios_pendientes AS cc_pendiente
             FROM (
                SELECT id, carpeta, uid, adjuntos_pendiente, destinatarios_pendientes, timestamp
                FROM {$this->table}
                WHERE cuenta_id = ?
                  AND (adjuntos_pendiente = 1 OR destinatarios_pendientes = 1)
                ORDER BY timestamp DESC LIMIT {$limite}
             ) t ORDER BY carpeta, uid",
            [$this->cuentaId]
        ) ?: [];
    }

    public function contarPendientesCc()
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE cuenta_id = ? AND destinatarios_pendientes = 1",
            [$this->cuentaId]
        );
    }

    public function pendientesCc($limite = 400)
    {
        $limite = max(1, min(1000, (int) $limite));
        return $this->fetchAll(
            "SELECT id, carpeta, uid FROM (
                SELECT id, carpeta, uid, timestamp FROM {$this->table}
                WHERE cuenta_id = ? AND destinatarios_pendientes = 1
                ORDER BY timestamp DESC LIMIT {$limite}
             ) t ORDER BY carpeta, uid",
            [$this->cuentaId]
        ) ?: [];
    }

    public function guardarCc($id, $texto)
    {
        return $this->execute(
            "UPDATE {$this->table} SET cc = ? WHERE id = ?",
            [(string) $texto, (int) $id]
        );
    }

    public function guardarDestinatarios($id, $cc, $replyTo)
    {
        return $this->execute(
            "UPDATE {$this->table} SET cc = ?, reply_to = ? WHERE id = ?",
            [(string) $cc, (string) $replyTo, (int) $id]
        );
    }

    public function guardarDestinatariosPorMensaje($carpeta, $uid, $cc, $replyTo)
    {
        return $this->execute(
            "UPDATE {$this->table} SET cc = ?, reply_to = ?
             WHERE cuenta_id = ? AND carpeta = ? AND uid = ?",
            [(string) $cc, (string) $replyTo, $this->cuentaId, (string) $carpeta, (int) $uid]
        );
    }

    /**
     * Completa solo Reply-To sin borrar un CC que ya haya sido indexado.
     * Se usa para acelerar búsquedas puntuales mientras termina la cola general.
     */
    public function guardarReplyToPorMensaje($carpeta, $uid, $replyTo)
    {
        return $this->execute(
            "UPDATE {$this->table} SET reply_to = ?
             WHERE cuenta_id = ? AND carpeta = ? AND uid = ?",
            [(string) $replyTo, $this->cuentaId, (string) $carpeta, (int) $uid]
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

    /**
     * Listado liviano para construir el árbol de carpetas sin consultar IMAP.
     */
    public function listarCarpetasResumen()
    {
        return $this->fetchAll(
            "SELECT carpeta, mensajes, ultima_sync
             FROM correo_carpetas
             WHERE cuenta_id = ?
             ORDER BY carpeta",
            [$this->cuentaId]
        ) ?: [];
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
        // El historial conserva una copia de los datos visibles antes de que
        // el índice reemplazable suelte su referencia a estas filas.
        $this->execute(
            "UPDATE correo_lote_items li
             INNER JOIN {$this->table} i ON i.id = li.correo_indice_id
             INNER JOIN correo_lotes l ON l.id = li.lote_id
             SET li.asunto = COALESCE(li.asunto, i.asunto),
                 li.remitente = COALESCE(li.remitente, i.remitente),
                 li.fecha_correo = COALESCE(li.fecha_correo, i.fecha)
             WHERE l.cuenta_id = ? AND i.cuenta_id = ? AND i.carpeta = ?",
            [$this->cuentaId, $this->cuentaId, (string) $carpeta]
        );

        return $this->execute(
            "DELETE FROM {$this->table} WHERE cuenta_id = ? AND carpeta = ?",
            [$this->cuentaId, (string) $carpeta]
        );
    }

    /**
     * Reemplaza una carpeta dentro de una transacción. Los encabezados ya
     * deben haberse descargado de IMAP para no dejar el índice vacío si la
     * red falla. Las referencias históricas vigentes se enlazan nuevamente.
     */
    public function reemplazarCarpeta($carpeta, $carpetaNombre, $uidvalidity, array $filas)
    {
        $db = self::getDB();
        $transaccionPropia = !$db->inTransaction();

        if ($transaccionPropia) {
            $db->beginTransaction();
        }

        try {
            $this->vaciarCarpeta($carpeta);
            $insertadas = $this->insertarLote($carpeta, $carpetaNombre, $uidvalidity, $filas);

            $this->execute(
                "UPDATE correo_lote_items li
                 INNER JOIN correo_lotes l ON l.id = li.lote_id
                 INNER JOIN {$this->table} i
                    ON i.cuenta_id = l.cuenta_id
                   AND i.carpeta = li.carpeta
                   AND i.uidvalidity = li.uidvalidity
                   AND i.uid = li.uid
                 SET li.correo_indice_id = i.id
                 WHERE l.cuenta_id = ? AND li.carpeta = ?
                   AND li.correo_indice_id IS NULL",
                [$this->cuentaId, (string) $carpeta]
            );

            if ($transaccionPropia) {
                $db->commit();
            }
            return $insertadas;
        } catch (Throwable $e) {
            if ($transaccionPropia && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
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

                $textoBusqueda = (string) ($fila['asunto'] ?? '') . ' ' . (string) ($fila['adjuntos'] ?? '');
                $consecutivo = $fila['consecutivo'] ?? $this->extraerConsecutivo($textoBusqueda);
                $numeroCorto = $fila['numero_corto'] ?? $this->extraerNumeroCorto($consecutivo);

                $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params[] = $this->cuentaId;
                $params[] = (string) $carpeta;
                $params[] = (string) $carpetaNombre;
                $params[] = $uid;
                $params[] = (int) $uidvalidity;
                $params[] = (string) ($fila['clave'] ?? ((int) $uidvalidity . ':' . $uid));
                $params[] = $fila['remitente'] ?? null;
                $params[] = $fila['cc'] ?? null;
                $params[] = $fila['reply_to'] ?? null;
                $params[] = $consecutivo;
                $params[] = $numeroCorto;
                $params[] = $fila['asunto'] ?? null;
                $params[] = $fila['adjuntos'] ?? null;
                $params[] = $fila['fecha'] ?? null;
                $params[] = (int) ($fila['timestamp'] ?? 0);
            }

            if (empty($values)) {
                continue;
            }

            $sql = "INSERT INTO {$this->table}
                    (cuenta_id, carpeta, carpeta_nombre, uid, uidvalidity, clave, remitente, cc, reply_to, consecutivo, numero_corto, asunto, adjuntos, fecha, timestamp)
                    VALUES " . implode(', ', $values) . "
                    ON DUPLICATE KEY UPDATE
                        carpeta_nombre = VALUES(carpeta_nombre),
                        clave = VALUES(clave),
                        remitente = VALUES(remitente),
                        cc = COALESCE(VALUES(cc), cc),
                        reply_to = COALESCE(VALUES(reply_to), reply_to),
                        consecutivo = COALESCE(VALUES(consecutivo), consecutivo),
                        numero_corto = COALESCE(VALUES(numero_corto), numero_corto),
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
        if (self::$esquemaListo) {
            $this->fulltextDestinatarios = self::$fulltextDetectado;
            return;
        }

        if ($this->esquemaCompleto()) {
            self::$esquemaListo = true;
            self::$fulltextDetectado = $this->fulltextDestinatarios;
            return;
        }

        $this->crearOMigrarTablas();

        self::$esquemaListo = true;
        self::$fulltextDetectado = $this->fulltextDestinatarios;
    }

    /**
     * true si las tablas, columnas e índices esperados ya existen: en ese
     * caso NO se ejecuta ningún CREATE/ALTER (solo dos SELECT ligeros a
     * information_schema, que no compiten por metadata locks con la
     * sincronización). También detecta aquí el índice FULLTEXT.
     */
    private function esquemaCompleto()
    {
        try {
            $columnas = $this->fetchAll(
                "SELECT LOWER(TABLE_NAME) AS tabla, LOWER(COLUMN_NAME) AS columna
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN (?, 'correo_carpetas')",
                [strtolower($this->table)]
            ) ?: [];
            $indices = $this->fetchAll(
                "SELECT DISTINCT LOWER(INDEX_NAME) AS indice
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = ?",
                [strtolower($this->table)]
            ) ?: [];
        } catch (Throwable $e) {
            return false;
        }

        $columnasPorTabla = [];
        foreach ($columnas as $fila) {
            $columnasPorTabla[$fila['tabla']][$fila['columna']] = true;
        }
        $nombresIndices = [];
        foreach ($indices as $fila) {
            $nombresIndices[$fila['indice']] = true;
        }

        $this->fulltextDestinatarios = isset($nombresIndices['ft_destinatarios']);

        if (empty($columnasPorTabla['correo_carpetas'])) {
            return false;
        }

        $tabla = $columnasPorTabla[strtolower($this->table)] ?? [];
        foreach (['adjuntos', 'cc', 'reply_to', 'consecutivo', 'numero_corto',
                  'adjuntos_pendiente', 'destinatarios_pendientes'] as $columna) {
            if (!isset($tabla[$columna])) {
                return false;
            }
        }

        // ft_destinatarios se omite a propósito: en motores sin FULLTEXT la
        // búsqueda funciona con LIKE y no hay que reintentar el ALTER siempre.
        foreach (['uk_cuenta_carpeta_uid', 'idx_cuenta_timestamp_id', 'idx_pend_adjuntos',
                  'idx_pend_destinatarios', 'idx_cuenta_consecutivo', 'idx_cuenta_numero_corto',
                  'idx_cuenta_carpeta_ts'] as $indice) {
            if (!isset($nombresIndices[$indice])) {
                return false;
            }
        }

        return true;
    }

    private function crearOMigrarTablas()
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
                    cc VARCHAR(1024) NULL DEFAULT NULL,
                    reply_to VARCHAR(1024) NULL DEFAULT NULL,
                    consecutivo VARCHAR(20) NULL DEFAULT NULL,
                    numero_corto VARCHAR(10) NULL DEFAULT NULL,
                    asunto VARCHAR(255) NULL DEFAULT NULL,
                    adjuntos VARCHAR(1024) NULL DEFAULT NULL,
                    fecha DATETIME NULL DEFAULT NULL,
                    timestamp INT UNSIGNED NOT NULL DEFAULT 0,
                    adjuntos_pendiente TINYINT(1) AS (adjuntos IS NULL) PERSISTENT,
                    destinatarios_pendientes TINYINT(1) AS (cc IS NULL OR reply_to IS NULL) PERSISTENT,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_cuenta_carpeta_uid (cuenta_id, carpeta(170), uidvalidity, uid),
                    KEY idx_timestamp (timestamp),
                    KEY idx_cuenta_timestamp_id (cuenta_id, timestamp, id),
                    KEY idx_cuenta_carpeta_ts (cuenta_id, carpeta(170), timestamp),
                    KEY idx_pend_adjuntos (cuenta_id, adjuntos_pendiente, timestamp, id),
                    KEY idx_pend_destinatarios (cuenta_id, destinatarios_pendientes, timestamp, id),
                    KEY idx_cuenta_consecutivo (cuenta_id, consecutivo),
                    KEY idx_cuenta_numero_corto (cuenta_id, numero_corto),
                    FULLTEXT KEY ft_destinatarios (remitente, cc, reply_to)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Autocurativo para índices ya creados antes de esta columna (MariaDB
        // soporta IF NOT EXISTS; si no, se ignora el error y queda la migración).
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS adjuntos VARCHAR(1024) NULL DEFAULT NULL AFTER asunto");
        } catch (Throwable $e) {
            // La columna ya existe o el motor no soporta IF NOT EXISTS: sin efecto.
        }
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS cc VARCHAR(1024) NULL DEFAULT NULL AFTER remitente");
        } catch (Throwable $e) {
            // La columna ya existe o el motor no soporta IF NOT EXISTS: sin efecto.
        }
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS reply_to VARCHAR(1024) NULL DEFAULT NULL AFTER cc");
        } catch (Throwable $e) {
            // La columna ya existe o el motor no soporta IF NOT EXISTS: sin efecto.
        }
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS consecutivo VARCHAR(20) NULL DEFAULT NULL AFTER reply_to");
            $this->query("ALTER TABLE {$this->table} ADD INDEX IF NOT EXISTS idx_cuenta_consecutivo (cuenta_id, consecutivo)");
        } catch (Throwable $e) {
            // La columna/índice ya existen o queda disponible la migración manual.
        }
        try {
            $this->query("ALTER TABLE {$this->table} ADD COLUMN IF NOT EXISTS numero_corto VARCHAR(10) NULL DEFAULT NULL AFTER consecutivo");
            $this->query("ALTER TABLE {$this->table} ADD INDEX IF NOT EXISTS idx_cuenta_numero_corto (cuenta_id, numero_corto)");
        } catch (Throwable $e) {
            // La columna/índice ya existen o queda disponible la migración manual.
        }

        try {
            $this->query("ALTER TABLE {$this->table} ADD FULLTEXT INDEX IF NOT EXISTS ft_destinatarios (remitente, cc, reply_to)");
        } catch (Throwable $e) {
            // En motores sin FULLTEXT la búsqueda conserva el LIKE tradicional.
        }
        try {
            $this->query(
                "ALTER TABLE {$this->table}
                 ADD COLUMN IF NOT EXISTS adjuntos_pendiente
                    TINYINT(1) AS (adjuntos IS NULL) PERSISTENT,
                 ADD COLUMN IF NOT EXISTS destinatarios_pendientes
                    TINYINT(1) AS (cc IS NULL OR reply_to IS NULL) PERSISTENT,
                 ADD INDEX IF NOT EXISTS idx_cuenta_timestamp_id (cuenta_id, timestamp, id),
                 ADD INDEX IF NOT EXISTS idx_pend_adjuntos
                    (cuenta_id, adjuntos_pendiente, timestamp, id),
                 ADD INDEX IF NOT EXISTS idx_pend_destinatarios
                    (cuenta_id, destinatarios_pendientes, timestamp, id)"
            );
        } catch (Throwable $e) {
            // Queda disponible la migración manual para motores/versiones antiguas.
        }
        try {
            // Listar una carpeta ordenada por fecha sin filesort (la vista pide
            // 500 filas de la carpeta activa cada vez que se abre el módulo).
            $this->query("ALTER TABLE {$this->table} ADD INDEX IF NOT EXISTS idx_cuenta_carpeta_ts (cuenta_id, carpeta(170), timestamp)");
        } catch (Throwable $e) {
            // Queda disponible la migración manual para motores/versiones antiguas.
        }
        try {
            $this->fulltextDestinatarios = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'ft_destinatarios'",
                [$this->table]
            ) > 0;
        } catch (Throwable $e) {
            $this->fulltextDestinatarios = false;
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

    /** Extrae el consecutivo CR de 20 dígitos desde una clave de 50 dígitos. */
    private function extraerConsecutivo($texto)
    {
        $texto = (string) $texto;
        if (preg_match('/(?<!\d)(\d{50})(?!\d)/', $texto, $m)) {
            return substr($m[1], 21, 20);
        }
        if (preg_match('/(?<!\d)(\d{20})(?!\d)/', $texto, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extraerNumeroCorto($consecutivo)
    {
        if (!is_string($consecutivo) || strlen($consecutivo) !== 20) {
            return null;
        }
        $numero = ltrim(substr($consecutivo, 10, 10), '0');
        return $numero !== '' ? $numero : '0';
    }
}
