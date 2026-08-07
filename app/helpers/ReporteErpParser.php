<?php
/**
 * Parser de los reportes PDF del ERP para la verificación de NC de proveedor:
 *
 *  - "Facturas Boleta Local" (bodega Ventas): faltantes y diferencias de
 *    costo al recibir camión. Trae el consecutivo de 20 dígitos de la
 *    factura del proveedor y secciones NOTA CANTIDAD / FALTANTE / NOTA COSTO.
 *  - "Reporte de Devolución a Proveedor" (bodega Cambios): mercadería
 *    dañada/vencida. Trae proveedor fiscal y total con IVA.
 *
 * Diseño desconfiado: todo documento se cuadra contra sus propios totales
 * impresos. Si la suma de líneas no coincide, el resultado sale con
 * ok=false y el detalle en 'errores' — nunca se aceptan datos a medias.
 */

require_once __DIR__ . '/PdfTextoExtractor.php';

class ReporteErpParser
{
    const TOLERANCIA_MONTO = 0.05;
    const TOLERANCIA_CANTIDAD = 0.005;

    /** Extrae el texto del PDF, detecta el formato y lo parsea. */
    public static function parseArchivo($rutaPdf, $binarioPdftotext = null)
    {
        $texto = PdfTextoExtractor::extraer($rutaPdf, $binarioPdftotext);
        $tipo = self::detectarTipo($texto);

        if ($tipo === 'boleta_local') {
            $resultado = self::parseBoletaLocal($texto);
        } elseif ($tipo === 'devolucion_proveedor') {
            $resultado = self::parseDevolucionProveedor($texto);
        } else {
            $resultado = [
                'ok' => false,
                'tipo' => null,
                'errores' => ['Formato de reporte no reconocido (no es Boleta Local ni Devolución a Proveedor).'],
                'advertencias' => [],
            ];
        }

        $resultado['archivo'] = basename($rutaPdf);
        return $resultado;
    }

    public static function detectarTipo($texto)
    {
        if (preg_match('/Reporte de Devoluci.n a Proveedor/iu', $texto)) {
            return 'devolucion_proveedor';
        }
        if (preg_match('/Facturas\s+Boleta\s+Local/iu', $texto)) {
            return 'boleta_local';
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Formato 1: Facturas Boleta Local (bodega Ventas)
    // ------------------------------------------------------------------

    public static function parseBoletaLocal($texto)
    {
        $errores = [];
        $advertencias = [];
        $lineasTexto = preg_split('/\r\n|\r|\n/', $texto);

        $cab = [
            'no_boleta' => self::soloDigitos(self::valorEtiqueta($lineasTexto, '/No\.?\s*Boleta:/iu')),
            'numero_factura' => self::soloDigitos(self::valorEtiqueta($lineasTexto, '/N.{0,3}Factura:/u')),
            'proveedor' => self::valorEtiqueta($lineasTexto, '/Proveedor:/u'),
            'sucursal' => self::valorEtiqueta($lineasTexto, '/Sucursal:/u'),
            'bodega' => self::valorEtiqueta($lineasTexto, '/Bodega:/u'),
            'fecha' => self::normalizarFecha(self::valorEtiqueta($lineasTexto, '/^\s*Fecha:/u')),
            'cantidad_scaneada' => self::numero(self::valorEtiqueta($lineasTexto, '/Cantidad Scaneada:/iu')),
            'cantidad_en_notas' => self::numero(self::valorEtiqueta($lineasTexto, '/Cantidad en Notas:/iu')),
            'cantidad_faltante' => self::numero(self::valorEtiqueta($lineasTexto, '/Cantidad Faltante:/iu')),
            'cantidad_devolver' => self::numero(self::valorEtiqueta($lineasTexto, '/Cantidad Devolver:/iu')),
            'diferencia_costos' => self::numero(self::valorEtiqueta($lineasTexto, '/Diferencia Costos:/iu')),
        ];

        // Secciones y sus líneas. Las columnas de una línea de la boleta:
        // cantidad, costo_bruto, descuento, costo_neto, impuesto, gasto,
        // total, dif_costo, dif_monto_bonif.
        $columnas = ['cantidad', 'costo_bruto', 'descuento', 'costo_neto',
            'impuesto', 'gasto', 'total', 'dif_costo', 'dif_monto_bonif'];
        $nombresSeccion = ['FACTURA', 'NOTA CANTIDAD', 'FALTANTE', 'NOTA COSTO'];

        $secciones = [];
        foreach ($nombresSeccion as $s) {
            $secciones[$s] = [];
        }
        $seccionActual = null;
        $lineaAbierta = null;
        $totalImpreso = null;

        foreach ($lineasTexto as $raw) {
            $trim = self::limpiar($raw);
            if ($trim === '') {
                $lineaAbierta = null;
                continue;
            }

            $up = mb_strtoupper($trim, 'UTF-8');
            if (in_array($up, $nombresSeccion, true)) {
                $seccionActual = $up;
                $lineaAbierta = null;
                continue;
            }

            if (preg_match('/^Total:/iu', $trim)) {
                $tokens = self::tokenizar($trim);
                $numeros = self::colaNumerica($tokens);
                if (count($numeros) >= 9) {
                    $totalImpreso = array_combine($columnas, array_slice($numeros, -9));
                }
                $lineaAbierta = null;
                continue;
            }

            if ($seccionActual === null) {
                continue;
            }

            // Encabezados repetidos por página y pies: no son datos.
            if (preg_match('/^(Grupo BM|Facturas\s+Boleta|Sucursal:|Bodega:|No\.?\s*Boleta|Art.culo\s+Nombre|P.gina\s|Fin del Reporte|Fecha de Im)/iu', $trim)) {
                $lineaAbierta = null;
                continue;
            }

            $tokens = self::tokenizar($trim);
            $numeros = self::colaNumerica($tokens);

            if (count($numeros) >= 9 && preg_match('/^\d{1,14}$/', $tokens[0])) {
                $nombre = implode(' ', array_slice($tokens, 1, count($tokens) - 1 - count($numeros)));
                $linea = array_merge(
                    ['codigo' => $tokens[0], 'nombre' => $nombre],
                    array_combine($columnas, array_slice($numeros, -9))
                );
                $secciones[$seccionActual][] = $linea;
                $lineaAbierta = count($secciones[$seccionActual]) - 1;
                continue;
            }

            // Continuación del nombre (renglón envuelto): indentado y sin números.
            if ($lineaAbierta !== null && count($numeros) === 0 && preg_match('/^\s{4,}\S/', $raw)) {
                $secciones[$seccionActual][$lineaAbierta]['nombre'] =
                    trim($secciones[$seccionActual][$lineaAbierta]['nombre'] . ' ' . $trim);
                continue;
            }

            $advertencias[] = 'Línea no reconocida en sección ' . $seccionActual . ': "' . mb_substr($trim, 0, 80) . '"';
            $lineaAbierta = null;
        }

        // --- Validaciones ---
        if (strlen((string) $cab['numero_factura']) !== 20) {
            $errores[] = 'N° Factura ausente o sin los 20 dígitos esperados: "' . $cab['numero_factura'] . '"';
        }
        if ($cab['proveedor'] === '') {
            $errores[] = 'Proveedor no encontrado en la cabecera.';
        }
        if (empty($secciones['FACTURA'])) {
            $errores[] = 'No se extrajo ninguna línea de la sección FACTURA.';
        }
        if ($totalImpreso === null) {
            $errores[] = 'No se encontró la fila "Total:" impresa; sin ella no se puede cuadrar la extracción.';
        } else {
            $todas = array_merge(...array_values($secciones));
            foreach ($columnas as $col) {
                $suma = 0.0;
                foreach ($todas as $l) {
                    $suma += $l[$col];
                }
                $tol = $col === 'cantidad' ? self::TOLERANCIA_CANTIDAD : self::TOLERANCIA_MONTO;
                if (abs($suma - $totalImpreso[$col]) > $tol) {
                    $errores[] = sprintf(
                        'Descuadre en columna %s: líneas suman %.3f y el reporte imprime %.3f.',
                        $col, $suma, $totalImpreso[$col]
                    );
                }
            }
        }

        // Cruces informativos cabecera↔secciones (no bloquean).
        $sumaNotas = self::sumaColumna($secciones['NOTA CANTIDAD'], 'cantidad');
        if ($cab['cantidad_en_notas'] !== null && abs($sumaNotas - $cab['cantidad_en_notas']) > self::TOLERANCIA_CANTIDAD) {
            $advertencias[] = sprintf('Cantidad en Notas (%.3f) no coincide con NOTA CANTIDAD (%.3f).', $cab['cantidad_en_notas'], $sumaNotas);
        }
        $sumaFaltante = self::sumaColumna($secciones['FALTANTE'], 'cantidad');
        if ($cab['cantidad_faltante'] !== null && abs($sumaFaltante - $cab['cantidad_faltante']) > self::TOLERANCIA_CANTIDAD) {
            $advertencias[] = sprintf('Cantidad Faltante (%.3f) no coincide con FALTANTE (%.3f).', $cab['cantidad_faltante'], $sumaFaltante);
        }

        return array_merge(['ok' => empty($errores), 'tipo' => 'boleta_local'], $cab, [
            'secciones' => $secciones,
            'total_impreso' => $totalImpreso,
            // Montos que el proveedor debería acreditar vía NC (con IVA):
            'nc_esperada_cantidad' => round(self::sumaColumna($secciones['NOTA CANTIDAD'], 'total'), 2),
            'nc_esperada_costo' => round(self::sumaColumna($secciones['NOTA COSTO'], 'total'), 2),
            'errores' => $errores,
            'advertencias' => $advertencias,
        ]);
    }

    // ------------------------------------------------------------------
    // Formato 2: Reporte de Devolución a Proveedor (bodega Cambios)
    // ------------------------------------------------------------------

    public static function parseDevolucionProveedor($texto)
    {
        $errores = [];
        $advertencias = [];
        $lineasTexto = preg_split('/\r\n|\r|\n/', $texto);

        // Proveedor: "<código interno> <razón social>", posiblemente envuelto
        // en varios renglones antes de la línea "Devolución:".
        $proveedorCodigo = '';
        $proveedorNombre = '';
        foreach ($lineasTexto as $i => $raw) {
            if (!preg_match('/Proveedor:\s*(.+)$/u', $raw, $m)) {
                continue;
            }
            $chunk = self::primerChunk($m[1]);
            if (preg_match('/^(\d+)\s+(.*)$/u', $chunk, $mm)) {
                $proveedorCodigo = $mm[1];
                $proveedorNombre = trim($mm[2]);
            } else {
                $proveedorNombre = $chunk;
            }
            for ($j = $i + 1; $j < count($lineasTexto); $j++) {
                if (preg_match('/Devoluci.n:|Usuario:|Art.culo/u', $lineasTexto[$j])) {
                    break;
                }
                if (preg_match('/^\s{4,}(\S.*)$/u', $lineasTexto[$j], $mc)) {
                    $proveedorNombre = trim($proveedorNombre . ' ' . self::primerChunk($mc[1]));
                }
            }
            break;
        }

        $cab = [
            'sucursal' => self::valorEtiqueta($lineasTexto, '/Sucursal:/u'),
            'bodega' => self::valorEtiqueta($lineasTexto, '/Bodega:/u'),
            'proveedor_codigo' => $proveedorCodigo,
            'proveedor_nombre' => $proveedorNombre,
            'devolucion_numero' => self::soloDigitos(self::valorEtiqueta($lineasTexto, '/Devoluci.n:/u')),
            'usuario' => self::valorEtiqueta($lineasTexto, '/Usuario:/u'),
            'estado' => self::valorEtiqueta($lineasTexto, '/Estado:/u'),
            'fecha' => self::normalizarFecha(self::extraerFecha($texto, '/(?<!de )Fecha:\s+(\d{2}\/\d{2}\/\d{4})/u')),
            'fecha_aplicacion' => self::normalizarFecha(self::extraerFecha($texto, '/Fecha Aplicaci.n:\s+(\d{2}\/\d{2}\/\d{4})/u')),
            'observaciones' => self::valorEtiqueta($lineasTexto, '/Observaciones:/u'),
        ];

        // Líneas: cantidad, costo_unitario, descuento, impuesto_unitario, total.
        $columnas = ['cantidad', 'costo_unitario', 'descuento', 'impuesto_unitario', 'total'];
        $lineas = [];
        $lineaAbierta = null;
        $sueltos = [];
        $enTabla = false;

        foreach ($lineasTexto as $raw) {
            $trim = self::limpiar($raw);
            if ($trim === '') {
                $lineaAbierta = null;
                continue;
            }
            if (preg_match('/Art.culo\s+Nombre/iu', $trim)) {
                $enTabla = true;
                $lineaAbierta = null;
                continue;
            }
            // La cabecera (antes de la tabla de artículos) se lee con las
            // etiquetas de arriba; aquí solo interesan las filas de la tabla.
            if (!$enTabla) {
                continue;
            }
            if (preg_match('/^(Grupo BM|Reporte de Devoluci|Sucursal:|Bodega:|Proveedor:|Devoluci.n:|Usuario:|Unitario$|P.gina\s|Observaciones:|Persona que|Fecha)/iu', $trim)) {
                $lineaAbierta = null;
                continue;
            }

            $tokens = self::tokenizar($trim);
            $numeros = self::colaNumerica($tokens);

            if (count($numeros) >= 5 && preg_match('/^\d{1,14}$/', $tokens[0])) {
                $nombre = implode(' ', array_slice($tokens, 1, count($tokens) - 1 - count($numeros)));
                $lineas[] = array_merge(
                    ['codigo' => $tokens[0], 'nombre' => $nombre],
                    array_combine($columnas, array_slice($numeros, -5))
                );
                $lineaAbierta = count($lineas) - 1;
                continue;
            }

            // Totales impresos: renglones que solo contienen un número.
            if (count($tokens) === count($numeros) && count($numeros) >= 1 && !empty($lineas)) {
                foreach ($numeros as $n) {
                    $sueltos[] = $n;
                }
                $lineaAbierta = null;
                continue;
            }

            if ($lineaAbierta !== null && count($numeros) === 0 && preg_match('/^\s{4,}\S/', $raw)) {
                $lineas[$lineaAbierta]['nombre'] = trim($lineas[$lineaAbierta]['nombre'] . ' ' . $trim);
                continue;
            }

            $advertencias[] = 'Línea no reconocida: "' . mb_substr($trim, 0, 80) . '"';
            $lineaAbierta = null;
        }

        // --- Validaciones ---
        if ($cab['devolucion_numero'] === '') {
            $errores[] = 'Número de devolución no encontrado.';
        }
        if ($proveedorNombre === '') {
            $errores[] = 'Proveedor no encontrado en la cabecera.';
        }
        if (empty($lineas)) {
            $errores[] = 'No se extrajo ninguna línea de artículos.';
        }

        $sumaCantidad = self::sumaColumna($lineas, 'cantidad');
        $sumaTotal = self::sumaColumna($lineas, 'total');
        $cantidadImpresa = null;
        $totalImpreso = null;
        foreach ($sueltos as $n) {
            if ($cantidadImpresa === null && abs($n - $sumaCantidad) <= self::TOLERANCIA_CANTIDAD) {
                $cantidadImpresa = $n;
            } elseif ($totalImpreso === null && abs($n - $sumaTotal) <= self::TOLERANCIA_MONTO) {
                $totalImpreso = $n;
            }
        }
        if ($totalImpreso === null) {
            $errores[] = sprintf(
                'Descuadre: las líneas suman %.2f y ningún total impreso lo confirma (impresos: %s).',
                $sumaTotal, $sueltos ? implode(', ', $sueltos) : 'ninguno'
            );
        }
        if ($cantidadImpresa === null && !empty($lineas)) {
            $advertencias[] = sprintf('La cantidad total impresa no confirma la suma de líneas (%.3f).', $sumaCantidad);
        }

        return array_merge(['ok' => empty($errores), 'tipo' => 'devolucion_proveedor'], $cab, [
            'lineas' => $lineas,
            'cantidad_total' => round($sumaCantidad, 3),
            'total' => round($sumaTotal, 2),
            'errores' => $errores,
            'advertencias' => $advertencias,
        ]);
    }

    // ------------------------------------------------------------------
    // Utilitarios
    // ------------------------------------------------------------------

    /** trim() de PHP no incluye el salto de página (\f) que pdftotext emite. */
    private static function limpiar($linea)
    {
        return trim((string) $linea, " \t\r\n\0\x0B\f");
    }

    private static function tokenizar($linea)
    {
        $tokens = preg_split('/\s{2,}/', self::limpiar($linea));
        return is_array($tokens) ? array_values(array_filter($tokens, 'strlen')) : [];
    }

    private static function esNumero($token)
    {
        return (bool) preg_match('/^-?[\d,]*\d\.\d{2,4}$/', $token);
    }

    /** Números al final de la lista de tokens (las columnas de la fila). */
    private static function colaNumerica(array $tokens)
    {
        $numeros = [];
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (!self::esNumero($tokens[$i])) {
                break;
            }
            array_unshift($numeros, self::numero($tokens[$i]));
        }
        return $numeros;
    }

    private static function numero($texto)
    {
        $limpio = str_replace(',', '', trim((string) $texto));
        return $limpio !== '' && is_numeric($limpio) ? (float) $limpio : null;
    }

    private static function soloDigitos($texto)
    {
        return preg_replace('/\D+/', '', (string) $texto);
    }

    /**
     * Valor a la derecha de una etiqueta, cortado en el siguiente bloque de
     * columnas (2+ espacios). Busca la primera línea que contenga la etiqueta.
     */
    private static function valorEtiqueta(array $lineas, $patronEtiqueta)
    {
        foreach ($lineas as $linea) {
            if (preg_match($patronEtiqueta, $linea, $m, PREG_OFFSET_CAPTURE)) {
                $despues = substr($linea, $m[0][1] + strlen($m[0][0]));
                return self::primerChunk($despues);
            }
        }
        return '';
    }

    private static function primerChunk($texto)
    {
        $partes = preg_split('/\s{2,}/', trim((string) $texto));
        return is_array($partes) && $partes !== [] ? trim($partes[0]) : '';
    }

    private static function extraerFecha($texto, $patron)
    {
        return preg_match($patron, $texto, $m) ? $m[1] : '';
    }

    private static function normalizarFecha($texto)
    {
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', (string) $texto, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    private static function sumaColumna(array $lineas, $columna)
    {
        $suma = 0.0;
        foreach ($lineas as $l) {
            $suma += (float) ($l[$columna] ?? 0);
        }
        return $suma;
    }
}
