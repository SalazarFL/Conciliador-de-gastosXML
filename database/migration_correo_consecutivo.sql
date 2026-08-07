-- Migración: campo compacto e indexado para buscar facturas por consecutivo.

ALTER TABLE correo_indice
    ADD COLUMN IF NOT EXISTS consecutivo VARCHAR(20) NULL DEFAULT NULL AFTER reply_to,
    ADD COLUMN IF NOT EXISTS numero_corto VARCHAR(10) NULL DEFAULT NULL AFTER consecutivo,
    ADD INDEX IF NOT EXISTS idx_cuenta_consecutivo (cuenta_id, consecutivo),
    ADD INDEX IF NOT EXISTS idx_cuenta_numero_corto (cuenta_id, numero_corto);

-- Clave CR: 3 país + 6 fecha + 12 cédula + 20 consecutivo + 1 situación + 8 seguridad.
UPDATE correo_indice
SET consecutivo = CASE
    WHEN REGEXP_SUBSTR(CONCAT_WS(' ', asunto, adjuntos), '[0-9]{50}') <> ''
        THEN SUBSTRING(REGEXP_SUBSTR(CONCAT_WS(' ', asunto, adjuntos), '[0-9]{50}'), 22, 20)
    ELSE NULLIF(REGEXP_SUBSTR(CONCAT_WS(' ', asunto, adjuntos), '[0-9]{20}'), '')
END
WHERE consecutivo IS NULL;

UPDATE correo_indice
SET numero_corto = COALESCE(NULLIF(TRIM(LEADING '0' FROM SUBSTRING(consecutivo, 11, 10)), ''), '0')
WHERE numero_corto IS NULL AND consecutivo IS NOT NULL;
