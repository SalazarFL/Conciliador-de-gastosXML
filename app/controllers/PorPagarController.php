<?php
/**
 * Controlador del pago.
 *
 * El pago de un pago es una selección de facturas del ERP, no una copia del
 * archivo con que se eligieron. Cargar un listado significa: leer del archivo
 * el documento, el proveedor y el saldo, buscar cada factura en Facturas ERP y
 * marcarla como parte del pago. Nada de lo que dice el archivo se guarda —
 * el archivo no es un origen de datos, es una selección—.
 *
 * De ahí sale el resto del comportamiento del módulo:
 *   - el checklist muestra los datos del ERP, que son los que cuadran contra
 *     los totales del proveedor;
 *   - el respaldo electrónico se cruza por consecutivo, que es una igualdad y
 *     no un parecido;
 *   - una factura que llega después por el correo se engancha sola, porque el
 *     XML encuentra su fila del ERP y esa fila ya sabe a qué pago pertenece.
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/PagoResolutor.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
// La vista llama a Pago::etiqueta() para nombrar cada pago por su fecha,
// así que la clase tiene que estar cargada aunque la lista venga vacía.
require_once __DIR__ . '/../models/Pago.php';
require_once __DIR__ . '/../helpers/BusquedaImporte.php';
require_once __DIR__ . '/../helpers/Columnas.php';

class PorPagarController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        // El checklist se recuerda como se dejó; el listado y el pago no,
        // que son el contexto y se eligen arriba.
        $this->recordarFiltros('por_pagar', [
            'q', 'proveedor', 'sucursal', 'estado', 'vinculo',
            'fecha_desde', 'fecha_hasta', 'monto', 'saldo',
        ]);

        $modelo = $this->loadModel('Pago');
        $erp = $this->loadModel('FacturaErp');
        $filtros = $this->filtrosListado();

        // Acá había DOS selectores: uno elegía el pago y otro, cuál de sus
        // listados mirar. El segundo nunca tuvo nada que elegir —un pago tuvo
        // siempre un solo listado— y desapareció junto con la tabla. Queda
        // este, y lo que muestra es el pago entero.
        //
        // Sin parámetro se usa el último elegido, que se comparte entre
        // módulos por la sesión.
        $pagos = [];
        try {
            $pagos = $modelo->getConDetalle(60);
        } catch (Throwable $e) {
        }

        $pagoFiltro = $this->pagoActivo();
        if (isset($_GET['pago_id']) && $_GET['pago_id'] !== '') {
            $pagoFiltro = max(0, (int) $_GET['pago_id']);
            $this->setPagoActivo($pagoFiltro);
        }

        // El pago recordado puede ser de otra empresa o haberse borrado: si no
        // está en la lista, se cae al más reciente en vez de mostrar el vacío.
        $idsValidos = array_map(function ($p) { return (int) $p['id']; }, $pagos);
        if ($pagoFiltro <= 0 || !in_array($pagoFiltro, $idsValidos, true)) {
            $pagoFiltro = !empty($pagos) ? (int) $pagos[0]['id'] : 0;
            $this->setPagoActivo($pagoFiltro);
        }

        $pago = $pagoFiltro > 0 ? $modelo->getConSociedad($pagoFiltro) : null;
        $lineas = $pago ? $erp->getFacturasPago($pagoFiltro, $filtros) : [];
        $resumen = $pago ? $erp->resumenRespaldoPago($pagoFiltro) : [];
        $dimensiones = $pago
            ? $erp->dimensionesPago($pagoFiltro)
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

        $carpetasPago = [];
        try {
            $carpetasPago = $this->carpetasPagoDe($sociedadActiva, $modelo);
        } catch (Throwable $e) {
        }

        $sinCoincidencia = 0;
        if ($pago) {
            try {
                $sinCoincidencia = count($erp->getXmlSinCoincidencia($pagoFiltro));
            } catch (Throwable $e) {
            }
        }

        $this->render('porpagar/index', [
            'title'           => 'Pagos - Nexo Fiscal',
            'pago'            => $pago,
            'lineas'          => $lineas,
            'resumen'         => $resumen,
            'sociedadActiva'  => $sociedadActiva,
            'pagos'           => $pagos,
            'pagoFiltro'      => $pagoFiltro,
            'carpetasPago'    => $carpetasPago,
            'sinCoincidencia' => $sinCoincidencia,
            'filtros'         => $filtros,
            'proveedoresFiltro' => ProveedorCatalogo::opciones($dimensiones['proveedores']),
            'sucursales'        => $dimensiones['sucursales'],
            // Para que la pantalla de carga dibuje las fases desde el
            // principio y no tenga que adivinarlas ni esperarlas.
            'pasosCarga'        => self::PASOS_CARGA,
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

        // Monto y saldo son dos preguntas distintas: cuánto facturó el
        // proveedor y cuánto queda por pagarle.
        $monto = BusquedaImporte::numero($this->get('monto', ''));
        $saldo = BusquedaImporte::numero($this->get('saldo', ''));

        return [
            'q' => $q,
            'proveedor' => $proveedor,
            'sucursal' => $sucursal,
            'estado' => $estado,
            'vinculo' => $vinculo,
            'fecha_desde' => $desde,
            'fecha_hasta' => $hasta,
            'monto' => $monto,
            'saldo' => $saldo,
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
    private function leerYResolver($erp, $pagoId = 0, $conservarArchivo = false)
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
            $resolucion = PagoResolutor::resolver($filas, $erp->getIndicePago(), (int) $pagoId);

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
                    // Una tabla armada a mano puede traerlo o no; el reporte
                    // agrupado siempre lo trae, y lo pone el otro camino.
                    'codigo' => $this->getValue($row, $map, ['codigo', 'codigo_proveedor', 'proveedor_codigo']),
                    'total' => $this->getValue($row, $map, ['saldo', 'total']),
                ];
            }
        }

        $filas = [];
        foreach ($registros as $row) {
            try {
                $numero = trim((string) ($row['numero'] ?? ''));
                $proveedor = trim((string) ($row['proveedor'] ?? ''));
                $codigo = trim((string) ($row['codigo'] ?? ''));
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
                    // El reporte la lista sin monto: se lleva marcada para
                    // contarla, pero no se paga. Ver extraerReporteAgrupado().
                    'estado' => !empty($row['sin_monto']) ? 'sin_monto' : 'leida',
                    'numero' => $numero,
                    'proveedor' => $proveedor,
                    'codigo' => $codigo,
                    'saldo' => $saldo,
                ];
            } catch (Throwable $e) {
                $filas[] = [
                    'estado' => 'error',
                    'motivo' => $e->getMessage(),
                    'numero' => trim((string) ($row['numero'] ?? '')),
                    'proveedor' => trim((string) ($row['proveedor'] ?? '')),
                    'codigo' => trim((string) ($row['codigo'] ?? '')),
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
        $this->exigirListadoProveedores();

        try {
            $erp = $this->loadModel('FacturaErp');
            $pagoId = $this->pagoDeLaVistaPrevia();
            $pagoPrevio = $pagoId > 0 ? $this->loadModel('Pago')->findById($pagoId) : null;

            $datos = $this->leerYResolver($erp, $pagoId, true);
            $resumen = $datos['resolucion']['resumen'];

            $montoResuelto = 0.0;
            foreach ($datos['resolucion']['filas'] as $fila) {
                if ($fila['estado'] === 'resuelta' && $fila['erp']) {
                    $montoResuelto += (float) $fila['erp']['saldo'];
                }
            }

            // Este es el momento en que el aviso sirve: antes de pagar.
            // Pagar completa una factura que tenía una nota de crédito sin
            // aplicar deja la nota sin dónde descontarse, y eso hoy no se ve
            // en ninguna parte hasta que ya pasó.
            $avisoNotas = $this->avisarNotasDeCredito($datos['resolucion']['filas']);

            $this->json([
                'ok' => true,
                'archivo' => $datos['archivo'],
                'token' => $datos['token'],
                'carpeta_pago' => DocumentoArchivo::normalizarCarpetaPago((string) $this->post('carpeta_pago', '')),
                'listado_existente' => !empty($pagoPrevio['archivo_origen'])
                    ? (string) $pagoPrevio['archivo_origen'] : null,
                // Con nombre y no con "#7": el aviso es para decidir, y nadie
                // reconoce un pago por su número de fila.
                'reasignadas' => $this->pagosQueSeVacian($datos['resolucion']['filas']),
                'resumen' => $resumen,
                'monto_resuelto' => round($montoResuelto, 2),
                'notas_credito' => $avisoNotas,
                'lineas' => array_slice($datos['resolucion']['filas'], 0, 1000),
                'total_resultados' => count($datos['resolucion']['filas']),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Las facturas de este pago que traen una nota de crédito sin aplicar.
     *
     * No frena nada ni descuenta nada: el que aplica es el ERP. Lo que hace
     * es poner el dato delante de quien decide, en el único momento en que
     * todavía se puede hacer algo con él. Pagar la factura completa deja la
     * nota colgando, y después ya no hay forma de darse cuenta.
     *
     * Va envuelto: es un aviso, y que falle no puede impedir cargar un pago.
     */
    private function avisarNotasDeCredito(array $filas)
    {
        $vacio = ['facturas' => 0, 'notas' => 0, 'saldo' => 0.0, 'detalle' => []];

        try {
            $ids = [];
            $porId = [];
            foreach ($filas as $fila) {
                if (($fila['estado'] ?? '') === 'resuelta' && !empty($fila['erp']['id'])) {
                    $ids[] = (int) $fila['erp']['id'];
                    $porId[(int) $fila['erp']['id']] = $fila['erp'];
                }
            }
            if (!$ids) {
                return $vacio;
            }

            $porFactura = $this->loadModel('NotaCredito')->notasVivasDeFacturas($ids);
            if (!$porFactura) {
                return $vacio;
            }

            $detalle = [];
            $totalNotas = 0;
            $saldo = 0.0;
            foreach ($porFactura as $facturaId => $notas) {
                $suma = 0.0;
                $documentos = [];
                foreach ($notas as $nota) {
                    $suma += (float) $nota['saldo'];
                    $documentos[] = (string) $nota['documento'];
                }
                $totalNotas += count($notas);
                $saldo += $suma;
                $detalle[] = [
                    'factura' => (string) ($porId[$facturaId]['documento'] ?? ''),
                    'proveedor' => (string) ($porId[$facturaId]['proveedor_nombre'] ?? ''),
                    'saldo_factura' => (float) ($porId[$facturaId]['saldo'] ?? 0),
                    'notas' => $documentos,
                    'saldo_notas' => round($suma, 2),
                ];
            }

            // Primero las de más plata: si hay veinte, se miran las que
            // importan.
            usort($detalle, function ($a, $b) {
                return $b['saldo_notas'] <=> $a['saldo_notas'];
            });

            return [
                'facturas' => count($detalle),
                'notas' => $totalNotas,
                'saldo' => round($saldo, 2),
                'detalle' => array_slice($detalle, 0, 50),
            ];
        } catch (Throwable $e) {
            return $vacio;
        }
    }

    /**
     * En qué va la carga que está corriendo en otra petición.
     *
     * Lo primero que hace es soltar la sesión. Sin eso, esta petición se
     * quedaría esperando a que la carga larga suelte el candado —es decir,
     * contestaría cuando ya no hace falta— y la pantalla no mostraría nada
     * hasta el final, que es exactamente lo que vino a arreglar.
     */
    public function progreso()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        require_once __DIR__ . '/../helpers/ProgresoTarea.php';
        $estado = ProgresoTarea::leer((string) $this->get('token', ''));

        // Sin progreso guardado no es un error: puede que la carga todavía no
        // haya escrito el primero. La pantalla lo entiende como "esperá".
        $this->json($estado === null
            ? ['ok' => true, 'estado' => 'esperando']
            : array_merge(['ok' => true], $estado));
    }

    /**
     * Las fases de la carga, en orden, tal como las ve quien la espera.
     *
     * Están acá y no sueltas en el código para que la pantalla pueda decir "3
     * de 5" y dibujar la lista completa desde el principio: media espera es
     * saber cuánto falta, y una barra que solo avanza no lo dice.
     */
    const PASOS_CARGA = [
        'Preparando el pago',
        'Leyendo el archivo y buscando cada factura en el sistema',
        'Asignando las facturas al pago',
        'Buscándole el comprobante a cada factura',
        'Acomodando los documentos y copiándolos a la carpeta del pago',
    ];

    /**
     * Carga el listado: marca en Facturas ERP las facturas que se pagan.
     */
    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }
        $this->exigirListadoProveedores($this->url('/por-pagar'));

        @set_time_limit(300);

        require_once __DIR__ . '/../helpers/ProgresoTarea.php';
        $token = (string) $this->post('progreso_token', '');
        $progreso = ProgresoTarea::para($token, self::PASOS_CARGA);

        // Se suelta la sesión antes de empezar: PHP la bloquea mientras la
        // tiene abierta, y con ella abierta la petición que viene a preguntar
        // "¿en qué va?" se queda esperando a que esto termine —justo cuando la
        // respuesta ya no le sirve a nadie—. `redirectWithMessage` la vuelve a
        // abrir al final para dejar el mensaje.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $modelo = $this->loadModel('Pago');
            $erp = $this->loadModel('FacturaErp');

            $pagoId = $this->pagoSolicitado(true);
            $pago = $this->prepararPago($pagoId);

            // ¿Ya se le había cargado un archivo a este pago? Cambia el
            // mensaje del final —"+N nuevas" o "N facturas"— y nada más: el
            // pago existe desde antes de que llegue el archivo.
            $yaTeniaArchivo = !empty($pago['archivo_origen']);

            $progreso->paso('Leyendo el archivo y buscando cada factura en el sistema');
            $datos = $this->leerYResolver($erp, $pagoId);
            $resolucion = $datos['resolucion'];
            $resumen = $resolucion['resumen'];

            if ($resumen['resuelta'] < 1) {
                throw new Exception(
                    'Ninguna factura del archivo está en Facturas. '
                    . 'Cargá primero el reporte "Facturas por Proveedor" que las incluya, en Facturas.'
                );
            }

            // Todo o nada: medio pago cargada obliga a llevar en la cabeza
            // cuáles entraron. Si algo no resolvió, se dice y no se escribe.
            $this->exigirResolucionCompleta($resumen, $resolucion['filas']);

            $sociedadId = null;
            try {
                $activa = $this->loadModel('Sociedad')->getActiva();
                $sociedadId = $activa ? (int) $activa['id'] : null;
            } catch (Throwable $e) {
            }

            // Acá se creaba una segunda ficha para el mismo pago, con el
            // nombre del pago y "Pago " pegado adelante —de ahí salían los
            // "Pago Pago Semana 200826" de la base—. Ahora solo se anota de
            // qué archivo salió.
            $modelo->registrarArchivo($pagoId, $datos['archivo'], $sociedadId);
            $nombre = Pago::etiqueta($pago);

            // Las que vienen de otro pago: cuáles son —para soltarles el
            // saldo que congeló el pago anterior— y de qué pagos salen, que
            // quedan con menos facturas y hay que recontarlos.
            $movidas = [];
            $pagosVaciados = [];
            foreach ($resolucion['filas'] as $filaResuelta) {
                if (empty($filaResuelta['movida_desde'])) {
                    continue;
                }
                $movidas[] = (int) $filaResuelta['factura_erp_id'];
                $pagosVaciados[(int) $filaResuelta['movida_desde']] = true;
            }

            $progreso->paso(
                'Asignando las facturas al pago',
                count($resolucion['ids']) . ' facturas'
                . (count($movidas) ? ' · ' . count($movidas) . ' vienen de otro pago' : '')
            );

            $yaEstaban = $erp->contarPago($pagoId);
            $erp->descongelarSaldoPago($movidas);
            $erp->asignarAPago($resolucion['ids'], $pagoId);
            $modelo->actualizarTotalLineas($pagoId);
            // El pago del que salieron ya no las tiene: si no se recuenta,
            // sigue diciendo en pantalla que tiene facturas que no tiene.
            foreach (array_keys($pagosVaciados) as $pagoViejo) {
                $modelo->actualizarTotalLineas($pagoViejo);
            }

            $progreso->paso('Buscándole el comprobante a cada factura');
            $stats = $this->ejecutarMatching($pagoId, $erp);
            $total = $erp->contarPago($pagoId);
            $nuevas = max(0, $total - $yaEstaban);

            // La carpeta del pago se creó arriba, en prepararPago(); esto es
            // lo que la llena. Sin esta línea quedaba vacía hasta que corriera
            // la tarea programada —que ordena cada quince minutos y solo si
            // está activa en esa computadora—, así que quien cargaba el pago
            // abría la carpeta recién creada y no encontraba nada. Las otras
            // tres puertas que cambian la composición del pago (actualizar el
            // listado, quitar una factura, borrar el pago) ya movían sus
            // archivos en el momento; esta, que es la más grande, no.
            // La fase larga de verdad: toca disco por cada documento, y con la
            // carpeta en OneDrive cada archivo que solo está en la nube hay que
            // bajarlo. Decir cuántos son es lo que separa "está trabajando" de
            // "se colgó" para quien mira la pantalla.
            $conXml = $erp->idsXmlDePago($pagoId);
            $progreso->paso(
                'Acomodando los documentos y copiándolos a la carpeta del pago',
                count($conXml) . ' comprobante(s)'
            );
            $archivos = $this->reubicarArchivos($conXml);

            $msg = ($yaTeniaArchivo
                    ? "Pago \"{$nombre}\": +{$nuevas} facturas nuevas (total {$total})"
                    : "Pago \"{$nombre}\": {$total} facturas del sistema")
                . (count($movidas) > 0
                    ? ' — ' . count($movidas) . ' movida(s) desde otro pago'
                    : '')
                . " — {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.";

            if ($archivos['copias_pago'] > 0) {
                $msg .= ' ' . $archivos['copias_pago']
                    . ' comprobante(s) se copiaron a la carpeta del pago.';
            }
            $incompletas = $this->avisoIncompletas($archivos);
            if ($incompletas['texto'] !== '') {
                $msg .= ' ' . $incompletas['texto'];
            }
            if ($archivos['aviso'] !== '') {
                $msg .= ' ' . $archivos['aviso'];
            }

            // Se marca listo antes de redirigir: la pantalla deja de preguntar
            // en cuanto lo ve, y no se queda mostrando la última fase mientras
            // el navegador sigue el 302.
            $progreso->terminar($total . ' facturas en el pago');

            $this->redirectWithMessage(
                $this->url('/por-pagar?pago_id=' . (int) $pagoId),
                $msg,
                ($stats['sin_respaldo'] + $stats['con_diferencia'] > 0
                    || $archivos['aviso'] !== ''
                    || $incompletas['texto'] !== '')
                    ? 'warning'
                    : 'success',
                $incompletas['detalle']
            );
        } catch (Throwable $e) {
            // Que el progreso lo diga también: si no, la pantalla se queda
            // girando sobre una carga que ya murió, y el mensaje de error
            // aparece detrás de ella sin que nadie lo vea.
            $progreso->fallar($e->getMessage());
            $this->redirectWithMessage($this->url('/por-pagar'), 'Error al cargar el pago: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Corta la carga si alguna fila del archivo no encontró su factura.
     *
     * La regla de negocio no cambió —el pago no inventa facturas, paga
     * las que el ERP ya reportó—, pero ahora se comprueba resolviendo de
     * verdad en vez de preguntando si el número existe. El mensaje nombra las
     * primeras, que es lo que hace falta para ir a cargar el reporte correcto.
     */
    private function exigirResolucionCompleta(array $resumen, array $filas)
    {
        $problemas = [];
        // 'en_otro_pago' salió de esta lista: una factura que ya estaba en el
        // pago de otro pago dejó de cortar la carga. Se paga una sola vez,
        // así que el archivo más reciente manda y la factura se mueve.
        // 'ausente' cubre dos cosas desde que se lee el código del proveedor: la
        // factura no está en Facturas, o está pero bajo otro código. Cuál de las
        // dos es lo dice el motivo de cada fila, que la vista previa enseña; acá
        // se nombra a las dos porque el texto se lee antes de ir a mirarlas.
        foreach (['ausente' => 'no están en Facturas, o su número está a nombre de otro proveedor',
                  'ambigua' => 'tienen más de una factura posible en el sistema',
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
                . '. Corregí el archivo o cargá el reporte del sistema que falte.');
        }
    }

    /**
     * De qué pagos se llevaría facturas esta carga, y cuántas.
     *
     * Mover una factura la saca del pago de la otro pago. Eso está bien
     * —se paga una sola vez—, pero es una consecuencia sobre algo que no se
     * está mirando, así que la vista previa lo dice antes de confirmar.
     */
    private function pagosQueSeVacian(array $filas)
    {
        $cuenta = [];
        foreach ($filas as $fila) {
            if (!empty($fila['movida_desde'])) {
                $pagoViejo = (int) $fila['movida_desde'];
                $cuenta[$pagoViejo] = ($cuenta[$pagoViejo] ?? 0) + 1;
            }
        }
        if (!$cuenta) {
            return [];
        }

        $modelo = $this->loadModel('Pago');
        $salida = [];
        foreach ($cuenta as $pagoViejo => $cuantas) {
            $nombre = '';
            try {
                $viejo = $modelo->findById($pagoViejo);
                $nombre = $viejo ? Pago::etiqueta($viejo) : '';
            } catch (Throwable $e) {
            }
            $salida[] = [
                'pago' => $nombre !== '' ? $nombre : ('Pago #' . $pagoViejo),
                'facturas' => $cuantas,
            ];
        }
        return $salida;
    }

    /**
     * A qué pago va el archivo que se está previsualizando.
     *
     * "➕ Nuevo pago…" tiene que valer acá. La vista previa es el único paso
     * hacia la carga —el botón del formulario es ese—, así que exigir un pago
     * que ya exista dejaba la opción de crear uno sin ninguna salida: quien
     * elegía "Nuevo pago…" no podía importar nada.
     *
     * Devuelve 0 porque el pago todavía no existe y no se crea acá: esta
     * pantalla no escribe, y del pago solo necesita saber si ya tiene un
     * listado cargado —uno nuevo no lo tiene—. Lo crea subir() al confirmar.
     * Crearlo antes dejaría un pago vacío cada vez que alguien mira un archivo
     * y se arrepiente.
     */
    private function pagoDeLaVistaPrevia()
    {
        $seleccion = trim((string) $this->post('pago_id', ''));
        if ($seleccion === 'nuevo' || $seleccion === 'nueva') {
            return 0;
        }
        if ($seleccion === '') {
            // El archivo se verifica contra las facturas que tenga asignadas
            // el pago: sin pago no hay contra qué verificarlo.
            throw new Exception('Elegí a qué pago va este archivo: '
                . 'uno de la lista, o "Nuevo pago…" con su fecha.');
        }
        return $this->pagoSolicitado();
    }

    private function pagoSolicitado($permitirNuevo = false)
    {
        $seleccion = trim((string) $this->post('pago_id', ''));
        if ($permitirNuevo) {
            $pagoId = $this->loadModel('Pago')->resolverSeleccion(
                $seleccion,
                (string) $this->post('pago_nuevo', ''),
                (string) $this->post('pago_fecha', '')
            );
            if (empty($pagoId)) {
                throw new Exception('Elegí a qué pago va este archivo.');
            }
            return (int) $pagoId;
        }

        if (!ctype_digit($seleccion) || (int) $seleccion <= 0) {
            throw new Exception('Seleccioná un pago existente.');
        }
        return (int) $seleccion;
    }

    /** Deja lista la carpeta del pago del pago elegida. */
    private function prepararPago($pagoId)
    {
        $pagoModel = $this->loadModel('Pago');
        $pago = $pagoModel->findById((int) $pagoId);
        if ($pago === null) {
            throw new Exception('El pago seleccionada ya no existe.');
        }

        $carpetaSolicitada = trim((string) $this->post('carpeta_pago', ''));
        if ($carpetaSolicitada === '') {
            $carpetaSolicitada = (string) ($pago['carpeta_pago'] ?? ($pago['nombre'] ?? ''));
        }
        $carpetaPago = DocumentoArchivo::normalizarCarpetaPago($carpetaSolicitada);
        if ($carpetaPago === '') {
            throw new Exception('Seleccioná una carpeta válida para el pago.');
        }
        $pagoModel->configurarCarpetaPago((int) $pagoId, $carpetaPago);
        // En la carpeta compartida se deja creada para que se vea enseguida.
        // En SharePoint no: la crea la primera copia que llega, y crearla
        // acá serían llamadas por red con alguien esperando la pantalla.
        try {
            if ($this->archivaEnDisco($pago['sociedad_id'] ?? 0)) {
                (new DocumentoArchivo())->prepararCarpetaPago($carpetaPago);
            }
        } catch (Throwable $e) {
            // Sin carpeta raíz configurada el pago se carga igual; los
            // archivos se acomodan cuando alguien la configure.
        }
        $pago['carpeta_pago'] = $carpetaPago;
        return $pago;
    }

    /**
     * Las carpetas de pago para el selector, de donde archiva la empresa:
     * las que hay en la carpeta compartida, o —en SharePoint— los nombres
     * que ya usan sus pagos, que la base sabe sin salir a la red.
     */
    private function carpetasPagoDe($sociedad, $pagoModel)
    {
        if (!$this->archivaEnDisco($sociedad['id'] ?? 0)) {
            return $pagoModel->carpetasUsadas();
        }
        return DocumentoArchivo::raizConfigurada() !== ''
            ? (new DocumentoArchivo())->carpetasPago()
            : [];
    }

    /** ¿La empresa archiva en la carpeta compartida? Sin empresa, sí: es lo de siempre. */
    private function archivaEnDisco($sociedadId)
    {
        return (int) $sociedadId <= 0
            || AlmacenDocumentos::tipoDeSociedad((int) $sociedadId) === AlmacenDocumentos::DISCO;
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
            $pagoId = $this->pagoSolicitado();
            $pago = $this->loadModel('Pago')->findById($pagoId);
            if ($pago === null) {
                throw new Exception('El pago seleccionado ya no existe.');
            }

            $datos = $this->leerYResolver($erp, $pagoId);
            $asignadas = $erp->getFacturasPago($pagoId);
            $comparacion = PorPagarComparador::comparar($datos['resolucion'], $asignadas);

            $this->json([
                'ok' => true,
                'archivo' => $datos['archivo'],
                'pago' => (string) ($pago['nombre'] ?? ('Pago #' . $pagoId)),
                'listado_existente' => !empty($pago['archivo_origen'])
                    ? (string) $pago['archivo_origen'] : null,
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
     * Deja el pago del pago igual al archivo nuevo (POST, JSON).
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
            $modelo = $this->loadModel('Pago');
            $erp = $this->loadModel('FacturaErp');
            $pagoId = $this->pagoSolicitado();

            $pago = $modelo->findById($pagoId);
            if ($pago === null || empty($pago['archivo_origen'])) {
                throw new Exception('A este pago todavía no se le cargó ningún archivo. Usá "Vista previa" para cargarlo por primera vez.');
            }

            $datos = $this->leerYResolver($erp, $pagoId);
            $asignadas = $erp->getFacturasPago($pagoId);
            $comparacion = PorPagarComparador::comparar($datos['resolucion'], $asignadas);
            $resumen = $comparacion['resumen'];

            if ((int) $resumen['nueva'] + (int) $resumen['igual'] < 1) {
                throw new Exception('El archivo no resolvió ninguna factura del sistema; no se actualizó nada.');
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
                $quitadas = $salen ? $erp->quitarDePago($salen, $pagoId) : 0;
                $anadidas = $entran ? $erp->asignarAPago($entran, $pagoId, $pagoId) : 0;
                $modelo->actualizarTotalLineas($pagoId);
                $modelo->commit();
            } catch (Throwable $e) {
                $modelo->rollback();
                throw $e;
            }

            $erp->soltarPagoXml($xmlDeLasQueSalen);
            $stats = $this->ejecutarMatching($pagoId, $erp);

            $xmlDespues = $erp->idsXmlDePago($pagoId);
            $archivos = $this->reubicarArchivos(array_merge(
                array_diff($xmlDeLasQueSalen, $xmlDespues),
                array_diff($xmlDespues, $xmlDeLasQueSalen)
            ));

            $this->json([
                'ok' => true,
                'sin_cambios' => false,
                'listado_id' => $pagoId,
                'pago_id' => $pagoId,
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
        $resultado = ['movidos' => 0, 'por_fecha' => 0, 'en_pago' => 0,
                      'copias_pago' => 0, 'sin_par' => 0, 'incompletos' => [],
                      'aviso' => ''];

        $facturaIds = array_values(array_unique(array_filter(array_map('intval', $facturaIds))));
        if (!$facturaIds) {
            return $resultado;
        }
        if (DocumentoArchivo::raizConfigurada() === '') {
            return $this->reubicarSinCarpeta($facturaIds, $resultado);
        }

        try {
            require_once __DIR__ . '/../helpers/OrganizadorDocumentos.php';
            $resumen = (new OrganizadorDocumentos())->organizarIds($facturaIds, false);
            if (!empty($resumen['omitido_por_bloqueo'])) {
                $resultado['aviso'] = 'Hay otra ordenación en curso, en esta u otra computadora: los archivos no se movieron.';
                return $resultado;
            }
            $resultado['movidos'] = (int) ($resumen['movidos'] ?? 0);
            $resultado['por_fecha'] = (int) ($resumen['por_fecha'] ?? 0);
            $resultado['en_pago'] = (int) ($resumen['en_pago'] ?? 0);
            $resultado['copias_pago'] = (int) ($resumen['copias_pago'] ?? 0);
            $resultado['sin_par'] = (int) ($resumen['sin_par_completo'] ?? 0);
            $resultado['incompletos'] = is_array($resumen['incompletos'] ?? null)
                ? $resumen['incompletos']
                : [];
            if (!empty($resumen['errores'])) {
                $resultado['aviso'] = $resumen['errores'] . ' documento(s) no se pudieron mover.';
            }
            // Un fallo al copiar no interrumpe el acomodo del original, así que
            // sin decirlo acá la carpeta del pago quedaría incompleta en
            // silencio, que es justo lo que esta carpeta no puede permitirse.
            if (!empty($resumen['errores_copia_pago'])) {
                $resultado['aviso'] = trim($resultado['aviso'] . ' ' . $resumen['errores_copia_pago']
                    . ' copia(s) no llegaron a la carpeta del pago.');
            }
            if (!empty($resumen['copias_pago_en_espera'])) {
                $resultado['aviso'] = trim($resultado['aviso'] . ' ' . $resumen['copias_pago_en_espera']
                    . ' documento(s) están en SharePoint: su copia en la carpeta del pago la hace la tarea'
                    . ' programada en los próximos minutos.');
            }
        } catch (Throwable $e) {
            $resultado['aviso'] = 'No se pudieron mover los archivos: ' . $e->getMessage();
        }

        return $resultado;
    }

    /**
     * Sin carpeta compartida en esta computadora no hay nada de disco que
     * acomodar, y lo de SharePoint tampoco se acomoda acá: sus copias a la
     * carpeta del pago las hace la tarea programada. Se cuentan para
     * decirlo. Solo si algún documento del pago sigue en disco hay algo que
     * esta PC no puede hacer, y eso también se dice.
     */
    private function reubicarSinCarpeta(array $facturaIds, array $resultado)
    {
        try {
            $facturas = $this->loadModel('Factura');
            $enDisco = count($facturas->getParaOrganizarArchivos($facturaIds));
            $enEspera = 0;
            foreach ($facturas->getParaOrganizarArchivos($facturaIds, 'sharepoint', true) as $fila) {
                if (!empty($fila['en_pago']) && trim((string) ($fila['carpeta_pago'] ?? '')) !== '') {
                    $enEspera++;
                }
            }
        } catch (Throwable $e) {
            $resultado['aviso'] = 'No se pudo revisar dónde están los archivos: ' . $e->getMessage();
            return $resultado;
        }

        $avisos = [];
        if ($enDisco > 0) {
            $avisos[] = $enDisco . ' documento(s) están en la carpeta compartida, que esta computadora no'
                . ' tiene configurada: quedaron donde estaban.';
        }
        if ($enEspera > 0) {
            $avisos[] = $enEspera . ' documento(s) están en SharePoint: su copia en la carpeta del pago la'
                . ' hace la tarea programada en los próximos minutos.';
        }
        $resultado['aviso'] = implode(' ', $avisos);
        return $resultado;
    }

    /**
     * "Falta el PDF" no le sirve a nadie si no dice de cuál factura.
     *
     * La carpeta del pago solo recibe pares completos —se entrega para
     * respaldar, y un documento sin su PDF no se puede entregar—, así que
     * siempre va a tener menos archivos que facturas tiene el pago. Esa
     * diferencia era invisible: había que comparar la carpeta contra el
     * listado a mano para descubrir cuáles faltaban.
     *
     * Se nombra cada una como se la busca: número, proveedor y qué le falta.
     */
    private function nombrarIncompletas(array $incompletas)
    {
        $lineas = [];
        foreach ($incompletas as $doc) {
            $numero = trim((string) ($doc['numero'] ?? ''));
            $proveedor = trim((string) ($doc['proveedor'] ?? ''));
            $falta = trim((string) ($doc['falta'] ?? ''));

            $etiqueta = $numero !== ''
                ? NumeroFactura::xmlOchoDigitos($numero)
                : ('documento ' . (int) ($doc['documento_id'] ?? 0));
            if ($proveedor !== '') {
                $etiqueta .= ' · ' . $proveedor;
            }
            $lineas[] = $etiqueta . ($falta !== '' ? ' — falta el ' . $falta : '');
        }
        return $lineas;
    }

    /**
     * El aviso de las incompletas, listo para el mensaje y para el detalle
     * desplegable del toast. Devuelve ['texto' => ..., 'detalle' => [...]].
     */
    private function avisoIncompletas(array $archivos)
    {
        $sinPar = (int) ($archivos['sin_par'] ?? 0);
        if ($sinPar < 1) {
            return ['texto' => '', 'detalle' => []];
        }

        $nombradas = $this->nombrarIncompletas($archivos['incompletos'] ?? []);
        $texto = $sinPar . ' factura(s) no se copiaron a la carpeta del pago porque'
            . ' les falta el XML o el PDF.';
        if (count($nombradas) < $sinPar) {
            $texto .= ' Se nombran las primeras ' . count($nombradas) . '.';
        }

        return [
            'texto' => $texto,
            'detalle' => $nombradas
                ? ['aviso_lista' => [
                    'titulo' => 'Sin copiar al pago (' . $sinPar . ')',
                    'items' => $nombradas,
                  ]]
                : [],
        ];
    }

    // ── Verificar, eliminar ────────────────────────────────────────

    /**
     * El pago no se cierra.
     *
     * Existió un botón "Cerrar pago" que lo congelaba: una vez cerrado
     * nadie añadía, quitaba ni reemparejaba nada. Con la base de datos
     * compartida esa foto fija se volvió mentira —dos personas trabajan el
     * mismo pago desde máquinas distintas, y el XML que una consigue el
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
            $modelo = $this->loadModel('Pago');
            $erp = $this->loadModel('FacturaErp');
            $registro = $modelo->findById((int) $id);
            if ($registro === null) {
                $this->redirectWithMessage($this->url('/por-pagar'), 'El pago no existe.', 'error');
            }

            $destino = '/por-pagar?pago_id=' . (int) $id;
            $stats = $this->ejecutarMatching((int) $id, $erp);

            // Volver a cruzar puede estrenar respaldos —un XML que llegó
            // después, un documento recuperado de un respaldo—, y un respaldo
            // nuevo es una copia que falta en la carpeta del pago.
            $archivos = $this->reubicarArchivos($erp->idsXmlDePago((int) $id));

            $mensaje = "Verificación actualizada: {$stats['respaldada']} respaldadas, {$stats['con_diferencia']} con diferencia, {$stats['sin_respaldo']} sin respaldo.";
            if ($archivos['copias_pago'] > 0) {
                $mensaje .= ' ' . $archivos['copias_pago']
                    . ' comprobante(s) se copiaron a la carpeta del pago.';
            }
            $incompletas = $this->avisoIncompletas($archivos);
            if ($incompletas['texto'] !== '') {
                $mensaje .= ' ' . $incompletas['texto'];
            }
            if ($archivos['aviso'] !== '') {
                $mensaje .= ' ' . $archivos['aviso'];
            }

            $this->redirectWithMessage(
                $this->url($destino),
                $mensaje,
                ($stats['sin_respaldo'] + $stats['con_diferencia'] > 0
                    || $archivos['aviso'] !== ''
                    || $incompletas['texto'] !== '')
                    ? 'warning'
                    : 'success',
                $incompletas['detalle']
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/por-pagar'), 'No se pudo verificar: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Borra el pago de un pago entero.
     *
     * Lo único que se borra es la selección —qué facturas se pagaban esa
     * pago—. Las facturas del ERP vuelven a "pendiente" y sus comprobantes
     * salen de la carpeta del pago hacia el árbol por fecha de emisión. Ni las
     * facturas ni los XML/PDF se eliminan: son el registro de la empresa.
     *
     * El pago en sí tampoco desaparece; sigue existiendo para el correo, los
     * comprobantes y el seguimiento. Lo que queda es un pago sin pago
     * cargado, lista para cargarle otro archivo.
     */
    public function borrarPago($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/por-pagar'));
        }

        try {
            $erp = $this->loadModel('FacturaErp');
            $xmlIds = $erp->idsXmlDePago((int) $id);
            $total = $erp->contarPago((int) $id);
            $this->loadModel('Pago')->eliminar((int) $id);
            $erp->soltarPagoXml($xmlIds);
            $archivos = $this->reubicarArchivos($xmlIds);

            $msg = sprintf(
                'Pago del pago borrado: %d factura(s) del sistema volvieron a quedar disponibles.',
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
                'No se pudo borrar el pago: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Quita una factura del pago. No borra nada.
     *
     * La factura del ERP vuelve a "pendiente" —sigue existiendo, disponible
     * para otro pago— y su XML/PDF sale de la carpeta del pago y regresa al
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
            if ($factura === null || empty($factura['pago_id'])) {
                $this->redirectWithMessage($this->url('/por-pagar'),
                    'Esa factura no está en ningún pago.', 'error');
            }
            $pagoId = (int) $factura['pago_id'];
            $xmlId = (int) ($factura['factura_xml_id'] ?? 0);

            // El orden importa: soltar el pago del XML antes de mover el
            // archivo. El organizador decide la carpeta leyendo la base, y
            // mientras el comprobante siga marcado con el pago del pago
            // creería que todavía hay que entregarlo y lo dejaría donde está.
            $erp->quitarDePago([(int) $id], $pagoId);
            $this->loadModel('Pago')->actualizarTotalLineas($pagoId);
            $archivos = ['por_fecha' => 0, 'aviso' => ''];
            if ($xmlId > 0) {
                $erp->soltarPagoXml([$xmlId]);
                $archivos = $this->reubicarArchivos([$xmlId]);
            }

            $destino = '/por-pagar?pago_id=' . $pagoId;
            $filtrosRetorno = array_filter($this->filtrosListado(), function ($valor) {
                return $valor !== '' && $valor !== null;
            });
            if ($filtrosRetorno) {
                $destino .= '&' . http_build_query($filtrosRetorno);
            }

            $msg = 'La factura ' . trim((string) $factura['documento'])
                . ' ya no es de este pago. No se eliminó nada: sigue en Facturas, disponible para otro pago.';
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
                'No se pudo quitar la factura del pago: ' . $e->getMessage(), 'error');
        }
    }

    // ── Vínculo manual ─────────────────────────────────────────────

    /**
     * JSON del botón "Sin coincidencia": XML del pago que no respaldan
     * ninguna factura del pago, y las facturas del pago aún sin respaldo.
     */
    public function sinCoincidencia()
    {
        try {
            $erp = $this->loadModel('FacturaErp');
            $pagoId = (int) $this->get('pago_id', 0);
            $pago = $this->loadModel('Pago')->getConSociedad($pagoId);
            if ($pago === null) {
                $this->json(['ok' => false, 'message' => 'El pago no existe.'], 404);
            }

            $facturas = array_map(function ($f) {
                return [
                    'id'        => (int) $f['id'],
                    'numero'    => NumeroFactura::xmlOchoDigitos($f['numero_factura_asistente']),
                    'proveedor' => (string) ($f['proveedor_nombre'] ?? ''),
                    'total'     => (float) $f['total'],
                    'fecha'     => (string) ($f['fecha_emision'] ?? ''),
                ];
            }, $erp->getXmlSinCoincidencia($pagoId));

            $lineas = array_map(function ($l) {
                return [
                    'id'        => (int) $l['id'],
                    'numero'    => (string) $l['documento'],
                    'proveedor' => (string) $l['proveedor_nombre'],
                    'total'     => (float) $l['monto'],
                ];
            }, $erp->getFacturasPago($pagoId, ['estado' => 'sin_respaldo']));

            $pagos = [];
            try {
                foreach ($this->loadModel('Pago')->getAll() as $s) {
                    $pagos[] = ['id' => (int) $s['id'], 'nombre' => (string) $s['nombre']];
                }
            } catch (Throwable $e) {
            }

            $this->json([
                'ok'        => true,
                'pago_id' => (int) ($listado['pago_id'] ?? 0),
                'facturas'  => $facturas,
                'lineas'    => $lineas,
                'pagos'   => $pagos,
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
            $pagoId = (int) $lineaErp['pago_id'];
            foreach ($erp->getFacturasPagoParaMatching($pagoId) as $otra) {
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

            $this->ejecutarMatching($pagoId, $erp);
            $ncResueltas = $aprendido ? $this->reprocesarNotasCredito($aliasTexto) : 0;

            // Vincular a mano es afirmar que ese comprobante respalda esta
            // factura: desde ese momento le toca estar en la carpeta del pago.
            $archivos = $this->reubicarArchivos([(int) $xml['id']]);

            $this->json([
                'ok' => true,
                'estado' => $estado,
                'diferencia' => $diferencia,
                'alias_aprendido' => $aprendido,
                'notas_resueltas' => $ncResueltas,
                'copias_pago' => $archivos['copias_pago'],
                'aviso_archivos' => $archivos['aviso'],
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo vincular: ' . $e->getMessage()], 500);
        }
    }

    /**
     * El checklist del pago en Excel, con las columnas que estén encendidas.
     *
     * El archivo lleva el nombre de su pago: en la carpeta de descargas, tres
     * pagos distintos con el mismo nombre y distinta hora no se distinguen.
     */
    public function exportar()
    {
        try {
            $pagoId = (int) $this->get('pago_id', 0);
            $pago = $this->loadModel('Pago')->getConSociedad($pagoId);
            if ($pago === null) {
                throw new Exception('Pago no encontrado.');
            }

            $lineas = $this->loadModel('FacturaErp')->getFacturasPago($pagoId, $this->filtrosListado());
            $etiqueta = Pago::etiqueta($pago);

            Columnas::descargar('porpagar', $lineas, $this->get('cols', ''), $etiqueta ?: (string) $pagoId);
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/por-pagar?pago_id=' . (int) $this->get('pago_id', 0)),
                'Error al exportar a Excel: ' . $e->getMessage(),
                'error'
            );
        }
    }

    // ── Auxiliares ─────────────────────────────────────────────────

    private function ejecutarMatching($pagoId, $erp)
    {
        require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
        return PorPagarVerificador::verificarListado($pagoId, $erp, $this->loadModel('Factura'));
    }

    /**
     * Aprende la equivalencia de nombre que revela un emparejamiento manual.
     *
     * El caso: el ERP dice "COOPEAGRI" y el XML dice "COOPERATIVA AGRICOLA
     * INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL". No comparten ni una
     * palabra, así que ninguna comparación por parecido los va a juntar. La
     * única fuente válida es la persona que emparejó a mano.
     *
     * Al pago ya casi no le hace falta —cruza por consecutivo—, pero a
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
        $codigo = '';

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
            $grupo = $this->grupoDeProveedor($celdas);
            if ($grupo !== null) {
                $proveedor = $grupo['nombre'];
                $codigo = $grupo['codigo'];
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
            // Monto en blanco: el reporte la lista pero no la paga.
            //
            // No es una fila rota. Quien arma el pago borra el monto de lo que
            // deja fuera, y el subtotal del grupo lo confirma: en 162 de los 168
            // grupos del reporte del 17/09 el subtotal es exactamente la suma de
            // las filas que SÍ traen monto. Acá se saltaban en silencio —229 de
            // 539 en ese archivo—, así que nadie podía ver si el archivo era el
            // que creía. Ahora se pasan marcadas: no entran al pago, pero se
            // cuentan en la vista previa.
            if ($monto === '') {
                $registros[] = [
                    'sin_monto' => true,
                    'fecha' => '',
                    'numero' => $celdas[0],
                    'proveedor' => $proveedor,
                    'codigo' => $codigo,
                    'total' => '',
                ];
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
                'codigo' => $codigo,
                'total' => $monto,
            ];
        }

        return $registros;
    }

    /**
     * Código y nombre del proveedor si estas celdas abren un grupo, null si no.
     *
     * El código es lo que identifica al proveedor en el ERP, y viene en la misma
     * fila, a la par del nombre. Se leía ya —hace falta para reconocer el
     * encabezado— pero se tiraba, y sin él la única forma de saber de quién era
     * una factura era comparar dos nombres escritos por sistemas distintos.
     *
     * Se busca el rótulo ENTRE las celdas en vez de exigir que la fila
     * empiece con él, y el nombre se toma de su celda en vez de recortarlo
     * de la fila pegada. La razón es que quien prepara el pago escribe sobre
     * el reporte: marca proveedores con una "x" en la primera columna y deja
     * recordatorios al final ("CAMBIAR TXT"). Con la fila pegada, esa "x"
     * hacía que el encabezado no se reconociera, y como el proveedor solo
     * cambia al ver uno, TODAS las facturas del grupo quedaban a nombre del
     * proveedor anterior —sin error y sin fila perdida, solo mal atribuidas—.
     * En el archivo del pago 130826 le pasó a dos grupos de 140.
     *
     * El código y el nombre pueden venir juntos o en celdas separadas, según
     * de dónde se exportó.
     */
    private function grupoDeProveedor(array $celdas)
    {
        foreach ($celdas as $i => $celda) {
            // El rótulo, con el código y el nombre opcionalmente pegados.
            if (!preg_match('/^Proveedor\s*:?(?:\s+(\d{4,})(?:\s+(.*))?)?$/iu', $celda, $m)) {
                continue;
            }

            $codigo = isset($m[1]) ? trim($m[1]) : '';
            $resto = array_values(array_slice($celdas, $i + 1));
            if (isset($m[2]) && trim($m[2]) !== '') {
                return ['codigo' => $codigo, 'nombre' => trim($m[2])];
            }

            // El código no venía con el rótulo: tiene que estar en la celda
            // siguiente. Si no lo está, esto era un rótulo de columna suelto
            // ("Proveedor" sobre una tabla) y no abre ningún grupo.
            if ($codigo === '') {
                if (empty($resto)) {
                    return null;
                }
                if (preg_match('/^(\d{4,})\s+(.+)$/u', $resto[0], $c)) {
                    return ['codigo' => $c[1], 'nombre' => trim($c[2])];
                }
                if (!preg_match('/^\d{4,}$/', $resto[0])) {
                    return null;
                }
                $codigo = $resto[0];
                array_shift($resto);
            }

            return !empty($resto) && trim($resto[0]) !== ''
                ? ['codigo' => $codigo, 'nombre' => trim($resto[0])]
                : null;
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
