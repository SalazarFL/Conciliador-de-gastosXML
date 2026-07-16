<?php
/**
 * Modelo de cuentas de correo IMAP.
 *
 * La empresa tiene varios buzones: las cuentas se administran desde el ⚙
 * del módulo Correo y se elige con cuál trabajar. El password se guarda en
 * base64 (herramienta local, la BD no sale del equipo).
 *
 * La primera vez que se usa, si existe app/config/correo.php (la config
 * antigua de una sola cuenta) se siembra como "Cuenta principal" y los
 * datos ya indexados (correo_indice/correo_carpetas/correo_bandeja con
 * cuenta_id = 0) se adoptan a su nombre, igual que las marcas de
 * correo_procesados (se prefijan con "c{id}:").
 */

class CorreoCuenta extends Model
{
    protected $table = 'correo_cuentas';

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
                `host` VARCHAR(255) NOT NULL,
                `puerto` INT UNSIGNED NOT NULL DEFAULT 993,
                `usuario` VARCHAR(255) NOT NULL,
                `password` TEXT NOT NULL,
                `carpeta` VARCHAR(100) NOT NULL DEFAULT 'INBOX',
                `dias_atras` INT UNSIGNED NOT NULL DEFAULT 0,
                `solo_no_leidos` TINYINT(1) NOT NULL DEFAULT 0,
                `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
        }
    }

    public function getAll()
    {
        return $this->fetchAll("SELECT * FROM {$this->table} ORDER BY id ASC") ?: [];
    }

    public function getById($id)
    {
        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [(int) $id]);
        return $fila ?: null;
    }

    public function contarTotal()
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}");
    }

    public function crear(array $datos)
    {
        $sql = "INSERT INTO {$this->table} (nombre, host, puerto, usuario, password, carpeta, dias_atras, solo_no_leidos)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->insert($sql, [
            (string) $datos['nombre'],
            (string) $datos['host'],
            max(1, (int) ($datos['puerto'] ?? 993)),
            (string) $datos['usuario'],
            base64_encode((string) $datos['password']),
            trim((string) ($datos['carpeta'] ?? '')) !== '' ? (string) $datos['carpeta'] : 'INBOX',
            max(0, (int) ($datos['dias_atras'] ?? 0)),
            !empty($datos['solo_no_leidos']) ? 1 : 0,
        ]);
    }

    /**
     * Actualiza una cuenta. Si el password llega vacío se conserva el actual.
     */
    public function actualizar($id, array $datos)
    {
        $actual = $this->getById($id);
        if ($actual === null) {
            return 0;
        }

        $password = trim((string) ($datos['password'] ?? '')) !== ''
            ? base64_encode((string) $datos['password'])
            : $actual['password'];

        $sql = "UPDATE {$this->table}
                SET nombre = ?, host = ?, puerto = ?, usuario = ?, password = ?, carpeta = ?
                WHERE id = ?";
        return $this->execute($sql, [
            (string) $datos['nombre'],
            (string) $datos['host'],
            max(1, (int) ($datos['puerto'] ?? 993)),
            (string) $datos['usuario'],
            $password,
            trim((string) ($datos['carpeta'] ?? '')) !== '' ? (string) $datos['carpeta'] : 'INBOX',
            (int) $id,
        ]);
    }

    public function eliminar($id)
    {
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $id]);
    }

    /**
     * Config lista para MailFetcher (password decodificado + cuenta_id).
     */
    public function configPara($id)
    {
        $cuenta = $this->getById($id);
        if ($cuenta === null) {
            return null;
        }

        return [
            'cuenta_id' => (int) $cuenta['id'],
            'host' => (string) $cuenta['host'],
            'puerto' => (int) $cuenta['puerto'],
            'usuario' => (string) $cuenta['usuario'],
            'password' => (string) base64_decode((string) $cuenta['password'], true),
            'carpeta' => (string) $cuenta['carpeta'],
            'dias_atras' => (int) $cuenta['dias_atras'],
            'solo_no_leidos' => (bool) $cuenta['solo_no_leidos'],
        ];
    }

    /**
     * Siembra la tabla desde app/config/correo.php (config antigua de una
     * sola cuenta) y adopta los datos ya indexados. Idempotente: si ya hay
     * cuentas no hace nada. Devuelve el id sembrado o 0.
     */
    public function seedDesdeArchivo()
    {
        if ($this->contarTotal() > 0) {
            return 0;
        }

        if (!class_exists('MailFetcher')) {
            require_once __DIR__ . '/../helpers/MailFetcher.php';
        }

        $config = MailFetcher::cargarConfig();
        if (!MailFetcher::configurado($config)) {
            return 0;
        }

        $id = (int) $this->crear([
            'nombre' => 'Cuenta principal',
            'host' => (string) $config['host'],
            'puerto' => (int) ($config['puerto'] ?? 993),
            'usuario' => (string) $config['usuario'],
            'password' => (string) $config['password'],
            'carpeta' => (string) ($config['carpeta'] ?? 'INBOX'),
            'dias_atras' => (int) ($config['dias_atras'] ?? 0),
            'solo_no_leidos' => !empty($config['solo_no_leidos']),
        ]);

        if ($id <= 0) {
            return 0;
        }

        // Adoptar lo ya indexado por la config de archivo (cuenta_id = 0)
        try {
            $this->execute("UPDATE correo_indice SET cuenta_id = ? WHERE cuenta_id = 0", [$id]);
            $this->execute("UPDATE correo_carpetas SET cuenta_id = ? WHERE cuenta_id = 0", [$id]);
            $this->execute("UPDATE correo_bandeja SET cuenta_id = ? WHERE cuenta_id = 0", [$id]);
        } catch (Throwable $e) {
            // Tablas aún sin la columna: la migración manual la agrega
        }

        // Prefijar las marcas de procesado ("uidvalidity:uid" → "c{id}:...").
        // Instanciar el modelo también importa un procesados.json legado que
        // siga en disco, así el prefijado alcanza esas marcas.
        try {
            if (!class_exists('CorreoProcesado')) {
                require_once __DIR__ . '/CorreoProcesado.php';
            }
            (new CorreoProcesado())->prefijarCuenta($id);
        } catch (Throwable $e) {
            // Sin marcas que adoptar
        }

        return $id;
    }
}
