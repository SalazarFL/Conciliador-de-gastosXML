<?php
/**
 * Helper de conexión IMAP para capturar facturas (XML + PDF) del buzón.
 *
 * Solo funciona en local: requiere la extensión ext/imap y salida a internet
 * (InfinityFree bloquea conexiones salientes). La vista /correo muestra el
 * módulo deshabilitado cuando la extensión no está disponible.
 */

class MailFetcher
{
    private const EXTENSIONES_FACTURA = ['xml', 'pdf', 'zip'];
    private const MAX_VISTA_PREVIA_BYTES = 15728640; // 15 MB en memoria

    private $config;
    private $stream = null;
    private $uidValidity = 0;
    private $procesados = null;
    private $mailboxRef = '';      // '{host:993/imap/ssl}'
    private $carpetaBase = 'INBOX';
    private $carpetaActual = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // ── Estado / configuración ─────────────────────────────────────

    public static function extensionDisponible()
    {
        return function_exists('imap_open');
    }

    public static function configPath()
    {
        return __DIR__ . '/../config/correo.php';
    }

    /**
     * Carga app/config/correo.php. Devuelve null si el archivo no existe
     * (la vista muestra instrucciones para crearlo desde el example).
     */
    public static function cargarConfig()
    {
        $path = self::configPath();
        if (!is_file($path)) {
            return null;
        }

        $config = require $path;
        return is_array($config) ? $config : null;
    }

    public static function configurado($config)
    {
        return is_array($config)
            && trim((string) ($config['host'] ?? '')) !== ''
            && trim((string) ($config['usuario'] ?? '')) !== ''
            && trim((string) ($config['password'] ?? '')) !== ''
            && strpos((string) $config['host'], 'tudominio.com') === false;
    }

    /**
     * Ruta bajo storage/correo/ (se crea si no existe).
     */
    public static function storagePath($sub = '')
    {
        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'correo';
        $path = $sub !== '' ? $base . DIRECTORY_SEPARATOR . $sub : $base;

        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception('No se pudo crear el directorio de correo: ' . $path);
        }

        return $path;
    }

    // ── Conexión ───────────────────────────────────────────────────

    public function conectar()
    {
        if (!self::extensionDisponible()) {
            throw new Exception('La extensión imap de PHP no está activa en este servidor.');
        }

        $host    = trim((string) ($this->config['host'] ?? ''));
        $puerto  = (int) ($this->config['puerto'] ?? 993);
        $usuario = trim((string) ($this->config['usuario'] ?? ''));
        $carpeta = trim((string) ($this->config['carpeta'] ?? 'INBOX'));
        if ($carpeta === '') {
            $carpeta = 'INBOX';
        }

        // Fallar rápido si el servidor no responde (default de imap es ~30s+)
        imap_timeout(IMAP_OPENTIMEOUT, 15);
        imap_timeout(IMAP_READTIMEOUT, 30);

        $this->mailboxRef = '{' . $host . ':' . $puerto . '/imap/ssl}';
        $this->carpetaBase = $carpeta;

        $stream = @imap_open($this->mailboxRef . $carpeta, $usuario, (string) ($this->config['password'] ?? ''), 0, 1);

        if ($stream === false) {
            $error = trim((string) imap_last_error());
            imap_errors(); // limpiar la pila para evitar avisos al cerrar PHP

            $lower = strtolower($error);
            if (strpos($lower, 'authenticat') !== false || strpos($lower, 'login') !== false
                || strpos($lower, 'password') !== false || strpos($lower, 'credential') !== false) {
                throw new Exception("El buzón rechazó las credenciales (usuario o contraseña incorrectos). Detalle: {$error}");
            }

            throw new Exception("No fue posible conectar con el servidor de correo {$host}:{$puerto}. Verifica el host y tu conexión. Detalle: " . ($error !== '' ? $error : 'sin respuesta del servidor'));
        }

        $this->stream = $stream;
        $this->carpetaActual = $carpeta;

        $status = @imap_status($stream, $this->mailboxRef . $carpeta, SA_UIDVALIDITY);
        $this->uidValidity = ($status && isset($status->uidvalidity)) ? (int) $status->uidvalidity : 0;

        return true;
    }

    /**
     * Cambia la carpeta abierta (y su uidvalidity). Los UID son únicos
     * solo dentro de su carpeta, así que siempre hay que abrir la correcta.
     */
    private function abrirCarpeta($carpeta)
    {
        $carpeta = (string) $carpeta;
        if ($carpeta === '' || $carpeta === $this->carpetaActual) {
            return true;
        }

        if (!@imap_reopen($this->stream, $this->mailboxRef . $carpeta)) {
            imap_errors();
            return false;
        }

        $this->carpetaActual = $carpeta;

        $status = @imap_status($this->stream, $this->mailboxRef . $carpeta, SA_UIDVALIDITY);
        $this->uidValidity = ($status && isset($status->uidvalidity)) ? (int) $status->uidvalidity : 0;

        return true;
    }

    /**
     * Todas las carpetas del buzón donde buscar facturas (el usuario las
     * archiva en subcarpetas por año/mes), excluyendo spam, papelera,
     * borradores y enviados. La carpeta configurada va primero.
     */
    public function carpetasABuscar()
    {
        $buzones = @imap_list($this->stream, $this->mailboxRef, '*');
        if (!is_array($buzones) || empty($buzones)) {
            return [$this->carpetaBase];
        }

        // Papelera sí se incluye: Roundcube busca globalmente allí y una
        // factura borrada por error todavía puede necesitar recuperarse.
        $excluidas = ['spam', 'junk', 'borrador', 'draft', 'sent', 'enviado'];

        $carpetas = [];
        foreach ($buzones as $buzon) {
            $pos = strpos($buzon, '}');
            $nombre = $pos !== false ? substr($buzon, $pos + 1) : $buzon;
            if ($nombre === '') {
                continue;
            }

            $legible = mb_strtolower($this->nombreLegibleCarpeta($nombre), 'UTF-8');
            $saltar = false;
            foreach ($excluidas as $ex) {
                if (strpos($legible, $ex) !== false) {
                    $saltar = true;
                    break;
                }
            }

            if (!$saltar) {
                $carpetas[] = $nombre;
            }
        }

        if (empty($carpetas)) {
            return [$this->carpetaBase];
        }

        $base = $this->carpetaBase;
        usort($carpetas, function ($a, $b) use ($base) {
            if ($a === $base) {
                return -1;
            }
            if ($b === $base) {
                return 1;
            }
            return strcasecmp($a, $b);
        });

        return array_values(array_unique($carpetas));
    }

    /**
     * Devuelve todas las carpetas visibles del buzón, incluidas las especiales
     * que no forman parte del índice de facturas (Borradores, Enviados, SPAM).
     * Solo lista nombres: no ejecuta STATUS por carpeta.
     */
    public function listarCarpetasCorreo()
    {
        if (!$this->stream) {
            $this->conectar();
        }

        $buzones = @imap_getmailboxes($this->stream, $this->mailboxRef, '*');
        if (!is_array($buzones)) {
            imap_errors();
            return [];
        }

        $estadoEntrada = @imap_status($this->stream, $this->mailboxRef . 'INBOX', SA_UNSEEN);
        $noLeidosEntrada = $estadoEntrada ? (int) ($estadoEntrada->unseen ?? 0) : null;

        $carpetas = [];
        foreach ($buzones as $buzon) {
            $nombreCompleto = (string) ($buzon->name ?? '');
            $pos = strpos($nombreCompleto, '}');
            $nombre = $pos !== false ? substr($nombreCompleto, $pos + 1) : $nombreCompleto;
            if ($nombre === '') {
                continue;
            }

            $atributos = (int) ($buzon->attributes ?? 0);
            $noSeleccionable = defined('LATT_NOSELECT')
                && (($atributos & constant('LATT_NOSELECT')) !== 0);

            $carpetas[] = [
                'carpeta' => $nombre,
                'nombre' => $this->nombreLegibleCarpeta($nombre),
                'delimitador' => (string) ($buzon->delimiter ?? '.'),
                'seleccionable' => !$noSeleccionable,
                'no_leidos' => strcasecmp($nombre, 'INBOX') === 0 ? $noLeidosEntrada : null,
            ];
        }
        imap_errors();

        usort($carpetas, function ($a, $b) {
            if ($a['carpeta'] === 'INBOX') {
                return -1;
            }
            if ($b['carpeta'] === 'INBOX') {
                return 1;
            }
            return strcasecmp($a['nombre'], $b['nombre']);
        });

        return $carpetas;
    }

    /**
     * Estado de una carpeta vía STATUS (no requiere abrirla): uidvalidity,
     * uidnext y total de mensajes. Es UN viaje al servidor por carpeta —
     * la base de la sincronización incremental del índice local.
     */
    public function estadoCarpeta($carpeta)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        $status = @imap_status($this->stream, $this->mailboxRef . $carpeta, SA_ALL);
        imap_errors();

        if (!$status) {
            return null;
        }

        return [
            'uidvalidity' => (int) ($status->uidvalidity ?? 0),
            'uidnext'     => (int) ($status->uidnext ?? 0),
            'mensajes'    => (int) ($status->messages ?? 0),
        ];
    }

    /**
     * Encabezados de una carpeta por rango de UID (p. ej. '1:*' completa,
     * '501:*' solo lo nuevo). Un solo viaje al servidor por carpeta.
     */
    public function overviewCarpeta($carpeta, $rangoUid)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if (!$this->abrirCarpeta($carpeta)) {
            return [];
        }

        $overviews = @imap_fetch_overview($this->stream, (string) $rangoUid, FT_UID);
        imap_errors();

        if (!is_array($overviews)) {
            return [];
        }

        $filas = [];
        foreach ($overviews as $ov) {
            $uid = (int) ($ov->uid ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $ts = 0;
            if (!empty($ov->udate)) {
                $ts = (int) $ov->udate;
            } elseif (!empty($ov->date)) {
                $ts = (int) strtotime((string) $ov->date);
            }

            // Los nombres de adjuntos NO se leen aquí: imap_fetchstructure es
            // un viaje por mensaje y volvería eterna la sincronización de una
            // carpeta grande (timeout 500). Se rellenan aparte, por tandas
            // (ver adjuntosDeMensaje + fase 2 de la sincronización).
            $filas[] = [
                'uid'       => $uid,
                'clave'     => $this->claveMensaje($uid),
                'asunto'    => mb_substr($this->decodificarTexto((string) ($ov->subject ?? '')), 0, 255, 'UTF-8'),
                'remitente' => mb_substr($this->decodificarTexto((string) ($ov->from ?? '')), 0, 255, 'UTF-8'),
                // La extensión IMAP normalmente no incluye CC en el overview.
                // Si el servidor sí lo entrega lo aprovechamos; null indica que
                // la fase de metadatos debe leerlo del encabezado completo.
                'cc'        => isset($ov->cc)
                    ? mb_substr($this->decodificarTexto((string) $ov->cc), 0, 1000, 'UTF-8')
                    : null,
                'reply_to'  => isset($ov->reply_to)
                    ? mb_substr($this->decodificarTexto((string) $ov->reply_to), 0, 1000, 'UTF-8')
                    : null,
                'fecha'     => $ts > 0 ? date('Y-m-d H:i:s', $ts) : null,
                'timestamp' => $ts,
            ];
        }

        return $filas;
    }

    /**
     * Nombres de los adjuntos de UN mensaje como texto plano para el índice
     * ('' si no tiene). Solo lee la estructura MIME, sin bajar archivos.
     * Devuelve null si la carpeta no se pudo abrir (el mensaje queda
     * pendiente para la próxima ronda).
     */
    public function adjuntosDeMensaje($uid, $carpeta)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if (!$this->abrirCarpeta($carpeta)) {
            return null;
        }

        $estructura = @imap_fetchstructure($this->stream, (int) $uid, FT_UID);
        imap_errors();
        if (!$estructura) {
            return ''; // mensaje borrado/movido: no dejarlo pendiente por siempre
        }

        $nombres = $this->recolectarNombres($estructura);
        return mb_substr(implode(' ', $nombres), 0, 1000, 'UTF-8');
    }

    /**
     * Destinatarios CC de un mensaje como texto buscable para el índice.
     * Lee únicamente los encabezados; no descarga el cuerpo ni los adjuntos.
     * null significa que la carpeta no se pudo abrir y debe reintentarse.
     */
    public function ccDeMensaje($uid, $carpeta)
    {
        $destinatarios = $this->destinatariosDeMensaje($uid, $carpeta);
        return $destinatarios === null ? null : $destinatarios['cc'];
    }

    /** Lee CC y Reply-To en un solo viaje IMAP. */
    public function destinatariosDeMensaje($uid, $carpeta)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if (!$this->abrirCarpeta($carpeta)) {
            return null;
        }

        $raw = @imap_fetchheader($this->stream, (int) $uid, FT_UID);
        imap_errors();
        if (!is_string($raw) || $raw === '') {
            return ['cc' => '', 'reply_to' => ''];
        }

        $encabezados = @imap_rfc822_parse_headers($raw);
        if (!$encabezados) {
            return ['cc' => '', 'reply_to' => ''];
        }

        $cc = $this->decodificarTexto((string) ($encabezados->ccaddress ?? ''));
        $replyTo = $this->decodificarTexto((string) ($encabezados->reply_toaddress ?? ''));
        return [
            'cc' => mb_substr($cc, 0, 1000, 'UTF-8'),
            'reply_to' => mb_substr($replyTo, 0, 1000, 'UTF-8'),
        ];
    }

    /**
     * Nombre legible de una carpeta IMAP (decodifica UTF-7 y quita el
     * prefijo INBOX.): 'INBOX.2026.A FACTURAS MG' → '2026/A FACTURAS MG'.
     */
    public function nombreLegibleCarpeta($carpeta)
    {
        return self::nombreLegibleEstatico($carpeta);
    }

    public static function nombreLegibleEstatico($carpeta)
    {
        $nombre = function_exists('imap_mutf7_to_utf8') ? @imap_mutf7_to_utf8((string) $carpeta) : (string) $carpeta;
        if (!is_string($nombre) || $nombre === '') {
            $nombre = (string) $carpeta;
        }

        $nombre = preg_replace('/^INBOX\./i', '', $nombre);
        return str_replace('.', '/', $nombre);
    }

    /** true si hay una conexión IMAP abierta (para reutilizar entre tandas). */
    public function estaConectado()
    {
        return (bool) $this->stream;
    }

    public function cerrar()
    {
        if ($this->stream) {
            imap_errors();
            imap_alerts();
            @imap_close($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->cerrar();
    }

    // ── Listado ligero (solo encabezados, sin bajar adjuntos) ──────

    /**
     * Lista correos con SOLO sus encabezados (remitente, CC, asunto, fecha).
     *
     * La búsqueda por $texto corre EN el servidor de correo (IMAP SEARCH)
     * y recorre TODAS las carpetas del buzón (menos spam/papelera/enviados/
     * borradores), porque el usuario archiva las facturas en subcarpetas.
     * Nunca baja adjuntos ni cuerpos. dias_atras = 0 significa sin filtro
     * de fecha.
     *
     * Devuelve ['total' => coincidencias, 'correos' => [...]]; cada correo:
     *   ['uid', 'clave', 'carpeta', 'carpeta_nombre', 'asunto', 'remitente', 'cc',
     *    'fecha', 'procesado'] — del más reciente al más viejo, máx $limite.
     */
    public function listarMensajes($limite = 500, $texto = '', $ambito = 'asunto_remitente', $carpetaFiltro = '', $offset = 0)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        $texto = trim(str_replace('"', ' ', (string) $texto));

        $sufijo = '';
        $fechaDesde = trim((string) ($this->config['fecha_desde'] ?? ''));
        $fechaHasta = trim((string) ($this->config['fecha_hasta'] ?? ''));
        if ($fechaDesde !== '' && strtotime($fechaDesde) !== false) {
            $sufijo .= ' SINCE "' . date('d-M-Y', strtotime($fechaDesde)) . '"';
        }
        if ($fechaHasta !== '' && strtotime($fechaHasta) !== false) {
            $sufijo .= ' BEFORE "' . date('d-M-Y', strtotime($fechaHasta)) . '"';
        }
        if ($fechaDesde === '' && $fechaHasta === '') {
            $diasAtras = (int) ($this->config['dias_atras'] ?? 14);
            if ($diasAtras > 0) {
                $sufijo .= ' SINCE "' . date('d-M-Y', strtotime("-{$diasAtras} days")) . '"';
            }
        }
        // solo_no_leidos aplica al monitoreo sin término: una búsqueda
        // explícita debe encontrar también los correos ya leídos
        if ($texto === '' && !empty($this->config['solo_no_leidos'])) {
            $sufijo .= ' UNSEEN';
        }

        // Ámbitos: 'asunto' (SUBJECT), 'remitente' (FROM y CC, sirve para el
        // correo del proveedor), 'asunto_remitente' o 'todo' (agrega BODY:
        // el número de factura a veces viene en la descripción del correo).
        // c-client no soporta OR en el string de criterios: se hace una
        // búsqueda por campo y se une el resultado.
        $campos = [];
        if ($ambito === 'texto_mime') {
            // TEXT incluye encabezados y cuerpo MIME; por eso encuentra el
            // número aunque solo exista en filename= del XML/PDF adjunto.
            $campos[] = 'TEXT';
        } elseif ($ambito === 'reply_to') {
            $campos[] = 'HEADER "Reply-To"';
        } elseif (in_array($ambito, ['asunto', 'asunto_remitente', 'todo'], true)) {
            $campos[] = 'SUBJECT';
        }
        if (!in_array($ambito, ['texto_mime', 'reply_to'], true)
            && in_array($ambito, ['remitente', 'asunto_remitente', 'todo'], true)) {
            $campos[] = 'FROM';
            $campos[] = 'CC';
            $campos[] = 'HEADER "Reply-To"';
        }
        if ($ambito === 'todo') {
            $campos[] = 'BODY';
        }
        if (empty($campos)) {
            $campos = ['SUBJECT', 'FROM', 'CC', 'HEADER "Reply-To"'];
        }

        $correos = [];
        $totalCoincidencias = 0;
        $limite = max(1, (int) $limite);
        $offset = max(0, (int) $offset);
        $maxOverview = max($limite + $offset, 1500);

        $carpetaFiltro = trim((string) $carpetaFiltro);
        $carpetas = $carpetaFiltro !== '' ? [$carpetaFiltro] : $this->carpetasABuscar();

        foreach ($carpetas as $carpeta) {
            if (!$this->abrirCarpeta($carpeta)) {
                continue;
            }

            if ($texto !== '') {
                $grupos = [];
                foreach ($campos as $campo) {
                    $grupos[] = $this->buscarUids($campo . ' "' . $texto . '"' . $sufijo, $texto);
                }
                $uids = array_values(array_unique(array_merge(...$grupos)));
            } else {
                $uids = $this->buscarUids('ALL' . $sufijo, '');
            }

            $totalCoincidencias += count($uids);

            // Acotar el trabajo de overview: con miles de coincidencias solo
            // se piden encabezados de las más recientes (UID mayor ≈ más nuevo)
            if (count($uids) > $maxOverview) {
                sort($uids);
                $uids = array_slice($uids, -$maxOverview);
            }

            $nombreCarpeta = $this->nombreLegibleCarpeta($carpeta);

            // Overview por lotes: una ida al servidor por cada 200 mensajes
            foreach (array_chunk($uids, 200) as $lote) {
                $overviews = @imap_fetch_overview($this->stream, implode(',', $lote), FT_UID);
                if (!is_array($overviews)) {
                    continue;
                }

                foreach ($overviews as $ov) {
                    $uid = (int) ($ov->uid ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }

                    $ts = 0;
                    if (!empty($ov->udate)) {
                        $ts = (int) $ov->udate;
                    } elseif (!empty($ov->date)) {
                        $ts = (int) strtotime((string) $ov->date);
                    }

                    $clave = $this->claveMensaje($uid);
                    $correos[] = [
                        'uid'            => $uid,
                        'clave'          => $clave,
                        'carpeta'        => $carpeta,
                        'carpeta_nombre' => $nombreCarpeta,
                        'asunto'         => mb_substr($this->decodificarTexto((string) ($ov->subject ?? '')), 0, 255, 'UTF-8'),
                        'remitente'      => mb_substr($this->decodificarTexto((string) ($ov->from ?? '')), 0, 255, 'UTF-8'),
                        'cc'             => isset($ov->cc)
                            ? mb_substr($this->decodificarTexto((string) $ov->cc), 0, 1000, 'UTF-8')
                            : '',
                        'reply_to'       => isset($ov->reply_to)
                            ? mb_substr($this->decodificarTexto((string) $ov->reply_to), 0, 1000, 'UTF-8')
                            : '',
                        'fecha'          => $ts > 0 ? date('Y-m-d H:i:s', $ts) : null,
                        'timestamp'      => $ts,
                        'procesado'      => $this->yaProcesado($clave),
                    ];
                }
            }
        }

        usort($correos, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        $correos = array_slice($correos, $offset, $limite);

        return [
            'total'   => $totalCoincidencias,
            'correos' => $correos,
        ];
    }

    /**
     * Ejecuta un IMAP SEARCH y devuelve los UIDs. Los términos con acentos
     * o ñ requieren charset UTF-8; si el servidor lo rechaza se reintenta
     * con la versión sin acentos del término.
     */
    private function buscarUids($criterio, $texto)
    {
        $esAscii = $texto === '' || preg_match('/^[\x20-\x7E]*$/', $texto) === 1;

        if ($esAscii) {
            $uids = @imap_search($this->stream, $criterio, SE_UID);
        } else {
            $uids = @imap_search($this->stream, $criterio, SE_UID, 'UTF-8');

            if (!is_array($uids)) {
                $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
                if (is_string($ascii) && trim($ascii) !== '' && $ascii !== $texto) {
                    $uids = @imap_search($this->stream, str_replace($texto, $ascii, $criterio), SE_UID);
                }
            }
        }

        imap_errors(); // limpiar la pila: una búsqueda sin resultados deja avisos

        return is_array($uids) ? array_map('intval', $uids) : [];
    }

    // ── Extracción de un correo seleccionado ───────────────────────

    /**
     * Baja los adjuntos .xml/.pdf de UN mensaje (descomprimiendo .zip)
     * a storage/correo/tmp/. Solo se llama para los correos que el
     * usuario seleccionó — el resto del buzón nunca se descarga.
     *
     * Devuelve ['clave', 'uid', 'asunto', 'remitente', 'fecha',
     *           'xmls' => [['ruta','nombre']], 'pdfs' => [['ruta','nombre']]]
     */
    public function extraerMensaje($uid, $carpeta = '')
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if ($carpeta !== '' && !$this->abrirCarpeta($carpeta)) {
            throw new Exception('No se pudo abrir la carpeta "' . $this->nombreLegibleCarpeta($carpeta) . '" del buzón.');
        }

        $uid = (int) $uid;

        $estructura = @imap_fetchstructure($this->stream, $uid, FT_UID);
        $adjuntos = $estructura ? $this->extraerAdjuntos($uid, $estructura) : [];

        $xmls = [];
        $pdfs = [];
        foreach ($adjuntos as $adj) {
            $ext = strtolower(pathinfo($adj['nombre'], PATHINFO_EXTENSION));
            if ($ext === 'xml') {
                $xmls[] = $adj;
            } elseif ($ext === 'pdf') {
                $pdfs[] = $adj;
            }
        }

        $info = $this->infoMensaje($uid);

        return [
            'clave'     => $this->claveMensaje($uid),
            'uid'       => $uid,
            'asunto'    => $info['asunto'],
            'remitente' => $info['remitente'],
            'fecha'     => $info['fecha'],
            'xmls'      => $xmls,
            'pdfs'      => $pdfs,
        ];
    }

    /**
     * Nombres de los archivos adjuntos de un mensaje SIN descargarlos
     * (solo lee la estructura MIME).
     */
    public function nombresAdjuntos($uid, $carpeta = '')
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if ($carpeta !== '' && !$this->abrirCarpeta($carpeta)) {
            return [];
        }

        $estructura = @imap_fetchstructure($this->stream, (int) $uid, FT_UID);
        return $estructura ? $this->recolectarNombres($estructura) : [];
    }

    /**
     * Metadatos de adjuntos sin descargar su contenido. La sección MIME
     * identifica exactamente qué parte se leerá si el usuario pide verla.
     */
    public function listarAdjuntos($uid, $carpeta = '')
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if ($carpeta !== '' && !$this->abrirCarpeta($carpeta)) {
            return [];
        }

        $estructura = @imap_fetchstructure($this->stream, (int) $uid, FT_UID);
        return $estructura ? $this->recolectarAdjuntos($estructura) : [];
    }

    /**
     * Lee una sola parte MIME para visualizarla. El contenido vive únicamente
     * durante la petición: no pasa por guardarTemp() ni se escribe en disco.
     */
    public function obtenerAdjuntoParaVista($uid, $carpeta, $seccion)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if ($carpeta !== '' && !$this->abrirCarpeta($carpeta)) {
            throw new RuntimeException('No se pudo abrir la carpeta del correo.');
        }

        $uid = (int) $uid;
        $seccion = trim((string) $seccion);
        if ($uid <= 0 || !preg_match('/^\d+(?:\.\d+)*$/', $seccion)) {
            throw new InvalidArgumentException('El adjunto solicitado no es válido.');
        }

        $estructura = @imap_fetchstructure($this->stream, $uid, FT_UID);
        if (!$estructura) {
            throw new RuntimeException('No se pudo leer la estructura del correo.');
        }

        $parte = $this->buscarPartePorSeccion($estructura, $seccion);
        if ($parte === null) {
            throw new RuntimeException('El adjunto ya no está disponible en el correo.');
        }

        $nombre = mb_substr($this->nombreDeParte($parte), 0, 255, 'UTF-8');
        $tipoVista = $this->tipoVistaAdjunto($nombre);
        if ($tipoVista === '') {
            throw new RuntimeException('Este tipo de archivo no admite vista previa segura.');
        }

        $bytesDeclarados = max(0, (int) ($parte->bytes ?? 0));
        if ($bytesDeclarados > self::MAX_VISTA_PREVIA_BYTES) {
            throw new RuntimeException('El archivo supera el límite de vista previa de 15 MB.');
        }

        $contenido = @imap_fetchbody($this->stream, $uid, $seccion, FT_UID | FT_PEEK);
        if ($contenido === false || $contenido === '') {
            throw new RuntimeException('No se pudo leer el contenido del adjunto.');
        }

        $encoding = (int) ($parte->encoding ?? 0);
        if ($encoding === ENCBASE64) {
            $contenido = base64_decode($contenido, true);
        } elseif ($encoding === ENCQUOTEDPRINTABLE) {
            $contenido = quoted_printable_decode($contenido);
        }

        if ($contenido === false || $contenido === '') {
            throw new RuntimeException('El adjunto está vacío o usa una codificación no válida.');
        }
        if (strlen($contenido) > self::MAX_VISTA_PREVIA_BYTES) {
            throw new RuntimeException('El archivo supera el límite de vista previa de 15 MB.');
        }

        if ($tipoVista === 'pdf') {
            if (strpos(substr($contenido, 0, 1024), '%PDF-') === false) {
                throw new RuntimeException('El archivo no contiene un PDF válido.');
            }
            $mime = 'application/pdf';
        } else {
            $inicio = ltrim(substr($contenido, 0, 1024), "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
            if ($inicio === '' || $inicio[0] !== '<') {
                throw new RuntimeException('El archivo no contiene XML legible.');
            }
            $mime = 'text/plain; charset=utf-8';
        }

        return [
            'nombre' => $nombre,
            'tipo_vista' => $tipoVista,
            'mime' => $mime,
            'contenido' => $contenido,
        ];
    }

    private function recolectarAdjuntos($estructura, $prefijoSeccion = '')
    {
        $adjuntos = [];

        if (empty($estructura->parts)) {
            $seccion = $prefijoSeccion !== '' ? $prefijoSeccion : '1';
            $nombre = mb_substr($this->nombreDeParte($estructura), 0, 255, 'UTF-8');
            if ($nombre !== '') {
                $bytes = max(0, (int) ($estructura->bytes ?? 0));
                $tipoVista = $this->tipoVistaAdjunto($nombre);
                $adjuntos[] = [
                    'nombre' => $nombre,
                    'seccion' => $seccion,
                    'bytes' => $bytes,
                    'tipo_vista' => $tipoVista,
                    'visualizable' => $tipoVista !== ''
                        && ($bytes === 0 || $bytes <= self::MAX_VISTA_PREVIA_BYTES),
                ];
            }
            return $adjuntos;
        }

        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijoSeccion !== ''
                ? $prefijoSeccion . '.' . ($i + 1)
                : (string) ($i + 1);

            if (!empty($parte->parts)) {
                $adjuntos = array_merge($adjuntos, $this->recolectarAdjuntos($parte, $seccion));
                continue;
            }

            $nombre = mb_substr($this->nombreDeParte($parte), 0, 255, 'UTF-8');
            if ($nombre === '') {
                continue;
            }

            $bytes = max(0, (int) ($parte->bytes ?? 0));
            $tipoVista = $this->tipoVistaAdjunto($nombre);
            $adjuntos[] = [
                'nombre' => $nombre,
                'seccion' => $seccion,
                'bytes' => $bytes,
                'tipo_vista' => $tipoVista,
                'visualizable' => $tipoVista !== ''
                    && ($bytes === 0 || $bytes <= self::MAX_VISTA_PREVIA_BYTES),
            ];
        }

        return $adjuntos;
    }

    private function buscarPartePorSeccion($estructura, $objetivo, $prefijoSeccion = '')
    {
        if (empty($estructura->parts)) {
            $seccion = $prefijoSeccion !== '' ? $prefijoSeccion : '1';
            return $seccion === $objetivo && $this->nombreDeParte($estructura) !== ''
                ? $estructura
                : null;
        }

        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijoSeccion !== ''
                ? $prefijoSeccion . '.' . ($i + 1)
                : (string) ($i + 1);

            if (!empty($parte->parts)) {
                $encontrada = $this->buscarPartePorSeccion($parte, $objetivo, $seccion);
                if ($encontrada !== null) {
                    return $encontrada;
                }
            } elseif ($seccion === $objetivo && $this->nombreDeParte($parte) !== '') {
                return $parte;
            }
        }

        return null;
    }

    private function tipoVistaAdjunto($nombre)
    {
        $extension = strtolower(pathinfo((string) $nombre, PATHINFO_EXTENSION));
        return in_array($extension, ['pdf', 'xml'], true) ? $extension : '';
    }

    private function recolectarNombres($estructura)
    {
        $nombres = [];

        if (empty($estructura->parts)) {
            $nombre = $this->nombreDeParte($estructura);
            if ($nombre !== '') {
                $nombres[] = $nombre;
            }
            return $nombres;
        }

        foreach ($estructura->parts as $parte) {
            if (!empty($parte->parts)) {
                $nombres = array_merge($nombres, $this->recolectarNombres($parte));
            } else {
                $nombre = $this->nombreDeParte($parte);
                if ($nombre !== '') {
                    $nombres[] = $nombre;
                }
            }
        }

        return $nombres;
    }

    // ── Vista previa del cuerpo (sin bajar adjuntos) ───────────────

    /**
     * Devuelve el texto del cuerpo de UN mensaje (para leer la descripción
     * cuando el número de factura no viene en el asunto). Prefiere la parte
     * text/plain; si solo hay HTML, lo convierte a texto. No baja adjuntos.
     */
    public function obtenerCuerpo($uid, $carpeta = '', $maxLen = 6000)
    {
        if (!$this->stream) {
            $this->conectar();
        }

        if ($carpeta !== '' && !$this->abrirCarpeta($carpeta)) {
            return '';
        }

        $uid = (int) $uid;

        $estructura = @imap_fetchstructure($this->stream, $uid, FT_UID);
        if (!$estructura) {
            return '';
        }

        $texto = $this->buscarParteTexto($uid, $estructura, '', 'PLAIN');

        if ($texto === null) {
            $html = $this->buscarParteTexto($uid, $estructura, '', 'HTML');
            if ($html !== null) {
                $html = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', ' ', $html);
                $html = preg_replace('/<br\s*\/?>|<\/(p|div|tr|li|h[1-6])>/i', "\n", $html);
                $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if ($texto === null) {
            return '';
        }

        $texto = str_replace("\r", '', $texto);
        $texto = preg_replace('/[ \t]+/', ' ', $texto);
        $texto = preg_replace('/\n[ ]+/', "\n", $texto);
        $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
        $texto = trim($texto);

        if (mb_strlen($texto, 'UTF-8') > $maxLen) {
            $texto = mb_substr($texto, 0, $maxLen, 'UTF-8') . '…';
        }

        return $texto;
    }

    /**
     * Busca recursivamente la primera parte text/PLAIN o text/HTML que no
     * sea adjunto y devuelve su contenido decodificado (o null si no hay).
     */
    private function buscarParteTexto($uid, $estructura, $prefijoSeccion, $subtipo)
    {
        if (empty($estructura->parts)) {
            if ((int) ($estructura->type ?? -1) === TYPETEXT
                && strtoupper((string) ($estructura->subtype ?? '')) === $subtipo
                && $this->nombreDeParte($estructura) === '') {
                $seccion = $prefijoSeccion !== '' ? $prefijoSeccion : '1';
                return $this->decodificarCuerpoParte($uid, $estructura, $seccion);
            }
            return null;
        }

        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijoSeccion !== '' ? $prefijoSeccion . '.' . ($i + 1) : (string) ($i + 1);

            if (!empty($parte->parts)) {
                $texto = $this->buscarParteTexto($uid, $parte, $seccion, $subtipo);
                if ($texto !== null) {
                    return $texto;
                }
            } elseif ((int) ($parte->type ?? -1) === TYPETEXT
                && strtoupper((string) ($parte->subtype ?? '')) === $subtipo
                && $this->nombreDeParte($parte) === '') {
                $texto = $this->decodificarCuerpoParte($uid, $parte, $seccion);
                if ($texto !== null) {
                    return $texto;
                }
            }
        }

        return null;
    }

    private function decodificarCuerpoParte($uid, $parte, $seccion)
    {
        $cuerpo = @imap_fetchbody($this->stream, $uid, $seccion, FT_UID | FT_PEEK);
        if ($cuerpo === false || $cuerpo === '') {
            return null;
        }

        $encoding = (int) ($parte->encoding ?? 0);
        if ($encoding === ENCBASE64) {
            $cuerpo = base64_decode($cuerpo);
        } elseif ($encoding === ENCQUOTEDPRINTABLE) {
            $cuerpo = quoted_printable_decode($cuerpo);
        }

        if (!is_string($cuerpo) || $cuerpo === '') {
            return null;
        }

        $charset = '';
        if (!empty($parte->ifparameters) && !empty($parte->parameters)) {
            foreach ($parte->parameters as $param) {
                if (strtolower((string) $param->attribute) === 'charset') {
                    $charset = strtoupper((string) $param->value);
                }
            }
        }

        // Algunos proveedores declaran UTF-8 (o no declaran charset), pero
        // envían bytes Windows-1252. Normalizar siempre antes de llevar el
        // cuerpo a JSON para que un solo carácter inválido no vacíe la respuesta.
        return $this->convertirAUtf8($cuerpo, $charset);
    }

    // ── Deduplicación entre corridas (tabla correo_procesados) ─────
    // El estado vive en MySQL: una fila por correo, escritura atómica
    // (el procesados.json anterior se reescribía completo en cada marca y
    // con varios usuarios a la vez se perdían marcas). Aquí solo se cachea
    // en memoria por instancia para que listar no consulte por mensaje.

    public function yaProcesado($clave)
    {
        $this->cargarProcesados();
        return isset($this->procesados[$clave]);
    }

    public function marcarProcesado($clave)
    {
        $this->cargarProcesados();
        $this->procesados[$clave] = date('Y-m-d H:i:s');
        self::modeloProcesados()->marcar($clave);
    }

    /**
     * Quita marcas de procesado para que esos correos vuelvan a aparecer
     * como "sin procesar" (p. ej. al descartar de la bandeja).
     * Estático: no necesita conexión ni configuración de cuenta.
     */
    public static function desmarcarProcesados(array $claves)
    {
        return self::modeloProcesados()->desmarcar($claves);
    }

    private function cargarProcesados()
    {
        if ($this->procesados !== null) {
            return;
        }
        $this->procesados = self::modeloProcesados()->todas();
    }

    private static function modeloProcesados()
    {
        if (!class_exists('Model')) {
            require_once __DIR__ . '/../core/Model.php';
        }
        if (!class_exists('CorreoProcesado')) {
            require_once __DIR__ . '/../models/CorreoProcesado.php';
        }
        return new CorreoProcesado();
    }

    private function claveMensaje($uid)
    {
        // El prefijo de cuenta evita colisiones entre buzones distintos
        // (el par uidvalidity:uid solo es único dentro de un buzón)
        $cuenta = (int) ($this->config['cuenta_id'] ?? 0);
        $prefijo = $cuenta > 0 ? 'c' . $cuenta . ':' : '';

        return $prefijo . $this->uidValidity . ':' . (int) $uid;
    }

    // ── Cabeceras del mensaje ──────────────────────────────────────

    private function infoMensaje($uid)
    {
        $asunto = '';
        $remitente = '';
        $fecha = null;

        $overview = @imap_fetch_overview($this->stream, (string) (int) $uid, FT_UID);
        if (is_array($overview) && isset($overview[0])) {
            $ov = $overview[0];
            $asunto = $this->decodificarTexto((string) ($ov->subject ?? ''));
            $remitente = $this->decodificarTexto((string) ($ov->from ?? ''));
            if (!empty($ov->udate)) {
                $fecha = date('Y-m-d H:i:s', (int) $ov->udate);
            } elseif (!empty($ov->date)) {
                $ts = strtotime((string) $ov->date);
                $fecha = $ts ? date('Y-m-d H:i:s', $ts) : null;
            }
        }

        return [
            'asunto'    => mb_substr($asunto, 0, 255, 'UTF-8'),
            'remitente' => mb_substr($remitente, 0, 255, 'UTF-8'),
            'fecha'     => $fecha,
        ];
    }

    // ── Extracción de adjuntos ─────────────────────────────────────

    /**
     * Recorre la estructura MIME (partes anidadas) y guarda en tmp/ los
     * adjuntos .xml/.pdf; los .zip se abren y sus .xml/.pdf internos se
     * tratan como adjuntos del mismo correo.
     *
     * Devuelve [['ruta' => tmp path, 'nombre' => nombre original], ...]
     */
    private function extraerAdjuntos($uid, $estructura, $prefijoSeccion = '')
    {
        $adjuntos = [];

        if (empty($estructura->parts)) {
            // Mensaje de una sola parte: la parte es el cuerpo (sección "1")
            $seccion = $prefijoSeccion !== '' ? $prefijoSeccion : '1';
            return $this->procesarParte($uid, $estructura, $seccion);
        }

        foreach ($estructura->parts as $i => $parte) {
            $seccion = $prefijoSeccion !== '' ? $prefijoSeccion . '.' . ($i + 1) : (string) ($i + 1);

            if (!empty($parte->parts)) {
                $adjuntos = array_merge($adjuntos, $this->extraerAdjuntos($uid, $parte, $seccion));
            } else {
                $adjuntos = array_merge($adjuntos, $this->procesarParte($uid, $parte, $seccion));
            }
        }

        return $adjuntos;
    }

    private function procesarParte($uid, $parte, $seccion)
    {
        $nombre = $this->nombreDeParte($parte);
        if ($nombre === '') {
            return [];
        }

        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONES_FACTURA, true)) {
            return [];
        }

        $cuerpo = @imap_fetchbody($this->stream, $uid, $seccion, FT_UID | FT_PEEK);
        if ($cuerpo === false || $cuerpo === '') {
            return [];
        }

        $encoding = (int) ($parte->encoding ?? 0);
        if ($encoding === ENCBASE64) {
            $cuerpo = base64_decode($cuerpo);
        } elseif ($encoding === ENCQUOTEDPRINTABLE) {
            $cuerpo = quoted_printable_decode($cuerpo);
        }

        if ($cuerpo === false || $cuerpo === '') {
            return [];
        }

        if ($ext === 'zip') {
            return $this->extraerDeZip($cuerpo, $nombre);
        }

        $ruta = $this->guardarTemp($cuerpo, $nombre);
        return $ruta !== null ? [['ruta' => $ruta, 'nombre' => $nombre]] : [];
    }

    private function extraerDeZip($contenido, $nombreZip)
    {
        $adjuntos = [];

        $tmpZip = $this->guardarTemp($contenido, $nombreZip);
        if ($tmpZip === null) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entrada = (string) $zip->getNameIndex($i);
                $ext = strtolower(pathinfo($entrada, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xml', 'pdf'], true)) {
                    continue;
                }

                $datos = $zip->getFromIndex($i);
                if ($datos === false || $datos === '') {
                    continue;
                }

                $ruta = $this->guardarTemp($datos, basename($entrada));
                if ($ruta !== null) {
                    $adjuntos[] = ['ruta' => $ruta, 'nombre' => basename($entrada)];
                }
            }
            $zip->close();
        }

        @unlink($tmpZip);

        return $adjuntos;
    }

    private function guardarTemp($contenido, $nombreOriginal)
    {
        $seguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nombreOriginal);
        if ($seguro === '' || $seguro === null) {
            return null;
        }

        $ruta = self::storagePath('tmp') . DIRECTORY_SEPARATOR . uniqid('adj_', true) . '_' . $seguro;
        return file_put_contents($ruta, $contenido) !== false ? $ruta : null;
    }

    private function nombreDeParte($parte)
    {
        // filename (Content-Disposition) tiene prioridad sobre name (Content-Type)
        if (!empty($parte->ifdparameters) && !empty($parte->dparameters)) {
            foreach ($parte->dparameters as $param) {
                if (strtolower((string) $param->attribute) === 'filename') {
                    return trim($this->decodificarTexto((string) $param->value));
                }
            }
        }

        if (!empty($parte->ifparameters) && !empty($parte->parameters)) {
            foreach ($parte->parameters as $param) {
                if (strtolower((string) $param->attribute) === 'name') {
                    return trim($this->decodificarTexto((string) $param->value));
                }
            }
        }

        return '';
    }

    private function decodificarTexto($texto)
    {
        $resultado = '';

        foreach (imap_mime_header_decode((string) $texto) as $parte) {
            $charset = strtoupper((string) $parte->charset);
            if ($charset === 'DEFAULT' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
                $resultado .= $parte->text;
            } else {
                $resultado .= $this->convertirAUtf8($parte->text, $parte->charset);
            }
        }

        return $this->convertirAUtf8($resultado, 'UTF-8');
    }

    /**
     * Convierte texto a UTF-8 tolerando charsets desconocidos: en PHP 8,
     * mb_convert_encoding lanza ValueError si el correo declara un charset
     * que mbstring no conoce (el @ no atrapa excepciones), y eso tumbaba
     * la sincronización completa por un solo correo mal formado.
     */
    private function convertirAUtf8($texto, $charset)
    {
        $texto = (string) $texto;
        $charset = trim((string) $charset);

        if ($charset !== '' && strtoupper($charset) !== 'UTF-8'
            && strtoupper($charset) !== 'US-ASCII' && strtoupper($charset) !== 'DEFAULT') {
            try {
                $convertido = @mb_convert_encoding($texto, 'UTF-8', $charset);
                if (is_string($convertido) && mb_check_encoding($convertido, 'UTF-8')) {
                    return $convertido;
                }
            } catch (Throwable $e) {
                // Charset inventado o mal escrito: se limpia abajo
            }
        }

        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        // Charset ausente o declarado incorrectamente: Windows-1252 es el
        // caso habitual de estos correos en español y conserva tildes/ñ.
        try {
            $convertido = @mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
            if (is_string($convertido) && mb_check_encoding($convertido, 'UTF-8')) {
                return $convertido;
            }
        } catch (Throwable $e) {
            // Si tampoco se puede convertir, se descartan solo los bytes malos.
        }

        // Último respaldo: garantizar UTF-8 válido (nunca lanza).
        $limpio = @mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        return is_string($limpio) ? $limpio : $texto;
    }
}
