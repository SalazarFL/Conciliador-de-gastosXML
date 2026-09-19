<?php
/**
 * Parser del reporte "Lista de Proveedores" del ERP (CSV impreso).
 *
 * Para qué sirve: es la única fuente que dice, sin adivinar, a qué cédula
 * pertenece cada código del ERP. Hasta ahora el sistema lo deducía de los
 * emparejamientos que cuadraban de monto, y eso falla justo donde más duele:
 * proveedores chicos que facturan cifras redondas (₡5.000) y cuyo consecutivo
 * choca por los últimos ocho dígitos con el de otro. Un solo proveedor llegó a
 * quedar enganchado a cuatro códigos que no eran suyos.
 *
 * El reporte tiene la misma forma que "Facturas por Proveedor": bandas, pie de
 * página cada tantas filas, y —la trampa de siempre— las columnas NO están en
 * la misma posición en todas las filas. En el archivo real, el nombre cae en
 * la celda 5 en unas filas y en la 4 en otras. Por eso se lee por patrón:
 * primero se saca la cédula (lo que parece una cédula), después el nombre (lo
 * primero que queda y no es un número suelto).
 *
 * Dos avisos sobre lo que este archivo NO sirve para traer:
 *
 *  1. El correo viene cortado por el ancho de la columna —de 168 direcciones
 *     del archivo real, 156 llegaban partidas ("st_cald91@hotm")— y además la
 *     columna trae teléfonos y nombres de contacto mezclados. No se lee.
 *  2. El nombre también viene cortado ("ASOCIACION DE PROD" por "ASOCIACION DE
 *     PROD AGROPECUARIOS AGUILA PEJIBAYE"). Se lee igual, pero solo para poder
 *     mostrarlo: el nombre bueno es el del reporte de facturas.
 *
 * Lo que sí llega entero, que es lo único que importa acá, es el par
 * código + cédula.
 */
class ProveedoresErpCsvParser
{
    /** Tope defensivo para no leer un archivo que no es este reporte. */
    const MAX_FILAS = 100000;

    /** El ERP imprime un punto donde no hay dato. */
    const VACIO = '.';

    public static function parseArchivo($ruta)
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return self::resultadoVacio(basename((string) $ruta), ['No se pudo leer el archivo.']);
        }
        $raw = file_get_contents($ruta);
        if ($raw === false || $raw === '') {
            return self::resultadoVacio(basename((string) $ruta), ['El archivo está vacío.']);
        }
        return self::parseTexto($raw, basename($ruta));
    }

    public static function parseTexto($raw, $nombreArchivo = '')
    {
        // El ERP exporta en Windows-1252: sin convertir, "Código" y "Teléfono"
        // entran con basura y los nombres con tilde quedan rotos.
        $codificacion = mb_check_encoding($raw, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        if ($codificacion !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $lineas = preg_split('/\r\n|\r|\n/', $raw);
        if (count($lineas) > self::MAX_FILAS) {
            return self::resultadoVacio($nombreArchivo, [
                'El archivo supera las ' . number_format(self::MAX_FILAS, 0, '.', ',') . ' líneas admitidas.',
            ]);
        }

        $proveedores = [];
        $sinCedula = [];
        $repetidos = [];
        $noReconocidas = [];
        $grupos = [];
        $impresoEn = null;

        foreach ($lineas as $i => $lineaRaw) {
            if (trim($lineaRaw) === '') {
                continue;
            }
            if ($impresoEn === null
                && preg_match('#Impreso:\s*(\d{2}/\d{2}/\d{4})\s+(\d{2}:\d{2}:\d{2})#u', $lineaRaw, $m)) {
                $impresoEn = self::fechaSql($m[1]) . ' ' . $m[2];
            }

            $c = self::celdas($lineaRaw);
            if (!$c) {
                continue;
            }

            // El grupo es una banda, no un dato de la fila: se anota para
            // poder decir después qué trae el archivo y qué no.
            $iGrupo = array_search('Grupo:', $c, true);
            if ($iGrupo !== false) {
                $nombre = trim((string) ($c[$iGrupo + 1] ?? ''));
                if ($nombre !== '') {
                    $grupos[$nombre] = true;
                }
                continue;
            }

            if (self::esRuido($c)) {
                continue;
            }

            // ── Fila de proveedor ──
            // El código del ERP son nueve dígitos y es lo primero de la fila;
            // es lo que la distingue de cualquier otra banda del reporte.
            $codigo = self::codigoDe($c);
            if ($codigo === '') {
                $noReconocidas[] = 'Línea ' . ($i + 1) . ': '
                    . mb_substr(implode(' | ', array_slice($c, 0, 6)), 0, 120);
                continue;
            }

            $campos = self::camposDe($c, $codigo);

            if (isset($proveedores[$codigo])) {
                // No debería pasar nunca —el código es la llave del ERP— pero
                // si pasa, quedarse con el primero en silencio sería elegir a
                // ciegas cuál de las dos cédulas manda.
                $repetidos[] = $codigo;
                continue;
            }

            if ($campos['cedula'] === '') {
                // Sin cédula no hay nada que enseñarle al mapa: el código se
                // queda como estaba. Se cuenta para poder decirlo.
                $sinCedula[] = ['codigo' => $codigo, 'nombre' => $campos['nombre'], 'fila' => $i + 1];
                continue;
            }

            $proveedores[$codigo] = [
                'codigo' => $codigo,
                'cedula' => $campos['cedula'],
                'nombre' => $campos['nombre'],
                'fila'   => $i + 1,
            ];
        }

        $errores = [];
        if (!$proveedores) {
            $errores[] = 'No se reconoció ningún proveedor: ¿es el reporte "Lista de Proveedores"?';
        }

        /*
         * Varios códigos con la misma cédula: hay que preguntarlo.
         *
         * Son de dos clases y el archivo no las distingue. Unas son puntos de
         * venta de la misma empresa —CEDI, MAYOREO, PROMOCIONES— y las tres
         * filas están bien. Otras son basura: "ALPELMUSA S.A" y "ALPEMUSA
         * S.A." con la misma cédula es un error de dedo que dio de alta dos
         * proveedores. Meterlas todas deja la cédula sin poder identificar a
         * nadie; descartarlas a ciegas borra códigos buenos.
         *
         * Se entregan con nombre y fila para que quien mire pueda decidir, y
         * la importación no sigue hasta que decida.
         */
        $porCedula = [];
        foreach ($proveedores as $p) {
            $porCedula[$p['cedula']][] = $p;
        }
        $compartidas = array_filter($porCedula, function ($filas) { return count($filas) > 1; });

        return [
            'ok' => empty($errores),
            'proveedores' => array_values($proveedores),
            'errores' => $errores,
            'sin_cedula' => $sinCedula,
            'codigos_repetidos' => array_values(array_unique($repetidos)),
            'cedulas_compartidas' => $compartidas,
            'no_reconocidas' => $noReconocidas,
            'meta' => [
                'archivo' => $nombreArchivo,
                'codificacion' => $codificacion,
                'impreso_en' => $impresoEn,
                'lineas' => count($lineas),
                'grupos' => array_keys($grupos),
            ],
        ];
    }

    // ------------------------------------------------------------------

    /**
     * El código del ERP: nueve dígitos, en las primeras celdas de la fila.
     *
     * Se busca por forma y no en una posición fija porque el reporte corre las
     * columnas de una fila a otra, igual que el de facturas.
     */
    private static function codigoDe(array $c)
    {
        foreach (array_slice($c, 0, 3) as $celda) {
            if (preg_match('/^\d{9}$/', $celda)) {
                return $celda;
            }
        }
        return '';
    }

    /**
     * Cédula y nombre de una fila ya reconocida.
     *
     * El orden importa: primero se saca la cédula y se la quita de en medio,
     * porque si no el nombre de las sociedades que se llaman por su cédula
     * —"3-101-011167 S.A."— se confundiría con ella.
     */
    private static function camposDe(array $c, $codigo)
    {
        $resto = [];
        $saltado = false;
        foreach ($c as $celda) {
            if (!$saltado && $celda === $codigo) { $saltado = true; continue; }
            if ($celda === '' || $celda === self::VACIO) { continue; }
            $resto[] = $celda;
        }

        $cedula = '';
        foreach ($resto as $k => $celda) {
            // Con guiones o sin ellos: en el archivo real hay una fila que la
            // trae escrita "3-101-155686" y sin limpiarla ese proveedor se
            // quedaba sin cédula y con el nombre corrido de columna.
            $limpia = preg_replace('/\D/', '', $celda);
            if ($limpia !== '' && preg_match('/^[\d-]+$/', $celda) && strlen($limpia) >= 9 && strlen($limpia) <= 12) {
                $cedula = $limpia;
                array_splice($resto, $k, 1);
                break;
            }
        }

        $nombre = '';
        foreach ($resto as $celda) {
            // Lo que queda y no es un número suelto: los días de crédito (30)
            // y algún teléfono se cuelan en estas celdas.
            if (!preg_match('/^\d{1,4}$/', $celda)) {
                $nombre = $celda;
                break;
            }
        }

        return ['cedula' => $cedula, 'nombre' => $nombre];
    }

    /** Celdas con contenido de una fila. */
    private static function celdas($linea)
    {
        $c = str_getcsv($linea, ',', '"', '\\');
        if (!is_array($c)) {
            return [];
        }
        $limpias = [];
        foreach ($c as $celda) {
            $celda = trim((string) $celda);
            if ($celda !== '') {
                $limpias[] = $celda;
            }
        }
        return $limpias;
    }

    /** Encabezados, títulos y pies de página: todo lo que no es un proveedor. */
    private static function esRuido(array $c)
    {
        $primera = $c[0] ?? '';
        // El título de la empresa y el del reporte se reimprimen en cada
        // página. Sin nombrarlos acá, las 32 páginas del archivo real dejaban
        // 32 "líneas que no se pudieron leer" que no son ningún problema.
        if ($primera === 'Código' || $primera === 'Lista de Proveedores'
            || preg_match('/^Grupo [A-Z]/u', $primera)) {
            return true;
        }
        foreach ($c as $celda) {
            if (strpos($celda, 'Usuario:') === 0
                || strpos($celda, 'Página:') === 0
                || strpos($celda, 'Impreso:') === 0) {
                return true;
            }
        }
        // El encabezado de columnas y el título de la empresa no traen código.
        return false;
    }

    private static function fechaSql($ddmmyyyy)
    {
        $p = explode('/', trim((string) $ddmmyyyy));
        return count($p) === 3 ? sprintf('%04d-%02d-%02d', $p[2], $p[1], $p[0]) : null;
    }

    private static function resultadoVacio($archivo, array $errores)
    {
        return [
            'ok' => false,
            'proveedores' => [],
            'errores' => $errores,
            'sin_cedula' => [],
            'codigos_repetidos' => [],
            'cedulas_compartidas' => [],
            'no_reconocidas' => [],
            'meta' => [
                'archivo' => $archivo,
                'codificacion' => 'UTF-8',
                'impreso_en' => null,
                'lineas' => 0,
                'grupos' => [],
            ],
        ];
    }
}
