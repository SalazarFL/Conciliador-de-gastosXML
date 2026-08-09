-- Migración: módulo "Facturas ERP"
--
-- Importa el reporte "Facturas por Proveedor" del ERP (CSV impreso) para
-- saber qué facturas siguen con saldo pendiente. El archivo es una foto del
-- momento en que se imprimió, no un flujo: cada carga vuelve a subirse
-- completa y se reconcilia contra lo ya guardado.
--
-- Identidad de una factura (columna `clave`):
--   con número  -> proveedor|documento|fecha_emision
--   sin número  -> proveedor|SINDOC|fecha_emision|monto
-- Verificado contra el archivo real: 5.770 facturas => 5.770 claves, 0 choques.
-- El documento solo no basta (12 números se repiten dentro del mismo
-- proveedor con distinta fecha de emisión) y 49 facturas vienen sin número.

-- ── Historial de cargas ──
CREATE TABLE IF NOT EXISTS `facturas_erp_cargas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `archivo_origen` VARCHAR(255) NOT NULL,
    `impreso_en` DATETIME NULL DEFAULT NULL COMMENT 'Fecha de impresión del reporte: a esa hora corresponden los saldos',
    `rango_texto` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Periodo que declara el encabezado del reporte',
    `filas_leidas` INT UNSIGNED NOT NULL DEFAULT 0,
    `insertadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `actualizadas` INT UNSIGNED NOT NULL DEFAULT 0,
    `sin_cambio` INT UNSIGNED NOT NULL DEFAULT 0,
    `totales_verificados` INT UNSIGNED NOT NULL DEFAULT 0,
    `totales_descuadrados` INT UNSIGNED NOT NULL DEFAULT 0,
    `cuadre_ok` TINYINT(1) NOT NULL DEFAULT 0,
    `incidencias` INT UNSIGNED NOT NULL DEFAULT 0,
    `advertencias` TEXT NULL DEFAULT NULL COMMENT 'JSON con el detalle de los descuadres tolerados',
    `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_creado` (`creado_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Historial de incidencias ──
-- Cada carga vuelve a evaluar el archivo completo, así que una misma factura
-- problemática deja una incidencia por carga: eso es justamente el historial.
-- Tipos: numero_duplicado, descuadre_total, saldo_mayor_monto (alerta);
--        sin_numero, vence_antes_emision, vencimiento_lejano,
--        moneda_extranjera, saldo_modificado (aviso).
CREATE TABLE IF NOT EXISTS `facturas_erp_incidencias` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `carga_id` INT UNSIGNED NOT NULL,
    `tipo` VARCHAR(30) NOT NULL,
    `severidad` VARCHAR(10) NOT NULL DEFAULT 'aviso' COMMENT 'alerta = pide revisión; aviso = solo deja constancia',
    `proveedor_codigo` VARCHAR(30) NOT NULL DEFAULT '',
    `proveedor_nombre` VARCHAR(255) NOT NULL DEFAULT '',
    `documento` VARCHAR(60) NOT NULL DEFAULT '',
    `clave` VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Identidad de la factura en facturas_erp',
    `fecha_emision` DATE NULL DEFAULT NULL,
    `monto` DECIMAL(18,2) NULL DEFAULT NULL,
    `saldo_anterior` DECIMAL(18,2) NULL DEFAULT NULL,
    `saldo_nuevo` DECIMAL(18,2) NULL DEFAULT NULL,
    `detalle` VARCHAR(500) NOT NULL DEFAULT '',
    `firma` VARCHAR(220) NOT NULL DEFAULT '' COMMENT 'Identidad de la incidencia entre cargas; permite descartarla de forma permanente',
    `descartada` TINYINT(1) NOT NULL DEFAULT 0,
    `descartada_en` DATETIME NULL DEFAULT NULL,
    `descartada_por` INT UNSIGNED NULL DEFAULT NULL,
    `motivo` VARCHAR(255) NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_carga` (`carga_id`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_severidad` (`severidad`),
    KEY `idx_proveedor` (`proveedor_codigo`),
    KEY `idx_documento` (`documento`),
    KEY `idx_firma` (`firma`),
    KEY `idx_descartada` (`descartada`),
    CONSTRAINT `fk_facturas_erp_inc_carga` FOREIGN KEY (`carga_id`)
        REFERENCES `facturas_erp_cargas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Descartes permanentes ──
-- Sin esta tabla descartar no serviría de nada: la carga siguiente vuelve a
-- evaluar el archivo completo y generaría otra vez la misma incidencia. Al
-- importar, toda incidencia cuya firma esté aquí entra ya marcada como
-- descartada: queda el registro de que la carga la detectó, pero no molesta.
CREATE TABLE IF NOT EXISTS `facturas_erp_descartes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `firma` VARCHAR(220) NOT NULL,
    `tipo` VARCHAR(30) NOT NULL DEFAULT '',
    `proveedor_codigo` VARCHAR(30) NOT NULL DEFAULT '',
    `proveedor_nombre` VARCHAR(255) NOT NULL DEFAULT '',
    `documento` VARCHAR(60) NOT NULL DEFAULT '',
    `motivo` VARCHAR(255) NULL DEFAULT NULL,
    `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_firma` (`firma`),
    KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Facturas del listado ──
CREATE TABLE IF NOT EXISTS `facturas_erp` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clave` VARCHAR(190) NOT NULL COMMENT 'Identidad estable de la factura entre cargas',
    `proveedor_codigo` VARCHAR(30) NOT NULL,
    `proveedor_nombre` VARCHAR(255) NOT NULL,
    `sucursal` VARCHAR(120) NOT NULL DEFAULT '',
    `tipo` VARCHAR(5) NOT NULL DEFAULT 'F' COMMENT 'Prefijo impreso del documento (F-, NC-, ...)',
    `documento` VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'Vacío cuando el reporte no lo imprime',
    `numero_corto` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Últimos 8 dígitos del consecutivo de 20; cruza con facturas_xml',
    `fecha_emision` DATE NULL DEFAULT NULL,
    `fecha_vence` DATE NULL DEFAULT NULL,
    `origen` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'Local (electrónica) / Exter (capturada a mano)',
    `moneda` VARCHAR(10) NOT NULL DEFAULT '',
    `monto` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `saldo` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `saldo_colones` DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'Saldo convertido que imprime el reporte',
    `saldo_anterior` DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'Saldo que tenía antes del último cambio',
    `carga_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Carga que insertó la factura',
    `carga_cambio_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Última carga que cambió el saldo',
    `saldo_cambiado_en` DATETIME NULL DEFAULT NULL,
    `estado` ENUM('pendiente','asignada_semana') NOT NULL DEFAULT 'pendiente',
    `semana_id` INT UNSIGNED NULL DEFAULT NULL,
    `porpagar_listado_id` INT UNSIGNED NULL DEFAULT NULL,
    `asignada_semana_en` DATETIME NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_clave` (`clave`),
    KEY `idx_proveedor` (`proveedor_codigo`),
    KEY `idx_saldo` (`saldo`),
    KEY `idx_numero_corto` (`numero_corto`),
    KEY `idx_documento` (`documento`),
    KEY `idx_sucursal` (`sucursal`),
    KEY `idx_estado` (`estado`),
    KEY `idx_semana` (`semana_id`),
    KEY `idx_porpagar_listado` (`porpagar_listado_id`),
    CONSTRAINT `fk_facturas_erp_carga` FOREIGN KEY (`carga_id`)
        REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
