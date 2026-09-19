-- =========================================================================
-- 008_correo_lote_tomado_por.sql — Quién se llevó cada correo de un lote
-- =========================================================================
--
-- El modo Descargas reparte los correos de un lote en tandas. Quien procesa
-- una tanda marcaba los correos como 'procesando' y acto seguido los volvía a
-- leer con "WHERE id IN (…)", sin mirar si el UPDATE le había tocado a él.
--
-- El candado que evita dos motores a la vez es un archivo (storage/correo/
-- lotes.lock): ordena a los procesos de UNA computadora, no a los de la
-- oficina. Entre máquinas solo ordena la base. Así que si dos computadoras
-- —o una computadora y la tarea programada de otra— pedían tanda en el mismo
-- instante, el UPDATE se lo llevaba una sola… y la otra releía esos mismos
-- correos igual y los bajaba e importaba por segunda vez.
--
-- Con esta columna cada proceso firma lo que tomó y relee solo lo suyo.
--
-- La aplicación intenta agregarla sola al abrir el módulo; esta migración es
-- para los servidores donde el usuario web no tiene permiso de ALTER.
--
-- Es segura de correr más de una vez.

ALTER TABLE correo_lote_items
    ADD COLUMN IF NOT EXISTS tomado_por VARCHAR(40) NULL AFTER iniciado_en;
