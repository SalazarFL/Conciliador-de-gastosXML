<?php
/**
 * Controlador de gestión de facturas
 */

class FacturasController extends Controller
{
	private $queueService;

	public function __construct() { $this->requireAuth(); }

	public function index()
	{
		$facturas          = [];
		$historial         = [];
		$importacionActiva = null;
		$semanas           = [];

		// El selector "Semana de trabajo" define lo que se muestra abajo:
		// semana_id=N = las facturas de esa semana; 0 = "Sin semana".
		// Sin parámetro se usa la última semana elegida (compartida entre
		// módulos vía sesión); al llegar en la URL se recuerda para los demás.
		$semanaFiltro = $this->semanaActiva();
		if (isset($_GET['semana_id']) && $_GET['semana_id'] !== '') {
			$semanaFiltro = max(0, (int) $_GET['semana_id']);
			$this->setSemanaActiva($semanaFiltro);
		}

		try {
			$facturaModel     = $this->loadModel('Factura');
			$importacionModel = $this->loadModel('Importacion');

			$historial = $importacionModel->getAllByTipo('xml');
			$semanas = $this->loadModel('Semana')->getAll();

			if (empty($_GET['limpiar'])) {
				$importacionId = max(0, (int) ($_GET['importacion_id'] ?? 0));

				if ($importacionId > 0) {
					$facturas          = $facturaModel->getByImportacion($importacionId);
					$importacionActiva = $importacionModel->findById($importacionId);
				} else {
					$facturas = $facturaModel->getAllWithImportacion($semanaFiltro);
				}
			}
		} catch (Exception $e) {
			$this->redirectWithMessage($this->url('/facturas'), 'No fue posible cargar facturas: ' . $e->getMessage(), 'warning');
		}

		$this->render('facturas/index', [
			'title'             => 'Facturas - XMLConcilia',
			'facturas'          => $facturas,
			'historial'         => $historial,
			'importacionActiva' => $importacionActiva,
			'semanas'           => $semanas,
			'semanaFiltro'      => $semanaFiltro,
		]);
	}

	/**
	 * Semana elegida en un formulario (semana_id + semana_nueva) → id o null.
	 */
	private function resolverSemana()
	{
		try {
			return $this->loadModel('Semana')->resolverSeleccion(
				(string) $this->post('semana_id', ''),
				(string) $this->post('semana_nueva', '')
			);
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Asignar o cambiar la semana de una factura desde el botón de la
	 * columna Semana (AJAX). semana_id: '' quita la semana, N la asigna,
	 * 'nueva' crea una con el nombre de semana_nueva.
	 */
	public function semanaAsignar()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/facturas'));
		}

		$facturaId = (int) $this->post('factura_id', 0);

		try {
			$facturaModel = $this->loadModel('Factura');
			if ($facturaId <= 0 || empty($facturaModel->findById($facturaId))) {
				$this->json(['ok' => false, 'message' => 'La factura no existe.'], 404);
			}

			$semanaId = $this->resolverSemana();
			$facturaModel->asignarSemana($facturaId, $semanaId);

			$this->json(['ok' => true, 'semana_id' => !empty($semanaId) ? (int) $semanaId : '']);
		} catch (Throwable $e) {
			$this->json(['ok' => false, 'message' => 'No se pudo asignar la semana: ' . $e->getMessage()], 500);
		}
	}

	/**
	 * Nombre automático del lote de importación (ya no se pide al usuario).
	 * Usa la semana si viene, si no la fecha/hora.
	 */
	private function nombreImportacionAuto($semanaId = null)
	{
		if (!empty($semanaId)) {
			try {
				$semana = $this->loadModel('Semana')->findById((int) $semanaId);
				if (!empty($semana['nombre'])) {
					return mb_substr('Carga ' . $semana['nombre'], 0, 120, 'UTF-8');
				}
			} catch (Throwable $e) {
			}
		}
		return 'Carga ' . date('d/m/Y H:i');
	}

	public function subir()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/facturas'));
		}

		require_once __DIR__ . '/../helpers/FileUploader.php';
		if (!class_exists('XmlInvoiceParser', false)) {
			require_once __DIR__ . '/../helpers/XmlParser.php';
		}

		$config = require __DIR__ . '/../config/config.php';
		$uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'xml';
		$maxSize = $config['max_upload_size'] ?? 10485760;
		$allowed = $config['allowed_extensions']['xml'] ?? ['xml'];

		// El nombre del lote se genera solo (el sistema se organiza por semana).
		$semanaId = $this->resolverSemana();
		$nombreImportacion = $this->nombreImportacionAuto($semanaId);

		try {
			$uploadedFiles = FileUploader::uploadMultiple('xml_files', $uploadDir, $allowed, $maxSize);

			$importacionModel = $this->loadModel('Importacion');
			$facturaModel = $this->loadModel('Factura');
			$proveedorModel = $this->loadModel('Proveedor');

			$importacionId = $importacionModel->crear([
				'tipo' => 'xml',
				'archivo_origen' => $nombreImportacion,
				'ruta_archivo' => $uploadDir
			]);

			$recibidos = count($uploadedFiles);
			$limitePhp = (int) ini_get('max_file_uploads');

			$exitosos = 0;
			$duplicados = 0;
			$fallidos = 0;
			$errores = [];
			$archivosError = [];
			$archivosDup = [];

			foreach ($uploadedFiles as $file) {
				try {
					$docData = XmlInvoiceParser::parseCfdiFromFile($file['path']);

					$proveedorId = $proveedorModel->obtenerOCrear($docData['rfc_emisor'] ?? '', $docData['razon_social_emisor'] ?? '');

					$metadata = $docData['metadata'] ?? [];
					if (!is_array($metadata)) {
						$metadata = [];
					}

					$hashDocumento = (string) ($docData['hash_documento'] ?? ($docData['hash_xml'] ?? null));

					// Verificar duplicado por hash o consecutivo antes de insertar
					$esDuplicadoPrevio = false;
					if ($hashDocumento !== '') {
						$esDuplicadoPrevio = $facturaModel->existsByHash($hashDocumento);
					}
					if (!$esDuplicadoPrevio && !empty($docData['consecutivo_completo'])) {
						$esDuplicadoPrevio = $facturaModel->existsByConsecutivo(
							$docData['consecutivo_completo'],
							$proveedorId,
							$docData['fecha_emision'] ?? ''
						);
					}

					if ($esDuplicadoPrevio) {
						$duplicados++;
						$archivosDup[] = $file['original_name'];
					} else {
						$facturaModel->crear([
							'importacion_id' => $importacionId,
							'semana_id' => $semanaId,
							'consecutivo_completo' => $docData['consecutivo_completo'],
							'clave' => $docData['clave'] ?? null,
							'tipo_documento' => $docData['tipo_documento'] ?? null,
							'receptor_id' => $docData['receptor_id'] ?? null,
							'numero_factura_asistente' => $docData['numero_factura_asistente'],
							'proveedor_id' => $proveedorId,
							'fecha_emision' => $docData['fecha_emision'],
							'subtotal' => $docData['subtotal'],
							'iva' => $docData['iva'],
							'total' => $docData['total'],
							'moneda' => $docData['moneda'],
							'tipo_comprobante' => $docData['tipo_comprobante'] ?? null,
							'archivo_xml' => $file['original_name'],
							'ruta_xml' => $file['path'],
							'hash_xml' => $hashDocumento !== '' ? $hashDocumento : null,
							'xml_contenido' => $docData['xml_contenido'] ?? null,
							'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
						]);
						$exitosos++;
					}
				} catch (Throwable $e) {
					$msg = $e->getMessage();
					$esDuplicado = stripos($msg, 'Duplicate entry') !== false
						|| stripos($msg, 'uk_consecutivo') !== false
						|| stripos($msg, 'uk_hash') !== false;

					if ($esDuplicado) {
						$duplicados++;
						$archivosDup[] = $file['original_name'];
					} else {
						$fallidos++;
						$archivosError[] = $file['original_name'];
					}

					$errores[] = [
						'archivo' => $file['original_name'],
						'error' => $msg
					];
				}

				if (is_file($file['path'])) {
					@unlink($file['path']);
				}
			}

			$importacionModel->cerrar($importacionId, $recibidos, $exitosos, $fallidos + $duplicados, $errores);

			$avisoLimite = '';
			if ($limitePhp > 0 && $recibidos >= $limitePhp) {
				$avisoLimite = "El servidor recibió solo {$recibidos} archivos (límite PHP max_file_uploads={$limitePhp}). Si enviaste más, divídelos en lotes de {$limitePhp}.";
			}

			$partes = ["Recibidos: {$recibidos}", "Importados: {$exitosos}"];
			if ($duplicados > 0) {
				$partes[] = "Ya existían: {$duplicados}";
			}
			if ($fallidos > 0) {
				$partes[] = "Errores: {$fallidos}";
			}

			$hayProblemas = $fallidos > 0 || $avisoLimite !== '';
			$todosFallaron = $exitosos === 0 && $duplicados === 0;
			$tipo = $todosFallaron ? 'error' : ($hayProblemas ? 'warning' : 'success');

			// Volver a la vista de la semana elegida (ahí quedan las subidas)
			$this->redirectWithMessage(
				$this->url('/facturas' . (!empty($semanaId) ? '?semana_id=' . (int) $semanaId : '')),
				implode(' | ', $partes),
				$tipo,
				[
					'server_limit_warning' => $avisoLimite,
					'duplicate_files'      => array_values(array_unique($archivosDup)),
					'failed_files'         => array_values(array_unique($archivosError)),
				]
			);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/facturas'), 'Error de importación XML: ' . $e->getMessage(), 'error');
		}
	}

	public function colaIniciar()
	{
		if (!$this->isPost()) {
			$this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
		}

		try {
			// El nombre del lote se genera solo (el sistema se organiza por semana).
			$semanaId = $this->resolverSemana();
			$nombre = $this->nombreImportacionAuto($semanaId);

			$service = $this->getQueueService();
			$result = $service->iniciarImportacion([
				'archivo_origen' => $nombre,
				'total_esperado' => (int) $this->post('total_esperado', 0),
				'semana_id' => $semanaId,
			]);

			$this->json([
				'ok' => true,
				'importacion_id' => $result['importacion_id'],
				'semana_id' => $semanaId,
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

	private function getQueueService()
	{
		if ($this->queueService === null) {
			require_once __DIR__ . '/../helpers/InvoiceImportQueue.php';
			$this->queueService = new InvoiceImportQueue();
		}

		return $this->queueService;
	}

}
