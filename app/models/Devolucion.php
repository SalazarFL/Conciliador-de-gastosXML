<?php
/**
 * Devoluciones a proveedor (reportes PDF del ERP) y sus vínculos con las
 * notas de crédito electrónicas. Maneja las tablas devoluciones,
 * devolucion_lineas y devolucion_matches.
 */

require_once __DIR__ . '/ProveedorCatalogo.php';

class Devolucion extends Model
{
    // Las devoluciones ya guardaban su sociedad, pero las NC contra las que
    // se emparejan salían de toda la tabla de XML.
    use AlcanceSociedad;

    protected $table = 'devoluciones';

    // El PDF del reporte se archiva en la carpeta compartida.
    protected $camposRuta = ['ruta_pdf'];

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return false;
    }
    public function inTransaction() { return self::getDB()->inTransaction(); }

    public function buscarPorHash($hashPdf)
    {
        return $this->fetchOne(
            'SELECT id, tipo, numero, archivo_pdf FROM devoluciones WHERE hash_pdf = ? LIMIT 1',
            [(string) $hashPdf]
        ) ?: null;
    }

    public function crear(array $d)
    {
        return $this->insert(
            'INSERT INTO devoluciones
                (sociedad_id, tipo, numero, sucursal, bodega, numero_factura,
                 factura_xml_id, proveedor_codigo_erp, proveedor_nombre_erp,
                 proveedor_id, fecha, estado_erp, usuario_erp, observaciones,
                 cantidad_total, total, nc_esperada_cantidad, nc_esperada_costo,
                 estado, archivo_pdf, ruta_pdf, hash_pdf, advertencias)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $d['sociedad_id'], $d['tipo'], $d['numero'], $d['sucursal'],
                $d['bodega'], $d['numero_factura'], $d['factura_xml_id'],
                $d['proveedor_codigo_erp'], $d['proveedor_nombre_erp'],
                $d['proveedor_id'], $d['fecha'], $d['estado_erp'],
                $d['usuario_erp'], $d['observaciones'], $d['cantidad_total'],
                $d['total'], $d['nc_esperada_cantidad'], $d['nc_esperada_costo'],
                $d['estado'] ?? 'pendiente', $d['archivo_pdf'],
                RutaDocumento::relativa($d['ruta_pdf'] ?? '') ?: null,
                $d['hash_pdf'], $d['advertencias'],
            ]
        );
    }

    /** Los valores de una línea, en el orden en que van al INSERT. */
    private function valoresLinea($devolucionId, array $l)
    {
        return [
            (int) $devolucionId, $l['seccion'], $l['codigo'], $l['nombre'],
            $l['cantidad'], $l['costo'], $l['impuesto'], $l['total'],
            $l['dif_costo'],
        ];
    }

    public function crearLinea($devolucionId, array $l)
    {
        return $this->insert(
            'INSERT INTO devolucion_lineas
                (devolucion_id, seccion, codigo, nombre, cantidad, costo, impuesto, total, dif_costo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->valoresLinea($devolucionId, $l)
        );
    }

    /**
     * Inserta las líneas de una devolución en tandas: con la base en el
     * servidor, cada INSERT cuesta un viaje de ida y vuelta, y una devolución
     * larga se lleva la petición entera de a una.
     */
    public function crearLineasLote($devolucionId, array $lineas, $tam = 200)
    {
        $insertadas = 0;
        foreach (array_chunk(array_values($lineas), max(1, (int) $tam)) as $tanda) {
            $params = [];
            foreach ($tanda as $linea) {
                foreach ($this->valoresLinea($devolucionId, $linea) as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                'INSERT INTO devolucion_lineas
                    (devolucion_id, seccion, codigo, nombre, cantidad, costo, impuesto, total, dif_costo)
                 VALUES ' . implode(', ', array_fill(0, count($tanda), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                $params
            );
            $insertadas += count($tanda);
        }

        return $insertadas;
    }

    public function getDevolucion($id)
    {
        return $this->fetchOne(
            'SELECT d.*, p.razon_social AS proveedor_local, p.rfc AS proveedor_rfc,
                    f.consecutivo_completo AS factura_consecutivo, f.clave AS factura_clave,
                    f.total AS factura_total, f.fecha_emision AS factura_fecha
             FROM devoluciones d
             LEFT JOIN proveedores p ON p.id = d.proveedor_id
             LEFT JOIN facturas_xml f ON f.id = d.factura_xml_id
             WHERE d.id = ? LIMIT 1',
            [(int) $id]
        ) ?: null;
    }

    public function getLineas($devolucionId)
    {
        return $this->fetchAll(
            'SELECT * FROM devolucion_lineas WHERE devolucion_id = ? ORDER BY id',
            [(int) $devolucionId]
        );
    }

    public function getMatches($devolucionId)
    {
        return $this->fetchAll(
            'SELECT m.*, f.consecutivo_completo AS nc_consecutivo, f.clave AS nc_clave,
                    f.fecha_emision AS nc_fecha, f.total AS nc_total,
                    p.razon_social AS nc_proveedor
             FROM devolucion_matches m
             LEFT JOIN facturas_xml f ON f.id = m.factura_xml_id
             LEFT JOIN proveedores p ON p.id = f.proveedor_id
             WHERE m.devolucion_id = ?
             ORDER BY FIELD(m.objetivo, \'cantidad\', \'costo\', \'total\'),
                      FIELD(m.estado, \'confirmado\', \'sugerido\', \'sin_nc\', \'descartado\'), m.id',
            [(int) $devolucionId]
        );
    }

    public function listar(array $filtros = [])
    {
        $where = ['1=1'];
        $params = [];

        // Si el controlador no indica sociedad, manda la seleccionada: nunca
        // se listan devoluciones de todas las empresas por descuido.
        $sociedadFiltro = (int) ($filtros['sociedad_id'] ?? 0) ?: $this->sociedadId();
        if ($sociedadFiltro > 0) {
            $where[] = 'd.sociedad_id = ?';
            $params[] = $sociedadFiltro;
        }
        if (!empty($filtros['tipo'])) {
            $where[] = 'd.tipo = ?';
            $params[] = (string) $filtros['tipo'];
        }
        if (!empty($filtros['estado'])) {
            $where[] = 'd.estado = ?';
            $params[] = (string) $filtros['estado'];
        }
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(d.numero LIKE ? OR d.numero_factura LIKE ?
                         OR d.proveedor_nombre_erp LIKE ? OR p.razon_social LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        // El reporte trae el código del ERP y, cuando se pudo resolver, el
        // proveedor local. El nombre queda de respaldo para los reportes
        // viejos que solo tienen eso.
        $proveedor = ProveedorCatalogo::condicion(
            $filtros['proveedor'] ?? '',
            [
                'codigo' => 'd.proveedor_codigo_erp',
                'proveedor_id' => 'd.proveedor_id',
                'cedula' => 'p.rfc',
                'nombre' => 'd.proveedor_nombre_erp',
            ],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
        }

        return $this->fetchAll(
            'SELECT d.*, p.razon_social AS proveedor_local,
                    (SELECT COUNT(*) FROM devolucion_matches m
                      WHERE m.devolucion_id = d.id AND m.estado = \'confirmado\') AS matches_confirmados,
                    (SELECT COUNT(*) FROM devolucion_matches m
                      WHERE m.devolucion_id = d.id AND m.estado = \'sugerido\') AS matches_sugeridos
             FROM devoluciones d
             LEFT JOIN proveedores p ON p.id = d.proveedor_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY d.fecha DESC, d.id DESC
             LIMIT 500',
            $params
        );
    }

    public function resumen($sociedadId = null)
    {
        $where = $sociedadId ? 'WHERE sociedad_id = ? OR sociedad_id IS NULL' : '';
        $params = $sociedadId ? [(int) $sociedadId] : [];
        $filas = $this->fetchAll(
            "SELECT estado, COUNT(*) AS n FROM devoluciones {$where} GROUP BY estado",
            $params
        );
        $resumen = ['pendiente' => 0, 'sin_nc' => 0, 'parcial' => 0, 'verificada' => 0, 'total' => 0];
        foreach ($filas as $f) {
            $resumen[(string) $f['estado']] = (int) $f['n'];
            $resumen['total'] += (int) $f['n'];
        }
        return $resumen;
    }

    // ------------------------------------------------------------------
    // Soporte del verificador
    // ------------------------------------------------------------------

    public function pendientesDeVerificar($sociedadId = null)
    {
        $where = $sociedadId !== null ? 'AND (sociedad_id = ? OR sociedad_id IS NULL)' : '';
        $params = $sociedadId !== null ? [(int) $sociedadId] : [];
        return $this->fetchAll(
            "SELECT id FROM devoluciones WHERE estado <> 'verificada' {$where} ORDER BY id",
            $params
        );
    }

    /** Borra matches no manuales de una devolución antes de re-verificar. */
    public function limpiarMatchesAutomaticos($devolucionId)
    {
        return $this->execute(
            'DELETE FROM devolucion_matches
             WHERE devolucion_id = ? AND metodo <> \'manual\' AND estado <> \'descartado\'',
            [(int) $devolucionId]
        );
    }

    public function crearMatch(array $m)
    {
        return $this->insert(
            'INSERT INTO devolucion_matches
                (devolucion_id, objetivo, monto_esperado, factura_xml_id,
                 metodo, estado, monto_nc, diferencia, nc_consolidada, motivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $m['devolucion_id'], $m['objetivo'], $m['monto_esperado'],
                $m['factura_xml_id'], $m['metodo'], $m['estado'], $m['monto_nc'],
                $m['diferencia'], (int) ($m['nc_consolidada'] ?? 0), $m['motivo'],
            ]
        );
    }

    public function actualizarEstado($devolucionId, $estado, $marcarVerificado = true)
    {
        return $this->execute(
            'UPDATE devoluciones SET estado = ?' . ($marcarVerificado ? ', verificado_en = NOW()' : '') . ' WHERE id = ?',
            [(string) $estado, (int) $devolucionId]
        );
    }

    public function asignarProveedor($devolucionId, $proveedorId)
    {
        return $this->execute(
            'UPDATE devoluciones SET proveedor_id = ? WHERE id = ?',
            [$proveedorId !== null ? (int) $proveedorId : null, (int) $devolucionId]
        );
    }

    public function asignarFactura($devolucionId, $facturaXmlId)
    {
        return $this->execute(
            'UPDATE devoluciones SET factura_xml_id = ? WHERE id = ?',
            [$facturaXmlId !== null ? (int) $facturaXmlId : null, (int) $devolucionId]
        );
    }

    /** Objetivos que ya tienen match manual confirmado (se respetan). */
    public function objetivosManuales($devolucionId)
    {
        $filas = $this->fetchAll(
            'SELECT objetivo FROM devolucion_matches
             WHERE devolucion_id = ? AND metodo = \'manual\' AND estado = \'confirmado\'',
            [(int) $devolucionId]
        );
        return array_map(function ($f) { return (string) $f['objetivo']; }, $filas);
    }

    public function getMatch($matchId)
    {
        return $this->fetchOne(
            'SELECT * FROM devolucion_matches WHERE id = ? LIMIT 1',
            [(int) $matchId]
        ) ?: null;
    }

    public function actualizarMatchEstado($matchId, $estado, $metodo = null)
    {
        $sql = 'UPDATE devolucion_matches SET estado = ?';
        $params = [(string) $estado];
        if ($metodo !== null) {
            $sql .= ', metodo = ?';
            $params[] = (string) $metodo;
        }
        $sql .= ' WHERE id = ?';
        $params[] = (int) $matchId;
        return $this->execute($sql, $params);
    }

    public function crearMatchManual($devolucionId, $objetivo, $montoEsperado, $facturaXmlId, $montoNc, $motivo)
    {
        // Un manual nuevo reemplaza cualquier fila previa del mismo objetivo.
        $this->execute(
            'DELETE FROM devolucion_matches WHERE devolucion_id = ? AND objetivo = ?',
            [(int) $devolucionId, (string) $objetivo]
        );
        return $this->crearMatch([
            'devolucion_id' => (int) $devolucionId,
            'objetivo' => (string) $objetivo,
            'monto_esperado' => $montoEsperado,
            'factura_xml_id' => (int) $facturaXmlId,
            'metodo' => 'manual',
            'estado' => 'confirmado',
            'monto_nc' => $montoNc,
            'diferencia' => round((float) $montoEsperado - (float) $montoNc, 2),
            'nc_consolidada' => 0,
            'motivo' => (string) $motivo,
        ]);
    }

    public function eliminarMatchesObjetivo($devolucionId, $objetivo)
    {
        return $this->execute(
            'DELETE FROM devolucion_matches WHERE devolucion_id = ? AND objetivo = ?',
            [(int) $devolucionId, (string) $objetivo]
        );
    }

    public function eliminar($devolucionId)
    {
        return $this->execute('DELETE FROM devoluciones WHERE id = ?', [(int) $devolucionId]);
    }

    // ------------------------------------------------------------------
    // Consultas sobre facturas/NC para la cascada
    // ------------------------------------------------------------------

    public function facturaPorConsecutivo($consecutivo)
    {
        $digits = preg_replace('/\D+/', '', (string) $consecutivo);
        if (strlen($digits) !== 20) {
            return null;
        }
        $params = [$digits];
        return $this->fetchOne(
            'SELECT id, clave, consecutivo_completo, proveedor_id, total, fecha_emision
             FROM facturas_xml
             WHERE tipo_documento = \'FE\' AND consecutivo_completo = ?'
             . $this->condicionSociedad('', $params) . '
             ORDER BY id DESC LIMIT 1',
            $params
        ) ?: null;
    }

    /** NC que referencian la clave dada, con cuántas claves referencia cada una. */
    public function ncPorClaveReferenciada($clave)
    {
        $digits = preg_replace('/\D+/', '', (string) $clave);
        if ($digits === '') {
            return [];
        }
        $params = [$digits];
        return $this->fetchAll(
            'SELECT f.id, f.consecutivo_completo, f.clave, f.proveedor_id,
                    f.fecha_emision, f.total, f.moneda,
                    (SELECT COUNT(*) FROM facturas_xml_referencias r2
                      WHERE r2.factura_xml_id = f.id AND r2.clave_ref IS NOT NULL) AS total_referencias
             FROM facturas_xml_referencias r
             INNER JOIN facturas_xml f ON f.id = r.factura_xml_id
             WHERE r.clave_ref = ? AND f.tipo_documento = \'NC\''
             . $this->condicionSociedad('f.', $params) . '
             ORDER BY f.fecha_emision, f.id',
            $params
        );
    }

    /** NC del proveedor cercanas en fecha y monto (camino sin referencia). */
    public function ncPorProveedorYMonto($proveedorId, $monto, $fechaDesde, $fechaHasta, $tolerancia)
    {
        $params = [
            (int) $proveedorId,
            (string) $fechaDesde,
            (string) $fechaHasta,
            (float) $monto - (float) $tolerancia,
            (float) $monto + (float) $tolerancia,
        ];
        $filtroSociedad = $this->condicionSociedad('f.', $params);
        $params[] = (float) $monto; // el de ORDER BY va de último
        return $this->fetchAll(
            'SELECT f.id, f.consecutivo_completo, f.clave, f.proveedor_id,
                    f.fecha_emision, f.total, f.moneda
             FROM facturas_xml f
             WHERE f.tipo_documento = \'NC\'
               AND f.proveedor_id = ?
               AND f.fecha_emision BETWEEN ? AND ?
               AND f.total BETWEEN ? AND ?'
             . $filtroSociedad . '
             ORDER BY ABS(f.total - ?), f.fecha_emision
             LIMIT 10',
            $params
        );
    }

    /**
     * NC del proveedor en la ventana de fechas, sin filtro de monto, con su
     * conteo de líneas. Alimenta la sugerencia por líneas (nivel 2.5).
     */
    public function ncPorProveedorEnVentana($proveedorId, $fechaDesde, $fechaHasta, $limite = 30)
    {
        $params = [(int) $proveedorId, (string) $fechaDesde, (string) $fechaHasta];
        return $this->fetchAll(
            'SELECT f.id, f.consecutivo_completo, f.clave, f.proveedor_id,
                    f.fecha_emision, f.total, f.moneda,
                    (SELECT COUNT(*) FROM facturas_xml_lineas l
                      WHERE l.factura_xml_id = f.id) AS num_lineas
             FROM facturas_xml f
             WHERE f.tipo_documento = \'NC\'
               AND f.proveedor_id = ?
               AND f.fecha_emision BETWEEN ? AND ?'
             . $this->condicionSociedad('f.', $params) . '
             ORDER BY f.fecha_emision, f.id
             LIMIT ' . max(1, (int) $limite),
            $params
        );
    }

    /** Líneas XML de un conjunto de NC, agrupadas por factura_xml_id. */
    public function lineasDeNcs(array $facturaXmlIds)
    {
        $ids = array_values(array_filter(array_map('intval', $facturaXmlIds)));
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = $this->fetchAll(
            "SELECT factura_xml_id, codigo_comercial, detalle, cantidad, total_linea
             FROM facturas_xml_lineas
             WHERE factura_xml_id IN ({$marcas})
             ORDER BY factura_xml_id, numero_linea",
            $ids
        );
        $porNc = [];
        foreach ($filas as $f) {
            $porNc[(int) $f['factura_xml_id']][] = $f;
        }
        return $porNc;
    }

    /** NC ya usadas en confirmaciones por monto (evita doble asignación). */
    public function ncConfirmadasPorMonto()
    {
        $filas = $this->fetchAll(
            'SELECT DISTINCT factura_xml_id FROM devolucion_matches
             WHERE estado = \'confirmado\' AND metodo IN (\'monto\', \'manual\')
               AND factura_xml_id IS NOT NULL'
        );
        $ids = [];
        foreach ($filas as $f) {
            $ids[(int) $f['factura_xml_id']] = true;
        }
        return $ids;
    }

    /** Los proveedores que aparecen en los reportes importados, para el filtro. */
    public function proveedoresParaFiltro($sociedadId = null)
    {
        $where = '';
        $params = [];
        $sociedadFiltro = (int) $sociedadId ?: $this->sociedadId();
        if ($sociedadFiltro > 0) {
            $where = 'WHERE d.sociedad_id = ?';
            $params[] = $sociedadFiltro;
        }

        return $this->fetchAll(
            "SELECT COALESCE(d.proveedor_codigo_erp, '') AS codigo,
                    d.proveedor_id AS proveedor_id,
                    MAX(p.rfc) AS cedula,
                    MAX(COALESCE(p.razon_social, d.proveedor_nombre_erp)) AS nombre,
                    COUNT(*) AS n
               FROM devoluciones d
               LEFT JOIN proveedores p ON p.id = d.proveedor_id
               {$where}
              GROUP BY COALESCE(d.proveedor_codigo_erp, ''), d.proveedor_id",
            $params
        ) ?: [];
    }

    public function proveedoresActivos()
    {
        return $this->fetchAll(
            'SELECT id, rfc, razon_social, razon_social_normalizada, alias
             FROM proveedores WHERE activo = 1'
        );
    }

    public function getNc($facturaXmlId)
    {
        $params = [(int) $facturaXmlId];
        return $this->fetchOne(
            'SELECT id, consecutivo_completo, clave, proveedor_id, fecha_emision, total, moneda
             FROM facturas_xml WHERE id = ? AND tipo_documento = \'NC\''
             . $this->condicionSociedad('', $params) . ' LIMIT 1',
            $params
        ) ?: null;
    }

    /** Candidatas para vinculación manual: NC del proveedor ordenadas por cercanía de monto. */
    public function ncCandidatas($proveedorId, $monto, $limite = 15)
    {
        $params = $proveedorId ? [(int) $proveedorId] : [];
        $filtroSociedad = $this->condicionSociedad('f.', $params);
        $params[] = (float) $monto; // el de ORDER BY va de último
        return $this->fetchAll(
            'SELECT f.id, f.consecutivo_completo, f.fecha_emision, f.total, f.moneda,
                    p.razon_social AS proveedor
             FROM facturas_xml f
             LEFT JOIN proveedores p ON p.id = f.proveedor_id
             WHERE f.tipo_documento = \'NC\'' . ($proveedorId ? ' AND f.proveedor_id = ?' : '')
             . $filtroSociedad . '
             ORDER BY ABS(f.total - ?) ASC, f.fecha_emision DESC
             LIMIT ' . max(1, (int) $limite),
            $params
        );
    }
}
