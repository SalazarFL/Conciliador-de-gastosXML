-- Migración: incluir Reply-To (Responder a) en el índice local de correo.
-- NULL significa pendiente de leer; cadena vacía significa sin Reply-To.

ALTER TABLE correo_indice
    ADD COLUMN IF NOT EXISTS reply_to VARCHAR(1024) NULL DEFAULT NULL AFTER cc;
