<?php
/**
 * Helper para extraer datos de facturas desde PDF.
 *
 * Flujo:
 * 1) Intentar extraer texto digital del PDF.
 * 2) Si no hay texto Ãºtil, intentar OCR (PDF imagen).
 * 3) Mapear texto a campos de factura y devolver estructura compatible.
 *
 * NOTA: Requiere utilidades del sistema para mÃ¡xima precisiÃ³n:
 * - pdftotext (Poppler) para PDFs con texto.
 * - pdftoppm + tesseract para OCR en PDFs escaneados.
 */

if (!class_exists('PdfInvoiceParser', false)) {
class PdfInvoiceParser
{
	public static function parseInvoiceFromPdf($filePath, array $options = [])
	{
		self::extendExecutionWindow();

		if (!file_exists($filePath)) {
			throw new Exception('Archivo PDF no encontrado: ' . $filePath);
		}

		$maxOcrPages = (int) ($options['max_ocr_pages'] ?? 3);
		$ocrLanguage = (string) ($options['ocr_language'] ?? 'spa+eng');
		$sourceName = trim((string) ($options['source_name'] ?? ''));
		if ($sourceName === '') {
			$sourceName = basename((string) $filePath);
		}

		$text = self::extractTextFromPdf($filePath);
		$source = 'pdf_texto';
		$ocrUsed = false;

		if (!self::isUsefulText($text)) {
			$text = self::extractTextViaOcr($filePath, $maxOcrPages, $ocrLanguage);
			$source = 'pdf_ocr';
			$ocrUsed = true;
		}

		if (!self::isUsefulText($text)) {
			throw new Exception('No fue posible extraer texto Ãºtil del PDF. Instala pdftotext o tesseract/pdftoppm y vuelve a intentar.');
		}

		$normalized = self::normalizeText($text);
		$claveCR = self::extractClaveCR($normalized);
		$cedulaJuridica = self::extractCedulaFromClave($claveCR);
		$proveedorApi = self::resolveProviderNameByCedula($cedulaJuridica);
		$templateKey = self::resolveTemplateForCedula($cedulaJuridica, $proveedorApi);
		if ($templateKey === 'generic_cr') {
			$templateByFileName = self::resolveTemplateForCedula($cedulaJuridica, $sourceName);
			if ($templateByFileName !== '' && $templateByFileName !== 'generic_cr') {
				$templateKey = $templateByFileName;
			}
		}

		if (!empty($options['require_template']) && !self::isTemplateConfigured($templateKey)) {
			$cedulaInfo = $cedulaJuridica !== '' ? $cedulaJuridica : 'desconocida';
			$provInfo = $proveedorApi !== '' ? $proveedorApi : 'PROVEEDOR NO IDENTIFICADO';
			throw new Exception('Proveedor sin plantilla de parseo (cedula: ' . $cedulaInfo . ', proveedor: ' . $provInfo . ').');
		}

		$parsed = self::parseInvoiceText($text, $sourceName, [
			'clave_cr' => $claveCR,
			'cedula_juridica' => $cedulaJuridica,
			'proveedor_api' => $proveedorApi,
			'template_key' => $templateKey,
		]);

		$resolvedByParsedProvider = self::resolveTemplateForCedula($cedulaJuridica, (string) ($parsed['razon_social_emisor'] ?? ''));
		$resolvedByFileName = self::resolveTemplateForCedula($cedulaJuridica, $sourceName);
		$resolvedTemplate = '';
		foreach ([$resolvedByParsedProvider, $resolvedByFileName] as $candidateTemplate) {
			if (
				$candidateTemplate !== ''
				&& $candidateTemplate !== 'generic_cr'
				&& $candidateTemplate !== $templateKey
			) {
				$resolvedTemplate = $candidateTemplate;
				break;
			}
		}
		if ($templateKey === 'generic_cr' && $resolvedTemplate !== '') {
			$templateKey = $resolvedTemplate;
			$parsed = self::parseInvoiceText($text, $sourceName, [
				'clave_cr' => $claveCR,
				'cedula_juridica' => $cedulaJuridica,
				'proveedor_api' => $proveedorApi,
				'template_key' => $templateKey,
			]);
		}

		$hash = hash_file('sha256', $filePath);
		$consecutivo = self::normalizeConsecutivo($parsed['consecutivo_completo'] ?? '');
		$numeroReferencia = (string) ($parsed['numero_referencia'] ?? '');

		$numeroDesdeClave = self::extractNumeroFromClave($consecutivo);
		if ($numeroReferencia === '' && $numeroDesdeClave !== '') {
			$numeroReferencia = $numeroDesdeClave;
		}

		if ($consecutivo === '') {
			$consecutivo = 'PDF-' . substr($hash, 0, 24);
		}

		return [
			'consecutivo_completo' => $consecutivo,
			'numero_factura_asistente' => self::buildNumeroAsistente($numeroReferencia !== '' ? $numeroReferencia : $consecutivo),
			'rfc_emisor' => $parsed['rfc_emisor'],
			'razon_social_emisor' => $parsed['razon_social_emisor'] !== '' ? $parsed['razon_social_emisor'] : 'SIN PROVEEDOR',
			'fecha_emision' => $parsed['fecha_emision'] !== '' ? $parsed['fecha_emision'] : date('Y-m-d'),
			'subtotal' => $parsed['subtotal'],
			'iva' => $parsed['iva'],
			'total' => $parsed['total'],
			'moneda' => $parsed['moneda'] !== '' ? $parsed['moneda'] : 'MXN',
			'tipo_comprobante' => null,
			'hash_documento' => $hash,
			'metadata' => [
				'plantilla_parseo' => $templateKey,
				'cedula_juridica_clave' => $cedulaJuridica,
				'proveedor_api_hacienda' => $proveedorApi,
				'fuente_documento' => $source,
				'ocr_usado' => $ocrUsed,
				'confianza_extraccion' => $ocrUsed ? 70 : 92,
				'requiere_revision_manual' => $ocrUsed ? 1 : 0,
				'texto_preview' => mb_substr(preg_replace('/\s+/', ' ', trim($text)), 0, 500, 'UTF-8')
			]
		];
	}

	private static function parseByTemplate($templateKey, $normalizedText, $claveCR, $documentoCR)
	{
		$template = self::getTemplateDefinition((string) $templateKey);
		if (!is_array($template)) {
			return [
				'fecha_raw' => '',
				'amounts' => self::resolveAmounts($normalizedText),
				'numero_referencia' => '',
			];
		}

		$datePatterns = (array) ($template['date_patterns'] ?? []);
		$fechaMatches = [];
		foreach ($datePatterns as $pattern) {
			$match = self::match((string) $pattern, $normalizedText);
			if ($match !== '') {
				$fechaMatches[] = $match;
			}
		}
		$fechaRaw = self::firstNonEmpty($fechaMatches);

		$lineLabels = (array) ($template['line_labels'] ?? []);
		$heuristicLabels = (array) ($template['heuristic_labels'] ?? $lineLabels);

		$lineAmounts = self::extractAmountsByLineLabels($normalizedText, $lineLabels);
		$heuristicAmounts = self::resolveAmounts($normalizedText, $heuristicLabels);

		$amounts = [
			'subtotal' => array_key_exists('subtotal', $lineAmounts) && $lineAmounts['subtotal'] !== null ? $lineAmounts['subtotal'] : ($heuristicAmounts['subtotal'] ?? 0),
			'iva' => array_key_exists('iva', $lineAmounts) && $lineAmounts['iva'] !== null ? $lineAmounts['iva'] : ($heuristicAmounts['iva'] ?? 0),
			'total' => array_key_exists('total', $lineAmounts) && $lineAmounts['total'] !== null ? $lineAmounts['total'] : ($heuristicAmounts['total'] ?? 0),
		];
		$amounts = self::repairTemplateAmounts((string) $templateKey, $normalizedText, $amounts);

		$priority = (array) ($template['numero_referencia_priority'] ?? ['documento', 'clave']);
		$candidates = [];
		foreach ($priority as $source) {
			if ($source === 'documento') {
				$candidates[] = $documentoCR;
			}
			if ($source === 'clave') {
				$candidates[] = self::extractNumeroFromClave($claveCR);
			}
		}
		$numeroRef = self::firstNonEmpty($candidates);

		return [
			'fecha_raw' => $fechaRaw,
			'amounts' => $amounts,
			'numero_referencia' => $numeroRef,
		];
	}

	private static function resolveTemplateForCedula($cedulaJuridica, $providerName = '')
	{
		$ced = preg_replace('/\D+/', '', (string) $cedulaJuridica);
		$provider = self::normalizeAliasLookupText((string) $providerName);
		$config = self::getTemplateConfig();

		$templates = (array) ($config['provider_template_map'] ?? []);

		if ($ced !== '' && isset($templates[$ced])) {
			return $templates[$ced];
		}

		$aliasMap = (array) ($config['provider_alias_templates'] ?? []);
		if ($provider !== '') {
			foreach ($aliasMap as $templateKey => $aliases) {
				foreach ((array) $aliases as $alias) {
					$aliasText = self::normalizeAliasLookupText((string) $alias);
					if ($aliasText !== '' && strpos($provider, $aliasText) !== false) {
						return (string) $templateKey;
					}
				}
			}
		}

		return (string) ($config['default_template'] ?? 'generic_cr');
	}

	private static function normalizeAliasLookupText($text)
	{
		$value = mb_strtoupper(trim((string) $text), 'UTF-8');
		if ($value === '') {
			return '';
		}

		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
		if (is_string($ascii) && $ascii !== '') {
			$value = $ascii;
		}

		$value = preg_replace('/[^A-Z0-9]+/u', ' ', $value);
		$value = preg_replace('/\s+/', ' ', (string) $value);
		return trim((string) $value);
	}

	private static function isTemplateConfigured($templateKey)
	{
		$key = trim((string) $templateKey);
		if ($key === '' || $key === 'generic_cr') {
			return false;
		}

		$template = self::getTemplateDefinition($key);
		return is_array($template) && (($template['enabled'] ?? true) === true);
	}

	private static function getTemplateConfig()
	{
		static $config = null;
		if ($config !== null) {
			return $config;
		}

		$file = __DIR__ . '/../config/pdf_templates.php';
		if (!is_file($file)) {
			$config = [];
			return $config;
		}

		$data = require $file;
		$config = is_array($data) ? $data : [];
		return $config;
	}

	private static function getTemplateDefinition($templateKey)
	{
		$key = trim((string) $templateKey);
		if ($key === '') {
			return null;
		}

		$config = self::getTemplateConfig();
		$templates = (array) ($config['templates'] ?? []);
		return isset($templates[$key]) && is_array($templates[$key]) ? $templates[$key] : null;
	}

	private static function extractTextFromPdf($filePath)
	{
		self::extendExecutionWindow();

		$escaped = escapeshellarg($filePath);

		// Extrae texto sin guardar archivos intermedios.
		$output = self::runCommand("pdftotext -enc UTF-8 -layout {$escaped} -");
		if (self::isUsefulText($output)) {
			return $output;
		}

		return '';
	}

	private static function extractTextViaOcr($filePath, $maxPages, $language)
	{
		self::extendExecutionWindow();

		$tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'xmlconcilia_ocr_' . bin2hex(random_bytes(5));
		if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
			return '';
		}

		$text = '';
		$escapedPdf = escapeshellarg($filePath);
		$prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
		$escapedPrefix = escapeshellarg($prefix);

		try {
			$pages = max(1, (int) $maxPages);
			$cmd = "pdftoppm -f 1 -l {$pages} -r 220 -png {$escapedPdf} {$escapedPrefix}";
			self::runCommand($cmd);

			$images = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.png') ?: [];
			sort($images);

			foreach ($images as $imgPath) {
				self::extendExecutionWindow();

				$escapedImg = escapeshellarg($imgPath);
				$escapedLang = escapeshellarg($language);
				$ocrText = self::runCommand("tesseract {$escapedImg} stdout -l {$escapedLang} --psm 6");
				if ($ocrText !== '') {
					$text .= "\n" . $ocrText;
				}
			}
		} catch (Throwable $e) {
			// Si falla OCR devolvemos vacÃ­o para que el flujo reporte error claro.
			$text = '';
		}

		foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $tmpFile) {
			@unlink($tmpFile);
		}
		@rmdir($tmpDir);

		return $text;
	}

	private static function parseInvoiceText($text, $fileName = '', array $context = [])
	{
		$normalized = self::normalizeText($text);
		$fileNameInfo = self::extractReferencesFromFileName($fileName);
		$templateKey = (string) ($context['template_key'] ?? 'generic_cr');
		$proveedorApi = trim((string) ($context['proveedor_api'] ?? ''));

		// --- Formato costarricense ---
		// Clave: 50 dÃ­gitos numÃ©ricos
		$claveCR = trim((string) ($context['clave_cr'] ?? ''));
		if ($claveCR === '') {
			$claveCR = self::extractClaveCR($normalized);
		}
		// Documento No / NÃºmero de documento: patrÃ³n XXXXXXXXXX-XXXXXXXXXXXXXXXXXXXXX
		$documentoCR = self::extractDocumentoCR($normalized, $claveCR);
		$cedulaJuridica = trim((string) ($context['cedula_juridica'] ?? ''));
		if ($cedulaJuridica === '') {
			$cedulaJuridica = self::extractCedulaFromClave($claveCR);
		}

		$templateParsed = self::parseByTemplate($templateKey, $normalized, $claveCR, $documentoCR);

		// --- Formato CFDI mexicano ---
		$uuid = self::match('/\b([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})\b/i', $normalized);

		// Folio genÃ©rico (Ãºltimo recurso, excluye palabras clave de encabezado)
		$folio = self::firstNonEmpty([
			self::match('/\b(?:Folio|No\.?\s*Factura|Numero\s*Factura|Comprobante)\s*[:#\-]?\s*([A-Z0-9\-]{4,40})/iu', $normalized),
			''
		]);

		// RFC/CÃ©dula: formato mexicano (XYYY000000XX) o costarricense (solo dÃ­gitos 9-12 chars)
		$rfc = self::firstNonEmpty([
			self::match('/\b([A-Z&Ã‘]{3,4}\d{6}[A-Z0-9]{3})\b/iu', mb_strtoupper($normalized, 'UTF-8')),
			$cedulaJuridica,
			self::match('/\bC[eÃ©]dula\s*[:\-]?\s*(\d{9,12})\b/iu', $normalized),
			''
		]);

		// RazÃ³n social: primera lÃ­nea significativa del texto (antes de "Cedula" o "DirecciÃ³n")
		$razon = self::firstNonEmpty([
			$proveedorApi,
			self::match('/\b(INSTITUTO\s+COSTARRICENSE\s+DE\s+ELECTRICIDAD)\b/iu', $normalized),
			self::extractSimplifiedProviderName($normalized),
			self::match('/(?:Razon\s*Social|Raz[oÃ³]n\s*Social|Emisor|Proveedor)\s*[:\-]\s*([^\n\r]{3,120})/iu', $normalized),
			// Para CR: primera lÃ­nea no vacÃ­a antes de "Cedula"
			self::match('/^([A-ZÃÃ‰ÃÃ“ÃšÃ‘][^\n\r]{3,80})\s*\n[^\n\r]*C[eÃ©]dula/imsu', $normalized),
			self::extractProviderFromText($normalized),
			($fileNameInfo['proveedor'] ?? ''),
			''
		]);

		$fechaRaw = self::firstNonEmpty([
			(string) ($templateParsed['fecha_raw'] ?? ''),
			self::match('/(?:Fecha\s*(?:de\s*)?Emision|Fecha)\s*[:\-]?\s*([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{4})/iu', $normalized),
			self::match('/\bFecha\b[^\n]{0,30}?\b([0-9]{1,2}[\/\-][0-9]{1,2}[\/\-][0-9]{4})/iu', $normalized),
			self::match('/\b([0-3]?\d\s+(?:ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\s+20\d{2})\b/iu', $normalized),
			self::match('/\b(20\d{2}[\-\/][01]?\d[\-\/][0-3]?\d)\b/u', $normalized),
			self::match('/\b([0-3]?\d[\/\-][01]?\d[\/\-]20\d{2})\b/u', $normalized),
		]);

		$amounts = is_array($templateParsed['amounts'] ?? null) ? $templateParsed['amounts'] : self::resolveAmounts($normalized);
		$subtotal = $amounts['subtotal'];
		$iva = $amounts['iva'];
		$total = $amounts['total'];

		if ($subtotal <= 0 && $total > 0 && $iva > 0) {
			$subtotal = max(0, $total - $iva);
		}

		$moneda = self::firstNonEmpty([
			self::match('/\b(MXN|USD|EUR|CRC)\b/u', mb_strtoupper($normalized, 'UTF-8')),
			'MXN'
		]);

		return [
			// Prioridad: clave CR (50 dÃ­gitos) > UUID CFDI > documento CR > folio genÃ©rico
			'consecutivo_completo' => $claveCR !== ''
				? $claveCR
				: ($uuid !== ''
					? strtoupper($uuid)
					: ($documentoCR !== ''
						? $documentoCR
						: ($folio !== '' ? $folio : ($fileNameInfo['consecutivo'] ?? '')))),
			// NÃºmero de referencia para asistente: documento CR > folio > uuid > clave
			'numero_referencia' => (($templateParsed['numero_referencia'] ?? '') !== '')
				? (string) $templateParsed['numero_referencia']
				: ($documentoCR !== ''
				? $documentoCR
				: ($folio !== ''
					? $folio
					: ($uuid !== '' ? $uuid : (($fileNameInfo['numero'] ?? '') !== '' ? $fileNameInfo['numero'] : $claveCR)))),
			'rfc_emisor' => self::normalizeRfc($rfc),
			'razon_social_emisor' => trim($razon),
			'fecha_emision' => self::normalizeDate($fechaRaw),
			'subtotal' => $subtotal,
			'iva' => $iva,
			'total' => $total,
			'moneda' => $moneda,
		];
	}

	private static function resolveAmounts($text, array $labelSets = [])
	{
		$subtotalLabels = $labelSets['subtotal'] ?? ['Sub\s*[Tt]otal', 'Base\s*Imponible', 'Monto\s*Neto', 'Total\s*Gravado'];
		$ivaLabels = $labelSets['iva'] ?? ['I\.?\s*V\.?\s*A\.?', 'IVA', 'Impuesto(?:\s*de)?\s*Venta', 'IGV', 'VAT', 'Tax'];
		$totalLabels = $labelSets['total'] ?? ['Total\s*a\s*pagar', 'Importe\s*Total', 'Monto\s*Total', 'Total\s*Comprobante', '(?<![a-zA-Z])Total(?!\s*a\s*pagar)(?![a-zA-Z])'];

		$subtotalCandidates = self::extractAmountCandidatesByLabels($text, $subtotalLabels);
		$ivaCandidates = self::extractAmountCandidatesByLabels($text, $ivaLabels);
		$totalCandidates = self::extractAmountCandidatesByLabels($text, $totalLabels);
		$allAmounts = self::extractAllAmounts($text);

		$subPool = self::uniqueAmounts(array_merge($subtotalCandidates, $allAmounts));
		$ivaPool = self::uniqueAmounts(array_merge($ivaCandidates, [0.0], $allAmounts));
		$totalPool = self::uniqueAmounts(array_merge($totalCandidates, $allAmounts));

		$best = ['subtotal' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'score' => -INF];

		foreach (array_slice($subPool, 0, 40) as $s) {
			if ($s < 0) {
				continue;
			}
			foreach (array_slice($ivaPool, 0, 40) as $i) {
				if ($i < 0) {
					continue;
				}
				foreach (array_slice($totalPool, 0, 40) as $t) {
					if ($t < 0 || $t + 0.01 < $s) {
						continue;
					}

					$delta = abs(($s + $i) - $t);
					$tol = max(1.0, $t * 0.02);
					$score = 0.0;

					if ($delta <= $tol) {
						$score += 100.0 - (($delta / $tol) * 20.0);
					} else {
						$score -= min(80.0, $delta);
					}

					if (self::containsApprox($subtotalCandidates, $s)) {
						$score += 25.0;
					}
					if (self::containsApprox($ivaCandidates, $i)) {
						$score += 25.0;
					}
					if (self::containsApprox($totalCandidates, $t)) {
						$score += 30.0;
					}

					if ($t >= max(1.0, $s)) {
						$score += 5.0;
					}

					if ($score > $best['score']) {
						$best = ['subtotal' => $s, 'iva' => $i, 'total' => $t, 'score' => $score];
					}
				}
			}
		}

		$subtotal = $best['subtotal'];
		$iva = $best['iva'];
		$total = $best['total'];

		if ($total <= 0) {
			$total = self::firstNonZero([$totalCandidates, $allAmounts]);
		}
		if ($subtotal <= 0) {
			$subtotal = self::firstNonZero([$subtotalCandidates]);
		}
		if ($iva <= 0) {
			$iva = self::firstNonZero([$ivaCandidates]);
		}

		if ($subtotal > 0 && $total > 0 && $iva <= 0 && $total >= $subtotal) {
			$iva = round($total - $subtotal, 2);
		}
		if ($total > 0 && $iva > 0 && $subtotal <= 0 && $total >= $iva) {
			$subtotal = round($total - $iva, 2);
		}
		if ($subtotal > 0 && $iva > 0 && $total <= 0) {
			$total = round($subtotal + $iva, 2);
		}

		return [
			'subtotal' => round(max(0.0, (float) $subtotal), 2),
			'iva' => round(max(0.0, (float) $iva), 2),
			'total' => round(max(0.0, (float) $total), 2),
		];
	}

	private static function repairTemplateAmounts($templateKey, $text, array $amounts)
	{
		if (in_array((string) $templateKey, ['cr_sabores_coto_brus', 'cr_roma_del_sur', 'cr_cindy_retana_mora'], true)) {
			$tail = trim((string) preg_replace('/.*Version\s*4\.4/isu', '', (string) $text));
			if (preg_match('/([0-9][0-9\.,]{1,20})\s+((?:[0-9][0-9\.,]{1,20}|[.,]0{2}))\s+([0-9][0-9\.,]{1,20})\s*$/u', $tail, $m)) {
				$subtotal = self::parseAmount($m[1]);
				$iva = self::parseAmount($m[2]);
				$total = self::parseAmount($m[3]);
				if ($total > 0 && $subtotal > 0 && abs(($subtotal + $iva) - $total) <= 1.0) {
					return [
						'subtotal' => round(max(0.0, $subtotal), 2),
						'iva' => round(max(0.0, $iva), 2),
						'total' => round(max(0.0, $total), 2),
					];
				}
			}

			return $amounts;
		}

		if ((string) $templateKey === 'cr_compania_carragonza') {
			$subtotal = 0.0;
			$total = 0.0;
			$iva = 0.0;

			if (preg_match('/VENTA\s*NETA\s*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mSubtotal)) {
				$subtotal = self::parseAmount($mSubtotal[1] ?? '');
			}
			if (preg_match('/TOTAL\s*CRC\s*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mTotal)) {
				$total = self::parseAmount($mTotal[1] ?? '');
			}
			if (preg_match('/(?:\bIVA\b|Total\s*Impuesto)\s*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mIva)) {
				$iva = self::parseAmount($mIva[1] ?? '');
			}

			if ($subtotal > 0 && $total > 0 && abs($subtotal - $total) <= 1.0) {
				return [
					'subtotal' => round($subtotal, 2),
					'iva' => round(max(0.0, $iva), 2),
					'total' => round($total, 2),
				];
			}

			return $amounts;
		}

		if ((string) $templateKey === 'cr_maria_jose_fonseca_sibaja') {
			$subtotal = 0.0;
			$iva = 0.0;
			$total = 0.0;

			if (preg_match('/\bSubtotal\s*:\s*[^\d\r\n]{0,8}([0-9][0-9\.,]{1,20})/iu', (string) $text, $mSubtotal)) {
				$subtotal = self::parseAmount($mSubtotal[1] ?? '');
			}
			if (preg_match('/\bIVA\s*:\s*[^\d\r\n]{0,8}((?:[0-9][0-9\.,]{1,20}|[.,]0{2}))/iu', (string) $text, $mIva)) {
				$iva = self::parseAmount($mIva[1] ?? '');
			}
			if (preg_match('/Total\s*Factura\s*Electr[\s\S]{0,30}?:\s*[\sÂ¢$]*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mTotal)) {
				$total = self::parseAmount($mTotal[1] ?? '');
			}

			if ($subtotal > 0 && $total > 0) {
				return [
					'subtotal' => round($subtotal, 2),
					'iva' => round(max(0.0, $iva), 2),
					'total' => round($total, 2),
				];
			}

			return $amounts;
		}

		if ((string) $templateKey === 'cr_corporacion_alfaro_mano_de_dios') {
			$subtotal = 0.0;
			$iva = 0.0;
			$total = 0.0;
			$ivaRaw = '';

			if (preg_match('/Total\\s*Venta\\s*Neta\\s*[Â¢$\\s]*([0-9][0-9\\.,]{1,20})/iu', (string) $text, $mSubtotal)) {
				$subtotal = self::parseAmount($mSubtotal[1] ?? '');
			}
			if (preg_match('/Total\\s*Impuestos\\s*[Â¢$\\s]*([0-9][0-9\\.,]{1,20})/iu', (string) $text, $mIva)) {
				$ivaRaw = trim((string) ($mIva[1] ?? ''));
				$iva = self::parseAmount($ivaRaw);
			}
			if (preg_match('/Total\\s*Comprobante\\s*[Â¢$\\s]*([0-9][0-9\\.,]{1,20})/iu', (string) $text, $mTotal)) {
				$total = self::parseAmount($mTotal[1] ?? '');
			}
			if (($iva <= 0 || ($total > 0 && $iva >= $total)) && preg_match('/IVA\\s*13%[\\s\\S]{0,30}?[Â¢$\\s]*([0-9][0-9\\.,]{1,20})/iu', (string) $text, $mIva13)) {
				$iva = self::parseAmount($mIva13[1] ?? '');
			}
			if (($iva <= 0 || ($total > 0 && $iva >= $total)) && $ivaRaw !== '' && preg_match('/^([0-9]+)[\\.,]([0-9]{2})[0-9]+$/', $ivaRaw, $mIvaShort)) {
				$iva = (float) ($mIvaShort[1] . '.' . $mIvaShort[2]);
			}

			if ($subtotal > 0 && $total > 0) {
				return [
					'subtotal' => round($subtotal, 2),
					'iva' => round(max(0.0, $iva), 2),
					'total' => round($total, 2),
				];
			}

			return $amounts;
		}

		// BM-family stores: asterisk-delimited summary lines
		// Format: SUBTOTAL *******4,726.38 / DESCUENTO *********818.58 / IVA *********452.71 / TOTAL *******4,360.50
		$bmTemplates = [
			'cr_bm_del_general', 'cr_bm_del_palmar', 'cr_bm_quepos',
			'cr_bm_uvita', 'cr_comercial_corcovado_del_sur',
			'cr_surbm', 'cr_arrendadora_bm_pz',
		];
		if (in_array((string) $templateKey, $bmTemplates, true)) {
			$iva = 0.0;
			$total = 0.0;

			// IVA seguido de asteriscos y monto (evita columna IVA de tabla de productos)
			if (preg_match('/(?:^|\n)\s*IVA\s*\*+\s*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mIva)) {
				$iva = self::parseAmount($mIva[1] ?? '');
			}
			// TOTAL seguido de asteriscos (distinto de columna TOTAL de productos)
			if (preg_match('/(?:^|\n)\s*TOTAL\s*\*+\s*([0-9][0-9\.,]{1,20})/iu', (string) $text, $mTotal)) {
				$total = self::parseAmount($mTotal[1] ?? '');
			}

			if ($total > 0) {
				// SUBTOTAL en BM es pre-descuento; la base real es total - iva.
				$subtotal = round(max(0.0, $total - $iva), 2);
				return [
					'subtotal' => $subtotal,
					'iva' => round(max(0.0, $iva), 2),
					'total' => round($total, 2),
				];
			}

			return $amounts;
		}

		if ((string) $templateKey !== 'cr_ice') {
			return $amounts;
		}

		$total = (float) ($amounts['total'] ?? 0);
		$subtotal = (float) ($amounts['subtotal'] ?? 0);
		$iva = (float) ($amounts['iva'] ?? 0);

		if ($total <= 0) {
			return $amounts;
		}

		if ($iva <= 0 || $iva < 100 || $iva >= $total || $subtotal >= $total) {
			$inferredIva = self::inferVatFromAmounts($text, $total);
			if ($inferredIva > 0 && $inferredIva < $total) {
				$iva = $inferredIva;
			}
		}

		if ($iva > 0 && ($subtotal <= 0 || $subtotal >= $total)) {
			$subtotal = round(max(0.0, $total - $iva), 2);
		}

		$amounts['subtotal'] = round(max(0.0, $subtotal), 2);
		$amounts['iva'] = round(max(0.0, $iva), 2);
		$amounts['total'] = round(max(0.0, $total), 2);

		return $amounts;
	}

	private static function inferVatFromAmounts($text, $total = 0.0)
	{
		$amounts = self::extractAllAmounts($text);
		$best = 0.0;

		foreach ($amounts as $candidate) {
			$candidate = (float) $candidate;
			if ($candidate <= 0 || ($total > 0 && $candidate >= $total)) {
				continue;
			}

			foreach ($amounts as $base) {
				$base = (float) $base;
				if ($base <= $candidate || ($total > 0 && $base >= $total)) {
					continue;
				}

				if (abs(round($base * 0.13, 2) - $candidate) <= 1.0) {
					$best = max($best, $candidate);
					break;
				}
			}
		}

		return round($best, 2);
	}

	private static function extractAmountByLabels($text, array $labels)
	{
		foreach ($labels as $label) {
			// \s*[:\-]? permite etiquetas sin dos puntos seguidas de nÃºmero en misma lÃ­nea
			$pattern = '/(?:' . $label . ')[^\S\n]*[:\-]?[^\S\n]*\$?[^\S\n]*([0-9][0-9\.,]{0,20})/iu';
			$value = self::match($pattern, $text);
			if ($value !== '') {
				return self::parseAmount($value);
			}
		}

		return 0.0;
	}

	private static function extractAmountCandidatesByLabels($text, array $labels)
	{
		$candidates = [];

		foreach ($labels as $label) {
			$patternAfter = '/(?:' . $label . ')[^\n\r]{0,60}?([0-9][0-9\.,\s]{0,25})/iu';
			if (preg_match_all($patternAfter, (string) $text, $matchesAfter)) {
				foreach (($matchesAfter[1] ?? []) as $raw) {
					$val = self::parseAmount($raw);
					if ($val > 0 && $val < 1000000000) {
						$candidates[] = $val;
					}
				}
			}

			$patternBefore = '/([0-9][0-9\.,\s]{0,25})[^\n\r]{0,30}?(?:' . $label . ')/iu';
			if (preg_match_all($patternBefore, (string) $text, $matchesBefore)) {
				foreach (($matchesBefore[1] ?? []) as $raw) {
					$val = self::parseAmount($raw);
					if ($val > 0 && $val < 1000000000) {
						$candidates[] = $val;
					}
				}
			}
		}

		return self::uniqueAmounts($candidates);
	}

	private static function extractAmountsByLineLabels($text, array $labelSets)
	{
		return [
			'subtotal' => self::extractAmountFromLabelLines($text, (array) ($labelSets['subtotal'] ?? [])),
			'iva' => self::extractAmountFromLabelLines($text, (array) ($labelSets['iva'] ?? [])),
			'total' => self::extractAmountFromLabelLines($text, (array) ($labelSets['total'] ?? [])),
		];
	}

	private static function extractAmountFromLabelLines($text, array $labels)
	{
		$lines = preg_split('/\r?\n/', (string) $text) ?: [];
		$matches = [];
		$zeroFound = false;
		$moneyPattern = '/(?<!\d)(?:\d{1,3}(?:[ \x{00A0}\.,]\d{3})+(?:[\.,]\d{2})|\d+(?:[\.,]\d{2})|[\.,]0{2})(?!\d)/u';

		foreach ($lines as $line) {
			$lineTxt = trim((string) $line);
			if ($lineTxt === '') {
				continue;
			}

			$labelFound = false;
			foreach ($labels as $label) {
				if (preg_match('/' . $label . '/iu', $lineTxt)) {
					$labelFound = true;
					break;
				}
			}
			if (!$labelFound) {
				continue;
			}

			if (!preg_match_all($moneyPattern, $lineTxt, $m)) {
				continue;
			}

			foreach (($m[0] ?? []) as $token) {
				$amount = self::parseAmount($token);
				if ($amount > 0 && self::isLikelyMoney($token, $amount)) {
					$matches[] = $amount;
				} elseif ($amount == 0.0 && preg_match('/^(?:0+(?:[\.,]0{2})|[\.,]0{2})$/', trim((string) $token))) {
					$zeroFound = true;
				}
			}
		}

		if (empty($matches)) {
			return $zeroFound ? 0.0 : null;
		}

		rsort($matches, SORT_NUMERIC);
		return (float) $matches[0];
	}

	private static function extractAllAmounts($text)
	{
		$pattern = '/(?<!\d)(?:\d{1,3}(?:[\.,]\d{3})+|\d+)(?:[\.,]\d{2})?(?!\d)/u';
		if (!preg_match_all($pattern, (string) $text, $m)) {
			return [];
		}

		$out = [];
		foreach (($m[0] ?? []) as $raw) {
			$val = self::parseAmount($raw);
			if (!self::isLikelyMoney($raw, $val)) {
				continue;
			}
			if ($val > 0 && $val < 1000000000) {
				$out[] = $val;
			}
		}

		return self::uniqueAmounts($out);
	}

	private static function parseAmount($value)
	{
		$raw = trim((string) $value);
		if ($raw === '') {
			return 0.0;
		}

		$raw = str_replace(["\xc2\xa0", ' ', '$', 'Â¢', 'CRC', 'USD', 'EUR', 'MXN'], '', $raw);
		$raw = preg_replace('/[^0-9,\.\-]/u', '', $raw);
		if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
			return 0.0;
		}

		$commaPos = strrpos($raw, ',');
		$dotPos = strrpos($raw, '.');

		if ($commaPos !== false && $dotPos !== false) {
			if ($commaPos > $dotPos) {
				// 1.234,56 -> decimal es coma
				$raw = str_replace('.', '', $raw);
				$raw = str_replace(',', '.', $raw);
			} else {
				// 1,234.56 -> decimal es punto
				$raw = str_replace(',', '', $raw);
			}
		} elseif ($commaPos !== false) {
			$parts = explode(',', $raw);
			$decLen = strlen((string) end($parts));
			if ($decLen === 2) {
				$raw = str_replace(',', '.', $raw);
			} else {
				$raw = str_replace(',', '', $raw);
			}
		} elseif ($dotPos !== false) {
			$parts = explode('.', $raw);
			$decLen = strlen((string) end($parts));
			if ($decLen !== 2) {
				$raw = str_replace('.', '', $raw);
			}
		}

		return round((float) $raw, 2);
	}

	private static function isLikelyMoney($rawToken, $parsed)
	{
		$raw = trim((string) $rawToken);
		$val = (float) $parsed;
		if ($val <= 0) {
			return false;
		}

		$digitsOnly = preg_replace('/\D+/', '', $raw);
		$hasDecimal2 = (bool) preg_match('/[\.,]\d{2}\b/', $raw);
		if (strlen((string) $digitsOnly) === 4 && !$hasDecimal2 && $val >= 1900 && $val <= 2100) {
			return false;
		}

		// Evita aÃ±os con formato de miles/decimales: 2,025 o 2,025.00 / 2.025,00
		if (
			preg_match('/^\s*[12][\.,]?\d{3}(?:[\.,]00)?\s*$/', $raw) &&
			$val >= 1900 && $val <= 2100
		) {
			return false;
		}

		// Evita aÃ±os como 2025/2026 tomados como montos.
		if (preg_match('/^\d{4}$/', $raw) && $val >= 1900 && $val <= 2100) {
			return false;
		}

		// Para enteros sin separador decimal/miles y <= 4 dÃ­gitos, suele ser cÃ³digo/folio, no dinero.
		$hasSeparator = (strpos($raw, ',') !== false || strpos($raw, '.') !== false);
		if (!$hasSeparator && preg_match('/^\d{1,4}$/', $raw)) {
			return false;
		}

		return true;
	}

	private static function uniqueAmounts(array $values)
	{
		$clean = [];
		foreach ($values as $value) {
			$v = round((float) $value, 2);
			if ($v > 0) {
				$clean[] = $v;
			}
		}

		$clean = array_values(array_unique($clean));
		rsort($clean, SORT_NUMERIC);
		return $clean;
	}

	private static function containsApprox(array $values, $target, $eps = 0.02)
	{
		foreach ($values as $value) {
			if (abs(((float) $value) - ((float) $target)) <= $eps) {
				return true;
			}
		}

		return false;
	}

	private static function firstNonZero(array $lists)
	{
		foreach ($lists as $list) {
			foreach ((array) $list as $value) {
				$v = (float) $value;
				if ($v > 0) {
					return $v;
				}
			}
		}

		return 0.0;
	}

	private static function normalizeRfc($rfc)
	{
		$rfc = mb_strtoupper(trim((string) $rfc), 'UTF-8');
		$rfc = preg_replace('/[^A-Z0-9Ã‘&]/u', '', $rfc);
		return $rfc;
	}

	private static function normalizeDate($text)
	{
		$value = trim((string) $text);
		if ($value === '') {
			return '';
		}

		$spanishMonth = self::parseSpanishMonthDate($value);
		if ($spanishMonth !== '') {
			return $spanishMonth;
		}

		$spanishShortMonth = self::parseSpanishShortMonthDate($value);
		if ($spanishShortMonth !== '') {
			return $spanishShortMonth;
		}

		$spanishMonthFirst = self::parseSpanishMonthNameFirstDate($value);
		if ($spanishMonthFirst !== '') {
			return $spanishMonthFirst;
		}

		// Eliminar parte de hora si viene con timestamp: "14/08/2025 05:50:00" â†’ "14/08/2025"
		$value = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?\s*(?:A\.?M\.?|P\.?M\.?)?$/iu', '', trim($value));
		$value = preg_replace('/^(20\d{2}[\-\/][01]?\d[\-\/][0-3]?\d)T.*$/u', '$1', $value);

		$formats = ['Y-m-d', 'Y/m/d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'j.n.Y', 'd/m/y', 'j/n/y', 'd-m-y', 'j-n-y', 'd.m.y', 'j.n.y', 'm/d/Y', 'n/j/Y', 'm-d-Y', 'n-j-Y', 'm/d/y', 'n/j/y', 'm-d-y', 'n-j-y'];
		foreach ($formats as $format) {
			$date = DateTime::createFromFormat($format, $value);
			if ($date && $date->format($format) === $value) {
				return $date->format('Y-m-d');
			}
		}

		try {
			return (new DateTime($value))->format('Y-m-d');
		} catch (Throwable $e) {
			return '';
		}
	}

	private static function parseSpanishMonthDate($text)
	{
		$value = mb_strtoupper(trim((string) $text), 'UTF-8');
		$value = str_replace(['Ã', 'Ã‰', 'Ã', 'Ã“', 'Ãš'], ['A', 'E', 'I', 'O', 'U'], $value);

		if (!preg_match('/\b([0-3]?\d)\s+(ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\s+(20\d{2})\b/u', $value, $m)) {
			return '';
		}

		$day = str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT);
		$monthMap = [
			'ENERO' => '01',
			'FEBRERO' => '02',
			'MARZO' => '03',
			'ABRIL' => '04',
			'MAYO' => '05',
			'JUNIO' => '06',
			'JULIO' => '07',
			'AGOSTO' => '08',
			'SETIEMBRE' => '09',
			'SEPTIEMBRE' => '09',
			'OCTUBRE' => '10',
			'NOVIEMBRE' => '11',
			'DICIEMBRE' => '12',
		];
		$month = $monthMap[$m[2]] ?? '';
		$year = $m[3];

		if ($month === '') {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private static function parseSpanishMonthNameFirstDate($text)
	{
		$value = mb_strtoupper(trim((string) $text), 'UTF-8');
		$value = str_replace(['ÃƒÂ', 'Ãƒâ€°', 'ÃƒÂ', 'Ãƒâ€œ', 'ÃƒÅ¡'], ['A', 'E', 'I', 'O', 'U'], $value);

		if (!preg_match('/\b(ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SETIEMBRE|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\s+([0-3]?\d),?\s+(20\d{2})\b/u', $value, $m)) {
			return '';
		}

		$monthMap = [
			'ENERO' => '01',
			'FEBRERO' => '02',
			'MARZO' => '03',
			'ABRIL' => '04',
			'MAYO' => '05',
			'JUNIO' => '06',
			'JULIO' => '07',
			'AGOSTO' => '08',
			'SETIEMBRE' => '09',
			'SEPTIEMBRE' => '09',
			'OCTUBRE' => '10',
			'NOVIEMBRE' => '11',
			'DICIEMBRE' => '12',
		];

		$month = $monthMap[$m[1]] ?? '';
		$day = str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT);
		$year = $m[3];

		if ($month === '') {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private static function parseSpanishShortMonthDate($text)
	{
		$value = mb_strtoupper(trim((string) $text), 'UTF-8');
		$value = str_replace(['ÃƒÂ', 'Ãƒâ€°', 'ÃƒÂ', 'Ãƒâ€œ', 'ÃƒÅ¡', 'ÃƒÆ’Ã‚Â', 'ÃƒÆ’Ã¢â‚¬Â°', 'ÃƒÆ’Ã‚Â', 'ÃƒÆ’Ã¢â‚¬Å“', 'ÃƒÆ’Ã…Â¡'], ['A', 'E', 'I', 'O', 'U', 'A', 'E', 'I', 'O', 'U'], $value);

		if (!preg_match('/\b([0-3]?\d)\s*(?:[\/\-]|\s)\s*(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SET|SEP|OCT|NOV|DIC)\.?\s*(?:[\/\-]|\s)\s*(20\d{2})\b/u', $value, $m)) {
			return '';
		}

		$monthMap = [
			'ENE' => '01',
			'FEB' => '02',
			'MAR' => '03',
			'ABR' => '04',
			'MAY' => '05',
			'JUN' => '06',
			'JUL' => '07',
			'AGO' => '08',
			'SET' => '09',
			'SEP' => '09',
			'OCT' => '10',
			'NOV' => '11',
			'DIC' => '12',
		];

		$day = str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT);
		$month = $monthMap[$m[2]] ?? '';
		$year = $m[3];

		if ($month === '') {
			return '';
		}

		return $year . '-' . $month . '-' . $day;
	}

	private static function normalizeText($text)
	{
		$txt = (string) $text;
		$txt = str_replace(["\r\n", "\r"], "\n", $txt);
		return trim($txt);
	}

	private static function match($pattern, $subject)
	{
		if (preg_match($pattern, (string) $subject, $m)) {
			return trim((string) ($m[1] ?? ''));
		}

		return '';
	}

	private static function firstNonEmpty(array $values)
	{
		foreach ($values as $value) {
			if (trim((string) $value) !== '') {
				return trim((string) $value);
			}
		}

		return '';
	}

	private static function isUsefulText($text)
	{
		$trimmed = trim((string) $text);
		if ($trimmed === '') {
			return false;
		}

		// Texto Ãºtil mÃ­nimo: longitud y mezcla de letras/nÃºmeros.
		return mb_strlen($trimmed, 'UTF-8') >= 60 && preg_match('/[A-Za-z]/', $trimmed) && preg_match('/\d/', $trimmed);
	}

	private static function buildNumeroAsistente($value)
	{
		$raw = (string) $value;

		// Si es consecutivo de fallback por hash, no inventar nÃºmero desde letras/nÃºmeros del hash.
		if (stripos($raw, 'PDF-') === 0) {
			return '0';
		}

		// Clave CR de 50 dÃ­gitos: estructura 3+2+2+2+12+1+20+8
		// El bloque F (20 dÃ­gitos) es 22-41 en base 1 => Ã­ndice 21 en base 0.
		// Dentro de esos 20: 3(sucursal)+5(terminal)+2(tipo)+10(nÃºmero).
		if (preg_match('/(?:^|\D)(\d{50})(?:\D|$)/', $raw, $m50) ||
			preg_match('/^(\d{50})$/', $raw, $m50)) {
			$consecutivoInterno = substr($m50[1], 21, 20);
			if (strlen($consecutivoInterno) === 20) {
				$numero = substr($consecutivoInterno, 10, 10); // Ãºltimos 10 = nÃºmero puro
				$numero = ltrim($numero, '0');
				return $numero !== '' ? $numero : '0';
			}
		}

		// Documento CR tÃ­pico: 00100001010023894455 -> usar consecutivo final de 8 dÃ­gitos.
		if (preg_match('/\b\d{20}\b/', $raw, $m20)) {
			$numero = substr($m20[0], -10);
			$numero = ltrim($numero, '0');
			return $numero !== '' ? $numero : '0';
		}

		if (preg_match('/\b\d{10}-\d{20}\b/', $raw, $mDoc)) {
			$parts = explode('-', $mDoc[0]);
			if (isset($parts[1]) && preg_match('/^\d{20}$/', $parts[1])) {
				$numero = substr($parts[1], -10);
				$numero = ltrim($numero, '0');
				return $numero !== '' ? $numero : '0';
			}
		}

		// Algunos proveedores incluyen un bloque extra (p.ej. seguridad) a la derecha.
		// En secuencias 21..30 se toma el primer bloque lÃ³gico de 20 dÃ­gitos.
		if (preg_match('/\b(\d{21,30})\b/', $raw, $mLong)) {
			$doc20 = substr($mLong[1], 0, 20);
			$numero = substr($doc20, -10);
			$numero = ltrim($numero, '0');
			return $numero !== '' ? $numero : '0';
		}

		$digits = preg_replace('/\D+/', '', $raw);
		if (strlen((string) $digits) >= 21 && strlen((string) $digits) <= 30) {
			$doc20 = substr($digits, 0, 20);
			$numero = substr($doc20, -10);
			$numero = ltrim($numero, '0');
			return $numero !== '' ? $numero : '0';
		}

		$digits = ltrim($digits, '0');
		if ($digits === '') {
			$digits = preg_replace('/[^A-Za-z0-9]/', '', $raw);
		}

		if ($digits === '') {
			$digits = '0';
		}

		return substr($digits, -10);
	}

	private static function extractClaveCR($text)
	{
		$subject = (string) $text;

		// 1) Clave junto a la etiqueta, permitiendo separadores/espacios/saltos entre dÃ­gitos.
		if (preg_match('/Clave\s*[:\-]?\s*([0-9\s\-]{55,120})/iu', $subject, $mLabel)) {
			$digits = preg_replace('/\D+/', '', (string) ($mLabel[1] ?? ''));
			if (strlen($digits) >= 50) {
				return substr($digits, 0, 50);
			}
		}

		// 2) Cualquier secuencia de 50 dÃ­gitos continuos en el texto.
		if (preg_match('/\b(\d{50})\b/u', $subject, $m50)) {
			return (string) $m50[1];
		}

		// 3) Secuencias largas con separadores, luego normalizar y buscar bloque de 50.
		if (preg_match_all('/[0-9][0-9\s\-]{49,160}/u', $subject, $mSeq)) {
			foreach (($mSeq[0] ?? []) as $candidate) {
				$digits = preg_replace('/\D+/', '', (string) $candidate);
				if (strlen($digits) >= 50) {
					return substr($digits, 0, 50);
				}
			}
		}

		return '';
	}

	private static function normalizeConsecutivo($value)
	{
		$raw = trim((string) $value);
		if ($raw === '') {
			return '';
		}

		$digits = preg_replace('/\D+/', '', $raw);

		// Para clave CR, algunos OCR agregan 1 dÃ­gito extra al final. Normalizamos a 50.
		if (strlen($digits) >= 50 && strpos($digits, '506') === 0) {
			return substr($digits, 0, 50);
		}

		// Si no parece clave CR, conservar valor original.
		return $raw;
	}

	private static function extractDocumentoCR($text, $claveCR = '')
	{
		$subject = (string) $text;
		$docHash = self::match('/#\s*(\d{20})\b/u', $subject);
		if ($docHash !== '') {
			$numero = substr($docHash, -10);
			$numero = ltrim($numero, '0');
			return $numero !== '' ? $numero : '0';
		}


		// 1) Documento No explÃ­cito.
		$doc = self::match('/(?:Documento\s*No|N[uÃº]mero\s*(?:de\s*)?documento)\s*[:\-]?\s*([A-Z0-9\-]{10,60})/iu', $subject);
		if ($doc !== '') {
			$digitsDoc = preg_replace('/\D+/', '', (string) $doc);
			if (strlen($digitsDoc) >= 21 && strlen($digitsDoc) <= 30) {
				$doc20 = substr($digitsDoc, 0, 20);
				$numero = substr($doc20, -10);
				$numero = ltrim($numero, '0');
				return $numero !== '' ? $numero : '0';
			}

			if (strlen($digitsDoc) === 20) {
				$numero = substr($digitsDoc, -10);
				$numero = ltrim($numero, '0');
				return $numero !== '' ? $numero : '0';
			}

			return $doc;
		}

		// 2) Si existe clave CR de 50, extraer el nÃºmero consecutivo puro desde bloque F (22-41 base 1).
		if ($claveCR !== '' && preg_match('/^\d{50}$/', $claveCR)) {
			$consecutivoInterno = substr($claveCR, 21, 20); // F block at 0-based offset 21
			if (strlen($consecutivoInterno) === 20) {
				$numeroPuro = substr($consecutivoInterno, 10, 10); // last 10 = nÃºmero
				return ltrim($numeroPuro, '0') ?: $numeroPuro;
			}
		}

		return '';
	}

	private static function extractCedulaFromClave($claveCR)
	{
		$digits = preg_replace('/\D+/', '', (string) $claveCR);
		if (strlen($digits) !== 50) {
			return '';
		}

		// Estructura clave CR: 3+2+2+2+12+1+20+8. CÃ©dula = bloque de 12 desde offset 9.
		$cedula12 = substr($digits, 9, 12);
		$cedula12 = preg_replace('/\D+/', '', (string) $cedula12);
		if (strlen($cedula12) !== 12) {
			return '';
		}

		// API suele usar identificaciÃ³n sin padding.
		$cedula = ltrim($cedula12, '0');
		return $cedula !== '' ? $cedula : $cedula12;
	}

	private static function extractNumeroFromClave($claveCR)
	{
		$digits = preg_replace('/\D+/', '', (string) $claveCR);
		if (strlen($digits) !== 50) {
			return '';
		}

		$consecutivoInterno = substr($digits, 21, 20);
		if (strlen($consecutivoInterno) !== 20) {
			return '';
		}

		$numeroPuro = substr($consecutivoInterno, 10, 10);
		$numeroTrim = ltrim((string) $numeroPuro, '0');
		return $numeroTrim !== '' ? $numeroTrim : (string) $numeroPuro;
	}

	private static function resolveProviderNameByCedula($cedulaJuridica)
	{
		$ced = preg_replace('/\D+/', '', (string) $cedulaJuridica);
		if ($ced === '') {
			return '';
		}

		$cache = self::loadProviderCache();
		if (isset($cache[$ced]['nombre']) && trim((string) $cache[$ced]['nombre']) !== '') {
			return trim((string) $cache[$ced]['nombre']);
		}

		$url = 'https://api.hacienda.go.cr/fe/ae?identificacion=' . rawurlencode($ced);
		$json = self::fetchUrl($url, 6);
		if ($json === '') {
			return '';
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return '';
		}

		$nombre = self::extractProviderNameFromApiPayload($data);
		if ($nombre === '') {
			return '';
		}

		$cache[$ced] = [
			'nombre' => $nombre,
			'actualizado_en' => date('c'),
		];
		self::saveProviderCache($cache);

		return $nombre;
	}

	private static function extractProviderNameFromApiPayload($data)
	{
		if (is_array($data)) {
			foreach (['nombre', 'nombreComercial', 'razonSocial', 'razon_social'] as $key) {
				if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
					return trim($data[$key]);
				}
			}

			foreach ($data as $item) {
				$name = self::extractProviderNameFromApiPayload($item);
				if ($name !== '') {
					return $name;
				}
			}
		}

		return '';
	}

	private static function fetchUrl($url, $timeoutSeconds = 6)
	{
		$timeout = max(2, (int) $timeoutSeconds);

		if (function_exists('curl_init')) {
			$ch = curl_init((string) $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'xmlconcilia-pdf-parser/1.0');
			$resp = curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if (is_string($resp) && $resp !== '' && $status >= 200 && $status < 300) {
				return $resp;
			}
		}

		$ctx = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => $timeout,
				'header' => "User-Agent: xmlconcilia-pdf-parser/1.0\r\n",
			],
		]);
		$resp = @file_get_contents((string) $url, false, $ctx);
		return is_string($resp) ? $resp : '';
	}

	private static function loadProviderCache()
	{
		$path = self::providerCacheFilePath();
		if (!is_file($path)) {
			return [];
		}

		$content = @file_get_contents($path);
		if (!is_string($content) || trim($content) === '') {
			return [];
		}

		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	private static function saveProviderCache(array $cache)
	{
		$path = self::providerCacheFilePath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		@file_put_contents($path, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	}

	private static function providerCacheFilePath()
	{
		$root = dirname(__DIR__, 2);
		return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'proveedores_hacienda.json';
	}

	private static function extractReferencesFromFileName($fileName)
	{
		$name = preg_replace('/\.pdf$/i', '', (string) $fileName);
		$out = ['consecutivo' => '', 'numero' => '', 'proveedor' => ''];

		if (preg_match('/(?<!\d)(\d{50})(?!\d)/', $name, $mClave)) {
			$out['consecutivo'] = $mClave[1];
		}

		if (preg_match('/(?<!\d)(\d{20})(?!\d)/', $name, $mDoc)) {
			$numero = substr($mDoc[1], -10);
			$out['numero'] = ltrim($numero, '0') ?: $numero;
			if ($out['consecutivo'] === '') {
				$out['consecutivo'] = $mDoc[1];
			}
		} elseif (preg_match('/\b(\d{7,12})\b/', $name, $mShort)) {
			$out['numero'] = $mShort[1];
			if ($out['consecutivo'] === '') {
				$out['consecutivo'] = $mShort[1];
			}
		}

		$provider = preg_replace('/[_\-]+/', ' ', $name);
		$provider = preg_replace('/\b\d{4,}\b/u', ' ', $provider);
		$provider = trim(preg_replace('/\s+/', ' ', (string) $provider));
		if (self::isLikelyCompanyName($provider)) {
			$out['proveedor'] = mb_strtoupper($provider, 'UTF-8');
		}

		return $out;
	}

	private static function extractSimplifiedProviderName($text)
	{
		$provider = self::match('/Proveedor\s*:\s*([^\n\r]+)/iu', (string) $text);
		if ($provider === '') {
			return '';
		}

		$provider = preg_replace('/\s+Es\s+Compra\b.*$/iu', '', $provider);
		$provider = preg_replace('/^\d+\s*/u', '', (string) $provider);
		$provider = trim((string) preg_replace('/\s+/', ' ', (string) $provider));

		if (!self::isLikelyCompanyName($provider)) {
			return '';
		}

		return mb_strtoupper($provider, 'UTF-8');
	}

	private static function extractProviderFromText($text)
	{
		$lines = preg_split('/\n+/', (string) $text) ?: [];
		foreach ($lines as $line) {
			$candidate = trim((string) $line);
			if ($candidate === '') {
				continue;
			}
			if (preg_match('/\d{6,}/', $candidate)) {
				continue;
			}
			if (!self::isLikelyCompanyName($candidate)) {
				continue;
			}

			return mb_strtoupper($candidate, 'UTF-8');
		}

		return '';
	}

	private static function isLikelyCompanyName($name)
	{
		$txt = trim((string) $name);
		if ($txt === '' || mb_strlen($txt, 'UTF-8') < 3) {
			return false;
		}

		$upper = mb_strtoupper($txt, 'UTF-8');
		if (preg_match('/\b(FACTURA|ELECTRONICA|CLAVE|FECHA|TOTAL|SUBTOTAL|IVA|DOCUMENTO|CEDULA|DIRECCION|MONEDA|AUTORIZACION|HACIENDA)\b/u', $upper)) {
			return false;
		}

		return (bool) preg_match('/[A-ZÃÃ‰ÃÃ“ÃšÃ‘]/u', $upper);
	}

	private static function runCommand($command)
	{
		self::extendExecutionWindow();

		if (!function_exists('shell_exec')) {
			return '';
		}

		// Redirigimos stderr para poder detectar errores en entornos Windows/Linux.
		$fullCommand = $command . ' 2>&1';
		$output = shell_exec($fullCommand);

		return is_string($output) ? trim($output) : '';
	}

	private static function extendExecutionWindow($seconds = 600)
	{
		$seconds = max(120, (int) $seconds);

		if (function_exists('set_time_limit')) {
			@set_time_limit($seconds);
		}

		@ini_set('max_execution_time', (string) $seconds);
	}
}
}

