-- El modo Correo de la cola de seguimiento.
--
-- Seguimiento tenía una sola cola: la que arranca del ERP —facturas y líneas
-- del reporte de notas— y pregunta qué le falta a cada registro (su XML, su
-- PDF, que el monto cuadre). Esa es ahora el modo "Sistema".
--
-- El modo "Correo" hace la pregunta inversa: arranca de los comprobantes XML
-- ya cargados —los que entraron por el buzón y los que alguien subió a mano—
-- y busca cuáles NO aparecen todavía en ningún registro del ERP. Un XML sin
-- registro es plata que llegó y que nadie anotó, y hasta ahora no había dónde
-- verla: los listados de Facturas XML y Notas XML enseñan lo que hay, no lo
-- que falta del otro lado.
--
-- La gestión de los dos modos vive en esta misma tabla, porque es la misma:
-- la marca a mano, el responsable, el recordatorio, el motivo y la bitácora.
-- Lo único que las mantiene aparte es el `origen`, y por eso hay que ampliarlo:
--
--   nota_credito, factura        líneas del ERP        (modo Sistema)
--   xml_nota,     xml_factura    filas de facturas_xml (modo Correo)
--
-- No se mueve ninguna fila: las que ya están siguen apuntando a donde
-- apuntaban, y los id de facturas_xml no chocan con los de las otras tablas
-- porque la clave única es (origen, referencia_id).
--
-- Esto también lo hace el modelo Seguimiento al arrancar; la migración sirve
-- para aplicarlo explícitamente o donde no haya permisos DDL en runtime.

ALTER TABLE `seguimiento` MODIFY `origen`
    ENUM('nota_credito','factura','xml_factura','xml_nota') NOT NULL;
