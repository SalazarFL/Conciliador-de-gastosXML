-- El seguimiento se alimenta de las facturas, no del pago semanal.
--
-- Cuando se hizo la cola, el pago semanal tenía líneas propias y el renglón
-- venía de ahí: de ahí el valor 'pago_semanal'. Desde que la línea del pago ES
-- la factura del ERP, el pago solo marca cuáles se pagan esta semana y no
-- alimenta ningún documento. El renglón siempre fue —y ahora se llama— una
-- factura.
--
-- El `referencia_id` no cambia: ya era el id de `facturas_erp`, así que la
-- gestión y la bitácora ya registradas siguen apuntando al mismo documento.
--
-- Tres pasos: el ENUM tiene que admitir los dos nombres antes de poder
-- reescribir las filas, y solo después se retira el viejo. Esto también lo
-- hace el modelo Seguimiento al arrancar; la migración sirve para aplicarlo
-- explícitamente o donde no haya permisos DDL en runtime.

ALTER TABLE `seguimiento`
    MODIFY `origen` ENUM('nota_credito', 'pago_semanal', 'factura') NOT NULL;

UPDATE `seguimiento` SET `origen` = 'factura' WHERE `origen` = 'pago_semanal';

ALTER TABLE `seguimiento`
    MODIFY `origen` ENUM('nota_credito', 'factura') NOT NULL;
