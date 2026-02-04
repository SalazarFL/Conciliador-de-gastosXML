-- =========================================
-- ESQUEMA DE BASE DE DATOS
-- Sistema: XMLConcilia (VerificadorXMLConciliacion)
-- Motor: MySQL 5.7+ / XAMPP
-- Charset: utf8mb4
-- =========================================

-- Crear base de datos
DROP DATABASE IF EXISTS `bd_xmlconcilia`;
CREATE DATABASE `bd_xmlconcilia` 
    DEFAULT CHARACTER SET utf8mb4 
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `bd_xmlconcilia`;

-- =========================================
-- TABLA: proveedores
-- Propósito: Catálogo normalizado de proveedores/emisores
-- Normaliza la razón social y RFC de los proveedores
-- =========================================
CREATE TABLE `proveedores` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rfc` VARCHAR(13) NULL DEFAULT NULL COMMENT 'RFC del proveedor (si está disponible)',
    `razon_social` VARCHAR(255) NOT NULL COMMENT 'Razón social del emisor',
    `razon_social_normalizada` VARCHAR(255) NOT NULL COMMENT 'Razón social sin acentos/mayúsculas para matching',
    `alias` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Alias o nombre corto',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rfc` (`rfc`),
    KEY `idx_razon_social_norm` (`razon_social_normalizada`),
    KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Catálogo de proveedores normalizados';

-- =========================================
-- TABLA: catalogo_estados
-- Propósito: Estados posibles de conciliación
-- Catálogo de estados para el proceso de conciliación
-- =========================================
CREATE TABLE `catalogo_estados` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL COMMENT 'Código único del estado',
    `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre descriptivo',
    `descripcion` TEXT NULL DEFAULT NULL,
    `color_hex` VARCHAR(7) NULL DEFAULT NULL COMMENT 'Color para UI (#RRGGBB)',
    `orden` INT NOT NULL DEFAULT 0 COMMENT 'Orden de visualización',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Catálogo de estados de conciliación';

-- Insertar estados predefinidos
INSERT INTO `catalogo_estados` (`codigo`, `nombre`, `descripcion`, `color_hex`, `orden`) VALUES
('conciliada', 'Conciliada', 'Factura y gasto coinciden perfectamente', '#28a745', 1),
('con_diferencias', 'Con Diferencias', 'Factura y gasto existen pero con diferencias en montos', '#ffc107', 2),
('pendiente', 'Pendiente', 'Factura XML sin gasto asociado', '#17a2b8', 3),
('gasto_sin_xml', 'Gasto sin XML', 'Gasto registrado sin factura XML correspondiente', '#dc3545', 4);

-- =========================================
-- TABLA: importaciones
-- Propósito: Registro de cada proceso de carga masiva
-- Auditoría de importaciones de XML y gastos
-- =========================================
CREATE TABLE `importaciones` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` ENUM('xml', 'gastos') NOT NULL COMMENT 'Tipo de importación',
    `archivo_origen` VARCHAR(255) NOT NULL COMMENT 'Nombre del archivo importado',
    `ruta_archivo` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Ruta completa del archivo',
    `total_registros` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total de registros procesados',
    `registros_exitosos` INT UNSIGNED NOT NULL DEFAULT 0,
    `registros_fallidos` INT UNSIGNED NOT NULL DEFAULT 0,
    `errores` TEXT NULL DEFAULT NULL COMMENT 'JSON con detalles de errores',
    `importado_por` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Usuario/operador (futuro)',
    `fecha_importacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `duracion_segundos` DECIMAL(10,2) NULL DEFAULT NULL,
    `metadata` TEXT NULL DEFAULT NULL COMMENT 'JSON con información adicional',
    PRIMARY KEY (`id`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_fecha` (`fecha_importacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registro de importaciones masivas de XML y gastos';

-- =========================================
-- TABLA: facturas_xml
-- Propósito: Almacenar facturas electrónicas procesadas
-- Contiene los datos extraídos de los XML de CFDI
-- =========================================
CREATE TABLE `facturas_xml` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `importacion_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a importaciones',
    `consecutivo_completo` VARCHAR(255) NOT NULL COMMENT 'Folio fiscal o UUID completo del XML',
    `numero_factura_asistente` VARCHAR(20) NOT NULL COMMENT 'Últimos 10 dígitos sin ceros a la izq.',
    `proveedor_id` INT UNSIGNED NOT NULL COMMENT 'FK a proveedores',
    `fecha_emision` DATE NOT NULL COMMENT 'Fecha de emisión de la factura',
    `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Base gravable',
    `iva` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto de IVA',
    `total` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Total de la factura',
    `moneda` VARCHAR(3) NOT NULL DEFAULT 'MXN' COMMENT 'Código de moneda ISO 4217',
    `tipo_comprobante` VARCHAR(20) NULL DEFAULT NULL COMMENT 'I=Ingreso, E=Egreso, etc.',
    `archivo_xml` VARCHAR(255) NOT NULL COMMENT 'Nombre del archivo XML',
    `ruta_xml` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Ruta completa del archivo',
    `hash_xml` VARCHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 del contenido XML (opcional)',
    `xml_contenido` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Contenido completo del XML (opcional)',
    `metadata` TEXT NULL DEFAULT NULL COMMENT 'JSON con datos adicionales del XML',
    `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_consecutivo` (`consecutivo_completo`),
    KEY `idx_numero_factura` (`numero_factura_asistente`),
    KEY `idx_proveedor` (`proveedor_id`),
    KEY `idx_fecha_emision` (`fecha_emision`),
    KEY `idx_importacion` (`importacion_id`),
    KEY `idx_fecha_proveedor` (`fecha_emision`, `proveedor_id`),
    CONSTRAINT `fk_facturas_proveedor` 
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_facturas_importacion` 
        FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Facturas electrónicas XML procesadas (CFDI)';

-- =========================================
-- TABLA: gastos_raw
-- Propósito: Registros individuales importados desde Excel/CSV
-- Cada fila del archivo de gastos se almacena aquí
-- =========================================
CREATE TABLE `gastos_raw` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `importacion_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a importaciones',
    `numero_factura` VARCHAR(50) NOT NULL COMMENT 'Número de factura reportado en el gasto',
    `proveedor_texto` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Proveedor como texto libre',
    `fecha_gasto` DATE NULL DEFAULT NULL COMMENT 'Fecha del gasto',
    `descripcion` TEXT NULL DEFAULT NULL COMMENT 'Descripción del gasto',
    `monto_base` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Base gravable',
    `iva` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'IVA del gasto',
    `total` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Total del gasto',
    `categoria` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Categoría o cuenta contable',
    `centro_costos` VARCHAR(100) NULL DEFAULT NULL,
    `proyecto` VARCHAR(100) NULL DEFAULT NULL,
    `observaciones` TEXT NULL DEFAULT NULL,
    `fila_archivo` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Número de fila en el archivo origen',
    `metadata` TEXT NULL DEFAULT NULL COMMENT 'JSON con campos adicionales flexibles',
    `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_numero_factura` (`numero_factura`),
    KEY `idx_fecha_gasto` (`fecha_gasto`),
    KEY `idx_importacion` (`importacion_id`),
    KEY `idx_proveedor_texto` (`proveedor_texto`(100)),
    CONSTRAINT `fk_gastos_raw_importacion` 
        FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Gastos importados (datos crudos sin consolidar)';

-- =========================================
-- TABLA: gastos_consolidados
-- Propósito: Gastos agrupados por número de factura
-- Resultado de consolidar múltiples líneas de gastos_raw
-- =========================================
CREATE TABLE `gastos_consolidados` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `numero_factura` VARCHAR(50) NOT NULL COMMENT 'Número de factura consolidado',
    `proveedor_texto` VARCHAR(255) NOT NULL COMMENT 'Proveedor predominante (normalizado)',
    `cantidad_items` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Cantidad de líneas consolidadas',
    `fecha_min` DATE NULL DEFAULT NULL COMMENT 'Fecha más antigua de los gastos',
    `fecha_max` DATE NULL DEFAULT NULL COMMENT 'Fecha más reciente de los gastos',
    `suma_base` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma de bases',
    `suma_iva` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma de IVA',
    `suma_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Suma total',
    `consolidado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_numero_factura_proveedor` (`numero_factura`, `proveedor_texto`),
    KEY `idx_numero_factura` (`numero_factura`),
    KEY `idx_fecha_min` (`fecha_min`),
    KEY `idx_fecha_max` (`fecha_max`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Gastos consolidados por número de factura y proveedor';

-- =========================================
-- TABLA: conciliaciones
-- Propósito: Relación entre facturas XML y gastos consolidados
-- Registra el resultado del proceso de conciliación
-- Permite casos donde existe factura sin gasto o viceversa
-- =========================================
CREATE TABLE `conciliaciones` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a facturas_xml (NULL si es gasto_sin_xml)',
    `gasto_consolidado_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK a gastos_consolidados (NULL si es pendiente)',
    `estado_id` INT UNSIGNED NOT NULL COMMENT 'FK a catalogo_estados',
    `diferencia_base` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Diferencia en base (XML - Gasto)',
    `diferencia_iva` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Diferencia en IVA',
    `diferencia_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Diferencia en total',
    `porcentaje_diferencia` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Porcentaje de diferencia',
    `fecha_conciliacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `conciliado_por` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Usuario que realizó la conciliación',
    `notas` TEXT NULL DEFAULT NULL COMMENT 'Observaciones o notas adicionales',
    `metadata` TEXT NULL DEFAULT NULL COMMENT 'JSON con información adicional',
    `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_factura_xml` (`factura_xml_id`),
    KEY `idx_gasto_consolidado` (`gasto_consolidado_id`),
    KEY `idx_estado` (`estado_id`),
    KEY `idx_fecha_conciliacion` (`fecha_conciliacion`),
    KEY `idx_estado_fecha` (`estado_id`, `fecha_conciliacion`),
    CONSTRAINT `fk_conciliacion_factura` 
        FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_conciliacion_gasto` 
        FOREIGN KEY (`gasto_consolidado_id`) REFERENCES `gastos_consolidados` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_conciliacion_estado` 
        FOREIGN KEY (`estado_id`) REFERENCES `catalogo_estados` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
    -- NOTA: CHECK constraint no funciona en MySQL 5.7 (se ignora)
    -- La validación de que al menos uno (factura_xml_id o gasto_consolidado_id) 
    -- debe ser NOT NULL se debe hacer en el código de la aplicación
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Registro de conciliaciones entre facturas XML y gastos';

-- =========================================
-- VISTAS ÚTILES
-- =========================================

-- Vista: Resumen de conciliaciones con información completa
CREATE OR REPLACE VIEW `v_conciliaciones_completas` AS
SELECT 
    c.id AS conciliacion_id,
    c.fecha_conciliacion,
    e.codigo AS estado_codigo,
    e.nombre AS estado_nombre,
    e.color_hex AS estado_color,
    f.id AS factura_id,
    f.consecutivo_completo,
    f.numero_factura_asistente,
    f.fecha_emision,
    f.subtotal AS factura_base,
    f.iva AS factura_iva,
    f.total AS factura_total,
    p.razon_social AS proveedor,
    p.rfc AS proveedor_rfc,
    g.id AS gasto_id,
    g.numero_factura AS gasto_numero_factura,
    g.fecha_min AS gasto_fecha_min,
    g.fecha_max AS gasto_fecha_max,
    g.suma_base AS gasto_base,
    g.suma_iva AS gasto_iva,
    g.suma_total AS gasto_total,
    g.cantidad_items AS gasto_items,
    c.diferencia_base,
    c.diferencia_iva,
    c.diferencia_total,
    c.porcentaje_diferencia,
    c.notas
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN proveedores p ON f.proveedor_id = p.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id;

-- =========================================
-- ÍNDICES ADICIONALES PARA REPORTES
-- =========================================

-- Índice compuesto para reportes por fecha y estado
CREATE INDEX `idx_facturas_fecha_estado` ON `facturas_xml` (`fecha_emision`, `id`);

-- Índice para búsquedas por hash (si se usa validación de duplicados)
CREATE INDEX `idx_hash_xml` ON `facturas_xml` (`hash_xml`);

-- =========================================
-- FIN DEL SCHEMA
-- =========================================
