-- De posponer a recordar.
--
-- `vence_el` era una fecha suelta: el renglón salía de la cola y volvía ese
-- día, a ninguna hora en particular y una sola vez. Un recordatorio de verdad
-- necesita saber la hora —a las ocho de la mañana no es lo mismo que a las
-- seis de la tarde— y cada cuánto insistir, porque un proveedor que no
-- contesta no contesta una sola vez.
--
--   recordar_en    cuándo empieza a molestar (fecha Y hora)
--   recordar_cada  cada cuántos días insiste; NULL = avisa una vez y calla
--   avisado_en     cuándo se dejó el último aviso en la campana
--
-- Lo pospuesto se conserva como un recordatorio de una sola vez a las ocho:
-- es la hora a la que alguien habría mirado la lista de todos modos, y nadie
-- eligió otra porque no se podía elegir.
--
-- Esto también lo hace el modelo Seguimiento al arrancar; la migración sirve
-- para aplicarlo explícitamente o donde no haya permisos DDL en runtime.

ALTER TABLE `seguimiento`
    ADD COLUMN `recordar_en` DATETIME NULL AFTER `responsable`,
    ADD COLUMN `recordar_cada` SMALLINT UNSIGNED NULL AFTER `recordar_en`,
    ADD COLUMN `avisado_en` DATETIME NULL AFTER `recordar_cada`;

UPDATE `seguimiento` SET `recordar_en` = TIMESTAMP(`vence_el`, '08:00:00')
 WHERE `vence_el` IS NOT NULL;

-- El índice viejo apuntaba a la columna que se va.
ALTER TABLE `seguimiento` DROP INDEX `idx_seguimiento_estado`;
ALTER TABLE `seguimiento` DROP COLUMN `vence_el`;
ALTER TABLE `seguimiento` ADD KEY `idx_seguimiento_recordar` (`estado`, `recordar_en`);
