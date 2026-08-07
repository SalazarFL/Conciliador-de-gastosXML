DROP TABLE IF EXISTS correo_incidencias;
DROP TABLE IF EXISTS correo_lote_items;
DROP TABLE IF EXISTS correo_lotes;

ALTER TABLE facturas_xml
    DROP INDEX IF EXISTS idx_correo_origen,
    DROP INDEX IF EXISTS idx_estado_pdf,
    DROP INDEX IF EXISTS idx_tipo_fecha,
    DROP COLUMN IF EXISTS archivado_en,
    DROP COLUMN IF EXISTS fecha_correo,
    DROP COLUMN IF EXISTS correo_uid,
    DROP COLUMN IF EXISTS correo_uidvalidity,
    DROP COLUMN IF EXISTS correo_carpeta,
    DROP COLUMN IF EXISTS correo_cuenta_id,
    DROP COLUMN IF EXISTS estado_pdf,
    DROP COLUMN IF EXISTS hash_pdf,
    DROP COLUMN IF EXISTS archivo_pdf,
    DROP COLUMN IF EXISTS ruta_pdf;
