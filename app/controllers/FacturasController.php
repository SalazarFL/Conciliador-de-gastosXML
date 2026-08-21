<?php
/**
 * Controlador de gestión de facturas
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/../helpers/Retorno.php';

class FacturasController extends Controller
{
	private $queueService;

	public function __construct() { $this->requireAuth(); }

	public function index()
	{
		// Los buscadores de la tabla se recuerdan mientras dure la sesión: al
		// volver de otro módulo la lista sale como se dejó.
		$this->recordarFiltros('facturas', [
			'q', 'proveedor', 'fecha_desde', 'fecha_hasta',
			'monto_desde', 'monto_hasta', 'respaldo', 'alcance',
		]);


		/*
		 * Tarjeta del documento que se está buscando: se llega acá con el botón
		 * "Buscar en los XML cargados" del pago semanal o de la cola de
		 * seguimiento, y se recorre esa lista sin volver al otro módulo.
		 */
		$navDoc = null;
		try {
			$navDoc = NavegacionDocumentos::desde(
				$_GET,
				function ($nombre) { return $this->loadModel($nombre); },
				$this->url('')
			);
		} catch (Throwable $e) {
			$this->registrarFallo('Tarjeta del documento en /facturas', $e);
		}

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
			// Se mira el disco, no solo la columna: la ruta puede estar guardada
			// y el archivo haber desaparecido de la carpeta compartida.
			$facturas = array_values(array_filter(
				EstadoArchivo::decorar($facturas),
				static function ($factura) use ($respaldo) {
					$par = !empty($factura['archivo_xml_ok']) && !empty($factura['archivo_pdf_ok']);
					if ($respaldo === 'con_par') {
						return $par;
					}
					if ($respaldo === 'sin_par') {
						return !$par;
					}
					if ($respaldo === 'perdido') {
						return !empty($factura['archivo_perdido']);
					}
					return true;
				}
			));
		} catch (Exception $e) {
			$this->redirectWithMessage($this->url('/facturas'), 'No fue posible cargar facturas: ' . $e->getMessage(), 'warning');
		}

		$proveedoresFiltro = [];
		try {
			$proveedoresFiltro = ProveedorCatalogo::opciones(
				$this->loadModel('Factura')->proveedoresParaFiltro('FE')
			);
		} catch (Throwable $e) {
			// Sin catálogo el filtro queda vacío; la lista se sigue viendo.
		}

		$this->render('facturas/index', [
			'title'             => 'Facturas - Nexo Fiscal',
			'facturas'          => $facturas,
			'proveedoresFiltro' => $proveedoresFiltro,
			'historial'         => $historial,
			'importacionActiva' => $importacionActiva,
			'semanas'           => $semanas,
			'semanaFiltro'      => $semanaFiltro,
			'filtros'           => $filtros,
			'navDoc'            => $navDoc,
		]);
	}

	/** Normaliza los buscadores de la tabla de Facturas XML. */
	private function filtrosListado()
	{
		$q = mb_substr(trim((string) $this->get('q', '')), 0, 150, 'UTF-8');
		$proveedor = ProveedorCatalogo::normalizarClave($this->get('proveedor', ''));
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
		if (!in_array($respaldo, ['', 'con_par', 'sin_par', 'perdido'], true)) {
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
	 * Asignar la semana de una factura a mano salió del sistema junto con su
	 * botón. La semana de un comprobante ya no se elige: la hereda de la
	 * factura del ERP a la que respalda, y esa factura sabe en qué pago
	 * semanal está. Forzarla por fuera no la metía en ningún pago —el cruce va
	 * por consecutivo— y sí podía sacarle el par XML/PDF de la carpeta del
	 * pago, que se decide comparando ambas semanas.
	 */

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
			$erp = $this->loadModel('FacturaErp');
			$facturas = $this->loadModel('Factura');
			foreach (array_unique(array_map('intval', $semanaIds)) as $sid) {
				if ($sid > 0) {
					PorPagarVerificador::verificarSemana($sid, $erp, $facturas);
				}
			}
		} catch (Throwable $e) {
			// Best effort: la próxima asignación o importación la repite
		}
	}

	/**
	 * Re-verifica los pagos que todavía tienen facturas sin respaldo.
	 *
	 * Es la red para el XML que entra sin semana: no se sabe a qué pago
	 * pertenece, así que se revisan los que todavía esperan comprobantes.
	 * Nunca lanza, por lo mismo que verificarSemanasPorPagar.
	 */
	private function verificarPagosPendientes()
	{
		try {
			require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
			PorPagarVerificador::verificarPendientes(
				$this->loadModel('FacturaErp'),
				$this->loadModel('Factura')
			);
		} catch (Throwable $e) {
			// Best effort: la próxima importación lo repite.
		}
	}

	/**
	 * Revalida los listados por período después de importar NC XML, tanto por
	 * carga directa como por la cola usada desde Correo.
	 *
	 * Corre sola cada vez que entran notas y reemplaza al botón "Verificar
	 * nuevamente" del módulo, igual que verificarSemanasPorPagar reemplazó al
	 * de Por Pagar. Nunca lanza: la verificación automática no debe romper la
	 * importación que la disparó.
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
			// Best effort: la próxima entrada de notas lo repite.
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

			// Facturas nuevas en la semana → re-verificar su listado por pagar.
			// Sin semana elegida no hay listado al que apuntar, pero el
			// comprobante puede ser justo el que le falta a un pago: se revisan
			// los que tienen facturas sin respaldo, igual que hace el correo.
			// Si no se hiciera, el XML quedaría cruzado en Facturas y sin
			// cruzar en Por pagar, que es la diferencia que nadie entiende.
			if ($exitosos > 0) {
				if (!empty($semanasAfectadas)) {
					$this->verificarSemanasPorPagar($semanasAfectadas);
				} else {
					$this->verificarPagosPendientes();
				}
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
				'title' => 'Detalle de Factura - Nexo Fiscal',
				'factura' => $factura,
				// Al detalle se entra desde cuatro pantallas distintas. "Volver"
				// devuelve a la que se venía —con sus filtros— en vez de soltar
				// a la persona en un listado que no pidió.
				'retorno' => Retorno::anterior($_SERVER, $this->url(''), '/facturas'),
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
