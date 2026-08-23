<?php
/**
 * Lector del reporte "Notas de Crédito por Proveedor".
 *
 * El reporte no es un CSV convencional: cada línea física viene envuelta en
 * comillas, las comillas internas están duplicadas, usa Windows-1252 y las
 * posiciones cambian entre páginas. Por eso se detectan los campos por su
 * contenido y por el contexto Proveedor/Sucursal.
 *
 * Lo que no se puede leer no se tira: sale en 'revision' con sus celdas
 * crudas para que alguien lo corrija a mano. Ver LineaRevision.
 */

require_once __DIR__ . '/LineaRevision.php';

class NotasCreditoCsvParser
{
    public static function parse($filePath)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new Exception('No fue posible abrir el archivo de notas de crédito.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new Exception('No fue posible abrir el archivo de notas de crédito.');
        }

        $empresa = '';
        $periodoDesde = null;
        $periodoHasta = null;
        $proveedorCodigo = '';
        $proveedorNombre = '';
        $sucursal = '';
        $lineas = [];
        $errores = [];
        $revision = [];
        $numeroFisico = 0;
        $pendiente = null;
        $primerosTextos = [];

        while (($raw = fgets($handle)) !== false) {
            $numeroFisico++;
            $row = self::decodePhysicalLine($raw);
            $nonEmpty = self::nonEmptyCells($row);

            if (empty($nonEmpty)) {
                $pendiente = null;
                continue;
            }

            foreach ($nonEmpty as $value) {
                if (count($primerosTextos) < 5 && !self::isNumericValue($value)) {
                    $primerosTextos[] = $value;
                }
            }

            $flat = implode(' ', array_values($nonEmpty));
            if ($empresa === '' && stripos($flat, 'Notas de Crédito') === false
                && stripos($flat, 'Del ') !== 0 && stripos($flat, 'Todos los Proveedores') === false) {
                $empresa = trim((string) reset($nonEmpty));
            }

            if (preg_match('/Del\s+(\d{1,2})\s+de\s+([[:alpha:]áéíóúñ]+)\s+del?\s+(\d{4})\s+al\s+'
                . '(\d{1,2})\s+de\s+([[:alpha:]áéíóúñ]+)\s+del?\s+(\d{4})/iu', $flat, $m)) {
                $periodoDesde = self::spanishDate($m[1], $m[2], $m[3]);
                $periodoHasta = self::spanishDate($m[4], $m[5], $m[6]);
            }

            $first = (string) reset($nonEmpty);
            if (preg_match('/^Proveedor$/iu', $first)) {
                [$proveedorCodigo, $proveedorNombre] = self::providerFromRow($nonEmpty);
                $pendiente = null;
                continue;
            }

            if (preg_match('/^Sucursal$/iu', $first)) {
                $values = array_values($nonEmpty);
                $sucursal = trim((string) end($values));
                $pendiente = null;
                continue;
            }

            $documentIndex = self::findDocumentIndex($nonEmpty);
            if ($documentIndex === null) {
                // El número interno puede venir partido en la siguiente línea.
                if ($pendiente !== null && count($nonEmpty) === 1) {
                    $continuation = trim((string) reset($nonEmpty));
                    // Solo se pega si parece la cola de un número. Antes bastaba
                    // con que tuviera una letra o un dígito, y así el nombre del
                    // proveedor terminaba dentro del documento
                    // ("1-2-68-0GrupoBMSPS.A"), que ya nunca emparejaba.
                    if ($continuation !== '' && preg_match('/^[0-9][0-9-]*$/', $continuation)) {
                        $lineas[$pendiente]['documento'] .= $continuation;
                        $origen = json_decode($lineas[$pendiente]['datos_origen'], true) ?: [];
                        $origen['continuacion'][] = [
                            'fila' => $numeroFisico,
                            'valor' => $continuation,
                        ];
                        $lineas[$pendiente]['datos_origen'] = json_encode(
                            $origen,
                            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                        );
                        $pendiente = null;
                        continue;
                    }
                }

                // Una fila sin celda "NC-" ya no se tira sin más.
                //
                // Era el agujero más grande del importador: la regla es de
                // forma, no de contenido, así que una nota perfectamente
                // buena cuyo número viniera escrito "NC 4946" o "N/C- 4947"
                // desaparecía sin error, sin contarse y sin salir en ninguna
                // pantalla. El listado decía "1 nota leída" y las otras tres
                // no habían existido nunca. Si la fila tiene pinta de dato
                // —trae fecha y números— pasa a la bandeja de revisión; si es
                // mobiliario del reporte (títulos, totales, encabezados) se
                // sigue descartando, que para eso está.
                if (self::pareceDato($nonEmpty)) {
                    $revision[] = self::paraRevision(
                        $numeroFisico,
                        $row,
                        $nonEmpty,
                        'La fila tiene datos pero ninguna celda empieza con "NC-", '
                            . 'que es como el importador reconoce una nota.',
                        $proveedorCodigo,
                        $proveedorNombre,
                        $sucursal
                    );
                }
                $pendiente = null;
                continue;
            }

            try {
                if ($proveedorNombre === '') {
                    throw new Exception('No se encontró el proveedor que encabeza la fila.');
                }

                $currencyIndex = self::findCurrencyIndex($nonEmpty, $documentIndex);
                if ($currencyIndex === null) {
                    throw new Exception('No se encontró la moneda de la nota.');
                }

                $dates = self::dateCellsBetween($row, $documentIndex, $currencyIndex);
                if (empty($dates)) {
                    throw new Exception('No se encontró la fecha de la nota.');
                }

                $amounts = self::amountsAfter($row, $currencyIndex);
                if (count($amounts) < 3) {
                    throw new Exception('No se encontraron Monto, Saldo y Monto conversión.');
                }

                $fecha = self::parseDate($dates[0]['value']);
                $fechaNcProveedor = isset($dates[1]) ? self::parseDate($dates[1]['value']) : null;
                $ncProveedor = '';
                if (isset($dates[1])) {
                    $ncProveedor = self::joinCells($row, $dates[0]['index'] + 1, $dates[1]['index']);
                }

                $lastDateIndex = $dates[count($dates) - 1]['index'];
                $entrada = self::joinCells($row, $lastDateIndex + 1, $currencyIndex);

                $rawSparse = [];
                foreach ($nonEmpty as $index => $value) {
                    $rawSparse[(string) $index] = $value;
                }

                $lineas[] = [
                    'fila_origen' => $numeroFisico,
                    'proveedor_codigo' => $proveedorCodigo,
                    'proveedor_nombre' => $proveedorNombre,
                    'sucursal' => $sucursal,
                    'documento' => trim((string) $row[$documentIndex]),
                    'fecha' => $fecha,
                    'nc_proveedor' => $ncProveedor !== '' ? $ncProveedor : null,
                    'fecha_nc_proveedor' => $fechaNcProveedor,
                    'entrada_asociada' => $entrada !== '' ? $entrada : null,
                    'moneda' => self::normalizeCurrency((string) $row[$currencyIndex]),
                    'monto' => $amounts[0],
                    'saldo' => $amounts[1],
                    'monto_conversion' => $amounts[2],
                    'datos_origen' => json_encode([
                        'fila' => $numeroFisico,
                        'celdas' => $rawSparse,
                    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ];
                $pendiente = count($lineas) - 1;
            } catch (Throwable $e) {
                // La fila SÍ es una nota (tiene su "NC-") pero le falta algo
                // para poder leerla. Antes solo se contaba —"3 filas
                // inválidas fueron omitidas"— y ahí moría: nadie podía saber
                // cuáles eran ni recuperarlas. Ahora se cuenta y además se
                // guarda entera, con lo que sí se le pudo leer ya puesto.
                $errores[] = [
                    'fila' => $numeroFisico,
                    'documento' => trim((string) ($row[$documentIndex] ?? '')),
                    'motivo' => $e->getMessage(),
                ];
                $revision[] = self::paraRevision(
                    $numeroFisico,
                    $row,
                    $nonEmpty,
                    $e->getMessage(),
                    $proveedorCodigo,
                    $proveedorNombre,
                    $sucursal
                );
                $pendiente = null;
            }
        }

        fclose($handle);

        if (empty($lineas)) {
            throw new Exception('El archivo no contiene filas válidas de notas de crédito.');
        }

        $proveedores = [];
        $sucursales = [];
        $monedas = ['CRC' => 0, 'USD' => 0];
        foreach ($lineas as $linea) {
            $proveedores[$linea['proveedor_codigo'] . '|' . $linea['proveedor_nombre']] = true;
            if ($linea['sucursal'] !== '') {
                $sucursales[$linea['sucursal']] = true;
            }
            $monedas[$linea['moneda']] = ($monedas[$linea['moneda']] ?? 0) + 1;
        }

        return [
            'empresa' => $empresa !== '' ? $empresa : ($primerosTextos[0] ?? ''),
            'periodo_desde' => $periodoDesde,
            'periodo_hasta' => $periodoHasta,
            'lineas' => $lineas,
            'errores' => $errores,
            'revision' => $revision,
            'estadisticas' => [
                'total' => count($lineas),
                'proveedores' => count($proveedores),
                'sucursales' => count($sucursales),
                'crc' => (int) ($monedas['CRC'] ?? 0),
                'usd' => (int) ($monedas['USD'] ?? 0),
            ],
        ];
    }

    private static function decodePhysicalLine($raw)
    {
        $line = rtrim((string) $raw, "\r\n");

        // Hay dos variantes del reporte en circulación:
        // 1. Una sola celda CSV que envuelve toda la línea del reporte.
        // 2. Un CSV estándar donde cada columna viene delimitada por separado.
        // Primero se interpreta como CSV estándar. Solo se hace una segunda
        // lectura cuando el resultado es una única celda que aún contiene comas.
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) === 1 && strpos((string) $row[0], ',') !== false) {
            $row = str_getcsv((string) $row[0], ',', '"', '\\');
        }

        foreach ($row as &$value) {
            $value = (string) $value;
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            }
            $value = trim(str_replace("\xC2\xA0", ' ', $value));
        }
        unset($value);

        return $row;
    }

    private static function nonEmptyCells(array $row)
    {
        $result = [];
        foreach ($row as $index => $value) {
            if (trim((string) $value) !== '') {
                $result[$index] = trim((string) $value);
            }
        }
        return $result;
    }

    private static function providerFromRow(array $nonEmpty)
    {
        $values = array_values($nonEmpty);
        foreach ($values as $index => $value) {
            if (preg_match('/^\d{6,}$/', $value)) {
                return [
                    $value,
                    trim((string) ($values[$index + 1] ?? '')),
                ];
            }
        }
        return ['', ''];
    }

    /**
     * Si una fila que no se pudo leer parece un dato o es mobiliario del
     * reporte. Se pide fecha y algún número: los títulos, los encabezados de
     * columna y los rótulos no tienen ninguna de las dos cosas, y los totales
     * tienen números pero no fecha. Ante la duda se prefiere preguntar: una
     * fila de más en la bandeja es una molestia, una fila de menos es plata
     * que nadie sabe que falta.
     */
    private static function pareceDato(array $nonEmpty)
    {
        $primera = (string) reset($nonEmpty);
        if (preg_match('/^(Notas de Cr|Del \d+ de |Todos los Proveedores|Documento|Fecha|Entrada|'
            . 'Moneda|Monto|Saldo|Conversi|Total|Fin del|P.gina|Usuario|Impreso|Grupo )/iu', $primera)) {
            return false;
        }

        $tieneFecha = false;
        $tieneNumero = false;
        foreach ($nonEmpty as $valor) {
            if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', (string) $valor)) { $tieneFecha = true; }
            if (self::isNumericValue($valor)) { $tieneNumero = true; }
        }

        return $tieneFecha && $tieneNumero;
    }

    /**
     * Una fila para la bandeja de revisión: sus celdas crudas, lo que se le
     * pudo deducir por la forma de cada dato y su firma, que es lo que
     * permite reconocerla cuando el mismo reporte se vuelva a subir.
     */
    private static function paraRevision($fila, array $row, array $nonEmpty, $motivo,
                                         $proveedorCodigo, $proveedorNombre, $sucursal)
    {
        $celdas = array_values($nonEmpty);

        $campos = [
            'proveedor_codigo' => (string) $proveedorCodigo,
            'proveedor_nombre' => (string) $proveedorNombre,
            'sucursal' => (string) $sucursal,
            'documento' => '',
            'fecha' => '',
            'nc_proveedor' => '',
            'fecha_nc_proveedor' => '',
            'entrada_asociada' => '',
            'moneda' => 'CRC',
            'monto' => '',
            'saldo' => '',
            'monto_conversion' => '',
        ];

        $fechas = [];
        $numeros = [];
        foreach ($celdas as $valor) {
            $valor = trim((string) $valor);
            if ($valor === '') {
                continue;
            }
            if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $valor)) { $fechas[] = $valor; continue; }
            if (self::isNumericValue($valor)) { $numeros[] = $valor; continue; }

            $mayus = mb_strtoupper($valor, 'UTF-8');
            if (in_array($mayus, ['¢', '₡', 'CRC', 'COLONES', '$', 'USD'], true) || strpos($mayus, '$(') === 0) {
                $campos['moneda'] = self::normalizeCurrency($valor);
                continue;
            }
            // El documento es lo primero que parece un número de nota: el
            // "NC-" de siempre, o el número desnudo cuando el prefijo es el
            // que vino mal escrito y por eso la fila cayó aquí.
            if ($campos['documento'] === ''
                && preg_match('/^(N\/?C[-\s]*)?[\dA-Z][\dA-Z-]*$/iu', $valor)) {
                $campos['documento'] = $valor;
            }
        }

        if (isset($fechas[0])) { $campos['fecha'] = $fechas[0]; }
        if (isset($fechas[1])) { $campos['fecha_nc_proveedor'] = $fechas[1]; }
        if (isset($numeros[0])) { $campos['monto'] = $numeros[0]; }
        if (isset($numeros[1])) { $campos['saldo'] = $numeros[1]; }
        if (isset($numeros[2])) { $campos['monto_conversion'] = $numeros[2]; }

        return [
            'fila_origen' => (int) $fila,
            'motivo' => (string) $motivo,
            'celdas' => $celdas,
            'campos' => $campos,
            'firma' => LineaRevision::firma('notas_credito', $celdas, (string) $proveedorCodigo),
        ];
    }

    private static function findDocumentIndex(array $nonEmpty)
    {
        foreach ($nonEmpty as $index => $value) {
            if (preg_match('/^NC-\s*/iu', $value)) {
                return (int) $index;
            }
        }
        return null;
    }

    private static function findCurrencyIndex(array $nonEmpty, $documentIndex)
    {
        foreach ($nonEmpty as $index => $value) {
            if ($index <= $documentIndex) {
                continue;
            }
            $normalized = mb_strtoupper(trim((string) $value), 'UTF-8');
            if (in_array($normalized, ['¢', '₡', 'CRC', 'COLONES', '$', 'USD'], true)
                || strpos($normalized, '$(') === 0) {
                return (int) $index;
            }
        }
        return null;
    }

    private static function dateCellsBetween(array $row, $from, $to)
    {
        $dates = [];
        for ($index = $from + 1; $index < $to; $index++) {
            $value = trim((string) ($row[$index] ?? ''));
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value)) {
                $dates[] = ['index' => $index, 'value' => $value];
            }
        }
        return $dates;
    }

    private static function amountsAfter(array $row, $currencyIndex)
    {
        $amounts = [];
        for ($index = $currencyIndex + 1; $index < count($row); $index++) {
            $value = trim((string) $row[$index]);
            if (self::isNumericValue($value)) {
                $amounts[] = self::parseAmount($value);
            }
        }
        return $amounts;
    }

    private static function joinCells(array $row, $from, $to)
    {
        $parts = [];
        for ($index = $from; $index < $to; $index++) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return trim(implode(' ', $parts));
    }

    private static function parseDate($value)
    {
        $date = DateTime::createFromFormat('!d/m/Y', trim((string) $value));
        if (!$date) {
            throw new Exception('Fecha inválida: ' . $value);
        }
        return $date->format('Y-m-d');
    }

    private static function spanishDate($day, $monthName, $year)
    {
        $key = mb_strtolower(trim((string) $monthName), 'UTF-8');
        $key = strtr($key, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];
        if (!isset($months[$key]) || !checkdate($months[$key], (int) $day, (int) $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $year, $months[$key], (int) $day);
    }

    private static function normalizeCurrency($value)
    {
        $value = mb_strtoupper(trim((string) $value), 'UTF-8');
        return ($value === '$' || $value === 'USD' || strpos($value, '$(') === 0) ? 'USD' : 'CRC';
    }

    private static function isNumericValue($value)
    {
        return preg_match('/^-?[\d.,]+$/', trim((string) $value)) === 1;
    }

    private static function parseAmount($value)
    {
        $value = str_replace([',', ' '], '', trim((string) $value));
        if (!is_numeric($value)) {
            throw new Exception('Monto inválido: ' . $value);
        }
        return round((float) $value, 2);
    }
}
