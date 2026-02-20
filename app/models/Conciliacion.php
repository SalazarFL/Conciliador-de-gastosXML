<?php
/**
 * Modelo de Conciliación
 * Gestiona conciliaciones entre facturas XML y gastos
 */

class Conciliacion extends Model
{
    protected $table = 'conciliaciones';
    
    /**
     * Obtener todas las conciliaciones completas (con vista)
     */
    public function getAllCompletas()
    {
        $sql = "SELECT * FROM v_conciliaciones_completas ORDER BY fecha_conciliacion DESC";
        return $this->fetchAll($sql);
    }
    
    /**
     * Obtener conciliaciones pendientes de revisión
     */
    public function getPendientesRevision()
    {
        $sql = "SELECT * FROM v_pendientes_revision ORDER BY score_total DESC";
        return $this->fetchAll($sql);
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
     * Contar conciliaciones por estado
     */
    public function countByEstado($codigoEstado)
    {
        $sql = "SELECT COUNT(*) 
                FROM {$this->table} c
                INNER JOIN catalogo_estados ce ON c.estado_id = ce.id
                WHERE ce.codigo = ?";
        
        return (int) $this->fetchColumn($sql, [$codigoEstado]);
    }
    
    /**
     * Obtener resumen de revisión
     */
    public function getResumenRevision()
    {
        $sql = "SELECT * FROM v_resumen_revision";
        return $this->fetchAll($sql);
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
