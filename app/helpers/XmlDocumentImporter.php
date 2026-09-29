<?php
require_once __DIR__ . '/XmlParser.php';
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/FacturaErp.php';
require_once __DIR__ . '/../models/FacturaXmlDetalle.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/../models/Sociedad.php';

/** Importa un FE/NC, archiva sus archivos y guarda solo metadatos en BD. */
class XmlDocumentImporter
{
    /**
     * Tipos que nunca se guardan, decida lo que decida quien llame. Hoy solo
     * la nota de débito. Los mensajes de Hacienda no están en esta lista
     * porque ni siquiera llegan hasta aquí: XmlInvoiceParser los rechaza, que
     * es donde corresponde —no son un tipo de comprobante, no son un
     * comprobante—.
     */
    public const NUNCA_SE_GUARDAN = ['ND'];

    private $facturas;
    private $proveedores;
    private $archivo;

    /** id de empresa => nombre, para no preguntarlo por cada documento. */
    private $nombresEmpresa = [];

    public function __construct($carpetaRaiz = '')
    {
        $this->facturas = new Factura();
        $this->proveedores = new Proveedor();
        $this->archivo = new DocumentoArchivo($carpetaRaiz);
    }

    /**
     * El archivo apuntando a la carpeta de una empresa.
     *
     * Una carga de correo trae documentos de la empresa del lote, y el mismo
     * importador puede usarse para varias seguidas: la carpeta se resuelve por
     * documento y no se recuerda entre uno y otro.
     */
    private function archivoDe($sociedadId)
    {
        $sociedadId = (int) $sociedadId;
        if ($sociedadId <= 0) {
            // No se sabe de quién es: va a la carpeta de los indefinidos, no
            // revuelto con los de una empresa cualquiera.
            return $this->archivo->paraEmpresa(DocumentoArchivo::EMPRESA_SIN_DEFINIR);
        }
        // La sociedad decide también DÓNDE: su carpeta y su almacén.
        if (!array_key_exists($sociedadId, $this->nombresEmpresa)) {
            $fila = (new Sociedad())->findById($sociedadId);
            $this->nombresEmpresa[$sociedadId] = is_array($fila) ? (string) $fila['nombre'] : '';
        }
        $nombre = $this->nombresEmpresa[$sociedadId];
        return $this->archivo->paraSociedad(
            $sociedadId,
            $nombre !== '' ? $nombre : DocumentoArchivo::EMPRESA_SIN_DEFINIR
        );
    }

    /**
     * Sin listado de proveedores no entra nada.
     *
     * Es el portón, y va acá porque los cinco caminos del XML —el correo, la
     * cola, la carga a mano, la entrada automática y las notas— terminan todos
     * en este método. Ponerlo en cada uno dejaría el hueco el día que alguien
     * agregue el sexto.
     *
     * Se lanza excepción en vez de devolver un fallo silencioso para que el
     * archivo NO se borre: quien llama borra el temporal solo cuando la
     * importación salió bien. El comprobante se queda donde está y entra
     * cuando la lista esté cargada.
     */
    private function exigirListado($empresa = 0)
    {
        if (!class_exists('ProveedorCodigoErp')) {
            $ruta = __DIR__ . '/../models/ProveedorCodigoErp.php';
            if (!class_exists('Model') || !is_file($ruta)) {
                return;
            }
            require_once $ruta;
        }
        if (ProveedorCodigoErp::hayListado($empresa)) {
            return;
        }
        throw new RuntimeException(
            'Todavía no se ha cargado el listado de proveedores de esta empresa, así que este comprobante no se '
            . 'puede procesar: sin saber de quién es cada código, se engancharía a ciegas. '
            . 'Cargá la Lista de Proveedores en Configuración y volvé a intentarlo. '
            . 'El archivo no se perdió.'
        );
    }

    /**
     * Los cinco caminos del XML llegan acá con rutas de dos clases: los que
     * subió una persona están en esta máquina, y los que vienen de la bandeja
     * o de la cola están en el almacén. Todo lo de abajo —leer el XML, sacar
     * los hashes, archivar— necesita un archivo local, así que se consigue una
     * vez en la entrada y no en cada uno de los sitios que lo leen.
     */
    public function importar($xmlPath, $pdfPath = null, array $contexto = [])
    {
        return RutaDocumento::conArchivoLocal($xmlPath, function ($xmlLocal) use ($pdfPath, $contexto) {
            // Un PDF que no se puede leer no frena al XML: el comprobante
            // entra y el PDF queda pendiente, igual que si no hubiera venido.
            // Es lo que ya pasaba con un PDF ausente, y por eso no se lanza.
            //
            // Y se consigue recién cuando hace falta: si el comprobante ya
            // estaba archivado con su PDF, no se usa, y bajarlo de SharePoint
            // era un segundo de espera para nada.
            $pdfLocal = null;
            $esTemporal = false;
            $buscado = false;
            $pdf = function ($soloSiVino = false) use ($pdfPath, &$pdfLocal, &$esTemporal, &$buscado) {
                if ($soloSiVino) {
                    return is_string($pdfPath) && $pdfPath !== '';
                }
                if (!$buscado) {
                    $buscado = true;
                    if (is_string($pdfPath) && $pdfPath !== '') {
                        if (RutaDocumento::esAbsoluta($pdfPath) && is_file($pdfPath)) {
                            $pdfLocal = $pdfPath;
                        } else {
                            $pdfLocal = RutaDocumento::rutaLocal($pdfPath);
                            $esTemporal = $pdfLocal !== null;
                        }
                    }
                }
                return $pdfLocal;
            };
            try {
                return $this->importarLocal($xmlLocal, $pdf, $contexto);
            } finally {
                if ($esTemporal) {
                    RutaDocumento::soltarLocal($pdfLocal);
                }
            }
        });
    }

    /**
     * @param Closure $pdf Devuelve el PDF local, o null; lo baja la primera vez
     *                     que se le pide. $pdf(true) solo dice si vino un PDF.
     */
    private function importarLocal($xmlPath, Closure $pdf, array $contexto)
    {
        // La empresa del lote, que puede no ser la seleccionada (cli/, la
        // entrada automática). 0 = la seleccionada. El catálogo es por
        // empresa, así que el portón y el aviso preguntan por la suya.
        $empresa = (int) ($contexto['sociedad_id'] ?? 0);
        $this->exigirListado($empresa);

        $doc = XmlInvoiceParser::parseCfdiFromFile($xmlPath);
        $tipo = strtoupper(trim((string) ($doc['tipo_documento'] ?? 'FE')));

        // Los únicos documentos que se guardan son factura y nota de crédito.
        // Las notas de débito se descartan siempre, y esto va por encima de
        // 'tipos_permitidos' a propósito: es una regla del negocio, no una
        // opción de cada módulo, y un módulo nuevo que pasara la lista
        // equivocada las volvería a meter sin que nadie lo note.
        if (in_array($tipo, self::NUNCA_SE_GUARDAN, true)) {
            throw new RuntimeException(
                'Las notas de débito no se guardan en el sistema (' . $tipo . ').'
            );
        }

        $permitidos = array_diff(
            array_map('strtoupper', $contexto['tipos_permitidos'] ?? ['FE', 'NC']),
            self::NUNCA_SE_GUARDAN
        );
        if (!in_array($tipo, $permitidos, true)) {
            throw new RuntimeException('Tipo de documento no permitido en este módulo: ' . $tipo . '.');
        }

        if (!empty($contexto['validar_receptor'])) {
            $cedula = preg_replace('/\D+/', '', (string) ($contexto['cedula_receptor'] ?? ''));
            $receptor = preg_replace('/\D+/', '', (string) ($doc['receptor_id'] ?? ''));
            if ($cedula !== '' && $receptor !== '' && $cedula !== $receptor) {
                throw new RuntimeException('El receptor del XML (' . $receptor . ') no corresponde a la sociedad activa.');
            }
        }

        $proveedorId = (int) $this->proveedores->obtenerOCrear(
            (string) ($doc['rfc_emisor'] ?? ''),
            (string) ($doc['razon_social_emisor'] ?? '')
        );

        // El comprobante entra igual; lo que se pregunta es a qué código del
        // ERP pertenece. Un emisor que no está en el catálogo no tiene ninguna
        // factura a la que pegarse, así que el XML queda guardado pero suelto
        // hasta que alguien le dé un código o diga que ese proveedor no va.
        $this->avisarSiNoPerteneceANingunCodigo(
            (string) ($doc['rfc_emisor'] ?? ''),
            (string) ($doc['razon_social_emisor'] ?? ''),
            $empresa
        );
        $hash = (string) ($doc['hash_documento'] ?? ($doc['hash_xml'] ?? ''));
        $existente = $hash !== '' ? $this->facturas->findByHashRecord($hash) : null;
        if (!$existente && !empty($doc['consecutivo_completo'])) {
            $existente = $this->facturas->findByConsecutivoRecord(
                (string) $doc['consecutivo_completo'],
                $proveedorId,
                (string) ($doc['fecha_emision'] ?? '')
            );
        }

        if ($existente) {
            return $this->completarExistente($existente, $xmlPath, $pdf, $doc, $contexto);
        }

        // La carpeta es la de la empresa del documento, no una sola para todas.
        $pdfPath = $pdf();
        $archivado = $this->archivoDe($contexto['sociedad_id'] ?? 0)->archivar(
            $xmlPath,
            is_string($pdfPath) ? $pdfPath : null,
            $doc,
            (string) ($doc['razon_social_emisor'] ?? '')
        );

        $metadata = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
        $metadata['archivo_local'] = true;
        $metadata['origen'] = (string) ($contexto['origen'] ?? 'carga_directa');

        try {
            $this->facturas->begin();
            $id = (int) $this->facturas->crear([
                'importacion_id' => !empty($contexto['importacion_id']) ? (int) $contexto['importacion_id'] : null,
                'pago_id' => $tipo === 'FE' && !empty($contexto['pago_id']) ? (int) $contexto['pago_id'] : null,
                // Quien importa manda: el worker de un lote de correo trabaja
                // con la sociedad DEL LOTE, que puede no ser la seleccionada
                // en pantalla si alguien cambió de empresa mientras corría.
                'sociedad_id' => !empty($contexto['sociedad_id']) ? (int) $contexto['sociedad_id'] : 0,
                'consecutivo_completo' => $doc['consecutivo_completo'],
                'clave' => $doc['clave'] ?? null,
                'tipo_documento' => $tipo,
                'receptor_id' => $doc['receptor_id'] ?? null,
                'numero_factura_asistente' => $doc['numero_factura_asistente'],
                'proveedor_id' => $proveedorId,
                'fecha_emision' => $doc['fecha_emision'],
                'subtotal' => $doc['subtotal'],
                'iva' => $doc['iva'],
                'total' => $doc['total'],
                'moneda' => $doc['moneda'],
                'tipo_cambio' => $doc['tipo_cambio'] ?? null,
                'tipo_comprobante' => $doc['tipo_comprobante'] ?? null,
                'archivo_xml' => $archivado['archivo_xml'],
                'ruta_xml' => $archivado['ruta_xml'],
                'ruta_pdf' => $archivado['ruta_pdf'],
                'archivo_pdf' => $archivado['archivo_pdf'],
                'hash_pdf' => $archivado['hash_pdf'],
                'estado_pdf' => $archivado['estado_pdf'],
                'correo_cuenta_id' => $contexto['correo_cuenta_id'] ?? null,
                'correo_carpeta' => $contexto['correo_carpeta'] ?? null,
                'correo_uidvalidity' => $contexto['correo_uidvalidity'] ?? null,
                'correo_uid' => $contexto['correo_uid'] ?? null,
                'fecha_correo' => $contexto['fecha_correo'] ?? null,
                'archivado_en' => $archivado['archivado_en'],
                'hash_xml' => $archivado['hash_xml'],
                // Dónde quedó: la fila lo anota sola a partir de la ruta y
                // de estos ids (ver Factura::anotarAlmacen).
                'item_xml_id' => $archivado['item_xml_id'] ?? '',
                'etag_xml' => $archivado['etag_xml'] ?? '',
                'item_pdf_id' => $archivado['item_pdf_id'] ?? '',
                'etag_pdf' => $archivado['etag_pdf'] ?? '',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
            $this->facturas->commit();
        } catch (Throwable $e) {
            $this->facturas->rollback();
            DocumentoArchivo::eliminarCreados($archivado);

            /*
             * Otro trabajador lo guardó entre la búsqueda de arriba y este
             * INSERT. Pasa cuando el proveedor manda el mismo comprobante en
             * dos correos y el lote los reparte entre dos trabajadores a la
             * vez (la pantalla y la tarea programada): los dos preguntan "¿ya
             * está?", los dos oyen que no, y el segundo choca con la llave
             * única. No es un XML inválido: es un duplicado, y se trata como
             * cualquier otro —se busca el que ganó y se completa ese—.
             */
            if (self::esClaveRepetida($e)) {
                $ganador = $hash !== '' ? $this->facturas->findByHashRecord($hash) : null;
                if (!$ganador && !empty($doc['consecutivo_completo'])) {
                    $ganador = $this->facturas->findByConsecutivoRecord(
                        (string) $doc['consecutivo_completo'],
                        $proveedorId,
                        (string) ($doc['fecha_emision'] ?? '')
                    );
                }
                if ($ganador) {
                    return $this->completarExistente($ganador, $xmlPath, $pdf, $doc, $contexto);
                }
            }
            throw $e;
        }

        // Aquí NO se reorganiza el árbol: el documento se queda donde lo dejó
        // DocumentoArchivo al archivarlo —la carpeta de su mes—, y si le toca
        // entrar a un pago, la copia la deja el acomodo de la tarea
        // programada. Importar no puede quedarse esperando a que se recorra el
        // archivo entero.
        if ($tipo === 'NC') {
            $this->extraerDetalle((int) $id, (string) $archivado['ruta_xml']);
        }

        $enganche = $this->engancharConErp($tipo, (int) $id, $doc, (int) ($contexto['sociedad_id'] ?? 0));

        return [
            'estado' => 'importado',
            'id' => $id,
            'tipo_documento' => $tipo,
            'pdf_pendiente' => $archivado['estado_pdf'] !== 'disponible',
            'documento' => $doc,
            'archivado' => $archivado,
            'erp' => $enganche,
            'pago_id' => $enganche['pago_id'] ?? null,
        ];
    }

    /**
     * Busca la factura del ERP que corresponde a este comprobante y las
     * empareja.
     *
     * Solo facturas: una nota de crédito no respalda un pago.
     *
     * Nunca lanza. Que el enganche falle no puede tumbar una importación que ya
     * está guardada y archivada; si no engancha, el comprobante queda igual que
     * antes y la verificación del pago lo recoge después.
     *
     * Busca en la empresa DEL DOCUMENTO, no en la seleccionada. El worker de
     * cli/ corre sin sesión: ahí "la seleccionada" es la empresa por omisión, y
     * un lote de otra empresa buscaba su factura entre las de aquella. No
     * encontraba ninguna —el consecutivo es de otro juego de facturas— y los
     * comprobantes de la segunda empresa se quedaban sin enganchar, sin que
     * nada avisara.
     */
    private function engancharConErp($tipo, $facturaId, array $doc, $sociedadId = 0)
    {
        if ($tipo !== 'FE') {
            return ['estado' => 'no_aplica'];
        }
        try {
            $erp = new FacturaErp();
            if ((int) $sociedadId > 0) {
                $erp->setSociedad((int) $sociedadId);
            }
            return $erp->engancharXml(
                $facturaId,
                (string) ($doc['consecutivo_completo'] ?? ''),
                (string) ($doc['numero_factura_asistente'] ?? ''),
                (float) ($doc['total'] ?? 0)
            );
        } catch (Throwable $e) {
            return ['estado' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Avisa cuando el emisor de un comprobante no está en el catálogo del ERP.
     *
     * Es la pregunta que antes no se hacía: el sistema guardaba el XML de
     * cualquiera y solo se notaba el problema mucho después, cuando la factura
     * del ERP se quedaba sin respaldo sin explicación. Ahora se pregunta en el
     * momento, que es cuando alguien todavía se acuerda de por qué llegó ese
     * correo.
     *
     * Un aviso por cédula y no por comprobante: la decisión es sobre el
     * proveedor, y treinta facturas del mismo emisor son una sola pregunta.
     * La firma se encarga de eso.
     *
     * Nunca lanza: el comprobante ya está guardado y el aviso es un extra. Si
     * la campana falla, la importación tiene que seguir igual.
     */
    private function avisarSiNoPerteneceANingunCodigo($cedula, $nombre, $empresa = 0)
    {
        try {
            // Con guardia, como arriba: quien ya tenga la clase cargada —o
            // puesta a propósito, en las pruebas— no la ve redeclararse.
            if (!class_exists('ProveedorCodigoErp')) {
                require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
            }
            require_once __DIR__ . '/../models/Notificacion.php';

            $cedula = ProveedorCodigoErp::normalizarCedula($cedula);
            if ($cedula === '' || ProveedorCodigoErp::conoceCedula($cedula, $empresa)) {
                return;
            }
            // El aviso es de la empresa que recibió el comprobante: es en SU
            // catálogo donde falta el código.
            $empresa = $empresa > 0 ? (int) $empresa : (int) ProveedorCodigoErp::sociedadSeleccionadaId();

            $nombre = trim((string) $nombre) ?: ('cédula ' . $cedula);

            (new Notificacion())->avisar([
                'tipo' => 'proveedor_sin_codigo',
                'severidad' => 'media',
                'titulo' => 'Llegó un comprobante de un proveedor sin código',
                'detalle' => $nombre . ' (cédula ' . $cedula . ') no tiene ningún código en el sistema. '
                           . 'Sus comprobantes quedan guardados, pero no se les puede buscar factura '
                           . 'hasta saber cuál es su código. Dáselo acá, o decí que no se procesa.',
                'firma' => 'proveedor_sin_codigo|' . $cedula,
                'sociedad_id' => $empresa,
                'ref_tabla' => 'proveedor_codigo_erp',
                'ref_clave' => $cedula,
                'datos' => ['cedula' => $cedula, 'nombre' => $nombre],
            ]);
        } catch (Throwable $e) {
            // El comprobante ya quedó guardado; la campana es un extra.
        }
    }

    /**
     * ¿La base rechazó el INSERT por una llave única repetida (MySQL 1062)?
     * Solo esa: cualquier otra violación de integridad —una clave foránea,
     * un NOT NULL— sí es un error y tiene que verse.
     */
    private static function esClaveRepetida(Throwable $e)
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof PDOException
                && (int) ($x->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
            if ($x instanceof PDOException
                && (string) $x->getCode() === '23000'
                && strpos($x->getMessage(), ' 1062 ') !== false) {
                return true;
            }
        }
        return false;
    }

    private function completarExistente(array $existente, $xmlPath, Closure $pdf, array $doc, array $contexto)
    {
        $cambios = [];
        $creados = [];
        $tipo = strtoupper((string) ($existente['tipo_documento'] ?? ($doc['tipo_documento'] ?? 'FE')));
        $pagoAnterior = !empty($existente['pago_id']) ? (int) $existente['pago_id'] : null;
        $pagoDestino = $tipo === 'FE' && !empty($contexto['pago_id'])
            ? (int) $contexto['pago_id']
            : null;
        $mismoPago = $pagoDestino !== null && $pagoAnterior === $pagoDestino;
        $moverPago = $pagoDestino !== null && $pagoAnterior !== $pagoDestino;

        // Repara rutas históricas obsoletas si el XML vuelve a llegar.
        if (empty($existente['ruta_xml']) || !RutaDocumento::existe((string) $existente['ruta_xml'])) {
            $pdfPath = $pdf();
            $creados = $this->archivoDe(
                $existente['sociedad_id'] ?? ($contexto['sociedad_id'] ?? 0)
            )->archivar(
                $xmlPath,
                is_string($pdfPath) ? $pdfPath : null,
                $doc,
                (string) ($existente['proveedor_nombre'] ?? ($doc['razon_social_emisor'] ?? ''))
            );
            $cambios = $creados;
        } elseif ($pdf(true)
            && (empty($existente['ruta_pdf']) || !RutaDocumento::existe((string) $existente['ruta_pdf']))
            && is_string($pdfPath = $pdf()) && is_file($pdfPath)) {
            $cambios = $this->archivoDe(
                    $existente['sociedad_id'] ?? ($contexto['sociedad_id'] ?? 0)
                )->archivarPdfPara($existente, $pdfPath);
            $creados = $cambios;
        }

        $cambios['correo_cuenta_id'] = $contexto['correo_cuenta_id'] ?? null;
        $cambios['correo_carpeta'] = $contexto['correo_carpeta'] ?? null;
        $cambios['correo_uidvalidity'] = $contexto['correo_uidvalidity'] ?? null;
        $cambios['correo_uid'] = $contexto['correo_uid'] ?? null;
        $cambios['fecha_correo'] = $contexto['fecha_correo'] ?? null;

        try {
            $this->facturas->begin();
            $this->facturas->actualizarArchivos((int) $existente['id'], $cambios);
            if ($moverPago) {
                $this->facturas->asignarPago((int) $existente['id'], $pagoDestino);
            }
            $this->facturas->commit();
        } catch (Throwable $e) {
            $this->facturas->rollback();
            DocumentoArchivo::eliminarCreados($creados);
            throw $e;
        }

        if ($tipo === 'NC') {
            $ruta = (string) ($cambios['ruta_xml'] ?? ($existente['ruta_xml'] ?? ''));
            $this->extraerDetalle((int) $existente['id'], $ruta);
        }

        // Un comprobante que ya estaba puede no haberse enganchado nunca —
        // llegó antes de que su factura entrara a un pago—. Se reintenta.
        $enganche = $this->engancharConErp(
            $tipo,
            (int) $existente['id'],
            $doc,
            // Un comprobante que ya estaba lleva su empresa consigo; el contexto
            // de esta importación puede venir vacío.
            (int) ($existente['sociedad_id'] ?? ($contexto['sociedad_id'] ?? 0))
        );
        if (!empty($enganche['pago_id'])) {
            $pagoDestino = (int) $enganche['pago_id'];
            $moverPago = $pagoAnterior !== $pagoDestino;
            $mismoPago = !$moverPago;
        }

        $pdfDisponible = !empty($cambios['ruta_pdf']) || (!empty($existente['ruta_pdf']) && RutaDocumento::existe((string) $existente['ruta_pdf']));
        $estado = $moverPago
            ? 'movido_pago'
            : ($mismoPago ? 'duplicado_pago' : (!empty($cambios['ruta_pdf']) ? 'pdf_completado' : 'duplicado'));
        return [
            'estado' => $estado,
            'id' => (int) $existente['id'],
            'tipo_documento' => $tipo,
            'pdf_pendiente' => !$pdfDisponible,
            'pago_anterior' => $pagoAnterior,
            'pago_id' => $moverPago ? $pagoDestino : $pagoAnterior,
            'movido_pago' => $moverPago,
            'duplicado_mismo_pago' => $mismoPago,
            'documento' => $doc,
            'archivado' => $cambios,
            'erp' => $enganche,
        ];
    }

    private function extraerDetalle($id, $rutaXml)
    {
        if ($rutaXml === '' || !RutaDocumento::existe($rutaXml)) {
            return;
        }
        try {
            (new FacturaXmlDetalle())->extraerYGuardar((int) $id, $rutaXml);
        } catch (Throwable $e) {
            // Best effort: el CLI de backfill reintenta los pendientes.
        }
    }

}
