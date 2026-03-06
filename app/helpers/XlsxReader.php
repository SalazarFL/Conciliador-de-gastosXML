<?php
/**
 * Lector basico de archivos XLSX (primera hoja)
 * No requiere dependencias externas.
 */

class XlsxReader
{
    public static function readFirstSheet($filePath)
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('La extension ZipArchive no esta habilitada en PHP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception('No fue posible abrir el archivo XLSX.');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $sheetPath = self::resolveFirstSheetPath($zip);

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            throw new Exception('No se pudo leer la hoja principal del XLSX.');
        }

        $rows = self::parseSheetRows($sheetXml, $sharedStrings);
        $zip->close();

        if (empty($rows)) {
            throw new Exception('El archivo XLSX no contiene filas.');
        }

        $header = array_shift($rows);

        return [
            'header' => $header,
            'rows' => $rows
        ];
    }

    private static function readSharedStrings(ZipArchive $zip)
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sharedXml);
        if ($xml === false) {
            return [];
        }

        $items = $xml->xpath('//*[local-name()="si"]');

        $strings = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                $parts = $item->xpath('.//*[local-name()="t"]');
                $text = '';
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $text .= (string) $part;
                    }
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    private static function resolveFirstSheetPath(ZipArchive $zip)
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new Exception('Estructura XLSX invalida: workbook no disponible.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if ($workbook === false || $rels === false) {
            throw new Exception('No se pudo parsear metadata del XLSX.');
        }

        $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!is_array($sheets) || !isset($sheets[0])) {
            throw new Exception('El XLSX no contiene hojas.');
        }

        $attributes = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($attributes['id'] ?? '');
        if ($rid === '') {
            $rid = (string) ($sheets[0]['id'] ?? '');
        }
        if ($rid === '') {
            throw new Exception('No se encontro la relacion de la primera hoja.');
        }

        $relations = $rels->xpath('//*[local-name()="Relationship"]');
        if (!is_array($relations)) {
            throw new Exception('No se encontraron relaciones internas del XLSX.');
        }

        foreach ($relations as $relation) {
            $id = (string) ($relation['Id'] ?? '');
            if ($id === $rid) {
                $target = (string) ($relation['Target'] ?? '');
                if ($target === '') {
                    break;
                }

                return self::normalizeZipTarget($target);
            }
        }

        throw new Exception('No se pudo resolver la ruta de la primera hoja.');
    }

    private static function parseSheetRows($sheetXml, array $sharedStrings)
    {
        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new Exception('No se pudo parsear la hoja XLSX.');
        }

        $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');

        if (!is_array($rowNodes)) {
            return [];
        }

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $maxIndex = -1;
            $cellNodes = $rowNode->xpath('./*[local-name()="c"]');
            if (!is_array($cellNodes)) {
                continue;
            }

            foreach ($cellNodes as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $colIndex = self::columnIndexFromCellRef($ref);
                if ($colIndex < 0) {
                    continue;
                }

                $type = (string) ($cell['t'] ?? '');
                $value = self::readCellValue($cell, $type, $sharedStrings);

                $cells[$colIndex] = $value;
                if ($colIndex > $maxIndex) {
                    $maxIndex = $colIndex;
                }
            }

            if ($maxIndex < 0) {
                continue;
            }

            $row = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $row[] = $cells[$i] ?? '';
            }

            $hasValues = false;
            foreach ($row as $cellValue) {
                if (trim((string) $cellValue) !== '') {
                    $hasValues = true;
                    break;
                }
            }

            if ($hasValues) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function readCellValue(SimpleXMLElement $cell, $type, array $sharedStrings)
    {
        if ($type === 'inlineStr') {
            $textNodes = $cell->xpath('./*[local-name()="is"]/*[local-name()="t"]');
            if (is_array($textNodes) && isset($textNodes[0])) {
                return (string) $textNodes[0];
            }
            return '';
        }

        $valueNodes = $cell->xpath('./*[local-name()="v"]');
        $rawValue = (is_array($valueNodes) && isset($valueNodes[0])) ? (string) $valueNodes[0] : '';

        if ($type === 's') {
            $index = (int) $rawValue;
            return $sharedStrings[$index] ?? '';
        }

        if ($type === 'b') {
            return $rawValue === '1' ? 'TRUE' : 'FALSE';
        }

        return $rawValue;
    }

    private static function columnIndexFromCellRef($ref)
    {
        if ($ref === '' || !preg_match('/^[A-Z]+/i', $ref, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[0]);
        $index = 0;
        $length = strlen($letters);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private static function normalizeZipTarget($target)
    {
        $target = str_replace('\\', '/', (string) $target);
        $target = ltrim($target, '/');

        if (strpos($target, 'xl/') === 0) {
            return $target;
        }

        while (strpos($target, '../') === 0) {
            $target = substr($target, 3);
        }

        return 'xl/' . $target;
    }
}
