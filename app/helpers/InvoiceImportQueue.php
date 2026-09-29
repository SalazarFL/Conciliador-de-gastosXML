<?php
/**
 * Servicio de cola para importaciones masivas de facturas XML.
 */

require_once __DIR__ . '/../models/Importacion.php';
require_once __DIR__ . '/../models/ImportacionItem.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/FileUploader.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/XmlDocumentImporter.php';

if (!class_exists('XmlInvoiceParser', false)) {
    require_once __DIR__ . '/XmlParser.php';
}

class InvoiceImportQueue
{
    private const REQUEST_TIME_LIMIT_SECONDS = 600;
    private const REQUEST_TIME_BUDGET_SECONDS = 45;
    private const STALE_PROCESSING_SECONDS = 150;

    private $config;
    private $allowedExtensions;
    private $maxUploadSize;
    private $importacionModel;
    private $importacionItemModel;
    private $facturaModel;
    private $proveedorModel;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->allowedExtensions = $this->config['allowed_extensions']['xml'] ?? ['xml'];
        $this->maxUploadSize = (int) ($this->config['max_upload_size'] ?? 10485760);
        $this->importacionModel = new Importacion();
        $this->importacionItemModel = new ImportacionItem();
        $this->facturaModel = new Factura();
        $this->proveedorModel = new Proveedor();
    }

    public function iniciarImportacion(array $data = [])
    {
        $archivoOrigen = trim((string) ($data['archivo_origen'] ?? 'multiple_xml_files'));
        $totalEsperado = max(0, (int) ($data['total_esperado'] ?? 0));
        $metadata = [
            'modo' => 'cola',
            'estado_cola' => 'subiendo',
            'total_esperado' => $totalEsperado,
            'archivos_subidos' => 0,
            'subida_completa' => false,
            'created_at_queue' => date('Y-m-d H:i:s'),
            'ultima_actualizacion' => date('Y-m-d H:i:s'),
        ];

        // Pago elegida: cada factura de esta importación
        // quedará asignada a ella (buildEstado conserva la clave al mergear)
        if (!empty($data['pago_id'])) {
            $metadata['pago_id'] = (int) $data['pago_id'];
        }
        $metadata['tipo_documento'] = strtoupper((string) ($data['tipo_documento'] ?? 'FE'));
        // La cola se procesa en varias peticiones: la sociedad se guarda con
        // la importación para que el sello no dependa de cuál esté
        // seleccionada cuando le toque el turno a cada archivo.
        $metadata['cedula_receptor'] = (string) ($data['cedula_receptor'] ?? '');
        $metadata['sociedad_id'] = (int) ($data['sociedad_id'] ?? 0);

        $importacionId = (int) $this->importacionModel->crear([
            'tipo' => 'xml',
            'archivo_origen' => $archivoOrigen !== '' ? $archivoOrigen : 'multiple_xml_files',
            // La carpeta definitiva se conoce hasta tener el id; se corrige
            // enseguida con actualizarResumen().
            'ruta_archivo' => null,
            'total_registros' => $totalEsperado,
            'registros_exitosos' => 0,
            'registros_fallidos' => 0,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        $directory = $this->getImportacionDirectory($importacionId, $metadata['sociedad_id']);
        $this->ensureDirectory($directory);
        $this->importacionModel->actualizarResumen($importacionId, [
            'ruta_archivo' => $directory,
        ]);

        return [
            'importacion_id' => $importacionId,
            'ruta_archivo' => $directory,
            'metadata' => $metadata,
        ];
    }

    public function agregarArchivos($importacionId, $fieldName = 'xml_files')
    {
        $importacion = $this->getImportacionOrFail($importacionId);
        // La carpeta que se le dio al iniciarla, en el almacén de su empresa.
        $directory = (string) ($importacion['ruta_archivo'] ?? '');
        if ($directory === '') {
            $meta = json_decode((string) ($importacion['metadata'] ?? ''), true);
            $directory = $this->getImportacionDirectory(
                (int) $importacion['id'],
                is_array($meta) ? (int) ($meta['sociedad_id'] ?? 0) : 0
            );
        }
        $this->ensureDirectory($directory);

        // Lo que sube el navegador cae primero en public/uploads, que es de
        // esta máquina, y de ahí se le entrega al almacén. Subirlo directo a
        // la carpeta de la cola funcionaba mientras esa carpeta fuera un
        // disco; con los documentos en SharePoint no hay dónde escribir.
        $paso = rtrim((string) ($this->config['uploads_path'] ?? ''), '/\\') . DIRECTORY_SEPARATOR . 'xml_queue';
        $uploadedFiles = FileUploader::uploadMultiple($fieldName, $paso, $this->allowedExtensions, $this->maxUploadSize);

        foreach ($uploadedFiles as $i => $file) {
            $destino = $directory . DIRECTORY_SEPARATOR . $file['saved_name'];
            AlmacenDocumentos::de($destino)->adoptar($destino, (string) $file['path']);
            $uploadedFiles[$i]['path'] = $destino;
        }

        foreach ($uploadedFiles as $file) {
            $this->importacionItemModel->crear([
                'importacion_id' => (int) $importacion['id'],
                'archivo_original' => (string) ($file['original_name'] ?? ''),
                'archivo_guardado' => $file['saved_name'] ?? null,
                'ruta_archivo' => (string) ($file['path'] ?? ''),
                'extension' => strtolower((string) pathinfo((string) ($file['original_name'] ?? ''), PATHINFO_EXTENSION)),
                'tamano' => (int) ($file['size'] ?? 0),
                'estado' => 'pendiente',
                'metadata' => [
                    'subido_en' => date('Y-m-d H:i:s'),
                ],
            ]);
        }

        $estado = $this->persistirEstadoImportacion((int) $importacion['id'], [
            'estado_cola' => 'en_cola',
        ]);

        return [
            'importacion_id' => (int) $importacion['id'],
            'uploaded_count' => count($uploadedFiles),
            'files' => $uploadedFiles,
            'estado' => $estado,
        ];
    }

    /**
     * Encola archivos que ya están en el almacén (los XML capturados del
     * correo, que esperan en la bandeja).
     *
     * La cola los toma DONDE ESTÁN, sin copiarlos: el ítem apunta al archivo
     * de la bandeja y la cola lo borra cuando termina de procesarlo. Antes se
     * copiaba a IMPORTACIONES/ y enseguida se borraba el original, que es un
     * traslado que costaba escribir dos veces; contra SharePoint serían dos
     * subidas y una bajada por documento, a un segundo cada una.
     *
     * Quien llama NO tiene que borrar el original: ahora es de la cola.
     *
     * $rutas: lista de rutas, o ['ruta' => ..., 'nombre' => ...] para
     * conservar el nombre original del adjunto.
     */
    public function agregarArchivosLocales($importacionId, array $rutas)
    {
        $importacion = $this->getImportacionOrFail($importacionId);

        $agregados = [];

        foreach ($rutas as $entrada) {
            $ruta = is_array($entrada) ? (string) ($entrada['ruta'] ?? '') : (string) $entrada;
            $rutaPdf = is_array($entrada) ? (string) ($entrada['ruta_pdf'] ?? '') : '';
            $nombreOriginal = is_array($entrada) && !empty($entrada['nombre'])
                ? (string) $entrada['nombre']
                : basename($ruta);

            if ($ruta === '' || !RutaDocumento::existe($ruta)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($nombreOriginal, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->allowedExtensions, true)) {
                continue;
            }

            $savedName = basename(str_replace('\\', '/', $ruta));

            $this->importacionItemModel->crear([
                'importacion_id' => (int) $importacion['id'],
                'archivo_original' => $nombreOriginal,
                'archivo_guardado' => $savedName,
                'ruta_archivo' => $ruta,
                'extension' => $extension,
                'tamano' => (int) RutaDocumento::tamano($ruta),
                'estado' => 'pendiente',
                'metadata' => [
                    'subido_en' => date('Y-m-d H:i:s'),
                    'origen' => 'correo',
                    // Relativa como todo lo que se persiste: esta metadata la
                    // lee después otra petición, quizá en otra computadora.
                    'ruta_pdf' => $rutaPdf !== '' && RutaDocumento::existe($rutaPdf) ? RutaDocumento::relativa($rutaPdf) : null,
                    'correo_cuenta_id' => is_array($entrada) ? (int) ($entrada['correo_cuenta_id'] ?? 0) : 0,
                    'correo_carpeta' => is_array($entrada) ? (string) ($entrada['correo_carpeta'] ?? '') : '',
                    'correo_uidvalidity' => is_array($entrada) ? (int) ($entrada['correo_uidvalidity'] ?? 0) : 0,
                    'correo_uid' => is_array($entrada) ? (int) ($entrada['correo_uid'] ?? 0) : 0,
                    'fecha_correo' => is_array($entrada) ? ($entrada['fecha_correo'] ?? null) : null,
                ],
            ]);

            $agregados[] = [
                'original_name' => $nombreOriginal,
                'saved_name' => $savedName,
                'path' => $ruta,
            ];
        }

        $estado = $this->persistirEstadoImportacion((int) $importacion['id'], [
            'estado_cola' => 'en_cola',
        ]);

        return [
            'importacion_id' => (int) $importacion['id'],
            'uploaded_count' => count($agregados),
            'files' => $agregados,
            'estado' => $estado,
        ];
    }

    public function procesarBatch($importacionId, $limit = 5)
    {
        $this->extendExecutionWindow();

        $importacion = $this->getImportacionOrFail($importacionId);
        $this->importacionItemModel->requeueStaleProcessing(self::STALE_PROCESSING_SECONDS, (int) $importacion['id']);

        $processed = [];
        $startedAt = microtime(true);
        $maxItems = max(1, min(25, (int) $limit));

        while (count($processed) < $maxItems) {
            if (!empty($processed) && $this->shouldStopCurrentBatch($startedAt)) {
                break;
            }

            $claimedItems = $this->importacionItemModel->claimPendingBatch((int) $importacion['id'], 1);
            if (empty($claimedItems)) {
                break;
            }

            $processed[] = $this->procesarItem($claimedItems[0]);

            if ($this->shouldStopCurrentBatch($startedAt)) {
                break;
            }
        }

        $estado = $this->persistirEstadoImportacion((int) $importacion['id'], [
            'estado_cola' => empty($processed) ? 'en_cola' : 'procesando',
            'pagos_afectados' => $this->resolverPagosAfectados($importacion, $processed),
        ]);

        return [
            'importacion_id' => (int) $importacion['id'],
            'processed_in_batch' => count($processed),
            'processed' => $processed,
            'completed' => (bool) ($estado['completed'] ?? false),
            'estado' => $estado,
        ];
    }

    public function procesarPendientesGlobal($limit = 10)
    {
        $this->extendExecutionWindow();
        $this->importacionItemModel->requeueStaleProcessing(self::STALE_PROCESSING_SECONDS);

        $processed = [];
        $importaciones = [];
        $procesadosPorImportacion = [];
        $startedAt = microtime(true);
        $maxItems = max(1, min(50, (int) $limit));

        while (count($processed) < $maxItems) {
            if (!empty($processed) && $this->shouldStopCurrentBatch($startedAt)) {
                break;
            }

            $claimedItems = $this->importacionItemModel->claimPendingBatchGlobal(1);
            if (empty($claimedItems)) {
                break;
            }

            $item = $claimedItems[0];
            $resultadoItem = $this->procesarItem($item);
            $processed[] = $resultadoItem;
            $iid = (int) $item['importacion_id'];
            $importaciones[$iid] = true;
            $procesadosPorImportacion[$iid][] = $resultadoItem;

            if ($this->shouldStopCurrentBatch($startedAt)) {
                break;
            }
        }

        $summaries = [];
        foreach (array_keys($importaciones) as $importacionId) {
            $importacion = $this->getImportacionOrFail((int) $importacionId);
            $summaries[] = $this->persistirEstadoImportacion((int) $importacionId, [
                'estado_cola' => 'procesando',
                'pagos_afectados' => $this->resolverPagosAfectados(
                    $importacion,
                    $procesadosPorImportacion[(int) $importacionId] ?? []
                ),
            ]);
        }

        return [
            'processed_in_batch' => count($processed),
            'processed' => $processed,
            'imports' => $summaries,
            'completed' => empty($processed),
        ];
    }

    public function getEstado($importacionId)
    {
        $importacion = $this->getImportacionOrFail($importacionId);
        return $this->buildEstado($importacion, $this->importacionItemModel->getStats((int) $importacion['id']));
    }

    /**
     * Carpeta de una importación, en _TRABAJO/IMPORTACIONES de SharePoint y
     * no en public/uploads/: una importación se procesa a lo largo de varias
     * peticiones y quien la termine puede ser otra persona desde otra
     * computadora. Se resuelve al usarla —y no en el constructor— para que
     * consultar el historial de importaciones siga funcionando aunque esta
     * máquina todavía no tenga SharePoint.
     */
    public function getImportacionDirectory($importacionId, $sociedadId = null)
    {
        return RutaDocumento::carpetaTrabajo(
            'IMPORTACIONES/importacion_' . (int) $importacionId,
            $sociedadId !== null && (int) $sociedadId > 0 ? (int) $sociedadId : null
        );
    }

    /** Cache por petición: pago asignada a cada importación (metadata). */
    private $pagoPorImportacion = [];

    /**
     * Pago de la importación, pública para la re-verificación automática
     * de los listados por pagar al completarse la cola.
     */
    public function pagoImportacion($importacionId)
    {
        return $this->pagoDeImportacion($importacionId);
    }

    /** Pagos que deben revalidarse porque recibieron o perdieron facturas. */
    public function pagosAfectadosImportacion($importacionId)
    {
        $importacion = $this->getImportacionOrFail((int) $importacionId);
        return $this->resolverPagosAfectados($importacion, []);
    }

    private function resolverPagosAfectados(array $importacion, array $resultados)
    {
        $metadata = $this->decodeMetadata($importacion['metadata'] ?? null);
        $pagos = array_map('intval', (array) ($metadata['pagos_afectados'] ?? []));
        if (!empty($metadata['pago_id'])) {
            $pagos[] = (int) $metadata['pago_id'];
        }
        foreach ($resultados as $resultado) {
            if (!empty($resultado['pago_anterior'])) {
                $pagos[] = (int) $resultado['pago_anterior'];
            }
            if (!empty($resultado['pago_id'])) {
                $pagos[] = (int) $resultado['pago_id'];
            }
        }
        return array_values(array_unique(array_filter($pagos, static function ($id) {
            return $id > 0;
        })));
    }

    /**
     * Pago de la importación (metadata.pago_id) o null.
     */
    private function pagoDeImportacion($importacionId)
    {
        $importacionId = (int) $importacionId;
        if ($importacionId <= 0) {
            return null;
        }

        if (!array_key_exists($importacionId, $this->pagoPorImportacion)) {
            $pago = null;
            try {
                $importacion = $this->importacionModel->findById($importacionId);
                $metadata = $this->decodeMetadata($importacion['metadata'] ?? null);
                if (!empty($metadata['pago_id'])) {
                    $pago = (int) $metadata['pago_id'];
                }
            } catch (Throwable $e) {
                // Sin metadata legible la factura queda sin pago
            }
            $this->pagoPorImportacion[$importacionId] = $pago;
        }

        return $this->pagoPorImportacion[$importacionId];
    }

    private function procesarItem(array $item)
    {
        $itemId = (int) ($item['id'] ?? 0);
        $importacionId = (int) ($item['importacion_id'] ?? 0);
        $originalName = (string) ($item['archivo_original'] ?? '');
        $filePath = (string) ($item['ruta_archivo'] ?? '');

        try {
            $this->extendExecutionWindow();
            $importacion = $this->importacionModel->findById($importacionId);
            $metaImportacion = $this->decodeMetadata($importacion['metadata'] ?? null);
            $metaItem = $this->decodeMetadata($item['metadata'] ?? null);
            $tipoPermitido = strtoupper((string) ($metaImportacion['tipo_documento'] ?? 'FE'));

            $pdfPath = RutaDocumento::absoluta($metaItem['ruta_pdf'] ?? '');
            $resultado = (new XmlDocumentImporter())->importar($filePath, $pdfPath !== '' ? $pdfPath : null, [
                'origen' => 'cola_xml',
                'importacion_id' => $importacionId,
                'pago_id' => $this->pagoDeImportacion($importacionId),
                'tipos_permitidos' => [$tipoPermitido],
                'validar_receptor' => !empty($metaImportacion['cedula_receptor']),
                'cedula_receptor' => (string) ($metaImportacion['cedula_receptor'] ?? ''),
                'sociedad_id' => (int) ($metaImportacion['sociedad_id'] ?? 0),
                'correo_cuenta_id' => $metaItem['correo_cuenta_id'] ?? null,
                'correo_carpeta' => $metaItem['correo_carpeta'] ?? null,
                'correo_uidvalidity' => $metaItem['correo_uidvalidity'] ?? null,
                'correo_uid' => $metaItem['correo_uid'] ?? null,
                'fecha_correo' => $metaItem['fecha_correo'] ?? null,
            ]);

            $facturaId = (int) ($resultado['id'] ?? 0);
            $docData = $resultado['documento'] ?? [];
            $estadoDocumento = (string) ($resultado['estado'] ?? '');
            $estado = in_array($estadoDocumento, ['importado', 'movido_pago'], true)
                ? 'importado'
                : 'duplicado';
            $this->importacionItemModel->marcarResultado($itemId, $estado, null, $facturaId ?: null, [
                'proveedor' => $docData['razon_social_emisor'] ?? null,
                'consecutivo' => $docData['consecutivo_completo'] ?? null,
                'resultado_documento' => $estadoDocumento,
                'pago_anterior' => $resultado['pago_anterior'] ?? null,
                'pago_id' => $resultado['pago_id'] ?? $this->pagoDeImportacion($importacionId),
            ]);

            // El documento ya está archivado en su carpeta definitiva: estas
            // dos son las copias de trabajo, y quedarse con ellas sería tener
            // el mismo comprobante en tres sitios.
            if ($filePath) {
                AlmacenDocumentos::de($filePath)->borrar($filePath);
            }
            if (!empty($pdfPath)) {
                AlmacenDocumentos::de($pdfPath)->borrar($pdfPath);
            }

            return [
                'item_id' => $itemId,
                'archivo' => $originalName,
                'estado' => $estado,
                'factura_id' => $facturaId,
                'resultado_documento' => $estadoDocumento,
                'pago_anterior' => $resultado['pago_anterior'] ?? null,
                'pago_id' => $resultado['pago_id'] ?? $this->pagoDeImportacion($importacionId),
            ];
        } catch (Throwable $e) {
            $estado = $this->clasificarError($e->getMessage());
            $this->importacionItemModel->marcarResultado($itemId, $estado, $e->getMessage(), null, [
                'exception_class' => get_class($e),
            ]);
            if ($filePath) {
                AlmacenDocumentos::de($filePath)->borrar($filePath);
            }
            $metaItem = isset($metaItem) ? $metaItem : $this->decodeMetadata($item['metadata'] ?? null);
            $pdfPath = RutaDocumento::absoluta($metaItem['ruta_pdf'] ?? '');
            if ($pdfPath !== '') {
                AlmacenDocumentos::de($pdfPath)->borrar($pdfPath);
            }
            return [
                'item_id' => $itemId,
                'archivo' => $originalName,
                'estado' => $estado,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function persistirEstadoImportacion($importacionId, array $extraMetadata = [])
    {
        $importacion = $this->getImportacionOrFail($importacionId);
        $stats = $this->importacionItemModel->getStats((int) $importacion['id']);
        $estado = $this->buildEstado($importacion, $stats, $extraMetadata);
        $metadata = $estado['metadata'];

        $errores = $this->importacionItemModel->getRecentIssues((int) $importacion['id'], 20);

        $this->importacionModel->actualizarResumen((int) $importacion['id'], [
            'total_registros' => (int) $estado['expected_total'],
            'registros_exitosos' => (int) $stats['importado'],
            'registros_fallidos' => (int) ($stats['duplicado'] + $stats['error']),
            'errores' => !empty($errores) ? json_encode($errores, JSON_UNESCAPED_UNICODE) : null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        $updated = $this->getImportacionOrFail($importacionId);
        return $this->buildEstado($updated, $stats);
    }

    private function buildEstado(array $importacion, array $stats, array $extraMetadata = [])
    {
        $metadata = array_merge($this->decodeMetadata($importacion['metadata'] ?? null), $extraMetadata);
        $expectedTotal = max(
            (int) ($metadata['total_esperado'] ?? 0),
            (int) ($importacion['total_registros'] ?? 0),
            (int) ($stats['total'] ?? 0)
        );

        $subidaCompleta = $expectedTotal > 0 ? ((int) ($stats['total'] ?? 0) >= $expectedTotal) : ((int) ($stats['total'] ?? 0) > 0);
        $completed = $subidaCompleta
            && (int) ($stats['pendiente'] ?? 0) === 0
            && (int) ($stats['procesando'] ?? 0) === 0
            && (int) ($stats['total'] ?? 0) > 0;

        $estadoCola = (string) ($metadata['estado_cola'] ?? 'subiendo');

        if ($completed) {
            $estadoCola = 'completada';
        } elseif ((int) ($stats['procesando'] ?? 0) > 0) {
            $estadoCola = 'procesando';
        } elseif ((int) ($stats['pendiente'] ?? 0) > 0) {
            $estadoCola = 'en_cola';
        } elseif ((int) ($stats['total'] ?? 0) === 0) {
            $estadoCola = 'subiendo';
        }

        $progressBase = max($expectedTotal, (int) ($stats['total'] ?? 0), 1);
        $progressPercent = min(100, (int) round((((int) ($stats['terminales'] ?? 0)) / $progressBase) * 100));

        $metadata['estado_cola'] = $estadoCola;
        $metadata['total_esperado'] = $expectedTotal;
        $metadata['archivos_subidos'] = (int) ($stats['total'] ?? 0);
        $metadata['subida_completa'] = $subidaCompleta;
        $metadata['completed'] = $completed;
        $metadata['ultima_actualizacion'] = date('Y-m-d H:i:s');
        $metadata['progress_percent'] = $progressPercent;

        return [
            'importacion_id' => (int) ($importacion['id'] ?? 0),
            'importacion' => $importacion,
            'metadata' => $metadata,
            'stats' => $stats,
            'expected_total' => $expectedTotal,
            'progress_percent' => $progressPercent,
            'completed' => $completed,
            'recent_issues' => $this->importacionItemModel->getRecentIssues((int) ($importacion['id'] ?? 0), 10),
        ];
    }

    private function getImportacionOrFail($importacionId)
    {
        $importacion = $this->importacionModel->findById((int) $importacionId);
        if (!$importacion) {
            throw new Exception('Importacion no encontrada: ' . (int) $importacionId);
        }

        return $importacion;
    }

    private function decodeMetadata($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function clasificarError($message)
    {
        $message = (string) $message;
        $isDuplicate = stripos($message, 'Duplicate entry') !== false
            || stripos($message, 'uk_consecutivo') !== false
            || stripos($message, 'uk_hash') !== false;

        if ($isDuplicate) {
            return 'duplicado';
        }

        return 'error';
    }

    /**
     * En disco la carpeta se deja creada, como siempre. Donde recorrer cuesta
     * red —SharePoint— no: la crea la primera subida, y crearla de antemano
     * dejaba una carpeta vacía en la biblioteca por cada importación —las que
     * vienen de la bandeja nunca copian nada ahí— a costa de llamadas con
     * alguien esperando.
     */
    private function ensureDirectory($path)
    {
        $almacen = AlmacenDocumentos::de($path);
        if ($almacen->recorreElArbolBarato()) {
            $almacen->asegurarCarpeta($path);
        }
    }

    private function shouldStopCurrentBatch($startedAt)
    {
        return (microtime(true) - (float) $startedAt) >= self::REQUEST_TIME_BUDGET_SECONDS;
    }

    private function extendExecutionWindow($seconds = self::REQUEST_TIME_LIMIT_SECONDS)
    {
        $seconds = max(120, (int) $seconds);

        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }

        @ini_set('max_execution_time', (string) $seconds);
    }
}
