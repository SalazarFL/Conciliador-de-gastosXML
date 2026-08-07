-- Las coincidencias automáticas por número+proveedor pertenecen a la
-- semana aunque el monto difiera. Si una factura quedó enlazada a varios
-- listados mientras estaba sin semana, prevalece el listado más reciente.

UPDATE facturas_xml f
INNER JOIN (
    SELECT pf.factura_xml_id, MAX(pf.listado_id) AS listado_id
    FROM porpagar_facturas pf
    INNER JOIN porpagar_listados l ON l.id = pf.listado_id
    WHERE pf.factura_xml_id IS NOT NULL
      AND pf.match_manual = 0
      AND pf.estado IN ('respaldada', 'con_diferencia')
      AND l.semana_id IS NOT NULL
    GROUP BY pf.factura_xml_id
) ultima ON ultima.factura_xml_id = f.id
INNER JOIN porpagar_listados l ON l.id = ultima.listado_id
SET f.semana_id = l.semana_id
WHERE f.semana_id IS NULL;
