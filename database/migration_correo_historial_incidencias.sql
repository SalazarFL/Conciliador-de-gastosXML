-- Historial navegable de incidencias del modo General de Correo.
ALTER TABLE `correo_incidencias`
    ADD COLUMN IF NOT EXISTS `asunto` VARCHAR(255) NULL AFTER `mensaje`;

-- Conserva una copia del asunto para las incidencias ya existentes.
UPDATE `correo_incidencias` x
JOIN `correo_lote_items` li ON li.id = x.lote_item_id
JOIN `correo_indice` i ON i.id = li.correo_indice_id
SET x.asunto = i.asunto
WHERE (x.asunto IS NULL OR x.asunto = '')
  AND i.asunto IS NOT NULL AND i.asunto <> '';
