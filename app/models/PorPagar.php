<?php
/**
 * Modelo del módulo "Facturas por pagar".
 *
 * Cada semana se sube el listado de facturas por pagar (porpagar_listados)
 * con sus líneas (porpagar_facturas); el matching las cruza contra las
 * facturas XML del sistema y deja el semáforo:
 * respaldada / con_diferencia / sin_respaldo.
 */

class PorPagar extends Model
{
    protected $table = 'porpagar_listados';

    public function __construct()
    {
        $this->ensureTables();
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS porpagar_listados (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre` VARCHAR(255) NOT NULL,
                `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
                `archivo_origen` VARCHAR(255) NULL DEFAULT NULL,
                `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0,
                `fecha_subida` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS porpagar_facturas (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listado_id` INT UNSIGNED NOT NULL,
                `fecha` DATE NULL DEFAULT NULL,
                `numero` VARCHAR(100) NOT NULL,
                `proveedor_texto` VARCHAR(255) NOT NULL,
                `total` DECIMAL(18,2) NOT NULL DEFAULT 0,
                `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
                `estado` ENUM('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
                `diferencia` DECIMAL(18,2) NULL DEFAULT NULL,
                `score_numero` DECIMAL(5,1) NULL DEFAULT NULL,
                `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL,
                `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_listado` (`listado_id`),
                KEY `idx_estado` (`estado`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // La migración manual las crea si aquí no hay permisos DDL
        }
    }

    // ── Listados (uno por semana subida) ───────────────────────────

    public function crearListado($nombre, $sociedadId, $archivoOrigen, $semanaId = null)
    {
        $sql = "INSERT INTO porpagar_listados (nombre, sociedad_id, archivo_origen, semana_id) VALUES (?, ?, ?, ?)";
        return $this->insert($sql, [$nombre, $sociedadId ?: null, $archivoOrigen, $semanaId ?: null]);
    }

    /**
     * Listados guardados. Filtro por semana: null = todos · 0 = sin semana ·
     * >0 = los de esa semana.
     */
    public function getListados($limite = 30, $semanaId = null)
    {
        $where = '';
        $params = [];
        if ($semanaId !== null && $semanaId !== '') {
            if ((int) $semanaId > 0) {
                $where = " WHERE l.semana_id = ?";
                $params[] = (int) $semanaId;
            } else {
                $where = " WHERE l.semana_id IS NULL";
            }
        }

        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                {$where}
                ORDER BY l.id DESC
                LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getListado($id)
    {
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                WHERE l.id = ? LIMIT 1";
        $fila = $this->fetchOne($sql, [(int) $id]);
        return $fila ?: null;
    }

    public function actualizarTotalLineas($listadoId)
    {
        $sql = "UPDATE porpagar_listados
                SET total_lineas = (SELECT COUNT(*) FROM porpagar_facturas WHERE listado_id = ?)
                WHERE id = ?";
        return $this->execute($sql, [(int) $listadoId, (int) $listadoId]);
    }

    public function eliminarListado($id)
    {
        $this->execute("DELETE FROM porpagar_facturas WHERE listado_id = ?", [(int) $id]);
        return $this->execute("DELETE FROM porpagar_listados WHERE id = ?", [(int) $id]);
    }

    // ── Líneas del listado ─────────────────────────────────────────

    public function crearLinea($listadoId, array $linea)
    {
        $sql = "INSERT INTO porpagar_facturas (listado_id, fecha, numero, proveedor_texto, total)
                VALUES (?, ?, ?, ?, ?)";
        return $this->insert($sql, [
            (int) $listadoId,
            $linea['fecha'] ?: null,
            (string) $linea['numero'],
            (string) $linea['proveedor'],
            (float) $linea['total'],
        ]);
    }

    /**
     * Líneas del listado con los datos de la factura XML que las respalda.
     */
    public function getLineas($listadoId)
    {
        $sql = "SELECT pf.*,
                       f.numero_factura_asistente AS xml_numero,
                       f.total AS xml_total,
                       f.fecha_emision AS xml_fecha,
                       p.razon_social AS xml_proveedor
                FROM porpagar_facturas pf
                LEFT JOIN facturas_xml f ON f.id = pf.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE pf.listado_id = ?
                ORDER BY FIELD(pf.estado, 'con_diferencia', 'sin_respaldo', 'respaldada'),
                         pf.proveedor_texto ASC, pf.id ASC";
        return $this->fetchAll($sql, [(int) $listadoId]) ?: [];
    }

    public function actualizarMatch($lineaId, $facturaXmlId, $estado, $diferencia, $scoreNumero, $scoreProveedor)
    {
        $sql = "UPDATE porpagar_facturas
                SET factura_xml_id = ?, estado = ?, diferencia = ?, score_numero = ?, score_proveedor = ?
                WHERE id = ?";
        return $this->execute($sql, [
            $facturaXmlId ?: null,
            $estado,
            $diferencia,
            $scoreNumero,
            $scoreProveedor,
            (int) $lineaId,
        ]);
    }

    /**
     * Facturas candidatas para el matching: solo FE (NC/ND no respaldan
     * pagos) y solo las columnas necesarias (sin xml_contenido, que pesa).
     *
     * Con $semanaId > 0 se limita a las facturas asignadas a ESA semana:
     * la verificación del listado no es contra el acumulado del sistema.
     */
    public function getFacturasParaMatching($semanaId = 0)
    {
        $params = [];
        $sql = "SELECT f.id, f.numero_factura_asistente, f.consecutivo_completo, f.total,
                       p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')";

        if ((int) $semanaId > 0) {
            $sql .= " AND f.semana_id = ?";
            $params[] = (int) $semanaId;
        }

        return $this->fetchAll($sql, $params) ?: [];
    }

    public function resumenPorEstado($listadoId)
    {
        $sql = "SELECT estado, COUNT(*) AS n, COALESCE(SUM(total), 0) AS monto
                FROM porpagar_facturas
                WHERE listado_id = ?
                GROUP BY estado";
        $resumen = [];
        foreach ($this->fetchAll($sql, [(int) $listadoId]) ?: [] as $fila) {
            $resumen[$fila['estado']] = (int) $fila['n'];
            $resumen[$fila['estado'] . '_monto'] = (float) $fila['monto'];
        }
        return $resumen;
    }
}
