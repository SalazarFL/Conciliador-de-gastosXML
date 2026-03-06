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
		require_once __DIR__ . '/../helpers/XlsxReader.php';

		$config = require __DIR__ . '/../config/config.php';
		$uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'gastos';
		$maxSize = $config['max_upload_size'] ?? 10485760;

		try {
			$allowed = $config['allowed_extensions']['gastos'] ?? ['csv'];
			$file = FileUploader::uploadSingle('gastos_file', $uploadDir, $allowed, $maxSize);

			$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
			if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
				throw new Exception('Formato no soportado. Usa CSV o XLSX.');
			}

			if ($ext === 'xls') {
				throw new Exception('El formato .xls no está soportado en esta versión. Guarda el archivo como .xlsx o .csv e intenta de nuevo.');
			}

			$importacionModel = $this->loadModel('Importacion');
			$gastoModel = $this->loadModel('Gasto');

			$importacionId = $importacionModel->crear([
				'tipo' => 'gastos',
				'archivo_origen' => $file['original_name'],
				'ruta_archivo' => $file['path']
			]);

			$result = $this->procesarGastos($file['path'], $ext, $gastoModel);
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

	private function procesarGastos($filePath, $ext, $gastoModel)
	{
		if ($ext === 'csv') {
			$dataset = $this->readCsvData($filePath);
		} else {
			$dataset = XlsxReader::readFirstSheet($filePath);
		}

		$map = $this->buildHeaderMap($dataset['header']);
		$this->validateRequiredColumns($map);
		$total = 0;
		$exitosos = 0;
		$fallidos = 0;
		$errores = [];

		foreach ($dataset['rows'] as $row) {
			$total++;
			try {
				$fecha = Validator::parseDate($this->getValue($row, $map, ['fecha']));
				$numero = $this->normalizeNumeroFactura($this->getValue($row, $map, ['numero']));
				$proveedor = trim((string) $this->getValue($row, $map, ['proveedor']));
				$iva = Validator::parseAmount($this->getValue($row, $map, ['iva']));
				$totalMonto = Validator::parseAmount($this->getValue($row, $map, ['total']));

				if (!Validator::required($numero)) {
					throw new Exception('Número vacío.');
				}

				if (!Validator::required($proveedor)) {
					throw new Exception('Proveedor vacío.');
				}

				if (!$fecha) {
					throw new Exception('Fecha inválida.');
				}

				if ($totalMonto <= 0) {
					throw new Exception('Total inválido o vacío.');
				}

				$base = max(0, $totalMonto - $iva);

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

		return [
			'total' => $total,
			'exitosos' => $exitosos,
			'fallidos' => $fallidos,
			'errores' => $errores
		];
	}

	private function readCsvData($filePath)
	{
		$handle = fopen($filePath, 'r');
		if ($handle === false) {
			throw new Exception('No fue posible abrir el archivo CSV de gastos.');
		}

		$delimiter = $this->detectCsvDelimiter($filePath);
		$header = fgetcsv($handle, 0, $delimiter);
		if (!$header) {
			fclose($handle);
			throw new Exception('El archivo CSV no contiene encabezados.');
		}

		$rows = [];
		while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
			$rows[] = $row;
		}

		fclose($handle);

		return [
			'header' => $header,
			'rows' => $rows
		];
	}

	private function buildHeaderMap(array $header)
	{
		$map = [];
		foreach ($header as $index => $name) {
			$key = $this->normalizeHeaderKey((string) $name);
			$map[$key] = $index;
		}

		return $map;
	}

	private function getValue(array $row, array $map, array $keys)
	{
		foreach ($keys as $key) {
			$normalized = $this->normalizeHeaderKey($key);
			if (isset($map[$normalized])) {
				return $row[$map[$normalized]] ?? null;
			}
		}

		return null;
	}

	private function validateRequiredColumns(array $map)
	{
		$required = ['fecha', 'numero', 'proveedor', 'iva', 'total'];
		$missing = [];

		foreach ($required as $column) {
			if (!isset($map[$column])) {
				$missing[] = $column;
			}
		}

		if (!empty($missing)) {
			throw new Exception('El archivo debe incluir las columnas: Fecha, Numero, Proveedor, Iva, Total. Faltan: ' . implode(', ', $missing));
		}
	}

	private function normalizeHeaderKey($value)
	{
		$key = trim((string) $value);
		$key = preg_replace('/^\xEF\xBB\xBF/', '', $key); // remover BOM UTF-8
		$key = mb_strtolower($key, 'UTF-8');
		$key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;
		$key = str_replace([' ', '-', '.'], '_', $key);
		$key = preg_replace('/[^a-z0-9_]/', '', $key);
		$key = preg_replace('/_+/', '_', $key);

		return trim($key, '_');
	}

	private function detectCsvDelimiter($filePath)
	{
		$line = '';
		$fh = fopen($filePath, 'r');
		if ($fh !== false) {
			$line = (string) fgets($fh);
			fclose($fh);
		}

		$commaCount = substr_count($line, ',');
		$semicolonCount = substr_count($line, ';');

		return $semicolonCount > $commaCount ? ';' : ',';
	}

	private function normalizeNumeroFactura($value)
	{
		$text = trim((string) $value);
		if ($text === '') {
			return '';
		}

		// Si viene como numérico de Excel (ej: 4510215.0), quitar decimal redundante.
		if (preg_match('/^\d+\.0+$/', $text)) {
			$text = preg_replace('/\.0+$/', '', $text);
		}

		return $text;
	}
}
