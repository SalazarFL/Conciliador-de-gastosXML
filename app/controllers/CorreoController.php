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
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/RutaDocumento.php';
require_once __DIR__ . '/../helpers/XmlDocumentImporter.php';
require_once __DIR__ . '/../helpers/PaqueteDocumentos.php';

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
         * "Buscar el electrónico" —del pago semanal o de la cola de
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
            'abrirCorreoCarpeta' => mb_substr(trim((string) $this->get('abrir_carpeta', '')), 0, 255, 'UTF-8'),
            'navDoc'          => $navDoc,
            'bandeja'         => $bandeja,
            'historial'       => $historial,
            'conteo'          => $conteo,
            'carpetasCorreo'  => $carpetasCorreo,
            'modoCorreo'      => $modo,
            'loteGeneral'     => $loteGeneral,
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
            if ($indice->contarTotal() === 0) {
                // Primer uso: construir el índice ahora
                @set_time_limit(300);
                try {
                    $this->ejecutarSincronizacion($config, $indice);
                } catch (Throwable $e) {
                    $this->json(['ok' => false, 'message' => 'No fue posible construir el índice del buzón: ' . $e->getMessage()], 500);
                }
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

            $buscarLocal = function ($mesFiltro = '', $permitirFallbackTexto = true,
                                    $fechaDesdeFiltro = '', $fechaHastaFiltro = '') use ($indice, $usarIndiceNumero, $terminoObjetivo, $texto, $ambito, $config, $carpeta, $porPagina, $offset) {
                if ($usarIndiceNumero) {
                    $resultado = $indice->buscarPorNumero(
                        $terminoObjetivo,
                        (int) $config['dias_atras'],
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
                    (int) $config['dias_atras'],
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
                // La tarjeta sin resultados cercanos amplía automáticamente
                // a todo el buzón. La bandeja llega aquí desde el inicio y
                // conserva sus filtros de días/carpeta/ámbito.
                $lista = $buscarLocal();
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
            if ($ambito === 'asunto_remitente' && $terminoObjetivo !== '' && !$coincidenciaLocalObjetivo
                && $respaldoAcotado
                && $pendientesIndice > 0) {
                // Búsqueda TEXT contra el buzón, carpeta por carpeta: es la
                // que tarda un minuto. Solo se llega aquí cuando el número no
                // está en el índice, y eso pasa mientras queden adjuntos sin
                // leer. Se avisa en la respuesta para poder explicarlo.
                $respaldoBuzon = true;
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
                    $porPagina, $offset, $indice
                );

                // La tarjeta terminó primero toda la búsqueda cercana. Si
                // no hubo nada, ahora sí amplía tanto el índice como IMAP a
                // todas las fechas, sin reutilizar filtros de la bandeja.
                $mimeAmplio = false;
                if ($rangoTarjetaProbado && (int) $listaMime['total'] === 0) {
                    $lista = $buscarLocal();
                    if ((int) $lista['total'] === 0) {
                        $configMimeTodo = $config;
                        $configMimeTodo['dias_atras'] = 0;
                        unset($configMimeTodo['fecha_desde'], $configMimeTodo['fecha_hasta']);
                        $listaMime = $this->buscarNumeroEnMime(
                            $configMimeTodo, $terminoObjetivo, '',
                            $porPagina, $offset, $indice
                        );
                    } else {
                        $listaMime = ['total' => 0, 'correos' => []];
                    }
                    $mimeAmplio = true;
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

    /** Busca un número en encabezados/cuerpo MIME e hidrata sus adjuntos. */
    private function buscarNumeroEnMime(array $config, $numero, $carpeta, $limite, $offset, $indice)
    {
        $fetcher = new MailFetcher($config);
        try {
            $fetcher->conectar();
            $lista = $fetcher->listarMensajes(
                (int) $limite,
                (string) $numero,
                'texto_mime',
                $carpeta !== '' ? (string) $carpeta : $this->carpetasCandidatas($fetcher, $indice, $config),
                (int) $offset
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
            return ['total' => 0, 'correos' => []];
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
        $unicos = [];
        foreach ($correos as $correo) {
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

            if (!isset($unicos[$llave])) {
                $unicos[$llave] = $correo;
            }
        }
        return array_values($unicos);
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
     * Solo corre UNA sincronización a la vez en todo el sistema (mismo
     * lock que el CLI): con varios usuarios abriendo el módulo, el primero
     * sincroniza y los demás siguen leyendo el índice sin abrir IMAP.
     */
    private function ejecutarSincronizacion(array $config, $indice, $presupuestoSegundos = 20)
    {
        require_once __DIR__ . '/../helpers/CorreoSync.php';

        $lock = CorreoSync::adquirirLock();
        if ($lock === null) {
            // Otro usuario o el cron ya están sincronizando: no se duplica
            // la conexión. completado=true corta el ciclo de tandas del JS.
            return [
                'carpetas' => 0, 'nuevos' => 0, 'reindexadas' => 0, 'restantes' => 0,
                'carpetas_totales' => 0, 'carpetas_por_revisar' => 0,
                'metadatos_resueltos' => 0, 'metadatos_pendientes' => 0,
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
            $adjuntos = $fetcher->listarAdjuntos($uid, $carpeta);
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
            'adjuntos' => $adjuntos,
            'cc' => $destinatarios['cc'] ?? '',
            'reply_to' => $destinatarios['reply_to'] ?? '',
            'cuerpo' => $cuerpo !== '' ? $cuerpo : '(Este correo no tiene texto legible; su contenido puede estar solo en los adjuntos.)',
        ]);
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

                if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                    $sinAdjuntos++;
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
            'ya_existentes' => $yaExistentes,
            'aceptadas' => $aceptadas,
            'rechazadas' => $rechazadas,
            'otra_cedula' => $otraCedula,
            'pdfs_guardados' => $pdfsGuardados,
            'pdfs_sin_identificar' => $pdfsSinIdentificar,
            'errores' => array_slice($errores, 0, 10),
        ]);
    }

    public function generalEstimar()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        try {
            $cuentaId = (int) $this->post('cuenta_id', 0);
            $config = $this->configCuenta($cuentaId);
            $cuentaId = (int) ($config['cuenta_id'] ?? $cuentaId);
            [$desde, $hasta] = $this->rangoGeneral();
            $correo = $this->correoBusquedaGeneral();
            $conteo = $this->loadModel('CorreoLote')->estimar($cuentaId, $desde, $hasta, $correo);
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
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function generalCrear()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        try {
            $cuentaId = (int) $this->post('cuenta_id', 0);
            $config = $this->configCuenta($cuentaId);
            if (!$config || !MailFetcher::configurado($config)) {
                throw new RuntimeException('La cuenta seleccionada no está configurada.');
            }
            $cuentaId = (int) ($config['cuenta_id'] ?? $cuentaId);
            [$desde, $hasta] = $this->rangoGeneral();
            $correo = $this->correoBusquedaGeneral();
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            if (!$sociedad) { throw new RuntimeException('Selecciona una sociedad activa antes de iniciar.'); }
            $raiz = trim((string) ($this->configLocal()['carpeta_destino'] ?? ''));
            if ($raiz === '') { throw new RuntimeException('Configura la carpeta raíz desde el engranaje de Correo.'); }
            new DocumentoArchivo($raiz); // valida creación y escritura antes de crear el lote

            // Actualización breve y best-effort; el lote también incluye los
            // mensajes cuyo detalle de adjuntos siga pendiente en el índice.
            try {
                $indice = $this->loadModel('CorreoIndice')->setCuenta($cuentaId);
                $this->ejecutarSincronizacion($config, $indice, 15);
            } catch (Throwable $e) {
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
            $this->json(['ok' => true, 'lote' => $lote]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function generalEstado()
    {
        if (!$this->isPost()) { $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405); }
        $id = (int) $this->post('lote_id', 0);
        $modelo = $this->loadModel('CorreoLote');
        $lote = $modelo->get($id);
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
                $_SESSION['user_id'] ?? null
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
            $ids = $this->idsRecibidos();
            if (!$ids) {
                $this->json(['ok' => false, 'message' => 'No se indicó ninguna incidencia.'], 422);
            }
            $r = $this->loadModel('CorreoLote')->restaurarIncidencias($ids);
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

        $r = $this->procesarTandaLote($loteId, $limit, 25);
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
            $lock = CorreoLote::adquirirLock();
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
            if ($fetcherPropio) { $fetcher->cerrar(); }
            return ['ok' => false, 'lote' => $lotes->get($loteId), 'procesados_ahora' => 0, 'incidencias' => [],
                    'message' => $e->getMessage(), 'status' => 500];
        }

        $bandeja = $this->loadModel('CorreoBandeja');
        $facturas = $this->loadModel('Factura');
        $importer = new XmlDocumentImporter((string) $lote['carpeta_raiz']);
        $notasNuevas = 0;

        // Presupuesto por tanda: se procesa lo que quepa y lo no alcanzado
        // vuelve a 'pendiente' para el siguiente viaje. Así un lote grande
        // avanza en tandas amplias sin arriesgar el timeout del servidor.
        $inicioLote = microtime(true);
        $presupuestoSegundos = max(5, (int) $presupuestoSegundos);
        $procesadosAhora = 0;

        foreach ($items as $indiceItem => $item) {
            if ($procesadosAhora > 0 && (microtime(true) - $inicioLote) > $presupuestoSegundos) {
                $lotes->devolverAPendiente(array_column(array_slice($items, $indiceItem), 'id'));
                break;
            }
            $resumen = ['estado' => 'completado', 'importados' => 0, 'duplicados' => 0, 'pdf_pendientes' => 0, 'detalle' => ''];
            try {
                $mensaje = $fetcher->extraerMensaje((int) $item['uid'], (string) $item['carpeta']);
                if (empty($mensaje['xmls']) && empty($mensaje['pdfs'])) {
                    $resumen['estado'] = 'omitido';
                    $resumen['detalle'] = 'Sin XML/PDF adjuntos.';
                } else {
                    $captura = $this->procesarMensaje(
                        $mensaje, $bandeja, $facturas, (int) $lote['cuenta_id'],
                        ['FE', 'NC'], (string) $lote['sociedad_cedula'],
                        (int) $lote['sociedad_id'], [], 'descargas'
                    );
                    $resumen['duplicados'] += (int) ($captura['ya_existentes'] ?? 0);

                    foreach (($captura['errores'] ?? []) as $error) {
                        $tipoIncidencia = $this->tipoIncidencia($error);
                        $lotes->incidencia($loteId, (int) $item['id'], $tipoIncidencia, $error, ['uid' => (int) $item['uid']]);
                    }
                    foreach (($captura['archivos_huerfanos'] ?? []) as $huerfano) {
                        if (is_string($huerfano) && is_file($huerfano)) { @unlink($huerfano); }
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
            $procesadosAhora++;
        }
        if ($fetcherCompartido === null) { $fetcher->cerrar(); }
        if ($notasNuevas > 0) { $this->revalidarNotasGeneral((int) $lote['sociedad_id']); }

        // Cruzar contra los pagos semanales cuesta millones de comparaciones
        // de texto, así que se hace UNA vez —al terminar el lote— y no en
        // cada tanda. Una vez completado, las llamadas siguientes salen antes
        // de llegar aquí, así que no se repite.
        $loteFinal = $lotes->get($loteId);
        if (($loteFinal['estado'] ?? '') === 'completado') {
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
            $lote = $this->loadModel('CorreoLote')->cambiarEstado((int) $this->post('lote_id', 0), $estado);
            $this->json(['ok' => true, 'lote' => $lote]);
        } catch (Throwable $e) { $this->json(['ok' => false, 'message' => $e->getMessage()], 422); }
    }

    private function rangoGeneral()
    {
        $desde = trim((string) $this->post('fecha_desde', ''));
        $hasta = trim((string) $this->post('fecha_hasta', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)
            || strtotime($hasta) < strtotime($desde)) {
            throw new InvalidArgumentException('Indica un rango de fechas válido.');
        }
        return [$desde, $hasta];
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
            if ($ruta !== '' && is_file($ruta)) { @unlink($ruta); }
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
     * Cruza contra los pagos semanales las facturas que acaban de entrar.
     *
     * Existía la mitad de notas de crédito y faltaba esta. Sin ella, importar
     * desde Correo dejaba las líneas del listado "sin respaldo" aunque su XML
     * ya estuviera en la base: el emparejamiento solo corría al entrar a Por
     * Pagar y darle a "Verificar de nuevo", y quien importaba no tenía forma
     * de saber que hacía falta.
     *
     * No se acota a una semana porque las facturas que llegan por correo no
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
     */
    private function configListoOFallar()
    {
        if (!MailFetcher::extensionDisponible()) {
            $this->json(['ok' => false, 'message' => 'La extensión imap de PHP no está activa en este servidor.'], 500);
        }

        $config = $this->configCuenta((int) $this->post('cuenta_id', 0));
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

            if (empty($rutas)) {
                $this->json(['ok' => false, 'message' => 'Las filas seleccionadas no tienen XML pendiente de importar (¿ya se importaron o falta el archivo?).'], 422);
            }

            // Importar ya no pide semana. La semana de un comprobante se
            // deduce: al guardarlo, el importador busca su factura en el
            // listado del ERP por el consecutivo, y si esa factura está en un
            // pago semanal, el comprobante hereda esa semana y el pago se
            // actualiza solo. Elegirla a mano era una decisión que nadie tenía
            // por qué tomar —y equivocarse dejaba la factura fuera del pago—.
            $semanaId = null;

            require_once __DIR__ . '/../helpers/InvoiceImportQueue.php';
            $service = new InvoiceImportQueue();

            $sociedadActiva = $this->loadModel('Sociedad')->getActiva();
            $inicio = $service->iniciarImportacion([
                'archivo_origen' => 'Correo ' . date('d/m/Y H:i'),
                'total_esperado' => count($rutas),
                'semana_id' => $semanaId,
                'tipo_documento' => 'FE',
                'cedula_receptor' => $sociedadActiva['cedula'] ?? '',
                'sociedad_id' => (int) ($sociedadActiva['id'] ?? 0),
            ]);
            $importacionId = (int) $inicio['importacion_id'];

            $resultado = $service->agregarArchivosLocales($importacionId, $rutas);

            $bandejaModel->marcarImportadas($idsValidos, $importacionId);

            // La cola ya tiene su copia del XML. El PDF temporal se conserva
            // hasta que XmlDocumentImporter archive el par y confirme la BD.
            $avisosPendientes = [];
            if ((int) $resultado['uploaded_count'] === count($filasValidas)) {
                foreach ($filasValidas as $fila) {
                    if (!empty($fila['archivo_xml']) && is_file($fila['archivo_xml'])) {
                        @unlink($fila['archivo_xml']);
                    }
                    if (empty($fila['archivo_pdf']) || !is_file($fila['archivo_pdf'])) {
                        $avisosPendientes[] = 'Factura ' . ($fila['numero_corto'] ?? '')
                            . ': el XML se archivará y el PDF quedará pendiente.';
                    }
                }
            }

            $this->json([
                'ok' => true,
                'importacion_id' => $importacionId,
                'encoladas' => (int) $resultado['uploaded_count'],
                'archivos_guardados' => 0,
                'carpeta_destino' => trim((string) ($this->configLocal()['carpeta_destino'] ?? '')),
                'aviso_carpeta' => '',
                'avisos' => array_slice($avisosPendientes, 0, 10),
                'estado' => $resultado['estado'],
            ]);

            // Dejar el XML + PDF de cada factura importada, ya renombrados
            // (FE_/NC_PROVEEDOR_ddmmyy_numero), en la carpeta configurada ⚙.
            // Cada factura debe dejar su PAR completo: cualquier archivo que
            // no llegue a la carpeta (copia fallida o PDF inexistente)
            // genera un aviso que la vista muestra al terminar.
            $archivosGuardados = 0;
            $avisoCarpeta = '';
            $avisos = [];
            $carpetaDestino = trim((string) ($this->configLocal()['carpeta_destino'] ?? ''));

            if ($carpetaDestino === '') {
                $avisoCarpeta = 'Configura la carpeta destino (⚙) para guardar los XML/PDF renombrados.';
            } elseif (!is_dir($carpetaDestino) && !@mkdir($carpetaDestino, 0777, true) && !is_dir($carpetaDestino)) {
                $avisoCarpeta = 'No se pudo abrir la carpeta destino "' . $carpetaDestino . '".';
            } else {
                foreach ($filasValidas as $fila) {
                    $nombre = $this->nombreArchivoBandeja($fila);
                    $etiqueta = trim((string) ($fila['numero_corto'] ?? ''));
                    if ($etiqueta === '') {
                        $etiqueta = $this->nombreOriginal((string) $fila['archivo_xml']);
                    }

                    $xmlOk = @copy((string) $fila['archivo_xml'], $carpetaDestino . DIRECTORY_SEPARATOR . $nombre . '.xml');
                    if ($xmlOk) {
                        $archivosGuardados++;
                    } else {
                        $avisos[] = 'Factura ' . $etiqueta . ': NO se pudo copiar el XML a la carpeta destino.';
                    }

                    $rutaPdf = (string) ($fila['archivo_pdf'] ?? '');
                    $pdfOk = true; // sin PDF no hay nada que copiar ni que retener
                    if ($rutaPdf !== '' && is_file($rutaPdf)) {
                        $pdfOk = @copy($rutaPdf, $carpetaDestino . DIRECTORY_SEPARATOR . $nombre . '.pdf');
                        if ($pdfOk) {
                            $archivosGuardados++;
                        } else {
                            $avisos[] = 'Factura ' . $etiqueta . ': NO se pudo copiar el PDF a la carpeta destino.';
                        }
                    } else {
                        $avisos[] = 'Factura ' . $etiqueta . ': quedó sin PDF en la carpeta (el correo no traía PDF emparejado; solo se guardó el XML).';
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
                'avisos' => array_slice($avisos, 0, 10),
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

            // Se puede descartar todo lo que se ve en la bandeja
            $filas = array_merge(
                $bandejaModel->getByIds($ids, 'pendiente'),
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
                // uid_correo es la misma clave usada en correo_procesados
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
                if ($ruta !== '' && is_file($ruta)) {
                    @unlink($ruta);
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
    /** Punto compartido con el worker: captura, pero nunca importa. */
    public function capturarMensajeParaRevision(array $mensaje, $cuentaId,
                                                 array $sociedadesPermitidas = [])
    {
        return $this->procesarMensaje(
            $mensaje,
            $this->loadModel('CorreoBandeja'),
            $this->loadModel('Factura'),
            (int) $cuentaId,
            ['FE'],
            '',
            0,
            $sociedadesPermitidas,
            'automatica'
        );
    }

    private function procesarMensaje(array $mensaje, $bandejaModel, $facturaModel,
                                     $cuentaId = 0, array $tiposPermitidos = ['FE'],
                                     $cedulaForzada = '', $sociedadIdForzada = 0,
                                     array $sociedadesPermitidas = [], $origen = 'manual')
    {
        $resultado = [
            'nuevas' => 0,
            'ya_existentes' => 0,
            'aceptadas' => 0,
            'rechazadas' => 0,
            'otra_cedula' => 0,
            'pdfs_guardados' => 0,
            'pdfs_sin_identificar' => 0,
            'pdfs_duplicados_omitidos' => 0,
            'errores' => [],
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
                if (in_array($tipoDetectado, XmlDocumentImporter::NUNCA_SE_GUARDAN, true)) {
                    $resultado['errores'][] = 'Nota de débito descartada (no se guardan): ' . $adjunto['nombre'];
                    @unlink($adjunto['ruta']);
                    continue;
                }
                if (!in_array($tipoDetectado, $tiposPermitidos, true)) {
                    $resultado['errores'][] = 'Documento ' . $tipoDetectado . ' omitido en este modo: ' . $adjunto['nombre'];
                    @unlink($adjunto['ruta']);
                    continue;
                }

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

        // 3) Insertar cada factura en la bandeja
        foreach ($facturas as $idx => $factura) {
            $adjunto = $factura['adjunto'];

            // Si el mismo correo ya se capturó, se reemplazan sus temporales y
            // se reactiva la fila. La deduplicación se decide después, al
            // importar contra la semana elegida, no al abrir el correo.
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

            // Mover el XML de tmp/ a la bandeja (nombre con prefijo removible
            // "__"). La bandeja vive en la carpeta compartida: la fila que se
            // crea abajo la ve todo el grupo, así que el archivo también tiene
            // que estar donde todos lleguen.
            $nombreSeguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $adjunto['nombre']);
            $rutaXml = RutaDocumento::carpetaTrabajo('BANDEJA/xml') . DIRECTORY_SEPARATOR . uniqid('correo_', true) . '__' . $nombreSeguro;
            if (!rename($adjunto['ruta'], $rutaXml)) {
                $resultado['errores'][] = 'No se pudo guardar el XML "' . $adjunto['nombre'] . '".';
                continue;
            }

            // Guardar su PDF ya nombrado con el número de factura.
            // El PDF de una factura rechazada por Hacienda o de otra
            // cédula se descarta: no debe llegar a las carpetas de trabajo.
            // Toda factura pendiente debe quedar con su par XML+PDF: si el
            // PDF no vino en el correo o no se pudo guardar, se avisa.
            $etiquetaFactura = $factura['numero_corto'] !== '' ? $factura['numero_corto'] : $adjunto['nombre'];
            $rutaPdf = null;
            if (isset($pdfPorIndice[$idx])) {
                if ($esRechazada || $esOtraCedula) {
                    @unlink($pdfPorIndice[$idx]['ruta']);
                } else {
                    $numero = $factura['numero_corto'] !== '' ? $factura['numero_corto'] : pathinfo($nombreSeguro, PATHINFO_FILENAME);
                    $rutaPdf = RutaDocumento::carpetaTrabajo('BANDEJA/pdf') . DIRECTORY_SEPARATOR
                        . uniqid('correo_pdf_', true) . '__' . $numero . '.pdf';
                    if (rename($pdfPorIndice[$idx]['ruta'], $rutaPdf)) {
                        $resultado['pdfs_guardados']++;
                    } else {
                        $rutaPdf = null;
                        $resultado['errores'][] = 'La factura ' . $etiquetaFactura
                            . ' quedó SIN PDF: no se pudo guardar "'
                            . mb_substr((string) $pdfPorIndice[$idx]['nombre'], 0, 80, 'UTF-8') . '".';
                    }
                }
            } elseif (!$esRechazada && !$esOtraCedula) {
                $resultado['errores'][] = 'La factura ' . $etiquetaFactura
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
                foreach ($rutasAnteriores as $rutaAnterior) {
                    if ($rutaAnterior !== ''
                        && $rutaAnterior !== $rutaXml
                        && $rutaAnterior !== (string) $rutaPdf
                        && is_file($rutaAnterior)) {
                        @unlink($rutaAnterior);
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
            $destino = RutaDocumento::carpetaTrabajo('BANDEJA/sin-identificar') . DIRECTORY_SEPARATOR . uniqid('pdf_', true) . '__' . $nombreSeguro;
            if (rename($pdf['ruta'], $destino)) {
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
                if (!RutaDocumento::permiteEscritura($carpeta)) {
                    $this->json(['ok' => false, 'message' => 'La carpeta "' . $carpeta . '" existe, pero no dejó guardar un archivo de prueba. Si es de SharePoint, pide permiso de edición sobre la biblioteca.'], 422);
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

    // Aquí vivían organizar() y organizarPrevisualizar(), con su tarjeta en
    // Configuración: elegir el alcance, ver qué haría y confirmar.
    //
    // Salieron porque no había nada que decidir. La carpeta de un documento la
    // deciden su fecha de emisión y su tipo —que no cambian nunca— y su semana
    // de pago; no hay dos respuestas posibles que justifiquen mirar una lista
    // antes de aceptarla. Lo que sí había era una pantalla más que alguien
    // tenía que acordarse de visitar para que el archivo quedara en su sitio, y
    // mientras no la visitara, la carpeta del pago no se armaba.
    //
    // Ahora lo acomoda la tarea programada (cli/sync_correo.php), como mucho
    // cada quince minutos y solo sobre documentos registrados. Nunca borra:
    // mueve el par a la carpeta de su mes y COPIA a la del pago semanal. Para
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

        if ($capturarNuevos) {
            $raiz = DocumentoArchivo::raizConfigurada();
            if ($raiz === '' || !is_dir($raiz)) {
                $this->json([
                    'ok' => false,
                    'message' => 'Configura primero una carpeta compartida válida para guardar la Bandeja.',
                ], 422);
            }
            if (!RutaDocumento::permiteEscritura($raiz)) {
                $this->json([
                    'ok' => false,
                    'message' => 'La carpeta compartida no permite escritura; la captura automática no podría guardar la Bandeja.',
                ], 422);
            }
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
            'max_correos_corrida'  => $maxCorreos,
            'max_intentos'         => $maxIntentos,
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
            'message'       => 'Automatización activada cada ' . $intervalo . ' min. '
                . ($capturarNuevos
                    ? 'Los correos nuevos quedarán en la Bandeja hasta que una persona los importe.'
                    : 'Solo se actualizará el índice.'),
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
    // Recordar la semana elegida en la bandeja se fue con el selector: la
    // bandeja ya no pregunta a qué semana va lo que se importa, porque cada
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
            'escribible' => RutaDocumento::permiteEscritura($ruta),
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
            // El picker vive en una ruta FIJA: la tarea programada del modo
            // servicio apunta a ella y así solo se registra la primera vez.
            $picker    = $dir . DIRECTORY_SEPARATOR . 'pick_selector.ps1';
            $launcher  = $dir . DIRECTORY_SEPARATOR . 'pick_launch_' . $token . '.ps1';
            $lanzaVbs  = $dir . DIRECTORY_SEPARATOR . 'pick_go_' . $token . '.vbs';
            // El diálogo moderno se compila UNA vez a este dll y se reusa:
            // recompilar el C# en cada apertura añadía varios segundos.
            $dll = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'selector_dialog.dll';

            // El diálogo abre en la carpeta ya configurada, si existe
            $inicial = trim((string) ($this->configLocal()['carpeta_destino'] ?? ''));
            if ($inicial !== '' && !is_dir($inicial)) {
                $inicial = '';
            }

            file_put_contents($picker, $this->scriptPicker($resultado, $inicial, $dll));
            file_put_contents($launcher, $this->scriptLauncher($picker, $resultado));

            // Lanzar SIN esperar (WScript.Run asíncrono, igual que la sync):
            // el exec() de antes bloqueaba la respuesta 3-8 s mientras
            // PowerShell arrancaba y registraba la tarea. Los errores del
            // lanzador llegan ahora por el archivo de resultado ("ERROR ..."),
            // que selectorEstado() ya sabe reportar.
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $launcher . '"';
            file_put_contents($lanzaVbs, 'CreateObject("WScript.Shell").Run "' . str_replace('"', '""', $cmd) . '", 0, False' . "\r\n");
            @exec('wscript.exe //B //Nologo "' . $lanzaVbs . '"');

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
        @unlink($dir . DIRECTORY_SEPARATOR . 'pick_launch_' . $token . '.ps1');
        @unlink($dir . DIRECTORY_SEPARATOR . 'pick_go_' . $token . '.vbs');

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
    private function scriptPicker($archivoResultado, $carpetaInicial, $rutaDll)
    {
        $plantilla = <<<'POWERSHELL'
# Selector de carpeta de Nexo Fiscal: muestra el diálogo nativo de Windows
# y escribe la ruta elegida (o CANCEL / ERROR ...) en el archivo resultado.
$ErrorActionPreference = 'Stop'
$resultado = '{{RESULTADO}}'
$inicial   = '{{INICIAL}}'
$dll       = '{{DLL}}'

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
        # Dialogo moderno "Seleccionar carpeta" del explorador de Windows.
        # El C# se compila UNA vez al dll y las siguientes aperturas solo lo
        # cargan: recompilarlo en cada apertura costaba varios segundos.
        $src = @'
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
        $cargado = $false
        if (Test-Path -LiteralPath $dll) {
            try { Add-Type -Path $dll; $cargado = $true }
            catch { try { Remove-Item -LiteralPath $dll -Force } catch { } }
        }
        if (-not $cargado) {
            try {
                Add-Type -TypeDefinition $src -OutputAssembly $dll | Out-Null
                Add-Type -Path $dll
            } catch {
                # Sin dll (p. ej. carpeta no escribible): compilar en memoria
                Add-Type -TypeDefinition $src
            }
        }
    } catch { $moderno = $false }

    if ($moderno) {
        $ruta = [XmlConciliaSelector]::Mostrar($dueno.Handle, 'Carpeta destino de XML y PDF - Nexo Fiscal', $inicial)
        if ($null -eq $ruta) { Escribir 'CANCEL'; exit 0 }
    } else {
        # Respaldo: dialogo clasico de seleccion de carpeta
        $dlg = New-Object System.Windows.Forms.FolderBrowserDialog
        $dlg.Description = 'Carpeta destino de XML y PDF - Nexo Fiscal'
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
            ['{{RESULTADO}}', '{{INICIAL}}', '{{DLL}}'],
            [
                str_replace("'", "''", $archivoResultado),
                str_replace("'", "''", $carpetaInicial),
                str_replace("'", "''", $rutaDll),
            ],
            $plantilla
        );
    }

    /**
     * Script que lanza el selector en la sesión del usuario. Si PHP ya corre
     * en una sesión interactiva del usuario de Windows
     * lo abre directo; si corre como servicio (sesión 0) registra una tarea
     * programada interactiva — la vía soportada para mostrar una ventana en
     * el escritorio del usuario desde un servicio de Windows.
     */
    private function scriptLauncher($rutaPicker, $rutaResultado)
    {
        $plantilla = <<<'POWERSHELL'
# Lanzador del selector de carpeta. Corre en segundo plano (la peticion
# AJAX ya respondio), asi que sus errores se reportan escribiendo
# "ERROR ..." en el archivo de resultado que consulta selectorEstado().
$ErrorActionPreference = 'Stop'
$picker    = '{{PICKER}}'
$resultado = '{{RESULTADO}}'

function EscribirError([string] $texto) {
    try {
        $utf8 = New-Object System.Text.UTF8Encoding -ArgumentList $false
        [System.IO.File]::WriteAllText($resultado, 'ERROR ' + $texto, $utf8)
    } catch { }
}

try {
    if ((Get-Process -Id $PID).SessionId -gt 0) {
        # Sesion interactiva: el dialogo se
        # muestra desde este mismo proceso, sin arrancar otro PowerShell
        & $picker
        exit 0
    }

    # Sesion 0 (servicio): lanzar el picker en la sesion del usuario via
    # tarea programada. El picker tiene ruta FIJA, asi que la tarea se
    # registra la primera vez y despues solo se arranca (mucho mas rapido).
    $usuario = (Get-CimInstance Win32_ComputerSystem).UserName
    if (-not $usuario) {
        EscribirError 'No hay ningun usuario con sesion abierta en Windows.'
        exit 1
    }

    $tn = 'XMLConcilia_SelectorCarpeta'
    $argumento = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $picker + '"'
    $tarea = Get-ScheduledTask -TaskName $tn -ErrorAction SilentlyContinue

    # Si quedo un dialogo abierto de un intento anterior, cerrarlo
    if ($tarea) { try { Stop-ScheduledTask -TaskName $tn -ErrorAction Stop } catch { } }

    $reutilizable = $false
    if ($tarea) {
        try {
            $reutilizable = ($tarea.Actions[0].Arguments -eq $argumento) -and ($tarea.Principal.UserId -eq $usuario)
        } catch { }
    }

    if (-not $reutilizable) {
        $accion = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $argumento
        $principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive
        $ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
        Register-ScheduledTask -TaskName $tn -Action $accion -Principal $principal -Settings $ajustes -Force | Out-Null
    }

    Start-ScheduledTask -TaskName $tn
    exit 0
} catch {
    EscribirError $_.Exception.Message
    exit 1
}
POWERSHELL;

        return "\xEF\xBB\xBF" . str_replace(
            ['{{PICKER}}', '{{RESULTADO}}'],
            [str_replace("'", "''", $rutaPicker), str_replace("'", "''", $rutaResultado)],
            $plantilla
        );
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
