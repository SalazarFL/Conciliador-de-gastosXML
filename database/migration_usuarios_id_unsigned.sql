-- Migración: `usuarios.id` pasa a INT UNSIGNED y las 17 referencias ganan su
-- clave foránea.
--
-- POR QUÉ
--
-- Diecisiete columnas de la base dicen "esto lo hizo el usuario tal":
-- bitacora.usuario_id, notificaciones.resuelta_por, proveedor_alias.creado_por
-- y catorce más. Ninguna tiene clave foránea, así que para el motor son
-- enteros sueltos: se puede escribir un id de usuario que no existe, y se
-- puede borrar un usuario dejando sus diecisiete rastros apuntando al vacío.
--
-- No es descuido de quien las escribió. NO SE PODÍAN PONER. InnoDB exige que
-- padre e hijo tengan el tipo idéntico, incluido el signo, y aquí no coinciden:
--
--     usuarios.id            INT(11)           ← con signo
--     las diecisiete         INT(10) UNSIGNED  ← sin signo
--
-- `usuarios` es la única tabla de la base con la llave con signo; nació así y
-- las demás se escribieron después con la convención correcta. Mientras el
-- padre siga con signo, ninguna de las diecisiete puede tener su clave, y por
-- eso esta migración empieza por ahí.
--
-- El signo no servía para nada: es un AUTO_INCREMENT, nunca hubo ni va a haber
-- un id negativo. Lo único que hacía era bloquear las claves.
--
-- QUÉ REGLA LLEVA CADA UNA
--
--   * usuario_sociedades.usuario_id  →  ON DELETE CASCADE
--     Es a qué empresas puede entrar alguien. Si la persona se va, el permiso
--     se va con ella: un permiso de acceso para un usuario que no existe no
--     significa nada y es lo que hoy queda tirado.
--
--   * las otras dieciséis            →  ON DELETE SET NULL
--     Son de autoría: quién cargó el reporte, quién descartó la incidencia,
--     quién resolvió el aviso. El HECHO tiene que sobrevivir a la persona —la
--     carga ocurrió aunque el usuario ya no esté—, así que la fila se queda y
--     solo se suelta el nombre. La bitácora además guarda `usuario_nombre`
--     aparte, justo para que el registro siga siendo legible después.
--
-- EFECTO SECUNDARIO ESPERADO: quince índices nuevos
--
-- InnoDB exige un índice en la columna hija y lo crea solo si no está. Quince
-- de las diecisiete no lo tienen, así que aparecerán quince índices nuevos con
-- nombre de la clave. No es desperdicio: son las columnas por las que se
-- pregunta "qué hizo Fulano", y las tablas que los reciben son casi todas de
-- solo agregar, donde un índice de más cuesta poco.
--
-- CÓMO SE APLICA
--
-- Los pasos 1 y 2 se leen; no cambian nada. Los pasos 3 a 5 sí escriben, y
-- conviene correrlos con la aplicación parada. El paso 6 es la comprobación.
--
-- Si algo del paso 4 falla, la migración se puede repetir entera: cada clave
-- se borra antes de crearse, así que no hay estado a medias que arreglar.


-- =====================================================================
-- 1. ANTES: lo que tendría que impedir seguir
-- =====================================================================
-- Esta consulta DEBE devolver 0. Si devuelve algo, hay ids negativos y el
-- cambio de tipo los rompería en silencio: hay que verlos antes.

SELECT 'usuarios con id negativo (debe dar 0)' AS revision, COUNT(*) AS filas
  FROM `usuarios` WHERE `id` < 0;


-- =====================================================================
-- 2. ANTES: cuántas referencias están rotas hoy
-- =====================================================================
-- Estas son las filas que el paso 3 va a soltar. No hace falta que den 0 —lo
-- normal es que den algo, porque es justamente el daño que se viene
-- acumulando sin las claves—, pero conviene MIRARLAS antes de soltarlas, sobre
-- todo la última, que es la única donde se borran filas en vez de vaciar una
-- columna.

SELECT 'bitacora',                        COUNT(*) FROM `bitacora` t                        LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'correo_incidencias',              COUNT(*) FROM `correo_incidencias` t              LEFT JOIN `usuarios` u ON u.id=t.`descartada_por` WHERE t.`descartada_por` IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'correo_incidencias_descartes',    COUNT(*) FROM `correo_incidencias_descartes` t    LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'facturas_erp_cargas',             COUNT(*) FROM `facturas_erp_cargas` t             LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'facturas_erp_descartes',          COUNT(*) FROM `facturas_erp_descartes` t          LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'facturas_erp_incidencias',        COUNT(*) FROM `facturas_erp_incidencias` t        LEFT JOIN `usuarios` u ON u.id=t.`descartada_por` WHERE t.`descartada_por` IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'facturas_erp_revision',           COUNT(*) FROM `facturas_erp_revision` t           LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'facturas_erp_revision_memoria',   COUNT(*) FROM `facturas_erp_revision_memoria` t   LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'notas_credito_cargas',            COUNT(*) FROM `notas_credito_cargas` t            LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'notas_credito_revision',          COUNT(*) FROM `notas_credito_revision` t          LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'notas_credito_revision_memoria',  COUNT(*) FROM `notas_credito_revision_memoria` t  LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'notificaciones',                  COUNT(*) FROM `notificaciones` t                  LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'proveedor_alias',                 COUNT(*) FROM `proveedor_alias` t                 LEFT JOIN `usuarios` u ON u.id=t.`creado_por`     WHERE t.`creado_por`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'proveedor_cedula_ignorada',       COUNT(*) FROM `proveedor_cedula_ignorada` t       LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'proveedor_codigo_erp',            COUNT(*) FROM `proveedor_codigo_erp` t            LEFT JOIN `usuarios` u ON u.id=t.`confirmado_por` WHERE t.`confirmado_por` IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'seguimiento_bitacora',            COUNT(*) FROM `seguimiento_bitacora` t            LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL
UNION ALL SELECT '>>> usuario_sociedades (ESTAS SE BORRAN)', COUNT(*) FROM `usuario_sociedades` t LEFT JOIN `usuarios` u ON u.id=t.`usuario_id` WHERE u.id IS NULL;


-- =====================================================================
-- 3. Soltar las referencias rotas
-- =====================================================================
-- Sin esto el paso 4 falla: no se puede crear una clave foránea sobre una
-- columna que ya contiene valores inválidos. Es el precio de haber estado sin
-- claves hasta hoy, y se paga una sola vez.

UPDATE `bitacora` t                       LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `correo_incidencias` t             LEFT JOIN `usuarios` u ON u.id=t.`descartada_por` SET t.`descartada_por` = NULL WHERE t.`descartada_por` IS NOT NULL AND u.id IS NULL;
UPDATE `correo_incidencias_descartes` t   LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `facturas_erp_cargas` t            LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `facturas_erp_descartes` t         LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `facturas_erp_incidencias` t       LEFT JOIN `usuarios` u ON u.id=t.`descartada_por` SET t.`descartada_por` = NULL WHERE t.`descartada_por` IS NOT NULL AND u.id IS NULL;
UPDATE `facturas_erp_revision` t          LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   SET t.`resuelta_por`   = NULL WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL;
UPDATE `facturas_erp_revision_memoria` t  LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `notas_credito_cargas` t           LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `notas_credito_revision` t         LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   SET t.`resuelta_por`   = NULL WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL;
UPDATE `notas_credito_revision_memoria` t LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `notificaciones` t                 LEFT JOIN `usuarios` u ON u.id=t.`resuelta_por`   SET t.`resuelta_por`   = NULL WHERE t.`resuelta_por`   IS NOT NULL AND u.id IS NULL;
UPDATE `proveedor_alias` t                LEFT JOIN `usuarios` u ON u.id=t.`creado_por`     SET t.`creado_por`     = NULL WHERE t.`creado_por`     IS NOT NULL AND u.id IS NULL;
UPDATE `proveedor_cedula_ignorada` t      LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;
UPDATE `proveedor_codigo_erp` t           LEFT JOIN `usuarios` u ON u.id=t.`confirmado_por` SET t.`confirmado_por` = NULL WHERE t.`confirmado_por` IS NOT NULL AND u.id IS NULL;
UPDATE `seguimiento_bitacora` t           LEFT JOIN `usuarios` u ON u.id=t.`usuario_id`     SET t.`usuario_id`     = NULL WHERE t.`usuario_id`     IS NOT NULL AND u.id IS NULL;

-- La única donde se BORRAN filas: usuario_id es NOT NULL, así que un permiso
-- de un usuario inexistente no se puede vaciar, solo quitar. Es lo que la
-- clave CASCADE habría hecho sola en su momento.
DELETE t FROM `usuario_sociedades` t LEFT JOIN `usuarios` u ON u.id=t.`usuario_id` WHERE u.id IS NULL;


-- =====================================================================
-- 4. El tipo del padre
-- =====================================================================
-- Lo que destraba todo lo demás. Se repite la definición completa porque
-- MODIFY la reemplaza entera: omitir AUTO_INCREMENT aquí lo quitaría.

ALTER TABLE `usuarios`
    MODIFY COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;


-- =====================================================================
-- 5. Las diecisiete claves
-- =====================================================================
-- Cada una se borra antes de crearse para que la migración se pueda repetir
-- sin quedar a medias.

ALTER TABLE `usuario_sociedades` DROP FOREIGN KEY IF EXISTS `fk_us_usuario`;
ALTER TABLE `usuario_sociedades`
    ADD CONSTRAINT `fk_us_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

ALTER TABLE `bitacora` DROP FOREIGN KEY IF EXISTS `fk_bitacora_usuario`;
ALTER TABLE `bitacora`
    ADD CONSTRAINT `fk_bitacora_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `correo_incidencias` DROP FOREIGN KEY IF EXISTS `fk_correo_incidencia_usuario`;
ALTER TABLE `correo_incidencias`
    ADD CONSTRAINT `fk_correo_incidencia_usuario` FOREIGN KEY (`descartada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `correo_incidencias_descartes` DROP FOREIGN KEY IF EXISTS `fk_correo_descarte_usuario`;
ALTER TABLE `correo_incidencias_descartes`
    ADD CONSTRAINT `fk_correo_descarte_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_cargas` DROP FOREIGN KEY IF EXISTS `fk_erp_carga_usuario`;
ALTER TABLE `facturas_erp_cargas`
    ADD CONSTRAINT `fk_erp_carga_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_descartes` DROP FOREIGN KEY IF EXISTS `fk_erp_descarte_usuario`;
ALTER TABLE `facturas_erp_descartes`
    ADD CONSTRAINT `fk_erp_descarte_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_incidencias` DROP FOREIGN KEY IF EXISTS `fk_erp_incidencia_usuario`;
ALTER TABLE `facturas_erp_incidencias`
    ADD CONSTRAINT `fk_erp_incidencia_usuario` FOREIGN KEY (`descartada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_revision` DROP FOREIGN KEY IF EXISTS `fk_erp_revision_usuario`;
ALTER TABLE `facturas_erp_revision`
    ADD CONSTRAINT `fk_erp_revision_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `facturas_erp_revision_memoria` DROP FOREIGN KEY IF EXISTS `fk_erp_memoria_usuario`;
ALTER TABLE `facturas_erp_revision_memoria`
    ADD CONSTRAINT `fk_erp_memoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_cargas` DROP FOREIGN KEY IF EXISTS `fk_nc_carga_usuario`;
ALTER TABLE `notas_credito_cargas`
    ADD CONSTRAINT `fk_nc_carga_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_revision` DROP FOREIGN KEY IF EXISTS `fk_nc_revision_usuario`;
ALTER TABLE `notas_credito_revision`
    ADD CONSTRAINT `fk_nc_revision_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `notas_credito_revision_memoria` DROP FOREIGN KEY IF EXISTS `fk_nc_memoria_usuario`;
ALTER TABLE `notas_credito_revision_memoria`
    ADD CONSTRAINT `fk_nc_memoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `notificaciones` DROP FOREIGN KEY IF EXISTS `fk_notificacion_usuario`;
ALTER TABLE `notificaciones`
    ADD CONSTRAINT `fk_notificacion_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `proveedor_alias` DROP FOREIGN KEY IF EXISTS `fk_alias_usuario`;
ALTER TABLE `proveedor_alias`
    ADD CONSTRAINT `fk_alias_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `proveedor_cedula_ignorada` DROP FOREIGN KEY IF EXISTS `fk_cedula_ignorada_usuario`;
ALTER TABLE `proveedor_cedula_ignorada`
    ADD CONSTRAINT `fk_cedula_ignorada_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `proveedor_codigo_erp` DROP FOREIGN KEY IF EXISTS `fk_codigo_erp_usuario`;
ALTER TABLE `proveedor_codigo_erp`
    ADD CONSTRAINT `fk_codigo_erp_usuario` FOREIGN KEY (`confirmado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

ALTER TABLE `seguimiento_bitacora` DROP FOREIGN KEY IF EXISTS `fk_seg_bitacora_usuario`;
ALTER TABLE `seguimiento_bitacora`
    ADD CONSTRAINT `fk_seg_bitacora_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;


-- =====================================================================
-- 6. DESPUÉS: comprobar
-- =====================================================================
-- La primera debe decir `int(10) unsigned`. La segunda debe decir 17.
-- `php tests/ClavesForaneasUsuarioTest.php` comprueba lo mismo y además que
-- cada clave tenga la regla de borrado que le toca.

SELECT COLUMN_TYPE AS tipo_de_usuarios_id
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'id';

SELECT COUNT(DISTINCT CONSTRAINT_NAME) AS claves_hacia_usuarios
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'usuarios';
