-- Migración: dos empresas no pueden compartir cédula, se escriba como se escriba.
--
-- EL PROBLEMA
--
-- `sociedades` no tiene ninguna unicidad sobre `cedula`: solo la clave
-- primaria sobre `id`. Y tampoco la comprueba el código: `SociedadesController`
-- solo verifica que la cédula traiga ALGÚN dígito
-- (`preg_replace('/\D+/', '', $cedula) === ''`) y guarda el texto tal cual se
-- escribió. Así que hoy se puede crear dos veces la misma empresa, y ni
-- siquiera hace falta escribirla igual:
--
--     3-101-123456
--     3101123456
--     3 101 123456
--
-- son tres filas distintas con la misma cédula. Ninguna pantalla lo avisa.
--
-- QUÉ TAN GRAVE ES
--
-- Menos de lo que parece, y conviene decirlo: el importador NO busca la
-- empresa por cédula. `XmlDocumentImporter` compara el receptor del XML contra
-- la empresa ACTIVA (y ya compara solo dígitos, así que el formato le da
-- igual), de modo que un duplicado no desvía documentos por su cuenta. Lo que
-- sí hace es permitir que las dos acepten los mismos, y que el trabajo de una
-- empresa quede repartido entre dos fichas que nadie sabe que son la misma.
--
-- CÓMO SE ARREGLA SIN TOCAR LO QUE SE VE
--
-- Se podría normalizar la columna al escribir, pero entonces la cédula
-- dejaría de verse como la gente la escribe, con sus guiones. En vez de eso,
-- una columna derivada con solo los dígitos, y la unicidad sobre ella:
--
--     cedula_norm = NULLIF(REGEXP_REPLACE(cedula, '[^0-9]', ''), '')
--
-- El valor que se teclea se guarda y se muestra igual que siempre; lo que se
-- compara son los dígitos. El NULLIF es para el caso límite: una cédula sin
-- ningún dígito queda en NULL, y una unicidad ignora los NULL, así que no
-- chocan entre sí —el controlador ya no deja llegar a ese caso, pero la base
-- no tiene por qué confiar en eso—.
--
-- SI EL PASO 1 DEVUELVE FILAS
--
-- PARARSE. Hay empresas duplicadas y el paso 2 va a fallar, con razón. No se
-- arregla borrando una: hay documentos, pagos y permisos colgando de las dos.
-- Hay que decidir cuál se queda y mover lo de la otra —`sociedades` ya tiene
-- clave foránea desde todas partes (migration_claves_sociedad.sql), así que la
-- que sobre no se va a poder borrar hasta que esté vacía, que es justo lo que
-- se quiere—.


-- =====================================================================
-- 1. ANTES: cédulas repetidas mirando solo los dígitos
-- =====================================================================
-- Debe devolver 0 filas.

SELECT REGEXP_REPLACE(`cedula`, '[^0-9]', '') AS cedula_en_digitos,
       COUNT(*) AS cuantas,
       GROUP_CONCAT(CONCAT(`id`, ': ', `nombre`, ' [', `cedula`, ']')
                    ORDER BY `id` SEPARATOR ' | ') AS empresas
  FROM `sociedades`
 WHERE REGEXP_REPLACE(`cedula`, '[^0-9]', '') <> ''
 GROUP BY cedula_en_digitos
HAVING COUNT(*) > 1;


-- =====================================================================
-- 2. La columna derivada y su unicidad
-- =====================================================================
-- Se dejan caer antes por si la migración se repite: así el estado final es
-- siempre el mismo, venga de donde venga.

ALTER TABLE `sociedades` DROP INDEX IF EXISTS `uq_sociedad_cedula`;
ALTER TABLE `sociedades` DROP COLUMN IF EXISTS `cedula_norm`;

ALTER TABLE `sociedades`
    ADD COLUMN `cedula_norm` VARCHAR(30)
        GENERATED ALWAYS AS (NULLIF(REGEXP_REPLACE(`cedula`, '[^0-9]', ''), '')) STORED
        COMMENT 'Derivada: solo los dígitos de la cédula, para que no se repita'
        AFTER `cedula`,
    ADD UNIQUE KEY `uq_sociedad_cedula` (`cedula_norm`);


-- =====================================================================
-- 3. DESPUÉS: comprobar
-- =====================================================================
-- La primera debe decir 1 (la unicidad está). La segunda muestra cómo quedó
-- cada empresa; `cedula` tiene que verse exactamente igual que antes.

SELECT COUNT(*) AS tiene_unicidad FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sociedades'
   AND INDEX_NAME = 'uq_sociedad_cedula';

SELECT id, nombre, cedula, cedula_norm, activa FROM `sociedades` ORDER BY id;
