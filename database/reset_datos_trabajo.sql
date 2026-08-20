-- =====================================================================
--  RESET DE DATOS DE TRABAJO  ·  ¡DESTRUCTIVO Y SIN VUELTA ATRÁS!
-- ---------------------------------------------------------------------
--  Deja la base como el primer día de trabajo, pero sin volver a montar
--  el sistema. Se conserva lo que da acceso y lo que costó horas
--  construir; se borra todo lo que se puede volver a cargar.
--
--  SE CONSERVA
--    usuarios                     el login: nadie se queda afuera
--    sociedades                   las empresas y su cédula
--    correo_cuentas               los buzones configurados
--    correo_cuenta_sociedades     qué buzón atiende a qué empresa
--    correo_indice                el índice del buzón: 130 000+ mensajes
--                                 que tardan horas en volver a leerse
--    correo_carpetas              hasta dónde se sincronizó cada carpeta;
--                                 sin esto el índice se recorrería entero
--    proveedores                  el catálogo
--    proveedor_alias              los nombres alternativos resueltos a mano
--    proveedor_codigo_erp         el puente entre el nombre del XML y el
--                                 código del ERP, que se arma uno por uno
--
--  SE BORRA: todo el trabajo. Facturas XML y del ERP, notas de crédito,
--  pagos semanales, semanas, seguimiento, devoluciones, importaciones,
--  bandeja, lotes e incidencias del correo.
--
--  Las tablas no se eliminan: se vacían y su contador vuelve a 1.
--
--  ANTES DE CORRERLO: un respaldo. Ver docs/INSTALACION.md.
--  Si además se vacía la carpeta compartida de XML/PDF, hay que borrar
--  storage/cache/documentos_indice_*.json y documentos_estado_*.json,
--  que son la memoria de dónde estaba cada archivo.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Comprobantes electrónicos ────────────────────────────────────────
TRUNCATE TABLE facturas_xml_referencias;   -- referencias entre documentos
TRUNCATE TABLE facturas_xml_lineas;        -- detalle de línea de cada XML
TRUNCATE TABLE facturas_xml;               -- facturas y notas XML
TRUNCATE TABLE importacion_items;          -- cola de importación
TRUNCATE TABLE importaciones;              -- lotes de importación

-- ── Facturas del ERP ─────────────────────────────────────────────────
TRUNCATE TABLE facturas_erp_incidencias;   -- avisos de cada carga
TRUNCATE TABLE facturas_erp_descartes;     -- filas descartadas a propósito
TRUNCATE TABLE facturas_erp_cargas;        -- cargas del reporte
TRUNCATE TABLE facturas_erp;               -- las facturas y su saldo

-- ── Notas de crédito ─────────────────────────────────────────────────
TRUNCATE TABLE notas_credito_verificaciones;
TRUNCATE TABLE notas_credito_historial;
TRUNCATE TABLE notas_credito_lineas;
TRUNCATE TABLE notas_credito_listados;
TRUNCATE TABLE notas_credito_cargas;

-- ── Pago semanal ─────────────────────────────────────────────────────
TRUNCATE TABLE porpagar_facturas;          -- líneas (legado: hoy son ERP)
TRUNCATE TABLE porpagar_listados;          -- los listados subidos
TRUNCATE TABLE semanas;                    -- las semanas y su carpeta

-- ── Cola de trabajo ──────────────────────────────────────────────────
TRUNCATE TABLE seguimiento_bitacora;
TRUNCATE TABLE seguimiento;

-- ── Devoluciones ─────────────────────────────────────────────────────
TRUNCATE TABLE devolucion_matches;
TRUNCATE TABLE devolucion_lineas;
TRUNCATE TABLE devoluciones;

-- ── Correo: lo que se procesó, NO el índice ──────────────────────────
-- correo_indice y correo_carpetas se quedan. Lo demás son marcas de
-- trabajo: al vaciarlas, los mensajes vuelven a estar disponibles para
-- importar, que es justo lo que se quiere al empezar de cero.
TRUNCATE TABLE correo_bandeja;             -- bandeja de revisión
TRUNCATE TABLE correo_lote_items;
TRUNCATE TABLE correo_lotes;
TRUNCATE TABLE correo_procesados;          -- "este mensaje ya se importó"
TRUNCATE TABLE correo_capturas_auto;       -- capturas automáticas
TRUNCATE TABLE correo_incidencias_descartes;
TRUNCATE TABLE correo_incidencias;

-- ── Restos que apuntan a lo borrado ──────────────────────────────────
TRUNCATE TABLE proveedor_codigo_conflictos; -- vetos sobre facturas que ya no existen
TRUNCATE TABLE notificaciones;

SET FOREIGN_KEY_CHECKS = 1;
