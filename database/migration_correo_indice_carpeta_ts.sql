-- Listar una carpeta ordenada por fecha sin filesort: la vista de Correo pide
-- 500 filas de la carpeta activa cada vez que se abre el módulo y solo existía
-- (cuenta_id, timestamp) global o (cuenta_id, carpeta, uidvalidity, uid) sin fecha.
-- La app también lo crea sola (CorreoIndice); este script es para aplicarlo
-- manualmente en producción en horario tranquilo.

ALTER TABLE correo_indice
    ADD INDEX IF NOT EXISTS idx_cuenta_carpeta_ts (cuenta_id, carpeta(170), timestamp);

-- Control posterior:
-- SHOW INDEX FROM correo_indice;
