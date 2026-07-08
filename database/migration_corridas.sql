-- Migración: corridas de conciliación guardadas
-- Ejecutar en local (phpMyAdmin o mysql CLI) y en producción (phpMyAdmin InfinityFree)

CREATE TABLE IF NOT EXISTS `conciliacion_corridas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(255) NOT NULL COMMENT 'Nombre identificador: importación XML + archivo gastos',
    `importacion_xml_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a importaciones (tipo xml), NULL = todas',
    `importacion_gastos_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a importaciones (tipo gastos), NULL = todas',
    `total_registros` INT UNSIGNED NOT NULL DEFAULT 0,
    `fecha_ejecucion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_corrida_fecha` (`fecha_ejecucion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `conciliaciones`
    ADD COLUMN `corrida_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD KEY `idx_conc_corrida` (`corrida_id`);
