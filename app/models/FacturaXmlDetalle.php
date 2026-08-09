<?php
/**
 * Detalle interno de los XML: referencias (InformacionReferencia) y líneas
 * (LineaDetalle). La referencia con clave es el puente determinístico
 * NC → factura acreditada; las líneas sirven de desempate en la verificación
 * de devoluciones.
 */

require_once __DIR__ . '/../helpers/XmlParser.php';

class FacturaXmlDetalle extends Model
{
    protected $table = 'facturas_xml_referencias';

    // Los pendientes de extraer se buscan por facturas_xml.ruta_xml.
    protected $camposRuta = ['ruta_xml'];

    /**
     * Extrae referencias y líneas del XML en disco y las guarda,
     * reemplazando lo previo. Si el documento ya fue extraído se omite,
     * salvo con $forzar. Devuelve ['estado', 'referencias', 'lineas'].
     */
    public function extraerYGuardar($facturaXmlId, $rutaXml, $forzar = false)
    {
        $facturaXmlId = (int) $facturaXmlId;

        if (!$forzar) {
            $marca = $this->fetchColumn(
                'SELECT detalle_extraido_en FROM facturas_xml WHERE id = ?',
                [$facturaXmlId]
            );
            if (!empty($marca)) {
                return ['estado' => 'omitido', 'referencias' => 0, 'lineas' => 0];
            }
        }

        $detalle = XmlInvoiceParser::parseDetalleFromFile($rutaXml);

        $db = self::getDB();
        $enTransaccion = $db->inTransaction();
        if (!$enTransaccion) {
            $db->beginTransaction();
        }

        try {
            $this->execute('DELETE FROM facturas_xml_referencias WHERE factura_xml_id = ?', [$facturaXmlId]);
            $this->execute('DELETE FROM facturas_xml_lineas WHERE factura_xml_id = ?', [$facturaXmlId]);

            foreach ($detalle['referencias'] as $ref) {
                $this->insert(
                    'INSERT INTO facturas_xml_referencias
                        (factura_xml_id, tipo_doc_ref, numero_ref, clave_ref,
                         consecutivo_ref, fecha_ref, codigo_ref, razon)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $facturaXmlId,
                        $ref['tipo_doc_ref'],
                        $ref['numero_ref'],
                        $ref['clave_ref'],
                        $ref['consecutivo_ref'],
                        $ref['fecha_ref'],
                        $ref['codigo_ref'],
                        $ref['razon'],
                    ]
                );
            }

            foreach ($detalle['lineas'] as $linea) {
                $this->insert(
                    'INSERT INTO facturas_xml_lineas
                        (factura_xml_id, numero_linea, codigo_cabys, codigo_comercial,
                         detalle, cantidad, unidad, precio_unitario, monto_descuento,
                         subtotal, impuesto, total_linea)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $facturaXmlId,
                        $linea['numero_linea'],
                        $linea['codigo_cabys'],
                        $linea['codigo_comercial'],
                        $linea['detalle'],
                        $linea['cantidad'],
                        $linea['unidad'],
                        $linea['precio_unitario'],
                        $linea['monto_descuento'],
                        $linea['subtotal'],
                        $linea['impuesto'],
                        $linea['total_linea'],
                    ]
                );
            }

            $this->execute(
                'UPDATE facturas_xml SET detalle_extraido_en = NOW() WHERE id = ?',
                [$facturaXmlId]
            );

            if (!$enTransaccion) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if (!$enTransaccion && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'estado' => 'extraido',
            'referencias' => count($detalle['referencias']),
            'lineas' => count($detalle['lineas']),
        ];
    }

    /**
     * Documentos con XML en disco pendientes de extracción, paginados por id
     * (keyset) para que un archivo con error no bloquee el avance del lote.
     */
    public function pendientes($despuesDeId, $limite, $tipoDocumento = 'NC')
    {
        $sql = 'SELECT id, ruta_xml FROM facturas_xml
                WHERE detalle_extraido_en IS NULL
                  AND ruta_xml IS NOT NULL AND ruta_xml <> \'\'
                  AND id > ?';
        $params = [(int) $despuesDeId];

        if ($tipoDocumento !== null && strtoupper($tipoDocumento) !== 'ALL') {
            $sql .= ' AND tipo_documento = ?';
            $params[] = strtoupper($tipoDocumento);
        }

        $sql .= ' ORDER BY id ASC LIMIT ' . max(1, (int) $limite);
        return $this->fetchAll($sql, $params);
    }

    public function contarPendientes($tipoDocumento = 'NC')
    {
        $sql = 'SELECT COUNT(*) FROM facturas_xml
                WHERE detalle_extraido_en IS NULL
                  AND ruta_xml IS NOT NULL AND ruta_xml <> \'\'';
        $params = [];

        if ($tipoDocumento !== null && strtoupper($tipoDocumento) !== 'ALL') {
            $sql .= ' AND tipo_documento = ?';
            $params[] = strtoupper($tipoDocumento);
        }

        return (int) $this->fetchColumn($sql, $params);
    }

    /** Desmarca documentos ya extraídos para que el backfill los reprocese. */
    public function reabrirParaReproceso($tipoDocumento = 'NC')
    {
        $sql = "UPDATE facturas_xml SET detalle_extraido_en = NULL
                WHERE ruta_xml IS NOT NULL AND ruta_xml <> ''";
        $params = [];

        if ($tipoDocumento !== null && strtoupper($tipoDocumento) !== 'ALL') {
            $sql .= ' AND tipo_documento = ?';
            $params[] = strtoupper($tipoDocumento);
        }

        return $this->execute($sql, $params);
    }

    public function contarConClaveReferenciada()
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(DISTINCT factura_xml_id) FROM facturas_xml_referencias WHERE clave_ref IS NOT NULL'
        );
    }

    public function referenciasDe($facturaXmlId)
    {
        return $this->fetchAll(
            'SELECT * FROM facturas_xml_referencias WHERE factura_xml_id = ? ORDER BY id',
            [(int) $facturaXmlId]
        );
    }

    public function lineasDe($facturaXmlId)
    {
        return $this->fetchAll(
            'SELECT * FROM facturas_xml_lineas WHERE factura_xml_id = ? ORDER BY numero_linea',
            [(int) $facturaXmlId]
        );
    }

    /**
     * NC (u otros documentos) que referencian la clave dada: el JOIN
     * determinístico para "¿qué notas acreditan esta factura?".
     */
    public function documentosQueReferencianClave($clave)
    {
        $digits = preg_replace('/\D+/', '', (string) $clave);
        if ($digits === '') {
            return [];
        }

        return $this->fetchAll(
            'SELECT f.id, f.tipo_documento, f.consecutivo_completo, f.clave,
                    f.proveedor_id, f.fecha_emision, f.subtotal, f.iva, f.total,
                    f.moneda, r.numero_ref, r.codigo_ref, r.razon
             FROM facturas_xml_referencias r
             INNER JOIN facturas_xml f ON f.id = r.factura_xml_id
             WHERE r.clave_ref = ?
             ORDER BY f.fecha_emision, f.id',
            [$digits]
        );
    }
}
