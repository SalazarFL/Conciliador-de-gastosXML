-- Migración: módulo "Facturas por pagar" + sociedades + correo multi-cuenta
-- Reemplaza el enfoque de gastos: el listado semanal de facturas por pagar
-- se verifica contra las facturas XML del sistema.

-- ── Sociedades (empresas del grupo; la activa define la cédula contra la
--    que el módulo de correo verifica el receptor de cada factura) ──
CREATE TABLE IF NOT EXISTS `sociedades` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(255) NOT NULL,
    `cedula` VARCHAR(30) NOT NULL,
    `activa` TINYINT(1) NOT NULL DEFAULT 0,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cuentas de correo IMAP (la empresa tiene varios buzones; se eligen
--    desde la interfaz; password en base64 — herramienta local) ──
CREATE TABLE IF NOT EXISTS `correo_cuentas` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Listados semanales de facturas por pagar ──
CREATE TABLE IF NOT EXISTS `porpagar_listados` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `porpagar_facturas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `listado_id` INT UNSIGNED NOT NULL,
    `fecha` DATE NULL DEFAULT NULL COMMENT 'Fecha del listado, solo informativa',
    `numero` VARCHAR(100) NOT NULL,
    `proveedor_texto` VARCHAR(255) NOT NULL,
    `total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
    `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL,
    `estado` ENUM('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
    `diferencia` DECIMAL(18,2) NULL DEFAULT NULL,
    `score_numero` DECIMAL(5,1) NULL DEFAULT NULL,
    `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_listado` (`listado_id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_factura_erp` (`factura_erp_id`),
    CONSTRAINT `fk_porpagar_listado` FOREIGN KEY (`listado_id`)
        REFERENCES `porpagar_listados` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── facturas_xml: campos que el parser ya extrae y hoy se descartan
--    (tipo_documento excluye NC/ND del matching; clave y receptor quedan
--    listos para la futura función de notas de crédito) ──
ALTER TABLE `facturas_xml`
    ADD COLUMN `clave` VARCHAR(60) NULL DEFAULT NULL COMMENT 'Clave Hacienda (50 dígitos)' AFTER `consecutivo_completo`,
    ADD COLUMN `tipo_documento` VARCHAR(4) NULL DEFAULT NULL COMMENT 'FE/NC/ND (NULL = legado, se asume FE)' AFTER `clave`,
    ADD COLUMN `receptor_id` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Cédula del receptor del XML' AFTER `tipo_documento`;

-- ── Correo multi-cuenta: cada fila del índice/carpetas/bandeja pertenece a
--    una cuenta (0 = legado, se reasigna al sembrar la cuenta principal) ──
ALTER TABLE `correo_indice`
    ADD COLUMN `cuenta_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`,
    DROP INDEX `uk_carpeta_uid`,
    ADD UNIQUE KEY `uk_cuenta_carpeta_uid` (`cuenta_id`, `carpeta`(170), `uidvalidity`, `uid`);

ALTER TABLE `correo_carpetas`
    ADD COLUMN `cuenta_id` INT UNSIGNED NOT NULL DEFAULT 0 FIRST,
    DROP PRIMARY KEY,
    MODIFY `carpeta` VARCHAR(180) NOT NULL,
    ADD PRIMARY KEY (`cuenta_id`, `carpeta`);

ALTER TABLE `correo_bandeja`
    ADD COLUMN `cuenta_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`;
