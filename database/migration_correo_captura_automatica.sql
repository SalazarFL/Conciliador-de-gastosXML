-- Cola persistente para capturar solamente correos nuevos.
-- "capturado" significa que el documento está en correo_bandeja; no que fue
-- importado. La importación y el archivo final continúan siendo manuales.

CREATE TABLE IF NOT EXISTS `correo_capturas_auto` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cuenta_id` INT UNSIGNED NOT NULL,
    `carpeta` VARCHAR(255) NOT NULL,
    `uidvalidity` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `uid` INT UNSIGNED NOT NULL,
    `clave` VARCHAR(100) NOT NULL,
    `fecha_correo` DATETIME NULL,
    `estado` ENUM('pendiente','procesando','capturado','sin_documentos','error')
        NOT NULL DEFAULT 'pendiente',
    `intentos` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `documentos` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `detalle` VARCHAR(1000) NULL,
    `detectado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `iniciado_en` DATETIME NULL,
    `terminado_en` DATETIME NULL,
    `reintentar_en` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_captura_clave` (`clave`),
    KEY `idx_captura_cuenta_estado` (`cuenta_id`, `estado`, `reintentar_en`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatible con instalaciones existentes y reejecuciones de la migración.
SET @sql_origen = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'correo_bandeja'
          AND COLUMN_NAME = 'origen'
    ),
    'SELECT 1',
    'ALTER TABLE correo_bandeja ADD COLUMN origen ENUM(''manual'',''automatica'',''descargas'') NOT NULL DEFAULT ''manual'' AFTER estado'
);
PREPARE stmt_origen FROM @sql_origen;
EXECUTE stmt_origen;
DEALLOCATE PREPARE stmt_origen;
