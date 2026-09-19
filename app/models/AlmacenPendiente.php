<?php
/**
 * Lo que se quiso deshacer en el almacén y no se pudo.
 *
 * Importar un documento es dos pasos: subirlo al almacén y guardar su fila en
 * la base, en ese orden. Si la base falla, lo subido se borra —un archivo
 * huérfano no lo ve nadie, pero ocupa y confunde—. Con los documentos en
 * disco ese borrado no falla nunca. Contra SharePoint falla justo cuando más
 * se lo necesita: si lo que tumbó la base fue la red, la misma red tumba el
 * borrado.
 *
 * La respuesta no es una transacción entre la base y SharePoint —no existe—
 * sino una lista de trabajo: cada borrado se anota ANTES de intentarlo y se
 * tacha cuando sale bien. Lo que queda anotado lo reintenta la tarea
 * programada (cli/sync_correo.php).
 *
 * Y si la base tampoco estaba para anotar —que es el caso en que falló el
 * INSERT—, el borrado se intenta igual. Si también falla, el archivo queda
 * huérfano en la biblioteca, y ese caso lo recoge el reconciliador: aparece
 * en los cambios de la biblioteca como un documento que ninguna fila conoce.
 */

class AlmacenPendiente extends Model
{
    protected $table = 'almacen_pendientes';

    /** Después de tantos intentos se deja de reintentar solo: hay que mirarlo. */
    public const MAX_INTENTOS = 20;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `accion` VARCHAR(20) NOT NULL DEFAULT 'borrar',
                `ruta` VARCHAR(700) NOT NULL DEFAULT '',
                `item_id` VARCHAR(128) NOT NULL DEFAULT '',
                `motivo` VARCHAR(255) NULL DEFAULT NULL,
                `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
                `error` VARCHAR(500) NULL DEFAULT NULL,
                `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `intentado_en` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_pendientes_intentos` (`intentos`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // Sin permisos de DDL: la crea database/migration_almacen_graph.sql.
        }
    }

    /** Anota un borrado por hacer. @return int el id, para tacharlo después. */
    public function anotarBorrado($ruta, $itemId, $motivo)
    {
        return (int) $this->insert(
            "INSERT INTO {$this->table} (accion, ruta, item_id, motivo) VALUES ('borrar', ?, ?, ?)",
            [RutaDocumento::relativa((string) $ruta), (string) $itemId, mb_substr((string) $motivo, 0, 255, 'UTF-8')]
        );
    }

    public function tachar($id)
    {
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $id]);
    }

    public function fallo($id, $error)
    {
        return $this->execute(
            "UPDATE {$this->table} SET intentos = intentos + 1, intentado_en = NOW(), error = ? WHERE id = ?",
            [mb_substr((string) $error, 0, 500, 'UTF-8'), (int) $id]
        );
    }

    /** Lo que todavía vale la pena reintentar, los más viejos primero. */
    public function porReintentar($limite = 50)
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE intentos < ? ORDER BY id ASC LIMIT " . max(1, (int) $limite),
            [self::MAX_INTENTOS]
        ) ?: [];
    }

    /** Cuántos quedaron sin poder hacerse, para el diagnóstico. */
    public function atascados()
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE intentos >= ?", [self::MAX_INTENTOS]
        );
    }

    /**
     * Reintenta lo anotado. Lo llama la tarea programada.
     *
     * @return array ['hechos' => n, 'fallidos' => n]
     */
    public function drenar($limite = 50)
    {
        require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';
        $resultado = ['hechos' => 0, 'fallidos' => 0];
        foreach ($this->porReintentar($limite) as $pendiente) {
            $ruta = RutaDocumento::absoluta((string) $pendiente['ruta']);
            try {
                AlmacenDocumentos::de($ruta)->borrar($ruta, (string) $pendiente['item_id']);
                $this->tachar((int) $pendiente['id']);
                $resultado['hechos']++;
            } catch (Throwable $e) {
                $this->fallo((int) $pendiente['id'], $e->getMessage());
                $resultado['fallidos']++;
            }
        }
        return $resultado;
    }
}
