-- Bandeja de avisos de la aplicación.
--
-- Qué es: la caja de la campana en la barra de arriba. Hasta ahora lo único
-- que tenía la aplicación para decir algo era el toast de `flash_message`, que
-- dura siete segundos y se pierde: si nadie estaba viendo la pantalla en ese
-- momento, el aviso no existió. Las cosas que piden una decisión —"este código
-- del ERP parece haber cambiado de proveedor"— no pueden vivir en algo que se
-- desvanece.
--
-- Los avisos son COMPARTIDOS, no de cada usuario. La base es una sola y la
-- comparten todas las máquinas del grupo; un aviso aquí no es "algo que Fabián
-- no ha leído" sino una tarea pendiente del equipo, igual que Seguimiento. Si
-- Sofía resuelve la confirmación de un código, queda resuelta para todos, que
-- es justo lo que se quiere: nadie la revisa dos veces.
--
-- Por eso no hay tabla de "leído por usuario": el contador de la campana
-- cuenta los `pendiente`, y lo que saca un aviso de esa cuenta es que alguien
-- lo resuelva o lo descarte, no que lo haya mirado.

CREATE TABLE IF NOT EXISTS `notificaciones` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Familia del aviso; decide el icono y a dónde lleva el botón de acción.
    -- El primero es 'codigo_proveedor', pero la tabla es genérica a propósito:
    -- es el lugar donde deben ir cayendo las demás alertas del sistema.
    `tipo` VARCHAR(40) NOT NULL,

    -- alta  = pide una decisión (bloquea trabajo si no se atiende)
    -- media = conviene mirarlo
    -- baja  = queda como constancia
    `severidad` VARCHAR(10) NOT NULL DEFAULT 'media',

    `titulo` VARCHAR(200) NOT NULL,
    `detalle` TEXT NULL DEFAULT NULL,

    -- Identidad del aviso: dos veces el mismo hecho no son dos avisos. Sin
    -- esto, cada verificación de un pago volvería a crear el mismo renglón y
    -- la campana quedaría inservible en una semana.
    `firma` VARCHAR(190) NOT NULL,
    -- Cuántas veces volvió a pasar lo mismo. Es la señal de que algo dejó de
    -- ser una casualidad.
    `veces` INT UNSIGNED NOT NULL DEFAULT 1,

    -- A qué apunta, para que el panel pueda ofrecer la acción correcta sin
    -- tener que interpretar el texto.
    `ref_tabla` VARCHAR(40) NOT NULL DEFAULT '',
    `ref_clave` VARCHAR(60) NOT NULL DEFAULT '',
    `datos` TEXT NULL DEFAULT NULL COMMENT 'JSON con lo que necesite la pantalla de revisión',

    `estado` ENUM('pendiente','resuelta','descartada') NOT NULL DEFAULT 'pendiente',
    `resuelta_por` INT UNSIGNED NULL DEFAULT NULL,
    `resuelta_en` DATETIME NULL DEFAULT NULL,
    `motivo` VARCHAR(255) NULL DEFAULT NULL,

    `creada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizada_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_firma` (`firma`),
    KEY `idx_estado` (`estado`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_creada` (`creada_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Avisos compartidos: la campana de la barra superior';
