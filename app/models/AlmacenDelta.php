<?php
/**
 * Hasta dónde leyó el reconciliador los cambios de cada biblioteca.
 *
 * SharePoint entrega los cambios de una biblioteca "desde la última vez" con
 * un enlace (deltaLink) que hay que guardar. Va en la base y no en
 * storage/cache/ porque la tarea programada corre en varias PC: con un enlace
 * por máquina, cada cambio se procesaría una vez por PC, o ninguna si una PC
 * dejó de correr. Aquí hay uno solo para todas.
 *
 * Guarda también por dónde va la revisión rotativa (cursor_revision): el id de
 * la última fila que se repasó contra SharePoint. Ver ReconciliadorGraph.
 */
class AlmacenDelta extends Model
{
    protected $table = 'almacen_delta';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `almacen` VARCHAR(20) NOT NULL,
                `drive_id` VARCHAR(191) NOT NULL DEFAULT '',
                `delta_link` TEXT NULL,
                `cursor_revision` INT UNSIGNED NOT NULL DEFAULT 0,
                `vuelta_completa_en` DATETIME NULL DEFAULT NULL,
                `actualizado_en` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`almacen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // Sin permisos de DDL: la crea database/migration_almacen_graph.sql.
        }
    }

    /**
     * Lo guardado para ese almacén. Si la biblioteca cambió —otro drive_id en
     * local.graph.php—, lo de la vieja no sirve: se empieza de cero.
     */
    public function leer($almacen, $driveId)
    {
        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE almacen = ?", [(string) $almacen]);
        if (!is_array($fila) || (string) $fila['drive_id'] !== (string) $driveId) {
            return ['delta_link' => '', 'cursor_revision' => 0, 'vuelta_completa_en' => null];
        }
        return [
            'delta_link' => (string) ($fila['delta_link'] ?? ''),
            'cursor_revision' => (int) $fila['cursor_revision'],
            'vuelta_completa_en' => $fila['vuelta_completa_en'],
        ];
    }

    public function guardar($almacen, $driveId, $deltaLink, $cursor, $vueltaCompleta = false)
    {
        return $this->execute(
            "INSERT INTO {$this->table} (almacen, drive_id, delta_link, cursor_revision, vuelta_completa_en, actualizado_en)
             VALUES (?, ?, ?, ?, " . ($vueltaCompleta ? 'NOW()' : 'NULL') . ", NOW())
             ON DUPLICATE KEY UPDATE drive_id = VALUES(drive_id), delta_link = VALUES(delta_link),
                 cursor_revision = VALUES(cursor_revision), actualizado_en = NOW()"
             . ($vueltaCompleta ? ', vuelta_completa_en = NOW()' : ''),
            [(string) $almacen, (string) $driveId, (string) $deltaLink, max(0, (int) $cursor)]
        );
    }
}
