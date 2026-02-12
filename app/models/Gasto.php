<?php
/**
 * Modelo de Gasto
 * Gestiona gastos consolidados
 */

class Gasto extends Model
{
    protected $table = 'gastos_consolidados';
    
    /**
     * Obtener todos los gastos con información de proveedor
     */
    public function getAllWithProveedor()
    {
        $sql = "SELECT g.*, p.razon_social as proveedor_nombre, i.nombre_archivo as archivo_importacion
                FROM {$this->table} g
                LEFT JOIN proveedores p ON g.proveedor_id = p.id
                LEFT JOIN importaciones i ON g.importacion_id = i.id
                ORDER BY g.fecha_gasto DESC";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Buscar gastos por proveedor
     */
    public function findByProveedor($proveedorId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE proveedor_id = ? ORDER BY fecha_gasto DESC";
        return $this->fetchAll($sql, [$proveedorId]);
    }
    
    /**
     * Buscar gastos por número de factura
     */
    public function findByNumeroFactura($numero)
    {
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura LIKE ? ORDER BY fecha_gasto DESC";
        return $this->fetchAll($sql, ['%' . $numero . '%']);
    }
    
    /**
     * Obtener gastos por rango de fechas
     */
    public function findByFechaRange($fechaInicio, $fechaFin)
    {
        $sql = "SELECT g.*, p.razon_social as proveedor_nombre
                FROM {$this->table} g
                LEFT JOIN proveedores p ON g.proveedor_id = p.id
                WHERE g.fecha_gasto BETWEEN ? AND ?
                ORDER BY g.fecha_gasto DESC";
        
        return $this->fetchAll($sql, [$fechaInicio, $fechaFin]);
    }
    
    /**
     * Insertar nuevo gasto
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table} 
                (importacion_id, proveedor_id, numero_factura, fecha_gasto, monto_total, 
                 numero_factura_normalizado, proveedor_normalizado)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['importacion_id'],
            $data['proveedor_id'],
            $data['numero_factura'],
            $data['fecha_gasto'],
            $data['monto_total'],
            $data['numero_factura_normalizado'] ?? null,
            $data['proveedor_normalizado'] ?? null
        ];
        
        return $this->insert($sql, $params);
    }
    
    /**
     * Obtener suma de montos
     */
    public function getTotalMonto()
    {
        $sql = "SELECT COALESCE(SUM(monto_total), 0) FROM {$this->table}";
        return (float) $this->fetchColumn($sql);
    }
}
