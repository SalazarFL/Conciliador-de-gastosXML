<?php
/**
 * Servicio de cola para importaciones masivas de facturas XML.
 */

require_once __DIR__ . '/../models/Importacion.php';
require_once __DIR__ . '/../models/ImportacionItem.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/FileUploader.php';

if (!class_exists('XmlInvoiceParser', false)) {
    require_once __DIR__ . '/XmlParser.php';
}

class InvoiceImportQueue
{
    private const REQUEST_TIME_LIMIT_SECONDS = 600;
    private const REQUEST_TIME_BUDGET_SECONDS = 45;
    private const STALE_PROCESSING_SECONDS = 150;

    private $config;
    private $queueBaseDir;
    private $allowedExtensions;
    private $maxUploadSize;
    private $importacionModel;
    private $importacionItemModel;
    private $facturaModel;
    private $proveedorModel;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->queueBaseDir = rtrim($this->config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'xml_queue';
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

        $importacionId = (int) $this->importacionModel->crear([
            'tipo' => 'xml',
            'archivo_origen' => $archivoOrigen !== '' ? $archivoOrigen : 'multiple_xml_files',
            'ruta_archivo' => $this->queueBaseDir,
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
            $processed[] = $this->procesarItem($item);
            $importaciones[(int) $item['importacion_id']] = true;

            if ($this->shouldStopCurrentBatch($startedAt)) {
                break;
            }
        }

        $summaries = [];
        foreach (array_keys($importaciones) as $importacionId) {
            $summaries[] = $this->persistirEstadoImportacion((int) $importacionId, [
                'estado_cola' => 'procesando',
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
        return $this->queueBaseDir . DIRECTORY_SEPARATOR . 'importacion_' . (int) $importacionId;
    }

    private function procesarItem(array $item)
    {
        $itemId = (int) ($item['id'] ?? 0);
        $importacionId = (int) ($item['importacion_id'] ?? 0);
        $originalName = (string) ($item['archivo_original'] ?? '');
        $filePath = (string) ($item['ruta_archivo'] ?? '');

        try {
            $this->extendExecutionWindow();

            $docData = XmlInvoiceParser::parseCfdiFromFile($filePath);

            $proveedorId = $this->proveedorModel->obtenerOCrear(
                $docData['rfc_emisor'] ?? '',
                $docData['razon_social_emisor'] ?? ''
            );

            $metadata = $docData['metadata'] ?? [];
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata['queue_item_id'] = $itemId;

            $hashDocumento = (string) ($docData['hash_documento'] ?? ($docData['hash_xml'] ?? null));

            $facturaId = (int) $this->facturaModel->crear([
                'importacion_id' => $importacionId,
                'consecutivo_completo' => $docData['consecutivo_completo'],
                'numero_factura_asistente' => $docData['numero_factura_asistente'],
                'proveedor_id' => $proveedorId,
                'fecha_emision' => $docData['fecha_emision'],
                'subtotal' => $docData['subtotal'],
                'iva' => $docData['iva'],
                'total' => $docData['total'],
                'moneda' => $docData['moneda'],
                'tipo_comprobante' => $docData['tipo_comprobante'] ?? null,
                'archivo_xml' => $originalName,
                'ruta_xml' => $filePath,
                'hash_xml' => $hashDocumento !== '' ? $hashDocumento : null,
                'xml_contenido' => $docData['xml_contenido'] ?? null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);

            $this->importacionItemModel->marcarResultado($itemId, 'importado', null, $facturaId, [
                'proveedor' => $docData['razon_social_emisor'] ?? null,
                'consecutivo' => $docData['consecutivo_completo'] ?? null,
            ]);

            if ($filePath && is_file($filePath)) {
                @unlink($filePath);
            }

            return [
                'item_id' => $itemId,
                'archivo' => $originalName,
                'estado' => 'importado',
                'factura_id' => $facturaId,
            ];
        } catch (Throwable $e) {
            $estado = $this->clasificarError($e->getMessage());

            $this->importacionItemModel->marcarResultado($itemId, $estado, $e->getMessage(), null, [
                'exception_class' => get_class($e),
            ]);

            if ($filePath && is_file($filePath)) {
                @unlink($filePath);
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
