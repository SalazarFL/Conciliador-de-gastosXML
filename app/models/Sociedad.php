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

        // Dónde se archivan los documentos nuevos de la sociedad. Es una
        // tabla de una fila por empresa: agregarla acá no cuesta nada, y si
        // el usuario de la base no puede, database/migration_almacen_graph.sql.
        try {
            $this->execute("ALTER TABLE {$this->table}
                ADD COLUMN IF NOT EXISTS `almacen_documentos` VARCHAR(10) NOT NULL DEFAULT 'disco' AFTER `cedula`");
        } catch (Throwable $e) {
            // Sin la columna todas siguen en disco: ver almacenesPorSociedad().
        }
    }

    /** Dónde puede vivir lo nuevo de una sociedad. */
    public const ALMACENES = ['disco' => 'Carpeta compartida', 'sharepoint' => 'SharePoint'];

    /**
     * [sociedad_id => almacén] de las que NO están en disco.
     *
     * Solo las que se apartan de lo de siempre: con todas en disco, que es
     * hoy, la respuesta es una lista vacía. Sin la columna también, y eso es
     * exactamente lo que significa no haber corrido la migración.
     */
    public function almacenesPorSociedad()
    {
        try {
            $filas = $this->fetchAll("SELECT id, almacen_documentos FROM {$this->table}
                                      WHERE almacen_documentos <> 'disco'") ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(int) $fila['id']] = (string) $fila['almacen_documentos'];
        }
        return $mapa;
    }

    /**
     * Cambia dónde se archiva lo nuevo de una sociedad.
     *
     * Se niega a pasar a un almacén que ESTA máquina no puede usar: el ajuste
     * es de todas las PC y la credencial es de cada una. Quien lo cambia
     * tiene que poder comprobar, en el momento, que funciona.
     *
     * Esto NO mueve los documentos que ya están: cada uno sigue donde dice su
     * fila. Pasar lo existente es cli/migrar_documentos_a_graph.php.
     */
    public function definirAlmacen($id, $almacen)
    {
        $almacen = strtolower(trim((string) $almacen));
        if (!isset(self::ALMACENES[$almacen])) {
            throw new InvalidArgumentException('Almacén desconocido: ' . $almacen);
        }
        require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';
        if (!AlmacenDocumentos::disponible($almacen)) {
            throw new RuntimeException('Esta computadora no tiene configurado ' . self::ALMACENES[$almacen]
                . ': falta app/config/local.graph.php. Haz el cambio desde una que sí lo tenga.');
        }
        // Cada documento anota en su fila dónde quedó. Sin esa columna, lo que
        // se archive en SharePoint se leería como de disco: perdido.
        if ($almacen !== 'disco' && !$this->fetchColumn(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen' LIMIT 1"
        )) {
            throw new RuntimeException('Antes hay que correr database/migration_almacen_graph.sql en la base.');
        }
        $this->execute("UPDATE {$this->table} SET almacen_documentos = ? WHERE id = ?", [$almacen, (int) $id]);
        AlmacenDocumentos::olvidarAjustes();
        return true;
    }

    /**
     * Las empresas que esta persona puede ver.
     *
     * Filtra por lo que el administrador le asignó (usuario_sociedades). Sin
     * asignación, o siendo administrador, salen todas —ver
     * Permisos::sociedadesPermitidas()—. El filtro está aquí y no en cada
     * pantalla porque este método lo llaman el selector de Inicio, la
     * configuración y la barra superior: hacerlo tres veces sería olvidarlo
     * una.
     */
    public function getAll()
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        $permitidas = [];
        try {
            $permitidas = Permisos::sociedadesPermitidas();
        } catch (Throwable $e) {
            // Sin poder resolver permisos se listan todas, que es como se
            // comportaba antes: el filtro no puede dejar a nadie sin empresa.
        }

        if ($permitidas) {
            $sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($permitidas), '?')) . ')';
            $params = $permitidas;
        }

        return $this->fetchAll($sql . ' ORDER BY activa DESC, nombre ASC', $params) ?: [];
    }

    /**
     * TODAS las empresas, sin filtrar por quién mira. La usa la pantalla que
     * reparte accesos: para poder asignarle a alguien una empresa hay que
     * poder verla, aunque quien reparte no trabaje con ella.
     */
    public function getTodas()
    {
        return $this->fetchAll("SELECT * FROM {$this->table} ORDER BY activa DESC, nombre ASC") ?: [];
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
