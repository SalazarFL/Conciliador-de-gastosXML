-- Migración: las ocho referencias que quedaban sueltas hacia roles, pagos,
-- importaciones y facturas.
--
-- POR QUÉ
--
-- Es el tercer y último grupo grande de columnas `_id` sin clave foránea. Cada
-- una deja un agujero distinto:
--
--   * usuarios.rol_id y rol_permisos.rol_id
--     Borrar un rol dejaba a su gente apuntando a un rol que no existe y sus
--     permisos colgando. Rol::eliminar limpiaba a mano, pero el DELETE del rol
--     estaba condicionado a `es_sistema = 0` y las dos limpiezas de antes NO:
--     pedir el borrado de un rol del sistema le vaciaba los permisos y dejaba
--     a su gente sin rol, sin borrar el rol y sin avisar de nada.
--
--   * facturas_xml.pago_id y facturas_erp.pago_id
--     Pago::eliminar sí las suelta bien, pero es lo único que lo hace: un
--     `DELETE FROM pagos` tecleado en consola deja facturas asignadas a un
--     pago que no existe, que es un estado que ninguna pantalla sabe mostrar.
--
--   * almacen_copias_pago.factura_id
--     Es la anotación de "esta factura ya se copió a la carpeta del pago", y
--     existe para no salir a la red a preguntar lo que ya se sabe. Si la
--     factura se borra, la anotación miente sobre un documento que no está.
--
--   * correo_bandeja.importacion_id
--     La bandeja anota con qué importación se procesó cada documento. Borrada
--     la importación, la anotación apunta a un número que ya no dice nada.
--
--   * importacion_items.importacion_id y .factura_id
--     Estas dos NO SE PODÍAN PONER, igual que pasaba con usuarios.id: son
--     BIGINT(20) UNSIGNED contra padres INT(10) UNSIGNED, y a InnoDB los tipos
--     le tienen que coincidir. El BIGINT no servía para nada —ningún id de
--     importación ni de factura se acerca a los cuatro mil millones— y lo
--     único que hacía era bloquear las claves.
--
-- QUÉ REGLA LLEVA CADA UNA
--
--   CASCADE (3)  rol_permisos, almacen_copias_pago, importacion_items ->
--                importaciones. No significan nada sin su padre: un permiso de
--                un rol borrado, una anotación de copia de una factura que no
--                está, una línea de una importación que no existe.
--
--   SET NULL (5) usuarios.rol_id, los dos pago_id, correo_bandeja.
--                importacion_id e importacion_items.factura_id.
--                La fila tiene que sobrevivir: la persona sigue
--                estando aunque su rol se borre —y se queda SIN rol a
--                propósito, porque elegirle otro sería cambiarle los permisos
--                sin que nadie se entere—, y la factura del ERP sigue estando
--                aunque su pago se borre; vuelve a quedar disponible, que es
--                justo lo que hace Pago::eliminar hoy a mano.
--
-- CÓMO SE APLICA
--
-- Los pasos 1 y 2 se leen. El paso 1 tiene que dar 0 en las dos filas o el
-- cambio de tipo del paso 3 truncaría ids en silencio. Los pasos 3 a 5
-- escriben, y conviene correrlos con la aplicación parada.
--
-- Es repetible: cada clave se borra en su propia sentencia antes de crearse.
-- (En un solo ALTER no se puede: InnoDB contesta errno 121 al recrear una
-- restricción con el nombre que se acaba de soltar en la misma sentencia.)


-- =====================================================================
-- 1. ANTES: nada puede quedar fuera del rango de INT UNSIGNED
-- =====================================================================
-- Las dos DEBEN dar 0. Si alguna no da 0, PARAR: el paso 3 truncaría esos ids.

SELECT 'importacion_items.importacion_id fuera de rango (debe dar 0)' AS revision,
       COUNT(*) AS filas FROM `importacion_items` WHERE `importacion_id` > 4294967295
UNION ALL
SELECT 'importacion_items.factura_id fuera de rango (debe dar 0)',
       COUNT(*) FROM `importacion_items` WHERE `factura_id` > 4294967295;


-- =====================================================================
-- 2. ANTES: referencias que ya están rotas
-- =====================================================================
-- Todas se limpian solas en el paso 3; se miran para saber cuánto daño había.

SELECT 'usuarios -> rol inexistente' AS tabla, COUNT(*) AS rotas
  FROM `usuarios` t LEFT JOIN `roles` r ON r.id=t.rol_id WHERE t.rol_id IS NOT NULL AND r.id IS NULL
UNION ALL SELECT 'rol_permisos -> rol inexistente',    COUNT(*) FROM `rol_permisos` t LEFT JOIN `roles` r ON r.id=t.rol_id WHERE r.id IS NULL
UNION ALL SELECT 'facturas_xml -> pago inexistente',   COUNT(*) FROM `facturas_xml` t LEFT JOIN `pagos` p ON p.id=t.pago_id WHERE t.pago_id IS NOT NULL AND p.id IS NULL
UNION ALL SELECT 'facturas_erp -> pago inexistente',   COUNT(*) FROM `facturas_erp` t LEFT JOIN `pagos` p ON p.id=t.pago_id WHERE t.pago_id IS NOT NULL AND p.id IS NULL
UNION ALL SELECT 'almacen_copias_pago -> factura inexistente', COUNT(*) FROM `almacen_copias_pago` t LEFT JOIN `facturas_xml` f ON f.id=t.factura_id WHERE f.id IS NULL
UNION ALL SELECT 'correo_bandeja -> importacion inexistente',  COUNT(*) FROM `correo_bandeja` t LEFT JOIN `importaciones` i ON i.id=t.importacion_id WHERE t.importacion_id IS NOT NULL AND i.id IS NULL
UNION ALL SELECT 'importacion_items -> importacion inexistente', COUNT(*) FROM `importacion_items` t LEFT JOIN `importaciones` i ON i.id=t.importacion_id WHERE i.id IS NULL
UNION ALL SELECT 'importacion_items -> factura inexistente',     COUNT(*) FROM `importacion_items` t LEFT JOIN `facturas_xml` f ON f.id=t.factura_id WHERE t.factura_id IS NOT NULL AND f.id IS NULL;


-- =====================================================================
-- 3. Soltar lo roto y arreglar los dos tipos
-- =====================================================================

UPDATE `usuarios` t     LEFT JOIN `roles` r ON r.id=t.rol_id  SET t.rol_id  = NULL WHERE t.rol_id  IS NOT NULL AND r.id IS NULL;
UPDATE `facturas_xml` t LEFT JOIN `pagos` p ON p.id=t.pago_id SET t.pago_id = NULL WHERE t.pago_id IS NOT NULL AND p.id IS NULL;
UPDATE `facturas_erp` t LEFT JOIN `pagos` p ON p.id=t.pago_id SET t.pago_id = NULL WHERE t.pago_id IS NOT NULL AND p.id IS NULL;
UPDATE `correo_bandeja` t LEFT JOIN `importaciones` i ON i.id=t.importacion_id SET t.importacion_id = NULL WHERE t.importacion_id IS NOT NULL AND i.id IS NULL;
UPDATE `importacion_items` t LEFT JOIN `facturas_xml` f ON f.id=t.factura_id SET t.factura_id = NULL WHERE t.factura_id IS NOT NULL AND f.id IS NULL;

-- Estas dos no se pueden vaciar —son NOT NULL— y una fila cuyo padre no existe
-- no se puede reconstruir: se quitan, que es lo que la clave CASCADE habría
-- hecho sola el día que se borró el padre.
DELETE t FROM `rol_permisos` t      LEFT JOIN `roles` r ON r.id=t.rol_id         WHERE r.id IS NULL;
DELETE t FROM `almacen_copias_pago` t LEFT JOIN `facturas_xml` f ON f.id=t.factura_id WHERE f.id IS NULL;
DELETE t FROM `importacion_items` t LEFT JOIN `importaciones` i ON i.id=t.importacion_id WHERE i.id IS NULL;

-- Los tipos, ahora que ya no hay nada que estorbe.
ALTER TABLE `importacion_items`
    MODIFY COLUMN `importacion_id` INT UNSIGNED NOT NULL,
    MODIFY COLUMN `factura_id` INT UNSIGNED DEFAULT NULL;


-- =====================================================================
-- 4. Las ocho claves
-- =====================================================================

ALTER TABLE `usuarios` DROP FOREIGN KEY IF EXISTS `fk_usuarios_rol`;
ALTER TABLE `usuarios`
    ADD CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;

ALTER TABLE `rol_permisos` DROP FOREIGN KEY IF EXISTS `fk_rol_permisos_rol`;
ALTER TABLE `rol_permisos`
    ADD CONSTRAINT `fk_rol_permisos_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

ALTER TABLE `facturas_xml` DROP FOREIGN KEY IF EXISTS `fk_facturas_pago`;
ALTER TABLE `facturas_xml`
    ADD CONSTRAINT `fk_facturas_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp` DROP FOREIGN KEY IF EXISTS `fk_erp_pago`;
ALTER TABLE `facturas_erp`
    ADD CONSTRAINT `fk_erp_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE SET NULL;

ALTER TABLE `almacen_copias_pago` DROP FOREIGN KEY IF EXISTS `fk_copia_pago_factura`;
ALTER TABLE `almacen_copias_pago`
    ADD CONSTRAINT `fk_copia_pago_factura` FOREIGN KEY (`factura_id`) REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE;

ALTER TABLE `correo_bandeja` DROP FOREIGN KEY IF EXISTS `fk_bandeja_importacion`;
ALTER TABLE `correo_bandeja`
    ADD CONSTRAINT `fk_bandeja_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`) ON DELETE SET NULL;

ALTER TABLE `importacion_items` DROP FOREIGN KEY IF EXISTS `fk_items_importacion`;
ALTER TABLE `importacion_items`
    ADD CONSTRAINT `fk_items_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`) ON DELETE CASCADE;

ALTER TABLE `importacion_items` DROP FOREIGN KEY IF EXISTS `fk_items_factura`;
ALTER TABLE `importacion_items`
    ADD CONSTRAINT `fk_items_factura` FOREIGN KEY (`factura_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL;


-- =====================================================================
-- 5. DESPUÉS: comprobar
-- =====================================================================
-- Los dos tipos deben decir `int(10) unsigned`, y las claves totales de la
-- base deben ser 61 (20 de siempre + 17 de usuarios + 16 de sociedades + 8).
-- `php tests/ClavesForaneasRestoTest.php` comprueba además la regla de cada
-- una y que actúen de verdad.

SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'importacion_items'
   AND COLUMN_NAME IN ('importacion_id', 'factura_id');

SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS claves_foraneas_totales
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL;
