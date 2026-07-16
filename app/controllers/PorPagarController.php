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

class PorPagarController extends Controller
{
    /** Diferencia de monto tolerada por redondeo (colones). */
    private const TOLERANCIA_CRC = 1.00;

    /** Umbral mínimo de similitud de proveedor para considerar candidato. */
    private const UMBRAL_PROVEEDOR = 60;

    /** El número identifica la factura: solo matches fuertes (núcleo exacto
     *  = 100, incrustado en consecutivo = 95). */
    private const UMBRAL_NUMERO = 90;

    /** Rescate cuando el proveedor NO se parece (nombre comercial en el XML
     *  vs razón social en el listado, p. ej. "CENTRO DE PLASTICO DE PEREZ"
     *  vs "WILLIAM PEREZ QUESADA"): con número contundente (≥95) Y monto
     *  que cuadra al colón, la factura se acepta igual — esa combinación
     *  identifica mejor que cualquier nombre. */
    private const NUMERO_RESCATE = 95;

    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $modelo = $this->loadModel('PorPagar');

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
        $lineas = $listado ? $modelo->getLineas($listadoId) : [];
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

        $this->render('porpagar/index', [
            'title'          => 'Facturas por pagar - XMLConcilia',
            'listados'       => $listados,
            'listado'        => $listado,
            'lineas'         => $lineas,
            'resumen'        => $resumen,
            'sociedadActiva' => $sociedadActiva,
            'semanas'        => $semanas,
            'semanaFiltro'   => $semanaFiltro,
        ]);
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
            try {
                $semanaId = $this->loadModel('Semana')->resolverSeleccion(
                    (string) $this->post('semana_id', ''),
                    (string) $this->post('semana_nueva', '')
                );
            } catch (Throwable $e) {
            }

            // El nombre del listado se genera solo (organizado por semana).
            $nombre = 'Pago ' . date('d/m/Y H:i');
            if (!empty($semanaId)) {
                try {
                    $semana = $this->loadModel('Semana')->findById((int) $semanaId);
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
                $listadoId = (int) $listadoExistente['id'];
                $nombre = (string) $listadoExistente['nombre'];
            } else {
                $listadoId = (int) $modelo->crearListado($nombre, $sociedadId, $file['original_name'], $semanaId);
            }

            $exitosos = 0;
            $fallidos = 0;
            $omitidas = 0;

            foreach ($analisis['lineas'] as $lineaArchivo) {
                if ($lineaArchivo['estado'] === 'error') {
                    $fallidos++;
                    continue;
                }
                if ($lineaArchivo['estado'] === 'repetida') {
                    $omitidas++;
                    continue;
                }

                $modelo->crearLinea($listadoId, [
                    'fecha' => $lineaArchivo['fecha'] ?: null,   // informativa
                    'numero' => $lineaArchivo['numero'],
                    'proveedor' => $lineaArchivo['proveedor'],
                    'total' => $lineaArchivo['total'],
                ]);
                $exitosos++;
            }

            if (is_file($file['path'])) {
                @unlink($file['path']);
            }

            if ($exitosos === 0) {
                if ($listadoExistente === null) {
                    // Listado recién creado y vacío: se borra y se avisa
                    $modelo->eliminarListado($listadoId);
                    $this->redirectWithMessage($this->url('/por-pagar'),'No se pudo leer ninguna línea del listado. Verifica las columnas Fecha, Numero, Proveedor y Total.', 'error');
                }
                // Modo añadir: el archivo no trae nada nuevo — el listado
                // de la semana queda intacto, sin re-verificar nada
                $this->redirectWithMessage(
                    $this->url('/por-pagar?listado_id=' . $listadoId . '&semana_id=' . (int) $semanaId),
                    $omitidas > 0
                        ? "Sin cambios: las {$omitidas} facturas del archivo ya estaban en \"{$nombre}\"."
                        : 'No se pudo leer ninguna línea del listado. Verifica las columnas Fecha, Numero, Proveedor y Total.',
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
     * Exporta el checklist del listado a CSV (Excel lo abre directo).
     */
    public function exportar()
    {
        $listadoId = (int) $this->get('listado_id', 0);
        $modelo = $this->loadModel('PorPagar');

        $listado = $modelo->getListado($listadoId);
        if ($listado === null) {
            $this->redirectWithMessage($this->url('/por-pagar'),'Listado no encontrado.', 'error');
        }

        $lineas = $modelo->getLineas($listadoId);

        $nombreArchivo = 'por_pagar_' . $listadoId . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete UTF-8

        fputcsv($out, ['Fecha', 'Numero', 'Proveedor', 'Total listado', 'Numero XML', 'Total XML', 'Diferencia', 'Estado'], ';');

        $etiquetas = [
            'respaldada' => 'Respaldada',
            'con_diferencia' => 'Con diferencia',
            'sin_respaldo' => 'Sin respaldo',
        ];

        foreach ($lineas as $linea) {
            fputcsv($out, [
                (string) ($linea['fecha'] ?? ''),
                (string) $linea['numero'],
                (string) $linea['proveedor_texto'],
                number_format((float) $linea['total'], 2, '.', ''),
                (string) ($linea['xml_numero'] ?? ''),
                $linea['xml_total'] !== null ? number_format((float) $linea['xml_total'], 2, '.', '') : '',
                $linea['diferencia'] !== null ? number_format((float) $linea['diferencia'], 2, '.', '') : '',
                $etiquetas[$linea['estado']] ?? $linea['estado'],
            ], ';');
        }

        fclose($out);
        exit;
    }

    // ── Matching listado ↔ facturas XML ────────────────────────────

    /**
     * Cruza cada línea del listado con la mejor factura XML disponible.
     * El proveedor y el número identifican la factura; el monto solo
     * clasifica (respaldada / con_diferencia). NC y ND quedan fuera.
     */
    private function ejecutarMatching($listadoId, $modelo)
    {
        @set_time_limit(120);

        $lineas = $modelo->getLineas($listadoId);

        // El universo de candidatas es la semana del listado (si tiene):
        // la verificación no es contra el acumulado de todo el sistema
        $registro = $modelo->getListado($listadoId);
        $semanaId = $registro ? (int) ($registro['semana_id'] ?? 0) : 0;
        $facturas = $modelo->getFacturasParaMatching($semanaId);

        $stats = ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        $usadas = [];

        foreach ($lineas as $linea) {
            $mejor = null;
            $mejorScore = 0;
            $mejorNumero = 0;
            $mejorProveedor = 0;

            // Rescate: candidata con proveedor que NO se parece pero con
            // número contundente y monto exacto (ver NUMERO_RESCATE).
            $rescate = null;
            $rescateNumero = 0;
            $rescateProveedor = 0;

            foreach ($facturas as $factura) {
                $fid = (int) $factura['id'];
                if (isset($usadas[$fid])) {
                    continue;
                }

                // El número del listado puede ser el corto ("FACT-26546") o el
                // consecutivo de 20 dígitos: se compara contra ambos campos
                $scoreNumero = max(
                    FacturaMatcher::similaridadNumero($linea['numero'], (string) $factura['numero_factura_asistente']),
                    FacturaMatcher::similaridadNumero($linea['numero'], (string) $factura['consecutivo_completo'])
                );
                if ($scoreNumero < self::UMBRAL_NUMERO) {
                    continue;
                }

                $scoreProveedor = FacturaMatcher::similaridadTexto(
                    (string) $linea['proveedor_texto'],
                    (string) $factura['proveedor_nombre']
                );

                if ($scoreProveedor < self::UMBRAL_PROVEEDOR) {
                    // Proveedor distinto (nombre comercial vs razón social):
                    // solo rescatable si el número es contundente Y el monto
                    // cuadra al colón. Gana el número más fuerte.
                    if ($scoreNumero >= self::NUMERO_RESCATE
                        && abs(round((float) $linea['total'] - (float) $factura['total'], 2)) <= self::TOLERANCIA_CRC
                        && $scoreNumero > $rescateNumero) {
                        $rescate = $factura;
                        $rescateNumero = $scoreNumero;
                        $rescateProveedor = $scoreProveedor;
                    }
                    continue;
                }

                $score = ($scoreNumero * 0.6) + ($scoreProveedor * 0.4);
                if ($score > $mejorScore) {
                    $mejorScore = $score;
                    $mejor = $factura;
                    $mejorNumero = $scoreNumero;
                    $mejorProveedor = $scoreProveedor;
                }
            }

            // Sin candidata normal: usar el rescate si lo hubo
            if ($mejor === null && $rescate !== null) {
                $mejor = $rescate;
                $mejorNumero = $rescateNumero;
                $mejorProveedor = $rescateProveedor;
            }

            if ($mejor === null) {
                $modelo->actualizarMatch((int) $linea['id'], null, 'sin_respaldo', null, null, null);
                $stats['sin_respaldo']++;
                continue;
            }

            $usadas[(int) $mejor['id']] = true;

            $diferencia = round((float) $linea['total'] - (float) $mejor['total'], 2);
            $estado = abs($diferencia) <= self::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $modelo->actualizarMatch(
                (int) $linea['id'],
                (int) $mejor['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null,
                round($mejorNumero, 1),
                round($mejorProveedor, 1)
            );
            $stats[$estado]++;
        }

        return $stats;
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
