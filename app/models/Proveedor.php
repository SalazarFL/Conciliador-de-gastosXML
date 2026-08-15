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
    
    // Aquí había tres consultas más: buscar() para un autocompletado que
    // ninguna pantalla llegó a usar, y getListado() y getAllWithStats(), que
    // unían proveedores con `gastos_consolidados`. Esa tabla se fue con el
    // módulo de Gastos, así que las dos últimas ya ni siquiera podían correr.

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
