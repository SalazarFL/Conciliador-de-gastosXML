-- Permite reconstruir correo_indice sin borrar el historial de lotes.
-- Cada item conserva una instantánea de los datos visibles del correo y la
-- referencia al índice pasa a NULL solamente si ese encabezado se reemplaza.

ALTER TABLE correo_lote_items
    ADD COLUMN IF NOT EXISTS asunto VARCHAR(255) NULL AFTER uid,
    ADD COLUMN IF NOT EXISTS remitente VARCHAR(255) NULL AFTER asunto,
    ADD COLUMN IF NOT EXISTS fecha_correo DATETIME NULL AFTER remitente;

UPDATE correo_lote_items li
INNER JOIN correo_indice i ON i.id = li.correo_indice_id
SET li.asunto = COALESCE(li.asunto, i.asunto),
    li.remitente = COALESCE(li.remitente, i.remitente),
    li.fecha_correo = COALESCE(li.fecha_correo, i.fecha);

SET @fk_indice_existe = (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'correo_lote_items'
      AND CONSTRAINT_NAME = 'fk_correo_lote_indice'
);
SET @sql_quitar_fk = IF(
    @fk_indice_existe > 0,
    'ALTER TABLE correo_lote_items DROP FOREIGN KEY fk_correo_lote_indice',
    'SELECT 1'
);
PREPARE stmt_quitar_fk FROM @sql_quitar_fk;
EXECUTE stmt_quitar_fk;
DEALLOCATE PREPARE stmt_quitar_fk;

ALTER TABLE correo_lote_items
    MODIFY correo_indice_id INT UNSIGNED NULL;

ALTER TABLE correo_lote_items
    ADD CONSTRAINT fk_correo_lote_indice
        FOREIGN KEY (correo_indice_id) REFERENCES correo_indice(id)
        ON DELETE SET NULL;
