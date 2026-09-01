-- Cuándo cambió por última vez cada carpeta del buzón.
--
-- La sincronización le preguntaba a todas las carpetas con la misma
-- frecuencia. Con 17 sociedades eso son unas 1.680 carpetas cada cinco
-- minutos, y la vuelta no cabe: necesita 14 minutos y tiene dos y medio.
--
-- La salida es distinguir la carpeta viva de la carpeta muerta. En el buzón
-- real de hoy, 88 de 106 no reciben un correo desde hace más de dos semanas
-- —las de meses cerrados, "CORREOS 2024/10 OCTUBRE"— y se estaban revisando
-- doce veces por hora igual que la bandeja de entrada. Con ritmos separados la
-- vuelta baja de 106 carpetas por corrida a 26.
--
-- Para decidir el ritmo hace falta saber cuándo cambió cada carpeta por última
-- vez, y eso no se estaba guardando: `ultima_sync` dice cuándo se la MIRÓ, que
-- es otra cosa —se la mira cada cinco minutos aunque no pase nada—.
--
-- La regla de los ritmos vive en app/helpers/RitmoCarpetas.php.
--
-- Esto también lo hace el modelo CorreoIndice al arrancar; la migración sirve
-- para aplicarlo a mano sobre una base ya montada.

ALTER TABLE correo_carpetas
    ADD COLUMN IF NOT EXISTS ultimo_cambio DATETIME NULL DEFAULT NULL AFTER ultima_sync;

-- Sembrado inicial: la fecha del mensaje más nuevo de cada carpeta.
--
-- Sin esto, al arrancar ninguna carpeta tendría cambio anotado y todas se
-- tratarían como vivas hasta que pasaran dos semanas —o sea, el ahorro no
-- empezaría hasta dentro de dos semanas—. Con el sembrado, la primera corrida
-- ya clasifica bien.
--
-- Es una aproximación y a propósito: la fecha del mensaje más nuevo no es
-- exactamente cuándo cambió la carpeta (alguien pudo archivar ayer un correo
-- de 2024). Se equivoca hacia el lado seguro nada más al revés —una carpeta
-- así se enfría antes de tiempo— y se corrige sola en cuanto la
-- sincronización detecte el primer cambio real.
UPDATE correo_carpetas c
   SET c.ultimo_cambio = (
        SELECT FROM_UNIXTIME(MAX(i.timestamp))
          FROM correo_indice i
         WHERE i.cuenta_id = c.cuenta_id
           AND i.carpeta = c.carpeta
           AND i.timestamp > 0
   )
 WHERE c.ultimo_cambio IS NULL;
