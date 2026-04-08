<?php
/**
 * Controlador de conciliación de facturas y gastos
 */

class ConciliacionController extends Controller
{
	private const MONTO_TOLERANCIA = 1.00;

	public function index()
	{
		$rows = [];
		$facturas = [];
		$gastos = [];
		$resumen = [];
		$estados = [];
		$stats = [
			'total_facturas' => 0,
			'total_gastos' => 0,
			'total_conciliadas' => 0,
			'pendientes_revision' => 0,
			'monto_facturas' => 0,
			'monto_gastos' => 0,
		];
		$lastImports = [
			'xml' => null,
			'gastos' => null,
		];
		$loadError = null;

		try {
			$facturaModel = $this->loadModel('Factura');
			$gastoModel = $this->loadModel('Gasto');
			$conciliacionModel = $this->loadModel('Conciliacion');
			$importacionModel = $this->loadModel('Importacion');

			$facturas = $facturaModel->getAllWithImportacion();
			$gastos = $gastoModel->getAllWithProveedor();
			$rows = $conciliacionModel->getGridRows();
			$rows = $this->ordenarPorMatch($rows);
			$resumen = $conciliacionModel->getResumenRevision();
			$estados = $conciliacionModel->getEstadoMap();

			$stats['total_facturas'] = count($facturas);
			$stats['total_gastos'] = count($gastos);
			$stats['total_conciliadas'] = $conciliacionModel->countByEstado('conciliada');
			$stats['pendientes_revision'] = $conciliacionModel->countByEstado('requiere_revision') + $conciliacionModel->countByEstado('con_diferencias');
			$stats['monto_facturas'] = $facturaModel->getTotalMonto();
			$stats['monto_gastos'] = $gastoModel->getTotalMonto();

			$lastImports['xml'] = $importacionModel->getLatestByTipo('xml');
			$lastImports['gastos'] = $importacionModel->getLatestByTipo('gastos');
		} catch (Throwable $e) {
			$loadError = $e->getMessage();
		}

		$this->render('conciliacion/index', [
			'title' => 'Conciliación - XMLConcilia',
			'facturas' => $facturas,
			'gastos' => $gastos,
			'conciliaciones' => $rows,
			'resumen' => $resumen,
			'estados' => $estados,
			'stats' => $stats,
			'lastImports' => $lastImports,
			'load_error' => $loadError
		]);
	}

	public function ejecutar()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/conciliacion'));
		}

		try {
			$facturaModel = $this->loadModel('Factura');
			$gastoModel = $this->loadModel('Gasto');
			$conciliacionModel = $this->loadModel('Conciliacion');

			$facturas = $facturaModel->getAllWithImportacion();
			$gastos = $gastoModel->getAllWithProveedor();

			if (empty($facturas) && empty($gastos)) {
				$this->redirectWithMessage($this->url('/conciliacion'), 'No hay datos para conciliar todavía.', 'warning');
			}

			$estadoMap = $conciliacionModel->getEstadoMap();
			$this->validarEstadosMinimos($estadoMap);

			$conciliacionModel->clearAll();
			$usados = [];
			$creados = 0;

			foreach ($facturas as $factura) {
				$match = $this->buscarMejorGasto($factura, $gastos, $usados);
				$gasto = $match['gasto'] ?? null;

				if ($gasto !== null) {
					$usados[(int) $gasto['id']] = true;
				}

				$registro = $this->buildConciliacionRegistro($factura, $gasto, $match, $estadoMap);
				$conciliacionModel->crearFlexible($registro);
				$creados++;
			}

			foreach ($gastos as $gasto) {
				$gid = (int) ($gasto['id'] ?? 0);
				if ($gid > 0 && isset($usados[$gid])) {
					continue;
				}

				$registro = $this->buildConciliacionRegistro(null, $gasto, [
					'score_numero' => 0,
					'score_proveedor' => 0,
					'score_total' => 0,
					'match_tipo' => 'manual',
					'observaciones' => 'Gasto sin XML asociado'
				], $estadoMap);

				$conciliacionModel->crearFlexible($registro);
				$creados++;
			}

			$this->redirectWithMessage($this->url('/conciliacion'), "Conciliación ejecutada. Registros generados: {$creados}", 'success');
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'Error al conciliar: ' . $e->getMessage(), 'error');
		}
	}

	public function limpiarPruebas()
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/conciliacion'));
		}

		try {
			$facturaModel = $this->loadModel('Factura');
			$conciliacionModel = $this->loadModel('Conciliacion');

			// Primero conciliaciones por integridad referencial, luego facturas.
			$conciliacionModel->clearAll();
			$facturaModel->clearAll();

			$this->redirectWithMessage($this->url('/conciliacion'), 'Se eliminaron facturas y conciliaciones para pruebas.', 'success');
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'No se pudo limpiar los datos de prueba: ' . $e->getMessage(), 'error');
		}
	}

	public function resultados()
	{
		$this->redirect($this->url('/conciliacion'));
	}

	public function pendientes()
	{
		$this->redirect($this->url('/conciliacion'));
	}

	public function revisar($id)
	{
		if (!$this->isPost()) {
			$this->redirect($this->url('/conciliacion'));
		}

		$comentario = trim((string) $this->post('comentario', ''));
		$estadoCodigo = trim((string) $this->post('estado_codigo', 'requiere_revision'));

		try {
			$conciliacionModel = $this->loadModel('Conciliacion');
			$estados = $conciliacionModel->getEstadoMap();

			if (!isset($estados[$estadoCodigo])) {
				throw new Exception('Estado inválido para validación manual.');
			}

			$conciliacionModel->marcarManual((int) $id, (int) $estados[$estadoCodigo]['id'], $comentario, 'operador');
			$this->redirectWithMessage($this->url('/conciliacion'), 'Validación manual aplicada correctamente.', 'success');
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'No fue posible guardar la validación manual: ' . $e->getMessage(), 'error');
		}
	}

	public function exportar()
	{
		$this->respondNotImplemented('Exportación de conciliación');
	}

	public function marcarRevisado()
	{
		$this->json([
			'success' => false,
			'message' => 'Endpoint no implementado aún'
		], 501);
	}

	private function ordenarPorMatch(array $rows)
	{
		foreach ($rows as &$row) {
			$match = $row['score_total'] ?? null;
			if ($match === null || $match === '') {
				$pct = abs((float) ($row['porcentaje_diferencia'] ?? 100));
				$match = max(0, 100 - min(100, $pct));
			}
			$row['match_score'] = round((float) $match, 2);
			$row['estado_priority'] = $this->estadoPriority((string) ($row['estado_codigo'] ?? ''));
		}
		unset($row);

		usort($rows, function ($a, $b) {
			if (($a['estado_priority'] ?? 99) !== ($b['estado_priority'] ?? 99)) {
				return ($a['estado_priority'] < $b['estado_priority']) ? -1 : 1;
			}

			// En filas "mal" (prioridad 1): las peores (menor match) primero.
			if (($a['estado_priority'] ?? 99) === 1 && $a['match_score'] !== $b['match_score']) {
				return ($a['match_score'] < $b['match_score']) ? -1 : 1;
			}

			// En revisión/conciliadas: mayor match arriba.
			if ($a['match_score'] !== $b['match_score']) {
				return ($a['match_score'] < $b['match_score']) ? 1 : -1;
			}

			return strcmp((string) ($b['fecha_conciliacion'] ?? ''), (string) ($a['fecha_conciliacion'] ?? ''));
		});

		return $rows;
	}

	private function estadoPriority($codigo)
	{
		// 1: mal, 2: requiere revision, 3: conciliada, 4+: otros.
		if (in_array($codigo, ['con_diferencias', 'pendiente', 'gasto_sin_xml'], true)) {
			return 1;
		}

		if ($codigo === 'requiere_revision') {
			return 2;
		}

		if ($codigo === 'conciliada') {
			return 3;
		}

		return 4;
	}

	private function buscarMejorGasto(array $factura, array $gastos, array $usados)
	{
		$best = [
			'gasto' => null,
			'score_numero' => 0,
			'score_proveedor' => 0,
			'score_total' => 0,
			'match_tipo' => 'manual',
			'observaciones' => 'Sin gasto asociado'
		];

		foreach ($gastos as $gasto) {
			$gid = (int) ($gasto['id'] ?? 0);
			if ($gid > 0 && isset($usados[$gid])) {
				continue;
			}

			$scoreNumero = $this->similaridadNumero(
				(string) ($factura['numero_factura_asistente'] ?? ''),
				(string) ($gasto['numero_factura'] ?? '')
			);

			$scoreProveedor = $this->similaridadTexto(
				(string) ($factura['proveedor_nombre'] ?? ''),
				(string) ($gasto['proveedor_texto'] ?? '')
			);

			$scoreMonto = $this->scoreMonto(
				(float) ($factura['total'] ?? 0),
				(float) ($gasto['suma_total'] ?? 0),
				(float) ($factura['iva'] ?? 0),
				(float) ($gasto['suma_iva'] ?? 0)
			);

			$scoreTotal = round(($scoreNumero * 0.50) + ($scoreProveedor * 0.30) + ($scoreMonto * 0.20), 2);

			if ($scoreTotal > $best['score_total']) {
				$best = [
					'gasto' => $gasto,
					'score_numero' => round($scoreNumero, 2),
					'score_proveedor' => round($scoreProveedor, 2),
					'score_total' => $scoreTotal,
					'match_tipo' => $scoreTotal >= 95 ? 'exacto' : 'sugerido',
					'observaciones' => 'Match calculado automáticamente'
				];
			}
		}

		if (($best['score_total'] ?? 0) < 20) {
			$best['gasto'] = null;
			$best['match_tipo'] = 'manual';
			$best['observaciones'] = 'No se encontró coincidencia razonable';
		}

		return $best;
	}

	private function buildConciliacionRegistro($factura, $gasto, array $match, array $estadoMap)
	{
		$facturaTotal = (float) ($factura['total'] ?? 0);
		$facturaIva = (float) ($factura['iva'] ?? 0);
		$facturaBase = (float) ($factura['subtotal'] ?? 0);

		$gastoTotal = (float) ($gasto['suma_total'] ?? 0);
		$gastoIva = (float) ($gasto['suma_iva'] ?? 0);
		$gastoBase = (float) ($gasto['suma_base'] ?? 0);

		$difTotal = $facturaTotal - $gastoTotal;
		$difIva = $facturaIva - $gastoIva;
		$difBase = $facturaBase - $gastoBase;

		$referencia = max($facturaTotal, $gastoTotal);
		$pct = $referencia > 0 ? (abs($difTotal) / $referencia) * 100 : 0;

		$match['diferencia_total_abs'] = abs($difTotal);
		$match['diferencia_iva_abs'] = abs($difIva);
		$estadoCodigo = $this->resolverEstado($factura, $gasto, $match, $pct, $estadoMap);
		$estadoId = (int) $estadoMap[$estadoCodigo]['id'];

		return [
			'factura_xml_id' => $factura['id'] ?? null,
			'gasto_consolidado_id' => $gasto['id'] ?? null,
			'estado_id' => $estadoId,
			'diferencia_base' => round($difBase, 2),
			'diferencia_iva' => round($difIva, 2),
			'diferencia_total' => round($difTotal, 2),
			'porcentaje_diferencia' => round($pct, 2),
			'score_numero' => (float) ($match['score_numero'] ?? 0),
			'score_proveedor' => (float) ($match['score_proveedor'] ?? 0),
			'score_total' => (float) ($match['score_total'] ?? 0),
			'match_tipo' => (string) ($match['match_tipo'] ?? 'manual'),
			'observaciones_match' => (string) ($match['observaciones'] ?? ''),
			'notas' => (string) ($match['observaciones'] ?? ''),
			'metadata' => json_encode([
				'factura_numero' => $factura['numero_factura_asistente'] ?? null,
				'gasto_numero' => $gasto['numero_factura'] ?? null,
				'algoritmo' => 'numero/proveedor/monto'
			], JSON_UNESCAPED_UNICODE)
		];
	}

	private function resolverEstado($factura, $gasto, array $match, $pctDiff, array $estadoMap)
	{
		if ($factura === null && $gasto !== null) {
			return 'gasto_sin_xml';
		}

		if ($factura !== null && $gasto === null) {
			return 'pendiente';
		}

		$score = (float) ($match['score_total'] ?? 0);
		$scoreNumero = (float) ($match['score_numero'] ?? 0);
		$scoreProveedor = (float) ($match['score_proveedor'] ?? 0);
		$difTotalAbs = abs((float) ($match['diferencia_total_abs'] ?? 0));
		$difIvaAbs = abs((float) ($match['diferencia_iva_abs'] ?? 0));

		// Verde cuando numero/proveedor coinciden y total+IVA caen dentro de tolerancia.
		if (
			$scoreNumero >= 100 &&
			$scoreProveedor >= 100 &&
			$score >= 99.99 &&
			$difTotalAbs <= self::MONTO_TOLERANCIA &&
			$difIvaAbs <= self::MONTO_TOLERANCIA
		) {
			return 'conciliada';
		}

		if ($score >= 75 && isset($estadoMap['requiere_revision'])) {
			return 'requiere_revision';
		}

		return 'con_diferencias';
	}

	private function similaridadNumero($a, $b)
	{
		$a = $this->normalizarNumero($a);
		$b = $this->normalizarNumero($b);

		if ($a === '' || $b === '') {
			return 0;
		}

		if ($a === $b) {
			return 100;
		}

		similar_text($a, $b, $pct);
		return $pct;
	}

	private function similaridadTexto($a, $b)
	{
		$a = $this->normalizarTexto($a);
		$b = $this->normalizarTexto($b);

		if ($a === '' || $b === '') {
			return 0;
		}

		if ($a === $b) {
			return 100;
		}

		similar_text($a, $b, $pct);
		return $pct;
	}

	private function scoreMonto($facturaTotal, $gastoTotal, $facturaIva, $gastoIva)
	{
		$facturaTotal = (float) $facturaTotal;
		$gastoTotal = (float) $gastoTotal;
		$facturaIva = (float) $facturaIva;
		$gastoIva = (float) $gastoIva;

		$scoreTotal = $this->scorePorDiferencia($facturaTotal, $gastoTotal);
		$scoreIva = $this->scorePorDiferencia($facturaIva, $gastoIva);

		// El monto de IVA impacta explícitamente el match global.
		return round(($scoreTotal * 0.70) + ($scoreIva * 0.30), 2);
	}

	private function scorePorDiferencia($valorA, $valorB)
	{
		$valorA = (float) $valorA;
		$valorB = (float) $valorB;

		if ($valorA <= 0 && $valorB <= 0) {
			return 100;
		}

		if ($valorA <= 0 || $valorB <= 0) {
			return 0;
		}

		$base = max($valorA, $valorB);
		$diff = abs($valorA - $valorB);

		if ($diff <= self::MONTO_TOLERANCIA) {
			return 100;
		}

		$pct = ($diff / $base) * 100;

		return max(0, 100 - min(100, $pct));
	}

	private function normalizarNumero($value)
	{
		$text = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim((string) $value)));
		$text = preg_replace('/^0+/', '', $text);
		return $text;
	}

	private function normalizarTexto($value)
	{
		$text = strtoupper(trim((string) $value));
		$text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
		$text = preg_replace('/\b(SA|SAS|S\.?A\.?|SOCIEDAD|ANONIMA|DE|CV|LTDA|LIMITADA|INC|CORP|COMPANIA|CIA)\b/', ' ', $text);
		$text = preg_replace('/[^A-Z0-9 ]/', ' ', $text);
		$text = preg_replace('/\s+/', ' ', $text);
		return trim($text);
	}

	private function validarEstadosMinimos(array $estadoMap)
	{
		$requeridos = ['conciliada', 'con_diferencias', 'pendiente', 'gasto_sin_xml'];
		$faltantes = [];

		foreach ($requeridos as $codigo) {
			if (!isset($estadoMap[$codigo])) {
				$faltantes[] = $codigo;
			}
		}

		if (!empty($faltantes)) {
			throw new Exception('Faltan estados en catalogo_estados: ' . implode(', ', $faltantes));
		}
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
