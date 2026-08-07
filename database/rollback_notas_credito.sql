-- Reversión exclusiva del módulo de notas de crédito.
-- Ejecutar únicamente después de respaldar cualquier listado que deba conservarse.
-- No elimina facturas_xml, proveedores ni sociedades.

DROP TABLE IF EXISTS `notas_credito_historial`;
DROP TABLE IF EXISTS `notas_credito_verificaciones`;
DROP TABLE IF EXISTS `notas_credito_lineas`;
DROP TABLE IF EXISTS `notas_credito_listados`;
