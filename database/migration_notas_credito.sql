-- Módulo independiente de notas de crédito.
-- Cada archivo cargado es un listado por período y sus filas se cruzan
-- exclusivamente contra XML de tipo NC de la misma sociedad.

CREATE TABLE IF NOT EXISTS `notas_credito_listados` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sociedad_id` INT UNSIGNED NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `empresa_reporte` VARCHAR(255) NULL DEFAULT NULL,
    `periodo_desde` DATE NULL DEFAULT NULL,
    `periodo_hasta` DATE NULL DEFAULT NULL,
    `archivo_origen` VARCHAR(255) NOT NULL,
    `archivo_ruta` VARCHAR(500) NOT NULL,
    `archivo_hash` CHAR(64) NOT NULL,
    `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0,
    `fecha_subida` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_nc_archivo_hash` (`archivo_hash`),
    KEY `idx_nc_listado_sociedad` (`sociedad_id`, `fecha_subida`),
    CONSTRAINT `fk_nc_listado_sociedad`
        FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notas_credito_lineas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `listado_id` INT UNSIGNED NOT NULL,
    `fila_origen` INT UNSIGNED NOT NULL,
    `proveedor_codigo` VARCHAR(50) NULL DEFAULT NULL,
    `proveedor_nombre` VARCHAR(255) NOT NULL,
    `sucursal` VARCHAR(150) NULL DEFAULT NULL,
    `documento` VARCHAR(255) NOT NULL,
    `fecha` DATE NOT NULL,
    `nc_proveedor` VARCHAR(100) NULL DEFAULT NULL,
    `fecha_nc_proveedor` DATE NULL DEFAULT NULL,
    `entrada_asociada` VARCHAR(255) NULL DEFAULT NULL,
    `moneda` CHAR(3) NOT NULL,
    `monto` DECIMAL(18,2) NOT NULL,
    `saldo` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `monto_conversion` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `datos_origen` LONGTEXT NULL DEFAULT NULL,
    `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
    `estado` ENUM('sin_respaldo','coincide','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
    `diferencia` DECIMAL(18,2) NULL DEFAULT NULL,
    `metodo_match` ENUM('ninguno','numero','atributos','manual') NOT NULL DEFAULT 'ninguno',
    `score_proveedor` DECIMAL(5,1) NULL DEFAULT NULL,
    `match_manual` TINYINT(1) NOT NULL DEFAULT 0,
    `bloqueo_automatico` TINYINT(1) NOT NULL DEFAULT 0,
    `motivo_match` VARCHAR(255) NULL DEFAULT NULL,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_nc_xml_por_listado` (`listado_id`, `factura_xml_id`),
    KEY `idx_nc_linea_listado_estado` (`listado_id`, `estado`),
    KEY `idx_nc_linea_documento` (`documento`),
    KEY `idx_nc_linea_nc_proveedor` (`nc_proveedor`),
    KEY `idx_nc_linea_factura_xml` (`factura_xml_id`),
    CONSTRAINT `fk_nc_linea_listado`
        FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nc_linea_factura`
        FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notas_credito_verificaciones` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `listado_id` INT UNSIGNED NOT NULL,
    `origen` VARCHAR(30) NOT NULL DEFAULT 'automatico',
    `fecha_inicio` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_fin` DATETIME NULL DEFAULT NULL,
    `coincide` INT UNSIGNED NOT NULL DEFAULT 0,
    `con_diferencia` INT UNSIGNED NOT NULL DEFAULT 0,
    `sin_respaldo` INT UNSIGNED NOT NULL DEFAULT 0,
    `cantidad_cambios` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_nc_verificacion_listado_fecha` (`listado_id`, `fecha_inicio`),
    CONSTRAINT `fk_nc_verificacion_listado`
        FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notas_credito_historial` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `verificacion_id` BIGINT UNSIGNED NOT NULL,
    `listado_id` INT UNSIGNED NOT NULL,
    `linea_id` INT UNSIGNED NULL DEFAULT NULL,
    `fila_origen` INT UNSIGNED NULL DEFAULT NULL,
    `documento` VARCHAR(255) NULL DEFAULT NULL,
    `proveedor_nombre` VARCHAR(255) NULL DEFAULT NULL,
    `nc_proveedor` VARCHAR(100) NULL DEFAULT NULL,
    `moneda` CHAR(3) NULL DEFAULT NULL,
    `estado_anterior` VARCHAR(30) NOT NULL,
    `estado_nuevo` VARCHAR(30) NOT NULL,
    `factura_xml_id_anterior` INT UNSIGNED NULL DEFAULT NULL,
    `factura_xml_id_nuevo` INT UNSIGNED NULL DEFAULT NULL,
    `diferencia_anterior` DECIMAL(18,2) NULL DEFAULT NULL,
    `diferencia_nueva` DECIMAL(18,2) NULL DEFAULT NULL,
    `motivo_anterior` VARCHAR(255) NULL DEFAULT NULL,
    `motivo_nuevo` VARCHAR(255) NULL DEFAULT NULL,
    `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_nc_historial_verificacion` (`verificacion_id`, `id`),
    KEY `idx_nc_historial_linea` (`linea_id`, `fecha`),
    CONSTRAINT `fk_nc_historial_verificacion`
        FOREIGN KEY (`verificacion_id`) REFERENCES `notas_credito_verificaciones` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nc_historial_listado`
        FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nc_historial_linea`
        FOREIGN KEY (`linea_id`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
