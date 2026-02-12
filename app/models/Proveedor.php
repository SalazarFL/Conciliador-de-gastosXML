<?php
/**
 * Modelo de Proveedor
 * Gestiona proveedores normalizados
 */

class Proveedor extends Model
{
    protected $table = 'proveedores';
    
    /**
     * Buscar proveedor por RFC
     */
    public function findByRfc($rfc)
    {
        $sql = "SELECT * FROM {$this->table} WHERE rfc = ? LIMIT 1";
        return $this->fetchOne($sql, [$rfc]);
    }
    
    /**
     * Buscar proveedor por razón social
     */
    public function findByRazonSocial($razonSocial)
    {
        $sql = "SELECT * FROM {$this->table} WHERE razon_social LIKE ? ORDER BY razon_social";
        return $this->fetchAll($sql, ['%' . $razonSocial . '%']);
    }
    
    /**
     * Obtener o crear proveedor
     */
    public function obtenerOCrear($rfc, $razonSocial)
    {
        // Buscar existente
        $proveedor = $this->findByRfc($rfc);
        
        if ($proveedor) {
            return $proveedor['id'];
        }
        
        // Crear nuevo
        $sql = "INSERT INTO {$this->table} (rfc, razon_social) VALUES (?, ?)";
        return $this->insert($sql, [$rfc, $razonSocial]);
    }
    
    /**
     * Actualizar proveedor
     */
    public function actualizar($id, $data)
    {
        $sql = "UPDATE {$this->table} 
                SET rfc = ?, razon_social = ? 
                WHERE id = ?";
        
        $params = [
            $data['rfc'],
            $data['razon_social'],
            $id
        ];
        
        return $this->execute($sql, $params);
    }
    
    /**
     * Buscar proveedores (autocompletado)
     */
    public function buscar($termino)
    {
        $sql = "SELECT id, rfc, razon_social 
                FROM {$this->table} 
                WHERE rfc LIKE ? OR razon_social LIKE ?
                ORDER BY razon_social
                LIMIT 20";
        
        $param = '%' . $termino . '%';
        return $this->fetchAll($sql, [$param, $param]);
    }
    
    /**
     * Obtener proveedores con estadísticas
     */
    public function getAllWithStats()
    {
        $sql = "SELECT p.*,
                    COUNT(DISTINCT f.id) as total_facturas,
                    COUNT(DISTINCT g.id) as total_gastos,
                    COALESCE(SUM(f.total), 0) as monto_facturas,
                    COALESCE(SUM(g.monto_total), 0) as monto_gastos
                FROM {$this->table} p
                LEFT JOIN facturas_xml f ON p.id = f.proveedor_id
                LEFT JOIN gastos_consolidados g ON p.id = g.proveedor_id
                GROUP BY p.id
                ORDER BY p.razon_social";
        
        return $this->fetchAll($sql);
    }
}
