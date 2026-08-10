<?php
/** Estado persistente de una ejecución del modo Descargas de Correo. */
class CorreoLote extends Model
{
    protected $table = 'correo_lotes';
    private static $schemaIncidenciasLista = false;

    /**
     * Correos del índice que son candidatos del modo Descargas.
     * adjuntos=NULL entra deliberadamente: un mensaje cuyo índice aún no leyó
     * la estructura MIME no debe perderse; el worker lo verifica al abrirlo.
     * Parámetros esperados, en orden: cuenta_id, timestamp desde, hasta.
     */
    private const CONDICIONES_CANDIDATO =
        "i.cuenta_id = ?
         AND i.timestamp >= ? AND i.timestamp < ?
         AND (i.adjuntos IS NULL OR LOWER(i.adjuntos) REGEXP '\\.(xml|zip)([[:space:]]|$)')
         AND LOWER(i.carpeta) NOT REGEXP '(spam|junk|borrador|draft|sent|enviado|trash|papelera)'";

    /**
     * ¿Este correo ya se procesó en una corrida anterior?
     *
     * correo_procesados guarda una marca por mensaje con la llave
     * "c{cuenta}:{uidvalidity}:{uid}" (ver MailFetcher::claveMensaje), y el
     * modo Descargas la escribe al terminar cada correo. Antes nadie la
     * consultaba al armar el lote: rangos que se traslapaban volvían a bajar
     * los adjuntos por IMAP para descubrir al final que ya estaban. En el
     * lote #10 eso fueron 1296 de 2490 correos. La llave es PRIMARY KEY, así
     * que descartarlos aquí no cuesta prácticamente nada.
     *
     * Descartar de la bandeja quita la marca (MailFetcher::desmarcarProcesados),
     * así que reprocesar a propósito sigue funcionando.
     */
    private const YA_PROCESADO =
        "EXISTS (SELECT 1 FROM correo_procesados p
                  WHERE p.clave = CONCAT('c', i.cuenta_id, ':', i.uidvalidity, ':', i.uid))";

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureIncidenciasSchema(); });
    }

    private function ensureIncidenciasSchema()
    {
        if (self::$schemaIncidenciasLista) {
            return;
        }
        try {
            $existe = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'correo_incidencias' AND COLUMN_NAME = 'asunto'"
            );
            if ($existe > 0) {
                self::$schemaIncidenciasLista = true;
                return;
            }
            $this->execute("ALTER TABLE correo_incidencias
                            ADD COLUMN IF NOT EXISTS asunto VARCHAR(255) NULL AFTER mensaje");
            self::$schemaIncidenciasLista = true;
        } catch (Throwable $e) {
            // database/migration_correo_historial_incidencias.sql cubre
            // servidores donde el usuario web no tiene permisos de DDL.
        }
    }

    public function crear(array $data)
    {
        $desdeTs = strtotime((string) $data['fecha_desde'] . ' 00:00:00');
        $hastaTs = strtotime((string) $data['fecha_hasta'] . ' +1 day 00:00:00');
        if ($desdeTs === false || $hastaTs === false || $hastaTs <= $desdeTs) {
            throw new InvalidArgumentException('El rango de fechas no es válido.');
        }

        $sql = "INSERT INTO {$this->table}
                (cuenta_id, sociedad_id, fecha_desde, fecha_hasta, carpeta_raiz, carpetas_json, estado)
                VALUES (?, ?, ?, ?, ?, ?, 'pendiente')";
        $id = (int) $this->insert($sql, [
            (int) $data['cuenta_id'],
            (int) $data['sociedad_id'],
            $data['fecha_desde'],
            $data['fecha_hasta'],
            $data['carpeta_raiz'],
            json_encode($data['carpetas'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        // adjuntos=NULL entra deliberadamente: así un mensaje cuyo índice
        // aún no leyó la estructura MIME no se pierde; el worker lo verifica.
        $insert = "INSERT IGNORE INTO correo_lote_items
                   (lote_id, correo_indice_id, carpeta, uidvalidity, uid,
                    asunto, remitente, fecha_correo)
                   SELECT ?, i.id, i.carpeta, i.uidvalidity, i.uid,
                          i.asunto, i.remitente, i.fecha
                   FROM correo_indice i
                   WHERE " . self::CONDICIONES_CANDIDATO;
        $params = [$id, (int) $data['cuenta_id'], $desdeTs, $hastaTs];
        $this->agregarFiltroCorreo($insert, $params, $data['correo_busqueda'] ?? '');
        if (empty($data['incluir_procesados'])) {
            $insert .= ' AND NOT ' . self::YA_PROCESADO;
        }
        $this->prepararTablaProcesados();
        $this->execute($insert, $params);
        $total = (int) $this->fetchColumn('SELECT COUNT(*) FROM correo_lote_items WHERE lote_id = ?', [$id]);
        $this->execute("UPDATE {$this->table} SET total_mensajes = ? WHERE id = ?", [$total, $id]);
        return $this->get($id);
    }

    /**
     * Cuántos correos entrarían en el lote, separando los que ya se
     * procesaron antes: es lo que se le muestra al usuario antes de iniciar,
     * para que sepa si va a trabajar de verdad o a repetir lo mismo.
     *
     * @return array{total:int,procesados:int,nuevos:int}
     */
    public function estimar($cuentaId, $desde, $hasta, $correoBusqueda = '')
    {
        $desdeTs = strtotime($desde . ' 00:00:00');
        $hastaTs = strtotime($hasta . ' +1 day 00:00:00');
        if ($desdeTs === false || $hastaTs === false) {
            return ['total' => 0, 'procesados' => 0, 'nuevos' => 0];
        }
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(' . self::YA_PROCESADO . '), 0) AS procesados
                  FROM correo_indice i
                 WHERE ' . self::CONDICIONES_CANDIDATO;
        $params = [(int) $cuentaId, $desdeTs, $hastaTs];
        $this->agregarFiltroCorreo($sql, $params, $correoBusqueda);

        $this->prepararTablaProcesados();
        $fila = $this->fetchOne($sql, $params) ?: [];
        $total = (int) ($fila['total'] ?? 0);
        $procesados = (int) ($fila['procesados'] ?? 0);

        return ['total' => $total, 'procesados' => $procesados, 'nuevos' => $total - $procesados];
    }

    /**
     * correo_procesados la crea CorreoProcesado en su constructor. Se toca
     * antes de consultarla porque un servidor recién instalado puede no
     * tenerla todavía y aquí se referencia desde SQL crudo.
     */
    protected function prepararTablaProcesados()
    {
        static $lista = false;
        if ($lista) {
            return;
        }
        require_once __DIR__ . '/CorreoProcesado.php';
        new CorreoProcesado();
        $lista = true;
    }

    private function agregarFiltroCorreo(&$sql, array &$params, $correoBusqueda)
    {
        $correo = mb_strtolower(trim((string) $correoBusqueda), 'UTF-8');
        if ($correo === '') {
            return;
        }
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo de búsqueda no es válido.');
        }

        $like = '%' . addcslashes($correo, '%_\\') . '%';
        $sql .= " AND (LOWER(COALESCE(i.remitente, '')) LIKE ?
                       OR LOWER(COALESCE(i.cc, '')) LIKE ?
                       OR LOWER(COALESCE(i.reply_to, '')) LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    public function get($id)
    {
        $row = $this->fetchOne("SELECT l.*, c.nombre AS cuenta_nombre, c.usuario AS cuenta_usuario,
                                      s.nombre AS sociedad_nombre, s.cedula AS sociedad_cedula
                               FROM {$this->table} l
                               LEFT JOIN correo_cuentas c ON c.id = l.cuenta_id
                               LEFT JOIN sociedades s ON s.id = l.sociedad_id
                               WHERE l.id = ? LIMIT 1", [(int) $id]);
        return $row ?: null;
    }

    public function ultimo($cuentaId = 0)
    {
        $where = $cuentaId > 0 ? 'WHERE l.cuenta_id = ?' : '';
        $params = $cuentaId > 0 ? [(int) $cuentaId] : [];
        $row = $this->fetchOne("SELECT l.*, c.nombre AS cuenta_nombre, s.nombre AS sociedad_nombre
                               FROM {$this->table} l
                               LEFT JOIN correo_cuentas c ON c.id = l.cuenta_id
                               LEFT JOIN sociedades s ON s.id = l.sociedad_id
                               {$where} ORDER BY l.id DESC LIMIT 1", $params);
        return $row ?: null;
    }

    /**
     * Lotes que aún tienen trabajo por delante, del más viejo al más nuevo:
     * uno parado hace días es el que urge. Lo consume cli/procesar_lotes.php.
     */
    public function pendientes($loteId = 0)
    {
        $sql = "SELECT id, cuenta_id, sociedad_id, total_mensajes, procesados, estado
                  FROM {$this->table}
                 WHERE estado IN ('pendiente','ejecutando')";
        $params = [];
        if ((int) $loteId > 0) {
            $sql .= ' AND id = ?';
            $params[] = (int) $loteId;
        }
        return $this->fetchAll($sql . ' ORDER BY id ASC', $params) ?: [];
    }

    public function iniciar($id)
    {
        $this->execute("UPDATE {$this->table}
                        SET estado = 'ejecutando', iniciado_en = COALESCE(iniciado_en, NOW()), ultimo_error = NULL
                        WHERE id = ? AND estado IN ('pendiente','pausado','error')", [(int) $id]);
        return $this->get($id);
    }

    public function cambiarEstado($id, $estado)
    {
        if (!in_array($estado, ['pausado', 'ejecutando', 'cancelado'], true)) {
            throw new InvalidArgumentException('Estado de lote no permitido.');
        }
        $terminal = $estado === 'cancelado' ? ', terminado_en = NOW()' : '';
        $permitidosDesde = $estado === 'pausado' ? "('ejecutando')"
            : ($estado === 'ejecutando' ? "('pendiente','pausado','error')" : "('pendiente','ejecutando','pausado','error')");
        $this->execute("UPDATE {$this->table} SET estado = ? {$terminal}
                        WHERE id = ? AND estado IN {$permitidosDesde}", [$estado, (int) $id]);
        if ($estado === 'cancelado') {
            $this->execute("UPDATE correo_lote_items SET estado = 'cancelado', procesado_en = NOW()
                            WHERE lote_id = ? AND estado IN ('pendiente','procesando')", [(int) $id]);
        }
        return $this->get($id);
    }

    public function tomarPendientes($loteId, $limit = 3)
    {
        $lote = $this->get($loteId);
        if (!$lote || $lote['estado'] !== 'ejecutando') {
            return [];
        }
        $this->execute("UPDATE correo_lote_items SET estado = 'pendiente', iniciado_en = NULL
                        WHERE lote_id = ? AND estado = 'procesando'
                          AND iniciado_en < DATE_SUB(NOW(), INTERVAL 10 MINUTE)", [(int) $loteId]);
        $limit = max(1, min(10, (int) $limit));
        $items = $this->fetchAll("SELECT * FROM correo_lote_items
                                 WHERE lote_id = ? AND estado = 'pendiente'
                                 ORDER BY id ASC LIMIT {$limit}", [(int) $loteId]);
        if (!$items) {
            $this->completarSiTermino($loteId);
            return [];
        }
        $ids = array_map('intval', array_column($items, 'id'));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([(int) $loteId], $ids);
        $this->execute("UPDATE correo_lote_items SET estado = 'procesando', intentos = intentos + 1, iniciado_en = NOW()
                        WHERE lote_id = ? AND id IN ({$marks}) AND estado = 'pendiente'", $params);
        return $this->fetchAll("SELECT * FROM correo_lote_items WHERE id IN ({$marks}) ORDER BY id", $ids) ?: [];
    }

    /**
     * Devuelve a 'pendiente' items tomados que no se alcanzaron a procesar
     * (la petición cortó por presupuesto de tiempo): así el siguiente viaje
     * los retoma de inmediato en vez de esperar el rescate de 10 minutos.
     */
    public function devolverAPendiente(array $itemIds)
    {
        $ids = array_values(array_filter(array_map('intval', $itemIds)));
        if (empty($ids)) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->execute("UPDATE correo_lote_items SET estado = 'pendiente', iniciado_en = NULL
                        WHERE id IN ({$marks}) AND estado = 'procesando'", $ids);
    }

    public function finalizarItem($itemId, array $resumen)
    {
        $estado = in_array($resumen['estado'] ?? '', ['completado', 'omitido', 'error'], true)
            ? $resumen['estado'] : 'completado';
        $this->execute("UPDATE correo_lote_items SET estado = ?, documentos_importados = ?, duplicados = ?,
                            pdf_pendientes = ?, detalle = ?, procesado_en = NOW() WHERE id = ?", [
            $estado,
            (int) ($resumen['importados'] ?? 0),
            (int) ($resumen['duplicados'] ?? 0),
            (int) ($resumen['pdf_pendientes'] ?? 0),
            mb_substr((string) ($resumen['detalle'] ?? ''), 0, 65000, 'UTF-8'),
            (int) $itemId,
        ]);
        $loteId = (int) $this->fetchColumn('SELECT lote_id FROM correo_lote_items WHERE id = ?', [(int) $itemId]);
        $this->recalcular($loteId);
        $this->completarSiTermino($loteId);
    }

    public function incidencia($loteId, $itemId, $tipo, $mensaje, array $metadata = [])
    {
        $asunto = trim((string) ($metadata['asunto'] ?? ''));
        if ($asunto === '' && $itemId > 0) {
            $asunto = (string) $this->fetchColumn(
                "SELECT COALESCE(i.asunto, li.asunto)
                 FROM correo_lote_items li
                 LEFT JOIN correo_indice i ON i.id = li.correo_indice_id
                 WHERE li.id = ? LIMIT 1",
                [(int) $itemId]
            );
        }

        $this->insert("INSERT INTO correo_incidencias
                          (lote_id, lote_item_id, tipo, mensaje, asunto, metadata)
                       VALUES (?, ?, ?, ?, ?, ?)", [
            (int) $loteId,
            $itemId > 0 ? (int) $itemId : null,
            mb_substr((string) $tipo, 0, 40, 'UTF-8'),
            mb_substr((string) $mensaje, 0, 1000, 'UTF-8'),
            $asunto !== '' ? mb_substr($asunto, 0, 255, 'UTF-8') : null,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $this->recalcular((int) $loteId);
    }

    public function incidencias($loteId, $limit = 20)
    {
        $limit = max(1, min(100, (int) $limit));
        return $this->fetchAll(
            "SELECT x.id, x.lote_id, x.lote_item_id, x.tipo, x.mensaje,
                    COALESCE(NULLIF(x.asunto, ''), i.asunto, li.asunto, '') AS asunto,
                    x.metadata, x.creado_en, li.carpeta, li.uid, li.uidvalidity,
                    COALESCE(i.remitente, li.remitente) AS remitente,
                    COALESCE(i.fecha, li.fecha_correo) AS fecha_correo
             FROM correo_incidencias x
             LEFT JOIN correo_lote_items li ON li.id = x.lote_item_id
             LEFT JOIN correo_indice i ON i.id = li.correo_indice_id
             WHERE x.lote_id = ? ORDER BY x.id DESC LIMIT {$limit}",
            [(int) $loteId]
        ) ?: [];
    }

    public function historialIncidencias($cuentaId, array $filters = [], $page = 1, $perPage = 50)
    {
        $where = ['l.cuenta_id = ?'];
        $params = [(int) $cuentaId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(x.mensaje LIKE ? OR x.tipo LIKE ?
                        OR COALESCE(NULLIF(x.asunto, ''), i.asunto, li.asunto, '') LIKE ?
                        OR COALESCE(i.remitente, li.remitente, '') LIKE ? OR li.carpeta LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $tipo = trim((string) ($filters['tipo'] ?? ''));
        if ($tipo !== '') {
            $where[] = 'x.tipo = ?';
            $params[] = $tipo;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->fetchColumn(
            "SELECT COUNT(*)
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             LEFT JOIN correo_lote_items li ON li.id = x.lote_item_id
             LEFT JOIN correo_indice i ON i.id = li.correo_indice_id
             WHERE {$whereSql}",
            $params
        );

        $perPage = max(20, min(100, (int) $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $page, $pages));
        $offset = ($page - 1) * $perPage;
        $rows = $this->fetchAll(
            "SELECT x.id, x.lote_id, x.tipo, x.mensaje,
                    COALESCE(NULLIF(x.asunto, ''), i.asunto, li.asunto, '') AS asunto,
                    x.metadata, x.creado_en, li.carpeta, li.uid, li.uidvalidity,
                    COALESCE(i.remitente, li.remitente) AS remitente,
                    COALESCE(i.fecha, li.fecha_correo) AS fecha_correo
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             LEFT JOIN correo_lote_items li ON li.id = x.lote_item_id
             LEFT JOIN correo_indice i ON i.id = li.correo_indice_id
             WHERE {$whereSql}
             ORDER BY x.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        ) ?: [];
        $tipos = $this->fetchAll(
            "SELECT DISTINCT x.tipo
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             WHERE l.cuenta_id = ? ORDER BY x.tipo",
            [(int) $cuentaId]
        ) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'tipos' => array_values(array_filter(array_map(function ($row) {
                return (string) ($row['tipo'] ?? '');
            }, $tipos))),
        ];
    }

    public function begin() { return self::getDB()->beginTransaction(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) { return self::getDB()->rollBack(); }
        return true;
    }

    private function recalcular($loteId)
    {
        if ($loteId <= 0) { return; }
        $this->execute("UPDATE {$this->table} l SET
                procesados = (SELECT COUNT(*) FROM correo_lote_items i WHERE i.lote_id=l.id AND i.estado IN ('completado','omitido','error','cancelado')),
                documentos_importados = (SELECT COALESCE(SUM(i.documentos_importados),0) FROM correo_lote_items i WHERE i.lote_id=l.id),
                duplicados = (SELECT COALESCE(SUM(i.duplicados),0) FROM correo_lote_items i WHERE i.lote_id=l.id),
                pdf_pendientes = (SELECT COALESCE(SUM(i.pdf_pendientes),0) FROM correo_lote_items i WHERE i.lote_id=l.id),
                incidencias = (SELECT COUNT(*) FROM correo_incidencias x WHERE x.lote_id=l.id)
                WHERE l.id = ?", [(int) $loteId]);
    }

    private function completarSiTermino($loteId)
    {
        $pendientes = (int) $this->fetchColumn("SELECT COUNT(*) FROM correo_lote_items
                                               WHERE lote_id = ? AND estado IN ('pendiente','procesando')", [(int) $loteId]);
        if ($pendientes === 0) {
            $this->recalcular($loteId);
            $this->execute("UPDATE {$this->table} SET estado = 'completado', terminado_en = NOW()
                            WHERE id = ? AND estado = 'ejecutando'", [(int) $loteId]);
        }
    }
}
