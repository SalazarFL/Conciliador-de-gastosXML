<?php
/**
 * Modelo del módulo "Facturas por pagar".
 *
 * Cada semana se sube el listado de facturas por pagar (porpagar_listados)
 * con sus líneas (porpagar_facturas); el matching las cruza contra las
 * facturas XML del sistema y deja el semáforo:
 * respaldada / con_diferencia / sin_respaldo.
 */

class PorPagar extends Model
{
    // El listado ya guardaba su sociedad_id, pero las facturas XML contra las
    // que se cruza salían de toda la tabla. Con dos empresas, el matching
    // semanal de una podía emparejar con una factura de la otra.
    use AlcanceSociedad;

    protected $table = 'porpagar_listados';

    public function __construct()
    {
        require_once __DIR__ . '/Semana.php';
        new Semana();
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS porpagar_listados (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nombre` VARCHAR(255) NOT NULL,
                `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
                `archivo_origen` VARCHAR(255) NULL DEFAULT NULL,
                `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0,
                `estado` ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
                `cerrado_en` DATETIME NULL DEFAULT NULL,
                `cerrado_por` INT UNSIGNED NULL DEFAULT NULL,
                `fecha_subida` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS porpagar_facturas (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `listado_id` INT UNSIGNED NOT NULL,
                `fecha` DATE NULL DEFAULT NULL,
                `numero` VARCHAR(100) NOT NULL,
                `proveedor_texto` VARCHAR(255) NOT NULL,
                `total` DECIMAL(18,2) NOT NULL DEFAULT 0,
                `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
                `estado` ENUM('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
                `diferencia` DECIMAL(18,2) NULL DEFAULT NULL,
                `score_numero` DECIMAL(5,1) NULL DEFAULT NULL,
                `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL,
                `match_manual` TINYINT(1) NOT NULL DEFAULT 0,
                `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL,
                `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_listado` (`listado_id`),
                KEY `idx_estado` (`estado`),
                KEY `idx_factura_erp` (`factura_erp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // La migración manual las crea si aquí no hay permisos DDL
        }

        // Columna añadida después del despliegue inicial (instalaciones previas)
        try {
            if (!$this->fetchOne("SHOW COLUMNS FROM porpagar_facturas LIKE 'match_manual'")) {
                $this->execute("ALTER TABLE porpagar_facturas
                                ADD COLUMN `match_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `score_proveedor`");
            }
        } catch (Throwable $e) {
        }
        try {
            if (!$this->fetchOne("SHOW COLUMNS FROM porpagar_listados LIKE 'estado'")) {
                $this->execute("ALTER TABLE porpagar_listados
                                ADD COLUMN `estado` ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto' AFTER `total_lineas`,
                                ADD COLUMN `cerrado_en` DATETIME NULL DEFAULT NULL AFTER `estado`,
                                ADD COLUMN `cerrado_por` INT UNSIGNED NULL DEFAULT NULL AFTER `cerrado_en`,
                                ADD KEY `idx_estado_pago` (`estado`)");
            }
        } catch (Throwable $e) {
        }
        foreach ([
            'cerrado_en' => "ADD COLUMN `cerrado_en` DATETIME NULL DEFAULT NULL AFTER `estado`",
            'cerrado_por' => "ADD COLUMN `cerrado_por` INT UNSIGNED NULL DEFAULT NULL AFTER `cerrado_en`",
        ] as $columna => $definicion) {
            try {
                if (!$this->fetchOne("SHOW COLUMNS FROM porpagar_listados LIKE '{$columna}'")) {
                    $this->execute("ALTER TABLE porpagar_listados {$definicion}");
                }
            } catch (Throwable $e) {
            }
        }
        try {
            if (!$this->fetchOne("SHOW COLUMNS FROM porpagar_facturas LIKE 'factura_erp_id'")) {
                $this->execute("ALTER TABLE porpagar_facturas
                                ADD COLUMN `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL AFTER `factura_xml_id`,
                                ADD KEY `idx_factura_erp` (`factura_erp_id`)");
            }
        } catch (Throwable $e) {
        }
    }

    // ── Listados (uno por semana subida) ───────────────────────────

    public function crearListado($nombre, $sociedadId, $archivoOrigen, $semanaId = null)
    {
        $sql = "INSERT INTO porpagar_listados (nombre, sociedad_id, archivo_origen, semana_id) VALUES (?, ?, ?, ?)";
        return $this->insert($sql, [$nombre, $sociedadId ?: null, $archivoOrigen, $semanaId ?: null]);
    }

    /**
     * Listados guardados. Filtro por semana: null = todos · 0 = sin semana ·
     * >0 = los de esa semana.
     */
    /**
     * Listados abiertos a los que todavía les falta respaldo, del más nuevo
     * al más viejo.
     *
     * Sirve para decidir qué vale la pena volver a verificar después de
     * importar facturas. Va en una sola consulta a propósito: preguntando
     * listado por listado eran tantos viajes al servidor como listados, y eso
     * se paga en cada tanda de un lote de correo.
     *
     * El límite deja fuera los listados antiguos. Un pago de hace meses con
     * líneas sin respaldo ya no las va a completar con una factura que llega
     * hoy, y revisarlo en cada importación es trabajo que nunca rinde.
     */
    public function idsAbiertosConFaltantes($limite = 10)
    {
        $where = ["l.estado <> 'cerrado'"];
        $params = [];
        $this->filtrarPorSociedad($where, $params, 'l.');

        $filas = $this->fetchAll(
            "SELECT l.id
               FROM porpagar_listados l
               JOIN porpagar_facturas pf
                 ON pf.listado_id = l.id AND pf.estado = 'sin_respaldo'
              WHERE " . implode(' AND ', $where) . "
              GROUP BY l.id
              ORDER BY l.id DESC
              LIMIT " . max(1, (int) $limite),
            $params
        ) ?: [];

        return array_values(array_map('intval', array_column($filas, 'id')));
    }

    public function getListados($limite = 30, $semanaId = null)
    {
        $where = ['1=1'];
        $params = [];
        $this->filtrarPorSociedad($where, $params, 'l.');
        if ($semanaId !== null && $semanaId !== '') {
            if ((int) $semanaId > 0) {
                $where[] = 'l.semana_id = ?';
                $params[] = (int) $semanaId;
            } else {
                $where[] = 'l.semana_id IS NULL';
            }
        }

        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre,
                       sem.carpeta_pago AS semana_carpeta_pago
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.id DESC
                LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getListado($id)
    {
        $params = [(int) $id];
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, sem.nombre AS semana_nombre,
                       sem.carpeta_pago AS semana_carpeta_pago
                FROM porpagar_listados l
                LEFT JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN semanas sem ON sem.id = l.semana_id
                WHERE l.id = ?" . $this->condicionSociedad('l.', $params) . " LIMIT 1";
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    public function actualizarTotalLineas($listadoId)
    {
        $sql = "UPDATE porpagar_listados
                SET total_lineas = (SELECT COUNT(*) FROM porpagar_facturas WHERE listado_id = ?)
                WHERE id = ?";
        return $this->execute($sql, [(int) $listadoId, (int) $listadoId]);
    }

    public function eliminarListado($id)
    {
        $listado = $this->getListado((int) $id);
        if ($listado !== null && ($listado['estado'] ?? 'abierto') === 'cerrado') {
            throw new Exception('El pago semanal está cerrado y no se puede eliminar.');
        }
        $this->execute("DELETE FROM porpagar_facturas WHERE listado_id = ?", [(int) $id]);
        return $this->execute("DELETE FROM porpagar_listados WHERE id = ?", [(int) $id]);
    }

    // ── Líneas del listado ─────────────────────────────────────────

    /** Los valores de una línea, en el orden en que van al INSERT. */
    private function valoresLinea($listadoId, array $linea)
    {
        return [
            (int) $listadoId,
            $linea['fecha'] ?: null,
            (string) $linea['numero'],
            (string) $linea['proveedor'],
            (float) $linea['total'],
        ];
    }

    public function crearLinea($listadoId, array $linea)
    {
        $sql = "INSERT INTO porpagar_facturas (listado_id, fecha, numero, proveedor_texto, total)
                VALUES (?, ?, ?, ?, ?)";
        return $this->insert($sql, $this->valoresLinea($listadoId, $linea));
    }

    /**
     * Inserta las líneas de un listado en tandas. Un listado semanal son
     * cientos de filas, y con la base en el servidor cada INSERT cuesta un
     * viaje de ida y vuelta: de a una, la carga se acerca peligrosamente al
     * tiempo máximo de la petición.
     */
    public function crearLineasLote($listadoId, array $lineas, $tam = 200)
    {
        $insertadas = 0;
        foreach (array_chunk(array_values($lineas), max(1, (int) $tam)) as $tanda) {
            $params = [];
            foreach ($tanda as $linea) {
                foreach ($this->valoresLinea($listadoId, $linea) as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                'INSERT INTO porpagar_facturas (listado_id, fecha, numero, proveedor_texto, total)'
                . ' VALUES ' . implode(', ', array_fill(0, count($tanda), '(?, ?, ?, ?, ?)')),
                $params
            );
            $insertadas += count($tanda);
        }

        return $insertadas;
    }

    /**
     * Líneas del listado con los datos de la factura XML que las respalda.
     */
    public function getLineas($listadoId, array $filtros = [])
    {
        $where = ['pf.listado_id = ?'];
        $params = [(int) $listadoId];

        $buscar = trim((string) ($filtros['q'] ?? ''));
        if ($buscar !== '') {
            $like = '%' . $buscar . '%';
            $where[] = '(pf.numero LIKE ? OR f.numero_factura_asistente LIKE ? OR f.consecutivo_completo LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $proveedor = trim((string) ($filtros['proveedor'] ?? ''));
        if ($proveedor !== '') {
            $like = '%' . $proveedor . '%';
            $where[] = '(pf.proveedor_texto LIKE ? OR p.razon_social LIKE ?)';
            array_push($params, $like, $like);
        }

        $estado = (string) ($filtros['estado'] ?? '');
        if (in_array($estado, ['respaldada', 'con_diferencia', 'sin_respaldo'], true)) {
            $where[] = 'pf.estado = ?';
            $params[] = $estado;
        }

        $desde = trim((string) ($filtros['fecha_desde'] ?? ''));
        if ($desde !== '') {
            $where[] = 'pf.fecha >= ?';
            $params[] = $desde;
        }
        $hasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
        if ($hasta !== '') {
            $where[] = 'pf.fecha <= ?';
            $params[] = $hasta;
        }

        if (isset($filtros['monto_desde']) && $filtros['monto_desde'] !== ''
            && $filtros['monto_desde'] !== null && is_numeric($filtros['monto_desde'])) {
            $where[] = 'pf.total >= ?';
            $params[] = (float) $filtros['monto_desde'];
        }
        if (isset($filtros['monto_hasta']) && $filtros['monto_hasta'] !== ''
            && $filtros['monto_hasta'] !== null && is_numeric($filtros['monto_hasta'])) {
            $where[] = 'pf.total <= ?';
            $params[] = (float) $filtros['monto_hasta'];
        }

        $vinculo = (string) ($filtros['vinculo'] ?? '');
        if ($vinculo === 'manual') {
            $where[] = 'pf.factura_xml_id IS NOT NULL AND pf.match_manual = 1';
        } elseif ($vinculo === 'automatico') {
            $where[] = 'pf.factura_xml_id IS NOT NULL AND pf.match_manual = 0';
        } elseif ($vinculo === 'sin_vinculo') {
            $where[] = 'pf.factura_xml_id IS NULL';
        }

        $sql = "SELECT pf.*,
                       f.numero_factura_asistente AS xml_numero,
                       f.total AS xml_total,
                       f.fecha_emision AS xml_fecha,
                       p.razon_social AS xml_proveedor
                FROM porpagar_facturas pf
                LEFT JOIN facturas_xml f ON f.id = pf.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY FIELD(pf.estado, 'con_diferencia', 'sin_respaldo', 'respaldada'),
                         pf.proveedor_texto ASC, pf.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function actualizarMatch($lineaId, $facturaXmlId, $estado, $diferencia, $scoreNumero, $scoreProveedor)
    {
        $sql = "UPDATE porpagar_facturas
                SET factura_xml_id = ?, estado = ?, diferencia = ?, score_numero = ?, score_proveedor = ?,
                    match_manual = 0
                WHERE id = ?";
        return $this->execute($sql, [
            $facturaXmlId ?: null,
            $estado,
            $diferencia,
            $scoreNumero,
            $scoreProveedor,
            (int) $lineaId,
        ]);
    }

    private const COLUMNAS_MATCH = ['factura_xml_id', 'estado', 'diferencia',
        'score_numero', 'score_proveedor'];

    /**
     * Lo mismo que actualizarMatch pero para muchas líneas de una vez.
     *
     * Verificar un listado toca TODAS sus líneas —cientos—, y con la base en
     * el servidor cada UPDATE cuesta un viaje de ida y vuelta. De a una, un
     * listado grande se llevaba la mitad del tiempo de la petición, y al
     * vincular a mano eso se repite en cada listado abierto.
     *
     * El ELSE de cada CASE deja intacta cualquier fila fuera del lote.
     */
    public function actualizarMatchLote(array $filas, $tam = 100)
    {
        $afectadas = 0;
        foreach (array_chunk(array_values($filas), max(1, (int) $tam)) as $tanda) {
            $sets = [];
            $params = [];
            foreach (self::COLUMNAS_MATCH as $columna) {
                $caso = "{$columna} = CASE id";
                foreach ($tanda as $fila) {
                    $caso .= ' WHEN ? THEN ?';
                    $params[] = (int) $fila['id'];
                    $params[] = $fila[$columna];
                }
                $sets[] = $caso . " ELSE {$columna} END";
            }
            $ids = array_map(function ($fila) { return (int) $fila['id']; }, $tanda);
            $afectadas += (int) $this->execute(
                'UPDATE porpagar_facturas SET ' . implode(', ', $sets) . ', match_manual = 0'
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, $ids)
            );
        }

        return $afectadas;
    }

    /**
     * Vínculo manual (botón "Sin coincidencia"): número y proveedor no
     * coincidieron, la persona decidió el respaldo y solo cuenta el monto.
     * La marca match_manual evita que "Verificar de nuevo" lo deshaga.
     */
    public function actualizarMatchManual($lineaId, $facturaXmlId, $estado, $diferencia)
    {
        $sql = "UPDATE porpagar_facturas
                SET factura_xml_id = ?, estado = ?, diferencia = ?,
                    score_numero = NULL, score_proveedor = NULL, match_manual = 1
                WHERE id = ?";
        return $this->execute($sql, [(int) $facturaXmlId, $estado, $diferencia, (int) $lineaId]);
    }

    public function getLinea($id)
    {
        $fila = $this->fetchOne(
            "SELECT pf.*, l.estado AS listado_estado
               FROM porpagar_facturas pf
               JOIN porpagar_listados l ON l.id = pf.listado_id
              WHERE pf.id = ? LIMIT 1",
            [(int) $id]
        );
        return $fila ?: null;
    }

    /**
     * Elimina solo una factura del listado y mantiene sincronizado el total
     * mostrado en porpagar_listados. La factura XML vinculada no se elimina.
     *
     * @return array|null La linea eliminada, o null si ya no existia.
     */
    public function eliminarLinea($id)
    {
        $linea = $this->getLinea((int) $id);
        if ($linea === null) {
            return null;
        }
        if (($linea['listado_estado'] ?? 'abierto') === 'cerrado') {
            throw new Exception('El pago semanal está cerrado y sus facturas ya no se pueden eliminar.');
        }

        $eliminadas = $this->execute(
            "DELETE FROM porpagar_facturas WHERE id = ? AND listado_id = ?",
            [(int) $id, (int) $linea['listado_id']]
        );
        if ($eliminadas < 1) {
            return null;
        }

        $this->actualizarTotalLineas((int) $linea['listado_id']);
        return $linea;
    }

    /**
     * Líneas del listado aún sin respaldo: las candidatas al vínculo manual.
     */
    public function getLineasSinRespaldo($listadoId)
    {
        $sql = "SELECT id, numero, proveedor_texto, total
                FROM porpagar_facturas
                WHERE listado_id = ? AND estado = 'sin_respaldo'
                ORDER BY proveedor_texto ASC, id ASC";
        return $this->fetchAll($sql, [(int) $listadoId]) ?: [];
    }

    /**
     * Facturas candidatas para el matching: solo FE (NC/ND no respaldan
     * pagos) y solo las columnas necesarias (sin el detalle pesado).
     *
     * Con $semanaId > 0 se limita a las facturas asignadas a ESA semana:
     * la verificación del listado no es contra el acumulado del sistema.
     */
    public function getFacturasParaMatching($semanaId = 0)
    {
        $params = [];
        $sql = "SELECT f.id, f.numero_factura_asistente, f.consecutivo_completo, f.total,
                       f.semana_id, p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params);

        if ((int) $semanaId > 0) {
            $sql .= " AND (f.semana_id = ? OR f.semana_id IS NULL)";
            $params[] = (int) $semanaId;
            $sql .= " ORDER BY CASE WHEN f.semana_id = ? THEN 0 ELSE 1 END, f.id ASC";
            $params[] = (int) $semanaId;
        } else {
            $sql .= " ORDER BY f.id ASC";
        }

        return $this->fetchAll($sql, $params) ?: [];
    }

    /** Facturas enlazadas antes o después de verificar, para reordenar sus archivos. */
    public function getFacturaIdsVinculadas($listadoId, $soloRespaldadas = false)
    {
        $estado = $soloRespaldadas ? " AND estado = 'respaldada'" : '';
        $rows = $this->fetchAll(
            "SELECT DISTINCT factura_xml_id AS id
             FROM porpagar_facturas
             WHERE listado_id = ? AND factura_xml_id IS NOT NULL{$estado}",
            [(int) $listadoId]
        ) ?: [];
        return array_values(array_map('intval', array_column($rows, 'id')));
    }

    /**
     * Una coincidencia automática por número+proveedor pasa a pertenecer a
     * la semana aunque el monto sea distinto. Los vínculos manuales con
     * diferencia no se incluyen. Nunca se reasigna desde otra semana.
     */
    public function asignarRespaldadasASemana($listadoId, $semanaId)
    {
        if ((int) $semanaId <= 0) {
            return 0;
        }
        $sql = "UPDATE facturas_xml f
                JOIN porpagar_facturas pf ON pf.factura_xml_id = f.id
                SET f.semana_id = ?
                WHERE pf.listado_id = ?
                  AND (pf.estado = 'respaldada'
                       OR (pf.estado = 'con_diferencia' AND pf.match_manual = 0))
                  AND (f.semana_id IS NULL OR f.semana_id = ?)";
        return $this->execute($sql, [(int) $semanaId, (int) $listadoId, (int) $semanaId]);
    }

    /**
     * Una factura de la semana para el chequeo de coincidencia (mismos
     * campos que getFacturasParaMatching). NULL si no existe o es NC/ND.
     */
    public function getFacturaParaMatching($facturaId)
    {
        $params = [(int) $facturaId];
        // proveedor_id se usa al aprender un alias de nombre en el
        // emparejamiento manual (ver PorPagarController::forzar).
        $sql = "SELECT f.id, f.numero_factura_asistente, f.consecutivo_completo, f.total,
                       f.proveedor_id, p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.id = ? AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params) . "
                LIMIT 1";
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    /**
     * Facturas XML de la semana que no respaldan ninguna línea del listado:
     * quedaron fuera del último matching (número/proveedor no coincidió).
     */
    public function getFacturasSinCoincidencia($semanaId, $listadoId)
    {
        $params = [(int) $semanaId, (int) $listadoId];
        $sql = "SELECT f.id, f.numero_factura_asistente, f.consecutivo_completo, f.total,
                       f.fecha_emision, p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')
                  AND f.semana_id = ?
                  AND f.id NOT IN (
                      SELECT factura_xml_id FROM porpagar_facturas
                      WHERE listado_id = ? AND factura_xml_id IS NOT NULL
                  )"
             . $this->condicionSociedad('f.', $params) . "
                ORDER BY p.razon_social ASC, f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function resumenPorEstado($listadoId)
    {
        $sql = "SELECT estado, COUNT(*) AS n, COALESCE(SUM(total), 0) AS monto
                FROM porpagar_facturas
                WHERE listado_id = ?
                GROUP BY estado";
        $resumen = [];
        foreach ($this->fetchAll($sql, [(int) $listadoId]) ?: [] as $fila) {
            $resumen[$fila['estado']] = (int) $fila['n'];
            $resumen[$fila['estado'] . '_monto'] = (float) $fila['monto'];
        }
        return $resumen;
    }
}
