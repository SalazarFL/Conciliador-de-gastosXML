-- Normaliza los numeros cortos de XML existentes a exactamente 8 digitos.
-- El consecutivo_completo conserva el consecutivo original de 20 digitos.

START TRANSACTION;

UPDATE facturas_xml
SET numero_factura_asistente = LPAD(
    RIGHT(
        COALESCE(NULLIF(TRIM(LEADING '0' FROM numero_factura_asistente), ''), '0'),
        8
    ),
    8,
    '0'
)
WHERE numero_factura_asistente REGEXP '^[0-9]+$';

ALTER TABLE facturas_xml
    MODIFY COLUMN numero_factura_asistente VARCHAR(8) NOT NULL
    COMMENT 'Numero XML corto normalizado a 8 digitos';

COMMIT;
