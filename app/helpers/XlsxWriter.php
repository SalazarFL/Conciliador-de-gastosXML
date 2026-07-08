<?php
/**
 * Helper para generar archivos XLSX sin dependencias externas.
 * Usa ZipArchive + XML plano para crear un xlsx mínimo compatible con Excel.
 */

class XlsxWriter
{
	/**
	 * $cellStyles : array[ rowIndex ][ colIndex ] = styleId
	 *   Style 2 = fondo amarillo (hallazgo con diferencias)
	 *   Style 3 = fondo verde   (sin diferencias / ok)
	 * $colWidths  : array[ colIndex ] = width (unidades Excel, aprox. chars)
	 */
	public static function generate(array $headers, array $rows, string $sheetName = 'Datos', array $cellStyles = [], array $colWidths = [])
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
		if ($tmpFile === false) {
			throw new Exception('No se pudo crear archivo temporal para XLSX.');
		}

		$zip = new ZipArchive();
		if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
			throw new Exception('No se pudo crear el archivo XLSX.');
		}

		$sharedStrings = [];
		$ssIndex = [];

		$addShared = function ($value) use (&$sharedStrings, &$ssIndex) {
			$str = (string) $value;
			if (!isset($ssIndex[$str])) {
				$ssIndex[$str] = count($sharedStrings);
				$sharedStrings[] = $str;
			}
			return $ssIndex[$str];
		};

		$colCount = count($headers);
		$rowCount = 1 + count($rows);

		$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

		$lastCol = self::colLetter($colCount - 1);
		$sheetXml .= '<dimension ref="A1:' . $lastCol . $rowCount . '"/>';
		$sheetXml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

		$sheetXml .= '<cols>';
		for ($c = 0; $c < $colCount; $c++) {
			$w = isset($colWidths[$c]) ? (float) $colWidths[$c] : 18;
			$sheetXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" bestFit="1" customWidth="1"/>';
		}
		$sheetXml .= '</cols>';

		$sheetXml .= '<sheetData>';

		// Header row
		$sheetXml .= '<row r="1">';
		foreach ($headers as $ci => $header) {
			$ref = self::colLetter($ci) . '1';
			$idx = $addShared($header);
			$sheetXml .= '<c r="' . $ref . '" t="s" s="1"><v>' . $idx . '</v></c>';
		}
		$sheetXml .= '</row>';

		// Data rows
		foreach ($rows as $ri => $row) {
			$rowNum = $ri + 2;
			$sheetXml .= '<row r="' . $rowNum . '">';
			foreach ($headers as $ci => $header) {
				$ref = self::colLetter($ci) . $rowNum;
				$val = $row[$ci] ?? $row[$header] ?? '';
				$styleId = $cellStyles[$ri][$ci] ?? 0;
				$sAttr   = $styleId > 0 ? ' s="' . $styleId . '"' : '';

				if (is_numeric($val) && $val !== '' && !preg_match('/^0\d/', (string) $val)) {
					$sheetXml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $val . '</v></c>';
				} else {
					$idx = $addShared((string) $val);
					$sheetXml .= '<c r="' . $ref . '" t="s"' . $sAttr . '><v>' . $idx . '</v></c>';
				}
			}
			$sheetXml .= '</row>';
		}

		$sheetXml .= '</sheetData>';
		$sheetXml .= '<autoFilter ref="A1:' . $lastCol . $rowCount . '"/>';
		$sheetXml .= '</worksheet>';

		// Shared strings
		$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
		foreach ($sharedStrings as $s) {
			$ssXml .= '<si><t>' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
		}
		$ssXml .= '</sst>';

		// Styles
		// 0 = normal  |  1 = bold+azul (header)  |  2 = fondo amarillo (hallazgo)  |  3 = fondo verde (ok)
		$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="5">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="4">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>'
			. '</cellXfs>'
			. '</styleSheet>';

		$safeSheetName = htmlspecialchars(mb_substr($sheetName, 0, 31, 'UTF-8'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

		// Workbook
		$wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . $safeSheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

		// Rels
		$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
			. '</Relationships>';

		$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';

		$zip->addFromString('[Content_Types].xml', $contentTypes);
		$zip->addFromString('_rels/.rels', $rootRels);
		$zip->addFromString('xl/workbook.xml', $wbXml);
		$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
		$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
		$zip->addFromString('xl/sharedStrings.xml', $ssXml);
		$zip->addFromString('xl/styles.xml', $stylesXml);

		$zip->close();

		return $tmpFile;
	}

	public static function send(string $filePath, string $downloadName)
	{
		if (!is_file($filePath)) {
			throw new Exception('Archivo XLSX no encontrado para descarga.');
		}

		$safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $downloadName);
		if (!preg_match('/\.xlsx$/i', $safeName)) {
			$safeName .= '.xlsx';
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $safeName . '"');
		header('Content-Length: ' . filesize($filePath));
		header('Cache-Control: max-age=0');

		readfile($filePath);
		@unlink($filePath);
		exit;
	}

	private static function colLetter(int $index): string
	{
		$letter = '';
		$index++;
		while ($index > 0) {
			$index--;
			$letter = chr(65 + ($index % 26)) . $letter;
			$index = intdiv($index, 26);
		}
		return $letter;
	}
}
