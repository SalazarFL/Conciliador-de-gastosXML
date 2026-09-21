-- Dónde vive cada documento: en la carpeta compartida o en SharePoint.
-- Es segura de correr más de una vez (MariaDB: IF NOT EXISTS).
--
-- Por qué dos niveles y no un ajuste de la instalación:
--
--   sociedades.almacen_documentos   dónde se archivan los documentos NUEVOS
--                                   de esa sociedad.
--   facturas_xml.almacen            dónde está ESE documento ahora mismo.
--
-- Mientras una sociedad se pasa a SharePoint, las dos respuestas discrepan
-- durante horas, y está bien: para leer manda la fila. Sin la columna por
-- fila el paso sería todo o nada, sin poder retomarlo ni volver atrás.
--
-- NULL en `almacen` significa disco: es todo lo archivado antes de esta
-- migración, y así sigue.
--
-- La ruta sigue siendo la dirección legible del documento; `item_*_id` es su
-- identidad en SharePoint, que no cambia aunque alguien lo mueva. Cuando las
-- dos discrepan, gana el id y la ruta se corrige (el reconciliador).
--
-- No hay ids en correo_bandeja ni en importacion_items: ese material vive
-- horas, y si alguien lo mueve a mano el remedio es volver a capturarlo. En
-- devoluciones sí, porque su PDF vive años.
--
-- Esta migración no mueve nada: con ella corrida todo sigue en disco hasta
-- que se cambie el ajuste de una sociedad.

ALTER TABLE `sociedades`
    ADD COLUMN IF NOT EXISTS `almacen_documentos` VARCHAR(10) NOT NULL DEFAULT 'disco'
        COMMENT 'disco | sharepoint: dónde se archiva lo nuevo' AFTER `cedula`;

ALTER TABLE `facturas_xml`
    ADD COLUMN IF NOT EXISTS `almacen` VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'NULL = disco (histórico) | sharepoint',
    ADD COLUMN IF NOT EXISTS `item_xml_id` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'Id permanente del XML en SharePoint',
    ADD COLUMN IF NOT EXISTS `item_pdf_id` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'Id permanente del PDF en SharePoint',
    ADD COLUMN IF NOT EXISTS `etag_xml` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'cTag del XML: si cambia, alguien lo editó',
    ADD COLUMN IF NOT EXISTS `etag_pdf` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'cTag del PDF',
    ADD INDEX IF NOT EXISTS `idx_item_xml` (`item_xml_id`),
    ADD INDEX IF NOT EXISTS `idx_item_pdf` (`item_pdf_id`),
    -- La revisión rotativa del reconciliador: "los de SharePoint, desde tal id".
    ADD INDEX IF NOT EXISTS `idx_almacen_id` (`almacen`, `id`);

-- Hasta dónde se leyeron los cambios de la biblioteca, y por dónde va la
-- revisión rotativa (ver app/models/AlmacenDelta.php). En la base y no en
-- cada PC: el enlace de cambios es uno solo para todas.
CREATE TABLE IF NOT EXISTS `almacen_delta` (
    `almacen` VARCHAR(20) NOT NULL,
    `drive_id` VARCHAR(191) NOT NULL DEFAULT '',
    `delta_link` TEXT NULL,
    `cursor_revision` INT UNSIGNED NOT NULL DEFAULT 0,
    `vuelta_completa_en` DATETIME NULL DEFAULT NULL,
    `actualizado_en` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`almacen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las copias de la carpeta del pago ya hechas en SharePoint, para no volver a
-- preguntarle por ellas cada cinco minutos (ver app/models/AlmacenCopiaPago.php).
CREATE TABLE IF NOT EXISTS `almacen_copias_pago` (
    `factura_id` INT UNSIGNED NOT NULL,
    `firma` CHAR(40) NOT NULL,
    `ruta_xml` VARCHAR(700) NULL DEFAULT NULL,
    `ruta_pdf` VARCHAR(700) NULL DEFAULT NULL,
    `copiado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`factura_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lo que se quiso deshacer en SharePoint y no se pudo (ver
-- app/models/AlmacenPendiente.php). La aplicación la crea sola; esto es para
-- donde el usuario de la base no tenga permiso de CREATE.
CREATE TABLE IF NOT EXISTS `almacen_pendientes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `accion` VARCHAR(20) NOT NULL DEFAULT 'borrar',
    `ruta` VARCHAR(700) NOT NULL DEFAULT '',
    `item_id` VARCHAR(128) NOT NULL DEFAULT '',
    `motivo` VARCHAR(255) NULL DEFAULT NULL,
    `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
    `error` VARCHAR(500) NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `intentado_en` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pendientes_intentos` (`intentos`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El paso a paso de pasar una empresa a SharePoint, un archivo por fila, para
-- poder cortar y retomar desde cualquier PC (ver app/models/AlmacenMigracion.php
-- y cli/migrar_documentos_a_graph.php).
CREATE TABLE IF NOT EXISTS `almacen_migracion` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sociedad_id` INT UNSIGNED NOT NULL,
    `tabla` VARCHAR(30) NOT NULL,
    `fila_id` INT UNSIGNED NOT NULL,
    `campo` VARCHAR(20) NOT NULL,
    `ruta` VARCHAR(700) NOT NULL,
    `sha256` CHAR(64) NULL DEFAULT NULL,
    `tamano` BIGINT UNSIGNED NULL DEFAULT NULL,
    `estado` VARCHAR(15) NOT NULL DEFAULT 'inventariado',
    `item_id` VARCHAR(128) NULL DEFAULT NULL,
    `etag` VARCHAR(128) NULL DEFAULT NULL,
    `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
    `error` VARCHAR(500) NULL DEFAULT NULL,
    `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_migracion_archivo` (`tabla`, `fila_id`, `campo`),
    KEY `idx_migracion_estado` (`sociedad_id`, `estado`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `devoluciones`
    ADD COLUMN IF NOT EXISTS `almacen` VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'NULL = disco (histórico) | sharepoint',
    ADD COLUMN IF NOT EXISTS `item_pdf_id` VARCHAR(128) NULL DEFAULT NULL
        COMMENT 'Id permanente del PDF en SharePoint';

-- Toda ruta de SharePoint se guarda con su prefijo: una ruta sin prefijo es de
-- disco, y así se lee bien aunque la consulta no traiga la columna `almacen`.
-- Las filas pasadas por cli/migrar_documentos_a_graph.php antes del 19/09/2026
-- quedaron sin él. Seguro de repetir: solo toca las que no lo tienen.
UPDATE `facturas_xml` SET `ruta_xml` = CONCAT('sharepoint:', `ruta_xml`)
 WHERE `almacen` = 'sharepoint' AND `ruta_xml` <> '' AND `ruta_xml` NOT LIKE 'sharepoint:%';
UPDATE `facturas_xml` SET `ruta_pdf` = CONCAT('sharepoint:', `ruta_pdf`)
 WHERE `almacen` = 'sharepoint' AND `ruta_pdf` <> '' AND `ruta_pdf` NOT LIKE 'sharepoint:%';
UPDATE `devoluciones` SET `ruta_pdf` = CONCAT('sharepoint:', `ruta_pdf`)
 WHERE `almacen` = 'sharepoint' AND `ruta_pdf` <> '' AND `ruta_pdf` NOT LIKE 'sharepoint:%';
