<?php
/**
 * Controlador de gestión de gastos
 */

class GastosController extends Controller
{
	public function index()
	{
		$gastos = [];

		try {
			$gastoModel = $this->loadModel('Gasto');
			$gastos = $gastoModel->getAllWithProveedor();
		} catch (Exception $e) {
			$this->redirectWithMessage($this->url('/gastos/importar'), 'No fue posible cargar gastos: ' . $e->getMessage(), 'warning');
		}

		$this->render('gastos/index', [
			'title' => 'Gastos - XMLConcilia',
			'gastos' => $gastos
		]);
	}

	public function importar()
	{
		$this->render('gastos/upload', [
			'title' => 'Importar Gastos - XMLConcilia'
		]);
	}

	public function subir()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/gastos/importar'));
		}

		require_once __DIR__ . '/../helpers/FileUploader.php';
		require_once __DIR__ . '/../helpers/Validator.php';

		$config = require __DIR__ . '/../config/config.php';
		$uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'gastos';
		$maxSize = $config['max_upload_size'] ?? 10485760;

		try {
			$file = FileUploader::uploadSingle('gastos_file', $uploadDir, ['csv'], $maxSize);

			$importacionModel = $this->loadModel('Importacion');
			$gastoModel = $this->loadModel('Gasto');

			$importacionId = $importacionModel->crear([
				'tipo' => 'gastos',
				'archivo_origen' => $file['original_name'],
				'ruta_archivo' => $file['path']
			]);

			$result = $this->procesarCsvGastos($file['path'], $gastoModel);
			$importacionModel->cerrar($importacionId, $result['total'], $result['exitosos'], $result['fallidos'], $result['errores']);

			if ($result['exitosos'] === 0) {
				$this->redirectWithMessage($this->url('/gastos/importar'), 'No se importó ningún gasto. Verifica columnas y formato CSV.', 'error');
			}

			$this->redirectWithMessage(
				$this->url('/gastos'),
				"Importación de gastos completada. Exitosos: {$result['exitosos']}, Fallidos: {$result['fallidos']}",
				$result['fallidos'] > 0 ? 'warning' : 'success'
			);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/gastos/importar'), 'Error de importación de gastos: ' . $e->getMessage(), 'error');
		}
	}

	public function ver($id)
	{
		$this->respondNotImplemented('Detalle de gasto');
	}

	public function eliminar($id)
	{
		$this->respondNotImplemented('Eliminación de gasto');
	}

	private function respondNotImplemented($feature)
	{
		http_response_code(501);
		header('Content-Type: text/html; charset=utf-8');
		echo '<h1>501 - No implementado</h1>';
		echo '<p>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . ' estará disponible en la siguiente iteración.</p>';
		exit;
	}

	private function procesarCsvGastos($filePath, $gastoModel)
	{
		$handle = fopen($filePath, 'r');
		if ($handle === false) {
			throw new Exception('No fue posible abrir el archivo CSV de gastos.');
		}

		$header = fgetcsv($handle);
		if (!$header) {
			fclose($handle);
			throw new Exception('El archivo CSV no contiene encabezados.');
		}

		$map = $this->buildCsvMap($header);
		$total = 0;
		$exitosos = 0;
		$fallidos = 0;
		$errores = [];

		while (($row = fgetcsv($handle)) !== false) {
			$total++;
			try {
				$numero = trim((string) $this->getCsvValue($row, $map, ['numero_factura', 'factura', 'numero']));
				$proveedor = trim((string) $this->getCsvValue($row, $map, ['proveedor', 'proveedor_texto', 'razon_social']));
				$fecha = Validator::parseDate($this->getCsvValue($row, $map, ['fecha_gasto', 'fecha']));
				$base = Validator::parseAmount($this->getCsvValue($row, $map, ['monto_base', 'base', 'subtotal']));
				$iva = Validator::parseAmount($this->getCsvValue($row, $map, ['iva']));
				$totalMonto = Validator::parseAmount($this->getCsvValue($row, $map, ['total', 'monto_total']));

				if (!Validator::required($numero)) {
					throw new Exception('Número de factura vacío.');
				}

				if (!Validator::required($proveedor)) {
					$proveedor = 'SIN PROVEEDOR';
				}

				if ($totalMonto <= 0) {
					$totalMonto = $base + $iva;
				}

				$gastoModel->upsertConsolidado([
					'numero_factura' => $numero,
					'proveedor_texto' => $proveedor,
					'cantidad_items' => 1,
					'fecha_min' => $fecha,
					'fecha_max' => $fecha,
					'suma_base' => $base,
					'suma_iva' => $iva,
					'suma_total' => $totalMonto
				]);

				$exitosos++;
			} catch (Throwable $e) {
				$fallidos++;
				$errores[] = [
					'fila' => $total + 1,
					'error' => $e->getMessage()
				];
			}
		}

		fclose($handle);

		return [
			'total' => $total,
			'exitosos' => $exitosos,
			'fallidos' => $fallidos,
			'errores' => $errores
		];
	}

	private function buildCsvMap(array $header)
	{
		$map = [];
		foreach ($header as $index => $name) {
			$key = strtolower(trim((string) $name));
			$key = str_replace([' ', '-'], '_', $key);
			$map[$key] = $index;
		}

		return $map;
	}

	private function getCsvValue(array $row, array $map, array $keys)
	{
		foreach ($keys as $key) {
			if (isset($map[$key])) {
				return $row[$map[$key]] ?? null;
			}
		}

		return null;
	}
}
