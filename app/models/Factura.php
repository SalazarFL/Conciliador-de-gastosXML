<?php
/**
 * Modelo de Factura
 * Gestiona facturas XML (CFDI)
 */

class Factura extends Model
{
    protected $table = 'facturas_xml';
    
    /**
     * Obtener todas las facturas con información de importación
     */
    public function getAllWithImportacion()
    {
        $sql = "SELECT f.*, i.nombre_archivo as archivo_importacion, i.fecha as fecha_importacion
                FROM {$this->table} f
                LEFT JOIN importaciones i ON f.importacion_id = i.id
                ORDER BY f.fecha_emision DESC";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Buscar factura por UUID
     */
    public function findByUuid($uuid)
    {
        $sql = "SELECT * FROM {$this->table} WHERE uuid = ? LIMIT 1";
        return $this->fetchOne($sql, [$uuid]);
    }
    
    /**
     * Buscar facturas por proveedor
     */
    public function findByProveedor($proveedorId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE proveedor_id = ? ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, [$proveedorId]);
    }
    
    /**
     * Buscar facturas por número
     */
    public function findByNumero($numero)
    {
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura LIKE ? ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, ['%' . $numero . '%']);
    }
    
    /**
     * Obtener facturas por rango de fechas
     */
    public function findByFechaRange($fechaInicio, $fechaFin)
    {
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.fecha_emision BETWEEN ? AND ?
                ORDER BY f.fecha_emision DESC";
        
        return $this->fetchAll($sql, [$fechaInicio, $fechaFin]);
    }
    
    /**
     * Insertar nueva factura
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table} 
                (importacion_id, proveedor_id, uuid, numero_factura, fecha_emision, 
                 subtotal, iva, total, moneda, metodo_pago, proveedor_normalizado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['importacion_id'],
            $data['proveedor_id'],
            $data['uuid'],
            $data['numero_factura'],
            $data['fecha_emision'],
            $data['subtotal'],
            $data['iva'],
            $data['total'],
            $data['moneda'] ?? 'MXN',
            $data['metodo_pago'] ?? null,
            $data['proveedor_normalizado'] ?? null
        ];
        
        return $this->insert($sql, $params);
    }
    
    /**
     * Obtener suma de totales
     */
    public function getTotalMonto()
    {
        $sql = "SELECT COALESCE(SUM(total), 0) FROM {$this->table}";
        return (float) $this->fetchColumn($sql);
    }
}
