-- Cierre de pagos semanales y trazabilidad hacia el listado de Facturas ERP.
-- Las columnas también se crean de forma defensiva desde los modelos; esta
-- migración permite desplegarlas explícitamente en instalaciones existentes.

ALTER TABLE `porpagar_listados`
    ADD COLUMN IF NOT EXISTS `estado` ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto' AFTER `total_lineas`,
    ADD COLUMN IF NOT EXISTS `cerrado_en` DATETIME NULL DEFAULT NULL AFTER `estado`,
    ADD COLUMN IF NOT EXISTS `cerrado_por` INT UNSIGNED NULL DEFAULT NULL AFTER `cerrado_en`;

ALTER TABLE `porpagar_facturas`
    ADD COLUMN IF NOT EXISTS `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL AFTER `factura_xml_id`;

ALTER TABLE `facturas_erp`
    ADD COLUMN IF NOT EXISTS `estado` ENUM('pendiente','asignada_semana') NOT NULL DEFAULT 'pendiente' AFTER `saldo_cambiado_en`,
    ADD COLUMN IF NOT EXISTS `semana_id` INT UNSIGNED NULL DEFAULT NULL AFTER `estado`,
    ADD COLUMN IF NOT EXISTS `porpagar_listado_id` INT UNSIGNED NULL DEFAULT NULL AFTER `semana_id`,
    ADD COLUMN IF NOT EXISTS `asignada_semana_en` DATETIME NULL DEFAULT NULL AFTER `porpagar_listado_id`;

-- Los CREATE INDEX separados mantienen la migración compatible con datos
-- existentes. Si un índice ya existe, el modelo no vuelve a crearlo.
CREATE INDEX IF NOT EXISTS `idx_estado_pago` ON `porpagar_listados` (`estado`);
CREATE INDEX IF NOT EXISTS `idx_factura_erp` ON `porpagar_facturas` (`factura_erp_id`);
CREATE INDEX IF NOT EXISTS `idx_estado` ON `facturas_erp` (`estado`);
CREATE INDEX IF NOT EXISTS `idx_semana` ON `facturas_erp` (`semana_id`);
CREATE INDEX IF NOT EXISTS `idx_porpagar_listado` ON `facturas_erp` (`porpagar_listado_id`);
