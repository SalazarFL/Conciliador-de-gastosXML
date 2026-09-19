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
    /*
     * Un aviso puede ser de una empresa o del grupo.
     *
     * De una empresa: el recordatorio de un documento concreto. Del grupo: que
     * el índice de un buzón se quedó atrás, o que un código del ERP apunta a
     * dos proveedores —el buzón y el mapa de códigos son compartidos—. Los
     * primeros llevan su `sociedad_id`; los segundos lo llevan en 0 y se ven
     * desde todas. Sin esta distinción, la empresa nueva abría la campana con
     * los pendientes de la otra, y resolverlos ahí los apagaba en las dos.
     *
     * El "del grupo" es 0 y no NULL a propósito: la unicidad que junta el
     * mismo hecho repetido en un solo renglón es (sociedad_id, firma), y MySQL
     * considera distintos dos NULL. Con NULL, cada repetición de un aviso del
     * grupo habría abierto una fila nueva en vez de sumar `veces`.
     */
    use AlcanceSociedad;

    protected $table = 'notificaciones';

    /** Cuántos avisos caben en el panel desplegable. */
    const EN_PANEL = 8;

    /**
     * Los avisos que NO suenan en la campana.
     *
     * No son menos importantes: son de otra naturaleza. Un aviso de la campana
     * es un hecho suelto que alguien tiene que atender —"este código apunta a
     * dos proveedores"—, y se espera que la lista quede en cero. Un comprobante
     * de un proveedor sin código es trabajo de catálogo: llegan a decenas, no
     * urgen, y se despachan de corrido mirándolos juntos. Mezclados, tapaban con
     * veinte renglones los tres avisos que sí había que atender ese día, y la
     * campana dejaba de significar algo.
     *
     * Se siguen guardando igual, con la misma firma y las mismas salidas; lo
     * único que cambia es por dónde se atienden: Correo → Comprobantes sin
     * código, que es la pantalla donde se está cuando aparecen.
     */
    const FUERA_DE_LA_CAMPANA = ['proveedor_sin_codigo'];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS notificaciones (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sociedad_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = aviso del grupo',
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
            UNIQUE KEY `uk_firma` (`sociedad_id`, `firma`),
            KEY `idx_estado` (`estado`),
            KEY `idx_tipo` (`tipo`),
            KEY `idx_creada` (`creada_en`),
            KEY `idx_sociedad` (`sociedad_id`, `estado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->ponerseAlDia();
    }

    /**
     * La campana es anterior a que hubiera dos empresas: en las bases ya
     * instaladas hay que agregarle la columna y rehacer la unicidad, que se
     * escribió sobre la firma sola. Con ella así, el mismo problema en dos
     * empresas era UN aviso con veces = 2, y el de la segunda no existía.
     */
    private function ponerseAlDia()
    {
        try {
            $tiene = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                    AND COLUMN_NAME = 'sociedad_id'"
            );
            if ($tiene === 0) {
                $this->execute(
                    "ALTER TABLE notificaciones
                       ADD COLUMN `sociedad_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`,
                       ADD KEY `idx_sociedad` (`sociedad_id`, `estado`)"
                );
            } else {
                // Que admita NULL rompería lo que junta las repeticiones en un
                // solo renglón: para MySQL dos NULL son distintos y la
                // unicidad (sociedad_id, firma) dejaría de agrupar los avisos
                // del grupo. El 0 es el valor que sí compara igual.
                $nulos = (string) $this->fetchColumn(
                    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                        AND COLUMN_NAME = 'sociedad_id'"
                );
                if (strtoupper($nulos) === 'YES') {
                    $this->execute('UPDATE notificaciones SET sociedad_id = 0 WHERE sociedad_id IS NULL');
                    $this->execute(
                        "ALTER TABLE notificaciones
                          MODIFY COLUMN `sociedad_id` INT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT '0 = aviso del grupo'"
                    );
                }
            }
            $partes = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
                    AND INDEX_NAME = 'uk_firma'"
            );
            if ($partes === 1) {
                $this->execute(
                    "ALTER TABLE notificaciones DROP INDEX `uk_firma`,
                       ADD UNIQUE KEY `uk_firma` (`sociedad_id`, `firma`)"
                );
            }
        } catch (Throwable $e) {
            // Sin permisos de DDL la campana sigue funcionando como antes.
        }
    }

    /**
     * Los avisos que le tocan a quien mira: los de su empresa y los del grupo.
     * Sin empresa en curso no se acota, que es lo que necesitan los procesos
     * sin sesión.
     */
    private function condicionVisible($alias = '', ?array &$params = null)
    {
        $id = $this->sociedadId();
        if ($id <= 0) {
            return '';
        }
        if ($params !== null) {
            $params[] = $id;
        }
        return " AND ({$alias}sociedad_id = 0 OR {$alias}sociedad_id = ?)";
    }

    /**
     * Deja fuera lo que tiene su propia pantalla. Ver FUERA_DE_LA_CAMPANA.
     */
    private function condicionCampana($alias = '', ?array &$params = null)
    {
        $tipos = self::FUERA_DE_LA_CAMPANA;
        if (!$tipos) {
            return '';
        }
        if ($params !== null) {
            foreach ($tipos as $tipo) {
                $params[] = $tipo;
            }
        }
        return " AND {$alias}tipo NOT IN (" . implode(', ', array_fill(0, count($tipos), '?')) . ')';
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
        return $this->avisarVarios([$a]);
    }

    /**
     * Varios avisos en una sola escritura.
     *
     * Para quien deja muchos de una vez —los recordatorios vencidos de
     * Seguimiento—: de a un INSERT por aviso, cincuenta vencidos eran
     * cincuenta viajes a una base que está al otro lado de la red. Cada aviso
     * se comporta igual que con avisar(): suma `veces` si ya estaba y se
     * reabre si estaba resuelto.
     *
     * @return int Cuántos avisos se dejaron (0 si la escritura falló).
     */
    public function avisarVarios(array $avisos)
    {
        $filas = [];
        foreach ($avisos as $a) {
            $firma = trim((string) ($a['firma'] ?? ''));
            $titulo = trim((string) ($a['titulo'] ?? ''));
            if ($firma === '' || $titulo === '') {
                continue;
            }
            $filas[] = [
                // Sin 'sociedad_id' el aviso es del grupo y se ve desde
                // todas: así son los del buzón y los del mapa de códigos
                // del ERP, que no pertenecen a ninguna empresa en concreto.
                max(0, (int) ($a['sociedad_id'] ?? 0)),
                mb_substr((string) ($a['tipo'] ?? 'general'), 0, 40, 'UTF-8'),
                mb_substr((string) ($a['severidad'] ?? 'media'), 0, 10, 'UTF-8'),
                mb_substr($titulo, 0, 200, 'UTF-8'),
                $a['detalle'] ?? null,
                mb_substr($firma, 0, 190, 'UTF-8'),
                mb_substr((string) ($a['ref_tabla'] ?? ''), 0, 40, 'UTF-8'),
                mb_substr((string) ($a['ref_clave'] ?? ''), 0, 60, 'UTF-8'),
                isset($a['datos']) ? json_encode($a['datos'], JSON_UNESCAPED_UNICODE) : null,
            ];
        }
        if (!$filas) {
            return 0;
        }

        $dejados = 0;
        foreach (array_chunk($filas, 100) as $tanda) {
            try {
                $this->execute(
                    'INSERT INTO notificaciones
                        (sociedad_id, tipo, severidad, titulo, detalle, firma, ref_tabla, ref_clave, datos)
                     VALUES ' . implode(', ', array_fill(0, count($tanda), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')) . '
                     ON DUPLICATE KEY UPDATE
                        veces      = veces + 1,
                        severidad  = VALUES(severidad),
                        titulo     = VALUES(titulo),
                        detalle    = VALUES(detalle),
                        datos      = VALUES(datos),
                        estado     = IF(estado = \'descartada\', \'descartada\', \'pendiente\')',
                    array_merge(...$tanda)
                );
                $dejados += count($tanda);
            } catch (Throwable $e) {
                // la campana nunca tumba lo que la disparó
            }
        }
        return $dejados;
    }

    /** El número rojo de la campana. */
    public function pendientes()
    {
        try {
            $params = [];
            return (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM notificaciones WHERE estado = 'pendiente'"
                . $this->condicionVisible('', $params)
                . $this->condicionCampana('', $params),
                $params
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Lo que se ve al abrir la campana: solo pendientes, lo más nuevo arriba. */
    public function recientes($limite = self::EN_PANEL)
    {
        $params = [];
        try {
            return $this->fetchAll(
                "SELECT id, tipo, severidad, titulo, detalle, veces, ref_tabla, ref_clave,
                        datos, estado, creada_en
                   FROM notificaciones
                  WHERE estado = 'pendiente'"
                . $this->condicionVisible('', $params)
                . $this->condicionCampana('', $params) . "
                  ORDER BY FIELD(severidad, 'alta', 'media', 'baja'), creada_en DESC
                  LIMIT " . max(1, (int) $limite),
                $params
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
        $params = [];
        $where = 'WHERE 1=1';
        if (in_array($estado, ['pendiente', 'resuelta', 'descartada'], true)) {
            $where .= ' AND n.estado = ?';
            $params[] = $estado;
        }
        $where .= $this->condicionVisible('n.', $params);
        $where .= $this->condicionCampana('n.', $params);

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

    /**
     * Todos los avisos de un tipo, para la pantalla que se hace cargo de ellos.
     *
     * El reverso de FUERA_DE_LA_CAMPANA: lo que se sacó de la campana tiene que
     * poder verse entero en algún sitio, o sería lo mismo que no guardarlo. No
     * filtra por tipo excluido a propósito —quien pregunta por un tipo lo hace
     * porque es el suyo—, pero sí respeta la empresa en curso, igual que todo
     * lo demás.
     *
     * @param string $estado 'pendiente', 'resuelta', 'descartada' o '' para todo.
     */
    public function porTipo($tipo, $estado = 'pendiente', $limite = 500)
    {
        $tipo = trim((string) $tipo);
        if ($tipo === '') {
            return [];
        }

        $params = [$tipo];
        $where = 'WHERE n.tipo = ?';
        if (in_array($estado, ['pendiente', 'resuelta', 'descartada'], true)) {
            $where .= ' AND n.estado = ?';
            $params[] = $estado;
        }
        $where .= $this->condicionVisible('n.', $params);

        try {
            return $this->fetchAll(
                "SELECT n.*, u.nombre AS resuelta_por_nombre
                   FROM notificaciones n
                   LEFT JOIN usuarios u ON u.id = n.resuelta_por
                   {$where}
                  ORDER BY FIELD(n.estado, 'pendiente', 'resuelta', 'descartada'),
                           n.creada_en DESC
                  LIMIT " . max(1, (int) $limite),
                $params
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** El número del botón que lleva a esa pantalla. */
    public function contarPorTipo($tipo, $estado = 'pendiente')
    {
        $tipo = trim((string) $tipo);
        if ($tipo === '') {
            return 0;
        }

        $params = [$tipo];
        $where = 'WHERE tipo = ?';
        if (in_array($estado, ['pendiente', 'resuelta', 'descartada'], true)) {
            $where .= ' AND estado = ?';
            $params[] = $estado;
        }
        $where .= $this->condicionVisible('', $params);

        try {
            return (int) $this->fetchColumn("SELECT COUNT(*) FROM notificaciones {$where}", $params);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function porId($id)
    {
        try {
            $params = [(int) $id];
            return $this->fetchOne(
                'SELECT * FROM notificaciones WHERE id = ?'
                . $this->condicionVisible('', $params) . ' LIMIT 1',
                $params
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
        $params = [
            $estado,
            (int) $usuarioId > 0 ? (int) $usuarioId : null,
            mb_substr(trim((string) $motivo), 0, 255, 'UTF-8') ?: null,
            (int) $id,
        ];
        try {
            return (int) $this->execute(
                'UPDATE notificaciones
                    SET estado = ?, resuelta_por = ?, resuelta_en = NOW(), motivo = ?
                  WHERE id = ?' . $this->condicionVisible('', $params),
                $params
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Cierra por firma. Lo usa quien arregla la causa desde otra pantalla. */
    public function cerrarPorFirma($firma, $estado = 'resuelta', $usuarioId = 0)
    {
        return $this->cerrarPorFirmas([$firma], $estado, $usuarioId);
    }

    /**
     * Cierra los avisos de varias firmas en una sola escritura: sacar
     * cincuenta renglones de revisión eran cincuenta UPDATE.
     */
    public function cerrarPorFirmas(array $firmas, $estado = 'resuelta', $usuarioId = 0)
    {
        $firmas = array_values(array_unique(array_filter(
            array_map(static fn($f) => trim((string) $f), $firmas),
            static fn($f) => $f !== ''
        )));
        if (!$firmas || !in_array($estado, ['resuelta', 'descartada'], true)) {
            return 0;
        }

        $cerrados = 0;
        foreach (array_chunk($firmas, 500) as $tanda) {
            $params = array_merge([$estado, (int) $usuarioId > 0 ? (int) $usuarioId : null], $tanda);
            try {
                $cerrados += (int) $this->execute(
                    "UPDATE notificaciones
                        SET estado = ?, resuelta_por = ?, resuelta_en = NOW()
                      WHERE firma IN (" . implode(', ', array_fill(0, count($tanda), '?')) . ")
                        AND estado = 'pendiente'"
                    . $this->condicionVisible('', $params),
                    $params
                );
            } catch (Throwable $e) {
                // la campana nunca tumba lo que la disparó
            }
        }
        return $cerrados;
    }
}
