<?php
/**
 * Modelo de Factura
 * Gestiona facturas XML (CFDI)
 */

require_once __DIR__ . '/../helpers/NumeroFactura.php';

class Factura extends Model
{
    protected $table = 'facturas_xml';
    
    /**
     * Obtener todas las facturas con información de importación.
     * Filtro por semana: null = todas · 0 = sin semana (no asignadas) ·
     * >0 = esa semana de trabajo.
     */
    public function getAllWithImportacion($semanaId = null)
    {
        return $this->buscarConImportacion(['semana_id' => $semanaId]);
    }

    /**
     * Consulta de Facturas XML con filtros combinables.
     * La existencia física del par XML/PDF se valida en el controlador,
     * porque SQL solo conoce las rutas registradas.
     */
    public function buscarConImportacion(array $filtros = [])
    {
        $where = ["(f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"];
        $params = [];

        if (array_key_exists('semana_id', $filtros)
            && $filtros['semana_id'] !== null && $filtros['semana_id'] !== '') {
            if ((int) $filtros['semana_id'] > 0) {
                $where[] = 'f.semana_id = ?';
                $params[] = (int) $filtros['semana_id'];
            } else {
                $where[] = 'f.semana_id IS NULL';
            }
        }

        if (!empty($filtros['importacion_id'])) {
            $where[] = 'f.importacion_id = ?';
            $params[] = (int) $filtros['importacion_id'];
        }

        $buscar = trim((string) ($filtros['q'] ?? ''));
        if ($buscar !== '') {
            $like = '%' . $buscar . '%';
            $where[] = '(f.numero_factura_asistente LIKE ?
                         OR f.consecutivo_completo LIKE ?
                         OR f.clave LIKE ?
                         OR p.razon_social LIKE ?
                         OR p.rfc LIKE ?
                         OR f.archivo_xml LIKE ?
                         OR f.archivo_pdf LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $proveedor = trim((string) ($filtros['proveedor'] ?? ''));
        if ($proveedor !== '') {
            $like = '%' . $proveedor . '%';
            $where[] = '(p.razon_social LIKE ? OR p.rfc LIKE ?)';
            array_push($params, $like, $like);
        }

        if (!empty($filtros['fecha_desde'])) {
            $where[] = 'f.fecha_emision >= ?';
            $params[] = (string) $filtros['fecha_desde'];
        }
        if (!empty($filtros['fecha_hasta'])) {
            $where[] = 'f.fecha_emision <= ?';
            $params[] = (string) $filtros['fecha_hasta'];
        }
        if (isset($filtros['monto_desde']) && $filtros['monto_desde'] !== '') {
            $where[] = 'f.total >= ?';
            $params[] = (float) $filtros['monto_desde'];
        }
        if (isset($filtros['monto_hasta']) && $filtros['monto_hasta'] !== '') {
            $where[] = 'f.total <= ?';
            $params[] = (float) $filtros['monto_hasta'];
        }

        $sql = "SELECT f.*, p.razon_social as proveedor_nombre, i.archivo_origen as archivo_importacion, i.fecha_importacion,
                       s.nombre as semana_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                LEFT JOIN importaciones i ON f.importacion_id = i.id
                LEFT JOIN semanas s ON f.semana_id = s.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.fecha_emision DESC, f.id DESC";

        return $this->fetchAll($sql, $params);
    }
    
    /**
     * Buscar factura por UUID
     */
    public function findByUuid($uuid)
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE consecutivo_completo = ? AND (tipo_documento IS NULL OR tipo_documento = 'FE') LIMIT 1";
        return $this->fetchOne($sql, [$uuid]);
    }
    
    /**
     * Buscar facturas por proveedor
     */
    public function findByProveedor($proveedorId)
    {
        $sql = "SELECT * FROM {$this->table} WHERE proveedor_id = ?
                AND (tipo_documento IS NULL OR tipo_documento = 'FE') ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, [$proveedorId]);
    }
    
    /**
     * Buscar facturas por número
     */
    public function findByNumero($numero)
    {
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura_asistente LIKE ?
                AND (tipo_documento IS NULL OR tipo_documento = 'FE') ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, ['%' . $numero . '%']);
    }
    
    /**
     * Obtener facturas por rango de fechas
     */
    public function findByFechaRange($fechaInicio, $fechaFin)
    {
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.fecha_emision BETWEEN ? AND ?
                  AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')
                ORDER BY f.fecha_emision DESC";
        
        return $this->fetchAll($sql, [$fechaInicio, $fechaFin]);
    }
    
    /**
     * Asignar o cambiar la semana de trabajo de una factura (null = quitarla).
     */
    public function asignarSemana($facturaId, $semanaId)
    {
        $sql = "UPDATE {$this->table} SET semana_id = ?
                WHERE id = ? AND (tipo_documento IS NULL OR tipo_documento = 'FE')";
        return $this->execute($sql, [!empty($semanaId) ? (int) $semanaId : null, (int) $facturaId]);
    }

    /**
     * Insertar nueva factura
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table}
                (importacion_id, semana_id, consecutivo_completo, clave, tipo_documento, receptor_id,
                 numero_factura_asistente, proveedor_id, fecha_emision,
                 subtotal, iva, total, moneda, tipo_comprobante, archivo_xml, ruta_xml, ruta_pdf,
                 archivo_pdf, hash_pdf, estado_pdf, correo_cuenta_id, correo_carpeta,
                 correo_uidvalidity, correo_uid, fecha_correo, archivado_en,
                 hash_xml, xml_contenido, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $data['importacion_id'] ?? null,
            !empty($data['semana_id']) ? (int) $data['semana_id'] : null,
            $data['consecutivo_completo'] ?? $data['uuid'] ?? '',
            $data['clave'] ?? null,
            $data['tipo_documento'] ?? null,
            $data['receptor_id'] ?? null,
            NumeroFactura::xmlOchoDigitos($data['numero_factura_asistente'] ?? $data['numero_factura'] ?? ''),
            $data['proveedor_id'],
            $data['fecha_emision'],
            $data['subtotal'] ?? 0,
            $data['iva'] ?? 0,
            $data['total'] ?? 0,
            $data['moneda'] ?? 'CRC',
            $data['tipo_comprobante'] ?? null,
            $data['archivo_xml'] ?? 'sin_archivo.xml',
            $data['ruta_xml'] ?? null,
            $data['ruta_pdf'] ?? null,
            $data['archivo_pdf'] ?? null,
            $data['hash_pdf'] ?? null,
            $data['estado_pdf'] ?? 'pendiente',
            !empty($data['correo_cuenta_id']) ? (int) $data['correo_cuenta_id'] : null,
            $data['correo_carpeta'] ?? null,
            isset($data['correo_uidvalidity']) ? (int) $data['correo_uidvalidity'] : null,
            isset($data['correo_uid']) ? (int) $data['correo_uid'] : null,
            $data['fecha_correo'] ?? null,
            $data['archivado_en'] ?? null,
            $data['hash_xml'] ?? null,
            null, // los archivos XML viven en el árbol local, nunca como blob en BD
            $data['metadata'] ?? null
        ];
        
        return $this->insert($sql, $params);
    }
    
    /**
     * Obtener suma de totales
     */
    public function getTotalMonto()
    {
        $sql = "SELECT COALESCE(SUM(total), 0) FROM {$this->table}
                WHERE tipo_documento IS NULL OR tipo_documento = 'FE'";
        return (float) $this->fetchColumn($sql);
    }

    public function contarFacturas()
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}
                                        WHERE tipo_documento IS NULL OR tipo_documento = 'FE'");
    }

    /**
     * Eliminar todas las facturas (uso en pruebas)
     */
    public function clearAll()
    {
        $sql = "DELETE FROM {$this->table}";
        return $this->execute($sql);
    }

    public function existsByHash(string $hash): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE hash_xml = ? LIMIT 1";
        return (bool) $this->fetchColumn($sql, [$hash]);
    }

    public function findByHashRecord(string $hash)
    {
        if ($hash === '') {
            return null;
        }
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.hash_xml = ? LIMIT 1";
        $row = $this->fetchOne($sql, [$hash]);
        return $row ?: null;
    }

    public function findByConsecutivoRecord(string $consecutivo, int $proveedorId, string $fechaEmision)
    {
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.consecutivo_completo = ? AND f.proveedor_id = ? AND f.fecha_emision = ?
                LIMIT 1";
        $row = $this->fetchOne($sql, [$consecutivo, $proveedorId, $fechaEmision]);
        return $row ?: null;
    }

    public function getOneWithProvider($id)
    {
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre, p.rfc AS proveedor_cedula
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.id = ? LIMIT 1";
        $row = $this->fetchOne($sql, [(int) $id]);
        return $row ?: null;
    }

    public function actualizarArchivos($id, array $data)
    {
        $sql = "UPDATE {$this->table} SET
                    ruta_xml = COALESCE(?, ruta_xml),
                    archivo_xml = COALESCE(?, archivo_xml),
                    hash_xml = COALESCE(?, hash_xml),
                    ruta_pdf = COALESCE(?, ruta_pdf),
                    archivo_pdf = COALESCE(?, archivo_pdf),
                    hash_pdf = COALESCE(?, hash_pdf),
                    estado_pdf = COALESCE(?, estado_pdf),
                    correo_cuenta_id = COALESCE(?, correo_cuenta_id),
                    correo_carpeta = COALESCE(?, correo_carpeta),
                    correo_uidvalidity = COALESCE(?, correo_uidvalidity),
                    correo_uid = COALESCE(?, correo_uid),
                    fecha_correo = COALESCE(?, fecha_correo),
                    archivado_en = COALESCE(?, archivado_en)
                WHERE id = ?";
        return $this->execute($sql, [
            $data['ruta_xml'] ?? null,
            $data['archivo_xml'] ?? null,
            $data['hash_xml'] ?? null,
            $data['ruta_pdf'] ?? null,
            $data['archivo_pdf'] ?? null,
            $data['hash_pdf'] ?? null,
            $data['estado_pdf'] ?? null,
            !empty($data['correo_cuenta_id']) ? (int) $data['correo_cuenta_id'] : null,
            $data['correo_carpeta'] ?? null,
            isset($data['correo_uidvalidity']) ? (int) $data['correo_uidvalidity'] : null,
            isset($data['correo_uid']) ? (int) $data['correo_uid'] : null,
            $data['fecha_correo'] ?? null,
            $data['archivado_en'] ?? null,
            (int) $id,
        ]);
    }

    public function existsByConsecutivo(string $consecutivo, int $proveedorId = 0, string $fechaEmision = ''): bool
    {
        if ($proveedorId > 0 && $fechaEmision !== '') {
            $sql = "SELECT 1 FROM {$this->table}
                    WHERE consecutivo_completo = ? AND proveedor_id = ? AND fecha_emision = ?
                    LIMIT 1";
            return (bool) $this->fetchColumn($sql, [$consecutivo, $proveedorId, $fechaEmision]);
        }
        $sql = "SELECT 1 FROM {$this->table} WHERE consecutivo_completo = ? LIMIT 1";
        return (bool) $this->fetchColumn($sql, [$consecutivo]);
    }

    public function getByImportacion(int $importacionId): array
    {
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.importacion_id = ?
                  AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')
                ORDER BY f.fecha_emision DESC";
        return $this->fetchAll($sql, [$importacionId]) ?: [];
    }

    public function getNotasXml($desde = '', $hasta = '', $buscar = '', $page = 1, $perPage = 100)
    {
        $where = ["f.tipo_documento = 'NC'"];
        $params = [];
        if ($desde !== '') {
            $where[] = 'f.fecha_emision >= ?';
            $params[] = $desde;
        }
        if ($hasta !== '') {
            $where[] = 'f.fecha_emision <= ?';
            $params[] = $hasta;
        }
        if ($buscar !== '') {
            $where[] = '(f.consecutivo_completo LIKE ? OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)';
            $like = '%' . $buscar . '%';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(500, (int) $perPage));
        $offset = (max(1, (int) $page) - 1) * $limit;
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre, i.archivo_origen AS archivo_importacion
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                LEFT JOIN importaciones i ON i.id = f.importacion_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.fecha_emision DESC, f.id DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function countNotasXml($desde = '', $hasta = '', $buscar = '')
    {
        $where = ["f.tipo_documento = 'NC'"];
        $params = [];
        if ($desde !== '') { $where[] = 'f.fecha_emision >= ?'; $params[] = $desde; }
        if ($hasta !== '') { $where[] = 'f.fecha_emision <= ?'; $params[] = $hasta; }
        if ($buscar !== '') {
            $where[] = '(f.consecutivo_completo LIKE ? OR f.numero_factura_asistente LIKE ? OR p.razon_social LIKE ?)';
            $like = '%' . $buscar . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = "SELECT COUNT(*) FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE " . implode(' AND ', $where);
        return (int) $this->fetchColumn($sql, $params);
    }

    public function getConContenidoParaMigrar($limit = 100)
    {
        $limit = max(1, min(1000, (int) $limit));
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.xml_contenido IS NOT NULL AND f.xml_contenido <> ''
                ORDER BY f.id ASC LIMIT {$limit}";
        return $this->fetchAll($sql) ?: [];
    }

    public function confirmarMigracionArchivo($id, array $archivo)
    {
        $sql = "UPDATE {$this->table} SET ruta_xml = ?, archivo_xml = ?, hash_xml = ?,
                    estado_pdf = CASE WHEN ruta_pdf IS NULL OR ruta_pdf = '' THEN 'no_disponible_historico' ELSE 'disponible' END,
                    archivado_en = ?, xml_contenido = NULL
                WHERE id = ? AND xml_contenido IS NOT NULL";
        return $this->execute($sql, [
            $archivo['ruta_xml'], $archivo['archivo_xml'], $archivo['hash_xml'],
            $archivo['archivado_en'] ?? date('Y-m-d H:i:s'), (int) $id,
        ]);
    }

    public function contarContenidoEnBase()
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}
                                        WHERE xml_contenido IS NOT NULL AND xml_contenido <> ''");
    }

    /** Documentos y estado de conciliación necesarios para ordenar el archivo local. */
    public function getParaOrganizarArchivos(array $ids = [])
    {
        $where = '';
        $params = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            $where = 'WHERE f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }

        $sql = "SELECT f.id, f.tipo_documento, f.fecha_emision,
                       f.numero_factura_asistente, p.razon_social AS proveedor_nombre,
                       f.ruta_xml, f.archivo_xml, f.ruta_pdf, f.archivo_pdf,
                       f.hash_xml, f.hash_pdf, f.estado_pdf,
                       f.semana_id, sem.nombre AS semana_nombre,
                       sem.carpeta_pago AS carpeta_pago,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM porpagar_facturas pf
                           WHERE pf.factura_xml_id = f.id AND pf.estado = 'con_diferencia'
                       ) OR EXISTS (
                           SELECT 1 FROM notas_credito_lineas nl
                           WHERE nl.factura_xml_id = f.id AND nl.estado = 'con_diferencia'
                       ) THEN 1 ELSE 0 END AS con_diferencia,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM porpagar_facturas pf
                           WHERE pf.factura_xml_id = f.id AND pf.estado = 'respaldada'
                       ) OR EXISTS (
                           SELECT 1 FROM notas_credito_lineas nl
                           WHERE nl.factura_xml_id = f.id AND nl.estado = 'coincide'
                       ) THEN 1 ELSE 0 END AS coincide_registro,
                       CASE WHEN EXISTS (
                           SELECT 1
                           FROM porpagar_facturas psp
                            JOIN porpagar_listados lsp ON lsp.id = psp.listado_id
                            WHERE psp.factura_xml_id = f.id
                              AND (psp.estado = 'respaldada'
                                   OR (psp.estado = 'con_diferencia' AND psp.match_manual = 0))
                              AND lsp.semana_id = f.semana_id
                        ) THEN 1 ELSE 0 END AS pago_semanal
                FROM {$this->table} f
                LEFT JOIN semanas sem ON sem.id = f.semana_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                {$where}
                ORDER BY f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function actualizarUbicacionArchivos($id, $rutaXml, $rutaPdf)
    {
        $rutaXml = trim((string) $rutaXml);
        $rutaPdf = trim((string) $rutaPdf);
        $pdfDisponible = $rutaPdf !== '' && is_file($rutaPdf);
        return $this->execute(
            "UPDATE {$this->table}
             SET ruta_xml = ?, archivo_xml = ?, ruta_pdf = ?, archivo_pdf = ?,
                 estado_pdf = ?
             WHERE id = ?",
            [
                $rutaXml !== '' ? $rutaXml : null,
                $rutaXml !== '' ? basename($rutaXml) : null,
                $rutaPdf !== '' ? $rutaPdf : null,
                $rutaPdf !== '' ? basename($rutaPdf) : null,
                $pdfDisponible ? 'disponible' : 'pendiente',
                (int) $id,
            ]
        );
    }

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return true;
    }
}
