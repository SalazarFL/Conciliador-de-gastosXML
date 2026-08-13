<?php
/**
 * Controlador de gestión de facturas
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';

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
		$filtros           = $this->filtrosListado();

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

			$historial = $importacionModel->getAllXmlPorDocumento('FE');
			$semanas = $this->loadModel('Semana')->getAll();

			$importacionId = max(0, (int) ($_GET['importacion_id'] ?? 0));
			$consulta = $filtros;

			if ($importacionId > 0) {
				$consulta['importacion_id'] = $importacionId;
				$importacionActiva = $importacionModel->findById($importacionId);
			} elseif (($filtros['alcance'] ?? '') !== 'todas') {
				$consulta['semana_id'] = $semanaFiltro;
			}

			$facturas = $facturaModel->buscarConImportacion($consulta);
			$respaldo = (string) ($filtros['respaldo'] ?? '');
			$facturas = array_values(array_filter(array_map(static function ($factura) {
				$factura['_xml_disponible'] = !empty($factura['ruta_xml'])
					&& is_file((string) $factura['ruta_xml']);
				$factura['_pdf_disponible'] = !empty($factura['ruta_pdf'])
					&& is_file((string) $factura['ruta_pdf']);
				$factura['_par_completo'] = $factura['_xml_disponible'] && $factura['_pdf_disponible'];
				return $factura;
			}, $facturas), static function ($factura) use ($respaldo) {
				if ($respaldo === 'con_par') {
					return !empty($factura['_par_completo']);
				}
				if ($respaldo === 'sin_par') {
					return empty($factura['_par_completo']);
				}
				return true;
			}));
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
			'filtros'           => $filtros,
		]);
	}

	/** Normaliza los buscadores de la tabla de Facturas XML. */
	private function filtrosListado()
	{
		$q = mb_substr(trim((string) $this->get('q', '')), 0, 150, 'UTF-8');
		$proveedor = mb_substr(trim((string) $this->get('proveedor', '')), 0, 120, 'UTF-8');
		$desde = $this->fechaFiltro((string) $this->get('fecha_desde', ''));
		$hasta = $this->fechaFiltro((string) $this->get('fecha_hasta', ''));
		if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
			$tmp = $desde;
			$desde = $hasta;
			$hasta = $tmp;
		}

		$montoDesde = trim((string) $this->get('monto_desde', ''));
		$montoHasta = trim((string) $this->get('monto_hasta', ''));
		$montoDesde = is_numeric($montoDesde) && (float) $montoDesde >= 0 ? $montoDesde : '';
		$montoHasta = is_numeric($montoHasta) && (float) $montoHasta >= 0 ? $montoHasta : '';
		if ($montoDesde !== '' && $montoHasta !== '' && (float) $montoDesde > (float) $montoHasta) {
			$tmp = $montoDesde;
			$montoDesde = $montoHasta;
			$montoHasta = $tmp;
		}

		$respaldo = strtolower(trim((string) $this->get('respaldo', '')));
		if (!in_array($respaldo, ['', 'con_par', 'sin_par'], true)) {
			$respaldo = '';
		}
		$alcance = strtolower(trim((string) $this->get('alcance', ''))) === 'todas' ? 'todas' : '';

		return [
			'q' => $q,
			'proveedor' => $proveedor,
			'fecha_desde' => $desde,
			'fecha_hasta' => $hasta,
			'monto_desde' => $montoDesde,
			'monto_hasta' => $montoHasta,
			'respaldo' => $respaldo,
			'alcance' => $alcance,
		];
	}

	private function fechaFiltro($valor)
	{
		$valor = trim((string) $valor);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
			return '';
		}
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valor : '';
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
			$factura = $facturaModel->findById($facturaId);
			if ($facturaId <= 0 || empty($factura)) {
				$this->json(['ok' => false, 'message' => 'La factura no existe.'], 404);
			}

			$semanaId = $this->resolverSemana();
			$semanaAnterior = (int) ($factura['semana_id'] ?? 0);
			$facturaModel->asignarSemana($facturaId, $semanaId);

			// La verificación del listado por pagar corre sola: al entrar a
			// la semana nueva y al salir de la anterior (su línea pierde el
			// respaldo). Reemplaza al botón "Verificar de nuevo".
			$this->verificarSemanasPorPagar([$semanaAnterior, (int) $semanaId]);

			// Si la semana ya tiene listado del pago y la factura no coincide
			// con ninguna de sus líneas, se avisa: el toast aparece tras el
			// reload gracias al flash de sesión.
			$advertencia = null;
			if (!empty($semanaId)) {
				$advertencia = $this->advertenciaSinCoincidencia($facturaId, (int) $semanaId);
				if ($advertencia !== null) {
					if (session_status() === PHP_SESSION_NONE) {
						session_start();
					}
					$_SESSION['flash_message'] = [
						'message' => $advertencia,
						'type' => 'warning',
						'details' => [],
					];
					session_write_close();
				}
			}

			$this->json([
				'ok' => true,
				'semana_id' => !empty($semanaId) ? (int) $semanaId : '',
				'warning' => $advertencia,
			]);
		} catch (Throwable $e) {
			$this->json(['ok' => false, 'message' => 'No se pudo asignar la semana: ' . $e->getMessage()], 500);
		}
	}

	/**
	 * ¿La factura respalda alguna línea del listado del pago de esa semana?
	 * Devuelve el texto de advertencia si no coincide con ninguna, o null si
	 * coincide, no hay listado o la factura es NC/ND (esas no respaldan pagos).
	 */
	private function advertenciaSinCoincidencia($facturaId, $semanaId)
	{
		try {
			$porPagar = $this->loadModel('PorPagar');

			$listados = $porPagar->getListados(1, $semanaId);
			if (empty($listados)) {
				return null; // sin listado aún: nada contra qué comparar
			}

			$factura = $porPagar->getFacturaParaMatching((int) $facturaId);
			if ($factura === null) {
				return null;
			}

			foreach ($porPagar->getLineas((int) $listados[0]['id']) as $linea) {
				if (FacturaMatcher::facturaRespaldaLinea($linea, $factura)) {
					return null;
				}
			}

			return 'La factura quedó asignada a la semana, pero no coincide con ninguna línea del listado "'
				. $listados[0]['nombre'] . '". Revísala en Pagos semanales con el botón "Sin coincidencia".';
		} catch (Throwable $e) {
			return null; // el aviso nunca debe romper la asignación
		}
	}

	/**
	 * Re-verifica los listados por pagar de las semanas indicadas. Corre
	 * sola cada vez que facturas entran o salen de una semana (asignación
	 * manual, subida directa o cola de importación) y reemplaza al botón
	 * "Verificar de nuevo". Nunca lanza: la verificación automática no
	 * debe romper la operación que la disparó.
	 */
	private function verificarSemanasPorPagar(array $semanaIds)
	{
		try {
			require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
			$modelo = $this->loadModel('PorPagar');
			foreach (array_unique(array_map('intval', $semanaIds)) as $sid) {
				if ($sid > 0) {
					PorPagarVerificador::verificarSemana($sid, $modelo);
				}
			}
		} catch (Throwable $e) {
			// Best effort: la próxima asignación o importación la repite
		}
	}

	/**
	 * Revalida los listados por período después de importar NC XML, tanto por
	 * carga directa como por la cola usada desde Correo.
	 */
	private function verificarListadosNotasCredito()
	{
		try {
			$sociedad = $this->loadModel('Sociedad')->getActiva();
			if (!$sociedad) {
				return;
			}
			require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
			$modelo = $this->loadModel('NotaCredito');
			NotasCreditoVerificador::verificarTodosSociedad((int) $sociedad['id'], $modelo);
		} catch (Throwable $e) {
			// Best effort: el botón "Verificar nuevamente" permite reintentarlo.
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
		require_once __DIR__ . '/../helpers/XmlDocumentImporter.php';

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
			$documentImporter = new XmlDocumentImporter();
			$sociedad = $this->loadModel('Sociedad')->getActiva();

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
			$semanasAfectadas = !empty($semanaId) ? [(int) $semanaId] : [];
			$errores = [];
			$archivosError = [];
			$archivosDup = [];

			foreach ($uploadedFiles as $file) {
				try {
					$resultado = $documentImporter->importar($file['path'], null, [
						'origen' => 'facturas_xml',
						'importacion_id' => $importacionId,
						'semana_id' => $semanaId,
						'tipos_permitidos' => ['FE'],
						'validar_receptor' => !empty($sociedad),
						'cedula_receptor' => $sociedad['cedula'] ?? '',
						'sociedad_id' => (int) ($sociedad['id'] ?? 0),
					]);
					if (!empty($resultado['semana_anterior'])) {
						$semanasAfectadas[] = (int) $resultado['semana_anterior'];
					}

					if (in_array(($resultado['estado'] ?? ''), ['duplicado', 'duplicado_semana', 'pdf_completado'], true)) {
						$duplicados++;
						$archivosDup[] = $file['original_name'];
					} else {
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

			// Facturas nuevas en la semana → re-verificar su listado por pagar
			if ($exitosos > 0 && !empty($semanasAfectadas)) {
				$this->verificarSemanasPorPagar($semanasAfectadas);
			}

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
			$this->redirectWithMessage($this->url('/carga'), 'Error de importación XML: ' . $e->getMessage(), 'error');
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
			$sociedad = $this->loadModel('Sociedad')->getActiva();
			$result = $service->iniciarImportacion([
				'archivo_origen' => $nombre,
				'total_esperado' => (int) $this->post('total_esperado', 0),
				'semana_id' => $semanaId,
				'tipo_documento' => 'FE',
				'cedula_receptor' => $sociedad['cedula'] ?? '',
				'sociedad_id' => (int) ($sociedad['id'] ?? 0),
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

			// Cola terminada: re-verificar los listados de la semana para
			// que las facturas recién importadas queden cruzadas solas
			// (sin el botón "Verificar de nuevo")
			if (!empty($result['completed'])) {
				$semanasCola = $this->getQueueService()->semanasAfectadasImportacion($importacionId);
				if (!empty($semanasCola)) {
					$this->verificarSemanasPorPagar($semanasCola);
				}

				// Una cola de facturas FE no cambia ninguna nota de crédito. Evita
				// revalidar todos los listados de NC (miles de líneas) al terminar
				// cada importación de facturas. Las colas NC conservan la revisión.
				$tipoDocumento = strtoupper((string) (
					$result['estado']['metadata']['tipo_documento'] ?? 'FE'
				));
				if ($tipoDocumento === 'NC') {
					$this->verificarListadosNotasCredito();
				}
			}

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
			$factura = $facturaModel->getOneWithProvider((int) $id);

			if (!$factura || !in_array(strtoupper((string) ($factura['tipo_documento'] ?? 'FE')), ['FE', ''], true)) {
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
