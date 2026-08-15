-- Puente NC directa -> factura acreditada (InformacionReferencia del XML).
--
-- El verificador ya sabía emparejar por el consecutivo de la NC que da el
-- proveedor y por proveedor + monto exacto. Ahora, para las notas directas,
-- usa además la referencia que la propia nota lleva en su XML: el reporte del
-- ERP numera la línea con el consecutivo de la FACTURA corregida y el XML de
-- la nota cita esa misma factura, así que las dos puntas se tocan sin depender
-- del monto. Ese emparejamiento se anota con el método 'referencia'.
--
-- Solo amplía el ENUM: ningún valor existente cambia ni deja de ser válido.
-- El modelo aplica lo mismo al arrancar (NotaCredito::ensureTables); este
-- archivo es para instalaciones sin permisos DDL en tiempo de ejecución.
--
-- Requiere facturas_xml_referencias, que crea migration_facturas_xml_detalle.sql
-- y llena cli/backfill_xml_detalle.php. Sin esa tabla el verificador sigue
-- funcionando por los caminos de siempre.

ALTER TABLE notas_credito_lineas
    MODIFY COLUMN metodo_match
    ENUM('ninguno','numero','referencia','atributos','manual')
    NOT NULL DEFAULT 'ninguno';
