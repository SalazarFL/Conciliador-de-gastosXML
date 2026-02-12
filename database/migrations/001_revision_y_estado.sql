-- =========================================
-- MIGRACIÓN 001: Sistema de Revisión Manual y Auditoría
-- Sistema: XMLConcilia
-- Fecha: 2026-02-12
-- Propósito: Agregar estado "requiere_revision" y campos de auditoría
-- IMPORTANTE: Esta migración es incremental y segura de ejecutar
-- =========================================

USE `bd_xmlconcilia`;

-- =========================================
-- 1. ACTUALIZAR CATÁLOGO DE ESTADOS
-- Agregar nuevo estado "requiere_revision"
-- =========================================

-- Insertar nuevo estado (ignorar si ya existe)
INSERT IGNORE INTO `catalogo_estados` (`codigo`, `nombre`, `descripcion`, `color_hex`, `orden`, `activo`) 
VALUES ('requiere_revision', 'Requiere Revisión', 
        'Coincidencia parcial o sugerida; requiere validación humana', 
        '#fd7e14', 2, 1);

-- Ajustar orden de estados existentes para mantener lógica:
-- 1. conciliada (verde)
-- 2. requiere_revision (naranja) <- NUEVO
-- 3. con_diferencias (amarillo)
-- 4. pendiente (azul)
-- 5. gasto_sin_xml (rojo)

UPDATE `catalogo_estados` SET `orden` = 1 WHERE `codigo` = 'conciliada';
UPDATE `catalogo_estados` SET `orden` = 2 WHERE `codigo` = 'requiere_revision';
UPDATE `catalogo_estados` SET `orden` = 3 WHERE `codigo` = 'con_diferencias';
UPDATE `catalogo_estados` SET `orden` = 4 WHERE `codigo` = 'pendiente';
UPDATE `catalogo_estados` SET `orden` = 5 WHERE `codigo` = 'gasto_sin_xml';

-- =========================================
-- 2. ALTER TABLE: conciliaciones
-- Agregar campos de revisión manual y scoring
-- =========================================

-- NOTA: Si algún campo ya existe (de migraciones previas), 
-- MySQL mostrará error 1060 "Duplicate column name"
-- En ese caso, comentar la línea correspondiente y continuar

-- Campos de scoring (pueden existir de migración fuzzy_match)
ALTER TABLE `conciliaciones`
ADD COLUMN `score_numero` DECIMAL(5,2) NULL DEFAULT NULL 
    COMMENT 'Score de coincidencia del número (0-100)' AFTER `estado_id`;

ALTER TABLE `conciliaciones`
ADD COLUMN `score_proveedor` DECIMAL(5,2) NULL DEFAULT NULL 
    COMMENT 'Score de coincidencia del proveedor (0-100)' AFTER `score_numero`;

ALTER TABLE `conciliaciones`
ADD COLUMN `score_total` DECIMAL(5,2) NULL DEFAULT NULL 
    COMMENT 'Score total ponderado (0-100)' AFTER `score_proveedor`;

-- Tipo de match (puede existir, pero verificar ENUM values)
-- Si ya existe con valores diferentes, usar ALTER TABLE MODIFY COLUMN
ALTER TABLE `conciliaciones`
ADD COLUMN `match_tipo` ENUM('exacto', 'sugerido', 'manual') NOT NULL DEFAULT 'manual'
    COMMENT 'Tipo de match: exacto=100%, sugerido=parcial, manual=forzado' AFTER `score_total`;

ALTER TABLE `conciliaciones`
ADD COLUMN `observaciones_match` TEXT NULL DEFAULT NULL 
    COMMENT 'Detalles del proceso de matching automático' AFTER `match_tipo`;

-- Campos de revisión manual (NUEVOS)
ALTER TABLE `conciliaciones`
ADD COLUMN `revisado` TINYINT(1) NOT NULL DEFAULT 0 
    COMMENT 'Indica si fue revisado manualmente (0=no, 1=sí)' AFTER `notas`;

ALTER TABLE `conciliaciones`
ADD COLUMN `revisado_por` VARCHAR(100) NULL DEFAULT NULL 
    COMMENT 'Usuario que realizó la revisión' AFTER `revisado`;

ALTER TABLE `conciliaciones`
ADD COLUMN `revisado_en` TIMESTAMP NULL DEFAULT NULL 
    COMMENT 'Fecha y hora de la revisión' AFTER `revisado_por`;

ALTER TABLE `conciliaciones`
ADD COLUMN `revision_comentario` TEXT NULL DEFAULT NULL 
    COMMENT 'Comentarios del revisor' AFTER `revisado_en`;

-- Crear índices para optimizar consultas de revisión
CREATE INDEX `idx_revisado` ON `conciliaciones` (`revisado`);
CREATE INDEX `idx_estado_revisado` ON `conciliaciones` (`estado_id`, `revisado`);
CREATE INDEX `idx_revisado_en` ON `conciliaciones` (`revisado_en`);
CREATE INDEX `idx_match_tipo` ON `conciliaciones` (`match_tipo`);
CREATE INDEX `idx_score_total` ON `conciliaciones` (`score_total`);

-- =========================================
-- 3. RECREAR VISTA: v_conciliaciones_completas
-- Incluir nuevos campos de revisión y scoring
-- =========================================

DROP VIEW IF EXISTS `v_conciliaciones_completas`;

CREATE VIEW `v_conciliaciones_completas` AS
SELECT 
    c.id AS conciliacion_id,
    c.fecha_conciliacion,
    
    -- Estado y tipo de match
    e.codigo AS estado_codigo,
    e.nombre AS estado_nombre,
    e.color_hex AS estado_color,
    c.match_tipo,
    
    -- Scores de matching
    c.score_numero,
    c.score_proveedor,
    c.score_total,
    c.observaciones_match,
    
    -- Datos de factura XML
    f.id AS factura_id,
    f.consecutivo_completo,
    f.numero_factura_asistente,
    f.fecha_emision,
    f.subtotal AS factura_base,
    f.iva AS factura_iva,
    f.total AS factura_total,
    p.razon_social AS proveedor,
    p.rfc AS proveedor_rfc,
    
    -- Datos de gasto consolidado
    g.id AS gasto_id,
    g.numero_factura AS gasto_numero_factura,
    g.proveedor_texto AS gasto_proveedor,
    g.fecha_min AS gasto_fecha_min,
    g.fecha_max AS gasto_fecha_max,
    g.suma_base AS gasto_base,
    g.suma_iva AS gasto_iva,
    g.suma_total AS gasto_total,
    g.cantidad_items AS gasto_items,
    
    -- Diferencias calculadas
    c.diferencia_base,
    c.diferencia_iva,
    c.diferencia_total,
    c.porcentaje_diferencia,
    
    -- Campos de revisión manual
    c.revisado,
    c.revisado_por,
    c.revisado_en,
    c.revision_comentario,
    
    -- Notas generales
    c.notas,
    c.actualizado_en

FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN proveedores p ON f.proveedor_id = p.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id;

-- =========================================
-- 4. VISTAS ADICIONALES PARA REPORTES
-- =========================================

-- Vista: Conciliaciones pendientes de revisión
DROP VIEW IF EXISTS `v_pendientes_revision`;

CREATE VIEW `v_pendientes_revision` AS
SELECT 
    c.id AS conciliacion_id,
    e.codigo AS estado_codigo,
    e.nombre AS estado_nombre,
    c.match_tipo,
    c.score_total,
    f.numero_factura_asistente AS factura_numero,
    p.razon_social AS factura_proveedor,
    f.total AS factura_total,
    g.numero_factura AS gasto_numero,
    g.proveedor_texto AS gasto_proveedor,
    g.suma_total AS gasto_total,
    c.diferencia_total,
    c.porcentaje_diferencia,
    c.observaciones_match,
    c.fecha_conciliacion
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN proveedores p ON f.proveedor_id = p.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
WHERE c.revisado = 0
  AND e.codigo IN ('requiere_revision', 'con_diferencias')
ORDER BY 
    CASE 
        WHEN e.codigo = 'requiere_revision' THEN 1
        WHEN e.codigo = 'con_diferencias' THEN 2
        ELSE 3
    END,
    c.score_total DESC,
    c.fecha_conciliacion DESC;

-- Vista: Resumen de estados por revisión
DROP VIEW IF EXISTS `v_resumen_revision`;

CREATE VIEW `v_resumen_revision` AS
SELECT 
    e.codigo AS estado_codigo,
    e.nombre AS estado_nombre,
    e.color_hex AS color,
    COUNT(*) AS total,
    SUM(CASE WHEN c.revisado = 0 THEN 1 ELSE 0 END) AS sin_revisar,
    SUM(CASE WHEN c.revisado = 1 THEN 1 ELSE 0 END) AS revisadas,
    ROUND(SUM(CASE WHEN c.revisado = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) AS porcentaje_revisado
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
GROUP BY e.id, e.codigo, e.nombre, e.color_hex, e.orden
ORDER BY e.orden;

-- =========================================
-- 5. PROCEDIMIENTO ALMACENADO: Marcar como revisado
-- Facilita la actualización de revisión desde PHP
-- =========================================

DROP PROCEDURE IF EXISTS `sp_marcar_revisado`;

DELIMITER $$

CREATE PROCEDURE `sp_marcar_revisado`(
    IN p_conciliacion_id INT UNSIGNED,
    IN p_usuario VARCHAR(100),
    IN p_comentario TEXT
)
BEGIN
    UPDATE `conciliaciones`
    SET 
        `revisado` = 1,
        `revisado_por` = p_usuario,
        `revisado_en` = CURRENT_TIMESTAMP,
        `revision_comentario` = p_comentario
    WHERE `id` = p_conciliacion_id;
    
    -- Retornar confirmación
    SELECT 
        `id`,
        `revisado`,
        `revisado_por`,
        `revisado_en`
    FROM `conciliaciones`
    WHERE `id` = p_conciliacion_id;
END$$

DELIMITER ;

-- =========================================
-- 6. ÍNDICES ADICIONALES PARA PERFORMANCE
-- =========================================

-- Índice compuesto para búsquedas de revisión por estado y fecha
CREATE INDEX `idx_estado_revisado_fecha` ON `conciliaciones` (`estado_id`, `revisado`, `fecha_conciliacion`);

-- Índice para búsqueda de conciliaciones sugeridas con alto score
CREATE INDEX `idx_match_score` ON `conciliaciones` (`match_tipo`, `score_total`);

-- =========================================
-- 7. COMENTARIOS EN TABLAS (DOCUMENTATION)
-- =========================================

-- Actualizar comentario de la tabla para reflejar nuevas funcionalidades
ALTER TABLE `conciliaciones` 
COMMENT = 'Registro de conciliaciones entre facturas XML y gastos con sistema de revisión manual y scoring';

-- =========================================
-- FIN DE MIGRACIÓN 001
-- =========================================

-- Verification query (ejecutar después de la migración)
-- SELECT 'Migración completada exitosamente' AS status;
-- SELECT codigo, nombre, orden FROM catalogo_estados ORDER BY orden;
-- SELECT COUNT(*) as total_conciliaciones, 
--        SUM(revisado) as revisadas,
--        SUM(1-revisado) as sin_revisar
-- FROM conciliaciones;
