-- Migración: incluir destinatarios CC en el índice local de correo.
--
-- cc = NULL significa "encabezado pendiente de leer"; cadena vacía indica
-- que el mensaje no tiene destinatarios CC. La sincronización completa los
-- correos existentes gradualmente, sin descargar cuerpos ni adjuntos.

ALTER TABLE correo_indice
    ADD COLUMN IF NOT EXISTS cc VARCHAR(1024) NULL DEFAULT NULL AFTER remitente;
