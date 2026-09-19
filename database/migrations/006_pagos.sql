-- =========================================================================
-- 006_pagos.sql — El pago deja de ser semanal
-- =========================================================================
--
-- Un pago no es una semana. Puede ser de un día, pueden ser tres en la misma
-- semana, puede ser quincenal. Llamarlo "semana" obligaba a inventar nombres
-- como "Semana pago 2708" para un pago del jueves, y dejaba sin forma de
-- ordenar dos pagos del mismo lunes.
--
-- Esta migración hace dos cosas:
--
--   1. Renombra: `semanas` → `pagos`, y `semana_id` → `pago_id` donde sea que
--      estuviera. Es el mismo dato con el nombre correcto; nada se mueve.
--   2. Le da al pago su propia fecha (`fecha_pago`), que es lo que lo
--      identifica cuando ya no hay semana que lo haga. Los que ya existen la
--      heredan del día en que se crearon.
--
-- Los modelos aplican esto solos la primera vez que alguien entra (Pago,
-- FacturaErp, Factura y PorPagar lo hacen en su ensureTable). Este archivo es
-- para los servidores donde el usuario de la web NO tiene permisos de DDL:
-- ahí hay que correrlo a mano una vez.
--
--   mysql -h SERVIDOR -P 3307 -u root -p bd_xmlconcilia < 006_pagos.sql
--
-- Es idempotente: se puede correr dos veces sin romper nada.
-- =========================================================================


-- ── La tabla ─────────────────────────────────────────────────────────────
-- RENAME TABLE no tiene IF EXISTS, así que se pregunta antes. Si ya se
-- renombró, esto no hace nada; si es una instalación nueva, tampoco: la crea
-- el modelo con el nombre bueno.
SET @hay_semanas = (SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'semanas');
SET @hay_pagos   = (SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos');
SET @sql = IF(@hay_semanas = 1 AND @hay_pagos = 0,
              'RENAME TABLE `semanas` TO `pagos`',
              'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Por si la instalación es tan vieja que ni siquiera tenía la tabla.
CREATE TABLE IF NOT EXISTS `pagos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
    `nombre` VARCHAR(100) NOT NULL,
    `fecha_pago` DATE NULL DEFAULT NULL,
    `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pagos_sociedad` (`sociedad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── La fecha del pago ────────────────────────────────────────────────────
-- Lo que lo identifica ahora que no hay semana. Se deja NULL-able: un pago
-- puede nacer sin fecha decidida y ganarla después.
ALTER TABLE `pagos`
    ADD COLUMN IF NOT EXISTS `fecha_pago` DATE NULL DEFAULT NULL AFTER `nombre`;

ALTER TABLE `pagos`
    ADD INDEX IF NOT EXISTS `idx_pagos_fecha` (`sociedad_id`, `fecha_pago`);

-- Los pagos que ya existían no tenían fecha propia. La del día en que se
-- crearon es la mejor aproximación que hay y no inventa nada: se cargaban el
-- mismo día del pago.
UPDATE `pagos` SET `fecha_pago` = DATE(`creado_en`) WHERE `fecha_pago` IS NULL;


-- ── Quien apuntaba a la semana ───────────────────────────────────────────
ALTER TABLE `porpagar_listados`
    CHANGE COLUMN IF EXISTS `semana_id` `pago_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `facturas_xml`
    CHANGE COLUMN IF EXISTS `semana_id` `pago_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `facturas_erp`
    CHANGE COLUMN IF EXISTS `semana_id` `pago_id` INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `facturas_erp`
    CHANGE COLUMN IF EXISTS `asignada_semana_en` `asignada_pago_en` DATETIME NULL DEFAULT NULL;


-- ── El estado de la factura del ERP ──────────────────────────────────────
-- 'asignada_semana' decía dónde entró la factura, y ese dónde es un pago. Se
-- ensancha el enum, se mueven las filas y se vuelve a cerrar: así ninguna
-- queda un instante con un valor que la columna no admite.
ALTER TABLE `facturas_erp`
    MODIFY COLUMN `estado` ENUM('pendiente','asignada_semana','asignada_pago')
    NOT NULL DEFAULT 'pendiente';

UPDATE `facturas_erp` SET `estado` = 'asignada_pago' WHERE `estado` = 'asignada_semana';

ALTER TABLE `facturas_erp`
    MODIFY COLUMN `estado` ENUM('pendiente','asignada_pago')
    NOT NULL DEFAULT 'pendiente';


-- ── Los índices que llevaban el nombre viejo ─────────────────────────────
-- Renombrar la columna no renombra el índice: seguiría llamándose idx_semana
-- sobre una columna que ya no existe con ese nombre. Se rehacen, que en estas
-- tablas cuesta segundos.
ALTER TABLE `porpagar_listados` DROP INDEX IF EXISTS `idx_semana`;
ALTER TABLE `porpagar_listados` ADD INDEX IF NOT EXISTS `idx_pago` (`pago_id`);

ALTER TABLE `facturas_erp` DROP INDEX IF EXISTS `idx_semana`;
ALTER TABLE `facturas_erp` ADD INDEX IF NOT EXISTS `idx_pago` (`pago_id`);

ALTER TABLE `facturas_xml` DROP INDEX IF EXISTS `idx_semana`;
ALTER TABLE `facturas_xml` ADD INDEX IF NOT EXISTS `idx_pago` (`pago_id`);

ALTER TABLE `pagos` DROP INDEX IF EXISTS `idx_semanas_sociedad`;
ALTER TABLE `pagos` ADD INDEX IF NOT EXISTS `idx_pagos_sociedad` (`sociedad_id`);
