<?php
/**
 * Módulo "Facturas ERP".
 *
 * Carga el reporte "Facturas por Proveedor" del ERP y muestra sus columnas,
 * con foco en qué facturas siguen con saldo pendiente. Cada carga vuelve a
 * subirse completa: el modelo inserta las nuevas, actualiza las que cambiaron
 * de saldo y deja intactas las que siguen igual.
 */

require_once __DIR__ . '/../helpers/FacturasErpCsvParser.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';

class FacturasErpController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $this->recordarFiltros('facturas_erp', [
            'q', 'proveedor', 'sucursal', 'origen', 'estado',
            'desde', 'hasta', 'solo_saldo',
        ]);

        $modelo = $this->loadModel('FacturaErp');
        $filtros = $this->filtros();
        $pagina = max(1, (int) $this->get('pagina', 1));
        $porPagina = 200;

        $total = $modelo->contar($filtros);
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $totalPaginas);

        $this->render('facturaserp.index', [
            'titulo' => 'Facturas ERP',
            'facturas' => $modelo->listar($filtros, $porPagina, ($pagina - 1) * $porPagina),
            // El resumen de la pantalla (facturas, con saldo, proveedores) se
            // fue con las tarjetas: era una consulta de agregados por cada
            // visita para cifras que nadie usaba para decidir nada.
            'opciones' => $modelo->opcionesFiltro(),
            'proveedoresFiltro' => ProveedorCatalogo::opciones($modelo->proveedoresParaFiltro()),
            'cargas' => $modelo->cargas(10),
            'ultimaCarga' => $modelo->ultimaCarga(),
            'incidenciasAbiertas' => $modelo->contarIncidencias(['severidad' => 'alerta']),
            'incidenciasTotal' => $modelo->contarIncidencias([]),
            'filtros' => $filtros,
            'pagina' => $pagina,
            'totalPaginas' => $totalPaginas,
            'total' => $total,
        ]);
    }

    /** Historial de problemas detectados en cada carga. */
    public function incidencias()
    {
        // Su propia memoria, aparte del listado: se filtra por otra cosa.
        // 'carga' queda fuera porque elige de qué carga se está hablando.
        $this->recordarFiltros('facturas_erp_incidencias', [
            'ver', 'tipo', 'severidad', 'proveedor', 'q',
        ]);

        $modelo = $this->loadModel('FacturaErp');
        $filtros = $this->filtrosIncidencia();
        $pagina = max(1, (int) $this->get('pagina', 1));
        $porPagina = 200;

        $total = $modelo->contarIncidencias($filtros);
        $totalPaginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $totalPaginas);

        $this->render('facturaserp.incidencias', [
            'titulo' => 'Incidencias · Facturas ERP',
            'incidencias' => $modelo->incidencias($filtros, $porPagina, ($pagina - 1) * $porPagina),
            'resumenTipos' => $modelo->resumenIncidencias($filtros),
            'proveedoresFiltro' => ProveedorCatalogo::opciones($modelo->proveedoresConIncidencia($filtros)),
            'cargas' => $modelo->cargas(30),
            'catalogo' => FacturasErpCsvParser::TIPOS_INCIDENCIA,
            'filtros' => $filtros,
            'pagina' => $pagina,
            'totalPaginas' => $totalPaginas,
            'total' => $total,
            'totalDescartadas' => $modelo->contarIncidencias(
                array_merge($filtros, ['ver' => 'descartadas'])
            ),
        ]);
    }

    /**
     * Oculta incidencias. El descarte es permanente por omisión: se recuerda
     * la firma para que las cargas siguientes no vuelvan a mostrar lo mismo.
     */
    public function descartarIncidencias()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp/incidencias'));
        }
        $modelo = $this->loadModel('FacturaErp');
        $volver = $this->url('/facturas-erp/incidencias') . $this->queryIncidencia();

        try {
            // "Descartar todas las del filtro" no manda ids: los resuelve aquí.
            if ($this->post('todas_del_filtro', '') !== '') {
                $ids = $modelo->idsIncidencias($this->filtrosIncidencia());
            } else {
                $ids = (array) $this->post('ids', []);
            }
            if (!$ids) {
                $this->redirectWithMessage($volver, 'No seleccionaste ninguna incidencia.', 'warning');
            }

            $r = $modelo->descartarIncidencias(
                $ids,
                (string) $this->post('motivo', ''),
                $_SESSION['usuario_id'] ?? null,
                $this->post('solo_esta_vez', '') === ''
            );

            $mensaje = sprintf('%s incidencia(s) descartada(s).', number_format($r['descartadas'], 0, ',', '.'));
            if ($r['firmas'] > 0) {
                $mensaje .= sprintf(
                    ' %s no volverán a aparecer en las próximas cargas.',
                    number_format($r['firmas'], 0, ',', '.')
                );
            }
            $this->redirectWithMessage($volver, $mensaje, 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo descartar: ' . $e->getMessage(), 'error');
        }
    }

    public function restaurarIncidencias()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp/incidencias'));
        }
        $modelo = $this->loadModel('FacturaErp');
        $volver = $this->url('/facturas-erp/incidencias') . $this->queryIncidencia();

        try {
            $ids = (array) $this->post('ids', []);
            if (!$ids) {
                $this->redirectWithMessage($volver, 'No seleccionaste ninguna incidencia.', 'warning');
            }
            $r = $modelo->restaurarIncidencias($ids);
            $this->redirectWithMessage(
                $volver,
                sprintf('%s incidencia(s) restaurada(s): vuelven a mostrarse.',
                    number_format($r['restauradas'], 0, ',', '.')),
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo restaurar: ' . $e->getMessage(), 'error');
        }
    }

    private function filtrosIncidencia()
    {
        $ver = trim((string) $this->get('ver', 'vigentes'));
        return [
            'ver' => in_array($ver, ['vigentes', 'descartadas', 'todas'], true) ? $ver : 'vigentes',
            'carga_id' => (int) $this->get('carga', 0),
            'tipo' => trim((string) $this->get('tipo', '')),
            'severidad' => trim((string) $this->get('severidad', '')),
            'proveedor' => ProveedorCatalogo::normalizarClave($this->get('proveedor', '')),
            'texto' => trim((string) $this->get('q', '')),
        ];
    }

    /** Conserva el filtro activo al volver de una acción POST. */
    private function queryIncidencia()
    {
        $f = $this->filtrosIncidencia();
        $q = array_filter([
            'ver' => $f['ver'] !== 'vigentes' ? $f['ver'] : '',
            'carga' => $f['carga_id'] ?: '',
            'tipo' => $f['tipo'], 'severidad' => $f['severidad'],
            'proveedor' => $f['proveedor'], 'q' => $f['texto'],
        ], function ($v) { return $v !== '' && $v !== null; });
        return $q ? '?' . http_build_query($q) : '';
    }

    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/carga'));
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'facturas_erp';

        try {
            $archivo = FileUploader::uploadSingle(
                'listado_file',
                $uploadDir,
                ['csv'],
                $config['max_upload_size'] ?? 10485760
            );

            @set_time_limit(180);
            $resultado = FacturasErpCsvParser::parseArchivo($archivo['path']);
            $resultado['meta']['archivo'] = $archivo['original_name'] ?? basename($archivo['path']);

            // El reporte trae sus propios totales: si la lectura no los
            // reproduce, no se importa nada a medias.
            if (!$resultado['ok']) {
                $detalle = array_merge($resultado['errores'], array_slice($resultado['no_reconocidas'], 0, 5));
                $this->redirectWithMessage(
                    $this->url('/carga'),
                    'No se pudo leer el listado: ' . implode(' ', $resultado['errores']),
                    'error',
                    ['failed_files' => $detalle]
                );
            }

            // El reporte del ERP no dice a qué empresa pertenece, así que se
            // sella con la sociedad seleccionada al subirlo. Sin esto, dos
            // empresas compartirían saldos y el cierre semanal de una podría
            // emparejar contra una factura de la otra.
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                $this->redirectWithMessage(
                    $this->url('/carga'),
                    'Selecciona una sociedad antes de cargar el listado del ERP: el reporte no indica a qué empresa pertenece.',
                    'error'
                );
            }
            $meta = $resultado['meta'];
            $meta['sociedad_id'] = (int) $sociedad['id'];

            $modelo = $this->loadModel('FacturaErp');
            $r = $modelo->importar(
                $resultado['facturas'],
                $meta,
                $resultado['cuadre'],
                $_SESSION['usuario_id'] ?? null,
                $resultado['incidencias']
            );

            $mensaje = sprintf(
                '%s facturas leídas: %s nuevas, %s con saldo actualizado y %s sin cambios.',
                number_format(count($resultado['facturas']), 0, ',', '.'),
                number_format($r['insertadas'], 0, ',', '.'),
                number_format($r['actualizadas'], 0, ',', '.'),
                number_format($r['sin_cambio'], 0, ',', '.')
            );

            // El reporte cierra con un "Total General": si el saldo leído lo
            // reproduce, la importación está confirmada de punta a punta.
            $cuadre = $resultado['cuadre'];
            if (!empty($cuadre['saldo_general_ok'])) {
                $mensaje .= sprintf(
                    ' El saldo importado (₡%s) coincide con el Total General del reporte.',
                    number_format($cuadre['saldo_leido'], 2)
                );
            }

            $tipo = 'success';
            $detalles = [];
            if (!empty($r['descuadres'])) {
                $tipo = 'warning';
                $mensaje .= ' Atención: ' . count($r['descuadres']) . ' total(es) del reporte no cuadran con lo leído.';
                $detalles['duplicate_files'] = array_map(function ($d) {
                    return sprintf(
                        'Proveedor %s%s: leído %s vs impreso %s',
                        $d['proveedor'],
                        $d['sucursal'] !== '' ? ' / ' . $d['sucursal'] : '',
                        number_format($d['leido_monto'], 2),
                        number_format($d['impreso_monto'], 2)
                    );
                }, array_slice($r['descuadres'], 0, 5));
            }

            $this->redirectWithMessage($this->url('/facturas-erp'), $mensaje, $tipo, $detalles);
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/carga'),
                'Error al procesar el listado: ' . $e->getMessage(),
                'error'
            );
        }
    }

    /** Exporta lo que está viendo el usuario, respetando los filtros activos. */
    public function exportar()
    {
        $modelo = $this->loadModel('FacturaErp');
        $filas = $modelo->listar($this->filtros(), 5000, 0);

        $nombre = 'facturas_erp_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete los acentos
        fputcsv($out, ['Proveedor', 'Nombre', 'Sucursal', 'Documento', 'Fecha',
                       'Vence', 'Compra', 'Moneda', 'Monto', 'Saldo', 'Estado', 'Semana'], ',', '"', '\\');
        foreach ($filas as $f) {
            fputcsv($out, [
                $f['proveedor_codigo'], $f['proveedor_nombre'], $f['sucursal'],
                $f['documento'], $f['fecha_emision'], $f['fecha_vence'],
                $f['origen'], $f['moneda'], $f['monto'], $f['saldo'],
                ($f['estado'] ?? 'pendiente') === 'asignada_semana' ? 'Asignada a una semana' : 'Pendiente',
                $f['semana_nombre'] ?? '',
            ], ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private function filtros()
    {
        return [
            'texto' => trim((string) $this->get('q', '')),
            'proveedor' => ProveedorCatalogo::normalizarClave($this->get('proveedor', '')),
            'sucursal' => trim((string) $this->get('sucursal', '')),
            'origen' => trim((string) $this->get('origen', '')),
            'estado' => trim((string) $this->get('estado', '')),
            'desde' => $this->fecha($this->get('desde', '')),
            'hasta' => $this->fecha($this->get('hasta', '')),
            'solo_saldo' => $this->get('solo_saldo', '') !== '' ? 1 : 0,
        ];
    }

    private function fecha($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
    }
}
