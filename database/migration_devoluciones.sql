-- Migración: módulo de devoluciones a proveedor ↔ notas de crédito.
--
-- Cada fila de `devoluciones` es un reporte PDF del ERP importado y validado
-- (cuadre contra sus totales impresos):
--   · boleta_local: faltantes/dif. de costo al recibir camión (bodega Ventas).
--     Genera hasta dos expectativas de NC: cantidad y costo.
--   · devolucion_proveedor: dañado/vencido (bodega Cambios). Una expectativa
--     por el total.
-- `devolucion_matches` guarda el resultado de la cascada de verificación:
-- una fila por (expectativa, candidato NC). Los confirmados manuales
-- sobreviven a las re-verificaciones automáticas.

CREATE TABLE IF NOT EXISTS devoluciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sociedad_id INT UNSIGNED NULL DEFAULT NULL,
    tipo VARCHAR(25) NOT NULL COMMENT 'boleta_local | devolucion_proveedor',
    numero VARCHAR(20) NOT NULL COMMENT 'No. de boleta o de devolución en el ERP',
    sucursal VARCHAR(120) NULL DEFAULT NULL,
    bodega VARCHAR(120) NULL DEFAULT NULL,
    numero_factura VARCHAR(20) NULL DEFAULT NULL COMMENT 'Consecutivo 20 díg. de la factura (solo boleta)',
    factura_xml_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Factura local hallada por ese consecutivo',
    proveedor_codigo_erp VARCHAR(20) NULL DEFAULT NULL,
    proveedor_nombre_erp VARCHAR(255) NULL DEFAULT NULL,
    proveedor_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Proveedor local resuelto por nombre',
    fecha DATE NULL DEFAULT NULL,
    estado_erp VARCHAR(30) NULL DEFAULT NULL,
    usuario_erp VARCHAR(120) NULL DEFAULT NULL,
    observaciones VARCHAR(500) NULL DEFAULT NULL,
    cantidad_total DECIMAL(16,3) NOT NULL DEFAULT 0,
    total DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'Total con IVA (formato devolución)',
    nc_esperada_cantidad DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'NC esperada por faltante (boleta)',
    nc_esperada_costo DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'NC esperada por dif. de costo (boleta)',
    estado VARCHAR(15) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente|sin_nc|parcial|verificada',
    archivo_pdf VARCHAR(255) NOT NULL,
    ruta_pdf VARCHAR(500) NOT NULL,
    hash_pdf VARCHAR(64) NOT NULL,
    advertencias TEXT NULL DEFAULT NULL COMMENT 'JSON de advertencias del parser',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verificado_en DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dev_hash (hash_pdf),
    KEY idx_dev_tipo_numero (tipo, numero),
    KEY idx_dev_estado (estado),
    KEY idx_dev_proveedor (proveedor_id),
    KEY idx_dev_fecha (fecha),
    KEY idx_dev_factura (factura_xml_id),
    CONSTRAINT fk_dev_factura FOREIGN KEY (factura_xml_id)
        REFERENCES facturas_xml (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devolucion_lineas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_id INT UNSIGNED NOT NULL,
    seccion VARCHAR(20) NULL DEFAULT NULL COMMENT 'FACTURA|NOTA CANTIDAD|FALTANTE|NOTA COSTO; NULL en formato devolución',
    codigo VARCHAR(20) NULL DEFAULT NULL,
    nombre VARCHAR(255) NULL DEFAULT NULL,
    cantidad DECIMAL(16,3) NOT NULL DEFAULT 0,
    costo DECIMAL(18,5) NOT NULL DEFAULT 0 COMMENT 'Costo neto (boleta) o unitario (devolución)',
    impuesto DECIMAL(18,5) NOT NULL DEFAULT 0,
    total DECIMAL(18,2) NOT NULL DEFAULT 0,
    dif_costo DECIMAL(18,2) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_dl_devolucion (devolucion_id, seccion),
    CONSTRAINT fk_dl_devolucion FOREIGN KEY (devolucion_id)
        REFERENCES devoluciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devolucion_matches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    devolucion_id INT UNSIGNED NOT NULL,
    objetivo VARCHAR(12) NOT NULL COMMENT 'cantidad|costo|total',
    monto_esperado DECIMAL(18,2) NOT NULL DEFAULT 0,
    factura_xml_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'La NC candidata/confirmada',
    metodo VARCHAR(15) NOT NULL DEFAULT 'ninguno' COMMENT 'referencia|monto|manual|ninguno',
    estado VARCHAR(15) NOT NULL DEFAULT 'sin_nc' COMMENT 'confirmado|sugerido|sin_nc|descartado',
    monto_nc DECIMAL(18,2) NULL DEFAULT NULL,
    diferencia DECIMAL(18,2) NULL DEFAULT NULL,
    nc_consolidada TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'La NC referencia varias facturas',
    motivo VARCHAR(400) NULL DEFAULT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dm_devolucion (devolucion_id, objetivo),
    KEY idx_dm_nc (factura_xml_id),
    CONSTRAINT fk_dm_devolucion FOREIGN KEY (devolucion_id)
        REFERENCES devoluciones (id) ON DELETE CASCADE,
    CONSTRAINT fk_dm_nc FOREIGN KEY (factura_xml_id)
        REFERENCES facturas_xml (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
