<?php
/**
 * Controlador de conciliación de facturas y gastos
 */
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';

class ConciliacionController extends Controller
{
	/** Diferencia de monto considerada "exacta" (solo redondeo de centavos). */
	private const MONTO_TOLERANCIA = 0.01;

	/** Diferencia máxima absoluta para marcar una conciliación como cerrada. */
	private const MONTO_TOLERANCIA_CONCILIADA = 0.50;

	/** Umbral mínimo de similitud de PROVEEDOR para considerar un candidato. */
	private const UMBRAL_PROVEEDOR = 60;

	/** Umbral mínimo de similitud de NÚMERO de factura dentro del mismo proveedor. */
	private const UMBRAL_NUMERO = 30;

	/** Umbral del score ponderado final para aceptar un emparejamiento. */
	private const UMBRAL_MATCH = 45;

	/** Score por encima del cual el match se etiqueta como "exacto". */
	private const UMBRAL_EXACTO = 99.5;

	/** Sufijos corporativos y stopwords para normalizar nombres de proveedor. */
	private const STOPWORDS_PROVEEDOR = [
		// Sufijos corporativos
		'SA', 'SAS', 'SRL', 'SL', 'SC', 'SCA',
		'SOCIEDAD', 'ANONIMA', 'SIMPLIFICADA',
		'LTDA', 'LIMITADA', 'LTD', 'LIMITED',
		'CIA', 'COMPANIA', 'COMPANY', 'CO',
		'INC', 'INCORPORATED', 'CORP', 'CORPORATION',
		'CV', 'LLC', 'GMBH', 'AG',
		// Conectores
		'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS',
		'Y', 'E', 'O', 'U',
	];

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

		// Historial siempre se carga (incluso con ?limpiar)
		try {
			$importacionModel    = $this->loadModel('Importacion');
			$importacionesXml    = $importacionModel->getAllXmlPorDocumento('FE');
			$importacionesGastos = $importacionModel->getAllByTipo('gastos');
		} catch (Throwable $e) {
			// historial no crítico
		}

		$corridas      = [];
		$corridaActiva = null;

		if (empty($_GET['limpiar'])) {
			try {
				$facturaModel      = $this->loadModel('Factura');
				$gastoModel        = $this->loadModel('Gasto');
				$conciliacionModel = $this->loadModel('Conciliacion');

				$corridas  = $conciliacionModel->getCorridas(50);
				$corridaId = max(0, (int) ($_GET['corrida_id'] ?? 0));
				if ($corridaId <= 0 && !empty($corridas)) {
					$corridaId = (int) $corridas[0]['id'];
				}
				foreach ($corridas as $c) {
					if ((int) $c['id'] === $corridaId) {
						$corridaActiva = $c;
						break;
					}
				}

				$facturas = $facturaModel->getAllWithImportacion();
				$gastos   = $gastoModel->getAllWithProveedor();
				$rows     = $conciliacionModel->getGridRows($corridaId > 0 ? $corridaId : null);
				$rows     = $this->ordenarPorMatch($rows);
				$resumen  = $conciliacionModel->getResumenRevision($corridaId);
				$estados  = $conciliacionModel->getEstadoMap();

				$stats['total_facturas']    = count($facturas);
				$stats['total_gastos']      = count($gastos);
				$stats['total_conciliadas'] = $conciliacionModel->countByEstado('conciliada', $corridaId);
				$stats['pendientes_revision'] = $conciliacionModel->countByEstado('requiere_revision', $corridaId)
					+ $conciliacionModel->countByEstado('con_diferencias', $corridaId);
				$stats['monto_facturas'] = $facturaModel->getTotalMonto();
				$stats['monto_gastos']   = $gastoModel->getTotalMonto();
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
			'corridas'           => $corridas,
			'corridaActiva'      => $corridaActiva,
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
			$importacionModel = $this->loadModel('Importacion');

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

			// Nombre de la corrida: nombre de la importación XML + archivo del listado de gastos.
			$nombreXml = 'Todas las facturas';
			if ($importacionIdXml > 0) {
				$imp = $importacionModel->findById($importacionIdXml);
				$nombreXml = trim((string) ($imp['archivo_origen'] ?? '')) ?: "Importación #{$importacionIdXml}";
			}
			$nombreGastos = 'Todos los gastos';
			if ($importacionIdGastos > 0) {
				$imp = $importacionModel->findById($importacionIdGastos);
				$nombreGastos = trim((string) ($imp['archivo_origen'] ?? '')) ?: "Importación #{$importacionIdGastos}";
			}
			$nombreCorrida = $nombreXml . ' × ' . $nombreGastos;

			$corridaId = (int) $conciliacionModel->crearCorrida($nombreCorrida, $importacionIdXml, $importacionIdGastos);

			$usados = [];
			$creados = 0;

			foreach ($facturas as $factura) {
				$match = $this->buscarMejorGasto($factura, $gastos, $usados);
				$gasto = $match['gasto'] ?? null;

				if ($gasto !== null) {
					$usados[(int) $gasto['id']] = true;
				}

				$registro = $this->buildConciliacionRegistro($factura, $gasto, $match, $estadoMap);
				$registro['corrida_id'] = $corridaId;
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

				$registro['corrida_id'] = $corridaId;
				$conciliacionModel->crearFlexible($registro);
				$creados++;
			}

			$conciliacionModel->actualizarTotalCorrida($corridaId, $creados);

			$this->redirectWithMessage(
				$this->url('/conciliacion?corrida_id=' . $corridaId),
				"Conciliación \"{$nombreCorrida}\" guardada. Registros generados: {$creados}",
				'success'
			);
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

	/**
	 * Descarga un ZIP con los XML de las facturas de una corrida que tienen
	 * gasto asociado. Cada archivo se renombra: FE_{PROVEEDOR}_{ddmmyy}_{numero 8 dígitos}.xml
	 */
	public function descargarXml()
	{
		try {
			$conciliacionModel = $this->loadModel('Conciliacion');

			$corridaId = max(0, (int) ($_GET['corrida_id'] ?? 0));
			if ($corridaId <= 0) {
				$corridaId = (int) $conciliacionModel->getUltimaCorridaId();
			}

			$facturas = $conciliacionModel->getFacturasParaZip($corridaId > 0 ? $corridaId : null);
			if (empty($facturas)) {
				$this->redirectWithMessage(
					$this->url('/conciliacion'),
					'No hay facturas con gasto asociado en esta conciliación para descargar.',
					'warning'
				);
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'zipfe_');
			if ($tmpFile === false) {
				throw new Exception('No se pudo crear el archivo temporal para el ZIP.');
			}

			$zip = new ZipArchive();
			if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
				throw new Exception('No se pudo crear el archivo ZIP.');
			}

			$usados = [];
			$porContenido = [];
			$avisos = [];
			$agregados = 0;

			foreach ($facturas as $f) {
				$rutaXml = (string) ($f['ruta_xml'] ?? '');
				$rutaReal = $rutaXml !== '' ? realpath($rutaXml) : false;
				$contenido = (string) ($f['xml_contenido'] ?? ''); // compatibilidad durante migración
				$nombre = $this->nombreArchivoFactura($f);

				if ($rutaReal === false && $contenido === '') {
					$avisos[] = "{$nombre}: omitida, el archivo XML local no está disponible.";
					continue;
				}

				// Integridad: el contenido debe corresponder a ESTA factura.
				// Si el hash guardado al importar no coincide, el registro está corrupto.
				$hashRow = (string) ($f['hash_xml'] ?? '');
				$hashActual = $rutaReal !== false ? hash_file('sha256', $rutaReal) : hash('sha256', $contenido);
				if ($hashRow !== '' && !hash_equals($hashRow, $hashActual)) {
					$avisos[] = "{$nombre}: omitida, el contenido XML no corresponde a esta factura (hash no coincide).";
					continue;
				}

				// Dos facturas distintas jamás deben compartir el mismo XML.
				$firmaContenido = $hashActual;
				if (isset($porContenido[$firmaContenido])) {
					$avisos[] = "{$nombre}: omitida, su contenido XML es idéntico al de {$porContenido[$firmaContenido]} (registro duplicado/corrupto en BD).";
					continue;
				}

				$final = $nombre;
				$i = 2;
				while (isset($usados[$final])) {
					$final = $nombre . '_' . $i;
					$i++;
				}
				$usados[$final] = true;
				$porContenido[$firmaContenido] = $final;

				if ($rutaReal !== false) {
					$zip->addFile($rutaReal, $final . '.xml');
				} else {
					$zip->addFromString($final . '.xml', $contenido);
				}
				$agregados++;
			}

			if (!empty($avisos)) {
				$zip->addFromString(
					'AVISOS.txt',
					"Archivos omitidos del ZIP (" . count($avisos) . "):\r\n\r\n" . implode("\r\n", $avisos) . "\r\n"
				);
			}

			$zip->close();

			if ($agregados === 0) {
				@unlink($tmpFile);
				$this->redirectWithMessage(
					$this->url('/conciliacion'),
					'Las facturas de esta conciliación no tienen contenido XML almacenado.',
					'warning'
				);
			}

			$zipName = 'facturas_conciliadas_'
				. ($corridaId > 0 ? "corrida{$corridaId}_" : '')
				. date('Ymd_His') . '.zip';

			header('Content-Type: application/zip');
			header('Content-Disposition: attachment; filename="' . $zipName . '"');
			header('Content-Length: ' . filesize($tmpFile));
			header('Cache-Control: max-age=0');

			readfile($tmpFile);
			@unlink($tmpFile);
			exit;
		} catch (Throwable $e) {
			$this->redirectWithMessage($this->url('/conciliacion'), 'Error al generar el ZIP: ' . $e->getMessage(), 'error');
		}
	}

	/** Sufijos societarios que se eliminan del nombre del archivo (los conectores DE/DEL/LA se conservan). */
	private const SUFIJOS_SOCIETARIOS = [
		'SA', 'SAS', 'SRL', 'SL', 'SC', 'SCA',
		'SOCIEDAD', 'ANONIMA', 'SIMPLIFICADA', 'RESPONSABILIDAD',
		'LTDA', 'LIMITADA', 'LTD', 'LIMITED',
		'CIA', 'COMPANIA', 'COMPANY', 'CO',
		'INC', 'INCORPORATED', 'CORP', 'CORPORATION',
		'CV', 'LLC', 'GMBH', 'AG',
	];

	/**
	 * Nombre estandarizado del archivo XML:
	 *   FE_ALIMENTOS_DEL_SUR_120126_00009857
	 *   FE = factura electrónica
	 *   ALIMENTOS_DEL_SUR = nombre del proveedor sin sufijos societarios (S.A, LTDA…), espacios → _
	 *   120126 = fecha de emisión (ddmmyy)
	 *   00009857 = número corto de la factura, relleno a 8 dígitos
	 */
	private function nombreArchivoFactura(array $f)
	{
		$prov = mb_strtoupper(trim((string) ($f['proveedor_nombre'] ?? '')), 'UTF-8');
		// Acentos y eñes: mapa explícito (iconv TRANSLIT es poco confiable en Windows).
		$prov = strtr($prov, [
			'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
			'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
			'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
			'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
			'Ñ' => 'N', 'Ç' => 'C',
		]);
		$prov = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $prov) ?: $prov;
		// Quitar puntos ANTES de tokenizar para que "S.A." se vuelva "SA"
		// (token que sí está en la lista de sufijos) y "M." quede como inicial "M".
		$prov = str_replace('.', '', $prov);
		$prov = preg_replace('/[^A-Z0-9 ]/', ' ', $prov);
		$prov = preg_replace('/\s+/', ' ', trim($prov));

		$sufijos = array_flip(self::SUFIJOS_SOCIETARIOS);
		$tokens = array_values(array_filter(
			explode(' ', $prov),
			fn($t) => $t !== '' && !isset($sufijos[$t])
		));

		$tokenProv = !empty($tokens) ? implode('_', $tokens) : 'PROVEEDOR';
		$tokenProv = trim(mb_substr($tokenProv, 0, DocumentoArchivo::MAX_TOKEN_PROVEEDOR, 'UTF-8'), '_');

		$ts = strtotime((string) ($f['fecha_emision'] ?? ''));
		$fechaStr = $ts !== false ? date('dmy', $ts) : '000000';

		$core = $this->numeroCorto((string) ($f['numero_factura_asistente'] ?? ''));
		if ($core === '') {
			$core = '0';
		}
		$numero = strlen($core) >= 8 ? $core : str_pad($core, 8, '0', STR_PAD_LEFT);

		return "FE_{$tokenProv}_{$fechaStr}_{$numero}";
	}

	/**
	 * Número corto: la secuencia de dígitos más larga del texto, sin ceros a la
	 * izquierda y SIN mínimo de longitud (un número corto como "9" es válido aquí).
	 */
	private function numeroCorto($valor)
	{
		preg_match_all('/\d+/', (string) $valor, $m);
		$core = '';
		foreach ($m[0] as $run) {
			$run = ltrim($run, '0');
			if (strlen($run) > strlen($core)) {
				$core = $run;
			}
		}
		return $core;
	}

	/**
	 * Mapa número corto → nombre FE_ de las facturas de una corrida (las que
	 * tienen gasto asociado, igual que el ZIP de XML). Lo usa el renombrador
	 * de PDFs del navegador: los PDF nunca se suben al servidor.
	 */
	public function mapaNombresPdf()
	{
		try {
			$conciliacionModel = $this->loadModel('Conciliacion');

			$corridaId = max(0, (int) ($_GET['corrida_id'] ?? 0));
			if ($corridaId <= 0) {
				$corridaId = (int) $conciliacionModel->getUltimaCorridaId();
			}

			$facturas = $conciliacionModel->getFacturasParaZip($corridaId > 0 ? $corridaId : null);

			$mapa = [];
			$ambiguos = [];
			foreach ($facturas as $f) {
				$core = $this->numeroCorto((string) ($f['numero_factura_asistente'] ?? ''));
				if ($core === '') {
					continue;
				}
				$nombre = $this->nombreArchivoFactura($f);
				if (isset($mapa[$core]) && $mapa[$core] !== $nombre) {
					// Dos facturas distintas comparten número corto: no se puede
					// decidir automáticamente, se reporta como ambiguo.
					$ambiguos[$core] = true;
					continue;
				}
				$mapa[$core] = $nombre;
			}
			foreach (array_keys($ambiguos) as $core) {
				unset($mapa[$core]);
			}

			$this->json([
				'success'    => true,
				'corrida_id' => $corridaId,
				'total'      => count($mapa),
				'mapa'       => $mapa,
				'ambiguos'   => array_keys($ambiguos),
			]);
		} catch (Throwable $e) {
			$this->json(['success' => false, 'message' => $e->getMessage()], 500);
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

		$facturaNumero    = (string) ($factura['numero_factura_asistente'] ?? '');
		$facturaProveedor = (string) ($factura['proveedor_nombre'] ?? '');
		$facturaTotal     = (float)  ($factura['total'] ?? 0);
		$facturaFecha     = (string) ($factura['fecha_emision'] ?? '');

		// Fase 1: filtrar candidatos que coincidan en proveedor.
		$candidatos = [];
		foreach ($gastos as $gasto) {
			$gid = (int) ($gasto['id'] ?? 0);
			if ($gid > 0 && isset($usados[$gid])) {
				continue;
			}

			$scoreProveedor = $this->similaridadTexto(
				$facturaProveedor,
				(string) ($gasto['proveedor_texto'] ?? '')
			);

			if ($scoreProveedor < self::UMBRAL_PROVEEDOR) {
				continue;
			}

			$candidatos[] = [
				'gasto' => $gasto,
				'score_proveedor' => $scoreProveedor,
			];
		}

		if (empty($candidatos)) {
			return $best;
		}

		// Fase 2: dentro del mismo proveedor, buscar la mejor coincidencia por número
		// (número identifica la factura; los demás ejes rompen empates).
		foreach ($candidatos as $cand) {
			$gasto = $cand['gasto'];
			$scoreProveedor = $cand['score_proveedor'];

			$scoreNumero = $this->similaridadNumero(
				$facturaNumero,
				(string) ($gasto['numero_factura'] ?? '')
			);

			if ($scoreNumero < self::UMBRAL_NUMERO) {
				continue;
			}

			$scoreMonto = $this->scoreMonto($facturaTotal, (float) ($gasto['suma_total'] ?? 0));
			$scoreFecha = $this->scoreFecha(
				$facturaFecha,
				(string) ($gasto['fecha_max'] ?? ($gasto['fecha_min'] ?? ''))
			);

			// Pesos: número 40%, proveedor 30%, monto 15%, fecha 15%.
			// El número es el identificador único dentro del proveedor.
			$scoreTotal = round(
				($scoreNumero    * 0.40) +
				($scoreProveedor * 0.30) +
				($scoreMonto     * 0.15) +
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

		// "Conciliada" exige TODOS los ejes alineados:
		// número exacto, proveedor casi idéntico, fecha próxima, total dentro de tolerancia estricta.
		if (
			$scoreNumero    >= 100 &&
			$scoreProveedor >=  95 &&
			$scoreFecha     >=  85 &&
			$difTotalAbs    <= self::MONTO_TOLERANCIA_CONCILIADA
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
		$rawA = (string) $a;
		$rawB = (string) $b;

		$a = $this->normalizarNumero($rawA);
		$b = $this->normalizarNumero($rawB);

		if ($a === '' || $b === '') {
			return 0;
		}

		if ($a === $b) {
			return 100;
		}

		// Núcleo numérico: la secuencia de dígitos más larga sin ceros a la izquierda.
		// Cubre formatos como "0000071176" vs "000...00071176" vs "FACT-1-1-0000071176-360",
		// que representan la misma factura con relleno de ceros o prefijos/sufijos.
		$coreA = $this->nucleoNumerico($rawA);
		$coreB = $this->nucleoNumerico($rawB);
		if ($coreA !== '' && $coreA === $coreB) {
			return 100;
		}
		// El núcleo de uno aparece como secuencia completa en el otro
		// (ej. núcleo "71176" vs consecutivo "FACT-2-71176").
		if ($coreA !== '' && in_array($coreA, $this->secuenciasNumericas($rawB), true)) {
			return 95;
		}
		if ($coreB !== '' && in_array($coreB, $this->secuenciasNumericas($rawA), true)) {
			return 95;
		}

		// El número corto está incrustado al final del consecutivo largo con
		// relleno de ceros: "0000005061" vs "FACT-01400020010000005061-3"
		// (el consecutivo de Hacienda termina en el número de factura).
		if ($this->nucleoTerminaEn($coreB, $coreA) || $this->nucleoTerminaEn($coreA, $coreB)) {
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

	/**
	 * Secuencias de dígitos del texto, sin ceros a la izquierda.
	 * Se ignoran secuencias de menos de 3 dígitos (serie/sucursal como "1" o "36"
	 * generarían falsos positivos).
	 */
	private function secuenciasNumericas($value)
	{
		preg_match_all('/\d+/', (string) $value, $m);
		$runs = [];
		foreach ($m[0] as $run) {
			$run = ltrim($run, '0');
			if (strlen($run) >= 3) {
				$runs[] = $run;
			}
		}
		return $runs;
	}

	/** La secuencia numérica más larga: el "número real" de la factura. */
	private function nucleoNumerico($value)
	{
		$best = '';
		foreach ($this->secuenciasNumericas($value) as $run) {
			if (strlen($run) > strlen($best)) {
				$best = $run;
			}
		}
		return $best;
	}

	/**
	 * ¿El núcleo largo termina en el núcleo corto con relleno de ceros?
	 * "1400020010000005061" termina en "5061" y el dígito anterior es 0
	 * → mismo número. El guard del cero evita confundir 5061 con ...15061.
	 */
	private function nucleoTerminaEn($largo, $corto)
	{
		$largo = (string) $largo;
		$corto = (string) $corto;

		if (strlen($corto) < 3 || strlen($largo) <= strlen($corto)) {
			return false;
		}
		if (substr($largo, -strlen($corto)) !== $corto) {
			return false;
		}
		return substr($largo, -strlen($corto) - 1, 1) === '0';
	}

	/**
	 * Similitud de proveedor basada en tokens.
	 *
	 * Normaliza ambos nombres, tokeniza, y para cada token del más corto busca el mejor
	 * match del otro. Reconoce iniciales ("M." ≈ "MIGUEL"), abreviaturas cortas ("CIA" ≈
	 * "COMPANIA") y ignora sufijos corporativos (SA, LTDA, SOCIEDAD, etc.).
	 *
	 * Ejemplo:
	 *   "Bejos M. Yamuni e Hijos S.A"       → tokens: [BEJOS, M, YAMUNI, HIJOS]
	 *   "BEJOS MIGUEL YAMUNI E HIJOS SOCIEDAD" → tokens: [BEJOS, MIGUEL, YAMUNI, HIJOS]
	 *   score ≈ 97 (M ↔ MIGUEL como inicial)
	 */
	private function similaridadTexto($a, $b)
	{
		$tokensA = $this->tokenizarProveedor($a);
		$tokensB = $this->tokenizarProveedor($b);

		if (empty($tokensA) || empty($tokensB)) {
			return 0;
		}

		// Iterar sobre el conjunto más corto para no penalizar razones sociales largas
		// que contengan iniciales/apellidos extra.
		if (count($tokensA) > count($tokensB)) {
			[$tokensA, $tokensB] = [$tokensB, $tokensA];
		}

		$sum = 0;
		$usados = [];
		foreach ($tokensA as $ta) {
			$best = 0;
			$bestIdx = -1;
			foreach ($tokensB as $i => $tb) {
				if (isset($usados[$i])) {
					continue;
				}
				$s = $this->scoreToken($ta, $tb);
				if ($s > $best) {
					$best = $s;
					$bestIdx = $i;
				}
			}
			if ($bestIdx >= 0) {
				$usados[$bestIdx] = true;
			}
			$sum += $best;
		}

		return round($sum / count($tokensA), 2);
	}

	/**
	 * Compara dos tokens individuales.
	 *
	 * Reglas:
	 * - Igual → 100
	 * - Uno es letra sola y prefijo del otro ("M" ↔ "MIGUEL") → 90
	 * - Uno es abreviatura ≤3 chars y prefijo del otro ("CIA" ↔ "COMPANIA") → 85
	 * - similar_text ≥75 → devuelve %; menor descarta (0)
	 */
	private function scoreToken($a, $b)
	{
		if ($a === $b) {
			return 100;
		}

		$lenA = strlen($a);
		$lenB = strlen($b);

		if ($lenA === 1) {
			return str_starts_with($b, $a) ? 90 : 0;
		}
		if ($lenB === 1) {
			return str_starts_with($a, $b) ? 90 : 0;
		}

		if ($lenA <= 3 && str_starts_with($b, $a)) {
			return 85;
		}
		if ($lenB <= 3 && str_starts_with($a, $b)) {
			return 85;
		}

		similar_text($a, $b, $pct);
		return $pct >= 75 ? $pct : 0;
	}

	/**
	 * Convierte un nombre de proveedor a lista de tokens significativos.
	 * Quita acentos, puntuación, sufijos corporativos (SA, LTDA, SOCIEDAD…)
	 * y conectores (DE, LA, Y, E…).
	 */
	private function tokenizarProveedor($value)
	{
		$text = strtoupper(trim((string) $value));
		$text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
		$text = preg_replace('/[^A-Z0-9 ]/', ' ', $text);
		$text = preg_replace('/\s+/', ' ', trim($text));

		if ($text === '') {
			return [];
		}

		$tokens = explode(' ', $text);
		$stopwords = array_flip(self::STOPWORDS_PROVEEDOR);

		return array_values(array_filter(
			$tokens,
			fn($t) => $t !== '' && !isset($stopwords[$t])
		));
	}

	private function scoreMonto($facturaTotal, $gastoTotal)
	{
		return $this->scorePorDiferencia((float) $facturaTotal, (float) $gastoTotal);
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
