-- Migración: las claves que quedaban hacia buzones, proveedores, cargas y listados.
--
-- POR QUÉ
--
-- Es el resto del inventario, después de usuarios, sociedades y
-- rol/pago/importación. Veintiséis columnas en cuatro familias:
--
--   * Los buzones (5)
--     `CorreoCuenta::eliminar` es un `DELETE FROM correo_cuentas WHERE id = ?`
--     pelado, igual que lo era el de sociedades. Borrar un buzón deja su
--     índice entero —que en la copia de agosto eran 134.681 mensajes—, sus
--     carpetas, su bandeja y su cola de capturas apuntando a una cuenta que ya
--     no existe. Nada de eso sale en ninguna pantalla: todas filtran por
--     cuenta.
--
--   * Los proveedores (2)
--     `facturas_xml.proveedor_id` sí tiene clave desde siempre (RESTRICT), y
--     por eso en la práctica no se puede borrar un proveedor con facturas.
--     Pero `devoluciones` y `proveedor_codigo_erp` quedaron fuera.
--
--   * Cargas, listados e historial (18)
--     Cada carga de un reporte del ERP o de notas de crédito deja filas que la
--     referencian. La peor es `notas_credito_historial`: `notas_credito_lineas`
--     se borra EN CASCADA al borrar su listado, y el historial no tenía clave,
--     así que sobrevivía a las líneas de las que habla.
--
--   * El historial de seguimiento (1)
--     `seguimiento_bitacora.seguimiento_id`, que es donde vive el comentario
--     de cada cambio de estado.
--
-- LAS REGLAS
--
--   CASCADE   lo que no significa nada sin su padre: el índice de un buzón
--             borrado, el mapeo de un código del ERP a un proveedor que ya no
--             está, las incidencias de una carga, el historial de un listado.
--
--   SET NULL  lo que sobrevive y solo pierde la etiqueta: una factura sigue
--             siendo una factura aunque se borre el registro de la carga que
--             la trajo, o el buzón del que salió.
--
--   RESTRICT  `correo_bandeja`, que es la única de la familia de correo que
--             guarda DOCUMENTOS (archivo_xml, archivo_pdf) todavía sin
--             importar. Igual que con las empresas: primero se vacía la
--             bandeja, después se borra el buzón.
--
-- TRES COLUMNAS CON CENTINELA: MIRAR EL PASO 1
--
-- `correo_indice.cuenta_id`, `correo_carpetas.cuenta_id` y
-- `correo_bandeja.cuenta_id` son NOT NULL DEFAULT 0, de cuando había un solo
-- buzón y la columna no existía. Si quedan filas con cuenta_id = 0, el paso 4
-- va a fallar en esas tablas, y está bien que falle: hay que decidir a qué
-- buzón pertenecen antes, porque ninguna cuenta tiene id 0.
--
-- UNA QUE SE QUEDA FUERA
--
-- `facturas_erp_revision.contenedor_id` no recibe clave: el módulo de facturas
-- del ERP no pasa contenedor —`registrarPendientes` lo deja en NULL siempre— y
-- la columna solo la usa notas de crédito, que sí la recibe. Ponerle una clave
-- a una columna que nadie llena es ruido.
--
-- CÓMO SE APLICA
--
-- Pasos 1 y 2 se leen. El paso 1 tiene que dar 0 en las tres filas del
-- centinela. El paso 3 limpia lo roto y el 4 crea las claves; conviene
-- correrlos con la aplicación parada. Es repetible.


-- =====================================================================
-- 1. ANTES: el centinela 0 de las tres tablas de correo
-- =====================================================================
-- Las tres DEBEN dar 0. Si alguna no, PARAR y decidir a qué buzón van.

SELECT 'correo_indice con cuenta_id = 0'   AS revision, COUNT(*) AS filas FROM `correo_indice`   WHERE `cuenta_id` = 0
UNION ALL SELECT 'correo_carpetas con cuenta_id = 0', COUNT(*) FROM `correo_carpetas` WHERE `cuenta_id` = 0
UNION ALL SELECT 'correo_bandeja con cuenta_id = 0',  COUNT(*) FROM `correo_bandeja`  WHERE `cuenta_id` = 0;


-- =====================================================================
-- 2. ANTES: lo que ya está roto
-- =====================================================================
SELECT 'correo_indice -> cuenta'      AS tabla, COUNT(*) AS rotas FROM `correo_indice` t        LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE t.cuenta_id <> 0 AND c.id IS NULL
UNION ALL SELECT 'correo_carpetas -> cuenta',      COUNT(*) FROM `correo_carpetas` t      LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE t.cuenta_id <> 0 AND c.id IS NULL
UNION ALL SELECT 'correo_capturas_auto -> cuenta', COUNT(*) FROM `correo_capturas_auto` t LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE c.id IS NULL
UNION ALL SELECT '[RESTRICT] correo_bandeja -> cuenta', COUNT(*) FROM `correo_bandeja` t  LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE t.cuenta_id <> 0 AND c.id IS NULL
UNION ALL SELECT 'facturas_xml -> correo_cuenta',  COUNT(*) FROM `facturas_xml` t         LEFT JOIN `correo_cuentas` c ON c.id=t.correo_cuenta_id WHERE t.correo_cuenta_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'devoluciones -> proveedor',      COUNT(*) FROM `devoluciones` t         LEFT JOIN `proveedores` p ON p.id=t.proveedor_id WHERE t.proveedor_id IS NOT NULL AND p.id IS NULL
UNION ALL SELECT 'proveedor_codigo_erp -> proveedor', COUNT(*) FROM `proveedor_codigo_erp` t LEFT JOIN `proveedores` p ON p.id=t.proveedor_id WHERE p.id IS NULL
UNION ALL SELECT 'facturas_erp -> carga',          COUNT(*) FROM `facturas_erp` t         LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_id WHERE t.carga_id IS NOT NULL AND g.id IS NULL
UNION ALL SELECT 'facturas_erp -> factura_xml',    COUNT(*) FROM `facturas_erp` t         LEFT JOIN `facturas_xml` x ON x.id=t.factura_xml_id WHERE t.factura_xml_id IS NOT NULL AND x.id IS NULL
UNION ALL SELECT 'facturas_erp_incidencias -> carga', COUNT(*) FROM `facturas_erp_incidencias` t LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_id WHERE g.id IS NULL
UNION ALL SELECT 'nc_lineas -> carga',             COUNT(*) FROM `notas_credito_lineas` t LEFT JOIN `notas_credito_cargas` g ON g.id=t.carga_id WHERE t.carga_id IS NOT NULL AND g.id IS NULL
UNION ALL SELECT 'nc_lineas -> factura_erp',       COUNT(*) FROM `notas_credito_lineas` t LEFT JOIN `facturas_erp` e ON e.id=t.factura_erp_id WHERE t.factura_erp_id IS NOT NULL AND e.id IS NULL
UNION ALL SELECT 'nc_historial -> listado',        COUNT(*) FROM `notas_credito_historial` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_id WHERE l.id IS NULL
UNION ALL SELECT 'nc_historial -> verificacion',   COUNT(*) FROM `notas_credito_historial` t LEFT JOIN `notas_credito_verificaciones` v ON v.id=t.verificacion_id WHERE v.id IS NULL
UNION ALL SELECT 'nc_verificaciones -> listado',   COUNT(*) FROM `notas_credito_verificaciones` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_id WHERE l.id IS NULL
UNION ALL SELECT 'seguimiento_bitacora -> seguimiento', COUNT(*) FROM `seguimiento_bitacora` t LEFT JOIN `seguimiento` s ON s.id=t.seguimiento_id WHERE s.id IS NULL;


-- =====================================================================
-- 3. Soltar y quitar lo roto
-- =====================================================================
-- Las anulables se vacían; las NOT NULL se borran, que es lo que la cascada
-- habría hecho sola el día que se borró el padre.

UPDATE `facturas_xml` t         LEFT JOIN `correo_cuentas` c ON c.id=t.correo_cuenta_id SET t.correo_cuenta_id = NULL WHERE t.correo_cuenta_id IS NOT NULL AND c.id IS NULL;
UPDATE `devoluciones` t         LEFT JOIN `proveedores` p ON p.id=t.proveedor_id        SET t.proveedor_id = NULL     WHERE t.proveedor_id IS NOT NULL AND p.id IS NULL;
UPDATE `facturas_erp` t         LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_id    SET t.carga_id = NULL         WHERE t.carga_id IS NOT NULL AND g.id IS NULL;
UPDATE `facturas_erp` t         LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_cambio_id SET t.carga_cambio_id = NULL WHERE t.carga_cambio_id IS NOT NULL AND g.id IS NULL;
UPDATE `facturas_erp` t         LEFT JOIN `facturas_xml` x ON x.id=t.factura_xml_id     SET t.factura_xml_id = NULL   WHERE t.factura_xml_id IS NOT NULL AND x.id IS NULL;
UPDATE `facturas_erp_revision` t LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_id   SET t.carga_id = NULL         WHERE t.carga_id IS NOT NULL AND g.id IS NULL;
UPDATE `facturas_erp_revision` t LEFT JOIN `facturas_erp` e ON e.id=t.resultado_id      SET t.resultado_id = NULL     WHERE t.resultado_id IS NOT NULL AND e.id IS NULL;
UPDATE `notas_credito_cargas` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_id SET t.listado_id = NULL     WHERE t.listado_id IS NOT NULL AND l.id IS NULL;
UPDATE `notas_credito_lineas` t LEFT JOIN `notas_credito_cargas` g ON g.id=t.carga_id   SET t.carga_id = NULL         WHERE t.carga_id IS NOT NULL AND g.id IS NULL;
UPDATE `notas_credito_lineas` t LEFT JOIN `notas_credito_cargas` g ON g.id=t.carga_cambio_id SET t.carga_cambio_id = NULL WHERE t.carga_cambio_id IS NOT NULL AND g.id IS NULL;
UPDATE `notas_credito_lineas` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_origen_id SET t.listado_origen_id = NULL WHERE t.listado_origen_id IS NOT NULL AND l.id IS NULL;
UPDATE `notas_credito_lineas` t LEFT JOIN `facturas_erp` e ON e.id=t.factura_erp_id     SET t.factura_erp_id = NULL   WHERE t.factura_erp_id IS NOT NULL AND e.id IS NULL;
UPDATE `notas_credito_revision` t LEFT JOIN `notas_credito_cargas` g ON g.id=t.carga_id SET t.carga_id = NULL         WHERE t.carga_id IS NOT NULL AND g.id IS NULL;
UPDATE `notas_credito_revision` t LEFT JOIN `notas_credito_listados` l ON l.id=t.contenedor_id SET t.contenedor_id = NULL WHERE t.contenedor_id IS NOT NULL AND l.id IS NULL;
UPDATE `notas_credito_revision` t LEFT JOIN `notas_credito_lineas` n ON n.id=t.resultado_id SET t.resultado_id = NULL WHERE t.resultado_id IS NOT NULL AND n.id IS NULL;
UPDATE `notas_credito_historial` t LEFT JOIN `notas_credito_lineas` n ON n.id=t.linea_id SET t.linea_id = NULL        WHERE t.linea_id IS NOT NULL AND n.id IS NULL;

DELETE t FROM `correo_indice` t        LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE t.cuenta_id <> 0 AND c.id IS NULL;
DELETE t FROM `correo_carpetas` t      LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE t.cuenta_id <> 0 AND c.id IS NULL;
DELETE t FROM `correo_capturas_auto` t LEFT JOIN `correo_cuentas` c ON c.id=t.cuenta_id WHERE c.id IS NULL;
DELETE t FROM `proveedor_codigo_erp` t LEFT JOIN `proveedores` p ON p.id=t.proveedor_id WHERE p.id IS NULL;
DELETE t FROM `facturas_erp_incidencias` t LEFT JOIN `facturas_erp_cargas` g ON g.id=t.carga_id WHERE g.id IS NULL;
DELETE t FROM `notas_credito_verificaciones` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_id WHERE l.id IS NULL;
DELETE t FROM `notas_credito_historial` t LEFT JOIN `notas_credito_listados` l ON l.id=t.listado_id WHERE l.id IS NULL;
DELETE t FROM `notas_credito_historial` t LEFT JOIN `notas_credito_verificaciones` v ON v.id=t.verificacion_id WHERE v.id IS NULL;
DELETE t FROM `seguimiento_bitacora` t LEFT JOIN `seguimiento` s ON s.id=t.seguimiento_id WHERE s.id IS NULL;

-- Las de RESTRICT no se tocan: si correo_bandeja tiene filas de un buzón
-- borrado, son documentos sin importar y hay que mirarlos, no borrarlos.


-- =====================================================================
-- 4. Las veintiséis claves
-- =====================================================================

-- ── Buzones ──
ALTER TABLE `correo_indice` DROP FOREIGN KEY IF EXISTS `fk_indice_cuenta`;
ALTER TABLE `correo_indice`
    ADD CONSTRAINT `fk_indice_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE;

ALTER TABLE `correo_carpetas` DROP FOREIGN KEY IF EXISTS `fk_carpetas_cuenta`;
ALTER TABLE `correo_carpetas`
    ADD CONSTRAINT `fk_carpetas_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE;

ALTER TABLE `correo_capturas_auto` DROP FOREIGN KEY IF EXISTS `fk_capturas_cuenta`;
ALTER TABLE `correo_capturas_auto`
    ADD CONSTRAINT `fk_capturas_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE;

ALTER TABLE `correo_bandeja` DROP FOREIGN KEY IF EXISTS `fk_bandeja_cuenta`;
ALTER TABLE `correo_bandeja`
    ADD CONSTRAINT `fk_bandeja_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE RESTRICT;

ALTER TABLE `facturas_xml` DROP FOREIGN KEY IF EXISTS `fk_facturas_correo_cuenta`;
ALTER TABLE `facturas_xml`
    ADD CONSTRAINT `fk_facturas_correo_cuenta` FOREIGN KEY (`correo_cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE SET NULL;

-- ── Proveedores ──
ALTER TABLE `devoluciones` DROP FOREIGN KEY IF EXISTS `fk_dev_proveedor`;
ALTER TABLE `devoluciones`
    ADD CONSTRAINT `fk_dev_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL;

ALTER TABLE `proveedor_codigo_erp` DROP FOREIGN KEY IF EXISTS `fk_codigo_erp_proveedor`;
ALTER TABLE `proveedor_codigo_erp`
    ADD CONSTRAINT `fk_codigo_erp_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE;

-- ── Cargas del ERP ──
ALTER TABLE `facturas_erp` DROP FOREIGN KEY IF EXISTS `fk_erp_carga`;
ALTER TABLE `facturas_erp`
    ADD CONSTRAINT `fk_erp_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp` DROP FOREIGN KEY IF EXISTS `fk_erp_carga_cambio`;
ALTER TABLE `facturas_erp`
    ADD CONSTRAINT `fk_erp_carga_cambio` FOREIGN KEY (`carga_cambio_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp` DROP FOREIGN KEY IF EXISTS `fk_erp_factura_xml`;
ALTER TABLE `facturas_erp`
    ADD CONSTRAINT `fk_erp_factura_xml` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_incidencias` DROP FOREIGN KEY IF EXISTS `fk_erp_incidencia_carga`;
ALTER TABLE `facturas_erp_incidencias`
    ADD CONSTRAINT `fk_erp_incidencia_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE CASCADE;

ALTER TABLE `facturas_erp_revision` DROP FOREIGN KEY IF EXISTS `fk_erp_revision_carga`;
ALTER TABLE `facturas_erp_revision`
    ADD CONSTRAINT `fk_erp_revision_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_revision` DROP FOREIGN KEY IF EXISTS `fk_erp_revision_resultado`;
ALTER TABLE `facturas_erp_revision`
    ADD CONSTRAINT `fk_erp_revision_resultado` FOREIGN KEY (`resultado_id`) REFERENCES `facturas_erp` (`id`) ON DELETE SET NULL;

-- ── Cargas y listados de notas de crédito ──
ALTER TABLE `notas_credito_cargas` DROP FOREIGN KEY IF EXISTS `fk_nc_carga_listado`;
ALTER TABLE `notas_credito_cargas`
    ADD CONSTRAINT `fk_nc_carga_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_lineas` DROP FOREIGN KEY IF EXISTS `fk_nc_linea_carga`;
ALTER TABLE `notas_credito_lineas`
    ADD CONSTRAINT `fk_nc_linea_carga` FOREIGN KEY (`carga_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_lineas` DROP FOREIGN KEY IF EXISTS `fk_nc_linea_carga_cambio`;
ALTER TABLE `notas_credito_lineas`
    ADD CONSTRAINT `fk_nc_linea_carga_cambio` FOREIGN KEY (`carga_cambio_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_lineas` DROP FOREIGN KEY IF EXISTS `fk_nc_linea_listado_origen`;
ALTER TABLE `notas_credito_lineas`
    ADD CONSTRAINT `fk_nc_linea_listado_origen` FOREIGN KEY (`listado_origen_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_lineas` DROP FOREIGN KEY IF EXISTS `fk_nc_linea_factura_erp`;
ALTER TABLE `notas_credito_lineas`
    ADD CONSTRAINT `fk_nc_linea_factura_erp` FOREIGN KEY (`factura_erp_id`) REFERENCES `facturas_erp` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_revision` DROP FOREIGN KEY IF EXISTS `fk_nc_revision_carga`;
ALTER TABLE `notas_credito_revision`
    ADD CONSTRAINT `fk_nc_revision_carga` FOREIGN KEY (`carga_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_revision` DROP FOREIGN KEY IF EXISTS `fk_nc_revision_contenedor`;
ALTER TABLE `notas_credito_revision`
    ADD CONSTRAINT `fk_nc_revision_contenedor` FOREIGN KEY (`contenedor_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_revision` DROP FOREIGN KEY IF EXISTS `fk_nc_revision_resultado`;
ALTER TABLE `notas_credito_revision`
    ADD CONSTRAINT `fk_nc_revision_resultado` FOREIGN KEY (`resultado_id`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_verificaciones` DROP FOREIGN KEY IF EXISTS `fk_nc_verificacion_listado`;
ALTER TABLE `notas_credito_verificaciones`
    ADD CONSTRAINT `fk_nc_verificacion_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE;

-- ── El historial que sobrevivía a sus líneas ──
ALTER TABLE `notas_credito_historial` DROP FOREIGN KEY IF EXISTS `fk_nc_historial_listado`;
ALTER TABLE `notas_credito_historial`
    ADD CONSTRAINT `fk_nc_historial_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE;

ALTER TABLE `notas_credito_historial` DROP FOREIGN KEY IF EXISTS `fk_nc_historial_verificacion`;
ALTER TABLE `notas_credito_historial`
    ADD CONSTRAINT `fk_nc_historial_verificacion` FOREIGN KEY (`verificacion_id`) REFERENCES `notas_credito_verificaciones` (`id`) ON DELETE CASCADE;

ALTER TABLE `notas_credito_historial` DROP FOREIGN KEY IF EXISTS `fk_nc_historial_linea`;
ALTER TABLE `notas_credito_historial`
    ADD CONSTRAINT `fk_nc_historial_linea` FOREIGN KEY (`linea_id`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE SET NULL;

-- ── El historial de seguimiento ──
ALTER TABLE `seguimiento_bitacora` DROP FOREIGN KEY IF EXISTS `fk_seg_bitacora_seguimiento`;
ALTER TABLE `seguimiento_bitacora`
    ADD CONSTRAINT `fk_seg_bitacora_seguimiento` FOREIGN KEY (`seguimiento_id`) REFERENCES `seguimiento` (`id`) ON DELETE CASCADE;


-- =====================================================================
-- 5. DESPUÉS: comprobar
-- =====================================================================
-- Debe decir 90 (61 + 3 de seguimiento + 26 de aquí).
-- `php tests/ClavesForaneasCorreoCargasTest.php` comprueba la regla de cada una.

SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS claves_foraneas_totales
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
