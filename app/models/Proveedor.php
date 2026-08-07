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
        $rfc = trim((string) $rfc);
        $razonSocial = trim((string) $razonSocial);

        if ($razonSocial === '') {
            $razonSocial = 'SIN PROVEEDOR';
        }

        // Buscar existente
        $proveedor = null;
        if ($rfc !== '') {
            $proveedor = $this->findByRfc($rfc);
        } else {
            $sql = "SELECT * FROM {$this->table} WHERE razon_social = ? LIMIT 1";
            $proveedor = $this->fetchOne($sql, [$razonSocial]);
        }
        
        if ($proveedor) {
            return $proveedor['id'];
        }
        
        // Crear nuevo
        $sql = "INSERT INTO {$this->table} (rfc, razon_social, razon_social_normalizada) VALUES (?, ?, ?)";
        return $this->insert($sql, [$rfc !== '' ? $rfc : null, $razonSocial, $this->normalizarRazonSocial($razonSocial)]);
    }
    
    /**
     * Actualizar proveedor
     */
    public function actualizar($id, $data)
    {
        $sql = "UPDATE {$this->table} 
                SET rfc = ?, razon_social = ?, razon_social_normalizada = ?
                WHERE id = ?";
        
        $params = [
            $data['rfc'],
            $data['razon_social'],
            $this->normalizarRazonSocial($data['razon_social']),
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
     * Listado de nombres únicos de proveedores (facturas + gastos)
     */
    public function getListado()
    {
        $sql = "SELECT DISTINCT nombre FROM (
                    SELECT p.razon_social AS nombre FROM {$this->table} p
                    WHERE p.razon_social IS NOT NULL AND p.razon_social <> ''
                    UNION
                    SELECT g.proveedor_texto AS nombre FROM gastos_consolidados g
                    WHERE g.proveedor_texto IS NOT NULL AND g.proveedor_texto <> ''
                ) t ORDER BY nombre";
        return $this->fetchAll($sql);
    }

    /**
     * Obtener proveedores con estadísticas
     */
    public function getAllWithStats()
    {
        $sql = "SELECT p.*,
                    COALESCE(f.total_facturas, 0) as total_facturas,
                    COALESCE(g.total_gastos, 0) as total_gastos,
                    COALESCE(f.monto_facturas, 0) as monto_facturas,
                    COALESCE(g.monto_gastos, 0) as monto_gastos
                FROM {$this->table} p
                LEFT JOIN (
                    SELECT proveedor_id, COUNT(*) as total_facturas, COALESCE(SUM(total), 0) as monto_facturas
                    FROM facturas_xml
                    WHERE tipo_documento IS NULL OR tipo_documento = 'FE'
                    GROUP BY proveedor_id
                ) f ON p.id = f.proveedor_id
                LEFT JOIN (
                    SELECT proveedor_texto, COUNT(*) as total_gastos, COALESCE(SUM(suma_total), 0) as monto_gastos
                    FROM gastos_consolidados
                    GROUP BY proveedor_texto
                ) g ON p.razon_social = g.proveedor_texto
                ORDER BY p.razon_social";
        
        return $this->fetchAll($sql);
    }

    private function normalizarRazonSocial($value)
    {
        $normalized = mb_strtoupper(trim((string) $value), 'UTF-8');
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if ($normalized === false) {
            return mb_strtoupper(trim((string) $value), 'UTF-8');
        }

        return preg_replace('/\s+/', ' ', trim($normalized));
    }
}
