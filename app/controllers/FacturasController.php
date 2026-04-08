<?php
/**
 * Controlador de gestión de facturas
 */

class FacturasController extends Controller
{
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

			$exitosos = 0;
			$fallidos = 0;
			$errores = [];
			$sinPlantilla = [];

			foreach ($uploadedFiles as $file) {
				$ext = strtolower(pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION));
				$isPdf = ($ext === 'pdf');

				try {
					if ($isPdf) {
						$docData = PdfInvoiceParser::parseInvoiceFromPdf($file['path'], [
							'max_ocr_pages' => 3,
							'ocr_language' => 'spa+eng',
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
					$fallidos++;
					if (stripos($e->getMessage(), 'sin plantilla de parseo') !== false) {
						$sinPlantilla[] = $file['original_name'];
					}
					$errores[] = [
						'archivo' => $file['original_name'],
						'error' => $e->getMessage()
					];
				} finally {
					// Los PDF se usan solo para extracción y luego se descartan.
					if ($isPdf && !empty($file['path']) && is_file($file['path'])) {
						@unlink($file['path']);
					}
				}
			}

			$importacionModel->cerrar($importacionId, count($uploadedFiles), $exitosos, $fallidos, $errores);

			if ($exitosos === 0) {
				if (!empty($sinPlantilla)) {
					$this->redirectWithMessage(
						$this->url('/conciliacion'),
						'No se importó ninguna factura porque los PDF requieren plantilla de parseo por proveedor. Pendientes: ' . implode(', ', $sinPlantilla),
						'warning'
					);
				}

				$this->redirectWithMessage($this->url('/conciliacion'), 'No se importó ninguna factura. Revisa formatos XML/PDF, OCR y duplicados.', 'error');
			}

			$msgPlantilla = '';
			if (!empty($sinPlantilla)) {
				$msgPlantilla = ' | Sin plantilla: ' . count($sinPlantilla) . ' (' . implode(', ', $sinPlantilla) . ')';
			}

			$this->redirectWithMessage(
				$this->url('/conciliacion'),
				"Importación de documentos completada. Exitosos: {$exitosos}, Fallidos: {$fallidos}{$msgPlantilla}",
				$fallidos > 0 ? 'warning' : 'success'
			);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'Error de importación XML/PDF: ' . $e->getMessage(), 'error');
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

	private function respondNotImplemented($feature)
	{
		http_response_code(501);
		header('Content-Type: text/html; charset=utf-8');
		echo '<h1>501 - No implementado</h1>';
		echo '<p>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . ' estará disponible en la siguiente iteración.</p>';
		exit;
	}
}
