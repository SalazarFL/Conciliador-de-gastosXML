-- =========================================
-- Descarte de incidencias del módulo Correo
--
-- Mismo mecanismo que ya existe en Facturas ERP: una incidencia que alguien
-- revisó y decidió que no da más trabajo se marca descartada, y la marca
-- sobrevive a que el correo se vuelva a procesar. Sin eso la lista no baja
-- nunca —hoy son 836 filas— y una lista que no baja deja de leerse.
--
-- El descarte NO borra nada: la incidencia sigue ahí y se puede restaurar.
--
-- `CorreoLote` aplica estos mismos cambios solo si puede (ALTER en caliente).
-- Este archivo es para los servidores donde el usuario web no tiene permisos
-- de DDL.
-- =========================================

ALTER TABLE `correo_incidencias`
    ADD COLUMN IF NOT EXISTS `firma` VARCHAR(64) NOT NULL DEFAULT '' AFTER `metadata`,
    ADD COLUMN IF NOT EXISTS `descartada` TINYINT(1) NOT NULL DEFAULT 0 AFTER `firma`,
    ADD COLUMN IF NOT EXISTS `descartada_en` DATETIME NULL AFTER `descartada`,
    ADD COLUMN IF NOT EXISTS `descartada_por` INT(10) UNSIGNED NULL AFTER `descartada_en`,
    ADD COLUMN IF NOT EXISTS `motivo` VARCHAR(255) NULL AFTER `descartada_por`;

ALTER TABLE `correo_incidencias`
    ADD KEY IF NOT EXISTS `idx_firma` (`firma`),
    ADD KEY IF NOT EXISTS `idx_descartada` (`descartada`);

-- La firma identifica LA MISMA incidencia entre corridas. Se arma con el
-- correo (carpeta + uidvalidity + uid), que es lo único estable —al
-- reprocesar, el lote y el item son nuevos pero el mensaje del buzón es el
-- mismo— MÁS el texto de la incidencia.
--
-- El texto no sobra: un mismo correo trae varias facturas y genera una
-- incidencia por cada una ("La factura 2312 vino sin PDF…"). Sin él, las seis
-- de un correo comparten firma y descartar una descarta las seis. El texto se
-- deriva del mismo XML, así que es estable entre corridas.
UPDATE `correo_incidencias` x
   LEFT JOIN `correo_lote_items` li ON li.id = x.lote_item_id
   SET x.firma = SHA1(CONCAT_WS('|', x.tipo,
        COALESCE(li.carpeta, ''), COALESCE(li.uidvalidity, 0), COALESCE(li.uid, 0),
        x.mensaje))
 WHERE x.firma = '';

-- Lo descartado a propósito, para que vuelva descartado cuando el correo se
-- reprocese y la incidencia se cree de nuevo.
CREATE TABLE IF NOT EXISTS `correo_incidencias_descartes` (
    `firma`      VARCHAR(64) NOT NULL,
    `tipo`       VARCHAR(40) NOT NULL DEFAULT '',
    `asunto`     VARCHAR(255) NULL,
    `motivo`     VARCHAR(255) NULL,
    `usuario_id` INT(10) UNSIGNED NULL,
    `creado_en`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`firma`),
    KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
