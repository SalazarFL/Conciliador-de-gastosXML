<?php
/** Estado persistente de una ejecución del modo Descargas de Correo. */
require_once __DIR__ . '/../helpers/MailFetcher.php';

class CorreoLote extends Model
{
    /*
     * Las descargas son de una empresa: el lote guarda su sociedad_id desde
     * que se crea. Lo que NO se acota es pendientes(), que consume el worker
     * de cli/ sin sesión y tiene que ver el trabajo de todas.
     */
    use AlcanceSociedad;

    protected $table = 'correo_lotes';
    private static $schemaIncidenciasLista = false;
    /** Se da por presente; solo se pone en false si se comprueba que falta. */
    private static $hayColumnaTomadoPor = true;

    /**
     * Archivo de lock que serializa a los motores que hacen avanzar un lote:
     * la tarea programada de Windows y el latido del navegador. Los items se
     * toman de forma atómica, así que dos motores a la vez no se pisan, pero
     * sí abrirían dos conexiones IMAP contra el mismo buzón para hacer el
     * mismo trabajo. No es el lock de la sincronización del índice: son
     * trabajos distintos y pueden convivir.
     *
     * El archivo es local a cada computadora, así que deja un motor por
     * máquina —no uno en toda la oficina—: es el mismo número de conexiones
     * que cuando cada quien tenía abierto el modo Descargas.
     */
    public static function rutaLock()
    {
        return MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'lotes.lock';
    }

    /** Toma el lock sin bloquear; null si otro motor ya está trabajando. */
    public static function adquirirLock()
    {
        $fp = @fopen(self::rutaLock(), 'c');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    public static function liberarLock($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

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
     *
     * La marca es de cada EMPRESA. Un buzón puede servir a varias, y que la
     * primera ya haya trabajado un correo no puede dejar a la segunda sin
     * poder verlo: sus facturas están en ese mismo mensaje. Dentro de una
     * empresa la marca sigue evitando bajar dos veces lo mismo.
     *
     * La empresa entra como número en el texto y no como parámetro a
     * propósito: esta condición se usa una vez en el SELECT y otra al final
     * del WHERE, y con marcadores habría que llevar la cuenta del orden en que
     * se agregan los parámetros en cada consulta.
     */
    private static function yaProcesado($sociedadId)
    {
        return "EXISTS (SELECT 1 FROM correo_procesados p
                         WHERE p.sociedad_id = " . max(0, (int) $sociedadId) . "
                           AND p.clave = CONCAT('c', i.cuenta_id, ':', i.uidvalidity, ':', i.uid))";
    }

    public function __construct()
    {
        Esquema::unaVez(static::class, function () {
            $this->ensureIncidenciasSchema();
            $this->ensureItemsSchema();
        });
    }

    /**
     * La columna con la que cada proceso firma los correos que tomó.
     *
     * Ver tomarPendientes(): sin ella, dos computadoras pidiendo tanda a la vez
     * se llevan los mismos correos. database/migrations/008_correo_lote_tomado_por.sql
     * hace lo mismo donde el usuario web no tiene permisos para alterar tablas.
     */
    private function ensureItemsSchema()
    {
        try {
            $this->execute("ALTER TABLE correo_lote_items
                            ADD COLUMN IF NOT EXISTS tomado_por VARCHAR(40) NULL AFTER iniciado_en");
        } catch (Throwable $e) {
            // Sin permiso de ALTER: se comprueba si de todos modos está, para
            // poder decirlo con claridad en vez de fallar con un
            // "Unknown column" a mitad de una descarga.
            try {
                self::$hayColumnaTomadoPor = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'correo_lote_items' AND COLUMN_NAME = 'tomado_por'"
                ) > 0;
            } catch (Throwable $e2) {
            }
        }
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
            if ($existe === 0) {
                $this->execute("ALTER TABLE correo_incidencias
                                ADD COLUMN IF NOT EXISTS asunto VARCHAR(255) NULL AFTER mensaje");
            }

            // Descarte de incidencias, igual que en Facturas ERP.
            $this->execute("ALTER TABLE correo_incidencias
                ADD COLUMN IF NOT EXISTS firma VARCHAR(64) NOT NULL DEFAULT '' AFTER metadata,
                ADD COLUMN IF NOT EXISTS descartada TINYINT(1) NOT NULL DEFAULT 0 AFTER firma,
                ADD COLUMN IF NOT EXISTS descartada_en DATETIME NULL AFTER descartada,
                ADD COLUMN IF NOT EXISTS descartada_por INT(10) UNSIGNED NULL AFTER descartada_en,
                ADD COLUMN IF NOT EXISTS motivo VARCHAR(255) NULL AFTER descartada_por");
            $this->execute("CREATE TABLE IF NOT EXISTS correo_incidencias_descartes (
                firma      VARCHAR(64) NOT NULL,
                tipo       VARCHAR(40) NOT NULL DEFAULT '',
                asunto     VARCHAR(255) NULL,
                motivo     VARCHAR(255) NULL,
                usuario_id INT(10) UNSIGNED NULL,
                creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (firma),
                KEY idx_tipo (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            self::$schemaIncidenciasLista = true;
        } catch (Throwable $e) {
            // database/migration_correo_historial_incidencias.sql y
            // database/migration_correo_incidencias_descarte.sql cubren
            // servidores donde el usuario web no tiene permisos de DDL.
        }
    }

    /**
     * Identifica LA MISMA incidencia entre corridas.
     *
     * El lote y el item cambian al reprocesar; lo que no cambia es el mensaje
     * del buzón (carpeta + uidvalidity + uid). Se le suma el texto porque un
     * mismo correo trae varias facturas y produce una incidencia por cada una:
     * sin eso, descartar "la factura 2312 vino sin PDF" descartaría también
     * las otras cinco del mismo correo.
     */
    public static function firmaIncidencia($tipo, $carpeta, $uidvalidity, $uid, $mensaje)
    {
        return sha1(implode('|', [
            (string) $tipo,
            (string) $carpeta,
            (int) $uidvalidity,
            (int) $uid,
            (string) $mensaje,
        ]));
    }

    public function crear(array $data)
    {
        $desdeTs = strtotime((string) $data['fecha_desde'] . ' 00:00:00');
        $hastaTs = strtotime((string) $data['fecha_hasta'] . ' +1 day 00:00:00');
        if ($desdeTs === false || $hastaTs === false || $hastaTs <= $desdeTs) {
            throw new InvalidArgumentException('El rango de fechas no es válido.');
        }
        // Todo lo que puede rechazarse, ANTES de insertar nada: el correo de
        // búsqueda se validaba al armar la consulta de los items, o sea con el
        // lote ya creado, y un correo mal escrito dejaba un lote huérfano en
        // "pendiente" con 0 correos que después había que ir a cancelar.
        self::validarCorreoBusqueda($data['correo_busqueda'] ?? '');

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
            $insert .= ' AND NOT ' . self::yaProcesado($data['sociedad_id'] ?? 0);
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
    public function estimar($cuentaId, $desde, $hasta, $correoBusqueda = '', $sociedadId = null)
    {
        $desdeTs = strtotime($desde . ' 00:00:00');
        $hastaTs = strtotime($hasta . ' +1 day 00:00:00');
        if ($desdeTs === false || $hastaTs === false) {
            return ['total' => 0, 'procesados' => 0, 'nuevos' => 0];
        }
        // Sin decir cuál, la de quien está mirando: es la que va a trabajar
        // el lote y la que decide qué cuenta como ya procesado.
        $sociedadId = $sociedadId === null ? $this->sociedadId() : (int) $sociedadId;
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(' . self::yaProcesado($sociedadId) . '), 0) AS procesados
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

    /** El correo de búsqueda, en minúsculas; '' si no hay. Lanza si no es uno. */
    private static function validarCorreoBusqueda($correoBusqueda)
    {
        $correo = mb_strtolower(trim((string) $correoBusqueda), 'UTF-8');
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo de búsqueda no es válido.');
        }
        return $correo;
    }

    private function agregarFiltroCorreo(&$sql, array &$params, $correoBusqueda)
    {
        $correo = self::validarCorreoBusqueda($correoBusqueda);
        if ($correo === '') {
            return;
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
        $params = [];
        $where = 'WHERE 1=1' . $this->condicionSociedad('l.', $params);
        if ((int) $cuentaId > 0) {
            $where .= ' AND l.cuenta_id = ?';
            $params[] = (int) $cuentaId;
        }
        $row = $this->fetchOne("SELECT l.*, c.nombre AS cuenta_nombre, s.nombre AS sociedad_nombre
                               FROM {$this->table} l
                               LEFT JOIN correo_cuentas c ON c.id = l.cuenta_id
                               LEFT JOIN sociedades s ON s.id = l.sociedad_id
                               {$where} ORDER BY l.id DESC LIMIT 1", $params);
        return $row ?: null;
    }

    /**
     * El lote que tiene trabajo por delante, sea de la cuenta que sea.
     *
     * Una descarga en curso es del sistema, no de la pantalla que la empezó:
     * cambiar de buzón o de módulo no puede hacerla desaparecer. El más
     * viejo primero, igual que el worker. Un lote pausado no cuenta: ahí la
     * quietud se pidió a propósito.
     *
     * "Sea de la cuenta que sea" no es "sea de la empresa que sea": una
     * empresa recién registrada, todavía sin buzones, veía descargando el
     * correo de otra.
     */
    public function enCurso()
    {
        $params = [];
        $row = $this->fetchOne(
            "SELECT l.*, c.nombre AS cuenta_nombre, s.nombre AS sociedad_nombre
               FROM {$this->table} l
               LEFT JOIN correo_cuentas c ON c.id = l.cuenta_id
               LEFT JOIN sociedades s ON s.id = l.sociedad_id
              WHERE l.estado IN ('pendiente', 'ejecutando')"
              . $this->condicionSociedad('l.', $params) . "
              ORDER BY l.id ASC LIMIT 1",
            $params
        );
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

        // Firma de ESTA toma. El candado de archivo (lotes.lock) ordena a los
        // procesos de una máquina, no a los de la oficina: dos computadoras
        // —o una y la tarea programada— pueden llegar aquí con los mismos ids.
        // El UPDATE decide quién se los lleva, y después se releen SOLO los
        // que llevan esta firma. Antes se releía "WHERE id IN (…)" a secas,
        // así que el que perdía el UPDATE se los llevaba igual y los dos
        // bajaban e importaban el mismo correo.
        if (!self::$hayColumnaTomadoPor) {
            throw new RuntimeException(
                'Falta la columna correo_lote_items.tomado_por, sin la cual dos computadoras '
                . 'pueden bajar los mismos correos. Aplica '
                . 'database/migrations/008_correo_lote_tomado_por.sql.'
            );
        }
        $firma = bin2hex(random_bytes(8));
        $params = array_merge([$firma, (int) $loteId], $ids);
        $tomados = (int) $this->execute(
            "UPDATE correo_lote_items SET estado = 'procesando', intentos = intentos + 1,
                    iniciado_en = NOW(), tomado_por = ?
              WHERE lote_id = ? AND id IN ({$marks}) AND estado = 'pendiente'",
            $params
        );
        if ($tomados === 0) {
            return [];
        }
        return $this->fetchAll(
            "SELECT * FROM correo_lote_items WHERE id IN ({$marks}) AND tomado_por = ? ORDER BY id",
            array_merge($ids, [$firma])
        ) ?: [];
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

    /**
     * Cierra un correo del lote.
     *
     * NO recalcula los contadores del lote: eso es trabajo de cerrarTanda(),
     * una vez al final. Recalcular aquí costaba un UPDATE con cinco
     * subconsultas —y la consulta del lote_id— por cada correo: con la base al
     * otro lado de la red eran ~0,5 s de espera por correo antes de bajar
     * ningún XML. Los estados de los items sí quedan escritos en el acto, que
     * es lo que hace falta para no repetir trabajo si la corrida se interrumpe.
     */
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
    }

    /**
     * Pone al día los contadores del lote y lo completa si ya no queda nada.
     * Se llama UNA vez al terminar la tanda, no por correo.
     */
    public function cerrarTanda($loteId)
    {
        $loteId = (int) $loteId;
        if ($loteId <= 0) {
            return;
        }
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

        $tipo = mb_substr((string) $tipo, 0, 40, 'UTF-8');
        $mensaje = mb_substr((string) $mensaje, 0, 1000, 'UTF-8');

        // Firma del correo al que pertenece, para que el descarte sobreviva a
        // reprocesar: el lote y el item serán otros, el mensaje del buzón no.
        $correo = ['carpeta' => '', 'uidvalidity' => 0, 'uid' => 0];
        if ($itemId > 0) {
            $fila = $this->fetchOne(
                'SELECT carpeta, uidvalidity, uid FROM correo_lote_items WHERE id = ? LIMIT 1',
                [(int) $itemId]
            );
            if ($fila) {
                $correo = $fila;
            }
        }
        $firma = self::firmaIncidencia(
            $tipo, $correo['carpeta'] ?? '', $correo['uidvalidity'] ?? 0, $correo['uid'] ?? 0, $mensaje
        );

        // Si esta misma incidencia ya se descartó antes, nace descartada. Sin
        // esto, reprocesar un rango devuelve a la lista todo lo que alguien ya
        // revisó y la lista no baja nunca.
        $descarte = $this->descarteDe($firma);

        $this->insert("INSERT INTO correo_incidencias
                          (lote_id, lote_item_id, tipo, mensaje, asunto, metadata,
                           firma, descartada, descartada_en, motivo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            (int) $loteId,
            $itemId > 0 ? (int) $itemId : null,
            $tipo,
            $mensaje,
            $asunto !== '' ? mb_substr($asunto, 0, 255, 'UTF-8') : null,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $firma,
            $descarte !== null ? 1 : 0,
            $descarte !== null ? date('Y-m-d H:i:s') : null,
            $descarte !== null ? ($descarte['motivo'] ?? null) : null,
        ]);
        // El contador de incidencias del lote sube con el recálculo del cierre
        // de la tanda (cerrarTanda): una incidencia no tiene por qué pagar un
        // UPDATE de cinco subconsultas en el acto.
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

    /**
     * El WHERE del historial, compartido con el descarte masivo: si el filtro
     * de la pantalla y el de "descartar todas" no fueran literalmente el mismo
     * código, "todas" acabaría alcanzando filas que el usuario no vio.
     */
    private function condicionesIncidencia($cuentaId, array $filters = [])
    {
        $where = ['l.cuenta_id = ?'];
        $params = [(int) $cuentaId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Quien busca "100%" busca ese texto, no "100 seguido de lo que
            // sea": los comodines viajan escapados, igual que en
            // CorreoIndice::buscar y en agregarFiltroCorreo.
            $like = '%' . addcslashes($q, '%_\\') . '%';
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

        // Por omisión solo lo pendiente: descartar sirve justamente para no
        // volver a verlo. 'descartadas' lo invierte y 'todas' no filtra.
        $ver = (string) ($filters['ver'] ?? 'pendientes');
        if ($ver === 'descartadas') {
            $where[] = 'x.descartada = 1';
        } elseif ($ver !== 'todas') {
            $where[] = 'x.descartada = 0';
        }

        return [implode(' AND ', $where), $params];
    }

    public function historialIncidencias($cuentaId, array $filters = [], $page = 1, $perPage = 50)
    {
        [$whereSql, $params] = $this->condicionesIncidencia((int) $cuentaId, $filters);
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
                    x.descartada, x.descartada_en, x.motivo,
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
        // El selector de tipo lleva el conteo de lo PENDIENTE de cada uno: es
        // lo que responde "¿qué me queda por revisar?" antes de abrir nada, y
        // lo que hace obvio que 194 cédulas equivocadas se descartan de una.
        $tipos = $this->fetchAll(
            "SELECT x.tipo,
                    SUM(CASE WHEN x.descartada = 0 THEN 1 ELSE 0 END) AS pendientes,
                    COUNT(*) AS total
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             WHERE l.cuenta_id = ?
             GROUP BY x.tipo ORDER BY x.tipo",
            [(int) $cuentaId]
        ) ?: [];

        $conteo = $this->fetchOne(
            "SELECT SUM(CASE WHEN x.descartada = 0 THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN x.descartada = 1 THEN 1 ELSE 0 END) AS descartadas
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             WHERE l.cuenta_id = ?",
            [(int) $cuentaId]
        ) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'ver' => (string) ($filters['ver'] ?? 'pendientes'),
            'pendientes' => (int) ($conteo['pendientes'] ?? 0),
            'descartadas' => (int) ($conteo['descartadas'] ?? 0),
            'tipos' => array_map(function ($row) {
                return [
                    'tipo' => (string) ($row['tipo'] ?? ''),
                    'pendientes' => (int) ($row['pendientes'] ?? 0),
                    'total' => (int) ($row['total'] ?? 0),
                ];
            }, $tipos),
        ];
    }

    // ── Descarte de incidencias ────────────────────────────────────
    //
    // Mismo mecanismo que Facturas ERP. Una incidencia revisada que no da más
    // trabajo se marca descartada y deja de contar; la marca vive por firma,
    // así que sobrevive a que el correo se vuelva a procesar. No borra nada:
    // se puede restaurar.

    /** El descarte permanente de esa firma, o null. */
    private function descarteDe($firma)
    {
        try {
            $fila = $this->fetchOne(
                'SELECT motivo FROM correo_incidencias_descartes WHERE firma = ? LIMIT 1',
                [(string) $firma]
            );
            return $fila ?: null;
        } catch (Throwable $e) {
            // Instalación sin la migración aplicada: nada está descartado.
            return null;
        }
    }

    /**
     * A qué incidencias alcanza una acción por id.
     *
     * Los ids llegan de la pantalla, así que por sí solos no prueban nada: sin
     * esto, mandar los ids de otro buzón los descarta igual. Se acota al buzón
     * que se está mirando y, además, a la empresa en curso; el JOIN va dentro
     * de la propia escritura para que no quede un hueco entre comprobar y
     * escribir.
     */
    private function alcanceIncidencias($cuentaId, array &$params)
    {
        $sql = '';
        if ((int) $cuentaId > 0) {
            $sql .= ' AND l.cuenta_id = ?';
            $params[] = (int) $cuentaId;
        }
        $sociedad = $this->sociedadId();
        if ($sociedad > 0) {
            $sql .= ' AND l.sociedad_id = ?';
            $params[] = $sociedad;
        }
        return $sql;
    }

    /**
     * Descarta por id. `$permanente` guarda además la firma, para que la
     * incidencia no vuelva a aparecer si el correo se reprocesa.
     */
    public function descartarIncidencias(array $ids, $motivo = '', $usuarioId = null, $permanente = true, $cuentaId = 0)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['descartadas' => 0, 'firmas' => 0];
        }
        $motivo = mb_substr(trim((string) $motivo), 0, 255, 'UTF-8');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $usuario = $usuarioId !== null ? (int) $usuarioId : null;

        $this->begin();
        try {
            $paramsFirmas = $ids;
            $alcance = $this->alcanceIncidencias($cuentaId, $paramsFirmas);
            $filas = $this->fetchAll(
                "SELECT DISTINCT x.firma, x.tipo, x.asunto
                   FROM correo_incidencias x
                   JOIN correo_lotes l ON l.id = x.lote_id
                  WHERE x.id IN ({$marcas}) AND x.firma <> ''{$alcance}",
                $paramsFirmas
            );

            $paramsUpdate = array_merge([$usuario, $motivo !== '' ? $motivo : null], $ids);
            $alcanceUpdate = $this->alcanceIncidencias($cuentaId, $paramsUpdate);
            $descartadas = $this->execute(
                "UPDATE correo_incidencias x
                   JOIN correo_lotes l ON l.id = x.lote_id
                    SET x.descartada = 1, x.descartada_en = NOW(), x.descartada_por = ?, x.motivo = ?
                  WHERE x.id IN ({$marcas}){$alcanceUpdate}",
                $paramsUpdate
            );

            $firmas = 0;
            if ($permanente) {
                foreach ($filas as $f) {
                    // Alcanza también a las apariciones de otros lotes del
                    // mismo buzón: si algo ya no interesa, no interesa en
                    // ninguna corrida. De otro buzón, no: la firma lleva
                    // carpeta y UID, que se repiten entre cuentas distintas.
                    $paramsFirma = [$usuario, $motivo !== '' ? $motivo : null, $f['firma']];
                    $alcanceFirma = $this->alcanceIncidencias($cuentaId, $paramsFirma);
                    $this->execute(
                        "UPDATE correo_incidencias x
                           JOIN correo_lotes l ON l.id = x.lote_id
                            SET x.descartada = 1, x.descartada_en = NOW(), x.descartada_por = ?, x.motivo = ?
                          WHERE x.firma = ? AND x.descartada = 0{$alcanceFirma}",
                        $paramsFirma
                    );
                    $this->execute(
                        "INSERT INTO correo_incidencias_descartes (firma, tipo, asunto, motivo, usuario_id)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE motivo = VALUES(motivo), usuario_id = VALUES(usuario_id)",
                        [$f['firma'], (string) $f['tipo'], $f['asunto'], $motivo !== '' ? $motivo : null, $usuario]
                    );
                    $firmas++;
                }
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        // Lo realmente descartado, no lo pedido: los ids que no son de este
        // buzón no cuentan.
        return ['descartadas' => (int) $descartadas, 'firmas' => $firmas];
    }

    public function restaurarIncidencias(array $ids, $cuentaId = 0)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['restauradas' => 0];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $this->begin();
        try {
            $paramsFirmas = $ids;
            $alcance = $this->alcanceIncidencias($cuentaId, $paramsFirmas);
            $firmas = array_column($this->fetchAll(
                "SELECT DISTINCT x.firma
                   FROM correo_incidencias x
                   JOIN correo_lotes l ON l.id = x.lote_id
                  WHERE x.id IN ({$marcas}) AND x.firma <> ''{$alcance}",
                $paramsFirmas
            ), 'firma');

            foreach ($firmas as $firma) {
                $this->execute('DELETE FROM correo_incidencias_descartes WHERE firma = ?', [$firma]);
                $paramsFirma = [$firma];
                $alcanceFirma = $this->alcanceIncidencias($cuentaId, $paramsFirma);
                $this->execute(
                    "UPDATE correo_incidencias x
                       JOIN correo_lotes l ON l.id = x.lote_id
                        SET x.descartada = 0, x.descartada_en = NULL, x.descartada_por = NULL, x.motivo = NULL
                      WHERE x.firma = ?{$alcanceFirma}",
                    $paramsFirma
                );
            }
            $paramsUpdate = $ids;
            $alcanceUpdate = $this->alcanceIncidencias($cuentaId, $paramsUpdate);
            $restauradas = $this->execute(
                "UPDATE correo_incidencias x
                   JOIN correo_lotes l ON l.id = x.lote_id
                    SET x.descartada = 0, x.descartada_en = NULL, x.descartada_por = NULL, x.motivo = NULL
                  WHERE x.id IN ({$marcas}){$alcanceUpdate}",
                $paramsUpdate
            );
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['restauradas' => (int) $restauradas];
    }

    /**
     * Los ids de TODO lo que cumple el filtro actual, para "descartar todas
     * las de este tipo" sin ir marcando página por página. Es el caso de uso
     * real: 194 cédulas equivocadas no se descartan de a cincuenta.
     */
    public function idsIncidencias($cuentaId, array $filtros = [], $tope = 5000)
    {
        [$where, $params] = $this->condicionesIncidencia((int) $cuentaId, $filtros);
        $tope = max(1, min(20000, (int) $tope));
        return array_map('intval', array_column($this->fetchAll(
            "SELECT x.id
             FROM correo_incidencias x
             JOIN correo_lotes l ON l.id = x.lote_id
             LEFT JOIN correo_lote_items li ON li.id = x.lote_item_id
             LEFT JOIN correo_indice i ON i.id = li.correo_indice_id
             WHERE {$where} LIMIT {$tope}",
            $params
        ), 'id'));
    }

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit()
    {
        if (self::getDB()->inTransaction()) { return self::getDB()->commit(); }
        return true;
    }
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
