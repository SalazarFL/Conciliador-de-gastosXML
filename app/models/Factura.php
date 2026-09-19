<?php
/**
 * Modelo de Factura
 * Gestiona facturas XML (CFDI)
 */

require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/../helpers/BusquedaDocumento.php';
require_once __DIR__ . '/../helpers/BusquedaImporte.php';
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
     * Filtro por pago: null = todas · 0 = sin pago (no asignadas) ·
     * >0 = las de ese pago.
     */
    public function getAllWithImportacion($pagoId = null)
    {
        return $this->buscarConImportacion(['pago_id' => $pagoId]);
    }

    /**
     * El registro del ERP con el que está enganchado cada comprobante.
     *
     * Vive aparte porque lo necesitan el listado —para enseñar el saldo— y el
     * conteo —para que buscar por saldo cuente lo mismo que lista—. Si solo
     * lo llevara uno de los dos, la paginación diría "200 de 8.519" sobre una
     * búsqueda que devuelve doce.
     *
     * Se resuelve contra una tabla derivada que se queda con el PRIMER
     * registro de cada comprobante. Un JOIN directo no servía: nada impide que
     * dos filas del ERP apunten al mismo XML —pasa cuando se carga dos veces
     * el reporte y el emparejador acierta las dos— y ahí el comprobante
     * saldría duplicado. Es el mismo arreglo que usa la cola de seguimiento.
     */
    private function joinEnganche($tabla)
    {
        return "LEFT JOIN (SELECT factura_xml_id, MIN(id) AS id
                             FROM {$tabla} WHERE factura_xml_id IS NOT NULL
                            GROUP BY factura_xml_id) eng ON eng.factura_xml_id = f.id
                LEFT JOIN {$tabla} erp ON erp.id = eng.id";
    }

    /**
     * Consulta de Facturas XML con filtros combinables.
     * La existencia física del par XML/PDF se valida en el controlador,
     * porque SQL solo conoce las rutas registradas.
     */
    public function buscarConImportacion(array $filtros = [])
    {
        $params = [];
        $where = $this->condicionesListado($filtros, $params);

        /*
         * El saldo no es del comprobante: un XML dice cuánto se facturó, no
         * cuánto queda por pagar. Sale del registro del ERP al que esté
         * enganchado, y viene NULL cuando no lo está —que es una respuesta,
         * no un cero—.
         */
        // La cédula viaja con el nombre: es una columna que el listado puede
        // encender, y sin ella habría que ir a la ficha del proveedor por cada
        // renglón para saber a quién se le está pagando.
        $sql = "SELECT f.*, p.razon_social as proveedor_nombre, p.rfc AS proveedor_cedula,
                       i.archivo_origen as archivo_importacion, i.fecha_importacion,
                       s.nombre as pago_nombre,
                       COALESCE(erp.saldo_pago, erp.saldo) AS saldo_erp,
                       erp.documento AS documento_erp
                FROM {$this->table} f
                LEFT JOIN proveedores p ON f.proveedor_id = p.id
                LEFT JOIN importaciones i ON f.importacion_id = i.id
                LEFT JOIN pagos s ON f.pago_id = s.id
                " . $this->joinEnganche('facturas_erp') . "
                WHERE {$where}
                ORDER BY f.fecha_emision DESC, f.id DESC";

        /*
         * La tanda que se va a mostrar, no el archivo entero. Por cada fila se
         * le pregunta al disco compartido si su XML y su PDF siguen ahí, y eso
         * sobre miles de comprobantes deja la pantalla colgada: quien llama
         * pide el `limite` y el `offset` de la página que está mirando.
         */
        $limite = (int) ($filtros['limite'] ?? 0);
        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite;
            $offset = max(0, (int) ($filtros['offset'] ?? 0));
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        return $this->fetchAll($sql, $params);
    }

    /**
     * Cuántos comprobantes cumplen esos mismos criterios.
     *
     * Es lo que le falta a la pantalla para decir cuántos hay y hasta dónde
     * llega el "Siguiente". No entra el filtro de respaldo: si el archivo
     * sigue en el disco no lo sabe SQL, eso lo mira quien arma el listado.
     */
    public function contarConImportacion(array $filtros = [])
    {
        $params = [];
        $where = $this->condicionesListado($filtros, $params);

        // El mismo enganche que el listado: si no, buscar por saldo contaría
        // sobre una consulta distinta de la que se está viendo.
        return (int) $this->fetchColumn(
            "SELECT COUNT(*)
               FROM {$this->table} f
               LEFT JOIN proveedores p ON f.proveedor_id = p.id
               " . $this->joinEnganche('facturas_erp') . "
              WHERE {$where}",
            $params
        );
    }

    /**
     * El WHERE del listado, compartido por la consulta y por el conteo:
     * contar con criterios distintos a los que se muestran daría páginas
     * prometidas que al abrirlas están vacías.
     */
    private function condicionesListado(array $filtros, array &$params)
    {
        $where = ["(f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"];
        $this->filtrarPorSociedad($where, $params, 'f.');

        if (array_key_exists('pago_id', $filtros)
            && $filtros['pago_id'] !== null && $filtros['pago_id'] !== '') {
            if ((int) $filtros['pago_id'] > 0) {
                $where[] = 'f.pago_id = ?';
                $params[] = (int) $filtros['pago_id'];
            } else {
                $where[] = 'f.pago_id IS NULL';
            }
        }

        if (!empty($filtros['importacion_id'])) {
            $where[] = 'f.importacion_id = ?';
            $params[] = (int) $filtros['importacion_id'];
        }

        /*
         * Un número se busca como número y un nombre como texto. Buscar los
         * tres dígitos de la factura 336 dentro de la clave —cincuenta
         * dígitos— traía 124 comprobantes que no eran, y la pantalla decía
         * "124 facturas" cuando la respuesta era "ninguna". Ver
         * app/helpers/BusquedaDocumento.php.
         */
        $condicionBusqueda = BusquedaDocumento::condicion(
            $filtros['q'] ?? '',
            [
                'numero' => 'f.numero_factura_asistente',
                'consecutivo' => 'f.consecutivo_completo',
                'clave' => 'f.clave',
                'cedula' => 'p.rfc',
                'texto' => ['p.razon_social', 'f.archivo_xml', 'f.archivo_pdf'],
            ],
            $params
        );
        if ($condicionBusqueda !== '') {
            $where[] = $condicionBusqueda;
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
        // El importe del comprobante, buscado por coincidencia.
        $condMonto = BusquedaImporte::condicion('f.total', $filtros['monto'] ?? '', $params);
        if ($condMonto !== '') {
            $where[] = $condMonto;
        }
        // Y el saldo, que no es del comprobante sino del registro del ERP con
        // el que esté enganchado. Sin enganche no hay saldo y la fila no puede
        // coincidir con nada, que es lo correcto.
        $condSaldo = BusquedaImporte::condicion(
            'COALESCE(erp.saldo_pago, erp.saldo)', $filtros['saldo'] ?? '', $params
        );
        if ($condSaldo !== '') {
            $where[] = $condSaldo;
        }

        return implode(' AND ', $where);
    }
    
    /**
     * ¿Está cargado el comprobante con este número corto?
     *
     * Es la pregunta del botón "Buscarlo entre los XML cargados": se llega al
     * listado buscando UNO, y entre las coincidencias del buscador puede no
     * estar. Se pregunta por el número normalizado a ocho dígitos, que es como
     * se guarda, y contra un índice: cuesta lo mismo que no preguntarlo.
     */
    public function existeNumeroXml($numero, $tipo = 'FE')
    {
        $numero = NumeroFactura::xmlOchoDigitos($numero);
        if ($numero === '') {
            return false;
        }
        $params = [$numero];
        $deTipo = strtoupper((string) $tipo) === 'NC'
            ? "tipo_documento = 'NC'"
            : "(tipo_documento IS NULL OR tipo_documento = 'FE')";
        $sql = "SELECT 1 FROM {$this->table}
                WHERE numero_factura_asistente = ? AND {$deTipo}"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';

        return (bool) $this->fetchColumn($sql, $params);
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
     * Asignar o cambiar el pago de una factura (null = quitarla).
     * Acotado: no se puede mover una factura de otra sociedad.
     */
    public function asignarPago($facturaId, $pagoId)
    {
        $params = [!empty($pagoId) ? (int) $pagoId : null, (int) $facturaId];
        $sql = "UPDATE {$this->table} SET pago_id = ?
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
                (importacion_id, pago_id, consecutivo_completo, clave, tipo_documento, receptor_id,
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
            !empty($data['pago_id']) ? (int) $data['pago_id'] : null,
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
        
        $id = $this->insert($sql, $params);
        $this->anotarAlmacen((int) $id, $data, true);

        return $id;
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

    /**
     * El findById heredado no sabe de empresas, y a este modelo llega un id
     * que viene del formulario: el del comprobante que alguien elige a mano
     * para respaldar una factura. Sin acotarlo se podía respaldar una factura
     * con el XML de otra empresa escribiendo su número.
     */
    public function findById($id)
    {
        $params = [(int) $id];
        $sql = "SELECT * FROM {$this->table} WHERE id = ?"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    /**
     * Un comprobante por su id, acotado a la empresa en curso.
     *
     * Por aquí entran las cuatro puertas que abren un documento suelto: la
     * ficha del visor, /facturas/ver, /notas-xml/ver y —lo que obliga a
     * acotarlo— /documentos/xml y /documentos/pdf, que no muestran datos sino
     * que ENTREGAN el archivo. Sin el filtro, cambiar el número de la
     * dirección bastaba para descargarse el comprobante de otra empresa.
     */
    public function getOneWithProvider($id)
    {
        $params = [(int) $id];
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre, p.rfc AS proveedor_cedula
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                WHERE f.id = ?"
             . $this->condicionSociedad('f.', $params) . ' LIMIT 1';
        $row = $this->fetchOne($sql, $params);
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
        $cambiadas = $this->execute($sql, [
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
        $this->anotarAlmacen((int) $id, $data, false);

        return $cambiadas;
    }

    /**
     * Dónde quedó el archivo de esta fila, y su id en SharePoint.
     *
     * Va acá, en el mismo modelo que guarda las rutas, para que ningún camino
     * que archive —importar, reponer, completar un PDF— pueda olvidarse: una
     * fila de SharePoint guardada sin `almacen` se leería como de disco, y el
     * documento quedaría perdido para el sistema aunque esté en la biblioteca.
     *
     * El almacén sale de la dirección del XML ('sharepoint:…' o una ruta de
     * disco), porque es la que decide dónde vive el par: el PDF siempre va al
     * lado de su XML (ver DocumentoArchivo::archivarPdfPara).
     *
     * Con todo en disco, que es lo de hoy, una fila nueva no cuesta ni una
     * consulta de más: NULL ya es disco.
     */
    private static $columnaAlmacen = null;

    /** ¿Ya se corrió migration_almacen_graph.sql? Una pregunta por proceso. */
    private function tieneColumnaAlmacen()
    {
        if (self::$columnaAlmacen === null) {
            try {
                self::$columnaAlmacen = (bool) $this->fetchColumn(
                    "SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'almacen' LIMIT 1",
                    [$this->table]
                );
            } catch (Throwable $e) {
                self::$columnaAlmacen = false;
            }
        }
        return self::$columnaAlmacen;
    }

    private function anotarAlmacen($id, array $data, $nueva)
    {
        require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';
        $rutaXml = (string) ($data['ruta_xml'] ?? '');
        $tipo = AlmacenDocumentos::tipoDeDireccion($rutaXml);
        $ids = array_filter([
            'item_xml_id' => (string) ($data['item_xml_id'] ?? ''),
            'etag_xml' => (string) ($data['etag_xml'] ?? ''),
            'item_pdf_id' => (string) ($data['item_pdf_id'] ?? ''),
            'etag_pdf' => (string) ($data['etag_pdf'] ?? ''),
        ], 'strlen');

        if ($id <= 0 || ($tipo === '' && !$ids && ($nueva || $rutaXml === ''))) {
            return;
        }
        // Un XML repuesto en disco: solo hay algo que limpiar si la base ya
        // sabe de almacenes. Sin la columna, preguntar dejaría un error en el
        // registro en cada reposición.
        if ($tipo === '' && !$ids && !$this->tieneColumnaAlmacen()) {
            return;
        }

        $sets = [];
        $params = [];
        if ($rutaXml !== '') {
            // Un XML que vuelve a archivarse en disco —se repuso después de
            // devolver la empresa a la carpeta compartida— deja de ser de
            // SharePoint, y sus ids de allá ya no dicen nada.
            $sets[] = 'almacen = ?';
            $params[] = $tipo === '' ? null : $tipo;
            if ($tipo === '') {
                $sets[] = 'item_xml_id = NULL';
                $sets[] = 'etag_xml = NULL';
            }
        }
        foreach ($ids as $columna => $valor) {
            $sets[] = $columna . ' = ?';
            $params[] = $valor;
        }
        $params[] = (int) $id;

        try {
            $this->execute("UPDATE {$this->table} SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
        } catch (Throwable $e) {
            // Sin la columna: si todo es de disco no hay nada que anotar, que
            // es lo que significa no haber corrido la migración. Pero un
            // documento de SharePoint sin anotar sería uno perdido: eso sí
            // tiene que parar la importación.
            if ($tipo !== '' || $ids) {
                throw new RuntimeException('El documento se archivó en SharePoint pero la base no tiene dónde '
                    . 'anotarlo: falta correr database/migration_almacen_graph.sql. ' . $e->getMessage(), 0, $e);
            }
        }
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

    public function getNotasXml($desde = '', $hasta = '', $buscar = '', $proveedor = '', $page = 1, $perPage = 100, array $importes = [])
    {
        [$where, $params] = $this->condicionesNotasXml($desde, $hasta, $buscar, $proveedor, $importes);
        // El techo alto es para la exportación, que baja el listado entero de
        // una vez; la pantalla sigue pidiendo de cien en cien.
        $limit = max(1, min(5000, (int) $perPage));
        $offset = (max(1, (int) $page) - 1) * $limit;
        // El saldo sale de la línea del reporte de notas a la que esté
        // enganchada, con el mismo cuidado que en el listado de comprobantes.
        // Lo mismo que trae el listado de comprobantes: son la misma tabla y
        // enseñan las mismas columnas, así que tienen que traer los mismos
        // campos o la mitad saldría en blanco al encenderlas.
        $sql = "SELECT f.*, p.razon_social AS proveedor_nombre, p.rfc AS proveedor_cedula,
                       i.archivo_origen AS archivo_importacion, i.fecha_importacion,
                       s.nombre AS pago_nombre,
                       erp.saldo AS saldo_erp, erp.documento AS documento_erp
                FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                LEFT JOIN importaciones i ON i.id = f.importacion_id
                LEFT JOIN pagos s ON s.id = f.pago_id
                " . $this->joinEnganche('notas_credito_lineas') . "
                WHERE " . implode(' AND ', $where) . "
                ORDER BY f.fecha_emision DESC, f.id DESC LIMIT {$limit} OFFSET {$offset}";
        return $this->fetchAll($sql, $params) ?: [];
    }

    public function countNotasXml($desde = '', $hasta = '', $buscar = '', $proveedor = '', array $importes = [])
    {
        [$where, $params] = $this->condicionesNotasXml($desde, $hasta, $buscar, $proveedor, $importes);
        // El mismo enganche que el listado, por lo mismo que en comprobantes.
        $sql = "SELECT COUNT(*) FROM {$this->table} f
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                " . $this->joinEnganche('notas_credito_lineas') . "
                WHERE " . implode(' AND ', $where);
        return (int) $this->fetchColumn($sql, $params);
    }

    /** Las condiciones de la lista de notas XML, una sola vez para listar y contar. */
    /**
     * @param array $importes ['monto' => , 'saldo' => ] tal como se escribieron
     *                        en la barra. Va al final y con valor por defecto
     *                        para no obligar a quien solo cuenta a pasarlos.
     */
    private function condicionesNotasXml($desde, $hasta, $buscar, $proveedor, array $importes = [])
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
        // Una nota se persigue tanto por su número como por lo que rebaja,
        // sobre todo cuando se está cuadrando un pago. El saldo es el de la
        // línea del reporte con la que esté enganchada.
        foreach ([
            ['monto', 'f.total'],
            ['saldo', 'erp.saldo'],
        ] as [$cual, $columna]) {
            $cond = BusquedaImporte::condicion($columna, $importes[$cual] ?? '', $params);
            if ($cond !== '') {
                $where[] = $cond;
            }
        }
        // Mismo criterio que el listado de comprobantes: el botón "Buscarlo
        // entre los XML cargados" manda acá cuando el documento es una nota.
        $condicionBusqueda = BusquedaDocumento::condicion(
            $buscar,
            [
                'numero' => 'f.numero_factura_asistente',
                'consecutivo' => 'f.consecutivo_completo',
                'clave' => 'f.clave',
                'cedula' => 'p.rfc',
                'texto' => ['p.razon_social'],
            ],
            $params
        );
        if ($condicionBusqueda !== '') {
            $where[] = $condicionBusqueda;
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

    /**
     * Documentos y estado de conciliación necesarios para ordenar el archivo.
     *
     * @param string|null $almacen   null: los del disco, que son los del
     *                               organizador. 'sharepoint': los de la
     *                               biblioteca, para el reconciliador.
     * @param bool        $soloEnPago Solo los que tienen pago: los únicos que
     *                               pueden necesitar copia en PAGOS.
     */
    public function getParaOrganizarArchivos(array $ids = [], $almacen = null, $soloEnPago = false)
    {
        $condiciones = [];
        $params = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            $condiciones[] = 'f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        // El organizador recorre y rehashea el disco: los documentos de
        // SharePoint no son suyos. Si le llegaran, buscaría su ruta en el
        // disco, no la encontraría y los marcaría como perdidos. De esos se
        // ocupa el reconciliador por los cambios de la biblioteca.
        $extra = '';
        if ($almacen !== null) {
            if (!$this->tieneColumnaAlmacen()) {
                return [];
            }
            $condiciones[] = 'f.almacen = ?';
            $params[] = (string) $almacen;
            $extra = ', f.item_xml_id, f.item_pdf_id, f.etag_xml, f.etag_pdf, f.archivo_faltante_en, f.almacen';
        } elseif ($this->tieneColumnaAlmacen()) {
            $condiciones[] = 'f.almacen IS NULL';
        }
        if ($soloEnPago) {
            $condiciones[] = 'f.pago_id IS NOT NULL';
        }
        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        // La empresa viaja con el documento porque decide en qué carpeta del
        // árbol vive: SISTEMA/EMPRESA/AAAA/MM MES. El organizador no consulta
        // la base, así que si no viene aquí no tiene de dónde saberlo.
        $sql = "SELECT f.id, f.tipo_documento, f.fecha_emision,
                       f.sociedad_id, s.nombre AS sociedad_nombre,
                       f.numero_factura_asistente, p.razon_social AS proveedor_nombre,
                       f.ruta_xml, f.archivo_xml, f.ruta_pdf, f.archivo_pdf,
                       f.hash_xml, f.hash_pdf, f.estado_pdf,
                       f.pago_id, pg.nombre AS pago_nombre, pg.fecha_pago AS pago_fecha,
                       pg.carpeta_pago AS carpeta_pago{$extra},
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
                            WHERE psp.factura_xml_id = f.id
                              AND psp.estado_respaldo IN ('respaldada', 'con_diferencia')
                              AND psp.pago_id = f.pago_id
                        ) THEN 1 ELSE 0 END AS en_pago
                FROM {$this->table} f
                LEFT JOIN pagos pg ON pg.id = f.pago_id
                LEFT JOIN proveedores p ON p.id = f.proveedor_id
                LEFT JOIN sociedades s ON s.id = f.sociedad_id
                {$where}
                ORDER BY f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Comprobantes electrónicos con los que se puede respaldar un pago.
     *
     * Son todas las facturas de la sociedad, sin filtrar por pago: desde que
     * el pago es una selección de facturas del ERP, el pago del XML
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
                       f.fecha_emision, f.pago_id, f.proveedor_id,
                       p.rfc AS proveedor_cedula, p.razon_social AS proveedor_nombre
                  FROM {$this->table} f
                  LEFT JOIN proveedores p ON p.id = f.proveedor_id
                 WHERE (f.tipo_documento IS NULL OR f.tipo_documento = 'FE')"
             . $this->condicionSociedad('f.', $params) . "
                 ORDER BY f.id ASC";
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * IDs de las facturas asignadas a un pago. Lo usa la orden de ordenar
     * el pago, que solo debe alcanzar esas facturas y ninguna otra.
     */
    public function getIdsPorPago($pagoId)
    {
        $pagoId = (int) $pagoId;
        if ($pagoId <= 0) {
            return [];
        }
        $filas = $this->fetchAll(
            "SELECT id FROM {$this->table} WHERE pago_id = ? ORDER BY id ASC",
            [$pagoId]
        );
        return array_map('intval', array_column($filas ?: [], 'id'));
    }

    /**
     * Anota qué documentos no tienen el archivo que su ruta promete.
     *
     * Lo llama el organizador después de mirar el disco. La marca se pone y se
     * quita sola: si el archivo vuelve —restaurado de la papelera o rebajado
     * del correo— la siguiente revisión la limpia.
     *
     * $ambito son los documentos que se revisaron. Lo que quede fuera no se
     * toca, porque de esos no se sabe nada; vacío significa "se revisaron
     * todos". La limpieza va por negación —los revisados que NO faltan— para
     * no mandar diez mil identificadores en cada corrida.
     */
    public function marcarArchivosFaltantes(array $faltantes, array $ambito = [])
    {
        $faltantes = array_values(array_unique(array_filter(array_map('intval', $faltantes))));
        $ambito = array_values(array_unique(array_filter(array_map('intval', $ambito))));

        $tocadas = 0;
        foreach (array_chunk($faltantes, 500) as $grupo) {
            $tocadas += (int) $this->execute(
                "UPDATE {$this->table} SET archivo_faltante_en = NOW()
                  WHERE archivo_faltante_en IS NULL
                    AND id IN (" . implode(',', array_fill(0, count($grupo), '?')) . ')',
                $grupo
            );
        }

        $sql = "UPDATE {$this->table} SET archivo_faltante_en = NULL
                 WHERE archivo_faltante_en IS NOT NULL";
        // Quien marca y desmarca los de SharePoint es el reconciliador; una
        // revisión del disco no dice nada de ellos.
        if ($this->tieneColumnaAlmacen()) {
            $sql .= ' AND almacen IS NULL';
        }
        $params = [];
        if ($ambito) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ambito), '?')) . ')';
            $params = $ambito;
        }
        if ($faltantes) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($faltantes), '?')) . ')';
            $params = array_merge($params, $faltantes);
        }

        return $tocadas + (int) $this->execute($sql, $params);
    }

    /**
     * Documentos sin archivo que se pueden volver a bajar del correo.
     *
     * Se pide el mensaje del que salieron —cuenta, carpeta y UID— y el hash
     * con el que se comprueba que lo que baje sea exactamente lo mismo que se
     * archivó. Sin hash no hay con qué probarlo, así que esos no entran.
     *
     * Va acotado a la empresa en curso, como todo lo que se lee: el botón de
     * la pantalla manda identificadores, y sin esto bastaría cambiar uno para
     * hacer que el sistema baje y escriba el respaldo de otra sociedad. El
     * mantenimiento por línea de comandos pide `setSociedad(0)` a propósito.
     */
    public function getRecuperablesDelCorreo(array $ids = [], $limite = 0)
    {
        $where = ["(f.archivo_faltante_en IS NOT NULL OR f.ruta_xml IS NULL OR f.ruta_xml = '')",
                  'f.correo_cuenta_id IS NOT NULL', 'f.correo_uid > 0',
                  "COALESCE(f.hash_xml, '') <> ''"];
        $params = [];

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            // Cuando se piden documentos concretos manda quien los pide: la
            // marca puede no estar puesta todavía —se pone al revisar—, y el
            // botón de la pantalla nace justo de ver que el archivo no está.
            $where = ['f.correo_cuenta_id IS NOT NULL', 'f.correo_uid > 0',
                      "COALESCE(f.hash_xml, '') <> ''",
                      'f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
            $params = $ids;
        }
        $this->filtrarPorSociedad($where, $params, 'f.');

        $sql = "SELECT f.id, f.tipo_documento, f.consecutivo_completo, f.numero_factura_asistente,
                       f.fecha_emision, f.ruta_xml, f.ruta_pdf, f.archivo_xml, f.archivo_pdf,
                       f.hash_xml, f.hash_pdf, f.estado_pdf, f.archivo_faltante_en,
                       f.correo_cuenta_id, f.correo_carpeta, f.correo_uid, f.correo_uidvalidity,
                       f.sociedad_id, s.nombre AS sociedad_nombre,
                       p.razon_social AS proveedor_nombre
                  FROM {$this->table} f
                  LEFT JOIN proveedores p ON p.id = f.proveedor_id
                  LEFT JOIN sociedades s ON s.id = f.sociedad_id
                 WHERE " . implode(' AND ', $where) . '
                 ORDER BY f.correo_cuenta_id, f.correo_carpeta, f.correo_uid';
        if ((int) $limite > 0) {
            $sql .= ' LIMIT ' . (int) $limite;
        }
        return $this->fetchAll($sql, $params) ?: [];
    }

    // ── Lo que usa el reconciliador de SharePoint ─────────────────────────
    //
    // En SharePoint cada documento tiene un id que no cambia aunque alguien lo
    // mueva o le cambie el nombre. La ruta es la dirección legible; el id es
    // la identidad. Cuando no coinciden gana el id y la ruta se reescribe.
    // Ver app/helpers/ReconciliadorGraph.php.

    private const COLUMNAS_ALMACEN = 'id, ruta_xml, ruta_pdf, archivo_xml, archivo_pdf, hash_xml, hash_pdf,
                                      almacen, item_xml_id, item_pdf_id, etag_xml, etag_pdf, archivo_faltante_en';

    /** Los documentos de un almacén, por tandas y en orden de id. */
    public function getEnAlmacen($almacen, $desdeId = 0, $limite = 200)
    {
        if (!$this->tieneColumnaAlmacen()) {
            return [];
        }
        return $this->fetchAll(
            'SELECT ' . self::COLUMNAS_ALMACEN . " FROM {$this->table}
              WHERE almacen = ? AND id > ? ORDER BY id ASC LIMIT " . max(1, (int) $limite),
            [(string) $almacen, (int) $desdeId]
        ) ?: [];
    }

    /** Los documentos que tienen alguno de estos ids de SharePoint. */
    public function getPorItems(array $itemIds)
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds), 'strlen')));
        if (!$itemIds || !$this->tieneColumnaAlmacen()) {
            return [];
        }
        $filas = [];
        foreach (array_chunk($itemIds, 500) as $grupo) {
            $marcas = implode(',', array_fill(0, count($grupo), '?'));
            foreach ($this->fetchAll(
                'SELECT ' . self::COLUMNAS_ALMACEN . " FROM {$this->table}
                  WHERE item_xml_id IN ({$marcas}) OR item_pdf_id IN ({$marcas})",
                array_merge($grupo, $grupo)
            ) ?: [] as $fila) {
                $filas[(int) $fila['id']] = $fila;
            }
        }
        ksort($filas);
        return array_values($filas);
    }

    /** ¿Hay algún documento de ese almacén bajo esta carpeta? */
    public function hayEnCarpeta($almacen, $carpeta)
    {
        if (!$this->tieneColumnaAlmacen()) {
            return false;
        }
        $prefijo = addcslashes(RutaDocumento::relativa($carpeta), '%_\\') . '/%';
        return (bool) $this->fetchColumn(
            "SELECT 1 FROM {$this->table}
              WHERE almacen = ? AND (ruta_xml LIKE ? OR ruta_pdf LIKE ?) LIMIT 1",
            [(string) $almacen, $prefijo, $prefijo]
        );
    }

    /**
     * Anota dónde está ahora un documento de SharePoint: la ruta que tiene su
     * id, y su id y cTag.
     *
     * @param string $extension 'xml' o 'pdf'
     */
    public function seguirEnAlmacen($id, $extension, $ruta, $itemId, $etag)
    {
        $extension = $extension === 'pdf' ? 'pdf' : 'xml';
        return $this->execute(
            "UPDATE {$this->table}
                SET ruta_{$extension} = ?, archivo_{$extension} = ?,
                    item_{$extension}_id = ?, etag_{$extension} = ?
              WHERE id = ?",
            [RutaDocumento::relativa($ruta), basename(str_replace('\\', '/', (string) $ruta)),
             (string) $itemId ?: null, (string) $etag ?: null, (int) $id]
        );
    }

    /**
     * La marca de "sin archivo" de los documentos de SharePoint: se pone a los
     * que faltan y se quita a los que están. Solo toca los nombrados: de los
     * demás, esta revisión no sabe nada.
     */
    public function marcarFaltantesEnAlmacen(array $faltan, array $estan)
    {
        $tocadas = 0;
        foreach ([[$faltan, 'NOW()', 'IS NULL'], [$estan, 'NULL', 'IS NOT NULL']] as [$ids, $valor, $antes]) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            foreach (array_chunk($ids, 500) as $grupo) {
                $tocadas += (int) $this->execute(
                    "UPDATE {$this->table} SET archivo_faltante_en = {$valor}
                      WHERE archivo_faltante_en {$antes} AND almacen IS NOT NULL
                        AND id IN (" . implode(',', array_fill(0, count($grupo), '?')) . ')',
                    $grupo
                );
            }
        }
        return $tocadas;
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
     * una sola vez. Ordenar un pago son cientos de documentos, y con la
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
