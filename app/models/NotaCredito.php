<?php
/**
 * Persistencia del módulo "Notas de crédito".
 */
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/ProveedorCatalogo.php';

class NotaCredito extends Model
{
    protected $table = 'notas_credito_listados';

    /** Dos saldos con una diferencia menor a medio centavo son el mismo. */
    private const EPSILON_SALDO = 0.005;

    /** Tamaño de las escrituras agrupadas del importador. */
    private const LOTE_IMPORTACION = 300;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            // Una carga es el archivo que se recibió. Vive aparte del listado
            // canónico porque el archivo es una foto y las líneas son el
            // estado acumulado: exactamente la separación que usa Facturas ERP.
            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_cargas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                listado_id INT UNSIGNED NULL,
                sociedad_id INT UNSIGNED NOT NULL,
                listado_legacy_id INT UNSIGNED NULL,
                archivo_origen VARCHAR(255) NOT NULL,
                archivo_ruta VARCHAR(500) NULL,
                archivo_hash CHAR(64) NOT NULL,
                empresa_reporte VARCHAR(255) NULL,
                periodo_desde DATE NULL,
                periodo_hasta DATE NULL,
                filas_leidas INT UNSIGNED NOT NULL DEFAULT 0,
                insertadas INT UNSIGNED NOT NULL DEFAULT 0,
                actualizadas INT UNSIGNED NOT NULL DEFAULT 0,
                sin_cambio INT UNSIGNED NOT NULL DEFAULT 0,
                recuperadas INT UNSIGNED NOT NULL DEFAULT 0,
                filas_invalidas INT UNSIGNED NOT NULL DEFAULT 0,
                usuario_id INT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_nc_carga_legacy (listado_legacy_id),
                KEY idx_nc_carga_sociedad (sociedad_id, creado_en),
                KEY idx_nc_carga_listado (listado_id),
                KEY idx_nc_carga_hash (archivo_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_listados (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sociedad_id INT UNSIGNED NOT NULL,
                nombre VARCHAR(255) NOT NULL,
                empresa_reporte VARCHAR(255) NULL,
                periodo_desde DATE NULL,
                periodo_hasta DATE NULL,
                archivo_origen VARCHAR(255) NOT NULL,
                archivo_ruta VARCHAR(500) NOT NULL,
                archivo_hash CHAR(64) NOT NULL,
                total_lineas INT UNSIGNED NOT NULL DEFAULT 0,
                fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_nc_archivo_hash (archivo_hash),
                KEY idx_nc_listado_sociedad (sociedad_id, fecha_subida)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_lineas (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                listado_id INT UNSIGNED NOT NULL,
                listado_origen_id INT UNSIGNED NULL,
                fila_origen INT UNSIGNED NOT NULL,
                proveedor_codigo VARCHAR(50) NULL,
                proveedor_nombre VARCHAR(255) NOT NULL,
                sucursal VARCHAR(150) NULL,
                documento VARCHAR(255) NOT NULL,
                clase ENUM('directa','costo','cambio','ajuste','revisar') NOT NULL DEFAULT 'revisar',
                fecha DATE NOT NULL,
                nc_proveedor VARCHAR(100) NULL,
                fecha_nc_proveedor DATE NULL,
                entrada_asociada VARCHAR(255) NULL,
                moneda CHAR(3) NOT NULL,
                monto DECIMAL(18,2) NOT NULL,
                saldo DECIMAL(18,2) NOT NULL DEFAULT 0,
                saldo_anterior DECIMAL(18,2) NULL,
                monto_conversion DECIMAL(18,2) NOT NULL DEFAULT 0,
                datos_origen LONGTEXT NULL,
                carga_id INT UNSIGNED NULL,
                carga_cambio_id INT UNSIGNED NULL,
                saldo_cambiado_en DATETIME NULL,
                factura_xml_id INT UNSIGNED NULL,
                estado ENUM('sin_respaldo','coincide','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
                diferencia DECIMAL(18,2) NULL,
                metodo_match ENUM('ninguno','numero','referencia','atributos','manual') NOT NULL DEFAULT 'ninguno',
                score_proveedor DECIMAL(5,1) NULL,
                match_manual TINYINT(1) NOT NULL DEFAULT 0,
                bloqueo_automatico TINYINT(1) NOT NULL DEFAULT 0,
                motivo_match VARCHAR(255) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_nc_xml_por_listado (listado_id, factura_xml_id),
                KEY idx_nc_linea_listado_estado (listado_id, estado),
                KEY idx_nc_linea_listado_origen (listado_origen_id),
                KEY idx_nc_lineas_clase (listado_id, clase, estado),
                KEY idx_nc_linea_documento (documento),
                KEY idx_nc_linea_nc_proveedor (nc_proveedor),
                KEY idx_nc_linea_factura_xml (factura_xml_id),
                KEY idx_nc_linea_carga (carga_id),
                KEY idx_nc_linea_carga_cambio (carga_cambio_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_verificaciones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                listado_id INT UNSIGNED NOT NULL,
                origen VARCHAR(30) NOT NULL DEFAULT 'automatico',
                fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_fin DATETIME NULL,
                coincide INT UNSIGNED NOT NULL DEFAULT 0,
                con_diferencia INT UNSIGNED NOT NULL DEFAULT 0,
                sin_respaldo INT UNSIGNED NOT NULL DEFAULT 0,
                cantidad_cambios INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_nc_verificacion_listado_fecha (listado_id, fecha_inicio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS notas_credito_historial (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                verificacion_id BIGINT UNSIGNED NOT NULL,
                listado_id INT UNSIGNED NOT NULL,
                linea_id INT UNSIGNED NULL,
                fila_origen INT UNSIGNED NULL,
                documento VARCHAR(255) NULL,
                proveedor_nombre VARCHAR(255) NULL,
                nc_proveedor VARCHAR(100) NULL,
                moneda CHAR(3) NULL,
                estado_anterior VARCHAR(30) NOT NULL,
                estado_nuevo VARCHAR(30) NOT NULL,
                factura_xml_id_anterior INT UNSIGNED NULL,
                factura_xml_id_nuevo INT UNSIGNED NULL,
                diferencia_anterior DECIMAL(18,2) NULL,
                diferencia_nueva DECIMAL(18,2) NULL,
                motivo_anterior VARCHAR(255) NULL,
                motivo_nuevo VARCHAR(255) NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_nc_historial_verificacion (verificacion_id, id),
                KEY idx_nc_historial_linea (linea_id, fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // database/migration_notas_credito.sql cubre instalaciones sin DDL en runtime.
        }

        $this->agregarColumnasImportacion();
        $this->sembrarCargasHistoricas();

        // 'referencia' es el método que añadió el puente NC → factura acreditada
        // (InformacionReferencia del XML). En una instalación ya creada el
        // CREATE TABLE de arriba no la agrega, así que se amplía el ENUM.
        try {
            $columna = $this->fetchOne("SHOW COLUMNS FROM notas_credito_lineas LIKE 'metodo_match'");
            if ($columna && strpos((string) ($columna['Type'] ?? ''), "'referencia'") === false) {
                $this->execute("ALTER TABLE notas_credito_lineas
                                MODIFY COLUMN metodo_match
                                ENUM('ninguno','numero','referencia','atributos','manual')
                                NOT NULL DEFAULT 'ninguno'");
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * CREATE TABLE IF NOT EXISTS no modifica instalaciones ya creadas.
     * Estas columnas convierten las líneas en un maestro acumulativo y dejan
     * trazabilidad del saldo, sin tocar los campos del emparejamiento XML.
     */
    private function agregarColumnasImportacion()
    {
        $columnas = [
            'listado_origen_id' => "ADD COLUMN listado_origen_id INT UNSIGNED NULL AFTER listado_id, ADD KEY idx_nc_linea_listado_origen (listado_origen_id)",
            'saldo_anterior' => "ADD COLUMN saldo_anterior DECIMAL(18,2) NULL AFTER saldo",
            'carga_id' => "ADD COLUMN carga_id INT UNSIGNED NULL AFTER datos_origen, ADD KEY idx_nc_linea_carga (carga_id)",
            'carga_cambio_id' => "ADD COLUMN carga_cambio_id INT UNSIGNED NULL AFTER carga_id, ADD KEY idx_nc_linea_carga_cambio (carga_cambio_id)",
            'saldo_cambiado_en' => "ADD COLUMN saldo_cambiado_en DATETIME NULL AFTER carga_cambio_id",
            'creado_en' => "ADD COLUMN creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER motivo_match",
        ];

        try {
            foreach ($columnas as $nombre => $ddl) {
                $existe = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'notas_credito_lineas' AND COLUMN_NAME = ?",
                    [$nombre]
                );
                if ($existe === 0) {
                    $this->execute("ALTER TABLE notas_credito_lineas {$ddl}");
                }
            }

            // Una migración interrumpida pudo alcanzar a crear la columna y
            // no su índice. Se comprueban aparte para que el siguiente intento
            // termine el esquema en vez de darlo por bueno solo por la columna.
            $indicesLineas = [
                'idx_nc_linea_listado_origen' => 'listado_origen_id',
                'idx_nc_linea_carga' => 'carga_id',
                'idx_nc_linea_carga_cambio' => 'carga_cambio_id',
            ];
            foreach ($indicesLineas as $nombre => $columna) {
                $existe = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'notas_credito_lineas' AND INDEX_NAME = ?",
                    [$nombre]
                );
                if ($existe === 0) {
                    $this->execute(
                        "ALTER TABLE notas_credito_lineas ADD KEY {$nombre} ({$columna})"
                    );
                }
            }

            // El hash servía para impedir una segunda foto. En el modelo
            // acumulativo una foto repetida es válida y simplemente produce
            // cero cambios; el hash queda como índice de auditoría, no UNIQUE.
            $indice = $this->fetchOne(
                "SELECT NON_UNIQUE FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_listados'
                    AND INDEX_NAME = 'uk_nc_archivo_hash' LIMIT 1"
            );
            $indiceNormal = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_listados'
                    AND INDEX_NAME = 'idx_nc_archivo_hash'"
            );
            if ($indice && (int) $indice['NON_UNIQUE'] === 0) {
                $this->execute(
                    'ALTER TABLE notas_credito_listados DROP INDEX uk_nc_archivo_hash'
                    . ($indiceNormal === 0 ? ', ADD KEY idx_nc_archivo_hash (archivo_hash)' : '')
                );
            } elseif ($indiceNormal === 0) {
                $this->execute(
                    'ALTER TABLE notas_credito_listados ADD KEY idx_nc_archivo_hash (archivo_hash)'
                );
            }
        } catch (Throwable $e) {
            // La migración SQL formal cubre servidores donde la aplicación no
            // tiene permiso de ALTER. Las lecturas del módulo siguen funcionando.
        }
    }

    /**
     * Convierte las antiguas cabeceras (una por foto) en historial de cargas.
     * Es idempotente por listado_legacy_id y no mueve ni elimina líneas.
     */
    private function sembrarCargasHistoricas()
    {
        try {
            $this->execute(
                "INSERT IGNORE INTO notas_credito_cargas
                    (listado_id, sociedad_id, listado_legacy_id, archivo_origen, archivo_ruta,
                     archivo_hash, empresa_reporte, periodo_desde, periodo_hasta,
                     filas_leidas, insertadas, creado_en)
                 SELECT l.id, l.sociedad_id, l.id, l.archivo_origen, l.archivo_ruta,
                        l.archivo_hash, l.empresa_reporte, l.periodo_desde, l.periodo_hasta,
                        l.total_lineas, l.total_lineas, l.fecha_subida
                   FROM notas_credito_listados l
                  WHERE NOT EXISTS (
                        SELECT 1 FROM notas_credito_cargas existente
                         WHERE existente.listado_id = l.id
                  )"
            );
        } catch (Throwable $e) {
            // Tabla aún no migrada o permisos limitados: se reintentará cuando
            // cambie el esquema/código o mediante la migración manual.
        }
    }

    public function begin()
    {
        return self::getDB()->beginTransaction();
    }

    public function commit()
    {
        return self::getDB()->commit();
    }

    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return false;
    }

    public function inTransaction()
    {
        return self::getDB()->inTransaction();
    }

    /** Serializa las decisiones de matching de un mismo acumulado. */
    public function bloquearListado($listadoId)
    {
        return $this->fetchOne(
            'SELECT id FROM notas_credito_listados WHERE id = ? FOR UPDATE',
            [(int) $listadoId]
        );
    }

    /** Bloquea una línea después de su cabecera; ese orden evita deadlocks. */
    public function bloquearLinea($lineaId)
    {
        return $this->fetchOne(
            'SELECT id, listado_id FROM notas_credito_lineas WHERE id = ? FOR UPDATE',
            [(int) $lineaId]
        );
    }

    public function crearListado(array $data)
    {
        $sql = "INSERT INTO notas_credito_listados
                    (sociedad_id, nombre, empresa_reporte, periodo_desde, periodo_hasta,
                     archivo_origen, archivo_ruta, archivo_hash, total_lineas)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        return (int) $this->insert($sql, [
            (int) $data['sociedad_id'],
            (string) $data['nombre'],
            $data['empresa_reporte'] ?: null,
            $data['periodo_desde'] ?: null,
            $data['periodo_hasta'] ?: null,
            (string) $data['archivo_origen'],
            (string) $data['archivo_ruta'],
            (string) $data['archivo_hash'],
            (int) $data['total_lineas'],
        ]);
    }

    private const COLUMNAS_LINEA = [
        'listado_id', 'fila_origen', 'proveedor_codigo', 'proveedor_nombre', 'sucursal',
        'documento', 'clase', 'fecha', 'nc_proveedor', 'fecha_nc_proveedor', 'entrada_asociada',
        'moneda', 'monto', 'saldo', 'monto_conversion', 'datos_origen', 'carga_id',
    ];

    /** Los valores de una línea, en el orden de COLUMNAS_LINEA. */
    private function valoresLinea($listadoId, array $linea, $cargaId = null)
    {
        return [
            (int) $listadoId,
            (int) $linea['fila_origen'],
            $linea['proveedor_codigo'] ?: null,
            (string) $linea['proveedor_nombre'],
            $linea['sucursal'] ?: null,
            (string) $linea['documento'],
            ClaseNotaCredito::clasificar($linea['documento']),
            (string) $linea['fecha'],
            $linea['nc_proveedor'] ?: null,
            $linea['fecha_nc_proveedor'] ?: null,
            $linea['entrada_asociada'] ?: null,
            (string) $linea['moneda'],
            (float) $linea['monto'],
            (float) $linea['saldo'],
            (float) $linea['monto_conversion'],
            $linea['datos_origen'] ?: null,
            $cargaId !== null ? (int) $cargaId : null,
        ];
    }

    public function crearLinea($listadoId, array $linea)
    {
        return (int) $this->insert(
            'INSERT INTO notas_credito_lineas (' . implode(', ', self::COLUMNAS_LINEA) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count(self::COLUMNAS_LINEA), '?')) . ')',
            $this->valoresLinea($listadoId, $linea)
        );
    }

    /**
     * Inserta las líneas de un listado en tandas. Un CSV de notas son miles
     * de filas, y con la base en el servidor cada INSERT cuesta un viaje de
     * ida y vuelta: de a una, la carga se pasa del tiempo máximo de la
     * petición antes de llegar al final.
     */
    public function crearLineasLote($listadoId, array $lineas, $tam = 200, $cargaId = null)
    {
        $columnas = implode(', ', self::COLUMNAS_LINEA);
        $hueco = '(' . implode(', ', array_fill(0, count(self::COLUMNAS_LINEA), '?')) . ')';

        $insertadas = 0;
        foreach (array_chunk(array_values($lineas), max(1, (int) $tam)) as $tanda) {
            $params = [];
            foreach ($tanda as $linea) {
                foreach ($this->valoresLinea($listadoId, $linea, $cargaId) as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                "INSERT INTO notas_credito_lineas ({$columnas}) VALUES "
                . implode(', ', array_fill(0, count($tanda), $hueco)),
                $params
            );
            $insertadas += count($tanda);
        }

        return $insertadas;
    }

    // ------------------------------------------------------------------
    // Importación acumulativa
    // ------------------------------------------------------------------

    /**
     * Identidad estable de una nota entre dos fotos del ERP.
     *
     * La sociedad se aplica en la consulta que arma el conjunto. La moneda se
     * incluye porque forma parte del significado del monto: 100 CRC y 100 USD
     * no pueden ser el mismo documento aunque los cuatro textos coincidan.
     */
    public static function claveCarga(array $linea)
    {
        $proveedor = trim((string) ($linea['proveedor_codigo'] ?? ''));
        if ($proveedor === '') {
            $proveedor = (string) ($linea['proveedor_nombre'] ?? '');
        }

        $documentoCrudo = (string) ($linea['documento'] ?? '');
        $documento = ClaseNotaCredito::limpiar($documentoCrudo);
        if ($documento === '') {
            $documento = $documentoCrudo;
        }

        $partes = [
            self::normalizarIdentidad($proveedor),
            self::normalizarIdentidad((string) ($linea['sucursal'] ?? '')),
            self::normalizarIdentidad($documento),
            self::normalizarMoneda((string) ($linea['moneda'] ?? 'CRC')),
            number_format(round((float) ($linea['monto'] ?? 0), 2), 2, '.', ''),
        ];
        return hash('sha256', implode('|', $partes));
    }

    private static function normalizarIdentidad($valor)
    {
        $valor = mb_strtoupper(trim((string) $valor), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $valor);
    }

    private static function normalizarMoneda($valor)
    {
        $valor = self::normalizarIdentidad($valor);
        return in_array($valor, ['$', 'USD', 'DOLARES', 'DÓLARES'], true) ? 'USD' : 'CRC';
    }

    /** Regla única para fusionar decisiones XML de fotos heredadas. */
    private static function debeTransferirMatchHistorico(array $actual, array $historica, array $xmlUsados)
    {
        // Una decisión manual o un desvínculo explícito del acumulado nunca
        // se reemplazan con información de una foto anterior.
        if (!empty($actual['match_manual']) || !empty($actual['bloqueo_automatico'])) {
            return false;
        }

        $xmlActual = (int) ($actual['factura_xml_id'] ?? 0);
        $xmlHistorico = (int) ($historica['factura_xml_id'] ?? 0);
        $mismoXml = $xmlHistorico > 0 && $xmlHistorico === $xmlActual;
        $xmlHistoricoLibre = $xmlHistorico > 0
            && (!isset($xmlUsados[$xmlHistorico])
                || (int) $xmlUsados[$xmlHistorico] === (int) $actual['id']);

        if ($xmlActual === 0 && $xmlHistoricoLibre) {
            return true;
        }
        if (!empty($historica['match_manual']) && ($mismoXml || $xmlHistoricoLibre)) {
            return true;
        }

        // Desvincular manualmente deja XML NULL y bloqueo_automatico=1. Esa
        // decisión también pertenece a la identidad y debe sobrevivir si la
        // fila reciente todavía no tiene ninguna decisión propia.
        return $xmlActual === 0
            && $xmlHistorico === 0
            && !empty($historica['bloqueo_automatico']);
    }

    /** Actualiza los mapas de una simulación o consolidación ya decidida. */
    private static function aplicarMatchHistoricoEnMemoria(array $actual, array $historica, array &$xmlUsados)
    {
        $xmlActual = (int) ($actual['factura_xml_id'] ?? 0);
        if ($xmlActual > 0
            && isset($xmlUsados[$xmlActual])
            && (int) $xmlUsados[$xmlActual] === (int) $actual['id']) {
            unset($xmlUsados[$xmlActual]);
        }
        foreach (self::COLUMNAS_MATCH as $columna) {
            $actual[$columna] = $historica[$columna] ?? null;
        }
        $xmlNuevo = (int) ($actual['factura_xml_id'] ?? 0);
        if ($xmlNuevo > 0) {
            $xmlUsados[$xmlNuevo] = (int) $actual['id'];
        }
        return $actual;
    }

    /**
     * Calcula el efecto de un archivo sin escribir. Considera también fotos
     * antiguas, porque al confirmar se recuperan sus documentos ausentes en el
     * listado más reciente antes de aplicar el archivo nuevo.
     */
    public function previsualizarImportacion($sociedadId, array $lineas)
    {
        $sociedadId = (int) $sociedadId;
        $listadoId = (int) $this->fetchColumn(
            'SELECT id FROM notas_credito_listados WHERE sociedad_id = ? ORDER BY id DESC LIMIT 1',
            [$sociedadId]
        );
        $guardadas = $this->fetchAll(
            "SELECT nl.*, nl.listado_id
               FROM notas_credito_lineas nl
               JOIN notas_credito_listados li ON li.id = nl.listado_id
              WHERE li.sociedad_id = ?
              ORDER BY li.id DESC, nl.id DESC",
            [$sociedadId]
        ) ?: [];

        $acumuladas = [];
        $xmlUsados = [];
        foreach ($guardadas as $fila) {
            if ((int) $fila['listado_id'] !== $listadoId) {
                continue;
            }
            $clave = self::claveCarga($fila);
            if (isset($acumuladas[$clave])) {
                throw new Exception('El listado actual ya contiene identidades duplicadas.');
            }
            $acumuladas[$clave] = $fila;
            if (!empty($fila['factura_xml_id'])) {
                $xmlUsados[(int) $fila['factura_xml_id']] = (int) $fila['id'];
            }
        }

        // Reproduce la misma regla de la consolidación real: una identidad
        // antigua se recupera solo si no existe en el maestro y su XML no está
        // reservado por otra identidad más reciente.
        $recuperables = 0;
        foreach ($guardadas as $fila) {
            if ((int) $fila['listado_id'] === $listadoId) {
                continue;
            }
            $clave = self::claveCarga($fila);
            if (isset($acumuladas[$clave])) {
                $actual = $acumuladas[$clave];
                if (self::debeTransferirMatchHistorico($actual, $fila, $xmlUsados)) {
                    $acumuladas[$clave] = self::aplicarMatchHistoricoEnMemoria(
                        $actual,
                        $fila,
                        $xmlUsados
                    );
                }
                continue;
            }
            $xmlId = (int) ($fila['factura_xml_id'] ?? 0);
            if ($xmlId > 0 && isset($xmlUsados[$xmlId])) {
                continue;
            }
            $acumuladas[$clave] = $fila;
            if ($xmlId > 0) {
                $xmlUsados[$xmlId] = (int) $fila['id'];
            }
            $recuperables++;
        }

        $resultado = [
            'nuevas' => 0,
            'actualizadas' => 0,
            'sin_cambio' => 0,
            'recuperables' => $recuperables,
            'acumuladas' => count($acumuladas),
        ];
        $vistas = [];
        foreach ($lineas as $linea) {
            $clave = self::claveCarga($linea);
            if (isset($vistas[$clave])) {
                throw new Exception(
                    'El archivo repite la misma nota (documento, proveedor, sucursal y monto) '
                    . 'en las filas ' . $vistas[$clave] . ' y ' . (int) ($linea['fila_origen'] ?? 0) . '.'
                );
            }
            $vistas[$clave] = (int) ($linea['fila_origen'] ?? 0);
            if (!isset($acumuladas[$clave])) {
                $resultado['nuevas']++;
            } elseif (abs((float) $acumuladas[$clave]['saldo'] - (float) $linea['saldo']) >= self::EPSILON_SALDO) {
                $resultado['actualizadas']++;
            } else {
                $resultado['sin_cambio']++;
            }
        }
        return $resultado;
    }

    /**
     * Consolida las fotos heredadas sin necesidad de esperar otro archivo.
     * Los IDs se conservan y listado_origen_id permite auditar de qué foto se
     * movió cada fila. No se crea una carga porque aquí no se recibió un CSV.
     */
    public function consolidarSociedad($sociedadId)
    {
        $sociedadId = (int) $sociedadId;
        if ($sociedadId <= 0) {
            throw new InvalidArgumentException('La sociedad no es válida.');
        }

        $this->begin();
        try {
            $this->fetchOne('SELECT id FROM sociedades WHERE id = ? FOR UPDATE', [$sociedadId]);
            $listadoId = (int) $this->fetchColumn(
                'SELECT id FROM notas_credito_listados
                  WHERE sociedad_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$sociedadId]
            );
            if ($listadoId <= 0) {
                $this->commit();
                return ['listado_id' => 0, 'recuperadas' => 0, 'ids' => [], 'total' => 0];
            }

            $ids = $this->consolidarListadosAnteriores($sociedadId, $listadoId);
            $total = (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM notas_credito_lineas WHERE listado_id = ?',
                [$listadoId]
            );
            $this->execute(
                'UPDATE notas_credito_listados SET total_lineas = ? WHERE id = ?',
                [$total, $listadoId]
            );
            $this->commit();
            return [
                'listado_id' => $listadoId,
                'recuperadas' => count($ids),
                'ids' => $ids,
                'total' => $total,
            ];
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Aplica una foto del ERP al único conjunto acumulado de la sociedad.
     * No elimina lo que no vino: inserta nuevas, actualiza saldos distintos y
     * deja intactas (incluido su vínculo XML) las filas cuyo saldo no cambió.
     */
    public function importarConsolidado(array $lineas, array $meta, $usuarioId = null)
    {
        if (!$lineas) {
            throw new InvalidArgumentException('El archivo no contiene notas válidas para importar.');
        }
        $sociedadId = (int) ($meta['sociedad_id'] ?? 0);
        if ($sociedadId <= 0) {
            throw new InvalidArgumentException('La carga de notas necesita una sociedad.');
        }
        $meta = array_merge([
            'nombre' => 'Notas de crédito acumuladas',
            'empresa_reporte' => null,
            'periodo_desde' => null,
            'periodo_hasta' => null,
            'archivo_origen' => 'notas.csv',
            'archivo_ruta' => '',
            'archivo_hash' => hash('sha256', uniqid('nc_', true)),
            'filas_leidas' => count($lineas),
            'filas_invalidas' => 0,
        ], $meta);

        $this->begin();
        try {
            // Serializa las cargas de una misma sociedad, incluso cuando aún
            // no existe listado canónico y dos peticiones llegan juntas.
            $this->fetchOne('SELECT id FROM sociedades WHERE id = ? FOR UPDATE', [$sociedadId]);
            $listado = $this->fetchOne(
                'SELECT * FROM notas_credito_listados WHERE sociedad_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$sociedadId]
            );
            if (!$listado) {
                $listadoId = $this->crearListado([
                    'sociedad_id' => $sociedadId,
                    'nombre' => (string) $meta['nombre'],
                    'empresa_reporte' => $meta['empresa_reporte'],
                    'periodo_desde' => $meta['periodo_desde'],
                    'periodo_hasta' => $meta['periodo_hasta'],
                    'archivo_origen' => (string) $meta['archivo_origen'],
                    'archivo_ruta' => (string) $meta['archivo_ruta'],
                    'archivo_hash' => (string) $meta['archivo_hash'],
                    'total_lineas' => 0,
                ]);
            } else {
                $listadoId = (int) $listado['id'];
            }

            $idsRecuperadas = $this->consolidarListadosAnteriores($sociedadId, $listadoId);
            $recuperadas = count($idsRecuperadas);
            $cargaId = $this->crearCarga($listadoId, $sociedadId, $meta, $usuarioId);

            $existentes = [];
            foreach ($this->fetchAll(
                'SELECT id, proveedor_codigo, proveedor_nombre, sucursal, documento, moneda, monto, saldo
                   FROM notas_credito_lineas WHERE listado_id = ? FOR UPDATE',
                [$listadoId]
            ) ?: [] as $fila) {
                $clave = self::claveCarga($fila);
                if (isset($existentes[$clave])) {
                    throw new Exception('El acumulado contiene dos filas con la misma identidad; no se aplicó la carga.');
                }
                $existentes[$clave] = $fila;
            }

            $nuevas = [];
            $cambios = [];
            $sinCambio = 0;
            $vistas = [];
            $idsRecibidas = [];
            foreach ($lineas as $linea) {
                $clave = self::claveCarga($linea);
                if (isset($vistas[$clave])) {
                    throw new Exception(
                        'El archivo repite la misma nota (documento, proveedor, sucursal y monto) '
                        . 'en las filas ' . $vistas[$clave] . ' y ' . (int) ($linea['fila_origen'] ?? 0) . '.'
                    );
                }
                $vistas[$clave] = (int) ($linea['fila_origen'] ?? 0);

                if (!isset($existentes[$clave])) {
                    $nuevas[] = $linea;
                    continue;
                }
                $idsRecibidas[] = (int) $existentes[$clave]['id'];
                $anterior = (float) $existentes[$clave]['saldo'];
                if (abs($anterior - (float) $linea['saldo']) < self::EPSILON_SALDO) {
                    $sinCambio++;
                    continue;
                }
                $cambios[] = [
                    'id' => (int) $existentes[$clave]['id'],
                    'saldo_anterior' => $anterior,
                    'saldo' => (float) $linea['saldo'],
                ];
            }

            $this->crearLineasLote($listadoId, $nuevas, self::LOTE_IMPORTACION, $cargaId);
            $idsNuevas = array_map('intval', array_column($this->fetchAll(
                'SELECT id FROM notas_credito_lineas WHERE listado_id = ? AND carga_id = ? ORDER BY id',
                [$listadoId, $cargaId]
            ) ?: [], 'id'));
            $this->actualizarSaldosLote($cambios, $cargaId);

            $total = (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM notas_credito_lineas WHERE listado_id = ?',
                [$listadoId]
            );
            $this->execute(
                "UPDATE notas_credito_listados
                    SET nombre = ?, empresa_reporte = ?, periodo_desde = ?, periodo_hasta = ?,
                        archivo_origen = ?, archivo_ruta = ?, archivo_hash = ?,
                        total_lineas = ?, fecha_subida = NOW()
                  WHERE id = ?",
                [
                    (string) ($meta['nombre'] ?? 'Notas de crédito acumuladas'),
                    $meta['empresa_reporte'] ?? null,
                    $meta['periodo_desde'] ?? null,
                    $meta['periodo_hasta'] ?? null,
                    (string) ($meta['archivo_origen'] ?? 'notas.csv'),
                    (string) ($meta['archivo_ruta'] ?? ''),
                    (string) ($meta['archivo_hash'] ?? ''),
                    $total,
                    $listadoId,
                ]
            );
            $this->execute(
                "UPDATE notas_credito_cargas
                    SET insertadas = ?, actualizadas = ?, sin_cambio = ?, recuperadas = ?
                  WHERE id = ?",
                [count($nuevas), count($cambios), $sinCambio, $recuperadas, $cargaId]
            );
            $this->commit();

            return [
                'listado_id' => $listadoId,
                'carga_id' => $cargaId,
                'insertadas' => count($nuevas),
                'actualizadas' => count($cambios),
                'sin_cambio' => $sinCambio,
                'recuperadas' => $recuperadas,
                'total' => $total,
                // También se incluyen las identidades ya conocidas que vinieron
                // en el archivo. Así una recarga idéntica reintenta cualquier
                // verificación que hubiera fallado después del commit anterior.
                'ids_verificar' => array_values(array_unique(array_merge(
                    $idsRecuperadas,
                    $idsNuevas,
                    $idsRecibidas
                ))),
            ];
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function crearCarga($listadoId, $sociedadId, array $meta, $usuarioId)
    {
        return (int) $this->insert(
            "INSERT INTO notas_credito_cargas
                (listado_id, sociedad_id, archivo_origen, archivo_ruta, archivo_hash,
                 empresa_reporte, periodo_desde, periodo_hasta, filas_leidas,
                 filas_invalidas, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $listadoId,
                (int) $sociedadId,
                (string) ($meta['archivo_origen'] ?? 'notas.csv'),
                (string) ($meta['archivo_ruta'] ?? ''),
                (string) ($meta['archivo_hash'] ?? ''),
                $meta['empresa_reporte'] ?? null,
                $meta['periodo_desde'] ?? null,
                $meta['periodo_hasta'] ?? null,
                (int) ($meta['filas_leidas'] ?? 0),
                (int) ($meta['filas_invalidas'] ?? 0),
                $usuarioId !== null ? (int) $usuarioId : null,
            ]
        );
    }

    /** Mueve al maestro las identidades que solo vivían en fotos antiguas. */
    private function consolidarListadosAnteriores($sociedadId, $listadoId)
    {
        $actuales = [];
        $xmlUsados = [];
        foreach ($this->fetchAll(
            'SELECT * FROM notas_credito_lineas WHERE listado_id = ? ORDER BY id DESC FOR UPDATE',
            [(int) $listadoId]
        ) ?: [] as $fila) {
            $clave = self::claveCarga($fila);
            if (isset($actuales[$clave])) {
                throw new Exception('El listado actual ya contiene identidades duplicadas.');
            }
            $actuales[$clave] = $fila;
            if (!empty($fila['factura_xml_id'])) {
                $xmlUsados[(int) $fila['factura_xml_id']] = (int) $fila['id'];
            }
        }

        $anteriores = $this->fetchAll(
            "SELECT nl.*
               FROM notas_credito_lineas nl
               JOIN notas_credito_listados li ON li.id = nl.listado_id
              WHERE li.sociedad_id = ? AND li.id <> ?
              ORDER BY li.id DESC, nl.id DESC
              FOR UPDATE",
            [(int) $sociedadId, (int) $listadoId]
        ) ?: [];

        $recuperadas = [];
        foreach ($anteriores as $fila) {
            $clave = self::claveCarga($fila);
            if (isset($actuales[$clave])) {
                // La fila reciente conserva saldo, ID y gestión. Si la misma
                // identidad solo estaba vinculada en una foto anterior, se
                // recupera esa decisión sin volver a crear la nota. Un match
                // manual histórico también prevalece sobre uno automático,
                // siempre que su XML no pertenezca ya a otra identidad actual.
                $actual = $actuales[$clave];
                if (self::debeTransferirMatchHistorico($actual, $fila, $xmlUsados)) {
                    $this->transferirMatchHistorico((int) $actual['id'], $fila);
                    $actuales[$clave] = self::aplicarMatchHistoricoEnMemoria(
                        $actual,
                        $fila,
                        $xmlUsados
                    );
                }
                continue;
            }

            $xmlId = (int) ($fila['factura_xml_id'] ?? 0);
            if ($xmlId > 0 && isset($xmlUsados[$xmlId])) {
                // La misma NC electrónica ya respalda otra identidad en la
                // foto más reciente. En datos heredados esto corresponde a
                // números corregidos entre cargas (por ejemplo, el proveedor
                // pegado al final del documento). La fila reciente prevalece;
                // recuperar la antigua crearía un duplicado falso. La fila
                // histórica y su decisión manual, si la hubo, no se eliminan.
                continue;
            }

            $this->execute(
                'UPDATE notas_credito_lineas
                    SET listado_origen_id = COALESCE(listado_origen_id, listado_id), listado_id = ?
                  WHERE id = ?',
                [(int) $listadoId, (int) $fila['id']]
            );
            $actuales[$clave] = $fila;
            $actuales[$clave]['listado_id'] = (int) $listadoId;
            if ($xmlId > 0) {
                $xmlUsados[$xmlId] = (int) $fila['id'];
            }
            $recuperadas[] = (int) $fila['id'];
        }
        return $recuperadas;
    }

    /** Copia solo la decisión XML; saldo e identidad siguen siendo los recientes. */
    private function transferirMatchHistorico($lineaId, array $historica)
    {
        return $this->execute(
            "UPDATE notas_credito_lineas
                SET factura_xml_id = ?, estado = ?, diferencia = ?, metodo_match = ?,
                    score_proveedor = ?, match_manual = ?, bloqueo_automatico = ?,
                    motivo_match = ?
              WHERE id = ?",
            [
                !empty($historica['factura_xml_id']) ? (int) $historica['factura_xml_id'] : null,
                (string) ($historica['estado'] ?? 'sin_respaldo'),
                $historica['diferencia'] ?? null,
                (string) ($historica['metodo_match'] ?? 'ninguno'),
                $historica['score_proveedor'] ?? null,
                !empty($historica['match_manual']) ? 1 : 0,
                !empty($historica['bloqueo_automatico']) ? 1 : 0,
                $historica['motivo_match'] ?? null,
                (int) $lineaId,
            ]
        );
    }

    /** Solo toca el saldo y su auditoría; el matching XML queda intacto. */
    private function actualizarSaldosLote(array $cambios, $cargaId)
    {
        foreach (array_chunk($cambios, self::LOTE_IMPORTACION) as $tanda) {
            if (!$tanda) {
                continue;
            }
            $saldo = 'saldo = CASE id';
            $anterior = 'saldo_anterior = CASE id';
            $params = [];
            foreach ($tanda as $cambio) {
                $saldo .= ' WHEN ? THEN ?';
                array_push($params, (int) $cambio['id'], (float) $cambio['saldo']);
            }
            foreach ($tanda as $cambio) {
                $anterior .= ' WHEN ? THEN ?';
                array_push($params, (int) $cambio['id'], (float) $cambio['saldo_anterior']);
            }
            $ids = array_map(function ($cambio) { return (int) $cambio['id']; }, $tanda);
            $this->execute(
                "UPDATE notas_credito_lineas
                    SET {$saldo} ELSE saldo END,
                        {$anterior} ELSE saldo_anterior END,
                        carga_cambio_id = ?, saldo_cambiado_en = NOW()
                  WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, [(int) $cargaId], $ids)
            );
        }
    }

    public function buscarPorHash($hash, $sociedadId = 0)
    {
        $params = [(string) $hash];
        $sql = "SELECT c.*, COALESCE(li.nombre, c.archivo_origen) AS nombre
                  FROM notas_credito_cargas c
                  LEFT JOIN notas_credito_listados li ON li.id = c.listado_id
                 WHERE c.archivo_hash = ?";
        if ((int) $sociedadId > 0) {
            $sql .= ' AND c.sociedad_id = ?';
            $params[] = (int) $sociedadId;
        }
        $sql .= ' ORDER BY c.creado_en DESC, c.id DESC LIMIT 1';
        $row = $this->fetchOne($sql, $params);
        return $row ?: null;
    }

    public function getListados($sociedadId, $limit = 100)
    {
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre
                FROM notas_credito_listados l
                JOIN sociedades s ON s.id = l.sociedad_id
                WHERE l.sociedad_id = ?
                  AND l.id = (SELECT MAX(actual.id)
                                FROM notas_credito_listados actual
                               WHERE actual.sociedad_id = l.sociedad_id)
                ORDER BY l.id DESC
                LIMIT " . max(1, (int) $limit);
        return $this->fetchAll($sql, [(int) $sociedadId]) ?: [];
    }

    public function getListado($id)
    {
        $sql = "SELECT l.*, s.nombre AS sociedad_nombre, s.cedula AS sociedad_cedula
                FROM notas_credito_listados l
                JOIN sociedades s ON s.id = l.sociedad_id
                WHERE l.id = ? LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $id]);
        return $row ?: null;
    }

    public function getLinea($id)
    {
        $sql = "SELECT nl.*, l.sociedad_id, l.nombre AS listado_nombre,
                       s.cedula AS sociedad_cedula,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                JOIN notas_credito_listados l ON l.id = nl.listado_id
                JOIN sociedades s ON s.id = l.sociedad_id
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE nl.id = ? LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $id]);
        return $row ?: null;
    }

    public function getLineasParaMatching($listadoId)
    {
        $sql = "SELECT nl.*, f.total AS xml_total, f.moneda AS xml_moneda
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                WHERE nl.listado_id = ?
                ORDER BY nl.id ASC";
        return $this->fetchAll($sql, [(int) $listadoId]) ?: [];
    }

    public function getLineasPaginadas($listadoId, array $filters, $page = 1, $perPage = 100)
    {
        $where = ['nl.listado_id = ?'];
        $params = [(int) $listadoId];

        if (!empty($filters['estado'])
            && in_array($filters['estado'], ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $filters['estado'];
        }
        $proveedor = ProveedorCatalogo::condicion(
            $filters['proveedor'] ?? '',
            ['codigo' => 'nl.proveedor_codigo', 'nombre' => 'nl.proveedor_nombre'],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
        }
        if (!empty($filters['sucursal'])) {
            $where[] = 'nl.sucursal = ?';
            $params[] = $filters['sucursal'];
        }
        if (!empty($filters['q'])) {
            $like = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(nl.documento LIKE ? OR nl.nc_proveedor LIKE ? OR nl.entrada_asociada LIKE ? OR nl.proveedor_nombre LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM notas_credito_lineas nl WHERE {$whereSql}",
            $params
        );

        $perPage = max(20, min(200, (int) $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $page, $pages));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT nl.id, nl.listado_id, nl.fila_origen,
                       nl.proveedor_codigo, nl.proveedor_nombre, nl.sucursal,
                       nl.documento, nl.fecha, nl.nc_proveedor, nl.fecha_nc_proveedor,
                       nl.entrada_asociada, nl.moneda, nl.monto, nl.saldo,
                       nl.monto_conversion, nl.factura_xml_id, nl.estado,
                       nl.diferencia, nl.metodo_match, nl.score_proveedor,
                       nl.match_manual, nl.bloqueo_automatico, nl.motivo_match,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE {$whereSql}
                ORDER BY FIELD(nl.estado, 'con_diferencia', 'sin_respaldo', 'coincide'),
                         nl.proveedor_nombre ASC, nl.fecha DESC, nl.id ASC
                LIMIT {$perPage} OFFSET {$offset}";
        return [
            'rows' => $this->fetchAll($sql, $params) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Devuelve todas las líneas que cumplen los filtros. Esta consulta es la
     * fuente de la búsqueda en vivo y deliberadamente no usa paginación: los
     * filtros se aplican al listado completo en la base de datos.
     */
    public function getLineasFiltradas($listadoId, array $filters)
    {
        $where = ['nl.listado_id = ?'];
        $params = [(int) $listadoId];

        $estado = trim((string) ($filters['estado'] ?? ''));
        if ($estado !== '' && in_array($estado, ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $estado;
        }
        $estadoColumna = trim((string) ($filters['col_estado'] ?? ''));
        if ($estadoColumna !== '' && in_array($estadoColumna, ['sin_respaldo', 'coincide', 'con_diferencia'], true)) {
            $where[] = 'nl.estado = ?';
            $params[] = $estadoColumna;
        }

        $condicionSaldo = trim((string) ($filters['condicion_saldo'] ?? ''));
        if ($condicionSaldo === 'con_saldo') {
            $where[] = 'nl.saldo <> 0';
        } elseif ($condicionSaldo === 'sin_saldo') {
            $where[] = 'nl.saldo = 0';
        }

        $condicionNcProveedor = trim((string) ($filters['condicion_nc_proveedor'] ?? ''));
        if ($condicionNcProveedor === 'con_nc_proveedor') {
            $where[] = "nl.nc_proveedor IS NOT NULL AND TRIM(nl.nc_proveedor) <> ''";
        } elseif ($condicionNcProveedor === 'sin_nc_proveedor') {
            $where[] = "(nl.nc_proveedor IS NULL OR TRIM(nl.nc_proveedor) = '')";
        }

        // El listado del ERP no siempre trae el código en la línea, así que
        // aquí el nombre sigue contando: es lo único que tienen las que no lo
        // traen.
        $proveedor = ProveedorCatalogo::condicion(
            $filters['proveedor'] ?? '',
            ['codigo' => 'nl.proveedor_codigo', 'nombre' => 'nl.proveedor_nombre'],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
        }

        // La clase dice QUÉ es la nota —si corrige una factura, si es una
        // diferencia de costo, si es un cambio de mercadería— y es lo que
        // decide si lleva respaldo XML. Es la misma que usa Seguimiento.
        //
        // Se piden varias a la vez porque el trabajo se reparte por clase, y
        // de a una obligaba a recorrer el listado tantas veces como clases.
        $clases = ClaseNotaCredito::clasesPedidas($filters['clase'] ?? '');
        if ($clases) {
            $where[] = 'nl.clase IN (' . implode(',', array_fill(0, count($clases), '?')) . ')';
            foreach ($clases as $clase) {
                $params[] = $clase;
            }
        }
        if (trim((string) ($filters['sucursal'] ?? '')) !== '') {
            $where[] = 'nl.sucursal = ?';
            $params[] = trim((string) $filters['sucursal']);
        }

        $textFilters = [
            'proveedor_codigo' => 'nl.proveedor_codigo',
            'proveedor_nombre' => 'nl.proveedor_nombre',
            'sucursal_texto' => 'nl.sucursal',
            'documento' => 'nl.documento',
            'nc_proveedor' => 'nl.nc_proveedor',
            'entrada_asociada' => 'nl.entrada_asociada',
            'nc_xml' => "CONCAT_WS(' ', f.consecutivo_completo, f.numero_factura_asistente, p.razon_social)",
        ];
        foreach ($textFilters as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "{$column} LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        foreach (['fecha' => 'nl.fecha', 'fecha_nc_proveedor' => 'nl.fecha_nc_proveedor'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        $moneda = strtoupper(trim((string) ($filters['moneda'] ?? '')));
        if (in_array($moneda, ['CRC', 'USD'], true)) {
            $where[] = 'UPPER(nl.moneda) = ?';
            $params[] = $moneda;
        }

        $numericFilters = [
            'monto' => 'nl.monto',
            'saldo' => 'nl.saldo',
            'monto_conversion' => 'nl.monto_conversion',
            'xml_total' => 'f.total',
            'diferencia' => 'nl.diferencia',
        ];
        foreach ($numericFilters as $key => $column) {
            $value = preg_replace('/[₡$,\s]/u', '', (string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "CAST({$column} AS CHAR) LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        $global = trim((string) ($filters['q'] ?? ''));
        if ($global !== '') {
            $like = '%' . $global . '%';
            $where[] = "(nl.proveedor_codigo LIKE ? OR nl.proveedor_nombre LIKE ?
                        OR nl.sucursal LIKE ? OR nl.documento LIKE ? OR nl.nc_proveedor LIKE ?
                        OR nl.entrada_asociada LIKE ? OR f.consecutivo_completo LIKE ?
                        OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)";
            for ($i = 0; $i < 9; $i++) {
                $params[] = $like;
            }
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT nl.id, nl.listado_id, nl.fila_origen,
                       nl.proveedor_codigo, nl.proveedor_nombre, nl.sucursal,
                       nl.documento, nl.fecha, nl.nc_proveedor, nl.fecha_nc_proveedor,
                       nl.entrada_asociada, nl.moneda, nl.monto, nl.saldo,
                       nl.monto_conversion, nl.factura_xml_id, nl.estado,
                       nl.diferencia, nl.metodo_match, nl.score_proveedor,
                       nl.match_manual, nl.bloqueo_automatico, nl.motivo_match,
                       f.consecutivo_completo AS xml_consecutivo,
                       f.numero_factura_asistente AS xml_numero,
                       f.fecha_emision AS xml_fecha,
                       f.total AS xml_total,
                       f.moneda AS xml_moneda,
                       p.razon_social AS xml_proveedor
                FROM notas_credito_lineas nl
                LEFT JOIN facturas_xml f ON f.id = nl.factura_xml_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE {$whereSql}
                ORDER BY FIELD(nl.estado, 'con_diferencia', 'sin_respaldo', 'coincide'),
                         nl.proveedor_nombre ASC, nl.fecha DESC, nl.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Los proveedores del listado, como los espera ProveedorCatalogo.
     *
     * Se agrupa por código Y por nombre porque las líneas sin código solo se
     * pueden distinguir por lo escrito; el catálogo junta después lo que
     * resulte ser el mismo proveedor.
     */
    public function proveedoresParaFiltro($listadoId)
    {
        return $this->fetchAll(
            "SELECT COALESCE(proveedor_codigo, '') AS codigo, proveedor_nombre AS nombre,
                    COUNT(*) AS n
               FROM notas_credito_lineas
              WHERE listado_id = ?
              GROUP BY COALESCE(proveedor_codigo, ''), proveedor_nombre",
            [(int) $listadoId]
        ) ?: [];
    }

    public function opcionesFiltros($listadoId)
    {
        return [
            'sucursales' => $this->fetchAll(
                "SELECT DISTINCT sucursal AS valor
                 FROM notas_credito_lineas
                 WHERE listado_id = ? AND sucursal IS NOT NULL AND sucursal <> ''
                 ORDER BY sucursal",
                [(int) $listadoId]
            ) ?: [],
        ];
    }

    /**
     * Pares (listado, proveedor) que siguen sin respaldo.
     *
     * Con esto, un alias recién aprendido solo hace revisar los listados
     * donde ese proveedor tiene algo pendiente, en vez de repasarlos todos.
     */
    public function proveedoresSinRespaldo()
    {
        return $this->fetchAll(
            "SELECT DISTINCT nl.listado_id, nl.proveedor_nombre
               FROM notas_credito_lineas nl
               JOIN notas_credito_listados li ON li.id = nl.listado_id
              WHERE nl.estado = 'sin_respaldo' AND nl.proveedor_nombre <> ''
                AND li.id = (SELECT MAX(actual.id)
                               FROM notas_credito_listados actual
                              WHERE actual.sociedad_id = li.sociedad_id)"
        ) ?: [];
    }

    public function resumen($listadoId)
    {
        $rows = $this->fetchAll(
            "SELECT estado, COUNT(*) cantidad, COALESCE(SUM(monto), 0) monto
             FROM notas_credito_lineas
             WHERE listado_id = ?
             GROUP BY estado",
            [(int) $listadoId]
        ) ?: [];
        $result = [
            'sin_respaldo' => 0,
            'coincide' => 0,
            'con_diferencia' => 0,
            'total' => 0,
        ];
        foreach ($rows as $row) {
            $result[$row['estado']] = (int) $row['cantidad'];
            $result['total'] += (int) $row['cantidad'];
        }
        return $result;
    }

    public function iniciarVerificacion($listadoId, $origen = 'automatico')
    {
        return (int) $this->insert(
            "INSERT INTO notas_credito_verificaciones (listado_id, origen) VALUES (?, ?)",
            [(int) $listadoId, substr(trim((string) $origen) ?: 'automatico', 0, 30)]
        );
    }

    private const COLUMNAS_HISTORIAL = [
        'verificacion_id', 'listado_id', 'linea_id', 'fila_origen', 'documento',
        'proveedor_nombre', 'nc_proveedor', 'moneda', 'estado_anterior', 'estado_nuevo',
        'factura_xml_id_anterior', 'factura_xml_id_nuevo',
        'diferencia_anterior', 'diferencia_nueva', 'motivo_anterior', 'motivo_nuevo',
    ];

    /**
     * Los valores de la fila de historial de un cambio, o null si la línea
     * quedó igual que estaba y no hay nada que historiar.
     *
     * Separado de la escritura para que quien verifica pueda juntar todas las
     * filas y guardarlas de una vez: la primera verificación de un listado
     * cambia TODAS sus líneas, y ahí una consulta por línea no cabe en el
     * tiempo de la petición.
     */
    public function filaHistorial($verificacionId, array $anterior, array $nuevo)
    {
        $estadoAnterior = (string) ($anterior['estado'] ?? 'sin_respaldo');
        $estadoNuevo = (string) ($nuevo['estado'] ?? 'sin_respaldo');
        $xmlAnterior = !empty($anterior['factura_xml_id']) ? (int) $anterior['factura_xml_id'] : null;
        $xmlNuevo = !empty($nuevo['factura_xml_id']) ? (int) $nuevo['factura_xml_id'] : null;
        $diferenciaAnterior = $anterior['diferencia'] ?? null;
        $diferenciaNueva = $nuevo['diferencia'] ?? null;
        $motivoAnterior = trim((string) ($anterior['motivo_match'] ?? '')) ?: null;
        $motivoNuevo = trim((string) ($nuevo['motivo_match'] ?? '')) ?: null;

        $cambio = $estadoAnterior !== $estadoNuevo
            || $xmlAnterior !== $xmlNuevo
            || self::valorDecimalHistorial($diferenciaAnterior) !== self::valorDecimalHistorial($diferenciaNueva)
            || $motivoAnterior !== $motivoNuevo;
        if (!$cambio) {
            return null;
        }

        return [
            (int) $verificacionId,
            (int) ($anterior['listado_id'] ?? 0),
            (int) ($anterior['id'] ?? 0) ?: null,
            (int) ($anterior['fila_origen'] ?? 0) ?: null,
            trim((string) ($anterior['documento'] ?? '')) ?: null,
            trim((string) ($anterior['proveedor_nombre'] ?? '')) ?: null,
            trim((string) ($anterior['nc_proveedor'] ?? '')) ?: null,
            trim((string) ($anterior['moneda'] ?? '')) ?: null,
            $estadoAnterior,
            $estadoNuevo,
            $xmlAnterior,
            $xmlNuevo,
            $diferenciaAnterior,
            $diferenciaNueva,
            $motivoAnterior,
            $motivoNuevo,
        ];
    }

    public function registrarCambioVerificacion($verificacionId, array $anterior, array $nuevo)
    {
        $valores = $this->filaHistorial($verificacionId, $anterior, $nuevo);
        if ($valores === null) {
            return false;
        }
        $this->registrarHistorialLote([$valores]);
        return true;
    }

    /** Guarda muchas filas de historial en tandas de una sola consulta. */
    public function registrarHistorialLote(array $filas, $tam = 200)
    {
        $columnas = implode(', ', self::COLUMNAS_HISTORIAL);
        $hueco = '(' . implode(', ', array_fill(0, count(self::COLUMNAS_HISTORIAL), '?')) . ')';

        $guardadas = 0;
        foreach (array_chunk(array_values($filas), max(1, (int) $tam)) as $tanda) {
            $params = [];
            foreach ($tanda as $valores) {
                foreach ($valores as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                "INSERT INTO notas_credito_historial ({$columnas}) VALUES "
                . implode(', ', array_fill(0, count($tanda), $hueco)),
                $params
            );
            $guardadas += count($tanda);
        }

        return $guardadas;
    }

    private static function valorDecimalHistorial($value)
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }

    public function finalizarVerificacion($verificacionId, array $stats, $cantidadCambios)
    {
        return $this->execute(
            "UPDATE notas_credito_verificaciones
             SET fecha_fin = NOW(), coincide = ?, con_diferencia = ?, sin_respaldo = ?, cantidad_cambios = ?
             WHERE id = ?",
            [
                (int) ($stats['coincide'] ?? 0),
                (int) ($stats['con_diferencia'] ?? 0),
                (int) ($stats['sin_respaldo'] ?? 0),
                (int) $cantidadCambios,
                (int) $verificacionId,
            ]
        );
    }

    public function getVerificaciones($listadoId, $limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));
        return $this->fetchAll(
            "SELECT id, listado_id, origen, fecha_inicio, fecha_fin, coincide,
                    con_diferencia, sin_respaldo, cantidad_cambios
             FROM notas_credito_verificaciones
             WHERE listado_id = ?
             ORDER BY id DESC
             LIMIT {$limit}",
            [(int) $listadoId]
        ) ?: [];
    }

    public function getCambiosVerificacion($verificacionId, $listadoId)
    {
        $sql = "SELECT h.*,
                       COALESCE(fa.numero_factura_asistente, fa.consecutivo_completo) AS xml_anterior,
                       COALESCE(fn.numero_factura_asistente, fn.consecutivo_completo) AS xml_nuevo
                FROM notas_credito_historial h
                LEFT JOIN facturas_xml fa ON fa.id = h.factura_xml_id_anterior
                LEFT JOIN facturas_xml fn ON fn.id = h.factura_xml_id_nuevo
                WHERE h.verificacion_id = ? AND h.listado_id = ?
                ORDER BY h.id ASC";
        return $this->fetchAll($sql, [(int) $verificacionId, (int) $listadoId]) ?: [];
    }

    public function actualizarMatch($lineaId, $facturaId, $estado, $diferencia, $metodo, $score, $manual, $motivo, $bloqueo = 0)
    {
        $sql = "UPDATE notas_credito_lineas
                SET factura_xml_id = ?, estado = ?, diferencia = ?, metodo_match = ?,
                    score_proveedor = ?, match_manual = ?, bloqueo_automatico = ?,
                    motivo_match = ?
                WHERE id = ?";
        return $this->execute($sql, [
            $facturaId ?: null,
            (string) $estado,
            $diferencia,
            (string) $metodo,
            $score,
            $manual ? 1 : 0,
            $bloqueo ? 1 : 0,
            $motivo ?: null,
            (int) $lineaId,
        ]);
    }

    /** Columnas que escribe el emparejamiento, en el orden del UPDATE por tandas. */
    private const COLUMNAS_MATCH = [
        'factura_xml_id', 'estado', 'diferencia', 'metodo_match',
        'score_proveedor', 'match_manual', 'bloqueo_automatico', 'motivo_match',
    ];

    /**
     * Aplica muchos emparejamientos en pocas consultas.
     *
     * Verificar un listado hacía un UPDATE por línea. Con la base en la misma
     * máquina eran décimas de segundo y nadie lo notó nunca; con la base en el
     * servidor cada uno cuesta la latencia de la red, y un listado de 2 100
     * líneas tardaba casi seis minutos: la pantalla moría en el límite de
     * ejecución de PHP sin decir por qué.
     *
     * Cada tanda resuelve hasta $tam líneas con un solo UPDATE, usando CASE
     * sobre el id. El `ELSE columna` deja intacta cualquier fila que entre por
     * el WHERE sin estar en la tanda: no puede borrar nada por accidente.
     *
     * @param array $filas Cada una con 'id' y las columnas de COLUMNAS_MATCH.
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
                'UPDATE notas_credito_lineas SET ' . implode(', ', $sets)
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($params, $ids)
            );
        }
        return $afectadas;
    }

    /**
     * Prepara una revalidación completa sin tocar vínculos manuales ni el
     * bloqueo solicitado por el usuario. Evita que un vínculo automático
     * anterior choque con la restricción de un XML por listado.
     */
    public function limpiarMatchesAutomaticos($listadoId)
    {
        $sql = "UPDATE notas_credito_lineas
                SET factura_xml_id = NULL,
                    estado = 'sin_respaldo',
                    diferencia = NULL,
                    metodo_match = 'ninguno',
                    score_proveedor = NULL,
                    motivo_match = NULL
                WHERE listado_id = ? AND match_manual = 0";
        return $this->execute($sql, [(int) $listadoId]);
    }

    public function getFacturasNcSociedad($cedula)
    {
        $cedula = preg_replace('/\D+/', '', (string) $cedula);
        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) AS moneda,
                       f.receptor_id, p.rfc AS proveedor_cedula,
                       p.razon_social AS proveedor_nombre,
                       p.alias AS proveedor_alias
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                ORDER BY f.id ASC";
        return $this->fetchAll($sql, [$cedula]) ?: [];
    }

    /**
     * A qué factura dice acreditar cada NC electrónica de la sociedad.
     *
     * Es el otro extremo del puente de las notas directas: el reporte del ERP
     * numera la línea con el consecutivo de la FACTURA, y el XML de la nota
     * cita esa misma factura en su InformacionReferencia. Con las dos puntas
     * la nota queda identificada sin depender del monto.
     *
     * Se devuelve la referencia en crudo además del consecutivo ya extraído:
     * el parser solo rellena consecutivo_ref cuando el proveedor escribió la
     * clave de 50 dígitos, y unos cuantos ponen directamente el consecutivo de
     * 20. Quien consume decide; acá no se adivina.
     */
    public function getReferenciasNcSociedad($cedula)
    {
        $cedula = preg_replace('/\D+/', '', (string) $cedula);
        $sql = "SELECT r.factura_xml_id, r.consecutivo_ref, r.numero_ref, r.tipo_doc_ref
                FROM facturas_xml_referencias r
                INNER JOIN facturas_xml f ON f.id = r.factura_xml_id
                WHERE f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?";
        try {
            return $this->fetchAll($sql, [$cedula]) ?: [];
        } catch (Throwable $e) {
            // Sin la tabla de detalle (instalación que aún no corrió el
            // backfill) el verificador sigue con los demás caminos.
            return [];
        }
    }

    public function getFacturaNcValida($facturaId, $cedula)
    {
        $cedula = preg_replace('/\D+/', '', (string) $cedula);
        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) AS moneda,
                       f.receptor_id, p.razon_social AS proveedor_nombre
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.id = ? AND f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $facturaId, $cedula]);
        return $row ?: null;
    }

    public function facturaUsadaEnListado($listadoId, $facturaId, $exceptLineaId = 0)
    {
        $sql = "SELECT id FROM notas_credito_lineas
                WHERE listado_id = ? AND factura_xml_id = ? AND id <> ?
                LIMIT 1";
        return (bool) $this->fetchOne($sql, [
            (int) $listadoId,
            (int) $facturaId,
            (int) $exceptLineaId,
        ]);
    }

    public function getCandidatasManuales(array $linea, $query = '')
    {
        $params = [
            preg_replace('/\D+/', '', (string) $linea['sociedad_cedula']),
            (string) $linea['moneda'],
            (int) $linea['listado_id'],
            (int) $linea['id'],
        ];
        $filter = '';
        if (trim((string) $query) !== '') {
            $like = '%' . trim((string) $query) . '%';
            $filter = " AND (f.consecutivo_completo LIKE ? OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)";
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.total, UPPER(f.moneda) moneda,
                       p.razon_social AS proveedor_nombre,
                       p.alias AS proveedor_alias
                FROM facturas_xml f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.tipo_documento = 'NC'
                  AND REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '') = ?
                  AND UPPER(f.moneda) = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM notas_credito_lineas usada
                      WHERE usada.listado_id = ? AND usada.factura_xml_id = f.id AND usada.id <> ?
                  )
                  {$filter}
                ORDER BY ABS(f.total - ?), f.id DESC
                LIMIT 100";
        $params[] = (float) $linea['monto'];
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getListadosPorSociedad($sociedadId)
    {
        return $this->fetchAll(
            "SELECT id FROM notas_credito_listados
              WHERE sociedad_id = ? ORDER BY id DESC LIMIT 1",
            [(int) $sociedadId]
        ) ?: [];
    }

    /** Última foto recibida; no es otro conjunto de notas. */
    public function ultimaCarga($sociedadId)
    {
        $row = $this->fetchOne(
            "SELECT c.*, li.nombre AS listado_nombre
               FROM notas_credito_cargas c
               LEFT JOIN notas_credito_listados li ON li.id = c.listado_id
              WHERE c.sociedad_id = ?
              ORDER BY c.creado_en DESC, c.id DESC LIMIT 1",
            [(int) $sociedadId]
        );
        return $row ?: null;
    }

    public function cargas($sociedadId, $limite = 20)
    {
        $limite = max(1, min(100, (int) $limite));
        return $this->fetchAll(
            "SELECT * FROM notas_credito_cargas
              WHERE sociedad_id = ? ORDER BY creado_en DESC, id DESC LIMIT {$limite}",
            [(int) $sociedadId]
        ) ?: [];
    }

    public function eliminarListado($id)
    {
        return $this->execute("DELETE FROM notas_credito_listados WHERE id = ?", [(int) $id]);
    }
}
