-- =========================================
-- ESTRUCTURA DE LA BASE DE DATOS
-- Sistema: Nexo Fiscal
-- Motor: MariaDB 11.4 LTS · utf8mb4 / utf8mb4_unicode_ci
-- =========================================
--
-- ESTE ARCHIVO SE GENERA, NO SE EDITA A MANO
--
-- Es el volcado de la estructura de la base de trabajo, con todas las
-- migraciones de `database/` aplicadas. Para regenerarlo:
--
--   mysqldump -h SERVIDOR -u USUARIO -p --no-data --skip-comments \
--             --skip-dump-date --compact bd_xmlconcilia
--
-- y pegar el resultado debajo de esta cabecera.
--
-- POR QUÉ SE REHIZO
--
-- La versión anterior describía una base que ya no existe. Creaba
-- `gastos_raw`, `gastos_consolidados`, `conciliaciones`, `catalogo_estados` y
-- la vista `v_conciliaciones_completas` —todas retiradas por
-- `migration_retirar_conciliacion_gastos.sql`— y no tenía ninguna de las
-- tablas de correo, notas de crédito, devoluciones, pagos ni seguimiento, que
-- son la mayor parte del sistema de hoy.
--
-- Y empezaba con `DROP DATABASE IF EXISTS bd_xmlconcilia;`. Correrlo para
-- montar una computadora nueva no solo armaba la base equivocada: si se
-- apuntaba sin querer al servidor, borraba la buena. Ese DROP ya no está, y
-- la base se crea con IF NOT EXISTS.
--
-- CÓMO SE MONTA UNA COMPUTADORA NUEVA
--
-- Con este archivo basta: trae la estructura completa y al día. Las
-- migraciones de `database/` son para las copias que YA existen y hay que
-- poner al día; en una base recién creada con este archivo no hay que aplicar
-- ninguna. `php cli/diagnostico.php` lo confirma.
--
-- No trae datos: ni empresas, ni usuarios, ni roles. Eso se crea desde la
-- aplicación.
-- =========================================

CREATE DATABASE IF NOT EXISTS `bd_xmlconcilia`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `bd_xmlconcilia`;

SET FOREIGN_KEY_CHECKS = 0;

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `almacen_copias_pago` (
  `factura_id` int(10) unsigned NOT NULL,
  `firma` char(40) NOT NULL,
  `ruta_xml` varchar(700) DEFAULT NULL,
  `ruta_pdf` varchar(700) DEFAULT NULL,
  `copiado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`factura_id`),
  CONSTRAINT `fk_copia_pago_factura` FOREIGN KEY (`factura_id`) REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `almacen_delta` (
  `almacen` varchar(20) NOT NULL,
  `drive_id` varchar(191) NOT NULL DEFAULT '',
  `delta_link` text DEFAULT NULL,
  `cursor_revision` int(10) unsigned NOT NULL DEFAULT 0,
  `vuelta_completa_en` datetime DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`almacen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `almacen_migracion` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `tabla` varchar(30) NOT NULL,
  `fila_id` int(10) unsigned NOT NULL,
  `campo` varchar(20) NOT NULL,
  `ruta` varchar(700) NOT NULL,
  `sha256` char(64) DEFAULT NULL,
  `tamano` bigint(20) unsigned DEFAULT NULL,
  `estado` varchar(15) NOT NULL DEFAULT 'inventariado',
  `item_id` varchar(128) DEFAULT NULL,
  `etag` varchar(128) DEFAULT NULL,
  `intentos` int(10) unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migracion_archivo` (`tabla`,`fila_id`,`campo`),
  KEY `idx_migracion_estado` (`sociedad_id`,`estado`,`id`),
  CONSTRAINT `fk_migracion_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `almacen_pendientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `accion` varchar(20) NOT NULL DEFAULT 'borrar',
  `ruta` varchar(700) NOT NULL DEFAULT '',
  `item_id` varchar(128) NOT NULL DEFAULT '',
  `motivo` varchar(255) DEFAULT NULL,
  `intentos` int(10) unsigned NOT NULL DEFAULT 0,
  `error` varchar(500) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `intentado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pendientes_intentos` (`intentos`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bitacora` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `usuario_nombre` varchar(120) DEFAULT NULL,
  `rol_nombre` varchar(60) DEFAULT NULL,
  `sociedad_id` int(10) unsigned DEFAULT NULL,
  `modulo` varchar(40) NOT NULL DEFAULT 'general',
  `accion` varchar(160) NOT NULL,
  `permiso` varchar(60) DEFAULT NULL,
  `entidad` varchar(60) DEFAULT NULL,
  `entidad_id` varchar(60) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `metodo` varchar(8) NOT NULL DEFAULT 'GET',
  `ruta` varchar(190) NOT NULL DEFAULT '',
  `ip` varchar(45) DEFAULT NULL,
  `resultado` enum('ok','denegado','error') NOT NULL DEFAULT 'ok',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bitacora_fecha` (`creado_en`),
  KEY `idx_bitacora_usuario` (`usuario_id`,`creado_en`),
  KEY `idx_bitacora_modulo` (`modulo`,`creado_en`),
  KEY `idx_bitacora_resultado` (`resultado`,`creado_en`),
  KEY `idx_bitacora_entidad` (`entidad`,`entidad_id`),
  KEY `fk_bitacora_sociedad` (`sociedad_id`),
  CONSTRAINT `fk_bitacora_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bitacora_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_bandeja` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL DEFAULT 0,
  `sociedad_id` int(10) unsigned NOT NULL COMMENT 'Empresa dueña. Obligatoria: sin ella la unicidad no deduplica',
  `uid_correo` varchar(64) NOT NULL COMMENT 'uidvalidity:uid del mensaje IMAP (dedup)',
  `remitente` varchar(255) DEFAULT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `fecha_correo` datetime DEFAULT NULL,
  `archivo_xml` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/BANDEJA/xml)',
  `archivo_pdf` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/BANDEJA/pdf)',
  `numero_corto` varchar(50) DEFAULT NULL COMMENT 'Número corto extraído del XML',
  `tipo_doc` varchar(4) NOT NULL DEFAULT 'FE',
  `proveedor` varchar(255) DEFAULT NULL,
  `fecha_emision` date DEFAULT NULL,
  `total` decimal(15,2) DEFAULT NULL,
  `hash_xml` char(64) NOT NULL DEFAULT '' COMMENT 'sha256 del XML. Vacío = no se pudo leer (antes era NULL, y dos NULL no son iguales)',
  `estado` enum('pendiente','importada','descartada','ya_existe','rechazada','otra_cedula') NOT NULL DEFAULT 'pendiente',
  `origen` enum('manual','automatica','descargas') NOT NULL DEFAULT 'manual',
  `importacion_id` int(10) unsigned DEFAULT NULL COMMENT 'Importación con la que se procesó',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uid_xml` (`sociedad_id`,`uid_correo`,`hash_xml`),
  KEY `idx_estado` (`estado`),
  KEY `fk_bandeja_importacion` (`importacion_id`),
  KEY `fk_bandeja_cuenta` (`cuenta_id`),
  CONSTRAINT `fk_bandeja_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`),
  CONSTRAINT `fk_bandeja_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bandeja_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_capturas_auto` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL,
  `carpeta` varchar(255) NOT NULL,
  `uidvalidity` bigint(20) unsigned NOT NULL DEFAULT 0,
  `uid` int(10) unsigned NOT NULL,
  `clave` varchar(100) NOT NULL,
  `fecha_correo` datetime DEFAULT NULL,
  `estado` enum('pendiente','procesando','capturado','sin_documentos','error') NOT NULL DEFAULT 'pendiente',
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `documentos` smallint(5) unsigned NOT NULL DEFAULT 0,
  `detalle` varchar(1000) DEFAULT NULL,
  `detectado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `iniciado_en` datetime DEFAULT NULL,
  `terminado_en` datetime DEFAULT NULL,
  `reintentar_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_captura_clave` (`clave`),
  KEY `idx_captura_cuenta_estado` (`cuenta_id`,`estado`,`reintentar_en`,`id`),
  CONSTRAINT `fk_capturas_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_carpetas` (
  `cuenta_id` int(10) unsigned NOT NULL DEFAULT 0,
  `carpeta` varchar(180) NOT NULL,
  `uidvalidity` bigint(20) unsigned NOT NULL DEFAULT 0,
  `ultimo_uid` int(10) unsigned NOT NULL DEFAULT 0,
  `mensajes` int(10) unsigned NOT NULL DEFAULT 0,
  `mensajes_omitidos` int(10) unsigned NOT NULL DEFAULT 0,
  `retencion_dias` int(10) unsigned NOT NULL DEFAULT 0,
  `ultima_sync` datetime DEFAULT NULL,
  `ultimo_cambio` datetime DEFAULT NULL,
  PRIMARY KEY (`cuenta_id`,`carpeta`),
  CONSTRAINT `fk_carpetas_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_cuenta_sociedades` (
  `cuenta_id` int(10) unsigned NOT NULL,
  `sociedad_id` int(10) unsigned NOT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`cuenta_id`,`sociedad_id`),
  KEY `idx_cuenta_sociedad_sociedad` (`sociedad_id`),
  CONSTRAINT `fk_cuenta_sociedad_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cuenta_sociedad_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_cuentas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `host` varchar(255) NOT NULL,
  `puerto` int(10) unsigned NOT NULL DEFAULT 993,
  `usuario` varchar(255) NOT NULL,
  `password` text NOT NULL,
  `carpeta` varchar(100) NOT NULL DEFAULT 'INBOX',
  `dias_atras` int(10) unsigned NOT NULL DEFAULT 0,
  `indice_retencion_dias` int(10) unsigned NOT NULL DEFAULT 1825,
  `solo_no_leidos` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9009 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_incidencias` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lote_id` int(10) unsigned DEFAULT NULL,
  `lote_item_id` bigint(20) unsigned DEFAULT NULL,
  `tipo` varchar(40) NOT NULL,
  `mensaje` varchar(1000) NOT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `metadata` mediumtext DEFAULT NULL,
  `firma` varchar(64) NOT NULL DEFAULT '',
  `descartada` tinyint(1) NOT NULL DEFAULT 0,
  `descartada_en` datetime DEFAULT NULL,
  `descartada_por` int(10) unsigned DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incidencia_lote` (`lote_id`,`creado_en`),
  KEY `fk_correo_incidencia_item` (`lote_item_id`),
  KEY `idx_firma` (`firma`),
  KEY `idx_descartada` (`descartada`),
  KEY `fk_correo_incidencia_usuario` (`descartada_por`),
  CONSTRAINT `fk_correo_incidencia_item` FOREIGN KEY (`lote_item_id`) REFERENCES `correo_lote_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_correo_incidencia_lote` FOREIGN KEY (`lote_id`) REFERENCES `correo_lotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_correo_incidencia_usuario` FOREIGN KEY (`descartada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_incidencias_descartes` (
  `firma` varchar(64) NOT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT '',
  `asunto` varchar(255) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`firma`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_correo_descarte_usuario` (`usuario_id`),
  CONSTRAINT `fk_correo_descarte_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_indice` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL DEFAULT 0,
  `carpeta` varchar(255) NOT NULL,
  `carpeta_nombre` varchar(255) NOT NULL DEFAULT '',
  `uid` int(10) unsigned NOT NULL,
  `uidvalidity` bigint(20) unsigned NOT NULL DEFAULT 0,
  `clave` varchar(64) NOT NULL,
  `remitente` varchar(255) DEFAULT NULL,
  `cc` varchar(1024) DEFAULT NULL,
  `reply_to` varchar(255) DEFAULT NULL,
  `consecutivo` varchar(20) DEFAULT NULL,
  `numero_corto` varchar(10) DEFAULT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `adjuntos` varchar(1024) DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `timestamp` int(10) unsigned NOT NULL DEFAULT 0,
  `adjuntos_pendiente` tinyint(1) GENERATED ALWAYS AS (`adjuntos` is null) STORED,
  `destinatarios_pendientes` tinyint(1) GENERATED ALWAYS AS (`cc` is null or `reply_to` is null) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cuenta_carpeta_uid` (`cuenta_id`,`carpeta`(170),`uidvalidity`,`uid`),
  KEY `idx_cuenta_consecutivo` (`cuenta_id`,`consecutivo`),
  KEY `idx_cuenta_numero_corto` (`cuenta_id`,`numero_corto`),
  KEY `idx_cuenta_timestamp_id` (`cuenta_id`,`timestamp`,`id`),
  KEY `idx_pend_adjuntos` (`cuenta_id`,`adjuntos_pendiente`,`timestamp`,`id`),
  KEY `idx_pend_destinatarios` (`cuenta_id`,`destinatarios_pendientes`,`timestamp`,`id`),
  KEY `idx_cuenta_carpeta_ts` (`cuenta_id`,`carpeta`(170),`timestamp`),
  FULLTEXT KEY `ft_destinatarios` (`remitente`,`cc`,`reply_to`),
  CONSTRAINT `fk_indice_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=407 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_lote_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lote_id` int(10) unsigned NOT NULL,
  `correo_indice_id` int(10) unsigned DEFAULT NULL,
  `carpeta` varchar(255) NOT NULL,
  `uidvalidity` bigint(20) unsigned NOT NULL DEFAULT 0,
  `uid` int(10) unsigned NOT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `remitente` varchar(255) DEFAULT NULL,
  `fecha_correo` datetime DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `documentos_importados` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicados` int(10) unsigned NOT NULL DEFAULT 0,
  `pdf_pendientes` int(10) unsigned NOT NULL DEFAULT 0,
  `detalle` text DEFAULT NULL,
  `iniciado_en` datetime DEFAULT NULL,
  `tomado_por` varchar(40) DEFAULT NULL,
  `procesado_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lote_mensaje` (`lote_id`,`correo_indice_id`),
  KEY `idx_lote_estado_id` (`lote_id`,`estado`,`id`),
  KEY `fk_correo_lote_indice` (`correo_indice_id`),
  CONSTRAINT `fk_correo_lote_indice` FOREIGN KEY (`correo_indice_id`) REFERENCES `correo_indice` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_correo_lote_item` FOREIGN KEY (`lote_id`) REFERENCES `correo_lotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_lotes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cuenta_id` int(10) unsigned NOT NULL,
  `sociedad_id` int(10) unsigned NOT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `carpeta_raiz` varchar(500) NOT NULL,
  `carpetas_json` mediumtext DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `total_mensajes` int(10) unsigned NOT NULL DEFAULT 0,
  `procesados` int(10) unsigned NOT NULL DEFAULT 0,
  `documentos_importados` int(10) unsigned NOT NULL DEFAULT 0,
  `duplicados` int(10) unsigned NOT NULL DEFAULT 0,
  `incidencias` int(10) unsigned NOT NULL DEFAULT 0,
  `pdf_pendientes` int(10) unsigned NOT NULL DEFAULT 0,
  `ultimo_error` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `iniciado_en` datetime DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `terminado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lote_cuenta_fecha` (`cuenta_id`,`fecha_desde`,`fecha_hasta`),
  KEY `idx_lote_estado` (`estado`,`actualizado_en`),
  KEY `fk_correo_lote_sociedad` (`sociedad_id`),
  CONSTRAINT `fk_correo_lote_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `correo_cuentas` (`id`),
  CONSTRAINT `fk_correo_lote_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `correo_procesados` (
  `sociedad_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'empresa que lo trabajó',
  `clave` varchar(100) NOT NULL COMMENT 'c{cuenta}:uidvalidity:uid del mensaje',
  `procesado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sociedad_id`,`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devolucion_lineas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devolucion_id` int(10) unsigned NOT NULL,
  `seccion` varchar(20) DEFAULT NULL COMMENT 'FACTURA|NOTA CANTIDAD|FALTANTE|NOTA COSTO; NULL en formato devolución',
  `codigo` varchar(20) DEFAULT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `cantidad` decimal(16,3) NOT NULL DEFAULT 0.000,
  `costo` decimal(18,5) NOT NULL DEFAULT 0.00000 COMMENT 'Costo neto (boleta) o unitario (devolución)',
  `impuesto` decimal(18,5) NOT NULL DEFAULT 0.00000,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `dif_costo` decimal(18,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dl_devolucion` (`devolucion_id`,`seccion`),
  CONSTRAINT `fk_dl_devolucion` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devolucion_matches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devolucion_id` int(10) unsigned NOT NULL,
  `objetivo` varchar(12) NOT NULL COMMENT 'cantidad|costo|total',
  `monto_esperado` decimal(18,2) NOT NULL DEFAULT 0.00,
  `factura_xml_id` int(10) unsigned DEFAULT NULL COMMENT 'La NC candidata/confirmada',
  `metodo` varchar(15) NOT NULL DEFAULT 'ninguno' COMMENT 'referencia|monto|manual|ninguno',
  `estado` varchar(15) NOT NULL DEFAULT 'sin_nc' COMMENT 'confirmado|sugerido|sin_nc|descartado',
  `monto_nc` decimal(18,2) DEFAULT NULL,
  `diferencia` decimal(18,2) DEFAULT NULL,
  `nc_consolidada` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'La NC referencia varias facturas',
  `motivo` varchar(400) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dm_devolucion` (`devolucion_id`,`objetivo`),
  KEY `idx_dm_nc` (`factura_xml_id`),
  CONSTRAINT `fk_dm_devolucion` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dm_nc` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devoluciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL COMMENT 'Empresa dueña. Obligatoria: una devolución de nadie se le contaba a todas.',
  `tipo` varchar(25) NOT NULL COMMENT 'boleta_local | devolucion_proveedor',
  `numero` varchar(20) NOT NULL COMMENT 'No. de boleta o de devolución en el ERP',
  `sucursal` varchar(120) DEFAULT NULL,
  `bodega` varchar(120) DEFAULT NULL,
  `numero_factura` varchar(20) DEFAULT NULL COMMENT 'Consecutivo 20 díg. de la factura (solo boleta)',
  `factura_xml_id` int(10) unsigned DEFAULT NULL COMMENT 'Factura local hallada por ese consecutivo',
  `proveedor_codigo_erp` varchar(20) DEFAULT NULL,
  `proveedor_nombre_erp` varchar(255) DEFAULT NULL,
  `proveedor_id` int(10) unsigned DEFAULT NULL COMMENT 'Proveedor local resuelto por nombre',
  `fecha` date DEFAULT NULL,
  `estado_erp` varchar(30) DEFAULT NULL,
  `usuario_erp` varchar(120) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `cantidad_total` decimal(16,3) NOT NULL DEFAULT 0.000,
  `total` decimal(18,2) DEFAULT NULL COMMENT 'Total con IVA (formato devolución)',
  `nc_esperada_cantidad` decimal(18,2) DEFAULT NULL COMMENT 'NC esperada por faltante (boleta)',
  `nc_esperada_costo` decimal(18,2) DEFAULT NULL COMMENT 'NC esperada por dif. de costo (boleta)',
  `estado` varchar(15) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente|sin_nc|parcial|verificada',
  `archivo_pdf` varchar(255) NOT NULL,
  `ruta_pdf` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/DEVOLUCIONES)',
  `hash_pdf` varchar(64) NOT NULL,
  `advertencias` text DEFAULT NULL COMMENT 'JSON de advertencias del parser',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `verificado_en` datetime DEFAULT NULL,
  `almacen` varchar(10) DEFAULT NULL COMMENT 'NULL = disco (histórico) | sharepoint',
  `item_pdf_id` varchar(128) DEFAULT NULL COMMENT 'Id permanente del PDF en SharePoint',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dev_hash` (`sociedad_id`,`hash_pdf`),
  KEY `idx_dev_tipo_numero` (`tipo`,`numero`),
  KEY `idx_dev_estado` (`estado`),
  KEY `idx_dev_proveedor` (`proveedor_id`),
  KEY `idx_dev_fecha` (`fecha`),
  KEY `idx_dev_factura` (`factura_xml_id`),
  CONSTRAINT `fk_dev_factura` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dev_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dev_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(190) NOT NULL,
  `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Heredada de la carga que la trajo',
  `proveedor_codigo` varchar(30) NOT NULL,
  `proveedor_nombre` varchar(255) NOT NULL,
  `sucursal` varchar(120) NOT NULL DEFAULT '',
  `tipo` varchar(5) NOT NULL DEFAULT 'F',
  `documento` varchar(60) NOT NULL DEFAULT '',
  `numero_corto` varchar(20) DEFAULT NULL,
  `fecha_emision` date DEFAULT NULL,
  `fecha_vence` date DEFAULT NULL,
  `origen` varchar(10) NOT NULL DEFAULT '',
  `moneda` varchar(10) NOT NULL DEFAULT '',
  `monto` decimal(18,2) NOT NULL DEFAULT 0.00,
  `saldo` decimal(18,2) NOT NULL DEFAULT 0.00,
  `saldo_colones` decimal(18,2) DEFAULT NULL,
  `saldo_anterior` decimal(18,2) DEFAULT NULL,
  `carga_id` int(10) unsigned DEFAULT NULL,
  `carga_cambio_id` int(10) unsigned DEFAULT NULL,
  `saldo_cambiado_en` datetime DEFAULT NULL,
  `estado` enum('pendiente','asignada_pago') NOT NULL DEFAULT 'pendiente',
  `pago_id` int(10) unsigned DEFAULT NULL,
  `asignada_pago_en` datetime DEFAULT NULL,
  `saldo_pago` decimal(18,2) DEFAULT NULL,
  `factura_xml_id` int(10) unsigned DEFAULT NULL,
  `estado_respaldo` enum('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
  `diferencia` decimal(18,2) DEFAULT NULL,
  `score_numero` decimal(5,1) DEFAULT NULL,
  `score_proveedor` decimal(5,1) DEFAULT NULL,
  `match_manual` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_clave` (`sociedad_id`,`clave`),
  KEY `idx_proveedor` (`proveedor_codigo`),
  KEY `idx_saldo` (`saldo`),
  KEY `idx_numero_corto` (`numero_corto`),
  KEY `idx_documento` (`documento`),
  KEY `idx_sucursal` (`sucursal`),
  KEY `idx_estado` (`estado`),
  KEY `idx_facturas_erp_sociedad_doc` (`sociedad_id`,`documento`),
  KEY `idx_factura_xml` (`factura_xml_id`),
  KEY `idx_estado_respaldo` (`estado_respaldo`),
  KEY `idx_pago` (`pago_id`),
  KEY `fk_erp_carga` (`carga_id`),
  KEY `fk_erp_carga_cambio` (`carga_cambio_id`),
  CONSTRAINT `fk_erp_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_carga_cambio` FOREIGN KEY (`carga_cambio_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_factura_xml` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp_cargas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `archivo_origen` varchar(255) NOT NULL,
  `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Sociedad a la que pertenece el reporte cargado',
  `impreso_en` datetime DEFAULT NULL,
  `rango_texto` varchar(255) DEFAULT NULL,
  `filas_leidas` int(10) unsigned NOT NULL DEFAULT 0,
  `insertadas` int(10) unsigned NOT NULL DEFAULT 0,
  `actualizadas` int(10) unsigned NOT NULL DEFAULT 0,
  `sin_cambio` int(10) unsigned NOT NULL DEFAULT 0,
  `totales_verificados` int(10) unsigned NOT NULL DEFAULT 0,
  `totales_descuadrados` int(10) unsigned NOT NULL DEFAULT 0,
  `cuadre_ok` tinyint(1) NOT NULL DEFAULT 0,
  `incidencias` int(10) unsigned NOT NULL DEFAULT 0,
  `advertencias` text DEFAULT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_creado` (`creado_en`),
  KEY `idx_facturas_erp_cargas_sociedad` (`sociedad_id`),
  KEY `fk_erp_carga_usuario` (`usuario_id`),
  CONSTRAINT `fk_erp_carga_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_carga_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp_descartes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned DEFAULT NULL,
  `firma` varchar(220) NOT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT '',
  `proveedor_codigo` varchar(30) NOT NULL DEFAULT '',
  `proveedor_nombre` varchar(255) NOT NULL DEFAULT '',
  `documento` varchar(60) NOT NULL DEFAULT '',
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_firma` (`sociedad_id`,`firma`),
  KEY `idx_tipo` (`tipo`),
  KEY `fk_erp_descarte_usuario` (`usuario_id`),
  CONSTRAINT `fk_erp_descarte_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_descarte_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp_incidencias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `carga_id` int(10) unsigned NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `severidad` varchar(10) NOT NULL DEFAULT 'aviso',
  `proveedor_codigo` varchar(30) NOT NULL DEFAULT '',
  `proveedor_nombre` varchar(255) NOT NULL DEFAULT '',
  `documento` varchar(60) NOT NULL DEFAULT '',
  `clave` varchar(190) NOT NULL DEFAULT '',
  `fecha_emision` date DEFAULT NULL,
  `monto` decimal(18,2) DEFAULT NULL,
  `saldo_anterior` decimal(18,2) DEFAULT NULL,
  `saldo_nuevo` decimal(18,2) DEFAULT NULL,
  `detalle` varchar(500) NOT NULL DEFAULT '',
  `firma` varchar(220) NOT NULL DEFAULT '',
  `descartada` tinyint(1) NOT NULL DEFAULT 0,
  `descartada_en` datetime DEFAULT NULL,
  `descartada_por` int(10) unsigned DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_carga` (`carga_id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_severidad` (`severidad`),
  KEY `idx_proveedor` (`proveedor_codigo`),
  KEY `idx_documento` (`documento`),
  KEY `idx_firma` (`firma`),
  KEY `idx_descartada` (`descartada`),
  KEY `fk_erp_incidencia_usuario` (`descartada_por`),
  CONSTRAINT `fk_erp_incidencia_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_incidencia_usuario` FOREIGN KEY (`descartada_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp_revision` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `carga_id` int(10) unsigned DEFAULT NULL,
  `contenedor_id` int(10) unsigned DEFAULT NULL,
  `fila_origen` int(10) unsigned NOT NULL DEFAULT 0,
  `firma` varchar(40) NOT NULL,
  `grupo` varchar(190) NOT NULL DEFAULT '',
  `motivo` varchar(400) NOT NULL DEFAULT '',
  `celdas` longtext DEFAULT NULL,
  `campos` longtext DEFAULT NULL,
  `estado` enum('pendiente','incluida','descartada') NOT NULL DEFAULT 'pendiente',
  `resultado_id` int(10) unsigned DEFAULT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `resuelta_en` datetime DEFAULT NULL,
  `resuelta_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rev_pendientes` (`sociedad_id`,`estado`,`id`),
  KEY `idx_rev_carga` (`carga_id`),
  KEY `idx_rev_firma` (`firma`),
  KEY `idx_rev_grupo` (`sociedad_id`,`estado`,`grupo`),
  KEY `fk_erp_revision_usuario` (`resuelta_por`),
  KEY `fk_erp_revision_resultado` (`resultado_id`),
  CONSTRAINT `fk_erp_revision_carga` FOREIGN KEY (`carga_id`) REFERENCES `facturas_erp_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_revision_resultado` FOREIGN KEY (`resultado_id`) REFERENCES `facturas_erp` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_revision_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_revision_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=152 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_erp_revision_memoria` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `firma` varchar(40) NOT NULL,
  `accion` enum('incluir','descartar') NOT NULL,
  `campos` longtext DEFAULT NULL,
  `celdas` longtext DEFAULT NULL,
  `mapa` longtext DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `veces_aplicada` int(10) unsigned NOT NULL DEFAULT 0,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rev_memoria` (`sociedad_id`,`firma`),
  KEY `fk_erp_memoria_usuario` (`usuario_id`),
  CONSTRAINT `fk_erp_memoria_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_memoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_xml` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `importacion_id` int(10) unsigned DEFAULT NULL COMMENT 'FK a importaciones',
  `pago_id` int(10) unsigned DEFAULT NULL,
  `consecutivo_completo` varchar(255) NOT NULL COMMENT 'Folio fiscal o UUID completo del XML',
  `clave` varchar(60) DEFAULT NULL COMMENT 'Clave Hacienda (50 dígitos)',
  `tipo_documento` varchar(4) DEFAULT NULL COMMENT 'FE/NC/ND (NULL = legado, se asume FE)',
  `receptor_id` varchar(30) DEFAULT NULL COMMENT 'Cédula del receptor del XML',
  `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Sociedad dueña del documento (receptor del XML)',
  `numero_factura_asistente` varchar(8) NOT NULL COMMENT 'Numero XML corto normalizado a 8 digitos',
  `proveedor_id` int(10) unsigned NOT NULL COMMENT 'FK a proveedores',
  `fecha_emision` date NOT NULL COMMENT 'Fecha de emisión de la factura',
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Base gravable',
  `iva` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto de IVA',
  `total` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Total de la factura',
  `moneda` varchar(3) NOT NULL DEFAULT 'CRC' COMMENT 'Código de moneda ISO 4217',
  `tipo_cambio` decimal(18,5) DEFAULT NULL COMMENT 'TipoCambio del XML; NULL en colones o si no se leyó',
  `tipo_comprobante` varchar(20) DEFAULT NULL COMMENT 'I=Ingreso, E=Egreso, etc.',
  `archivo_xml` varchar(255) NOT NULL COMMENT 'Nombre del archivo XML',
  `ruta_xml` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida de documentos',
  `ruta_pdf` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida de documentos',
  `archivo_pdf` varchar(255) DEFAULT NULL,
  `hash_pdf` varchar(64) DEFAULT NULL,
  `estado_pdf` varchar(30) NOT NULL DEFAULT 'pendiente',
  `archivo_faltante_en` datetime DEFAULT NULL COMMENT 'Desde cuando se sabe que el archivo de la ruta no esta',
  `correo_cuenta_id` int(10) unsigned DEFAULT NULL,
  `correo_carpeta` varchar(255) DEFAULT NULL,
  `correo_uidvalidity` bigint(20) unsigned DEFAULT NULL,
  `correo_uid` int(10) unsigned DEFAULT NULL,
  `fecha_correo` datetime DEFAULT NULL,
  `archivado_en` datetime DEFAULT NULL,
  `detalle_extraido_en` datetime DEFAULT NULL COMMENT 'Última extracción de referencias/líneas; NULL = pendiente',
  `hash_xml` varchar(64) DEFAULT NULL COMMENT 'SHA-256 del contenido XML (opcional)',
  `metadata` text DEFAULT NULL COMMENT 'JSON con datos adicionales del XML',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `almacen` varchar(10) DEFAULT NULL COMMENT 'NULL = disco (histórico) | sharepoint',
  `item_xml_id` varchar(128) DEFAULT NULL COMMENT 'Id permanente del XML en SharePoint',
  `item_pdf_id` varchar(128) DEFAULT NULL COMMENT 'Id permanente del PDF en SharePoint',
  `etag_xml` varchar(128) DEFAULT NULL COMMENT 'cTag del XML: si cambia, alguien lo editó',
  `etag_pdf` varchar(128) DEFAULT NULL COMMENT 'cTag del PDF',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_consecutivo` (`consecutivo_completo`(191),`proveedor_id`,`fecha_emision`),
  KEY `idx_numero_factura` (`numero_factura_asistente`),
  KEY `idx_proveedor` (`proveedor_id`),
  KEY `idx_importacion` (`importacion_id`),
  KEY `idx_fecha_proveedor` (`fecha_emision`,`proveedor_id`),
  KEY `idx_facturas_fecha_estado` (`fecha_emision`,`id`),
  KEY `idx_hash_xml` (`hash_xml`),
  KEY `idx_numero_proveedor_norm` (`numero_factura_asistente`),
  KEY `idx_tipo_fecha` (`tipo_documento`,`fecha_emision`,`id`),
  KEY `idx_estado_pdf` (`estado_pdf`),
  KEY `idx_correo_origen` (`correo_cuenta_id`,`correo_uidvalidity`,`correo_uid`),
  KEY `idx_facturas_detalle_pend` (`tipo_documento`,`detalle_extraido_en`),
  KEY `idx_facturas_xml_sociedad_tipo` (`sociedad_id`,`tipo_documento`),
  KEY `idx_archivo_faltante` (`archivo_faltante_en`),
  KEY `idx_pago` (`pago_id`),
  KEY `idx_item_xml` (`item_xml_id`),
  KEY `idx_item_pdf` (`item_pdf_id`),
  KEY `idx_almacen_id` (`almacen`,`id`),
  CONSTRAINT `fk_facturas_correo_cuenta` FOREIGN KEY (`correo_cuenta_id`) REFERENCES `correo_cuentas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facturas_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_facturas_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_facturas_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_facturas_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comprobantes electrónicos (XML de Hacienda): facturas, notas de crédito y de débito';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_xml_lineas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `factura_xml_id` int(10) unsigned NOT NULL,
  `numero_linea` int(10) unsigned NOT NULL,
  `codigo_cabys` varchar(20) DEFAULT NULL,
  `codigo_comercial` varchar(30) DEFAULT NULL COMMENT 'Código del proveedor (a veces EAN)',
  `detalle` varchar(255) DEFAULT NULL COMMENT 'Descripción del proveedor, no la interna',
  `cantidad` decimal(16,3) NOT NULL DEFAULT 0.000,
  `unidad` varchar(15) DEFAULT NULL,
  `precio_unitario` decimal(18,5) NOT NULL DEFAULT 0.00000,
  `monto_descuento` decimal(18,5) NOT NULL DEFAULT 0.00000,
  `subtotal` decimal(18,5) NOT NULL DEFAULT 0.00000,
  `impuesto` decimal(18,5) NOT NULL DEFAULT 0.00000,
  `total_linea` decimal(18,5) NOT NULL DEFAULT 0.00000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fxl_factura_linea` (`factura_xml_id`,`numero_linea`),
  KEY `idx_fxl_codigo_comercial` (`codigo_comercial`),
  CONSTRAINT `fk_fxl_factura` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_xml_referencias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `factura_xml_id` int(10) unsigned NOT NULL,
  `tipo_doc_ref` varchar(4) DEFAULT NULL COMMENT 'TipoDoc del bloque (01=FE, 08=otros...)',
  `numero_ref` varchar(60) NOT NULL COMMENT 'Numero referenciado tal cual viene en el XML',
  `clave_ref` varchar(50) DEFAULT NULL COMMENT 'Solo si numero_ref es una clave de 50 dígitos',
  `consecutivo_ref` varchar(20) DEFAULT NULL COMMENT 'Consecutivo (20 díg.) derivado de la clave',
  `fecha_ref` date DEFAULT NULL,
  `codigo_ref` varchar(4) DEFAULT NULL COMMENT 'Codigo de referencia (01=anula, 02=corrige...)',
  `razon` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fxr_factura_numero` (`factura_xml_id`,`numero_ref`),
  KEY `idx_fxr_clave` (`clave_ref`),
  KEY `idx_fxr_consecutivo` (`consecutivo_ref`),
  CONSTRAINT `fk_fxr_factura` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `importacion_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `importacion_id` int(10) unsigned NOT NULL,
  `archivo_original` varchar(255) NOT NULL,
  `archivo_guardado` varchar(255) DEFAULT NULL,
  `ruta_archivo` varchar(500) NOT NULL COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/IMPORTACIONES)',
  `extension` varchar(10) NOT NULL,
  `tamano` bigint(20) unsigned NOT NULL DEFAULT 0,
  `estado` varchar(30) NOT NULL DEFAULT 'pendiente',
  `intentos` int(10) unsigned NOT NULL DEFAULT 0,
  `factura_id` int(10) unsigned DEFAULT NULL,
  `error_texto` text DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_importacion_estado` (`importacion_id`,`estado`,`id`),
  KEY `idx_estado_updated` (`estado`,`updated_at`),
  KEY `fk_items_factura` (`factura_id`),
  CONSTRAINT `fk_items_factura` FOREIGN KEY (`factura_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_items_importacion` FOREIGN KEY (`importacion_id`) REFERENCES `importaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `importaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tipo` enum('xml','gastos') NOT NULL COMMENT 'Tipo de importación',
  `archivo_origen` varchar(255) NOT NULL COMMENT 'Nombre del archivo importado',
  `ruta_archivo` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa a la carpeta compartida (_TRABAJO/IMPORTACIONES)',
  `total_registros` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Total de registros procesados',
  `registros_exitosos` int(10) unsigned NOT NULL DEFAULT 0,
  `registros_fallidos` int(10) unsigned NOT NULL DEFAULT 0,
  `errores` text DEFAULT NULL COMMENT 'JSON con detalles de errores',
  `fecha_importacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `metadata` text DEFAULT NULL COMMENT 'JSON con información adicional',
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_fecha` (`fecha_importacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de importaciones masivas de XML';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_cargas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `listado_id` int(10) unsigned DEFAULT NULL,
  `sociedad_id` int(10) unsigned NOT NULL,
  `listado_legacy_id` int(10) unsigned DEFAULT NULL,
  `archivo_origen` varchar(255) NOT NULL,
  `archivo_ruta` varchar(500) DEFAULT NULL,
  `archivo_hash` char(64) NOT NULL,
  `empresa_reporte` varchar(255) DEFAULT NULL,
  `periodo_desde` date DEFAULT NULL,
  `periodo_hasta` date DEFAULT NULL,
  `filas_leidas` int(10) unsigned NOT NULL DEFAULT 0,
  `insertadas` int(10) unsigned NOT NULL DEFAULT 0,
  `actualizadas` int(10) unsigned NOT NULL DEFAULT 0,
  `sin_cambio` int(10) unsigned NOT NULL DEFAULT 0,
  `recuperadas` int(10) unsigned NOT NULL DEFAULT 0,
  `filas_invalidas` int(10) unsigned NOT NULL DEFAULT 0,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nc_carga_legacy` (`listado_legacy_id`),
  KEY `idx_nc_carga_sociedad` (`sociedad_id`,`creado_en`),
  KEY `idx_nc_carga_listado` (`listado_id`),
  KEY `idx_nc_carga_hash` (`archivo_hash`),
  KEY `fk_nc_carga_usuario` (`usuario_id`),
  CONSTRAINT `fk_nc_carga_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_carga_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`),
  CONSTRAINT `fk_nc_carga_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `verificacion_id` bigint(20) unsigned NOT NULL,
  `listado_id` int(10) unsigned NOT NULL,
  `linea_id` int(10) unsigned DEFAULT NULL,
  `fila_origen` int(10) unsigned DEFAULT NULL,
  `documento` varchar(255) DEFAULT NULL,
  `proveedor_nombre` varchar(255) DEFAULT NULL,
  `nc_proveedor` varchar(100) DEFAULT NULL,
  `moneda` char(3) DEFAULT NULL,
  `estado_anterior` varchar(30) NOT NULL,
  `estado_nuevo` varchar(30) NOT NULL,
  `factura_xml_id_anterior` int(10) unsigned DEFAULT NULL,
  `factura_xml_id_nuevo` int(10) unsigned DEFAULT NULL,
  `diferencia_anterior` decimal(18,2) DEFAULT NULL,
  `diferencia_nueva` decimal(18,2) DEFAULT NULL,
  `motivo_anterior` varchar(255) DEFAULT NULL,
  `motivo_nuevo` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nc_historial_verificacion` (`verificacion_id`,`id`),
  KEY `idx_nc_historial_linea` (`linea_id`,`fecha`),
  KEY `fk_nc_historial_listado` (`listado_id`),
  CONSTRAINT `fk_nc_historial_linea` FOREIGN KEY (`linea_id`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_historial_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nc_historial_verificacion` FOREIGN KEY (`verificacion_id`) REFERENCES `notas_credito_verificaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_lineas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `listado_id` int(10) unsigned NOT NULL,
  `listado_origen_id` int(10) unsigned DEFAULT NULL,
  `fila_origen` int(10) unsigned NOT NULL,
  `proveedor_codigo` varchar(50) DEFAULT NULL,
  `proveedor_nombre` varchar(255) NOT NULL,
  `sucursal` varchar(150) DEFAULT NULL,
  `documento` varchar(255) NOT NULL,
  `clase` enum('directa','costo','cambio','ajuste','revisar') NOT NULL DEFAULT 'revisar' COMMENT 'Qué clase de nota es, según la forma de su número',
  `fecha` date NOT NULL,
  `nc_proveedor` varchar(100) DEFAULT NULL,
  `fecha_nc_proveedor` date DEFAULT NULL,
  `entrada_asociada` varchar(255) DEFAULT NULL,
  `moneda` char(3) NOT NULL,
  `monto` decimal(18,2) NOT NULL,
  `saldo` decimal(18,2) NOT NULL DEFAULT 0.00,
  `saldo_anterior` decimal(18,2) DEFAULT NULL,
  `monto_conversion` decimal(18,2) NOT NULL DEFAULT 0.00,
  `datos_origen` longtext DEFAULT NULL,
  `carga_id` int(10) unsigned DEFAULT NULL,
  `carga_cambio_id` int(10) unsigned DEFAULT NULL,
  `saldo_cambiado_en` datetime DEFAULT NULL,
  `factura_xml_id` int(10) unsigned DEFAULT NULL,
  `factura_erp_id` int(10) unsigned DEFAULT NULL,
  `estado_aplicacion` enum('aplicable','factura_liquidada','aplicada','sin_factura','sin_referencia','no_aplica') NOT NULL DEFAULT 'no_aplica',
  `estado` enum('sin_respaldo','coincide','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
  `diferencia` decimal(18,2) DEFAULT NULL,
  `metodo_match` enum('ninguno','numero','referencia','atributos','manual') NOT NULL DEFAULT 'ninguno',
  `score_proveedor` decimal(5,1) DEFAULT NULL,
  `match_manual` tinyint(1) NOT NULL DEFAULT 0,
  `bloqueo_automatico` tinyint(1) NOT NULL DEFAULT 0,
  `motivo_match` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nc_xml_por_listado` (`listado_id`,`factura_xml_id`),
  KEY `idx_nc_linea_listado_estado` (`listado_id`,`estado`),
  KEY `idx_nc_linea_documento` (`documento`),
  KEY `idx_nc_linea_nc_proveedor` (`nc_proveedor`),
  KEY `idx_nc_linea_factura_xml` (`factura_xml_id`),
  KEY `idx_nc_lineas_clase` (`listado_id`,`clase`,`estado`),
  KEY `idx_nc_linea_carga` (`carga_id`),
  KEY `idx_nc_linea_carga_cambio` (`carga_cambio_id`),
  KEY `idx_nc_linea_listado_origen` (`listado_origen_id`),
  KEY `idx_nc_linea_factura_erp` (`factura_erp_id`),
  KEY `idx_nc_linea_estado_aplicacion` (`estado_aplicacion`),
  CONSTRAINT `fk_nc_linea_carga` FOREIGN KEY (`carga_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_linea_carga_cambio` FOREIGN KEY (`carga_cambio_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_linea_factura` FOREIGN KEY (`factura_xml_id`) REFERENCES `facturas_xml` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_linea_factura_erp` FOREIGN KEY (`factura_erp_id`) REFERENCES `facturas_erp` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_linea_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nc_linea_listado_origen` FOREIGN KEY (`listado_origen_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=162 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_listados` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `empresa_reporte` varchar(255) DEFAULT NULL,
  `periodo_desde` date DEFAULT NULL,
  `periodo_hasta` date DEFAULT NULL,
  `archivo_origen` varchar(255) NOT NULL,
  `archivo_ruta` varchar(500) NOT NULL,
  `archivo_hash` char(64) NOT NULL,
  `total_lineas` int(10) unsigned NOT NULL DEFAULT 0,
  `fecha_subida` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nc_listado_sociedad` (`sociedad_id`,`fecha_subida`),
  KEY `idx_nc_archivo_hash` (`archivo_hash`),
  CONSTRAINT `fk_nc_listado_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_revision` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `carga_id` int(10) unsigned DEFAULT NULL,
  `contenedor_id` int(10) unsigned DEFAULT NULL,
  `fila_origen` int(10) unsigned NOT NULL DEFAULT 0,
  `firma` varchar(40) NOT NULL,
  `grupo` varchar(190) NOT NULL DEFAULT '',
  `motivo` varchar(400) NOT NULL DEFAULT '',
  `celdas` longtext DEFAULT NULL,
  `campos` longtext DEFAULT NULL,
  `estado` enum('pendiente','incluida','descartada') NOT NULL DEFAULT 'pendiente',
  `resultado_id` int(10) unsigned DEFAULT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `resuelta_en` datetime DEFAULT NULL,
  `resuelta_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rev_pendientes` (`sociedad_id`,`estado`,`id`),
  KEY `idx_rev_carga` (`carga_id`),
  KEY `idx_rev_firma` (`firma`),
  KEY `idx_rev_grupo` (`sociedad_id`,`estado`,`grupo`),
  KEY `fk_nc_revision_usuario` (`resuelta_por`),
  KEY `fk_nc_revision_contenedor` (`contenedor_id`),
  KEY `fk_nc_revision_resultado` (`resultado_id`),
  CONSTRAINT `fk_nc_revision_carga` FOREIGN KEY (`carga_id`) REFERENCES `notas_credito_cargas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_revision_contenedor` FOREIGN KEY (`contenedor_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_revision_resultado` FOREIGN KEY (`resultado_id`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nc_revision_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nc_revision_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_revision_memoria` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL,
  `firma` varchar(40) NOT NULL,
  `accion` enum('incluir','descartar') NOT NULL,
  `campos` longtext DEFAULT NULL,
  `celdas` longtext DEFAULT NULL,
  `mapa` longtext DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `veces_aplicada` int(10) unsigned NOT NULL DEFAULT 0,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rev_memoria` (`sociedad_id`,`firma`),
  KEY `fk_nc_memoria_usuario` (`usuario_id`),
  CONSTRAINT `fk_nc_memoria_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nc_memoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notas_credito_verificaciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `listado_id` int(10) unsigned NOT NULL,
  `origen` varchar(30) NOT NULL DEFAULT 'automatico',
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  `coincide` int(10) unsigned NOT NULL DEFAULT 0,
  `con_diferencia` int(10) unsigned NOT NULL DEFAULT 0,
  `sin_respaldo` int(10) unsigned NOT NULL DEFAULT 0,
  `cantidad_cambios` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_nc_verificacion_listado_fecha` (`listado_id`,`fecha_inicio`),
  CONSTRAINT `fk_nc_verificacion_listado` FOREIGN KEY (`listado_id`) REFERENCES `notas_credito_listados` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0 = aviso del grupo',
  `tipo` varchar(40) NOT NULL,
  `severidad` varchar(10) NOT NULL DEFAULT 'media',
  `titulo` varchar(200) NOT NULL,
  `detalle` text DEFAULT NULL,
  `firma` varchar(190) NOT NULL,
  `veces` int(10) unsigned NOT NULL DEFAULT 1,
  `ref_tabla` varchar(40) NOT NULL DEFAULT '',
  `ref_clave` varchar(60) NOT NULL DEFAULT '',
  `datos` text DEFAULT NULL COMMENT 'JSON con lo que necesite la pantalla de revisión',
  `estado` enum('pendiente','resuelta','descartada') NOT NULL DEFAULT 'pendiente',
  `resuelta_por` int(10) unsigned DEFAULT NULL,
  `resuelta_en` datetime DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `creada_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizada_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_firma` (`sociedad_id`,`firma`),
  KEY `idx_estado` (`estado`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_creada` (`creada_en`),
  KEY `idx_sociedad` (`sociedad_id`,`estado`),
  KEY `fk_notificacion_usuario` (`resuelta_por`),
  CONSTRAINT `fk_notificacion_usuario` FOREIGN KEY (`resuelta_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Avisos compartidos: la campana de la barra superior';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pagos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sociedad_id` int(10) unsigned DEFAULT NULL,
  `nombre` varchar(100) NOT NULL,
  `fecha_pago` date DEFAULT NULL,
  `carpeta_pago` varchar(100) DEFAULT NULL,
  `archivo_origen` varchar(255) DEFAULT NULL,
  `total_lineas` int(10) unsigned NOT NULL DEFAULT 0,
  `archivo_subido_en` datetime DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pagos_fecha` (`sociedad_id`,`fecha_pago`),
  CONSTRAINT `fk_pagos_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `porpagar_facturas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `listado_id` int(10) unsigned NOT NULL,
  `fecha` date DEFAULT NULL COMMENT 'Fecha del listado, solo informativa',
  `numero` varchar(100) NOT NULL,
  `proveedor_texto` varchar(255) NOT NULL,
  `total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `factura_xml_id` int(10) unsigned DEFAULT NULL,
  `factura_erp_id` int(10) unsigned DEFAULT NULL,
  `estado` enum('sin_respaldo','respaldada','con_diferencia') NOT NULL DEFAULT 'sin_respaldo',
  `diferencia` decimal(18,2) DEFAULT NULL,
  `score_numero` decimal(5,1) DEFAULT NULL,
  `score_proveedor` decimal(5,1) DEFAULT NULL,
  `match_manual` tinyint(1) NOT NULL DEFAULT 0,
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_listado` (`listado_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_factura_erp` (`factura_erp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedor_alias` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proveedor_id` int(10) unsigned NOT NULL,
  `alias_norm` varchar(255) NOT NULL,
  `alias_texto` varchar(255) NOT NULL,
  `veces_aplicado` int(10) unsigned NOT NULL DEFAULT 0,
  `creado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_alias_norm` (`alias_norm`),
  KEY `idx_proveedor` (`proveedor_id`),
  KEY `fk_alias_usuario` (`creado_por`),
  CONSTRAINT `fk_alias_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alias_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedor_cedula_ignorada` (
  `sociedad_id` int(10) unsigned NOT NULL,
  `cedula` varchar(20) NOT NULL,
  `nombre` varchar(255) NOT NULL DEFAULT '',
  `motivo` varchar(255) DEFAULT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sociedad_id`,`cedula`),
  KEY `fk_cedula_ignorada_usuario` (`usuario_id`),
  CONSTRAINT `fk_cedula_ignorada_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedor_codigo_erp` (
  `sociedad_id` int(10) unsigned NOT NULL COMMENT 'Cada empresa tiene su propio catálogo',
  `proveedor_codigo` varchar(30) NOT NULL COMMENT 'Código del proveedor en el ERP (140001227)',
  `proveedor_id` int(10) unsigned NOT NULL,
  `cedula` varchar(20) NOT NULL DEFAULT '',
  `nombre_erp` varchar(255) NOT NULL DEFAULT '' COMMENT 'Cómo escribe el ERP a este proveedor',
  `origen` varchar(20) NOT NULL DEFAULT 'cosecha',
  `confirmado_por` int(10) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`sociedad_id`,`proveedor_codigo`),
  KEY `idx_proveedor` (`proveedor_id`),
  KEY `idx_origen` (`origen`),
  KEY `fk_codigo_erp_usuario` (`confirmado_por`),
  CONSTRAINT `fk_codigo_erp_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_codigo_erp_usuario` FOREIGN KEY (`confirmado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Código del ERP -> cédula del emisor';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rfc` varchar(13) DEFAULT NULL COMMENT 'Cédula del proveedor (la columna se llama rfc por herencia de México)',
  `razon_social` varchar(255) NOT NULL COMMENT 'Razón social del emisor',
  `razon_social_normalizada` varchar(255) NOT NULL COMMENT 'Razón social sin acentos/mayúsculas para matching',
  `alias` varchar(100) DEFAULT NULL COMMENT 'Alias o nombre corto',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rfc` (`rfc`),
  KEY `idx_razon_social_norm` (`razon_social_normalizada`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catálogo de proveedores normalizados';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rol_permisos` (
  `rol_id` int(10) unsigned NOT NULL,
  `permiso` varchar(60) NOT NULL,
  PRIMARY KEY (`rol_id`,`permiso`),
  KEY `idx_rol_permiso` (`permiso`),
  CONSTRAINT `fk_rol_permisos_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `es_admin` tinyint(1) NOT NULL DEFAULT 0,
  `es_sistema` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rol_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `origen` enum('nota_credito','factura','xml_factura','xml_nota') NOT NULL,
  `referencia_id` int(10) unsigned NOT NULL,
  `estado` enum('pendiente','revision','lista','cerrada') DEFAULT NULL,
  `responsable` varchar(120) DEFAULT NULL COMMENT 'Quién lo tiene a cargo',
  `recordar_en` datetime DEFAULT NULL,
  `recordar_cada` smallint(5) unsigned DEFAULT NULL,
  `avisado_en` datetime DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL COMMENT 'Por qué se cerró o en qué va',
  `actualizado_por` varchar(120) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ref_factura_xml` int(10) unsigned GENERATED ALWAYS AS (if(`origen` in ('xml_factura','xml_nota'),`referencia_id`,NULL)) STORED COMMENT 'Derivada: a qué facturas_xml apunta, si apunta a una',
  `ref_nota_linea` int(10) unsigned GENERATED ALWAYS AS (if(`origen` = 'nota_credito',`referencia_id`,NULL)) STORED COMMENT 'Derivada: a qué notas_credito_lineas apunta, si apunta a una',
  `ref_factura_erp` int(10) unsigned GENERATED ALWAYS AS (if(`origen` = 'factura',`referencia_id`,NULL)) STORED COMMENT 'Derivada: a qué facturas_erp apunta, si apunta a una',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_seguimiento_referencia` (`origen`,`referencia_id`),
  KEY `idx_seguimiento_responsable` (`responsable`,`estado`),
  KEY `idx_seguimiento_recordar` (`estado`,`recordar_en`),
  KEY `idx_seg_ref_factura_xml` (`ref_factura_xml`),
  KEY `idx_seg_ref_nota_linea` (`ref_nota_linea`),
  KEY `idx_seg_ref_factura_erp` (`ref_factura_erp`),
  CONSTRAINT `fk_seg_factura_erp` FOREIGN KEY (`ref_factura_erp`) REFERENCES `facturas_erp` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_factura_xml` FOREIGN KEY (`ref_factura_xml`) REFERENCES `facturas_xml` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_nota_linea` FOREIGN KEY (`ref_nota_linea`) REFERENCES `notas_credito_lineas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `seguimiento_bitacora` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seguimiento_id` bigint(20) unsigned NOT NULL,
  `usuario_id` int(10) unsigned DEFAULT NULL,
  `usuario_nombre` varchar(120) DEFAULT NULL,
  `estado_anterior` varchar(30) DEFAULT NULL,
  `estado_nuevo` varchar(30) DEFAULT NULL,
  `comentario` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bitacora_seguimiento` (`seguimiento_id`,`id`),
  KEY `fk_seg_bitacora_usuario` (`usuario_id`),
  CONSTRAINT `fk_seg_bitacora_seguimiento` FOREIGN KEY (`seguimiento_id`) REFERENCES `seguimiento` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_seg_bitacora_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sociedades` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `cedula` varchar(30) NOT NULL,
  `cedula_norm` varchar(30) GENERATED ALWAYS AS (nullif(regexp_replace(`cedula`,'[^0-9]',''),'')) STORED COMMENT 'Derivada: solo los dígitos de la cédula, para que no se repita',
  `almacen_documentos` varchar(10) NOT NULL DEFAULT 'sharepoint', -- ya no se lee: todo va a SharePoint
  `activa` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sociedad_cedula` (`cedula_norm`)
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuario_sociedades` (
  `usuario_id` int(10) unsigned NOT NULL,
  `sociedad_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`usuario_id`,`sociedad_id`),
  KEY `idx_us_sociedad` (`sociedad_id`),
  CONSTRAINT `fk_us_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_us_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `username` varchar(80) NOT NULL,
  `cedula` varchar(30) NOT NULL DEFAULT '',
  `password_hash` varchar(255) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `rol_id` int(10) unsigned DEFAULT NULL,
  `sociedad_id` int(10) unsigned DEFAULT NULL COMMENT 'Empresa con la que trabaja este usuario (su selección, no global)',
  `ultimo_acceso` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_usuarios_sociedad` (`sociedad_id`),
  KEY `idx_usuario_rol` (`rol_id`),
  KEY `idx_usuarios_cedula` (`cedula`),
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuarios_sociedad` FOREIGN KEY (`sociedad_id`) REFERENCES `sociedades` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================
-- FIN
-- =========================================
