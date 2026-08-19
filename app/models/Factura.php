<?php
/**
 * Modelo de Factura
 * Gestiona facturas XML (CFDI)
 */

require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/ProveedorCatalogo.php';

class Factura extends Model
{
    // Listados, conteos y totales quedan acotados a la sociedad seleccionada.
    // Las búsquedas por hash/consecutivo NO se acotan a propósito: sirven para
    // detectar que un documento ya existe, y un mismo XML tiene un solo
    // receptor, así que verlo en otra empresa es una anomalía que conviene
    // encontrar, no ocultar.
    use AlcanceSociedad;

    protected $table = 'facturas_xml';

    // El XML y el PDF viven en la carpeta compartida, no dentro del proyecto
    // ni en la base: aquí solo se guarda dónde encontrarlos, relativo a esa
    // carpeta, para que la fila sirva en cualquier computadora del grupo.
    protected $camposRuta = ['ruta_xml', 'ruta_pdf'];

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
        $this->filtrarPorSociedad($where, $params, 'f.');

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

        // Por el emisor del comprobante: la cédula del XML es la identidad,
        // no el nombre con el que cada quien lo escribió.
        $proveedor = ProveedorCatalogo::condicion(
            $filtros['proveedor'] ?? '',
            ['proveedor_id' => 'f.proveedor_id', 'cedula' => 'p.rfc'],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
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
        $params = [$proveedorId];
        $sql = "SELECT * FROM {$this->table} WHERE proveedor_id = ?
                AND (tipo_documento IS NULL OR tipo_documento = 'FE')"
             . $this->condicionSociedad('', $params) . " ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, $params);
    }

    /**
     * Buscar facturas por número
     */
    public function findByNumero($numero)
    {
        $params = ['%' . $numero . '%'];
        $sql = "SELECT * FROM {$this->table} WHERE numero_factura_asistente LIKE ?
                AND (tipo_documento IS NULL OR tipo_documento = 'FE')"
             . $this->condicionSociedad('', $params) . " ORDER BY fecha_emision DESC";
        return $this->fetchAll($sql, $params);
    }

    /**
     * Obtener facturas por rango de fechas
     */
    public function findByFechaRange($fechaInicio, $fechaFin)
    {
        $params = [$fechaInicio, $fechaFin];
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.fecha_emision BETWEEN ? AND ?
                  AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params) . "
                ORDER BY f.fecha_emision DESC";

        return $this->fetchAll($sql, $params);
    }

    /**
     * Asignar o cambiar la semana de trabajo de una factura (null = quitarla).
     * Acotado: no se puede mover una factura de otra sociedad.
     */
    public function asignarSemana($facturaId, $semanaId)
    {
        $params = [!empty($semanaId) ? (int) $semanaId : null, (int) $facturaId];
        $sql = "UPDATE {$this->table} SET semana_id = ?
                WHERE id = ? AND (tipo_documento IS NULL OR tipo_documento = 'FE')"
             . $this->condicionSociedad('', $params);
        return $this->execute($sql, $params);
    }

    /**
     * Insertar nueva factura
     */
    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table}
                (importacion_id, semana_id, consecutivo_completo, clave, tipo_documento, receptor_id,
                 sociedad_id,
                 numero_factura_asistente, proveedor_id, fecha_emision,
                 subtotal, iva, total, moneda, tipo_comprobante, archivo_xml, ruta_xml, ruta_pdf,
                 archivo_pdf, hash_pdf, estado_pdf, correo_cuenta_id, correo_carpeta,
                 correo_uidvalidity, correo_uid, fecha_correo, archivado_en,
                 hash_xml, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        // El sello de sociedad viene de quien importa; si no lo indica, es la
        // seleccionada. Nunca queda NULL: un documento sin dueño no aparecería
        // en ningún listado.
        $sociedadId = !empty($data['sociedad_id']) ? (int) $data['sociedad_id'] : $this->sociedadId();

        $params = [
            $data['importacion_id'] ?? null,
            !empty($data['semana_id']) ? (int) $data['semana_id'] : null,
            $data['consecutivo_completo'] ?? $data['uuid'] ?? '',
            $data['clave'] ?? null,
            $data['tipo_documento'] ?? null,
            $data['receptor_id'] ?? null,
            $sociedadId > 0 ? $sociedadId : null,
            NumeroFactura::xmlOchoDigitos($data['numero_factura_asistente'] ?? $data['numero_factura'] ?? ''),
            $data['proveedor_id'],
            $data['fecha_emision'],
            $data['subtotal'] ?? 0,
            $data['iva'] ?? 0,
            $data['total'] ?? 0,
            $data['moneda'] ?? 'CRC',
            $data['tipo_comprobante'] ?? null,
            $data['archivo_xml'] ?? 'sin_archivo.xml',
            RutaDocumento::relativa($data['ruta_xml'] ?? '') ?: null,
            RutaDocumento::relativa($data['ruta_pdf'] ?? '') ?: null,
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
            $data['metadata'] ?? null
        ];
        
        return $this->insert($sql, $params);
    }
    
    /**
     * Obtener suma de totales
     */
    public function getTotalMonto()
    {
        $params = [];
        $sql = "SELECT COALESCE(SUM(total), 0) FROM {$this->table}
                WHERE (tipo_documento IS NULL OR tipo_documento = 'FE')"
             . $this->condicionSociedad('', $params);
        return (float) $this->fetchColumn($sql, $params);
    }

    public function contarFacturas()
    {
        $params = [];
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE (tipo_documento IS NULL OR tipo_documento = 'FE')"
             . $this->condicionSociedad('', $params);
        return (int) $this->fetchColumn($sql, $params);
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
            RutaDocumento::relativa($data['ruta_xml'] ?? '') ?: null,
            $data['archivo_xml'] ?? null,
            $data['hash_xml'] ?? null,
            RutaDocumento::relativa($data['ruta_pdf'] ?? '') ?: null,
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
        $params = [$importacionId];
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                WHERE f.importacion_id = ?
                  AND (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params) . "
                ORDER BY f.fecha_emision DESC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function getNotasXml($desde = '', $hasta = '', $buscar = '', $proveedor = '', $page = 1, $perPage = 100)
    {
        [$where, $params] = $this->condicionesNotasXml($desde, $hasta, $buscar, $proveedor);
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

    public function countNotasXml($desde = '', $hasta = '', $buscar = '', $proveedor = '')
    {
        [$where, $params] = $this->condicionesNotasXml($desde, $hasta, $buscar, $proveedor);
        $sql = "SELECT COUNT(*) FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE " . implode(' AND ', $where);
        return (int) $this->fetchColumn($sql, $params);
    }

    /** Las condiciones de la lista de notas XML, una sola vez para listar y contar. */
    private function condicionesNotasXml($desde, $hasta, $buscar, $proveedor)
    {
        $where = ["f.tipo_documento = 'NC'"];
        $params = [];
        $this->filtrarPorSociedad($where, $params, 'f.');
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
        $condicionProveedor = ProveedorCatalogo::condicion(
            $proveedor,
            ['proveedor_id' => 'f.proveedor_id', 'cedula' => 'p.rfc'],
            $params
        );
        if ($condicionProveedor !== '') {
            $where[] = $condicionProveedor;
        }

        return [$where, $params];
    }

    /**
     * Los proveedores que emitieron documentos XML, para el filtro.
     *
     * @param string $tipo 'FE' facturas · 'NC' notas de crédito
     */
    public function proveedoresParaFiltro($tipo = 'FE')
    {
        $where = $tipo === 'NC'
            ? ["f.tipo_documento = 'NC'"]
            : ["(f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"];
        $params = [];
        $this->filtrarPorSociedad($where, $params, 'f.');

        return $this->fetchAll(
            "SELECT f.proveedor_id AS proveedor_id, MAX(p.rfc) AS cedula,
                    MAX(p.razon_social) AS nombre, COUNT(*) AS n
               FROM {$this->table} f
               LEFT JOIN proveedores p ON p.id = f.proveedor_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY f.proveedor_id",
            $params
        ) ?: [];
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
                           SELECT 1 FROM facturas_erp pe
                           WHERE pe.factura_xml_id = f.id AND pe.estado_respaldo = 'con_diferencia'
                       ) OR EXISTS (
                           SELECT 1 FROM notas_credito_lineas nl
                           WHERE nl.factura_xml_id = f.id AND nl.estado = 'con_diferencia'
                       ) THEN 1 ELSE 0 END AS con_diferencia,
                       CASE WHEN EXISTS (
                           SELECT 1 FROM facturas_erp pe
                           WHERE pe.factura_xml_id = f.id AND pe.estado_respaldo = 'respaldada'
                       ) OR EXISTS (
                           SELECT 1 FROM notas_credito_lineas nl
                           WHERE nl.factura_xml_id = f.id AND nl.estado = 'coincide'
                       ) THEN 1 ELSE 0 END AS coincide_registro,
                       -- Un vínculo hecho a mano vale lo mismo que uno
                       -- automático. Antes se excluía de la carpeta de pago
                       -- el que tuviera diferencia de monto y fuera manual, y
                       -- el efecto era el contrario del buscado: una factura
                       -- ya entregada SALÍA de la carpeta en cuanto alguien la
                       -- emparejaba a mano, sin que nada lo avisara. Quien
                       -- vincula a mano está afirmando que ese respaldo es el
                       -- correcto; la diferencia de monto ya se ve marcada en
                       -- el listado, que es donde hay que resolverla.
                       CASE WHEN EXISTS (
                           SELECT 1
                           FROM facturas_erp psp
                            JOIN porpagar_listados lsp ON lsp.id = psp.porpagar_listado_id
                            WHERE psp.factura_xml_id = f.id
                              AND psp.estado_respaldo IN ('respaldada', 'con_diferencia')
                              AND lsp.semana_id = f.semana_id
                        ) THEN 1 ELSE 0 END AS pago_semanal
                FROM {$this->table} f
                LEFT JOIN semanas sem ON sem.id = f.semana_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                {$where}
                ORDER BY f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Comprobantes electrónicos con los que se puede respaldar un pago.
     *
     * Son todas las facturas de la sociedad, sin filtrar por semana: desde que
     * el pago semanal es una selección de facturas del ERP, la semana del XML
     * dejó de ser un dato que alguien asigne a mano y pasó a deducirse del
     * pago. Filtrar por ella acá dejaría fuera justamente lo que se busca.
     *
     * El cruce contra el ERP es por consecutivo —una igualdad—, así que traer
     * de más no afloja nada: solo agranda el índice en memoria.
     */
    public function getCandidatasParaPago()
    {
        $params = [];
        // `proveedor_id` y la cédula viajan para la guarda del emparejador: el
        // consecutivo de veinte dígitos lo arma cada emisor por su cuenta, así
        // que dos proveedores pueden compartirlo y hay que poder preguntar de
        // quién es este comprobante antes de darlo por bueno.
        $sql = "SELECT f.id, f.consecutivo_completo, f.numero_factura_asistente, f.total,
                       f.fecha_emision, f.semana_id, f.proveedor_id,
                       p.rfc AS proveedor_cedula, p.razon_social AS proveedor_nombre
                  FROM {$this->table} f
                  LEFT JOIN proveedores p ON p.id = f.proveedor_id
                 WHERE (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params) . "
                 ORDER BY f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * IDs de las facturas asignadas a una semana. Lo usa la orden de ordenar
     * el pago semanal, que solo debe alcanzar esas facturas y ninguna otra.
     */
    public function getIdsPorSemana($semanaId)
    {
        $semanaId = (int) $semanaId;
        if ($semanaId <= 0) {
            return [];
        }
        $filas = $this->fetchAll(
            "SELECT id FROM {$this->table} WHERE semana_id = ? ORDER BY id ASC",
            [$semanaId]
        );
        return array_map('intval', array_column($filas ?: [], 'id'));
    }

    public function actualizarUbicacionArchivos($id, $rutaXml, $rutaPdf)
    {
        // Entran rutas del disco de esta máquina (quien organiza el archivo
        // trabaja con rutas reales) y salen guardadas relativas a la carpeta
        // compartida. archivo_xml/archivo_pdf son solo el nombre, no la ruta.
        $rutaXml = trim((string) $rutaXml);
        $rutaPdf = trim((string) $rutaPdf);
        $pdfDisponible = $rutaPdf !== '' && RutaDocumento::existe($rutaPdf);
        return $this->execute(
            "UPDATE {$this->table}
             SET ruta_xml = ?, archivo_xml = ?, ruta_pdf = ?, archivo_pdf = ?,
                 estado_pdf = ?
             WHERE id = ?",
            [
                RutaDocumento::relativa($rutaXml) ?: null,
                $rutaXml !== '' ? basename($rutaXml) : null,
                RutaDocumento::relativa($rutaPdf) ?: null,
                $rutaPdf !== '' ? basename($rutaPdf) : null,
                $pdfDisponible ? 'disponible' : 'pendiente',
                (int) $id,
            ]
        );
    }

    /**
     * Lo mismo que actualizarUbicacionArchivos pero para muchos documentos de
     * una sola vez. Ordenar una semana son cientos de documentos, y con la
     * base en el servidor cada consulta cuesta un viaje de ida y vuelta: de a
     * uno, la petición se pasa del tiempo máximo antes de terminar.
     *
     * El ELSE de cada CASE deja intacta cualquier fila que no venga en el
     * lote, así que la consulta no puede tocar lo que no le corresponde.
     */
    public function actualizarUbicacionArchivosLote(array $filas)
    {
        if (!$filas) {
            return 0;
        }

        $columnas = ['ruta_xml', 'archivo_xml', 'ruta_pdf', 'archivo_pdf', 'estado_pdf'];
        $valores = [];
        foreach ($filas as $fila) {
            $rutaXml = trim((string) ($fila['ruta_xml'] ?? ''));
            $rutaPdf = trim((string) ($fila['ruta_pdf'] ?? ''));
            $valores[] = [
                'id'          => (int) $fila['id'],
                'ruta_xml'    => RutaDocumento::relativa($rutaXml) ?: null,
                'archivo_xml' => $rutaXml !== '' ? basename($rutaXml) : null,
                'ruta_pdf'    => RutaDocumento::relativa($rutaPdf) ?: null,
                'archivo_pdf' => $rutaPdf !== '' ? basename($rutaPdf) : null,
                'estado_pdf'  => $rutaPdf !== '' && RutaDocumento::existe($rutaPdf)
                    ? 'disponible' : 'pendiente',
            ];
        }

        $sets = [];
        $params = [];
        foreach ($columnas as $columna) {
            $caso = "{$columna} = CASE id";
            foreach ($valores as $valor) {
                $caso .= ' WHEN ? THEN ?';
                $params[] = $valor['id'];
                $params[] = $valor[$columna];
            }
            $sets[] = $caso . " ELSE {$columna} END";
        }

        $ids = array_column($valores, 'id');
        return (int) $this->execute(
            "UPDATE {$this->table} SET " . implode(', ', $sets)
            . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge($params, $ids)
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
