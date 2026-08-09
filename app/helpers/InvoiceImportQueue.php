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
    private $queueBaseDir = null;
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

        // Semana de trabajo elegida: cada factura de esta importación
        // quedará asignada a ella (buildEstado conserva la clave al mergear)
        if (!empty($data['semana_id'])) {
            $metadata['semana_id'] = (int) $data['semana_id'];
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

        $directory = $this->getImportacionDirectory($importacionId);
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
        $directory = $this->getImportacionDirectory((int) $importacion['id']);
        $this->ensureDirectory($directory);

        $uploadedFiles = FileUploader::uploadMultiple($fieldName, $directory, $this->allowedExtensions, $this->maxUploadSize);

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
     * Encola archivos que ya están en el servidor (p. ej. XML capturados del
     * correo). Copia cada archivo al directorio de la importación; el original
     * queda intacto (la cola borra su copia al procesarla).
     *
     * $rutas: lista de rutas absolutas, o ['ruta' => ..., 'nombre' => ...]
     * para conservar el nombre original del adjunto.
     */
    public function agregarArchivosLocales($importacionId, array $rutas)
    {
        $importacion = $this->getImportacionOrFail($importacionId);
        $directory = $this->getImportacionDirectory((int) $importacion['id']);
        $this->ensureDirectory($directory);

        $agregados = [];

        foreach ($rutas as $entrada) {
            $ruta = is_array($entrada) ? (string) ($entrada['ruta'] ?? '') : (string) $entrada;
            $rutaPdf = is_array($entrada) ? (string) ($entrada['ruta_pdf'] ?? '') : '';
            $nombreOriginal = is_array($entrada) && !empty($entrada['nombre'])
                ? (string) $entrada['nombre']
                : basename($ruta);

            if ($ruta === '' || !is_file($ruta)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($nombreOriginal, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->allowedExtensions, true)) {
                continue;
            }

            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombreOriginal);
            $savedName = uniqid('local_', true) . '_' . $nombreSeguro;
            $destino = $directory . DIRECTORY_SEPARATOR . $savedName;

            if (!copy($ruta, $destino)) {
                continue;
            }

            $this->importacionItemModel->crear([
                'importacion_id' => (int) $importacion['id'],
                'archivo_original' => $nombreOriginal,
                'archivo_guardado' => $savedName,
                'ruta_archivo' => $destino,
                'extension' => $extension,
                'tamano' => (int) filesize($destino),
                'estado' => 'pendiente',
                'metadata' => [
                    'subido_en' => date('Y-m-d H:i:s'),
                    'origen' => 'correo',
                    // Relativa como todo lo que se persiste: esta metadata la
                    // lee después otra petición, quizá en otra computadora.
                    'ruta_pdf' => $rutaPdf !== '' && is_file($rutaPdf) ? RutaDocumento::relativa($rutaPdf) : null,
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
                'path' => $destino,
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
            'semanas_afectadas' => $this->resolverSemanasAfectadas($importacion, $processed),
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
                'semanas_afectadas' => $this->resolverSemanasAfectadas(
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

    public function getImportacionDirectory($importacionId)
    {
        return $this->queueBaseDir() . DIRECTORY_SEPARATOR . 'importacion_' . (int) $importacionId;
    }

    /**
     * Carpeta de la cola, dentro de la carpeta compartida y no en
     * public/uploads/: una importación se procesa a lo largo de varias
     * peticiones y quien la termine puede ser otra persona desde otra
     * computadora. Se resuelve al usarla —y no en el constructor— para que
     * consultar el historial de importaciones siga funcionando aunque la
     * carpeta compartida todavía no esté configurada en esta máquina.
     */
    private function queueBaseDir()
    {
        if ($this->queueBaseDir === null) {
            $this->queueBaseDir = RutaDocumento::carpetaTrabajo('IMPORTACIONES');
        }
        return $this->queueBaseDir;
    }

    /** Cache por petición: semana asignada a cada importación (metadata). */
    private $semanaPorImportacion = [];

    /**
     * Semana de la importación, pública para la re-verificación automática
     * de los listados por pagar al completarse la cola.
     */
    public function semanaImportacion($importacionId)
    {
        return $this->semanaDeImportacion($importacionId);
    }

    /** Semanas que deben revalidarse porque recibieron o perdieron facturas. */
    public function semanasAfectadasImportacion($importacionId)
    {
        $importacion = $this->getImportacionOrFail((int) $importacionId);
        return $this->resolverSemanasAfectadas($importacion, []);
    }

    private function resolverSemanasAfectadas(array $importacion, array $resultados)
    {
        $metadata = $this->decodeMetadata($importacion['metadata'] ?? null);
        $semanas = array_map('intval', (array) ($metadata['semanas_afectadas'] ?? []));
        if (!empty($metadata['semana_id'])) {
            $semanas[] = (int) $metadata['semana_id'];
        }
        foreach ($resultados as $resultado) {
            if (!empty($resultado['semana_anterior'])) {
                $semanas[] = (int) $resultado['semana_anterior'];
            }
            if (!empty($resultado['semana_id'])) {
                $semanas[] = (int) $resultado['semana_id'];
            }
        }
        return array_values(array_unique(array_filter($semanas, static function ($id) {
            return $id > 0;
        })));
    }

    /**
     * Semana de trabajo de la importación (metadata.semana_id) o null.
     */
    private function semanaDeImportacion($importacionId)
    {
        $importacionId = (int) $importacionId;
        if ($importacionId <= 0) {
            return null;
        }

        if (!array_key_exists($importacionId, $this->semanaPorImportacion)) {
            $semana = null;
            try {
                $importacion = $this->importacionModel->findById($importacionId);
                $metadata = $this->decodeMetadata($importacion['metadata'] ?? null);
                if (!empty($metadata['semana_id'])) {
                    $semana = (int) $metadata['semana_id'];
                }
            } catch (Throwable $e) {
                // Sin metadata legible la factura queda sin semana
            }
            $this->semanaPorImportacion[$importacionId] = $semana;
        }

        return $this->semanaPorImportacion[$importacionId];
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
                'semana_id' => $this->semanaDeImportacion($importacionId),
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
            $estado = in_array($estadoDocumento, ['importado', 'movida_semana'], true)
                ? 'importado'
                : 'duplicado';
            $this->importacionItemModel->marcarResultado($itemId, $estado, null, $facturaId ?: null, [
                'proveedor' => $docData['razon_social_emisor'] ?? null,
                'consecutivo' => $docData['consecutivo_completo'] ?? null,
                'resultado_documento' => $estadoDocumento,
                'semana_anterior' => $resultado['semana_anterior'] ?? null,
                'semana_id' => $resultado['semana_id'] ?? $this->semanaDeImportacion($importacionId),
            ]);

            if ($filePath && is_file($filePath)) {
                @unlink($filePath);
            }
            if (!empty($pdfPath) && is_file($pdfPath)) {
                @unlink($pdfPath);
            }

            return [
                'item_id' => $itemId,
                'archivo' => $originalName,
                'estado' => $estado,
                'factura_id' => $facturaId,
                'resultado_documento' => $estadoDocumento,
                'semana_anterior' => $resultado['semana_anterior'] ?? null,
                'semana_id' => $resultado['semana_id'] ?? $this->semanaDeImportacion($importacionId),
            ];
        } catch (Throwable $e) {
            $estado = $this->clasificarError($e->getMessage());
            $this->importacionItemModel->marcarResultado($itemId, $estado, $e->getMessage(), null, [
                'exception_class' => get_class($e),
            ]);
            if ($filePath && is_file($filePath)) {
                @unlink($filePath);
            }
            $metaItem = isset($metaItem) ? $metaItem : $this->decodeMetadata($item['metadata'] ?? null);
            $pdfPath = RutaDocumento::absoluta($metaItem['ruta_pdf'] ?? '');
            if ($pdfPath !== '' && is_file($pdfPath)) {
                @unlink($pdfPath);
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

    private function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception('No se pudo crear el directorio de cola: ' . $path);
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
