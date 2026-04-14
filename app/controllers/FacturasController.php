<?php
/**
 * Controlador de gestión de facturas
 */

class FacturasController extends Controller
{
	private $queueService;

	public function index()
	{
		$facturas = [];

		try {
			$facturaModel = $this->loadModel('Factura');
			$facturas = $facturaModel->getAllWithImportacion();
		} catch (Exception $e) {
			$this->redirectWithMessage($this->url('/facturas/importar'), 'No fue posible cargar facturas: ' . $e->getMessage(), 'warning');
		}

		$this->render('facturas/index', [
			'title' => 'Facturas - XMLConcilia',
			'facturas' => $facturas
		]);
	}

	public function importar()
	{
		$this->redirect($this->url('/conciliacion'));
	}

	public function subir()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/conciliacion'));
		}

		require_once __DIR__ . '/../helpers/FileUploader.php';
		if (!class_exists('XmlInvoiceParser', false)) {
			require_once __DIR__ . '/../helpers/XmlParser.php';
		}
		if (!class_exists('PdfInvoiceParser', false)) {
			require_once __DIR__ . '/../helpers/PdfParser.php';
		}

		$config = require __DIR__ . '/../config/config.php';
		$uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'xml';
		$maxSize = $config['max_upload_size'] ?? 10485760;
		$allowed = $config['allowed_extensions']['xml'] ?? ['xml'];

		try {
			$uploadedFiles = FileUploader::uploadMultiple('xml_files', $uploadDir, $allowed, $maxSize);

			$importacionModel = $this->loadModel('Importacion');
			$facturaModel = $this->loadModel('Factura');
			$proveedorModel = $this->loadModel('Proveedor');

			$importacionId = $importacionModel->crear([
				'tipo' => 'xml',
				'archivo_origen' => count($uploadedFiles) === 1 ? $uploadedFiles[0]['original_name'] : 'multiple_xml_files',
				'ruta_archivo' => $uploadDir
			]);

			// Cuántos archivos recibió realmente PHP (puede ser menor a lo enviado si hay límite ini).
			$recibidos = count($uploadedFiles);
			$limitePhp = (int) ini_get('max_file_uploads');

			$exitosos = 0;
			$duplicados = 0;
			$fallidos = 0;
			$errores = [];
			$sinPlantilla = [];   // archivos PDF sin plantilla configurada
			$archivosError = [];  // archivos con error real de parseo/DB
			$archivosDup = [];    // archivos ya importados (duplicado de consecutivo)

			foreach ($uploadedFiles as $file) {
				$ext = strtolower(pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION));
				$isPdf = ($ext === 'pdf');

				try {
					if ($isPdf) {
						$docData = PdfInvoiceParser::parseInvoiceFromPdf($file['path'], [
							'max_ocr_pages' => 3,
							'ocr_language' => 'spa+eng',
							'source_name' => (string) ($file['original_name'] ?? ''),
							'require_template' => true,
						]);
					} else {
						$docData = XmlInvoiceParser::parseCfdiFromFile($file['path']);
					}

					$proveedorId = $proveedorModel->obtenerOCrear($docData['rfc_emisor'] ?? '', $docData['razon_social_emisor'] ?? '');

					$metadata = $docData['metadata'] ?? [];
					if (!is_array($metadata)) {
						$metadata = [];
					}
					$metadata['origen_archivo'] = $isPdf ? 'pdf' : 'xml';

					$hashDocumento = (string) ($docData['hash_documento'] ?? ($docData['hash_xml'] ?? null));

					$facturaModel->crear([
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
						'archivo_xml' => $file['original_name'],
						'ruta_xml' => $isPdf ? null : $file['path'],
						'hash_xml' => $hashDocumento !== '' ? $hashDocumento : null,
						'xml_contenido' => $isPdf ? null : ($docData['xml_contenido'] ?? null),
						'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
					]);

					$exitosos++;
				} catch (Throwable $e) {
					$msg = $e->getMessage();
					$esDuplicado = stripos($msg, 'Duplicate entry') !== false
						|| stripos($msg, 'uk_consecutivo') !== false
						|| stripos($msg, 'uk_hash') !== false;
					$esSinPlantilla = stripos($msg, 'sin plantilla de parseo') !== false;

					if ($esDuplicado) {
						$duplicados++;
						$archivosDup[] = $file['original_name'];
					} elseif ($esSinPlantilla) {
						$fallidos++;
						$sinPlantilla[] = $file['original_name'];
					} else {
						$fallidos++;
						$archivosError[] = $file['original_name'];
					}

					$errores[] = [
						'archivo' => $file['original_name'],
						'error' => $msg
					];
				} finally {
					// Los PDF se usan solo para extracción y luego se descartan.
					if ($isPdf && !empty($file['path']) && is_file($file['path'])) {
						@unlink($file['path']);
					}
				}
			}

			$importacionModel->cerrar($importacionId, $recibidos, $exitosos, $fallidos + $duplicados, $errores);

			// Aviso si el servidor pudo haber truncado archivos por límite PHP.
			$avisoLimite = '';
			if ($limitePhp > 0 && $recibidos >= $limitePhp) {
				$avisoLimite = "El servidor recibió solo {$recibidos} archivos (límite PHP max_file_uploads={$limitePhp}). Si enviaste más, divídelos en lotes de {$limitePhp}.";
			}

			// Armar resumen legible.
			$partes = ["Recibidos por servidor: {$recibidos}"];
			$partes[] = "Importados: {$exitosos}";
			if ($duplicados > 0) {
				$partes[] = "Ya existían: {$duplicados}";
			}
			if (!empty($sinPlantilla)) {
				$partes[] = "Sin plantilla PDF: " . count($sinPlantilla);
			}
			if ($fallidos > count($sinPlantilla)) {
				$partes[] = "Errores reales: " . ($fallidos - count($sinPlantilla));
			}

			$hayProblemas = $fallidos > 0 || !empty($sinPlantilla) || $avisoLimite !== '';
			$todosFallaron = $exitosos === 0 && $duplicados === 0;
			$tipo = $todosFallaron ? 'error' : ($hayProblemas ? 'warning' : 'success');

			$this->redirectWithMessage(
				$this->url('/conciliacion'),
				implode(' | ', $partes),
				$tipo,
				[
					'server_limit_warning' => $avisoLimite,
					'duplicate_files'      => array_values(array_unique($archivosDup)),
					'without_template'     => array_values(array_unique($sinPlantilla)),
					'failed_files'         => array_values(array_unique($archivosError)),
				]
			);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'Error de importación XML/PDF: ' . $e->getMessage(), 'error');
		}
	}

	public function colaIniciar()
	{
		if (!$this->isPost()) {
			$this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
		}

		try {
			$service = $this->getQueueService();
			$result = $service->iniciarImportacion([
				'archivo_origen' => $this->post('archivo_origen', 'multiple_xml_files'),
				'total_esperado' => (int) $this->post('total_esperado', 0),
			]);

			$this->json([
				'ok' => true,
				'importacion_id' => $result['importacion_id'],
				'estado' => $service->getEstado($result['importacion_id']),
			]);
		} catch (Throwable $e) {
			$this->json([
				'ok' => false,
				'message' => 'No fue posible iniciar la cola: ' . $e->getMessage(),
			], 500);
		}
	}

	public function colaAgregar()
	{
		if (!$this->isPost()) {
			$this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
		}

		try {
			$importacionId = (int) $this->post('importacion_id', 0);
			if ($importacionId <= 0) {
				throw new Exception('Importacion invalida.');
			}

			$result = $this->getQueueService()->agregarArchivos($importacionId, 'xml_files');

			$this->json([
				'ok' => true,
				'importacion_id' => $importacionId,
				'uploaded_count' => $result['uploaded_count'],
				'estado' => $result['estado'],
			]);
		} catch (Throwable $e) {
			$this->json([
				'ok' => false,
				'message' => 'No fue posible agregar archivos a la cola: ' . $e->getMessage(),
			], 500);
		}
	}

	public function colaProcesar()
	{
		if (!$this->isPost()) {
			$this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
		}

		try {
			if (function_exists('ignore_user_abort')) {
				@ignore_user_abort(true);
			}
			if (function_exists('set_time_limit')) {
				@set_time_limit(600);
			}
			@ini_set('max_execution_time', '600');

			$importacionId = (int) $this->post('importacion_id', 0);
			$limit = max(1, min(25, (int) $this->post('limit', 5)));

			if ($importacionId <= 0) {
				throw new Exception('Importacion invalida.');
			}

			$result = $this->getQueueService()->procesarBatch($importacionId, $limit);

			$this->json([
				'ok' => true,
				'importacion_id' => $importacionId,
				'processed_in_batch' => $result['processed_in_batch'],
				'completed' => $result['completed'],
				'estado' => $result['estado'],
			]);
		} catch (Throwable $e) {
			$this->json([
				'ok' => false,
				'message' => 'No fue posible procesar la cola: ' . $e->getMessage(),
			], 500);
		}
	}

	public function colaEstado($id)
	{
		if (!$this->isGet()) {
			$this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
		}

		try {
			$estado = $this->getQueueService()->getEstado((int) $id);
			$this->json([
				'ok' => true,
				'importacion_id' => (int) $id,
				'estado' => $estado,
			]);
		} catch (Throwable $e) {
			$this->json([
				'ok' => false,
				'message' => 'No fue posible consultar el estado: ' . $e->getMessage(),
			], 500);
		}
	}

	public function ver($id)
	{
		try {
			$facturaModel = $this->loadModel('Factura');
			$factura = $facturaModel->findById((int) $id);

			if (!$factura) {
				$this->redirectWithMessage($this->url('/facturas'), 'Factura no encontrada.', 'warning');
			}

			$this->render('facturas/detalle', [
				'title' => 'Detalle de Factura - XMLConcilia',
				'factura' => $factura
			]);
		} catch (Exception $e) {
			$this->redirectWithMessage($this->url('/facturas'), 'Error al cargar detalle: ' . $e->getMessage(), 'error');
		}
	}

	public function eliminar($id)
	{
		$this->respondNotImplemented('Eliminación de factura');
	}

	private function getQueueService()
	{
		if ($this->queueService === null) {
			require_once __DIR__ . '/../helpers/InvoiceImportQueue.php';
			$this->queueService = new InvoiceImportQueue();
		}

		return $this->queueService;
	}

	private function respondNotImplemented($feature)
	{
		http_response_code(501);
		header('Content-Type: text/html; charset=utf-8');
		echo '<h1>501 - No implementado</h1>';
		echo '<p>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . ' estará disponible en la siguiente iteración.</p>';
		exit;
	}
}
