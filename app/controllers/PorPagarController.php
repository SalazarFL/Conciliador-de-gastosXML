<?php
/**
 * Controlador del pago semanal.
 *
 * El pago de una semana es una selección de facturas del ERP, no una copia del
 * archivo con que se eligieron. Cargar un listado significa: leer del archivo
 * el documento, el proveedor y el saldo, buscar cada factura en Facturas ERP y
 * marcarla como parte de la semana. Nada de lo que dice el archivo se guarda —
 * el archivo no es un origen de datos, es una selección—.
 *
 * De ahí sale el resto del comportamiento del módulo:
 *   - el checklist muestra los datos del ERP, que son los que cuadran contra
 *     los totales del proveedor;
 *   - el respaldo electrónico se cruza por consecutivo, que es una igualdad y
 *     no un parecido;
 *   - una factura que llega después por el correo se engancha sola, porque el
 *     XML encuentra su fila del ERP y esa fila ya sabe a qué semana pertenece.
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/../helpers/PagoSemanalResolutor.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';

class PorPagarController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $modelo = $this->loadModel('PorPagar');
        $erp = $this->loadModel('FacturaErp');
        $filtros = $this->filtrosListado();

        // El selector "Semana de trabajo" define lo que se muestra: semana_id=N
        // = el pago de esa semana; 0 = "Sin semana". Sin parámetro se usa la
        // última semana elegida (compartida entre módulos vía sesión).
        $semanaFiltro = $this->semanaActiva();
        if (isset($_GET['semana_id']) && $_GET['semana_id'] !== '') {
            $semanaFiltro = max(0, (int) $_GET['semana_id']);
            $this->setSemanaActiva($semanaFiltro);
        }

        $listados = $modelo->getListados(60, $semanaFiltro);

        $listadoId = (int) $this->get('listado_id', 0);
        $idsValidos = array_map(function ($l) { return (int) $l['id']; }, $listados);
        if ($listadoId <= 0 || !in_array($listadoId, $idsValidos, true)) {
            $listadoId = !empty($listados) ? (int) $listados[0]['id'] : 0;
        }

        $listado = $listadoId > 0 ? $modelo->getListado($listadoId) : null;
        $lineas = $listado ? $erp->getFacturasPago($listadoId, $filtros) : [];
        $resumen = $listado ? $erp->resumenRespaldoPago($listadoId) : [];
        $dimensiones = $listado
            ? $erp->dimensionesPago($listadoId)
            : ['proveedores' => [], 'sucursales' => []];

        // Término de búsqueda para el botón "Buscar en correo" de cada línea.
        foreach ($lineas as &$linea) {
            $linea['numero_busqueda'] = $this->numeroBusqueda((string) $linea['documento']);
        }
        unset($linea);

        $sociedadActiva = null;
        try {
            $sociedadActiva = $this->loadModel('Sociedad')->getActiva();
        } catch (Throwable $e) {
        }

        $semanas = [];
        try {
            $semanas = $this->loadModel('Semana')->getAll();
        } catch (Throwable $e) {
        }

        $carpetasPago = [];
        try {
            if (DocumentoArchivo::raizConfigurada() !== '') {
                $carpetasPago = (new DocumentoArchivo())->carpetasPagoSemanal();
            }
        } catch (Throwable $e) {
        }

        $sinCoincidencia = 0;
        if ($listado) {
            try {
                $sinCoincidencia = count($erp->getXmlSinCoincidencia($listadoId));
            } catch (Throwable $e) {
            }
        }

        $this->render('porpagar/index', [
            'title'           => 'Pagos semanales - Nexo Fiscal',
            'listados'        => $listados,
            'listado'         => $listado,
            'lineas'          => $lineas,
            'resumen'         => $resumen,
            'sociedadActiva'  => $sociedadActiva,
            'semanas'         => $semanas,
            'semanaFiltro'    => $semanaFiltro,
            'carpetasPago'    => $carpetasPago,
            'sinCoincidencia' => $sinCoincidencia,
            'filtros'         => $filtros,
            'proveedoresFiltro' => ProveedorCatalogo::opciones($dimensiones['proveedores']),
            'sucursales'        => $dimensiones['sucursales'],
        ]);
    }

    /** Normaliza los buscadores del checklist. */
    private function filtrosListado()
    {
        $q = mb_substr(trim((string) $this->get('q', '')), 0, 150, 'UTF-8');
        $proveedor = ProveedorCatalogo::normalizarClave($this->get('proveedor', ''));
        $sucursal = mb_substr(trim((string) $this->get('sucursal', '')), 0, 60, 'UTF-8');

        $estado = strtolower(trim((string) $this->get('estado', '')));
        if (!in_array($estado, ['', 'respaldada', 'con_diferencia', 'sin_respaldo'], true)) {
            $estado = '';
        }

        $vinculo = strtolower(trim((string) $this->get('vinculo', '')));
        if (!in_array($vinculo, ['', 'automatico', 'manual', 'sin_vinculo'], true)) {
            $vinculo = '';
        }

        $desde = $this->fechaFiltro((string) $this->get('fecha_desde', ''));
        $hasta = $this->fechaFiltro((string) $this->get('fecha_hasta', ''));
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            $tmp = $desde; $desde = $hasta; $hasta = $tmp;
        }

        $montoDesde = trim((string) $this->get('monto_desde', ''));
        $montoHasta = trim((string) $this->get('monto_hasta', ''));
        $montoDesde = is_numeric($montoDesde) && (float) $montoDesde >= 0 ? $montoDesde : '';
        $montoHasta = is_numeric($montoHasta) && (float) $montoHasta >= 0 ? $montoHasta : '';
        if ($montoDesde !== '' && $montoHasta !== '' && (float) $montoDesde > (float) $montoHasta) {
            $tmp = $montoDesde; $montoDesde = $montoHasta; $montoHasta = $tmp;
        }

        return [
            'q' => $q,
            'proveedor' => $proveedor,
            'sucursal' => $sucursal,
            'estado' => $estado,
            'vinculo' => $vinculo,
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'monto_desde' => $montoDesde,
            'monto_hasta' => $montoHasta,
        ];
    }

    private function fechaFiltro($valor)
    {
        $valor = trim((string) $valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
            return '';
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valor : '';
    }

    // ── Carga del listado ──────────────────────────────────────────

    /**
     * Lee el archivo y resuelve cada fila contra Facturas ERP.
     *
     * No escribe: lo comparten la vista previa, la carga y la actualización,
     * y que las tres hagan la misma lectura es lo que permite confirmar sobre
     * lo que se ve en pantalla. El archivo temporal se borra siempre.
     */
    private function leerYResolver($erp, $listadoId = 0, $conservarArchivo = false)
    {
        require_once __DIR__ . '/../helpers/FileUploader.php';
        require_once __DIR__ . '/../helpers/Validator.php';
        require_once __DIR__ . '/../helpers/XlsxReader.php';

        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'porpagar';
        $maxSize = $config['max_upload_size'] ?? 10485760;
        $rutaTemporal = '';
        $conservar = (bool) $conservarArchivo;

        try {
            // Limpieza oportunista de vistas previas abandonadas (> 6 horas).
            foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $viejo) {
                if (is_file($viejo) && filemtime($viejo) < time() - 21600) {
                    @unlink($viejo);
                }
            }

            // Archivo recién elegido, o el que la vista previa dejó guardado y
            // que la confirmación consume por su token.
            $token = basename(trim((string) $this->post('archivo_token', '')));
            if ($token !== '') {
                $rutaTemporal = $uploadDir . DIRECTORY_SEPARATOR . $token;
                if (!is_file($rutaTemporal)) {
                    throw new Exception('La vista previa expiró. Volvé a elegir el archivo.');
                }
                $nombre = trim((string) $this->post('archivo_nombre', '')) ?: $token;
            } else {
                if (isset($_FILES['listado_file']['name']) && is_array($_FILES['listado_file']['name'])) {
                    throw new Exception('Selecciona un solo archivo.');
                }
                $file = FileUploader::uploadSingle('listado_file', $uploadDir, ['csv', 'xlsx', 'xls'], $maxSize);
                $rutaTemporal = (string) $file['path'];
                $nombre = (string) $file['original_name'];
            }

            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if ($ext === 'xls') {
                throw new Exception('El formato .xls no está soportado. Guarda el archivo como .xlsx o .csv.');
            }
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                $ext = strtolower(pathinfo($rutaTemporal, PATHINFO_EXTENSION));
            }
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                throw new Exception('Selecciona un archivo CSV o XLSX.');
            }

            $filas = $this->leerFilas($rutaTemporal, $ext);
            $resolucion = PagoSemanalResolutor::resolver($filas, $erp->getIndicePago(), (int) $listadoId);

            return [
                'archivo' => $nombre,
                'token' => $conservar ? basename($rutaTemporal) : null,
                'resolucion' => $resolucion,
            ];
        } finally {
            if (!$conservar && $rutaTemporal !== '' && is_file($rutaTemporal)) {
                @unlink($rutaTemporal);
            }
        }
    }

    /**
     * Filas crudas del archivo: documento, proveedor y saldo.
     *
     * Se aceptan los dos formatos de siempre —la tabla con encabezados y el
     * reporte agrupado que exporta el sistema de la empresa— porque son los que
     * la gente tiene. Lo que cambió es qué se hace con ellos: la fecha ya no se
     * lee (la pone el ERP) y el importe se usa para desempatar, no para
     * guardarlo.
     */
    private function leerFilas($rutaArchivo, $ext)
    {
        $dataset = $ext === 'csv'
            ? $this->readCsvData($rutaArchivo)
            : XlsxReader::readFirstSheet($rutaArchivo);
        $dataset = $this->normalizarDataset($dataset);

        $map = $this->buildHeaderMap($dataset['header']);
        $registros = null;
        try {
            $this->validateRequiredColumns($map);
        } catch (Throwable $errorColumnas) {
            $registros = $this->extraerReporteAgrupado($dataset);
            if (empty($registros)) {
                throw $errorColumnas;
            }
        }

        if ($registros === null) {
            $registros = [];
            foreach ($dataset['rows'] as $row) {
                $registros[] = [
                    'numero' => $this->getValue($row, $map, ['numero', 'documento']),
                    'proveedor' => $this->getValue($row, $map, ['proveedor']),
                    'total' => $this->getValue($row, $map, ['saldo', 'total']),
                ];
            }
        }

        $filas = [];
        foreach ($registros as $row) {
            try {
                $numero = trim((string) ($row['numero'] ?? ''));
                $proveedor = trim((string) ($row['proveedor'] ?? ''));
                $saldo = Validator::parseAmount($row['total'] ?? ($row['saldo'] ?? 0));

                // Números que Excel entrega como 26546.0
                if (preg_match('/^\d+\.0$/', $numero)) {
                    $numero = substr($numero, 0, -2);
                }

                if ($numero === '' && $proveedor === '' && $saldo <= 0) {
                    continue; // fila totalmente vacía: ni contarla
                }
                if ($numero === '') {
                    throw new Exception('Número de documento vacío.');
                }

                $filas[] = [
                    'estado' => 'leida',
                    'numero' => $numero,
                    'proveedor' => $proveedor,
                    'saldo' => $saldo,
                ];
            } catch (Throwable $e) {
                $filas[] = [
                    'estado' => 'error',
                    'motivo' => $e->getMessage(),
                    'numero' => trim((string) ($row['numero'] ?? '')),
                    'proveedor' => trim((string) ($row['proveedor'] ?? '')),
                    'saldo' => 0.0,
                ];
            }
        }

        return $filas;
    }

    /**
     * Vista previa (POST, JSON): dice qué haría la carga sin hacer nada.
     */
    public function previsualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $erp = $this->loadModel('FacturaErp');
            $semanaId = $this->semanaSolicitada();
            $listadoPrevio = $this->listadoDeSemana($semanaId);
            $listadoId = $listadoPrevio ? (int) $listadoPrevio['id'] : 0;

            $datos = $this->leerYResolver($erp, $listadoId, true);
            $resumen = $datos['resolucion']['resumen'];

            $montoResuelto = 0.0;
            foreach ($datos['resolucion']['filas'] as $fila) {
                if ($fila['estado'] === 'resuelta' && $fila['erp']) {
                    $montoResuelto += (float) $fila['erp']['saldo'];
                }
            }

            $this->json([
                'ok' => true,
                'archivo' => $datos['archivo'],
                'token' => $datos['token'],
                'carpeta_pago' => DocumentoArchivo::normalizarCarpetaPago((string) $this->post('carpeta_pago', '')),
                'listado_existente' => $listadoPrevio ? (string) $listadoPrevio['nombre'] : null,
                'resumen' => $resumen,
                'monto_resuelto' => round($montoResuelto, 2),
                'lineas' => array_slice($datos['resolucion']['filas'], 0, 1000),
                'total_resultados' => count($datos['resolucion']['filas']),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Carga el listado: marca en Facturas ERP las facturas que se pagan.
     */
    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        @set_time_limit(300);

        try {
            $modelo = $this->loadModel('PorPagar');
            $erp = $this->loadModel('FacturaErp');

            $semanaId = $this->semanaSolicitada(true);
            $semana = $this->prepararSemana($semanaId);

            $listadoPrevio = $this->listadoDeSemana($semanaId);
            $listadoId = $listadoPrevio ? (int) $listadoPrevio['id'] : 0;

            $datos = $this->leerYResolver($erp, $listadoId);
            $resolucion = $datos['resolucion'];
            $resumen = $resolucion['resumen'];

            if ($resumen['resuelta'] < 1) {
                throw new Exception(
                    'Ninguna factura del archivo está en Facturas ERP. '
                    . 'Cargá primero el reporte "Facturas por Proveedor" que las incluya, en Carga de documentos.'
                );
            }

            // Todo o nada: media semana cargada obliga a llevar en la cabeza
            // cuáles entraron. Si algo no resolvió, se dice y no se escribe.
            $this->exigirResolucionCompleta($resumen, $resolucion['filas']);

            $sociedadId = null;
            try {
                $activa = $this->loadModel('Sociedad')->getActiva();
                $sociedadId = $activa ? (int) $activa['id'] : null;
            } catch (Throwable $e) {
            }

            if ($listadoId <= 0) {
                $nombre = !empty($semana['nombre']) ? 'Pago ' . $semana['nombre'] : 'Pago ' . date('d/m/Y H:i');
                $listadoId = (int) $modelo->crearListado($nombre, $sociedadId, $datos['archivo'], $semanaId);
            }
            $nombre = $listadoPrevio ? (string) $listadoPrevio['nombre'] : ('Pago ' . ($semana['nombre'] ?? ''));

            $yaEstaban = $erp->contarPago($listadoId);
            $erp->asignarAPago($resolucion['ids'], $semanaId, $listadoId);
            $modelo->actualizarTotalLineas($listadoId);

            $stats = $this->ejecutarMatching($listadoId, $erp);
            $total = $erp->contarPago($listadoId);
            $nuevas = max(0, $total - $yaEstaban);

            $msg = ($listadoPrevio
                    ? "Pago \"{$nombre}\": +{$nuevas} facturas nuevas (total {$total})"
                    : "Pago \"{$nombre}\": {$total} facturas del ERP")
                . " — {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.";

            $this->redirectWithMessage(
                $this->url('/por-pagar?listado_id=' . $listadoId . '&semana_id=' . (int) $semanaId),
                $msg,
                $stats['sin_respaldo'] + $stats['con_diferencia'] > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'), 'Error al cargar el pago: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Corta la carga si alguna fila del archivo no encontró su factura.
     *
     * La regla de negocio no cambió —el pago semanal no inventa facturas, paga
     * las que el ERP ya reportó—, pero ahora se comprueba resolviendo de
     * verdad en vez de preguntando si el número existe. El mensaje nombra las
     * primeras, que es lo que hace falta para ir a cargar el reporte correcto.
     */
    private function exigirResolucionCompleta(array $resumen, array $filas)
    {
        $problemas = [];
        foreach (['ausente' => 'no están en Facturas ERP',
                  'ambigua' => 'tienen más de una factura posible en el ERP',
                  'en_otro_pago' => 'ya están asignadas a otro pago semanal',
                  'error' => 'no se pudieron leer'] as $estado => $texto) {
            if (empty($resumen[$estado])) {
                continue;
            }
            $muestra = [];
            foreach ($filas as $fila) {
                if ($fila['estado'] === $estado && count($muestra) < 5) {
                    $muestra[] = $fila['numero'] !== '' ? $fila['numero'] : '(sin número)';
                }
            }
            $problemas[] = $resumen[$estado] . ' ' . $texto . ' (' . implode(', ', $muestra)
                . ($resumen[$estado] > count($muestra) ? ', …' : '') . ')';
        }

        if ($problemas) {
            throw new Exception('No se cargó nada: ' . implode('; ', $problemas)
                . '. Corregí el archivo o cargá el reporte del ERP que falte.');
        }
    }

    private function semanaSolicitada($permitirNueva = false)
    {
        $seleccion = trim((string) $this->post('semana_id', ''));
        if ($permitirNueva) {
            $semanaId = $this->loadModel('Semana')->resolverSeleccion(
                $seleccion,
                (string) $this->post('semana_nueva', '')
            );
            if (empty($semanaId)) {
                throw new Exception('Elegí la semana de trabajo del pago.');
            }
            return (int) $semanaId;
        }

        if (!ctype_digit($seleccion) || (int) $seleccion <= 0) {
            throw new Exception('Seleccioná una semana existente.');
        }
        return (int) $seleccion;
    }

    /** Deja lista la carpeta del pago semanal de la semana elegida. */
    private function prepararSemana($semanaId)
    {
        $semanaModel = $this->loadModel('Semana');
        $semana = $semanaModel->findById((int) $semanaId);
        if ($semana === null) {
            throw new Exception('La semana seleccionada ya no existe.');
        }

        $carpetaSolicitada = trim((string) $this->post('carpeta_pago', ''));
        if ($carpetaSolicitada === '') {
            $carpetaSolicitada = (string) ($semana['carpeta_pago'] ?? ($semana['nombre'] ?? ''));
        }
        $carpetaPago = DocumentoArchivo::normalizarCarpetaPago($carpetaSolicitada);
        if ($carpetaPago === '') {
            throw new Exception('Seleccioná una carpeta válida para el pago semanal.');
        }
        $semanaModel->configurarCarpetaPago((int) $semanaId, $carpetaPago);
        try {
            (new DocumentoArchivo())->prepararCarpetaPagoSemanal($carpetaPago);
        } catch (Throwable $e) {
            // Sin carpeta raíz configurada el pago se carga igual; los
            // archivos se acomodan cuando alguien la configure.
        }
        $semana['carpeta_pago'] = $carpetaPago;
        return $semana;
    }

    private function listadoDeSemana($semanaId)
    {
        if ((int) $semanaId <= 0) {
            return null;
        }
        $previos = $this->loadModel('PorPagar')->getListados(1, (int) $semanaId);
        return !empty($previos) ? $previos[0] : null;
    }

    // ── Comparar y actualizar ──────────────────────────────────────

    /**
     * Comparador (POST, JSON): qué facturas entrarían y cuáles saldrían si se
     * aplicara este archivo. De solo lectura.
     */
    public function compararListado()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/PorPagarComparador.php';

        try {
            $erp = $this->loadModel('FacturaErp');
            $semanaId = $this->semanaSolicitada();
            $semana = $this->loadModel('Semana')->findById($semanaId);
            if ($semana === null) {
                throw new Exception('La semana seleccionada ya no existe.');
            }

            $listado = $this->listadoDeSemana($semanaId);
            $listadoId = $listado ? (int) $listado['id'] : 0;

            $datos = $this->leerYResolver($erp, $listadoId);
            $asignadas = $listadoId > 0 ? $erp->getFacturasPago($listadoId) : [];
            $comparacion = PorPagarComparador::comparar($datos['resolucion'], $asignadas);

            $this->json([
                'ok' => true,
                'archivo' => $datos['archivo'],
                'semana' => (string) ($semana['nombre'] ?? ('Semana #' . $semanaId)),
                'listado_existente' => $listado ? (string) $listado['nombre'] : null,
                'resumen' => $comparacion['resumen'],
                'lineas' => $comparacion['lineas'],
                'total_resultados' => count($comparacion['lineas']),
                'solo_lectura' => true,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Deja el pago de la semana igual al archivo nuevo (POST, JSON).
     *
     * Lo que entra se marca en Facturas ERP; lo que sale se desmarca y vuelve a
     * "pendiente". Después los XML y PDF se acomodan: los de las facturas que
     * salieron regresan al árbol por fecha de emisión y los de las que entraron
     * se reúnen en la carpeta del pago.
     */
    public function actualizarListado()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/PorPagarComparador.php';
        @set_time_limit(300);

        try {
            $modelo = $this->loadModel('PorPagar');
            $erp = $this->loadModel('FacturaErp');
            $semanaId = $this->semanaSolicitada();

            $listado = $this->listadoDeSemana($semanaId);
            if ($listado === null) {
                throw new Exception('Esta semana todavía no tiene pago que actualizar. Usá "Vista previa" para cargarlo por primera vez.');
            }
            $listadoId = (int) $listado['id'];

            $datos = $this->leerYResolver($erp, $listadoId);
            $asignadas = $erp->getFacturasPago($listadoId);
            $comparacion = PorPagarComparador::comparar($datos['resolucion'], $asignadas);
            $resumen = $comparacion['resumen'];

            if ((int) $resumen['nueva'] + (int) $resumen['igual'] < 1) {
                throw new Exception('El archivo no resolvió ninguna factura del ERP; no se actualizó nada.');
            }
            $this->exigirComparacionConfirmada($resumen);

            $entran = [];
            $salen = [];
            foreach ($comparacion['lineas'] as $linea) {
                if ($linea['estado'] === 'nueva') {
                    $entran[] = (int) $linea['factura_erp_id'];
                } elseif ($linea['estado'] === 'faltante') {
                    $salen[] = (int) $linea['factura_erp_id'];
                }
            }

            // Una fila ilegible no dice "quitá esta factura", pero se ve igual:
            // la que estaba sale como ausente y se iría con las demás.
            if ($salen && (int) $resumen['error'] > 0) {
                throw new Exception(sprintf(
                    'El archivo tiene %d fila(s) ilegibles y %d factura(s) quedarían fuera del pago; no se actualizó nada. '
                    . 'Corregí el archivo y volvé a comparar.',
                    (int) $resumen['error'],
                    count($salen)
                ));
            }

            if (!$entran && !$salen) {
                $this->json([
                    'ok' => true,
                    'sin_cambios' => true,
                    'message' => 'El pago ya coincide con el archivo: no había nada que actualizar.',
                ]);
            }

            // Los XML de las que salen, antes de soltarlos: son los archivos
            // que hay que devolver a su carpeta por fecha.
            $xmlDeLasQueSalen = [];
            foreach ($asignadas as $factura) {
                if (in_array((int) $factura['id'], $salen, true) && !empty($factura['factura_xml_id'])) {
                    $xmlDeLasQueSalen[] = (int) $factura['factura_xml_id'];
                }
            }

            $modelo->begin();
            try {
                $quitadas = $salen ? $erp->quitarDePago($salen, $listadoId) : 0;
                $anadidas = $entran ? $erp->asignarAPago($entran, $semanaId, $listadoId) : 0;
                $modelo->actualizarTotalLineas($listadoId);
                $modelo->commit();
            } catch (Throwable $e) {
                $modelo->rollback();
                throw $e;
            }

            $erp->soltarSemanaXml($xmlDeLasQueSalen);
            $stats = $this->ejecutarMatching($listadoId, $erp);

            $xmlDespues = $erp->idsXmlDePago($listadoId);
            $archivos = $this->reubicarArchivos(array_merge(
                array_diff($xmlDeLasQueSalen, $xmlDespues),
                array_diff($xmlDespues, $xmlDeLasQueSalen)
            ));

            $this->json([
                'ok' => true,
                'sin_cambios' => false,
                'listado_id' => $listadoId,
                'semana_id' => $semanaId,
                'anadidas' => $anadidas,
                'quitadas' => $quitadas,
                'estados' => $stats,
                'archivos' => $archivos,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Corta si el archivo dejó de dar el mismo resultado que la comparación
     * que se confirmó. Sin esto se estaría desmarcando facturas a ciegas.
     */
    private function exigirComparacionConfirmada(array $resumen)
    {
        foreach (['nueva', 'faltante'] as $clave) {
            $esperado = trim((string) $this->post('esperado_' . $clave, ''));
            if (!ctype_digit($esperado)) {
                throw new Exception('No llegó la comparación que se está confirmando. Volvé a comparar.');
            }
            if ((int) $esperado !== (int) $resumen[$clave]) {
                throw new Exception('El archivo ya no da el mismo resultado que la comparación en pantalla; no se actualizó nada. Volvé a comparar antes de actualizar.');
            }
        }
    }

    /**
     * Devuelve cada XML/PDF a la carpeta que le toca según cómo quedó el pago.
     * El destino lo decide el organizador leyendo la base; acá solo se le
     * nombran las facturas que cambiaron de lado.
     */
    private function reubicarArchivos(array $facturaIds)
    {
        $resultado = ['movidos' => 0, 'por_fecha' => 0, 'pago_semanal' => 0, 'aviso' => ''];

        $facturaIds = array_values(array_unique(array_filter(array_map('intval', $facturaIds))));
        if (!$facturaIds) {
            return $resultado;
        }
        if (DocumentoArchivo::raizConfigurada() === '') {
            $resultado['aviso'] = 'No hay carpeta raíz configurada: los archivos quedaron donde estaban.';
            return $resultado;
        }

        try {
            require_once __DIR__ . '/../helpers/OrganizadorDocumentos.php';
            $resumen = (new OrganizadorDocumentos())->organizarIds($facturaIds, false);
            if (!empty($resumen['omitido_por_bloqueo'])) {
                $resultado['aviso'] = 'Hay otra ordenación en curso en esta computadora: los archivos no se movieron.';
                return $resultado;
            }
            $resultado['movidos'] = (int) ($resumen['movidos'] ?? 0);
            $resultado['por_fecha'] = (int) ($resumen['por_fecha'] ?? 0);
            $resultado['pago_semanal'] = (int) ($resumen['pago_semanal'] ?? 0);
            if (!empty($resumen['errores'])) {
                $resultado['aviso'] = $resumen['errores'] . ' documento(s) no se pudieron mover.';
            }
        } catch (Throwable $e) {
            $resultado['aviso'] = 'No se pudieron mover los archivos: ' . $e->getMessage();
        }

        return $resultado;
    }

    // ── Verificar, eliminar ────────────────────────────────────────

    /**
     * El pago semanal no se cierra.
     *
     * Existió un botón "Cerrar pago semanal" que congelaba la semana: una vez
     * cerrada nadie añadía, quitaba ni reemparejaba nada. Con la base de datos
     * compartida esa foto fija se volvió mentira — dos personas trabajan la
     * misma semana desde máquinas distintas, y el XML que una consigue el
     * viernes tiene que aparecerle a la otra—. Congelar solo lograba que el
     * respaldo quedara desactualizado sin forma de arreglarlo.
     *
     * Ahora no hay estado: cada XML que entra —por subida, por correo o por
     * vínculo manual— vuelve a cruzar el pago, y lo que ve una persona es lo
     * que ven todas.
     */
    public function verificar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $modelo = $this->loadModel('PorPagar');
            $erp = $this->loadModel('FacturaErp');
            $registro = $modelo->getListado((int) $id);
            if ($registro === null) {
                $this->redirectWithMessage($this->url('/por-pagar'), 'El pago semanal no existe.', 'error');
            }

            $destino = '/por-pagar?listado_id=' . (int) $id . '&semana_id=' . (int) ($registro['semana_id'] ?? 0);
            $stats = $this->ejecutarMatching((int) $id, $erp);
            $this->redirectWithMessage(
                $this->url($destino),
                "Verificación actualizada: {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.",
                $stats['sin_respaldo'] + $stats['con_diferencia'] > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'), 'No se pudo verificar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Borra el pago de una semana entera.
     *
     * Lo único que se borra es la selección —qué facturas se pagaban esa
     * semana—. Las facturas del ERP vuelven a "pendiente" y sus comprobantes
     * salen de la carpeta del pago hacia el árbol por fecha de emisión. Ni las
     * facturas ni los XML/PDF se eliminan: son el registro de la empresa.
     *
     * La semana en sí tampoco desaparece; sigue existiendo para el correo, los
     * comprobantes y el seguimiento. Lo que queda es una semana sin pago
     * cargado, lista para cargarle otro archivo.
     */
    public function borrarSemana($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $erp = $this->loadModel('FacturaErp');
            $xmlIds = $erp->idsXmlDePago((int) $id);
            $total = $erp->contarPago((int) $id);
            $this->loadModel('PorPagar')->eliminarListado((int) $id);
            $erp->soltarSemanaXml($xmlIds);
            $archivos = $this->reubicarArchivos($xmlIds);

            $msg = sprintf(
                'Pago de la semana borrado: %d factura(s) del ERP volvieron a quedar disponibles.',
                $total
            );
            if ($archivos['por_fecha'] > 0) {
                $msg .= sprintf(
                    ' %d documento(s) regresaron a la carpeta por fecha de emisión.',
                    $archivos['por_fecha']
                );
            }
            if ($archivos['aviso'] !== '') {
                $msg .= ' ' . $archivos['aviso'];
            }
            $this->redirectWithMessage($this->url('/por-pagar'), $msg,
                $archivos['aviso'] !== '' ? 'warning' : 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'),
                'No se pudo borrar la semana: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Quita una factura de la semana. No borra nada.
     *
     * La factura del ERP vuelve a "pendiente" —sigue existiendo, disponible
     * para otra semana— y su XML/PDF sale de la carpeta del pago y regresa al
     * árbol por fecha de emisión, que es donde vive un comprobante que no se
     * está entregando con ningún pago.
     */
    public function quitarFactura($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $erp = $this->loadModel('FacturaErp');
            $factura = $erp->getFacturaPago((int) $id);
            if ($factura === null || empty($factura['porpagar_listado_id'])) {
                $this->redirectWithMessage($this->url('/por-pagar'),
                    'Esa factura no está en ningún pago semanal.', 'error');
            }
            $listadoId = (int) $factura['porpagar_listado_id'];
            $xmlId = (int) ($factura['factura_xml_id'] ?? 0);

            // El orden importa: soltar la semana del XML antes de mover el
            // archivo. El organizador decide la carpeta leyendo la base, y
            // mientras el comprobante siga marcado con la semana del pago
            // creería que todavía hay que entregarlo y lo dejaría donde está.
            $erp->quitarDePago([(int) $id], $listadoId);
            $this->loadModel('PorPagar')->actualizarTotalLineas($listadoId);
            $archivos = ['por_fecha' => 0, 'aviso' => ''];
            if ($xmlId > 0) {
                $erp->soltarSemanaXml([$xmlId]);
                $archivos = $this->reubicarArchivos([$xmlId]);
            }

            $destino = '/por-pagar?listado_id=' . $listadoId . '&semana_id=' . (int) ($factura['listado_semana_id'] ?? 0);
            $filtrosRetorno = array_filter($this->filtrosListado(), function ($valor) {
                return $valor !== '' && $valor !== null;
            });
            if ($filtrosRetorno) {
                $destino .= '&' . http_build_query($filtrosRetorno);
            }

            $msg = 'La factura ' . trim((string) $factura['documento'])
                . ' ya no es de esta semana. No se eliminó nada: sigue en Facturas ERP, disponible para otra semana.';
            if ($archivos['por_fecha'] > 0) {
                $msg .= ' Su XML y PDF regresaron a la carpeta de documentos por fecha de emisión.';
            }
            if ($archivos['aviso'] !== '') {
                $msg .= ' ' . $archivos['aviso'];
            }
            $this->redirectWithMessage($this->url($destino), $msg,
                $archivos['aviso'] !== '' ? 'warning' : 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'),
                'No se pudo quitar la factura de la semana: ' . $e->getMessage(), 'error');
        }
    }

    // ── Vínculo manual ─────────────────────────────────────────────

    /**
     * JSON del botón "Sin coincidencia": XML de la semana que no respaldan
     * ninguna factura del pago, y las facturas del pago aún sin respaldo.
     */
    public function sinCoincidencia()
    {
        try {
            $erp = $this->loadModel('FacturaErp');
            $listadoId = (int) $this->get('listado_id', 0);
            $listado = $this->loadModel('PorPagar')->getListado($listadoId);
            if ($listado === null) {
                $this->json(['ok' => false, 'message' => 'El pago semanal no existe.'], 404);
            }

            $facturas = array_map(function ($f) {
                return [
                    'id'        => (int) $f['id'],
                    'numero'    => NumeroFactura::xmlOchoDigitos($f['numero_factura_asistente']),
                    'proveedor' => (string) ($f['proveedor_nombre'] ?? ''),
                    'total'     => (float) $f['total'],
                    'fecha'     => (string) ($f['fecha_emision'] ?? ''),
                ];
            }, $erp->getXmlSinCoincidencia($listadoId));

            $lineas = array_map(function ($l) {
                return [
                    'id'        => (int) $l['id'],
                    'numero'    => (string) $l['documento'],
                    'proveedor' => (string) $l['proveedor_nombre'],
                    'total'     => (float) $l['monto'],
                ];
            }, $erp->getFacturasPago($listadoId, ['estado' => 'sin_respaldo']));

            $semanas = [];
            try {
                foreach ($this->loadModel('Semana')->getAll() as $s) {
                    $semanas[] = ['id' => (int) $s['id'], 'nombre' => (string) $s['nombre']];
                }
            } catch (Throwable $e) {
            }

            $this->json([
                'ok'        => true,
                'semana_id' => (int) ($listado['semana_id'] ?? 0),
                'facturas'  => $facturas,
                'lineas'    => $lineas,
                'semanas'   => $semanas,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Vincula a mano una factura del pago con un XML. El número y el proveedor
     * ya no se evalúan: solo el monto clasifica, y match_manual protege el
     * vínculo de la verificación automática.
     */
    public function forzar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            $erp = $this->loadModel('FacturaErp');
            $facturaModel = $this->loadModel('Factura');

            $lineaErp = $erp->getFacturaPago((int) $this->post('linea_id', 0));
            $xml = $facturaModel->findById((int) $this->post('factura_id', 0));
            if ($lineaErp === null || !$xml) {
                $this->json(['ok' => false, 'message' => 'La factura del pago o el XML no existen.'], 404);
            }
            $listadoId = (int) $lineaErp['porpagar_listado_id'];
            foreach ($erp->getFacturasPagoParaMatching($listadoId) as $otra) {
                if ((int) ($otra['factura_xml_id'] ?? 0) === (int) $xml['id']
                    && (int) $otra['id'] !== (int) $lineaErp['id']) {
                    $this->json(['ok' => false, 'message' => 'Ese XML ya respalda la factura "' . $otra['documento'] . '".'], 409);
                }
            }

            $diferencia = round((float) $lineaErp['monto'] - (float) $xml['total'], 2);
            $estado = abs($diferencia) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $erp->actualizarRespaldoManual(
                (int) $lineaErp['id'],
                (int) $xml['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null
            );

            $aliasTexto = $this->aprenderAliasProveedor($lineaErp, $xml);
            $aprendido = $aliasTexto !== '';
            $codigoAprendido = $this->aprenderCodigoProveedor($lineaErp, $xml);

            $this->ejecutarMatching($listadoId, $erp);
            $ncResueltas = $aprendido ? $this->reprocesarNotasCredito($aliasTexto) : 0;

            $this->json([
                'ok' => true,
                'estado' => $estado,
                'diferencia' => $diferencia,
                'alias_aprendido' => $aprendido,
                'codigo_aprendido' => $codigoAprendido,
                'notas_resueltas' => $ncResueltas,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()], 500);
        }
    }

    /** Exporta el checklist del pago como un libro XLSX real. */
    public function exportar()
    {
        try {
            require_once __DIR__ . '/../helpers/XlsxWriter.php';

            $listadoId = (int) $this->get('listado_id', 0);
            $listado = $this->loadModel('PorPagar')->getListado($listadoId);
            if ($listado === null) {
                throw new Exception('Pago semanal no encontrado.');
            }

            $lineas = $this->loadModel('FacturaErp')->getFacturasPago($listadoId, $this->filtrosListado());
            $headers = ['Fecha', 'Documento', 'Proveedor', 'Monto ERP', 'Saldo', 'Número XML', 'Total XML', 'Diferencia', 'Estado'];
            $etiquetas = [
                'respaldada' => 'Respaldada',
                'con_diferencia' => 'Con diferencia',
                'sin_respaldo' => 'Sin respaldo',
            ];
            $rows = [];
            $cellStyles = [];

            foreach ($lineas as $ri => $linea) {
                $estado = (string) ($linea['estado'] ?? '');
                $rows[] = [
                    (string) ($linea['fecha_emision'] ?? ''),
                    (string) $linea['documento'],
                    (string) $linea['proveedor_nombre'],
                    round((float) $linea['monto'], 2),
                    round((float) $linea['saldo_pago'], 2),
                    $linea['xml_numero'] !== null ? NumeroFactura::xmlOchoDigitos($linea['xml_numero']) : '',
                    $linea['xml_total'] !== null ? round((float) $linea['xml_total'], 2) : '',
                    $linea['diferencia'] !== null ? round((float) $linea['diferencia'], 2) : '',
                    $etiquetas[$estado] ?? $estado,
                ];
                $cellStyles[$ri][8] = $estado === 'respaldada' ? 3 : 2;
            }

            $nombreBase = trim((string) ($listado['semana_nombre'] ?? $listado['nombre'] ?? $listadoId));
            $nombreBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', $nombreBase);
            $nombreBase = trim((string) $nombreBase, '_') ?: (string) $listadoId;
            $nombreArchivo = 'por_pagar_' . $nombreBase . '_' . date('Ymd_His') . '.xlsx';
            $anchos = [13, 24, 38, 16, 16, 22, 16, 16, 18];

            $tmpFile = XlsxWriter::generate($headers, $rows, 'Pagos semanales', $cellStyles, $anchos);
            XlsxWriter::send($tmpFile, $nombreArchivo);
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/por-pagar?listado_id=' . (int) $this->get('listado_id', 0)),
                'Error al exportar a Excel: ' . $e->getMessage(),
                'error'
            );
        }
    }

    // ── Auxiliares ─────────────────────────────────────────────────

    private function ejecutarMatching($listadoId, $erp)
    {
        require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
        return PorPagarVerificador::verificarListado($listadoId, $erp, $this->loadModel('Factura'));
    }

    /**
     * Aprende la equivalencia de nombre que revela un emparejamiento manual.
     *
     * El caso: el ERP dice "COOPEAGRI" y el XML dice "COOPERATIVA AGRICOLA
     * INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL". No comparten ni una
     * palabra, así que ninguna comparación por parecido los va a juntar. La
     * única fuente válida es la persona que emparejó a mano.
     *
     * Al pago semanal ya casi no le hace falta —cruza por consecutivo—, pero a
     * las notas de crédito sí, y el alias vale para las dos.
     */
    private function aprenderAliasProveedor(array $lineaErp, array $xml)
    {
        $textoErp = trim((string) ($lineaErp['proveedor_nombre'] ?? ''));
        $proveedorId = (int) ($xml['proveedor_id'] ?? 0);
        if ($textoErp === '' || $proveedorId <= 0) {
            return '';
        }

        $nombreXml = '';
        try {
            $proveedor = $this->loadModel('Proveedor')->findById($proveedorId);
            $nombreXml = (string) ($proveedor['razon_social'] ?? '');
        } catch (Throwable $e) {
        }

        if ($nombreXml !== ''
            && FacturaMatcher::similaridadTexto($textoErp, $nombreXml) >= FacturaMatcher::UMBRAL_PROVEEDOR) {
            return ''; // se parecían; no hay nada que enseñar
        }

        try {
            $aprendido = $this->loadModel('ProveedorAlias')->aprender(
                $proveedorId,
                $textoErp,
                (int) ($_SESSION['user_id'] ?? 0)
            );
            return $aprendido ? $textoErp : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Aprende la equivalencia código del ERP ↔ cédula que revela un vínculo
     * manual.
     *
     * Es la llave firme, y por eso vale más que el alias de nombre: un código
     * atado a una cédula deja de depender de cómo esté escrito el proveedor.
     *
     * Ahora bien, esto NO es lo mismo que una persona declarando de quién es un
     * código. Quien vincula está resolviendo UNA factura, y de ahí no se sigue
     * que quiera reescribir un mapa respaldado por decenas de emparejamientos.
     * Así que solo se guarda cuando no hay nada que contradecir —el mapa no
     * conocía el código, o estaba en disputa, o ya decía lo mismo—. Si
     * contradice algo firme, se anota como conflicto y sube a la campana, que
     * es donde sí existe el botón para declararlo a propósito.
     *
     * @return bool Si el mapa quedó escrito.
     */
    private function aprenderCodigoProveedor(array $lineaErp, array $xml)
    {
        $codigo = trim((string) ($lineaErp['proveedor_codigo'] ?? ''));
        $proveedorId = (int) ($xml['proveedor_id'] ?? 0);
        if ($codigo === '' || $proveedorId <= 0) {
            return false;
        }

        try {
            require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
            $mapa = $this->loadModel('ProveedorCodigoErp');

            if (ProveedorCodigoErp::veredicto($codigo, $proveedorId) === 'ajeno') {
                $mapa->registrarVeto([
                    'codigo'                 => $codigo,
                    'proveedor_id_propuesto' => $proveedorId,
                    'factura_erp_id'         => (int) ($lineaErp['id'] ?? 0),
                    'factura_xml_id'         => (int) ($xml['id'] ?? 0),
                    // Que una persona lo haya vinculado a mano es señal fuerte:
                    // pide revisión aunque los montos no cuadren.
                    'monto_cuadraba'         => true,
                ]);
                return false;
            }

            return $mapa->confirmarManual($codigo, $proveedorId, (int) ($_SESSION['user_id'] ?? 0));
        } catch (Throwable $e) {
            return false; // el vínculo ya quedó guardado; esto es un extra
        }
    }

    private function reprocesarNotasCredito($aliasTexto, $limite = 3)
    {
        $resueltas = 0;
        try {
            require_once __DIR__ . '/../models/ProveedorAlias.php';
            $buscado = ProveedorAlias::normalizar($aliasTexto);
            if ($buscado === '') {
                return 0;
            }

            $notas = $this->loadModel('NotaCredito');
            $listados = [];
            foreach ($notas->proveedoresSinRespaldo() as $fila) {
                if (ProveedorAlias::normalizar((string) $fila['proveedor_nombre']) === $buscado) {
                    $listados[(int) $fila['listado_id']] = true;
                }
            }
            if (!$listados) {
                return 0;
            }

            require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
            $ids = array_slice(array_keys($listados), 0, max(1, (int) $limite));
            foreach ($ids as $id) {
                $antes = (int) ($notas->resumen($id)['sin_respaldo'] ?? 0);
                $stats = NotasCreditoVerificador::verificarListado($id, $notas, 'alias_nuevo');
                $resueltas += max(0, $antes - (int) ($stats['sin_respaldo'] ?? $antes));
            }
        } catch (Throwable $e) {
            // El vínculo manual ya quedó guardado; esto es un extra.
        }

        return $resueltas;
    }

    private function numeroBusqueda($numero)
    {
        return FacturaMatcher::terminoBusquedaCorreo($numero);
    }

    // ── Lectura del archivo (mismo patrón del importador de gastos) ──
    //
    // Las dos claves de deduplicación que vivían acá —número+proveedor y
    // número+monto— desaparecieron con la copia de los datos. Una factura ya no
    // puede entrar dos veces al pago porque entrar es marcar una fila del ERP,
    // y una fila o está marcada o no lo está.

    /**
     * Reporte agrupado del sistema de la empresa (sin fila de encabezados):
     * "Proveedor <código> <nombre>" abre un grupo; debajo vienen filas
     * "FACT-… <emisión> <vencimiento> ₡ <monto>" y cierra un subtotal sin
     * número de documento. Devuelve filas planas fecha/numero/proveedor/
     * total listas para el importador, o [] si el archivo no tiene esa pinta.
     */
    private function extraerReporteAgrupado($dataset)
    {
        $registros = [];
        $proveedor = '';

        // El reporte no trae encabezados: la primera fila es una fila más
        $filas = array_merge([$dataset['header']], $dataset['rows']);

        foreach ($filas as $fila) {
            $celdas = [];
            foreach ((array) $fila as $celda) {
                $celda = trim((string) $celda);
                if ($celda !== '') {
                    $celdas[] = $celda;
                }
            }
            if (empty($celdas)) {
                continue;
            }

            // Encabezado de grupo: "Proveedor 140000014 AGENCIAS JOP S.A."
            $nombre = $this->nombreDeGrupo($celdas);
            if ($nombre !== null) {
                $proveedor = $nombre;
                continue;
            }

            // Fila de documento: "FACT-12339 …". Los rótulos ("Documento"),
            // los subtotales (solo ₡ y monto) y los títulos del reporte no
            // cumplen el patrón y se saltan solos.
            if ($proveedor === '' || !preg_match('/^[A-ZÁÉÍÓÚÑ]{2,10}-[\w-]+$/iu', $celdas[0])) {
                continue;
            }

            // Monto: la última celda numérica (el ₡ puede venir aparte)
            $monto = '';
            $idxMonto = -1;
            for ($i = count($celdas) - 1; $i >= 1; $i--) {
                $limpio = trim(str_replace(['₡', '¢'], '', $celdas[$i]));
                if ($limpio !== '' && preg_match('/^-?[\d.,]+$/', $limpio)) {
                    $monto = $limpio;
                    $idxMonto = $i;
                    break;
                }
            }
            if ($monto === '') {
                continue;
            }

            // Fecha de emisión: la primera fecha antes del monto, ya sea
            // dd/mm/aaaa (CSV/texto) o serial de Excel (~46000 = año 2026).
            // La segunda fecha (vencimiento) se ignora: es informativa.
            $fecha = '';
            for ($i = 1; $i < $idxMonto && $fecha === ''; $i++) {
                $c = $celdas[$i];
                if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $c)) {
                    $fecha = $c;
                } elseif (preg_match('/^\d{5}$/', $c)) {
                    $serial = (int) $c;
                    if ($serial >= 40000 && $serial <= 60000) { // 2009–2064
                        $fecha = date('d/m/Y', ($serial - 25569) * 86400);
                    }
                }
            }

            $registros[] = [
                'fecha' => $fecha,
                'numero' => $celdas[0],
                'proveedor' => $proveedor,
                'total' => $monto,
            ];
        }

        return $registros;
    }

    /**
     * Nombre del proveedor si estas celdas abren un grupo, o null si no.
     *
     * Se busca el rótulo ENTRE las celdas en vez de exigir que la fila
     * empiece con él, y el nombre se toma de su celda en vez de recortarlo
     * de la fila pegada. La razón es que quien prepara el pago escribe sobre
     * el reporte: marca proveedores con una "x" en la primera columna y deja
     * recordatorios al final ("CAMBIAR TXT"). Con la fila pegada, esa "x"
     * hacía que el encabezado no se reconociera, y como el proveedor solo
     * cambia al ver uno, TODAS las facturas del grupo quedaban a nombre del
     * proveedor anterior —sin error y sin fila perdida, solo mal atribuidas—.
     * En el archivo de la semana 130826 le pasó a dos grupos de 140.
     *
     * El código y el nombre pueden venir juntos o en celdas separadas, según
     * de dónde se exportó.
     */
    private function nombreDeGrupo(array $celdas)
    {
        foreach ($celdas as $i => $celda) {
            // El rótulo, con el código y el nombre opcionalmente pegados.
            if (!preg_match('/^Proveedor\s*:?(?:\s+(\d{4,})(?:\s+(.*))?)?$/iu', $celda, $m)) {
                continue;
            }

            $resto = array_values(array_slice($celdas, $i + 1));
            if (isset($m[2]) && trim($m[2]) !== '') {
                return trim($m[2]);
            }

            // El código no venía con el rótulo: tiene que estar en la celda
            // siguiente. Si no lo está, esto era un rótulo de columna suelto
            // ("Proveedor" sobre una tabla) y no abre ningún grupo.
            if (!isset($m[1]) || $m[1] === '') {
                if (empty($resto)) {
                    return null;
                }
                if (preg_match('/^\d{4,}\s+(.+)$/u', $resto[0], $c)) {
                    return trim($c[1]);
                }
                if (!preg_match('/^\d{4,}$/', $resto[0])) {
                    return null;
                }
                array_shift($resto);
            }

            return !empty($resto) && trim($resto[0]) !== '' ? trim($resto[0]) : null;
        }

        return null;
    }

    /**
     * Pasa a UTF-8 lo que el ERP escribió en Windows-1252.
     *
     * No es cosmético. El reporte se lee con expresiones regulares que llevan
     * el modificador /u, y ante bytes UTF-8 inválidos preg_match no devuelve
     * "no coincide": devuelve FALSE. El encabezado de grupo "Proveedor
     * 140000076 COMPAÑIA AMERICANA DE HELADOS S.A." dejaba de reconocerse por
     * la Ñ, y como el nombre del proveedor solo cambia al ver un encabezado,
     * TODAS las facturas de ese grupo quedaban a nombre del proveedor
     * anterior. Silencioso: ni error ni fila perdida, solo mal atribuida.
     *
     * Se sanea aquí, en el embudo por el que pasan los dos lectores, y no
     * dentro de cada regex: el mismo texto termina guardado como
     * proveedor_texto y comparado contra el ERP.
     */
    private function normalizarDataset($dataset)
    {
        $dataset['header'] = array_map([$this, 'aUtf8'], (array) ($dataset['header'] ?? []));
        foreach (($dataset['rows'] ?? []) as $i => $fila) {
            $dataset['rows'][$i] = array_map([$this, 'aUtf8'], (array) $fila);
        }
        return $dataset;
    }

    /** Los valores que no son texto (números, fechas de Excel) se dejan tal cual. */
    private function aUtf8($valor)
    {
        if (!is_string($valor) || $valor === '' || mb_check_encoding($valor, 'UTF-8')) {
            return $valor;
        }
        return mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
    }

    private function readCsvData($filePath)
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('No fue posible abrir el archivo CSV.');
        }

        $delimiter = $this->detectCsvDelimiter($filePath);
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            throw new Exception('El archivo CSV no contiene encabezados.');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }

    private function detectCsvDelimiter($filePath)
    {
        $line = '';
        $fh = fopen($filePath, 'r');
        if ($fh !== false) {
            $line = (string) fgets($fh);
            fclose($fh);
        }

        $commaCount = substr_count($line, ',');
        $semicolonCount = substr_count($line, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    private function buildHeaderMap(array $header)
    {
        $map = [];
        foreach ($header as $index => $name) {
            $map[$this->normalizeHeaderKey((string) $name)] = $index;
        }
        return $map;
    }

    private function getValue(array $row, array $map, array $keys)
    {
        foreach ($keys as $key) {
            $normalized = $this->normalizeHeaderKey($key);
            if (isset($map[$normalized])) {
                return $row[$map[$normalized]] ?? null;
            }
        }
        return null;
    }

    /**
     * Las tres columnas que el pago necesita: documento, proveedor y saldo.
     *
     * La fecha dejó de pedirse. Se pedía porque se guardaba, y guardarla era el
     * problema: la fecha de la factura la tiene el ERP y no hay razón para
     * aceptar una segunda versión que puede no coincidir. Cualquier otra
     * columna que traiga el archivo se ignora sin quejarse.
     */
    private function validateRequiredColumns(array $map)
    {
        $requeridas = [
            'documento' => ['numero', 'documento'],
            'proveedor' => ['proveedor'],
            'saldo'     => ['saldo', 'total'],
        ];
        $faltan = [];

        foreach ($requeridas as $etiqueta => $alternativas) {
            $encontrada = false;
            foreach ($alternativas as $columna) {
                if (isset($map[$this->normalizeHeaderKey($columna)])) {
                    $encontrada = true;
                    break;
                }
            }
            if (!$encontrada) {
                $faltan[] = $etiqueta;
            }
        }

        if ($faltan) {
            throw new Exception(
                'El archivo debe incluir las columnas Numero (o Documento), Proveedor y Saldo (o Total). Faltan: '
                . implode(', ', $faltan)
            );
        }
    }

    private function normalizeHeaderKey($value)
    {
        $key = trim((string) $value);
        $key = preg_replace('/^\xEF\xBB\xBF/', '', $key); // remover BOM UTF-8
        $key = mb_strtolower($key, 'UTF-8');
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;
        $key = str_replace([' ', '-', '.'], '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        $key = preg_replace('/_+/', '_', $key);

        return trim($key, '_');
    }
}
