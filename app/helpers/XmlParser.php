<?php
/**
 * Helper para parsear archivos XML de facturas
 */

require_once __DIR__ . '/NumeroFactura.php';

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

	/**
	 * Detalle interno del comprobante: bloques InformacionReferencia (la NC
	 * cita ahí la clave de la factura que acredita) y LineaDetalle. Cubre
	 * FE 4.2/4.3 de Costa Rica; en un MensajeHacienda no existe nada de esto
	 * y se devuelven listas vacías.
	 */
	public static function parseDetalleFromFile($filePath)
	{
		if (!file_exists($filePath)) {
			throw new Exception('Archivo XML no encontrado: ' . $filePath);
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_file($filePath);
		if ($xml === false) {
			throw new Exception('No fue posible parsear el XML.');
		}

		return [
			'referencias' => self::getReferencias($xml),
			'lineas' => self::getLineasDetalle($xml),
		];
	}

	private static function getReferencias(SimpleXMLElement $xml)
	{
		$referencias = [];
		$nodes = $xml->xpath('//*[local-name()="InformacionReferencia"]');
		if (!is_array($nodes)) {
			return $referencias;
		}

		foreach ($nodes as $nodo) {
			$numero = self::childText($nodo, 'Numero');
			if ($numero === '') {
				continue;
			}

			$digits = preg_replace('/\D+/', '', $numero);
			$esClave = strlen($digits) === 50;

			// v4.3 usa FechaEmision/TipoDoc/Codigo; v4.4 los sufija con IR.
			$fecha = self::childText($nodo, 'FechaEmision');
			if ($fecha === '') {
				$fecha = self::childText($nodo, 'FechaEmisionIR');
			}
			if ($fecha === '' && $esClave) {
				// La clave trae la fecha de emisión en sus dígitos 4-9 (ddmmaa).
				$fecha = substr($digits, 3, 2) . '/' . substr($digits, 5, 2) . '/20' . substr($digits, 7, 2);
			}
			$tipoDoc = self::childText($nodo, 'TipoDoc');
			if ($tipoDoc === '') {
				$tipoDoc = self::childText($nodo, 'TipoDocIR');
			}
			$codigo = self::childText($nodo, 'Codigo');
			if ($codigo === '') {
				$codigo = self::childText($nodo, 'CodigoIR');
			}

			$referencias[] = [
				'tipo_doc_ref' => $tipoDoc !== '' ? substr($tipoDoc, 0, 4) : null,
				'numero_ref' => substr($numero, 0, 60),
				'clave_ref' => $esClave ? $digits : null,
				'consecutivo_ref' => $esClave ? substr($digits, 21, 20) : null,
				'fecha_ref' => $fecha !== '' ? self::normalizeDate($fecha) : null,
				'codigo_ref' => $codigo !== '' ? substr($codigo, 0, 4) : null,
				'razon' => self::childText($nodo, 'Razon') !== ''
					? mb_substr(self::childText($nodo, 'Razon'), 0, 255)
					: null,
			];
		}

		return $referencias;
	}

	private static function getLineasDetalle(SimpleXMLElement $xml)
	{
		$lineas = [];
		$nodes = $xml->xpath('//*[local-name()="LineaDetalle"]');
		if (!is_array($nodes)) {
			return $lineas;
		}

		$secuencia = 0;
		foreach ($nodes as $nodo) {
			$secuencia++;
			$numeroLinea = (int) self::childText($nodo, 'NumeroLinea');

			// v4.3: <Codigo> directo es el CABYS y <CodigoComercial> anida el
			// código del proveedor; v4.2: <Codigo> anida <Tipo> y <Codigo>.
			$cabys = '';
			$codigoComercial = '';
			$codigoNodos = $nodo->xpath('*[local-name()="Codigo" or local-name()="CodigoComercial"]');
			foreach (is_array($codigoNodos) ? $codigoNodos : [] as $codigoNodo) {
				$anidado = self::childText($codigoNodo, 'Codigo');
				if ($anidado !== '') {
					if ($codigoComercial === '') {
						$codigoComercial = $anidado;
					}
				} elseif ($cabys === '') {
					$cabys = trim((string) $codigoNodo);
				}
			}

			$impuesto = 0.0;
			$impuestoNodos = $nodo->xpath('*[local-name()="Impuesto"]/*[local-name()="Monto"]');
			foreach (is_array($impuestoNodos) ? $impuestoNodos : [] as $monto) {
				$impuesto += (float) ((string) $monto);
			}

			$descuento = (float) self::childText($nodo, 'MontoDescuento');
			if ($descuento === 0.0) {
				$descuentoNodos = $nodo->xpath('*[local-name()="Descuento"]/*[local-name()="MontoDescuento"]');
				foreach (is_array($descuentoNodos) ? $descuentoNodos : [] as $monto) {
					$descuento += (float) ((string) $monto);
				}
			}

			$lineas[] = [
				'numero_linea' => $numeroLinea > 0 ? $numeroLinea : $secuencia,
				'codigo_cabys' => $cabys !== '' ? substr($cabys, 0, 20) : null,
				'codigo_comercial' => $codigoComercial !== '' ? substr($codigoComercial, 0, 30) : null,
				'detalle' => self::childText($nodo, 'Detalle') !== ''
					? mb_substr(self::childText($nodo, 'Detalle'), 0, 255)
					: null,
				'cantidad' => (float) self::childText($nodo, 'Cantidad'),
				'unidad' => self::childText($nodo, 'UnidadMedida') !== ''
					? substr(self::childText($nodo, 'UnidadMedida'), 0, 15)
					: null,
				'precio_unitario' => (float) self::childText($nodo, 'PrecioUnitario'),
				'monto_descuento' => $descuento,
				'subtotal' => (float) self::childText($nodo, 'SubTotal'),
				'impuesto' => $impuesto,
				'total_linea' => (float) self::childText($nodo, 'MontoTotalLinea'),
			];
		}

		return $lineas;
	}

	private static function childText(SimpleXMLElement $nodo, $localName)
	{
		$hijos = $nodo->xpath('*[local-name()="' . $localName . '"]');
		if (is_array($hijos) && isset($hijos[0])) {
			return trim((string) $hijos[0]);
		}

		return '';
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
		return NumeroFactura::xmlOchoDigitos($value);
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
