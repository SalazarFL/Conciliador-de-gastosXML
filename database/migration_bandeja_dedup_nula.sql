-- Migración: la bandeja deja de repetir el correo que no se pudo leer.
--
-- EL PROBLEMA
--
-- `correo_bandeja` deduplica con
--
--     UNIQUE KEY uk_uid_xml (sociedad_id, uid_correo, hash_xml)
--
-- y dos de esas tres columnas admiten NULL. En SQL dos NULL no son iguales
-- —`NULL = NULL` no da cierto, da NULL—, así que una unicidad NO impide
-- repetir filas cuando alguna de sus columnas viene en NULL.
--
-- El agujero se abre justo en el peor momento: `hash_xml` es el sha256 del XML
-- y queda en NULL cuando el XML NO SE PUDO LEER. El mismo correo ilegible,
-- reprocesado cinco veces, abría cinco renglones en la bandeja —precisamente
-- el mensaje que uno menos quiere ver repetido—.
--
-- La búsqueda que debía evitarlo fallaba por el otro lado: `getPorUidHash`
-- comparaba `hash_xml = ?`, que con NULL no encuentra nada nunca. Eso ya se
-- arregló en el código (`<=>`, que compara tratando NULL como un valor más).
-- Esta migración pone la garantía en la base, que es la que vale cuando dos
-- trabajadores procesan el mismo buzón a la vez y los dos buscan antes de que
-- ninguno haya escrito.
--
-- CÓMO
--
-- Quitando los NULL de las dos columnas, que es lo que rompía la unicidad:
--
--   * `hash_xml` pasa a NOT NULL DEFAULT ''. La cadena vacía significa lo
--     mismo que significaba el NULL —"no se pudo leer el XML"— pero la
--     unicidad sí la compara. Un sha256 nunca es vacío, así que no choca con
--     ningún hash real.
--
--   * `sociedad_id` pasa a NOT NULL, igual que se hizo con `devoluciones`.
--     Una fila de la bandeja sin empresa no la ve nadie: todas las pantallas
--     filtran por empresa.
--
-- Así el índice que ya existe empieza a funcionar, sin índices nuevos y sin
-- tocar una sola consulta.
--
-- (Se intentó primero con columnas derivadas, como en `seguimiento` y en la
-- cédula de las empresas. No se puede aquí: MariaDB rechaza con el error 1901
-- cualquier expresión de texto en una columna generada de una tabla con
-- cotejamiento propio, porque el literal tomaría el juego de caracteres de la
-- conexión y dejaría de ser determinista.)
--
-- SI EL PASO 1 DEVUELVE FILAS
--
-- Son los duplicados que el índice roto dejó entrar. El paso 3 se queda con el
-- MÁS NUEVO de cada grupo —el último reprocesamiento trae los archivos
-- buenos— y borra los anteriores. Mirar la columna `estados` antes: si algún
-- grupo tiene una `importada`, esa es la que hay que conservar y hay que
-- resolverlo a mano.


-- =====================================================================
-- 1. ANTES: los duplicados que el índice no atajó
-- =====================================================================
-- Debe devolver 0 filas.

SELECT COALESCE(`sociedad_id`, 0) AS empresa,
       `uid_correo`,
       COUNT(*)                   AS cuantas,
       GROUP_CONCAT(`id` ORDER BY `id` SEPARATOR ',')     AS ids,
       GROUP_CONCAT(`estado` ORDER BY `id` SEPARATOR ',') AS estados
  FROM `correo_bandeja`
 GROUP BY COALESCE(`sociedad_id`, 0), `uid_correo`, COALESCE(`hash_xml`, '')
HAVING COUNT(*) > 1;

-- Y cuántas filas hay que adoptar en el paso 2.
SELECT 'bandeja sin empresa' AS revision, COUNT(*) AS filas
  FROM `correo_bandeja` WHERE `sociedad_id` IS NULL
UNION ALL
SELECT 'bandeja sin hash (XML ilegible)', COUNT(*)
  FROM `correo_bandeja` WHERE `hash_xml` IS NULL;


-- =====================================================================
-- 2. Adoptar las filas sin empresa
-- =====================================================================
-- Misma regla que migration_sociedades_alcance.sql y que la de devoluciones:
-- la activa por omisión y, si no hay, la primera que exista.

SET @sociedad_base := (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1);
SET @sociedad_base := COALESCE(@sociedad_base, (SELECT MIN(id) FROM `sociedades`));

UPDATE `correo_bandeja` SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;
UPDATE `correo_bandeja` SET `hash_xml` = '' WHERE `hash_xml` IS NULL;


-- =====================================================================
-- 3. Quedarse con el más nuevo de cada grupo repetido
-- =====================================================================
-- Ahora que ya no hay NULL, los duplicados se agrupan sin COALESCE. Sin esto
-- el paso 4 falla al crear la unicidad.

DELETE b FROM `correo_bandeja` b
  JOIN (
        SELECT MAX(`id`) AS conservar, `sociedad_id` AS s, `uid_correo` AS u, `hash_xml` AS h
          FROM `correo_bandeja`
         GROUP BY `sociedad_id`, `uid_correo`, `hash_xml`
        HAVING COUNT(*) > 1
       ) g
    ON b.`sociedad_id` = g.s AND b.`uid_correo` = g.u AND b.`hash_xml` = g.h
 WHERE b.`id` <> g.conservar;


-- =====================================================================
-- 4. Las dos columnas, obligatorias
-- =====================================================================
-- Si esto falla con "cannot be null" es que el paso 2 no encontró ninguna
-- empresa a la que adoptar las filas: no hay ninguna creada.
--
-- La clave foránea de `sociedad_id` hacia `sociedades` (RESTRICT, de
-- migration_claves_correo_cargas.sql) sigue igual: lo único que cambia es que
-- la columna deja de admitir NULL.

ALTER TABLE `correo_bandeja`
    MODIFY COLUMN `sociedad_id` INT UNSIGNED NOT NULL
        COMMENT 'Empresa dueña. Obligatoria: sin ella la unicidad no deduplica',
    MODIFY COLUMN `hash_xml` CHAR(64) NOT NULL DEFAULT ''
        COMMENT 'sha256 del XML. Vacío = no se pudo leer (antes era NULL, y dos NULL no son iguales)';


-- =====================================================================
-- 5. DESPUÉS: comprobar
-- =====================================================================
-- Las dos primeras deben decir 'NO'. La última, 0 filas.

SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correo_bandeja'
   AND COLUMN_NAME IN ('sociedad_id', 'hash_xml');

SELECT `sociedad_id`, `uid_correo`, COUNT(*) AS cuantas
  FROM `correo_bandeja`
 GROUP BY `sociedad_id`, `uid_correo`, `hash_xml`
HAVING COUNT(*) > 1;
