-- El seguimiento pasa de seis estados de gestión a cuatro pestañas.
--
-- Antes el estado decía en qué iba el trámite (en gestión, esperando al
-- proveedor…) y la pestaña se deducía de él. Ahora el estado ES la pestaña, y
-- lo normal es no tener ninguno: el estado se calcula solo a partir del saldo
-- y del respaldo.
--
--   sin saldo                      -> cerrada
--   con saldo y algo que falta     -> pendiente
--   con saldo y respaldo completo  -> lista
--
-- 'revision' no se calcula nunca: es la bandeja de los enredos, se entra a
-- ella a mano y exige describir el problema.
--
-- La columna pasa a admitir NULL, que significa "sin marca a mano, manda el
-- cálculo". Es lo que permite anotar un comentario sobre un renglón sin por
-- eso congelarle el estado, que es justo lo que pasaba antes: cualquier fila
-- de la tabla nacía en 'pendiente'.
--
-- La equivalencia de lo ya registrado:
--
--   en_gestion, esperando      -> revision   (alguien lo tenía entre manos)
--   resuelto                   -> lista
--   no_disponible, descartado  -> cerrada
--   pendiente                  -> NULL       (que lo diga el cálculo)
--
-- 'pendiente' se borra en vez de conservarse porque era el valor por omisión
-- de cualquier fila; conservarlo dejaría congelados en Pendientes documentos
-- que nadie clasificó nunca.
--
-- Esto también lo hace el modelo Seguimiento al arrancar; la migración sirve
-- para aplicarlo explícitamente o donde no haya permisos DDL en runtime.

ALTER TABLE `seguimiento` MODIFY `estado`
    ENUM('pendiente', 'en_gestion', 'esperando', 'resuelto', 'no_disponible', 'descartado',
         'revision', 'lista', 'cerrada') NULL DEFAULT NULL;

UPDATE `seguimiento` SET `estado` = CASE
    WHEN `estado` IN ('en_gestion', 'esperando')     THEN 'revision'
    WHEN `estado` = 'resuelto'                       THEN 'lista'
    WHEN `estado` IN ('no_disponible', 'descartado') THEN 'cerrada'
    ELSE NULL END;

ALTER TABLE `seguimiento` MODIFY `estado`
    ENUM('pendiente', 'revision', 'lista', 'cerrada') NULL DEFAULT NULL;
