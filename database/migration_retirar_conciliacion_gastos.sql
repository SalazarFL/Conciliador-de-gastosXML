-- Retira las tablas de Conciliación, Gastos y Reportes.
--
-- Conciliación cruzaba las facturas XML contra un listado de "gastos" que se
-- cargaba aparte, y el módulo de Gastos existía solo para alimentarla. Ese
-- cruce hoy lo hace el pago semanal contra Facturas ERP, que es el registro del
-- sistema de la empresa y no un archivo suelto: la factura del ERP ES la línea
-- del pago, y el comprobante electrónico se le engancha por consecutivo.
--
-- Reportes exportaba justamente esas dos cosas más un listado de facturas. Hoy
-- cada módulo exporta lo suyo con sus propios filtros (pago semanal, notas de
-- crédito, seguimiento, facturas ERP), que es donde la gente ya está mirando
-- los datos.
--
-- COMPROBADO ANTES DE BORRAR: las cuatro tablas están en cero filas. El flujo
-- nunca llegó a usarse en producción; no se pierde ningún dato.
--
--   conciliaciones          0 filas
--   conciliacion_corridas   0 filas
--   gastos_consolidados     0 filas
--   gastos_raw              0 filas
--
-- Si querés comprobarlo vos antes de correr esto:
--   SELECT 'conciliaciones' t, COUNT(*) n FROM conciliaciones
--   UNION ALL SELECT 'conciliacion_corridas', COUNT(*) FROM conciliacion_corridas
--   UNION ALL SELECT 'gastos_consolidados',   COUNT(*) FROM gastos_consolidados
--   UNION ALL SELECT 'gastos_raw',            COUNT(*) FROM gastos_raw;

-- Las vistas primero: las tres leen de las tablas de abajo, así que borrarlas
-- después las dejaría rotas (devolviendo error en cualquier SELECT) en vez de
-- desaparecer.
DROP VIEW  IF EXISTS v_conciliaciones_completas;
DROP VIEW  IF EXISTS v_pendientes_revision;
DROP VIEW  IF EXISTS v_resumen_revision;

DROP TABLE IF EXISTS conciliaciones;
DROP TABLE IF EXISTS conciliacion_corridas;
DROP TABLE IF EXISTS gastos_consolidados;
DROP TABLE IF EXISTS gastos_raw;

-- El semáforo de aquel módulo: conciliada / con diferencias / pendiente /
-- gasto sin XML / requiere revisión. Cinco filas de catálogo que describen
-- estados de un cruce factura↔gasto que ya no se hace, y que ningún archivo
-- del código consulta. Los estados de hoy (respaldada / con_diferencia /
-- sin_respaldo) son ENUM en su propia tabla, no un catálogo aparte.
DROP TABLE IF EXISTS catalogo_estados;

-- `importaciones` NO se toca: la sigue usando la cola de importación de XML
-- (InvoiceImportQueue) para llevar el avance de cada carga del correo.
