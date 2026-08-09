-- Migración: la sociedad seleccionada deja de ser global
--
-- `sociedades.activa` era una sola marca para todo el sistema: cambiar de
-- empresa hacía un UPDATE que apagaba la anterior y encendía la nueva. Con un
-- usuario funciona; con dos trabajando a la vez, el que cambiaba movía también
-- la empresa del otro, sin aviso. Y como cada documento ahora se sella con la
-- sociedad del momento, eso deja registros mal etiquetados que hay que
-- corregir a mano después.
--
-- La selección pasa a resolverse en tres escalones:
--   1. $_SESSION['sociedad_id']  — la de la sesión en curso (cada usuario la suya)
--   2. usuarios.sociedad_id      — su preferencia guardada, para el próximo ingreso
--   3. sociedades.activa         — el valor por omisión del sistema: usuarios que
--                                  nunca han elegido, y procesos sin sesión (cli/)
--
-- `activa` se conserva por eso último; lo que deja de hacer es cambiar cada vez
-- que alguien salta de empresa.

ALTER TABLE `usuarios`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Empresa con la que trabaja este usuario (su selección, no global)'
        AFTER `is_admin`,
    ADD INDEX `idx_usuarios_sociedad` (`sociedad_id`);

-- Todos arrancan en la que estaba activa hasta hoy: nadie nota el cambio.
UPDATE `usuarios`
   SET `sociedad_id` = (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1)
 WHERE `sociedad_id` IS NULL;
