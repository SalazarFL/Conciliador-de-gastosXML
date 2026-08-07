-- Optimización del índice local de correo para tablas grandes.
-- MariaDB 10.4 requiere ALGORITHM=COPY porque se agregan columnas generadas.
-- Ejecutar con la sincronización de correo temporalmente pausada.

ALTER TABLE correo_indice
    ADD COLUMN IF NOT EXISTS adjuntos_pendiente
        TINYINT(1) AS (adjuntos IS NULL) PERSISTENT,
    ADD COLUMN IF NOT EXISTS destinatarios_pendientes
        TINYINT(1) AS (cc IS NULL OR reply_to IS NULL) PERSISTENT,
    ADD INDEX IF NOT EXISTS idx_cuenta_timestamp_id
        (cuenta_id, timestamp, id),
    ADD INDEX IF NOT EXISTS idx_pend_adjuntos
        (cuenta_id, adjuntos_pendiente, timestamp, id),
    ADD INDEX IF NOT EXISTS idx_pend_destinatarios
        (cuenta_id, destinatarios_pendientes, timestamp, id),
    ALGORITHM=COPY;

-- Controles posteriores:
-- CHECK TABLE correo_indice;
-- SHOW INDEX FROM correo_indice;
