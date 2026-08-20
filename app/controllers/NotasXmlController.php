<?php
require_once __DIR__ . '/../helpers/XmlDocumentImporter.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';

class NotasXmlController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $this->recordarFiltros('notas_xml', ['desde', 'hasta', 'buscar', 'proveedor']);

        $desde = $this->fechaValida((string) $this->get('desde', ''));
        $hasta = $this->fechaValida((string) $this->get('hasta', ''));
        $buscar = mb_substr(trim((string) $this->get('buscar', '')), 0, 100, 'UTF-8');
        $proveedor = ProveedorCatalogo::normalizarClave($this->get('proveedor', ''));
        $page = max(1, (int) $this->get('pagina', 1));
        $perPage = 100;
        $modelo = $this->loadModel('Factura');
        $total = $modelo->countNotasXml($desde, $hasta, $buscar, $proveedor);
        $paginas = max(1, (int) ceil($total / $perPage));
        $page = min($page, $paginas);

        $this->render('notasxml/index', [
            'title' => 'Notas de crédito · Carga XML - Nexo Fiscal',
            'notas' => $modelo->getNotasXml($desde, $hasta, $buscar, $proveedor, $page, $perPage),
            'desde' => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'proveedor' => $proveedor,
            'proveedoresFiltro' => ProveedorCatalogo::opciones($modelo->proveedoresParaFiltro('NC')),
            'pagina' => $page, 'paginas' => $paginas, 'total' => $total,
            'carpetaRaiz' => DocumentoArchivo::raizConfigurada(),
        ]);
    }

    public function subir()
    {
        if (!$this->isPost()) { $this->redirect($this->url('/notas-xml')); }
        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';
        $temp = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'xml';

        try {
            $archivos = FileUploader::uploadMultiple('xml_files', $temp,
                $config['allowed_extensions']['xml'] ?? ['xml'],
                $config['max_upload_size'] ?? 10485760);
            $importaciones = $this->loadModel('Importacion');
            $importacionId = (int) $importaciones->crear([
                'tipo' => 'xml',
                'archivo_origen' => 'Notas XML ' . date('d/m/Y H:i'),
                'ruta_archivo' => DocumentoArchivo::raizConfigurada(),
                'metadata' => json_encode(['tipo_documento' => 'NC', 'modo' => 'notas_xml'], JSON_UNESCAPED_UNICODE),
            ]);
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            $importer = new XmlDocumentImporter();
            $ok = $duplicados = $errores = 0;
            $detalle = [];
            foreach ($archivos as $archivo) {
                try {
                    $r = $importer->importar($archivo['path'], null, [
                        'origen' => 'notas_xml', 'importacion_id' => $importacionId,
                        'tipos_permitidos' => ['NC'], 'validar_receptor' => !empty($sociedad),
                        'cedula_receptor' => $sociedad['cedula'] ?? '',
                        'sociedad_id' => (int) ($sociedad['id'] ?? 0),
                    ]);
                    if (($r['estado'] ?? '') === 'importado') { $ok++; } else { $duplicados++; }
                } catch (Throwable $e) {
                    $errores++;
                    $detalle[] = ['archivo' => $archivo['original_name'], 'error' => $e->getMessage()];
                } finally {
                    if (is_file($archivo['path'])) { @unlink($archivo['path']); }
                }
            }
            $importaciones->cerrar($importacionId, count($archivos), $ok, $duplicados + $errores, $detalle);
            if ($ok > 0) { $this->revalidarListados($sociedad); }

            $this->redirectWithMessage($this->url('/notas-xml'),
                'Recibidas: ' . count($archivos) . " | Importadas: {$ok} | Ya existían: {$duplicados} | Errores: {$errores}",
                $errores > 0 ? 'warning' : 'success',
                ['failed_files' => array_column($detalle, 'archivo')]);
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/notas-xml'), 'No se pudieron importar las notas XML: ' . $e->getMessage(), 'error');
        }
    }

    public function ver($id)
    {
        $nota = $this->loadModel('Factura')->getOneWithProvider((int) $id);
        if (!$nota || strtoupper((string) $nota['tipo_documento']) !== 'NC') {
            $this->redirectWithMessage($this->url('/notas-xml'), 'Nota XML no encontrada.', 'warning');
        }
        $this->render('notasxml/detalle', ['title' => 'Detalle de Nota XML', 'nota' => $nota]);
    }

    private function fechaValida($fecha)
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : '';
    }

    private function revalidarListados($sociedad)
    {
        if (!$sociedad) { return; }
        try {
            require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
            NotasCreditoVerificador::verificarTodosSociedad((int) $sociedad['id'], $this->loadModel('NotaCredito'));
        } catch (Throwable $e) {
            // Best effort: el listado conserva su verificación manual.
        }
    }
}
