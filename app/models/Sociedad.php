<?php
/**
 * Modelo de Sociedad.
 *
 * Empresas del grupo. La empresa con la que se trabaja define la cédula que
 * el módulo de correo usa para verificar el receptor de cada factura, y con
 * la que queda sellado todo lo que se importe.
 *
 * Esa selección es POR USUARIO, no del sistema. Antes era la columna `activa`
 * —una sola marca compartida—, así que si dos personas trabajaban a la vez, la
 * que cambiaba de empresa movía también la de la otra sin aviso, y los
 * documentos quedaban sellados con la sociedad equivocada.
 *
 * Se resuelve en tres escalones (ver seleccionadaId):
 *   1. la sesión en curso   2. la preferencia guardada del usuario
 *   3. `activa`, como valor por omisión del sistema y para procesos sin
 *      sesión (los workers de cli/, que además ya reciben su sociedad).
 */

class Sociedad extends Model
{
    protected $table = 'sociedades';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
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
     * Es la de ESTE usuario, no una marca global.
     */
    public function getActiva()
    {
        $id = $this->seleccionadaId();
        if ($id > 0) {
            $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);
            if ($fila) {
                return $fila;
            }
        }
        // La seleccionada ya no existe (la borraron): se cae al valor por
        // omisión para no dejar la aplicación sin empresa.
        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE activa = 1 LIMIT 1");
        return $fila ?: null;
    }

    /**
     * Id de la empresa en curso, cacheado por petición: lo consultan todos los
     * modelos acotados y no cambia a mitad de una. Vive aquí y no en el trait
     * porque un `static` dentro de un trait se duplica en cada clase que lo
     * usa, y entonces nada podría invalidarlos todos de una vez.
     */
    public static function seleccionadaIdActual()
    {
        if (self::$cacheSeleccionada !== null) {
            return self::$cacheSeleccionada;
        }
        try {
            self::$cacheSeleccionada = (int) (new self())->seleccionadaId();
        } catch (Throwable $e) {
            // Sin tabla de sociedades (instalación nueva) no se acota nada.
            self::$cacheSeleccionada = 0;
        }
        return self::$cacheSeleccionada;
    }

    /** Lo llama seleccionar() al cambiar de empresa. */
    public static function olvidarSeleccion()
    {
        self::$cacheSeleccionada = null;
    }

    private static $cacheSeleccionada = null;

    /**
     * Id de la empresa con la que trabaja quien hace esta petición.
     * Sesión → preferencia guardada del usuario → valor por omisión.
     */
    public function seleccionadaId()
    {
        if (!empty($_SESSION['sociedad_id'])) {
            $idSesion = (int) $_SESSION['sociedad_id'];
            if ($idSesion > 0 && $this->fetchColumn(
                "SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1",
                [$idSesion]
            )) {
                return $idSesion;
            }

            // Puede ocurrir si un administrador elimina una sociedad mientras
            // otro usuario mantiene una sesión abierta. La selección inválida
            // no debe dejar todos los módulos filtrando por un id inexistente.
            unset($_SESSION['sociedad_id']);
        }

        $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
        if ($usuarioId > 0) {
            $preferida = (int) $this->fetchColumn(
                'SELECT sociedad_id FROM usuarios WHERE id = ? LIMIT 1',
                [$usuarioId]
            );
            // Se recuerda en la sesión para no consultarlo en cada petición.
            if ($preferida > 0 && $this->fetchColumn("SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1", [$preferida])) {
                $_SESSION['sociedad_id'] = $preferida;
                return $preferida;
            }
        }

        return (int) $this->fetchColumn("SELECT id FROM {$this->table} WHERE activa = 1 ORDER BY id LIMIT 1");
    }

    /**
     * Cambia la empresa de ESTE usuario: en la sesión (efecto inmediato) y en
     * su ficha (para el próximo ingreso). No toca a los demás usuarios.
     */
    public function seleccionar($id)
    {
        $id = (int) $id;
        if ($id <= 0 || !$this->fetchColumn("SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1", [$id])) {
            throw new InvalidArgumentException('La sociedad indicada no existe.');
        }

        $_SESSION['sociedad_id'] = $id;

        $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
        if ($usuarioId > 0) {
            $this->execute('UPDATE usuarios SET sociedad_id = ? WHERE id = ?', [$id, $usuarioId]);
        }

        // El alcance se cachea por petición: si no se limpia, los modelos
        // seguirían consultando la empresa anterior.
        self::olvidarSeleccion();
        return true;
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
     * Cambiar de empresa. Antes hacía un UPDATE global (`activa`) que movía la
     * empresa de todos los usuarios a la vez; ahora es por usuario.
     */
    public function activar($id)
    {
        return $this->seleccionar($id);
    }

    /**
     * Valor por omisión del sistema: con qué empresa arranca un usuario que
     * nunca ha elegido, y con cuál trabajan los procesos sin sesión. Es lo
     * único que sigue guardándose en la columna `activa`.
     */
    public function definirPorOmision($id)
    {
        $this->execute("UPDATE {$this->table} SET activa = 0 WHERE activa = 1");
        return $this->execute("UPDATE {$this->table} SET activa = 1 WHERE id = ?", [(int) $id]);
    }
}
