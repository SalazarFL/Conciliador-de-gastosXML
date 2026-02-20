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
		$this->render('facturas/upload', [
			'title' => 'Importar Facturas - XMLConcilia'
		]);
	}

	public function subir()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/facturas/importar'));
		}

		require_once __DIR__ . '/../helpers/FileUploader.php';
		if (!class_exists('XmlInvoiceParser', false)) {
			require_once __DIR__ . '/../helpers/XmlParser.php';
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

			foreach ($uploadedFiles as $file) {
				try {
					$xmlData = XmlInvoiceParser::parseCfdiFromFile($file['path']);
					$proveedorId = $proveedorModel->obtenerOCrear($xmlData['rfc_emisor'], $xmlData['razon_social_emisor']);

					$facturaModel->crear([
						'importacion_id' => $importacionId,
						'consecutivo_completo' => $xmlData['consecutivo_completo'],
						'numero_factura_asistente' => $xmlData['numero_factura_asistente'],
						'proveedor_id' => $proveedorId,
						'fecha_emision' => $xmlData['fecha_emision'],
						'subtotal' => $xmlData['subtotal'],
						'iva' => $xmlData['iva'],
						'total' => $xmlData['total'],
						'moneda' => $xmlData['moneda'],
						'tipo_comprobante' => $xmlData['tipo_comprobante'],
						'archivo_xml' => $file['original_name'],
						'ruta_xml' => $file['path'],
						'hash_xml' => $xmlData['hash_xml'],
						'xml_contenido' => $xmlData['xml_contenido']
					]);

					$exitosos++;
				} catch (Throwable $e) {
					$fallidos++;
					$errores[] = [
						'archivo' => $file['original_name'],
						'error' => $e->getMessage()
					];
				}
			}

			$importacionModel->cerrar($importacionId, count($uploadedFiles), $exitosos, $fallidos, $errores);

			if ($exitosos === 0) {
				$this->redirectWithMessage($this->url('/facturas/importar'), 'No se importó ninguna factura. Revisa formato XML y duplicados.', 'error');
			}

			$this->redirectWithMessage(
				$this->url('/facturas'),
				"Importación completada. Exitosos: {$exitosos}, Fallidos: {$fallidos}",
				$fallidos > 0 ? 'warning' : 'success'
			);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/facturas/importar'), 'Error de importación XML: ' . $e->getMessage(), 'error');
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
