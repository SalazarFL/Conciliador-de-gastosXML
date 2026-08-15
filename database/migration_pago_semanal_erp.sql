-- El pago semanal deja de copiar el archivo: la línea del pago ES la factura
-- del ERP.
--
-- Hasta ahora un pago semanal era `porpagar_facturas`: una copia de cada
-- renglón del archivo del ERP con su fecha, número, proveedor y total. Esa
-- copia era el problema. El mismo documento se escribía de cinco formas según
-- de dónde se exportara el archivo, el nombre del proveedor llegaba recortado a
-- lo ancho de la columna o con anotaciones pegadas a mano, y todo el
-- emparejador difuso —umbrales, rescates, aprendizaje de alias— existía para
-- reparar eso. Y convivían dos versiones de la misma factura, la del archivo y
-- la del ERP, sin nada que garantizara que dijeran lo mismo.
--
-- Ahora el archivo del pago aporta una selección, no datos: dice qué facturas
-- se pagan esta semana. Cada fila se resuelve contra `facturas_erp` (documento
-- + proveedor + saldo) y esa factura queda marcada con `porpagar_listado_id` y
-- `semana_id`. Los datos son los del ERP, que son los que cuadran contra los
-- totales impresos del proveedor.
--
-- ORDEN DE APLICACIÓN
--   1. Este archivo (añade columnas; no borra nada).
--   2. php cli/migrar_pago_semanal_erp.php            → simulación
--      php cli/migrar_pago_semanal_erp.php --aplicar  → traslada los pagos ya
--      cargados y el emparejamiento con XML que ya tuvieran.
--   3. Cuando estés conforme con lo que se ve en el módulo:
--      php cli/migrar_pago_semanal_erp.php --retirar --aplicar
--      (solo renombra `porpagar_facturas` a `porpagar_facturas_respaldo`).
--
-- Los modelos aplican lo mismo al arrancar (FacturaErp::ensureTables); este
-- archivo es para instalaciones sin permisos DDL en tiempo de ejecución.

-- ── Respaldo electrónico: qué XML respalda cada factura del ERP ──────
ALTER TABLE facturas_erp
    ADD COLUMN `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL AFTER `asignada_semana_en`,
    ADD COLUMN `estado_respaldo` ENUM('sin_respaldo','respaldada','con_diferencia')
        NOT NULL DEFAULT 'sin_respaldo' AFTER `factura_xml_id`,
    ADD COLUMN `diferencia` DECIMAL(18,2) NULL DEFAULT NULL AFTER `estado_respaldo`,
    ADD COLUMN `score_numero` DECIMAL(5,1) NULL DEFAULT NULL AFTER `diferencia`,
    ADD COLUMN `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL AFTER `score_numero`,
    ADD COLUMN `match_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `score_proveedor`,
    ADD KEY `idx_factura_xml` (`factura_xml_id`),
    ADD KEY `idx_estado_respaldo` (`porpagar_listado_id`, `estado_respaldo`);

-- ── Foto del saldo al entrar al pago ────────────────────────────────
-- El saldo del ERP baja a cero cuando la factura se paga. Sin esta foto, volver
-- a cargar el reporte después de pagar dejaría la semana entera en ₡0 sin que
-- nadie la hubiera tocado. No es copiar el archivo: es el saldo del propio ERP
-- en el momento en que se decidió pagarlo.
ALTER TABLE facturas_erp
    ADD COLUMN `saldo_pago` DECIMAL(18,2) NULL DEFAULT NULL AFTER `asignada_semana_en`;

-- ── Lo que NO hace este archivo ─────────────────────────────────────
-- No toca `porpagar_facturas`. El traslado de los pagos ya cargados necesita
-- resolver número + proveedor + saldo contra el ERP, que es trabajo del CLI y
-- no de una sentencia SQL. Y borrar la tabla vieja es un paso aparte, para que
-- haya a dónde volver si algo no cuadra:
--
--   RENAME TABLE porpagar_facturas TO porpagar_facturas_respaldo;   -- (paso 3)
--   -- y mucho después, cuando ya no haga falta:
--   -- DROP TABLE porpagar_facturas_respaldo;
