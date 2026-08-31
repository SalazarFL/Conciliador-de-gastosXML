<?php
/**
 * Módulo "Facturas".
 *
 * Se llamaba "Facturas ERP" mientras había que distinguirlo del listado de
 * comprobantes XML. Para quien lo usa son las facturas de la empresa y ya; el
 * XML es el respaldo de una de ellas, no otra clase de factura. La ruta sigue
 * siendo /facturas-erp: los enlaces guardados y los de otras pantallas siguen
 * funcionando, y renombrarla no cambiaría nada de lo que se ve.
 *
 * Carga el reporte "Facturas por Proveedor" del ERP y muestra sus columnas,
 * con foco en qué facturas siguen con saldo pendiente. Cada carga vuelve a
 * subirse completa: el modelo inserta las nuevas, actualiza las que cambiaron
 * de saldo y deja intactas las que siguen igual.
 */

require_once __DIR__ . '/../helpers/FacturasErpCsvParser.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/AlcanceProveedor.php';
require_once __DIR__ . '/../helpers/BusquedaImporte.php';

class FacturasErpController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $this->recordarFiltros('facturas_erp', [
            'q', 'proveedor', 'sucursal', 'origen', 'estado',
            'desde', 'hasta', 'solo_saldo', 'monto', 'saldo',
            // Lo elegido se recuerda: la pregunta sale la primera vez y
            // después de Limpiar, no en cada visita.
            AlcanceProveedor::PARAM,
        ]);

        $modelo = $this->loadModel('FacturaErp');
        $filtros = $this->filtros();
        $pagina = max(1, (int) $this->get('pagina', 1));
        $porPagina = 200;

        /*
         * Abrir la pantalla no es pedir las 5.196 facturas del ERP. Mientras
         * no se diga de qué proveedor —o que se quieren todas— ni el conteo
         * filtrado ni el listado ni la búsqueda de notas por factura llegan a
         * ejecutarse.
         */
        $elegirProveedor = AlcanceProveedor::hayQuePreguntar($_GET, $filtros['proveedor'] ?? '');
        $facturas = [];
        $total = 0;
        $totalPaginas = 1;
        $totalDelArchivo = null;

        if ($elegirProveedor) {
            $totalDelArchivo = $modelo->contar([]);
        } else {
            $total = $modelo->contar($filtros);
            $totalPaginas = max(1, (int) ceil($total / $porPagina));
            $pagina = min($pagina, $totalPaginas);

            $facturas = $modelo->listar($filtros, $porPagina, ($pagina - 1) * $porPagina);
        }

        $this->render('facturaserp.index', [
            'titulo' => 'Facturas',
            'facturas' => $facturas,
            'elegirProveedor' => $elegirProveedor,
            'totalDelArchivo' => $totalDelArchivo,
            // Qué factura de esta página tiene una nota de crédito esperando.
            'notasPorFactura' => $this->notasDeLasFacturas($facturas),
            // El resumen de la pantalla (facturas, con saldo, proveedores) se
            // fue con las tarjetas: era una consulta de agregados por cada
            // visita para cifras que nadie usaba para decidir nada.
            'opciones' => $modelo->opcionesFiltro(),
            'proveedoresFiltro' => ProveedorCatalogo::opciones($modelo->proveedoresParaFiltro()),
            'cargas' => $modelo->cargas(10),
            'ultimaCarga' => $modelo->ultimaCarga(),
            'incidenciasAbiertas' => $modelo->contarIncidencias(['severidad' => 'alerta']),
            'incidenciasTotal' => $modelo->contarIncidencias([]),
            'revisionPendientes' => $this->pendientesDeRevision(),
            'filtros' => $filtros,
            'pagina' => $pagina,
            'totalPaginas' => $totalPaginas,
            'total' => $total,
        ]);
    }

    /**
     * Las líneas que la carga no supo leer, para corregirlas a mano.
     *
     * Es la pantalla que le faltaba al módulo: hasta ahora, una fila que no
     * encajaba en ningún patrón se descartaba sin dejar rastro y no había
     * forma de enterarse, y mucho menos de recuperarla.
     */
    public function revision()
    {
        $sociedad = $this->loadModel('Sociedad')->getActiva();
        if (!$sociedad) {
            $this->redirectWithMessage(
                $this->url('/facturas-erp'),
                'Seleccioná una sociedad para ver sus líneas en revisión.',
                'warning'
            );
        }

        $bandeja = $this->bandeja();
        $this->render('facturaserp.revision', [
            'titulo' => 'Líneas en revisión · Facturas',
            'modulo' => 'facturas-erp',
            'pendientes' => $bandeja->pendientes((int) $sociedad['id']),
            'resueltas' => $bandeja->resueltas((int) $sociedad['id'], 50),
            'memoria' => $bandeja->memoria((int) $sociedad['id']),
            'campos' => self::CAMPOS_REVISION,
        ]);
    }

    /** Qué se puede editar de una línea rescatada, y cómo se llama en pantalla. */
    const CAMPOS_REVISION = [
        'proveedor_codigo' => ['Código de proveedor', 'texto'],
        'proveedor_nombre' => ['Nombre del proveedor', 'texto'],
        'sucursal'         => ['Sucursal', 'texto'],
        'tipo'             => ['Tipo', 'texto'],
        'documento'        => ['Documento', 'texto'],
        'fecha_emision'    => ['Fecha de emisión', 'fecha'],
        'fecha_vence'      => ['Vence', 'fecha'],
        'origen'           => ['Compra', 'texto'],
        'moneda'           => ['Moneda', 'texto'],
        'monto'            => ['Monto', 'importe'],
        'saldo'            => ['Saldo', 'importe'],
    ];

    /** Guarda una línea corregida como una factura más del listado. */
    public function guardarRevision()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp/revision'));
        }
        $volver = $this->url('/facturas-erp/revision');

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception('No hay una sociedad activa.');
            }
            $bandeja = $this->bandeja();
            $linea = $bandeja->buscar((int) $sociedad['id'], (int) $this->post('id', 0));
            if (!$linea) {
                throw new Exception('Esa línea ya no está en la bandeja.');
            }
            if ($linea['estado'] !== 'pendiente') {
                throw new Exception('Esa línea ya fue resuelta.');
            }

            $campos = $this->camposDelFormulario(array_keys(self::CAMPOS_REVISION));
            $modelo = $this->loadModel('FacturaErp');
            $id = $modelo->insertarDesdeRevision($campos, (int) $sociedad['id'], $linea['carga_id']);

            $bandeja->marcarIncluida($linea['id'], $campos, $id, $_SESSION['usuario_id'] ?? null);
            $recordar = $this->post('recordar', '') !== '';
            if ($recordar) {
                $bandeja->recordar(
                    (int) $sociedad['id'],
                    $linea,
                    'incluir',
                    $campos,
                    'Corregida a mano desde la bandeja de revisión.',
                    $_SESSION['usuario_id'] ?? null
                );
            }

            $this->engancharComprobantes($modelo);

            $this->redirectWithMessage(
                $volver,
                'La línea entró al listado como una factura más.'
                . ($recordar ? ' La próxima carga la va a corregir sola.' : ''),
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo guardar: ' . $e->getMessage(), 'error');
        }
    }

    /** Deja fuera una línea: no es un dato que deba entrar al listado. */
    public function descartarRevision()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp/revision'));
        }
        $volver = $this->url('/facturas-erp/revision');

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception('No hay una sociedad activa.');
            }
            $bandeja = $this->bandeja();
            $linea = $bandeja->buscar((int) $sociedad['id'], (int) $this->post('id', 0));
            if (!$linea) {
                throw new Exception('Esa línea ya no está en la bandeja.');
            }

            $nota = (string) $this->post('nota', '');
            $bandeja->marcarDescartada($linea['id'], $nota, $_SESSION['usuario_id'] ?? null);
            $recordar = $this->post('recordar', '') !== '';
            if ($recordar) {
                $bandeja->recordar(
                    (int) $sociedad['id'],
                    $linea,
                    'descartar',
                    [],
                    $nota,
                    $_SESSION['usuario_id'] ?? null
                );
            }

            $this->redirectWithMessage(
                $volver,
                'Línea descartada.' . ($recordar ? ' No va a volver a preguntarse.' : ''),
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo descartar: ' . $e->getMessage(), 'error');
        }
    }

    /** Olvida una decisión recordada: la línea vuelve a preguntarse. */
    public function olvidarRevision()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp/revision'));
        }
        $volver = $this->url('/facturas-erp/revision');

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception('No hay una sociedad activa.');
            }
            $this->bandeja()->olvidar((int) $sociedad['id'], (int) $this->post('memoria_id', 0));
            $this->redirectWithMessage(
                $volver,
                'Decisión olvidada: la próxima carga vuelve a preguntar por esa línea.',
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo olvidar: ' . $e->getMessage(), 'error');
        }
    }

    /** Los campos que llegan del formulario de revisión, sin nada de más. */
    private function camposDelFormulario(array $permitidos)
    {
        $campos = [];
        foreach ($permitidos as $campo) {
            $campos[$campo] = trim((string) $this->post('campo_' . $campo, ''));
        }
        return $campos;
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
            'titulo' => 'Incidencias · Facturas',
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

    /** Donde viven los CSV del ERP ya aplicados, y sus vistas previas. */
    private function carpetaListados()
    {
        $config = require __DIR__ . '/../config/config.php';
        return rtrim($config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'facturas_erp';
    }

    private function carpetaPrevias()
    {
        return $this->carpetaListados() . DIRECTORY_SEPARATOR . 'previas';
    }

    /**
     * Vista previa (POST, JSON): dice qué haría la carga sin hacer nada.
     *
     * Facturas era el único listado que se aplicaba a ciegas. Notas de crédito
     * y el pago semanal ya se miran antes de confirmar, y acá hacía más falta
     * que en ninguno: este archivo mueve de una vez el saldo de miles de
     * facturas, y hasta ahora la única forma de saber cuáles había movido era
     * aplicarlo y leer el mensaje después.
     *
     * No escribe nada. El archivo queda en 'previas' con un token y confirmar
     * lo consume desde ahí: se aplica exactamente el que se miró, no uno que
     * se vuelve a subir y podría ser otro.
     */
    public function previsualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) {
                throw new Exception(
                    'Selecciona una sociedad antes de cargar el listado del ERP: '
                    . 'el reporte no indica a qué empresa pertenece.'
                );
            }

            // Vistas previas que nadie confirmó (más de 6 horas): el archivo ya
            // no le sirve a nadie y ocupa disco de la oficina.
            foreach (glob($this->carpetaPrevias() . DIRECTORY_SEPARATOR . '*') ?: [] as $viejo) {
                if (is_file($viejo) && filemtime($viejo) < time() - 21600) {
                    @unlink($viejo);
                }
            }

            $archivo = FileUploader::uploadSingle(
                'listado_file',
                $this->carpetaPrevias(),
                ['csv'],
                $config['max_upload_size'] ?? 10485760
            );

            @set_time_limit(180);
            $resultado = FacturasErpCsvParser::parseArchivo($archivo['path']);

            // El mismo reparto que hará la carga —leer la memoria no escribe—,
            // para que el conteo de la pantalla ya incluya las líneas que van a
            // entrar rescatadas y no aparezcan de sorpresa después.
            $reparto = $this->repartirRevision($resultado['revision'], (int) $sociedad['id']);
            $resultado = FacturasErpCsvParser::conFacturasRescatadas($resultado, $reparto['facturas']);

            $modelo = $this->loadModel('FacturaErp');
            $impacto = $modelo->previsualizarImportacion(
                (int) $sociedad['id'],
                $resultado['facturas']
            );
            $repetida = $modelo->cargaDelMismoReporte(
                (int) $sociedad['id'],
                $resultado['meta']['impreso_en'] ?? ''
            );

            $cuadre = $resultado['cuadre'];
            $this->json([
                'ok' => true,
                'token' => basename($archivo['path']),
                'archivo' => $archivo['original_name'] ?? basename($archivo['path']),
                'sociedad' => (string) $sociedad['nombre'],
                'impreso_en' => $resultado['meta']['impreso_en'],
                'rango_texto' => $resultado['meta']['rango_texto'],
                'leidas' => count($resultado['facturas']),
                'proveedores' => (int) $resultado['meta']['proveedores'],
                'impacto' => $impacto,
                // Si el reporte no cuadra contra sus propios totales, la carga
                // se niega. Decirlo acá evita subir, esperar, y que el único
                // resultado sea un error rojo.
                'puede_cargar' => (bool) $resultado['ok'],
                'errores' => $resultado['errores'],
                'cuadre' => [
                    'verificados' => (int) $cuadre['verificados'],
                    'descuadres' => count($cuadre['descuadres']),
                    'saldo_leido' => (float) $cuadre['saldo_leido'],
                    'saldo_general_impreso' => $cuadre['saldo_general_impreso'],
                    'saldo_general_ok' => $cuadre['saldo_general_ok'],
                    'detalle' => array_map(function ($d) {
                        return sprintf(
                            'Proveedor %s%s: leído %s vs impreso %s',
                            $d['proveedor'],
                            $d['sucursal'] !== '' ? ' / ' . $d['sucursal'] : '',
                            number_format($d['leido_monto'], 2),
                            number_format($d['impreso_monto'], 2)
                        );
                    }, array_slice($cuadre['descuadres'], 0, 5)),
                ],
                'revision' => [
                    'pendientes' => count($reparto['pendientes']),
                    'rescatadas' => count($reparto['facturas']),
                    'descartadas' => (int) $reparto['descartadas'],
                ],
                'repetida' => $repetida ? [
                    'archivo' => (string) $repetida['archivo_origen'],
                    'cuando' => (string) $repetida['creado_en'],
                ] : null,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * El CSV que se va a aplicar: el que dejó guardado la vista previa, por su
     * token.
     *
     * Acepta también un archivo subido de una, para que la ruta siga sirviendo
     * si alguien la llama sin pasar por la pantalla. Devuelve siempre la ruta
     * definitiva, ya fuera de 'previas'.
     */
    private function archivoAAplicar()
    {
        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';
        $destino = $this->carpetaListados();

        $token = basename(trim((string) $this->post('archivo_token', '')));
        if ($token === '') {
            $subido = FileUploader::uploadSingle(
                'listado_file',
                $destino,
                ['csv'],
                $config['max_upload_size'] ?? 10485760
            );
            return [
                'path' => (string) $subido['path'],
                'original_name' => (string) ($subido['original_name'] ?? basename($subido['path'])),
            ];
        }

        $temporal = $this->carpetaPrevias() . DIRECTORY_SEPARATOR . $token;
        if (!is_file($temporal)) {
            throw new Exception('La vista previa expiró. Volvé a elegir el archivo.');
        }
        if (!is_dir($destino) && !mkdir($destino, 0777, true)) {
            throw new Exception('No fue posible preparar la carpeta de listados.');
        }
        $definitivo = $destino . DIRECTORY_SEPARATOR . $token;
        if (!rename($temporal, $definitivo)) {
            throw new Exception('No fue posible conservar el CSV original.');
        }

        $nombre = basename(trim((string) $this->post('archivo_nombre', '')));
        return ['path' => $definitivo, 'original_name' => $nombre !== '' ? $nombre : $token];
    }

    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/facturas-erp'));
        }

        try {
            $archivo = $this->archivoAAplicar();

            @set_time_limit(180);
            $resultado = FacturasErpCsvParser::parseArchivo($archivo['path']);
            $resultado['meta']['archivo'] = $archivo['original_name'];

            // El reporte trae sus propios totales: si la lectura no los
            // reproduce, no se importa nada a medias.
            if (!$resultado['ok']) {
                $detalle = array_merge($resultado['errores'], array_slice($resultado['no_reconocidas'], 0, 5));
                $this->redirectWithMessage(
                    $this->url('/facturas-erp'),
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
                    $this->url('/facturas-erp'),
                    'Selecciona una sociedad antes de cargar el listado del ERP: el reporte no indica a qué empresa pertenece.',
                    'error'
                );
            }
            $meta = $resultado['meta'];
            $meta['sociedad_id'] = (int) $sociedad['id'];

            // Las líneas que el parser no supo leer se reparten antes de
            // importar: las que ya tienen una corrección guardada entran con
            // ella, las que ya se dijo que no van se descartan solas, y el
            // resto queda preguntando en la bandeja.
            $reparto = $this->repartirRevision($resultado['revision'], (int) $sociedad['id']);
            $resultado = FacturasErpCsvParser::conFacturasRescatadas($resultado, $reparto['facturas']);

            $modelo = $this->loadModel('FacturaErp');
            $r = $modelo->importar(
                $resultado['facturas'],
                $meta,
                $resultado['cuadre'],
                $_SESSION['usuario_id'] ?? null,
                $resultado['incidencias']
            );

            $pendientes = $this->bandeja()->registrarPendientes(
                (int) $sociedad['id'],
                $reparto['pendientes'],
                (int) ($r['carga_id'] ?? 0) ?: null
            );
            $this->bandeja()->marcarMemoriaAplicada($reparto['memoria_ids']);

            $enganche = $this->engancharComprobantes($modelo);
            // Pagar una factura mueve su saldo, y con eso cambia la situación
            // de las notas que la corrigen aunque las notas no se hayan
            // tocado. Por eso el cruce se rehace desde acá también.
            $this->recalcularNotas((int) $sociedad['id']);

            $mensaje = sprintf(
                '%s facturas leídas: %s nuevas, %s con saldo actualizado y %s sin cambios.',
                number_format(count($resultado['facturas']), 0, ',', '.'),
                number_format($r['insertadas'], 0, ',', '.'),
                number_format($r['actualizadas'], 0, ',', '.'),
                number_format($r['sin_cambio'], 0, ',', '.')
            );

            if ($enganche['enganchadas'] > 0) {
                $mensaje .= sprintf(
                    ' %s factura(s) encontraron su comprobante entre los XML ya cargados.',
                    number_format($enganche['enganchadas'], 0, ',', '.')
                );
            }

            // Lo que antes desaparecía en silencio ahora se dice en voz alta.
            if ($reparto['facturas']) {
                $mensaje .= sprintf(
                    ' %s línea(s) entraron con la corrección que ya habías guardado.',
                    number_format(count($reparto['facturas']), 0, ',', '.')
                );
            }
            if ($reparto['descartadas'] > 0) {
                $mensaje .= sprintf(
                    ' %s línea(s) se descartaron solas porque ya lo habías decidido.',
                    number_format($reparto['descartadas'], 0, ',', '.')
                );
            }
            if ($pendientes > 0) {
                $mensaje .= sprintf(
                    ' Atención: %s línea(s) del reporte no se pudieron leer y quedaron esperando '
                    . 'que decidas si entran.',
                    number_format($pendientes, 0, ',', '.')
                );
            }

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

            if ($pendientes > 0 && $tipo === 'success') {
                $tipo = 'warning';
            }

            $this->redirectWithMessage($this->url('/facturas-erp'), $mensaje, $tipo, $detalles);
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/facturas-erp'),
                'Error al procesar el listado: ' . $e->getMessage(),
                'error'
            );
        }
    }

    /**
     * Rehace el cruce entre las notas de crédito y las facturas que corrigen.
     *
     * Va envuelto: es información de apoyo, y que el módulo de notas falle no
     * puede impedir que se cargue el listado del ERP.
     */
    private function recalcularNotas($sociedadId)
    {
        try {
            return $this->loadModel('NotaCredito')->recalcularAplicacion($sociedadId);
        } catch (Throwable $e) {
            return ['revisadas' => 0, 'actualizadas' => 0, 'estados' => []];
        }
    }

    /**
     * Las notas vivas que corrigen estas facturas, para pintar el aviso en su
     * renglón. Si algo falla, la pantalla sale sin avisos en vez de no salir.
     */
    private function notasDeLasFacturas(array $facturas)
    {
        try {
            return $this->loadModel('NotaCredito')
                ->notasVivasDeFacturas(array_column($facturas, 'id'));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Cuántas líneas están esperando que alguien decida. */
    private function pendientesDeRevision()
    {
        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            return $sociedad ? $this->bandeja()->contarPendientes((int) $sociedad['id']) : 0;
        } catch (Throwable $e) {
            // El aviso es un extra: que falte no puede tumbar el listado.
            return 0;
        }
    }

    /** La bandeja de líneas en revisión de este módulo. */
    private function bandeja()
    {
        require_once __DIR__ . '/../models/LineaEnRevision.php';
        static $bandeja = null;
        if ($bandeja === null) {
            $bandeja = new LineaEnRevision('facturas_erp');
        }
        return $bandeja;
    }

    /**
     * Reparte las líneas ilegibles del archivo y devuelve las que pueden
     * entrar ya convertidas en facturas.
     *
     * Una corrección recordada que ya no valida —porque el reporte cambió lo
     * suficiente— no se fuerza: vuelve a la bandeja con el motivo, que es
     * preferible a meter al listado un dato que no se sostiene.
     */
    private function repartirRevision(array $lineas, $sociedadId)
    {
        if (!$lineas) {
            return ['facturas' => [], 'pendientes' => [], 'descartadas' => 0, 'memoria_ids' => []];
        }

        $reparto = $this->bandeja()->repartir($sociedadId, $lineas);

        $facturas = [];
        $memoriaIds = [];
        $pendientes = $reparto['pendientes'];

        foreach ($reparto['corregidas'] as $linea) {
            try {
                $facturas[] = FacturaErp::sanearDesdeRevision($linea['campos']);
                $memoriaIds[] = $linea['memoria_id'] ?? 0;
            } catch (Throwable $e) {
                $linea['motivo'] = 'La corrección guardada ya no sirve para esta línea ('
                    . $e->getMessage() . ') ' . $linea['motivo'];
                $pendientes[] = $linea;
            }
        }

        return [
            'facturas' => $facturas,
            'pendientes' => $pendientes,
            'descartadas' => count($reparto['descartadas']),
            'memoria_ids' => $memoriaIds,
        ];
    }

    /**
     * Cruza contra los XML ya cargados las facturas que no tienen comprobante.
     *
     * Faltaba esta mitad. El sistema sabía enganchar un XML que llega con la
     * factura que ya estaba —lo hace XmlDocumentImporter al importarlo—, pero
     * no al revés: cargar el reporte del ERP teniendo los comprobantes en la
     * base dejaba todo en "sin respaldo" hasta que alguien armara un pago
     * semanal, que era el único lugar donde corría el emparejador.
     *
     * No se acota a las filas de esta carga: alcanza a toda factura sin
     * comprobante, así que la primera corrida también recoge lo que quedó atrás
     * de cargas viejas. Después se agota sola —lo emparejado sale del conjunto—
     * y lo que queda revisando son las facturas que de verdad no tienen XML.
     *
     * Nunca lanza: la importación ya está guardada y esto es un extra.
     */
    private function engancharComprobantes($modelo)
    {
        try {
            require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
            return PorPagarVerificador::engancharSinRespaldo($modelo, $this->loadModel('Factura'));
        } catch (Throwable $e) {
            // La próxima carga lo reintenta, y el pago semanal lo cubre igual.
            return ['enganchadas' => 0, 'revisadas' => 0];
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
            // Los dos importes con los que se busca una factura del ERP.
            'monto' => BusquedaImporte::numero($this->get('monto', '')),
            'saldo' => BusquedaImporte::numero($this->get('saldo', '')),
        ];
    }

    private function fecha($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
    }
}
