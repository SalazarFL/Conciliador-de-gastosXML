-- Equivalencia entre el código de proveedor del ERP y la cédula del emisor.
--
-- El problema que resuelve
-- ------------------------
-- Hasta ahora lo único que unía una factura del ERP con su comprobante era el
-- consecutivo de veinte dígitos, y el emparejador lo trataba como si fuera
-- único a nivel país. No lo es. El consecutivo lo arma el EMISOR:
--
--     001      00001      01       0000000144
--     sucursal terminal   tipo     correlativo
--
-- Los primeros diez dígitos son casi siempre los mismos y el correlativo lo
-- lleva cada proveedor por su cuenta empezando en 1, así que la factura 144 de
-- un emisor pequeño y la 144 de otro tienen literalmente el mismo número. En
-- la base había 51 consecutivos compartidos entre emisores distintos, y
-- `buscarXml()` devolvía el primero de la lista sin preguntar de quién era.
-- Resultado medido: 24 facturas emparejadas sobre un número ambiguo, de las
-- cuales 8 quedaron con el XML de otro proveedor. Lo único único a nivel país
-- es la clave de 50 dígitos, que sí lleva la cédula del emisor adentro.
--
-- Este mapa es lo que le permite al emparejador preguntar "¿este comprobante
-- lo emitió el proveedor que dice esta línea del ERP?" antes de aceptarlo.
--
-- De dónde salen los datos
-- ------------------------
-- No hay que esperar a que alguien empareje nada: la respuesta ya está en los
-- emparejamientos hechos. Se cosecha SOLO de las facturas en estado
-- `respaldada`, o sea aquellas donde el monto del ERP cuadró al colón con el
-- total del XML. Ese filtro no es cosmético: sobre los datos reales da 255
-- códigos aprendidos y CERO códigos ambiguos, mientras que cosechando también
-- de `con_diferencia` aparecen 5 códigos con dos cédulas — que son exactamente
-- los enlaces malos. El monto ya estaba haciendo de verificador independiente.
--
-- Por qué la llave es el código solo
-- ----------------------------------
-- Un código del ERP es un proveedor. Al revés no: hay cédulas con varios
-- códigos (Grupo BM SP tiene tres, Marjava dos), lo cual es normal —cuentas o
-- sucursales separadas— y no estorba, porque la pregunta que se hace el
-- emparejador siempre va de código a cédula. Por eso `proveedor_id` va como
-- índice y no como parte de la llave primaria.
--
-- No lleva sociedad, por la misma razón que `proveedor_alias`: los proveedores
-- son compartidos entre las empresas del grupo y un código significa lo mismo
-- en todas.

-- ── El mapa ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `proveedor_codigo_erp` (
    `proveedor_codigo` VARCHAR(30) NOT NULL COMMENT 'Código del proveedor en el ERP (140001227)',

    -- El proveedor "de verdad": el del XML, que es el que tiene cédula.
    -- La comparación del emparejador se hace contra ESTE campo y no contra el
    -- texto de la cédula, para no depender de ceros a la izquierda ni de
    -- formatos (hay cédulas de 9, 10 y 12 dígitos en la misma tabla).
    `proveedor_id` INT UNSIGNED NOT NULL,

    -- Denormalizadas a propósito: son para mostrar en pantalla y para poder
    -- leer esta tabla sin JOIN cuando algo se está diagnosticando.
    `cedula` VARCHAR(20) NOT NULL DEFAULT '',
    `nombre_erp` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Cómo escribe el ERP a este proveedor',

    -- Cuántas facturas independientes y ya verificadas respaldan esta
    -- equivalencia. NO decide si se aprende —eso lo decide el estado
    -- `respaldada`— sino quién gana cuando dos datos se contradicen.
    `veces_confirmado` INT UNSIGNED NOT NULL DEFAULT 1,

    -- cosecha    = deducido de emparejamientos ya verificados
    -- automatico = aprendido al verificar un pago
    -- manual     = lo confirmó una persona. Manda sobre cualquier contador y
    --              la recosecha no lo toca.
    `origen` VARCHAR(20) NOT NULL DEFAULT 'cosecha',

    -- Empate: el mapa afirma algo con UNA sola confirmación y llegó un
    -- comprobante que afirma otra cosa con la misma fuerza. Ahí el mapa no
    -- sabe lo suficiente para vetar a nadie, así que se abstiene: para ese
    -- código el emparejamiento vuelve a comportarse como antes de existir esta
    -- tabla —o sea, sin retroceso— y se pide una decisión humana. Con dos o
    -- más confirmaciones el mapa sí veta, porque ya no es un empate.
    `en_disputa` TINYINT(1) NOT NULL DEFAULT 0,

    `confirmado_por` INT UNSIGNED NULL DEFAULT NULL,
    `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`proveedor_codigo`),
    KEY `idx_proveedor` (`proveedor_id`),
    KEY `idx_origen` (`origen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Código del ERP -> cédula del emisor';

-- ── La bitácora de contradicciones ─────────────────────────────────
--
-- Vive aparte y no como filas extra del mapa por una razón práctica: la guarda
-- corre DENTRO del bucle de emparejamiento, y ahí hace falta que la consulta
-- por código devuelva una sola fila sin tener que desempatar nada. La tabla
-- caliente se queda limpia; el ruido se acumula al lado.
--
-- Cada fila es un veto: el emparejador iba a aceptar un comprobante y el mapa
-- dijo que el emisor no corresponde al código. Casi siempre eso es una
-- colisión de consecutivo y el veto es correcto. Pero si el mismo par
-- (código, cédula propuesta) se repite, la lectura cambia: puede que el código
-- haya cambiado de dueño de verdad y el mapa sea el que está viejo. Eso no lo
-- puede decidir el programa —desde un solo evento las dos causas se ven
-- iguales— así que se acumula aquí y se avisa para que una persona lo mire.
CREATE TABLE IF NOT EXISTS `proveedor_codigo_conflictos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `proveedor_codigo` VARCHAR(30) NOT NULL,

    -- Lo que dice el mapa hoy...
    `proveedor_id_mapa` INT UNSIGNED NOT NULL,
    -- ...y lo que proponía el comprobante que se vetó.
    `proveedor_id_propuesto` INT UNSIGNED NOT NULL,

    `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL,
    `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,

    -- Si el monto del ERP cuadraba al colón con el del XML vetado, el veto es
    -- sospechoso: es la firma de un código que cambió de dueño, no de una
    -- colisión. Sube la severidad del aviso.
    `monto_cuadraba` TINYINT(1) NOT NULL DEFAULT 0,

    `visto_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_codigo` (`proveedor_codigo`),
    KEY `idx_par` (`proveedor_codigo`, `proveedor_id_propuesto`),
    KEY `idx_visto` (`visto_en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Vetos de la guarda de cédula, para revisión humana';

-- ── Cosecha inicial ────────────────────────────────────────────────
--
-- Idempotente a propósito: se puede volver a correr cuantas veces sea, y de
-- hecho el modelo la vuelve a correr cuando cambia el código, para que los
-- contadores sigan reflejando la realidad. Lo confirmado a mano nunca se pisa.
--
-- El ORDER BY importa: con varias filas para el mismo código gana la que llega
-- primero, así que entra primero la de más confirmaciones.
INSERT INTO `proveedor_codigo_erp`
       (`proveedor_codigo`, `proveedor_id`, `cedula`, `nombre_erp`, `veces_confirmado`, `origen`)
SELECT e.proveedor_codigo,
       f.proveedor_id,
       p.rfc,
       MAX(e.proveedor_nombre),
       COUNT(*) AS veces,
       'cosecha'
  FROM facturas_erp e
  JOIN facturas_xml f ON f.id = e.factura_xml_id
  JOIN proveedores  p ON p.id = f.proveedor_id
 WHERE e.estado_respaldo = 'respaldada'
   AND p.rfc IS NOT NULL AND p.rfc <> ''
 GROUP BY e.proveedor_codigo, f.proveedor_id, p.rfc
 ORDER BY veces DESC
ON DUPLICATE KEY UPDATE
    proveedor_id     = IF(origen = 'manual', proveedor_id,
                          IF(VALUES(veces_confirmado) > veces_confirmado, VALUES(proveedor_id), proveedor_id)),
    cedula           = IF(origen = 'manual', cedula,
                          IF(VALUES(veces_confirmado) > veces_confirmado, VALUES(cedula), cedula)),
    nombre_erp       = VALUES(nombre_erp),
    veces_confirmado = IF(origen = 'manual', veces_confirmado,
                          GREATEST(veces_confirmado, VALUES(veces_confirmado)));
