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

class FacturaErp extends Model
{
    // El reporte del ERP no dice a qué empresa pertenece, así que la sociedad
    // se pregunta al cargarlo y las filas la heredan de su carga. Acotar aquí
    // es lo que impide que el cierre semanal de una sociedad empareje contra
    // una factura del ERP de otra.
    use AlcanceSociedad;

    protected $table = 'facturas_erp';

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
            KEY `idx_porpagar_listado` (`porpagar_listado_id`)
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

    private function actualizarCambiadas(array $cambios, $cargaId)
    {
        foreach ($cambios as $c) {
            $f = $c['factura'];
            $this->execute(
                "UPDATE facturas_erp
                    SET saldo = ?, saldo_anterior = ?, saldo_colones = ?,
                        proveedor_nombre = ?, sucursal = ?, fecha_vence = ?,
                        carga_cambio_id = ?, saldo_cambiado_en = NOW()
                  WHERE id = ?",
                [
                    (float) $f['saldo'],
                    $c['anterior'],
                    $f['saldo_colones'],
                    (string) $f['proveedor_nombre'],
                    (string) $f['sucursal'],
                    $f['fecha_vence'],
                    $cargaId,
                    $c['id'],
                ]
            );
        }
    }

    // ------------------------------------------------------------------
    // Cierre del pago semanal
    // ------------------------------------------------------------------

    /**
     * Cierra un pago semanal y asigna sus facturas emparejadas al registro
     * correspondiente del ERP.
     *
     * La identidad es el CONSECUTIVO ELECTRÓNICO DE 20 DÍGITOS: el ERP lo
     * imprime tal cual en `documento` y en el XML son los dígitos 22..41 de
     * la clave de 50 (idéntico a `consecutivo_completo`). No se compara la
     * fecha: `facturas_erp.fecha_emision` es la fecha en que el ERP registró
     * el documento, no la de emisión del comprobante, y difiere de la del XML
     * en días o semanas. Tampoco se compara el proveedor: `proveedor_codigo`
     * es un código interno del ERP (140000003), nunca la cédula.
     *
     * El consecutivo es único por emisor, no globalmente, así que cuando dos
     * proveedores coinciden se desempata por monto y, si aun así empatan
     * (documento registrado dos veces en el ERP), por saldo pendiente.
     *
     * El cierre es atómico: si falta un XML, una factura ERP o aparece una
     * asignación previa a otra semana, no se guarda ningún cambio.
     */
    public function cerrarPagoSemanal($listadoId, $usuarioId = null)
    {
        require_once __DIR__ . '/../helpers/FacturaMatcher.php';

        $listadoId = (int) $listadoId;
        if ($listadoId <= 0) {
            throw new Exception('El pago semanal no es válido.');
        }

        $this->begin();
        try {
            $listado = $this->fetchOne(
                "SELECT * FROM porpagar_listados WHERE id = ? LIMIT 1 FOR UPDATE",
                [$listadoId]
            );
            if (!$listado) {
                throw new Exception('El pago semanal no existe.');
            }

            $semanaId = (int) ($listado['semana_id'] ?? 0);
            if ($semanaId <= 0) {
                throw new Exception('Asigna una semana antes de cerrar el pago semanal.');
            }

            if (($listado['estado'] ?? 'abierto') === 'cerrado') {
                $asignadas = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM porpagar_facturas
                      WHERE listado_id = ? AND factura_erp_id IS NOT NULL",
                    [$listadoId]
                );
                $this->commit();
                return ['asignadas' => $asignadas, 'ya_cerrado' => true, 'semana_id' => $semanaId];
            }

            $conteo = $this->fetchOne(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN factura_xml_id IS NULL THEN 1 ELSE 0 END) AS sin_xml
                   FROM porpagar_facturas WHERE listado_id = ?",
                [$listadoId]
            );
            $total = (int) ($conteo['total'] ?? 0);
            $sinXml = (int) ($conteo['sin_xml'] ?? 0);
            if ($total <= 0) {
                throw new Exception('El pago semanal está vacío.');
            }
            if ($sinXml > 0) {
                throw new Exception("No se puede cerrar: {$sinXml} factura(s) todavía no tienen XML emparejado.");
            }

            $lineas = $this->fetchAll(
                "SELECT pf.id AS linea_id, pf.numero, pf.factura_xml_id, fx.total AS xml_total,
                        COALESCE(NULLIF(fx.consecutivo_completo, ''), SUBSTRING(fx.clave, 22, 20)) AS consecutivo
                   FROM porpagar_facturas pf
                   JOIN facturas_xml fx ON fx.id = pf.factura_xml_id
                  WHERE pf.listado_id = ?
                  ORDER BY pf.id ASC",
                [$listadoId]
            );
            if (count($lineas) !== $total) {
                throw new Exception('No se puede cerrar: hay facturas cuyo XML vinculado ya no existe.');
            }

            $consecutivos = array_values(array_unique(array_filter(array_map(function ($linea) {
                return self::consecutivoValido($linea['consecutivo'] ?? '');
            }, $lineas))));
            $porConsecutivo = [];
            foreach ($this->facturasErpPorConsecutivos($consecutivos, true) as $factura) {
                $porConsecutivo[(string) $factura['documento']][] = $factura;
            }

            $asignaciones = [];
            $usadas = [];
            $faltantes = [];
            $ambiguas = [];
            $conflictos = [];

            foreach ($lineas as $linea) {
                $consecutivo = self::consecutivoValido($linea['consecutivo'] ?? '');
                $etiqueta = trim((string) ($linea['numero'] ?? $consecutivo));

                $exactas = $consecutivo === '' ? [] : ($porConsecutivo[$consecutivo] ?? []);

                // El consecutivo solo es único por emisor: dos proveedores
                // distintos pueden repetirlo. El monto los separa.
                if (count($exactas) > 1) {
                    $totalXml = (float) ($linea['xml_total'] ?? 0);
                    $porMonto = array_values(array_filter($exactas, function ($erp) use ($totalXml) {
                        return abs((float) $erp['monto'] - $totalXml) <= FacturaMatcher::TOLERANCIA_CRC;
                    }));
                    if ($porMonto) {
                        $exactas = $porMonto;
                    }
                }

                // Mismo documento y mismo monto = el ERP lo registró dos
                // veces; la que sigue debiéndose es la que se está pagando.
                if (count($exactas) > 1) {
                    $pendientes = array_values(array_filter($exactas, function ($erp) {
                        return (float) $erp['saldo'] > 0;
                    }));
                    if ($pendientes) {
                        $exactas = $pendientes;
                    }
                }

                if (count($exactas) === 0) {
                    $faltantes[] = $etiqueta;
                    continue;
                }
                if (count($exactas) > 1) {
                    $ambiguas[] = $etiqueta;
                    continue;
                }

                $erp = $exactas[0];
                $erpId = (int) $erp['id'];
                $otroListado = (int) ($erp['porpagar_listado_id'] ?? 0);
                $otraSemana = (int) ($erp['semana_id'] ?? 0);
                if (($otroListado > 0 && $otroListado !== $listadoId)
                    || ($otraSemana > 0 && $otraSemana !== $semanaId)
                    || isset($usadas[$erpId])) {
                    $conflictos[] = $etiqueta;
                    continue;
                }

                $usadas[$erpId] = true;
                $asignaciones[] = ['linea_id' => (int) $linea['linea_id'], 'erp_id' => $erpId];
            }

            if ($faltantes || $ambiguas || $conflictos) {
                $partes = [];
                if ($faltantes) {
                    $partes[] = count($faltantes) . ' no están en Facturas ERP (' . self::muestraNumeros($faltantes) . ')';
                }
                if ($ambiguas) {
                    $partes[] = count($ambiguas) . ' tienen más de una coincidencia ERP (' . self::muestraNumeros($ambiguas) . ')';
                }
                if ($conflictos) {
                    $partes[] = count($conflictos) . ' ya están asignadas a otro pago (' . self::muestraNumeros($conflictos) . ')';
                }
                throw new Exception('No se puede cerrar: ' . implode('; ', $partes) . '.');
            }

            foreach ($asignaciones as $asignacion) {
                $this->execute(
                    "UPDATE porpagar_facturas SET factura_erp_id = ? WHERE id = ? AND listado_id = ?",
                    [$asignacion['erp_id'], $asignacion['linea_id'], $listadoId]
                );
                $this->execute(
                    "UPDATE facturas_erp
                        SET estado = 'asignada_semana', semana_id = ?, porpagar_listado_id = ?,
                            asignada_semana_en = NOW()
                      WHERE id = ?",
                    [$semanaId, $listadoId, $asignacion['erp_id']]
                );
            }

            $cerradas = $this->execute(
                "UPDATE porpagar_listados
                    SET estado = 'cerrado', cerrado_en = NOW(), cerrado_por = ?
                  WHERE id = ? AND estado = 'abierto'",
                [$usuarioId !== null ? (int) $usuarioId : null, $listadoId]
            );
            if ($cerradas !== 1) {
                throw new Exception('El pago semanal cambió mientras se intentaba cerrar.');
            }

            $this->commit();
            return [
                'asignadas' => count($asignaciones),
                'ya_cerrado' => false,
                'semana_id' => $semanaId,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** Trae candidatas en lotes para no exceder el límite de parámetros SQL. */
    private function facturasErpPorConsecutivos(array $consecutivos, $bloquear = false)
    {
        $salida = [];
        foreach (array_chunk($consecutivos, 500) as $lote) {
            if (!$lote) {
                continue;
            }
            $marcas = implode(',', array_fill(0, count($lote), '?'));
            $params = $lote;
            $sql = "SELECT id, documento, numero_corto, fecha_emision, monto, saldo,
                           proveedor_codigo, proveedor_nombre,
                           estado, semana_id, porpagar_listado_id
                      FROM facturas_erp
                     WHERE tipo IN ('F','FE','FACT') AND documento IN ({$marcas})"
                 . $this->condicionSociedad('', $params);
            if ($bloquear) {
                $sql .= ' FOR UPDATE';
            }
            $salida = array_merge($salida, $this->fetchAll($sql, $params));
        }
        return $salida;
    }

    /** El consecutivo electrónico son exactamente 20 dígitos; nada más cruza. */
    private static function consecutivoValido($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{20}$/', $valor) ? $valor : '';
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

        if (!empty($f['proveedor'])) {
            $cond[] = 'proveedor_codigo = ?';
            $params[] = (string) $f['proveedor'];
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

    /** Valores distintos para poblar los selectores de la vista. */
    public function opcionesFiltro()
    {
        return [
            'proveedores' => $this->fetchAll(
                "SELECT proveedor_codigo, proveedor_nombre, COUNT(*) AS n
                   FROM facturas_erp GROUP BY proveedor_codigo, proveedor_nombre
                   ORDER BY proveedor_nombre ASC"
            ),
            'sucursales' => $this->fetchAll(
                "SELECT sucursal, COUNT(*) AS n FROM facturas_erp
                  GROUP BY sucursal ORDER BY sucursal ASC"
            ),
        ];
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
        if (!empty($f['proveedor'])) {
            $cond[] = 'i.proveedor_codigo = ?';
            $params[] = (string) $f['proveedor'];
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

    /** Proveedores que aparecen en las incidencias, para el selector. */
    public function proveedoresConIncidencia()
    {
        return $this->fetchAll(
            "SELECT proveedor_codigo, MAX(proveedor_nombre) AS proveedor_nombre, COUNT(*) AS n
               FROM facturas_erp_incidencias
              WHERE proveedor_codigo <> ''
              GROUP BY proveedor_codigo ORDER BY proveedor_nombre ASC"
        );
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
