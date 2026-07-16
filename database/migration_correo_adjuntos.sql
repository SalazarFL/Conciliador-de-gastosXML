-- Migración: indexar el NOMBRE de los adjuntos en el índice local de correo.
--
-- El número/clave de la factura suele venir en el nombre del XML/PDF adjunto
-- (p. ej. 50608062600310153367000200003010000267790100000000.xml), aunque el
-- asunto sea genérico ("Comprobante Electrónico"). Guardando esos nombres,
-- buscar "267790" es una consulta local instantánea en TODAS las carpetas,
-- sin escaneo de contenido en el servidor ni tener que adivinar la carpeta.
--
-- Los nombres se leen de la estructura MIME (sin descargar el adjunto) en la
-- FASE 2 de la sincronización, por tandas dentro del presupuesto de tiempo
-- (leer la estructura es un viaje IMAP por mensaje: hacerlo junto con el
-- indexado de encabezados provocaba timeouts 500 en carpetas grandes).
-- Se guarda el nombre completo, no solo el consecutivo, porque no todos los
-- proveedores nombran el archivo con la clave.
--
-- adjuntos = NULL significa "aún no leído" (pendiente); '' = "sin adjuntos".
-- Los correos ya indexados quedan NULL y la fase 2 los va completando sola
-- en cada visita al módulo ("indexando nombres de archivos…"): no hay que
-- reindexar nada a mano.

ALTER TABLE correo_indice
    ADD COLUMN IF NOT EXISTS adjuntos VARCHAR(1024) NULL DEFAULT NULL AFTER asunto;
