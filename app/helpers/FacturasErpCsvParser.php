<?php
/**
 * Parser del reporte "Facturas por Proveedor" del ERP (CSV impreso).
 *
 * El archivo no es una tabla: es un reporte por bandas donde el proveedor y
 * la sucursal son encabezados de sección y las facturas cuelgan de ellos.
 *
 *   Proveedor  140000003  3-101-011167 S.A.
 *     Sucursal: CEDI
 *       F- 00200001010000001111  13/06/2026  13/07/2026  Local  ¢  40,390.72  0.00
 *         (línea de continuación con el saldo convertido a colones)
 *       Total ..................................... 694,728.52 / 511,677.56
 *     Total Proveedor ............................ 1,170,223.48 / 511,677.56
 *
 * Diseño desconfiado, igual que ReporteErpParser: cada proveedor y cada
 * sucursal se cuadran contra sus propios totales impresos. Una fila que no
 * se reconozca es un error, no algo que se ignore en silencio.
 *
 * Tres trampas del formato, todas verificadas contra el archivo real:
 *
 *  1. El pie de página se imprime ENCIMA de la fila que venía saliendo, y se
 *     lleva por delante tanto cambios de sucursal como facturas completas:
 *       "Usuario: X | Sucursal | CEDI | Página: 92 | Impreso: ..."
 *       "Usuario: X | F- 001…0151 | 20/07/2026 | Página: 67 | 19/08/2026 | ..."
 *     Por eso los tokens del pie se quitan ANTES de clasificar la fila.
 *  2. La posición de cada columna cambia según la banda (una fila de factura
 *     no alinea con su encabezado ni con la fila de Total), así que todo se
 *     reconoce por patrón y nunca por índice de columna.
 *  3. El número de documento puede venir vacío ("F- ") y llegar impreso en
 *     una línea posterior.
 */

require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/LineaRevision.php';

class FacturasErpCsvParser
{
    /** El reporte redondea sus propios subtotales: hasta un colón es suyo, no nuestro. */
    const TOLERANCIA_TOTAL = 1.00;

    /** Tope defensivo para no cargar un archivo que no es este reporte. */
    const MAX_FILAS = 200000;

    public static function parseArchivo($ruta)
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return self::resultadoVacio(basename((string) $ruta), ['No se pudo leer el archivo.']);
        }
        $raw = file_get_contents($ruta);
        if ($raw === false || $raw === '') {
            return self::resultadoVacio(basename($ruta), ['El archivo está vacío.']);
        }
        return self::parseTexto($raw, basename($ruta));
    }

    public static function parseTexto($raw, $nombreArchivo = '')
    {
        // El ERP exporta en Windows-1252 (¢, Página, Conversión). Convertir
        // primero evita que los nombres de proveedor entren con basura.
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

        $facturas = [];
        $totalesSucursal = [];
        $totalesProveedor = [];
        $noReconocidas = [];
        $revision = [];
        $proveedores = [];
        $impresoEn = null;
        $rangoTexto = null;
        $totalGeneralSaldo = null;

        $proveedorCodigo = '';
        $proveedorNombre = '';
        $sucursal = '';

        foreach ($lineas as $i => $lineaRaw) {
            if (trim($lineaRaw) === '') {
                continue;
            }
            // La metadata viaja dentro del pie que luego se descarta.
            if ($impresoEn === null && preg_match('#Impreso:\s*(\d{2}/\d{2}/\d{4})\s+(\d{2}:\d{2}:\d{2})#u', $lineaRaw, $m)) {
                $impresoEn = self::fechaSql($m[1]) . ' ' . $m[2];
            }
            if ($rangoTexto === null && preg_match('/(Del\s+\d+\s+de\s+.+?al\s+\d+\s+de\s+[^"]+)/u', $lineaRaw, $m)) {
                $rangoTexto = trim($m[1]);
            }

            $c = self::celdas($lineaRaw);
            if (!$c) {
                continue;
            }

            // ── Cambio de proveedor ──
            if (strpos($c[0], 'Proveedor') === 0 && count($c) >= 3 && preg_match('/^\d{4,}$/', $c[1])) {
                $proveedorCodigo = $c[1];
                $proveedorNombre = $c[2];
                $sucursal = '';
                $proveedores[$proveedorCodigo] = $proveedorNombre;
                continue;
            }

            // ── Cambio de sucursal (puede venir incrustado en el pie) ──
            //
            // Se toma la sucursal y se quitan sus dos celdas, pero la fila
            // SIGUE evaluándose. Antes se cortaba aquí con un `continue`, y
            // como el pie de página se imprime encima de la fila que venía
            // saliendo, una fila que traía a la vez el cambio de sucursal y
            // una factura perdía la factura entera, sin error y sin rastro.
            // Es el mismo trato que ya reciben los tokens del pie: se les
            // saca lo que estorba y lo que queda se lee normal.
            $iSuc = array_search('Sucursal', $c, true);
            if ($iSuc !== false && isset($c[$iSuc + 1])) {
                $sucursal = $c[$iSuc + 1];
                array_splice($c, $iSuc, 2);
                if (!$c) {
                    continue;
                }
            }

            // ── Totales impresos: son el checksum del reporte ──
            if ($c[0] === 'Total Proveedor' && count($c) >= 3 && self::esMonto($c[1])) {
                $totalesProveedor[] = [
                    'proveedor' => $proveedorCodigo,
                    'monto' => self::numero($c[1]),
                    'saldo' => self::numero($c[2]),
                ];
                continue;
            }
            // El pie del reporte cierra con el gran total de todo el archivo.
            // Es el checksum más fuerte que hay: cubre las 5.770 facturas de
            // una sola vez. El monto sale cortado por el ancho de la columna
            // ("4,838,830,501."), pero el saldo viene completo.
            if ($c[0] === 'Total General') {
                $montos = array_values(array_filter(array_slice($c, 1), [self::class, 'esMonto']));
                if ($montos) {
                    $totalGeneralSaldo = self::numero(end($montos));
                }
                continue;
            }
            if ($c[0] === 'Total' && count($c) >= 3 && self::esMonto($c[1])) {
                $totalesSucursal[] = [
                    'proveedor' => $proveedorCodigo,
                    'sucursal' => $sucursal,
                    'monto' => self::numero($c[1]),
                    'saldo' => self::numero($c[2]),
                ];
                continue;
            }

            // ── Fila de factura ──
            // El número puede faltar, por eso \S* y no \S+.
            if (preg_match('/^([A-Z]+)-\s*(\S*)$/u', $c[0], $m)
                && count($c) >= 7 && self::esFecha($c[1]) && self::esFecha($c[2])
                && self::esMonto($c[5]) && self::esMonto($c[6])) {
                $documento = $m[2];
                $facturas[] = [
                    'proveedor_codigo' => $proveedorCodigo,
                    'proveedor_nombre' => $proveedorNombre,
                    'sucursal' => $sucursal,
                    'tipo' => $m[1],
                    'documento' => $documento,
                    'numero_corto' => self::numeroCorto($documento),
                    'fecha_emision' => self::fechaSql($c[1]),
                    'fecha_vence' => self::fechaSql($c[2]),
                    'origen' => $c[3],
                    'moneda' => $c[4],
                    'monto' => self::numero($c[5]),
                    'saldo' => self::numero($c[6]),
                    'saldo_colones' => null,
                ];
                continue;
            }

            // ── Continuación: el saldo convertido a colones ──
            if (count($c) === 1 && self::esMonto($c[0])) {
                if ($facturas) {
                    $facturas[count($facturas) - 1]['saldo_colones'] = self::numero($c[0]);
                }
                continue;
            }

            // ── Número de documento impreso en su propia línea ──
            // Las 49 facturas sin número del archivo real traen aquí una
            // ristra de ceros: es el marcador de "sin número" del ERP y hay
            // que descartarlo, o varias facturas terminarían compartiendo
            // el mismo documento. Un número real sí se aprovecha.
            if (count($c) === 1 && preg_match('/^\d{6,}$/', $c[0])) {
                $ultima = count($facturas) - 1;
                if ($ultima >= 0 && $facturas[$ultima]['documento'] === '' && !preg_match('/^0+$/', $c[0])) {
                    $facturas[$ultima]['documento'] = $c[0];
                    $facturas[$ultima]['numero_corto'] = self::numeroCorto($c[0]);
                }
                continue;
            }

            if (self::esRuido($c[0])) {
                continue;
            }

            // ── Lo que no encajó en ningún patrón ──
            // No se tira ni tumba la carga: queda como línea en revisión, con
            // sus celdas crudas y lo poco que se le pudo deducir, para que
            // alguien la corrija y la meta al listado o diga que no va.
            $noReconocidas[] = 'Línea ' . ($i + 1) . ': ' . mb_substr(implode(' | ', array_slice($c, 0, 6)), 0, 120);
            $revision[] = [
                'fila_origen' => $i + 1,
                'motivo' => 'La fila no tiene la forma de una factura del reporte '
                    . '(documento, dos fechas, compra, moneda, monto y saldo).',
                'celdas' => array_values($c),
                'campos' => self::camposProbables($c, $proveedorCodigo, $proveedorNombre, $sucursal),
                'firma' => LineaRevision::firma('facturas_erp', $c, $proveedorCodigo),
            ];
        }

        // La clave se calcula al final: el número puede haber llegado tarde.
        foreach ($facturas as $k => $f) {
            $facturas[$k]['clave'] = self::clave($f);
        }

        $cuadre = self::cuadrar($facturas, $totalesSucursal, $totalesProveedor, $totalGeneralSaldo);

        // Una línea sin clasificar ya no tumba el archivo entero.
        //
        // Tumbarlo era el reflejo correcto mientras no había dónde ponerla:
        // entre importar a medias sin avisar y no importar nada, no importar
        // nada era lo honesto. Ahora hay bandeja de revisión, así que entra
        // todo lo que sí se leyó y la línea rara queda preguntando. Lo que no
        // se afloja es el cuadre contra los totales impresos: si de verdad se
        // perdió plata, la diferencia sigue saliendo por ahí.
        $errores = [];
        if (!$facturas) {
            $errores[] = 'No se reconoció ninguna factura: ¿es el reporte "Facturas por Proveedor"?';
        }
        $claves = array_column($facturas, 'clave');
        $repetidas = count($claves) - count(array_unique($claves));
        if ($repetidas > 0) {
            $errores[] = $repetidas . ' factura(s) comparten identidad dentro del mismo archivo.';
        }

        return [
            'ok' => empty($errores),
            'facturas' => $facturas,
            'incidencias' => self::analizarIncidencias($facturas, $cuadre),
            'meta' => [
                'archivo' => $nombreArchivo,
                'codificacion' => $codificacion,
                'impreso_en' => $impresoEn,
                'rango_texto' => $rangoTexto,
                'lineas' => count($lineas),
                'proveedores' => count($proveedores),
            ],
            'cuadre' => $cuadre,
            'errores' => $errores,
            'no_reconocidas' => $noReconocidas,
            'revision' => $revision,
            // Los totales impresos viajan con el resultado para poder volver
            // a cuadrar cuando se le sumen las líneas rescatadas: si no, una
            // línea que entró por la bandeja seguiría contándose como
            // faltante y el descuadre sonaría todas las semanas.
            'totales' => [
                'sucursal' => $totalesSucursal,
                'proveedor' => $totalesProveedor,
                'general_saldo' => $totalGeneralSaldo,
            ],
        ];
    }

    /**
     * El mismo resultado, pero con las facturas que se rescataron de la
     * bandeja de revisión ya adentro y el cuadre rehecho contra los totales
     * impresos. Es lo que convierte un rescate en parte de la lectura del
     * archivo, y no en un parche colgando por fuera.
     */
    public static function conFacturasRescatadas(array $resultado, array $rescatadas)
    {
        if (!$rescatadas) {
            return $resultado;
        }

        $resultado['facturas'] = array_merge($resultado['facturas'], array_values($rescatadas));
        $totales = $resultado['totales'] ?? ['sucursal' => [], 'proveedor' => [], 'general_saldo' => null];
        $resultado['cuadre'] = self::cuadrar(
            $resultado['facturas'],
            $totales['sucursal'] ?? [],
            $totales['proveedor'] ?? [],
            $totales['general_saldo'] ?? null
        );
        $resultado['incidencias'] = self::analizarIncidencias($resultado['facturas'], $resultado['cuadre']);

        return $resultado;
    }

    /**
     * Lo que se le puede deducir a una fila que no encajó, para que quien la
     * revise no tenga que teclearla entera: se reconoce cada dato por su
     * forma, sin suponer en qué columna está. Lo que no se reconozca queda
     * vacío y lo llena la persona.
     */
    private static function camposProbables(array $c, $proveedorCodigo, $proveedorNombre, $sucursal)
    {
        $campos = [
            'proveedor_codigo' => (string) $proveedorCodigo,
            'proveedor_nombre' => (string) $proveedorNombre,
            'sucursal' => (string) $sucursal,
            'tipo' => 'F',
            'documento' => '',
            'fecha_emision' => '',
            'fecha_vence' => '',
            'origen' => '',
            'moneda' => '¢',
            'monto' => '',
            'saldo' => '',
        ];

        $fechas = [];
        $montos = [];
        foreach ($c as $celda) {
            $celda = trim((string) $celda);
            if ($celda === '') {
                continue;
            }
            if ($campos['documento'] === '' && preg_match('/^([A-Z]+)-\s*(\S*)$/u', $celda, $m)) {
                $campos['tipo'] = $m[1];
                $campos['documento'] = $m[2];
                continue;
            }
            if (self::esFecha($celda)) { $fechas[] = $celda; continue; }
            if (self::esMonto($celda)) { $montos[] = $celda; continue; }
            if ($celda === '¢' || strpos($celda, '$') === 0) { $campos['moneda'] = $celda; continue; }
            if ($campos['origen'] === '' && preg_match('/^[A-Za-zÁÉÍÓÚÑáéíóúñ]{4,10}$/u', $celda)) {
                $campos['origen'] = $celda;
            }
        }

        if (isset($fechas[0])) { $campos['fecha_emision'] = $fechas[0]; }
        if (isset($fechas[1])) { $campos['fecha_vence'] = $fechas[1]; }
        if (isset($montos[0])) { $campos['monto'] = $montos[0]; }
        if (isset($montos[1])) { $campos['saldo'] = $montos[1]; }

        return $campos;
    }

    // ------------------------------------------------------------------
    // Incidencias
    // ------------------------------------------------------------------

    /** Severidad de cada tipo: 'alerta' pide revisión, 'aviso' solo informa. */
    const TIPOS_INCIDENCIA = [
        'numero_duplicado'    => ['alerta', 'Número repetido en el mismo proveedor'],
        'descuadre_total'     => ['alerta', 'Un total impreso no cuadra con lo leído'],
        'saldo_mayor_monto'   => ['alerta', 'El saldo supera el monto de la factura'],
        'sin_numero'          => ['aviso',  'Factura sin número de documento'],
        'vence_antes_emision' => ['aviso',  'Vence antes de haber sido emitida'],
        'vencimiento_lejano'  => ['aviso',  'Vencimiento a más de 180 días'],
        'moneda_extranjera'   => ['aviso',  'Factura en moneda distinta al colón'],
        'saldo_modificado'    => ['aviso',  'El saldo cambió respecto a la carga anterior'],
    ];

    /** Días de crédito por encima de los cuales el vencimiento se considera raro. */
    const DIAS_VENCIMIENTO_LEJANO = 180;

    /**
     * Problemas detectables leyendo el archivo. No impiden importar: se
     * guardan para que alguien pueda revisarlos después. Las incidencias de
     * saldo modificado no salen de aquí, sino de comparar contra lo guardado.
     */
    public static function analizarIncidencias(array $facturas, array $cuadre = [])
    {
        $inc = [];

        $agregar = function ($tipo, array $f, $detalle, array $extra = []) use (&$inc) {
            $inc[] = array_merge([
                'tipo' => $tipo,
                'severidad' => self::TIPOS_INCIDENCIA[$tipo][0] ?? 'aviso',
                'proveedor_codigo' => (string) ($f['proveedor_codigo'] ?? ''),
                'proveedor_nombre' => (string) ($f['proveedor_nombre'] ?? ''),
                'documento' => (string) ($f['documento'] ?? ''),
                'clave' => (string) ($f['clave'] ?? ''),
                'fecha_emision' => $f['fecha_emision'] ?? null,
                'monto' => (float) ($f['monto'] ?? 0),
                'saldo_anterior' => null,
                'saldo_nuevo' => isset($f['saldo']) ? (float) $f['saldo'] : null,
                'detalle' => $detalle,
            ], $extra);
        };

        // Un mismo número dentro del mismo proveedor: son documentos distintos
        // (los separa la fecha), pero un consecutivo electrónico no debería
        // repetirse nunca, así que conviene mirarlos.
        $grupos = [];
        foreach ($facturas as $f) {
            if ((string) $f['documento'] !== '') {
                $grupos[$f['proveedor_codigo'] . '|' . $f['documento']][] = $f;
            }
        }
        foreach ($grupos as $repetidas) {
            if (count($repetidas) < 2) {
                continue;
            }
            $fechas = implode(', ', array_map(function ($x) {
                return self::fechaCorta($x['fecha_emision']);
            }, $repetidas));
            $pendiente = 0.0;
            foreach ($repetidas as $x) { $pendiente += (float) $x['saldo']; }
            foreach ($repetidas as $x) {
                $agregar('numero_duplicado', $x, sprintf(
                    'El documento aparece %d veces (emitidas %s). Saldo pendiente sumado: %s.',
                    count($repetidas), $fechas, number_format($pendiente, 2)
                ));
            }
        }

        foreach ($facturas as $f) {
            if ((string) $f['documento'] === '') {
                $agregar('sin_numero', $f, 'El reporte no imprime número para esta factura; se identifica por proveedor, fecha y monto.');
            }
            if (trim((string) $f['moneda']) !== '' && trim((string) $f['moneda']) !== '¢') {
                $agregar('moneda_extranjera', $f, sprintf(
                    'Moneda "%s": el monto no está en colones y el total del reporte sí lo convierte.',
                    trim((string) $f['moneda'])
                ));
            }
            if ((float) $f['saldo'] > (float) $f['monto'] + 0.01) {
                $agregar('saldo_mayor_monto', $f, sprintf(
                    'Saldo %s sobre un monto de %s.',
                    number_format((float) $f['saldo'], 2), number_format((float) $f['monto'], 2)
                ));
            }
            if (!empty($f['fecha_emision']) && !empty($f['fecha_vence'])) {
                $emision = strtotime($f['fecha_emision']);
                $vence = strtotime($f['fecha_vence']);
                if ($vence < $emision) {
                    $agregar('vence_antes_emision', $f, sprintf(
                        'Emitida el %s pero vence el %s.',
                        self::fechaCorta($f['fecha_emision']), self::fechaCorta($f['fecha_vence'])
                    ));
                } elseif (($vence - $emision) / 86400 > self::DIAS_VENCIMIENTO_LEJANO) {
                    $agregar('vencimiento_lejano', $f, sprintf(
                        'Plazo de %d días (emitida el %s, vence el %s).',
                        (int) round(($vence - $emision) / 86400),
                        self::fechaCorta($f['fecha_emision']), self::fechaCorta($f['fecha_vence'])
                    ));
                }
            }
        }

        foreach ($cuadre['descuadres'] ?? [] as $d) {
            $inc[] = [
                'tipo' => 'descuadre_total',
                'severidad' => 'alerta',
                'proveedor_codigo' => (string) $d['proveedor'],
                'proveedor_nombre' => '',
                'documento' => '',
                'clave' => '',
                'fecha_emision' => null,
                'monto' => (float) $d['leido_monto'],
                'saldo_anterior' => null,
                'saldo_nuevo' => null,
                'detalle' => sprintf(
                    'Total de %s%s: leído %s contra %s impreso (diferencia %s).',
                    $d['ambito'],
                    $d['sucursal'] !== '' ? ' ' . $d['sucursal'] : '',
                    number_format($d['leido_monto'], 2),
                    number_format($d['impreso_monto'], 2),
                    number_format($d['diferencia'], 2)
                ),
            ];
        }

        return $inc;
    }

    /**
     * Identidad de una incidencia entre cargas, para poder descartarla de
     * forma permanente. Las incidencias de condición (una factura sin número
     * lo seguirá estando) mantienen la misma firma carga tras carga; las de
     * suceso (un saldo que cambió) incluyen los valores, así que un cambio
     * distinto genera una firma nueva y vuelve a aparecer.
     */
    public static function firmaIncidencia(array $i)
    {
        $tipo = (string) ($i['tipo'] ?? '');
        $base = (string) ($i['clave'] ?? '');
        if ($base === '') {
            $base = (string) ($i['proveedor_codigo'] ?? '') . ':' . (string) ($i['documento'] ?? '');
        }
        if ($tipo === 'saldo_modificado') {
            $base .= '|' . number_format((float) ($i['saldo_anterior'] ?? 0), 2, '.', '')
                   . '>' . number_format((float) ($i['saldo_nuevo'] ?? 0), 2, '.', '');
        }
        return mb_substr($tipo . '|' . $base, 0, 220);
    }

    private static function fechaCorta($sql)
    {
        $ts = $sql ? strtotime((string) $sql) : false;
        return $ts !== false ? date('d/m/Y', $ts) : '—';
    }

    // ------------------------------------------------------------------
    // Cuadre contra los totales impresos
    // ------------------------------------------------------------------

    /**
     * Suma las facturas leídas y las compara con cada Total impreso. Una
     * diferencia de hasta un colón es el redondeo del propio reporte; por
     * encima de eso significa que se perdió (o se duplicó) alguna fila.
     */
    private static function cuadrar(array $facturas, array $totalesSucursal, array $totalesProveedor, $totalGeneralSaldo = null)
    {
        $porProveedor = [];
        $porSucursal = [];
        foreach ($facturas as $f) {
            $p = $f['proveedor_codigo'];
            $s = $p . '|' . $f['sucursal'];
            if (!isset($porProveedor[$p])) { $porProveedor[$p] = ['monto' => 0.0, 'saldo' => 0.0, 'mixta' => false]; }
            if (!isset($porSucursal[$s])) { $porSucursal[$s] = ['monto' => 0.0, 'saldo' => 0.0, 'mixta' => false]; }
            $porProveedor[$p]['monto'] += $f['monto'];
            $porProveedor[$p]['saldo'] += $f['saldo'];
            $porSucursal[$s]['monto'] += $f['monto'];
            $porSucursal[$s]['saldo'] += $f['saldo'];
            if (!self::enColones($f['moneda'])) {
                $porProveedor[$p]['mixta'] = true;
                $porSucursal[$s]['mixta'] = true;
            }
        }

        $descuadres = [];
        $redondeos = 0;
        $verificados = 0;
        $noComparables = 0;

        $comparar = function ($t, $leido, $ambito, $sucursal) use (&$descuadres, &$redondeos, &$noComparables) {
            // Un grupo con facturas en dólares no se puede cuadrar contra su
            // total impreso: el reporte suma los dólares ya convertidos a
            // colones y aquí no hay tipo de cambio con qué reproducirlo. En
            // los reportes reales aparece en la sucursal de un proveedor que
            // factura en las dos monedas: su total impreso es la suma de los
            // colones más los dólares convertidos, y nunca coincide con la
            // suma en crudo. Compararlos igual gritaba "no cuadra" en todas
            // las cargas por algo que sí cuadra, y una alarma que siempre
            // suena no es una alarma. Se cuentan aparte para que tampoco se
            // ignoren en silencio.
            if (!empty($leido['mixta'])) {
                $noComparables++;
                return;
            }
            $dif = max(abs($leido['monto'] - $t['monto']), abs($leido['saldo'] - $t['saldo']));
            if ($dif <= 0.01) { return; }
            if ($dif <= self::TOLERANCIA_TOTAL) { $redondeos++; return; }
            $descuadres[] = [
                'ambito' => $ambito,
                'proveedor' => $t['proveedor'],
                'sucursal' => $sucursal,
                'leido_monto' => round($leido['monto'], 2),
                'impreso_monto' => $t['monto'],
                'diferencia' => round($dif, 2),
            ];
        };

        foreach ($totalesSucursal as $t) {
            $verificados++;
            $comparar(
                $t,
                $porSucursal[$t['proveedor'] . '|' . $t['sucursal']] ?? ['monto' => 0.0, 'saldo' => 0.0, 'mixta' => false],
                'sucursal',
                $t['sucursal']
            );
        }

        foreach ($totalesProveedor as $t) {
            $verificados++;
            $comparar(
                $t,
                $porProveedor[$t['proveedor']] ?? ['monto' => 0.0, 'saldo' => 0.0, 'mixta' => false],
                'proveedor',
                ''
            );
        }

        // Cierre global: el saldo es la columna que importa y el reporte lo
        // imprime completo, así que se compara sin tolerancia de redondeo.
        $saldoLeido = round(array_sum(array_column($facturas, 'saldo')), 2);
        $saldoGeneralOk = null;
        if ($totalGeneralSaldo !== null) {
            $verificados++;
            $saldoGeneralOk = abs($saldoLeido - $totalGeneralSaldo) <= 0.01;
            if (!$saldoGeneralOk) {
                $descuadres[] = [
                    'ambito' => 'total_general',
                    'proveedor' => '',
                    'sucursal' => '',
                    'leido_monto' => $saldoLeido,
                    'impreso_monto' => $totalGeneralSaldo,
                    'diferencia' => round(abs($saldoLeido - $totalGeneralSaldo), 2),
                ];
            }
        }

        return [
            'ok' => empty($descuadres),
            'verificados' => $verificados,
            'exactos' => $verificados - $redondeos - $noComparables - count($descuadres),
            'redondeos' => $redondeos,
            'no_comparables' => $noComparables,
            'descuadres' => $descuadres,
            'saldo_leido' => $saldoLeido,
            'saldo_general_impreso' => $totalGeneralSaldo,
            'saldo_general_ok' => $saldoGeneralOk,
        ];
    }

    /** Si el símbolo de moneda del reporte es el colón. */
    private static function enColones($moneda)
    {
        $moneda = trim((string) $moneda);
        return $moneda === '' || $moneda === '¢' || $moneda === '₡'
            || mb_strtoupper($moneda, 'UTF-8') === 'CRC';
    }

    // ------------------------------------------------------------------
    // Utilitarios
    // ------------------------------------------------------------------

    /**
     * Celdas con contenido de una fila, sin los tokens del pie de página.
     * Se limpian aquí porque el pie se superpone sobre filas de datos y, si
     * se descartara la línea entera, se perderían facturas completas.
     */
    private static function celdas($linea)
    {
        $out = [];
        foreach (str_getcsv($linea, ',', '"', '\\') as $celda) {
            $celda = trim((string) $celda);
            if ($celda === '' || preg_match('/^(Usuario:|P.gina:|Impreso:)/u', $celda)) {
                continue;
            }
            $out[] = $celda;
        }
        return $out;
    }

    /** Encabezados que el reporte repite en cada página. */
    private static function esRuido($primera)
    {
        return (bool) preg_match(
            '/^(Grupo BM|Facturas por Proveedor|Del \d+ de |Monto Moneda|Todos los Proveedores|Conversi|Documento|Fecha|Vence|Compra|Moneda|Monto|Saldo|Colones|Total General|Fin del)/u',
            $primera
        );
    }

    private static function esMonto($v)
    {
        return (bool) preg_match('/^-?[\d,]*\d\.\d{2}$/', (string) $v);
    }

    private static function esFecha($v)
    {
        return (bool) preg_match('#^\d{2}/\d{2}/\d{4}$#', (string) $v);
    }

    private static function numero($v)
    {
        return (float) str_replace(',', '', (string) $v);
    }

    private static function fechaSql($ddmmyyyy)
    {
        if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) $ddmmyyyy, $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
            ? $m[3] . '-' . $m[2] . '-' . $m[1]
            : null;
    }

    /**
     * Solo el consecutivo electrónico de 20 dígitos cruza contra los XML.
     * Es pública porque una factura rescatada de la bandeja de revisión tiene
     * que calcularlo igual que una leída del archivo: si no, entraría sin
     * número corto y nunca encontraría su comprobante.
     */
    public static function numeroCorto($documento)
    {
        return preg_match('/^\d{20}$/', (string) $documento)
            ? NumeroFactura::xmlOchoDigitos($documento)
            : null;
    }

    /**
     * Identidad estable de una factura entre cargas. El documento por sí solo
     * no alcanza: se repite dentro del mismo proveedor con distinta fecha de
     * emisión, y hay facturas que el reporte imprime sin número.
     */
    public static function clave(array $f)
    {
        $prov = (string) $f['proveedor_codigo'];
        $fecha = (string) ($f['fecha_emision'] ?? '');
        if ((string) $f['documento'] !== '') {
            return $prov . '|' . $f['documento'] . '|' . $fecha;
        }
        return $prov . '|SINDOC|' . $fecha . '|' . number_format((float) $f['monto'], 2, '.', '');
    }

    private static function resultadoVacio($archivo, array $errores)
    {
        return [
            'ok' => false,
            'facturas' => [],
            'incidencias' => [],
            'meta' => ['archivo' => $archivo, 'codificacion' => null, 'impreso_en' => null,
                       'rango_texto' => null, 'lineas' => 0, 'proveedores' => 0],
            'cuadre' => ['ok' => false, 'verificados' => 0, 'exactos' => 0, 'redondeos' => 0,
                         'no_comparables' => 0, 'descuadres' => [], 'saldo_leido' => 0.0,
                         'saldo_general_impreso' => null, 'saldo_general_ok' => null],
            'errores' => $errores,
            'no_reconocidas' => [],
            'revision' => [],
            'totales' => ['sucursal' => [], 'proveedor' => [], 'general_saldo' => null],
        ];
    }
}
