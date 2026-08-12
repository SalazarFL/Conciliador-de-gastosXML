-- ---------------------------------------------------------------------------
-- Seguimiento de documentos
--
-- El sistema ya sabía QUÉ estaba mal (falta el XML, falta el PDF, el monto no
-- cuadra) pero no tenía dónde anotar QUÉ HIZO una persona al respecto. Sin eso
-- la lista de pendientes nunca baja: mañana vuelven a aparecer los mismos
-- 3 500 renglones, sin poder separar lo nuevo de lo ya perseguido.
--
-- Se guarda aparte, no como columnas dentro de cada módulo, por dos razones:
--   1. sirve igual para una línea de notas de crédito y para una del pago
--      semanal, con una sola implementación;
--   2. la fila se crea solo cuando alguien actúa. Lo que nadie ha tocado no
--      ocupa espacio y se muestra como 'pendiente' por omisión.
--
-- Esta migración se puede volver a correr sin romper nada.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS seguimiento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- De qué módulo viene el renglón y cuál es su id allá.
    origen ENUM('nota_credito', 'pago_semanal') NOT NULL,
    referencia_id INT UNSIGNED NOT NULL,

    -- pendiente        nadie lo ha tocado todavía
    -- en_gestion       alguien lo está trabajando
    -- esperando        se pidió al proveedor y se aguarda respuesta
    -- resuelto         llegó el documento o se corrigió el monto
    -- no_disponible    no va a existir nunca (histórico, previo al sistema)
    -- descartado       no aplica: no era un pendiente real
    estado ENUM('pendiente', 'en_gestion', 'esperando', 'resuelto', 'no_disponible', 'descartado')
        NOT NULL DEFAULT 'pendiente',

    responsable VARCHAR(120) NULL COMMENT 'Quién lo tiene a cargo',
    vence_el DATE NULL COMMENT 'Pospuesto hasta esta fecha, antes no estorba',
    motivo VARCHAR(255) NULL COMMENT 'Por qué se cerró o en qué va',

    actualizado_por VARCHAR(120) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_seguimiento_referencia (origen, referencia_id),
    KEY idx_seguimiento_estado (estado, vence_el),
    KEY idx_seguimiento_responsable (responsable, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién hizo qué y cuándo. Es lo que convierte la pantalla en un expediente:
-- sin bitácora, "en gestión" no dice nada.
CREATE TABLE IF NOT EXISTS seguimiento_bitacora (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seguimiento_id BIGINT UNSIGNED NOT NULL,

    usuario_id INT UNSIGNED NULL,
    usuario_nombre VARCHAR(120) NULL,

    estado_anterior VARCHAR(30) NULL,
    estado_nuevo VARCHAR(30) NULL,
    comentario TEXT NULL,

    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_bitacora_seguimiento (seguimiento_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
