<?php
/**
 * Modelo de Sociedad.
 *
 * Empresas del grupo. Una sola está "activa": su cédula es la que el módulo
 * de correo usa para verificar el receptor de cada factura, y la que queda
 * asociada a los listados de facturas por pagar.
 */

class Sociedad extends Model
{
    protected $table = 'sociedades';

    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre` VARCHAR(255) NOT NULL,
            `cedula` VARCHAR(30) NOT NULL,
            `activa` TINYINT(1) NOT NULL DEFAULT 0,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->execute($sql);
        } catch (Throwable $e) {
            // Sin permisos de DDL: la migración manual la crea
        }
    }

    public function getAll()
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY activa DESC, nombre ASC";
        return $this->fetchAll($sql) ?: [];
    }

    /**
     * La sociedad con la que se está trabajando (o null si no hay ninguna).
     */
    public function getActiva()
    {
        $sql = "SELECT * FROM {$this->table} WHERE activa = 1 LIMIT 1";
        $fila = $this->fetchOne($sql);
        return $fila ?: null;
    }

    public function crear($nombre, $cedula)
    {
        // La primera sociedad registrada queda activa automáticamente
        $esPrimera = (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}") === 0;

        $sql = "INSERT INTO {$this->table} (nombre, cedula, activa) VALUES (?, ?, ?)";
        return $this->insert($sql, [$nombre, $cedula, $esPrimera ? 1 : 0]);
    }

    public function actualizar($id, $nombre, $cedula)
    {
        $sql = "UPDATE {$this->table} SET nombre = ?, cedula = ? WHERE id = ?";
        return $this->execute($sql, [$nombre, $cedula, (int) $id]);
    }

    public function eliminar($id)
    {
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $id]);
    }

    /**
     * Marca una sociedad como activa (y desactiva las demás).
     */
    public function activar($id)
    {
        $this->execute("UPDATE {$this->table} SET activa = 0 WHERE activa = 1");
        return $this->execute("UPDATE {$this->table} SET activa = 1 WHERE id = ?", [(int) $id]);
    }
}
