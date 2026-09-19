-- =========================================================================
-- 005_roles_y_bitacora.sql — Control de accesos y bitácora de actividad
-- =========================================================================
--
-- Los modelos crean estas tablas solos la primera vez que alguien entra
-- (Rol::ensureTables y Bitacora::ensureTable, una sola vez por instalación
-- gracias a Esquema). Este archivo es para los servidores donde el usuario
-- de la web NO tiene permisos de DDL: ahí hay que correrlo a mano una vez.
--
--   mysql -h SERVIDOR -P 3307 -u root -p bd_xmlconcilia < 005_roles_y_bitacora.sql
--
-- Es idempotente: se puede correr dos veces sin romper nada.
-- =========================================================================


-- ── Roles ────────────────────────────────────────────────────────────────
-- Un rol es un nombre y una lista de permisos; cada usuario tiene uno solo.
--   es_admin    lo puede todo, también los permisos que se inventen mañana
--   es_sistema  no se puede eliminar (Administrador y Operación)
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre` VARCHAR(60) NOT NULL,
    `descripcion` VARCHAR(255) NULL DEFAULT NULL,
    `es_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `es_sistema` TINYINT(1) NOT NULL DEFAULT 0,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rol_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Roles del sistema; el catálogo de permisos vive en app/config/permisos.php';


-- ── Permisos de cada rol ─────────────────────────────────────────────────
-- Una fila por permiso concedido, y no un JSON en la fila del rol: así se
-- puede preguntar "quién puede borrar semanas" sin abrir todos los roles.
CREATE TABLE IF NOT EXISTS `rol_permisos` (
    `rol_id` INT UNSIGNED NOT NULL,
    `permiso` VARCHAR(60) NOT NULL,
    PRIMARY KEY (`rol_id`, `permiso`),
    KEY `idx_rol_permiso` (`permiso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Qué puede hacer cada rol; las claves son las de app/config/permisos.php';


-- ── A qué empresas llega cada usuario ────────────────────────────────────
-- SIN FILAS PARA UN USUARIO = TODAS LAS EMPRESAS, no ninguna. Si significara
-- ninguna, el día que esto se instala todo el mundo se quedaría sin ver nada
-- hasta que un administrador fuera usuario por usuario.
CREATE TABLE IF NOT EXISTS `usuario_sociedades` (
    `usuario_id` INT UNSIGNED NOT NULL,
    `sociedad_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`usuario_id`, `sociedad_id`),
    KEY `idx_us_sociedad` (`sociedad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Restricción por empresa; sin filas = todas';


-- ── El rol en la ficha del usuario ───────────────────────────────────────
-- ADD COLUMN IF NOT EXISTS es de MariaDB y no de MySQL, así que se comprueba
-- antes contra information_schema para que esto corra en las dos.
SET @hay_rol := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'rol_id'
);
SET @sql := IF(@hay_rol = 0,
    'ALTER TABLE `usuarios` ADD COLUMN `rol_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_admin`,
                            ADD KEY `idx_usuario_rol` (`rol_id`)',
    'SELECT "usuarios.rol_id ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── Bitácora ─────────────────────────────────────────────────────────────
-- Un renglón por acción que cambia algo. El nombre del usuario se copia en la
-- fila además del id: borrar una cuenta no puede borrar lo que esa cuenta
-- hizo.
CREATE TABLE IF NOT EXISTS `bitacora` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
    `usuario_nombre` VARCHAR(120) NULL DEFAULT NULL,
    `rol_nombre` VARCHAR(60) NULL DEFAULT NULL,
    `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
    `modulo` VARCHAR(40) NOT NULL DEFAULT 'general',
    `accion` VARCHAR(160) NOT NULL,
    `permiso` VARCHAR(60) NULL DEFAULT NULL,
    `entidad` VARCHAR(60) NULL DEFAULT NULL,
    `entidad_id` VARCHAR(60) NULL DEFAULT NULL,
    `detalle` TEXT NULL DEFAULT NULL,
    `metodo` VARCHAR(8) NOT NULL DEFAULT 'GET',
    `ruta` VARCHAR(190) NOT NULL DEFAULT '',
    `ip` VARCHAR(45) NULL DEFAULT NULL,
    `resultado` ENUM('ok','denegado','error') NOT NULL DEFAULT 'ok',
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bitacora_fecha` (`creado_en`),
    KEY `idx_bitacora_usuario` (`usuario_id`, `creado_en`),
    KEY `idx_bitacora_modulo` (`modulo`, `creado_en`),
    KEY `idx_bitacora_resultado` (`resultado`, `creado_en`),
    KEY `idx_bitacora_entidad` (`entidad`, `entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Quién hizo qué; la pantalla está en /bitacora';


-- ── Los dos roles base y el reparto de los usuarios que ya existían ──────
-- Quien era administrador sigue siéndolo; el resto recibe Operación. Nadie se
-- queda fuera el día que esto se sube, que es la única forma de que un
-- control de accesos no acabe desactivado a las dos horas.
--
-- Los permisos de Operación NO se insertan aquí: los calcula el propio código
-- a partir del catálogo (Rol::permisosDeOperacion), y escribirlos también en
-- SQL sería garantizar que las dos listas se separen con el tiempo. Si esta
-- instalación no tiene DDL en runtime pero sí INSERT, el código los siembra
-- solo la primera vez que alguien entra.
INSERT INTO `roles` (`nombre`, `descripcion`, `es_admin`, `es_sistema`)
SELECT 'Administrador',
       'Acceso completo al sistema, incluida la administración de usuarios y roles.', 1, 1
 WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `nombre` = 'Administrador');

INSERT INTO `roles` (`nombre`, `descripcion`, `es_admin`, `es_sistema`)
SELECT 'Operación',
       'El trabajo del día: cargar, revisar y resolver documentos. Sin administración.', 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `nombre` = 'Operación');

UPDATE `usuarios` u
   JOIN `roles` r ON r.`nombre` = 'Administrador'
   SET u.`rol_id` = r.`id`
 WHERE u.`rol_id` IS NULL AND u.`is_admin` = 1;

UPDATE `usuarios` u
   JOIN `roles` r ON r.`nombre` = 'Operación'
   SET u.`rol_id` = r.`id`
 WHERE u.`rol_id` IS NULL;
