<?php
/**
 * Modelo de Semana de trabajo.
 *
 * Las cargas de XML, las importaciones del correo y los listados por pagar
 * se asignan a una semana elegida por el usuario. Así la verificación del
 * pago semanal se hace contra las facturas de ESA semana y no contra el
 * acumulado de todo el sistema.
 */

class Semana extends Model
{
    // Decidido con el usuario: las semanas de pago son de cada sociedad, no
    // del grupo. Al cambiar de empresa cambia la lista de semanas.
    use AlcanceSociedad;

    protected $table = 'semanas';

    public function __construct()
    {
        $this->ensureTable();
    }

    private function ensureTable()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre` VARCHAR(100) NOT NULL,
                `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL,
                `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
        }
        try {
            if (!$this->fetchOne("SHOW COLUMNS FROM {$this->table} LIKE 'carpeta_pago'")) {
                $this->execute("ALTER TABLE {$this->table}
                                ADD COLUMN `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL AFTER `nombre`");
            }
        } catch (Throwable $e) {
        }
    }

    /** Semanas de la más reciente a la más vieja, de la sociedad en curso. */
    public function getAll($limite = 60)
    {
        $params = [];
        $sql = "SELECT * FROM {$this->table} WHERE 1=1"
             . $this->condicionSociedad('', $params)
             . " ORDER BY id DESC LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function crear($nombre, $sociedadId = 0)
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            $nombre = 'Semana ' . date('d/m/Y');
        }
        $sociedadId = (int) $sociedadId > 0 ? (int) $sociedadId : $this->sociedadId();
        return $this->insert(
            "INSERT INTO {$this->table} (nombre, sociedad_id) VALUES (?, ?)",
            [mb_substr($nombre, 0, 100, 'UTF-8'), $sociedadId > 0 ? $sociedadId : null]
        );
    }

    public function configurarCarpetaPago($semanaId, $carpeta)
    {
        $carpeta = trim((string) $carpeta);
        return $this->execute(
            "UPDATE {$this->table} SET carpeta_pago = ? WHERE id = ?",
            [$carpeta !== '' ? mb_substr($carpeta, 0, 100, 'UTF-8') : null, (int) $semanaId]
        );
    }

    /**
     * Resuelve la selección de un formulario: 'nueva' crea una semana con
     * el nombre indicado; un id numérico se valida contra la tabla.
     * Devuelve el id de semana o null (sin asignar).
     */
    public function resolverSeleccion($valor, $nombreNueva = '')
    {
        $valor = trim((string) $valor);

        if ($valor === 'nueva') {
            return (int) $this->crear($nombreNueva);
        }

        // Se valida contra las semanas de la sociedad en curso: un id de otra
        // empresa no se acepta aunque llegue en el formulario.
        $id = (int) $valor;
        if ($id > 0 && !empty($this->existePara($id))) {
            return $id;
        }

        return null;
    }

    /** ¿Esa semana es de la sociedad en la que se está trabajando? */
    public function existePara($semanaId)
    {
        $params = [(int) $semanaId];
        $sql = "SELECT 1 FROM {$this->table} WHERE id = ?"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';
        return (bool) $this->fetchColumn($sql, $params);
    }
}
