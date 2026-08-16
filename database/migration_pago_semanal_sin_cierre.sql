-- El pago semanal deja de cerrarse.
--
-- Cerrar congelaba la semana: una vez cerrada nadie añadía, quitaba ni
-- reemparejaba nada, y la verificación automática saltaba ese listado. Con la
-- base compartida entre varias máquinas esa foto fija se volvió mentira —el
-- XML que una persona consigue el viernes tiene que aparecerle a todas—, así
-- que el estado desapareció del código.
--
-- Las columnas se dejan en su sitio: guardan quién y cuándo cerró pagos
-- viejos, y borrarlas no se puede deshacer. Nadie las lee. Lo único necesario
-- es reabrir lo que quedó cerrado, para que un reporte hecho a mano contra la
-- base no lea un estado que la aplicación ya no respeta.
--
-- Esto también lo hace el modelo PorPagar al arrancar; la migración sirve para
-- aplicarlo explícitamente.

UPDATE `porpagar_listados` SET `estado` = 'abierto' WHERE `estado` <> 'abierto';
