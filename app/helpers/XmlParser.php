<?php
/**
 * Helper para parsear archivos XML de facturas
 */

if (!class_exists('XmlInvoiceParser', false)) {
class XmlInvoiceParser
{
	public static function parseCfdiFromFile($filePath)
	{
		if (!file_exists($filePath)) {
			throw new Exception('Archivo XML no encontrado: ' . $filePath);
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_file($filePath);
		if ($xml === false) {
			throw new Exception('No fue posible parsear el XML.');
		}

		// Algunos proveedores (p. ej. vía EDICOM/BusinessMail) envían por correo
		// solo el MensajeHacienda de aceptación + el PDF, sin la FacturaElectronica.
		// Se toma el mensaje como fuente: trae emisor, receptor y total, y de la
		// Clave se derivan consecutivo, fecha y tipo de documento.
		if (stripos($xml->getName(), 'mensaje') !== false) {
			return self::parseMensajeHacienda($xml, $filePath);
		}

		$uuid = self::firstAttrByLocalName($xml, 'TimbreFiscalDigital', 'UUID');
		$clave = self::firstNodeText($xml, 'Clave');
		$numeroConsecutivo = self::firstNodeText($xml, 'NumeroConsecutivo');
		$serie = self::firstAttrByLocalName($xml, 'Comprobante', 'Serie');
		$folio = self::firstAttrByLocalName($xml, 'Comprobante', 'Folio');

		$consecutivo = '';
		if ($numeroConsecutivo !== '') {
			$consecutivo = $numeroConsecutivo;
		} elseif ($uuid !== '') {
			$consecutivo = $uuid;
		} elseif ($clave !== '') {
			$consecutivo = $clave;
		} else {
			$consecutivo = trim($serie . $folio);
		}

		if ($consecutivo === '') {
			$consecutivo = sha1_file($filePath);
		}

		$numeroAsistente = self::buildNumeroAsistente($numeroConsecutivo !== '' ? $numeroConsecutivo : $consecutivo);
		$rfcEmisor = self::getEmisorId($xml);
		$razonSocialEmisor = self::getEmisorNombre($xml);
		$fechaEmision = self::normalizeDate(self::getFechaEmision($xml));
		$subtotal = self::getSubtotal($xml);
		$iva = self::getIva($xml);
		$total = self::getTotal($xml);
		$moneda = self::getMoneda($xml);
		$tipoComprobante = self::firstAttrByLocalName($xml, 'Comprobante', 'TipoDeComprobante');
		$receptorId = self::getReceptorId($xml);
		$tipoDocumento = self::getTipoDocumento($xml, $tipoComprobante);

		return [
			'consecutivo_completo' => $consecutivo,
			'clave' => $clave,
			'numero_factura_asistente' => $numeroAsistente,
			'rfc_emisor' => $rfcEmisor,
			'razon_social_emisor' => $razonSocialEmisor,
			'receptor_id' => $receptorId,
			'tipo_documento' => $tipoDocumento,
			'fecha_emision' => $fechaEmision,
			'subtotal' => $subtotal,
			'iva' => $iva,
			'total' => $total,
			'moneda' => $moneda,
			'tipo_comprobante' => $tipoComprobante !== '' ? $tipoComprobante : null,
			'hash_xml' => hash_file('sha256', $filePath),
			'xml_contenido' => file_get_contents($filePath)
		];
	}

	private static function normalizeDate($datetime)
	{
		if (empty($datetime)) {
			return date('Y-m-d');
		}

		$text = trim((string) $datetime);

		// Caso XML estándar ISO-8601: 2025-03-07T14:20:00 o 2025-03-07
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}

		// Formatos locales con slash: asumir d/m/Y para evitar inversión mes/día.
		$localFormats = [
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd/m/Y',
			'd-m-Y H:i:s',
			'd-m-Y H:i',
			'd-m-Y'
		];

		foreach ($localFormats as $format) {
			$date = DateTime::createFromFormat($format, $text);
			if ($date && $date->format($format) === $text) {
				return $date->format('Y-m-d');
			}
		}

		try {
			return (new DateTime($text))->format('Y-m-d');
		} catch (Exception $e) {
			return date('Y-m-d');
		}
	}

	private static function getIva(SimpleXMLElement $xml)
	{
		$iva = 0.0;
		$nodes = $xml->xpath('//*[local-name()="Traslado" and (@Impuesto="002" or @Codigo="01")]');

		if (is_array($nodes)) {
			foreach ($nodes as $node) {
				$iva += (float) ($node['Importe'] ?? 0);
				$iva += (float) ($node['Monto'] ?? 0);
			}
		}

		if ($iva > 0) {
			return $iva;
		}

		$totalImpuesto = self::firstNodeText($xml, 'TotalImpuesto');
		if ($totalImpuesto !== '') {
			return (float) $totalImpuesto;
		}

		$impuestos = $xml->xpath('//*[local-name()="Impuesto"]/*[local-name()="Monto"]');
		if (is_array($impuestos)) {
			foreach ($impuestos as $monto) {
				$iva += (float) ((string) $monto);
			}
		}

		return $iva;
	}

	private static function getSubtotal(SimpleXMLElement $xml)
	{
		$subTotalAttr = self::firstAttrByLocalName($xml, 'Comprobante', 'SubTotal');
		if ($subTotalAttr !== '') {
			return (float) $subTotalAttr;
		}

		$totalVentaNeta = self::firstNodeText($xml, 'TotalVentaNeta');
		if ($totalVentaNeta !== '') {
			return (float) $totalVentaNeta;
		}

		$totalVenta = self::firstNodeText($xml, 'TotalVenta');
		if ($totalVenta !== '') {
			return (float) $totalVenta;
		}

		return 0.0;
	}

	private static function getTotal(SimpleXMLElement $xml)
	{
		$totalAttr = self::firstAttrByLocalName($xml, 'Comprobante', 'Total');
		if ($totalAttr !== '') {
			return (float) $totalAttr;
		}

		$totalComprobante = self::firstNodeText($xml, 'TotalComprobante');
		if ($totalComprobante !== '') {
			return (float) $totalComprobante;
		}

		$total = self::firstNodeText($xml, 'Total');
		return $total !== '' ? (float) $total : 0.0;
	}

	private static function getMoneda(SimpleXMLElement $xml)
	{
		$monedaAttr = self::firstAttrByLocalName($xml, 'Comprobante', 'Moneda');
		if ($monedaAttr !== '') {
			return $monedaAttr;
		}

		$codigoMoneda = self::firstNodeText($xml, 'CodigoMoneda');
		if ($codigoMoneda !== '') {
			return $codigoMoneda;
		}

		return 'CRC';
	}

	private static function getFechaEmision(SimpleXMLElement $xml)
	{
		$fechaAttr = self::firstAttrByLocalName($xml, 'Comprobante', 'Fecha');
		if ($fechaAttr !== '') {
			return $fechaAttr;
		}

		$fechaNode = self::firstNodeText($xml, 'FechaEmision');
		if ($fechaNode !== '') {
			return $fechaNode;
		}

		return '';
	}

	/**
	 * Identificación del receptor (a quién va la factura): cédula en FE
	 * de Costa Rica, RFC en CFDI de México. Sirve para verificar que la
	 * factura esté a nombre de la empresa configurada.
	 */
	private static function getReceptorId(SimpleXMLElement $xml)
	{
		$rfc = self::firstAttrByLocalName($xml, 'Receptor', 'Rfc');
		if ($rfc !== '') {
			return $rfc;
		}

		return self::firstNodeText($xml, 'Numero', '//*[local-name()="Receptor"]//*[local-name()="Identificacion"]/*[local-name()="Numero"]');
	}

	/**
	 * Tipo del documento: 'FE' factura, 'NC' nota de crédito, 'ND' nota
	 * de débito. Se detecta por la raíz del XML (FacturaElectronica /
	 * NotaCreditoElectronica…) o por TipoDeComprobante en CFDI (E = NC).
	 */
	private static function getTipoDocumento(SimpleXMLElement $xml, $tipoComprobante)
	{
		$root = strtolower($xml->getName());

		if (strpos($root, 'notacredito') !== false) {
			return 'NC';
		}
		if (strpos($root, 'notadebito') !== false) {
			return 'ND';
		}
		if (strtoupper(trim((string) $tipoComprobante)) === 'E') {
			return 'NC';
		}

		return 'FE';
	}

	private static function getEmisorId(SimpleXMLElement $xml)
	{
		$rfc = self::firstAttrByLocalName($xml, 'Emisor', 'Rfc');
		if ($rfc !== '') {
			return $rfc;
		}

		$idNumero = self::firstNodeText($xml, 'Numero', '//*[local-name()="Emisor"]//*[local-name()="Identificacion"]/*[local-name()="Numero"]');
		if ($idNumero !== '') {
			return $idNumero;
		}

		return '';
	}

	private static function getEmisorNombre(SimpleXMLElement $xml)
	{
		$nombre = self::firstAttrByLocalName($xml, 'Emisor', 'Nombre');
		if ($nombre !== '') {
			return $nombre;
		}

		$nombreNode = self::firstNodeText($xml, 'Nombre', '//*[local-name()="Emisor"]/*[local-name()="Nombre"]');
		if ($nombreNode !== '') {
			return $nombreNode;
		}

		return 'SIN NOMBRE';
	}

	private static function firstNodeText(SimpleXMLElement $xml, $localName, $customXPath = null)
	{
		$xpath = $customXPath ?: '//*[local-name()="' . $localName . '"]';
		$nodes = $xml->xpath($xpath);

		if (is_array($nodes) && isset($nodes[0])) {
			return trim((string) $nodes[0]);
		}

		return '';
	}

	private static function firstAttrByLocalName(SimpleXMLElement $xml, $localName, $attrName)
	{
		$nodes = $xml->xpath('//*[local-name()="' . $localName . '"]');
		if (is_array($nodes) && isset($nodes[0])) {
			$value = (string) ($nodes[0][$attrName] ?? '');
			return trim($value);
		}

		if (strtolower($localName) === strtolower($xml->getName())) {
			$value = (string) ($xml[$attrName] ?? '');
			return trim($value);
		}

		return '';
	}

	private static function buildNumeroAsistente($value)
	{
		$digits = preg_replace('/\D+/', '', (string) $value);
		$digits = ltrim($digits, '0');
		if ($digits === '') {
			$digits = preg_replace('/[^A-Za-z0-9]/', '', (string) $value);
		}

		if ($digits === '') {
			$digits = '0';
		}

		return substr($digits, -10);
	}

	/**
	 * Factura reconstruida a partir del MensajeHacienda de aceptación (cuando
	 * el correo no trae la FacturaElectronica). El mensaje trae Clave, emisor,
	 * receptor y totales; el consecutivo, la fecha y el tipo de documento se
	 * derivan de la Clave (50 díg.: 506 + ddmmaa + cédula(12) + consecutivo(20)
	 * + situación(1) + código(8); el consecutivo = sucursal(3)+terminal(5)+
	 * tipo(2)+número(10), tipo 01=FE, 02=ND, 03=NC, 04=tiquete).
	 */
	private static function parseMensajeHacienda(SimpleXMLElement $xml, $filePath)
	{
		$clave             = self::firstNodeText($xml, 'Clave');
		$razonSocialEmisor = self::firstNodeText($xml, 'NombreEmisor');
		$rfcEmisor         = self::firstNodeText($xml, 'NumeroCedulaEmisor');
		$receptorId        = self::firstNodeText($xml, 'NumeroCedulaReceptor');
		$total             = (float) self::firstNodeText($xml, 'TotalFactura');
		$iva               = (float) self::firstNodeText($xml, 'MontoTotalImpuesto');

		$claveDigits = preg_replace('/\D+/', '', $clave);

		$consecutivo   = $claveDigits;
		$fecha         = '';
		$tipoDocumento = 'FE';

		if (strlen($claveDigits) >= 50) {
			$consecutivo = substr($claveDigits, 21, 20);
			$fecha = '20' . substr($claveDigits, 7, 2) . '-' . substr($claveDigits, 5, 2) . '-' . substr($claveDigits, 3, 2);

			$mapaTipo = ['01' => 'FE', '02' => 'ND', '03' => 'NC', '04' => 'FE'];
			$tipoDocumento = $mapaTipo[substr($consecutivo, 8, 2)] ?? 'FE';
		}

		if ($consecutivo === '') {
			$consecutivo = sha1_file($filePath);
		}

		return [
			'consecutivo_completo'     => $consecutivo,
			'clave'                    => $clave,
			'numero_factura_asistente' => self::buildNumeroAsistente($consecutivo),
			'rfc_emisor'               => $rfcEmisor,
			'razon_social_emisor'      => $razonSocialEmisor !== '' ? $razonSocialEmisor : 'SIN NOMBRE',
			'receptor_id'              => $receptorId,
			'tipo_documento'           => $tipoDocumento,
			'fecha_emision'            => self::normalizeDate($fecha),
			'subtotal'                 => $total > 0 ? max(0.0, $total - $iva) : 0.0,
			'iva'                      => $iva,
			'total'                    => $total,
			'moneda'                   => 'CRC',
			'tipo_comprobante'         => null,
			'hash_xml'                 => hash_file('sha256', $filePath),
			'xml_contenido'            => file_get_contents($filePath),
		];
	}
}
}
