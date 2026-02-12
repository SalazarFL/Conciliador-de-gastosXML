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
        $sql = "SELECT c.*, ce.nombre as estado_nombre, ce.color as estado_color
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
                (factura_id, gasto_id, estado_id, score_numero, score_proveedor, 
                 score_total, match_tipo, diferencia_monto, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['factura_id'] ?? null,
            $data['gasto_id'] ?? null,
            $data['estado_id'],
            $data['score_numero'] ?? null,
            $data['score_proveedor'] ?? null,
            $data['score_total'] ?? null,
            $data['match_tipo'] ?? null,
            $data['diferencia_monto'] ?? null,
            $data['observaciones'] ?? null
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
        $sql = "UPDATE {$this->table} SET observaciones = ? WHERE id = ?";
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
                    ce.color,
                    COUNT(*) as cantidad,
                    COALESCE(SUM(CASE WHEN c.factura_id IS NOT NULL THEN f.total ELSE 0 END), 0) as monto_total
                FROM {$this->table} c
                INNER JOIN catalogo_estados ce ON c.estado_id = ce.id
                LEFT JOIN facturas_xml f ON c.factura_id = f.id
                GROUP BY ce.id, ce.codigo, ce.nombre, ce.color
                ORDER BY ce.orden";
        
        return $this->fetchAll($sql);
    }
}
