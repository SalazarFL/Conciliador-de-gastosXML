-- Carpeta local elegida para reunir los XML/PDF de cada pago semanal.
-- Ejecutar una sola vez en instalaciones que ya tienen la tabla semanas.

ALTER TABLE `semanas`
    ADD COLUMN `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL AFTER `nombre`;
