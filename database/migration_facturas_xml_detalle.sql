-- Migración: detalle interno de los XML (referencias y líneas).
--
-- Las NC electrónicas de Costa Rica traen InformacionReferencia con la clave
-- de la factura que acreditan: es el puente determinístico NC → factura para
-- la verificación de devoluciones. Las líneas (LineaDetalle) sirven como
-- criterio de desempate (conteo, cantidades) cuando la referencia no alcanza.
--
-- detalle_extraido_en en facturas_xml marca qué documentos ya pasaron por la
-- extracción (importación nueva o backfill); NULL = pendiente.

CREATE TABLE IF NOT EXISTS facturas_xml_referencias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_xml_id INT UNSIGNED NOT NULL,
    tipo_doc_ref VARCHAR(4) NULL DEFAULT NULL COMMENT 'TipoDoc del bloque (01=FE, 08=otros...)',
    numero_ref VARCHAR(60) NOT NULL COMMENT 'Numero referenciado tal cual viene en el XML',
    clave_ref VARCHAR(50) NULL DEFAULT NULL COMMENT 'Solo si numero_ref es una clave de 50 dígitos',
    consecutivo_ref VARCHAR(20) NULL DEFAULT NULL COMMENT 'Consecutivo (20 díg.) derivado de la clave',
    fecha_ref DATE NULL DEFAULT NULL,
    codigo_ref VARCHAR(4) NULL DEFAULT NULL COMMENT 'Codigo de referencia (01=anula, 02=corrige...)',
    razon VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fxr_factura_numero (factura_xml_id, numero_ref),
    KEY idx_fxr_clave (clave_ref),
    KEY idx_fxr_consecutivo (consecutivo_ref),
    CONSTRAINT fk_fxr_factura FOREIGN KEY (factura_xml_id)
        REFERENCES facturas_xml (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturas_xml_lineas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    factura_xml_id INT UNSIGNED NOT NULL,
    numero_linea INT UNSIGNED NOT NULL,
    codigo_cabys VARCHAR(20) NULL DEFAULT NULL,
    codigo_comercial VARCHAR(30) NULL DEFAULT NULL COMMENT 'Código del proveedor (a veces EAN)',
    detalle VARCHAR(255) NULL DEFAULT NULL COMMENT 'Descripción del proveedor, no la interna',
    cantidad DECIMAL(16,3) NOT NULL DEFAULT 0,
    unidad VARCHAR(15) NULL DEFAULT NULL,
    precio_unitario DECIMAL(18,5) NOT NULL DEFAULT 0,
    monto_descuento DECIMAL(18,5) NOT NULL DEFAULT 0,
    subtotal DECIMAL(18,5) NOT NULL DEFAULT 0,
    impuesto DECIMAL(18,5) NOT NULL DEFAULT 0,
    total_linea DECIMAL(18,5) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fxl_factura_linea (factura_xml_id, numero_linea),
    KEY idx_fxl_codigo_comercial (codigo_comercial),
    CONSTRAINT fk_fxl_factura FOREIGN KEY (factura_xml_id)
        REFERENCES facturas_xml (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE facturas_xml
    ADD COLUMN IF NOT EXISTS detalle_extraido_en DATETIME NULL DEFAULT NULL
        COMMENT 'Última extracción de referencias/líneas; NULL = pendiente'
        AFTER archivado_en;

ALTER TABLE facturas_xml
    ADD INDEX IF NOT EXISTS idx_facturas_detalle_pend (tipo_documento, detalle_extraido_en);
