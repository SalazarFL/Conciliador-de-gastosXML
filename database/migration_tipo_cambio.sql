-- El tipo de cambio de cada comprobante electrónico.
-- Es segura de correr más de una vez (MariaDB: IF NOT EXISTS).
--
-- Una factura en dólares trae en el XML su moneda (USD) y el tipo de cambio
-- con el que se emitió (<CodigoTipoMoneda><TipoCambio>). La factura del
-- sistema está en colones, así que para compararlas el total del XML se
-- multiplica por ese número. Sin él, cada factura en dólares quedaba "con
-- diferencia" por el monto entero: el 28/09/2026 eran 23 de las 24 que
-- estaban enganchadas a un XML en dólares.
--
-- NULL en colones, o cuando el archivo no se pudo leer. Los comprobantes
-- que ya estaban en la base se completan con:
--
--     php cli/tipo_cambio_xml.php --aplicar
--
-- El modelo FacturaErp también la agrega solo si falta.

ALTER TABLE `facturas_xml`
    ADD COLUMN IF NOT EXISTS `tipo_cambio` DECIMAL(18,5) NULL DEFAULT NULL
        COMMENT 'TipoCambio del XML; NULL en colones o si no se leyó' AFTER `moneda`;
