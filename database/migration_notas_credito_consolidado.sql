-- Notas de crédito: pasar de una foto/listado por CSV a un maestro acumulativo.
--
-- La identidad entre cargas se calcula en PHP con:
--   sociedad + proveedor + sucursal + número normalizado + moneda + monto.
-- El listado más reciente de cada sociedad es el contenedor canónico. Al
-- ejecutar cli/consolidar_notas_credito.php --aplicar (o confirmar la primera
-- carga con esta versión), el modelo mueve hacia él las identidades que solo
-- existían en fotos anteriores y conserva sus IDs y listado de origen.

CREATE TABLE IF NOT EXISTS `notas_credito_cargas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `listado_id` INT UNSIGNED NULL DEFAULT NULL,
    `sociedad_id` INT UNSIGNED NOT NULL,
    `listado_legacy_id` INT UNSIGNED NULL DEFAULT NULL,
    `archivo_origen` VARCHAR(255) NOT NULL,
    `archivo_ruta` VARCHAR(500) NULL DEFAULT NULL,
    `archivo_hash` CHAR(64) NOT NULL,
    `empresa_reporte` VARCHAR(255) NULL DEFAULT NULL,
    `periodo_desde` DATE NULL DEFAULT NULL,
    `periodo_hasta` DATE NULL DEFAULT NULL,
    `filas_leidas` INT UNSIGNED NOT NULL DEFAULT 0,
    `insertadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `actualizadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `sin_cambio` INT UNSIGNED NOT NULL DEFAULT 0,
    `recuperadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `filas_invalidas` INT UNSIGNED NOT NULL DEFAULT 0,
    `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_nc_carga_legacy` (`listado_legacy_id`),
    KEY `idx_nc_carga_sociedad` (`sociedad_id`, `creado_en`),
    KEY `idx_nc_carga_listado` (`listado_id`),
    KEY `idx_nc_carga_hash` (`archivo_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una foto idéntica ya no se rechaza: se registra como otra carga y produce
-- cero cambios si todos sus saldos siguen iguales. Las comprobaciones hacen
-- que este archivo se pueda ejecutar de nuevo tras una migración interrumpida.
SET @nc_tiene_hash_unico := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_listados'
       AND INDEX_NAME = 'uk_nc_archivo_hash' AND NON_UNIQUE = 0
);
SET @nc_sql := IF(@nc_tiene_hash_unico > 0,
    'ALTER TABLE notas_credito_listados DROP INDEX uk_nc_archivo_hash',
    'SELECT 1');
PREPARE nc_stmt FROM @nc_sql; EXECUTE nc_stmt; DEALLOCATE PREPARE nc_stmt;

SET @nc_tiene_hash_indice := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_listados'
       AND INDEX_NAME = 'idx_nc_archivo_hash'
);
SET @nc_sql := IF(@nc_tiene_hash_indice = 0,
    'ALTER TABLE notas_credito_listados ADD KEY idx_nc_archivo_hash (archivo_hash)',
    'SELECT 1');
PREPARE nc_stmt FROM @nc_sql; EXECUTE nc_stmt; DEALLOCATE PREPARE nc_stmt;

DROP PROCEDURE IF EXISTS `nc_agregar_columna`;
DELIMITER $$
CREATE PROCEDURE `nc_agregar_columna`(IN p_columna VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_lineas'
           AND COLUMN_NAME = p_columna
    ) THEN
        SET @nc_sql = p_ddl;
        PREPARE nc_stmt FROM @nc_sql;
        EXECUTE nc_stmt;
        DEALLOCATE PREPARE nc_stmt;
    END IF;
END$$
DELIMITER ;

CALL `nc_agregar_columna`('saldo_anterior',
    'ALTER TABLE notas_credito_lineas ADD COLUMN saldo_anterior DECIMAL(18,2) NULL DEFAULT NULL AFTER saldo');
CALL `nc_agregar_columna`('listado_origen_id',
    'ALTER TABLE notas_credito_lineas ADD COLUMN listado_origen_id INT UNSIGNED NULL DEFAULT NULL AFTER listado_id, ADD KEY idx_nc_linea_listado_origen (listado_origen_id)');
CALL `nc_agregar_columna`('carga_id',
    'ALTER TABLE notas_credito_lineas ADD COLUMN carga_id INT UNSIGNED NULL DEFAULT NULL AFTER datos_origen, ADD KEY idx_nc_linea_carga (carga_id)');
CALL `nc_agregar_columna`('carga_cambio_id',
    'ALTER TABLE notas_credito_lineas ADD COLUMN carga_cambio_id INT UNSIGNED NULL DEFAULT NULL AFTER carga_id, ADD KEY idx_nc_linea_carga_cambio (carga_cambio_id)');
CALL `nc_agregar_columna`('saldo_cambiado_en',
    'ALTER TABLE notas_credito_lineas ADD COLUMN saldo_cambiado_en DATETIME NULL DEFAULT NULL AFTER carga_cambio_id');
CALL `nc_agregar_columna`('creado_en',
    'ALTER TABLE notas_credito_lineas ADD COLUMN creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER motivo_match');
DROP PROCEDURE `nc_agregar_columna`;

DROP PROCEDURE IF EXISTS `nc_agregar_indice`;
DELIMITER $$
CREATE PROCEDURE `nc_agregar_indice`(IN p_indice VARCHAR(64), IN p_ddl TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notas_credito_lineas'
           AND INDEX_NAME = p_indice
    ) THEN
        SET @nc_sql = p_ddl;
        PREPARE nc_stmt FROM @nc_sql;
        EXECUTE nc_stmt;
        DEALLOCATE PREPARE nc_stmt;
    END IF;
END$$
DELIMITER ;

CALL `nc_agregar_indice`('idx_nc_linea_listado_origen',
    'ALTER TABLE notas_credito_lineas ADD KEY idx_nc_linea_listado_origen (listado_origen_id)');
CALL `nc_agregar_indice`('idx_nc_linea_carga',
    'ALTER TABLE notas_credito_lineas ADD KEY idx_nc_linea_carga (carga_id)');
CALL `nc_agregar_indice`('idx_nc_linea_carga_cambio',
    'ALTER TABLE notas_credito_lineas ADD KEY idx_nc_linea_carga_cambio (carga_cambio_id)');
DROP PROCEDURE `nc_agregar_indice`;

-- Conservar cada cabecera histórica como auditoría de una foto ya recibida.
INSERT IGNORE INTO `notas_credito_cargas`
    (`listado_id`, `sociedad_id`, `listado_legacy_id`, `archivo_origen`, `archivo_ruta`,
     `archivo_hash`, `empresa_reporte`, `periodo_desde`, `periodo_hasta`,
     `filas_leidas`, `insertadas`, `creado_en`)
SELECT l.id, l.sociedad_id, l.id, l.archivo_origen, l.archivo_ruta,
       l.archivo_hash, l.empresa_reporte, l.periodo_desde, l.periodo_hasta,
       l.total_lineas, l.total_lineas, l.fecha_subida
  FROM `notas_credito_listados` l
 WHERE NOT EXISTS (
       SELECT 1 FROM `notas_credito_cargas` existente
        WHERE existente.listado_id = l.id
 );
