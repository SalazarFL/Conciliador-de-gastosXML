<?php
/**
 * Controlador de "Facturas por pagar".
 *
 * Cada semana se sube el listado del pago (Fecha, Numero, Proveedor, Total)
 * y se verifica contra las facturas XML del sistema:
 *   - respaldada: hay XML y el monto cuadra (±1 colón)
 *   - con_diferencia: hay XML pero el monto no cuadra
 *   - sin_respaldo: no se encontró el XML (botón "Buscar en correo")
 * La fecha del listado es informativa; no se compara.
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';

class PorPagarController extends Controller
{
    // Umbrales del matching: compartidos en FacturaMatcher (también los usa
    // la advertencia al asignar semana desde el módulo de Facturas).

    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $modelo = $this->loadModel('PorPagar');
        $filtros = $this->filtrosListado();

        // El selector "Semana de trabajo" define lo que se muestra: semana_id=N
        // = el listado de esa semana (se carga automáticamente); 0 = "Sin semana".
        // Sin parámetro se usa la última semana elegida (compartida entre módulos
        // vía sesión); al llegar en la URL se recuerda para los demás.
        $semanaFiltro = $this->semanaActiva();
        if (isset($_GET['semana_id']) && $_GET['semana_id'] !== '') {
            $semanaFiltro = max(0, (int) $_GET['semana_id']);
            $this->setSemanaActiva($semanaFiltro);
        }

        $listados = $modelo->getListados(60, $semanaFiltro);

        // El listado activo debe pertenecer al filtro; si no, se toma el más
        // reciente de la semana elegida
        $listadoId = (int) $this->get('listado_id', 0);
        $idsValidos = array_map(function ($l) { return (int) $l['id']; }, $listados);
        if ($listadoId <= 0 || !in_array($listadoId, $idsValidos, true)) {
            $listadoId = !empty($listados) ? (int) $listados[0]['id'] : 0;
        }

        $listado = $listadoId > 0 ? $modelo->getListado($listadoId) : null;
        $lineas = $listado ? $modelo->getLineas($listadoId, $filtros) : [];
        $resumen = $listado ? $modelo->resumenPorEstado($listadoId) : [];

        // Término de búsqueda para el botón "Buscar en correo" de cada línea
        foreach ($lineas as &$linea) {
            $linea['numero_busqueda'] = $this->numeroBusqueda((string) $linea['numero']);
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

        // Facturas de la semana que no respaldan ninguna línea del listado
        // (alimenta el botón "Sin coincidencia" del checklist)
        $sinCoincidencia = 0;
        if ($listado && !empty($listado['semana_id'])) {
            try {
                $sinCoincidencia = count($modelo->getFacturasSinCoincidencia(
                    (int) $listado['semana_id'],
                    (int) $listado['id']
                ));
            } catch (Throwable $e) {
            }
        }

        $this->render('porpagar/index', [
            'title'           => 'Pagos semanales - XMLConcilia',
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
        ]);
    }

    /** Normaliza los buscadores del checklist de facturas por pagar. */
    private function filtrosListado()
    {
        $q = mb_substr(trim((string) $this->get('q', '')), 0, 150, 'UTF-8');
        $proveedor = mb_substr(trim((string) $this->get('proveedor', '')), 0, 120, 'UTF-8');

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

    /**
     * Sube el listado semanal (CSV/XLSX), crea el listado con sus líneas y
     * ejecuta la verificación contra las facturas XML.
     */
    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        require_once __DIR__ . '/../helpers/Validator.php';
        require_once __DIR__ . '/../helpers/XlsxReader.php';

        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'porpagar';
        $maxSize = $config['max_upload_size'] ?? 10485760;

        try {
            // Archivo recién elegido, o el que la vista previa dejó guardado
            // (archivo_token) cuando el usuario confirma la importación
            $token = basename(trim((string) $this->post('archivo_token', '')));
            if ($token !== '') {
                $ruta = $uploadDir . DIRECTORY_SEPARATOR . $token;
                if (!is_file($ruta)) {
                    throw new Exception('La vista previa expiró. Vuelve a elegir el archivo.');
                }
                $file = [
                    'path' => $ruta,
                    'original_name' => trim((string) $this->post('archivo_nombre', '')) ?: $token,
                ];
            } else {
                $file = FileUploader::uploadSingle('listado_file', $uploadDir, ['csv', 'xlsx', 'xls'], $maxSize);
            }

            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
            }
            if ($ext === 'xls') {
                throw new Exception('El formato .xls no está soportado. Guarda el archivo como .xlsx o .csv.');
            }

            $modelo = $this->loadModel('PorPagar');

            $sociedadId = null;
            try {
                $activa = $this->loadModel('Sociedad')->getActiva();
                $sociedadId = $activa ? (int) $activa['id'] : null;
            } catch (Throwable $e) {
            }

            // Semana contra la que se verificará este listado
            $semanaId = null;
            $semanaModel = null;
            try {
                $semanaModel = $this->loadModel('Semana');
                $semanaId = $semanaModel->resolverSeleccion(
                    (string) $this->post('semana_id', ''),
                    (string) $this->post('semana_nueva', '')
                );
            } catch (Throwable $e) {
            }

            $semana = null;
            if (!empty($semanaId) && $semanaModel !== null) {
                $semana = $semanaModel->findById((int) $semanaId);
                $carpetaSolicitada = trim((string) $this->post('carpeta_pago', ''));
                if ($carpetaSolicitada === '') {
                    $carpetaSolicitada = (string) ($semana['carpeta_pago'] ?? ($semana['nombre'] ?? ''));
                }
                $carpetaPago = DocumentoArchivo::normalizarCarpetaPago($carpetaSolicitada);
                if ($carpetaPago === '') {
                    throw new Exception('Selecciona una carpeta válida para el pago semanal.');
                }
                $semanaModel->configurarCarpetaPago((int) $semanaId, $carpetaPago);
                (new DocumentoArchivo())->prepararCarpetaPagoSemanal($carpetaPago);
                $semana['carpeta_pago'] = $carpetaPago;
            }

            // El nombre del listado se genera solo (organizado por semana).
            $nombre = 'Pago ' . date('d/m/Y H:i');
            if (!empty($semanaId)) {
                try {
                    if (!empty($semana['nombre'])) {
                        $nombre = 'Pago ' . $semana['nombre'];
                    }
                } catch (Throwable $e) {
                }
            }

            // Analiza y clasifica el archivo (nueva / repetida / error)
            // contra el listado existente de la semana; la vista previa
            // usa exactamente el mismo método.
            $analisis = $this->analizarListado($file['path'], $ext, $semanaId, $modelo);
            $listadoExistente = $analisis['listado_existente'];

            if ($listadoExistente !== null) {
                if (($listadoExistente['estado'] ?? 'abierto') === 'cerrado') {
                    throw new Exception('El pago de esta semana ya está cerrado. Crea otra semana para cargar más facturas.');
                }
                $listadoId = (int) $listadoExistente['id'];
                $nombre = (string) $listadoExistente['nombre'];
            } else {
                $listadoId = (int) $modelo->crearListado($nombre, $sociedadId, $file['original_name'], $semanaId);
            }

            $exitosos = 0;
            $fallidos = 0;
            $omitidas = 0;

            $nuevas = [];
            foreach ($analisis['lineas'] as $lineaArchivo) {
                if ($lineaArchivo['estado'] === 'error') {
                    $fallidos++;
                    continue;
                }
                if ($lineaArchivo['estado'] === 'repetida') {
                    $omitidas++;
                    continue;
                }

                $nuevas[] = [
                    'fecha' => $lineaArchivo['fecha'] ?: null,   // informativa
                    'numero' => $lineaArchivo['numero'],
                    'proveedor' => $lineaArchivo['proveedor'],
                    'total' => $lineaArchivo['total'],
                ];
            }
            $exitosos = $modelo->crearLineasLote($listadoId, $nuevas);

            if (is_file($file['path'])) {
                @unlink($file['path']);
            }

            if ($exitosos === 0) {
                if ($listadoExistente === null) {
                    // Listado recién creado y vacío: se borra y se avisa
                    $modelo->eliminarListado($listadoId);
                    $this->redirectWithMessage($this->url('/por-pagar'),'No se pudo leer ninguna línea del listado. Verifica las columnas Fecha, Numero, Proveedor y Total.', 'error');
                }
                // Aunque no haya líneas nuevas, reaplica la carpeta elegida
                // a los respaldos existentes de la semana.
                $statsSinCambios = $this->ejecutarMatching($listadoId, $modelo);
                // Modo añadir: el archivo no trae nada nuevo.
                $mensajeSinCambios = $omitidas > 0
                    ? "Sin líneas nuevas: las {$omitidas} facturas ya estaban en \"{$nombre}\"."
                    : 'No se pudo leer ninguna línea del listado. Verifica las columnas Fecha, Numero, Proveedor y Total.';
                $this->redirectWithMessage(
                    $this->url('/por-pagar?listado_id=' . $listadoId . '&semana_id=' . (int) $semanaId),
                    $mensajeSinCambios,
                    $omitidas > 0 ? 'warning' : 'error'
                );
            }

            $modelo->actualizarTotalLineas($listadoId);
            $stats = $this->ejecutarMatching($listadoId, $modelo);

            $msg = ($listadoExistente !== null
                    ? "Listado \"{$nombre}\": +{$exitosos} facturas nuevas añadidas"
                    : "Listado \"{$nombre}\": {$exitosos} facturas")
                . ($omitidas > 0 ? " ({$omitidas} ya estaban, omitidas)" : '')
                . ($fallidos > 0 ? " ({$fallidos} filas descartadas)" : '')
                . " — {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.";

            // Volver al contexto de la semana del listado recién subido
            $this->redirectWithMessage(
                $this->url('/por-pagar?listado_id=' . $listadoId . (!empty($semanaId) ? '&semana_id=' . (int) $semanaId : '')),
                $msg,
                $stats['sin_respaldo'] + $stats['con_diferencia'] > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'),'Error al subir el listado: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Vista previa de la importación (POST, JSON): sube el archivo, lo
     * analiza SIN importar nada y devuelve la clasificación línea por
     * línea (nueva / ya estaba / error). El archivo queda guardado y
     * subir() lo consume después vía 'archivo_token' al confirmar.
     */
    public function previsualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        require_once __DIR__ . '/../helpers/Validator.php';
        require_once __DIR__ . '/../helpers/XlsxReader.php';

        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'porpagar';
        $maxSize = $config['max_upload_size'] ?? 10485760;

        try {
            // Limpieza oportunista de vistas previas abandonadas (> 6 horas)
            foreach (glob($uploadDir . DIRECTORY_SEPARATOR . '*') ?: [] as $viejo) {
                if (is_file($viejo) && filemtime($viejo) < time() - 21600) {
                    @unlink($viejo);
                }
            }

            $file = FileUploader::uploadSingle('listado_file', $uploadDir, ['csv', 'xlsx', 'xls'], $maxSize);

            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
            if ($ext === 'xls') {
                @unlink($file['path']);
                $this->json(['ok' => false, 'message' => 'El formato .xls no está soportado. Guarda el archivo como .xlsx o .csv.'], 422);
            }

            // Una semana recién escrita ("Nueva semana…") aún no existe en
            // BD, así que no puede tener listado previo: se analiza sin él.
            $semanaSel = trim((string) $this->post('semana_id', ''));
            $semanaId = ctype_digit($semanaSel) ? (int) $semanaSel : 0;

            $modelo = $this->loadModel('PorPagar');
            $analisis = $this->analizarListado($file['path'], $ext, $semanaId, $modelo);

            $nuevas = 0;
            $repetidas = 0;
            $errores = 0;
            $montoNuevas = 0.0;
            foreach ($analisis['lineas'] as $l) {
                if ($l['estado'] === 'nueva') {
                    $nuevas++;
                    $montoNuevas += (float) $l['total'];
                } elseif ($l['estado'] === 'repetida') {
                    $repetidas++;
                } else {
                    $errores++;
                }
            }

            $this->json([
                'ok' => true,
                'token' => basename($file['path']),
                'archivo' => $file['original_name'],
                'carpeta_pago' => DocumentoArchivo::normalizarCarpetaPago((string) $this->post('carpeta_pago', '')),
                'listado_existente' => $analisis['listado_existente'] !== null
                    ? (string) $analisis['listado_existente']['nombre'] : null,
                'nuevas' => $nuevas,
                'repetidas' => $repetidas,
                'errores' => $errores,
                'monto_nuevas' => round($montoNuevas, 2),
                'lineas' => array_slice($analisis['lineas'], 0, 1000),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Comparador independiente (POST, JSON). Lee un CSV/XLSX y contrasta
     * todas sus facturas con el listado actual de la semana seleccionada.
     * Es de solo lectura: el archivo temporal se elimina y la base de datos
     * no recibe INSERT, UPDATE ni DELETE.
     */
    public function compararListado()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        require_once __DIR__ . '/../helpers/Validator.php';
        require_once __DIR__ . '/../helpers/XlsxReader.php';
        require_once __DIR__ . '/../helpers/PorPagarComparador.php';

        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'porpagar';
        $maxSize = $config['max_upload_size'] ?? 10485760;
        $rutaTemporal = '';

        try {
            $semanaSel = trim((string) $this->post('semana_id', ''));
            if (!ctype_digit($semanaSel) || (int) $semanaSel <= 0) {
                throw new Exception('Selecciona una semana existente para hacer la comparacion.');
            }
            $semanaId = (int) $semanaSel;
            $semana = $this->loadModel('Semana')->findById($semanaId);
            if ($semana === null) {
                throw new Exception('La semana seleccionada ya no existe.');
            }
            if (isset($_FILES['listado_file']['name']) && is_array($_FILES['listado_file']['name'])) {
                throw new Exception('Selecciona un solo archivo para comparar.');
            }

            $file = FileUploader::uploadSingle('listado_file', $uploadDir, ['csv', 'xlsx', 'xls'], $maxSize);
            $rutaTemporal = (string) $file['path'];
            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
            if ($ext === 'xls') {
                throw new Exception('El formato .xls no esta soportado. Guarda el archivo como .xlsx o .csv.');
            }
            if (!in_array($ext, ['csv', 'xlsx'], true)) {
                throw new Exception('Selecciona un archivo CSV o XLSX.');
            }

            $modelo = $this->loadModel('PorPagar');
            $analisisLectura = $this->analizarListado($rutaTemporal, $ext, $semanaId, $modelo);
            $listado = $analisisLectura['listado_existente'];
            $actuales = $listado !== null
                ? $modelo->getLineas((int) $listado['id'])
                : [];
            $comparacion = PorPagarComparador::comparar($actuales, $analisisLectura['lineas']);

            if (is_file($rutaTemporal)) {
                @unlink($rutaTemporal);
            }

            $this->json([
                'ok' => true,
                'archivo' => (string) $file['original_name'],
                'semana' => (string) ($semana['nombre'] ?? ('Semana #' . $semanaId)),
                'listado_existente' => $listado !== null ? (string) $listado['nombre'] : null,
                'resumen' => $comparacion['resumen'],
                'lineas' => $comparacion['lineas'],
                'total_resultados' => count($comparacion['lineas']),
                'solo_lectura' => true,
            ]);
        } catch (Throwable $e) {
            if ($rutaTemporal !== '' && is_file($rutaTemporal)) {
                @unlink($rutaTemporal);
            }
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Lee un archivo de listado y clasifica cada línea contra el listado
     * existente de la semana (si lo hay): 'nueva' (se importaría),
     * 'repetida' (ya está en el listado o viene doble en el archivo) o
     * 'error' (fila ilegible, con su motivo). NO escribe nada: lo usan
     * tanto la vista previa como la importación real.
     */
    private function analizarListado($rutaArchivo, $ext, $semanaId, $modelo)
    {
        $dataset = $ext === 'csv'
            ? $this->readCsvData($rutaArchivo)
            : XlsxReader::readFirstSheet($rutaArchivo);

        // Dos formatos aceptados: el plano con columnas Fecha/Numero/
        // Proveedor/Total, y el reporte agrupado que exporta el sistema
        // de la empresa ("Proveedor <código> <nombre>" + filas FACT-…),
        // que se aplana automáticamente.
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
                    'fecha' => $this->getValue($row, $map, ['fecha']),
                    'numero' => $this->getValue($row, $map, ['numero']),
                    'proveedor' => $this->getValue($row, $map, ['proveedor']),
                    'total' => $this->getValue($row, $map, ['total']),
                ];
            }
        }

        // ¿La semana ya tiene listado? → modo "solo añadir nuevas": lo
        // repetido (mismo número y proveedor) se omite al importar.
        $listadoExistente = null;
        if (!empty($semanaId)) {
            $previos = $modelo->getListados(1, (int) $semanaId);
            $listadoExistente = !empty($previos) ? $previos[0] : null;
        }

        $clavesVistas = [];
        $clavesNumeroTotal = [];
        if ($listadoExistente !== null) {
            foreach ($modelo->getLineas((int) $listadoExistente['id']) as $lineaPrevia) {
                $clavesVistas[$this->claveLinea((string) $lineaPrevia['numero'], (string) $lineaPrevia['proveedor_texto'])] = true;
                $clavesNumeroTotal[$this->claveNumeroTotal((string) $lineaPrevia['numero'], $lineaPrevia['total'])] = true;
            }
        }

        $lineas = [];
        foreach ($registros as $row) {
            try {
                $fecha = Validator::parseDate($row['fecha']);
                $numero = trim((string) $row['numero']);
                $proveedor = trim((string) $row['proveedor']);
                $monto = Validator::parseAmount($row['total']);

                // Números que Excel entrega como 26546.0
                if (preg_match('/^\d+\.0$/', $numero)) {
                    $numero = substr($numero, 0, -2);
                }

                if ($numero === '' && $proveedor === '' && $monto <= 0) {
                    continue; // fila totalmente vacía: ni contarla
                }
                if ($numero === '') {
                    throw new Exception('Número vacío.');
                }
                if ($proveedor === '') {
                    throw new Exception('Proveedor vacío.');
                }
                if ($monto <= 0) {
                    throw new Exception('Total inválido.');
                }

                // Repetida por número+proveedor, o por número+monto exacto:
                // esta segunda atrapa la misma factura cuando el proveedor
                // viene escrito distinto entre archivos (recortado vs completo)
                $clave = $this->claveLinea($numero, $proveedor);
                $claveNT = $this->claveNumeroTotal($numero, $monto);
                $estado = (isset($clavesVistas[$clave]) || isset($clavesNumeroTotal[$claveNT])) ? 'repetida' : 'nueva';
                $clavesVistas[$clave] = true;
                $clavesNumeroTotal[$claveNT] = true;

                $lineas[] = [
                    'estado' => $estado,
                    'motivo' => '',
                    'fecha' => $fecha ?: null,   // informativa: inválida no bloquea
                    'numero' => $numero,
                    'proveedor' => $proveedor,
                    'total' => $monto,
                ];
            } catch (Throwable $e) {
                $lineas[] = [
                    'estado' => 'error',
                    'motivo' => $e->getMessage(),
                    'fecha' => null,
                    'numero' => trim((string) ($row['numero'] ?? '')),
                    'proveedor' => trim((string) ($row['proveedor'] ?? '')),
                    'total' => 0.0,
                ];
            }
        }

        return ['lineas' => $lineas, 'listado_existente' => $listadoExistente];
    }

    /**
     * Re-verifica un listado contra las facturas actuales (después de
     * importar más facturas desde el correo).
     */
    public function verificar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $modelo = $this->loadModel('PorPagar');
            $registro = $modelo->getListado((int) $id);
            if ($registro === null) {
                $this->redirectWithMessage($this->url('/por-pagar'), 'El listado no existe.', 'error');
            }
            if (($registro['estado'] ?? 'abierto') === 'cerrado') {
                $this->redirectWithMessage(
                    $this->url('/por-pagar?listado_id=' . (int) $id . '&semana_id=' . (int) ($registro['semana_id'] ?? 0)),
                    'El pago semanal ya está cerrado; sus coincidencias quedaron congeladas.',
                    'warning'
                );
            }

            $stats = $this->ejecutarMatching((int) $id, $modelo);

            // Volver al contexto de la semana del listado verificado
            $qsSemana = !empty($registro['semana_id']) ? '&semana_id=' . (int) $registro['semana_id'] : '';
            $this->redirectWithMessage(
                $this->url('/por-pagar?listado_id=' . (int) $id . $qsSemana),
                "Verificación actualizada: {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.",
                $stats['sin_respaldo'] + $stats['con_diferencia'] > 0 ? 'warning' : 'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'),'No se pudo verificar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Cierra el pago y refleja sus facturas emparejadas en Facturas ERP con
     * el estado "Asignada a una semana".
     */
    public function cerrar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        $modelo = $this->loadModel('PorPagar');
        $listado = $modelo->getListado((int) $id);
        if ($listado === null) {
            $this->redirectWithMessage($this->url('/por-pagar'), 'El pago semanal no existe.', 'error');
        }

        $destino = $this->url(
            '/por-pagar?listado_id=' . (int) $id . '&semana_id=' . (int) ($listado['semana_id'] ?? 0)
        );

        try {
            if (($listado['estado'] ?? 'abierto') !== 'cerrado') {
                // Toma la foto final de coincidencias justo antes del cierre.
                $this->ejecutarMatching((int) $id, $modelo);
            }
            $resultado = $this->loadModel('FacturaErp')->cerrarPagoSemanal(
                (int) $id,
                $_SESSION['usuario_id'] ?? null
            );

            if (!empty($resultado['ya_cerrado'])) {
                $mensaje = 'El pago semanal ya estaba cerrado.';
                $tipo = 'warning';
            } else {
                $mensaje = sprintf(
                    'Pago semanal cerrado: %s factura(s) quedaron como "Asignada a una semana" en Facturas ERP.',
                    number_format((int) $resultado['asignadas'], 0, ',', '.')
                );
                $tipo = 'success';
            }
            $this->redirectWithMessage($destino, $mensaje, $tipo);
        } catch (Throwable $e) {
            $this->redirectWithMessage($destino, 'No se pudo cerrar el pago semanal: ' . $e->getMessage(), 'error');
        }
    }

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $this->loadModel('PorPagar')->eliminarListado((int) $id);
            $this->redirectWithMessage($this->url('/por-pagar'),'Listado eliminado.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'),'No se pudo eliminar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Elimina una sola factura del listado. El comprobante XML que pudiera
     * estar vinculado queda intacto y vuelve a estar disponible para matching.
     */
    public function eliminarFactura($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $modelo = $this->loadModel('PorPagar');
            $linea = $modelo->eliminarLinea((int) $id);
            if ($linea === null) {
                $this->redirectWithMessage(
                    $this->url('/por-pagar'),
                    'La factura del listado no existe o ya fue eliminada.',
                    'error'
                );
            }

            $listadoId = (int) $linea['listado_id'];
            $listado = $modelo->getListado($listadoId);
            $destino = '/por-pagar?listado_id=' . $listadoId;
            if ($listado !== null) {
                $destino .= '&semana_id=' . (int) ($listado['semana_id'] ?? 0);
            }
            $filtrosRetorno = array_filter($this->filtrosListado(), function ($valor) {
                return $valor !== '' && $valor !== null;
            });
            if ($filtrosRetorno) {
                $destino .= '&' . http_build_query($filtrosRetorno);
            }

            $numero = trim((string) ($linea['numero'] ?? ''));
            $mensaje = $numero !== ''
                ? 'La factura ' . $numero . ' fue eliminada del listado. El XML asociado no fue eliminado.'
                : 'La factura fue eliminada del listado. El XML asociado no fue eliminado.';

            $this->redirectWithMessage($this->url($destino), $mensaje, 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/por-pagar'),
                'No se pudo eliminar la factura del listado: ' . $e->getMessage(),
                'error'
            );
        }
    }

    /**
     * JSON del botón "Sin coincidencia": facturas XML de la semana que no
     * respaldan ninguna línea del listado, las líneas aún sin respaldo
     * (para vincular a mano) y las semanas (para mover la factura).
     */
    public function sinCoincidencia()
    {
        try {
            $modelo = $this->loadModel('PorPagar');
            $listado = $modelo->getListado((int) $this->get('listado_id', 0));
            if ($listado === null || empty($listado['semana_id'])) {
                $this->json(['ok' => false, 'message' => 'El listado no existe o no tiene semana asignada.'], 404);
            }

            $facturas = array_map(function ($f) {
                return [
                    'id'        => (int) $f['id'],
                    'numero'    => NumeroFactura::xmlOchoDigitos($f['numero_factura_asistente']),
                    'proveedor' => (string) ($f['proveedor_nombre'] ?? ''),
                    'total'     => (float) $f['total'],
                    'fecha'     => (string) ($f['fecha_emision'] ?? ''),
                ];
            }, $modelo->getFacturasSinCoincidencia((int) $listado['semana_id'], (int) $listado['id']));

            $lineas = array_map(function ($l) {
                return [
                    'id'        => (int) $l['id'],
                    'numero'    => (string) $l['numero'],
                    'proveedor' => (string) $l['proveedor_texto'],
                    'total'     => (float) $l['total'],
                ];
            }, $modelo->getLineasSinRespaldo((int) $listado['id']));

            $semanas = [];
            try {
                foreach ($this->loadModel('Semana')->getAll() as $s) {
                    $semanas[] = ['id' => (int) $s['id'], 'nombre' => (string) $s['nombre']];
                }
            } catch (Throwable $e) {
            }

            $this->json([
                'ok'        => true,
                'semana_id' => (int) $listado['semana_id'],
                'facturas'  => $facturas,
                'lineas'    => $lineas,
                'semanas'   => $semanas,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Vincula a mano una línea del listado con una factura XML (botón
     * "Sin coincidencia"). El número y el proveedor ya no se evalúan:
     * solo el monto clasifica respaldada / con_diferencia, y la marca
     * match_manual protege el vínculo de "Verificar de nuevo".
     */
    public function forzar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            $modelo = $this->loadModel('PorPagar');
            $linea = $modelo->getLinea((int) $this->post('linea_id', 0));
            $factura = $modelo->getFacturaParaMatching((int) $this->post('factura_id', 0));

            if ($linea === null || $factura === null) {
                $this->json(['ok' => false, 'message' => 'La línea o la factura no existen.'], 404);
            }
            if (($linea['listado_estado'] ?? 'abierto') === 'cerrado') {
                $this->json(['ok' => false, 'message' => 'El pago semanal está cerrado y ya no se puede modificar.'], 409);
            }

            // Una factura solo puede respaldar una línea del listado
            foreach ($modelo->getLineas((int) $linea['listado_id']) as $otra) {
                if ((int) ($otra['factura_xml_id'] ?? 0) === (int) $factura['id']
                    && (int) $otra['id'] !== (int) $linea['id']) {
                    $this->json(['ok' => false, 'message' => 'Esa factura ya respalda la línea "' . $otra['numero'] . '".'], 409);
                }
            }

            $diferencia = round((float) $linea['total'] - (float) $factura['total'], 2);
            $estado = abs($diferencia) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $modelo->actualizarMatchManual(
                (int) $linea['id'],
                (int) $factura['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null
            );

            // Si los nombres no se parecían, esta vinculación acaba de
            // enseñar una equivalencia que nadie podía deducir. Se guarda para
            // que valga en todas las demás facturas del mismo proveedor.
            $aprendido = $this->aprenderAliasProveedor($linea, $factura);

            // Asigna la semana y mueve el par XML/PDF si el vínculo manual
            // quedó respaldado correctamente.
            $this->ejecutarMatching((int) $linea['listado_id'], $modelo);

            // Con un alias nuevo hay que revisar de nuevo lo que quedó sin
            // respaldo: puede que varias líneas se resuelvan de una vez.
            $reprocesadas = $aprendido
                ? $this->reprocesarListadosAbiertos($modelo, (int) $linea['listado_id'])
                : 0;

            $this->json([
                'ok' => true,
                'estado' => $estado,
                'diferencia' => $diferencia,
                'alias_aprendido' => $aprendido,
                'lineas_resueltas' => $reprocesadas,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()], 500);
        }
    }

    /** Exporta el checklist del listado como un libro XLSX real. */
    public function exportar()
    {
        try {
            require_once __DIR__ . '/../helpers/XlsxWriter.php';

            $listadoId = (int) $this->get('listado_id', 0);
            $modelo = $this->loadModel('PorPagar');
            $listado = $modelo->getListado($listadoId);
            if ($listado === null) {
                throw new Exception('Listado no encontrado.');
            }

            $lineas = $modelo->getLineas($listadoId, $this->filtrosListado());
            $headers = ['Fecha', 'Número', 'Proveedor', 'Total listado', 'Número XML', 'Total XML', 'Diferencia', 'Estado'];
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
                    (string) ($linea['fecha'] ?? ''),
                    (string) $linea['numero'],
                    (string) $linea['proveedor_texto'],
                    round((float) $linea['total'], 2),
                    $linea['xml_numero'] !== null
                        ? NumeroFactura::xmlOchoDigitos($linea['xml_numero'])
                        : '',
                    $linea['xml_total'] !== null ? round((float) $linea['xml_total'], 2) : '',
                    $linea['diferencia'] !== null ? round((float) $linea['diferencia'], 2) : '',
                    $etiquetas[$estado] ?? $estado,
                ];
                $cellStyles[$ri][7] = $estado === 'respaldada' ? 3 : 2;
            }

            $nombreBase = trim((string) ($listado['semana_nombre'] ?? $listado['nombre'] ?? $listadoId));
            $nombreBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', $nombreBase);
            $nombreBase = trim((string) $nombreBase, '_') ?: (string) $listadoId;
            $nombreArchivo = 'por_pagar_' . $nombreBase . '_' . date('Ymd_His') . '.xlsx';
            $anchos = [13, 20, 38, 16, 22, 16, 16, 18];

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

    // ── Matching listado ↔ facturas XML ────────────────────────────

    /**
     * Cruza cada línea del listado con la mejor factura XML disponible.
     * La lógica vive en PorPagarVerificador: es compartida con la
     * re-verificación automática que corre al asignar facturas a una
     * semana (desde Facturas, la subida directa o la cola del correo).
     */
    private function ejecutarMatching($listadoId, $modelo)
    {
        require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
        return PorPagarVerificador::verificarListado($listadoId, $modelo);
    }

    /**
     * Aprende la equivalencia de nombre que acaba de revelar un
     * emparejamiento manual.
     *
     * El caso: el ERP dice "COOPEAGRI" y el XML dice "COOPERATIVA AGRICOLA
     * INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL". No comparten ni una
     * palabra, así que ninguna comparación por parecido los va a juntar —
     * tampoco bajando el umbral, que solo traería falsos positivos. La única
     * fuente válida es la persona que emparejó a mano.
     *
     * Solo se aprende cuando hacía falta: si los nombres YA se parecían lo
     * suficiente, el vínculo manual fue por otra razón (un número raro, por
     * ejemplo) y guardar un alias no aportaría nada.
     */
    private function aprenderAliasProveedor(array $linea, array $factura)
    {
        $textoErp = trim((string) ($linea['proveedor_texto'] ?? ''));
        $proveedorId = (int) ($factura['proveedor_id'] ?? 0);
        if ($textoErp === '' || $proveedorId <= 0) {
            return false;
        }

        $score = FacturaMatcher::similaridadTexto(
            $textoErp,
            (string) ($factura['proveedor_nombre'] ?? '')
        );
        if ($score >= FacturaMatcher::UMBRAL_PROVEEDOR) {
            return false; // se parecían; no hay nada que enseñar
        }

        try {
            return (bool) $this->loadModel('ProveedorAlias')->aprender(
                $proveedorId,
                $textoErp,
                (int) ($_SESSION['user_id'] ?? 0)
            );
        } catch (Throwable $e) {
            // Que no se pueda aprender no debe tumbar el vínculo manual, que
            // es lo que la persona pidió.
            return false;
        }
    }

    /**
     * Vuelve a verificar los listados abiertos después de aprender un alias.
     *
     * Es la mitad que hace útil lo anterior: enseñar la equivalencia una vez
     * tiene que resolver TODAS las facturas del mismo proveedor que estaban
     * sin respaldo, no solo la que se emparejó a mano. Devuelve cuántas
     * líneas pasaron a tener respaldo.
     *
     * Los listados cerrados no se tocan: un pago ya cerrado no se recalcula.
     */
    private function reprocesarListadosAbiertos($modelo, $listadoYaProcesado = 0)
    {
        $resueltas = 0;
        try {
            foreach ($modelo->getListados(60) as $l) {
                $id = (int) $l['id'];
                if ($id === (int) $listadoYaProcesado || ($l['estado'] ?? 'abierto') === 'cerrado') {
                    continue;
                }
                $antes = (int) ($modelo->resumenPorEstado($id)['sin_respaldo'] ?? 0);
                if ($antes === 0) {
                    continue; // nada que rescatar en este listado
                }
                $this->ejecutarMatching($id, $modelo);
                $despues = (int) ($modelo->resumenPorEstado($id)['sin_respaldo'] ?? 0);
                $resueltas += max(0, $antes - $despues);
            }
        } catch (Throwable $e) {
            // El vínculo manual ya quedó guardado; esto es un extra.
        }
        return $resueltas;
    }

    /**
     * Término de búsqueda para el correo: el número corto de la factura
     * (regla compartida con la tarjeta de navegación del módulo Correo).
     */
    private function numeroBusqueda($numero)
    {
        return FacturaMatcher::terminoBusquedaCorreo($numero);
    }

    /**
     * Clave de deduplicación de una línea del listado: número + proveedor
     * normalizados (mayúsculas, espacios colapsados). Con ella, resubir un
     * listado de la semana solo añade las facturas que no estaban.
     */
    private function claveLinea($numero, $proveedor)
    {
        $numero = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $numero)), 'UTF-8');
        $proveedor = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $proveedor)), 'UTF-8');
        return $numero . '|' . $proveedor;
    }

    /**
     * Segunda clave de deduplicación: número + monto exacto al centavo.
     * Atrapa la misma factura cuando el proveedor viene escrito distinto
     * entre archivos (p. ej. "...DE LECHE" recortado en uno y
     * "...DE LECHE DOS PINOS R.L." completo en otro): que dos proveedores
     * distintos compartan número Y monto exacto es prácticamente imposible.
     */
    private function claveNumeroTotal($numero, $total)
    {
        $numero = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $numero)), 'UTF-8');
        return $numero . '|' . number_format((float) $total, 2, '.', '');
    }

    // ── Lectura del archivo (mismo patrón del importador de gastos) ──

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
            // (el código y el nombre pueden venir en celdas separadas)
            if (preg_match('/^Proveedor\s+\d{4,}\s*(.*)$/iu', implode(' ', $celdas), $m)) {
                $proveedor = trim($m[1]);
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

    private function validateRequiredColumns(array $map)
    {
        // El IVA ya no forma parte del listado; si viene, simplemente se ignora
        $required = ['fecha', 'numero', 'proveedor', 'total'];
        $missing = [];

        foreach ($required as $column) {
            if (!isset($map[$column])) {
                $missing[] = $column;
            }
        }

        if (!empty($missing)) {
            throw new Exception('El archivo debe incluir las columnas: Fecha, Numero, Proveedor y Total. Faltan: ' . implode(', ', $missing));
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
