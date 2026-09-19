-- =========================================================================
-- 007_pago_unico.sql — El pago y su listado, una sola ficha
-- =========================================================================
--
-- Un pago estaba guardado en dos tablas: `pagos` tenía su nombre, su fecha y
-- su carpeta; `porpagar_listados` tenía el archivo que se le cargó y cuántas
-- facturas trae. Siempre iban de a uno: ningún pago tuvo jamás dos listados,
-- ni hubo un listado sin pago.
--
-- Se notaba en los nombres. El listado se bautizaba pegándole "Pago " al
-- nombre del pago, así que en la base había filas llamadas "Pago Pago Semana
-- 200826": el mismo nombre sumado a sí mismo.
--
-- Y se pagaba en las consultas: cada factura del ERP llevaba DOS apuntadores
-- —`pago_id` y `porpagar_listado_id`— que decían lo mismo, se escribían juntos
-- y se borraban juntos. Dos formas de decir lo mismo son dos formas de que se
-- contradigan.
--
-- Esta migración deja una sola ficha:
--
--   1. `pagos` recibe lo que aportaba el listado: de qué archivo salió,
--      cuántas facturas tiene y cuándo se subió.
--   2. Las facturas del ERP pasan a apuntar al pago y nada más.
--   3. `porpagar_listados` desaparece.
--
-- Lo que NO se trae: `estado`, `cerrado_en` y `cerrado_por`. Son de cuando un
-- pago se podía "cerrar"; nadie las lee desde que la base es compartida (ver
-- el comentario de PorPagarController sobre por qué se quitó el cierre).
--
-- ── Antes de correr esto ──
--
-- `porpagar_facturas` es la tabla de líneas de la época anterior, y tiene una
-- clave foránea hacia `porpagar_listados`. Si todavía tiene filas, esta
-- migración NO borra la tabla padre: primero hay que terminar aquella
-- migración con `php cli/migrar_pago_erp.php --aplicar`. El script lo avisa
-- al final.
--
-- Los modelos aplican esto solos la primera vez que alguien entra. Este
-- archivo es para los servidores donde el usuario de la web NO tiene permisos
-- de DDL: ahí hay que correrlo a mano una vez.
--
--   mysql -h SERVIDOR -P 3307 -u root -p bd_xmlconcilia < 007_pago_unico.sql
--
-- Es idempotente: se puede correr dos veces sin romper nada.
-- =========================================================================


-- ── Lo que el pago hereda del listado ────────────────────────────────────
ALTER TABLE `pagos`
    ADD COLUMN IF NOT EXISTS `archivo_origen` VARCHAR(255) NULL DEFAULT NULL AFTER `carpeta_pago`;
ALTER TABLE `pagos`
    ADD COLUMN IF NOT EXISTS `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `archivo_origen`;
ALTER TABLE `pagos`
    ADD COLUMN IF NOT EXISTS `archivo_subido_en` DATETIME NULL DEFAULT NULL AFTER `total_lineas`;

-- Columna de paso: guarda de qué listado vino cada pago recién creado, para
-- poder engancharlos. Se borra al final de esta misma migración.
ALTER TABLE `pagos`
    ADD COLUMN IF NOT EXISTS `migracion_listado_id` INT UNSIGNED NULL DEFAULT NULL;


-- No hay IF alrededor de un statement en SQL, y correr esto dos veces tiene
-- que ser inofensivo. Se pregunta el estado una vez y cada sentencia se arma o
-- se convierte en un no-op.
SET @hay_listados = (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'porpagar_listados');
SET @hay_columna  = (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_erp'
                       AND COLUMN_NAME = 'porpagar_listado_id');
SET @hay_viejas   = (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'porpagar_facturas');


-- ── 1. Un pago para cada listado que no tenía ────────────────────────────
-- No debería haber ninguno —la pantalla siempre pidió el pago antes de
-- cargar—, pero si una base vieja tiene alguno, perderlo sería perder las
-- facturas que le pertenecen.
SET @sql = IF(@hay_listados = 1,
  'INSERT INTO pagos (sociedad_id, nombre, fecha_pago, archivo_origen,
                      total_lineas, archivo_subido_en, creado_en, migracion_listado_id)
   SELECT l.sociedad_id, LEFT(l.nombre, 100), DATE(l.fecha_subida), l.archivo_origen,
          l.total_lineas, l.fecha_subida, l.fecha_subida, l.id
     FROM porpagar_listados l
    WHERE l.pago_id IS NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@hay_listados = 1,
  'UPDATE porpagar_listados l
     JOIN pagos p ON p.migracion_listado_id = l.id
      SET l.pago_id = p.id
    WHERE l.pago_id IS NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2. El pago se queda con los datos de su listado ──────────────────────
SET @sql = IF(@hay_listados = 1,
  'UPDATE pagos p
     JOIN porpagar_listados l ON l.pago_id = p.id
      SET p.archivo_origen    = COALESCE(p.archivo_origen, l.archivo_origen),
          p.archivo_subido_en = COALESCE(p.archivo_subido_en, l.fecha_subida),
          p.sociedad_id       = COALESCE(p.sociedad_id, l.sociedad_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 3. Las facturas del ERP apuntan al pago ──────────────────────────────
-- El listado manda sobre lo que dijera `pago_id`: es el que tiene el vínculo
-- con las facturas. En los datos medidos los dos coincidían en las 944 filas,
-- pero si alguno discrepara, el bueno es este.
SET @sql = IF(@hay_listados = 1 AND @hay_columna = 1,
  'UPDATE facturas_erp e
     JOIN porpagar_listados l ON l.id = e.porpagar_listado_id
      SET e.pago_id = l.pago_id
    WHERE e.porpagar_listado_id IS NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 4. Se va el apuntador que sobraba ────────────────────────────────────
ALTER TABLE `facturas_erp` DROP INDEX IF EXISTS `idx_porpagar_listado`;
ALTER TABLE `facturas_erp` DROP COLUMN IF EXISTS `porpagar_listado_id`;


-- ── 5. Y la tabla, si nadie más la necesita ──────────────────────────────
-- `porpagar_facturas` —las líneas de la época anterior— la referencia con una
-- clave foránea. Si está vacía, aquella migración terminó y la referencia solo
-- estorba: se suelta. Si tiene filas, no se toca nada y el aviso del final lo
-- dice.
SET @lineas_viejas = 0;
SET @sql = IF(@hay_viejas = 1,
  'SELECT COUNT(*) INTO @lineas_viejas FROM porpagar_facturas',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@hay_viejas = 1 AND @lineas_viejas = 0,
  'ALTER TABLE porpagar_facturas DROP FOREIGN KEY IF EXISTS fk_porpagar_listado',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@lineas_viejas = 0, 'DROP TABLE IF EXISTS porpagar_listados', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `pagos` DROP COLUMN IF EXISTS `migracion_listado_id`;


-- ── 6. El contador, recontado desde la verdad ────────────────────────────
-- `total_lineas` es cuántas facturas tiene el pago, y eso se cuenta; copiarlo
-- del listado arrastraría cualquier desajuste que aquel tuviera.
UPDATE `pagos` p
   SET p.`total_lineas` = (SELECT COUNT(*) FROM `facturas_erp` e WHERE e.`pago_id` = p.`id`);


-- ── Cómo quedó ───────────────────────────────────────────────────────────
SELECT IF(@lineas_viejas = 0,
          'OK: el pago y su listado son una sola ficha.',
          CONCAT('A MEDIAS: porpagar_facturas todavia tiene ', @lineas_viejas,
                 ' filas. Termina esa migracion con "php cli/migrar_pago_erp.php --aplicar" ',
                 'y volve a correr esta.')
       ) AS resultado;
