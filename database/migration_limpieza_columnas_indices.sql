-- Migración: quitar lo que no se usa —cuatro columnas y cinco índices—.
--
-- Nada de esto cambia comportamiento: todo lo que se borra aquí está muerto o
-- duplicado. Lo que se gana es peso, y dejar de mentir.
--
-- LAS CUATRO COLUMNAS
--
--   * facturas_xml.proveedor_normalizado — y ESTA es la que importa.
--     Su propio comentario dice "Redundante de proveedores.
--     razon_social_normalizada". Lo único que la escribió fue el relleno de
--     `002_campos_normalizados.sql`, UNA VEZ. La aplicación no la lee ni la
--     escribe: cero referencias en todo el repositorio. O sea que las filas
--     nuevas entran con NULL y las viejas quedaron congeladas con el nombre
--     que el proveedor tenía aquel día —si a un proveedor se le corrigió la
--     razón social después, aquí sigue la de antes—. Encima lleva índice
--     (`idx_numero_proveedor_norm`), así que hay un índice construido sobre
--     datos NULL o caducos. Peor que no tener nada: parece que sirve.
--
--   * facturas_xml.xml_contenido — MEDIUMTEXT con el XML entero.
--     En la base de trabajo y en la de certificación YA NO ESTÁ:
--     `migration_rutas_relativas.sql`, que la elimina en su primera línea, se
--     aplicó entera en su momento. El DROP se deja aquí igualmente, con IF
--     EXISTS, para las copias que se hayan quedado atrás —una restauración de
--     un respaldo viejo, por ejemplo—. Donde ya no está, no hace nada.
--
--   * importaciones.importado_por e importaciones.duracion_segundos
--     Cero referencias. Nunca se escribieron: están en NULL en todas las
--     filas desde el primer día. `importado_por` hasta lo dice en su propio
--     comentario: "Usuario/operador (futuro)". El futuro no llegó.
--
-- LOS CINCO ÍNDICES
--
-- Cada uno es el PREFIJO EXACTO de otro índice de la misma tabla, y un índice
-- compuesto sirve para buscar por su primera columna igual que uno simple:
-- InnoDB usa `(a, b)` para responder `WHERE a = ?`. Así que estos cinco no
-- responden ninguna consulta que el otro no responda ya, y cada uno cuesta
-- espacio y una escritura más en cada INSERT y cada UPDATE.
--
--     correo_bandeja.idx_correo_bandeja_sociedad  ⊂ uk_uid_xml
--     facturas_erp.idx_facturas_erp_sociedad      ⊂ uk_clave
--     facturas_xml.idx_facturas_xml_sociedad      ⊂ idx_facturas_xml_sociedad_tipo
--     facturas_xml.idx_fecha_emision              ⊂ idx_fecha_proveedor
--     pagos.idx_pagos_sociedad                    ⊂ idx_pagos_fecha
--
-- UNO QUE NO SE TOCA, Y POR QUÉ
--
-- `facturas_xml.idx_numero_factura` también figuraba como redundante, porque
-- `idx_numero_proveedor_norm (numero_factura_asistente, proveedor_normalizado)`
-- lo contiene. Pero ese índice desaparece con la columna muerta del paso 1, y
-- entonces `idx_numero_factura` pasa a ser el ÚNICO índice por número de
-- factura, que es una de las búsquedas más usadas. Se queda.
--
-- SOBRE LAS CLAVES FORÁNEAS
--
-- Tres de los índices que se quitan están sobre columnas con clave foránea
-- (`sociedad_id`). InnoDB exige un índice en la columna hija, pero le vale
-- cualquiera que la lleve de PRIMERA, y en los tres casos el índice que queda
-- la lleva. Si alguno fallara con "needed in a foreign key constraint", es que
-- ese razonamiento no aplica ahí: dejarlo y avisar.
--
-- ESTA MIGRACIÓN TARDA
--
-- Quitar una columna reescribe la tabla entera, y `facturas_xml` es la más
-- grande del sistema. Con la aplicación parada y con el respaldo del día
-- hecho. (Donde `xml_contenido` todavía exista, además, el espacio que libera
-- es considerable: es el XML completo de cada comprobante.)


-- =====================================================================
-- 1. ANTES: qué se va a perder exactamente
-- =====================================================================
-- Lo que dice la primera consulta es el argumento entero: si
-- `proveedor_normalizado` sirviera para algo, "al día" sería el total.

-- Las tres consultas se arman solas: una vez aplicada la migración las
-- columnas ya no existen, y una consulta que las nombre haría fallar la
-- segunda pasada. Así el archivo se puede volver a correr entero.

SET @hay_norm := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_xml'
                     AND COLUMN_NAME = 'proveedor_normalizado');
SET @q := IF(@hay_norm > 0,
    'SELECT ''proveedor_normalizado al día'' AS estado, COUNT(*) AS filas
       FROM `facturas_xml` f JOIN `proveedores` p ON p.id = f.proveedor_id
      WHERE f.`proveedor_normalizado` <=> p.`razon_social_normalizada`
     UNION ALL
     SELECT ''proveedor_normalizado NULO o CADUCO'', COUNT(*)
       FROM `facturas_xml` f JOIN `proveedores` p ON p.id = f.proveedor_id
      WHERE NOT (f.`proveedor_normalizado` <=> p.`razon_social_normalizada`)',
    'SELECT ''proveedor_normalizado ya no existe en esta copia'' AS estado, 0 AS filas');
PREPARE ver FROM @q; EXECUTE ver; DEALLOCATE PREPARE ver;

SET @hay_imp := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'importaciones'
                    AND COLUMN_NAME = 'importado_por');
SET @q := IF(@hay_imp > 0,
    'SELECT ''importaciones con importado_por escrito'' AS estado, COUNT(*) AS filas
       FROM `importaciones` WHERE `importado_por` IS NOT NULL
     UNION ALL
     SELECT ''importaciones con duracion_segundos escrita'', COUNT(*)
       FROM `importaciones` WHERE `duracion_segundos` IS NOT NULL',
    'SELECT ''importado_por y duracion_segundos ya no existen'' AS estado, 0 AS filas');
PREPARE ver FROM @q; EXECUTE ver; DEALLOCATE PREPARE ver;

SET @hay_xml := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_xml'
                    AND COLUMN_NAME = 'xml_contenido');
SET @q := IF(@hay_xml > 0,
    'SELECT ROUND(SUM(LENGTH(`xml_contenido`))/1024/1024, 1) AS xml_contenido_mb,
            COUNT(`xml_contenido`) AS filas_con_xml FROM `facturas_xml`',
    'SELECT ''xml_contenido ya no existe en esta copia'' AS xml_contenido_mb, 0 AS filas_con_xml');
PREPARE ver FROM @q; EXECUTE ver; DEALLOCATE PREPARE ver;


-- =====================================================================
-- 2. Las columnas muertas
-- =====================================================================
-- `idx_numero_proveedor_norm` se va solo con la columna: no hace falta
-- borrarlo aparte, y de hecho no se puede hacerlo después.

ALTER TABLE `facturas_xml` DROP COLUMN IF EXISTS `proveedor_normalizado`;
ALTER TABLE `facturas_xml` DROP COLUMN IF EXISTS `xml_contenido`;

ALTER TABLE `importaciones` DROP COLUMN IF EXISTS `importado_por`;
ALTER TABLE `importaciones` DROP COLUMN IF EXISTS `duracion_segundos`;


-- =====================================================================
-- 3. Los índices duplicados
-- =====================================================================

ALTER TABLE `correo_bandeja` DROP INDEX IF EXISTS `idx_correo_bandeja_sociedad`;
ALTER TABLE `facturas_erp`   DROP INDEX IF EXISTS `idx_facturas_erp_sociedad`;
ALTER TABLE `facturas_xml`   DROP INDEX IF EXISTS `idx_facturas_xml_sociedad`;
ALTER TABLE `facturas_xml`   DROP INDEX IF EXISTS `idx_fecha_emision`;
ALTER TABLE `pagos`          DROP INDEX IF EXISTS `idx_pagos_sociedad`;


-- =====================================================================
-- 4. DESPUÉS: comprobar
-- =====================================================================
-- La primera debe devolver 0 filas (ninguna de las cuatro columnas existe).
-- La segunda debe incluir `idx_numero_factura`, que se conserva a propósito.

SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'facturas_xml' AND COLUMN_NAME IN ('proveedor_normalizado', 'xml_contenido'))
     OR (TABLE_NAME = 'importaciones' AND COLUMN_NAME IN ('importado_por', 'duracion_segundos')));

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columnas
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_xml'
 GROUP BY INDEX_NAME ORDER BY INDEX_NAME;
