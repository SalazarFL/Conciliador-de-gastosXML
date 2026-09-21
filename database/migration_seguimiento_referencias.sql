-- Migración: el seguimiento se amarra a su documento, sin dejar de ser polimórfico.
--
-- EL PROBLEMA
--
-- `seguimiento` no apunta a una tabla sino a tres, según lo que diga `origen`:
--
--     origen = 'xml_factura' | 'xml_nota'  ->  facturas_xml.id
--     origen = 'nota_credito'              ->  notas_credito_lineas.id
--     origen = 'factura'                   ->  facturas_erp.id
--
-- Una clave foránea no puede apuntar a tres tablas, así que `referencia_id`
-- nunca tuvo ninguna y nada limpia los seguimientos cuando su documento se
-- borra. Lo grave no es el desorden: `notas_credito_lineas` se borra EN
-- CASCADA al borrar su listado, y ahí se van de golpe las líneas de una carga
-- entera dejando sus seguimientos —con el responsable, el motivo y el
-- recordatorio que alguien escribió— apuntando a ids que ya no existen.
--
-- POR QUÉ NO SE PARTIÓ EN TRES COLUMNAS
--
-- Lo obvio era cambiar `origen` + `referencia_id` por tres columnas anulables
-- con su clave cada una. Son 72 usos del par en app/ y cli/, más diez pruebas,
-- casi todos dentro de un modelo de 2.200 líneas. Mucho riesgo para un arreglo
-- de integridad, y el sistema está en uso.
--
-- LO QUE SE HACE EN SU LUGAR
--
-- El par se queda EXACTAMENTE como está —ni una línea de PHP cambia— y se le
-- añaden tres columnas GENERADAS que derivan de él:
--
--     ref_factura_xml   = referencia_id cuando origen es xml_factura/xml_nota
--     ref_nota_linea    = referencia_id cuando origen es nota_credito
--     ref_factura_erp   = referencia_id cuando origen es factura
--
-- Cada una vale NULL cuando el origen no es el suyo, y una clave foránea
-- ignora los NULL. Así cada fila queda amarrada a su tabla y solo a la suya.
-- Son STORED, no VIRTUAL: InnoDB necesita la columna materializada para
-- indexarla y ponerle la clave.
--
-- Comprobado en esta base antes de escribirlo, porque no es evidente que
-- funcione:
--
--   · MariaDB acepta ON DELETE CASCADE sobre una columna generada STORED.
--   · Un INSERT o un UPDATE con un `referencia_id` que no existe en la tabla
--     que le toca se RECHAZA (1452); si el origen es otro, no se mira.
--   · La cascada funciona EN CADENA: borrar un listado borra sus líneas por
--     cascada, y eso borra sus seguimientos por cascada. Es justo el caso que
--     motivó todo esto, y es el que un trigger NO habría cubierto —en
--     MySQL/MariaDB los triggers no se disparan en borrados por cascada—.
--
-- POR QUÉ CASCADE Y NO SET NULL
--
-- `referencia_id` es NOT NULL y es lo que hace único al seguimiento
-- (`uk_seguimiento_referencia`). Un seguimiento sin documento no es nada: no
-- se puede mostrar, no se puede reasignar y no hay pantalla que lo liste.
-- Desaparecido el documento, desaparece su seguimiento.
--
-- OJO: el HISTORIAL sí sobrevive
--
-- `seguimiento_bitacora` guarda los cambios de estado con su comentario, y esa
-- es la memoria de lo que se decidió. Su clave va en otra migración
-- (migration_claves_correo_cargas.sql) y va con SET NULL a propósito.
--
-- CÓMO SE APLICA
--
-- El paso 1 se lee y NO se salta: lista uno por uno los seguimientos que ya
-- están huérfanos y que el paso 2 va a borrar. Son anotaciones que escribió
-- gente —responsable, motivo, recordatorio—, así que conviene mirarlas antes.
-- Si alguna vale la pena, se copia a mano antes de seguir; no hay forma de
-- conservarla, porque el documento al que se refiere ya no existe.


-- =====================================================================
-- 1. ANTES: los seguimientos que ya perdieron su documento
-- =====================================================================
-- Estos se van en el paso 2. Se muestran enteros, no contados, porque llevan
-- texto que escribió alguien.

SELECT s.id, s.origen, s.referencia_id, s.estado, s.responsable, s.motivo,
       s.creado_en, s.actualizado_en
  FROM `seguimiento` s
  LEFT JOIN `facturas_xml`         x  ON x.id  = s.referencia_id
  LEFT JOIN `notas_credito_lineas` nl ON nl.id = s.referencia_id
  LEFT JOIN `facturas_erp`         fe ON fe.id = s.referencia_id
 WHERE (s.origen IN ('xml_factura','xml_nota') AND x.id  IS NULL)
    OR (s.origen = 'nota_credito'              AND nl.id IS NULL)
    OR (s.origen = 'factura'                   AND fe.id IS NULL)
 ORDER BY s.origen, s.id;

-- Y el resumen, para tener el número a mano.
SELECT 'seguimientos huérfanos que se van' AS revision, COUNT(*) AS filas
  FROM `seguimiento` s
  LEFT JOIN `facturas_xml`         x  ON x.id  = s.referencia_id
  LEFT JOIN `notas_credito_lineas` nl ON nl.id = s.referencia_id
  LEFT JOIN `facturas_erp`         fe ON fe.id = s.referencia_id
 WHERE (s.origen IN ('xml_factura','xml_nota') AND x.id  IS NULL)
    OR (s.origen = 'nota_credito'              AND nl.id IS NULL)
    OR (s.origen = 'factura'                   AND fe.id IS NULL);


-- =====================================================================
-- 2. Quitar los huérfanos
-- =====================================================================
-- Sin esto el paso 4 no puede crear las claves. Su historial en
-- `seguimiento_bitacora` se va con ellos: es de un seguimiento que ya no
-- existe, de un documento que tampoco.

DELETE s FROM `seguimiento` s
  LEFT JOIN `facturas_xml`         x  ON x.id  = s.referencia_id
  LEFT JOIN `notas_credito_lineas` nl ON nl.id = s.referencia_id
  LEFT JOIN `facturas_erp`         fe ON fe.id = s.referencia_id
 WHERE (s.origen IN ('xml_factura','xml_nota') AND x.id  IS NULL)
    OR (s.origen = 'nota_credito'              AND nl.id IS NULL)
    OR (s.origen = 'factura'                   AND fe.id IS NULL);

DELETE b FROM `seguimiento_bitacora` b
  LEFT JOIN `seguimiento` s ON s.id = b.seguimiento_id
 WHERE s.id IS NULL;


-- =====================================================================
-- 3. Las tres columnas derivadas
-- =====================================================================
-- Se dejan caer antes por si la migración se repite: una columna generada no
-- admite ADD COLUMN IF NOT EXISTS con definición distinta sin líos, y así el
-- estado final es siempre el mismo.

ALTER TABLE `seguimiento` DROP FOREIGN KEY IF EXISTS `fk_seg_factura_xml`;
ALTER TABLE `seguimiento` DROP FOREIGN KEY IF EXISTS `fk_seg_nota_linea`;
ALTER TABLE `seguimiento` DROP FOREIGN KEY IF EXISTS `fk_seg_factura_erp`;
ALTER TABLE `seguimiento` DROP COLUMN IF EXISTS `ref_factura_xml`;
ALTER TABLE `seguimiento` DROP COLUMN IF EXISTS `ref_nota_linea`;
ALTER TABLE `seguimiento` DROP COLUMN IF EXISTS `ref_factura_erp`;

ALTER TABLE `seguimiento`
    ADD COLUMN `ref_factura_xml` INT UNSIGNED
        GENERATED ALWAYS AS (IF(`origen` IN ('xml_factura','xml_nota'), `referencia_id`, NULL)) STORED
        COMMENT 'Derivada: a qué facturas_xml apunta, si apunta a una',
    ADD COLUMN `ref_nota_linea` INT UNSIGNED
        GENERATED ALWAYS AS (IF(`origen` = 'nota_credito', `referencia_id`, NULL)) STORED
        COMMENT 'Derivada: a qué notas_credito_lineas apunta, si apunta a una',
    ADD COLUMN `ref_factura_erp` INT UNSIGNED
        GENERATED ALWAYS AS (IF(`origen` = 'factura', `referencia_id`, NULL)) STORED
        COMMENT 'Derivada: a qué facturas_erp apunta, si apunta a una',
    ADD KEY `idx_seg_ref_factura_xml` (`ref_factura_xml`),
    ADD KEY `idx_seg_ref_nota_linea` (`ref_nota_linea`),
    ADD KEY `idx_seg_ref_factura_erp` (`ref_factura_erp`);


-- =====================================================================
-- 4. Las tres claves
-- =====================================================================

ALTER TABLE `seguimiento`
    ADD CONSTRAINT `fk_seg_factura_xml` FOREIGN KEY (`ref_factura_xml`)
        REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE;

ALTER TABLE `seguimiento`
    ADD CONSTRAINT `fk_seg_nota_linea` FOREIGN KEY (`ref_nota_linea`)
        REFERENCES `notas_credito_lineas` (`id`) ON DELETE CASCADE;

ALTER TABLE `seguimiento`
    ADD CONSTRAINT `fk_seg_factura_erp` FOREIGN KEY (`ref_factura_erp`)
        REFERENCES `facturas_erp` (`id`) ON DELETE CASCADE;


-- =====================================================================
-- 5. DESPUÉS: comprobar
-- =====================================================================
-- Debe decir 3. `php tests/SeguimientoAmarradoTest.php` comprueba además que
-- la cascada funcione en cadena y que cada origen solo mire su tabla.

SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS claves_de_seguimiento
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento'
   AND REFERENCED_TABLE_NAME IS NOT NULL;
