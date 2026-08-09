-- Rutas relativas a la carpeta compartida + ningún documento dentro de la base
--
-- Contexto: la aplicación se instala en cada computadora y comparte una sola
-- base de datos en el servidor. Los XML y PDF viven en la carpeta sincronizada
-- de SharePoint, que cada máquina ve en una ubicación distinta. Guardar la ruta
-- completa hacía que un documento solo se abriera en la computadora que lo
-- importó; a partir de aquí se guarda relativa a esa carpeta.
--
-- Este archivo hace solo los cambios de estructura. La conversión de los datos
-- y el traslado de los archivos que hoy están dentro del proyecto los hace
--     php cli/migrar_rutas_relativas.php --aplicar
-- que además deja un respaldo de las rutas anteriores. Córrelo ANTES de este
-- script si quieres poder revisar el resultado con las dos formas a la vista.

-- 1) El contenido del XML nunca más en la base -----------------------------
--    Ya está vacía (la migración al árbol de archivos terminó); la columna
--    solo quedaba como puerta abierta a volver a llenarla.
ALTER TABLE `facturas_xml` DROP COLUMN `xml_contenido`;

-- 2) Espacio suficiente para la ruta relativa ------------------------------
--    Son más cortas que las absolutas, así que ningún dato se trunca; se
--    normalizan los anchos para que las cuatro tablas coincidan.
ALTER TABLE `facturas_xml`
    MODIFY `ruta_xml` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida de documentos',
    MODIFY `ruta_pdf` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida de documentos';

ALTER TABLE `correo_bandeja`
    MODIFY `archivo_xml` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/BANDEJA/xml)',
    MODIFY `archivo_pdf` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/BANDEJA/pdf)';

ALTER TABLE `devoluciones`
    MODIFY `ruta_pdf` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/DEVOLUCIONES)';

ALTER TABLE `importacion_items`
    MODIFY `ruta_archivo` VARCHAR(500) NOT NULL
        COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/IMPORTACIONES)';

ALTER TABLE `importaciones`
    MODIFY `ruta_archivo` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/IMPORTACIONES)';
