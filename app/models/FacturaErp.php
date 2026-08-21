<?php
/**
 * Modelo del módulo "Facturas ERP".
 *
 * Guarda el reporte "Facturas por Proveedor" del ERP, cuyo dato útil es el
 * SALDO pendiente de cada factura. El archivo es una foto del momento en que
 * se imprimió, así que cada carga se sube completa y se reconcilia contra lo
 * que ya está guardado:
 *
 *   - la factura no existe            -> se inserta
 *   - existe y el saldo cambió        -> se actualiza (guardando el anterior)
 *   - existe y el saldo es el mismo   -> NO SE TOCA la fila
 *
 * La identidad entre cargas es la columna `clave`, que calcula el parser
 * (proveedor + documento + fecha, con variante para las que vienen sin
 * número). El documento por sí solo no sirve: se repite dentro del mismo
 * proveedor con distinta fecha de emisión.
 */

require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/ProveedorCatalogo.php';

class FacturaErp extends Model
{
    // El reporte del ERP no dice a qué empresa pertenece, así que la sociedad
    // se pregunta al cargarlo y las filas la heredan de su carga. Acotar aquí
    // es lo que impide que el cierre semanal de una sociedad empareje contra
    // una factura del ERP de otra.
    use AlcanceSociedad;

    protected $table = 'facturas_erp';

    /**
     * Las rutas no son suyas —vienen del comprobante enlazado— pero salen en
     * sus consultas, y quien las lee necesita la ruta real de esta máquina
     * para poder preguntar si el archivo sigue estando.
     */
    protected $camposRuta = ['ruta_xml', 'ruta_pdf'];

    /** Diferencia por debajo de la cual dos saldos son el mismo saldo. */
    const EPSILON_SALDO = 0.005;

    /** Filas por INSERT agrupado en la primera carga. */
    const LOTE_INSERT = 500;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS facturas_erp_cargas (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `archivo_origen` VARCHAR(255) NOT NULL,
            `impreso_en` DATETIME NULL DEFAULT NULL,
            `rango_texto` VARCHAR(255) NULL DEFAULT NULL,
            `filas_leidas` INT UNSIGNED NOT NULL DEFAULT 0,
            `insertadas` INT UNSIGNED NOT NULL DEFAULT 0,
            `actualizadas` INT UNSIGNED NOT NULL DEFAULT 0,
            `sin_cambio` INT UNSIGNED NOT NULL DEFAULT 0,
            `totales_verificados` INT UNSIGNED NOT NULL DEFAULT 0,
            `totales_descuadrados` INT UNSIGNED NOT NULL DEFAULT 0,
            `cuadre_ok` TINYINT(1) NOT NULL DEFAULT 0,
            `incidencias` INT UNSIGNED NOT NULL DEFAULT 0,
            `advertencias` TEXT NULL DEFAULT NULL,
            `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_creado` (`creado_en`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Historial de problemas detectados en cada carga. Vive aparte de las
        // facturas porque una misma factura puede generar incidencias en
        // varias cargas distintas y todas quedan como historial.
        $this->execute("CREATE TABLE IF NOT EXISTS facturas_erp_incidencias (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `carga_id` INT UNSIGNED NOT NULL,
            `tipo` VARCHAR(30) NOT NULL,
            `severidad` VARCHAR(10) NOT NULL DEFAULT 'aviso',
            `proveedor_codigo` VARCHAR(30) NOT NULL DEFAULT '',
            `proveedor_nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `documento` VARCHAR(60) NOT NULL DEFAULT '',
            `clave` VARCHAR(190) NOT NULL DEFAULT '',
            `fecha_emision` DATE NULL DEFAULT NULL,
            `monto` DECIMAL(18,2) NULL DEFAULT NULL,
            `saldo_anterior` DECIMAL(18,2) NULL DEFAULT NULL,
            `saldo_nuevo` DECIMAL(18,2) NULL DEFAULT NULL,
            `detalle` VARCHAR(500) NOT NULL DEFAULT '',
            `firma` VARCHAR(220) NOT NULL DEFAULT '',
            `descartada` TINYINT(1) NOT NULL DEFAULT 0,
            `descartada_en` DATETIME NULL DEFAULT NULL,
            `descartada_por` INT UNSIGNED NULL DEFAULT NULL,
            `motivo` VARCHAR(255) NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_carga` (`carga_id`),
            KEY `idx_tipo` (`tipo`),
            KEY `idx_severidad` (`severidad`),
            KEY `idx_proveedor` (`proveedor_codigo`),
            KEY `idx_documento` (`documento`),
            KEY `idx_firma` (`firma`),
            KEY `idx_descartada` (`descartada`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Descartes permanentes. Sin esta tabla descartar no serviría de nada:
        // la carga siguiente vuelve a evaluar el archivo completo y generaría
        // otra vez exactamente la misma incidencia.
        $this->execute("CREATE TABLE IF NOT EXISTS facturas_erp_descartes (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `firma` VARCHAR(220) NOT NULL,
            `tipo` VARCHAR(30) NOT NULL DEFAULT '',
            `proveedor_codigo` VARCHAR(30) NOT NULL DEFAULT '',
            `proveedor_nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `documento` VARCHAR(60) NOT NULL DEFAULT '',
            `motivo` VARCHAR(255) NULL DEFAULT NULL,
            `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_firma` (`firma`),
            KEY `idx_tipo` (`tipo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->execute("CREATE TABLE IF NOT EXISTS facturas_erp (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clave` VARCHAR(190) NOT NULL,
            `proveedor_codigo` VARCHAR(30) NOT NULL,
            `proveedor_nombre` VARCHAR(255) NOT NULL,
            `sucursal` VARCHAR(120) NOT NULL DEFAULT '',
            `tipo` VARCHAR(5) NOT NULL DEFAULT 'F',
            `documento` VARCHAR(60) NOT NULL DEFAULT '',
            `numero_corto` VARCHAR(20) NULL DEFAULT NULL,
            `fecha_emision` DATE NULL DEFAULT NULL,
            `fecha_vence` DATE NULL DEFAULT NULL,
            `origen` VARCHAR(10) NOT NULL DEFAULT '',
            `moneda` VARCHAR(10) NOT NULL DEFAULT '',
            `monto` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `saldo` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `saldo_colones` DECIMAL(18,2) NULL DEFAULT NULL,
            `saldo_anterior` DECIMAL(18,2) NULL DEFAULT NULL,
            `carga_id` INT UNSIGNED NULL DEFAULT NULL,
            `carga_cambio_id` INT UNSIGNED NULL DEFAULT NULL,
            `saldo_cambiado_en` DATETIME NULL DEFAULT NULL,
            `estado` ENUM('pendiente','asignada_semana') NOT NULL DEFAULT 'pendiente',
            `semana_id` INT UNSIGNED NULL DEFAULT NULL,
            `porpagar_listado_id` INT UNSIGNED NULL DEFAULT NULL,
            `asignada_semana_en` DATETIME NULL DEFAULT NULL,
            `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
            `estado_respaldo` ENUM('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
            `diferencia` DECIMAL(18,2) NULL DEFAULT NULL,
            `score_numero` DECIMAL(5,1) NULL DEFAULT NULL,
            `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL,
            `match_manual` TINYINT(1) NOT NULL DEFAULT 0,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_clave` (`clave`),
            KEY `idx_proveedor` (`proveedor_codigo`),
            KEY `idx_saldo` (`saldo`),
            KEY `idx_numero_corto` (`numero_corto`),
            KEY `idx_documento` (`documento`),
            KEY `idx_sucursal` (`sucursal`),
            KEY `idx_estado` (`estado`),
            KEY `idx_semana` (`semana_id`),
            KEY `idx_porpagar_listado` (`porpagar_listado_id`),
            KEY `idx_factura_xml` (`factura_xml_id`),
            KEY `idx_estado_respaldo` (`porpagar_listado_id`, `estado_respaldo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->agregarColumnasFaltantes();
    }

    /**
     * CREATE TABLE IF NOT EXISTS no toca una tabla que ya existe, así que las
     * columnas añadidas después de la primera versión se agregan aquí.
     */
    private function agregarColumnasFaltantes()
    {
        $pendientes = [
            'facturas_erp_cargas' => [
                'incidencias' => "ADD COLUMN `incidencias` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `cuadre_ok`",
            ],
            'facturas_erp_incidencias' => [
                'firma' => "ADD COLUMN `firma` VARCHAR(220) NOT NULL DEFAULT '' AFTER `detalle`, ADD KEY `idx_firma` (`firma`)",
                'descartada' => "ADD COLUMN `descartada` TINYINT(1) NOT NULL DEFAULT 0 AFTER `firma`, ADD KEY `idx_descartada` (`descartada`)",
                'descartada_en' => "ADD COLUMN `descartada_en` DATETIME NULL DEFAULT NULL AFTER `descartada`",
                'descartada_por' => "ADD COLUMN `descartada_por` INT UNSIGNED NULL DEFAULT NULL AFTER `descartada_en`",
                'motivo' => "ADD COLUMN `motivo` VARCHAR(255) NULL DEFAULT NULL AFTER `descartada_por`",
            ],
            'facturas_erp' => [
                'estado' => "ADD COLUMN `estado` ENUM('pendiente','asignada_semana') NOT NULL DEFAULT 'pendiente' AFTER `saldo_cambiado_en`, ADD KEY `idx_estado` (`estado`)",
                'semana_id' => "ADD COLUMN `semana_id` INT UNSIGNED NULL DEFAULT NULL AFTER `estado`, ADD KEY `idx_semana` (`semana_id`)",
                'porpagar_listado_id' => "ADD COLUMN `porpagar_listado_id` INT UNSIGNED NULL DEFAULT NULL AFTER `semana_id`, ADD KEY `idx_porpagar_listado` (`porpagar_listado_id`)",
                'asignada_semana_en' => "ADD COLUMN `asignada_semana_en` DATETIME NULL DEFAULT NULL AFTER `porpagar_listado_id`",
                // Foto del saldo al entrar al pago. El saldo del ERP baja a
                // cero cuando la factura se paga, y volver a cargar el reporte
                // después de pagar dejaría la semana entera en ₡0 sin que
                // nadie la hubiera tocado. Esto no es copiar el archivo: es el
                // saldo del propio ERP en el momento en que se decidió pagarlo.
                'saldo_pago' => "ADD COLUMN `saldo_pago` DECIMAL(18,2) NULL DEFAULT NULL AFTER `asignada_semana_en`",
                // El respaldo electrónico vive acá desde que el pago semanal
                // dejó de copiar las facturas a una tabla propia: la factura
                // del ERP es la fila, y el XML que la respalda es un dato suyo.
                'factura_xml_id' => "ADD COLUMN `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL AFTER `asignada_semana_en`, ADD KEY `idx_factura_xml` (`factura_xml_id`)",
                'estado_respaldo' => "ADD COLUMN `estado_respaldo` ENUM('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo' AFTER `factura_xml_id`, ADD KEY `idx_estado_respaldo` (`porpagar_listado_id`, `estado_respaldo`)",
                'diferencia' => "ADD COLUMN `diferencia` DECIMAL(18,2) NULL DEFAULT NULL AFTER `estado_respaldo`",
                'score_numero' => "ADD COLUMN `score_numero` DECIMAL(5,1) NULL DEFAULT NULL AFTER `diferencia`",
                'score_proveedor' => "ADD COLUMN `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL AFTER `score_numero`",
                'match_manual' => "ADD COLUMN `match_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `score_proveedor`",
            ],
        ];

        foreach ($pendientes as $tabla => $columnas) {
            foreach ($columnas as $columna => $sql) {
                $existe = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$tabla, $columna]
                );
                if ($existe === 0) {
                    $this->execute("ALTER TABLE `{$tabla}` {$sql}");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Importación
    // ------------------------------------------------------------------

    /**
     * Aplica el resultado del parser sobre lo ya guardado.
     *
     * @param array $facturas Filas de FacturasErpCsvParser.
     * @return array Resumen con carga_id, insertadas, actualizadas, sin_cambio.
     */
    public function importar(array $facturas, array $meta = [], array $cuadre = [], $usuarioId = null, array $incidencias = [])
    {
        $descuadres = $cuadre['descuadres'] ?? [];

        // El reporte del ERP no dice de qué empresa es: la sociedad viene de
        // quien lo sube (o, en su defecto, la seleccionada). Todas las filas
        // de esta carga quedan selladas con ella.
        $sociedadId = !empty($meta['sociedad_id']) ? (int) $meta['sociedad_id'] : $this->sociedadId();

        $cargaId = $this->insert(
            "INSERT INTO facturas_erp_cargas
                (archivo_origen, sociedad_id, impreso_en, rango_texto, filas_leidas,
                 totales_verificados, totales_descuadrados, cuadre_ok, advertencias, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (string) ($meta['archivo'] ?? 'listado.csv'),
                $sociedadId > 0 ? $sociedadId : null,
                $meta['impreso_en'] ?? null,
                $meta['rango_texto'] ?? null,
                count($facturas),
                (int) ($cuadre['verificados'] ?? 0),
                count($descuadres),
                empty($descuadres) ? 1 : 0,
                $descuadres ? json_encode($descuadres, JSON_UNESCAPED_UNICODE) : null,
                $usuarioId !== null ? (int) $usuarioId : null,
            ]
        );

        // Un solo SELECT trae el estado actual: comparar factura por factura
        // contra la base sería 5.700 consultas por carga. Acotado a la
        // sociedad de la carga: la `clave` (proveedor+documento+fecha) puede
        // repetirse entre empresas, y sin acotar una carga sobrescribiría los
        // saldos de la otra.
        $existentes = [];
        $paramsExistentes = [];
        $sqlExistentes = "SELECT id, clave, saldo FROM facturas_erp WHERE 1=1"
            . ($sociedadId > 0 ? ' AND sociedad_id = ?' : '');
        if ($sociedadId > 0) {
            $paramsExistentes[] = $sociedadId;
        }
        foreach ($this->fetchAll($sqlExistentes, $paramsExistentes) as $fila) {
            $existentes[$fila['clave']] = ['id' => (int) $fila['id'], 'saldo' => (float) $fila['saldo']];
        }

        $nuevas = [];
        $cambios = [];
        $sinCambio = 0;

        foreach ($facturas as $f) {
            $clave = (string) ($f['clave'] ?? '');
            if ($clave === '') {
                continue;
            }
            if (!isset($existentes[$clave])) {
                $nuevas[] = $f;
                continue;
            }
            $anterior = $existentes[$clave]['saldo'];
            if (abs($anterior - (float) $f['saldo']) < self::EPSILON_SALDO) {
                // Mismo saldo: la fila se deja exactamente como estaba.
                $sinCambio++;
                continue;
            }
            $cambios[] = ['id' => $existentes[$clave]['id'], 'anterior' => $anterior, 'factura' => $f];
        }

        // Cada saldo que cambió es una incidencia por derecho propio: es el
        // dato que el módulo existe para vigilar.
        foreach ($cambios as $c) {
            $f = $c['factura'];
            $incidencias[] = [
                'tipo' => 'saldo_modificado',
                'severidad' => 'aviso',
                'proveedor_codigo' => (string) $f['proveedor_codigo'],
                'proveedor_nombre' => (string) $f['proveedor_nombre'],
                'documento' => (string) $f['documento'],
                'clave' => (string) $f['clave'],
                'fecha_emision' => $f['fecha_emision'] ?? null,
                'monto' => (float) $f['monto'],
                'saldo_anterior' => $c['anterior'],
                'saldo_nuevo' => (float) $f['saldo'],
                'detalle' => sprintf(
                    'El saldo pasó de %s a %s (diferencia %s).',
                    number_format($c['anterior'], 2),
                    number_format((float) $f['saldo'], 2),
                    number_format((float) $f['saldo'] - $c['anterior'], 2)
                ),
            ];
        }

        $this->begin();
        try {
            $this->insertarNuevas($nuevas, $cargaId, $sociedadId);
            $this->actualizarCambiadas($cambios, $cargaId);
            $this->registrarIncidencias($incidencias, $cargaId);
            $this->execute(
                "UPDATE facturas_erp_cargas
                    SET insertadas = ?, actualizadas = ?, sin_cambio = ?, incidencias = ?
                  WHERE id = ?",
                [count($nuevas), count($cambios), $sinCambio, count($incidencias), $cargaId]
            );
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return [
            'carga_id' => (int) $cargaId,
            'insertadas' => count($nuevas),
            'actualizadas' => count($cambios),
            'sin_cambio' => $sinCambio,
            'cuadre_ok' => empty($descuadres),
            'descuadres' => $descuadres,
            'incidencias' => count($incidencias),
        ];
    }

    /**
     * Guarda las incidencias de una carga. Las que ya fueron descartadas
     * antes entran directamente marcadas como descartadas: quedan en el
     * historial (la carga las detectó) pero no vuelven a molestar.
     */
    private function registrarIncidencias(array $incidencias, $cargaId)
    {
        require_once __DIR__ . '/../helpers/FacturasErpCsvParser.php';
        $descartadas = $this->firmasDescartadas();

        foreach (array_chunk($incidencias, self::LOTE_INSERT) as $lote) {
            $marcas = [];
            $valores = [];
            foreach ($lote as $i) {
                $firma = FacturasErpCsvParser::firmaIncidencia($i);
                $yaDescartada = isset($descartadas[$firma]);
                $marcas[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $valores,
                    $cargaId,
                    (string) $i['tipo'],
                    (string) ($i['severidad'] ?? 'aviso'),
                    (string) ($i['proveedor_codigo'] ?? ''),
                    (string) ($i['proveedor_nombre'] ?? ''),
                    (string) ($i['documento'] ?? ''),
                    (string) ($i['clave'] ?? ''),
                    $i['fecha_emision'] ?? null,
                    isset($i['monto']) ? (float) $i['monto'] : null,
                    isset($i['saldo_anterior']) ? $i['saldo_anterior'] : null,
                    isset($i['saldo_nuevo']) ? $i['saldo_nuevo'] : null,
                    mb_substr((string) ($i['detalle'] ?? ''), 0, 500),
                    $firma,
                    $yaDescartada ? 1 : 0,
                    $yaDescartada ? date('Y-m-d H:i:s') : null,
                    $yaDescartada ? $descartadas[$firma] : null
                );
            }
            $this->execute(
                "INSERT INTO facturas_erp_incidencias
                    (carga_id, tipo, severidad, proveedor_codigo, proveedor_nombre,
                     documento, clave, fecha_emision, monto, saldo_anterior, saldo_nuevo,
                     detalle, firma, descartada, descartada_en, motivo)
                 VALUES " . implode(',', $marcas),
                $valores
            );
        }
    }

    /** firma => motivo, de todo lo descartado permanentemente. */
    private function firmasDescartadas()
    {
        $out = [];
        foreach ($this->fetchAll("SELECT firma, motivo FROM facturas_erp_descartes") as $f) {
            $out[$f['firma']] = $f['motivo'];
        }
        return $out;
    }

    private function insertarNuevas(array $nuevas, $cargaId, $sociedadId = 0)
    {
        $columnas = 'clave, sociedad_id, proveedor_codigo, proveedor_nombre, sucursal, tipo, documento,
                     numero_corto, fecha_emision, fecha_vence, origen, moneda, monto, saldo,
                     saldo_colones, carga_id';
        foreach (array_chunk($nuevas, self::LOTE_INSERT) as $lote) {
            $marcas = [];
            $valores = [];
            foreach ($lote as $f) {
                $marcas[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push(
                    $valores,
                    $f['clave'],
                    $sociedadId > 0 ? $sociedadId : null,
                    (string) $f['proveedor_codigo'],
                    (string) $f['proveedor_nombre'],
                    (string) $f['sucursal'],
                    (string) $f['tipo'],
                    (string) $f['documento'],
                    $f['numero_corto'],
                    $f['fecha_emision'],
                    $f['fecha_vence'],
                    (string) $f['origen'],
                    (string) $f['moneda'],
                    (float) $f['monto'],
                    (float) $f['saldo'],
                    $f['saldo_colones'],
                    $cargaId
                );
            }
            $this->execute(
                "INSERT INTO facturas_erp ({$columnas}) VALUES " . implode(',', $marcas),
                $valores
            );
        }
    }

    /**
     * Escribe los saldos que cambiaron respecto de la carga anterior.
     *
     * Por tandas y no de a una: la primera carga inserta todo y no pasa por
     * aquí, pero la segunda encuentra saldos distintos en buena parte de las
     * miles de filas del reporte, y con la base en el servidor cada UPDATE
     * cuesta un viaje de ida y vuelta.
     *
     * El ELSE de cada CASE deja intacta cualquier fila fuera de la tanda.
     */
    private function actualizarCambiadas(array $cambios, $cargaId)
    {
        $columnas = ['saldo', 'saldo_anterior', 'saldo_colones',
                     'proveedor_nombre', 'sucursal', 'fecha_vence'];

        foreach (array_chunk($cambios, self::LOTE_INSERT) as $tanda) {
            $valores = [];
            foreach ($tanda as $c) {
                $f = $c['factura'];
                $valores[] = [
                    'id'               => (int) $c['id'],
                    'saldo'            => (float) $f['saldo'],
                    'saldo_anterior'   => $c['anterior'],
                    'saldo_colones'    => $f['saldo_colones'],
                    'proveedor_nombre' => (string) $f['proveedor_nombre'],
                    'sucursal'         => (string) $f['sucursal'],
                    'fecha_vence'      => $f['fecha_vence'],
                ];
            }

            $sets = [];
            $params = [];
            foreach ($columnas as $columna) {
                $caso = "{$columna} = CASE id";
                foreach ($valores as $valor) {
                    $caso .= ' WHEN ? THEN ?';
                    $params[] = $valor['id'];
                    $params[] = $valor[$columna];
                }
                $sets[] = $caso . " ELSE {$columna} END";
            }

            $ids = array_column($valores, 'id');
            $this->execute(
                'UPDATE facturas_erp SET ' . implode(', ', $sets)
                . ', carga_cambio_id = ?, saldo_cambiado_en = NOW()'
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, [$cargaId], $ids)
            );
        }
    }

    // ------------------------------------------------------------------
    // Pago semanal: la factura del ERP ES la línea del pago
    // ------------------------------------------------------------------

    /**
     * Columnas mínimas para armar el índice con el que se resuelve un archivo
     * de pago. Se trae la tabla entera de la sociedad porque el archivo llega
     * con cientos de números y preguntar de a uno son cientos de viajes.
     */
    public function getIndicePago()
    {
        $params = [];
        $sql = "SELECT id, documento, numero_corto, proveedor_nombre, fecha_emision,
                       monto, saldo, semana_id, porpagar_listado_id
                  FROM facturas_erp
                 WHERE tipo IN ('F','FE','FACT')"
             . $this->condicionSociedad('', $params);
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Marca facturas del ERP como el pago de una semana.
     *
     * Es lo único que hace "cargar un listado" desde que el pago dejó de tener
     * facturas propias: el archivo dice cuáles, y acá quedan marcadas. No se
     * copia ni un dato del archivo — si el saldo del archivo no coincide con
     * el del ERP, manda el ERP y la carga lo reporta.
     */
    public function asignarAPago(array $ids, $semanaId, $listadoId)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }
        $asignadas = 0;
        foreach (array_chunk($ids, self::LOTE_INSERT) as $tanda) {
            $asignadas += (int) $this->execute(
                "UPDATE facturas_erp
                    SET estado = 'asignada_semana', semana_id = ?, porpagar_listado_id = ?,
                        asignada_semana_en = NOW(),
                        saldo_pago = COALESCE(saldo_pago, saldo)
                  WHERE id IN (" . implode(',', array_fill(0, count($tanda), '?')) . ')',
                array_merge([(int) $semanaId, (int) $listadoId], $tanda)
            );
        }
        return $asignadas;
    }

    /**
     * Suelta el saldo congelado de facturas que cambian de pago.
     *
     * `saldo_pago` es el saldo del momento en que la factura entró a un pago,
     * y manda mientras ese pago exista —si no, pagarla la borraría de la cola
     * aunque su respaldo siguiera faltando—. Cuando se mueve a otra semana el
     * congelado que traía es el de la semana que la soltó, y puede tener
     * semanas de viejo: se descongela para que asignarAPago() vuelva a
     * congelar el saldo de hoy, que es contra el que se está pagando ahora.
     *
     * Va aparte de asignarAPago() a propósito: dentro habría que decidir a
     * partir del `porpagar_listado_id` que ese mismo UPDATE está pisando, y
     * eso depende del orden de las asignaciones del SET.
     */
    public function descongelarSaldoPago(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }
        $sueltas = 0;
        foreach (array_chunk($ids, self::LOTE_INSERT) as $tanda) {
            $sueltas += (int) $this->execute(
                'UPDATE facturas_erp SET saldo_pago = NULL
                  WHERE id IN (' . implode(',', array_fill(0, count($tanda), '?')) . ')',
                $tanda
            );
        }
        return $sueltas;
    }

    /**
     * Saca facturas de un pago y las devuelve a "pendiente".
     *
     * El vínculo con el XML se borra a la vez: ese emparejamiento se hizo
     * dentro del pago y fuera de él no significa nada. El XML no se toca —
     * sigue existiendo y vuelve a estar disponible—.
     */
    public function quitarDePago(array $ids, $listadoId)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return 0;
        }
        $quitadas = 0;
        foreach (array_chunk($ids, self::LOTE_INSERT) as $tanda) {
            $quitadas += (int) $this->execute(
                "UPDATE facturas_erp
                    SET estado = 'pendiente', semana_id = NULL, porpagar_listado_id = NULL,
                        asignada_semana_en = NULL, saldo_pago = NULL, factura_xml_id = NULL,
                        estado_respaldo = 'sin_respaldo', diferencia = NULL,
                        score_numero = NULL, score_proveedor = NULL, match_manual = 0
                  WHERE porpagar_listado_id = ?
                    AND id IN (" . implode(',', array_fill(0, count($tanda), '?')) . ')',
                array_merge([(int) $listadoId], $tanda)
            );
        }
        return $quitadas;
    }

    /** Las facturas del pago con el XML que las respalda: el checklist. */
    public function getFacturasPago($listadoId, array $filtros = [])
    {
        $where = ['e.porpagar_listado_id = ?'];
        $params = [(int) $listadoId];

        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(e.documento LIKE ? OR e.numero_corto LIKE ? OR x.numero_factura_asistente LIKE ?'
                     . ' OR x.consecutivo_completo LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        // Por el código del ERP, que es de quién dice el listado que es la
        // factura. Por el emisor del comprobante no: una factura enlazada por
        // error a un XML de otro proveedor aparecería en la lista del otro, y
        // el checklist es justamente para encontrar esos errores, no para
        // repartirlos. La cédula llega igual: la clave la expande a los
        // códigos de ese proveedor.
        $proveedor = ProveedorCatalogo::condicion(
            $filtros['proveedor'] ?? '',
            ['codigo' => 'e.proveedor_codigo'],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
        }

        $sucursal = trim((string) ($filtros['sucursal'] ?? ''));
        if ($sucursal !== '') {
            $where[] = 'e.sucursal = ?';
            $params[] = $sucursal;
        }

        $estado = (string) ($filtros['estado'] ?? '');
        if (in_array($estado, ['respaldada', 'con_diferencia', 'sin_respaldo'], true)) {
            $where[] = 'e.estado_respaldo = ?';
            $params[] = $estado;
        }

        $vinculo = (string) ($filtros['vinculo'] ?? '');
        if ($vinculo === 'manual') {
            $where[] = 'e.factura_xml_id IS NOT NULL AND e.match_manual = 1';
        } elseif ($vinculo === 'automatico') {
            $where[] = 'e.factura_xml_id IS NOT NULL AND e.match_manual = 0';
        } elseif ($vinculo === 'sin_vinculo') {
            $where[] = 'e.factura_xml_id IS NULL';
        }

        foreach ([['fecha_desde', '>='], ['fecha_hasta', '<=']] as $rango) {
            $valor = trim((string) ($filtros[$rango[0]] ?? ''));
            if ($valor !== '') {
                $where[] = 'e.fecha_emision ' . $rango[1] . ' ?';
                $params[] = $valor;
            }
        }
        foreach ([['monto_desde', '>='], ['monto_hasta', '<=']] as $rango) {
            $valor = $filtros[$rango[0]] ?? '';
            if ($valor !== '' && $valor !== null && is_numeric($valor)) {
                $where[] = 'COALESCE(e.saldo_pago, e.saldo) ' . $rango[1] . ' ?';
                $params[] = (float) $valor;
            }
        }

        $sql = "SELECT e.id, e.documento, e.numero_corto, e.proveedor_codigo, e.proveedor_nombre,
                       e.sucursal, e.fecha_emision, e.fecha_vence, e.moneda, e.monto, e.saldo,
                       COALESCE(e.saldo_pago, e.saldo) AS saldo_pago,
                       e.factura_xml_id, e.estado_respaldo AS estado, e.diferencia,
                       e.score_numero, e.score_proveedor, e.match_manual,
                       x.numero_factura_asistente AS xml_numero,
                       x.consecutivo_completo AS xml_consecutivo,
                       x.total AS xml_total, x.fecha_emision AS xml_fecha,
                       x.ruta_xml, x.ruta_pdf, x.estado_pdf,
                       " . EstadoArchivo::columnaRecuperable('x.') . " AS recuperable,
                       p.razon_social AS xml_proveedor
                  FROM facturas_erp e
                  LEFT JOIN facturas_xml x ON x.id = e.factura_xml_id
                  LEFT JOIN proveedores p ON p.id = x.proveedor_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY FIELD(e.estado_respaldo, 'con_diferencia', 'sin_respaldo', 'respaldada'),
                          e.proveedor_nombre ASC, e.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /** Lo mínimo para emparejar: sin las columnas pesadas ni los JOIN. */
    public function getFacturasPagoParaMatching($listadoId)
    {
        // `proveedor_codigo` viaja porque es la llave de la guarda de cédula:
        // con él, el emparejador puede preguntar si el emisor del comprobante
        // es realmente el proveedor de esta línea antes de aceptarlo.
        return $this->fetchAll(
            "SELECT id, documento, numero_corto, proveedor_codigo, proveedor_nombre, monto, saldo,
                    COALESCE(saldo_pago, saldo) AS saldo_pago,
                    factura_xml_id, estado_respaldo AS estado, diferencia, match_manual
               FROM facturas_erp
              WHERE porpagar_listado_id = ?
              ORDER BY id ASC",
            [(int) $listadoId]
        ) ?: [];
    }

    public function getFacturaPago($id)
    {
        $fila = $this->fetchOne(
            "SELECT e.*, e.estado_respaldo AS estado_respaldo,
                    l.semana_id AS listado_semana_id
               FROM facturas_erp e
               LEFT JOIN porpagar_listados l ON l.id = e.porpagar_listado_id
              WHERE e.id = ? LIMIT 1",
            [(int) $id]
        );
        return $fila ?: null;
    }

    /** Escribe los emparejamientos de una verificación de una sola vez. */
    public function actualizarRespaldoLote(array $filas, $tam = 100)
    {
        $columnas = ['factura_xml_id', 'estado_respaldo', 'diferencia', 'score_numero', 'score_proveedor'];
        $afectadas = 0;
        foreach (array_chunk(array_values($filas), max(1, (int) $tam)) as $tanda) {
            $sets = [];
            $params = [];
            foreach ($columnas as $columna) {
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
                'UPDATE facturas_erp SET ' . implode(', ', $sets) . ', match_manual = 0'
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, $ids)
            );
        }
        return $afectadas;
    }

    /** Vínculo hecho a mano: la verificación ya no lo deshace. */
    public function actualizarRespaldoManual($erpId, $facturaXmlId, $estado, $diferencia)
    {
        return $this->execute(
            "UPDATE facturas_erp
                SET factura_xml_id = ?, estado_respaldo = ?, diferencia = ?,
                    score_numero = NULL, score_proveedor = NULL, match_manual = 1
              WHERE id = ?",
            [$facturaXmlId ?: null, (string) $estado, $diferencia, (int) $erpId]
        );
    }

    public function resumenRespaldoPago($listadoId)
    {
        $resumen = [];
        $filas = $this->fetchAll(
            "SELECT estado_respaldo AS estado, COUNT(*) AS n,
                    COALESCE(SUM(COALESCE(saldo_pago, saldo)), 0) AS monto
               FROM facturas_erp WHERE porpagar_listado_id = ? GROUP BY estado_respaldo",
            [(int) $listadoId]
        ) ?: [];
        foreach ($filas as $fila) {
            $resumen[$fila['estado']] = (int) $fila['n'];
            $resumen[$fila['estado'] . '_monto'] = (float) $fila['monto'];
        }
        return $resumen;
    }

    /**
     * Facturas del ERP que todavía no tienen comprobante, en tandas por id.
     *
     * Es la mitad que le faltaba a `engancharXml`. Aquella resuelve el XML que
     * llega y busca su factura; esta alimenta el caso contrario —la factura que
     * llega y busca el XML que ya estaba—, que era el que dejaba el reporte del
     * ERP entero sin cruzar hasta que alguien armara un pago semanal.
     *
     * No se acota a una carga ni a un pago a propósito: `factura_xml_id` es de
     * la fila, no del pago, así que cualquier factura sin comprobante es
     * candidata, venga de esta carga o de una vieja. Las manuales quedan fuera
     * —ese vínculo lo decidió una persona— y las que ya tienen XML también:
     * este camino solo agrega, nunca deshace.
     *
     * Se pagina por `id` y no por OFFSET porque las filas emparejadas salen del
     * conjunto al escribirse; con OFFSET, cada tanda se saltaría tantas filas
     * como haya enganchado la anterior.
     */
    public function getFacturasSinRespaldoParaMatching($desdeId = 0, $limite = 2000)
    {
        $params = [(int) $desdeId];
        $sql = "SELECT id, documento, numero_corto, proveedor_codigo, proveedor_nombre,
                       monto, saldo, COALESCE(saldo_pago, saldo) AS saldo_pago,
                       factura_xml_id, estado_respaldo AS estado, diferencia,
                       match_manual, porpagar_listado_id
                  FROM facturas_erp
                 WHERE id > ?
                   AND factura_xml_id IS NULL
                   AND match_manual = 0
                   AND tipo IN ('F','FE','FACT')"
             . $this->condicionSociedad('', $params) . '
                 ORDER BY id ASC
                 LIMIT ' . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Los XML que ya respaldan alguna factura.
     *
     * Un comprobante respalda una sola factura. Al cruzar toda la tabla de una
     * vez —y no un pago, donde la consulta ya venía acotada— hay que saber
     * cuáles están tomados antes de empezar, o la primera factura sin
     * comprobante se llevaría un XML que ya es de otra.
     */
    public function idsXmlEnganchados()
    {
        $params = [];
        $sql = 'SELECT DISTINCT factura_xml_id FROM facturas_erp
                 WHERE factura_xml_id IS NOT NULL'
             . $this->condicionSociedad('', $params);
        $filas = $this->fetchAll($sql, $params) ?: [];
        return array_values(array_map('intval', array_column($filas, 'factura_xml_id')));
    }

    /**
     * Engancha un comprobante recién importado con su factura del ERP.
     *
     * Es la pieza por la que el correo dejó de pedir semana. Antes, quien
     * importaba un XML tenía que saber a qué semana pertenecía y decirlo; si se
     * equivocaba o lo dejaba en blanco, la factura quedaba fuera del pago y
     * alguien tenía que ir a buscarla. Ahora el XML busca su fila en el ERP por
     * el consecutivo —una igualdad, no un parecido—, y si esa fila ya está en
     * un pago semanal, el comprobante hereda la semana y el pago se pone al día
     * solo. No hay nada que elegir porque no hay nada que decidir.
     *
     * Devuelve qué pasó, para que quien importe lo pueda contar.
     */
    public function engancharXml($xmlId, $consecutivo, $numeroCorto, $total)
    {
        $xmlId = (int) $xmlId;
        if ($xmlId <= 0) {
            return ['estado' => 'sin_erp'];
        }

        $candidatas = $this->candidatasParaXml($consecutivo, $numeroCorto);
        if (!$candidatas) {
            return ['estado' => 'sin_erp'];
        }

        // Las que ya tienen otro XML no se tocan: ese vínculo lo hizo la
        // verificación o una persona, y este documento no lo discute.
        $libres = array_values(array_filter($candidatas, function ($erp) use ($xmlId) {
            $actual = (int) ($erp['factura_xml_id'] ?? 0);
            return $actual === 0 || $actual === $xmlId;
        }));
        if (!$libres) {
            return ['estado' => 'ya_tomada'];
        }

        // El consecutivo es único por emisor, no globalmente: si hay varias,
        // el monto es lo único que las separa.
        if (count($libres) > 1) {
            $porMonto = array_values(array_filter($libres, function ($erp) use ($total) {
                return abs((float) $erp['monto'] - (float) $total) <= 1.0;
            }));
            if (count($porMonto) !== 1) {
                return ['estado' => 'ambigua', 'candidatas' => count($libres)];
            }
            $libres = $porMonto;
        }

        $erp = $libres[0];
        $diferencia = round((float) $erp['monto'] - (float) $total, 2);
        $estado = abs($diferencia) <= 1.0 ? 'respaldada' : 'con_diferencia';

        $this->execute(
            "UPDATE facturas_erp
                SET factura_xml_id = ?, estado_respaldo = ?, diferencia = ?,
                    score_numero = 100.0, score_proveedor = 100.0
              WHERE id = ? AND match_manual = 0",
            [$xmlId, $estado, $estado === 'con_diferencia' ? $diferencia : null, (int) $erp['id']]
        );

        // Si la factura ya estaba en un pago, el comprobante hereda su semana:
        // de ahí la lee el organizador para llevarlo a la carpeta del pago.
        $semanaId = (int) ($erp['semana_id'] ?? 0);
        if ($semanaId > 0) {
            $this->execute(
                'UPDATE facturas_xml SET semana_id = ? WHERE id = ? AND (semana_id IS NULL OR semana_id <> ?)',
                [$semanaId, $xmlId, $semanaId]
            );
        }

        return [
            'estado' => 'enganchada',
            'factura_erp_id' => (int) $erp['id'],
            'estado_respaldo' => $estado,
            'diferencia' => $diferencia,
            'semana_id' => $semanaId ?: null,
            'listado_id' => (int) ($erp['porpagar_listado_id'] ?? 0) ?: null,
            'en_pago' => !empty($erp['porpagar_listado_id']),
        ];
    }

    /**
     * XML de la semana del pago que no respaldan ninguna de sus facturas.
     *
     * Alimenta el botón "Sin coincidencia": son comprobantes que se importaron
     * y quedaron sueltos, casi siempre porque el número del ERP y el del XML no
     * se cruzaron. Se ofrecen para vincular a mano.
     */
    public function getXmlSinCoincidencia($listadoId)
    {
        $params = [(int) $listadoId, (int) $listadoId];
        $sql = "SELECT x.id, x.numero_factura_asistente, x.consecutivo_completo, x.total,
                       x.fecha_emision, p.razon_social AS proveedor_nombre
                  FROM facturas_xml x
                  LEFT JOIN proveedores p ON p.id = x.proveedor_id
                  JOIN porpagar_listados l ON l.id = ?
                 WHERE (x.tipo_documento IS NULL OR x.tipo_documento = 'FE')
                   AND x.semana_id = l.semana_id
                   AND NOT EXISTS (SELECT 1 FROM facturas_erp e
                                    WHERE e.porpagar_listado_id = ?
                                      AND e.factura_xml_id = x.id)"
             . $this->condicionSociedad('x.', $params) . "
                 ORDER BY p.razon_social ASC, x.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /** Pagos con facturas todavía sin respaldo, los más recientes. */
    public function idsPagosConFaltantes($limite = 3)
    {
        $filas = $this->fetchAll(
            "SELECT l.id
               FROM porpagar_listados l
              WHERE EXISTS (SELECT 1 FROM facturas_erp e
                             WHERE e.porpagar_listado_id = l.id
                               AND e.estado_respaldo = 'sin_respaldo')
              ORDER BY l.id DESC
              LIMIT " . max(1, (int) $limite)
        ) ?: [];
        return array_values(array_map('intval', array_column($filas, 'id')));
    }

    public function idsPagosDeSemana($semanaId)
    {
        $filas = $this->fetchAll(
            "SELECT id FROM porpagar_listados WHERE semana_id = ? ORDER BY id DESC",
            [(int) $semanaId]
        ) ?: [];
        return array_values(array_map('intval', array_column($filas, 'id')));
    }

    /** El pago al que pertenece una factura del ERP, si lo hay. */
    public function pagoDeFactura($erpId)
    {
        $fila = $this->fetchOne(
            "SELECT l.id, l.semana_id
               FROM facturas_erp e
               JOIN porpagar_listados l ON l.id = e.porpagar_listado_id
              WHERE e.id = ? LIMIT 1",
            [(int) $erpId]
        );
        return $fila ?: null;
    }

    public function contarPago($listadoId)
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM facturas_erp WHERE porpagar_listado_id = ?',
            [(int) $listadoId]
        );
    }

    /**
     * Pasa la semana del pago a los XML que lo respaldan.
     *
     * La semana del comprobante dejó de ser algo que alguien elige al
     * importarlo: se deduce del pago al que pertenece la factura del ERP que
     * respalda. Sigue viviendo en facturas_xml porque de ahí la lee el
     * organizador para saber a qué carpeta de pago va el par XML/PDF.
     */
    public function sincronizarSemanaXml($listadoId)
    {
        return $this->execute(
            "UPDATE facturas_xml x
               JOIN facturas_erp e ON e.factura_xml_id = x.id
               JOIN porpagar_listados l ON l.id = e.porpagar_listado_id
                SET x.semana_id = l.semana_id
              WHERE e.porpagar_listado_id = ?
                AND l.semana_id IS NOT NULL
                AND (x.semana_id IS NULL OR x.semana_id <> l.semana_id)",
            [(int) $listadoId]
        );
    }

    /**
     * Suelta la semana de los XML que ya no respaldan nada de este pago.
     * Es la mitad que falta de sincronizarSemanaXml: sin ella, una factura
     * quitada del pago se quedaría en la carpeta semanal para siempre.
     */
    public function soltarSemanaXml(array $xmlIds)
    {
        $xmlIds = array_values(array_unique(array_filter(array_map('intval', $xmlIds))));
        if (!$xmlIds) {
            return 0;
        }
        $sueltas = 0;
        foreach (array_chunk($xmlIds, self::LOTE_INSERT) as $tanda) {
            $marcas = implode(',', array_fill(0, count($tanda), '?'));
            $sueltas += (int) $this->execute(
                "UPDATE facturas_xml x
                    SET x.semana_id = NULL
                  WHERE x.id IN ({$marcas})
                    AND NOT EXISTS (SELECT 1 FROM facturas_erp e
                                     WHERE e.factura_xml_id = x.id
                                       AND e.porpagar_listado_id IS NOT NULL)",
                $tanda
            );
        }
        return $sueltas;
    }

    /** IDs de los XML que respaldan este pago, para reordenar sus archivos. */
    public function idsXmlDePago($listadoId)
    {
        $filas = $this->fetchAll(
            'SELECT DISTINCT factura_xml_id AS id FROM facturas_erp
              WHERE porpagar_listado_id = ? AND factura_xml_id IS NOT NULL',
            [(int) $listadoId]
        ) ?: [];
        return array_values(array_map('intval', array_column($filas, 'id')));
    }

    /**
     * ¿Qué factura del ERP corresponde a un XML recién importado?
     *
     * Es la vía por la que el correo dejó de pedir semana: se busca la factura
     * del ERP por el consecutivo del XML —o por su número corto— y, si esa
     * factura ya está en un pago semanal, el XML hereda la semana y el pago se
     * actualiza solo. Se devuelven las candidatas; quien llama decide con el
     * monto, que es lo único que puede separar dos facturas con el mismo
     * número de emisores distintos.
     */
    public function candidatasParaXml($consecutivo, $numeroCorto)
    {
        $llaves = [];
        $consecutivo = preg_replace('/\D+/', '', (string) $consecutivo);
        if (preg_match('/^\d{20}$/', $consecutivo) && ltrim($consecutivo, '0') !== '') {
            $llaves['consecutivo'] = $consecutivo;
        }
        $corto = (string) NumeroFactura::xmlOchoDigitos((string) $numeroCorto);
        if ($corto !== '' && ltrim($corto, '0') !== '') {
            $llaves['corto'] = $corto;
        }
        if (!$llaves) {
            return [];
        }

        $params = [];
        $condiciones = [];
        if (isset($llaves['consecutivo'])) {
            $condiciones[] = 'e.documento = ?';
            $params[] = $llaves['consecutivo'];
        }
        if (isset($llaves['corto'])) {
            // numero_corto viene NULL en uno de cada cuatro renglones; ahí la
            // llave corta se deriva del documento, igual que en el índice.
            $condiciones[] = "LPAD(RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(e.numero_corto,''), e.documento), '[^0-9]', ''), 8), 8, '0') = ?";
            $params[] = str_pad($corto, 8, '0', STR_PAD_LEFT);
        }

        $sql = "SELECT e.id, e.documento, e.numero_corto, e.proveedor_nombre, e.monto, e.saldo,
                       e.semana_id, e.porpagar_listado_id, e.factura_xml_id
                  FROM facturas_erp e
                 WHERE e.tipo IN ('F','FE','FACT') AND (" . implode(' OR ', $condiciones) . ')'
             . $this->condicionSociedad('e.', $params) . '
                 ORDER BY e.id ASC';
        return $this->fetchAll($sql, $params) ?: [];
    }


    /**
     * De las dos llaves que puede traer un número del pago semanal, cuál es.
     *
     * El listado del pago semanal escribe el documento de dos formas según de
     * dónde lo exportaron:
     *
     *   FACT-00200001010000045587-1377   consecutivo electrónico + correlativo
     *   FACT-12339                       número interno del ERP, sin consecutivo
     *   FACT-000…0000039547              el mismo interno, rellenado con ceros
     *
     * La primera cruza contra `documento`; las otras dos contra `numero_corto`,
     * que es la única columna del reporte que las conoce. Sin la segunda vía,
     * los listados viejos —que traen casi todo en forma corta— quedarían
     * marcados enteros como ausentes del ERP.
     */
    public static function llavesDeNumero($numero)
    {
        $llaves = ['consecutivo' => '', 'corto' => ''];
        if (!preg_match_all('/\d+/', (string) $numero, $m)) {
            return $llaves;
        }
        $trozos = $m[0];

        // Un consecutivo son exactamente 20 dígitos como grupo completo. Se
        // exige el largo del grupo, no "veinte dígitos en algún lado": el ERP
        // rellena sus números internos con ceros hasta cincuenta cifras
        // (…0000039547) y cualquier ventana de veinte dentro de eso parece un
        // consecutivo válido sin serlo.
        foreach ($trozos as $trozo) {
            if (strlen($trozo) === 20 && ltrim($trozo, '0') !== '') {
                $llaves['consecutivo'] = $trozo;
                $llaves['corto'] = NumeroFactura::xmlOchoDigitos($trozo);
                return $llaves;
            }
        }

        // Sin grupo de veinte: se toma el más largo y se reduce a la misma
        // llave de ocho dígitos. El recorte por la DERECHA es lo que salva los
        // casos torcidos —el ERP imprime consecutivos a los que les falta un
        // cero (0010000401000090388, diecinueve dígitos) y otros con relleno
        // hasta cincuenta cifras—: la cola es el número que el proveedor
        // realmente usó, la cabeza es formato. Recortar por la izquierda con
        // ltrim solo funciona cuando lo que sobra son ceros.
        $masLargo = '';
        foreach ($trozos as $trozo) {
            if (strlen($trozo) >= strlen($masLargo)) {
                $masLargo = $trozo;
            }
        }
        if ($masLargo !== '') {
            $llaves['corto'] = NumeroFactura::xmlOchoDigitos($masLargo);
        }
        return $llaves;
    }

    /**
     * Cuáles de esos números NO están en ningún listado de facturas del ERP.
     *
     * La regla del negocio es que el pago semanal no inventa facturas: paga
     * las que el ERP ya reportó. Se comprueba al cargar y no al cerrar —donde
     * ya existía— porque enterarse al final significa haber emparejado una
     * semana entera antes de descubrir que faltaba cargar el reporte.
     *
     * Una sola consulta y comparación en memoria: son miles de líneas contra
     * miles de facturas y la base está a 100 ms por consulta.
     */
    public function faltantesEnErp(array $numeros)
    {
        $numeros = array_values(array_filter(array_map('strval', $numeros), function ($n) {
            return trim($n) !== '';
        }));
        if (!$numeros) {
            return [];
        }

        // El alcance por sociedad lo pone condicionSociedad, igual que en el
        // resto de los modelos: es por usuario, no por parámetro. Filtrar
        // además por la sociedad del listado sería redundante cuando
        // coinciden y devolvería vacío —y por tanto "todas faltan"— cuando no.
        $params = [];
        $sql = 'SELECT documento, numero_corto FROM facturas_erp WHERE 1=1'
             . $this->condicionSociedad('', $params);

        $documentos = [];
        $cortos = [];
        foreach ($this->fetchAll($sql, $params) as $fila) {
            $doc = trim((string) $fila['documento']);
            if (preg_match('/^\d{20}$/', $doc)) {
                $documentos[$doc] = true;
            }
            // La llave corta se calcula desde el documento y no se lee de
            // numero_corto: el parser deja esa columna en NULL cuando el
            // documento no mide veinte dígitos, y así son uno de cada cuatro
            // renglones del ERP (números cortos de proveedor: 1002, 7606,
            // 0000666409). Leyéndola quedaban fuera del índice y el pago
            // semanal los denunciaba como ausentes estando cargados.
            $corto = trim((string) $fila['numero_corto']);
            if ($corto === '') {
                $corto = $doc;
            }
            if ($corto !== '' && ltrim($corto, '0') !== '') {
                $cortos[NumeroFactura::xmlOchoDigitos($corto)] = true;
            }
        }

        $faltantes = [];
        foreach ($numeros as $numero) {
            $llaves = self::llavesDeNumero($numero);
            if ($llaves['consecutivo'] !== '' && isset($documentos[$llaves['consecutivo']])) {
                continue;
            }
            if ($llaves['corto'] !== '' && isset($cortos[$llaves['corto']])) {
                continue;
            }
            $faltantes[] = $numero;
        }
        return array_values(array_unique($faltantes));
    }

    private static function muestraNumeros(array $numeros)
    {
        $numeros = array_values(array_unique(array_filter(array_map('strval', $numeros))));
        $muestra = array_slice($numeros, 0, 5);
        return implode(', ', $muestra) . (count($numeros) > count($muestra) ? ', …' : '');
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    /** Filtros admitidos: proveedor, sucursal, origen, estado, solo_saldo, texto, desde, hasta. */
    public function listar(array $filtros = [], $limite = 500, $offset = 0)
    {
        [$where, $params] = $this->condiciones($filtros);
        $limite = max(1, min(5000, (int) $limite));
        $offset = max(0, (int) $offset);

        return $this->fetchAll(
            "SELECT facturas_erp.*,
                    (SELECT s.nombre FROM semanas s WHERE s.id = facturas_erp.semana_id) AS semana_nombre
               FROM facturas_erp {$where}
              ORDER BY saldo DESC, proveedor_nombre ASC, fecha_emision ASC
              LIMIT {$limite} OFFSET {$offset}",
            $params
        );
    }

    public function resumen(array $filtros = [])
    {
        [$where, $params] = $this->condiciones($filtros);
        $fila = $this->fetchOne(
            "SELECT COUNT(*) AS facturas,
                    SUM(CASE WHEN saldo > 0 THEN 1 ELSE 0 END) AS con_saldo,
                    SUM(CASE WHEN estado = 'asignada_semana' THEN 1 ELSE 0 END) AS asignadas_semana,
                    COALESCE(SUM(monto), 0) AS monto,
                    COALESCE(SUM(saldo), 0) AS saldo,
                    COUNT(DISTINCT proveedor_codigo) AS proveedores
               FROM facturas_erp {$where}",
            $params
        );
        return [
            'facturas' => (int) ($fila['facturas'] ?? 0),
            'con_saldo' => (int) ($fila['con_saldo'] ?? 0),
            'asignadas_semana' => (int) ($fila['asignadas_semana'] ?? 0),
            'monto' => (float) ($fila['monto'] ?? 0),
            'saldo' => (float) ($fila['saldo'] ?? 0),
            'proveedores' => (int) ($fila['proveedores'] ?? 0),
        ];
    }

    public function contar(array $filtros = [])
    {
        [$where, $params] = $this->condiciones($filtros);
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM facturas_erp {$where}", $params);
    }

    private function condiciones(array $f)
    {
        $cond = [];
        $params = [];
        $this->filtrarPorSociedad($cond, $params);

        // El proveedor elegido puede tener más de un código en el ERP: la
        // condición los alcanza todos, que es lo que se espera al elegirlo.
        $proveedor = ProveedorCatalogo::condicion(
            $f['proveedor'] ?? '',
            ['codigo' => 'proveedor_codigo'],
            $params
        );
        if ($proveedor !== '') {
            $cond[] = $proveedor;
        }
        if (isset($f['sucursal']) && $f['sucursal'] !== '') {
            $cond[] = 'sucursal = ?';
            $params[] = (string) $f['sucursal'];
        }
        if (!empty($f['origen'])) {
            $cond[] = 'origen = ?';
            $params[] = (string) $f['origen'];
        }
        if (!empty($f['estado']) && in_array($f['estado'], ['pendiente', 'asignada_semana'], true)) {
            $cond[] = 'estado = ?';
            $params[] = (string) $f['estado'];
        }
        if (!empty($f['solo_saldo'])) {
            $cond[] = 'saldo > 0';
        }
        if (!empty($f['desde'])) {
            $cond[] = 'fecha_emision >= ?';
            $params[] = (string) $f['desde'];
        }
        if (!empty($f['hasta'])) {
            $cond[] = 'fecha_emision <= ?';
            $params[] = (string) $f['hasta'];
        }
        if (!empty($f['texto'])) {
            $cond[] = '(proveedor_nombre LIKE ? OR documento LIKE ? OR proveedor_codigo LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['texto']) . '%';
            array_push($params, $like, $like, $like);
        }

        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    /**
     * Valores distintos para poblar los selectores de la vista.
     *
     * El de proveedor ya no sale de aquí: lo arma `proveedoresParaFiltro()`
     * y lo agrupa el catálogo, porque un proveedor puede tener más de un
     * código y este listado no es el único que lo filtra.
     */
    public function opcionesFiltro()
    {
        return [
            'sucursales' => $this->fetchAll(
                "SELECT sucursal, COUNT(*) AS n FROM facturas_erp
                  GROUP BY sucursal ORDER BY sucursal ASC"
            ),
        ];
    }

    /**
     * Los proveedores del listado, como los espera ProveedorCatalogo.
     *
     * Solo el código y el nombre: el ERP no guarda la cédula en la factura.
     * La cédula la pone el catálogo desde el mapa código ↔ proveedor.
     */
    public function proveedoresParaFiltro()
    {
        $cond = [];
        $params = [];
        $this->filtrarPorSociedad($cond, $params);
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

        return $this->fetchAll(
            "SELECT proveedor_codigo AS codigo, MAX(proveedor_nombre) AS nombre, COUNT(*) AS n
               FROM facturas_erp {$where}
              GROUP BY proveedor_codigo",
            $params
        ) ?: [];
    }

    /** Proveedores y sucursales de un pago semanal, para su checklist. */
    public function dimensionesPago($listadoId)
    {
        $filas = $this->fetchAll(
            "SELECT e.proveedor_codigo AS codigo, MAX(e.proveedor_nombre) AS nombre,
                    MAX(p.rfc) AS cedula, COALESCE(e.sucursal, '') AS sucursal, COUNT(*) AS n
               FROM facturas_erp e
               LEFT JOIN facturas_xml x ON x.id = e.factura_xml_id
               LEFT JOIN proveedores p ON p.id = x.proveedor_id
              WHERE e.porpagar_listado_id = ?
              GROUP BY e.proveedor_codigo, COALESCE(e.sucursal, '')",
            [(int) $listadoId]
        ) ?: [];

        $sucursales = [];
        foreach ($filas as $fila) {
            $sucursal = trim((string) $fila['sucursal']);
            if ($sucursal === '') {
                continue;
            }
            $sucursales[$sucursal] = ($sucursales[$sucursal] ?? 0) + (int) $fila['n'];
        }
        ksort($sucursales, SORT_NATURAL | SORT_FLAG_CASE);

        return ['proveedores' => $filas, 'sucursales' => $sucursales];
    }

    // ------------------------------------------------------------------
    // Incidencias
    // ------------------------------------------------------------------

    /** Filtros: carga_id, tipo, severidad, proveedor, texto. */
    public function incidencias(array $filtros = [], $limite = 300, $offset = 0)
    {
        [$where, $params] = $this->condicionesIncidencia($filtros);
        $limite = max(1, min(2000, (int) $limite));
        $offset = max(0, (int) $offset);

        return $this->fetchAll(
            "SELECT i.*, c.archivo_origen, c.impreso_en, c.creado_en AS carga_creada
               FROM facturas_erp_incidencias i
               LEFT JOIN facturas_erp_cargas c ON c.id = i.carga_id
               {$where}
               ORDER BY i.carga_id DESC,
                        FIELD(i.severidad, 'alerta', 'aviso'),
                        i.tipo ASC, i.proveedor_nombre ASC
               LIMIT {$limite} OFFSET {$offset}",
            $params
        );
    }

    public function contarIncidencias(array $filtros = [])
    {
        [$where, $params] = $this->condicionesIncidencia($filtros);
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM facturas_erp_incidencias i {$where}", $params
        );
    }

    /** Cuántas hay de cada tipo, para las pastillas de filtro rápido. */
    public function resumenIncidencias(array $filtros = [])
    {
        $sinTipo = $filtros;
        unset($sinTipo['tipo']);
        [$where, $params] = $this->condicionesIncidencia($sinTipo);

        $out = [];
        foreach ($this->fetchAll(
            "SELECT tipo, severidad, COUNT(*) AS n FROM facturas_erp_incidencias i
             {$where} GROUP BY tipo, severidad ORDER BY n DESC", $params
        ) as $fila) {
            $out[$fila['tipo']] = ['n' => (int) $fila['n'], 'severidad' => $fila['severidad']];
        }
        return $out;
    }

    private function condicionesIncidencia(array $f)
    {
        $cond = [];
        $params = [];

        // Por omisión solo se ven las vigentes; 'descartadas' muestra las
        // ocultas y 'todas' no filtra por este criterio.
        $ver = (string) ($f['ver'] ?? 'vigentes');
        if ($ver === 'descartadas') {
            $cond[] = 'i.descartada = 1';
        } elseif ($ver !== 'todas') {
            $cond[] = 'i.descartada = 0';
        }

        if (!empty($f['carga_id'])) {
            $cond[] = 'i.carga_id = ?';
            $params[] = (int) $f['carga_id'];
        }
        if (!empty($f['tipo'])) {
            $cond[] = 'i.tipo = ?';
            $params[] = (string) $f['tipo'];
        }
        if (!empty($f['severidad'])) {
            $cond[] = 'i.severidad = ?';
            $params[] = (string) $f['severidad'];
        }
        $proveedor = ProveedorCatalogo::condicion(
            $f['proveedor'] ?? '',
            ['codigo' => 'i.proveedor_codigo'],
            $params
        );
        if ($proveedor !== '') {
            $cond[] = $proveedor;
        }
        if (!empty($f['texto'])) {
            $cond[] = '(i.proveedor_nombre LIKE ? OR i.documento LIKE ? OR i.detalle LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['texto']) . '%';
            array_push($params, $like, $like, $like);
        }

        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    /**
     * Descarta incidencias para que dejen de verse.
     *
     * Por defecto el descarte es permanente: se guarda la firma y las cargas
     * futuras que vuelvan a detectar lo mismo la marcan sola. Con
     * $permanente = false solo se oculta esta aparición concreta, y la
     * próxima carga la volverá a mostrar.
     */
    public function descartarIncidencias(array $ids, $motivo = '', $usuarioId = null, $permanente = true)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['descartadas' => 0, 'firmas' => 0];
        }
        $motivo = mb_substr(trim((string) $motivo), 0, 255);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $this->begin();
        try {
            $filas = $this->fetchAll(
                "SELECT DISTINCT firma, tipo, proveedor_codigo, proveedor_nombre, documento
                   FROM facturas_erp_incidencias WHERE id IN ({$marcas}) AND firma <> ''",
                $ids
            );

            $this->execute(
                "UPDATE facturas_erp_incidencias
                    SET descartada = 1, descartada_en = NOW(), descartada_por = ?, motivo = ?
                  WHERE id IN ({$marcas})",
                array_merge([$usuarioId !== null ? (int) $usuarioId : null, $motivo !== '' ? $motivo : null], $ids)
            );
            $descartadas = count($ids);

            $firmas = 0;
            if ($permanente) {
                foreach ($filas as $f) {
                    // Alcanza también a las apariciones de cargas anteriores:
                    // si algo ya no interesa, no interesa en ninguna carga.
                    $this->execute(
                        "UPDATE facturas_erp_incidencias
                            SET descartada = 1, descartada_en = NOW(), descartada_por = ?, motivo = ?
                          WHERE firma = ? AND descartada = 0",
                        [$usuarioId !== null ? (int) $usuarioId : null, $motivo !== '' ? $motivo : null, $f['firma']]
                    );
                    $this->execute(
                        "INSERT INTO facturas_erp_descartes
                            (firma, tipo, proveedor_codigo, proveedor_nombre, documento, motivo, usuario_id)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE motivo = VALUES(motivo), usuario_id = VALUES(usuario_id)",
                        [$f['firma'], $f['tipo'], $f['proveedor_codigo'], $f['proveedor_nombre'],
                         $f['documento'], $motivo !== '' ? $motivo : null,
                         $usuarioId !== null ? (int) $usuarioId : null]
                    );
                    $firmas++;
                }
            }
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['descartadas' => $descartadas, 'firmas' => $firmas];
    }

    /** Devuelve incidencias descartadas a la vista y levanta su descarte permanente. */
    public function restaurarIncidencias(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return ['restauradas' => 0];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $this->begin();
        try {
            $firmas = array_column($this->fetchAll(
                "SELECT DISTINCT firma FROM facturas_erp_incidencias
                  WHERE id IN ({$marcas}) AND firma <> ''", $ids
            ), 'firma');

            foreach ($firmas as $firma) {
                $this->execute("DELETE FROM facturas_erp_descartes WHERE firma = ?", [$firma]);
                $this->execute(
                    "UPDATE facturas_erp_incidencias
                        SET descartada = 0, descartada_en = NULL, descartada_por = NULL, motivo = NULL
                      WHERE firma = ?",
                    [$firma]
                );
            }
            $this->execute(
                "UPDATE facturas_erp_incidencias
                    SET descartada = 0, descartada_en = NULL, descartada_por = NULL, motivo = NULL
                  WHERE id IN ({$marcas})",
                $ids
            );
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['restauradas' => count($ids)];
    }

    /** Ids que cumplen un filtro, para el botón "descartar todas las del filtro". */
    public function idsIncidencias(array $filtros, $tope = 5000)
    {
        [$where, $params] = $this->condicionesIncidencia($filtros);
        $tope = max(1, min(20000, (int) $tope));
        return array_map('intval', array_column(
            $this->fetchAll("SELECT i.id FROM facturas_erp_incidencias i {$where} LIMIT {$tope}", $params),
            'id'
        ));
    }

    /**
     * Proveedores que aparecen en las incidencias, para el selector.
     *
     * Con los mismos filtros que la pantalla —menos el de proveedor, que es
     * el que se está eligiendo—: contando siempre todo, el desplegable ofrecía
     * proveedores cuyas incidencias estaban descartadas y prometía cuarenta y
     * dos donde después no aparecía ninguna.
     */
    public function proveedoresConIncidencia(array $filtros = [])
    {
        $sinProveedor = $filtros;
        unset($sinProveedor['proveedor']);
        [$where, $params] = $this->condicionesIncidencia($sinProveedor);
        $where = $where === '' ? "WHERE i.proveedor_codigo <> ''" : $where . " AND i.proveedor_codigo <> ''";

        return $this->fetchAll(
            "SELECT i.proveedor_codigo AS codigo, MAX(i.proveedor_nombre) AS nombre, COUNT(*) AS n
               FROM facturas_erp_incidencias i
               {$where}
              GROUP BY i.proveedor_codigo",
            $params
        ) ?: [];
    }

    public function cargas($limite = 20)
    {
        $limite = max(1, min(100, (int) $limite));
        return $this->fetchAll(
            "SELECT * FROM facturas_erp_cargas ORDER BY id DESC LIMIT {$limite}"
        );
    }

    public function ultimaCarga()
    {
        return $this->fetchOne("SELECT * FROM facturas_erp_cargas ORDER BY id DESC LIMIT 1");
    }

    // ------------------------------------------------------------------

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return true;
    }
}
