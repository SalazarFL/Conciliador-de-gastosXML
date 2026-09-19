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

    /** Cuántas filas activas se traen y se pintan de una vez (ver getActivas). */
    private const TOPE_ACTIVAS = 500;

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
    public function getActivas($limite = self::TOPE_ACTIVAS)
    {
        // Orden por id: lo último cargado a la bandeja aparece arriba,
        // sin importar la fecha del correo (una factura vieja recién
        // traída del buzón debe salir de primera).
        //
        // Con tope: lo rechazado por Hacienda y lo de otra cédula no se
        // importa nunca y se queda ahí, así que sin él la bandeja es una lista
        // que solo crece y que la vista pinta entera en cada carga. Los
        // contadores de contarPorEstado() siguen diciendo cuántas hay en total.
        $limite = max(1, min(2000, (int) $limite));
        $params = [];
        $sql = "SELECT * FROM {$this->table}
                WHERE estado IN ('pendiente', 'rechazada', 'otra_cedula')"
             . $this->condicionSociedad('', $params) . "
                ORDER BY id DESC
                LIMIT {$limite}";
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
        $params = [(string) $uidCorreo, (string) $hashXml];
        $sql = "SELECT * FROM {$this->table} WHERE uid_correo = ? AND hash_xml = ?"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';
        return $this->fetchOne($sql, $params);
    }

    /**
     * Reactiva cualquier captura previa con los XML/PDF recién descargados.
     * La tabla tiene UNIQUE (sociedad_id, uid_correo, hash_xml), por eso
     * reprocesar el mismo correo desde la misma empresa reutiliza la fila y la
     * devuelve a estado pendiente. Desde otra empresa abre la suya: el buzón
     * puede ser compartido, la bandeja no.
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

    /**
     * Descartar se lleva los archivos (CorreoController::descartar), así que
     * la fila, que queda como historial, suelta también las rutas: si las
     * guardara, seguiría diciendo que tiene XML y PDF que ya no existen.
     */
    public function marcarDescartadas(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return 0;
        }

        // Solo lo que sigue en la bandeja: si alguien la importó mientras
        // tanto, esta fila ya no es descartable y sus archivos no se tocan
        // (ver actualizarEstado). Devuelve cuántas cambiaron de verdad.
        $estados = ['pendiente', 'rechazada', 'otra_cedula'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $esperados = implode(',', array_fill(0, count($estados), '?'));
        return $this->execute(
            "UPDATE {$this->table}
                SET estado = 'descartada', archivo_xml = NULL, archivo_pdf = NULL
              WHERE id IN ({$placeholders}) AND estado IN ({$esperados})",
            array_merge($ids, $estados)
        );
    }

    /**
     * De estas rutas, las que alguna fila fuera de $excluirIds sigue usando.
     *
     * Cada captura guarda su propia copia con nombre único, así que lo normal
     * es que no haya ninguna. Se pregunta igual antes de borrar: un archivo
     * que se borra por error no se recupera, y el que comparte otra fila (o
     * la bandeja de otra empresa) le hace falta a esa.
     */
    public function rutasEnUsoFuera(array $rutas, array $excluirIds)
    {
        $relativas = [];
        foreach ($rutas as $ruta) {
            $rel = RutaDocumento::relativa((string) $ruta);
            if ($rel !== '' && $rel !== null) {
                $relativas[$rel] = (string) $ruta;
            }
        }
        $excluirIds = array_values(array_filter(array_map('intval', $excluirIds)));
        if (empty($relativas)) {
            return [];
        }

        $enRutas = implode(',', array_fill(0, count($relativas), '?'));
        $sql = "SELECT archivo_xml, archivo_pdf FROM {$this->table}
                 WHERE (archivo_xml IN ({$enRutas}) OR archivo_pdf IN ({$enRutas}))";
        $params = array_merge(array_keys($relativas), array_keys($relativas));
        if (!empty($excluirIds)) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($excluirIds), '?')) . ')';
            $params = array_merge($params, $excluirIds);
        }

        $enUso = [];
        // Sin hidratar: se compara contra lo guardado, que es la ruta relativa.
        foreach ($this->query($sql, $params)->fetchAll() ?: [] as $fila) {
            foreach (['archivo_xml', 'archivo_pdf'] as $campo) {
                $rel = (string) ($fila[$campo] ?? '');
                if (isset($relativas[$rel])) {
                    $enUso[$relativas[$rel]] = true;
                }
            }
        }
        return $enUso;
    }

    /**
     * Cambia el estado de unas filas, solo si siguen en el que se espera.
     *
     * Dos personas pueden tocar la misma factura a la vez —una la importa y
     * otra la descarta— y cada pantalla trabaja con lo que vio al cargar. Sin
     * la condición de estado ganaba el último UPDATE: podía quedar "importada"
     * una fila cuyo XML y PDF ya se habían borrado. Ahora el que llega
     * segundo no cambia nada y `execute` devuelve 0, que es lo que mira quien
     * llama para decir "esa ya no estaba disponible".
     *
     * @param array|null $desde Estados admitidos; null = no condicionar.
     */
    private function actualizarEstado(array $ids, $estado, $importacionId = null, ?array $desde = ['pendiente'])
    {
        $ids = array_values(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $condicion = '';
        $esperados = [];
        if ($desde !== null && $desde !== []) {
            $esperados = array_values($desde);
            $condicion = ' AND estado IN (' . implode(',', array_fill(0, count($esperados), '?')) . ')';
        }

        if ($importacionId !== null) {
            $sql = "UPDATE {$this->table} SET estado = ?, importacion_id = ?
                     WHERE id IN ({$placeholders}){$condicion}";
            $params = array_merge([(string) $estado, (int) $importacionId], $ids, $esperados);
        } else {
            $sql = "UPDATE {$this->table} SET estado = ? WHERE id IN ({$placeholders}){$condicion}";
            $params = array_merge([(string) $estado], $ids, $esperados);
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
                    /*
                     * Con la empresa dentro, porque un buzón puede servir a
                     * varias (correo_cuenta_sociedades). Sin ella, el primer
                     * que revisara el buzón compartido se quedaba con la fila
                     * del mensaje y la segunda empresa no lo veía llegar nunca
                     * a su bandeja.
                     */
                    UNIQUE KEY uk_uid_xml (sociedad_id, uid_correo, hash_xml),
                    KEY idx_estado (estado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->query($sql);

        // Las bases ya instaladas traen la unicidad sin la empresa: se rehace
        // una vez. Agregarle una columna solo puede aflojarla, así que no hay
        // duplicados que resolver antes.
        try {
            $partes = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_uid_xml'",
                [$this->table]
            );
            if ($partes === 2) {
                $this->query("ALTER TABLE {$this->table} DROP INDEX uk_uid_xml,
                              ADD UNIQUE KEY uk_uid_xml (sociedad_id, uid_correo, hash_xml)");
            }
        } catch (Throwable $e) {
            // Sin permisos de DDL queda para la migración manual.
        }

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
