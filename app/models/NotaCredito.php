<?php
/**
 * Persistencia del módulo "Notas de crédito".
 */
class NotaCredito extends Model
{
    protected $table = 'notas_credito_listados';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_listados (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sociedad_id INT UNSIGNED NOT NULL,
                nombre VARCHAR(255) NOT NULL,
                empresa_reporte VARCHAR(255) NULL,
                periodo_desde DATE NULL,
                periodo_hasta DATE NULL,
                archivo_origen VARCHAR(255) NOT NULL,
                archivo_ruta VARCHAR(500) NOT NULL,
                archivo_hash CHAR(64) NOT NULL,
                total_lineas INT UNSIGNED NOT NULL DEFAULT 0,
                fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_nc_archivo_hash (archivo_hash),
                KEY idx_nc_listado_sociedad (sociedad_id, fecha_subida)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_lineas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                listado_id INT UNSIGNED NOT NULL,
                fila_origen INT UNSIGNED NOT NULL,
                proveedor_codigo VARCHAR(50) NULL,
                proveedor_nombre VARCHAR(255) NOT NULL,
                sucursal VARCHAR(150) NULL,
                documento VARCHAR(255) NOT NULL,
                fecha DATE NOT NULL,
                nc_proveedor VARCHAR(100) NULL,
                fecha_nc_proveedor DATE NULL,
                entrada_asociada VARCHAR(255) NULL,
                moneda CHAR(3) NOT NULL,
                monto DECIMAL(18,2) NOT NULL,
                saldo DECIMAL(18,2) NOT NULL DEFAULT 0,
                monto_conversion DECIMAL(18,2) NOT NULL DEFAULT 0,
                datos_origen LONGTEXT NULL,
                factura_xml_id INT UNSIGNED NULL,
                estado ENUM('sin_respaldo','coincide','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
                diferencia DECIMAL(18,2) NULL,
                metodo_match ENUM('ninguno','numero','atributos','manual') NOT NULL DEFAULT 'ninguno',
                score_proveedor DECIMAL(5,1) NULL,
                match_manual TINYINT(1) NOT NULL DEFAULT 0,
                bloqueo_automatico TINYINT(1) NOT NULL DEFAULT 0,
                motivo_match VARCHAR(255) NULL,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_nc_xml_por_listado (listado_id, factura_xml_id),
                KEY idx_nc_linea_listado_estado (listado_id, estado),
                KEY idx_nc_linea_documento (documento),
                KEY idx_nc_linea_nc_proveedor (nc_proveedor),
                KEY idx_nc_linea_factura_xml (factura_xml_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_verificaciones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                listado_id INT UNSIGNED NOT NULL,
                origen VARCHAR(30) NOT NULL DEFAULT 'automatico',
                fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_fin DATETIME NULL,
                coincide INT UNSIGNED NOT NULL DEFAULT 0,
                con_diferencia INT UNSIGNED NOT NULL DEFAULT 0,
                sin_respaldo INT UNSIGNED NOT NULL DEFAULT 0,
                cantidad_cambios INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_nc_verificacion_listado_fecha (listado_id, fecha_inicio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_historial (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                verificacion_id BIGINT UNSIGNED NOT NULL,
                listado_id INT UNSIGNED NOT NULL,
                linea_id INT UNSIGNED NULL,
                fila_origen INT UNSIGNED NULL,
                documento VARCHAR(255) NULL,
                proveedor_nombre VARCHAR(255) NULL,
                nc_proveedor VARCHAR(100) NULL,
                moneda CHAR(3) NULL,
                estado_anterior VARCHAR(30) NOT NULL,
                estado_nuevo VARCHAR(30) NOT NULL,
                factura_xml_id_anterior INT UNSIGNED NULL,
                factura_xml_id_nuevo INT UNSIGNED NULL,
                diferencia_anterior DECIMAL(18,2) NULL,
                diferencia_nueva DECIMAL(18,2) NULL,
                motivo_anterior VARCHAR(255) NULL,
                motivo_nuevo VARCHAR(255) NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_nc_historial_verificacion (verificacion_id, id),
                KEY idx_nc_historial_linea (linea_id, fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // database/migration_notas_credito.sql cubre instalaciones sin DDL en runtime.
        }
    }

    public function begin()
    {
        return self::getDB()->beginTransaction();
    }

    public function commit()
    {
        return self::getDB()->commit();
    }

    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return false;
    }

    public function inTransaction()
    {
        return self::getDB()->inTransaction();
    }

    public function crearListado(array $data)
    {
        $sql = "INSERT INTO notas_credito_listados
                    (sociedad_id, nombre, empresa_reporte, periodo_desde, periodo_hasta,
                     archivo_origen, archivo_ruta, archivo_hash, total_lineas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return (int) $this->insert($sql, [
            (int) $data['sociedad_id'],
            (string) $data['nombre'],
            $data['empresa_reporte'] ?: null,
            $data['periodo_desde'] ?: null,
            $data['periodo_hasta'] ?: null,
            (string) $data['archivo_origen'],
            (string) $data['archivo_ruta'],
            (string) $data['archivo_hash'],
            (int) $data['total_lineas'],
        ]);
    }

    private const COLUMNAS_LINEA = [
        'listado_id', 'fila_origen', 'proveedor_codigo', 'proveedor_nombre', 'sucursal',
        'documento', 'fecha', 'nc_proveedor', 'fecha_nc_proveedor', 'entrada_asociada',
        'moneda', 'monto', 'saldo', 'monto_conversion', 'datos_origen',
    ];

    /** Los valores de una línea, en el orden de COLUMNAS_LINEA. */
    private function valoresLinea($listadoId, array $linea)
    {
        return [
            (int) $listadoId,
            (int) $linea['fila_origen'],
            $linea['proveedor_codigo'] ?: null,
            (string) $linea['proveedor_nombre'],
            $linea['sucursal'] ?: null,
            (string) $linea['documento'],
            (string) $linea['fecha'],
            $linea['nc_proveedor'] ?: null,
            $linea['fecha_nc_proveedor'] ?: null,
            $linea['entrada_asociada'] ?: null,
            (string) $linea['moneda'],
            (float) $linea['monto'],
            (float) $linea['saldo'],
            (float) $linea['monto_conversion'],
            $linea['datos_origen'] ?: null,
        ];
    }

    public function crearLinea($listadoId, array $linea)
    {
        return (int) $this->insert(
            'INSERT INTO notas_credito_lineas (' . implode(', ', self::COLUMNAS_LINEA) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count(self::COLUMNAS_LINEA), '?')) . ')',
            $this->valoresLinea($listadoId, $linea)
        );
    }

    /**
     * Inserta las líneas de un listado en tandas. Un CSV de notas son miles
     * de filas, y con la base en el servidor cada INSERT cuesta un viaje de
     * ida y vuelta: de a una, la carga se pasa del tiempo máximo de la
     * petición antes de llegar al final.
     */
    public function crearLineasLote($listadoId, array $lineas, $tam = 200)
    {
        $columnas = implode(', ', self::COLUMNAS_LINEA);
        $hueco = '(' . implode(', ', array_fill(0, count(self::COLUMNAS_LINEA), '?')) . ')';

        $insertadas = 0;
        foreach (array_chunk(array_values($lineas), max(1, (int) $tam)) as $tanda) {
            $params = [];
            foreach ($tanda as $linea) {
                foreach ($this->valoresLinea($listadoId, $linea) as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                "INSERT INTO notas_credito_lineas ({$columnas}) VALUES "
                . implode(', ', array_fill(0, count($tanda), $hueco)),
                $params
            );
            $insertadas += count($tanda);
        }

        return $insertadas;
    }

    public function buscarPorHash($hash)
    {
        $row = $this->fetchOne(
            "SELECT * FROM notas_credito_listados WHERE archivo_hash = ? LIMIT 1",
            [(string) $hash]
        );
        return $row ?: null;
    }

    public function getListados($sociedadId, $limit = 100)
    {
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre
                FROM notas_credito_listados l
                JOIN sociedades s ON s.id = l.sociedad_id
                WHERE l.sociedad_id = ?
                ORDER BY l.id DESC
                LIMIT " . max(1, (int) $limit);
        return $this->fetchAll($sql, [(int) $sociedadId]) ?: [];
    }

    public function getListado($id)
    {
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, s.cedula AS sociedad_cedula
                FROM notas_credito_listados l
                JOIN sociedades s ON s.id = l.sociedad_id
                WHERE l.id = ? LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $id]);
        return $row ?: null;
    }

    public function getLinea($id)
    {
        $sql = "SELECT nl.*, l.sociedad_id, l.nombre AS listado_nombre,
                       s.cedula AS sociedad_cedula,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                JOIN notas_credito_listados l ON l.id = nl.listado_id
                JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE nl.id = ? LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $id]);
        return $row ?: null;
    }

    public function getLineasParaMatching($listadoId)
    {
        $sql = "SELECT nl.*, f.total AS xml_total, f.moneda AS xml_moneda
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                WHERE nl.listado_id = ?
                ORDER BY nl.id ASC";
        return $this->fetchAll($sql, [(int) $listadoId]) ?: [];
    }

    public function getLineasPaginadas($listadoId, array $filters, $page = 1, $perPage = 100)
    {
        $where = ['nl.listado_id = ?'];
        $params = [(int) $listadoId];

        if (!empty($filters['estado'])
            && in_array($filters['estado'], ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $filters['estado'];
        }
        if (!empty($filters['proveedor'])) {
            $where[] = 'nl.proveedor_nombre = ?';
            $params[] = $filters['proveedor'];
        }
        if (!empty($filters['sucursal'])) {
            $where[] = 'nl.sucursal = ?';
            $params[] = $filters['sucursal'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(nl.documento LIKE ? OR nl.nc_proveedor LIKE ? OR nl.entrada_asociada LIKE ? OR nl.proveedor_nombre LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM notas_credito_lineas nl WHERE {$whereSql}",
            $params
        );

        $perPage = max(20, min(200, (int) $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $page, $pages));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT nl.id, nl.listado_id, nl.fila_origen,
                       nl.proveedor_codigo, nl.proveedor_nombre, nl.sucursal,
                       nl.documento, nl.fecha, nl.nc_proveedor, nl.fecha_nc_proveedor,
                       nl.entrada_asociada, nl.moneda, nl.monto, nl.saldo,
                       nl.monto_conversion, nl.factura_xml_id, nl.estado,
                       nl.diferencia, nl.metodo_match, nl.score_proveedor,
                       nl.match_manual, nl.bloqueo_automatico, nl.motivo_match,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE {$whereSql}
                ORDER BY FIELD(nl.estado, 'con_diferencia', 'sin_respaldo', 'coincide'),
                         nl.proveedor_nombre ASC, nl.fecha DESC, nl.id ASC
                LIMIT {$perPage} OFFSET {$offset}";
        return [
            'rows' => $this->fetchAll($sql, $params) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Devuelve todas las líneas que cumplen los filtros. Esta consulta es la
     * fuente de la búsqueda en vivo y deliberadamente no usa paginación: los
     * filtros se aplican al listado completo en la base de datos.
     */
    public function getLineasFiltradas($listadoId, array $filters)
    {
        $where = ['nl.listado_id = ?'];
        $params = [(int) $listadoId];

        $estado = trim((string) ($filters['estado'] ?? ''));
        if ($estado !== '' && in_array($estado, ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $estado;
        }
        $estadoColumna = trim((string) ($filters['col_estado'] ?? ''));
        if ($estadoColumna !== '' && in_array($estadoColumna, ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $estadoColumna;
        }

        $condicionSaldo = trim((string) ($filters['condicion_saldo'] ?? ''));
        if ($condicionSaldo === 'con_saldo') {
            $where[] = 'nl.saldo <> 0';
        } elseif ($condicionSaldo === 'sin_saldo') {
            $where[] = 'nl.saldo = 0';
        }

        $condicionNcProveedor = trim((string) ($filters['condicion_nc_proveedor'] ?? ''));
        if ($condicionNcProveedor === 'con_nc_proveedor') {
            $where[] = "nl.nc_proveedor IS NOT NULL AND TRIM(nl.nc_proveedor) <> ''";
        } elseif ($condicionNcProveedor === 'sin_nc_proveedor') {
            $where[] = "(nl.nc_proveedor IS NULL OR TRIM(nl.nc_proveedor) = '')";
        }

        if (trim((string) ($filters['proveedor'] ?? '')) !== '') {
            $where[] = 'nl.proveedor_nombre = ?';
            $params[] = trim((string) $filters['proveedor']);
        }
        if (trim((string) ($filters['sucursal'] ?? '')) !== '') {
            $where[] = 'nl.sucursal = ?';
            $params[] = trim((string) $filters['sucursal']);
        }

        $textFilters = [
            'proveedor_codigo' => 'nl.proveedor_codigo',
            'proveedor_nombre' => 'nl.proveedor_nombre',
            'sucursal_texto' => 'nl.sucursal',
            'documento' => 'nl.documento',
            'nc_proveedor' => 'nl.nc_proveedor',
            'entrada_asociada' => 'nl.entrada_asociada',
            'nc_xml' => "CONCAT_WS(' ', f.consecutivo_completo, f.numero_factura_asistente, p.razon_social)",
        ];
        foreach ($textFilters as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "{$column} LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        foreach (['fecha' => 'nl.fecha', 'fecha_nc_proveedor' => 'nl.fecha_nc_proveedor'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        $moneda = strtoupper(trim((string) ($filters['moneda'] ?? '')));
        if (in_array($moneda, ['CRC', 'USD'], true)) {
            $where[] = 'UPPER(nl.moneda) = ?';
            $params[] = $moneda;
        }

        $numericFilters = [
            'monto' => 'nl.monto',
            'saldo' => 'nl.saldo',
            'monto_conversion' => 'nl.monto_conversion',
            'xml_total' => 'f.total',
            'diferencia' => 'nl.diferencia',
        ];
        foreach ($numericFilters as $key => $column) {
            $value = preg_replace('/[₡$,\s]/u', '', (string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "CAST({$column} AS CHAR) LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        $global = trim((string) ($filters['q'] ?? ''));
        if ($global !== '') {
            $like = '%' . $global . '%';
            $where[] = "(nl.proveedor_codigo LIKE ? OR nl.proveedor_nombre LIKE ?
                        OR nl.sucursal LIKE ? OR nl.documento LIKE ? OR nl.nc_proveedor LIKE ?
                        OR nl.entrada_asociada LIKE ? OR f.consecutivo_completo LIKE ?
                        OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)";
            for ($i = 0; $i < 9; $i++) {
                $params[] = $like;
            }
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT nl.id, nl.listado_id, nl.fila_origen,
                       nl.proveedor_codigo, nl.proveedor_nombre, nl.sucursal,
                       nl.documento, nl.fecha, nl.nc_proveedor, nl.fecha_nc_proveedor,
                       nl.entrada_asociada, nl.moneda, nl.monto, nl.saldo,
                       nl.monto_conversion, nl.factura_xml_id, nl.estado,
                       nl.diferencia, nl.metodo_match, nl.score_proveedor,
                       nl.match_manual, nl.bloqueo_automatico, nl.motivo_match,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE {$whereSql}
                ORDER BY FIELD(nl.estado, 'con_diferencia', 'sin_respaldo', 'coincide'),
                         nl.proveedor_nombre ASC, nl.fecha DESC, nl.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function opcionesFiltros($listadoId)
    {
        return [
            'proveedores' => $this->fetchAll(
                "SELECT DISTINCT proveedor_nombre AS valor
                 FROM notas_credito_lineas WHERE listado_id = ?
                 ORDER BY proveedor_nombre",
                [(int) $listadoId]
            ) ?: [],
            'sucursales' => $this->fetchAll(
                "SELECT DISTINCT sucursal AS valor
                 FROM notas_credito_lineas
                 WHERE listado_id = ? AND sucursal IS NOT NULL AND sucursal <> ''
                 ORDER BY sucursal",
                [(int) $listadoId]
            ) ?: [],
        ];
    }

    public function resumen($listadoId)
    {
        $rows = $this->fetchAll(
            "SELECT estado, COUNT(*) cantidad, COALESCE(SUM(monto), 0) monto
             FROM notas_credito_lineas
             WHERE listado_id = ?
             GROUP BY estado",
            [(int) $listadoId]
        ) ?: [];
        $result = [
            'sin_respaldo' => 0,
            'coincide' => 0,
            'con_diferencia' => 0,
            'total' => 0,
        ];
        foreach ($rows as $row) {
            $result[$row['estado']] = (int) $row['cantidad'];
            $result['total'] += (int) $row['cantidad'];
        }
        return $result;
    }

    public function iniciarVerificacion($listadoId, $origen = 'automatico')
    {
        return (int) $this->insert(
            "INSERT INTO notas_credito_verificaciones (listado_id, origen) VALUES (?, ?)",
            [(int) $listadoId, substr(trim((string) $origen) ?: 'automatico', 0, 30)]
        );
    }

    public function registrarCambioVerificacion($verificacionId, array $anterior, array $nuevo)
    {
        $estadoAnterior = (string) ($anterior['estado'] ?? 'sin_respaldo');
        $estadoNuevo = (string) ($nuevo['estado'] ?? 'sin_respaldo');
        $xmlAnterior = !empty($anterior['factura_xml_id']) ? (int) $anterior['factura_xml_id'] : null;
        $xmlNuevo = !empty($nuevo['factura_xml_id']) ? (int) $nuevo['factura_xml_id'] : null;
        $diferenciaAnterior = $anterior['diferencia'] ?? null;
        $diferenciaNueva = $nuevo['diferencia'] ?? null;
        $motivoAnterior = trim((string) ($anterior['motivo_match'] ?? '')) ?: null;
        $motivoNuevo = trim((string) ($nuevo['motivo_match'] ?? '')) ?: null;

        $cambio = $estadoAnterior !== $estadoNuevo
            || $xmlAnterior !== $xmlNuevo
            || self::valorDecimalHistorial($diferenciaAnterior) !== self::valorDecimalHistorial($diferenciaNueva)
            || $motivoAnterior !== $motivoNuevo;
        if (!$cambio) {
            return false;
        }

        $sql = "INSERT INTO notas_credito_historial
                    (verificacion_id, listado_id, linea_id, fila_origen, documento,
                     proveedor_nombre, nc_proveedor, moneda, estado_anterior, estado_nuevo,
                     factura_xml_id_anterior, factura_xml_id_nuevo,
                     diferencia_anterior, diferencia_nueva, motivo_anterior, motivo_nuevo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $this->insert($sql, [
            (int) $verificacionId,
            (int) ($anterior['listado_id'] ?? 0),
            (int) ($anterior['id'] ?? 0) ?: null,
            (int) ($anterior['fila_origen'] ?? 0) ?: null,
            trim((string) ($anterior['documento'] ?? '')) ?: null,
            trim((string) ($anterior['proveedor_nombre'] ?? '')) ?: null,
            trim((string) ($anterior['nc_proveedor'] ?? '')) ?: null,
            trim((string) ($anterior['moneda'] ?? '')) ?: null,
            $estadoAnterior,
            $estadoNuevo,
            $xmlAnterior,
            $xmlNuevo,
            $diferenciaAnterior,
            $diferenciaNueva,
            $motivoAnterior,
            $motivoNuevo,
        ]);
        return true;
    }

    private static function valorDecimalHistorial($value)
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }

    public function finalizarVerificacion($verificacionId, array $stats, $cantidadCambios)
    {
        return $this->execute(
            "UPDATE notas_credito_verificaciones
             SET fecha_fin = NOW(), coincide = ?, con_diferencia = ?, sin_respaldo = ?, cantidad_cambios = ?
             WHERE id = ?",
            [
                (int) ($stats['coincide'] ?? 0),
                (int) ($stats['con_diferencia'] ?? 0),
                (int) ($stats['sin_respaldo'] ?? 0),
                (int) $cantidadCambios,
                (int) $verificacionId,
            ]
        );
    }

    public function getVerificaciones($listadoId, $limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));
        return $this->fetchAll(
            "SELECT id, listado_id, origen, fecha_inicio, fecha_fin, coincide,
                    con_diferencia, sin_respaldo, cantidad_cambios
             FROM notas_credito_verificaciones
             WHERE listado_id = ?
             ORDER BY id DESC
             LIMIT {$limit}",
            [(int) $listadoId]
        ) ?: [];
    }

    public function getCambiosVerificacion($verificacionId, $listadoId)
    {
        $sql = "SELECT h.*,
                       COALESCE(fa.numero_factura_asistente, fa.consecutivo_completo) AS xml_anterior,
                       COALESCE(fn.numero_factura_asistente, fn.consecutivo_completo) AS xml_nuevo
                FROM notas_credito_historial h
                LEFT JOIN facturas_xml fa ON fa.id = h.factura_xml_id_anterior
                LEFT JOIN facturas_xml fn ON fn.id = h.factura_xml_id_nuevo
                WHERE h.verificacion_id = ? AND h.listado_id = ?
                ORDER BY h.id ASC";
        return $this->fetchAll($sql, [(int) $verificacionId, (int) $listadoId]) ?: [];
    }

    public function actualizarMatch($lineaId, $facturaId, $estado, $diferencia, $metodo, $score, $manual, $motivo, $bloqueo = 0)
    {
        $sql = "UPDATE notas_credito_lineas
                SET factura_xml_id = ?, estado = ?, diferencia = ?, metodo_match = ?,
                    score_proveedor = ?, match_manual = ?, bloqueo_automatico = ?,
                    motivo_match = ?
                WHERE id = ?";
        return $this->execute($sql, [
            $facturaId ?: null,
            (string) $estado,
            $diferencia,
            (string) $metodo,
            $score,
            $manual ? 1 : 0,
            $bloqueo ? 1 : 0,
            $motivo ?: null,
            (int) $lineaId,
        ]);
    }

    /** Columnas que escribe el emparejamiento, en el orden del UPDATE por tandas. */
    private const COLUMNAS_MATCH = [
        'factura_xml_id', 'estado', 'diferencia', 'metodo_match',
        'score_proveedor', 'match_manual', 'bloqueo_automatico', 'motivo_match',
    ];

    /**
     * Aplica muchos emparejamientos en pocas consultas.
     *
     * Verificar un listado hacía un UPDATE por línea. Con la base en la misma
     * máquina eran décimas de segundo y nadie lo notó nunca; con la base en el
     * servidor cada uno cuesta la latencia de la red, y un listado de 2 100
     * líneas tardaba casi seis minutos: la pantalla moría en el límite de
     * ejecución de PHP sin decir por qué.
     *
     * Cada tanda resuelve hasta $tam líneas con un solo UPDATE, usando CASE
     * sobre el id. El `ELSE columna` deja intacta cualquier fila que entre por
     * el WHERE sin estar en la tanda: no puede borrar nada por accidente.
     *
     * @param array $filas Cada una con 'id' y las columnas de COLUMNAS_MATCH.
     */
    public function actualizarMatchLote(array $filas, $tam = 100)
    {
        $afectadas = 0;
        foreach (array_chunk(array_values($filas), max(1, (int) $tam)) as $tanda) {
            $sets = [];
            $params = [];
            foreach (self::COLUMNAS_MATCH as $columna) {
                $caso = "{$columna} = CASE id";
                foreach ($tanda as $fila) {
                    $caso .= ' WHEN ? THEN ?';
                    $params[] = (int) $fila['id'];
                    $params[] = $fila[$columna];
                }
                $sets[] = $caso . " ELSE {$columna} END";
            }

            $ids = array_map(function ($fila) { return (int) $fila['id']; }, $tanda);
            $afectadas += (int) $this->execute(
                'UPDATE notas_credito_lineas SET ' . implode(', ', $sets)
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, $ids)
            );
        }
        return $afectadas;
    }

    /**
     * Prepara una revalidación completa sin tocar vínculos manuales ni el
     * bloqueo solicitado por el usuario. Evita que un vínculo automático
     * anterior choque con la restricción de un XML por listado.
     */
    public function limpiarMatchesAutomaticos($listadoId)
    {
        $sql = "UPDATE notas_credito_lineas
                SET factura_xml_id = NULL,
                    estado = 'sin_respaldo',
                    diferencia = NULL,
                    metodo_match = 'ninguno',
                    score_proveedor = NULL,
                    motivo_match = NULL
                WHERE listado_id = ? AND match_manual = 0";
        return $this->execute($sql, [(int) $listadoId]);
    }

    public function getFacturasNcSociedad($cedula)
    {
        $cedula = preg_replace('/\D+/', '', (string) $cedula);
        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) AS moneda,
                       f.receptor_id, p.rfc AS proveedor_cedula,
                       p.razon_social AS proveedor_nombre,
                       p.alias AS proveedor_alias
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                ORDER BY f.id ASC";
        return $this->fetchAll($sql, [$cedula]) ?: [];
    }

    public function getFacturaNcValida($facturaId, $cedula)
    {
        $cedula = preg_replace('/\D+/', '', (string) $cedula);
        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) AS moneda,
                       f.receptor_id, p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.id = ? AND f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $facturaId, $cedula]);
        return $row ?: null;
    }

    public function facturaUsadaEnListado($listadoId, $facturaId, $exceptLineaId = 0)
    {
        $sql = "SELECT id FROM notas_credito_lineas
                WHERE listado_id = ? AND factura_xml_id = ? AND id <> ?
                LIMIT 1";
        return (bool) $this->fetchOne($sql, [
            (int) $listadoId,
            (int) $facturaId,
            (int) $exceptLineaId,
        ]);
    }

    public function getCandidatasManuales(array $linea, $query = '')
    {
        $params = [
            preg_replace('/\D+/', '', (string) $linea['sociedad_cedula']),
            (string) $linea['moneda'],
            (int) $linea['listado_id'],
            (int) $linea['id'],
        ];
        $filter = '';
        if (trim((string) $query) !== '') {
            $like = '%' . trim((string) $query) . '%';
            $filter = " AND (f.consecutivo_completo LIKE ? OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)";
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) moneda,
                       p.razon_social AS proveedor_nombre,
                       p.alias AS proveedor_alias
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                  AND UPPER(f.moneda) = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM notas_credito_lineas usada
                      WHERE usada.listado_id = ? AND usada.factura_xml_id = f.id AND usada.id <> ?
                  )
                  {$filter}
                ORDER BY ABS(f.total - ?), f.id DESC
                LIMIT 100";
        $params[] = (float) $linea['monto'];
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getListadosPorSociedad($sociedadId)
    {
        return $this->fetchAll(
            "SELECT id FROM notas_credito_listados WHERE sociedad_id = ? ORDER BY id DESC",
            [(int) $sociedadId]
        ) ?: [];
    }

    public function eliminarListado($id)
    {
        return $this->execute("DELETE FROM notas_credito_listados WHERE id = ?", [(int) $id]);
    }
}
