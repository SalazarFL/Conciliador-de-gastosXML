<?php
/**
 * Controlador de conciliación de facturas y gastos
 */

class ConciliacionController extends Controller
{
	/** Diferencia de monto considerada "exacta" (solo redondeo de centavos). */
	private const MONTO_TOLERANCIA = 0.01;

	/** Diferencia máxima absoluta para marcar una conciliación como cerrada. */
	private const MONTO_TOLERANCIA_CONCILIADA = 0.50;

	/** Umbral mínimo individual: AMBOS identificadores (número Y proveedor) deben alcanzarlo. */
	private const UMBRAL_IDENTIFICADOR = 30;

	/** Umbral del score ponderado final para aceptar un emparejamiento. */
	private const UMBRAL_MATCH = 45;

	/** Score por encima del cual el match se etiqueta como "exacto". */
	private const UMBRAL_EXACTO = 99.5;

	public function __construct() { $this->requireAuth(); }

	public function index()
	{
		$rows = [];
		$facturas = [];
		$gastos = [];
		$resumen = [];
		$estados = [];
		$importacionesXml    = [];
		$importacionesGastos = [];
		$stats = [
			'total_facturas' => 0,
			'total_gastos' => 0,
			'total_conciliadas' => 0,
			'pendientes_revision' => 0,
			'monto_facturas' => 0,
			'monto_gastos' => 0,
		];
		$loadError = null;

		if (empty($_GET['limpiar'])) {
			try {
				$facturaModel      = $this->loadModel('Factura');
				$gastoModel        = $this->loadModel('Gasto');
				$conciliacionModel = $this->loadModel('Conciliacion');
				$importacionModel  = $this->loadModel('Importacion');

				$facturas = $facturaModel->getAllWithImportacion();
				$gastos   = $gastoModel->getAllWithProveedor();
				$rows     = $conciliacionModel->getGridRows();
				$rows     = $this->ordenarPorMatch($rows);
				$resumen  = $conciliacionModel->getResumenRevision();
				$estados  = $conciliacionModel->getEstadoMap();

				$stats['total_facturas']    = count($facturas);
				$stats['total_gastos']      = count($gastos);
				$stats['total_conciliadas'] = $conciliacionModel->countByEstado('conciliada');
				$stats['pendientes_revision'] = $conciliacionModel->countByEstado('requiere_revision')
					+ $conciliacionModel->countByEstado('con_diferencias');
				$stats['monto_facturas'] = $facturaModel->getTotalMonto();
				$stats['monto_gastos']   = $gastoModel->getTotalMonto();

				$importacionesXml    = $importacionModel->getAllByTipo('xml');
				$importacionesGastos = $importacionModel->getAllByTipo('gastos');
			} catch (Throwable $e) {
				$loadError = $e->getMessage();
			}
		}

		$this->render('conciliacion/index', [
			'title'              => 'Conciliación - XMLConcilia',
			'facturas'           => $facturas,
			'gastos'             => $gastos,
			'conciliaciones'     => $rows,
			'resumen'            => $resumen,
			'estados'            => $estados,
			'stats'              => $stats,
			'importacionesXml'   => $importacionesXml,
			'importacionesGastos'=> $importacionesGastos,
			'load_error'         => $loadError
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

			$importacionIdXml    = max(0, (int) $this->post('importacion_id_xml', 0));
			$importacionIdGastos = max(0, (int) $this->post('importacion_id_gastos', 0));

			$facturas = $importacionIdXml > 0
				? $facturaModel->getByImportacion($importacionIdXml)
				: $facturaModel->getAllWithImportacion();

			$gastos = $importacionIdGastos > 0
				? $gastoModel->getByImportacion($importacionIdGastos)
				: $gastoModel->getAllWithProveedor();

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

			$sufijo = $importacionIdXml > 0 ? " (importación #{$importacionIdXml})" : '';
			$this->redirectWithMessage($this->url('/conciliacion'), "Conciliación ejecutada. Registros generados: {$creados}{$sufijo}", 'success');
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'Error al conciliar: ' . $e->getMessage(), 'error');
		}
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
			'score_fecha' => 0,
			'score_monto' => 0,
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

			// GATE: ambos identificadores deben tener similitud mínima;
			// evita emparejar registros que solo coinciden por número o solo por proveedor.
			if ($scoreNumero < self::UMBRAL_IDENTIFICADOR || $scoreProveedor < self::UMBRAL_IDENTIFICADOR) {
				continue;
			}

			$scoreMonto = $this->scoreMonto(
				(float) ($factura['total'] ?? 0),
				(float) ($gasto['suma_total'] ?? 0),
				(float) ($factura['iva'] ?? 0),
				(float) ($gasto['suma_iva'] ?? 0)
			);

			$scoreFecha = $this->scoreFecha(
				(string) ($factura['fecha_emision'] ?? ''),
				(string) ($gasto['fecha_max'] ?? ($gasto['fecha_min'] ?? ''))
			);

			// Pesos: monto 40%, número 25%, proveedor 20%, fecha 15%.
			// El monto es el eje contable más relevante; un total distinto debe doler.
			$scoreTotal = round(
				($scoreMonto     * 0.40) +
				($scoreNumero    * 0.25) +
				($scoreProveedor * 0.20) +
				($scoreFecha     * 0.15),
				2
			);

			if ($scoreTotal > $best['score_total']) {
				$best = [
					'gasto' => $gasto,
					'score_numero' => round($scoreNumero, 2),
					'score_proveedor' => round($scoreProveedor, 2),
					'score_fecha' => round($scoreFecha, 2),
					'score_monto' => round($scoreMonto, 2),
					'score_total' => $scoreTotal,
					'match_tipo' => $scoreTotal >= self::UMBRAL_EXACTO ? 'exacto' : 'sugerido',
					'observaciones' => 'Match calculado automáticamente'
				];
			}
		}

		if (($best['score_total'] ?? 0) < self::UMBRAL_MATCH) {
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
		$estadoCodigo = $this->resolverEstado($factura, $gasto, $match, $estadoMap);
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
				'gasto_numero'   => $gasto['numero_factura'] ?? null,
				'score_fecha'    => round((float) ($match['score_fecha'] ?? 0), 2),
				'score_monto'    => round((float) ($match['score_monto'] ?? 0), 2),
				'algoritmo'      => 'monto/numero/proveedor/fecha (v2)'
			], JSON_UNESCAPED_UNICODE)
		];
	}

	private function resolverEstado($factura, $gasto, array $match, array $estadoMap)
	{
		if ($factura === null && $gasto !== null) {
			return 'gasto_sin_xml';
		}

		if ($factura !== null && $gasto === null) {
			return 'pendiente';
		}

		$score          = (float) ($match['score_total'] ?? 0);
		$scoreNumero    = (float) ($match['score_numero'] ?? 0);
		$scoreProveedor = (float) ($match['score_proveedor'] ?? 0);
		$scoreFecha     = (float) ($match['score_fecha'] ?? 0);
		$difTotalAbs    = abs((float) ($match['diferencia_total_abs'] ?? 0));
		$difIvaAbs      = abs((float) ($match['diferencia_iva_abs'] ?? 0));

		// "Conciliada" exige TODOS los ejes alineados:
		// número exacto, proveedor casi idéntico, fecha próxima, total e IVA dentro de tolerancia estricta.
		if (
			$scoreNumero    >= 100 &&
			$scoreProveedor >=  95 &&
			$scoreFecha     >=  85 &&
			$difTotalAbs    <= self::MONTO_TOLERANCIA_CONCILIADA &&
			$difIvaAbs      <= self::MONTO_TOLERANCIA_CONCILIADA
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

		// Para números cortos (≤6 chars), similar_text() infla el porcentaje
		// (ej: "657" vs "627" da 67% aunque son facturas distintas).
		// Usamos levenshtein: distancia 1 = posible typo, >1 = distinto.
		$maxLen = max(strlen($a), strlen($b));
		if ($maxLen <= 6) {
			$dist = levenshtein($a, $b);
			return $dist === 1 ? 50 : 0;
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
		$scoreTotal = $this->scorePorDiferencia((float) $facturaTotal, (float) $gastoTotal);
		$scoreIva   = $this->scorePorDiferencia((float) $facturaIva,   (float) $gastoIva);

		// El total pesa más que el IVA, pero ambos deben coincidir para llegar al 100.
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

		// Solo diferencias ≤ 1 centavo cuentan como exactas; cualquier otra cosa baja el score.
		if ($diff <= self::MONTO_TOLERANCIA) {
			return 100;
		}

		$pct = ($diff / $base) * 100;

		return round(max(0, 100 - min(100, $pct)), 2);
	}

	private function scoreFecha($fechaA, $fechaB)
	{
		$a = trim((string) $fechaA);
		$b = trim((string) $fechaB);

		if ($a === '' || $b === '') {
			return 0;
		}

		$tsA = strtotime($a);
		$tsB = strtotime($b);
		if ($tsA === false || $tsB === false) {
			return 0;
		}

		$dias = (int) round(abs($tsA - $tsB) / 86400);

		// Penalización progresiva: misma fecha = 100, 1 día = 85, hasta caer a 0 después del mes.
		if ($dias === 0)  return 100;
		if ($dias === 1)  return 85;
		if ($dias <= 3)   return 65;
		if ($dias <= 7)   return 40;
		if ($dias <= 30)  return 15;
		return 0;
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


}
