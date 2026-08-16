<?php
/**
 * Bandeja de avisos: lo que muestra la campana de la barra superior.
 *
 * Los avisos son compartidos por todo el grupo, no de cada usuario. Un aviso
 * no es "algo que no he leído" sino una tarea pendiente del equipo: si alguien
 * la resuelve, desaparece para todos. Por eso el contador cuenta `pendiente` y
 * no hay estado de lectura por persona.
 *
 * Todo lo que escribe está envuelto en try/catch. Un aviso jamás puede tumbar
 * la operación que lo generó: si la campana falla, el emparejamiento tiene que
 * seguir igual.
 */

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    /** Cuántos avisos caben en el panel desplegable. */
    const EN_PANEL = 8;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS notificaciones (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tipo` VARCHAR(40) NOT NULL,
            `severidad` VARCHAR(10) NOT NULL DEFAULT 'media',
            `titulo` VARCHAR(200) NOT NULL,
            `detalle` TEXT NULL DEFAULT NULL,
            `firma` VARCHAR(190) NOT NULL,
            `veces` INT UNSIGNED NOT NULL DEFAULT 1,
            `ref_tabla` VARCHAR(40) NOT NULL DEFAULT '',
            `ref_clave` VARCHAR(60) NOT NULL DEFAULT '',
            `datos` TEXT NULL DEFAULT NULL,
            `estado` ENUM('pendiente','resuelta','descartada') NOT NULL DEFAULT 'pendiente',
            `resuelta_por` INT UNSIGNED NULL DEFAULT NULL,
            `resuelta_en` DATETIME NULL DEFAULT NULL,
            `motivo` VARCHAR(255) NULL DEFAULT NULL,
            `creada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizada_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_firma` (`firma`),
            KEY `idx_estado` (`estado`),
            KEY `idx_tipo` (`tipo`),
            KEY `idx_creada` (`creada_en`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Deja un aviso, o suma una repetición si ya estaba.
     *
     * La `firma` es la identidad del hecho, no del momento: el mismo problema
     * detectado en diez verificaciones seguidas es UN aviso con `veces = 10`,
     * no diez renglones. Sin eso la campana se vuelve inservible en días.
     *
     * Un aviso ya resuelto que vuelve a ocurrir se REABRE, porque significa
     * que la decisión que se tomó no alcanzó. Uno descartado no: descartar es
     * decir "ya sé, no me lo vuelvas a mostrar".
     */
    public function avisar(array $a)
    {
        $firma = trim((string) ($a['firma'] ?? ''));
        $titulo = trim((string) ($a['titulo'] ?? ''));
        if ($firma === '' || $titulo === '') {
            return 0;
        }

        try {
            $this->execute(
                'INSERT INTO notificaciones
                    (tipo, severidad, titulo, detalle, firma, ref_tabla, ref_clave, datos)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    veces      = veces + 1,
                    severidad  = VALUES(severidad),
                    titulo     = VALUES(titulo),
                    detalle    = VALUES(detalle),
                    datos      = VALUES(datos),
                    estado     = IF(estado = \'descartada\', \'descartada\', \'pendiente\')',
                [
                    mb_substr((string) ($a['tipo'] ?? 'general'), 0, 40, 'UTF-8'),
                    mb_substr((string) ($a['severidad'] ?? 'media'), 0, 10, 'UTF-8'),
                    mb_substr($titulo, 0, 200, 'UTF-8'),
                    $a['detalle'] ?? null,
                    mb_substr($firma, 0, 190, 'UTF-8'),
                    mb_substr((string) ($a['ref_tabla'] ?? ''), 0, 40, 'UTF-8'),
                    mb_substr((string) ($a['ref_clave'] ?? ''), 0, 60, 'UTF-8'),
                    isset($a['datos']) ? json_encode($a['datos'], JSON_UNESCAPED_UNICODE) : null,
                ]
            );
            return 1;
        } catch (Throwable $e) {
            return 0; // la campana nunca tumba lo que la disparó
        }
    }

    /** El número rojo de la campana. */
    public function pendientes()
    {
        try {
            return (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM notificaciones WHERE estado = 'pendiente'"
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Lo que se ve al abrir la campana: solo pendientes, lo más nuevo arriba. */
    public function recientes($limite = self::EN_PANEL)
    {
        try {
            return $this->fetchAll(
                "SELECT id, tipo, severidad, titulo, detalle, veces, ref_tabla, ref_clave,
                        datos, estado, creada_en
                   FROM notificaciones
                  WHERE estado = 'pendiente'
                  ORDER BY FIELD(severidad, 'alta', 'media', 'baja'), creada_en DESC
                  LIMIT " . max(1, (int) $limite)
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * La lista completa de la pantalla de revisión.
     *
     * @param string $estado 'pendiente', 'resuelta', 'descartada' o '' para todo.
     */
    public function listar($estado = 'pendiente', $limite = 200)
    {
        $where = '';
        $params = [];
        if (in_array($estado, ['pendiente', 'resuelta', 'descartada'], true)) {
            $where = 'WHERE n.estado = ?';
            $params[] = $estado;
        }

        try {
            return $this->fetchAll(
                "SELECT n.*, u.nombre AS resuelta_por_nombre
                   FROM notificaciones n
                   LEFT JOIN usuarios u ON u.id = n.resuelta_por
                   {$where}
                  ORDER BY FIELD(n.estado, 'pendiente', 'resuelta', 'descartada'),
                           FIELD(n.severidad, 'alta', 'media', 'baja'),
                           n.creada_en DESC
                  LIMIT " . max(1, (int) $limite),
                $params
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function porId($id)
    {
        try {
            return $this->fetchOne(
                'SELECT * FROM notificaciones WHERE id = ? LIMIT 1',
                [(int) $id]
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Cierra un aviso. $estado: 'resuelta' o 'descartada'. */
    public function cerrar($id, $estado, $usuarioId = 0, $motivo = '')
    {
        if (!in_array($estado, ['resuelta', 'descartada'], true)) {
            return 0;
        }
        try {
            return (int) $this->execute(
                'UPDATE notificaciones
                    SET estado = ?, resuelta_por = ?, resuelta_en = NOW(), motivo = ?
                  WHERE id = ?',
                [
                    $estado,
                    (int) $usuarioId > 0 ? (int) $usuarioId : null,
                    mb_substr(trim((string) $motivo), 0, 255, 'UTF-8') ?: null,
                    (int) $id,
                ]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Cierra por firma. Lo usa quien arregla la causa desde otra pantalla. */
    public function cerrarPorFirma($firma, $estado = 'resuelta', $usuarioId = 0)
    {
        $firma = trim((string) $firma);
        if ($firma === '' || !in_array($estado, ['resuelta', 'descartada'], true)) {
            return 0;
        }
        try {
            return (int) $this->execute(
                "UPDATE notificaciones
                    SET estado = ?, resuelta_por = ?, resuelta_en = NOW()
                  WHERE firma = ? AND estado = 'pendiente'",
                [$estado, (int) $usuarioId > 0 ? (int) $usuarioId : null, $firma]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }
}
