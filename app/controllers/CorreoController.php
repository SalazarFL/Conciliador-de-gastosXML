<?php
/**
 * Controlador de captura de facturas desde el correo (IMAP).
 *
 * Flujo: los XML y PDF del buzón bajan a la bandeja (correo_bandeja), que es
 * una zona de paso. De ahí salen por tres caminos, según quién los trajo:
 *
 *   Tarea programada   La entrada automática mete al sistema lo que está
 *                      limpio, en el acto. Ver capturarMensajeAutomatico() y
 *                      app/helpers/EntradaAutomatica.php.
 *
 *   Modo Descargas     Igual, pero para un buzón entero y a petición de
 *                      alguien. Ver procesarTandaLote().
 *
 *   A mano             importar() manda las filas marcadas a la cola de
 *                      importación. Es el camino de lo que la entrada
 *                      automática apartó —rechazado por Hacienda, de otra
 *                      cédula, lo que falló— y el único si esa entrada está
 *                      apagada.
 *
 * Los PDF quedan nombrados con el número de su factura, listos para el
 * renombrador FE_.
 *
 * ── Lo que ya está cargado no vuelve a entrar ──
 *
 * En cualquiera de los tres caminos, un comprobante que ya está en el sistema
 * y conserva sus dos archivos ni siquiera llega a la Bandeja: se cuenta en
 * `ya_existentes`, se dice con su número y en qué empresa está, y la fila que
 * hubiera dejado una captura anterior del mismo correo se borra con sus
 * archivos. Antes entraba, se importaba y salía "duplicado" después de subir y
 * bajar el par por la red, que desde la pantalla se ve como un clic que no
 * hace nada.
 *
 * Al que le FALTE un archivo sí se le deja entrar: la importación lo vuelve a
 * archivar, y esa es la forma de reponer un documento perdido. Ver
 * Factura::yaCargado() y tests/CorreoRepetidaTest.php.
 */

require_once __DIR__ . '/../helpers/MailFetcher.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/RutaDocumento.php';
require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';
require_once __DIR__ . '/../helpers/XmlDocumentImporter.php';
require_once __DIR__ . '/../helpers/EntradaAutomatica.php';
require_once __DIR__ . '/../helpers/PaqueteDocumentos.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/TareaProgramada.php';

class CorreoController extends Controller
{
    /**
     * Hasta dónde mira la primera vuelta de una búsqueda de texto.
     *
     * Buscar texto libre no lo resuelve ningún índice —"un pedacito de texto
     * en algún lugar del renglón" obliga a leerlos todos— así que ese
     * recorrido crece con el buzón: hoy son 138 mil correos y con 42 buzones
     * serán casi dos millones.
     *
     * Medio año cubre de sobra lo que se persigue en un ciclo de pago
     * y es la cuarta parte del índice. Si el documento es más viejo, la
     * segunda vuelta lo encuentra igual; lo único que se paga es buscar dos
     * veces de vez en cuando, nunca una respuesta equivocada.
     */
    private const DIAS_BUSQUEDA_RECIENTE = 180;

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

    /*
     * Lo que puede tardar preguntarle al buzón por un número que el índice
     * todavía no tiene. Son presupuestos, no plazos: el recorrido se corta
     * entre carpetas y se dice que quedó a medias.
     *
     * El primero es el respaldo acotado por fecha, que sale solo mientras el
     * índice va rezagado. El segundo es el buzón entero sin filtro de fecha,
     * que ya no sale solo: lo pide quien busca, sabiendo lo que cuesta.
     */
    private const RESPALDO_SEGUNDOS = 25;
    private const RESPALDO_BUZON_SEGUNDOS = 120;

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
        // Dos modos: el trabajo correo a correo (facturas) y la descarga por
        // rango de fechas (descargas, antes "General"). El modo "notas" era un
        // sitio reservado que nunca tuvo flujo propio — las NC se procesan en
        // descargas junto con las facturas.
        $modo = strtolower(trim((string) $this->get('modo', 'facturas')));
        if ($modo === 'general') {
            $modo = 'descargas'; // enlaces guardados con el nombre anterior
        }
        if (!in_array($modo, ['facturas', 'descargas'], true)) {
            $modo = 'facturas';
        }
        // Cuentas de correo: se administran en el ⚙; la activa hace todo
        $cuentas = [];
        $cuentaActivaId = 0;
        $config = null;

        try {
            $cuentasModel = $this->loadModel('CorreoCuenta');
            $cuentasModel->seedDesdeArchivo();
            // Solo los buzones de la sociedad en curso: al cambiar de empresa
            // cambia la lista de correos con los que se puede trabajar.
            $cuentas = $cuentasModel->getVisibles();
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
        $carpetasCorreo = [];

        if ($cuentaActivaId > 0) {
            try {
                $carpetasCorreo = $this->loadModel('CorreoIndice')
                    ->setCuenta($cuentaActivaId)
                    ->listarCarpetasResumen();
                foreach ($carpetasCorreo as &$carpetaCorreo) {
                    $carpetaCorreo['nombre'] = MailFetcher::nombreLegibleEstatico(
                        (string) $carpetaCorreo['carpeta']
                    );
                    $carpetaCorreo['delimitador'] = '.';
                    $carpetaCorreo['seleccionable'] = true;
                    $carpetaCorreo['indexada'] = true;
                    $carpetaCorreo['no_leidos'] = null;
                }
                unset($carpetaCorreo);
            } catch (Throwable $e) {
                $carpetasCorreo = [];
            }
        }

        try {
            $bandejaModel = $this->loadModel('CorreoBandeja');
            $this->purgarYaExisten($bandejaModel);
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

        /*
         * Tarjeta del documento que se está buscando: si se llegó con el botón
         * "Buscar el electrónico" —del pago o de la cola de
         * seguimiento—, se muestran sus datos con flechas para recorrer esa
         * lista sin volver al otro módulo.
         *
         * Quién arma la lista es NavegacionDocumentos; acá solo se le pasa la
         * petición y con qué cargar modelos.
         */
        $navDoc = null;
        try {
            $navDoc = NavegacionDocumentos::desde(
                $_GET,
                function ($nombre) { return $this->loadModel($nombre); },
                $this->url('')
            );
        } catch (Throwable $e) {
            // Sin contexto no hay tarjeta y el buscador funciona igual. Queda
            // escrito: este catch ya se tragó una vez un método que había
            // dejado de existir, y la tarjeta no salía sin decir por qué.
            $this->registrarFallo('Tarjeta del documento en /correo', $e);
        }

        // Cuántos buzones hay en TODO el sistema, no solo en esta empresa: es
        // lo que distingue "no hay ninguno" de "los que hay atienden a otra".
        // Administrarlos es cosa de Configuración.
        $cuentasEnSistema = 0;
        try {
            $cuentasEnSistema = $cuentasModel->contarTotal();
        } catch (Throwable $e) {
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

        /*
         * El número del botón "Comprobantes sin código".
         *
         * Estos avisos ya no van a la campana —ver Notificacion::FUERA_DE_LA_CAMPANA—:
         * llegaban a decenas y tapaban lo que sí urgía. Se atienden desde aquí,
         * que es la pantalla donde se está cuando aparecen. Si la consulta falla,
         * el botón sale sin número y la pantalla entra igual.
         */
        $sinCodigoPendientes = 0;
        try {
            $sinCodigoPendientes = $this->loadModel('Notificacion')
                ->contarPorTipo('proveedor_sin_codigo');
        } catch (Throwable $e) {
        }

        $loteGeneral = null;
        try {
            $lotes = $this->loadModel('CorreoLote');
            $loteGeneral = $lotes->ultimo($cuentaActivaId);
            // Una descarga en curso de OTRA cuenta no puede desaparecer de la
            // pantalla solo porque se cambió de buzón: desde aquí se sigue
            // viendo, pausando o cancelando. Solo cede el puesto cuando la
            // cuenta activa tiene una corriendo.
            if (!$loteGeneral
                || !in_array($loteGeneral['estado'], ['pendiente', 'ejecutando'], true)) {
                $loteGeneral = $lotes->enCurso() ?: $loteGeneral;
            }
        } catch (Throwable $e) {
        }

        $this->render('correo/index', [
            'title'           => 'Correo - Nexo Fiscal',
            'imapDisponible'  => MailFetcher::extensionDisponible(),
            // Dos ausencias distintas que antes se contaban igual: no haber
            // registrado ninguna cuenta, o tenerlas todas asignadas a otra
            // empresa. La segunda decía "agrega la primera cuenta", que
            // invitaba a registrar por segunda vez un buzón que ya existía.
            'configExiste'    => !empty($cuentas),
            'hayCuentasEnSistema' => $cuentasEnSistema > 0,
            'configurado'     => MailFetcher::configurado($config),
            'configResumen'   => $this->resumenConfig($config),
            // Solo los buzones de esta empresa: son con los que se trabaja.
            // Los demás se ven y se reasignan en Configuración.
            'cuentas'         => $cuentasVista,
            'cuentaActivaId'  => $cuentaActivaId,
            'sociedadActiva'  => $sociedadActiva,
            'buscarInicial'   => trim((string) $this->get('buscar', '')),
            // Fecha de referencia del documento que manda a buscar (d/m/Y).
            // Con ella la búsqueda inicial usa el motor de la tarjeta: 15
            // días antes y después, y todo el buzón si ahí no hay nada. Es lo
            // que necesita una nota de crédito sin número propio, que solo se
            // puede buscar por proveedor y hay que acotar por fecha.
            'buscarFecha'     => preg_match('#^\d{2}/\d{2}/\d{4}$#', trim((string) $this->get('fecha', '')))
                ? trim((string) $this->get('fecha', ''))
                : '',
            'abrirCorreoUid'  => max(0, (int) $this->get('abrir_uid', 0)),
            // Llega desde el aviso de la campana: "el índice se quedó atrás"
            // sin un botón que lo intente ahora sería solo una queja.
            'forzarSync'      => !empty($this->get('sync', 0)),
            'abrirCorreoCarpeta' => mb_substr(trim((string) $this->get('abrir_carpeta', '')), 0, 255, 'UTF-8'),
            'navDoc'          => $navDoc,
            'bandeja'         => $bandeja,
            'historial'       => $historial,
            'conteo'          => $conteo,
            'carpetasCorreo'  => $carpetasCorreo,
            'modoCorreo'      => $modo,
            'loteGeneral'     => $loteGeneral,
            'sinCodigoPendientes' => $sinCodigoPendientes,
        ]);
    }

    /**
     * Lista correos del buzón con SOLO encabezados (POST, JSON).
     * 'texto' se busca por remitente, CC o asunto sobre
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
        $porPagina = 500;
        $pagina = max(1, min(10000, (int) $this->post('pagina', 1)));
        $offset = ($pagina - 1) * $porPagina;

        // La lupa de la tarjeta usa un motor propio: número de factura en
        // una ventana de 15 días antes/después. La búsqueda normal de la
        // bandeja no recibe ni hereda este contexto.
        $origenBusqueda = strtolower(trim((string) $this->post('origen_busqueda', 'bandeja')));
        $esBusquedaTarjeta = $origenBusqueda === 'tarjeta';

        /*
         * "Buscar en todo el buzón": lo manda el botón que aparece cuando la
         * búsqueda cercana no encontró nada. Recorre todas las carpetas sin
         * filtro de fecha preguntándole al servidor de correo por el número
         * dentro de cada mensaje, adjuntos incluidos. Es lo más lento que
         * hace este módulo, y por eso se pide a propósito.
         */
        $buzonCompleto = !empty($this->post('buzon_completo', 0));
        if ($buzonCompleto) {
            @set_time_limit(self::RESPALDO_BUZON_SEGUNDOS + 60);
        }
        $fechaDesdeTarjeta = '';
        $fechaHastaTarjeta = '';
        if ($esBusquedaTarjeta) {
            $fechaReferencia = trim((string) $this->post('fecha_referencia', ''));
            $fechaFactura = DateTime::createFromFormat('!d/m/Y', $fechaReferencia);
            $erroresFecha = DateTime::getLastErrors();
            if ($fechaFactura instanceof DateTime
                && ($erroresFecha === false
                    || ((int) ($erroresFecha['warning_count'] ?? 0) === 0
                        && (int) ($erroresFecha['error_count'] ?? 0) === 0))) {
                $fechaDesdeTarjeta = (clone $fechaFactura)->modify('-15 days')->format('Y-m-d');
                $fechaHastaTarjeta = (clone $fechaFactura)->modify('+15 days')->format('Y-m-d');
            }
        }

        // Dónde buscar: asunto (rápido), remitente/CC (correo del proveedor),
        // ambos, o completo incluyendo el contenido del correo (más lento)
        $ambito = (string) $this->post('ambito', 'asunto_remitente');
        if (!in_array($ambito, ['asunto', 'remitente', 'asunto_remitente', 'todo'], true)) {
            $ambito = 'asunto_remitente';
        }

        $carpeta = mb_substr(trim((string) $this->post('carpeta', '')), 0, 255, 'UTF-8');
        if (strpos($carpeta, '}') !== false || strpos($carpeta, "\0") !== false) {
            $carpeta = '';
        }

        if ($esBusquedaTarjeta) {
            // Independiente de los selectores visibles de la bandeja.
            $ambito = 'asunto_remitente';
            $carpeta = '';
            $config['dias_atras'] = 0;
        }

        $indice = $this->loadModel('CorreoIndice')->setCuenta((int) $config['cuenta_id']);
        $carpetaIndexada = $carpeta === '' || $indice->getEstadoCarpeta($carpeta) !== false;

        // Mes prioritario 'YYYY-MM' (lo manda la lupa de la tarjeta de
        // por-pagar con el mes de la factura): se busca primero SOLO en ese
        // mes — mucho más rápido — y si no hay nada se amplía a todas las
        // fechas de forma automática. Solo aplica al índice local.
        $mes = trim((string) $this->post('mes', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = '';
        }
        if ($esBusquedaTarjeta) {
            $mes = '';
        }
        $mesAplicado = '';
        $mesProbado = '';
        $respaldoBuzon = false;
        $pendientesIndice = 0;
        // El recorrido del buzón se cortó por tiempo: "sin resultados" pasa a
        // ser "hasta donde se miró, no está".
        $respaldoParcial = false;
        // Queda por mirar el buzón entero, y no se hizo porque nadie lo pidió.
        $buzonCompletoPendiente = false;
        $rangoTarjetaAplicado = false;
        $rangoTarjetaProbado = $esBusquedaTarjeta
            && $fechaDesdeTarjeta !== '' && $fechaHastaTarjeta !== '';

        if ($ambito === 'todo' || !$carpetaIndexada) {
            // Búsqueda dentro del contenido: no está en el índice local,
            // así que va al servidor IMAP carpeta por carpeta (lenta).
            @set_time_limit(180);

            $fetcher = new MailFetcher($config);

            try {
                $fetcher->conectar();
                $lista = $fetcher->listarMensajes($porPagina, $texto, $ambito, $carpeta, $offset);
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
            $enElIndice = $indice->contarTotal();
            if ($enElIndice === 0) {
                // Primer uso: construir el índice ahora
                @set_time_limit(300);
                try {
                    $this->ejecutarSincronizacion($config, $indice);
                    $enElIndice = $indice->contarTotal();
                } catch (Throwable $e) {
                    $this->json(['ok' => false, 'message' => 'No fue posible construir el índice del buzón: ' . $e->getMessage()], 500);
                }
            }

            // Una página más allá de la última se acota a la última. Nadie la
            // pide a mano, pero ?pagina=10000 en la URL sobre tres correos
            // mandaba un OFFSET 4999500 al índice, que la base recorre entero
            // para no devolver nada. El total del índice es la cota: ninguna
            // búsqueda puede devolver más filas que las que hay.
            $ultimaPagina = max(1, (int) ceil($enElIndice / $porPagina));
            if ($pagina > $ultimaPagina) {
                $pagina = $ultimaPagina;
                $offset = ($pagina - 1) * $porPagina;
            }

            $digitos = [];
            preg_match_all('/\d{4,}/', $texto, $coincidenciasNumericas);
            if (!empty($coincidenciasNumericas[0])) {
                $digitos = $coincidenciasNumericas[0];
                usort($digitos, function ($a, $b) { return strlen($b) - strlen($a); });
            }

            // La tarjeta aporta el consecutivo completo aunque en el campo se
            // vean solo sus últimos dígitos.
            $numeroContexto = mb_substr(trim((string) $this->post('numero_contexto', '')), 0, 120, 'UTF-8');
            $digitosContexto = [];
            preg_match_all('/\d{4,}/', $numeroContexto, $coincidenciasContexto);
            if (!empty($coincidenciasContexto[0])) {
                $digitosContexto = $coincidenciasContexto[0];
                usort($digitosContexto, function ($a, $b) { return strlen($b) - strlen($a); });
            }
            $terminoObjetivo = !empty($digitosContexto) ? $digitosContexto[0] : ($digitos[0] ?? '');
            $usarIndiceNumero = $ambito === 'asunto_remitente'
                && strlen($terminoObjetivo) >= 4 && strlen($terminoObjetivo) <= 20;

            /**
             * @param int|null $diasFiltro Cuántos días atrás mirar como mucho.
             *        null = lo que tenga puesto la cuenta, que es lo de
             *        siempre. Nunca amplía el límite de la cuenta: si la
             *        cuenta mira 60 días, pedir 180 sigue mirando 60. Este
             *        parámetro acota, no autoriza.
             */
            $buscarLocal = function ($mesFiltro = '', $permitirFallbackTexto = true,
                                    $fechaDesdeFiltro = '', $fechaHastaFiltro = '',
                                    $diasFiltro = null) use ($indice, $usarIndiceNumero, $terminoObjetivo, $texto, $ambito, $config, $carpeta, $porPagina, $offset) {
                $diasCuenta = (int) $config['dias_atras'];
                if ($diasFiltro === null) {
                    $dias = $diasCuenta;
                } elseif ($diasCuenta <= 0) {
                    // La cuenta no acota: manda la ventana pedida.
                    $dias = (int) $diasFiltro;
                } else {
                    $dias = min($diasCuenta, (int) $diasFiltro);
                }
                if ($usarIndiceNumero) {
                    $resultado = $indice->buscarPorNumero(
                        $terminoObjetivo,
                        $dias,
                        $porPagina,
                        $mesFiltro,
                        $carpeta,
                        $offset,
                        $fechaDesdeFiltro,
                        $fechaHastaFiltro
                    );
                    if ((int) $resultado['total'] > 0 || !$permitirFallbackTexto) {
                        return $resultado;
                    }
                }
                return $indice->buscar(
                    $texto,
                    $ambito,
                    $dias,
                    $porPagina,
                    $mesFiltro,
                    $carpeta,
                    $offset,
                    $fechaDesdeFiltro,
                    $fechaHastaFiltro
                );
            };

            $listaMes = null;
            $listaRango = null;
            if ($rangoTarjetaProbado) {
                $lista = $buscarLocal('', false, $fechaDesdeTarjeta, $fechaHastaTarjeta);
                $listaRango = $lista;
                $rangoTarjetaAplicado = (int) $lista['total'] > 0;
            } elseif ($mes !== '') {
                $mesProbado = $mes;
                // Para una búsqueda numérica, no aceptar aquí coincidencias
                // parciales del adjunto. Por ejemplo, buscar 64291 en julio
                // puede encontrar ...2264291..., aunque la factura exacta haya
                // llegado al correo el 30 de junio. Si el número exacto no está
                // en el mes sugerido, primero se amplía a todo el buzón.
                $lista = $buscarLocal($mes, false);
                $listaMes = $lista;
                if ((int) $lista['total'] > 0) {
                    $mesAplicado = $mes;
                }
            }
            if (!$rangoTarjetaProbado && !$rangoTarjetaAplicado && $mesAplicado === '') {
                /*
                 * La tarjeta sin resultados cercanos amplía automáticamente
                 * a todo el buzón. La bandeja llega aquí desde el inicio y
                 * conserva sus filtros de días/carpeta/ámbito.
                 *
                 * Pero primero medio año, y solo después el índice entero. Una
                 * búsqueda de texto sin fecha tiene que recorrer todos los
                 * renglones —no hay índice que resuelva "un pedacito de texto
                 * en algún lugar del renglón"— y ese recorrido crece con el
                 * buzón: con 42 buzones son casi dos millones de correos.
                 *
                 * Medio año cubre de sobra lo que se persigue en un ciclo de
                 * pago, y es una cuarta parte del índice. Cuando el
                 * documento es más viejo, la segunda vuelta lo encuentra
                 * igual: nunca se contesta "no está" por haber mirado poco,
                 * solo se paga dos veces de vez en cuando.
                 */
                $diasCuenta = (int) $config['dias_atras'];
                $laVentanaAcota = $diasCuenta <= 0 || $diasCuenta > self::DIAS_BUSQUEDA_RECIENTE;

                $lista = $buscarLocal('', true, '', '', self::DIAS_BUSQUEDA_RECIENTE);
                if ($laVentanaAcota && (int) $lista['total'] === 0) {
                    $lista = $buscarLocal();
                }
            }

            // Mientras queden nombres de adjuntos pendientes, una búsqueda
            // numérica local puede omitir facturas cuyo número solo aparece en
            // filename= del XML/PDF. TEXT consulta también los encabezados MIME.
            // Se acota al mes de la tarjeta o al rango elegido para evitar un
            // escaneo lento de todo el buzón.
            $coincidenciaLocalObjetivo = false;
            if ($terminoObjetivo !== '') {
                // Si el mes no dio resultados, $lista ya contiene la búsqueda
                // ampliada a todas las fechas y también debe validarse.
                $correosComprobar = $lista['correos'];
                foreach ($correosComprobar as $correoComprobar) {
                    $textoIndexado = implode(' ', [
                        $correoComprobar['asunto'] ?? '',
                        $correoComprobar['remitente'] ?? '',
                        $correoComprobar['cc'] ?? '',
                        $correoComprobar['reply_to'] ?? '',
                        $correoComprobar['adjuntos'] ?? '',
                    ]);
                    if (stripos($textoIndexado, $terminoObjetivo) !== false) {
                        $coincidenciaLocalObjetivo = true;
                        break;
                    }
                }
            }

            // Sin contexto de tarjeta, un resultado local ya es suficiente;
            // el respaldo se reserva para búsquedas sin ninguna coincidencia.
            if (empty($digitosContexto) && (int) $lista['total'] > 0) {
                $coincidenciaLocalObjetivo = true;
            }

            $respaldoAcotado = $rangoTarjetaProbado || $mes !== '' || (int) $config['dias_atras'] > 0;
            $pendientesIndice = $indice->contarPendientesAdjuntos();

            /*
             * Preguntarle al buzón, no al índice. Pasa por sí solo mientras al
             * índice le falten adjuntos por leer —el número puede vivir solo
             * en el nombre de un XML— y siempre que se pida el buzón entero a
             * propósito, que es la única forma de llegar al cuerpo del correo.
             */
            if ($ambito === 'asunto_remitente' && $terminoObjetivo !== ''
                && ($buzonCompleto
                    || (!$coincidenciaLocalObjetivo && $respaldoAcotado && $pendientesIndice > 0))) {
                // Búsqueda TEXT contra el buzón, carpeta por carpeta: la
                // resuelve el servidor de correo y es la parte lenta. Va
                // acotada por fecha y con presupuesto de tiempo, y se avisa
                // en la respuesta de hasta dónde se llegó.
                $respaldoBuzon = true;

                /*
                 * El buzón entero, sin filtro de fecha y en todas las
                 * carpetas: un TEXT contra decenas de miles de correos que se
                 * mide en minutos. Es lo que antes salía solo y terminaba,
                 * después de la espera, diciendo "sin resultados". Ahora lo
                 * pide quien busca, desde el panel que le dice qué falta por
                 * mirar; el presupuesto de tiempo lo corta si se pasa.
                 */
                $barrerBuzonEntero = function () use (
                    $config, $terminoObjetivo, $porPagina, $offset, $indice, &$respaldoParcial
                ) {
                    $configTodo = $config;
                    $configTodo['dias_atras'] = 0;
                    unset($configTodo['fecha_desde'], $configTodo['fecha_hasta']);
                    $encontrados = $this->buscarNumeroEnMime(
                        $configTodo, $terminoObjetivo, '',
                        $porPagina, $offset, $indice, self::RESPALDO_BUZON_SEGUNDOS
                    );
                    $respaldoParcial = $respaldoParcial || !empty($encontrados['parcial']);
                    return $encontrados;
                };

                $mimeAmplio = false;
                if ($buzonCompleto) {
                    // Se pidió el buzón entero: la búsqueda acotada por fecha
                    // es un pedazo de esta, así que no se hace antes. El
                    // índice sí se vuelve a mirar sin el rango de la tarjeta.
                    if ($rangoTarjetaProbado) {
                        $lista = $buscarLocal();
                        $mimeAmplio = true;
                    }
                    $listaMime = $barrerBuzonEntero();
                } else {
                    // El respaldo de siempre, acotado a las fechas en las que
                    // esa factura pudo llegar.
                    $configMime = $config;
                    if ($rangoTarjetaProbado) {
                        $configMime['dias_atras'] = 0;
                        $configMime['fecha_desde'] = $fechaDesdeTarjeta;
                        // MailFetcher usa BEFORE (límite exclusivo).
                        $configMime['fecha_hasta'] = date(
                            'Y-m-d',
                            strtotime($fechaHastaTarjeta . ' +1 day')
                        );
                    } elseif ($mes !== '') {
                        $inicioMes = strtotime($mes . '-01 00:00:00');
                        $configMime['dias_atras'] = 0;
                        $configMime['fecha_desde'] = date('Y-m-d', $inicioMes);
                        $configMime['fecha_hasta'] = date('Y-m-d', strtotime('+1 month', $inicioMes));
                    }

                    $listaMime = $this->buscarNumeroEnMime(
                        $configMime, $terminoObjetivo, $carpeta,
                        $porPagina, $offset, $indice, self::RESPALDO_SEGUNDOS
                    );
                    $respaldoParcial = !empty($listaMime['parcial']);

                    // La tarjeta agota primero lo cercano a la fecha de la
                    // factura —índice y buzón— antes de mirar más lejos.
                    if ($rangoTarjetaProbado && (int) $listaMime['total'] === 0) {
                        $lista = $buscarLocal();
                        $listaMime = ['total' => 0, 'correos' => []];
                        $mimeAmplio = true;
                    }
                }

                // Si el respaldo encontró algo en el mes prioritario, no se
                // mezclan resultados locales de otras fechas.
                if ($mes !== '' && (int) $listaMime['total'] > 0 && $listaMes !== null) {
                    $lista = $listaMes;
                    $mesAplicado = $mes;
                }
                if ($rangoTarjetaProbado && !$mimeAmplio
                    && (int) $listaMime['total'] > 0 && $listaRango !== null) {
                    $lista = $listaRango;
                    $rangoTarjetaAplicado = true;
                }

                $unicos = [];
                foreach (array_merge($lista['correos'], $listaMime['correos']) as $correo) {
                    $llave = (string) ($correo['carpeta'] ?? '') . ':' . (int) ($correo['uid'] ?? 0);
                    $unicos[$llave] = $correo;
                }
                $lista['correos'] = array_values($unicos);
                usort($lista['correos'], function ($a, $b) {
                    return (int) ($b['timestamp'] ?? 0) - (int) ($a['timestamp'] ?? 0);
                });
                $lista['correos'] = array_slice($lista['correos'], 0, $porPagina);
                $lista['total'] = max((int) $lista['total'], count($lista['correos']));
            }

            // No había metadatos pendientes que exigieran IMAP, o tampoco
            // hubo coincidencias MIME cercanas: completar la segunda etapa
            // de la tarjeta contra todo el índice.
            if ($rangoTarjetaProbado && !$rangoTarjetaAplicado
                && (int) ($lista['total'] ?? 0) === 0) {
                $lista = $buscarLocal();
            }

            // Una reconstrucción interrumpida pudo dejar temporalmente varias
            // generaciones del mismo mensaje con UID distintos. Se conserva
            // la fila más reciente (la consulta viene ordenada por id DESC).
            $antesDeduplicar = count($lista['correos']);
            $lista['correos'] = $this->deduplicarCorreosBusqueda($lista['correos']);
            $eliminados = $antesDeduplicar - count($lista['correos']);
            if ($eliminados > 0) {
                $lista['total'] = max(count($lista['correos']), (int) $lista['total'] - $eliminados);
            }

            /*
             * No apareció. Lo único que queda por levantar es el buzón entero
             * —todas las carpetas, sin filtro de fecha y mirando dentro de
             * cada correo—, y se ofrece en vez de hacerlo: cuesta minutos y
             * casi siempre termina confirmando que el correo no llegó. Antes
             * salía solo, y esa espera terminaba en "sin resultados" sin que
             * nadie hubiera decidido pagarla.
             */
            if (!$buzonCompleto && $terminoObjetivo !== '' && (int) $lista['total'] === 0) {
                $buzonCompletoPendiente = true;
            }

            $fuente = 'indice';
            $ultimaSync = $indice->ultimaSync();
        }

        $this->json([
            'ok' => true,
            'total' => (int) $lista['total'],
            'mostrados' => count($lista['correos']),
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => max(1, (int) ceil((int) $lista['total'] / $porPagina)),
            'hay_anterior' => $pagina > 1,
            'hay_siguiente' => $offset + count($lista['correos']) < (int) $lista['total'],
            'dias' => (int) $config['dias_atras'],
            'texto' => $texto,
            'carpeta' => $carpeta,
            'mes' => $mesAplicado !== '' ? $mesAplicado : null,
            'mes_probado' => $mesProbado !== '' ? $mesProbado : null,
            'origen_busqueda' => $esBusquedaTarjeta ? 'tarjeta' : 'bandeja',
            'fecha_desde' => $rangoTarjetaProbado ? $fechaDesdeTarjeta : null,
            'fecha_hasta' => $rangoTarjetaProbado ? $fechaHastaTarjeta : null,
            'rango_aplicado' => $rangoTarjetaAplicado,
            'ampliado_todo' => $rangoTarjetaProbado && !$rangoTarjetaAplicado,
            'fuente' => $fuente,
            // El resultado vino de preguntarle al buzón porque el índice
            // todavía no tiene los nombres de los adjuntos: explica la espera.
            'respaldo_buzon' => $respaldoBuzon,
            'pendientes_indice' => $pendientesIndice,
            // El recorrido del buzón se quedó a medias por tiempo: lo que
            // sigue sin aparecer puede estar en lo que no se alcanzó a mirar.
            'respaldo_parcial' => $respaldoParcial,
            // Falta el buzón entero, y está ahí para pedirlo: es lo que la
            // pantalla ofrece en vez de hacer esperar minutos por las dudas.
            'buzon_completo_pendiente' => $buzonCompletoPendiente,
            'buzon_completo' => $buzonCompleto,
            'ultima_sync' => $ultimaSync,
            'correos' => $lista['correos'],
        ]);
    }

    /**
     * Carpetas donde tiene sentido buscar dentro del rango de fechas pedido.
     *
     * El respaldo contra el buzón es una búsqueda TEXT (encabezados y cuerpo)
     * y el servidor la resuelve leyendo mensajes: recorrer las 150 carpetas
     * del archivo para encontrar una factura de los últimos 60 días tardaba
     * cerca de un minuto. El índice ya sabe qué carpetas tienen correo en ese
     * rango; las demás no pueden tener nada. Las que nunca se indexaron se
     * incluyen igual, porque de esas el índice no puede opinar.
     *
     * Devuelve [] cuando la búsqueda no tiene fecha de corte: ahí sí hay que
     * mirar el buzón entero.
     */
    private function carpetasCandidatas($fetcher, $indice, array $config)
    {
        $desde = 0;
        $fechaDesde = trim((string) ($config['fecha_desde'] ?? ''));
        if ($fechaDesde !== '' && strtotime($fechaDesde) !== false) {
            $desde = (int) strtotime($fechaDesde . ' 00:00:00');
        } elseif ((int) ($config['dias_atras'] ?? 0) > 0) {
            $dias = (int) $config['dias_atras'];
            $desde = (int) strtotime(date('Y-m-d 00:00:00', strtotime("-{$dias} days")));
        }
        if ($desde <= 0) {
            return [];
        }

        try {
            $conCorreo = $indice->carpetasConMensajesDesde($desde);
            $indexadas = $indice->getCarpetas();
            $candidatas = [];
            foreach ($fetcher->carpetasABuscar() as $carpeta) {
                if (isset($conCorreo[$carpeta]) || !isset($indexadas[$carpeta])) {
                    $candidatas[] = $carpeta;
                }
            }
            return $candidatas;
        } catch (Throwable $e) {
            return []; // ante la duda, el buzón entero: lento pero completo
        }
    }

    /**
     * Busca un número en encabezados/cuerpo MIME e hidrata sus adjuntos.
     *
     * $segundos es el presupuesto: esta búsqueda la resuelve el servidor de
     * correo carpeta por carpeta y sobre un buzón grande no termina en un
     * tiempo que nadie esté dispuesto a esperar mirando una pantalla.
     */
    private function buscarNumeroEnMime(array $config, $numero, $carpeta, $limite, $offset, $indice, $segundos = 0)
    {
        $fetcher = new MailFetcher($config);
        try {
            $fetcher->conectar();
            $lista = $fetcher->listarMensajes(
                (int) $limite,
                (string) $numero,
                'texto_mime',
                $carpeta !== '' ? (string) $carpeta : $this->carpetasCandidatas($fetcher, $indice, $config),
                (int) $offset,
                (int) $segundos
            );

            // Completar inmediatamente el índice de las coincidencias para
            // que las próximas búsquedas del mismo número sean locales.
            $tope = min(50, count($lista['correos'] ?? []));
            for ($i = 0; $i < $tope; $i++) {
                $correo = &$lista['correos'][$i];
                $nombres = $fetcher->nombresAdjuntos(
                    (int) ($correo['uid'] ?? 0),
                    (string) ($correo['carpeta'] ?? '')
                );
                $textoAdjuntos = implode(' ', $nombres);
                $indice->guardarAdjuntosPorMensaje(
                    (string) ($correo['carpeta'] ?? ''),
                    (int) ($correo['uid'] ?? 0),
                    $textoAdjuntos
                );
                $correo['adjuntos'] = $textoAdjuntos;
            }
            unset($correo);

            return $lista;
        } catch (Throwable $e) {
            // El índice local sigue siendo utilizable si IMAP falla o el
            // servidor no soporta la búsqueda TEXT.
            return ['total' => 0, 'correos' => [], 'parcial' => false];
        } finally {
            $fetcher->cerrar();
        }
    }

    /**
     * Deduplicación defensiva de copias del mismo mensaje dentro de una
     * carpeta. No mezcla correos iguales archivados en carpetas distintas.
     */
    private function deduplicarCorreosBusqueda(array $correos)
    {
        $porLlave = [];
        foreach ($correos as $posicion => $correo) {
            $timestamp = (int) ($correo['timestamp'] ?? 0);
            if ($timestamp <= 0) {
                $llave = (string) ($correo['carpeta'] ?? '') . ':uid:' . (int) ($correo['uid'] ?? 0);
            } else {
                $normalizar = function ($valor) {
                    $valor = mb_strtolower(trim((string) $valor), 'UTF-8');
                    return preg_replace('/\s+/u', ' ', $valor);
                };
                $llave = implode('|', [
                    (string) ($correo['carpeta'] ?? ''),
                    (string) $timestamp,
                    $normalizar($correo['remitente'] ?? ''),
                    $normalizar($correo['asunto'] ?? ''),
                ]);
            }
            $porLlave[$llave][] = $posicion;
        }

        $conservadas = [];
        foreach ($porLlave as $posiciones) {
            if (count($posiciones) === 1) {
                $conservadas[] = $posiciones[0];
                continue;
            }

            // Varias filas con la misma carpeta, segundo, remitente y asunto.
            // Si comparten la numeración del buzón (UIDVALIDITY) son correos
            // DISTINTOS: los sistemas de facturación mandan ráfagas —tres
            // "Comprobante electrónico" a las 10:00:00, uno por factura— y
            // colapsarlas escondía dos facturas. Lo que sí es el mismo correo
            // repetido es la misma fila vista con dos numeraciones, porque el
            // buzón renumeró y el índice guardó ambas.
            $porNumeracion = [];
            foreach ($posiciones as $posicion) {
                $porNumeracion[$this->uidvalidityDeCorreo($correos[$posicion])][] = $posicion;
            }
            // Sin numeración conocida (0) no hay con qué distinguirlos: se
            // tratan como el mismo correo repetido, que es lo que se veía
            // antes de que el índice guardara la clave.
            if (isset($porNumeracion[0])) {
                $porNumeracion[0] = array_slice($porNumeracion[0], 0, 1);
            }
            krsort($porNumeracion, SORT_NUMERIC); // a igualdad, la numeración más reciente

            $elegidas = [];
            foreach ($porNumeracion as $grupo) {
                if (count($grupo) > count($elegidas)) {
                    $elegidas = $grupo;
                }
            }
            foreach ($elegidas as $posicion) {
                $conservadas[] = $posicion;
            }
        }

        sort($conservadas); // el orden en que venían
        $resultado = [];
        foreach ($conservadas as $posicion) {
            $resultado[] = $correos[$posicion];
        }
        return $resultado;
    }

    /** La numeración del buzón a la que pertenece un correo del índice. */
    private function uidvalidityDeCorreo(array $correo)
    {
        if (isset($correo['uidvalidity'])) {
            return (int) $correo['uidvalidity'];
        }
        // La clave es "c{cuenta}:{uidvalidity}:{uid}" (MailFetcher::claveMensaje).
        $partes = explode(':', (string) ($correo['clave'] ?? ''));
        return count($partes) >= 3 ? (int) $partes[count($partes) - 2] : 0;
    }

    /** Lista las carpetas IMAP para el navegador lateral. */
    public function carpetasBuzon()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();
        $indice = $this->loadModel('CorreoIndice')->setCuenta((int) $config['cuenta_id']);
        $estados = $indice->getCarpetas();
        $fetcher = new MailFetcher($config);

        try {
            $fetcher->conectar();
            $carpetas = $fetcher->listarCarpetasCorreo();
        } catch (Throwable $e) {
            $fetcher->cerrar();
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
        $fetcher->cerrar();

        foreach ($carpetas as &$carpeta) {
            $ruta = (string) $carpeta['carpeta'];
            $estado = $estados[$ruta] ?? null;
            $carpeta['indexada'] = $estado !== null;
            $carpeta['mensajes'] = $estado !== null ? (int) $estado['mensajes'] : null;
        }
        unset($carpeta);

        $this->json(['ok' => true, 'carpetas' => $carpetas]);
    }

    /**
     * Sincroniza el índice local con el buzón en tandas cortas.
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
            // El módulo de correo dejó de tener dónde enseñar esto: va a la
            // campana, que es donde se mira aunque nadie tenga Correo abierto.
            require_once __DIR__ . '/../helpers/CorreoSync.php';
            CorreoSync::anotarFallo($e->getMessage(), $config);
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualiza el índice local carpeta por carpeta hasta agotar el
     * presupuesto de tiempo. La lógica vive en CorreoSync para que la
     * compartan el navegador (esta ruta AJAX) y la tarea programada
     * (cli/sync_correo.php), que mantiene el índice al día aunque el
     * módulo esté cerrado.
     *
     * Solo corre UNA sincronización a la vez POR BUZÓN (mismo candado que el
     * CLI): con varios usuarios abriendo el módulo, el primero sincroniza ese
     * buzón y los demás siguen leyendo el índice sin abrir IMAP. Dos usuarios
     * mirando buzones distintos sí sincronizan a la vez, que es el punto.
     */
    private function ejecutarSincronizacion(array $config, $indice, $presupuestoSegundos = 20)
    {
        require_once __DIR__ . '/../helpers/CorreoSync.php';

        $lock = CorreoSync::adquirirLock((int) ($config['cuenta_id'] ?? 0));
        if ($lock === null) {
            /*
             * Otro usuario o el cron ya están sincronizando ESTE buzón, o hay
             * un mantenimiento en curso: no se duplica la conexión.
             * completado=true corta el ciclo de tandas del JS.
             *
             * La cola sí se cuenta —es una consulta al índice, no al buzón—
             * porque de ella depende que la búsqueda tenga que ir hasta el
             * servidor de correo. Devolver cero mientras hay rezago dejaba a
             * la pantalla sin poder explicar por qué la búsqueda tardaba.
             */
            return [
                'carpetas' => 0, 'nuevos' => 0, 'reindexadas' => 0, 'restantes' => 0,
                'carpetas_totales' => 0, 'carpetas_por_revisar' => 0,
                'metadatos_resueltos' => 0,
                'metadatos_pendientes' => $indice->contarPendientesAdjuntos(),
                'adjuntos' => 0, 'cc' => 0, 'completado' => true, 'en_curso' => true, 'segundos' => 0,
            ];
        }

        try {
            return CorreoSync::ejecutar($config, $indice, $presupuestoSegundos);
        } finally {
            CorreoSync::liberarLock($lock);
        }
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

        // Desde la bandeja de revisión llega la fila, no el correo: de qué
        // buzón, carpeta y UID salió se averigua aquí.
        $bandejaId = (int) $this->post('bandeja_id', 0);
        $origen = $bandejaId > 0 ? $this->correoDeLaBandeja($bandejaId) : null;

        $config = $origen ? $origen['config'] : $this->configListoOFallar();

        $uid = $origen ? $origen['uid'] : (int) $this->post('uid', 0);
        $carpeta = $origen ? $origen['carpeta'] : (string) $this->post('carpeta', '');
        if ($uid <= 0) {
            $this->json(['ok' => false, 'message' => 'Correo inválido.'], 422);
        }

        @set_time_limit(60);

        $fetcher = new MailFetcher($config);

        try {
            $fetcher->conectar();
            if ($origen) {
                // El UID solo vale mientras la carpeta conserve su numeración.
                // Si el servidor la rehízo, ese número ya es de otro mensaje.
                $estado = $fetcher->estadoCarpeta($carpeta);
                if (!$estado || (int) $estado['uidvalidity'] !== $origen['uidvalidity']) {
                    throw new RuntimeException(self::CORREO_DE_BANDEJA_PERDIDO);
                }
            }
            $cuerpo = $fetcher->obtenerCuerpo($uid, $carpeta);
            $adjuntos = $fetcher->listarAdjuntos($uid, $carpeta);
            // Una fila de la bandeja salió de un adjunto: si el mensaje ya no
            // trae ninguno es que no está ahí (lo movieron o lo borraron).
            if ($origen && $cuerpo === '' && empty($adjuntos)) {
                throw new RuntimeException(self::CORREO_DE_BANDEJA_PERDIDO);
            }
            $nombresAdjuntos = array_values(array_map(function ($adjunto) {
                return (string) ($adjunto['nombre'] ?? '');
            }, $adjuntos));
            $destinatarios = $fetcher->destinatariosDeMensaje($uid, $carpeta);

            // Abrir el correo también completa su entrada del índice, de modo
            // que futuras búsquedas por archivo, CC o Reply-To sean locales.
            $indiceContenido = $this->loadModel('CorreoIndice')->setCuenta((int) $config['cuenta_id']);
            $indiceContenido->guardarAdjuntosPorMensaje($carpeta, $uid, implode(' ', $nombresAdjuntos));
            if ($destinatarios !== null) {
                $indiceContenido->guardarDestinatariosPorMensaje(
                    $carpeta,
                    $uid,
                    $destinatarios['cc'],
                    $destinatarios['reply_to']
                );
            }
        } catch (Throwable $e) {
            $fetcher->cerrar();
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $fetcher->cerrar();

        $this->json([
            'ok' => true,
            'uid' => $uid,
            'carpeta' => $carpeta,
            'cuenta_id' => (int) $config['cuenta_id'],
            'adjuntos' => $adjuntos,
            'cc' => $destinatarios['cc'] ?? '',
            'reply_to' => $destinatarios['reply_to'] ?? '',
            'cuerpo' => $cuerpo !== '' ? $cuerpo : '(Este correo no tiene texto legible; su contenido puede estar solo en los adjuntos.)',
        ]);
    }

    private const CORREO_DE_BANDEJA_PERDIDO = 'Este correo ya no está donde se capturó: '
        . 'lo movieron o lo borraron del buzón. Los archivos de la fila siguen en la bandeja.';

    /**
     * El correo del que salió una fila de la bandeja: buzón, carpeta y UID.
     * Responde el error en JSON si no se puede ubicar (json() corta).
     *
     * La fila guarda la clave del mensaje, "c{cuenta}:{uidvalidity}:{uid}"
     * (MailFetcher::claveMensaje). El buzón sale de ahí y no de la cuenta
     * activa: la bandeja es de la empresa, y a una empresa la pueden atender
     * varios buzones.
     */
    private function correoDeLaBandeja($bandejaId)
    {
        $fila = $this->loadModel('CorreoBandeja')->getActivaPorId($bandejaId);
        if ($fila === null) {
            $this->json(['ok' => false, 'message' => 'Esa fila ya no está en la bandeja. Recarga la página.'], 404);
        }

        if (!preg_match('/^(?:c(\d+):)?(\d+):(\d+)$/', (string) ($fila['uid_correo'] ?? ''), $m)) {
            $this->json(['ok' => false, 'message' => 'Esta fila no guarda de qué correo salió.'], 422);
        }
        $cuentaId = $m[1] !== '' ? (int) $m[1] : (int) ($fila['cuenta_id'] ?? 0);

        $config = $this->configListoOFallar($cuentaId);

        $carpeta = $this->loadModel('CorreoIndice')
            ->setCuenta((int) $config['cuenta_id'])
            ->ubicarMensaje((int) $m[2], (int) $m[3], (string) ($fila['asunto'] ?? ''));
        if ($carpeta === null) {
            $this->json([
                'ok' => false,
                'message' => 'No se encontró este correo en el índice del buzón: lo movieron, lo borraron '
                    . 'o el buzón todavía no terminó de sincronizar. Los archivos de la fila siguen en la bandeja.',
            ], 404);
        }

        return [
            'config' => $config,
            'carpeta' => $carpeta,
            'uidvalidity' => (int) $m[2],
            'uid' => (int) $m[3],
        ];
    }

    /**
     * Transmite un único PDF/XML desde IMAP para visualizarlo. No crea
     * archivos temporales, no persiste el contenido y no modifica la BD.
     */
    public function adjunto()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $config = $this->configListoOFallar();
        $uid = (int) $this->post('uid', 0);
        $carpeta = (string) $this->post('carpeta', '');
        $seccion = (string) $this->post('seccion', '');

        if ($uid <= 0 || !preg_match('/^\d+(?:\.\d+)*$/', $seccion)) {
            $this->json(['ok' => false, 'message' => 'Adjunto inválido.'], 422);
        }

        @set_time_limit(90);
        $fetcher = new MailFetcher($config);

        try {
            $fetcher->conectar();
            $archivo = $fetcher->obtenerAdjuntoParaVista($uid, $carpeta, $seccion);
        } catch (Throwable $e) {
            $fetcher->cerrar();
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $fetcher->cerrar();

        $nombre = str_replace(["\r", "\n", "\0"], '', (string) $archivo['nombre']);
        $nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre);
        if ($nombreAscii === '' || $nombreAscii === null) {
            $nombreAscii = $archivo['tipo_vista'] === 'pdf' ? 'documento.pdf' : 'documento.xml';
        }

        header('Content-Type: ' . $archivo['mime']);
        header('Content-Length: ' . strlen($archivo['contenido']));
        header('Content-Disposition: inline; filename="' . $nombreAscii
            . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');

        echo $archivo['contenido'];
        exit;
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
        $sinAdjuntos = 0;
        $nuevas = 0;
        $notasNuevas = 0;
        $yaExistentes = 0;
        $aceptadas = 0;
        $rechazadas = 0;
        $otraCedula = 0;
        $pdfsGuardados = 0;
        $pdfsSinIdentificar = 0;
        $errores = [];
        $repetidas = [];

        foreach ($lote as $item) {
            $uid = $item['uid'];
            try {
                $mensaje = $fetcher->extraerMensaje($uid, $item['carpeta']);

                if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                    $sinAdjuntos++;
                    $procesados++;
                    continue;
                }

                // Facturas y notas de crédito, igual que la captura
                // automática: las dos esperan en la Bandeja y se importan
                // desde ahí. Lo que no entra nunca —notas de débito, mensajes
                // de Hacienda— lo aparta procesarMensaje, venga de donde venga.
                $resultado = $this->procesarMensaje(
                    $mensaje, $bandejaModel, $facturaModel, (int) $config['cuenta_id'],
                    EntradaAutomatica::TIPOS
                );
                $nuevas += $resultado['nuevas'];
                $notasNuevas += (int) ($resultado['notas_nuevas'] ?? 0);
                $yaExistentes += $resultado['ya_existentes'];
                $aceptadas += $resultado['aceptadas'];
                $rechazadas += $resultado['rechazadas'];
                $otraCedula += $resultado['otra_cedula'];
                $pdfsGuardados += $resultado['pdfs_guardados'];
                $pdfsSinIdentificar += $resultado['pdfs_sin_identificar'];
                foreach ($resultado['errores'] as $err) {
                    $errores[] = $err;
                }
                foreach ($resultado['repetidas'] as $rep) {
                    $repetidas[] = $rep;
                }

                $procesados++;
            } catch (Throwable $e) {
                $errores[] = 'Correo UID ' . (int) $uid . ': ' . $e->getMessage();
            }
        }

        $fetcher->cerrar();

        $this->json([
            'ok' => true,
            'procesados' => $procesados,
            'sin_adjuntos' => $sinAdjuntos,
            'nuevas' => $nuevas,
            // De las nuevas, cuántas son notas de crédito (el resto, facturas).
            'notas_nuevas' => $notasNuevas,
            'ya_existentes' => $yaExistentes,
            'aceptadas' => $aceptadas,
            'rechazadas' => $rechazadas,
            'otra_cedula' => $otraCedula,
            'pdfs_guardados' => $pdfsGuardados,
            'pdfs_sin_identificar' => $pdfsSinIdentificar,
            'errores' => array_slice($errores, 0, 10),
            'repetidas' => array_slice($repetidas, 0, 20),
        ]);
    }

    public function generalEstimar()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        try {
            $cuentaId = (int) $this->post('cuenta_id', 0);
            $config = $this->configCuenta($cuentaId);
            if (!$config) {
                throw new InvalidArgumentException('La cuenta seleccionada no está configurada.');
            }
            $cuentaId = (int) ($config['cuenta_id'] ?? $cuentaId);
            [$desde, $hasta] = $this->rangoGeneral();
            $correo = $this->correoBusquedaGeneral();

            $conteo = $this->loadModel('CorreoLote')->estimar($cuentaId, $desde, $hasta, $correo);

            // Sin el buzón leído entero no hay lote, y el conteo de un índice
            // a medias también sale a medias: en vez del número, lo que falta.
            // Va después de contar porque la comprobación sale a la red y el
            // conteo no: si la base falla, que falle sin haberla esperado.
            $bloqueo = $this->buzonSinTerminarDeLeer($config, $desde);
            if ($bloqueo !== '') {
                $this->json(['ok' => true, 'bloqueo' => $bloqueo]);
            }

            $this->json([
                'ok' => true,
                'total' => $conteo['total'],
                'procesados' => $conteo['procesados'],
                'nuevos' => $conteo['nuevos'],
                'fecha_desde' => $desde,
                'fecha_hasta' => $hasta,
                'correo_busqueda' => $correo,
            ]);
        } catch (Throwable $e) {
            $this->responderFallo('estimar la descarga masiva de correo', $e);
        }
    }

    public function generalCrear()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        try {
            $cuentaId = (int) $this->post('cuenta_id', 0);
            $config = $this->configCuenta($cuentaId);
            if (!$config || !MailFetcher::configurado($config)) {
                throw new InvalidArgumentException('La cuenta seleccionada no está configurada.');
            }
            $cuentaId = (int) ($config['cuenta_id'] ?? $cuentaId);
            [$desde, $hasta] = $this->rangoGeneral();
            $correo = $this->correoBusquedaGeneral();
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) { throw new InvalidArgumentException('Selecciona una sociedad activa antes de iniciar.'); }
            // Los XML y PDF van a SharePoint: sin sus credenciales en esta
            // computadora, mejor decirlo antes de crear el lote que a la
            // mitad, con la mitad del buzón ya leído.
            if (!AlmacenDocumentos::hayPrincipal()) {
                throw new InvalidArgumentException(AlmacenDocumentos::SIN_SHAREPOINT);
            }
            // La columna carpeta_raiz del lote queda vacía: ya no hay carpeta
            // de esta computadora que anotar.
            $raiz = '';

            // Actualización breve y best-effort; el lote también incluye los
            // mensajes cuyo detalle de adjuntos siga pendiente en el índice.
            try {
                $indice = $this->loadModel('CorreoIndice')->setCuenta($cuentaId);
                $this->ejecutarSincronizacion($config, $indice, 15);
            } catch (Throwable $e) {
            }

            // La misma barrera que al estimar, y la que manda: la pantalla la
            // enseña antes, pero el lote no se arma sin pasar por acá.
            $bloqueo = $this->buzonSinTerminarDeLeer($config, $desde);
            if ($bloqueo !== '') {
                throw new InvalidArgumentException($bloqueo);
            }

            // Por defecto se saltan los correos ya procesados en corridas
            // anteriores. La casilla "volver a revisar todo" los reincorpora
            // (p. ej. tras corregir el parser o recuperar documentos).
            $incluirProcesados = in_array(
                strtolower(trim((string) $this->post('incluir_procesados', ''))),
                ['1', 'true', 'on', 'si', 'sí'],
                true
            );

            $lote = $this->loadModel('CorreoLote')->crear([
                'cuenta_id' => $cuentaId,
                'sociedad_id' => (int) $sociedad['id'],
                'fecha_desde' => $desde,
                'fecha_hasta' => $hasta,
                'carpeta_raiz' => $raiz,
                'correo_busqueda' => $correo,
                'incluir_procesados' => $incluirProcesados,
                'carpetas' => [
                    'incluidas' => 'todas',
                    'excluidas' => ['Borradores','Enviados','Spam','Papelera'],
                    'correo_busqueda' => $correo,
                    // Queda en carpetas_json para poder explicar después por
                    // qué un lote tuvo tan pocos (o tantos) correos.
                    'incluir_procesados' => $incluirProcesados,
                ],
            ]);
            $lote = $this->loadModel('CorreoLote')->iniciar((int) $lote['id']);
            // Los motores de fondo empiezan ya, sin esperar a la tarea
            // programada (hasta diez minutos) ni a que la pantalla siga abierta.
            CorreoLote::lanzarMotores(true);
            $this->json(['ok' => true, 'lote' => $lote]);
        } catch (Throwable $e) {
            $this->responderFallo('crear el lote de descarga masiva', $e);
        }
    }

    /**
     * El error de una acción, dicho para la persona.
     *
     * Los rechazos de la propia aplicación —lo que valida el controlador
     * (InvalidArgumentException) y lo que reportan el buzón o la carpeta
     * destino (RuntimeException)— traen un mensaje escrito para quien usa la
     * pantalla y se muestran tal cual. Lo demás se anota en el log y la
     * pantalla recibe un mensaje genérico: antes llegaba al navegador el
     * SQLSTATE con el nombre de la columna, que no le sirve a quien trabaja y
     * sí a quien quiera conocer la estructura por dentro. PDOException se
     * nombra aparte porque hereda de RuntimeException.
     *
     * Es el mismo criterio de SeguimientoController::responderFallo().
     */
    /**
     * Por qué todavía no se puede armar un lote de este buzón, o '' si se
     * puede.
     *
     * Un lote se arma con lo que ya está en el índice. Si una carpeta del
     * buzón no se ha leído, sus correos quedan fuera del lote sin que nadie
     * se entere; por eso no se deja armar hasta que la tarea automática
     * termine de leerlo. Ver CorreoSync::carpetasSinLeer().
     *
     * Si no se puede hablar con el servidor de correo tampoco se deja: sin la
     * lista de carpetas no hay cómo afirmar que esté todo.
     *
     * Lo mismo si el rango empieza antes de lo que el índice guarda (la
     * retención del buzón): esos correos no están en el índice y el lote
     * saldría sin ellos.
     */
    private function buzonSinTerminarDeLeer(array $config, $desde = '')
    {
        require_once __DIR__ . '/../helpers/CorreoSync.php';
        $cuentaId = (int) ($config['cuenta_id'] ?? 0);
        $indice = $this->loadModel('CorreoIndice')->setCuenta($cuentaId);

        $inicio = CorreoSync::inicioDelIndice($config);
        if ($inicio !== '' && (string) $desde !== '' && (string) $desde < $inicio) {
            return 'Este buzón solo guarda en su índice los correos de los últimos '
                 . (int) $config['indice_retencion_dias'] . ' días, desde el '
                 . date('d/m/Y', strtotime($inicio)) . '. Lo anterior no está, y un lote desde el '
                 . date('d/m/Y', strtotime((string) $desde)) . ' saldría sin esos correos.'
                 . "\n\n" . 'Empieza el rango el ' . date('d/m/Y', strtotime($inicio))
                 . ' o después, o amplía la retención del buzón (en su configuración) y espera a que '
                 . 'la tarea automática lo vuelva a leer.';
        }

        try {
            $faltan = CorreoSync::carpetasSinLeer($config, $indice);
        } catch (Throwable $e) {
            return 'No se pudo conectar con el buzón para comprobar que ya está leído completo, '
                 . 'así que por ahora no se arma el lote. Detalle: ' . $e->getMessage();
        }
        if (!$faltan) {
            return '';
        }

        $cuantas = count($faltan);
        $nombres = array_column(array_slice($faltan, 0, 5), 'nombre');
        $lista = implode(', ', $nombres) . ($cuantas > 5 ? ' y ' . ($cuantas - 5) . ' más' : '');
        $inicio = $indice->getCarpetas()
            ? 'Todavía falta leer ' . $cuantas . ($cuantas === 1 ? ' carpeta' : ' carpetas')
              . ' de este buzón (' . $lista . ').'
            : 'Este buzón todavía no se ha leído: es nuevo y la tarea automática todavía no pasó por él.';

        return $inicio . "\n\n"
             . 'Hasta que termine de leerlo no se puede armar un lote: saldría incompleto, sin los '
             . 'correos de lo que falta. La tarea automática corre cada 5 minutos y lee cada '
             . 'carpeta completa; vuelve a intentarlo en unos minutos.';
    }

    private function responderFallo(string $donde, Throwable $e): never
    {
        if (!$e instanceof PDOException
            && ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $this->registrarFallo($donde, $e);
        $this->json(['ok' => false, 'message' => self::MENSAJE_FALLO], 500);
    }

    private const MENSAJE_FALLO = 'No se pudo completar por un problema del sistema. '
        . 'Inténtalo de nuevo en un momento; si se repite, avisa a quien mantiene Nexo Fiscal.';

    /**
     * El lote que dice el formulario, solo si es de la empresa en curso.
     *
     * CorreoLote::get() busca por id a secas, porque el trabajador de cli/ hace
     * avanzar los lotes de todas las empresas y no tiene sesión con la cual
     * acotarse. Aquí el id llega de afuera, así que la pertenencia se comprueba
     * antes de dejar mirar, pausar o cancelar nada.
     */
    private function loteDeLaSociedad($id)
    {
        $lote = $this->loadModel('CorreoLote')->get((int) $id);
        if (!$lote) {
            return null;
        }
        $sociedad = (int) (Sociedad::seleccionadaIdActual());
        if ($sociedad > 0 && (int) ($lote['sociedad_id'] ?? 0) !== $sociedad) {
            return null;
        }
        return $lote;
    }

    public function generalEstado()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        $id = (int) $this->post('lote_id', 0);
        $modelo = $this->loadModel('CorreoLote');
        $lote = $this->loteDeLaSociedad($id);
        if (!$lote) { $this->json(['ok' => false, 'message' => 'Lote no encontrado.'], 404); }
        $this->json(['ok' => true, 'lote' => $lote, 'incidencias' => $modelo->incidencias($id, 20)]);
    }

    public function generalIncidencias()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }
        try {
            $config = $this->configCuenta((int) $this->post('cuenta_id', 0));
            if (!$config) {
                throw new RuntimeException('La cuenta seleccionada no está configurada.');
            }
            $result = $this->loadModel('CorreoLote')->historialIncidencias(
                (int) $config['cuenta_id'],
                $this->filtrosIncidencia(),
                max(1, (int) $this->post('pagina', 1)),
                50
            );
            $this->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Los ids marcados. La pantalla los manda como JSON en un solo campo:
     * con 'ids[]' repetido, una selección de varios cientos choca contra
     * max_input_vars de PHP y llegan truncados sin ningún error.
     */
    private function idsRecibidos()
    {
        $crudo = $this->post('ids', []);
        if (is_string($crudo)) {
            $decodificado = json_decode($crudo, true);
            $crudo = is_array($decodificado) ? $decodificado : [];
        }
        return array_values(array_filter(array_map('intval', (array) $crudo)));
    }

    /** El filtro que manda la pantalla, leído en un solo lugar. */
    private function filtrosIncidencia()
    {
        $ver = strtolower(trim((string) $this->post('ver', 'pendientes')));
        return [
            'q' => trim((string) $this->post('q', '')),
            'tipo' => trim((string) $this->post('tipo', '')),
            'ver' => in_array($ver, ['pendientes', 'descartadas', 'todas'], true) ? $ver : 'pendientes',
        ];
    }

    /**
     * Descarta incidencias revisadas (POST, JSON).
     *
     * Dos modos: por lista de ids, o `todas=1` para alcanzar todo lo que
     * cumple el filtro de la pantalla. El segundo es el que importa: 194
     * cédulas ajenas no se descartan marcándolas de a cincuenta.
     *
     * No borra nada: la incidencia queda marcada y se puede restaurar. La
     * marca vive por firma, así que reprocesar el correo no la resucita.
     */
    public function incidenciasDescartar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }
        try {
            $config = $this->configCuenta((int) $this->post('cuenta_id', 0));
            if (!$config) {
                throw new RuntimeException('La cuenta seleccionada no está configurada.');
            }
            $modelo = $this->loadModel('CorreoLote');

            $todas = in_array(strtolower(trim((string) $this->post('todas', '0'))), ['1', 'true', 'on', 'si', 'sí'], true);
            if ($todas) {
                $ids = $modelo->idsIncidencias((int) $config['cuenta_id'], $this->filtrosIncidencia());
            } else {
                $ids = $this->idsRecibidos();
            }
            if (!$ids) {
                $this->json(['ok' => false, 'message' => 'No hay incidencias que descartar con ese filtro.'], 422);
            }

            $r = $modelo->descartarIncidencias(
                $ids,
                (string) $this->post('motivo', ''),
                $_SESSION['user_id'] ?? null,
                true,
                (int) $config['cuenta_id']
            );
            $this->json([
                'ok' => true,
                'descartadas' => (int) $r['descartadas'],
                'message' => sprintf(
                    '%d incidencia(s) descartada(s). No van a volver aunque el correo se reprocese.',
                    (int) $r['descartadas']
                ),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Devuelve a la lista incidencias descartadas (POST, JSON). */
    public function incidenciasRestaurar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }
        try {
            $config = $this->configCuenta((int) $this->post('cuenta_id', 0));
            if (!$config) {
                throw new InvalidArgumentException('La cuenta seleccionada no está configurada.');
            }
            $ids = $this->idsRecibidos();
            if (!$ids) {
                $this->json(['ok' => false, 'message' => 'No se indicó ninguna incidencia.'], 422);
            }
            $r = $this->loadModel('CorreoLote')->restaurarIncidencias($ids, (int) $config['cuenta_id']);
            $this->json([
                'ok' => true,
                'restauradas' => (int) $r['restauradas'],
                'message' => (int) $r['restauradas'] . ' incidencia(s) de vuelta en la lista.',
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function generalProcesar()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        @set_time_limit(300);
        $loteId = (int) $this->post('lote_id', 0);
        $limit = max(1, min(10, (int) $this->post('limit', 2)));

        // El id llega de afuera: sin esto, un POST con el id de un lote de otra
        // empresa devuelve su buzón, su cédula y sus incidencias, y lo hace
        // avanzar. El resto de acciones del lote ya pasaban por aquí.
        if (!$this->loteDeLaSociedad($loteId)) {
            $this->json(['ok' => false, 'message' => 'Lote no encontrado.'], 404);
        }

        // Con los motores de fondo trabajando, esta pantalla solo mira: una
        // tanda desde acá sería una conexión más al buzón para el mismo lote.
        if ($this->motoresTrabajando()) {
            $lotes = $this->loadModel('CorreoLote');
            $this->json([
                'ok' => true,
                'ocupado' => true,
                'lote' => $lotes->get($loteId),
                'procesados_ahora' => 0,
                'incidencias' => $lotes->incidencias($loteId, 20),
            ]);
        }

        $lock = CorreoLote::adquirirLock();
        try {
            $r = $this->procesarTandaLote($loteId, $limit, 25);
        } finally {
            CorreoLote::liberarLock($lock);
        }
        if (empty($r['ok'])) {
            $this->json(['ok' => false, 'message' => $r['message'], 'lote' => $r['lote']], (int) $r['status']);
        }
        $this->json([
            'ok' => true,
            'lote' => $r['lote'],
            'procesados_ahora' => $r['procesados_ahora'],
            'incidencias' => $r['incidencias'],
        ]);
    }

    /**
     * ¿El lote ya lo están moviendo los motores de fondo? Si no hay ninguno,
     * se lanzan, y si se pudieron lanzar la respuesta también es sí: la
     * pantalla no tiene por qué hacer el trabajo.
     *
     * No lanza de nuevo si lo hizo hace menos de un minuto. En ese rato, si
     * todavía no arrancó ninguno, contesta que no y quien preguntó hace una
     * tanda él mismo: es mejor una conexión de más que un lote quieto porque
     * el proceso de fondo no pudo arrancar en esta computadora.
     */
    private function motoresTrabajando()
    {
        // Si faltan motores —uno se cayó, o terminó su tope de tiempo— se
        // completan: el 29/09/2026 un lote de 7.874 correos se quedó con uno
        // solo de cuatro, porque había "un motor en marcha" y nadie más miraba.
        $enMarcha = CorreoLote::motoresEnMarcha();
        $lanzados = $enMarcha < CorreoLote::motores() && CorreoLote::lanzarMotores();
        return $enMarcha > 0 || $lanzados;
    }

    /**
     * Latido de la descarga en curso: la mueve desde CUALQUIER pantalla.
     *
     * El único motor dentro del navegador vivía en el modo Descargas, así que
     * cambiar de buzón o irse a otro módulo dejaba el lote quieto —parecía
     * que la descarga se había quitado— hasta volver a esa pantalla o hasta
     * que corriera la tarea programada de Windows, que no está instalada en
     * todas las computadoras. Ahora el trabajo avanza mientras haya cualquier
     * pantalla del sistema abierta.
     *
     * El lock evita que dos pestañas (o la tarea programada y una pestaña)
     * abran dos conexiones IMAP para el mismo lote: quien no lo consigue solo
     * informa el avance.
     */
    public function lotesLatido()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        @set_time_limit(120);

        $lotes = $this->loadModel('CorreoLote');
        $lote = $lotes->enCurso();
        if (!$lote) {
            $this->json(['ok' => true, 'lote' => null]);
        }

        $ocupado = false;
        $error = '';
        if ((string) $this->post('avanzar', '1') !== '0') {
            $lock = $this->motoresTrabajando() ? null : CorreoLote::adquirirLock();
            if ($lock === null) {
                $ocupado = true;
            } else {
                try {
                    // Tanda corta: este viaje no lo está mirando nadie y no
                    // tiene por qué acaparar un proceso del servidor.
                    $r = $this->procesarTandaLote((int) $lote['id'], 4, 15);
                    $lote = $r['lote'] ?: $lote;
                    $error = empty($r['ok']) ? (string) $r['message'] : '';
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                } finally {
                    CorreoLote::liberarLock($lock);
                }
            }
        }

        $this->json([
            'ok' => true,
            'ocupado' => $ocupado,
            // Con el buzón caído el que llama espera en vez de insistir.
            'error' => $error,
            'lote' => [
                'id' => (int) $lote['id'],
                'estado' => (string) $lote['estado'],
                'cuenta_id' => (int) $lote['cuenta_id'],
                'cuenta_nombre' => (string) ($lote['cuenta_nombre'] ?? ''),
                'total_mensajes' => (int) $lote['total_mensajes'],
                'procesados' => (int) $lote['procesados'],
                'documentos_importados' => (int) ($lote['documentos_importados'] ?? 0),
            ],
        ]);
    }

    /**
     * Procesa una tanda de correos de un lote del modo Descargas y devuelve el
     * resultado en un array (no emite JSON ni corta la ejecución).
     *
     * Existe separada de generalProcesar() porque el mismo trabajo lo piden
     * dos frentes: el navegador —una tanda por petición, con presupuesto
     * corto para no chocar con el timeout de Apache— y cli/procesar_lotes.php,
     * que encadena tandas sin que nadie tenga el módulo abierto. Antes el
     * bucle vivía solo en el JavaScript de la vista, así que cerrar la
     * pestaña dejaba el lote congelado en 'ejecutando' para siempre.
     *
     * $fetcherCompartido permite reutilizar una sola conexión IMAP a lo largo
     * de muchas tandas (el saludo TLS cuesta 1-3 s y antes se pagaba en cada
     * viaje, por solo 6 correos). Quien lo presta es quien debe cerrarlo.
     *
     * @return array{ok:bool,lote:?array,procesados_ahora:int,incidencias:array,message:string,status:int}
     */
    public function procesarTandaLote($loteId, $limit = 6, $presupuestoSegundos = 25, $fetcherCompartido = null)
    {
        $loteId = (int) $loteId;
        $limit = max(1, min(10, (int) $limit));
        $lotes = $this->loadModel('CorreoLote');
        $lote = $lotes->get($loteId);
        if (!$lote) {
            return ['ok' => false, 'lote' => null, 'procesados_ahora' => 0, 'incidencias' => [],
                    'message' => 'Lote no encontrado.', 'status' => 404];
        }
        if ($lote['estado'] === 'pendiente') { $lote = $lotes->iniciar($loteId); }
        if ($lote['estado'] !== 'ejecutando') {
            return ['ok' => true, 'lote' => $lote, 'procesados_ahora' => 0, 'incidencias' => [],
                    'message' => '', 'status' => 200];
        }

        // tomarPendientes() también rescata los items que quedaron en
        // 'procesando' de una corrida interrumpida y, si ya no queda nada,
        // marca el lote como completado.
        $items = $lotes->tomarPendientes($loteId, $limit);
        if (empty($items)) {
            return ['ok' => true, 'lote' => $lotes->get($loteId), 'procesados_ahora' => 0, 'incidencias' => [],
                    'message' => '', 'status' => 200];
        }

        $fetcherPropio = !($fetcherCompartido instanceof MailFetcher);
        $fetcher = $fetcherPropio
            // El lote manda: ya nació con su sociedad y el worker corre sin sesión.
            ? new MailFetcher($this->configCuenta((int) $lote['cuenta_id'], false))
            : $fetcherCompartido;
        // La marca de "ya procesado" es de cada empresa, y la del lote es la
        // que manda: el worker de cli/ corre sin sesión, donde "la empresa en
        // curso" es la primera activa y no la dueña del lote.
        $fetcher->usarSociedad((int) $lote['sociedad_id']);
        try {
            if ($fetcherPropio || !$fetcher->estaConectado()) { $fetcher->conectar(); }
        } catch (Throwable $e) {
            // Que el buzón no conteste no es culpa de estos correos: vuelven a
            // la cola para reintentarlos más tarde. Antes se marcaban como
            // error de una vez, así que un corte de red se comía el lote
            // completo a razón de una tanda por viaje. Tras varios intentos
            // sí se dan por perdidos, para que un buzón mal configurado no
            // deje el lote girando para siempre.
            $reintentar = [];
            foreach ($items as $item) {
                if ((int) ($item['intentos'] ?? 0) < 3) {
                    $reintentar[] = (int) $item['id'];
                    continue;
                }
                $lotes->incidencia($loteId, (int) $item['id'], 'conexion', $e->getMessage());
                $lotes->finalizarItem((int) $item['id'], ['estado' => 'error', 'detalle' => $e->getMessage()]);
            }
            if ($reintentar) {
                // Una sola incidencia por tanda: durante un corte largo, una
                // por correo y por viaje inunda la lista sin decir más.
                $lotes->incidencia($loteId, $reintentar[0], 'conexion', $e->getMessage());
                $lotes->devolverAPendiente($reintentar);
            }
            $lotes->cerrarTanda($loteId);
            if ($fetcherPropio) { $fetcher->cerrar(); }
            return ['ok' => false, 'lote' => $lotes->get($loteId), 'procesados_ahora' => 0, 'incidencias' => [],
                    'message' => $e->getMessage(), 'status' => 500];
        }

        $bandeja = $this->loadModel('CorreoBandeja');
        $facturas = $this->loadModel('Factura');
        // Sin la carpeta_raiz del lote aunque la tenga: los lotes de antes la
        // anotaban, y todo lo nuevo se archiva en SharePoint.
        $importer = new XmlDocumentImporter();
        $notasNuevas = 0;

        // Presupuesto por tanda: se procesa lo que quepa y lo no alcanzado
        // vuelve a 'pendiente' para el siguiente viaje. Así un lote grande
        // avanza en tandas amplias sin arriesgar el timeout del servidor.
        $inicioLote = microtime(true);
        $presupuestoSegundos = max(5, (int) $presupuestoSegundos);
        $procesadosAhora = 0;
        $cerrados = [];

        try {
            foreach ($items as $indiceItem => $item) {
                if ($procesadosAhora > 0 && (microtime(true) - $inicioLote) > $presupuestoSegundos) {
                    $lotes->devolverAPendiente(array_column(array_slice($items, $indiceItem), 'id'));
                    break;
                }
                $resumen = ['estado' => 'completado', 'importados' => 0, 'duplicados' => 0, 'pdf_pendientes' => 0, 'detalle' => ''];
                try {
                    $mensaje = $fetcher->extraerMensaje((int) $item['uid'], (string) $item['carpeta']);
                    foreach (($mensaje['avisos'] ?? []) as $aviso) {
                        $lotes->incidencia($loteId, (int) $item['id'], 'adjunto_descartado', $aviso, ['uid' => (int) $item['uid']]);
                    }
                    if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                        $resumen['estado'] = 'omitido';
                        $resumen['detalle'] = 'Sin XML/PDF adjuntos.';
                    } else {
                        $captura = $this->procesarMensaje(
                            $mensaje, $bandeja, $facturas, (int) $lote['cuenta_id'],
                            ['FE', 'NC'], (string) $lote['sociedad_cedula'],
                            (int) $lote['sociedad_id'], [], 'descargas',
                            // Descargas resuelve cada fila aquí mismo —la importa
                            // o la descarta, y en los dos casos borra sus
                            // archivos—, así que su paso por la Bandeja no sale de
                            // esta computadora. Ver carpetaBandeja().
                            true
                        );
                        $resumen['duplicados'] += (int) ($captura['ya_existentes'] ?? 0);

                        foreach (($captura['errores'] ?? []) as $error) {
                            $tipoIncidencia = $this->tipoIncidencia($error);
                            $lotes->incidencia($loteId, (int) $item['id'], $tipoIncidencia, $error, ['uid' => (int) $item['uid']]);
                        }
                        foreach (($captura['archivos_huerfanos'] ?? []) as $huerfano) {
                            if (is_string($huerfano) && $huerfano !== '') { AlmacenDocumentos::de($huerfano)->borrar($huerfano); }
                        }

                        foreach (($captura['filas'] ?? []) as $ref) {
                            $filas = $bandeja->getByIds([(int) $ref['id']]);
                            if (empty($filas)) { continue; }
                            $fila = $filas[0];
                            if ($fila['estado'] === 'importada') { $resumen['duplicados']++; continue; }
                            if ($fila['estado'] !== 'pendiente') {
                                $lotes->incidencia($loteId, (int) $item['id'], (string) $fila['estado'],
                                    'Documento no importado: ' . ($fila['numero_corto'] ?? $fila['archivo_xml']));
                                $this->limpiarArchivosBandeja($fila);
                                $bandeja->marcarDescartadas([(int) $fila['id']]);
                                continue;
                            }

                            try {
                                $r = $importer->importar((string) $fila['archivo_xml'], (string) ($fila['archivo_pdf'] ?? ''), [
                                    'origen' => 'correo_general', 'tipos_permitidos' => ['FE', 'NC'],
                                    'validar_receptor' => true, 'cedula_receptor' => $lote['sociedad_cedula'],
                                    'sociedad_id' => (int) $lote['sociedad_id'],
                                    'correo_cuenta_id' => (int) $lote['cuenta_id'], 'correo_carpeta' => (string) $item['carpeta'],
                                    'correo_uidvalidity' => (int) $item['uidvalidity'], 'correo_uid' => (int) $item['uid'],
                                    'fecha_correo' => $mensaje['fecha'] ?? null,
                                ]);
                                if (($r['estado'] ?? '') === 'importado') {
                                    $resumen['importados']++;
                                    if (($r['tipo_documento'] ?? '') === 'NC') { $notasNuevas++; }
                                } else { $resumen['duplicados']++; }
                                if (!empty($r['pdf_pendiente'])) { $resumen['pdf_pendientes']++; }
                                $bandeja->marcarImportadasGeneral([(int) $fila['id']]);
                                $this->limpiarArchivosBandeja($fila);
                            } catch (Throwable $e) {
                                $lotes->incidencia($loteId, (int) $item['id'], $this->tipoIncidencia($e->getMessage(), 'xml_invalido'), $e->getMessage(), ['archivo' => basename((string) $fila['archivo_xml'])]);
                                $this->limpiarArchivosBandeja($fila);
                                $bandeja->marcarDescartadas([(int) $fila['id']]);
                            }
                        }
                        $resumen['detalle'] = 'Importados: ' . $resumen['importados'] . '; duplicados: ' . $resumen['duplicados'] . '; PDF pendientes: ' . $resumen['pdf_pendientes'];
                    }
                    $fetcher->marcarProcesado($mensaje['clave']);
                } catch (Throwable $e) {
                    $resumen['estado'] = 'error';
                    $resumen['detalle'] = $e->getMessage();
                    $lotes->incidencia($loteId, (int) $item['id'], 'procesamiento', $e->getMessage(), ['uid' => (int) $item['uid']]);
                }
                $lotes->finalizarItem((int) $item['id'], $resumen);
                $cerrados[] = (int) $item['id'];
                $procesadosAhora++;
            }
        } catch (Throwable $e) {
            // Algo que no es de un correo en particular cortó la tanda: los
            // que quedaron sin cerrar vuelven a la cola ya, y no a los diez
            // minutos del rescate.
            $lotes->devolverAPendiente(array_diff(array_map('intval', array_column($items, 'id')), $cerrados));
            if ($fetcherPropio) { $fetcher->cerrar(); }
            throw $e;
        }
        // Los contadores del lote, una vez por tanda. Recalcularlos por correo
        // costaba cinco subconsultas por cada uno.
        $loCompleto = $lotes->cerrarTanda($loteId);
        if ($fetcherCompartido === null) { $fetcher->cerrar(); }
        if ($notasNuevas > 0) { $this->revalidarNotasGeneral((int) $lote['sociedad_id']); }

        // Cruzar contra los pagos cuesta millones de comparaciones
        // de texto, así que se hace UNA vez —al terminar el lote— y no en
        // cada tanda. Lo hace solo el motor que lo completó: con varios
        // trabajando a la vez, los dos últimos lo ven completo.
        $loteFinal = $lotes->get($loteId);
        if ($loCompleto) {
            $this->revalidarPorPagarGeneral();
        }

        return [
            'ok' => true,
            'lote' => $loteFinal,
            'procesados_ahora' => $procesadosAhora,
            'incidencias' => $lotes->incidencias($loteId, 20),
            'message' => '',
            'status' => 200,
        ];
    }

    public function generalPausar() { $this->cambiarEstadoGeneral('pausado'); }
    public function generalReanudar() { $this->cambiarEstadoGeneral('ejecutando'); }
    public function generalCancelar() { $this->cambiarEstadoGeneral('cancelado'); }

    private function cambiarEstadoGeneral($estado)
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        try {
            $id = (int) $this->post('lote_id', 0);
            if ($this->loteDeLaSociedad($id) === null) {
                $this->json(['ok' => false, 'message' => 'Lote no encontrado.'], 404);
            }
            $lote = $this->loadModel('CorreoLote')->cambiarEstado($id, $estado);
            $this->json(['ok' => true, 'lote' => $lote]);
        } catch (Throwable $e) { $this->json(['ok' => false, 'message' => $e->getMessage()], 422); }
    }

    private function rangoGeneral()
    {
        $desde = trim((string) $this->post('fecha_desde', ''));
        $hasta = trim((string) $this->post('fecha_hasta', ''));
        // checkdate y no solo el patrón: "2026-02-30" tiene la forma de una
        // fecha, pero strtotime lo corre al 2 de marzo y la descarga acababa
        // cubriendo un rango que nadie pidió.
        if (!$this->fechaQueExiste($desde) || !$this->fechaQueExiste($hasta)
            || strtotime($hasta) < strtotime($desde)) {
            throw new InvalidArgumentException('Indica un rango de fechas válido.');
        }
        return [$desde, $hasta];
    }

    /** Una fecha aaaa-mm-dd que existe en el calendario. */
    private function fechaQueExiste($valor)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $valor, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function correoBusquedaGeneral()
    {
        $correo = mb_strtolower(trim((string) $this->post('correo_busqueda', '')), 'UTF-8');
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Indica un correo de búsqueda válido.');
        }
        return $correo;
    }

    private function limpiarArchivosBandeja(array $fila)
    {
        foreach (['archivo_xml', 'archivo_pdf'] as $campo) {
            $ruta = (string) ($fila[$campo] ?? '');
            if ($ruta !== '') { AlmacenDocumentos::de($ruta)->borrar($ruta); }
        }
    }

    private function revalidarNotasGeneral($sociedadId)
    {
        try {
            require_once __DIR__ . '/../helpers/NotasCreditoVerificador.php';
            NotasCreditoVerificador::verificarTodosSociedad($sociedadId, $this->loadModel('NotaCredito'));
        } catch (Throwable $e) {
        }
    }

    /**
     * Cruza contra los pagos las facturas que acaban de entrar.
     *
     * Existía la mitad de notas de crédito y faltaba esta. Sin ella, importar
     * desde Correo dejaba las líneas del listado "sin respaldo" aunque su XML
     * ya estuviera en la base: el emparejamiento solo corría al entrar a Por
     * Pagar y darle a "Verificar de nuevo", y quien importaba no tenía forma
     * de saber que hacía falta.
     *
     * No se acota a un pago porque las facturas que llegan por correo no
     * traen ninguna asignada; cualquier listado puede completarse con ellas.
     * Nunca lanza: la importación ya terminó bien y esto es un extra.
     */
    private function revalidarPorPagarGeneral()
    {
        try {
            require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
            PorPagarVerificador::verificarPendientes(
                $this->loadModel('FacturaErp'),
                $this->loadModel('Factura')
            );
        } catch (Throwable $e) {
        }
    }

    /**
     * Valida extensión + cuenta elegida y devuelve su config para
     * MailFetcher, o responde el error en JSON (json() corta la ejecución).
     * La cuenta llega en el POST (cuenta_id); sin ella se usa la activa.
     * $cuentaId la fija quien ya sabe de qué buzón se trata.
     */
    private function configListoOFallar($cuentaId = null)
    {
        if (!MailFetcher::extensionDisponible()) {
            $this->json(['ok' => false, 'message' => 'La extensión imap de PHP no está activa en este servidor.'], 500);
        }

        $config = $this->configCuenta($cuentaId !== null ? (int) $cuentaId : (int) $this->post('cuenta_id', 0));
        if ($config === null || !MailFetcher::configurado($config)) {
            $this->json([
                'ok' => false,
                'message' => 'Ningún buzón atiende a la sociedad en curso. Abre el engranaje ⚙ y marca '
                    . 'esta empresa en las "Sociedades que atiende" del buzón que corresponda.',
            ], 422);
        }

        return $config;
    }

    /**
     * Config de la cuenta indicada (o de la activa si $cuentaId <= 0).
     *
     * La cuenta llega del navegador, así que se comprueba que atienda a la
     * empresa en curso: de lo contrario bastaba mandar el id de un buzón de
     * otra sociedad para leer su correo.
     *
     * $validarSociedad = false lo usan los procesos que NO trabajan con la
     * empresa de la sesión sino con la del lote (el worker de cli/, que corre
     * sin sesión y ya trae su sociedad decidida desde que se creó el lote).
     */
    private function configCuenta($cuentaId = 0, $validarSociedad = true)
    {
        $cuentas = $this->loadModel('CorreoCuenta');
        $cuentas->seedDesdeArchivo();

        if ($cuentaId <= 0) {
            $cuentaId = $this->cuentaActivaId($cuentas);
        } elseif ($validarSociedad && !$cuentas->perteneceASociedad($cuentaId)) {
            return null;
        }

        return $cuentaId > 0 ? $cuentas->configPara($cuentaId) : null;
    }

    /**
     * Cuenta con la que se trabaja: la elegida en config.json si sigue
     * existiendo; si no, la primera registrada.
     */
    private function cuentaActivaId($cuentas)
    {
        // Solo cuentan los buzones habilitados para la sociedad en curso: si
        // el guardado en config.json es de otra empresa, se cae a la primera
        // que sí le corresponda en vez de trabajar sobre un buzón ajeno.
        $id = (int) ($this->configLocal()['cuenta_id'] ?? 0);
        if ($id > 0 && $cuentas->getById($id) !== null && $cuentas->perteneceASociedad($id)) {
            return $id;
        }

        $visibles = $cuentas->getVisibles();
        return !empty($visibles) ? (int) $visibles[0]['id'] : 0;
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
                $this->json(['ok' => false, 'message' => 'No seleccionaste ninguna fila.'], 422);
            }

            $bandejaModel = $this->loadModel('CorreoBandeja');
            $filas = $bandejaModel->getByIds($ids, 'pendiente');

            $facturaModel = $this->loadModel('Factura');
            $rutas = [];
            $idsValidos = [];
            $filasValidas = [];
            $repetidas = [];
            $yaExistentes = [];
            foreach ($filas as $fila) {
                $rutaXml = (string) ($fila['archivo_xml'] ?? '');
                if ($rutaXml === '' || !RutaDocumento::existe($rutaXml)) {
                    continue;
                }

                /*
                 * Última parada antes de encolar. La captura ya deja fuera lo
                 * que está cargado, pero entre capturar e importar pueden
                 * pasar horas y otra persona pudo meter el mismo comprobante:
                 * encolarlo sería gastar la subida para que el importador
                 * conteste "duplicado". Con sus dos archivos en pie no hay
                 * nada que hacerle, así que la fila se va de la Bandeja.
                 */
                $yaCargado = $facturaModel->yaCargado((string) ($fila['hash_xml'] ?? ''), '', '', '');
                if ($yaCargado !== null && EstadoArchivo::de($yaCargado)['entregable']) {
                    $yaExistentes[] = (int) $fila['id'];
                    $repetidas[] = [
                        'tipo' => strtoupper((string) ($fila['tipo_doc'] ?? 'FE')),
                        'numero' => (string) ($fila['numero_corto'] ?? ''),
                        'proveedor' => (string) ($fila['proveedor'] ?? ''),
                        'empresa' => (string) ($yaCargado['sociedad_nombre'] ?? ''),
                        'misma_empresa' => (int) ($yaCargado['sociedad_id'] ?? 0) === (int) ($fila['sociedad_id'] ?? 0),
                        'cargada_en' => (string) ($yaCargado['creado_en'] ?? ''),
                    ];
                    continue;
                }

                $rutas[] = [
                    'ruta' => $rutaXml,
                    'nombre' => $this->nombreOriginal($rutaXml),
                    'ruta_pdf' => (string) ($fila['archivo_pdf'] ?? ''),
                    'correo_cuenta_id' => (int) ($fila['cuenta_id'] ?? 0),
                    'fecha_correo' => $fila['fecha_correo'] ?? null,
                ];
                $idsValidos[] = (int) $fila['id'];
                $filasValidas[] = $fila;
            }

            if ($yaExistentes) {
                $bandejaModel->marcarYaExisten($yaExistentes);
                $this->purgarYaExisten($bandejaModel);
            }

            // Que no quede nada por importar es un fallo solo si tampoco hubo
            // nada que contar: si todas estaban ya cargadas, la respuesta de
            // abajo lo explica una por una.
            if (empty($rutas) && empty($repetidas)) {
                $this->json(['ok' => false, 'message' => 'Las filas seleccionadas no tienen XML pendiente de importar (¿ya se importaron o falta el archivo?).'], 422);
            }

            // Importar ya no pide pago. El pago de un comprobante se
            // deduce: al guardarlo, el importador busca su factura en el
            // listado del ERP por el consecutivo, y si esa factura está en un
            // pago, el comprobante hereda ese pago y el pago se
            // actualiza solo. Elegirla a mano era una decisión que nadie tenía
            // por qué tomar —y equivocarse dejaba la factura fuera del pago—.
            $pagoId = null;

            $importacionId = 0;
            $encoladas = 0;
            $estadoCola = null;
            $avisosPendientes = [];

            // Sin nada que encolar no se abre una importación vacía: la
            // respuesta va igual, con lo que se quedó fuera y por qué.
            if ($rutas) {
                require_once __DIR__ . '/../helpers/InvoiceImportQueue.php';
                $service = new InvoiceImportQueue();

                // La Bandeja guarda facturas y notas de crédito, y una misma
                // selección puede traer de las dos. La importación lleva los
                // tipos que de verdad van en ella: antes decía siempre 'FE', y
                // por eso una nota no podía pasar por aquí.
                $tipos = [];
                foreach ($filasValidas as $fila) {
                    $tipos[strtoupper((string) ($fila['tipo_doc'] ?? 'FE')) ?: 'FE'] = true;
                }
                $tipos = array_values(array_intersect(EntradaAutomatica::TIPOS, array_keys($tipos))) ?: ['FE'];

                $sociedadActiva = $this->loadModel('Sociedad')->getActiva();
                $inicio = $service->iniciarImportacion([
                    'archivo_origen' => 'Correo ' . date('d/m/Y H:i'),
                    'total_esperado' => count($rutas),
                    'pago_id' => $pagoId,
                    // Solo notas → 'NC', que es lo que mira quien lista las
                    // importaciones por tipo; con facturas de por medio, 'FE'.
                    'tipo_documento' => $tipos === ['NC'] ? 'NC' : 'FE',
                    'tipos_permitidos' => $tipos,
                    'cedula_receptor' => $sociedadActiva['cedula'] ?? '',
                    'sociedad_id' => (int) ($sociedadActiva['id'] ?? 0),
                ]);
                $importacionId = (int) $inicio['importacion_id'];

                $resultado = $service->agregarArchivosLocales($importacionId, $rutas);
                $encoladas = (int) $resultado['uploaded_count'];
                $estadoCola = $resultado['estado'];

                $bandejaModel->marcarImportadas($idsValidos, $importacionId);

                // La cola tomó el XML y el PDF donde están, en la bandeja, y los
                // borra ella cuando XmlDocumentImporter archive el par y confirme
                // la BD. Borrarlos acá le quitaría el archivo a la importación.
                if ($encoladas === count($filasValidas)) {
                    foreach ($filasValidas as $fila) {
                        if (empty($fila['archivo_pdf']) || !RutaDocumento::existe($fila['archivo_pdf'])) {
                            $avisosPendientes[] = (strtoupper((string) ($fila['tipo_doc'] ?? 'FE')) === 'NC' ? 'Nota de crédito ' : 'Factura ')
                                . ($fila['numero_corto'] ?? '')
                                . ': el XML se archivará y el PDF quedará pendiente.';
                        }
                    }
                }
            }

            // archivos_guardados va en 0 a propósito: archivar el par
            // renombrado ya no es cosa de aquí. Lo hace XmlDocumentImporter
            // cuando la cola procesa cada XML, y lo deja en la carpeta del mes
            // que corresponde (ver docs/ALMACENAMIENTO_DOCUMENTOS.md). Aquí
            // vivían 50 líneas que copiaban el par a la carpeta destino a mano
            // y que NUNCA corrían: json() termina la petición. La pantalla
            // prometía "archivos renombrados en la carpeta" y nadie los
            // escribía.
            $this->json([
                'ok' => true,
                'importacion_id' => $importacionId,
                'encoladas' => $encoladas,
                // Cuántas de las encoladas son notas de crédito: la pantalla
                // enlaza a su listado, que no es el de facturas.
                'notas' => count(array_filter($filasValidas, function ($fila) {
                    return strtoupper((string) ($fila['tipo_doc'] ?? 'FE')) === 'NC';
                })),
                'archivos_guardados' => 0,
                'aviso_carpeta' => '',
                'avisos' => array_slice($avisosPendientes, 0, 10),
                'repetidas' => $repetidas,
                'estado' => $estadoCola,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No fue posible encolar la importación: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Descarta filas de la bandeja: borra su XML y su PDF (POST, JSON).
     *
     * Antes el PDF se quedaba, y como la fila ya no aparecía en ningún lado
     * era un archivo que nadie iba a volver a encontrar ni a borrar. Lo que
     * hay en la bandeja es la copia de trabajo de lo que bajó del correo: el
     * original sigue en el buzón, y desmarcarlo como procesado (abajo) es lo
     * que permite volver a traerlo si se descartó por error.
     */
    public function descartar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $ids = $this->parseIds($this->post('ids', ''));
            if (empty($ids)) {
                $this->json(['ok' => false, 'message' => 'No seleccionaste ninguna fila.'], 422);
            }

            $bandejaModel = $this->loadModel('CorreoBandeja');

            // Se puede descartar todo lo que se ve en la bandeja
            $filas = array_merge(
                $bandejaModel->getByIds($ids, 'pendiente'),
                $bandejaModel->getByIds($ids, 'rechazada'),
                $bandejaModel->getByIds($ids, 'otra_cedula')
            );

            $idsValidos = [];
            $clavesCorreo = [];
            $rutas = [];
            $rutasPorFila = [];
            foreach ($filas as $fila) {
                $id = (int) $fila['id'];
                $idsValidos[] = $id;
                $rutasPorFila[$id] = [];
                foreach (['archivo_xml', 'archivo_pdf'] as $campo) {
                    $ruta = (string) ($fila[$campo] ?? '');
                    if ($ruta !== '') {
                        $rutas[$ruta] = true;
                        $rutasPorFila[$id][] = $ruta;
                    }
                }
                // uid_correo es la misma clave usada en correo_procesados
                $clave = (string) ($fila['uid_correo'] ?? '');
                if ($clave !== '') {
                    $clavesCorreo[$clave] = true;
                }
            }

            // Primero la fila, después el archivo: si el borrado falla a
            // medias, queda un archivo suelto y no una fila que apunta a nada.
            $enUso = $bandejaModel->rutasEnUsoFuera(array_keys($rutas), $idsValidos);
            $descartadas = $bandejaModel->marcarDescartadas($idsValidos);

            // Borrar los archivos de las filas que ESTE descarte se llevó, no
            // los de todas las que se pidieron: si alguien importó una de
            // ellas entre la lectura y la escritura, el UPDATE ya no la tocó y
            // sus archivos siguen haciendo falta.
            $confirmadas = $descartadas === count($idsValidos)
                ? $idsValidos
                : array_map('intval', array_column($bandejaModel->getByIds($idsValidos, 'descartada'), 'id'));

            $noBorrados = 0;
            $porBorrar = [];
            foreach ($confirmadas as $id) {
                foreach (($rutasPorFila[$id] ?? []) as $ruta) {
                    $porBorrar[$ruta] = true;
                }
            }
            foreach (array_keys($porBorrar) as $ruta) {
                if (isset($enUso[$ruta]) || !RutaDocumento::existe($ruta)) {
                    continue;
                }
                if (!AlmacenDocumentos::de($ruta)->borrar($ruta)) {
                    $noBorrados++;
                }
            }

            // Desmarcar los correos de origen como procesados: al eliminar
            // de la bandeja, el correo debe poder procesarse otra vez. Solo
            // para ESTA empresa: si la otra ya lo trabajó, eso es asunto suyo.
            if (!empty($clavesCorreo)) {
                MailFetcher::desmarcarProcesados(
                    array_keys($clavesCorreo),
                    Sociedad::seleccionadaIdActual()
                );
            }

            $this->json([
                'ok' => true,
                'descartadas' => (int) $descartadas,
                'no_borrados' => $noBorrados,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No fue posible descartar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Guardado masivo: los XML y PDF de la bandeja en un ZIP, con su nombrado.
     *
     * Aparte del flujo de importación a propósito. No marca, no mueve y no
     * borra: la bandeja queda exactamente igual después de guardar. Es para
     * llevarse los archivos, no para procesarlos.
     *
     * Sin ids se guarda toda la bandeja de revisión. Con ids, solo esas
     * filas, y siempre cruzadas contra lo que la bandeja muestra: así el
     * guardado no puede alcanzar documentos de otra empresa ni filas ya
     * importadas o descartadas, aunque alguien mande sus números a mano.
     *
     * Responde con el ZIP, no con JSON. El recuento viaja en cabeceras
     * propias (X-Guardados…) porque el cuerpo ya es el archivo.
     */
    public function guardarLote()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $zipTemporal = '';
        try {
            $bandejaModel = $this->loadModel('CorreoBandeja');
            $activas = $bandejaModel->getActivas();
            if (empty($activas)) {
                $this->json(['ok' => false, 'message' => 'La bandeja de revisión está vacía.'], 422);
            }

            $ids = $this->parseIds($this->post('ids', ''));
            if (!empty($ids)) {
                $pedidos = array_flip($ids);
                $activas = array_values(array_filter($activas, function ($fila) use ($pedidos) {
                    return isset($pedidos[(int) $fila['id']]);
                }));
            }
            if (empty($activas)) {
                $this->json(['ok' => false, 'message' => 'Las filas seleccionadas ya no están en la bandeja.'], 422);
            }

            $carpetaTmp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'correo' . DIRECTORY_SEPARATOR . 'tmp';
            if (!is_dir($carpetaTmp) && !@mkdir($carpetaTmp, 0777, true) && !is_dir($carpetaTmp)) {
                throw new RuntimeException('No se pudo preparar la carpeta temporal del guardado.');
            }
            // Un ZIP cuya descarga se cortó a la mitad no llega a borrarse:
            // el proceso muere dentro de readfile(). Se barren aquí los de
            // corridas anteriores para que la carpeta no crezca sola.
            foreach ((array) glob($carpetaTmp . DIRECTORY_SEPARATOR . 'bandeja_*.zip') as $viejo) {
                if (is_file($viejo) && filemtime($viejo) < time() - 3600) {
                    @unlink($viejo);
                }
            }

            $zipTemporal = $carpetaTmp . DIRECTORY_SEPARATOR
                . 'bandeja_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';

            $resumen = PaqueteDocumentos::crear($activas, $zipTemporal);

            $nombre = 'documentos_' . date('Ymd_Hi') . '.zip';
            $tamano = filesize($zipTemporal);

            // El ZIP se manda tal cual: cualquier byte que hubiera quedado en
            // un búfer de salida lo corrompería.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            if ($tamano !== false) {
                header('Content-Length: ' . $tamano);
            }
            header('Cache-Control: no-store');
            header('X-Guardados: ' . (int) $resumen['documentos']);
            header('X-Guardados-Xml: ' . (int) $resumen['xml']);
            header('X-Guardados-Pdf: ' . (int) $resumen['pdf']);
            header('X-Sin-Xml: ' . (int) $resumen['sin_xml']);
            header('X-Sin-Pdf: ' . (int) $resumen['sin_pdf']);
            readfile($zipTemporal);
            @unlink($zipTemporal);
            exit;
        } catch (Throwable $e) {
            if ($zipTemporal !== '' && is_file($zipTemporal)) {
                @unlink($zipTemporal);
            }
            $this->json(['ok' => false, 'message' => 'No fue posible guardar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Saca de la bandeja las facturas que ya estaban en el sistema y que la
     * versión anterior dejaba ahí en gris: no se podían importar y solo
     * estorbaban. Borra también sus XML/PDF de storage, que ya no los va a
     * usar nadie. A partir de ahora ni siquiera entran (procesarMensaje).
     */
    private function purgarYaExisten($bandejaModel)
    {
        foreach ($bandejaModel->purgarYaExisten() as $fila) {
            foreach ([$fila['archivo_xml'] ?? '', $fila['archivo_pdf'] ?? ''] as $ruta) {
                if ($ruta !== '') {
                    AlmacenDocumentos::de($ruta)->borrar($ruta);
                }
            }
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
    /**
     * Punto compartido con la tarea programada: saca los comprobantes de un
     * correo y, si le pasan una EntradaAutomatica, los mete al sistema en el
     * acto en vez de dejarlos esperando en la Bandeja.
     *
     * Los dos modos capturan igual —la Bandeja sigue siendo la zona de paso, y
     * lo rechazado por Hacienda o de otra cédula se queda ahí en los dos
     * casos—. Lo que cambia es qué pasa con lo limpio.
     *
     * Los tipos son los mismos en los dos: facturas y notas de crédito. Antes,
     * sin entrada automática se capturaban solo facturas, porque la
     * importación manual de la Bandeja encolaba todo como 'FE' y una nota que
     * esperara ahí habría fallado al importarse. Desde que importar() encola
     * cada fila con su tipo, una nota espera y entra igual que una factura.
     *
     * @param EntradaAutomatica|null $entrada
     * @param array $correo  carpeta, uidvalidity, uid y fecha del mensaje
     */
    public function capturarMensajeAutomatico(array $mensaje, $cuentaId,
                                              array $sociedadesPermitidas = [],
                                              $entrada = null, array $correo = [])
    {
        $automatica = $entrada instanceof EntradaAutomatica;

        $captura = $this->procesarMensaje(
            $mensaje,
            $this->loadModel('CorreoBandeja'),
            $this->loadModel('Factura'),
            (int) $cuentaId,
            EntradaAutomatica::TIPOS,
            '',
            0,
            $sociedadesPermitidas,
            'automatica',
            // Con entrada automática el comprobante se archiva a continuación,
            // en esta misma corrida: su paso por la Bandeja no lo ve nadie y
            // no tiene por qué salir de esta computadora. Lo que acabe
            // esperando ahí lo sube ingresar(). Ver carpetaTrabajoLocal().
            $automatica
        );

        $captura['entrada'] = $automatica
            ? $entrada->ingresar($captura, array_merge($correo, ['cuenta_id' => (int) $cuentaId]))
            : null;

        return $captura;
    }

    /**
     * Un correo a la vez, en todo el grupo.
     *
     * Procesar el mismo correo dos veces a la vez no da dos filas —la segunda
     * revive la de la primera—, pero sí dos juegos de archivos: cada una sube
     * los suyos y a lo sumo borra los de la otra, y en SharePoint son varias
     * llamadas por red por cada uno. El 19/09/2026, con el botón "Procesar
     * este correo" sin bloquearse mientras trabajaba, seis clics dejaron el
     * mismo XML subido seis veces a la bandeja.
     *
     * El candado es de la base (GET_LOCK), así que vale entre pestañas y entre
     * computadoras. Quien llega segundo no espera ni repite: el correo ya se
     * está guardando, y lo dice.
     */
    private function procesarMensaje(array $mensaje, $bandejaModel, $facturaModel,
                                     $cuentaId = 0, array $tiposPermitidos = ['FE'],
                                     $cedulaForzada = '', $sociedadIdForzada = 0,
                                     array $sociedadesPermitidas = [], $origen = 'manual',
                                     $seImportaEnElActo = false)
    {
        require_once __DIR__ . '/../models/BloqueoEnBase.php';
        $candado = 'captura_' . substr(sha1((int) $cuentaId . '|' . (string) ($mensaje['clave'] ?? '')), 0, 24);
        $tomado = BloqueoEnBase::tomar($candado);
        if ($tomado === false) {
            // Los adjuntos que se bajaron para esta vuelta no van a ningún
            // lado: son de esta máquina y se borran acá.
            foreach (array_merge($mensaje['xmls'] ?? [], $mensaje['pdfs'] ?? []) as $adjunto) {
                if (!empty($adjunto['ruta'])) {
                    @unlink((string) $adjunto['ruta']);
                }
            }
            return [
                'nuevas' => 0, 'ya_existentes' => 0, 'aceptadas' => 0, 'rechazadas' => 0,
                'otra_cedula' => 0, 'pdfs_guardados' => 0, 'pdfs_sin_identificar' => 0,
                'pdfs_duplicados_omitidos' => 0, 'repetidas' => [], 'filas' => [], 'archivos_huerfanos' => [],
                'en_curso' => 1,
                'errores' => ['El correo "' . mb_substr((string) ($mensaje['asunto'] ?? ''), 0, 80, 'UTF-8')
                    . '" ya se está guardando (en esta u otra ventana, o en otra computadora): '
                    . 'no se repitió. Espera a que termine.'],
            ];
        }
        try {
            return $this->procesarMensajeSinCandado($mensaje, $bandejaModel, $facturaModel, $cuentaId,
                $tiposPermitidos, $cedulaForzada, $sociedadIdForzada, $sociedadesPermitidas, $origen,
                $seImportaEnElActo);
        } finally {
            if ($tomado === true) {
                BloqueoEnBase::soltar($candado);
            }
        }
    }

    /**
     * Dónde dejar el adjunto recién bajado: en la zona de paso del grupo o en
     * la de esta computadora.
     *
     * El criterio es quién lo va a necesitar. Si el comprobante se archiva a
     * continuación en esta misma corrida, nadie más: se queda acá y se ahorra
     * el viaje de ida y vuelta a SharePoint. Si va a ESPERAR en la Bandeja a
     * que alguien lo mire, tiene que poder abrirlo quien lo mire, esté en la
     * computadora que esté.
     */
    private function carpetaBandeja($que, $sociedadId, $seImportaEnElActo)
    {
        $sub = 'BANDEJA/' . $que . '/' . RutaDocumento::delMes();

        return $seImportaEnElActo
            ? RutaDocumento::carpetaTrabajoLocal($sub)
            : RutaDocumento::carpetaTrabajo($sub, $sociedadId);
    }

    private function procesarMensajeSinCandado(array $mensaje, $bandejaModel, $facturaModel,
                                               $cuentaId, array $tiposPermitidos, $cedulaForzada,
                                               $sociedadIdForzada, array $sociedadesPermitidas, $origen,
                                               $seImportaEnElActo = false)
    {
        $resultado = [
            'nuevas' => 0,
            'notas_nuevas' => 0,
            'ya_existentes' => 0,
            'aceptadas' => 0,
            'rechazadas' => 0,
            'otra_cedula' => 0,
            'pdfs_guardados' => 0,
            'pdfs_sin_identificar' => 0,
            'pdfs_duplicados_omitidos' => 0,
            'errores' => [],
            // Las que ya estaban en el sistema, con nombre y empresa: la
            // pantalla las enseña una por una, no como un número.
            'repetidas' => [],
            'filas' => [],
            'archivos_huerfanos' => [],
        ];

        // Cédula de la sociedad activa (se elige en Inicio): toda factura
        // debe venir a nombre de esa cédula como receptor
        $cedulaEmpresa = preg_replace('/\D+/', '', (string) $cedulaForzada);
        $sociedadIdDestino = max(0, (int) $sociedadIdForzada);
        $sociedadesPorCedula = [];
        foreach ($sociedadesPermitidas as $sociedad) {
            $cedula = preg_replace('/\D+/', '', (string) ($sociedad['cedula'] ?? ''));
            $id = (int) ($sociedad['id'] ?? 0);
            if ($cedula !== '' && $id > 0) {
                $sociedadesPorCedula[$cedula] = $id;
                if ($sociedadIdDestino <= 0) {
                    $sociedadIdDestino = $id;
                }
            }
        }

        if (!$sociedadesPorCedula && ($cedulaEmpresa === '' || $sociedadIdDestino <= 0)) {
            try {
                $activa = $this->loadModel('Sociedad')->getActiva();
                if ($activa) {
                    if ($cedulaEmpresa === '') {
                        $cedulaEmpresa = preg_replace('/\D+/', '', (string) $activa['cedula']);
                    }
                    if ($sociedadIdDestino <= 0) {
                        $sociedadIdDestino = (int) $activa['id'];
                    }
                }
            } catch (Throwable $e) {
                // Sin sociedades registradas no se verifica cédula
            }
        }

        // 1) Clasificar cada XML: mensaje de Hacienda vs factura
        $mensajesHacienda = [];   // clave (50 dígitos) => ['codigo','detalle']
        $facturas = [];
        // Comprobantes que se leyeron bien pero no se capturan por este camino
        // (una nota de crédito a mano, una nota de débito siempre).
        $apartados = [];

        foreach ($mensaje['xmls'] as $adjunto) {
            $clasificacion = $this->clasificarXml($adjunto['ruta']);

            if ($clasificacion['tipo'] === 'mensaje_hacienda') {
                // El MensajeHacienda solo sirve para validar un comprobante
                // adjunto. Nunca se convierte por sí solo en FE o NC.
                $clasificacion['adjunto'] = $adjunto;
                $clave = $clasificacion['clave'] !== '' ? $clasificacion['clave'] : ('sin_clave_' . count($mensajesHacienda));
                $mensajesHacienda[$clave] = $clasificacion;
                continue;
            }

            try {
                $docData = XmlInvoiceParser::parseCfdiFromFile($adjunto['ruta']);

                $tipoDetectado = strtoupper((string) ($docData['tipo_documento'] ?? 'FE'));
                // Las notas de débito no se guardan nunca, no es cosa del modo:
                // se dice distinto para que quien lea el resultado no se ponga a
                // buscar qué modo habría que cambiar.
                $numeroDetectado = $this->numeroCorto((string) ($docData['numero_factura_asistente'] ?? ''));
                if (in_array($tipoDetectado, XmlDocumentImporter::NUNCA_SE_GUARDAN, true)) {
                    $resultado['errores'][] = 'Nota de débito descartada (no se guardan): ' . $adjunto['nombre'];
                    $apartados[] = ['numero_corto' => $numeroDetectado];
                    @unlink($adjunto['ruta']);
                    continue;
                }
                if (!in_array($tipoDetectado, $tiposPermitidos, true)) {
                    // Decirlo con su nombre. "Documento NC omitido en este
                    // modo" no le decía a nadie que el correo era una nota de
                    // crédito ni por dónde entran las notas.
                    $resultado['errores'][] = $tipoDetectado === 'NC'
                        ? 'Nota de crédito ' . ($numeroDetectado !== '' ? $numeroDetectado : $adjunto['nombre'])
                            . ': por aquí solo se capturan facturas. Las notas de crédito entran con la'
                            . ' captura automática o con el modo Descargas.'
                        : 'Documento ' . $tipoDetectado . ' omitido en este modo: ' . $adjunto['nombre'];
                    $apartados[] = ['numero_corto' => $numeroDetectado];
                    @unlink($adjunto['ruta']);
                    continue;
                }

                $facturas[] = [
                    'adjunto' => $adjunto,
                    'clave' => trim((string) ($docData['clave'] ?? '')),
                    // Lo que hace falta para preguntar si ya está cargado con
                    // la misma regla del importador. Ver Factura::yaCargado().
                    'consecutivo' => (string) ($docData['consecutivo_completo'] ?? ''),
                    'rfc_emisor' => (string) ($docData['rfc_emisor'] ?? ''),
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

        // Todos los MensajeHacienda se descartan después de la verificación.
        // Si el correo no traía un XML de comprobante, no se crea ninguna fila.
        foreach ($mensajesHacienda as $mh) {
            @unlink($mh['adjunto']['ruta']);
        }

        // 2) Emparejar PDFs: 1 XML + 1 PDF → directo; varios → por el número
        //    del nombre del PDF vs número de cada XML. Se prueba en orden:
        //    igualdad exacta, número extraído de la clave de 50 dígitos
        //    (PDFs nombrados "Factura#<clave>.pdf"), y la regla del núcleo
        //    "termina en" con relleno de ceros. Ante varias candidatas por
        //    "termina en" gana el número más largo (490 no le roba a 1490).
        $pdfPorIndice = [];
        $pdfsPrincipales = [];
        $pdfsInterpretacion = [];
        foreach ($mensaje['pdfs'] as $pdf) {
            if ($this->esPdfInterpretacion((string) ($pdf['nombre'] ?? ''))) {
                $pdfsInterpretacion[] = $pdf;
            } else {
                $pdfsPrincipales[] = $pdf;
            }
        }
        // Algunos emisores adjuntan dos representaciones del mismo documento:
        // FC-<clave>.pdf e Interpretacion_<clave>.PDF. Se prefiere la factura
        // principal y se deja Interpretacion como respaldo si es el único PDF.
        $pdfsRestantes = array_merge($pdfsPrincipales, $pdfsInterpretacion);

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

        // Un segundo PDF que identifica una factura ya emparejada es otra
        // representación del mismo comprobante, no un PDF huérfano. Se omite
        // para que no genere una incidencia falsa ni llegue a sin_identificar/.
        foreach ($pdfsRestantes as $k => $pdf) {
            foreach ($pdfPorIndice as $idx => $pdfPrincipal) {
                if (isset($facturas[$idx])
                    && $this->pdfCorrespondeFactura((string) $pdf['nombre'], $facturas[$idx])) {
                    @unlink($pdf['ruta']);
                    unset($pdfsRestantes[$k]);
                    $resultado['pdfs_duplicados_omitidos']++;
                    break;
                }
            }
        }

        // El PDF de un comprobante apartado tampoco es huérfano: su XML sí
        // vino, solo que no se captura por aquí. Antes seguía de largo hasta
        // sin_identificar/ con el aviso "vino sin su XML", y quien procesaba a
        // mano una nota de crédito leía un problema de PDF que no existía —y
        // cada intento dejaba otra copia del PDF en la carpeta—.
        $unicoApartado = count($apartados) === 1 && !$facturas && count($pdfsRestantes) === 1;
        foreach ($pdfsRestantes as $k => $pdf) {
            $esDeApartado = $unicoApartado;
            foreach ($apartados as $apartado) {
                $esDeApartado = $esDeApartado
                    || $this->pdfCorrespondeFactura((string) $pdf['nombre'], $apartado);
            }
            if ($esDeApartado) {
                @unlink($pdf['ruta']);
                unset($pdfsRestantes[$k]);
            }
        }

        // 3) Insertar cada factura en la bandeja
        foreach ($facturas as $idx => $factura) {
            $adjunto = $factura['adjunto'];

            // Si el mismo correo ya se capturó, se reemplazan sus temporales y
            // se reactiva la fila. La deduplicación se decide después, al
            // importar contra el pago elegido, no al abrir el correo.
            $filaPrevia = $factura['hash_xml'] !== ''
                ? $bandejaModel->getPorUidHash($mensaje['clave'], $factura['hash_xml'])
                : null;

            $esRechazada = ($factura['hacienda'] ?? null) === 'rechazada';

            // Verificación de cédula: el receptor del XML debe ser la
            // empresa configurada. Sin cédula configurada o sin receptor
            // legible en el XML no se bloquea.
            $esOtraCedula = false;
            $receptor = preg_replace('/\D+/', '', (string) $factura['receptor_id']);
            $sociedadFacturaId = $sociedadIdDestino;
            if ($sociedadesPorCedula) {
                if ($receptor !== '' && isset($sociedadesPorCedula[$receptor])) {
                    $sociedadFacturaId = (int) $sociedadesPorCedula[$receptor];
                } elseif (!$esRechazada && $receptor !== '') {
                    // Se conserva bajo la primera sociedad asociada para que
                    // el documento bloqueado sea visible y revisable.
                    $esOtraCedula = true;
                }
            } elseif (!$esRechazada && $cedulaEmpresa !== '') {
                $esOtraCedula = $receptor !== '' && $receptor !== $cedulaEmpresa;
            }

            $estado = 'pendiente';
            if ($esRechazada) {
                $estado = 'rechazada';
            } elseif ($esOtraCedula) {
                $estado = 'otra_cedula';
            }

            /*
             * Lo que ya está cargado no vuelve a entrar a la Bandeja.
             *
             * Antes entraba: se marcaba "pendiente", alguien la importaba,
             * subía y bajaba el par por la red y salía "duplicado". Desde la
             * pantalla eso se ve como un clic que no hace nada. Ahora se dice
             * en el momento, y en qué empresa está —el mismo buzón sirve a
             * varias, y la clave única de facturas_xml no lleva la empresa, así
             * que el mismo comprobante no puede estar en dos—.
             *
             * Se para SOLO si el comprobante conserva sus dos archivos. Si le
             * falta alguno sí entra: la importación lo vuelve a archivar, que
             * es la forma de reponer un documento perdido. Y solo se para lo
             * limpio: una rechazada por Hacienda o de otra cédula se queda en
             * la Bandeja aunque ya esté cargada, porque eso hay que mirarlo.
             */
            $yaCargado = $estado === 'pendiente' && $facturaModel instanceof Factura
                ? $facturaModel->yaCargado(
                    $factura['hash_xml'], $factura['consecutivo'],
                    $factura['rfc_emisor'], $factura['fecha_emision']
                )
                : null;
            if ($yaCargado !== null && EstadoArchivo::de($yaCargado)['entregable']) {
                @unlink($adjunto['ruta']);
                if (isset($pdfPorIndice[$idx])) {
                    @unlink($pdfPorIndice[$idx]['ruta']);
                    unset($pdfPorIndice[$idx]);
                }
                // La fila que dejó una captura anterior de este mismo correo
                // ya no lleva a ninguna parte: se va, con sus archivos.
                if ($filaPrevia) {
                    $bandejaModel->marcarYaExisten([(int) $filaPrevia['id']]);
                    $this->purgarYaExisten($bandejaModel);
                }
                $resultado['ya_existentes']++;
                $resultado['repetidas'][] = [
                    // Para nombrarla bien: una nota de crédito repetida no es "Factura X".
                    'tipo' => strtoupper((string) $factura['tipo_doc']),
                    'numero' => $factura['numero_corto'] !== '' ? $factura['numero_corto'] : $adjunto['nombre'],
                    'proveedor' => $factura['proveedor'],
                    'empresa' => (string) ($yaCargado['sociedad_nombre'] ?? ''),
                    'misma_empresa' => (int) ($yaCargado['sociedad_id'] ?? 0) === $sociedadFacturaId,
                    'cargada_en' => (string) ($yaCargado['creado_en'] ?? ''),
                ];
                continue;
            }

            // Mover el XML de tmp/ a la bandeja (nombre con prefijo removible
            // "__"). La bandeja vive en SharePoint: la fila que se crea abajo
            // la ve todo el grupo, así que el archivo también tiene que estar
            // donde todos lleguen. Una carpeta por mes, para que no acumule
            // miles de archivos en un solo lugar.
            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $adjunto['nombre']);
            try {
                $rutaXml = $this->carpetaBandeja('xml', $sociedadFacturaId, $seImportaEnElActo)
                    . DIRECTORY_SEPARATOR . uniqid('correo_', true) . '__' . $nombreSeguro;
                AlmacenDocumentos::de($rutaXml)->adoptar($rutaXml, $adjunto['ruta']);
            } catch (Throwable $e) {
                // El motivo va en el aviso: con SharePoint no es lo mismo "no
                // hay red" que "esta PC no tiene credenciales".
                $resultado['errores'][] = 'No se pudo guardar el XML "' . $adjunto['nombre'] . '": ' . $e->getMessage();
                continue;
            }

            // Guardar su PDF ya nombrado con el número de factura.
            // El PDF de una factura rechazada por Hacienda o de otra
            // cédula se descarta: no debe llegar a las carpetas de trabajo.
            // Toda factura pendiente debe quedar con su par XML+PDF: si el
            // PDF no vino en el correo o no se pudo guardar, se avisa.
            $etiquetaFactura = (strtoupper((string) $factura['tipo_doc']) === 'NC' ? 'La nota de crédito ' : 'La factura ')
                . ($factura['numero_corto'] !== '' ? $factura['numero_corto'] : $adjunto['nombre']);
            $rutaPdf = null;
            if (isset($pdfPorIndice[$idx])) {
                if ($esRechazada || $esOtraCedula) {
                    @unlink($pdfPorIndice[$idx]['ruta']);
                } else {
                    $numero = $factura['numero_corto'] !== '' ? $factura['numero_corto'] : pathinfo($nombreSeguro, PATHINFO_FILENAME);
                    try {
                        $rutaPdf = $this->carpetaBandeja('pdf', $sociedadFacturaId, $seImportaEnElActo)
                            . DIRECTORY_SEPARATOR . uniqid('correo_pdf_', true) . '__' . $numero . '.pdf';
                        AlmacenDocumentos::de($rutaPdf)->adoptar($rutaPdf, $pdfPorIndice[$idx]['ruta']);
                        $resultado['pdfs_guardados']++;
                    } catch (Throwable $e) {
                        // El documento sigue adelante sin PDF: el XML es el
                        // comprobante, y quedarse sin la factura entera por no
                        // haber podido guardar su representación sería peor.
                        $rutaPdf = null;
                        $resultado['errores'][] = $etiquetaFactura
                            . ' quedó SIN PDF: no se pudo guardar "'
                            . mb_substr((string) $pdfPorIndice[$idx]['nombre'], 0, 80, 'UTF-8') . '".';
                    }
                }
            } elseif (!$esRechazada && !$esOtraCedula) {
                $resultado['errores'][] = $etiquetaFactura
                    . ' vino sin PDF en este correo: al importarla solo quedará el XML en la carpeta.';
            }

            $datosFila = [
                'cuenta_id' => (int) $cuentaId,
                'sociedad_id' => $sociedadFacturaId > 0 ? $sociedadFacturaId : null,
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
                'origen' => in_array($origen, ['manual', 'automatica', 'descargas'], true)
                    ? $origen : 'manual',
            ];

            if ($filaPrevia) {
                $rutasAnteriores = [
                    (string) ($filaPrevia['archivo_xml'] ?? ''),
                    (string) ($filaPrevia['archivo_pdf'] ?? ''),
                ];
                $bandejaModel->revivir((int) $filaPrevia['id'], $datosFila);
                $filaId = (int) $filaPrevia['id'];
                // Si la fila estaba importada y su importación todavía espera
                // en la cola, la cola apunta a estos archivos (no los copia):
                // ese ítem va a fallar con "no se encontró". No se pierde
                // nada —la fila revivida trae el MISMO XML en su archivo
                // nuevo y vuelve a quedar pendiente—, y dejar los viejos
                // sería acumular basura en la bandeja.
                foreach ($rutasAnteriores as $rutaAnterior) {
                    if ($rutaAnterior !== ''
                        && $rutaAnterior !== $rutaXml
                        && $rutaAnterior !== (string) $rutaPdf) {
                        AlmacenDocumentos::de($rutaAnterior)->borrar($rutaAnterior);
                    }
                }
            } else {
                $filaId = (int) $bandejaModel->crear($datosFila);
            }
            $resultado['filas'][] = [
                'id' => $filaId, 'estado' => $estado,
                'tipo_doc' => $factura['tipo_doc'],
                'sociedad_id' => $sociedadFacturaId,
            ];

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
            } else {
                $resultado['nuevas']++;
                if (strtoupper((string) $factura['tipo_doc']) === 'NC') {
                    $resultado['notas_nuevas']++;
                }
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
            $guardado = true;
            try {
                $destino = RutaDocumento::carpetaTrabajo('BANDEJA/sin-identificar/' . RutaDocumento::delMes(), $sociedadIdDestino)
                    . DIRECTORY_SEPARATOR . uniqid('pdf_', true) . '__' . $nombreSeguro;
                AlmacenDocumentos::de($destino)->adoptar($destino, $pdf['ruta']);
            } catch (Throwable $e) {
                $guardado = false;
            }
            if ($guardado) {
                $resultado['pdfs_sin_identificar']++;
                $resultado['archivos_huerfanos'][] = $destino;

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

    // Aquí vivía config(): guardaba la carpeta compartida de documentos
    // (carpeta_destino). Desde el 27/09/2026 los documentos van a SharePoint y
    // no hay carpeta que elegir.

    // Aquí vivían organizar() y organizarPrevisualizar(), con su tarjeta en
    // Configuración: elegir el alcance, ver qué haría y confirmar.
    //
    // Salieron porque no había nada que decidir. La carpeta de un documento la
    // deciden su fecha de emisión y su tipo —que no cambian nunca— y su pago
    // de pago; no hay dos respuestas posibles que justifiquen mirar una lista
    // antes de aceptarla. Lo que sí había era una pantalla más que alguien
    // tenía que acordarse de visitar para que el archivo quedara en su sitio, y
    // mientras no la visitara, la carpeta del pago no se armaba.
    //
    // Ahora lo acomoda la tarea programada (cli/sync_correo.php), como mucho
    // cada quince minutos y solo sobre documentos registrados. Nunca borra:
    // mueve el par a la carpeta de su mes y COPIA a la del pago. Para
    // forzarlo a mano sigue estando `php cli/organizar_documentos.php
    // --forzar-orden`.

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
        $cola = null;
        $revisionPendiente = null;
        try {
            require_once __DIR__ . '/../models/CorreoCapturaAutomatica.php';
            $cola = (new CorreoCapturaAutomatica())->resumen();
            $conteoBandeja = $this->loadModel('CorreoBandeja')->contarPorEstado();
            $revisionPendiente = (int) ($conteoBandeja['pendiente'] ?? 0);
        } catch (Throwable $e) {
            // La configuración de la tarea se puede consultar aun durante
            // una instalación que todavía no aplicó la migración de cola.
        }

        $this->json([
            'ok'              => true,
            'soportado'       => DIRECTORY_SEPARATOR === '\\' && function_exists('exec'),
            'activo'          => !empty($cfg['activo']),
            'intervalo_min'   => max(1, min(1440, (int) ($cfg['intervalo_min'] ?? 10))),
            'capturar_nuevos' => !empty($cfg['capturar_nuevos']),
            'entrada_automatica' => EntradaAutomatica::activa(is_array($cfg) ? $cfg : []),
            'max_correos_corrida' => max(1, min(200, (int) ($cfg['max_correos_corrida'] ?? 20))),
            'max_intentos'    => max(1, min(10, (int) ($cfg['max_intentos'] ?? 3))),
            'cola_captura'    => $cola,
            'revision_pendiente' => $revisionPendiente,
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
        $capturarNuevos = in_array(
            strtolower(trim((string) $this->post('capturar_nuevos', '0'))),
            ['1', 'true', 'on', 'si', 'sí'],
            true
        );
        $maxCorreos = max(1, min(200, (int) $this->post('max_correos_corrida', 20)));
        $maxIntentos = max(1, min(10, (int) $this->post('max_intentos', 3)));
        // Que lo capturado entre solo al sistema. Es lo normal: si la petición
        // no dice nada —una pantalla vieja en caché— se conserva lo guardado.
        $entradaAutomatica = in_array(
            strtolower(trim((string) $this->post(
                'entrada_automatica',
                EntradaAutomatica::activa(
                    is_array($this->configLocal()['auto_sync'] ?? null)
                        ? $this->configLocal()['auto_sync'] : []
                ) ? '1' : '0'
            ))),
            ['1', 'true', 'on', 'si', 'sí'],
            true
        );
        /*
         * Cuántas copias del sincronizador corren a la vez. El tope de 12 no
         * es del sistema sino del servidor de correo: pasarse de conexiones
         * simultáneas contra el buzón hace que empiece a rechazarlas.
         *
         * Si no viene en la petición se conserva lo que ya estaba, no se
         * vuelve a 1: la pantalla todavía no ofrece este campo, y guardar
         * cualquier otro ajuste no tiene por qué deshacer este.
         */
        $autoGuardado = is_array($this->configLocal()['auto_sync'] ?? null)
            ? $this->configLocal()['auto_sync'] : [];
        $trabajadores = max(1, min(12, (int) $this->post(
            'trabajadores',
            (int) ($autoGuardado['trabajadores'] ?? 1)
        )));

        // La Bandeja y el archivo viven en SharePoint: sin sus credenciales,
        // la captura automática no tendría dónde guardar lo que baja.
        if ($capturarNuevos && !AlmacenDocumentos::hayPrincipal()) {
            $this->json([
                'ok' => false,
                'message' => 'La captura automática guarda en SharePoint. ' . AlmacenDocumentos::SIN_SHAREPOINT,
            ], 422);
        }

        $php = $this->rutaPhpCli();
        if ($php === null) {
            $this->json(['ok' => false, 'message' => 'No se encontró php.exe en la instalación del servidor.'], 422);
        }

        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'sync_correo.php';
        if (!is_file($script)) {
            $this->json(['ok' => false, 'message' => 'No se encontró el script cli/sync_correo.php.'], 500);
        }

        // Tope de cada corrida acorde al intervalo: aprovecha casi todo el
        // hueco entre corridas (el lock de archivo impide solaparse). Con un
        // rezago grande de adjuntos/CC, un tope corto deja el índice
        // trabajando una fracción del tiempo y la cola nunca termina.
        $topeSegundos = max(30, min(3600, $intervalo * 60 - 10));

        // Lanzador .vbs: ejecuta php OCULTO (sin ventana de consola cada N min)
        try {
            $vbs = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'sync_launch.vbs';
            $cmd = '"' . $php . '" "' . $script . '" ' . $topeSegundos;
            $vbsCmd = str_replace('"', '""', $cmd);

            /*
             * Varias copias del sincronizador, no una.
             *
             * Hablarle al servidor de correo es esperar: 105 ms por viaje. Con
             * un buzón se nota poco; con 42 la fila no cabe en la ventana. Las
             * copias se reparten los buzones solas por el candado de cada uno
             * —no hay coordinador— así que lanzarlas es literalmente repetir
             * la línea. La CPU casi no se entera: están bloqueadas en la red.
             *
             * Por omisión sigue siendo una: subirlo es una decisión que hay
             * que tomar sabiendo cuántas conexiones simultáneas tolera el
             * servidor de correo.
             */
            $lineas = '';
            for ($i = 0; $i < $trabajadores; $i++) {
                $lineas .= 'CreateObject("WScript.Shell").Run "' . $vbsCmd . '", 0, False' . "\r\n";
            }
            file_put_contents($vbs, $lineas);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo crear el lanzador: ' . $e->getMessage()], 500);
        }

        [$codigo, $salida] = $this->ejecutarPowerShell($this->scriptRegistrarTareaSync($vbs, $intervalo));

        if ($codigo !== 0) {
            $detalle = trim(implode(' ', array_slice($salida, 0, 6)));
            $this->json(['ok' => false, 'message' => 'No se pudo registrar la tarea programada' . ($detalle !== '' ? ': ' . $detalle : '.')], 500);
        }

        $configActual = $this->configLocal();
        $autoAnterior = is_array($configActual['auto_sync'] ?? null)
            ? $configActual['auto_sync'] : [];
        $capturaActivadaEn = (string) ($autoAnterior['captura_activada_en'] ?? '');
        if ($capturarNuevos && empty($autoAnterior['capturar_nuevos'])) {
            // Este corte evita que la primera indexación automática meta
            // todo el archivo histórico en revisión.
            $capturaActivadaEn = date('Y-m-d H:i:s');
        }
        $configActual['auto_sync'] = array_merge($autoAnterior, [
            'activo'               => true,
            'intervalo_min'        => $intervalo,
            'capturar_nuevos'      => $capturarNuevos,
            'entrada_automatica'   => $entradaAutomatica,
            'max_correos_corrida'  => $maxCorreos,
            'max_intentos'         => $maxIntentos,
            'trabajadores'         => $trabajadores,
            'captura_activada_en'  => $capturaActivadaEn,
            'php'                  => $php,
            'actualizado'          => date('Y-m-d H:i:s'),
        ]);
        $this->guardarConfigLocal($configActual);

        $this->json([
            'ok'            => true,
            'intervalo_min' => $intervalo,
            'capturar_nuevos' => $capturarNuevos,
            'php'           => $php,
            'entrada_automatica' => $entradaAutomatica,
            'message'       => 'Automatización activada cada ' . $intervalo . ' min. '
                . (!$capturarNuevos
                    ? 'Solo se actualizará el índice.'
                    : ($entradaAutomatica
                        ? 'Los comprobantes nuevos entrarán solos al sistema; en la Bandeja quedará '
                            . 'lo que haya que revisar.'
                        : 'Los correos nuevos quedarán en la Bandeja hasta que una persona los importe.')),
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

    /** Ubica el PHP CLI asociado al servidor actual. */
    private function rutaPhpCli()
    {
        $candidatos = [
            trim((string) getenv('XMLCONCILIA_PHP_CLI')),
            'C:\\WebServer\\PHP84\\php.exe',
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
     * el lanzador .vbs cada N minutos, indefinidamente, con la cuenta del
     * servidor web y sin depender de que alguien tenga la sesión abierta
     * (ver TareaProgramada). Sin acentos: se ejecuta en PowerShell 5.1.
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

{{PRINCIPAL}}
$ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

Register-ScheduledTask -TaskName $tn -Action $accion -Trigger $t -Principal $principal -Settings $ajustes -Force | Out-Null

# Primer refresco inmediato para que se vea trabajar
try { Start-ScheduledTask -TaskName $tn } catch { }
Write-Output 'OK'
POWERSHELL;

        return str_replace(
            ['{{VBS}}', '{{MIN}}', '{{PRINCIPAL}}'],
            [str_replace("'", "''", $vbs), (int) $intervaloMin, TareaProgramada::principalPowerShell()],
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
                'indice_retencion_dias' => max(0, min(3650,
                    (int) $this->post('indice_retencion_dias', 1825))),
            ];

            if ($datos['nombre'] === '' || $datos['host'] === '' || $datos['usuario'] === '') {
                $this->json(['ok' => false, 'message' => 'Nombre, host y usuario son obligatorios.'], 422);
            }
            if ($id <= 0 && trim($datos['password']) === '') {
                $this->json(['ok' => false, 'message' => 'La contraseña es obligatoria al crear una cuenta.'], 422);
            }

            $cuentas = $this->loadModel('CorreoCuenta');

            // Sociedades a las que sirve el buzón. Un mismo correo puede
            // recibir facturas de varias empresas del grupo, así que es una
            // lista y no un solo valor. Si no viene ninguna, se conserva lo
            // que ya tenía (o, al crear, la sociedad en curso).
            $sociedades = $this->post('sociedades', null);
            if (is_string($sociedades)) {
                $sociedades = array_filter(array_map('intval', explode(',', $sociedades)));
            }
            if (is_array($sociedades)) {
                $sociedades = array_values(array_filter(array_map('intval', $sociedades)));
            }

            if ($id > 0) {
                if ($cuentas->getById($id) === null) {
                    $this->json(['ok' => false, 'message' => 'La cuenta no existe.'], 422);
                }
                $cuentas->actualizar($id, $datos);
                if (is_array($sociedades) && $sociedades) {
                    $cuentas->asignarSociedades($id, $sociedades);
                }
            } else {
                if (is_array($sociedades) && $sociedades) {
                    $datos['sociedades'] = $sociedades;
                }
                $id = (int) $cuentas->crear($datos);
            }

            $this->json(['ok' => true, 'id' => $id, 'sociedades' => $cuentas->sociedadesDe($id)]);
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
    // Recordar el pago elegido en la bandeja se fue con el selector: la
    // bandeja ya no pregunta a qué pago va lo que se importa, porque cada
    // comprobante la hereda de su factura del ERP.

    public function cuentaUsar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        try {
            $id = (int) $this->post('id', 0);
            $cuentas = $this->loadModel('CorreoCuenta');
            if ($cuentas->getById($id) === null) {
                $this->json(['ok' => false, 'message' => 'La cuenta no existe.'], 422);
            }
            // No se puede quedar "trabajando con" un buzón de otra empresa.
            if (!$cuentas->perteneceASociedad($id)) {
                $this->json([
                    'ok' => false,
                    'message' => 'Ese buzón no atiende a la sociedad en curso. Asígnaselo primero en el engranaje ⚙.',
                ], 422);
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

    // Aquí vivían el explorador de carpetas del disco (carpetas()) y el
    // selector con la ventana de Windows (selectorAbrir/selectorEstado): servían
    // para elegir la carpeta compartida, que ya no existe.

    /**
     * Lee storage/correo/config.json: el buzón en uso y la captura automática.
     * Si trae carpeta_destino —la carpeta compartida de antes— se conserva al
     * guardar: es lo que permite seguir abriendo lo que quedó ahí sin migrar.
     */
    private function configLocal()
    {
        if ($this->configLocalCache !== null) {
            return $this->configLocalCache;
        }

        $defaults = ['cedula' => ''];
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
        if ($prefijo === 'NC') {
            $core = ltrim(preg_replace('/\D+/', '', $core), '0');
            $core = $core !== '' ? $core : '0';
            $numero = str_pad(substr($core, -8), 8, '0', STR_PAD_LEFT);
        } else {
            $numero = strlen($core) >= 8 ? $core : str_pad($core, 8, '0', STR_PAD_LEFT);
        }

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
        return trim(mb_substr($token, 0, DocumentoArchivo::MAX_TOKEN_PROVEEDOR, 'UTF-8'), '_');
    }

    // ── Utilidades ─────────────────────────────────────────────────

    /**
     * Distingue el XML del mensaje de Hacienda (aceptación/rechazo) del XML
     * de la factura. El mensaje tiene raíz MensajeHacienda (o MensajeReceptor)
     * con Clave, Mensaje (1 aceptado, 2 parcial, 3 rechazado) y DetalleMensaje.
     */
    /**
     * De qué es esta incidencia, leído del texto del error.
     *
     * Existe para que la pantalla pueda ofrecer "descartar todas las de este
     * tipo": mientras los mensajes de Hacienda y las cédulas ajenas cayeran
     * todos en 'adjunto' o 'xml_invalido', había que descartarlos de a uno
     * leyendo cada renglón.
     */
    private function tipoIncidencia($texto, $porOmision = 'adjunto')
    {
        $texto = (string) $texto;
        if (stripos($texto, 'mensaje de Hacienda') !== false) {
            return 'mensaje_hacienda';
        }
        if (stripos($texto, 'nota de débito') !== false || stripos($texto, 'nota de debito') !== false) {
            return 'nota_debito';
        }
        if (stripos($texto, 'otra cédula') !== false || stripos($texto, 'otra cedula') !== false) {
            return 'receptor';
        }
        if (stripos($texto, 'rechaz') !== false) {
            return 'rechazado';
        }
        return $porOmision;
    }

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

    private function pdfCorrespondeFactura($nombre, array $factura)
    {
        $numero = (string) ($factura['numero_corto'] ?? '');
        if ($numero === '') {
            return false;
        }

        $corePdf = $this->numeroCorto((string) $nombre);
        $clavePdf = $this->numeroDesdeClave((string) $nombre);
        return $numero === $corePdf
            || ($clavePdf !== '' && $numero === $clavePdf)
            || ($corePdf !== '' && FacturaMatcher::nucleoTerminaEn($corePdf, $numero));
    }

    private function esPdfInterpretacion($nombre)
    {
        return preg_match('/interpretaci[oó]n/iu', (string) $nombre) === 1;
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

        return [
            'host' => (string) ($config['host'] ?? ''),
            'usuario' => $this->enmascararUsuario((string) ($config['usuario'] ?? '')),
            'carpeta' => (string) ($config['carpeta'] ?? 'INBOX'),
            'dias_atras' => (int) ($config['dias_atras'] ?? 14),
            'solo_no_leidos' => !empty($config['solo_no_leidos']),
        ];
    }

    /**
     * El usuario del buzón, a medio tapar.
     *
     * Se enmascara SIEMPRE: antes solo si la arroba estaba más allá del
     * segundo carácter, así que "ab@empresa.cr" y un usuario sin arroba
     * ("contabilidad01") salían completos a la pantalla.
     */
    private function enmascararUsuario($usuario)
    {
        $usuario = (string) $usuario;
        if ($usuario === '') {
            return '';
        }

        $arroba = mb_strpos($usuario, '@', 0, 'UTF-8');
        $local = $arroba === false ? $usuario : mb_substr($usuario, 0, $arroba, 'UTF-8');
        $dominio = $arroba === false ? '' : mb_substr($usuario, $arroba, null, 'UTF-8');

        $largo = mb_strlen($local, 'UTF-8');
        $visibles = $largo > 2 ? 2 : ($largo > 1 ? 1 : 0);

        return mb_substr($local, 0, $visibles, 'UTF-8')
            . str_repeat('•', max(3, $largo - $visibles))
            . $dominio;
    }
}
