-- Marca de "la base promete un archivo que no está en el disco".
-- Ejecutar una sola vez.
--
-- Por qué: hasta ahora el listado decía "respaldada" mientras el XML y el PDF
-- ya no existían, y uno se enteraba al hacer clic. La columna la pone y la
-- quita el organizador en cada revisión (OrganizadorDocumentos::anotarFaltantes),
-- así que no hay nada que mantener a mano: si el archivo vuelve, se limpia.
--
-- NULL = el archivo está donde dice la ruta, o el documento nunca tuvo ruta
-- (los históricos que entraron antes del archivo local: esos no son un
-- faltante, son un documento que solo existe como registro).

ALTER TABLE `facturas_xml`
    ADD COLUMN `archivo_faltante_en` DATETIME NULL DEFAULT NULL
        COMMENT 'Desde cuándo se sabe que el archivo de la ruta no está' AFTER `estado_pdf`,
    ADD INDEX `idx_archivo_faltante` (`archivo_faltante_en`);
