-- Migración: la unicidad de las facturas del ERP es por empresa
--
-- migration_sociedades_alcance.sql selló cada fila con su sociedad y acotó las
-- consultas, pero dejó dos unicidades escritas cuando había una sola empresa:
--
--   * facturas_erp.uk_clave (clave)
--       `clave` es proveedor|documento|fecha_emision. Identifica una factura
--       DENTRO de una empresa, no en toda la base. importar() ya clasifica
--       nuevas y cambios acotado a la sociedad de la carga, así que una
--       factura de la empresa B que exista igual en la A entra por la rama
--       "nueva" y el INSERT choca contra el índice: la transacción se cae y
--       la carga entera se pierde. Es un bloqueo duro para la segunda empresa.
--
--   * facturas_erp_descartes.uk_firma (firma)
--       La firma es tipo+proveedor+documento. Descartar una incidencia en una
--       empresa silenciaba la misma incidencia en la otra, y las empresas del
--       grupo le compran a los mismos proveedores.
--
-- El modelo hace este mismo ajuste solo al arrancar (FacturaErp::
-- acotarUnicosPorSociedad), pero sobre la base viva conviene aplicarlo aquí,
-- con la aplicación parada y viendo el resultado.

-- ── Antes: comprobar que no hay nada que estorbe ──
-- Estas dos consultas deben devolver 0 filas. Si devuelven algo, hay filas sin
-- sociedad o duplicados que resolver a mano ANTES de tocar los índices.
SELECT 'facturas_erp sin sociedad' AS revision, COUNT(*) AS filas
  FROM `facturas_erp` WHERE `sociedad_id` IS NULL;

SELECT 'descartes sin sociedad' AS revision, COUNT(*) AS filas
  FROM `facturas_erp_descartes` WHERE `sociedad_id` IS NULL;

-- ── Los descartes necesitan la columna; nunca la tuvieron ──
ALTER TABLE `facturas_erp_descartes`
    ADD COLUMN `sociedad_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Empresa en la que se descartó la incidencia' AFTER `id`;

-- Lo descartado hasta hoy se decidió con una sola empresa en uso: le
-- corresponde a ella. Se toma la de por omisión para no fijar un id a mano.
SET @sociedad_base := (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1);
SET @sociedad_base := COALESCE(@sociedad_base, (SELECT MIN(id) FROM `sociedades`));

UPDATE `facturas_erp_descartes` SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;

-- ── Las unicidades, ahora por empresa ──
ALTER TABLE `facturas_erp`
    DROP INDEX `uk_clave`,
    ADD UNIQUE KEY `uk_clave` (`sociedad_id`, `clave`);

ALTER TABLE `facturas_erp_descartes`
    DROP INDEX `uk_firma`,
    ADD UNIQUE KEY `uk_firma` (`sociedad_id`, `firma`);

-- ── La campana de avisos ──
-- Un aviso puede ser de una empresa (el recordatorio de un documento) o del
-- grupo (que el índice de un buzón se atrasó, que un código del ERP apunta a
-- dos proveedores). Los del grupo llevan 0 —y no NULL— porque la unicidad es
-- la que junta el mismo hecho repetido en un solo renglón, y MySQL considera
-- distintos dos NULL: con NULL, cada repetición habría abierto fila nueva.
-- Lo avisado hasta hoy se decidió con una sola empresa: queda como del grupo.
ALTER TABLE `notificaciones`
    ADD COLUMN `sociedad_id` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0 = aviso del grupo' AFTER `id`,
    ADD KEY `idx_sociedad` (`sociedad_id`, `estado`);

ALTER TABLE `notificaciones`
    DROP INDEX `uk_firma`,
    ADD UNIQUE KEY `uk_firma` (`sociedad_id`, `firma`);

-- ── Devoluciones ──
-- El PDF del reporte lo imprime el ERP de cada empresa. Con la unicidad sobre
-- el hash solo, el reporte que sube la segunda choca contra el índice.
ALTER TABLE `devoluciones`
    DROP INDEX `uq_dev_hash`,
    ADD UNIQUE KEY `uq_dev_hash` (`sociedad_id`, `hash_pdf`);

-- ── Bandeja del correo ──
-- Un buzón puede servir a varias empresas (correo_cuenta_sociedades). Sin la
-- empresa en la unicidad, la primera que revisara el buzón compartido se
-- quedaba con la fila del mensaje y la segunda no lo veía llegar nunca.
ALTER TABLE `correo_bandeja`
    DROP INDEX `uk_uid_xml`,
    ADD UNIQUE KEY `uk_uid_xml` (`sociedad_id`, `uid_correo`, `hash_xml`);

-- ── "Este correo ya lo trabajé" ──
-- Es una afirmación de cada empresa, no del buzón. Con la marca compartida, la
-- primera que revisara un buzón compartido se llevaba los mensajes por delante
-- y la segunda no los volvía a ver, aunque sus facturas estuvieran ahí dentro.
-- Las marcas que ya están son de la empresa por omisión: es la única que
-- existía cuando se pusieron, y dejarlas en 0 le devolvería catorce mil
-- correos a la cola.
ALTER TABLE `correo_procesados`
    ADD COLUMN `sociedad_id` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'empresa que lo trabajó' FIRST;

UPDATE `correo_procesados` SET `sociedad_id` = @sociedad_base;

ALTER TABLE `correo_procesados`
    DROP PRIMARY KEY, ADD PRIMARY KEY (`sociedad_id`, `clave`);

-- ── La ficha de cada usuario: cédula en vez de correo ──
-- El correo no servía para nada: no se manda ningún aviso desde la aplicación
-- y nadie entra con él. La cédula sí identifica a la persona. El dato viejo NO
-- se traslada —un correo no es una cédula—: las fichas quedan sin cédula hasta
-- que alguien las edite, y el formulario la pide.
ALTER TABLE `usuarios`
    ADD COLUMN `cedula` VARCHAR(30) NOT NULL DEFAULT '' AFTER `username`,
    ADD KEY `idx_usuarios_cedula` (`cedula`);

ALTER TABLE `usuarios` DROP COLUMN `email`;
