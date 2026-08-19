-- ---------------------------------------------------------------------------
-- Seguimiento de documentos
--
-- El sistema ya sabía QUÉ estaba mal (falta el XML, falta el PDF, el monto no
-- cuadra) pero no tenía dónde anotar QUÉ HIZO una persona al respecto. Sin eso
-- la lista de pendientes nunca baja: mañana vuelven a aparecer los mismos
-- 3 500 renglones, sin poder separar lo nuevo de lo ya perseguido.
--
-- Se guarda aparte, no como columnas dentro de cada módulo, por dos razones:
--   1. sirve igual para una línea de notas de crédito y para una factura del
--      ERP, con una sola implementación;
--   2. la fila se crea solo cuando alguien actúa. Lo que nadie ha tocado no
--      ocupa espacio y se muestra como 'pendiente' por omisión.
--
-- Esta migración se puede volver a correr sin romper nada.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS seguimiento (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- De qué módulo viene el renglón y cuál es su id allá.
    -- 'factura' se llamó 'pago_semanal' hasta que el pago dejó de tener líneas
    -- propias; lo renombra database/migration_seguimiento_origen_factura.sql.
    origen ENUM('nota_credito', 'factura') NOT NULL,
    referencia_id INT UNSIGNED NOT NULL,

    -- La MARCA A MANO. NULL —lo normal— significa que no hay ninguna y que el
    -- estado lo decide el cálculo: sin saldo es 'cerrada', con saldo y algo
    -- que falta es 'pendiente', con saldo y respaldo completo es 'lista'.
    --
    -- pendiente   forzado a la cola de trabajo aunque no le tocara
    -- revision    apartado por alguien; el único que no se calcula nunca
    -- lista       dado por bueno aunque los datos digan otra cosa
    -- cerrada     dado por terminado (no va a existir, no aplica, ya se pagó)
    --
    -- Que admita NULL es lo que permite anotar un comentario sobre un renglón
    -- sin por eso congelarle el estado.
    estado ENUM('pendiente', 'revision', 'lista', 'cerrada') NULL DEFAULT NULL,

    responsable VARCHAR(120) NULL COMMENT 'Quién lo tiene a cargo',
    -- El recordatorio de los que están en revisión: cuándo vuelve a
    -- molestar (fecha Y hora), cada cuántos días insiste, y cuándo se dejó
    -- el último aviso en la campana.
    recordar_en DATETIME NULL,
    recordar_cada SMALLINT UNSIGNED NULL,
    avisado_en DATETIME NULL,
    motivo VARCHAR(255) NULL COMMENT 'Por qué se cerró o en qué va',

    actualizado_por VARCHAR(120) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_seguimiento_referencia (origen, referencia_id),
    KEY idx_seguimiento_recordar (estado, recordar_en),
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
