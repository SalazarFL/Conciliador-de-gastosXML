<?php
require_once __DIR__ . '/../helpers/NotasCreditoCsvParser.php';
require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';

class NotasCreditoController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        $modelo = $this->loadModel('NotaCredito');
        $sociedad = $this->loadModel('Sociedad')->getActiva();
        $listados = $sociedad ? $modelo->getListados((int) $sociedad['id']) : [];

        $listadoId = (int) $this->get('listado_id', 0);
        $ids = array_map(function ($row) { return (int) $row['id']; }, $listados);
        if ($listadoId <= 0 || !in_array($listadoId, $ids, true)) {
            $listadoId = !empty($listados) ? (int) $listados[0]['id'] : 0;
        }

        $listado = $listadoId > 0 ? $modelo->getListado($listadoId) : null;
        $filters = $this->filtrosLineas();
        $rows = $listado ? $modelo->getLineasFiltradas($listadoId, $filters) : [];

        $this->render('notascredito/index', [
            'title' => 'Notas de crédito - XMLConcilia',
            'sociedadActiva' => $sociedad,
            'listados' => $listados,
            'listado' => $listado,
            'lineas' => $rows,
            'paginacion' => [
                'total' => count($rows),
                'page' => 1,
                'pages' => 1,
                'per_page' => count($rows),
            ],
            'resumen' => $listado ? $modelo->resumen($listadoId) : [],
            'filtros' => $filters,
            'opciones' => $listado ? $modelo->opcionesFiltros($listadoId) : ['proveedores' => [], 'sucursales' => []],
        ]);
    }

    public function buscar()
    {
        try {
            $listadoId = (int) $this->get('listado_id', 0);
            $modelo = $this->loadModel('NotaCredito');
            $listado = $modelo->getListado($listadoId);
            $sociedad = $this->loadModel('Sociedad')->getActiva();

            if (!$listado || !$sociedad || (int) $listado['sociedad_id'] !== (int) $sociedad['id']) {
                throw new Exception('El listado no pertenece a la sociedad activa.');
            }

            $rows = $modelo->getLineasFiltradas($listadoId, $this->filtrosLineas());
            $this->json([
                'ok' => true,
                'total' => count($rows),
                'total_listado' => (int) $listado['total_lineas'],
                'lineas' => $rows,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function filtrosLineas()
    {
        $keys = [
            'q', 'estado', 'condicion_saldo', 'condicion_nc_proveedor', 'proveedor', 'sucursal',
            'col_estado', 'proveedor_codigo', 'proveedor_nombre', 'sucursal_texto',
            'documento', 'fecha', 'nc_proveedor', 'fecha_nc_proveedor',
            'entrada_asociada', 'moneda', 'monto', 'saldo', 'monto_conversion',
            'nc_xml', 'xml_total', 'diferencia',
        ];
        $filters = [];
        foreach ($keys as $key) {
            $filters[$key] = trim((string) $this->get($key, ''));
        }
        return $filters;
    }

    public function previsualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';
        $tempDir = $this->uploadBase() . DIRECTORY_SEPARATOR . 'previas';

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception('Debes seleccionar una sociedad activa antes de cargar el listado.');
            }

            foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $old) {
                if (is_file($old) && filemtime($old) < time() - 21600) {
                    @unlink($old);
                }
            }

            $file = FileUploader::uploadSingle(
                'listado_file',
                $tempDir,
                ['csv'],
                $config['max_upload_size'] ?? 10485760
            );
            $parsed = NotasCreditoCsvParser::parse($file['path']);
            $hash = hash_file('sha256', $file['path']);
            $existing = $this->loadModel('NotaCredito')->buscarPorHash($hash);

            $this->json([
                'ok' => true,
                'token' => basename($file['path']),
                'archivo' => $file['original_name'],
                'empresa' => $parsed['empresa'],
                'periodo_desde' => $parsed['periodo_desde'],
                'periodo_hasta' => $parsed['periodo_hasta'],
                'estadisticas' => $parsed['estadisticas'],
                'errores' => $parsed['errores'],
                'duplicado' => $existing ? [
                    'id' => (int) $existing['id'],
                    'nombre' => (string) $existing['nombre'],
                ] : null,
                'lineas' => array_slice($parsed['lineas'], 0, 200),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }

        $modelo = $this->loadModel('NotaCredito');
        $tempDir = $this->uploadBase() . DIRECTORY_SEPARATOR . 'previas';
        $token = basename(trim((string) $this->post('archivo_token', '')));
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $token;
        $permanentPath = null;

        try {
            if ($token === '' || !is_file($tempPath)) {
                throw new Exception('La vista previa expiró. Selecciona nuevamente el CSV.');
            }
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception('No existe una sociedad activa.');
            }

            $parsed = NotasCreditoCsvParser::parse($tempPath);
            $hash = hash_file('sha256', $tempPath);
            $existing = $modelo->buscarPorHash($hash);
            if ($existing) {
                @unlink($tempPath);
                $this->redirectWithMessage(
                    $this->url('/notas-credito?listado_id=' . (int) $existing['id']),
                    'Ese archivo ya fue cargado como "' . $existing['nombre'] . '".',
                    'warning'
                );
            }

            $originalName = basename(trim((string) $this->post('archivo_nombre', ''))) ?: $token;
            $permanentName = 'notas_' . date('Ymd_His') . '_' . substr($hash, 0, 10) . '.csv';
            $permanentPath = $this->uploadBase() . DIRECTORY_SEPARATOR . $permanentName;
            if (!is_dir($this->uploadBase()) && !mkdir($this->uploadBase(), 0777, true)) {
                throw new Exception('No fue posible preparar el almacenamiento de notas.');
            }
            if (!rename($tempPath, $permanentPath)) {
                throw new Exception('No fue posible conservar el CSV original.');
            }

            $nombre = $this->buildListName($parsed);
            $modelo->begin();
            $listadoId = $modelo->crearListado([
                'sociedad_id' => (int) $sociedad['id'],
                'nombre' => $nombre,
                'empresa_reporte' => $parsed['empresa'],
                'periodo_desde' => $parsed['periodo_desde'],
                'periodo_hasta' => $parsed['periodo_hasta'],
                'archivo_origen' => $originalName,
                'archivo_ruta' => $permanentPath,
                'archivo_hash' => $hash,
                'total_lineas' => count($parsed['lineas']),
            ]);
            $modelo->crearLineasLote($listadoId, $parsed['lineas']);
            $modelo->commit();

            $stats = NotasCreditoVerificador::verificarListado($listadoId, $modelo, 'carga_inicial');
            $message = count($parsed['lineas']) . ' notas importadas: '
                . $stats['coincide'] . ' coinciden, '
                . $stats['con_diferencia'] . ' con diferencia y '
                . $stats['sin_respaldo'] . ' sin respaldo.';
            if (!empty($parsed['errores'])) {
                $message .= ' ' . count($parsed['errores']) . ' filas inválidas fueron omitidas.';
            }
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . $listadoId),
                $message,
                ($stats['con_diferencia'] + $stats['sin_respaldo']) > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $modelo->rollback();
            if ($permanentPath && is_file($permanentPath)) {
                @rename($permanentPath, $tempPath);
            }
            $this->redirectWithMessage(
                $this->url('/notas-credito'),
                'No se pudo cargar el listado: ' . $e->getMessage(),
                'error'
            );
        }
    }

    public function verificar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }
        try {
            $modelo = $this->loadModel('NotaCredito');
            $listado = $modelo->getListado((int) $id);
            if (!$listado) {
                throw new Exception('El listado no existe.');
            }
            $stats = NotasCreditoVerificador::verificarListado((int) $id, $modelo, 'manual');
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . (int) $id),
                "Verificación terminada: {$stats['coincide']} coinciden, {$stats['con_diferencia']} con diferencia y {$stats['sin_respaldo']} sin respaldo.",
                ($stats['con_diferencia'] + $stats['sin_respaldo']) > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    public function candidatas()
    {
        $lineaId = (int) $this->get('linea_id', 0);
        try {
            $modelo = $this->loadModel('NotaCredito');
            $linea = $modelo->getLinea($lineaId);
            if (!$linea) {
                throw new Exception('La línea no existe.');
            }
            $rows = $modelo->getCandidatasManuales($linea, (string) $this->get('q', ''));
            $numeroProveedor = trim((string) ($linea['nc_proveedor'] ?? ''));
            if ($numeroProveedor !== '') {
                $rows = array_values(array_filter($rows, function ($row) use ($numeroProveedor) {
                    return NotasCreditoVerificador::mismoNumeroNc($numeroProveedor, $row);
                }));
            }
            foreach ($rows as &$row) {
                $mismoProveedor = FacturaMatcher::mismoProveedor(
                    (string) $linea['proveedor_nombre'],
                    (string) $row['proveedor_nombre']
                ) || FacturaMatcher::mismoProveedor(
                    (string) $linea['proveedor_nombre'],
                    (string) ($row['proveedor_alias'] ?? '')
                );
                $row['mismo_proveedor'] = $mismoProveedor;
                $row['score_proveedor'] = $mismoProveedor ? 100.0 : 0.0;
                $row['diferencia'] = round((float) $linea['monto'] - (float) $row['total'], 2);
                $row['monto_exacto'] = NotasCreditoVerificador::montosCoinciden(
                    $linea['monto'],
                    $row['total']
                );
            }
            unset($row);
            $rows = array_values(array_filter($rows, function ($row) {
                return !empty($row['monto_exacto']);
            }));
            usort($rows, function ($a, $b) {
                $porProveedor = (int) $b['mismo_proveedor'] <=> (int) $a['mismo_proveedor'];
                if ($porProveedor !== 0) {
                    return $porProveedor;
                }
                return abs((float) $a['diferencia']) <=> abs((float) $b['diferencia']);
            });
            $this->json(['ok' => true, 'linea' => $linea, 'candidatas' => $rows]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function historial($id)
    {
        try {
            $listadoId = (int) $id;
            $modelo = $this->loadModel('NotaCredito');
            $listado = $modelo->getListado($listadoId);
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$listado || !$sociedad || (int) $listado['sociedad_id'] !== (int) $sociedad['id']) {
                throw new Exception('El listado no pertenece a la sociedad activa.');
            }

            $verificaciones = $modelo->getVerificaciones($listadoId);
            $verificacionId = (int) $this->get('verificacion_id', 0);
            $seleccionada = null;
            foreach ($verificaciones as $item) {
                if ($verificacionId <= 0 || (int) $item['id'] === $verificacionId) {
                    $seleccionada = $item;
                    break;
                }
            }
            if ($verificacionId > 0 && $seleccionada === null) {
                throw new Exception('La verificación solicitada no pertenece a este listado.');
            }

            $this->json([
                'ok' => true,
                'verificaciones' => $verificaciones,
                'seleccionada' => $seleccionada,
                'cambios' => $seleccionada
                    ? $modelo->getCambiosVerificacion((int) $seleccionada['id'], $listadoId)
                    : [],
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function vincular()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }
        $lineaId = (int) $this->post('linea_id', 0);
        $facturaId = (int) $this->post('factura_id', 0);
        try {
            $modelo = $this->loadModel('NotaCredito');
            $linea = $modelo->getLinea($lineaId);
            if (!$linea) {
                throw new Exception('La línea del listado no existe.');
            }
            $factura = $modelo->getFacturaNcValida($facturaId, $linea['sociedad_cedula']);
            if (!$factura) {
                throw new Exception('El XML seleccionado no es una NC válida de esta sociedad.');
            }
            if (NotasCreditoVerificador::normalizeCurrency($factura['moneda']) !== $linea['moneda']) {
                throw new Exception('La moneda del XML no coincide con la del reporte.');
            }
            if (trim((string) ($linea['nc_proveedor'] ?? '')) !== ''
                && !NotasCreditoVerificador::mismoNumeroNc((string) $linea['nc_proveedor'], $factura)) {
                throw new Exception('No se puede vincular: el XML no tiene el consecutivo registrado en NC Proveedor.');
            }
            if (!NotasCreditoVerificador::montosCoinciden($linea['monto'], $factura['total'])) {
                throw new Exception('No se puede vincular: el monto del XML debe ser exactamente igual al monto del reporte.');
            }
            if ($modelo->facturaUsadaEnListado($linea['listado_id'], $facturaId, $lineaId)) {
                throw new Exception('Esa NC XML ya está vinculada a otra fila de este listado.');
            }

            [$estado, $diferencia] = NotasCreditoVerificador::clasificar(
                (float) $linea['monto'],
                (float) $factura['total'],
                (string) $linea['moneda']
            );
            $score = FacturaMatcher::similaridadTexto(
                (string) $linea['proveedor_nombre'],
                (string) $factura['proveedor_nombre']
            );
            $modelo->actualizarMatch(
                $lineaId, $facturaId, $estado, $diferencia, 'manual',
                round($score, 1), true, 'Vinculada manualmente.'
            );
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . (int) $linea['listado_id']),
                'La nota quedó vinculada manualmente.',
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    public function desvincular()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }
        try {
            $modelo = $this->loadModel('NotaCredito');
            $linea = $modelo->getLinea((int) $this->post('linea_id', 0));
            if (!$linea) {
                throw new Exception('La línea no existe.');
            }
            $modelo->actualizarMatch(
                (int) $linea['id'], null, 'sin_respaldo', null, 'ninguno',
                null, false, 'Desvinculada manualmente.', true
            );
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . (int) $linea['listado_id']),
                'La NC XML fue desvinculada. La fila queda bloqueada para el matching automático.',
                'warning'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }
        try {
            $modelo = $this->loadModel('NotaCredito');
            $listado = $modelo->getListado((int) $id);
            if (!$listado) {
                throw new Exception('El listado no existe.');
            }
            $modelo->eliminarListado((int) $id);
            $this->deleteAuditFile((string) $listado['archivo_ruta']);
            $this->redirectWithMessage(
                $this->url('/notas-credito'),
                'El listado fue eliminado. Ningún XML ni proveedor fue afectado.',
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    private function uploadBase()
    {
        $config = require __DIR__ . '/../config/config.php';
        return rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'notas_credito';
    }

    private function buildListName(array $parsed)
    {
        if ($parsed['periodo_desde'] && $parsed['periodo_hasta']) {
            return 'Notas ' . date('d/m/Y', strtotime($parsed['periodo_desde']))
                . ' al ' . date('d/m/Y', strtotime($parsed['periodo_hasta']));
        }
        return 'Notas ' . date('d/m/Y H:i');
    }

    private function deleteAuditFile($path)
    {
        $base = realpath($this->uploadBase());
        $target = realpath((string) $path);
        if ($base && $target && strpos($target, $base . DIRECTORY_SEPARATOR) === 0 && is_file($target)) {
            @unlink($target);
        }
    }
}
