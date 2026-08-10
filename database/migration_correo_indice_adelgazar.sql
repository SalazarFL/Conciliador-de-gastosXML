-- Reduce correo_indice sin tocar los mensajes IMAP ni los documentos locales.
-- Ejecutar con la sincronización pausada. Para podar filas y medir el antes/
-- después se usa cli/adelgazar_correo_indice.php, que también toma el lock.

ALTER TABLE correo_cuentas
    ADD COLUMN IF NOT EXISTS indice_retencion_dias
        INT UNSIGNED NOT NULL DEFAULT 1825 AFTER dias_atras;

ALTER TABLE correo_carpetas
    ADD COLUMN IF NOT EXISTS mensajes_omitidos
        INT UNSIGNED NOT NULL DEFAULT 0 AFTER mensajes,
    ADD COLUMN IF NOT EXISTS retencion_dias
        INT UNSIGNED NOT NULL DEFAULT 0 AFTER mensajes_omitidos;

-- La medición previa debe devolver 0. Si devuelve filas, no acortar hasta
-- decidir cómo tratar esos Reply-To atípicos.
SELECT COUNT(*) AS reply_to_demasiado_largos
FROM correo_indice
WHERE CHAR_LENGTH(reply_to) > 255;

-- idx_timestamp queda cubierto por idx_cuenta_timestamp_id porque todas las
-- consultas de la aplicación se acotan por cuenta. ALGORITHM=COPY compacta la
-- tabla y reconstruye el FULLTEXT, eliminando su historial de borrados.
ALTER TABLE correo_indice
    DROP INDEX IF EXISTS idx_timestamp,
    MODIFY reply_to VARCHAR(255) NULL DEFAULT NULL,
    ALGORITHM=COPY;

ANALYZE TABLE correo_indice;
