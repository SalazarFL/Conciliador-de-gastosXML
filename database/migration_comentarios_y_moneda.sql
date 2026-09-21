-- Migración: comentarios legibles, y la moneda por omisión de este país.
--
-- LO QUE ARREGLA
--
-- Los COMMENT de la base están corrompidos en tres codificaciones distintas, y
-- eso cuenta una historia: las migraciones se fueron aplicando con `SET NAMES`
-- diferentes en distintas épocas.
--
--     Raz??n social          <- pérdida irreversible (se perdió el byte)
--     C├®dula del receptor    <- utf8 leído como cp850 (la consola de Windows)
--     devoluciÃ³n             <- utf8 codificado dos veces
--
-- Son solo metadatos: ninguna fila de datos está afectada —se comprobó— y
-- nada del sistema los lee. Pero es lo primero que ve quien abre la base con
-- un cliente SQL, y el `??` es pérdida real: esos textos hay que reescribirlos
-- a mano porque el byte original ya no está en ninguna parte.
--
-- CÓMO SE HIZO, QUE IMPORTA
--
-- Cambiar el comentario de una columna en MySQL/MariaDB obliga a repetir su
-- definición ENTERA (`MODIFY COLUMN`), y una errata ahí cambia el tipo de la
-- columna en silencio. Por eso estas veinticuatro sentencias no se
-- escribieron a mano: se generaron leyendo la línea exacta de cada columna de
-- `SHOW CREATE TABLE` y sustituyendo únicamente el texto entre comillas del
-- COMMENT. El tipo, el NULL, el DEFAULT y el resto van tal cual estaban.
--
-- Los comentarios de TABLA no tienen ese problema: `ALTER TABLE ... COMMENT=`
-- no toca nada más.
--
-- LA MONEDA
--
-- `facturas_xml.moneda` tiene `DEFAULT 'MXN'`, de la plantilla mexicana con la
-- que nació el proyecto —el comentario de la tabla todavía decía "CFDI"—. En
-- la práctica no se usa nunca: `Factura::` guarda `$data['moneda'] ?? 'CRC'`,
-- así que siempre llega un valor. Se cambia igualmente, porque un DEFAULT que
-- miente es una trampa para el día que alguien inserte sin pasar por el
-- modelo.
--
-- No se toca `proveedores.rfc`: cambiarle el nombre a `cedula` obligaría a
-- tocar los 55 sitios que la nombran, y eso es un cambio de código, no de
-- base. Lo que sí se hace es decir en su comentario qué guarda de verdad.
--
-- Es repetible: aplicar dos veces deja exactamente lo mismo.


-- =====================================================================
-- 1. Los comentarios de columna
-- =====================================================================

ALTER TABLE `facturas_xml` MODIFY COLUMN `fecha_emision` date NOT NULL COMMENT 'Fecha de emisión de la factura';
ALTER TABLE `facturas_xml` MODIFY COLUMN `moneda` varchar(3) NOT NULL DEFAULT 'MXN' COMMENT 'Código de moneda ISO 4217';
ALTER TABLE `facturas_xml` MODIFY COLUMN `clave` varchar(60) DEFAULT NULL COMMENT 'Clave Hacienda (50 dígitos)';
ALTER TABLE `facturas_xml` MODIFY COLUMN `receptor_id` varchar(30) DEFAULT NULL COMMENT 'Cédula del receptor del XML';
ALTER TABLE `facturas_xml` MODIFY COLUMN `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Sociedad dueña del documento (receptor del XML)';
ALTER TABLE `facturas_xml` MODIFY COLUMN `detalle_extraido_en` datetime DEFAULT NULL COMMENT 'Última extracción de referencias/líneas; NULL = pendiente';
ALTER TABLE `facturas_xml_lineas` MODIFY COLUMN `codigo_comercial` varchar(30) DEFAULT NULL COMMENT 'Código del proveedor (a veces EAN)';
ALTER TABLE `facturas_xml_lineas` MODIFY COLUMN `detalle` varchar(255) DEFAULT NULL COMMENT 'Descripción del proveedor, no la interna';
ALTER TABLE `facturas_xml_referencias` MODIFY COLUMN `clave_ref` varchar(50) DEFAULT NULL COMMENT 'Solo si numero_ref es una clave de 50 dígitos';
ALTER TABLE `facturas_xml_referencias` MODIFY COLUMN `consecutivo_ref` varchar(20) DEFAULT NULL COMMENT 'Consecutivo (20 díg.) derivado de la clave';
ALTER TABLE `importaciones` MODIFY COLUMN `metadata` text DEFAULT NULL COMMENT 'JSON con información adicional';
ALTER TABLE `importaciones` MODIFY COLUMN `tipo` enum('xml','gastos') NOT NULL COMMENT 'Tipo de importación';
ALTER TABLE `proveedores` MODIFY COLUMN `razon_social` varchar(255) NOT NULL COMMENT 'Razón social del emisor';
ALTER TABLE `proveedores` MODIFY COLUMN `razon_social_normalizada` varchar(255) NOT NULL COMMENT 'Razón social sin acentos/mayúsculas para matching';
ALTER TABLE `proveedores` MODIFY COLUMN `rfc` varchar(13) DEFAULT NULL COMMENT 'Cédula del proveedor (la columna se llama rfc por herencia de México)';
ALTER TABLE `proveedor_codigo_erp` MODIFY COLUMN `nombre_erp` varchar(255) NOT NULL DEFAULT '' COMMENT 'Cómo escribe el ERP a este proveedor';
ALTER TABLE `proveedor_codigo_erp` MODIFY COLUMN `proveedor_codigo` varchar(30) NOT NULL COMMENT 'Código del proveedor en el ERP (140001227)';
ALTER TABLE `notificaciones` MODIFY COLUMN `datos` text DEFAULT NULL COMMENT 'JSON con lo que necesite la pantalla de revisión';
ALTER TABLE `usuarios` MODIFY COLUMN `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Empresa con la que trabaja este usuario (su selección, no global)';
ALTER TABLE `devoluciones` MODIFY COLUMN `numero` varchar(20) NOT NULL COMMENT 'No. de boleta o de devolución en el ERP';
ALTER TABLE `devoluciones` MODIFY COLUMN `numero_factura` varchar(20) DEFAULT NULL COMMENT 'Consecutivo 20 díg. de la factura (solo boleta)';
ALTER TABLE `devoluciones` MODIFY COLUMN `total` decimal(18,2) DEFAULT NULL COMMENT 'Total con IVA (formato devolución)';
ALTER TABLE `devolucion_lineas` MODIFY COLUMN `costo` decimal(18,5) NOT NULL DEFAULT 0.00000 COMMENT 'Costo neto (boleta) o unitario (devolución)';
ALTER TABLE `devolucion_lineas` MODIFY COLUMN `seccion` varchar(20) DEFAULT NULL COMMENT 'FACTURA|NOTA CANTIDAD|FALTANTE|NOTA COSTO; NULL en formato devolución';

-- =====================================================================
-- 2. Los comentarios de tabla
-- =====================================================================

ALTER TABLE `facturas_xml`
    COMMENT = 'Comprobantes electrónicos (XML de Hacienda): facturas, notas de crédito y de débito';
ALTER TABLE `proveedores`
    COMMENT = 'Catálogo de proveedores normalizados';
ALTER TABLE `proveedor_codigo_erp`
    COMMENT = 'Código del ERP -> cédula del emisor';
ALTER TABLE `importaciones`
    COMMENT = 'Registro de importaciones masivas de XML';


-- =====================================================================
-- 3. La moneda por omisión
-- =====================================================================
-- Se repite la definición completa, como en el paso 1, y por el mismo motivo.

ALTER TABLE `facturas_xml`
    MODIFY COLUMN `moneda` VARCHAR(3) NOT NULL DEFAULT 'CRC'
        COMMENT 'Código de moneda ISO 4217';


-- =====================================================================
-- 4. DESPUÉS: comprobar
-- =====================================================================
-- La primera debe devolver 0 filas: ya no queda ningún comentario con las
-- marcas de las tres corrupciones.

-- El `BINARY` no es un adorno: el cotejamiento por omisión ignora los
-- acentos, y sin él `LIKE '%Ã%'` casa con CUALQUIER comentario que lleve una
-- «a». La comprobación devolvía media base y no servía para nada. Se usa
-- `BINARY` y no `COLLATE ..._bin` porque `COLUMN_COMMENT` es utf8mb3 en
-- MariaDB y un cotejamiento de utf8mb4 ahí da error 1253.

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_COMMENT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_COMMENT <> ''
   AND (BINARY COLUMN_COMMENT LIKE BINARY '%??%'
     OR BINARY COLUMN_COMMENT LIKE BINARY '%Ã%'
     OR BINARY COLUMN_COMMENT LIKE BINARY '%├%');

SELECT COLUMN_DEFAULT AS moneda_por_omision FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_xml'
   AND COLUMN_NAME = 'moneda';
