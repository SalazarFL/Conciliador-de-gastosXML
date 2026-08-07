-- Archivo local de XML/PDF, Correo General por lotes y Notas XML.
-- Reversible con rollback_correo_general_notas_xml.sql.

ALTER TABLE facturas_xml
    ADD COLUMN IF NOT EXISTS ruta_pdf VARCHAR(500) NULL AFTER ruta_xml,
    ADD COLUMN IF NOT EXISTS archivo_pdf VARCHAR(255) NULL AFTER ruta_pdf,
    ADD COLUMN IF NOT EXISTS hash_pdf VARCHAR(64) NULL AFTER archivo_pdf,
    ADD COLUMN IF NOT EXISTS estado_pdf VARCHAR(30) NOT NULL DEFAULT 'pendiente' AFTER hash_pdf,
    ADD COLUMN IF NOT EXISTS correo_cuenta_id INT UNSIGNED NULL AFTER estado_pdf,
    ADD COLUMN IF NOT EXISTS correo_carpeta VARCHAR(255) NULL AFTER correo_cuenta_id,
    ADD COLUMN IF NOT EXISTS correo_uidvalidity BIGINT UNSIGNED NULL AFTER correo_carpeta,
    ADD COLUMN IF NOT EXISTS correo_uid INT UNSIGNED NULL AFTER correo_uidvalidity,
    ADD COLUMN IF NOT EXISTS fecha_correo DATETIME NULL AFTER correo_uid,
    ADD COLUMN IF NOT EXISTS archivado_en DATETIME NULL AFTER fecha_correo,
    ADD INDEX IF NOT EXISTS idx_tipo_fecha (tipo_documento, fecha_emision, id),
    ADD INDEX IF NOT EXISTS idx_estado_pdf (estado_pdf),
    ADD INDEX IF NOT EXISTS idx_correo_origen (correo_cuenta_id, correo_uidvalidity, correo_uid);

CREATE TABLE IF NOT EXISTS correo_lotes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id INT UNSIGNED NOT NULL,
    sociedad_id INT UNSIGNED NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE NOT NULL,
    carpeta_raiz VARCHAR(500) NOT NULL,
    carpetas_json MEDIUMTEXT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    total_mensajes INT UNSIGNED NOT NULL DEFAULT 0,
    procesados INT UNSIGNED NOT NULL DEFAULT 0,
    documentos_importados INT UNSIGNED NOT NULL DEFAULT 0,
    duplicados INT UNSIGNED NOT NULL DEFAULT 0,
    incidencias INT UNSIGNED NOT NULL DEFAULT 0,
    pdf_pendientes INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    iniciado_en DATETIME NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    terminado_en DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_lote_cuenta_fecha (cuenta_id, fecha_desde, fecha_hasta),
    KEY idx_lote_estado (estado, actualizado_en),
    CONSTRAINT fk_correo_lote_cuenta FOREIGN KEY (cuenta_id) REFERENCES correo_cuentas(id) ON DELETE RESTRICT,
    CONSTRAINT fk_correo_lote_sociedad FOREIGN KEY (sociedad_id) REFERENCES sociedades(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_lote_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id INT UNSIGNED NOT NULL,
    correo_indice_id INT UNSIGNED NULL,
    carpeta VARCHAR(255) NOT NULL,
    uidvalidity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uid INT UNSIGNED NOT NULL,
    asunto VARCHAR(255) NULL,
    remitente VARCHAR(255) NULL,
    fecha_correo DATETIME NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    documentos_importados INT UNSIGNED NOT NULL DEFAULT 0,
    duplicados INT UNSIGNED NOT NULL DEFAULT 0,
    pdf_pendientes INT UNSIGNED NOT NULL DEFAULT 0,
    detalle TEXT NULL,
    iniciado_en DATETIME NULL,
    procesado_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lote_mensaje (lote_id, correo_indice_id),
    KEY idx_lote_estado_id (lote_id, estado, id),
    CONSTRAINT fk_correo_lote_item FOREIGN KEY (lote_id) REFERENCES correo_lotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_correo_lote_indice FOREIGN KEY (correo_indice_id) REFERENCES correo_indice(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE correo_lote_items
    ADD COLUMN IF NOT EXISTS iniciado_en DATETIME NULL AFTER detalle;

CREATE TABLE IF NOT EXISTS correo_incidencias (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lote_id INT UNSIGNED NULL,
    lote_item_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(40) NOT NULL,
    mensaje VARCHAR(1000) NOT NULL,
    metadata MEDIUMTEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_incidencia_lote (lote_id, creado_en),
    CONSTRAINT fk_correo_incidencia_lote FOREIGN KEY (lote_id) REFERENCES correo_lotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_correo_incidencia_item FOREIGN KEY (lote_item_id) REFERENCES correo_lote_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
