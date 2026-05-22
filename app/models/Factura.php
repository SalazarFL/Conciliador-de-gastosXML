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
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre, i.archivo_origen as archivo_importacion, i.fecha_importacion
                FROM {$this->table} f
            LEFT JOIN proveedores p ON f.proveedor_id = p.id
                LEFT JOIN importaciones i ON f.importacion_id = i.id
                ORDER BY f.fecha_emision DESC";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Buscar factura por UUID
     */
    public function findByUuid($uuid)
    {
        $sql = "SELECT * FROM {$this->table} WHERE consecutivo_completo = ? LIMIT 1";
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
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura_asistente LIKE ? ORDER BY fecha_emision DESC";
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
                (importacion_id, consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision,
                 subtotal, iva, total, moneda, tipo_comprobante, archivo_xml, ruta_xml, hash_xml, xml_contenido, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['importacion_id'] ?? null,
            $data['consecutivo_completo'] ?? $data['uuid'] ?? '',
            $data['numero_factura_asistente'] ?? $data['numero_factura'] ?? '',
            $data['proveedor_id'],
            $data['fecha_emision'],
            $data['subtotal'] ?? 0,
            $data['iva'] ?? 0,
            $data['total'] ?? 0,
            $data['moneda'] ?? 'CRC',
            $data['tipo_comprobante'] ?? null,
            $data['archivo_xml'] ?? 'sin_archivo.xml',
            $data['ruta_xml'] ?? null,
            $data['hash_xml'] ?? null,
            $data['xml_contenido'] ?? null,
            $data['metadata'] ?? null
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

    /**
     * Eliminar todas las facturas (uso en pruebas)
     */
    public function clearAll()
    {
        $sql = "DELETE FROM {$this->table}";
        return $this->execute($sql);
    }

    public function getByImportacion(int $importacionId): array
    {
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.importacion_id = ?
                ORDER BY f.fecha_emision DESC";
        return $this->fetchAll($sql, [$importacionId]) ?: [];
    }
}
