-- =====================================================================
--  BORRAR FACTURAS XML, LISTADOS POR PAGAR Y CORREOS PROCESADOS
--  SOLO LOCAL  ·  ¡DESTRUCTIVO!
-- ---------------------------------------------------------------------
--  Vacía las facturas importadas (con sus lotes de importación), los
--  listados del pago semanal (con sus líneas) y las marcas de correos
--  ya procesados — así los correos que ya se buscaron e importaron se
--  pueden volver a procesar sin que el módulo de Correo los rechace
--  con "ya está procesado". Conserva todo lo demás: usuarios, cuentas
--  de correo, sociedades, proveedores, semanas y el índice del buzón.
--
--  Se usa DELETE en lugar de TRUNCATE porque TRUNCATE exige el
--  privilegio DROP, que el hosting restringido no da. El ALTER TABLE
--  reinicia el consecutivo a 1 (si el hosting tampoco permite ALTER,
--  puedes quitar esas líneas: los IDs seguirán donde iban, sin más).
--
--  Este script solo toca la base de datos: los XML/PDF ya copiados a la
--  carpeta destino y los archivos en storage/ (incluidos los de la
--  bandeja de correo que se borra aquí) no se borran del disco.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Facturas XML importadas y su cola de importación (hijas primero)
DELETE FROM importacion_items;         -- cola de importación de XML
DELETE FROM importaciones;             -- lotes de importación
DELETE FROM facturas_xml;              -- facturas importadas

-- Listados de facturas por pagar (líneas primero)
DELETE FROM porpagar_facturas;         -- líneas de los listados
DELETE FROM porpagar_listados;         -- listados del pago semanal

-- Correos: liberar lo procesado para poder volver a buscarlo e importarlo
DELETE FROM correo_bandeja;            -- bandeja de revisión del correo
DELETE FROM correo_procesados;         -- marcas de correos ya procesados

-- Reiniciar los consecutivos a 1
ALTER TABLE importacion_items  AUTO_INCREMENT = 1;
ALTER TABLE importaciones      AUTO_INCREMENT = 1;
ALTER TABLE facturas_xml       AUTO_INCREMENT = 1;
ALTER TABLE porpagar_facturas  AUTO_INCREMENT = 1;
ALTER TABLE porpagar_listados  AUTO_INCREMENT = 1;
ALTER TABLE correo_bandeja     AUTO_INCREMENT = 1;
ALTER TABLE correo_procesados  AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;
