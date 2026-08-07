-- Migración: semanas de trabajo.
-- Todo se puede asignar a una semana elegida por el usuario (no acumulado):
-- las cargas de XML, las facturas importadas del correo y los listados de
-- facturas por pagar. La verificación de un listado se limita a las
-- facturas de SU semana.

CREATE TABLE IF NOT EXISTS `semanas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(100) NOT NULL,
    `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturas: la semana a la que el usuario asignó la carga/importación
-- (NULL = sin asignar, facturas anteriores a esta función)
ALTER TABLE `facturas_xml`
    ADD COLUMN `semana_id` INT UNSIGNED NULL DEFAULT NULL AFTER `importacion_id`,
    ADD KEY `idx_semana` (`semana_id`);

-- Listados por pagar: la semana contra la que se verifica
ALTER TABLE `porpagar_listados`
    ADD COLUMN `semana_id` INT UNSIGNED NULL DEFAULT NULL AFTER `sociedad_id`;
