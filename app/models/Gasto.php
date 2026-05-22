<?php
/**
 * Modelo de Gasto
 * Gestiona gastos consolidados
 */

class Gasto extends Model
{
    protected $table = 'gastos_consolidados';
    
    /**
     * Agregar columna importacion_id si no existe (migración lazy).
     */
    public function ensureImportacionIdColumn(): void
    {
        try {
            $this->execute("ALTER TABLE {$this->table} ADD COLUMN importacion_id INT DEFAULT NULL");
        } catch (\Throwable $e) {
            // Columna ya existe; continuar.
        }
    }

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
     * Obtener gastos de una importación específica.
     */
    public function getByImportacion(int $importacionId): array
    {
        $sql = "SELECT g.*, g.proveedor_texto as proveedor_nombre
                FROM {$this->table} g
                WHERE g.importacion_id = ?
                ORDER BY g.fecha_max DESC";
        return $this->fetchAll($sql, [$importacionId]) ?: [];
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
                (numero_factura, proveedor_texto, cantidad_items, fecha_min, fecha_max, suma_base, suma_iva, suma_total, importacion_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $data['numero_factura'],
            $data['proveedor_texto'] ?? '',
            $data['cantidad_items'] ?? 1,
            $data['fecha_min'] ?? $data['fecha_gasto'] ?? null,
            $data['fecha_max'] ?? $data['fecha_gasto'] ?? null,
            $data['suma_base'] ?? $data['monto_base'] ?? 0,
            $data['suma_iva'] ?? $data['iva'] ?? 0,
            $data['suma_total'] ?? $data['monto_total'] ?? 0,
            $data['importacion_id'] ?? null,
        ];

        return $this->insert($sql, $params);
    }

    /**
     * Crear o actualizar gasto consolidado por número/proveedor.
     * - Misma importación: acumula (varias líneas del mismo CSV para la misma factura).
     * - Importación distinta: reemplaza montos y actualiza importacion_id.
     */
    public function upsertConsolidado($data)
    {
        $numeroFactura  = (string) ($data['numero_factura'] ?? '');
        $proveedorTexto = (string) ($data['proveedor_texto'] ?? '');
        $importacionId  = isset($data['importacion_id']) ? (int) $data['importacion_id'] : null;

        $sqlFind = "SELECT * FROM {$this->table} WHERE numero_factura = ? AND proveedor_texto = ? LIMIT 1";
        $existing = $this->fetchOne($sqlFind, [$numeroFactura, $proveedorTexto]);

        if (!$existing) {
            return $this->crear($data);
        }

        $existingImportId = isset($existing['importacion_id']) && $existing['importacion_id'] !== null
            ? (int) $existing['importacion_id']
            : null;

        $mismaImportacion = ($importacionId !== null && $existingImportId === $importacionId)
                         || ($importacionId === null && $existingImportId === null);

        $fechaMin = $this->minDate($existing['fecha_min'] ?? null, $data['fecha_min'] ?? null);
        $fechaMax = $this->maxDate($existing['fecha_max'] ?? null, $data['fecha_max'] ?? null);

        if ($mismaImportacion) {
            // Acumular: varias filas del mismo archivo para la misma factura
            $cantidadItems = (int) $existing['cantidad_items'] + ((int) ($data['cantidad_items'] ?? 1));
            $sumaBase      = (float) $existing['suma_base']  + (float) ($data['suma_base']  ?? 0);
            $sumaIva       = (float) $existing['suma_iva']   + (float) ($data['suma_iva']   ?? 0);
            $sumaTotal     = (float) $existing['suma_total'] + (float) ($data['suma_total'] ?? 0);
        } else {
            // Reemplazar: nueva importación actualiza el registro y se apropia de él
            $cantidadItems = (int) ($data['cantidad_items'] ?? 1);
            $sumaBase      = (float) ($data['suma_base']  ?? 0);
            $sumaIva       = (float) ($data['suma_iva']   ?? 0);
            $sumaTotal     = (float) ($data['suma_total'] ?? 0);
        }

        $sqlUpdate = "UPDATE {$this->table}
                      SET cantidad_items = ?, fecha_min = ?, fecha_max = ?,
                          suma_base = ?, suma_iva = ?, suma_total = ?, importacion_id = ?
                      WHERE id = ?";

        $this->execute($sqlUpdate, [
            $cantidadItems, $fechaMin, $fechaMax,
            $sumaBase, $sumaIva, $sumaTotal,
            $importacionId,
            $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    /**
     * Asigna importacion_id a todos los registros que lo tengan en NULL.
     * Se usa como backfill de una sola vez para datos históricos.
     */
    public function backfillImportacionId(int $importacionId): int
    {
        $sql = "UPDATE {$this->table} SET importacion_id = ? WHERE importacion_id IS NULL";
        return (int) $this->execute($sql, [$importacionId]);
    }
    
    /**
     * Obtener suma de montos
     */
    public function getTotalMonto()
    {
        $sql = "SELECT COALESCE(SUM(suma_total), 0) FROM {$this->table}";
        return (float) $this->fetchColumn($sql);
    }

    /**
     * Eliminar todos los gastos consolidados
     */
    public function clearAll()
    {
        $sql = "DELETE FROM {$this->table}";
        return $this->execute($sql);
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
