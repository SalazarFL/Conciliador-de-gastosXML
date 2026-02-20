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
        $sql = "SELECT g.*, g.proveedor_texto as proveedor_nombre
                FROM {$this->table} g
                ORDER BY g.fecha_max DESC";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Buscar gastos por proveedor
     */
    public function findByProveedor($proveedorId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE proveedor_texto LIKE ? ORDER BY fecha_max DESC";
        return $this->fetchAll($sql, ['%' . $proveedorId . '%']);
    }
    
    /**
     * Buscar gastos por número de factura
     */
    public function findByNumeroFactura($numero)
    {
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura LIKE ? ORDER BY fecha_max DESC";
        return $this->fetchAll($sql, ['%' . $numero . '%']);
    }
    
    /**
     * Obtener gastos por rango de fechas
     */
    public function findByFechaRange($fechaInicio, $fechaFin)
    {
        $sql = "SELECT g.*, g.proveedor_texto as proveedor_nombre
            FROM {$this->table} g
            WHERE (g.fecha_min IS NULL OR g.fecha_min <= ?)
              AND (g.fecha_max IS NULL OR g.fecha_max >= ?)
            ORDER BY g.fecha_max DESC";
        
        return $this->fetchAll($sql, [$fechaFin, $fechaInicio]);
    }
    
    /**
     * Insertar nuevo gasto
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table} 
                (numero_factura, proveedor_texto, cantidad_items, fecha_min, fecha_max, suma_base, suma_iva, suma_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $data['numero_factura'],
            $data['proveedor_texto'] ?? '',
            $data['cantidad_items'] ?? 1,
            $data['fecha_min'] ?? $data['fecha_gasto'] ?? null,
            $data['fecha_max'] ?? $data['fecha_gasto'] ?? null,
            $data['suma_base'] ?? $data['monto_base'] ?? 0,
            $data['suma_iva'] ?? $data['iva'] ?? 0,
            $data['suma_total'] ?? $data['monto_total'] ?? 0
        ];
        
        return $this->insert($sql, $params);
    }

    /**
     * Crear o acumular gasto consolidado por número/proveedor
     */
    public function upsertConsolidado($data)
    {
        $numeroFactura = (string) ($data['numero_factura'] ?? '');
        $proveedorTexto = (string) ($data['proveedor_texto'] ?? '');

        $sqlFind = "SELECT * FROM {$this->table} WHERE numero_factura = ? AND proveedor_texto = ? LIMIT 1";
        $existing = $this->fetchOne($sqlFind, [$numeroFactura, $proveedorTexto]);

        if (!$existing) {
            return $this->crear($data);
        }

        $cantidadItems = (int) $existing['cantidad_items'] + ((int) ($data['cantidad_items'] ?? 1));
        $fechaMin = $this->minDate($existing['fecha_min'] ?? null, $data['fecha_min'] ?? null);
        $fechaMax = $this->maxDate($existing['fecha_max'] ?? null, $data['fecha_max'] ?? null);
        $sumaBase = (float) $existing['suma_base'] + (float) ($data['suma_base'] ?? 0);
        $sumaIva = (float) $existing['suma_iva'] + (float) ($data['suma_iva'] ?? 0);
        $sumaTotal = (float) $existing['suma_total'] + (float) ($data['suma_total'] ?? 0);

        $sqlUpdate = "UPDATE {$this->table}
                      SET cantidad_items = ?, fecha_min = ?, fecha_max = ?, suma_base = ?, suma_iva = ?, suma_total = ?
                      WHERE id = ?";

        $this->execute($sqlUpdate, [
            $cantidadItems,
            $fechaMin,
            $fechaMax,
            $sumaBase,
            $sumaIva,
            $sumaTotal,
            $existing['id']
        ]);

        return (int) $existing['id'];
    }
    
    /**
     * Obtener suma de montos
     */
    public function getTotalMonto()
    {
        $sql = "SELECT COALESCE(SUM(suma_total), 0) FROM {$this->table}";
        return (float) $this->fetchColumn($sql);
    }

    private function minDate($a, $b)
    {
        if (empty($a)) {
            return $b;
        }
        if (empty($b)) {
            return $a;
        }

        return strtotime($a) <= strtotime($b) ? $a : $b;
    }

    private function maxDate($a, $b)
    {
        if (empty($a)) {
            return $b;
        }
        if (empty($b)) {
            return $a;
        }

        return strtotime($a) >= strtotime($b) ? $a : $b;
    }
}
