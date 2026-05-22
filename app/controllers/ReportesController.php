<?php
require_once __DIR__ . '/../helpers/XlsxWriter.php';

class ReportesController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        try {
            $conciliacionModel = $this->loadModel('Conciliacion');
            $proveedorModel    = $this->loadModel('Proveedor');
            $estados     = $conciliacionModel->getEstadoMap();
            $proveedores = array_column($proveedorModel->getListado(), 'nombre');
        } catch (Throwable $e) {
            $estados     = [];
            $proveedores = [];
        }

        $this->render('reportes/index', [
            'title'       => 'Reportes - XMLConcilia',
            'estados'     => $estados,
            'proveedores' => $proveedores,
        ]);
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

            $tipo      = $filtros['tipo'];
            $headers   = $this->headersParaTipo($tipo);
            $mapped    = $this->mapRowsParaTipo($rows, $tipo);
            $sheetName = $tipo === 'facturas' ? 'Facturas' : ($tipo === 'gastos' ? 'Gastos' : 'Conciliacion');
            $fileName  = 'reporte_' . $sheetName . '_' . date('Ymd_His') . '.xlsx';

            $tmpFile = XlsxWriter::generate($headers, $mapped, $sheetName);
            XlsxWriter::send($tmpFile, $fileName);
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/reportes'), 'Error al exportar: ' . $e->getMessage(), 'error');
        }
    }

    public function estadisticas()
    {
        try {
            $conciliacionModel = $this->loadModel('Conciliacion');
            $this->json([
                'success' => true,
                'data'    => $conciliacionModel->getEstadisticas(),
            ]);
        } catch (Throwable $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Parseo de filtros ────────────────────────────────────────────

    private function parseFiltros(): array
    {
        return [
            'tipo'             => $this->getParam('tipo', 'conciliacion'),
            'estado'           => $this->getParam('estado', ''),
            'match_tipo'       => $this->getParam('match_tipo', ''),
            'moneda'           => $this->getParam('moneda', ''),
            'numero'           => $this->getParam('numero', ''),
            'proveedor'        => $this->getParam('proveedor', ''),
            'fecha_desde'      => $this->getParam('fecha_desde', ''),
            'fecha_hasta'      => $this->getParam('fecha_hasta', ''),
            'monto_min'        => $this->getParamFloat('monto_min'),
            'monto_max'        => $this->getParamFloat('monto_max'),
            'diferencia_min'   => $this->getParamFloat('diferencia_min'),
            'score_min'        => $this->getParamFloat('score_min'),
            'solo_revisados'   => $this->getParam('solo_revisados', '') === '1',
            'solo_diferencias' => $this->getParam('solo_diferencias', '') === '1',
            'orden'            => $this->getParam('orden', 'fecha'),
            'orden_dir'        => strtolower($this->getParam('orden_dir', 'desc')) === 'asc' ? 'asc' : 'desc',
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

    private function getParamFloat(string $key): ?float
    {
        $v = $this->getParam($key, '');
        if ($v === '') return null;
        $f = filter_var($v, FILTER_VALIDATE_FLOAT);
        return $f === false ? null : $f;
    }

    // ─── Despacho de datos ────────────────────────────────────────────

    private function obtenerDatos(array $f): array
    {
        $rows = match ($f['tipo']) {
            'facturas' => $this->obtenerFacturas($f),
            'gastos'   => $this->obtenerGastos($f),
            default    => $this->obtenerConciliacion($f),
        };

        return $this->ordenar($rows, $f);
    }

    private function obtenerFacturas(array $f): array
    {
        $rows = $this->loadModel('Factura')->getAllWithImportacion();

        return array_values(array_filter($rows, function ($r) use ($f) {
            // Número
            if ($f['numero'] !== '') {
                if (mb_stripos((string) ($r['numero_factura_asistente'] ?? ''), $f['numero']) === false) return false;
            }
            // Proveedor
            if ($f['proveedor'] !== '') {
                if (mb_stripos((string) ($r['proveedor_nombre'] ?? ''), $f['proveedor']) === false) return false;
            }
            // Fechas
            $fecha = $r['fecha_emision'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha > $f['fecha_hasta']) return false;
            // Moneda
            if ($f['moneda'] !== '' && ($r['moneda'] ?? '') !== $f['moneda']) return false;
            // Monto
            $total = (float) ($r['total'] ?? 0);
            if ($f['monto_min'] !== null && $total < $f['monto_min']) return false;
            if ($f['monto_max'] !== null && $total > $f['monto_max']) return false;
            return true;
        }));
    }

    private function obtenerGastos(array $f): array
    {
        $rows = $this->loadModel('Gasto')->getAllWithProveedor();

        return array_values(array_filter($rows, function ($r) use ($f) {
            // Número
            if ($f['numero'] !== '') {
                if (mb_stripos((string) ($r['numero_factura'] ?? ''), $f['numero']) === false) return false;
            }
            // Proveedor
            if ($f['proveedor'] !== '') {
                $prov = (string) ($r['proveedor_texto'] ?? $r['proveedor_nombre'] ?? '');
                if (mb_stripos($prov, $f['proveedor']) === false) return false;
            }
            // Fechas
            $fecha = $r['fecha_max'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha > $f['fecha_hasta']) return false;
            // Monto
            $total = (float) ($r['suma_total'] ?? 0);
            if ($f['monto_min'] !== null && $total < $f['monto_min']) return false;
            if ($f['monto_max'] !== null && $total > $f['monto_max']) return false;
            return true;
        }));
    }

    private function obtenerConciliacion(array $f): array
    {
        $rows = $this->loadModel('Conciliacion')->getGridRows();

        return array_values(array_filter($rows, function ($r) use ($f) {
            // Estado
            if ($f['estado'] !== '' && ($r['estado_codigo'] ?? '') !== $f['estado']) return false;
            // Match tipo
            if ($f['match_tipo'] !== '' && ($r['match_tipo'] ?? '') !== $f['match_tipo']) return false;
            // Número (factura o gasto)
            if ($f['numero'] !== '') {
                $fn = (string) ($r['factura_numero'] ?? '');
                $gn = (string) ($r['gasto_numero'] ?? '');
                if (mb_stripos($fn, $f['numero']) === false && mb_stripos($gn, $f['numero']) === false) return false;
            }
            // Proveedor (factura o gasto)
            if ($f['proveedor'] !== '') {
                $fp = (string) ($r['factura_proveedor'] ?? '');
                $gp = (string) ($r['gasto_proveedor'] ?? '');
                if (mb_stripos($fp, $f['proveedor']) === false && mb_stripos($gp, $f['proveedor']) === false) return false;
            }
            // Fechas
            $fecha = $r['factura_fecha'] ?? $r['gasto_fecha'] ?? '';
            if ($f['fecha_desde'] !== '' && $fecha !== '' && $fecha < $f['fecha_desde']) return false;
            if ($f['fecha_hasta'] !== '' && $fecha !== '' && $fecha > $f['fecha_hasta']) return false;
            // Monto
            $total = (float) ($r['factura_total'] ?? $r['gasto_total'] ?? 0);
            if ($f['monto_min'] !== null && $total < $f['monto_min']) return false;
            if ($f['monto_max'] !== null && $total > $f['monto_max']) return false;
            // Diferencia mínima %
            if ($f['diferencia_min'] !== null) {
                $dif = abs((float) ($r['porcentaje_diferencia'] ?? 0));
                if ($dif < $f['diferencia_min']) return false;
            }
            // Score mínimo
            if ($f['score_min'] !== null) {
                $score = (float) ($r['score_total'] ?? 0);
                if ($score < $f['score_min']) return false;
            }
            // Solo revisados
            if ($f['solo_revisados'] && empty($r['revisado'])) return false;
            // Solo con diferencias (diferencia_total != 0)
            if ($f['solo_diferencias'] && abs((float) ($r['diferencia_total'] ?? 0)) < 0.01) return false;
            return true;
        }));
    }

    // ─── Ordenamiento ─────────────────────────────────────────────────

    private function ordenar(array $rows, array $f): array
    {
        $campo = $f['orden'];
        $dir   = $f['orden_dir'] === 'asc' ? 1 : -1;

        usort($rows, function ($a, $b) use ($campo, $dir) {
            $va = $this->valorOrden($a, $campo);
            $vb = $this->valorOrden($b, $campo);
            if (is_numeric($va) && is_numeric($vb)) {
                $cmp = (float) $va <=> (float) $vb;
            } else {
                $cmp = strcmp((string) $va, (string) $vb);
            }
            return $cmp * $dir;
        });

        return $rows;
    }

    private function valorOrden(array $row, string $campo): mixed
    {
        return match ($campo) {
            'fecha'       => $row['fecha_emision'] ?? $row['fecha_max'] ?? $row['factura_fecha'] ?? '',
            'monto'       => (float) ($row['total'] ?? $row['suma_total'] ?? $row['factura_total'] ?? 0),
            'proveedor'   => $row['proveedor_nombre'] ?? $row['proveedor_texto'] ?? $row['factura_proveedor'] ?? '',
            'diferencia'  => abs((float) ($row['diferencia_total'] ?? 0)),
            'score'       => (float) ($row['score_total'] ?? 0),
            default       => '',
        };
    }

    // ─── Summary ──────────────────────────────────────────────────────

    private function calcularSummary(array $rows, string $tipo): array
    {
        if (empty($rows)) return [];

        if ($tipo === 'facturas') {
            $totalMonto = array_sum(array_column($rows, 'total'));
            $totalIva   = array_sum(array_column($rows, 'iva'));
            return [
                'total_registros' => count($rows),
                'total_monto'     => round($totalMonto, 2),
                'total_iva'       => round($totalIva, 2),
            ];
        }

        if ($tipo === 'gastos') {
            $totalMonto = array_sum(array_column($rows, 'suma_total'));
            $totalIva   = array_sum(array_column($rows, 'suma_iva'));
            return [
                'total_registros' => count($rows),
                'total_monto'     => round($totalMonto, 2),
                'total_iva'       => round($totalIva, 2),
            ];
        }

        // Conciliación
        $difs    = array_map(fn($r) => abs((float) ($r['porcentaje_diferencia'] ?? 0)), $rows);
        $scores  = array_map(fn($r) => (float) ($r['score_total'] ?? 0), $rows);
        $montos  = array_map(fn($r) => (float) ($r['factura_total'] ?? $r['gasto_total'] ?? 0), $rows);

        return [
            'total_registros' => count($rows),
            'total_monto'     => round(array_sum($montos), 2),
            'dif_promedio'    => round(count($difs) ? array_sum($difs) / count($difs) : 0, 2),
            'score_promedio'  => round(count($scores) ? array_sum($scores) / count($scores) : 0, 1),
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
            'Dif. $', 'Dif. %', 'Tipo Match',
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
                ];
            }
        }
        return $out;
    }
}
