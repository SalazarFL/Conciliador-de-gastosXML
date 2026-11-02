<?php
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../core/Permisos.php';

/**
 * Los roles y a quién alcanzan.
 *
 * Un rol es un nombre y una lista de permisos, y cada usuario tiene uno. Uno
 * solo, a propósito: con varios roles sumándose nadie puede mirar una ficha y
 * decir de un vistazo qué puede hacer esa persona, que es justo lo que un
 * control de accesos tiene que responder. Si hace falta una excepción, se
 * crea el rol que la describe y se le pone nombre.
 *
 * Tres tablas:
 *   roles              nombre, descripción y las dos marcas de abajo
 *   rol_permisos       una fila por permiso concedido
 *   usuario_sociedades a qué empresas llega cada persona
 *
 * Las dos marcas de un rol:
 *   es_admin    lo puede todo, también lo que se invente mañana. No se le
 *               marcan permisos porque los tiene todos por definición.
 *   es_sistema  no se puede borrar. Lo llevan Administrador y Operación: sin
 *               ellos una instalación se queda sin ningún rol al que volver.
 */
class Rol extends Model
{
    protected $table = 'roles';

    /** El rol que lo puede todo. Se crea solo y no se puede borrar. */
    const ADMINISTRADOR = 'Administrador';

    /** El rol de quien trabaja todos los días, sin administración. */
    const OPERACION = 'Operación';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    // ── Esquema ───────────────────────────────────────────────────────────

    private function ensureTables()
    {
        try {
            $this->crearTablas();
        } catch (Throwable $e) {
            // Sin permisos de DDL, database/migrations/005_roles_y_bitacora.sql
            // las deja puestas a mano. Si tampoco están, Permisos vuelve al
            // reparto de antes (admin todo, el resto todo menos administración)
            // en vez de dejar a la oficina sin poder trabajar.
        }

        $this->agregarColumnaRol();
        $this->sembrarRolesBase();
    }

    private function crearTablas()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS roles (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre` VARCHAR(60) NOT NULL,
            `descripcion` VARCHAR(255) NULL DEFAULT NULL,
            `es_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `es_sistema` TINYINT(1) NOT NULL DEFAULT 0,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_rol_nombre` (`nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Una fila por permiso concedido, y no un JSON en la fila del rol:
        // así se puede preguntar "quién puede borrar pagos" sin leer todos
        // los roles y abrirlos uno por uno.
        $this->execute("CREATE TABLE IF NOT EXISTS rol_permisos (
            `rol_id` INT UNSIGNED NOT NULL,
            `permiso` VARCHAR(60) NOT NULL,
            PRIMARY KEY (`rol_id`, `permiso`),
            KEY `idx_rol_permiso` (`permiso`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // El alcance por empresa. Sin filas para un usuario = todas: ver
        // Permisos::sociedadesPermitidas() para por qué ese es el valor por
        // omisión y no "ninguna".
        $this->execute("CREATE TABLE IF NOT EXISTS usuario_sociedades (
            `usuario_id` INT UNSIGNED NOT NULL,
            `sociedad_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`usuario_id`, `sociedad_id`),
            KEY `idx_us_sociedad` (`sociedad_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * `usuarios.rol_id`. Se comprueba contra information_schema en vez de usar
     * ADD COLUMN IF NOT EXISTS porque esa forma es de MariaDB y no de MySQL, y
     * esto tiene que poder correr en las dos.
     */
    private function agregarColumnaRol()
    {
        try {
            $existe = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'rol_id'"
            );
            if ($existe === 0) {
                $this->execute("ALTER TABLE usuarios ADD COLUMN `rol_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_admin`");
                $this->execute("ALTER TABLE usuarios ADD KEY `idx_usuario_rol` (`rol_id`)");
            }
        } catch (Throwable $e) {
            // Sin permisos de DDL: database/migrations/005_roles_y_bitacora.sql
            // deja la columna puesta a mano.
        }
    }

    /**
     * Los dos roles con los que arranca cualquier instalación, y el reparto
     * de los usuarios que ya existían.
     *
     * Quien era administrador sigue siéndolo; el resto recibe Operación, que
     * es todo el trabajo del día sin nada de administración. Nadie se queda
     * fuera el día que esto se sube, que es la única forma de que una función
     * de permisos no acabe desactivada a las dos horas.
     *
     * Solo toca a quien no tiene rol todavía: si mañana se corre otra vez, no
     * deshace ninguna asignación hecha a mano.
     */
    private function sembrarRolesBase()
    {
        try {
            $adminId = $this->idPorNombre(self::ADMINISTRADOR);
            if ($adminId === 0) {
                $adminId = (int) $this->insert(
                    "INSERT INTO roles (nombre, descripcion, es_admin, es_sistema) VALUES (?, ?, 1, 1)",
                    [self::ADMINISTRADOR, 'Acceso completo al sistema, incluida la administración de usuarios y roles.']
                );
            }

            $operacionId = $this->idPorNombre(self::OPERACION);
            if ($operacionId === 0) {
                $operacionId = (int) $this->insert(
                    "INSERT INTO roles (nombre, descripcion, es_admin, es_sistema) VALUES (?, ?, 0, 1)",
                    [self::OPERACION, 'El trabajo del día: cargar, revisar y resolver documentos. Sin administración.']
                );
                $this->fijarPermisos($operacionId, self::permisosDeOperacion());
            }

            if ($adminId > 0) {
                $this->execute(
                    "UPDATE usuarios SET rol_id = ? WHERE rol_id IS NULL AND is_admin = 1",
                    [$adminId]
                );
            }
            if ($operacionId > 0) {
                $this->execute(
                    "UPDATE usuarios SET rol_id = ? WHERE rol_id IS NULL AND (is_admin = 0 OR is_admin IS NULL)",
                    [$operacionId]
                );
            }
        } catch (Throwable $e) {
            // Una instalación sin la tabla usuarios todavía (o sin DDL) no
            // debe impedir que el resto del sistema arranque.
        }
    }

    /**
     * Lo que puede Operación. Se calcula del catálogo en vez de escribirlo a
     * mano: todo salvo los módulos Administración y Configuración, y salvo
     * dar de alta buzones.
     *
     * Antes era "todo menos esta lista", y la lista se escribió una vez: los
     * permisos que llegaron después entraban solos a Operación. Así se le
     * coló 'bitacora.depurar' —borrar la bitácora, que es justo lo que no
     * puede hacer quien está siendo registrado en ella—. Ahora se excluye el
     * módulo entero, y lo que se añada a Administración mañana nace fuera.
     *
     * La única excepción es a propósito: corregir el código de un proveedor
     * ya lo hace Operación desde la campana (avisos.resolver), así que el
     * listado de proveedores es trabajo del día, no un ajuste.
     */
    public static function permisosDeOperacion(): array
    {
        $modulos = Permisos::modulos();

        $fuera = ['correo.configurar'];
        foreach (['administracion', 'configuracion'] as $id) {
            $fuera = array_merge($fuera, array_keys($modulos[$id]['permisos'] ?? []));
        }
        $dentro = ['configuracion.proveedores'];

        return array_values(array_diff(Permisos::todas(), array_diff($fuera, $dentro)));
    }

    // ── Lo que pregunta Permisos en cada petición ─────────────────────────

    /** Las claves de permiso de un usuario, por su rol. */
    public function permisosDeUsuario(int $usuarioId): array
    {
        $filas = $this->fetchAll(
            "SELECT rp.permiso
               FROM usuarios u
               JOIN rol_permisos rp ON rp.rol_id = u.rol_id
              WHERE u.id = ?",
            [$usuarioId]
        ) ?: [];

        return array_column($filas, 'permiso');
    }

    /** El rol de un usuario: ['id','nombre','es_admin'] o null. */
    public function rolDeUsuario(int $usuarioId): ?array
    {
        $fila = $this->fetchOne(
            "SELECT r.id, r.nombre, r.es_admin
               FROM usuarios u
               JOIN roles r ON r.id = u.rol_id
              WHERE u.id = ? LIMIT 1",
            [$usuarioId]
        );

        if (!$fila) {
            return null;
        }

        return [
            'id'       => (int) $fila['id'],
            'nombre'   => (string) $fila['nombre'],
            'es_admin' => (bool) $fila['es_admin'],
        ];
    }

    /** Ids de las empresas asignadas a un usuario. Vacío = todas. */
    public function sociedadesDeUsuario(int $usuarioId): array
    {
        $filas = $this->fetchAll(
            "SELECT sociedad_id FROM usuario_sociedades WHERE usuario_id = ?",
            [$usuarioId]
        ) ?: [];

        return array_map('intval', array_column($filas, 'sociedad_id'));
    }

    /**
     * Fija a qué empresas llega un usuario. Lista vacía = todas, que es
     * quitarle la restricción, no dejarlo sin ninguna.
     */
    public function fijarSociedades(int $usuarioId, array $sociedadIds): void
    {
        $this->execute("DELETE FROM usuario_sociedades WHERE usuario_id = ?", [$usuarioId]);

        $vistos = [];
        foreach ($sociedadIds as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;
            $this->execute(
                "INSERT IGNORE INTO usuario_sociedades (usuario_id, sociedad_id) VALUES (?, ?)",
                [$usuarioId, $id]
            );
        }
    }

    // ── Administración de roles ───────────────────────────────────────────

    /** Los roles con cuánta gente los usa, para el listado. */
    public function getAll(): array
    {
        return $this->fetchAll(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS usuarios,
                    (SELECT COUNT(*) FROM rol_permisos p WHERE p.rol_id = r.id) AS permisos
               FROM roles r
           ORDER BY r.es_admin DESC, r.es_sistema DESC, r.nombre ASC"
        ) ?: [];
    }

    /**
     * Las claves de todos los roles de una vez: [rol_id => [claves]]. La
     * lista de roles las necesita todas para dibujar quién puede qué, y una
     * consulta por rol sería lo mismo con más viajes.
     */
    public function permisosPorRol(): array
    {
        $mapa = [];
        foreach ($this->fetchAll("SELECT rol_id, permiso FROM rol_permisos ORDER BY permiso") ?: [] as $f) {
            $mapa[(int) $f['rol_id']][] = (string) $f['permiso'];
        }
        return $mapa;
    }

    /**
     * Quiénes tienen cada rol: [rol_id => [['nombre' => …, 'activo' => bool]]].
     * Un número ("3 usuarios") no dice a quién le afecta un cambio; los
     * nombres sí.
     */
    public function usuariosPorRol(): array
    {
        $mapa = [];
        $filas = $this->fetchAll(
            "SELECT rol_id, nombre, activo FROM usuarios WHERE rol_id IS NOT NULL ORDER BY activo DESC, nombre ASC"
        ) ?: [];
        foreach ($filas as $f) {
            $mapa[(int) $f['rol_id']][] = ['nombre' => (string) $f['nombre'], 'activo' => (bool) $f['activo']];
        }
        return $mapa;
    }

    /**
     * Los usuarios activos que no tienen rol y no son administradores de los
     * de antes: entran al sistema y no pueden abrir nada. Pasa cuando se borra
     * un rol con gente, o con usuarios creados antes de que hubiera roles.
     */
    public function usuariosSinRol(): array
    {
        return array_column($this->fetchAll(
            "SELECT nombre FROM usuarios
              WHERE rol_id IS NULL AND activo = 1 AND (is_admin = 0 OR is_admin IS NULL)
           ORDER BY nombre"
        ) ?: [], 'nombre');
    }

    /** Solo lo necesario para llenar un desplegable. */
    public function paraElegir(): array
    {
        return $this->fetchAll("SELECT id, nombre, es_admin FROM roles ORDER BY es_admin DESC, nombre ASC") ?: [];
    }

    /** Las claves de permiso de un rol. */
    public function permisosDe(int $rolId): array
    {
        $filas = $this->fetchAll(
            "SELECT permiso FROM rol_permisos WHERE rol_id = ? ORDER BY permiso",
            [$rolId]
        ) ?: [];

        return array_column($filas, 'permiso');
    }

    /** Cuántos usuarios tiene un rol; se pregunta antes de borrarlo. */
    public function usuariosDe(int $rolId): int
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM usuarios WHERE rol_id = ?", [$rolId]);
    }

    public function nombreExiste(string $nombre, int $excluirId = 0): bool
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM roles WHERE nombre = ? AND id != ?",
            [$nombre, $excluirId]
        ) > 0;
    }

    public function idPorNombre(string $nombre): int
    {
        return (int) $this->fetchColumn("SELECT id FROM roles WHERE nombre = ? LIMIT 1", [$nombre]);
    }

    public function crear(string $nombre, string $descripcion, array $permisos): int
    {
        $id = (int) $this->insert(
            "INSERT INTO roles (nombre, descripcion, es_admin, es_sistema) VALUES (?, ?, 0, 0)",
            [$nombre, $descripcion !== '' ? $descripcion : null]
        );
        $this->fijarPermisos($id, $permisos);
        return $id;
    }

    public function actualizar(int $id, string $nombre, string $descripcion, array $permisos): void
    {
        $this->execute(
            "UPDATE roles SET nombre = ?, descripcion = ? WHERE id = ?",
            [$nombre, $descripcion !== '' ? $descripcion : null, $id]
        );

        // Al rol administrador no se le tocan los permisos: los tiene todos
        // por definición, y guardarle una lista concreta sería empezar a
        // poder dejarlo sin algo.
        $rol = $this->findById($id);
        if (empty($rol['es_admin'])) {
            $this->fijarPermisos($id, $permisos);
        }
    }

    /**
     * Reemplaza los permisos de un rol por la lista dada. Solo entran claves
     * que existen en el catálogo: un permiso inventado no protegería nada y
     * quedaría ahí, ofreciéndose en la pantalla como si sirviera.
     */
    public function fijarPermisos(int $rolId, array $permisos): void
    {
        $validos = array_flip(Permisos::todas());

        $this->execute("DELETE FROM rol_permisos WHERE rol_id = ?", [$rolId]);

        $vistos = [];
        foreach ($permisos as $permiso) {
            $permiso = trim((string) $permiso);
            if ($permiso === '' || isset($vistos[$permiso]) || !isset($validos[$permiso])) {
                continue;
            }
            $vistos[$permiso] = true;
            $this->execute(
                "INSERT IGNORE INTO rol_permisos (rol_id, permiso) VALUES (?, ?)",
                [$rolId, $permiso]
            );
        }
    }

    /**
     * Borra un rol y deja sin rol a quien lo tuviera. No se les pasa a otro
     * rol automáticamente: elegirlo por ellos es peor que dejarlo en blanco,
     * porque nadie se enteraría de que la gente cambió de permisos.
     *
     * Antes la protección de los roles del sistema estaba SOLO en la última
     * sentencia (`... AND es_sistema = 0`). Las dos de arriba no la tenían, así
     * que pedir el borrado de un rol del sistema le vaciaba los permisos y
     * dejaba a su gente sin rol —y el rol seguía ahí, vivo y sin nada—, sin un
     * error ni un aviso. Ahora se comprueba primero y se lanza.
     *
     * RolesController ya comprobaba las dos cosas antes de llamar, pero un
     * modelo no puede depender de que quien lo llame se acuerde: el que borra
     * es él.
     */
    public function eliminar(int $id): void
    {
        $rol = $this->fetchOne(
            "SELECT id, nombre, es_sistema FROM roles WHERE id = ?",
            [$id]
        );
        if (!$rol) {
            throw new RuntimeException('Ese rol no existe.');
        }
        if (!empty($rol['es_sistema'])) {
            throw new RuntimeException(
                'El rol "' . $rol['nombre'] . '" es parte del sistema y no se '
                . 'puede eliminar.'
            );
        }

        // Las tres cosas o ninguna: si algo falla a la mitad, el rol se queda
        // entero en vez de quedar sin permisos y con su gente suelta.
        $db = self::getDB();
        $propia = !$db->inTransaction();
        if ($propia) {
            $db->beginTransaction();
        }
        try {
            // Las claves foráneas de migration_claves_rol_pago_importacion.sql
            // hacen esto mismo solas (SET NULL y CASCADE). Se deja explícito
            // para que el comportamiento no dependa de si la migración se
            // aplicó, y para que se lea aquí qué le pasa a cada cosa.
            $this->execute("UPDATE usuarios SET rol_id = NULL WHERE rol_id = ?", [$id]);
            $this->execute("DELETE FROM rol_permisos WHERE rol_id = ?", [$id]);
            $this->execute("DELETE FROM roles WHERE id = ?", [$id]);
            if ($propia) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
