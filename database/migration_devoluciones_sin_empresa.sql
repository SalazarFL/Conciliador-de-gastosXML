-- Migración: una devolución sin empresa deja de contársele a todas.
--
-- EL PROBLEMA
--
-- `Devolucion::resumen()` y `Devolucion::pendientesDeVerificar()` filtraban así:
--
--     WHERE sociedad_id = ? OR sociedad_id IS NULL
--
-- Es el único lugar del sistema con ese patrón. `listar()` y
-- `proveedoresParaFiltro()`, en la misma clase, filtran estricto
-- (`d.sociedad_id = ?`). El resultado en pantalla era que la LISTA mostraba las
-- devoluciones de la empresa y el RESUMEN de arriba contaba esas más todas las
-- que no tienen empresa: los números no cuadraban con lo que se veía debajo, y
-- cada empresa del grupo veía los mismos huérfanos sumados a los suyos. El
-- verificador, además, los reprocesaba una vez por empresa.
--
-- POR QUÉ ESTABA ASÍ
--
-- No fue un descuido: `migration_sociedades_alcance.sql` (línea 92) ya asigna
-- las devoluciones sin empresa a la empresa base, y ese `OR ... IS NULL` era
-- el respaldo por si esa migración no se había corrido todavía. El respaldo
-- sobrevivió a su motivo, y un respaldo que suma filas ajenas es peor que no
-- tenerlo.
--
-- QUÉ HACE ESTA MIGRACIÓN
--
--   1. Muestra las que todavía no tienen empresa, enteras.
--   2. Se las asigna a la empresa base, con la misma regla que ya usó
--      migration_sociedades_alcance.sql: no se inventa nada nuevo.
--   3. Pone la columna NOT NULL, que es lo que impide que vuelvan a aparecer.
--
-- El paso 3 es el que cierra el asunto de verdad. Mientras la columna admita
-- NULL, `DevolucionesController` puede volver a escribir uno: hacía
-- `$sociedad ? (int) $sociedad['id'] : null` al importar, así que una
-- importación sin empresa activa creaba una devolución de nadie. Eso también
-- se arregla en el código, pero la columna es la que lo garantiza.
--
-- SI EL PASO 1 DEVUELVE FILAS Y NO SON DE LA EMPRESA BASE
--
-- Pararse y asignarlas a mano antes de seguir. Una devolución lleva sucursal,
-- bodega y el nombre del proveedor tal como lo escribe el ERP: con eso se sabe
-- de quién es. Adivinar mal es peor que dejarla como está.


-- =====================================================================
-- 1. ANTES: las que no tienen empresa
-- =====================================================================
-- Enteras, no contadas: hay que poder reconocerlas.

SELECT id, tipo, numero, sucursal, bodega, proveedor_nombre_erp, fecha,
       total, estado, archivo_pdf, creado_en
  FROM `devoluciones`
 WHERE `sociedad_id` IS NULL
 ORDER BY fecha, id;

SELECT 'devoluciones sin empresa' AS revision, COUNT(*) AS filas
  FROM `devoluciones` WHERE `sociedad_id` IS NULL;

-- Y a qué empresa van a parar.
SELECT id, nombre, cedula, activa FROM `sociedades`
 WHERE id = COALESCE(
     (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1),
     (SELECT MIN(id) FROM `sociedades`)
 );


-- =====================================================================
-- 2. Asignarlas a la empresa base
-- =====================================================================
-- Misma regla que migration_sociedades_alcance.sql: la activa por omisión y,
-- si no hay ninguna, la primera que exista. Se toma así y no con un id a mano
-- para que la migración sirva igual en cualquier copia.

SET @sociedad_base := (SELECT id FROM `sociedades` WHERE activa = 1 ORDER BY id LIMIT 1);
SET @sociedad_base := COALESCE(@sociedad_base, (SELECT MIN(id) FROM `sociedades`));

UPDATE `devoluciones` SET `sociedad_id` = @sociedad_base WHERE `sociedad_id` IS NULL;


-- =====================================================================
-- 3. Que no puedan volver a aparecer
-- =====================================================================
-- Si esto falla con "Column 'sociedad_id' cannot be null" es que el paso 2 no
-- encontró ninguna empresa a la que asignarlas: no hay ninguna creada. Crear
-- la empresa primero y repetir.
--
-- La clave foránea hacia `sociedades` (RESTRICT, de
-- migration_claves_sociedad.sql) sigue igual; lo que cambia es que ahora la
-- columna es obligatoria.

ALTER TABLE `devoluciones`
    MODIFY COLUMN `sociedad_id` INT UNSIGNED NOT NULL
        COMMENT 'Empresa dueña. Obligatoria: una devolución de nadie se le contaba a todas.';


-- =====================================================================
-- 4. DESPUÉS: comprobar
-- =====================================================================
-- Las dos deben dar 0 filas y 'NO'.

SELECT 'devoluciones sin empresa (debe dar 0)' AS revision, COUNT(*) AS filas
  FROM `devoluciones` WHERE `sociedad_id` IS NULL;

SELECT IS_NULLABLE AS admite_null FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devoluciones'
   AND COLUMN_NAME = 'sociedad_id';
