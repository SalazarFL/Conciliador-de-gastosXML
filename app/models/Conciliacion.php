<?php
/**
 * Modelo de Conciliación
 * Gestiona conciliaciones entre facturas XML y gastos
 */

class Conciliacion extends Model
{
    protected $table = 'conciliaciones';

    private $columnCache = [];
    
    /**
     * Obtener todas las conciliaciones completas (con vista)
     */
    public function getAllCompletas()
    {
        return $this->getGridRows();
    }
    
    /**
     * Obtener conciliaciones pendientes de revisión
     */
    public function getPendientesRevision()
    {
        $rows = $this->getGridRows();
        return array_values(array_filter($rows, function ($row) {
            return in_array($row['estado_codigo'] ?? '', ['requiere_revision', 'con_diferencias'], true);
        }));
    }
    
    /**
     * Obtener conciliaciones por estado
     */
    public function findByEstado($codigoEstado)
    {
        $sql = "SELECT c.*, ce.nombre as estado_nombre, ce.color_hex as estado_color
                FROM {$this->table} c
                INNER JOIN catalogo_estados ce ON c.estado_id = ce.id
                WHERE ce.codigo = ?
                ORDER BY c.fecha_conciliacion DESC";
        
        return $this->fetchAll($sql, [$codigoEstado]);
    }
    
    /**
     * Contar conciliaciones por estado (opcionalmente de una corrida específica)
     */
    public function countByEstado($codigoEstado, $corridaId = null)
    {
        $sql = "SELECT COUNT(*)
                FROM {$this->table} c
                INNER JOIN catalogo_estados ce ON c.estado_id = ce.id
                WHERE ce.codigo = ?";
        $params = [$codigoEstado];

        if ($corridaId !== null && (int) $corridaId > 0 && $this->hasColumn('corrida_id')) {
            $sql .= " AND c.corrida_id = ?";
            $params[] = (int) $corridaId;
        }

        return (int) $this->fetchColumn($sql, $params);
    }

    /**
     * Obtener resumen de revisión (opcionalmente de una corrida específica)
     */
    public function getResumenRevision($corridaId = null)
    {
        $joinExtra = '';
        $params = [];
        if ($corridaId !== null && (int) $corridaId > 0 && $this->hasColumn('corrida_id')) {
            $joinExtra = ' AND c.corrida_id = ?';
            $params[] = (int) $corridaId;
        }

        $sql = "SELECT e.codigo, e.nombre, e.color_hex as color, COUNT(c.id) as total
                FROM catalogo_estados e
                LEFT JOIN conciliaciones c ON c.estado_id = e.id{$joinExtra}
                GROUP BY e.id, e.codigo, e.nombre, e.color_hex, e.orden
                ORDER BY e.orden";
        return $this->fetchAll($sql, $params);
    }

    // ─── Corridas de conciliación (ejecuciones guardadas) ─────────────

    public function crearCorrida($nombre, $importacionXmlId = null, $importacionGastosId = null)
    {
        $sql = "INSERT INTO conciliacion_corridas (nombre, importacion_xml_id, importacion_gastos_id)
                VALUES (?, ?, ?)";
        return $this->insert($sql, [
            mb_substr((string) $nombre, 0, 255, 'UTF-8'),
            (int) $importacionXmlId > 0 ? (int) $importacionXmlId : null,
            (int) $importacionGastosId > 0 ? (int) $importacionGastosId : null,
        ]);
    }

    public function getCorridas($limit = 50)
    {
        $limit = max(1, (int) $limit);
        $sql = "SELECT id, nombre, importacion_xml_id, importacion_gastos_id,
                       total_registros, fecha_ejecucion
                FROM conciliacion_corridas
                ORDER BY fecha_ejecucion DESC, id DESC
                LIMIT {$limit}";
        return $this->fetchAll($sql) ?: [];
    }

    public function getUltimaCorridaId()
    {
        $id = $this->fetchColumn("SELECT MAX(id) FROM conciliacion_corridas");
        return $id !== null ? (int) $id : 0;
    }

    public function actualizarTotalCorrida($corridaId, $total)
    {
        return $this->execute(
            "UPDATE conciliacion_corridas SET total_registros = ? WHERE id = ?",
            [(int) $total, (int) $corridaId]
        );
    }

    /**
     * Facturas XML de una corrida que SÍ tienen gasto asociado
     * (excluye pendientes y gasto_sin_xml). Incluye el contenido XML
     * para generar el ZIP de descarga.
     */
    public function getFacturasParaZip($corridaId = null)
    {
        $sql = "SELECT f.id,
                       f.numero_factura_asistente,
                       f.fecha_emision,
                       f.archivo_xml,
                       f.ruta_xml,
                       f.hash_xml,
                       f.xml_contenido,
                       p.razon_social AS proveedor_nombre
                FROM {$this->table} c
                INNER JOIN facturas_xml f ON c.factura_xml_id = f.id
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE c.gasto_consolidado_id IS NOT NULL
                  AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')";
        $params = [];

        if ($corridaId !== null && (int) $corridaId > 0 && $this->hasColumn('corrida_id')) {
            $sql .= " AND c.corrida_id = ?";
            $params[] = (int) $corridaId;
        }

        $sql .= " ORDER BY f.fecha_emision, f.id";

        return $this->fetchAll($sql, $params) ?: [];
    }

    public function clearAll()
    {
        return $this->execute("DELETE FROM {$this->table}");
    }

    public function getEstadoMap()
    {
        $rows = $this->fetchAll("SELECT id, codigo, nombre, color_hex FROM catalogo_estados WHERE activo = 1 ORDER BY orden");
        $map = [];
        foreach ($rows as $row) {
            $map[$row['codigo']] = $row;
        }

        return $map;
    }

    public function crearFlexible($data)
    {
        $fields = [
            'factura_xml_id' => $data['factura_xml_id'] ?? $data['factura_id'] ?? null,
            'gasto_consolidado_id' => $data['gasto_consolidado_id'] ?? $data['gasto_id'] ?? null,
            'estado_id' => $data['estado_id'] ?? null,
            'diferencia_base' => $data['diferencia_base'] ?? 0,
            'diferencia_iva' => $data['diferencia_iva'] ?? 0,
            'diferencia_total' => $data['diferencia_total'] ?? 0,
            'porcentaje_diferencia' => $data['porcentaje_diferencia'] ?? 0,
            'notas' => $data['notas'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];

        if ($this->hasColumn('score_numero')) {
            $fields['score_numero'] = $data['score_numero'] ?? null;
        }
        if ($this->hasColumn('score_proveedor')) {
            $fields['score_proveedor'] = $data['score_proveedor'] ?? null;
        }
        if ($this->hasColumn('score_total')) {
            $fields['score_total'] = $data['score_total'] ?? null;
        }
        if ($this->hasColumn('match_tipo')) {
            $fields['match_tipo'] = $data['match_tipo'] ?? 'manual';
        }
        if ($this->hasColumn('observaciones_match')) {
            $fields['observaciones_match'] = $data['observaciones_match'] ?? null;
        }
        if ($this->hasColumn('corrida_id')) {
            $fields['corrida_id'] = !empty($data['corrida_id']) ? (int) $data['corrida_id'] : null;
        }

        $columns = array_keys($fields);
        $placeholders = array_fill(0, count($columns), '?');
        $params = [];
        foreach ($columns as $column) {
            $params[] = $fields[$column];
        }

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        return $this->insert($sql, $params);
    }

    /**
     * Filas de la grilla de conciliación.
     *
     * @param int|null $corridaId  ID de corrida a cargar. null = última corrida
     *                             (o todas las filas si aún no existen corridas).
     *                             0 = todas las filas sin filtrar.
     */
    public function getGridRows($corridaId = null)
    {
        $select = [
            'c.id AS conciliacion_id',
            'c.factura_xml_id',
            'c.gasto_consolidado_id',
            'c.fecha_conciliacion',
            'c.diferencia_base',
            'c.diferencia_iva',
            'c.diferencia_total',
            'c.porcentaje_diferencia',
            'c.notas',
            'e.codigo AS estado_codigo',
            'e.nombre AS estado_nombre',
            'e.color_hex AS estado_color',
            'f.importacion_id AS factura_importacion_id',
            'f.fecha_emision AS factura_fecha',
            'f.numero_factura_asistente AS factura_numero',
            'p.razon_social AS factura_proveedor',
            'f.iva AS factura_iva',
            'f.total AS factura_total',
            'g.fecha_max AS gasto_fecha',
            'g.numero_factura AS gasto_numero',
            'g.proveedor_texto AS gasto_proveedor',
            'g.suma_iva AS gasto_iva',
            'g.suma_total AS gasto_total'
        ];

        if ($this->hasColumn('score_numero')) {
            $select[] = 'c.score_numero';
        } else {
            $select[] = 'NULL AS score_numero';
        }
        if ($this->hasColumn('score_proveedor')) {
            $select[] = 'c.score_proveedor';
        } else {
            $select[] = 'NULL AS score_proveedor';
        }
        if ($this->hasColumn('score_total')) {
            $select[] = 'c.score_total';
        } else {
            $select[] = 'NULL AS score_total';
        }
        if ($this->hasColumn('match_tipo')) {
            $select[] = 'c.match_tipo';
        } else {
            $select[] = "'manual' AS match_tipo";
        }
        if ($this->hasColumn('revisado')) {
            $select[] = 'c.revisado';
        } else {
            $select[] = '0 AS revisado';
        }
        if ($this->hasColumn('revision_comentario')) {
            $select[] = 'c.revision_comentario';
        } else {
            $select[] = 'NULL AS revision_comentario';
        }

        $sql = "SELECT " . implode(",\n", $select) . "
                FROM conciliaciones c
                INNER JOIN catalogo_estados e ON c.estado_id = e.id
                LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id";

        $params = [];
        if ($this->hasColumn('corrida_id')) {
            if ($corridaId === null) {
                $corridaId = $this->getUltimaCorridaId();
            }
            if ((int) $corridaId > 0) {
                $sql .= " WHERE c.corrida_id = ?";
                $params[] = (int) $corridaId;
            }
        }

        return $this->fetchAll($sql, $params);
    }

    public function marcarManual($id, $estadoId, $comentario, $usuario)
    {
        $sets = ['estado_id = ?', 'notas = ?'];
        $params = [$estadoId, $comentario];

        if ($this->hasColumn('revisado')) {
            $sets[] = 'revisado = 1';
        }
        if ($this->hasColumn('revisado_por')) {
            $sets[] = 'revisado_por = ?';
            $params[] = $usuario;
        }
        if ($this->hasColumn('revisado_en')) {
            $sets[] = 'revisado_en = NOW()';
        }
        if ($this->hasColumn('revision_comentario')) {
            $sets[] = 'revision_comentario = ?';
            $params[] = $comentario;
        }
        if ($this->hasColumn('match_tipo')) {
            $sets[] = "match_tipo = 'manual'";
        }

        $params[] = $id;
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = ?";
        return $this->execute($sql, $params);
    }

    private function hasColumn($column)
    {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }

        $sql = "SELECT COUNT(*)
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?";

        $exists = (int) $this->fetchColumn($sql, [$this->table, $column]) > 0;
        $this->columnCache[$column] = $exists;
        return $exists;
    }
    
    /**
     * Insertar nueva conciliación
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table} 
                (factura_xml_id, gasto_consolidado_id, estado_id, diferencia_base, diferencia_iva,
                 diferencia_total, porcentaje_diferencia, notas, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['factura_xml_id'] ?? $data['factura_id'] ?? null,
            $data['gasto_consolidado_id'] ?? $data['gasto_id'] ?? null,
            $data['estado_id'],
            $data['diferencia_base'] ?? 0,
            $data['diferencia_iva'] ?? 0,
            $data['diferencia_total'] ?? 0,
            $data['porcentaje_diferencia'] ?? null,
            $data['notas'] ?? $data['observaciones'] ?? null,
            $data['metadata'] ?? null
        ];
        
        return $this->insert($sql, $params);
    }
    
    /**
     * Marcar como revisado
     */
    public function marcarRevisado($id, $usuario, $comentario = null)
    {
        $sql = "CALL sp_marcar_revisado(?, ?, ?)";
        return $this->execute($sql, [$id, $usuario, $comentario]);
    }
    
    /**
     * Actualizar observaciones
     */
    public function actualizarObservaciones($id, $observaciones)
    {
        $sql = "UPDATE {$this->table} SET notas = ? WHERE id = ?";
        return $this->execute($sql, [$observaciones, $id]);
    }
    
    /**
     * Obtener estadísticas generales
     */
    public function getEstadisticas()
    {
        $sql = "SELECT 
                    ce.codigo,
                    ce.nombre,
                    ce.color_hex as color,
                    COUNT(*) as cantidad,
                    COALESCE(SUM(CASE WHEN c.factura_xml_id IS NOT NULL THEN f.total ELSE 0 END), 0) as monto_total
                FROM {$this->table} c
                INNER JOIN catalogo_estados ce ON c.estado_id = ce.id
                LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
                GROUP BY ce.id, ce.codigo, ce.nombre, ce.color_hex
                ORDER BY ce.orden";
        
        return $this->fetchAll($sql);
    }
}
