-- =========================================
-- Vistas y procedimiento almacenado de bd_xmlconcilia
--
-- Se separaron de 001_revision_y_estado.sql porque hay que poder recrearlos
-- solos, sin volver a correr una migración entera: el usuario `xmlconcilia`
-- del servidor no tiene permiso para exportarlos (`SHOW CREATE PROCEDURE`
-- falla), así que un respaldo hecho con ese usuario trae los datos pero no
-- estas definiciones. Al restaurar una copia hay que correr este archivo
-- después.
--
-- Es idempotente: se puede ejecutar las veces que haga falta.
--   mysql -u root bd_xmlconcilia < database/vistas_y_procedimientos.sql
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

-- Procedimiento: marcar una conciliación como revisada
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
