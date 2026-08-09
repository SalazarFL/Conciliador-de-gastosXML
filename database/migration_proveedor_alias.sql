-- Equivalencias de nombre de proveedor aprendidas al emparejar a mano
--
-- El problema: el mismo proveedor se escribe distinto en el listado del ERP y
-- en el XML de Hacienda. "COOPEAGRI" y "COOPERATIVA AGRICOLA INDUSTRIAL Y DE
-- SERVICIOS MULTIPLES EL GENERAL" no comparten ni una palabra, así que ninguna
-- comparación por parecido las va a juntar nunca — no es cuestión de ajustar
-- un umbral, es que no se parecen.
--
-- La única fuente de verdad es una persona diciendo "estas dos son la misma".
-- Esta tabla guarda esa decisión UNA vez, y desde entonces vale para todas las
-- facturas de ese proveedor: las que ya estaban sin emparejar y las que
-- lleguen después.
--
-- No lleva sociedad: los proveedores son compartidos entre las empresas del
-- grupo (`proveedores` tampoco se acota), y "COOPEAGRI" significa lo mismo en
-- todas.

CREATE TABLE IF NOT EXISTS `proveedor_alias` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- El proveedor "de verdad": el del XML, que es el que tiene cédula.
    `proveedor_id` INT UNSIGNED NOT NULL,

    -- Cómo lo escribe la otra fuente, normalizado igual que en el matching
    -- (mayúsculas, sin tildes, sin S.A./LTDA...). Es la llave de búsqueda.
    `alias_norm` VARCHAR(255) NOT NULL,

    -- Y tal cual venía escrito, para poder mostrarlo en pantalla.
    `alias_texto` VARCHAR(255) NOT NULL,

    `veces_aplicado` INT UNSIGNED NOT NULL DEFAULT 0,
    `creado_por` INT UNSIGNED NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Un mismo texto no puede apuntar a dos proveedores. Si alguien se
    -- equivoca al emparejar y luego lo corrige, el alias se reescribe solo.
    UNIQUE KEY `uk_alias_norm` (`alias_norm`),
    KEY `idx_proveedor` (`proveedor_id`),

    CONSTRAINT `fk_alias_proveedor` FOREIGN KEY (`proveedor_id`)
        REFERENCES `proveedores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
