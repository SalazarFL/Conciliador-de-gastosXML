<?php
/**
 * Controlador de generación de reportes y exportación XLSX
 */

require_once __DIR__ . '/../helpers/XlsxWriter.php';

class ReportesController extends Controller
{
	public function index()
	{
		try {
			$conciliacionModel = $this->loadModel('Conciliacion');
			$proveedorModel    = $this->loadModel('Proveedor');
			$estados    = $conciliacionModel->getEstadoMap();
			$proveedores = array_column($proveedorModel->getListado(), 'nombre');
		} catch (Throwable $e) {
			$estados = [];
			$proveedores = [];
		}

		$this->render('reportes/index', [
			'title'       => 'Reportes - XMLConcilia',
			'estados'     => $estados,
			'proveedores' => $proveedores,
		]);
	}

	/**
	 * AJAX: devuelve la vista previa filtrada como JSON.
	 */
	public function preview()
	{
		try {
			$filtros = $this->parseFiltros();
			$rows    = $this->obtenerDatos($filtros);
			$tipo    = $filtros['tipo'];
			$headers = $this->headersParaTipo($tipo);
			$mapped  = $this->mapRowsParaTipo($rows, $tipo);

			$this->json([
				'success'   => true,
				'total'     => count($mapped),
				'headers'   => $headers,
				'rows'      => array_slice($mapped, 0, 500),
				'truncated' => count($mapped) > 500,
			]);
		} catch (Throwable $e) {
			$this->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Descarga el XLSX con los mismos filtros de la vista previa.
	 */
	public function exportar()
	{
		try {
			$filtros = $this->parseFiltros();
			$rows    = $this->obtenerDatos($filtros);

			if (empty($rows)) {
				$this->redirectWithMessage($this->url('/reportes'), 'No hay datos para exportar con esos filtros.', 'warning');
				return;
			}

			$tipo    = $filtros['tipo'];
			$headers = $this->headersParaTipo($tipo);
			$mapped  = $this->mapRowsParaTipo($rows, $tipo);

			$sheetName = $tipo === 'facturas' ? 'Facturas' : ($tipo === 'gastos' ? 'Gastos' : 'Conciliacion');
			$fileName  = 'reporte_' . $sheetName . '_' . date('Ymd_His') . '.xlsx';

			$tmpFile = XlsxWriter::generate($headers, $mapped, $sheetName);
			XlsxWriter::send($tmpFile, $fileName);
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/reportes'), 'Error al exportar: ' . $e->getMessage(), 'error');
		}
	}

	public function estadisticas()
	{
		try {
			$conciliacionModel = $this->loadModel('Conciliacion');
			$this->json([
				'success' => true,
				'data'    => $conciliacionModel->getEstadisticas(),
			]);
		} catch (Throwable $e) {
			$this->json(['success' => false, 'message' => $e->getMessage()], 500);
		}
	}

	// Stubs redirigen al index
	public function resumen()      { $this->redirect($this->url('/reportes')); }
	public function porProveedor() { $this->redirect($this->url('/reportes')); }
	public function porEstado()    { $this->redirect($this->url('/reportes')); }
	public function diferencias()  { $this->redirect($this->url('/reportes')); }

	// ─── Lógica interna ───────────────────────────────────────────

	private function parseFiltros()
	{
		return [
			'tipo'        => $this->getParam('tipo', 'conciliacion'),
			'estado'      => $this->getParam('estado', ''),
			'proveedor'   => trim((string) $this->getParam('proveedor', '')),
			'fecha_desde' => $this->getParam('fecha_desde', ''),
			'fecha_hasta' => $this->getParam('fecha_hasta', ''),
			'match_tipo'  => $this->getParam('match_tipo', ''),
		];
	}

	private function getParam($key, $default = '')
	{
		if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
			return trim((string) $_GET[$key]);
		}
		if (isset($_POST[$key]) && trim((string) $_POST[$key]) !== '') {
			return trim((string) $_POST[$key]);
		}
		return $default;
	}

	private function obtenerDatos(array $f)
	{
		$tipo = $f['tipo'];

		if ($tipo === 'facturas') {
			return $this->obtenerFacturas($f);
		}
		if ($tipo === 'gastos') {
			return $this->obtenerGastos($f);
		}
		return $this->obtenerConciliacion($f);
	}

	private function obtenerFacturas(array $f)
	{
		$facturaModel = $this->loadModel('Factura');
		$rows = $facturaModel->getAllWithImportacion();

		return array_values(array_filter($rows, function ($r) use ($f) {
			if ($f['proveedor'] !== '') {
				$prov = mb_strtolower((string) ($r['proveedor_nombre'] ?? ''), 'UTF-8');
				if (mb_strpos($prov, mb_strtolower($f['proveedor'], 'UTF-8')) === false) {
					return false;
				}
			}
			if ($f['fecha_desde'] !== '' && ($r['fecha_emision'] ?? '') < $f['fecha_desde']) {
				return false;
			}
			if ($f['fecha_hasta'] !== '' && ($r['fecha_emision'] ?? '') > $f['fecha_hasta']) {
				return false;
			}
			return true;
		}));
	}

	private function obtenerGastos(array $f)
	{
		$gastoModel = $this->loadModel('Gasto');
		$rows = $gastoModel->getAllWithProveedor();

		return array_values(array_filter($rows, function ($r) use ($f) {
			if ($f['proveedor'] !== '') {
				$prov = mb_strtolower((string) ($r['proveedor_nombre'] ?? $r['proveedor_texto'] ?? ''), 'UTF-8');
				if (mb_strpos($prov, mb_strtolower($f['proveedor'], 'UTF-8')) === false) {
					return false;
				}
			}
			if ($f['fecha_desde'] !== '' && ($r['fecha_max'] ?? '') < $f['fecha_desde']) {
				return false;
			}
			if ($f['fecha_hasta'] !== '' && ($r['fecha_max'] ?? '') > $f['fecha_hasta']) {
				return false;
			}
			return true;
		}));
	}

	private function obtenerConciliacion(array $f)
	{
		$conciliacionModel = $this->loadModel('Conciliacion');
		$rows = $conciliacionModel->getGridRows();

		return array_values(array_filter($rows, function ($r) use ($f) {
			if ($f['estado'] !== '' && ($r['estado_codigo'] ?? '') !== $f['estado']) {
				return false;
			}
			if ($f['match_tipo'] !== '' && ($r['match_tipo'] ?? '') !== $f['match_tipo']) {
				return false;
			}
			if ($f['proveedor'] !== '') {
				$fp = mb_strtolower((string) ($r['factura_proveedor'] ?? ''), 'UTF-8');
				$gp = mb_strtolower((string) ($r['gasto_proveedor'] ?? ''), 'UTF-8');
				$q  = mb_strtolower($f['proveedor'], 'UTF-8');
				if (mb_strpos($fp, $q) === false && mb_strpos($gp, $q) === false) {
					return false;
				}
			}
			if ($f['fecha_desde'] !== '') {
				$fd = ($r['factura_fecha'] ?? $r['gasto_fecha'] ?? '');
				if ($fd !== '' && $fd < $f['fecha_desde']) {
					return false;
				}
			}
			if ($f['fecha_hasta'] !== '') {
				$fd = ($r['factura_fecha'] ?? $r['gasto_fecha'] ?? '');
				if ($fd !== '' && $fd > $f['fecha_hasta']) {
					return false;
				}
			}
			return true;
		}));
	}

	private function headersParaTipo($tipo)
	{
		if ($tipo === 'facturas') {
			return ['Fecha', 'Numero', 'Proveedor', 'Subtotal', 'IVA', 'Total', 'Moneda', 'Archivo'];
		}
		if ($tipo === 'gastos') {
			return ['Fecha', 'Numero', 'Proveedor', 'Items', 'Base', 'IVA', 'Total'];
		}
		return [
			'Estado', 'Score',
			'Fact. Fecha', 'Fact. Numero', 'Fact. Proveedor', 'Fact. IVA', 'Fact. Total',
			'Gasto Fecha', 'Gasto Numero', 'Gasto Proveedor', 'Gasto IVA', 'Gasto Total',
			'Dif. Total', 'Dif. %', 'Tipo Match',
		];
	}

	private function mapRowsParaTipo(array $rows, $tipo)
	{
		$out = [];
		foreach ($rows as $r) {
			if ($tipo === 'facturas') {
				$out[] = [
					$r['fecha_emision'] ?? '',
					$r['numero_factura_asistente'] ?? '',
					$r['proveedor_nombre'] ?? '',
					$r['subtotal'] ?? 0,
					$r['iva'] ?? 0,
					$r['total'] ?? 0,
					$r['moneda'] ?? '',
					$r['archivo_xml'] ?? '',
				];
			} elseif ($tipo === 'gastos') {
				$out[] = [
					$r['fecha_max'] ?? '',
					$r['numero_factura'] ?? '',
					$r['proveedor_texto'] ?? $r['proveedor_nombre'] ?? '',
					$r['cantidad_items'] ?? 1,
					$r['suma_base'] ?? 0,
					$r['suma_iva'] ?? 0,
					$r['suma_total'] ?? 0,
				];
			} else {
				$out[] = [
					$r['estado_nombre'] ?? '',
					round((float) ($r['score_total'] ?? 0), 1),
					$r['factura_fecha'] ?? '',
					$r['factura_numero'] ?? '',
					$r['factura_proveedor'] ?? '',
					$r['factura_iva'] ?? 0,
					$r['factura_total'] ?? 0,
					$r['gasto_fecha'] ?? '',
					$r['gasto_numero'] ?? '',
					$r['gasto_proveedor'] ?? '',
					$r['gasto_iva'] ?? 0,
					$r['gasto_total'] ?? 0,
					$r['diferencia_total'] ?? 0,
					round((float) ($r['porcentaje_diferencia'] ?? 0), 2),
					$r['match_tipo'] ?? '',
				];
			}
		}
		return $out;
	}
}
