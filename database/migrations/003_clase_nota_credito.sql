-- ---------------------------------------------------------------------------
-- Clase de la nota de crédito
--
-- El reporte del ERP mezcla cuatro cosas bajo la misma columna "Documento" y
-- solo tres llegan a tener documento electrónico. Sin distinguirlas, la
-- pantalla muestra ~4.500 notas "sin respaldo" de las que ~1.800 nunca van a
-- tener XML, y la lista deja de servir para trabajar.
--
--   directa  NC- 17-1-00100001010000012473-684   corrige una factura
--   costo    NC- 1-1-D-99900001010000670607-189  diferencia de precio o costo
--   cambio   NC- 17-1-132-0                      cambio de mercadería
--   ajuste   NC- 4945                            ajuste interno, NUNCA lleva XML
--   revisar  lo que no encaja en ninguna
--
-- La clase la calcula ClaseNotaCredito::clasificar() al cargar el listado.
-- Esta migración se puede volver a correr sin romper nada.
-- ---------------------------------------------------------------------------

ALTER TABLE notas_credito_lineas
    ADD COLUMN IF NOT EXISTS clase
        ENUM('directa', 'costo', 'cambio', 'ajuste', 'revisar')
        NOT NULL DEFAULT 'revisar'
        COMMENT 'Qué clase de nota es, según la forma de su número'
        AFTER documento;

-- Las consultas de la pantalla filtran por listado y luego por clase y estado.
ALTER TABLE notas_credito_lineas
    ADD INDEX IF NOT EXISTS idx_nc_lineas_clase (listado_id, clase, estado);
