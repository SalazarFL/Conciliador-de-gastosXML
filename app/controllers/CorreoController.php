<?php
/**
 * Controlador de captura de facturas desde el correo (IMAP).
 *
 * Flujo: buscar() baja XML/PDF del buzón a la bandeja (correo_bandeja);
 * el usuario selecciona filas y importar() las manda a la cola de
 * importación existente. Los PDF quedan nombrados con el número de su
 * factura en storage/correo/pdf/, listos para el renombrador FE_.
 */

require_once __DIR__ . '/../helpers/MailFetcher.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';

class CorreoController extends Controller
{
    // Misma lista del renombrador FE_ de conciliación: sufijos societarios
    // que se quitan del nombre del proveedor al armar el nombre del archivo
    private const SUFIJOS_SOCIETARIOS = [
        'SA', 'SAS', 'SRL', 'SL', 'SC', 'SCA',
        'SOCIEDAD', 'ANONIMA', 'SIMPLIFICADA', 'RESPONSABILIDAD',
        'LTDA', 'LIMITADA', 'LTD', 'LIMITED',
        'CIA', 'COMPANIA', 'COMPANY', 'CO',
        'INC', 'INCORPORATED', 'CORP', 'CORPORATION',
        'CV', 'LLC', 'GMBH', 'AG',
    ];

    private $configLocalCache = null;

    public function __construct()
    {
        $this->requireAuth();

        // Liberar el candado de sesión SOLO en los endpoints AJAX (POST):
        // corren en paralelo (sincronización + búsqueda + contenido) y no
        // escriben en la sesión. La vista (GET) la necesita abierta porque
        // el layout vuelve a leerla después de haber enviado HTML.
        if ($this->isPost() && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function index()
    {
        // Cuentas de correo: se administran en el ⚙; la activa hace todo
        $cuentas = [];
        $cuentaActivaId = 0;
        $config = null;

        try {
            $cuentasModel = $this->loadModel('CorreoCuenta');
            $cuentasModel->seedDesdeArchivo();
            $cuentas = $cuentasModel->getAll();
            $cuentaActivaId = $this->cuentaActivaId($cuentasModel);
            if ($cuentaActivaId > 0) {
                $config = $cuentasModel->configPara($cuentaActivaId);
            }
        } catch (Throwable $e) {
            // Sin BD el módulo muestra su estado igual
        }

        $bandeja = [];
        $historial = [];
        $conteo = [];
        $sociedadActiva = null;

        try {
            $bandejaModel = $this->loadModel('CorreoBandeja');
            $bandeja = $bandejaModel->getActivas();
            $historial = $bandejaModel->getHistorial();
            $conteo = $bandejaModel->contarPorEstado();
        } catch (Throwable $e) {
            // Sin BD no hay bandeja, pero la vista igual muestra el estado del módulo
        }

        try {
            $sociedadActiva = $this->loadModel('Sociedad')->getActiva();
        } catch (Throwable $e) {
        }

        $semanas = [];
        try {
            $semanas = $this->loadModel('Semana')->getAll();
        } catch (Throwable $e) {
        }

        // Tarjeta de navegación de "Facturas por pagar": si se llegó con el
        // botón "Buscar en correo" (?pp_listado & pp_linea), se muestran los
        // datos de la factura seleccionada con flechas para recorrer el
        // listado de la semana sin volver al otro módulo.
        $ppNav = null;
        $ppListadoId = (int) $this->get('pp_listado', 0);
        if ($ppListadoId > 0) {
            try {
                require_once __DIR__ . '/../helpers/FacturaMatcher.php';
                $ppModelo = $this->loadModel('PorPagar');
                $ppListado = $ppModelo->getListado($ppListadoId);

                if ($ppListado !== null) {
                    $ppLineaId = (int) $this->get('pp_linea', 0);
                    $lineasNav = [];
                    $idxActual = 0;

                    foreach ($ppModelo->getLineas($ppListadoId) as $l) {
                        if ((int) $l['id'] === $ppLineaId) {
                            $idxActual = count($lineasNav);
                        }
                        $ts = !empty($l['fecha']) ? strtotime((string) $l['fecha']) : false;
                        $lineasNav[] = [
                            'id'        => (int) $l['id'],
                            'numero'    => (string) $l['numero'],
                            'busqueda'  => FacturaMatcher::terminoBusquedaCorreo((string) $l['numero']),
                            'proveedor' => (string) $l['proveedor_texto'],
                            'fecha'     => $ts !== false ? date('d/m/Y', $ts) : '',
                            'total'     => (float) $l['total'],
                            'estado'    => (string) $l['estado'],
                        ];
                    }

                    if (!empty($lineasNav)) {
                        $ppNav = [
                            'listado_id' => $ppListadoId,
                            'listado'    => (string) $ppListado['nombre'],
                            'semana'     => (string) ($ppListado['semana_nombre'] ?? ''),
                            'idx'        => $idxActual,
                            'lineas'     => $lineasNav,
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Sin listado no hay tarjeta; el buscador funciona igual
            }
        }

        // Al front solo van datos no sensibles de las cuentas
        $cuentasVista = array_map(function ($c) {
            return [
                'id' => (int) $c['id'],
                'nombre' => (string) $c['nombre'],
                'usuario' => (string) $c['usuario'],
                'host' => (string) $c['host'],
                'puerto' => (int) $c['puerto'],
                'carpeta' => (string) $c['carpeta'],
            ];
        }, $cuentas);

        $this->render('correo/index', [
            'title'           => 'Correo - XMLConcilia',
            'imapDisponible'  => MailFetcher::extensionDisponible(),
            'configExiste'    => !empty($cuentas),
            'configurado'     => MailFetcher::configurado($config),
            'configResumen'   => $this->resumenConfig($config),
            'configLocal'     => $this->configLocal(),
            'cuentas'         => $cuentasVista,
            'cuentaActivaId'  => $cuentaActivaId,
            'sociedadActiva'  => $sociedadActiva,
            'semanas'         => $semanas,
            'semanaActiva'    => $this->semanaActiva(),
            'buscarInicial'   => trim((string) $this->get('buscar', '')),
            'ppNav'           => $ppNav,
            'bandeja'         => $bandeja,
            'historial'       => $historial,
            'conteo'          => $conteo,
        ]);
    }

    /**
     * Lista correos del buzón con SOLO encabezados (POST, JSON).
     * 'texto' se busca EN el servidor de correo (remitente/asunto) sobre
     * todo el buzón sin bajar adjuntos; 'dias' acota el rango (0 = todo).
     */
    public function listar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();

        $dias = $this->post('dias', null);
        if ($dias !== null && $dias !== '') {
            // 0 = todo el buzón (sin filtro de fecha)
            $config['dias_atras'] = max(0, min(3650, (int) $dias));
        }

        $texto = trim((string) $this->post('texto', ''));

        // Dónde buscar: asunto (rápido), remitente (correo del proveedor),
        // ambos, o completo incluyendo el contenido del correo (más lento)
        $ambito = (string) $this->post('ambito', 'asunto_remitente');
        if (!in_array($ambito, ['asunto', 'remitente', 'asunto_remitente', 'todo'], true)) {
            $ambito = 'asunto_remitente';
        }

        // Mes prioritario 'YYYY-MM' (lo manda la lupa de la tarjeta de
        // por-pagar con el mes de la factura): se busca primero SOLO en ese
        // mes — mucho más rápido — y si no hay nada se amplía a todas las
        // fechas de forma automática. Solo aplica al índice local.
        $mes = trim((string) $this->post('mes', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = '';
        }
        $mesAplicado = '';
        $mesProbado = '';

        if ($ambito === 'todo') {
            // Búsqueda dentro del contenido: no está en el índice local,
            // así que va al servidor IMAP carpeta por carpeta (lenta).
            @set_time_limit(180);

            $fetcher = new MailFetcher($config);

            try {
                $fetcher->conectar();
                $lista = $fetcher->listarMensajes(500, $texto, $ambito);
            } catch (Throwable $e) {
                $fetcher->cerrar();
                $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
            }

            $fetcher->cerrar();
            $fuente = 'imap';
            $ultimaSync = null;
        } else {
            // Búsqueda instantánea contra el índice local (MySQL),
            // limitada a la cuenta de correo elegida
            $indice = $this->loadModel('CorreoIndice')->setCuenta((int) $config['cuenta_id']);

            if ($indice->contarTotal() === 0) {
                // Primer uso: construir el índice ahora
                @set_time_limit(300);
                try {
                    $this->ejecutarSincronizacion($config, $indice);
                } catch (Throwable $e) {
                    $this->json(['ok' => false, 'message' => 'No fue posible construir el índice del buzón: ' . $e->getMessage()], 500);
                }
            }

            if ($mes !== '') {
                $mesProbado = $mes;
                $lista = $indice->buscar($texto, $ambito, (int) $config['dias_atras'], 500, $mes);
                if ((int) $lista['total'] > 0) {
                    $mesAplicado = $mes;
                }
            }
            if ($mesAplicado === '') {
                // Sin mes prioritario, o el mes de la factura no dio nada:
                // búsqueda normal sobre todas las fechas
                $lista = $indice->buscar($texto, $ambito, (int) $config['dias_atras'], 500);
            }

            // Marcar procesados (lee procesados.json, sin conectar al buzón)
            $fetcher = new MailFetcher($config);
            foreach ($lista['correos'] as &$correo) {
                $correo['procesado'] = $fetcher->yaProcesado((string) $correo['clave']);
            }
            unset($correo);

            $fuente = 'indice';
            $ultimaSync = $indice->ultimaSync();
        }

        $this->json([
            'ok' => true,
            'total' => (int) $lista['total'],
            'mostrados' => count($lista['correos']),
            'dias' => (int) $config['dias_atras'],
            'texto' => $texto,
            'mes' => $mesAplicado !== '' ? $mesAplicado : null,
            'mes_probado' => $mesProbado !== '' ? $mesProbado : null,
            'fuente' => $fuente,
            'ultima_sync' => $ultimaSync,
            'correos' => $lista['correos'],
        ]);
    }

    /**
     * Sincroniza el índice local con el buzón (POST, JSON) por TANDAS:
     * cada petición avanza hasta ~20 segundos de carpetas y devuelve
     * completado=false si quedan; la vista vuelve a llamar hasta terminar.
     * Así la construcción inicial (miles de correos) nunca agota el
     * tiempo de ejecución de PHP.
     */
    public function sincronizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();

        @set_time_limit(120);

        try {
            $indice = $this->loadModel('CorreoIndice')->setCuenta((int) $config['cuenta_id']);
            $stats = $this->ejecutarSincronizacion($config, $indice, 20);

            $this->json([
                'ok' => true,
                'stats' => $stats,
                'completado' => (bool) $stats['completado'],
                'total_indexados' => $indice->contarTotal(),
                'ultima_sync' => $indice->ultimaSync(),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualiza el índice local carpeta por carpeta hasta agotar el
     * presupuesto de tiempo. La lógica vive en CorreoSync para que la
     * compartan el navegador (esta ruta AJAX) y la tarea programada de
     * Windows (cli/sync_correo.php), que mantiene el índice al día aunque
     * el módulo esté cerrado.
     */
    private function ejecutarSincronizacion(array $config, $indice, $presupuestoSegundos = 20)
    {
        require_once __DIR__ . '/../helpers/CorreoSync.php';
        return CorreoSync::ejecutar($config, $indice, $presupuestoSegundos);
    }

    /**
     * Devuelve el texto del cuerpo de un correo (POST, JSON) para leer la
     * descripción cuando el número de factura no viene en el asunto.
     * No descarga adjuntos.
     */
    public function contenido()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();

        $uid = (int) $this->post('uid', 0);
        $carpeta = (string) $this->post('carpeta', '');
        if ($uid <= 0) {
            $this->json(['ok' => false, 'message' => 'Correo inválido.'], 422);
        }

        @set_time_limit(60);

        $fetcher = new MailFetcher($config);

        try {
            $fetcher->conectar();
            $cuerpo = $fetcher->obtenerCuerpo($uid, $carpeta);
            $adjuntos = $fetcher->nombresAdjuntos($uid, $carpeta);
        } catch (Throwable $e) {
            $fetcher->cerrar();
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $fetcher->cerrar();

        $this->json([
            'ok' => true,
            'uid' => $uid,
            'adjuntos' => $adjuntos,
            'cuerpo' => $cuerpo !== '' ? $cuerpo : '(Este correo no tiene texto legible; su contenido puede estar solo en los adjuntos.)',
        ]);
    }

    /**
     * Procesa los correos seleccionados: baja sus adjuntos, parsea los XML
     * y llena la bandeja (POST, JSON). Máximo 10 UIDs por request; el
     * cliente manda lotes y muestra el progreso.
     */
    public function procesar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();

        // items: JSON [{uid, carpeta}, ...] — el UID solo es único dentro de
        // su carpeta, así que viajan juntos. 'uids' queda como compatibilidad.
        $items = json_decode((string) $this->post('items', ''), true);
        if (!is_array($items)) {
            $items = array_map(function ($uid) {
                return ['uid' => $uid, 'carpeta' => ''];
            }, $this->parseIds($this->post('uids', '')));
        }

        $lote = [];
        foreach ($items as $item) {
            $uid = (int) (is_array($item) ? ($item['uid'] ?? 0) : 0);
            if ($uid > 0) {
                $lote[] = ['uid' => $uid, 'carpeta' => (string) ($item['carpeta'] ?? '')];
            }
            if (count($lote) >= 10) {
                break;
            }
        }

        if (empty($lote)) {
            $this->json(['ok' => false, 'message' => 'No seleccionaste ningún correo.'], 422);
        }

        // Agrupar por carpeta para minimizar cambios de carpeta en el servidor
        usort($lote, function ($a, $b) {
            return strcmp($a['carpeta'], $b['carpeta']);
        });

        if (!class_exists('XmlInvoiceParser', false)) {
            require_once __DIR__ . '/../helpers/XmlParser.php';
        }

        @set_time_limit(300);

        $fetcher = new MailFetcher($config);

        try {
            $fetcher->conectar();
        } catch (Throwable $e) {
            $fetcher->cerrar();
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $bandejaModel = $this->loadModel('CorreoBandeja');
        $facturaModel = $this->loadModel('Factura');

        $procesados = 0;
        $omitidos = 0;
        $sinAdjuntos = 0;
        $nuevas = 0;
        $yaExistentes = 0;
        $aceptadas = 0;
        $rechazadas = 0;
        $otraCedula = 0;
        $pdfsGuardados = 0;
        $pdfsSinIdentificar = 0;
        $errores = [];

        foreach ($lote as $item) {
            $uid = $item['uid'];
            try {
                $mensaje = $fetcher->extraerMensaje($uid, $item['carpeta']);

                if ($fetcher->yaProcesado($mensaje['clave'])) {
                    $omitidos++;
                    continue;
                }

                if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                    $sinAdjuntos++;
                    $fetcher->marcarProcesado($mensaje['clave']);
                    $procesados++;
                    continue;
                }

                $resultado = $this->procesarMensaje($mensaje, $bandejaModel, $facturaModel, (int) $config['cuenta_id']);
                $nuevas += $resultado['nuevas'];
                $yaExistentes += $resultado['ya_existentes'];
                $aceptadas += $resultado['aceptadas'];
                $rechazadas += $resultado['rechazadas'];
                $otraCedula += $resultado['otra_cedula'];
                $pdfsGuardados += $resultado['pdfs_guardados'];
                $pdfsSinIdentificar += $resultado['pdfs_sin_identificar'];
                foreach ($resultado['errores'] as $err) {
                    $errores[] = $err;
                }

                $fetcher->marcarProcesado($mensaje['clave']);
                $procesados++;
            } catch (Throwable $e) {
                $errores[] = 'Correo UID ' . (int) $uid . ': ' . $e->getMessage();
            }
        }

        $fetcher->cerrar();

        $this->json([
            'ok' => true,
            'procesados' => $procesados,
            'omitidos' => $omitidos,
            'sin_adjuntos' => $sinAdjuntos,
            'nuevas' => $nuevas,
            'ya_existentes' => $yaExistentes,
            'aceptadas' => $aceptadas,
            'rechazadas' => $rechazadas,
            'otra_cedula' => $otraCedula,
            'pdfs_guardados' => $pdfsGuardados,
            'pdfs_sin_identificar' => $pdfsSinIdentificar,
            'errores' => array_slice($errores, 0, 10),
        ]);
    }

    /**
     * Valida extensión + cuenta elegida y devuelve su config para
     * MailFetcher, o responde el error en JSON (json() corta la ejecución).
     * La cuenta llega en el POST (cuenta_id); sin ella se usa la activa.
     */
    private function configListoOFallar()
    {
        if (!MailFetcher::extensionDisponible()) {
            $this->json(['ok' => false, 'message' => 'La extensión imap de PHP no está activa en este servidor.'], 500);
        }

        $config = $this->configCuenta((int) $this->post('cuenta_id', 0));
        if ($config === null || !MailFetcher::configurado($config)) {
            $this->json(['ok' => false, 'message' => 'No hay cuentas de correo configuradas: pulsa el engranaje ⚙ y agrega la primera cuenta.'], 422);
        }

        return $config;
    }

    /**
     * Config de la cuenta indicada (o de la activa si $cuentaId <= 0).
     */
    private function configCuenta($cuentaId = 0)
    {
        $cuentas = $this->loadModel('CorreoCuenta');
        $cuentas->seedDesdeArchivo();

        if ($cuentaId <= 0) {
            $cuentaId = $this->cuentaActivaId($cuentas);
        }

        return $cuentaId > 0 ? $cuentas->configPara($cuentaId) : null;
    }

    /**
     * Cuenta con la que se trabaja: la elegida en config.json si sigue
     * existiendo; si no, la primera registrada.
     */
    private function cuentaActivaId($cuentas)
    {
        $id = (int) ($this->configLocal()['cuenta_id'] ?? 0);
        if ($id > 0 && $cuentas->getById($id) !== null) {
            return $id;
        }

        $todas = $cuentas->getAll();
        return !empty($todas) ? (int) $todas[0]['id'] : 0;
    }

    /**
     * Manda las filas seleccionadas a la cola de importación (POST, JSON).
     * El procesamiento lo hace el loop JS con /facturas/cola/procesar.
     */
    public function importar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $ids = $this->parseIds($this->post('ids', ''));
            if (empty($ids)) {
                $this->json(['ok' => false, 'message' => 'No seleccionaste ninguna factura.'], 422);
            }

            $bandejaModel = $this->loadModel('CorreoBandeja');
            $filas = $bandejaModel->getByIds($ids, 'pendiente');

            $rutas = [];
            $idsValidos = [];
            $filasValidas = [];
            foreach ($filas as $fila) {
                $rutaXml = (string) ($fila['archivo_xml'] ?? '');
                if ($rutaXml === '' || !is_file($rutaXml)) {
                    continue;
                }

                $rutas[] = ['ruta' => $rutaXml, 'nombre' => $this->nombreOriginal($rutaXml)];
                $idsValidos[] = (int) $fila['id'];
                $filasValidas[] = $fila;
            }

            if (empty($rutas)) {
                $this->json(['ok' => false, 'message' => 'Las filas seleccionadas no tienen XML pendiente de importar (¿ya se importaron o falta el archivo?).'], 422);
            }

            // Semana de trabajo elegida en la bandeja: las facturas
            // importadas quedan asignadas a ella
            $semanaId = null;
            try {
                $semanaId = $this->loadModel('Semana')->resolverSeleccion(
                    (string) $this->post('semana_id', ''),
                    (string) $this->post('semana_nueva', '')
                );
                // Recordar la semana elegida al importar para los demás módulos
                if (!empty($semanaId)) {
                    $this->setSemanaActiva($semanaId);
                }
            } catch (Throwable $e) {
            }

            require_once __DIR__ . '/../helpers/InvoiceImportQueue.php';
            $service = new InvoiceImportQueue();

            $inicio = $service->iniciarImportacion([
                'archivo_origen' => 'Correo ' . date('d/m/Y H:i'),
                'total_esperado' => count($rutas),
                'semana_id' => $semanaId,
            ]);
            $importacionId = (int) $inicio['importacion_id'];

            $resultado = $service->agregarArchivosLocales($importacionId, $rutas);

            $bandejaModel->marcarImportadas($idsValidos, $importacionId);

            // Dejar el XML + PDF de cada factura importada, ya renombrados
            // (FE_/NC_PROVEEDOR_ddmmyy_numero), en la carpeta configurada ⚙
            $archivosGuardados = 0;
            $avisoCarpeta = '';
            $carpetaDestino = trim((string) ($this->configLocal()['carpeta_destino'] ?? ''));

            if ($carpetaDestino === '') {
                $avisoCarpeta = 'Configura la carpeta destino (⚙) para guardar los XML/PDF renombrados.';
            } elseif (!is_dir($carpetaDestino) && !@mkdir($carpetaDestino, 0777, true) && !is_dir($carpetaDestino)) {
                $avisoCarpeta = 'No se pudo abrir la carpeta destino "' . $carpetaDestino . '".';
            } else {
                foreach ($filasValidas as $fila) {
                    $nombre = $this->nombreArchivoBandeja($fila);

                    $xmlOk = @copy((string) $fila['archivo_xml'], $carpetaDestino . DIRECTORY_SEPARATOR . $nombre . '.xml');
                    if ($xmlOk) {
                        $archivosGuardados++;
                    }

                    $rutaPdf = (string) ($fila['archivo_pdf'] ?? '');
                    $pdfOk = true; // sin PDF no hay nada que copiar ni que retener
                    if ($rutaPdf !== '' && is_file($rutaPdf)) {
                        $pdfOk = @copy($rutaPdf, $carpetaDestino . DIRECTORY_SEPARATOR . $nombre . '.pdf');
                        if ($pdfOk) {
                            $archivosGuardados++;
                        }
                    }

                    // Con el par renombrado ya en la carpeta destino, los
                    // originales de storage sobran: el contenido del XML
                    // queda en la BD y la cola importa desde SU copia.
                    // Si alguna copia falló (o no hay carpeta configurada),
                    // no se borra nada.
                    if ($xmlOk && $pdfOk) {
                        @unlink((string) $fila['archivo_xml']);
                        if ($rutaPdf !== '' && is_file($rutaPdf)) {
                            @unlink($rutaPdf);
                        }
                    }
                }
            }

            $this->json([
                'ok' => true,
                'importacion_id' => $importacionId,
                'encoladas' => (int) $resultado['uploaded_count'],
                'archivos_guardados' => $archivosGuardados,
                'carpeta_destino' => $carpetaDestino,
                'aviso_carpeta' => $avisoCarpeta,
                'estado' => $resultado['estado'],
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No fue posible encolar la importación: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Descarta filas de la bandeja: borra su XML, conserva el PDF (POST, JSON).
     */
    public function descartar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $ids = $this->parseIds($this->post('ids', ''));
            if (empty($ids)) {
                $this->json(['ok' => false, 'message' => 'No seleccionaste ninguna factura.'], 422);
            }

            $bandejaModel = $this->loadModel('CorreoBandeja');

            // Se pueden descartar pendientes, ya_existe, rechazadas y otra_cedula
            $filas = array_merge(
                $bandejaModel->getByIds($ids, 'pendiente'),
                $bandejaModel->getByIds($ids, 'ya_existe'),
                $bandejaModel->getByIds($ids, 'rechazada'),
                $bandejaModel->getByIds($ids, 'otra_cedula')
            );

            $idsValidos = [];
            $clavesCorreo = [];
            foreach ($filas as $fila) {
                $idsValidos[] = (int) $fila['id'];
                $rutaXml = (string) ($fila['archivo_xml'] ?? '');
                if ($rutaXml !== '' && is_file($rutaXml)) {
                    @unlink($rutaXml);
                }
                // uid_correo es la misma clave usada en procesados.json
                $clave = (string) ($fila['uid_correo'] ?? '');
                if ($clave !== '') {
                    $clavesCorreo[$clave] = true;
                }
            }

            $descartadas = $bandejaModel->marcarDescartadas($idsValidos);

            // Desmarcar los correos de origen como procesados: al eliminar
            // de la bandeja, el correo debe poder procesarse otra vez.
            if (!empty($clavesCorreo)) {
                MailFetcher::desmarcarProcesados(array_keys($clavesCorreo));
            }

            $this->json(['ok' => true, 'descartadas' => (int) $descartadas]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No fue posible descartar: ' . $e->getMessage()], 500);
        }
    }

    // ── Procesamiento de un correo hacia la bandeja ────────────────

    /**
     * Guarda los XML del correo en la bandeja y empareja sus PDF.
     *
     * Un correo de factura suele traer 3 archivos: el XML de la factura,
     * el XML del mensaje de Hacienda (aceptación/rechazo) y el PDF. El
     * mensaje de Hacienda NO se importa: solo se usa para verificar que
     * la factura esté aceptada; si Hacienda la rechazó, la factura queda
     * en la bandeja como 'rechazada' y no se puede importar.
     */
    private function procesarMensaje(array $mensaje, $bandejaModel, $facturaModel, $cuentaId = 0)
    {
        $resultado = [
            'nuevas' => 0,
            'ya_existentes' => 0,
            'aceptadas' => 0,
            'rechazadas' => 0,
            'otra_cedula' => 0,
            'pdfs_guardados' => 0,
            'pdfs_sin_identificar' => 0,
            'errores' => [],
        ];

        // Cédula de la sociedad activa (se elige en Inicio): toda factura
        // debe venir a nombre de esa cédula como receptor
        $cedulaEmpresa = '';
        try {
            $activa = $this->loadModel('Sociedad')->getActiva();
            if ($activa) {
                $cedulaEmpresa = preg_replace('/\D+/', '', (string) $activa['cedula']);
            }
        } catch (Throwable $e) {
            // Sin sociedades registradas no se verifica cédula
        }

        // 1) Clasificar cada XML: mensaje de Hacienda vs factura
        $mensajesHacienda = [];   // clave (50 dígitos) => ['codigo','detalle']
        $facturas = [];

        foreach ($mensaje['xmls'] as $adjunto) {
            $clasificacion = $this->clasificarXml($adjunto['ruta']);

            if ($clasificacion['tipo'] === 'mensaje_hacienda') {
                // Se conserva el archivo (con su ruta): si el correo no trae la
                // FacturaElectronica, este mensaje se usa como fuente más abajo.
                $clasificacion['adjunto'] = $adjunto;
                $clave = $clasificacion['clave'] !== '' ? $clasificacion['clave'] : ('sin_clave_' . count($mensajesHacienda));
                $mensajesHacienda[$clave] = $clasificacion;
                continue;
            }

            try {
                $docData = XmlInvoiceParser::parseCfdiFromFile($adjunto['ruta']);

                $facturas[] = [
                    'adjunto' => $adjunto,
                    'clave' => trim((string) ($docData['clave'] ?? '')),
                    'numero_corto' => $this->numeroCorto((string) ($docData['numero_factura_asistente'] ?? '')),
                    'proveedor' => (string) ($docData['razon_social_emisor'] ?? ''),
                    'fecha_emision' => (string) ($docData['fecha_emision'] ?? ''),
                    'total' => (float) ($docData['total'] ?? 0),
                    'hash_xml' => (string) ($docData['hash_xml'] ?? ''),
                    'receptor_id' => (string) ($docData['receptor_id'] ?? ''),
                    'tipo_doc' => (string) ($docData['tipo_documento'] ?? 'FE'),
                ];
            } catch (Throwable $e) {
                $resultado['errores'][] = 'XML "' . $adjunto['nombre'] . '" no se pudo leer: ' . $e->getMessage();
                @unlink($adjunto['ruta']);
            }
        }

        // 2) Verificación Hacienda: cruzar factura ↔ mensaje por Clave.
        //    Sin mensaje en el correo no se bloquea (no siempre viene).
        foreach ($facturas as $idx => $f) {
            $verificacion = null;

            if ($f['clave'] !== '' && isset($mensajesHacienda[$f['clave']])) {
                $verificacion = $mensajesHacienda[$f['clave']];
            } elseif (count($facturas) === 1 && count($mensajesHacienda) === 1) {
                // Única factura + único mensaje: es su mensaje aunque la
                // clave no se haya podido leer del XML de la factura
                $verificacion = reset($mensajesHacienda);
            }

            $facturas[$idx]['hacienda'] = null;
            $facturas[$idx]['hacienda_detalle'] = '';

            if ($verificacion !== null) {
                $codigo = (int) $verificacion['codigo'];
                // Código de Hacienda: 1 = aceptado, 2 = aceptación parcial, 3 = rechazado
                if ($codigo === 1 || $codigo === 2) {
                    $facturas[$idx]['hacienda'] = 'aceptada';
                } elseif ($codigo === 3) {
                    $facturas[$idx]['hacienda'] = 'rechazada';
                }
                $facturas[$idx]['hacienda_detalle'] = (string) $verificacion['detalle'];
            }
        }

        // 2b) Correo sin FacturaElectronica pero con MensajeHacienda aceptado:
        //     algunos proveedores (DEMASA vía EDICOM/BusinessMail) envían solo
        //     el comprobante de aceptación + el PDF. Se reconstruye la factura
        //     desde el mensaje para no perderla; la aceptación ya viene en él.
        $mensajesUsados = [];
        if (empty($facturas)) {
            foreach ($mensajesHacienda as $clave => $mh) {
                $codigo = (int) $mh['codigo'];
                if ($codigo !== 1 && $codigo !== 2) {
                    continue; // un rechazo sin factura no se carga
                }
                try {
                    $docData = XmlInvoiceParser::parseCfdiFromFile($mh['adjunto']['ruta']);
                } catch (Throwable $e) {
                    $resultado['errores'][] = 'MensajeHacienda "' . $mh['adjunto']['nombre'] . '" no se pudo leer: ' . $e->getMessage();
                    continue;
                }

                $facturas[] = [
                    'adjunto' => $mh['adjunto'],
                    'clave' => trim((string) ($docData['clave'] ?? '')),
                    'numero_corto' => $this->numeroCorto((string) ($docData['numero_factura_asistente'] ?? '')),
                    'proveedor' => (string) ($docData['razon_social_emisor'] ?? ''),
                    'fecha_emision' => (string) ($docData['fecha_emision'] ?? ''),
                    'total' => (float) ($docData['total'] ?? 0),
                    'hash_xml' => (string) ($docData['hash_xml'] ?? ''),
                    'receptor_id' => (string) ($docData['receptor_id'] ?? ''),
                    'tipo_doc' => (string) ($docData['tipo_documento'] ?? 'FE'),
                    'hacienda' => 'aceptada',
                    'hacienda_detalle' => (string) $mh['detalle'],
                ];
                $mensajesUsados[$clave] = true;
            }
        }

        // Los MensajeHacienda que solo sirvieron para verificar (o que no se
        // usaron como fuente) se descartan: no se importan al sistema.
        foreach ($mensajesHacienda as $clave => $mh) {
            if (empty($mensajesUsados[$clave])) {
                @unlink($mh['adjunto']['ruta']);
            }
        }

        // 2) Emparejar PDFs: 1 XML + 1 PDF → directo; varios → por el número
        //    del nombre del PDF vs número de cada XML. Se prueba en orden:
        //    igualdad exacta, número extraído de la clave de 50 dígitos
        //    (PDFs nombrados "Factura#<clave>.pdf"), y la regla del núcleo
        //    "termina en" con relleno de ceros. Ante varias candidatas por
        //    "termina en" gana el número más largo (490 no le roba a 1490).
        $pdfPorIndice = [];
        $pdfsRestantes = $mensaje['pdfs'];

        if (count($facturas) === 1 && count($pdfsRestantes) === 1) {
            $pdfPorIndice[0] = array_shift($pdfsRestantes);
        } else {
            foreach ($pdfsRestantes as $k => $pdf) {
                $corePdf = $this->numeroCorto((string) $pdf['nombre']);
                if ($corePdf === '') {
                    continue;
                }
                $clavePdf = $this->numeroDesdeClave((string) $pdf['nombre']);

                $mejorIdx = null;
                $mejorLen = -1;
                foreach ($facturas as $idx => $factura) {
                    $numero = (string) $factura['numero_corto'];
                    if (isset($pdfPorIndice[$idx]) || $numero === '') {
                        continue;
                    }
                    if ($numero === $corePdf || ($clavePdf !== '' && $numero === $clavePdf)) {
                        $mejorIdx = $idx;
                        break;
                    }
                    if (FacturaMatcher::nucleoTerminaEn($corePdf, $numero) && strlen($numero) > $mejorLen) {
                        $mejorIdx = $idx;
                        $mejorLen = strlen($numero);
                    }
                }

                if ($mejorIdx !== null) {
                    $pdfPorIndice[$mejorIdx] = $pdf;
                    unset($pdfsRestantes[$k]);
                }
            }
        }

        // 3) Insertar cada factura en la bandeja
        foreach ($facturas as $idx => $factura) {
            $adjunto = $factura['adjunto'];

            // Ya está en la bandeja de una corrida anterior (mismo correo+hash).
            // Una fila descartada NO cuenta: se revive más abajo para que el
            // correo eliminado de la bandeja pueda procesarse otra vez.
            $filaPrevia = $factura['hash_xml'] !== ''
                ? $bandejaModel->getPorUidHash($mensaje['clave'], $factura['hash_xml'])
                : null;
            if ($filaPrevia && $filaPrevia['estado'] !== 'descartada') {
                @unlink($adjunto['ruta']);
                if (isset($pdfPorIndice[$idx])) {
                    @unlink($pdfPorIndice[$idx]['ruta']);
                }
                continue;
            }

            $esRechazada = ($factura['hacienda'] ?? null) === 'rechazada';

            // Verificación de cédula: el receptor del XML debe ser la
            // empresa configurada. Sin cédula configurada o sin receptor
            // legible en el XML no se bloquea.
            $esOtraCedula = false;
            if (!$esRechazada && $cedulaEmpresa !== '') {
                $receptor = preg_replace('/\D+/', '', (string) $factura['receptor_id']);
                $esOtraCedula = $receptor !== '' && $receptor !== $cedulaEmpresa;
            }

            $estado = 'pendiente';
            if ($esRechazada) {
                $estado = 'rechazada';
            } elseif ($esOtraCedula) {
                $estado = 'otra_cedula';
            } elseif ($factura['hash_xml'] !== '' &&
                ($facturaModel->existsByHash($factura['hash_xml']) || $bandejaModel->existePorHash($factura['hash_xml']))) {
                $estado = 'ya_existe';
            }

            // Mover el XML de tmp/ a xml/ (nombre con prefijo removible "__")
            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $adjunto['nombre']);
            $rutaXml = MailFetcher::storagePath('xml') . DIRECTORY_SEPARATOR . uniqid('correo_', true) . '__' . $nombreSeguro;
            if (!rename($adjunto['ruta'], $rutaXml)) {
                $resultado['errores'][] = 'No se pudo guardar el XML "' . $adjunto['nombre'] . '".';
                continue;
            }

            // Guardar su PDF ya nombrado con el número de factura.
            // El PDF de una factura rechazada por Hacienda o de otra
            // cédula se descarta: no debe llegar a las carpetas de trabajo.
            $rutaPdf = null;
            if (isset($pdfPorIndice[$idx])) {
                if ($esRechazada || $esOtraCedula) {
                    @unlink($pdfPorIndice[$idx]['ruta']);
                } else {
                    $numero = $factura['numero_corto'] !== '' ? $factura['numero_corto'] : pathinfo($nombreSeguro, PATHINFO_FILENAME);
                    $rutaPdf = MailFetcher::storagePath('pdf') . DIRECTORY_SEPARATOR . $numero . '.pdf';
                    if (rename($pdfPorIndice[$idx]['ruta'], $rutaPdf)) {
                        $resultado['pdfs_guardados']++;
                    } else {
                        $rutaPdf = null;
                    }
                }
            }

            $datosFila = [
                'cuenta_id' => (int) $cuentaId,
                'uid_correo' => $mensaje['clave'],
                'remitente' => $mensaje['remitente'],
                'asunto' => $mensaje['asunto'],
                'fecha_correo' => $mensaje['fecha'],
                'archivo_xml' => $rutaXml,
                'archivo_pdf' => $rutaPdf,
                'numero_corto' => $factura['numero_corto'] !== '' ? $factura['numero_corto'] : null,
                'tipo_doc' => $factura['tipo_doc'],
                'proveedor' => $factura['proveedor'] !== '' ? mb_substr($factura['proveedor'], 0, 255, 'UTF-8') : null,
                'fecha_emision' => $factura['fecha_emision'] !== '' ? $factura['fecha_emision'] : null,
                'total' => $factura['total'],
                'hash_xml' => $factura['hash_xml'] !== '' ? $factura['hash_xml'] : null,
                'estado' => $estado,
            ];

            if ($filaPrevia) {
                // Fila descartada del mismo correo+documento: se reactiva
                $bandejaModel->revivir((int) $filaPrevia['id'], $datosFila);
            } else {
                $bandejaModel->crear($datosFila);
            }

            if ($estado === 'rechazada') {
                $resultado['rechazadas']++;
                $detalle = trim((string) $factura['hacienda_detalle']);
                $resultado['errores'][] = 'Rechazada por Hacienda: '
                    . ($factura['numero_corto'] !== '' ? $factura['numero_corto'] : $adjunto['nombre'])
                    . ($detalle !== '' ? ' — ' . mb_substr($detalle, 0, 140, 'UTF-8') : '');
            } elseif ($estado === 'otra_cedula') {
                $resultado['otra_cedula']++;
                $resultado['errores'][] = 'Receptor con otra cédula ('
                    . mb_substr((string) $factura['receptor_id'], 0, 30, 'UTF-8') . '): '
                    . ($factura['numero_corto'] !== '' ? $factura['numero_corto'] : $adjunto['nombre'])
                    . ' — no está a nombre de la empresa';
            } elseif ($estado === 'ya_existe') {
                $resultado['ya_existentes']++;
            } else {
                $resultado['nuevas']++;
            }

            if (($factura['hacienda'] ?? null) === 'aceptada') {
                $resultado['aceptadas']++;
            }
        }

        // 4) PDFs que no se pudieron emparejar → sin_identificar/, con un
        //    aviso que diga de QUÉ factura es el PDF huérfano (su XML no
        //    vino en el correo) para que el resumen no confunda.
        foreach ($pdfsRestantes as $pdf) {
            if (!is_file($pdf['ruta'])) {
                continue;
            }
            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $pdf['nombre']);
            $destino = MailFetcher::storagePath('sin_identificar') . DIRECTORY_SEPARATOR . uniqid('pdf_', true) . '__' . $nombreSeguro;
            if (rename($pdf['ruta'], $destino)) {
                $resultado['pdfs_sin_identificar']++;

                $numeroPdf = $this->numeroDesdeClave((string) $pdf['nombre']);
                if ($numeroPdf === '') {
                    $numeroPdf = $this->numeroCorto((string) $pdf['nombre']);
                    if (strlen($numeroPdf) > 10) {
                        $numeroPdf = '';
                    }
                }
                $resultado['errores'][] = ($numeroPdf !== ''
                    ? 'El PDF de la factura ' . $numeroPdf . ' vino sin su XML en este correo'
                    : 'Un PDF vino sin su XML en este correo')
                    . ' (quedó en sin_identificar/): ' . mb_substr((string) $pdf['nombre'], 0, 80, 'UTF-8');
            }
        }

        return $resultado;
    }

    // ── Configuración local (carpeta destino + cédula de la empresa) ──

    /**
     * Guarda la configuración del módulo (POST, JSON): la carpeta donde se
     * escriben XML+PDF renombrados al importar, y la cédula de la empresa
     * contra la que se verifica el receptor de cada factura.
     */
    public function config()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $carpeta = trim((string) $this->post('carpeta_destino', ''));

            if ($carpeta !== '') {
                $carpeta = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $carpeta), '\\/');

                if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
                    $this->json(['ok' => false, 'message' => 'No se pudo crear la carpeta "' . $carpeta . '". Verifica la ruta.'], 422);
                }
                if (!is_writable($carpeta)) {
                    $this->json(['ok' => false, 'message' => 'La carpeta "' . $carpeta . '" existe pero no se puede escribir en ella.'], 422);
                }
            }

            // La cédula ya no se guarda aquí: la define la sociedad activa (Inicio)
            $configActual = $this->configLocal();
            $configActual['carpeta_destino'] = $carpeta;
            $this->guardarConfigLocal($configActual);

            $this->json(['ok' => true, 'config' => $this->configLocal()]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo guardar la configuración: ' . $e->getMessage()], 500);
        }
    }

    // ── Auto-sincronización en segundo plano (Tarea Programada Windows) ──

    /**
     * Estado de la actualización automática del índice (POST, JSON): si está
     * activa, cada cuántos minutos, si la tarea existe en Windows y el
     * resultado de la última corrida (storage/correo/sync_estado.json).
     */
    public function autoSyncEstado()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $cfg = $this->configLocal()['auto_sync'] ?? [];

        $this->json([
            'ok'              => true,
            'soportado'       => DIRECTORY_SEPARATOR === '\\' && function_exists('exec'),
            'activo'          => !empty($cfg['activo']),
            'intervalo_min'   => max(1, (int) ($cfg['intervalo_min'] ?? 10)),
            'tarea_instalada' => $this->tareaSyncInstalada(),
            'ultima'          => $this->leerEstadoSync(),
        ]);
    }

    /**
     * Activa (o reconfigura) la tarea programada que mantiene el índice al día
     * aunque el módulo esté cerrado (POST, JSON). Registra
     * "XMLConcilia_SyncCorreo" para correr cli/sync_correo.php cada N minutos,
     * oculto (mediante un lanzador .vbs) y en la sesión del usuario conectado.
     */
    public function autoSyncActivar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->json(['ok' => false, 'message' => 'La tarea programada solo está disponible cuando el servidor corre en Windows.'], 422);
        }
        if (!function_exists('exec')) {
            $this->json(['ok' => false, 'message' => 'exec() está deshabilitado en PHP; no se puede registrar la tarea programada.'], 422);
        }

        $intervalo = max(1, min(1440, (int) $this->post('intervalo_min', 10)));

        $php = $this->rutaPhpCli();
        if ($php === null) {
            $this->json(['ok' => false, 'message' => 'No se encontró php.exe (busqué en C:\\xampp\\php\\). Verifica la instalación de XAMPP.'], 422);
        }

        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'sync_correo.php';
        if (!is_file($script)) {
            $this->json(['ok' => false, 'message' => 'No se encontró el script cli/sync_correo.php.'], 500);
        }

        // Lanzador .vbs: ejecuta php OCULTO (sin ventana de consola cada N min)
        try {
            $vbs = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'sync_launch.vbs';
            $cmd = '"' . $php . '" "' . $script . '"';
            $vbsCmd = str_replace('"', '""', $cmd);
            file_put_contents($vbs, 'CreateObject("WScript.Shell").Run "' . $vbsCmd . '", 0, False' . "\r\n");
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo crear el lanzador: ' . $e->getMessage()], 500);
        }

        [$codigo, $salida] = $this->ejecutarPowerShell($this->scriptRegistrarTareaSync($vbs, $intervalo));

        if ($codigo !== 0) {
            $detalle = trim(implode(' ', array_slice($salida, 0, 6)));
            $this->json(['ok' => false, 'message' => 'No se pudo registrar la tarea programada' . ($detalle !== '' ? ': ' . $detalle : '.')], 500);
        }

        $configActual = $this->configLocal();
        $configActual['auto_sync'] = [
            'activo'        => true,
            'intervalo_min' => $intervalo,
            'php'           => $php,
            'actualizado'   => date('Y-m-d H:i:s'),
        ];
        $this->guardarConfigLocal($configActual);

        $this->json([
            'ok'            => true,
            'intervalo_min' => $intervalo,
            'php'           => $php,
            'message'       => 'Actualización automática activada: el índice se refrescará cada ' . $intervalo . ' min en segundo plano.',
        ]);
    }

    /**
     * Desactiva la tarea programada (POST, JSON). El índice deja de refrescarse
     * solo; se puede seguir actualizando a mano con el módulo abierto.
     */
    public function autoSyncDesactivar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        if (DIRECTORY_SEPARATOR === '\\' && function_exists('exec')) {
            $this->ejecutarPowerShell(
                "\$ErrorActionPreference = 'SilentlyContinue'\r\n"
                . "Unregister-ScheduledTask -TaskName 'XMLConcilia_SyncCorreo' -Confirm:\$false | Out-Null\r\n"
                . "Write-Output 'OK'\r\n"
            );
        }

        $configActual = $this->configLocal();
        $auto = $configActual['auto_sync'] ?? [];
        $auto['activo'] = false;
        $configActual['auto_sync'] = $auto;
        $this->guardarConfigLocal($configActual);

        $this->json(['ok' => true, 'message' => 'Actualización automática desactivada.']);
    }

    /** Lee el resultado de la última corrida automática, o null si no hay. */
    private function leerEstadoSync()
    {
        $ruta = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'sync_estado.json';
        if (!is_file($ruta)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($ruta), true);
        return is_array($data) ? $data : null;
    }

    /** ¿Existe la tarea "XMLConcilia_SyncCorreo" en el Programador de Windows? */
    private function tareaSyncInstalada()
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            return false;
        }
        $salida = [];
        $codigo = 1;
        @exec('schtasks /query /TN "XMLConcilia_SyncCorreo" 2>NUL', $salida, $codigo);
        return $codigo === 0;
    }

    /**
     * Ubica php.exe de la CLI. XAMPP lo trae en <unidad>\xampp\php\php.exe;
     * se prueban también rutas derivadas del binario actual y de PHPRC.
     */
    private function rutaPhpCli()
    {
        $root = dirname(__DIR__, 2); // ...\xmlconcilia
        $candidatos = [
            dirname($root, 2) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe', // ...\xampp\php\php.exe
            'C:\\xampp\\php\\php.exe',
        ];

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidatos[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        $phprc = getenv('PHPRC');
        if ($phprc) {
            $candidatos[] = rtrim($phprc, '\\/') . DIRECTORY_SEPARATOR . 'php.exe';
        }

        foreach ($candidatos as $c) {
            if ($c !== '' && @is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Escribe un .ps1 temporal (con BOM: PowerShell 5.1 lee sin BOM como ANSI)
     * y lo ejecuta. Devuelve [codigoSalida, lineasDeSalida].
     */
    private function ejecutarPowerShell($script)
    {
        $dir = MailFetcher::storagePath('tmp');
        $ruta = $dir . DIRECTORY_SEPARATOR . 'ps_' . bin2hex(random_bytes(6)) . '.ps1';
        file_put_contents($ruta, "\xEF\xBB\xBF" . $script);

        $salida = [];
        $codigo = 1;
        @exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $ruta . '" 2>&1', $salida, $codigo);

        @unlink($ruta);
        return [$codigo, $salida];
    }

    /**
     * Script PowerShell que registra la tarea "XMLConcilia_SyncCorreo": corre
     * el lanzador .vbs cada N minutos, indefinidamente, en la sesión del
     * usuario conectado (sin pedir contraseña ni permisos de administrador).
     * Sin acentos: se ejecuta en PowerShell 5.1.
     */
    private function scriptRegistrarTareaSync($vbs, $intervaloMin)
    {
        $plantilla = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$tn = 'XMLConcilia_SyncCorreo'
$vbs = '{{VBS}}'
$intervalo = {{MIN}}

$accion = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('//B //Nologo "' + $vbs + '"')

# Cada N minutos, indefinido; primer arranque en 1 minuto
$t = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
$r = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $intervalo)
$t.Repetition = $r.Repetition

# Correr como el usuario con sesion abierta (sin contrasena ni admin)
$usuario = (Get-CimInstance Win32_ComputerSystem).UserName
if (-not $usuario) { $usuario = "$env:USERDOMAIN\$env:USERNAME" }
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited
$ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName $tn -Action $accion -Trigger $t -Principal $principal -Settings $ajustes -Force | Out-Null

# Primer refresco inmediato para que se vea trabajar
try { Start-ScheduledTask -TaskName $tn } catch { }
Write-Output 'OK'
POWERSHELL;

        return str_replace(
            ['{{VBS}}', '{{MIN}}'],
            [str_replace("'", "''", $vbs), (int) $intervaloMin],
            $plantilla
        );
    }

    // ── Cuentas de correo (⚙: la empresa tiene varios buzones) ─────

    /**
     * Crea o actualiza una cuenta (POST, JSON). Con id > 0 actualiza; el
     * password vacío al editar conserva el actual.
     */
    public function cuentaGuardar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $id = (int) $this->post('id', 0);
            $datos = [
                'nombre' => trim((string) $this->post('nombre', '')),
                'host' => trim((string) $this->post('host', '')),
                'puerto' => (int) $this->post('puerto', 993),
                'usuario' => trim((string) $this->post('usuario', '')),
                'password' => (string) $this->post('password', ''),
                'carpeta' => trim((string) $this->post('carpeta', 'INBOX')),
            ];

            if ($datos['nombre'] === '' || $datos['host'] === '' || $datos['usuario'] === '') {
                $this->json(['ok' => false, 'message' => 'Nombre, host y usuario son obligatorios.'], 422);
            }
            if ($id <= 0 && trim($datos['password']) === '') {
                $this->json(['ok' => false, 'message' => 'La contraseña es obligatoria al crear una cuenta.'], 422);
            }

            $cuentas = $this->loadModel('CorreoCuenta');

            if ($id > 0) {
                if ($cuentas->getById($id) === null) {
                    $this->json(['ok' => false, 'message' => 'La cuenta no existe.'], 422);
                }
                $cuentas->actualizar($id, $datos);
            } else {
                $id = (int) $cuentas->crear($datos);
            }

            $this->json(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo guardar la cuenta: ' . $e->getMessage()], 500);
        }
    }

    public function cuentaEliminar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $id = (int) $this->post('id', 0);
            $this->loadModel('CorreoCuenta')->eliminar($id);

            // Si era la cuenta en uso, soltar la referencia
            $configLocal = $this->configLocal();
            if ((int) ($configLocal['cuenta_id'] ?? 0) === $id) {
                unset($configLocal['cuenta_id']);
                $this->guardarConfigLocal($configLocal);
            }

            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo eliminar la cuenta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Elige la cuenta con la que se trabaja (POST, JSON).
     */
    /**
     * Guardar la semana de trabajo activa al cambiar el selector de la bandeja
     * (POST, JSON), para que se recuerde en los demás módulos. semana_id vacío
     * o 0 = "Sin semana".
     */
    public function semanaUsar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $this->setSemanaActiva((int) $this->post('semana_id', 0));
        $this->json(['ok' => true, 'semana_id' => $this->semanaActiva()]);
    }

    public function cuentaUsar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $id = (int) $this->post('id', 0);
            if ($this->loadModel('CorreoCuenta')->getById($id) === null) {
                $this->json(['ok' => false, 'message' => 'La cuenta no existe.'], 422);
            }

            $configLocal = $this->configLocal();
            $configLocal['cuenta_id'] = $id;
            $this->guardarConfigLocal($configLocal);

            $this->json(['ok' => true, 'cuenta_id' => $id]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo cambiar de cuenta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Prueba la conexión IMAP de una cuenta (POST, JSON). Con id > 0 usa la
     * cuenta guardada (y el password del formulario si viene); sin id, usa
     * los datos del formulario tal cual.
     */
    public function cuentaProbar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        if (!MailFetcher::extensionDisponible()) {
            $this->json(['ok' => false, 'message' => 'La extensión imap de PHP no está activa.'], 500);
        }

        try {
            $id = (int) $this->post('id', 0);
            $password = (string) $this->post('password', '');

            if ($id > 0) {
                $config = $this->loadModel('CorreoCuenta')->configPara($id);
                if ($config === null) {
                    $this->json(['ok' => false, 'message' => 'La cuenta no existe.'], 422);
                }
                if (trim($password) !== '') {
                    $config['password'] = $password;
                }
            } else {
                $config = [
                    'host' => trim((string) $this->post('host', '')),
                    'puerto' => (int) $this->post('puerto', 993),
                    'usuario' => trim((string) $this->post('usuario', '')),
                    'password' => $password,
                    'carpeta' => trim((string) $this->post('carpeta', 'INBOX')),
                ];
            }

            if (!MailFetcher::configurado($config)) {
                $this->json(['ok' => false, 'message' => 'Faltan datos: host, usuario y contraseña.'], 422);
            }

            @set_time_limit(60);

            $fetcher = new MailFetcher($config);
            try {
                $fetcher->conectar();
            } finally {
                $fetcher->cerrar();
            }

            $this->json(['ok' => true, 'message' => 'Conexión exitosa con ' . $config['usuario'] . '.']);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No conecta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Explorador de carpetas para el modal de configuración (POST, JSON).
     * Sin 'ruta' devuelve las unidades del equipo (C:\, D:\…); con una ruta,
     * sus subcarpetas. accion=crear crea una subcarpeta y devuelve su listado.
     *
     * Se navega dentro del propio modal en vez de abrir un diálogo nativo de
     * Windows porque Apache corre como servicio (sesión 0): un diálogo saldría
     * en un escritorio invisible y colgaría la petición.
     */
    public function carpetas()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $ruta = $this->normalizarRuta((string) $this->post('ruta', ''));

            if ((string) $this->post('accion', '') === 'crear') {
                $nombre = trim((string) $this->post('nombre', ''));
                if ($ruta === '' || $nombre === '' || preg_match('/[<>:"\/\\\\|?*]/', $nombre)) {
                    $this->json(['ok' => false, 'message' => 'Nombre de carpeta inválido.'], 422);
                }
                $nueva = rtrim($ruta, '\\/') . DIRECTORY_SEPARATOR . $nombre;
                if (!is_dir($nueva) && !@mkdir($nueva, 0777, true) && !is_dir($nueva)) {
                    $this->json(['ok' => false, 'message' => 'No se pudo crear la carpeta "' . $nombre . '".'], 422);
                }
                $ruta = $nueva;
            }

            $this->json($this->listarDirectorio($ruta));
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo abrir la carpeta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Devuelve el contenido (solo subcarpetas) de una ruta local. Ruta vacía
     * = raíz: lista las unidades del equipo. Incluye 'padre' para el botón
     * "subir" y 'escribible' para avisar si no se podrá guardar ahí.
     */
    private function listarDirectorio($ruta)
    {
        if ($ruta === '') {
            return [
                'ok' => true,
                'ruta' => '',
                'es_raiz' => true,
                'padre' => null,
                'escribible' => false,
                'carpetas' => $this->unidadesDisco(),
            ];
        }

        if (!is_dir($ruta)) {
            return ['ok' => false, 'message' => 'La carpeta ya no existe: ' . $ruta];
        }

        $carpetas = [];
        $items = @scandir($ruta);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = rtrim($ruta, '\\/') . DIRECTORY_SEPARATOR . $item;
                if (@is_dir($full)) {
                    $carpetas[] = ['nombre' => $item, 'ruta' => $full];
                }
            }
            usort($carpetas, function ($a, $b) {
                return strcasecmp($a['nombre'], $b['nombre']);
            });
        }

        return [
            'ok' => true,
            'ruta' => $ruta,
            'es_raiz' => false,
            'padre' => $this->rutaPadre($ruta),
            'escribible' => is_writable($ruta),
            'carpetas' => $carpetas,
        ];
    }

    /**
     * Normaliza una ruta recibida del cliente: unifica separadores y deja la
     * barra final solo en la raíz de unidad ("C:" → "C:\", "C:\x\" → "C:\x").
     */
    private function normalizarRuta($ruta)
    {
        $ruta = trim($ruta);
        if ($ruta === '') {
            return '';
        }

        $ruta = str_replace('/', DIRECTORY_SEPARATOR, $ruta);

        // "C:" → "C:\" (raíz de unidad necesita la barra)
        if (preg_match('/^[A-Za-z]:$/', $ruta)) {
            return $ruta . DIRECTORY_SEPARATOR;
        }
        // "C:\" se queda igual; cualquier otra, sin barra final
        if (preg_match('/^[A-Za-z]:\\\\$/', $ruta)) {
            return $ruta;
        }

        return rtrim($ruta, '\\/');
    }

    /**
     * Carpeta padre de una ruta. La raíz de unidad ("C:\") sube a la lista de
     * unidades (cadena vacía).
     */
    private function rutaPadre($ruta)
    {
        if (preg_match('/^[A-Za-z]:\\\\?$/', $ruta)) {
            return '';
        }

        $ruta = rtrim($ruta, '\\/');
        $pos = strrpos($ruta, DIRECTORY_SEPARATOR);
        if ($pos === false) {
            return '';
        }

        $padre = substr($ruta, 0, $pos);
        if (preg_match('/^[A-Za-z]:$/', $padre)) {
            $padre .= DIRECTORY_SEPARATOR;   // "C:" → "C:\"
        }

        return $padre;
    }

    /**
     * Unidades de disco disponibles. En Windows prueba C:–Z: (se omiten A:/B:
     * para no despertar lectores sin medio); en Unix devuelve la raíz "/".
     */
    private function unidadesDisco()
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [['nombre' => '/', 'ruta' => '/']];
        }

        $unidades = [];
        foreach (range('C', 'Z') as $letra) {
            $raiz = $letra . ':\\';
            if (@is_dir($raiz)) {
                $unidades[] = ['nombre' => $letra . ':\\', 'ruta' => $raiz];
            }
        }

        return $unidades;
    }

    // ── Selector nativo de carpeta (explorador de Windows) ─────────

    /**
     * Abre el diálogo nativo de Windows para elegir la carpeta destino
     * (POST, JSON). Apache corre como servicio (sesión 0, sin escritorio),
     * así que el diálogo no puede abrirse directo desde PHP: un script
     * lanzador lo ejecuta en la sesión del usuario conectado mediante una
     * tarea programada interactiva. El diálogo escribe la ruta elegida en
     * un archivo de resultado que selectorEstado() va consultando.
     */
    public function selectorAbrir()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->json(['ok' => false, 'message' => 'El explorador de Windows solo está disponible cuando el servidor corre en Windows.'], 422);
        }
        if (!function_exists('exec')) {
            $this->json(['ok' => false, 'message' => 'exec() está deshabilitado en PHP.'], 422);
        }

        try {
            $dir = MailFetcher::storagePath('tmp');
            $this->limpiarSelectorViejos($dir);

            $token = bin2hex(random_bytes(8));
            $resultado = $dir . DIRECTORY_SEPARATOR . 'pick_' . $token . '.txt';
            $picker    = $dir . DIRECTORY_SEPARATOR . 'pick_' . $token . '.ps1';
            $launcher  = $dir . DIRECTORY_SEPARATOR . 'pick_launch_' . $token . '.ps1';

            // El diálogo abre en la carpeta ya configurada, si existe
            $inicial = trim((string) ($this->configLocal()['carpeta_destino'] ?? ''));
            if ($inicial !== '' && !is_dir($inicial)) {
                $inicial = '';
            }

            file_put_contents($picker, $this->scriptPicker($resultado, $inicial));
            file_put_contents($launcher, $this->scriptLauncher($picker));

            $salida = [];
            $codigo = 1;
            exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $launcher . '" 2>&1', $salida, $codigo);

            if ($codigo !== 0) {
                @unlink($picker);
                @unlink($launcher);
                $detalle = trim(implode(' ', array_slice($salida, 0, 4)));
                $this->json(['ok' => false, 'message' => 'No se pudo abrir el explorador de Windows' . ($detalle !== '' ? ': ' . $detalle : '.')], 500);
            }

            $this->json(['ok' => true, 'token' => $token]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo abrir el explorador de Windows: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Consulta si el usuario ya eligió carpeta en el diálogo (POST, JSON).
     * Estados: esperando (diálogo abierto), ok (con 'ruta') o cancelado.
     */
    public function selectorEstado()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $token = (string) $this->post('token', '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            $this->json(['ok' => false, 'message' => 'Token inválido.'], 422);
        }

        $dir = MailFetcher::storagePath('tmp');
        $resultado = $dir . DIRECTORY_SEPARATOR . 'pick_' . $token . '.txt';

        if (!is_file($resultado)) {
            $this->json(['ok' => true, 'estado' => 'esperando']);
        }

        $contenido = trim((string) file_get_contents($resultado));

        @unlink($resultado);
        @unlink($dir . DIRECTORY_SEPARATOR . 'pick_' . $token . '.ps1');
        @unlink($dir . DIRECTORY_SEPARATOR . 'pick_launch_' . $token . '.ps1');

        if ($contenido === 'CANCEL') {
            $this->json(['ok' => true, 'estado' => 'cancelado']);
        }
        if (strpos($contenido, 'ERROR') === 0) {
            $this->json(['ok' => false, 'message' => 'El diálogo falló: ' . trim(substr($contenido, 5))], 500);
        }
        if ($contenido === '' || !is_dir($contenido)) {
            $this->json(['ok' => false, 'message' => 'La carpeta elegida no se pudo leer.'], 422);
        }

        $this->json(['ok' => true, 'estado' => 'ok', 'ruta' => $contenido]);
    }

    /**
     * Script PowerShell que muestra el diálogo nativo "Seleccionar carpeta"
     * (IFileOpenDialog con FOS_PICKFOLDERS, el mismo del explorador moderno;
     * con respaldo al diálogo clásico de carpetas) y escribe la ruta elegida
     * en el archivo de resultado. Sin acentos: se ejecuta en PowerShell 5.1.
     */
    private function scriptPicker($archivoResultado, $carpetaInicial)
    {
        $plantilla = <<<'POWERSHELL'
# Selector de carpeta de XMLConcilia: muestra el dialogo nativo de Windows
# y escribe la ruta elegida (o CANCEL / ERROR ...) en el archivo resultado.
$ErrorActionPreference = 'Stop'
$resultado = '{{RESULTADO}}'
$inicial   = '{{INICIAL}}'

function Escribir([string] $texto) {
    $utf8 = New-Object System.Text.UTF8Encoding -ArgumentList $false
    [System.IO.File]::WriteAllText($resultado, $texto, $utf8)
}

try {
    Add-Type -AssemblyName System.Windows.Forms

    # Formulario invisible siempre-encima: es el dueno del dialogo para que
    # salga al frente del navegador en vez de quedar detras de la ventana
    $dueno = New-Object System.Windows.Forms.Form
    $dueno.TopMost = $true
    $dueno.ShowInTaskbar = $false
    $dueno.FormBorderStyle = 'None'
    $dueno.Opacity = 0
    $null = $dueno.Handle

    $moderno = $true
    try {
        # Dialogo moderno "Seleccionar carpeta" del explorador de Windows
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class XmlConciliaSelector
{
    [ComImport, Guid("DC1C5A9C-E88A-4dde-A5A1-60F82A20AEF7")]
    private class FileOpenDialogRCW { }

    [ComImport, Guid("42f85136-db7e-439c-85f1-e4075d135fc8"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IFileOpenDialog
    {
        [PreserveSig] uint Show(IntPtr parent);
        void SetFileTypes(uint cFileTypes, IntPtr rgFilterSpec);
        void SetFileTypeIndex(uint iFileType);
        void GetFileTypeIndex(out uint piFileType);
        void Advise(IntPtr pfde, out uint pdwCookie);
        void Unadvise(uint dwCookie);
        void SetOptions(uint fos);
        void GetOptions(out uint pfos);
        void SetDefaultFolder(IShellItem psi);
        void SetFolder(IShellItem psi);
        void GetFolder(out IShellItem ppsi);
        void GetCurrentSelection(out IShellItem ppsi);
        void SetFileName([MarshalAs(UnmanagedType.LPWStr)] string pszName);
        void GetFileName(out IntPtr pszName);
        void SetTitle([MarshalAs(UnmanagedType.LPWStr)] string pszTitle);
        void SetOkButtonLabel([MarshalAs(UnmanagedType.LPWStr)] string pszText);
        void SetFileNameLabel([MarshalAs(UnmanagedType.LPWStr)] string pszLabel);
        void GetResult(out IShellItem ppsi);
        void AddPlace(IShellItem psi, uint fdap);
        void SetDefaultExtension([MarshalAs(UnmanagedType.LPWStr)] string pszDefaultExtension);
        void Close(int hr);
        void SetClientGuid(ref Guid guid);
        void ClearClientData();
        void SetFilter(IntPtr pFilter);
        void GetResults(out IntPtr ppenum);
        void GetSelectedItems(out IntPtr ppsai);
    }

    [ComImport, Guid("43826d1e-e718-42ee-bc55-a1e261c37bfe"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IShellItem
    {
        void BindToHandler(IntPtr pbc, ref Guid bhid, ref Guid riid, out IntPtr ppv);
        void GetParent(out IShellItem ppsi);
        void GetDisplayName(uint sigdnName, out IntPtr ppszName);
        void GetAttributes(uint sfgaoMask, out uint psfgaoAttribs);
        void Compare(IShellItem psi, uint hint, out int piOrder);
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode, PreserveSig = false)]
    private static extern void SHCreateItemFromParsingName(
        [MarshalAs(UnmanagedType.LPWStr)] string pszPath, IntPtr pbc,
        [In, MarshalAs(UnmanagedType.LPStruct)] Guid riid, out IShellItem ppv);

    public static string Mostrar(IntPtr dueno, string titulo, string inicial)
    {
        IFileOpenDialog dlg = (IFileOpenDialog)new FileOpenDialogRCW();
        uint opciones;
        dlg.GetOptions(out opciones);
        dlg.SetOptions(opciones | 0x20 | 0x40); // FOS_PICKFOLDERS | FOS_FORCEFILESYSTEM
        dlg.SetTitle(titulo);

        if (!string.IsNullOrEmpty(inicial) && System.IO.Directory.Exists(inicial))
        {
            try
            {
                IShellItem carpeta;
                SHCreateItemFromParsingName(inicial, IntPtr.Zero,
                    new Guid("43826d1e-e718-42ee-bc55-a1e261c37bfe"), out carpeta);
                dlg.SetFolder(carpeta);
            }
            catch { }
        }

        if (dlg.Show(dueno) != 0) return null; // cancelado

        IShellItem elegido;
        dlg.GetResult(out elegido);
        IntPtr pszRuta;
        elegido.GetDisplayName(0x80058000, out pszRuta); // SIGDN_FILESYSPATH
        try { return Marshal.PtrToStringUni(pszRuta); }
        finally { Marshal.FreeCoTaskMem(pszRuta); }
    }
}
'@
    } catch { $moderno = $false }

    if ($moderno) {
        $ruta = [XmlConciliaSelector]::Mostrar($dueno.Handle, 'Carpeta destino de XML y PDF - XMLConcilia', $inicial)
        if ($null -eq $ruta) { Escribir 'CANCEL'; exit 0 }
    } else {
        # Respaldo: dialogo clasico de seleccion de carpeta
        $dlg = New-Object System.Windows.Forms.FolderBrowserDialog
        $dlg.Description = 'Carpeta destino de XML y PDF - XMLConcilia'
        $dlg.ShowNewFolderButton = $true
        if ($inicial -ne '' -and (Test-Path -LiteralPath $inicial)) { $dlg.SelectedPath = $inicial }
        if ($dlg.ShowDialog($dueno) -ne [System.Windows.Forms.DialogResult]::OK) { Escribir 'CANCEL'; exit 0 }
        $ruta = $dlg.SelectedPath
    }

    Escribir $ruta
} catch {
    try { Escribir ('ERROR ' + $_.Exception.Message) } catch { }
    exit 1
}
POWERSHELL;

        // BOM UTF-8: PowerShell 5.1 lee los .ps1 sin BOM como ANSI
        return "\xEF\xBB\xBF" . str_replace(
            ['{{RESULTADO}}', '{{INICIAL}}'],
            [str_replace("'", "''", $archivoResultado), str_replace("'", "''", $carpetaInicial)],
            $plantilla
        );
    }

    /**
     * Script que lanza el selector en la sesión del usuario. Si PHP ya corre
     * en una sesión interactiva (Apache arrancado desde el panel de XAMPP)
     * lo abre directo; si corre como servicio (sesión 0) registra una tarea
     * programada interactiva — la vía soportada para mostrar una ventana en
     * el escritorio del usuario desde un servicio de Windows.
     */
    private function scriptLauncher($rutaPicker)
    {
        $plantilla = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$picker = '{{PICKER}}'

if ((Get-Process -Id $PID).SessionId -gt 0) {
    Start-Process powershell.exe -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', $picker)
    exit 0
}

# Sesion 0 (servicio): ubicar al usuario con la sesion abierta en el equipo
$usuario = (Get-CimInstance Win32_ComputerSystem).UserName
if (-not $usuario) {
    Write-Output 'No hay ningun usuario con sesion abierta en Windows.'
    exit 1
}

$accion = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $picker + '"')
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive
$ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

# Si quedo un dialogo abierto de un intento anterior, cerrarlo
try { Stop-ScheduledTask -TaskName 'XMLConcilia_SelectorCarpeta' -ErrorAction Stop } catch { }

Register-ScheduledTask -TaskName 'XMLConcilia_SelectorCarpeta' -Action $accion -Principal $principal -Settings $ajustes -Force | Out-Null
Start-ScheduledTask -TaskName 'XMLConcilia_SelectorCarpeta'
exit 0
POWERSHELL;

        return "\xEF\xBB\xBF" . str_replace('{{PICKER}}', str_replace("'", "''", $rutaPicker), $plantilla);
    }

    /**
     * Borra archivos pick_* de intentos viejos (más de una hora) en tmp/.
     */
    private function limpiarSelectorViejos($dir)
    {
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . 'pick_*') as $archivo) {
            if (is_file($archivo) && filemtime($archivo) < time() - 3600) {
                @unlink($archivo);
            }
        }
    }

    /**
     * Lee storage/correo/config.json (carpeta_destino, cedula).
     */
    private function configLocal()
    {
        if ($this->configLocalCache !== null) {
            return $this->configLocalCache;
        }

        $defaults = ['carpeta_destino' => '', 'cedula' => ''];
        $ruta = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'config.json';

        if (is_file($ruta)) {
            $data = json_decode((string) file_get_contents($ruta), true);
            if (is_array($data)) {
                return $this->configLocalCache = array_merge($defaults, $data);
            }
        }

        return $this->configLocalCache = $defaults;
    }

    private function guardarConfigLocal(array $config)
    {
        $ruta = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'config.json';
        file_put_contents($ruta, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $this->configLocalCache = null;
    }

    // ── Nombre estandarizado del archivo (regla del renombrador FE_) ──

    /**
     * Nombre del archivo para una fila de la bandeja:
     *   FE_ALIMENTOS_DEL_SUR_120726_00004354  (NC_ si es nota de crédito)
     * Proveedor sin sufijos societarios, fecha de emisión ddmmyy y número
     * corto relleno a 8 dígitos — la misma regla del renombrador FE_.
     */
    private function nombreArchivoBandeja(array $fila)
    {
        $tipo = strtoupper(trim((string) ($fila['tipo_doc'] ?? 'FE')));
        $prefijo = in_array($tipo, ['FE', 'NC', 'ND'], true) ? $tipo : 'FE';

        $tokenProv = $this->tokenProveedor((string) ($fila['proveedor'] ?? ''));

        $ts = strtotime((string) ($fila['fecha_emision'] ?? ''));
        $fechaStr = $ts !== false ? date('dmy', $ts) : '000000';

        $core = trim((string) ($fila['numero_corto'] ?? ''));
        if ($core === '') {
            $core = '0';
        }
        $numero = strlen($core) >= 8 ? $core : str_pad($core, 8, '0', STR_PAD_LEFT);

        return "{$prefijo}_{$tokenProv}_{$fechaStr}_{$numero}";
    }

    private function tokenProveedor($nombre)
    {
        $prov = mb_strtoupper(trim($nombre), 'UTF-8');
        $prov = strtr($prov, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
            'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            'Ñ' => 'N', 'Ç' => 'C',
        ]);
        $prov = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $prov) ?: $prov;
        $prov = str_replace('.', '', $prov);
        $prov = preg_replace('/[^A-Z0-9 ]/', ' ', $prov);
        $prov = preg_replace('/\s+/', ' ', trim($prov));

        $sufijos = array_flip(self::SUFIJOS_SOCIETARIOS);
        $tokens = array_values(array_filter(explode(' ', $prov), function ($t) use ($sufijos) {
            return $t !== '' && !isset($sufijos[$t]);
        }));

        $token = !empty($tokens) ? implode('_', $tokens) : 'PROVEEDOR';
        return trim(mb_substr($token, 0, 60, 'UTF-8'), '_');
    }

    // ── Utilidades ─────────────────────────────────────────────────

    /**
     * Distingue el XML del mensaje de Hacienda (aceptación/rechazo) del XML
     * de la factura. El mensaje tiene raíz MensajeHacienda (o MensajeReceptor)
     * con Clave, Mensaje (1 aceptado, 2 parcial, 3 rechazado) y DetalleMensaje.
     */
    private function clasificarXml($ruta)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($ruta);

        if ($xml === false || stripos($xml->getName(), 'mensaje') === false) {
            // Ilegible o no es mensaje: que el parser de facturas lo procese
            // (y reporte el error real si está corrupto)
            return ['tipo' => 'factura'];
        }

        return [
            'tipo' => 'mensaje_hacienda',
            'clave' => $this->nodoXml($xml, 'Clave'),
            'codigo' => (int) $this->nodoXml($xml, 'Mensaje'),
            'detalle' => $this->nodoXml($xml, 'DetalleMensaje'),
        ];
    }

    private function nodoXml(SimpleXMLElement $xml, $localName)
    {
        $nodes = $xml->xpath('//*[local-name()="' . $localName . '"]');
        return (is_array($nodes) && isset($nodes[0])) ? trim((string) $nodes[0]) : '';
    }

    /**
     * Número corto: la secuencia de dígitos más larga del texto, sin ceros a
     * la izquierda (misma regla que el renombrador FE_ de conciliación).
     */
    private function numeroCorto($valor)
    {
        preg_match_all('/\d+/', (string) $valor, $m);
        $core = '';
        foreach ($m[0] as $run) {
            $run = ltrim($run, '0');
            if (strlen($run) > strlen($core)) {
                $core = $run;
            }
        }
        return $core;
    }

    /**
     * Número corto extraído de la clave de Hacienda de 50 dígitos que
     * muchos adjuntos llevan en el nombre ("Factura#<clave>.pdf"): el
     * consecutivo vive en el offset 21 (20 dígitos) y su cola de 10 sin
     * ceros es el número de la factura. '' si el nombre no trae clave.
     */
    private function numeroDesdeClave($nombre)
    {
        if (!preg_match('/\d{50}/', (string) $nombre, $m)) {
            return '';
        }
        return ltrim(substr(substr($m[0], 21, 20), -10), '0');
    }

    /**
     * Nombre original del adjunto a partir del archivo guardado
     * (formato correo_<uniqid>__<nombre-original>).
     */
    private function nombreOriginal($ruta)
    {
        $base = basename((string) $ruta);
        $pos = strpos($base, '__');
        return $pos !== false ? substr($base, $pos + 2) : $base;
    }

    private function parseIds($valor)
    {
        if (is_array($valor)) {
            $lista = $valor;
        } else {
            $lista = explode(',', (string) $valor);
        }

        $ids = [];
        foreach ($lista as $id) {
            $id = (int) trim((string) $id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function resumenConfig($config)
    {
        if (!is_array($config)) {
            return null;
        }

        $usuario = (string) ($config['usuario'] ?? '');
        $arroba = strpos($usuario, '@');
        if ($arroba > 2) {
            $usuario = substr($usuario, 0, 2) . str_repeat('•', max(3, $arroba - 2)) . substr($usuario, $arroba);
        }

        return [
            'host' => (string) ($config['host'] ?? ''),
            'usuario' => $usuario,
            'carpeta' => (string) ($config['carpeta'] ?? 'INBOX'),
            'dias_atras' => (int) ($config['dias_atras'] ?? 14),
            'solo_no_leidos' => !empty($config['solo_no_leidos']),
        ];
    }
}
