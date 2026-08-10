-- Migración: bandeja de facturas capturadas del correo (IMAP)
-- Ejecutar solo en local (la integración de correo no corre en InfinityFree)

CREATE TABLE IF NOT EXISTS `correo_bandeja` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid_correo` VARCHAR(64) NOT NULL COMMENT 'uidvalidity:uid del mensaje IMAP (dedup)',
    `remitente` VARCHAR(255) NULL DEFAULT NULL,
    `asunto` VARCHAR(255) NULL DEFAULT NULL,
    `fecha_correo` DATETIME NULL DEFAULT NULL,
    `archivo_xml` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Ruta del XML en storage/correo/xml/',
    `archivo_pdf` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Ruta del PDF en storage/correo/pdf/',
    `numero_corto` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Número corto extraído del XML',
    `proveedor` VARCHAR(255) NULL DEFAULT NULL,
    `fecha_emision` DATE NULL DEFAULT NULL,
    `total` DECIMAL(15,2) NULL DEFAULT NULL,
    `hash_xml` CHAR(64) NULL DEFAULT NULL COMMENT 'sha256 del XML, para marcar "ya en el sistema"',
    `estado` ENUM('pendiente','importada','descartada','ya_existe','rechazada','otra_cedula') NOT NULL DEFAULT 'pendiente',
    `origen` ENUM('manual','automatica','descargas') NOT NULL DEFAULT 'manual',
    `importacion_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Importación con la que se procesó',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uid_xml` (`uid_correo`, `hash_xml`),
    KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para instalaciones que ya tenían la tabla:
-- estados 'rechazada' (Hacienda) y 'otra_cedula' (receptor ≠ empresa) + tipo de documento (FE/NC/ND)
ALTER TABLE `correo_bandeja`
    MODIFY `estado` ENUM('pendiente','importada','descartada','ya_existe','rechazada','otra_cedula') NOT NULL DEFAULT 'pendiente',
    ADD COLUMN `tipo_doc` VARCHAR(4) NOT NULL DEFAULT 'FE' AFTER `numero_corto`;

-- Índice local de encabezados del buzón: hace la búsqueda instantánea
-- (se sincroniza incremental; el contenido de los correos no se indexa)
CREATE TABLE IF NOT EXISTS `correo_indice` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `carpeta` VARCHAR(255) NOT NULL,
    `carpeta_nombre` VARCHAR(255) NOT NULL DEFAULT '',
    `uid` INT UNSIGNED NOT NULL,
    `uidvalidity` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clave` VARCHAR(64) NOT NULL,
    `remitente` VARCHAR(255) NULL DEFAULT NULL,
    `cc` VARCHAR(1024) NULL DEFAULT NULL,
    `reply_to` VARCHAR(255) NULL DEFAULT NULL,
    `consecutivo` VARCHAR(20) NULL DEFAULT NULL,
    `numero_corto` VARCHAR(10) NULL DEFAULT NULL,
    `asunto` VARCHAR(255) NULL DEFAULT NULL,
    `fecha` DATETIME NULL DEFAULT NULL,
    `timestamp` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_carpeta_uid` (`carpeta`(180), `uidvalidity`, `uid`),
    KEY `idx_consecutivo` (`consecutivo`),
    KEY `idx_numero_corto` (`numero_corto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `correo_carpetas` (
    `carpeta` VARCHAR(191) NOT NULL,
    `uidvalidity` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `ultimo_uid` INT UNSIGNED NOT NULL DEFAULT 0,
    `mensajes` INT UNSIGNED NOT NULL DEFAULT 0,
    `mensajes_omitidos` INT UNSIGNED NOT NULL DEFAULT 0,
    `retencion_dias` INT UNSIGNED NOT NULL DEFAULT 0,
    `ultima_sync` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`carpeta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
