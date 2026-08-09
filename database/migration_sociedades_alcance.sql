-- Migración: alcance por sociedad
--
-- Hasta aquí el sistema se comportaba como si existiera una sola empresa. La
-- validación al importar ya impedía que entrara un XML de otra sociedad
-- (XmlDocumentImporter comprueba el receptor), pero eso controla la ENTRADA,
-- no la LECTURA: las consultas de Facturas, Por pagar y Devoluciones piden
-- todo lo que hay en la tabla sin preguntar de quién es. Con una sola empresa
-- el filtro era implícito; con dos, los listados se mezclarían.
--
-- Cada documento queda sellado con la sociedad que estaba seleccionada cuando
-- se importó, y nunca se vuelve a deducir. Para los XML el sello no es una
-- suposición: `facturas_xml.receptor_id` ya trae la cédula a la que va
-- dirigido el comprobante, así que el relleno histórico es exacto.
--
-- Decisiones tomadas con el usuario (2026-08-08):
--   * Un buzón de correo puede servir a VARIAS sociedades -> tabla N:M.
--     Los datos lo respaldan: los tres buzones (grupobmsp, automercado, cedi)
--     reciben facturas a nombre de GRUPO BM SP, así que "automercado" y
--     "cedi" son sucursales, no empresas aparte.
--   * Las semanas de pago son de cada sociedad, no del grupo.
--   * `proveedores` NO se separa: un mismo proveedor le factura a varias
--     empresas del grupo y dividirlo rompería el emparejamiento.

-- ── Documentos XML ──
-- El receptor del comprobante es el dueño; se rellena por cédula.
ALTER TABLE `facturas_xml`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Sociedad dueña del documento (receptor del XML)' AFTER `receptor_id`,
    ADD INDEX `idx_facturas_xml_sociedad` (`sociedad_id`),
    ADD INDEX `idx_facturas_xml_sociedad_tipo` (`sociedad_id`, `tipo_documento`);

UPDATE `facturas_xml` f
  JOIN `sociedades` s
    ON REPLACE(REPLACE(REPLACE(REPLACE(f.receptor_id, '-', ''), ' ', ''), '.', ''), '/', '')
     = REPLACE(REPLACE(REPLACE(REPLACE(s.cedula,      '-', ''), ' ', ''), '.', ''), '/', '')
   SET f.sociedad_id = s.id
 WHERE f.sociedad_id IS NULL;

-- ── Facturas del ERP ──
-- El reporte "Facturas por Proveedor" no menciona la cédula en ninguna parte,
-- así que aquí no hay nada que deducir: la sociedad se pregunta al cargar y
-- las filas la heredan de su carga.
ALTER TABLE `facturas_erp_cargas`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Sociedad a la que pertenece el reporte cargado' AFTER `archivo_origen`,
    ADD INDEX `idx_facturas_erp_cargas_sociedad` (`sociedad_id`);

ALTER TABLE `facturas_erp`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Heredada de la carga que la trajo' AFTER `clave`,
    ADD INDEX `idx_facturas_erp_sociedad` (`sociedad_id`),
    ADD INDEX `idx_facturas_erp_sociedad_doc` (`sociedad_id`, `documento`);

-- ── Semanas de pago ──
ALTER TABLE `semanas`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD INDEX `idx_semanas_sociedad` (`sociedad_id`);

-- ── Bandeja del correo ──
-- Es zona de paso (el XML baja aquí antes de importarse), pero su listado
-- también debe respetar la empresa en la que se está trabajando.
ALTER TABLE `correo_bandeja`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL AFTER `cuenta_id`,
    ADD INDEX `idx_correo_bandeja_sociedad` (`sociedad_id`);

-- ── Buzones ↔ sociedades (N:M) ──
-- Un buzón puede recibir facturas de varias empresas del grupo, y una empresa
-- puede tener varios buzones. Sin fila aquí, la cuenta no aparece al trabajar
-- en esa sociedad.
CREATE TABLE IF NOT EXISTS `correo_cuenta_sociedades` (
    `cuenta_id` INT UNSIGNED NOT NULL,
    `sociedad_id` INT UNSIGNED NOT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`cuenta_id`, `sociedad_id`),
    KEY `idx_cuenta_sociedad_sociedad` (`sociedad_id`),
    CONSTRAINT `fk_cuenta_sociedad_cuenta` FOREIGN KEY (`cuenta_id`)
        REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cuenta_sociedad_sociedad` FOREIGN KEY (`sociedad_id`)
        REFERENCES `sociedades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Relleno histórico ──
-- Todo lo cargado hasta hoy se hizo con una única sociedad seleccionada, así
-- que le corresponde a ella. Se toma la activa para no fijar un id a mano.
SET @sociedad_base := (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1);
SET @sociedad_base := COALESCE(@sociedad_base, (SELECT MIN(id) FROM `sociedades`));

UPDATE `facturas_erp_cargas` SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;
UPDATE `facturas_erp`        SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;
UPDATE `semanas`             SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;
UPDATE `correo_bandeja`      SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;
UPDATE `devoluciones`        SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL OR `sociedad_id` = 0;

-- Los buzones existentes quedan disponibles para la sociedad base; desde el ⚙
-- se les pueden agregar o quitar sociedades.
INSERT IGNORE INTO `correo_cuenta_sociedades` (`cuenta_id`, `sociedad_id`)
SELECT c.id, @sociedad_base FROM `correo_cuentas` c WHERE @sociedad_base IS NOT NULL;
