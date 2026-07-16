<?php
require_once __DIR__ . '/../helpers/XlsxWriter.php';

class ReportesController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        try {
            $estados = $this->loadModel('Conciliacion')->getEstadoMap();
        } catch (Throwable $e) {
            $estados = [];
        }

        $this->render('reportes/index', [
            'title'   => 'Reportes - XMLConcilia',
            'estados' => $estados,
        ]);
    }

    public function importaciones()
    {
        try {
            $tipo    = $this->getParam('tipo', 'xml');
            $tipoImp = $tipo === 'gastos' ? 'gastos' : 'xml';
            $rows    = $this->loadModel('Importacion')->getAllByTipo($tipoImp, 100);

            $this->json([
                'success' => true,
                'data'    => array_map(fn($r) => [
                    'id'                 => (int) $r['id'],
                    'archivo_origen'     => $r['archivo_origen'],
                    'fecha_importacion'  => $r['fecha_importacion'],
                    'registros_exitosos' => (int) $r['registros_exitosos'],
                    'registros_fallidos' => (int) $r['registros_fallidos'],
                ], $rows),
            ]);
        } catch (Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function preview()
    {
        try {
            $filtros = $this->parseFiltros();
            $rows    = $this->obtenerDatos($filtros);
            $tipo    = $filtros['tipo'];
            $headers = $this->headersParaTipo($tipo);
            $mapped  = $this->mapRowsParaTipo($rows, $tipo);
            $summary = $this->calcularSummary($rows, $tipo);

            $this->json([
                'success'   => true,
                'total'     => count($mapped),
                'headers'   => $headers,
                'rows'      => array_slice($mapped, 0, 500),
                'truncated' => count($mapped) > 500,
                'summary'   => $summary,
            ]);
        } catch (Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportar()
    {
        try {
            $filtros = $this->parseFiltros();
            $rows    = $this->obtenerDatos($filtros);

            if (empty($rows)) {
                $this->redirectWithMessage($this->url('/reportes'), 'No hay datos para exportar con esos filtros.', 'warning');
                return;
            }

            $tipo    = $filtros['tipo'];
            $headers = $this->headersParaTipo($tipo);
            $mapped  = $this->mapRowsParaTipo($rows, $tipo);

            // Apply column selection
            $columnas = $filtros['columnas'];
            if (!empty($columnas)) {
                $maxCol  = count($headers) - 1;
                $indices = array_values(array_filter($columnas, fn($i) => $i >= 0 && $i <= $maxCol));
                if (!empty($indices)) {
                    $headers = array_values(array_map(fn($i) => $headers[$i], $indices));
                    $mapped  = array_map(
                        fn($row) => array_values(array_map(fn($i) => $row[$i] ?? '', $indices)),
                        $mapped
                    );
                }
            }

            $sheetName = $tipo === 'facturas' ? 'Facturas' : ($tipo === 'gastos' ? 'Gastos' : 'Conciliacion');
            $fileName  = 'reporte_' . $sheetName . '_' . date('Ymd_His') . '.xlsx';

            $cellStyles = [];
            $colWidths  = [];

            if ($tipo === 'conciliacion') {
                $hallazgosIdx = array_search('Hallazgos', $headers);
                if ($hallazgosIdx !== false) {
                    $colWidths[$hallazgosIdx] = 45;
                    foreach ($mapped as $ri => $row) {
                        $val = $row[$hallazgosIdx] ?? '';
                        if ($val === 'Sin diferencias') {
                            $cellStyles[$ri][$hallazgosIdx] = 3;
                        } elseif ($val !== '') {
                            $cellStyles[$ri][$hallazgosIdx] = 2;
                        }
                    }
                }
            }

            $tmpFile = XlsxWriter::generate($headers, $mapped, $sheetName, $cellStyles, $colWidths);
            XlsxWriter::send($tmpFile, $fileName);
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/reportes'), 'Error al exportar: ' . $e->getMessage(), 'error');
        }
    }

    public function estadisticas()
    {
        try {
            $this->json([
                'success' => true,
                'data'    => $this->loadModel('Conciliacion')->getEstadisticas(),
            ]);
        } catch (Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Parseo de filtros ────────────────────────────────────────────

    private function parseFiltros(): array
    {
        $conciliacionId = (int) $this->getParam('conciliacion_id', '0');

        $impIdsRaw = $_GET['importaciones_ids'] ?? '';
        if (is_array($impIdsRaw)) {
            $impIds = array_values(array_filter(array_map('intval', $impIdsRaw), fn($v) => $v > 0));
        } elseif ($impIdsRaw !== '') {
            $impIds = array_values(array_filter(array_map('intval', explode(',', (string) $impIdsRaw)), fn($v) => $v > 0));
        } else {
            $impIds = [];
        }

        $columnasRaw = $this->getParam('columnas', '');
        $columnas    = $columnasRaw !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $columnasRaw)), fn($v) => $v >= 0))
            : [];

        return [
            'tipo'              => $this->getParam('tipo', 'conciliacion'),
            'estado'            => $this->getParam('estado', ''),
            'match_tipo'        => $this->getParam('match_tipo', ''),
            'moneda'            => $this->getParam('moneda', ''),
            'fecha_desde'       => $this->getParam('fecha_desde', ''),
            'fecha_hasta'       => $this->getParam('fecha_hasta', ''),
            'solo_diferencias'  => $this->getParam('solo_diferencias', '') === '1',
            'conciliacion_id'   => $conciliacionId > 0 ? $conciliacionId : null,
            'importaciones_ids' => $impIds,
            'columnas'          => $columnas,
        ];
    }

    private function getParam(string $key, string $default = ''): string
    {
        foreach ([$_GET, $_POST] as $src) {
            if (isset($src[$key]) && trim((string) $src[$key]) !== '') {
                return trim((string) $src[$key]);
            }
        }
        return $default;
    }

    // ─── Despacho de datos ────────────────────────────────────────────

    private function obtenerDatos(array $f): array
    {
        return match ($f['tipo']) {
            'facturas' => $this->obtenerFacturas($f),
            'gastos'   => $this->obtenerGastos($f),
            default    => $this->obtenerConciliacion($f),
        };
    }

    private function obtenerFacturas(array $f): array
    {
        $rows = $this->loadModel('Factura')->getAllWithImportacion();

        return array_values(array_filter($rows, function ($r) use ($f) {
            $fecha = $r['fecha_emision'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha > $f['fecha_hasta']) return false;
            if ($f['moneda'] !== '' && ($r['moneda'] ?? '') !== $f['moneda']) return false;
            if (!empty($f['importaciones_ids']) && !in_array((int) ($r['importacion_id'] ?? 0), $f['importaciones_ids'], true)) return false;
            return true;
        }));
    }

    private function obtenerGastos(array $f): array
    {
        $rows = $this->loadModel('Gasto')->getAllWithProveedor();

        return array_values(array_filter($rows, function ($r) use ($f) {
            $fecha = $r['fecha_max'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha !== '' && $fecha > $f['fecha_hasta']) return false;
            if (!empty($f['importaciones_ids']) && !in_array((int) ($r['importacion_id'] ?? 0), $f['importaciones_ids'], true)) return false;
            return true;
        }));
    }

    private function obtenerConciliacion(array $f): array
    {
        $rows = $this->loadModel('Conciliacion')->getGridRows();

        return array_values(array_filter($rows, function ($r) use ($f) {
            if ($f['estado'] !== '' && ($r['estado_codigo'] ?? '') !== $f['estado']) return false;
            if ($f['match_tipo'] !== '' && ($r['match_tipo'] ?? '') !== $f['match_tipo']) return false;
            $fecha = $r['factura_fecha'] ?? $r['gasto_fecha'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha !== '' && $fecha > $f['fecha_hasta']) return false;
            if ($f['solo_diferencias'] && abs((float) ($r['diferencia_total'] ?? 0)) < 0.01) return false;
            if ($f['conciliacion_id'] !== null && (int) ($r['conciliacion_id'] ?? 0) !== $f['conciliacion_id']) return false;
            if (!empty($f['importaciones_ids'])) {
                $facImpId = (int) ($r['factura_importacion_id'] ?? 0);
                if (!in_array($facImpId, $f['importaciones_ids'], true)) return false;
            }
            return true;
        }));
    }

    // ─── Summary ──────────────────────────────────────────────────────

    private function calcularSummary(array $rows, string $tipo): array
    {
        if (empty($rows)) return [];

        if ($tipo === 'facturas') {
            return [
                'total_registros' => count($rows),
                'total_monto'     => round(array_sum(array_column($rows, 'total')), 2),
                'total_iva'       => round(array_sum(array_column($rows, 'iva')), 2),
            ];
        }

        if ($tipo === 'gastos') {
            return [
                'total_registros' => count($rows),
                'total_monto'     => round(array_sum(array_column($rows, 'suma_total')), 2),
                'total_iva'       => round(array_sum(array_column($rows, 'suma_iva')), 2),
            ];
        }

        $montos = array_map(fn($r) => (float) ($r['factura_total'] ?? $r['gasto_total'] ?? 0), $rows);
        $difs   = array_map(fn($r) => abs((float) ($r['porcentaje_diferencia'] ?? 0)), $rows);

        return [
            'total_registros' => count($rows),
            'total_monto'     => round(array_sum($montos), 2),
            'dif_promedio'    => round(count($difs) ? array_sum($difs) / count($difs) : 0, 2),
        ];
    }

    // ─── Headers y mapping ────────────────────────────────────────────

    private function headersParaTipo(string $tipo): array
    {
        if ($tipo === 'facturas') {
            return ['Fecha', 'N° Factura', 'Proveedor', 'Subtotal', 'IVA', 'Total', 'Moneda', 'Archivo XML'];
        }
        if ($tipo === 'gastos') {
            return ['Fecha', 'N° Factura', 'Proveedor', 'Items', 'Base', 'IVA', 'Total'];
        }
        return [
            'Estado', 'Score',
            'Fact. Fecha', 'Fact. N°', 'Fact. Proveedor', 'Fact. IVA', 'Fact. Total',
            'Gas. Fecha',  'Gas. N°',  'Gas. Proveedor',  'Gas. IVA',  'Gas. Total',
            'Dif. $', 'Dif. %', 'Tipo Match', 'Hallazgos',
        ];
    }

    private function mapRowsParaTipo(array $rows, string $tipo): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($tipo === 'facturas') {
                $out[] = [
                    $r['fecha_emision'] ?? '',
                    $r['numero_factura_asistente'] ?? '',
                    $r['proveedor_nombre'] ?? '',
                    $r['subtotal'] ?? 0,
                    $r['iva'] ?? 0,
                    $r['total'] ?? 0,
                    $r['moneda'] ?? '',
                    $r['archivo_xml'] ?? '',
                ];
            } elseif ($tipo === 'gastos') {
                $out[] = [
                    $r['fecha_max'] ?? '',
                    $r['numero_factura'] ?? '',
                    $r['proveedor_texto'] ?? $r['proveedor_nombre'] ?? '',
                    $r['cantidad_items'] ?? 1,
                    $r['suma_base'] ?? 0,
                    $r['suma_iva'] ?? 0,
                    $r['suma_total'] ?? 0,
                ];
            } else {
                $out[] = [
                    $r['estado_nombre'] ?? '',
                    round((float) ($r['score_total'] ?? 0), 1),
                    $r['factura_fecha'] ?? '',
                    $r['factura_numero'] ?? '',
                    $r['factura_proveedor'] ?? '',
                    $r['factura_iva'] ?? 0,
                    $r['factura_total'] ?? 0,
                    $r['gasto_fecha'] ?? '',
                    $r['gasto_numero'] ?? '',
                    $r['gasto_proveedor'] ?? '',
                    $r['gasto_iva'] ?? 0,
                    $r['gasto_total'] ?? 0,
                    $r['diferencia_total'] ?? 0,
                    round((float) ($r['porcentaje_diferencia'] ?? 0), 2),
                    $r['match_tipo'] ?? '',
                    $this->buildHallazgos($r),
                ];
            }
        }
        return $out;
    }

    // ─── Análisis de hallazgos ────────────────────────────────────────

    private function buildHallazgos(array $r): string
    {
        $tieneFactura = !empty($r['factura_xml_id']);
        $tieneGasto   = !empty($r['gasto_consolidado_id']);

        if (!$tieneFactura && $tieneGasto) return 'Sin factura XML';
        if ($tieneFactura && !$tieneGasto) return 'Sin gasto asociado';
        if (!$tieneFactura && !$tieneGasto) return 'Sin datos';

        $hallazgos = [];

        $fp = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($r['factura_proveedor'] ?? ''))), 'UTF-8');
        $gp = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) ($r['gasto_proveedor'] ?? ''))), 'UTF-8');
        if ($fp !== '' && $gp !== '' && $fp !== $gp) {
            $sim = 0.0;
            similar_text($fp, $gp, $sim);
            if ($sim < 70.0) {
                $hallazgos[] = 'Diferencia en proveedor';
            }
        }

        $fn = trim((string) ($r['factura_numero'] ?? ''));
        $gn = trim((string) ($r['gasto_numero'] ?? ''));
        if ($fn !== '' && $gn !== '' && !$this->numerosCoinciden($fn, $gn)) {
            $hallazgos[] = 'Diferencia en número de factura';
        }

        $ff = (string) ($r['factura_fecha'] ?? '');
        $gf = (string) ($r['gasto_fecha'] ?? '');
        if ($ff !== '' && $gf !== '' && $ff !== $gf) {
            $hallazgos[] = 'Diferencia en fecha';
        }

        $difTotal = abs((float) ($r['diferencia_total'] ?? 0));
        if ($difTotal > 0.01) {
            $hallazgos[] = 'Diferencia en total (' . number_format($difTotal, 2) . ')';
        }

        return empty($hallazgos) ? 'Sin diferencias' : implode('; ', $hallazgos);
    }

    /**
     * Dos números de factura coinciden si son iguales tras normalizar
     * (sin separadores ni ceros a la izquierda) o si comparten el mismo
     * núcleo numérico: la secuencia de dígitos más larga.
     * Cubre "0000071176" vs "000...071176" vs "FACT-1-1-0000071176-360".
     */
    private function numerosCoinciden(string $a, string $b): bool
    {
        $na = ltrim(preg_replace('/[^A-Za-z0-9]/', '', strtoupper($a)), '0');
        $nb = ltrim(preg_replace('/[^A-Za-z0-9]/', '', strtoupper($b)), '0');
        if ($na !== '' && $na === $nb) {
            return true;
        }

        $coreA = $this->nucleoNumerico($a);
        $coreB = $this->nucleoNumerico($b);
        if ($coreA !== '' && $coreA === $coreB) {
            return true;
        }

        // Número corto incrustado al final del consecutivo largo con relleno
        // de ceros: "0000005061" vs "FACT-01400020010000005061-3".
        return $this->nucleoTerminaEn($coreA, $coreB) || $this->nucleoTerminaEn($coreB, $coreA);
    }

    /** ¿El núcleo largo termina en el corto precedido por un 0 (relleno)? */
    private function nucleoTerminaEn(string $largo, string $corto): bool
    {
        if (strlen($corto) < 3 || strlen($largo) <= strlen($corto)) {
            return false;
        }
        if (substr($largo, -strlen($corto)) !== $corto) {
            return false;
        }
        return substr($largo, -strlen($corto) - 1, 1) === '0';
    }

    /** Secuencia de dígitos más larga sin ceros a la izquierda (mín. 3 dígitos). */
    private function nucleoNumerico(string $value): string
    {
        preg_match_all('/\d+/', $value, $m);
        $best = '';
        foreach ($m[0] as $run) {
            $run = ltrim($run, '0');
            if (strlen($run) >= 3 && strlen($run) > strlen($best)) {
                $best = $run;
            }
        }
        return $best;
    }
}
