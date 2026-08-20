<?php
require_once __DIR__ . '/../helpers/NotasCreditoCsvParser.php';
require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';

class NotasCreditoController extends Controller
{
    /**
     * Los filtros de la tabla: la barra de arriba y los de cada columna.
     * Los usa la consulta y también la memoria del módulo, para que agregar
     * un filtro no obligue a acordarse de apuntarlo en dos listas.
     * 'listado_id' no está: elige QUÉ listado se ve, no cómo se filtra.
     */
    private const CLAVES_FILTRO = [
        'q', 'estado', 'condicion_saldo', 'condicion_nc_proveedor', 'proveedor', 'sucursal', 'clase',
        'col_estado', 'proveedor_codigo', 'proveedor_nombre', 'sucursal_texto',
        'documento', 'fecha', 'nc_proveedor', 'fecha_nc_proveedor',
        'entrada_asociada', 'moneda', 'monto', 'saldo', 'monto_conversion',
        'nc_xml', 'xml_total', 'diferencia',
    ];

    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        $this->recordarFiltros('notas_credito', self::CLAVES_FILTRO);

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
        $rows = $this->conEstadoDeArchivo($listado ? $modelo->getLineasFiltradas($listadoId, $filters) : []);

        $this->render('notascredito/index', [
            'title' => 'Notas de crédito - Nexo Fiscal',
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
            'opciones' => $listado ? $modelo->opcionesFiltros($listadoId) : ['sucursales' => []],
            'proveedoresFiltro' => $listado
                ? ProveedorCatalogo::opciones($modelo->proveedoresParaFiltro($listadoId))
                : [],
        ]);
    }

    public function buscar()
    {
        // El filtrado de esta pantalla es en vivo: la tabla se rearma con
        // fetch y la barra de direcciones se reescribe sin recargar. Si la
        // memoria del módulo solo mirara index(), volvería a poner lo que
        // había en la última recarga y no lo que se está viendo.
        $this->recordarFiltros('notas_credito', self::CLAVES_FILTRO);

        try {
            $listadoId = (int) $this->get('listado_id', 0);
            $modelo = $this->loadModel('NotaCredito');
            $listado = $modelo->getListado($listadoId);
            $sociedad = $this->loadModel('Sociedad')->getActiva();

            if (!$listado || !$sociedad || (int) $listado['sociedad_id'] !== (int) $sociedad['id']) {
                throw new Exception('El listado no pertenece a la sociedad activa.');
            }

            $rows = $this->conEstadoDeArchivo($modelo->getLineasFiltradas($listadoId, $this->filtrosLineas()));
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

    /**
     * Si el comprobante enlazado todavía tiene sus archivos.
     *
     * Se resuelve en el servidor y las rutas se quitan antes de devolver las
     * filas: esta pantalla filtra en vivo y se repinta con JSON, y la ruta de
     * una carpeta del disco de la oficina no tiene por qué viajar al
     * navegador. Lo que necesita la tabla es el sí o el no.
     */
    private function conEstadoDeArchivo(array $filas)
    {
        $filas = EstadoArchivo::decorar($filas);
        foreach ($filas as $i => $fila) {
            unset($filas[$i]['ruta_xml'], $filas[$i]['ruta_pdf'], $filas[$i]['recuperable']);
        }
        return $filas;
    }

    private function filtrosLineas()
    {
        $filters = [];
        foreach (self::CLAVES_FILTRO as $key) {
            $filters[$key] = trim((string) $this->get($key, ''));
        }
        // El proveedor no es texto libre: es la clave que eligió el filtro.
        $filters['proveedor'] = ProveedorCatalogo::normalizarClave($filters['proveedor']);
        // La clase tampoco: son varias, separadas por comas, y solo valen las
        // que existen. Se normaliza acá para que lo que se recuerda y lo que
        // viaja en los enlaces sea siempre la misma cadena.
        $filters['clase'] = implode(',', ClaseNotaCredito::clasesPedidas($filters['clase']));
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
                throw new Exception('Debes seleccionar una sociedad activa antes de actualizar las notas.');
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
            $modelo = $this->loadModel('NotaCredito');
            $existing = $modelo->buscarPorHash($hash, (int) $sociedad['id']);
            $impacto = $modelo->previsualizarImportacion((int) $sociedad['id'], $parsed['lineas']);

            $this->json([
                'ok' => true,
                'token' => basename($file['path']),
                'archivo' => $file['original_name'],
                'empresa' => $parsed['empresa'],
                'periodo_desde' => $parsed['periodo_desde'],
                'periodo_hasta' => $parsed['periodo_hasta'],
                'estadisticas' => $parsed['estadisticas'],
                'errores' => $parsed['errores'],
                'impacto' => $impacto,
                'duplicado' => $existing ? [
                    'id' => (int) ($existing['listado_id'] ?? 0),
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
        $guardado = false;

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

            $originalName = basename(trim((string) $this->post('archivo_nombre', ''))) ?: $token;
            $permanentName = 'notas_' . date('Ymd_His') . '_' . substr($hash, 0, 10)
                . '_' . bin2hex(random_bytes(3)) . '.csv';
            $permanentPath = $this->uploadBase() . DIRECTORY_SEPARATOR . $permanentName;
            if (!is_dir($this->uploadBase()) && !mkdir($this->uploadBase(), 0777, true)) {
                throw new Exception('No fue posible preparar el almacenamiento de notas.');
            }
            if (!rename($tempPath, $permanentPath)) {
                throw new Exception('No fue posible conservar el CSV original.');
            }

            $nombre = $this->buildListName($parsed);
            $resultado = $modelo->importarConsolidado($parsed['lineas'], [
                'sociedad_id' => (int) $sociedad['id'],
                'nombre' => $nombre,
                'empresa_reporte' => $parsed['empresa'],
                'periodo_desde' => $parsed['periodo_desde'],
                'periodo_hasta' => $parsed['periodo_hasta'],
                'archivo_origen' => $originalName,
                'archivo_ruta' => $permanentPath,
                'archivo_hash' => $hash,
                'filas_leidas' => count($parsed['lineas']),
                'filas_invalidas' => count($parsed['errores']),
            ], $_SESSION['usuario_id'] ?? null);
            $guardado = true;
            $listadoId = (int) $resultado['listado_id'];

            if (!empty($resultado['ids_verificar'])) {
                NotasCreditoVerificador::verificarListado(
                    $listadoId,
                    $modelo,
                    'carga_incremental',
                    $resultado['ids_verificar']
                );
            }
            $stats = $modelo->resumen($listadoId);
            $message = count($parsed['lineas']) . ' notas leídas: '
                . $resultado['insertadas'] . ' nuevas, '
                . $resultado['actualizadas'] . ' con saldo actualizado y '
                . $resultado['sin_cambio'] . ' sin cambios.';
            if ($resultado['recuperadas'] > 0) {
                $message .= ' ' . $resultado['recuperadas']
                    . ' notas de cargas anteriores se incorporaron al acumulado.';
            }
            $message .= ' Verificación XML: ' . $stats['coincide'] . ' coinciden, '
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
            if (!$guardado && $permanentPath && is_file($permanentPath)) {
                @rename($permanentPath, $tempPath);
            }
            $this->redirectWithMessage(
                $this->url('/notas-credito'),
                ($guardado
                    ? 'Los saldos se actualizaron, pero falló la verificación XML: '
                    : 'No se pudo actualizar el acumulado: ') . $e->getMessage(),
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

            // El monto exacto deja de ser requisito para aparecer en la lista.
            // Lo era, y por eso una línea que el verificador ya había
            // identificado por la referencia de su XML —misma factura, mismo
            // proveedor, monto distinto por un céntimo— no ofrecía ni una sola
            // candidata: la única forma de resolverla era no resolverla. Las
            // exactas siguen primero; las demás quedan debajo, marcadas con su
            // diferencia, que es lo que hay que mirar.
            $rows = array_values(array_filter($rows, function ($row) {
                return !empty($row['monto_exacto']) || !empty($row['mismo_proveedor']);
            }));
            usort($rows, function ($a, $b) {
                $porExacto = (int) !empty($b['monto_exacto']) <=> (int) !empty($a['monto_exacto']);
                if ($porExacto !== 0) {
                    return $porExacto;
                }
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
        $modelo = null;
        try {
            $modelo = $this->loadModel('NotaCredito');
            $linea = $this->bloquearLineaParaMatch($modelo, $lineaId);
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
            // El monto exacto dejó de ser una condición para vincular y pasó a
            // ser una confirmación. Era una condición, y con eso las líneas que
            // el verificador identifica por la referencia del XML —la nota dice
            // a qué factura acredita— quedaban sin salida: se veía cuál era la
            // nota y no se podía aceptar. Aceptarla no borra la diferencia:
            // clasificar() la deja en 'con_diferencia', que es donde se cobra.
            if (!NotasCreditoVerificador::montosCoinciden($linea['monto'], $factura['total'])
                && (string) $this->post('aceptar_diferencia', '') !== '1') {
                throw new Exception(
                    'El monto del XML no es igual al del reporte (diferencia '
                    . number_format(round((float) $linea['monto'] - (float) $factura['total'], 2), 2, '.', ',')
                    . '). Confirmá la vinculación desde la lista de candidatas si aun así es la nota correcta.'
                );
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
                round($score, 1), true,
                $estado === 'con_diferencia'
                    ? 'Vinculada manualmente aceptando la diferencia de monto.'
                    : 'Vinculada manualmente.'
            );
            $modelo->commit();
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . (int) $linea['listado_id']),
                'La nota quedó vinculada manualmente.',
                'success'
            );
        } catch (Throwable $e) {
            if ($modelo) {
                $modelo->rollback();
            }
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    public function desvincular()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/notas-credito'));
        }
        $modelo = null;
        try {
            $modelo = $this->loadModel('NotaCredito');
            $linea = $this->bloquearLineaParaMatch(
                $modelo,
                (int) $this->post('linea_id', 0)
            );
            $modelo->actualizarMatch(
                (int) $linea['id'], null, 'sin_respaldo', null, 'ninguno',
                null, false, 'Desvinculada manualmente.', true
            );
            $modelo->commit();
            $this->redirectWithMessage(
                $this->url('/notas-credito?listado_id=' . (int) $linea['listado_id']),
                'La NC XML fue desvinculada. La fila queda bloqueada para el matching automático.',
                'warning'
            );
        } catch (Throwable $e) {
            if ($modelo) {
                $modelo->rollback();
            }
            $this->redirectWithMessage($this->url('/notas-credito'), $e->getMessage(), 'error');
        }
    }

    /**
     * Relee la línea bajo el mismo lock que usa el verificador. La primera
     * lectura ocurre fuera de la transacción solo para conocer qué cabecera
     * bloquear; la segunda es la que autoriza la decisión manual.
     */
    private function bloquearLineaParaMatch($modelo, $lineaId)
    {
        $inicial = $modelo->getLinea((int) $lineaId);
        if (!$inicial) {
            throw new Exception('La línea del listado no existe.');
        }

        $modelo->begin();
        $listadoId = (int) $inicial['listado_id'];
        if (!$modelo->bloquearListado($listadoId)) {
            throw new Exception('El acumulado de notas ya no existe.');
        }
        $bloqueada = $modelo->bloquearLinea((int) $lineaId);
        if (!$bloqueada || (int) $bloqueada['listado_id'] !== $listadoId) {
            throw new Exception('La nota cambió de acumulado; recarga la pantalla e inténtalo de nuevo.');
        }

        $linea = $modelo->getLinea((int) $lineaId);
        if (!$linea || (int) $linea['listado_id'] !== $listadoId) {
            throw new Exception('La nota cambió mientras se procesaba; inténtalo de nuevo.');
        }
        return $linea;
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

}
