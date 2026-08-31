<?php
require_once __DIR__ . '/../helpers/XmlDocumentImporter.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/BusquedaDocumento.php';
require_once __DIR__ . '/../helpers/AlcanceProveedor.php';
require_once __DIR__ . '/../helpers/BusquedaImporte.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/Retorno.php';

class NotasXmlController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        // 'alcance' entra con los demás: así la pregunta de qué proveedor
        // sale la primera vez y después de Limpiar, y no en cada visita.
        $this->recordarFiltros('notas_xml', [
            'desde', 'hasta', 'buscar', 'proveedor', AlcanceProveedor::PARAM,
            'monto', 'saldo',
        ]);

        /*
         * Tarjeta del documento que se está buscando: se llega acá con el botón
         * "Buscar en los XML cargados" del pago semanal o de la cola de
         * seguimiento, y se recorre esa lista sin volver al otro módulo.
         */
        $navDoc = null;
        try {
            $navDoc = NavegacionDocumentos::desde(
                $_GET,
                function ($nombre) { return $this->loadModel($nombre); },
                $this->url('')
            );
        } catch (Throwable $e) {
            $this->registrarFallo('Tarjeta del documento en /notas-xml', $e);
        }

        $desde = $this->fechaValida((string) $this->get('desde', ''));
        $hasta = $this->fechaValida((string) $this->get('hasta', ''));
        $buscar = mb_substr(trim((string) $this->get('buscar', '')), 0, 100, 'UTF-8');
        $proveedor = ProveedorCatalogo::normalizarClave($this->get('proveedor', ''));
        // Una nota se persigue por lo que rebaja tanto como por su número. El
        // saldo es el de la línea del reporte con la que esté enganchada.
        $importes = [
            'monto' => BusquedaImporte::numero($this->get('monto', '')),
            'saldo' => BusquedaImporte::numero($this->get('saldo', '')),
        ];
        $page = max(1, (int) $this->get('pagina', 1));
        $perPage = 100;
        $modelo = $this->loadModel('Factura');

        /*
         * Nadie pidió las notas de todos los proveedores por el hecho de abrir
         * la pantalla. Mientras no se elija, ni el conteo ni el listado se
         * ejecutan: solo se pregunta cuántas hay, para poder decirlo.
         */
        $elegirProveedor = AlcanceProveedor::hayQuePreguntar($_GET, $proveedor);
        $notas = [];
        $total = 0;
        $paginas = 1;
        $totalDelArchivo = null;

        if ($elegirProveedor) {
            $totalDelArchivo = $modelo->countNotasXml('', '', '', '');
        } else {
            $total = $modelo->countNotasXml($desde, $hasta, $buscar, $proveedor, $importes);
            $paginas = max(1, (int) ceil($total / $perPage));
            $page = min($page, $paginas);
            // Decoradas con lo que la columna no dice: si el archivo que su
            // ruta promete sigue estando en la carpeta compartida.
            $notas = EstadoArchivo::decorar(
                $modelo->getNotasXml($desde, $hasta, $buscar, $proveedor, $page, $perPage, $importes)
            );
        }

        // Igual que el listado de comprobantes: si se llegó buscando una nota
        // concreta, la pantalla dice si está cargada o no.
        $docBuscado = NavegacionDocumentos::documentoBuscado($navDoc, $buscar);
        $docBuscadoCargado = null;
        if ($docBuscado !== null && BusquedaDocumento::esNumero($docBuscado['busqueda'])) {
            try {
                $docBuscadoCargado = $modelo->existeNumeroXml($docBuscado['busqueda'], 'NC');
            } catch (Throwable $e) {
                // Sin respuesta no se afirma nada.
            }
        }

        $this->render('notasxml/index', [
            'title' => 'Notas de crédito · Carga XML - Nexo Fiscal',
            'notas' => $notas,
            'elegirProveedor' => $elegirProveedor,
            'totalDelArchivo' => $totalDelArchivo,
            'desde' => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'proveedor' => $proveedor,
            'importes' => $importes,
            'proveedoresFiltro' => ProveedorCatalogo::opciones($modelo->proveedoresParaFiltro('NC')),
            'pagina' => $page, 'paginas' => $paginas, 'total' => $total,
            'carpetaRaiz' => DocumentoArchivo::raizConfigurada(),
            'navDoc' => $navDoc,
            'docBuscadoCargado' => $docBuscadoCargado,
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
        $this->render('notasxml/detalle', [
            'title' => 'Detalle de Nota XML',
            'nota' => $nota,
            'retorno' => Retorno::anterior($_SERVER, $this->url(''), '/notas-xml'),
        ]);
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
            // Best effort: el listado conserva la verificación anterior y la
            // próxima entrada de notas la repite.
        }
    }
}
