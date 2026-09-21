-- Migración: borrar una empresa deja de poder llevarse sus documentos en silencio.
--
-- POR QUÉ
--
-- `SociedadesController::eliminar` hace un `DELETE FROM sociedades WHERE id = ?`
-- pelado, sin comprobar nada. Dieciocho tablas guardan un `sociedad_id` y solo
-- tres tienen clave foránea, así que hoy se puede borrar una empresa con
-- catorce mil facturas y no pasa nada visible: las facturas se quedan con un
-- `sociedad_id` que ya no existe. No salen en ninguna pantalla —todas filtran
-- por empresa—, no se pueden reasignar desde la interfaz, y no queda forma de
-- saber de quién eran salvo adivinar por la cédula del receptor.
--
-- La asimetría que lo resume, en la MISMA tabla:
--
--     facturas_xml.proveedor_id  ->  proveedores.id   con clave, RESTRICT
--     facturas_xml.sociedad_id   ->  (nada)
--
-- No se puede borrar un proveedor que tenga facturas. Sí se puede borrar la
-- empresa dueña de esas facturas.
--
-- QUÉ REGLA LLEVA CADA UNA
--
-- Tres grupos, por lo que significa la fila cuando su empresa desaparece:
--
--   * RESTRICT (6) — documentos y dinero.
--     facturas_xml, facturas_erp, devoluciones, pagos, notas_credito_cargas,
--     correo_bandeja. Son el trabajo de la empresa y no se pierden nunca: la
--     clave impide el borrado y obliga a vaciar primero. Es exactamente el
--     agujero que cierra esta migración.
--
--   * CASCADE (7) — permisos y bandejas de trabajo.
--     usuario_sociedades, las cuatro tablas *_revision y *_revision_memoria, y
--     almacen_migracion. No significan nada sin su empresa: un permiso de
--     acceso a una empresa que no existe, o una línea esperando revisión de un
--     listado de nadie, es basura que hoy se queda.
--
--   * SET NULL (3) — rastro que sobrevive.
--     bitacora (auditoría), usuarios.sociedad_id (es la empresa con la que
--     cada quien está trabajando, una preferencia, no un dato) y
--     facturas_erp_cargas (el registro de que la carga ocurrió).
--
-- DOS QUE SE QUEDAN SIN CLAVE, A PROPÓSITO
--
--   * notificaciones.sociedad_id es NOT NULL DEFAULT 0 y 0 significa "aviso
--     del grupo". No es descuido: migration_sociedades_unicidad.sql explica
--     que se eligió 0 y no NULL porque la unicidad `uk_firma (sociedad_id,
--     firma)` es la que junta el mismo aviso repetido en un solo renglón, y
--     MySQL considera distintos dos NULL —con NULL, cada repetición abriría
--     fila nueva—. Una clave foránea exigiría una empresa con id 0, que
--     saldría en todos los selectores de empresa. El centinela sostiene algo;
--     no se toca aquí.
--
--   * correo_procesados.sociedad_id es NOT NULL DEFAULT 0 por la misma forma.
--     Ahí el 0 sí es transitorio —la migración rellena todas las filas con la
--     empresa por omisión—, pero al ser NOT NULL no hay SET NULL al que caer y
--     una fila con 0 haría fallar el INSERT en mitad de la sincronización de
--     correo. El paso 2 la cuenta: si da 0 en la base viva, se le puede poner
--     CASCADE en una migración aparte y sin prisa.
--
-- CÓMO SE APLICA
--
-- Los pasos 1 y 2 se leen; no cambian nada. EL PASO 2 IMPORTA: si alguna tabla
-- de documentos tiene filas apuntando a una empresa borrada, el paso 4 va a
-- fallar en esa tabla, y está bien que falle. NO las vacíes ni las reasignes
-- por tu cuenta: una factura no se le puede adivinar el dueño, y ponerle la
-- empresa equivocada es peor que dejarla huérfana. Hay que mirarlas una por
-- una y decidir, o recrear la empresa que faltaba con su id.
--
-- Los pasos 3 y 4 escriben, y conviene correrlos con la aplicación parada.
-- Es repetible: cada clave se borra antes de crearse.


-- =====================================================================
-- 1. ANTES: a qué empresas apuntan las filas
-- =====================================================================
SELECT id, nombre, cedula, activa FROM `sociedades` ORDER BY id;


-- =====================================================================
-- 2. ANTES: filas que apuntan a una empresa que ya no existe
-- =====================================================================
-- Las seis primeras DEBEN dar 0 o el paso 4 falla en esa tabla. Las demás se
-- limpian solas en el paso 3.
-- La última no es huérfana: es el centinela de correo_procesados, y solo se
-- mira para saber si algún día se le puede poner clave.

SELECT '[RESTRICT] facturas_xml'         AS tabla, COUNT(*) AS huerfanas FROM `facturas_xml` t          LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[RESTRICT] facturas_erp',         COUNT(*) FROM `facturas_erp` t          LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[RESTRICT] devoluciones',         COUNT(*) FROM `devoluciones` t          LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[RESTRICT] pagos',                COUNT(*) FROM `pagos` t                 LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[RESTRICT] notas_credito_cargas', COUNT(*) FROM `notas_credito_cargas` t  LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[RESTRICT] correo_bandeja',       COUNT(*) FROM `correo_bandeja` t        LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[cascade]  usuario_sociedades',   COUNT(*) FROM `usuario_sociedades` t    LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[cascade]  almacen_migracion',    COUNT(*) FROM `almacen_migracion` t     LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[cascade]  facturas_erp_revision',          COUNT(*) FROM `facturas_erp_revision` t          LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[cascade]  facturas_erp_revision_memoria',  COUNT(*) FROM `facturas_erp_revision_memoria` t  LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[cascade]  facturas_erp_descartes',         COUNT(*) FROM `facturas_erp_descartes` t         LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[cascade]  notas_credito_revision',         COUNT(*) FROM `notas_credito_revision` t         LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[cascade]  notas_credito_revision_memoria', COUNT(*) FROM `notas_credito_revision_memoria` t LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL
UNION ALL SELECT '[set null] bitacora',             COUNT(*) FROM `bitacora` t              LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[set null] usuarios',             COUNT(*) FROM `usuarios` t              LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '[set null] facturas_erp_cargas',  COUNT(*) FROM `facturas_erp_cargas` t   LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL
UNION ALL SELECT '(sin clave) correo_procesados con 0', COUNT(*) FROM `correo_procesados` WHERE sociedad_id = 0;


-- =====================================================================
-- 3. Limpiar lo que sí se puede limpiar solo
-- =====================================================================
-- Solo los tres grupos donde la fila no es un documento. Las de RESTRICT no se
-- tocan a propósito: ver el aviso de arriba.

UPDATE `bitacora` t            LEFT JOIN `sociedades` s ON s.id=t.sociedad_id SET t.sociedad_id = NULL WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL;
UPDATE `usuarios` t            LEFT JOIN `sociedades` s ON s.id=t.sociedad_id SET t.sociedad_id = NULL WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL;
UPDATE `facturas_erp_cargas` t LEFT JOIN `sociedades` s ON s.id=t.sociedad_id SET t.sociedad_id = NULL WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL;

DELETE t FROM `usuario_sociedades` t            LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `almacen_migracion` t             LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `facturas_erp_revision` t         LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `facturas_erp_revision_memoria` t LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `notas_credito_revision` t        LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `notas_credito_revision_memoria` t LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE s.id IS NULL;
DELETE t FROM `facturas_erp_descartes` t        LEFT JOIN `sociedades` s ON s.id=t.sociedad_id WHERE t.sociedad_id IS NOT NULL AND s.id IS NULL;


-- =====================================================================
-- 4. Las dieciséis claves
-- =====================================================================

-- ── Documentos y dinero: no se borran nunca ──
ALTER TABLE `facturas_xml` DROP FOREIGN KEY IF EXISTS `fk_facturas_sociedad`;
ALTER TABLE `facturas_xml`
    ADD CONSTRAINT `fk_facturas_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

ALTER TABLE `facturas_erp` DROP FOREIGN KEY IF EXISTS `fk_erp_sociedad`;
ALTER TABLE `facturas_erp`
    ADD CONSTRAINT `fk_erp_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

ALTER TABLE `devoluciones` DROP FOREIGN KEY IF EXISTS `fk_dev_sociedad`;
ALTER TABLE `devoluciones`
    ADD CONSTRAINT `fk_dev_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

ALTER TABLE `pagos` DROP FOREIGN KEY IF EXISTS `fk_pagos_sociedad`;
ALTER TABLE `pagos`
    ADD CONSTRAINT `fk_pagos_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

ALTER TABLE `notas_credito_cargas` DROP FOREIGN KEY IF EXISTS `fk_nc_carga_sociedad`;
ALTER TABLE `notas_credito_cargas`
    ADD CONSTRAINT `fk_nc_carga_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

ALTER TABLE `correo_bandeja` DROP FOREIGN KEY IF EXISTS `fk_bandeja_sociedad`;
ALTER TABLE `correo_bandeja`
    ADD CONSTRAINT `fk_bandeja_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE RESTRICT;

-- ── Permisos y bandejas: se van con su empresa ──
ALTER TABLE `usuario_sociedades` DROP FOREIGN KEY IF EXISTS `fk_us_sociedad`;
ALTER TABLE `usuario_sociedades`
    ADD CONSTRAINT `fk_us_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `almacen_migracion` DROP FOREIGN KEY IF EXISTS `fk_migracion_sociedad`;
ALTER TABLE `almacen_migracion`
    ADD CONSTRAINT `fk_migracion_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `facturas_erp_revision` DROP FOREIGN KEY IF EXISTS `fk_erp_revision_sociedad`;
ALTER TABLE `facturas_erp_revision`
    ADD CONSTRAINT `fk_erp_revision_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `facturas_erp_revision_memoria` DROP FOREIGN KEY IF EXISTS `fk_erp_memoria_sociedad`;
ALTER TABLE `facturas_erp_revision_memoria`
    ADD CONSTRAINT `fk_erp_memoria_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `facturas_erp_descartes` DROP FOREIGN KEY IF EXISTS `fk_erp_descarte_sociedad`;
ALTER TABLE `facturas_erp_descartes`
    ADD CONSTRAINT `fk_erp_descarte_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `notas_credito_revision` DROP FOREIGN KEY IF EXISTS `fk_nc_revision_sociedad`;
ALTER TABLE `notas_credito_revision`
    ADD CONSTRAINT `fk_nc_revision_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

ALTER TABLE `notas_credito_revision_memoria` DROP FOREIGN KEY IF EXISTS `fk_nc_memoria_sociedad`;
ALTER TABLE `notas_credito_revision_memoria`
    ADD CONSTRAINT `fk_nc_memoria_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE;

-- ── Rastro: sobrevive sin su empresa ──
ALTER TABLE `bitacora` DROP FOREIGN KEY IF EXISTS `fk_bitacora_sociedad`;
ALTER TABLE `bitacora`
    ADD CONSTRAINT `fk_bitacora_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL;

ALTER TABLE `usuarios` DROP FOREIGN KEY IF EXISTS `fk_usuarios_sociedad`;
ALTER TABLE `usuarios`
    ADD CONSTRAINT `fk_usuarios_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_cargas` DROP FOREIGN KEY IF EXISTS `fk_erp_carga_sociedad`;
ALTER TABLE `facturas_erp_cargas`
    ADD CONSTRAINT `fk_erp_carga_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL;


-- =====================================================================
-- 5. DESPUÉS: comprobar
-- =====================================================================
-- Debe decir 19: las dieciséis de aquí más las tres que ya existían
-- (correo_cuenta_sociedades, correo_lotes, notas_credito_listados).
-- `php tests/ClavesForaneasSociedadTest.php` comprueba además la regla de
-- cada una y que borrar una empresa con documentos quede prohibido de verdad.

SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS claves_hacia_sociedades
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'sociedades';
