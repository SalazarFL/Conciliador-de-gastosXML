-- =========================================
-- MIGRACIÓN COMPLEMENTARIA: Campos Normalizados
-- Sistema: Nexo Fiscal
-- Fecha: 2026-02-12
-- Propósito: Agregar campos normalizados faltantes para fuzzy matching
-- EJECUTAR DESPUÉS DE: schema.sql + 001_revision_y_estado.sql
-- =========================================

USE `bd_xmlconcilia`;

-- =========================================
-- 1. TABLA: gastos_raw
-- Agregar campos normalizados
-- =========================================

ALTER TABLE `gastos_raw`
ADD COLUMN `numero_factura_normalizado` VARCHAR(50) NULL DEFAULT NULL 
    COMMENT 'Número sin espacios/guiones/ceros a la izquierda' AFTER `numero_factura`;

ALTER TABLE `gastos_raw`
ADD COLUMN `proveedor_normalizado` VARCHAR(255) NULL DEFAULT NULL 
    COMMENT 'Proveedor sin acentos/mayúsculas/tokens legales' AFTER `proveedor_texto`;

-- Crear índices
CREATE INDEX `idx_numero_normalizado` ON `gastos_raw` (`numero_factura_normalizado`);
CREATE INDEX `idx_proveedor_normalizado` ON `gastos_raw` (`proveedor_normalizado`(100));

-- =========================================
-- 2. TABLA: gastos_consolidados
-- Agregar campos normalizados y cambiar UNIQUE
-- =========================================

ALTER TABLE `gastos_consolidados`
ADD COLUMN `numero_factura_normalizado` VARCHAR(50) NULL DEFAULT NULL 
    COMMENT 'Número normalizado para matching' AFTER `numero_factura`;

ALTER TABLE `gastos_consolidados`
ADD COLUMN `proveedor_normalizado` VARCHAR(255) NULL DEFAULT NULL 
    COMMENT 'Proveedor normalizado para matching' AFTER `proveedor_texto`;

-- Poblar datos normalizados de registros existentes (si hay)
UPDATE `gastos_consolidados`
SET 
    `numero_factura_normalizado` = TRIM(LEADING '0' FROM REPLACE(REPLACE(REPLACE(`numero_factura`, ' ', ''), '-', ''), '/', '')),
    `proveedor_normalizado` = UPPER(TRIM(`proveedor_texto`))
WHERE `numero_factura_normalizado` IS NULL 
   OR `proveedor_normalizado` IS NULL;

-- Hacer NOT NULL después de poblar (si hay datos)
-- Descomentar solo si ya hay registros en la tabla:
-- ALTER TABLE `gastos_consolidados`
-- MODIFY COLUMN `numero_factura_normalizado` VARCHAR(50) NOT NULL,
-- MODIFY COLUMN `proveedor_normalizado` VARCHAR(255) NOT NULL;

-- Eliminar UNIQUE antiguo SOLO si existe
-- Verificar primero con: SHOW INDEX FROM gastos_consolidados WHERE Key_name = 'uk_numero_factura_proveedor';
SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'gastos_consolidados'
      AND index_name = 'uk_numero_factura_proveedor'
);

SET @drop_sql := IF(
    @idx_exists > 0,
    'ALTER TABLE `gastos_consolidados` DROP INDEX `uk_numero_factura_proveedor`',
    'SELECT "Índice uk_numero_factura_proveedor no existe, se omite DROP"'
);

PREPARE stmt_drop_idx FROM @drop_sql;
EXECUTE stmt_drop_idx;
DEALLOCATE PREPARE stmt_drop_idx;

-- Crear UNIQUE compuesto con campos normalizados
-- IMPORTANTE: Ejecutar esto DESPUÉS de poblar los datos
-- Si tienes datos duplicados con la nueva regla, primero límpialos
ALTER TABLE `gastos_consolidados`
ADD UNIQUE KEY `uk_numero_proveedor_norm` (`numero_factura_normalizado`, `proveedor_normalizado`);

-- Índice adicional para búsquedas por número solo
CREATE INDEX `idx_numero_normalizado` ON `gastos_consolidados` (`numero_factura_normalizado`);

-- =========================================
-- 3. TABLA: facturas_xml
-- Agregar campo proveedor normalizado
-- =========================================

ALTER TABLE `facturas_xml`
ADD COLUMN `proveedor_normalizado` VARCHAR(255) NULL DEFAULT NULL 
    COMMENT 'Redundante de proveedores.razon_social_normalizada' AFTER `proveedor_id`;

-- Poblar desde tabla proveedores
UPDATE `facturas_xml` f
INNER JOIN `proveedores` p ON f.proveedor_id = p.id
SET f.proveedor_normalizado = p.razon_social_normalizada
WHERE f.proveedor_normalizado IS NULL;

-- Índice compuesto para matching rápido
CREATE INDEX `idx_numero_proveedor_norm` ON `facturas_xml` (`numero_factura_asistente`, `proveedor_normalizado`(100));

-- =========================================
-- VERIFICACIÓN
-- Ejecutar estas queries para confirmar que todo está OK
-- =========================================

-- Ver estructura de gastos_raw
-- SHOW COLUMNS FROM gastos_raw LIKE '%normalizado%';

-- Ver estructura de gastos_consolidados
-- SHOW COLUMNS FROM gastos_consolidados LIKE '%normalizado%';

-- Ver estructura de facturas_xml
-- SHOW COLUMNS FROM facturas_xml LIKE '%normalizado%';

-- Ver índices de gastos_consolidados
-- SHOW INDEX FROM gastos_consolidados;

-- =========================================
-- FIN DE MIGRACIÓN COMPLEMENTARIA
-- =========================================
