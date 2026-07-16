-- Migración: marcas de "correo ya procesado" del archivo procesados.json
-- a la tabla correo_procesados.
--
-- El archivo se reescribía COMPLETO en cada marca (leer-modificar-escribir):
-- con varios usuarios procesando correos a la vez, dos peticiones podían
-- pisarse y perder marcas (correos que reaparecen como pendientes o se
-- importan dos veces). En MySQL cada marca es una fila y la escritura es
-- atómica, así que la carrera desaparece.
--
-- No hace falta correr esto a mano: CorreoProcesado::ensureTable crea la
-- tabla al primer uso e importa el procesados.json existente (el archivo
-- queda renombrado a procesados.json.migrado como respaldo).

CREATE TABLE IF NOT EXISTS `correo_procesados` (
    `clave` VARCHAR(100) NOT NULL COMMENT 'c{cuenta}:uidvalidity:uid del mensaje',
    `procesado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
