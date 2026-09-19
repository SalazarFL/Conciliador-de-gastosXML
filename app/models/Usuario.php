<?php
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/Rol.php';

/**
 * Las personas que entran al sistema.
 *
 * Cada una tiene un rol —lo que puede hacer— y, si se le quiere restringir,
 * una lista de empresas. Sin lista, ve todas: ver Permisos::sociedadesPermitidas()
 * para por qué ese es el valor por omisión.
 *
 * La columna `is_admin` sigue existiendo junto al rol y no es una repetición:
 * es el salvavidas. Sostiene al primer administrador antes de que haya roles
 * asignados, y evita que un error al editar roles deje la instalación sin
 * nadie que pueda entrar a arreglarla.
 */
class Usuario extends Model
{
    protected $table = 'usuarios';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->cambiarCorreoPorCedula(); });
    }

    /**
     * La ficha guardaba un correo electronico y ahora guarda la cedula.
     *
     * El correo no servia para nada: no se manda ningun aviso desde aqui y
     * nadie entra con el. La cedula si identifica a la persona y es lo que se
     * usa para cruzar con el ERP y con quien firma un documento.
     *
     * Se agrega la columna y se retira la del correo. El dato que habia no se
     * traslada, porque un correo no es una cedula: las fichas quedan sin
     * cedula hasta que alguien las edite, y el formulario la pide.
     */
    private function cambiarCorreoPorCedula()
    {
        try {
            $tieneCedula = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                    AND COLUMN_NAME = 'cedula'"
            );
            if ($tieneCedula === 0) {
                $this->execute(
                    "ALTER TABLE usuarios
                       ADD COLUMN `cedula` VARCHAR(30) NOT NULL DEFAULT '' AFTER `username`,
                       ADD KEY `idx_usuarios_cedula` (`cedula`)"
                );
            }

            $tieneEmail = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                    AND COLUMN_NAME = 'email'"
            );
            if ($tieneEmail > 0) {
                $this->execute('ALTER TABLE usuarios DROP COLUMN `email`');
            }
        } catch (Throwable $e) {
            // Sin permisos de DDL lo cubre la migracion manual.
        }
    }

    public function findByUsername(string $username): ?array
    {
        $row = $this->fetchOne(
            "SELECT * FROM usuarios WHERE username = ? LIMIT 1",
            [$username]
        );
        return $row ?: null;
    }

    /**
     * El listado de Configuración → Usuarios: con su rol y con cuántas
     * empresas tiene asignadas, que es lo que hay que poder mirar de un
     * vistazo para saber si el reparto está bien.
     */
    public function getAll(): array
    {
        try {
            return $this->fetchAll(
                "SELECT u.id, u.nombre, u.username, u.cedula, u.activo, u.is_admin,
                        u.rol_id, u.ultimo_acceso, u.created_at,
                        r.nombre AS rol_nombre, r.es_admin AS rol_es_admin,
                        (SELECT COUNT(*) FROM usuario_sociedades us WHERE us.usuario_id = u.id) AS empresas
                   FROM usuarios u
                   LEFT JOIN roles r ON r.id = u.rol_id
               ORDER BY u.id ASC"
            ) ?: [];
        } catch (Throwable $e) {
            // Una instalación a la que todavía no le corrió la migración de
            // roles sigue mostrando su lista de usuarios como antes.
            return $this->fetchAll(
                "SELECT id, nombre, username, cedula, activo, is_admin, ultimo_acceso, created_at
                   FROM usuarios ORDER BY id ASC"
            ) ?: [];
        }
    }

    public function create(array $data): int
    {
        $rolId = (int) ($data['rol_id'] ?? 0);

        $id = (int) $this->insert(
            "INSERT INTO usuarios (nombre, username, cedula, password_hash, activo, is_admin, rol_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['nombre'],
                $data['username'],
                self::soloDigitos($data['cedula'] ?? ''),
                password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                (int) ($data['activo'] ?? 1),
                (int) ($data['is_admin'] ?? 0),
                $rolId > 0 ? $rolId : null,
            ]
        );

        if (isset($data['sociedades'])) {
            (new Rol())->fijarSociedades($id, (array) $data['sociedades']);
        }

        return $id;
    }

    public function updateInfo(int $id, array $data): void
    {
        $rolId = (int) ($data['rol_id'] ?? 0);

        $this->execute(
            "UPDATE usuarios
                SET nombre=?, username=?, cedula=?, activo=?, is_admin=?, rol_id=?, updated_at=NOW()
              WHERE id=?",
            [
                $data['nombre'],
                $data['username'],
                self::soloDigitos($data['cedula'] ?? ''),
                (int) ($data['activo'] ?? 1),
                (int) ($data['is_admin'] ?? 0),
                $rolId > 0 ? $rolId : null,
                $id,
            ]
        );

        if (array_key_exists('sociedades', $data)) {
            (new Rol())->fijarSociedades($id, (array) $data['sociedades']);
        }
    }

    public function updatePassword(int $id, string $newPassword): void
    {
        $this->execute(
            "UPDATE usuarios SET password_hash=?, updated_at=NOW() WHERE id=?",
            [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $id]
        );
    }

    public function deleteById(int $id): void
    {
        // El rastro que dejó en la bitácora NO se borra: el nombre quedó
        // copiado en cada renglón justamente para que borrar una cuenta no
        // borre lo que esa cuenta hizo.
        $this->execute("DELETE FROM usuario_sociedades WHERE usuario_id=?", [$id]);
        $this->execute("DELETE FROM usuarios WHERE id=?", [$id]);
    }

    public function usernameExists(string $username, int $excludeId = 0): bool
    {
        $count = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios WHERE username=? AND id!=?",
            [$username, $excludeId]
        );
        return $count > 0;
    }

    public function cedulaExists(string $cedula, int $excludeId = 0): bool
    {
        $cedula = self::soloDigitos($cedula);
        if ($cedula === '') {
            return false;
        }
        $count = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios WHERE cedula=? AND id!=?",
            [$cedula, $excludeId]
        );
        return $count > 0;
    }

    /**
     * La cedula se guarda solo con digitos.
     *
     * Quien la escribe la escribe como quiere -3-101-639680, 3101639680- y las
     * dos son la misma. Guardarla normalizada es lo que hace que "ya esta
     * registrada" funcione y que se pueda cruzar con la del ERP.
     */
    public static function soloDigitos($valor): string
    {
        return (string) preg_replace('/\D+/', '', (string) $valor);
    }

    public function updateLastAccess(int $id): void
    {
        $this->execute(
            "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
            [$id]
        );
    }

    /** Las empresas asignadas a un usuario. Vacío = todas. */
    public function sociedadesDe(int $id): array
    {
        try {
            return (new Rol())->sociedadesDeUsuario($id);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Cuántos administradores quedan activos. Se pregunta antes de quitarle
     * el rol de administrador a alguien o de desactivarlo: una instalación
     * sin ningún administrador solo se arregla desde la base de datos.
     */
    public function administradoresActivos(int $excluirId = 0): int
    {
        try {
            return (int) $this->fetchColumn(
                "SELECT COUNT(*)
                   FROM usuarios u
                   LEFT JOIN roles r ON r.id = u.rol_id
                  WHERE u.activo = 1 AND u.id != ?
                    AND (u.is_admin = 1 OR r.es_admin = 1)",
                [$excluirId]
            );
        } catch (Throwable $e) {
            return (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND is_admin = 1 AND id != ?",
                [$excluirId]
            );
        }
    }
}
