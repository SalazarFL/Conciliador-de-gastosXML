<?php
/**
 * Modelo del pago semanal: la cabecera, y nada más.
 *
 * Un pago semanal era una tabla de líneas —`porpagar_facturas`— con una copia
 * de cada renglón del archivo del ERP: fecha, número, proveedor y total. Esa
 * copia era el problema. El mismo documento se escribía de cinco formas según
 * de dónde se exportara el archivo, el nombre del proveedor llegaba recortado
 * a lo ancho de la columna o con anotaciones pegadas, y todo el emparejador
 * difuso existía para reparar eso. Además convivían dos versiones de la misma
 * factura —la del archivo y la del ERP— sin que nada garantizara que dijeran
 * lo mismo.
 *
 * Ahora la línea del pago ES la factura del ERP (`facturas_erp`, marcada con
 * `porpagar_listado_id` y `semana_id`). El archivo del pago dejó de aportar
 * datos y aporta una selección: qué facturas se pagan esta semana. Lo que
 * queda acá es la cabecera —cuál semana y de qué archivo salió— y las
 * consultas de las líneas viven en FacturaErp, que es donde están las
 * facturas.
 *
 * Un pago tuvo estado abierto/cerrado. Ya no: la base es compartida y varias
 * personas trabajan la misma semana, así que congelarla solo servía para dejar
 * el respaldo desactualizado. Las columnas `estado`, `cerrado_en` y
 * `cerrado_por` pueden seguir existiendo en bases viejas; nadie las lee.
 */

class PorPagar extends Model
{
    use AlcanceSociedad;

    protected $table = 'porpagar_listados';

    public function __construct()
    {
        require_once __DIR__ . '/Semana.php';
        new Semana();
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS porpagar_listados (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre` VARCHAR(255) NOT NULL,
                `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
                `semana_id` INT UNSIGNED NULL DEFAULT NULL,
                `archivo_origen` VARCHAR(255) NULL DEFAULT NULL,
                `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0,
                `fecha_subida` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_semana` (`semana_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // La migración manual la crea si aquí no hay permisos DDL.
        }

        // Una base que viene del tiempo del cierre puede tener pagos marcados
        // como 'cerrado'. Nadie lee esa columna, pero mientras exista se deja
        // en 'abierto' para que un reporte hecho a mano no se confunda.
        try {
            if ($this->fetchOne("SHOW COLUMNS FROM porpagar_listados LIKE 'estado'")) {
                $this->execute("UPDATE porpagar_listados SET estado = 'abierto' WHERE estado <> 'abierto'");
            }
        } catch (Throwable $e) {
        }
    }

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
        $where = ['1=1'];
        $params = [];
        $this->filtrarPorSociedad($where, $params, 'l.');
        if ($semanaId !== null && $semanaId !== '') {
            if ((int) $semanaId > 0) {
                $where[] = 'l.semana_id = ?';
                $params[] = (int) $semanaId;
            } else {
                $where[] = 'l.semana_id IS NULL';
            }
        }

        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre,
                       sem.carpeta_pago AS semana_carpeta_pago
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.id DESC
                LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getListado($id)
    {
        $params = [(int) $id];
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre,
                       sem.carpeta_pago AS semana_carpeta_pago
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                WHERE l.id = ?" . $this->condicionSociedad('l.', $params) . " LIMIT 1";
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    /** El contador de la cabecera sale de contar las facturas marcadas. */
    public function actualizarTotalLineas($listadoId)
    {
        return $this->execute(
            "UPDATE porpagar_listados
                SET total_lineas = (SELECT COUNT(*) FROM facturas_erp WHERE porpagar_listado_id = ?)
              WHERE id = ?",
            [(int) $listadoId, (int) $listadoId]
        );
    }

    /**
     * Borra el pago y suelta sus facturas.
     *
     * Soltarlas es lo que antes era borrar líneas: las facturas del ERP no se
     * eliminan nunca —son el registro del sistema de la empresa—, vuelven a
     * quedar disponibles para otra semana.
     */
    public function eliminarListado($id)
    {
        $this->execute(
            "UPDATE facturas_erp
                SET estado = 'pendiente', semana_id = NULL, porpagar_listado_id = NULL,
                    asignada_semana_en = NULL, saldo_pago = NULL, factura_xml_id = NULL,
                    estado_respaldo = 'sin_respaldo', diferencia = NULL,
                    score_numero = NULL, score_proveedor = NULL, match_manual = 0
              WHERE porpagar_listado_id = ?",
            [(int) $id]
        );
        return $this->execute("DELETE FROM porpagar_listados WHERE id = ?", [(int) $id]);
    }

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return true;
    }
}
