-- =====================================================================
--  RESET DE DATOS DE TRABAJO  ·  SOLO LOCAL  ·  ¡DESTRUCTIVO!
-- ---------------------------------------------------------------------
--  Vacía las tablas transaccionales para empezar de cero, PERO conserva:
--    · usuarios          (login — no te quedas afuera)
--    · correo_cuentas    (cuentas de correo configuradas)
--    · sociedades        (empresas + cédula)
--    · proveedores       (catálogo de proveedores)
--    · correo_indice     (índice del buzón — NO re-sincronizas)
--    · correo_carpetas   (estado de sincronización del buzón)
--    · catalogo_estados  (catálogo de estados)
--
--  Las tablas se conservan (estructura intacta); solo se borran los
--  registros y se reinicia el AUTO_INCREMENT a 1.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE conciliaciones;         -- conciliaciones (legado)
TRUNCATE TABLE conciliacion_corridas;  -- corridas de conciliación (legado)
TRUNCATE TABLE gastos_consolidados;    -- gastos (legado)
TRUNCATE TABLE gastos_raw;             -- gastos crudos (legado, sin uso)

TRUNCATE TABLE importacion_items;      -- cola de importación de XML
TRUNCATE TABLE importaciones;          -- lotes de importación
TRUNCATE TABLE facturas_xml;           -- facturas importadas

TRUNCATE TABLE correo_bandeja;         -- bandeja de revisión del correo

TRUNCATE TABLE porpagar_facturas;      -- líneas de los listados por pagar
TRUNCATE TABLE porpagar_listados;      -- listados del pago semanal

TRUNCATE TABLE semanas;                -- semanas de trabajo

SET FOREIGN_KEY_CHECKS = 1;
