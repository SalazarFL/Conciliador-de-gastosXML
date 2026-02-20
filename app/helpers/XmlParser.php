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

		$uuid = self::firstAttrByLocalName($xml, 'TimbreFiscalDigital', 'UUID');
		$clave = self::firstNodeText($xml, 'Clave');
		$numeroConsecutivo = self::firstNodeText($xml, 'NumeroConsecutivo');
		$serie = self::firstAttrByLocalName($xml, 'Comprobante', 'Serie');
		$folio = self::firstAttrByLocalName($xml, 'Comprobante', 'Folio');

		$consecutivo = '';
		if ($uuid !== '') {
			$consecutivo = $uuid;
		} elseif ($clave !== '') {
			$consecutivo = $clave;
		} elseif ($numeroConsecutivo !== '') {
			$consecutivo = $numeroConsecutivo;
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

		return [
			'consecutivo_completo' => $consecutivo,
			'numero_factura_asistente' => $numeroAsistente,
			'rfc_emisor' => $rfcEmisor,
			'razon_social_emisor' => $razonSocialEmisor,
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

		try {
			return (new DateTime($datetime))->format('Y-m-d');
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

		return 'MXN';
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
}
}
