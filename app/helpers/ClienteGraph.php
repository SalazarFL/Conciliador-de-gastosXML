<?php
/**
 * Cómo se le habla a SharePoint por Microsoft Graph, con las redes puestas.
 *
 * cli/probar_sharepoint.php demostró que se puede leer y escribir en la
 * biblioteca sin nadie con la sesión iniciada. Lo que ese script no tiene, y
 * esto sí, es todo lo que hace falta para que funcione cuarenta mil veces
 * seguidas y con Microsoft de mal humor:
 *
 *   Token        Se pide una vez y se guarda —en memoria y en
 *                storage/cache/graph_token.json— hasta 5 minutos antes de
 *                vencer. Pedirlo en cada página cuesta 200-400 ms y tiene su
 *                propio límite de ritmo. Es una credencial portadora en disco:
 *                el mismo trato que local.clave.php. Si Graph lo rechaza igual
 *                (lo revocaron, cambió el secreto), se tira y se pide otro,
 *                una sola vez.
 *
 *   Reintentos   429: se espera EXACTAMENTE lo que dice Retry-After; ignorarlo
 *                es lo que hace que Microsoft apriete más. 503/504/509 y la red
 *                caída: espera creciente 1-2-4-8 s con algo de azar, hasta 5
 *                intentos. 500: dos reintentos, y solo si repetir no puede
 *                hacer daño. 409 "ya existe": ni se reintenta ni es un error,
 *                es una respuesta, y sale como AlmacenConflicto. 507 (sin
 *                espacio): se grita.
 *
 *                Lo que no es idempotente —POST, PATCH— no se reintenta a
 *                ciegas: un POST /copy repetido son dos copias. Solo tras un
 *                429 o un 401, que garantizan que no se hizo nada. Quien sabe
 *                que su POST sí se puede repetir lo dice ('idempotente').
 *
 *   Presupuesto  8 segundos por operación en una página, 300 en la consola.
 *                Sin tope, un rato de SharePoint lento convierte cada página
 *                en un tiempo agotado de Apache en vez de en un mensaje.
 *
 *   Corte        Tras 5 fallos seguidos, 60 segundos contestando al instante
 *                que SharePoint no responde, sin salir a la red. Una página de
 *                cien documentos no tiene por qué esperar cien veces lo mismo.
 *                Vive en un archivo porque cada página es un proceso nuevo.
 *
 *   Lotes        POST /$batch, de a 20: la diferencia entre que verificar una
 *                migración tarde horas o días.
 *
 *   Registro     storage/logs/graph.log: verbo, ruta, estado, milisegundos,
 *                intento. Sin esto, diagnosticar "SharePoint anda lento" es
 *                adivinar. Nunca cabeceras ni cuerpos ni consultas de las
 *                URL de subida: ahí viajan credenciales.
 *
 *   Archivos     Hasta 4 MiB, un PUT. Más, una sesión de carga por trozos de
 *   grandes      10 MiB (múltiplo de 320 KiB, como exige Graph), secuencial y
 *                sin optimizar: con 81 KB de promedio este camino es la
 *                excepción, y el código que casi nunca corre es el que conviene
 *                tener simple. Ver subirPorPartes().
 *
 * Todo lo que habla con la red pasa por un TransporteGraph, así que las
 * pruebas lo ejercitan entero sin red y sin credenciales.
 */

require_once __DIR__ . '/TransporteGraph.php';
require_once __DIR__ . '/ErrorGraph.php';
require_once __DIR__ . '/AlmacenConflicto.php';

class ClienteGraph
{
    public const BASE = 'https://graph.microsoft.com/v1.0';

    /** Hasta acá, un PUT simple. Es el límite que pone Graph. */
    public const LIMITE_SUBIDA_SIMPLE = 4194304;

    /** 32 × 320 KiB. Graph exige múltiplos de 320 KiB salvo en el último trozo. */
    public const TROZO = 10485760;

    /** Lo que Graph acepta en un $batch. */
    public const POR_LOTE = 20;

    private const RENOVAR_ANTES = 300;
    private const MAX_INTENTOS = 5;
    private const FALLOS_PARA_CORTAR = 5;
    private const PAUSA_DEL_CORTE = 60;
    private const VERBOS_IDEMPOTENTES = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    private $config;
    private $transporte;
    private $token = null;
    private $tokenVence = 0;
    private $presupuesto;
    private $reloj;
    private $dormir;
    private $archivoToken;
    private $archivoCircuito;
    private $archivoRegistro;

    /**
     * @param array $config    tenant_id, client_id, client_secret, drive_id
     * @param array $opciones  presupuesto (s), carpeta_estado (token y corte;
     *                         null = solo en memoria), registro (ruta o null),
     *                         reloj y dormir (para las pruebas: nadie espera
     *                         de verdad 60 segundos en una prueba)
     */
    public function __construct(array $config, ?TransporteGraph $transporte = null, array $opciones = [])
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'drive_id'] as $clave) {
            if (trim((string) ($config[$clave] ?? '')) === '') {
                throw new InvalidArgumentException("Falta '{$clave}' en la configuración de SharePoint.");
            }
        }
        $this->config = $config;
        $this->transporte = $transporte ?? new TransporteCurl();
        $this->presupuesto = (float) ($opciones['presupuesto'] ?? (PHP_SAPI === 'cli' ? 300 : 8));
        $this->reloj = $opciones['reloj'] ?? function () { return microtime(true); };
        $this->dormir = $opciones['dormir'] ?? function ($segundos) { usleep((int) ($segundos * 1000000)); };

        $raiz = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        $estado = array_key_exists('carpeta_estado', $opciones)
            ? $opciones['carpeta_estado']
            : $raiz . DIRECTORY_SEPARATOR . 'cache';
        // Un archivo por aplicación: cambiar de tenant o de app en la
        // configuración no puede reusar el token de la otra.
        $huella = substr(md5($config['tenant_id'] . '|' . $config['client_id']), 0, 12);
        $this->archivoToken = $estado === null ? null
            : rtrim($estado, '\\/') . DIRECTORY_SEPARATOR . 'graph_token_' . $huella . '.json';
        $this->archivoCircuito = $estado === null ? null
            : rtrim($estado, '\\/') . DIRECTORY_SEPARATOR . 'graph_circuito.json';
        $this->archivoRegistro = array_key_exists('registro', $opciones)
            ? $opciones['registro']
            : $raiz . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'graph.log';
    }

    /**
     * La configuración de ESTA máquina, o null si no tiene.
     *
     * Vive en app/config/local.graph.php, que git ignora: el repositorio es
     * público y el secreto no entra en ningún commit.
     */
    public static function configuracion($archivo = null)
    {
        $archivo = $archivo ?? dirname(__DIR__) . '/config/local.graph.php';
        if (!is_file($archivo)) {
            return null;
        }
        $config = require $archivo;
        if (!is_array($config)) {
            return null;
        }
        foreach (['tenant_id', 'client_id', 'client_secret', 'drive_id'] as $clave) {
            $valor = trim((string) ($config[$clave] ?? ''));
            if ($valor === '' || strpos($valor, 'PEGA-') === 0) {
                return null;
            }
        }
        return $config;
    }

    /** El cliente con la configuración de esta máquina, o null si no tiene. */
    public static function desdeConfiguracion(array $opciones = [])
    {
        $config = self::configuracion();
        return $config === null ? null : new self($config, null, $opciones);
    }

    public function driveId()
    {
        return (string) $this->config['drive_id'];
    }

    /** La biblioteca, para las rutas de la API: /drives/{id}. */
    public function unidad()
    {
        return '/drives/' . rawurlencode($this->driveId());
    }

    /**
     * Un documento por su ruta en la biblioteca: /drives/{id}/root:/a/b.xml:
     *
     * Cada tramo se codifica por separado —los nombres traen espacios, eñes y
     * acentos— y se aceptan las dos barras, porque las rutas salen de Windows.
     * Termina en ':' para que se le pueda pegar '/content', '/createUploadSession'…
     */
    public function rutaItem($ruta)
    {
        $tramos = array_filter(explode('/', str_replace('\\', '/', (string) $ruta)), 'strlen');
        if (!$tramos) {
            return $this->unidad() . '/root';
        }
        return $this->unidad() . '/root:/' . implode('/', array_map('rawurlencode', $tramos)) . ':';
    }

    // ── Pedir ─────────────────────────────────────────────────────────────

    /**
     * Una petición a Graph, con todo lo de arriba.
     *
     * @param string $ruta  Relativa a /v1.0 ('/drives/…') o una URL absoluta
     *                      (las de subida, el token).
     * @param array  $o     json (se manda como JSON), cuerpo (crudo),
     *                      cabeceras, autenticar (true), idempotente (según el
     *                      verbo), aceptar (códigos no-2xx que se devuelven en
     *                      vez de lanzar, p. ej. [404]), guardar_en (recurso),
     *                      seguir (redirecciones), presupuesto (s), ruta (la
     *                      del documento, para los mensajes).
     * @return array codigo, cabeceras, cuerpo, json
     * @throws ErrorGraph|AlmacenConflicto
     */
    public function pedir($metodo, $ruta, array $o = [])
    {
        $metodo = strtoupper((string) $metodo);
        $url = preg_match('~^https?://~i', (string) $ruta) ? (string) $ruta : self::BASE . $ruta;
        $autenticar = $o['autenticar'] ?? true;
        $idempotente = $o['idempotente'] ?? in_array($metodo, self::VERBOS_IDEMPOTENTES, true);
        $aceptar = $o['aceptar'] ?? [];
        $limite = $this->ahora() + (float) ($o['presupuesto'] ?? $this->presupuesto);

        $this->exigirCircuitoCerrado();

        $cabecerasBase = $o['cabeceras'] ?? [];
        $cuerpo = $o['cuerpo'] ?? null;
        if (array_key_exists('json', $o)) {
            $cuerpo = json_encode($o['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $cabecerasBase['Content-Type'] = 'application/json';
        }

        $tokenRenovado = false;
        $errores500 = 0;
        for ($intento = 1; ; $intento++) {
            $cabeceras = $cabecerasBase;
            if ($autenticar) {
                $cabeceras['Authorization'] = 'Bearer ' . $this->token($limite);
            }
            $restante = $limite - $this->ahora();
            if ($restante <= 0) {
                $this->anotarFallo();
                throw new ErrorGraph(0, 'timeout', '', 'SharePoint no contestó a tiempo.');
            }
            if (isset($o['guardar_en']) && is_resource($o['guardar_en'])) {
                // Un intento cortado a la mitad dejó bytes: el siguiente
                // empieza de cero, o el documento quedaría con dos comienzos.
                ftruncate($o['guardar_en'], 0);
                rewind($o['guardar_en']);
            }

            $inicio = $this->ahora();
            $respuesta = $this->transporte->enviar([
                'metodo' => $metodo,
                'url' => $url,
                'cabeceras' => $cabeceras,
                'cuerpo' => $cuerpo,
                'timeout' => min($restante, 120.0),
                'seguir' => !empty($o['seguir']),
                'guardar_en' => $o['guardar_en'] ?? null,
            ]) + ['codigo' => 0, 'cabeceras' => [], 'cuerpo' => '', 'error' => ''];
            $codigo = (int) $respuesta['codigo'];
            $this->registrar($metodo, $url, $codigo, $this->ahora() - $inicio, $intento);

            if (($codigo >= 200 && $codigo < 300) || in_array($codigo, $aceptar, true)) {
                $this->anotarExito();
                $json = json_decode((string) $respuesta['cuerpo'], true);
                $respuesta['json'] = is_array($json) ? $json : null;
                return $respuesta;
            }

            // El token que se tenía ya no sirve. Uno nuevo, una sola vez: si
            // tampoco sirve, el problema es la aplicación, no el token.
            if ($codigo === 401 && $autenticar && !$tokenRenovado) {
                $tokenRenovado = true;
                $this->olvidarToken();
                continue;
            }

            $error = ErrorGraph::deRespuesta($respuesta);
            if ($codigo === 409 && in_array($error->codigoGraph(), ['nameAlreadyExists', 'conflict'], true)) {
                $this->anotarExito();   // SharePoint contestó: no es una caída
                $donde = (string) ($o['ruta'] ?? $url);
                throw new AlmacenConflicto($donde, '', 'Ya hay un documento con ese nombre en ' . $donde . '.');
            }

            $espera = null;
            if ($codigo === 429) {
                $espera = $this->retryAfter($respuesta) ?? min(60.0, pow(2, $intento));
            } elseif ($idempotente && in_array($codigo, [0, 502, 503, 504, 509], true)) {
                $espera = $this->retryAfter($respuesta) ?? $this->creciente($intento);
            } elseif ($idempotente && $codigo === 500 && $errores500 < 2) {
                $errores500++;
                $espera = $this->creciente($intento);
            }

            if ($espera === null || $intento >= self::MAX_INTENTOS) {
                if ($error->esTransitorio()) {
                    $this->anotarFallo();
                } else {
                    $this->anotarExito();
                }
                throw $error;
            }
            if ($this->ahora() + $espera > $limite) {
                $this->anotarFallo();
                throw new ErrorGraph($codigo, $error->codigoGraph(), $error->detalle(),
                    $codigo === 429
                        ? 'SharePoint pidió esperar ' . (int) ceil($espera) . ' s y no alcanza el tiempo. Se reintenta solo en la próxima vuelta.'
                        : 'SharePoint no contestó a tiempo. ' . $error->getMessage());
            }
            ($this->dormir)($espera);
        }
    }

    /**
     * Varias lecturas en una sola ida y vuelta. Graph admite 20 por lote; acá
     * se parten solas.
     *
     * Las que vuelven con 429 o 503 se reintentan juntas, esperando lo que
     * pida la más exigente, mientras alcance el presupuesto. Las que no
     * alcanzan se devuelven con su código: quien llama decide si le importan.
     *
     * @param array $peticiones id => ['metodo' => 'GET', 'url' => '/drives/…']
     * @return array id => ['codigo', 'json', 'cabeceras']
     */
    public function lote(array $peticiones)
    {
        $resultados = [];
        $limite = $this->ahora() + $this->presupuesto;
        $pendientes = $peticiones;

        for ($ronda = 1; $pendientes && $ronda <= 3; $ronda++) {
            $reintentar = [];
            $esperaMayor = 0.0;
            foreach (array_chunk($pendientes, self::POR_LOTE, true) as $grupo) {
                $pedidos = [];
                $soloLecturas = true;
                foreach ($grupo as $id => $p) {
                    $metodo = strtoupper($p['metodo'] ?? 'GET');
                    $soloLecturas = $soloLecturas && $metodo === 'GET';
                    $pedido = ['id' => (string) $id, 'method' => $metodo, 'url' => $p['url']];
                    if (isset($p['json'])) {
                        $pedido['body'] = $p['json'];
                        $pedido['headers'] = ['Content-Type' => 'application/json'];
                    }
                    $pedidos[] = $pedido;
                }
                $r = $this->pedir('POST', '/$batch', [
                    'json' => ['requests' => $pedidos],
                    'idempotente' => $soloLecturas,
                    'presupuesto' => max(1.0, $limite - $this->ahora()),
                ]);
                foreach (($r['json']['responses'] ?? []) as $respuesta) {
                    $id = (string) $respuesta['id'];
                    $codigo = (int) ($respuesta['status'] ?? 0);
                    $cabeceras = array_change_key_case((array) ($respuesta['headers'] ?? []), CASE_LOWER);
                    $resultados[$id] = [
                        'codigo' => $codigo,
                        'json' => is_array($respuesta['body'] ?? null) ? $respuesta['body'] : null,
                        'cabeceras' => $cabeceras,
                    ];
                    if (in_array($codigo, [429, 503], true) && isset($grupo[$id])) {
                        $reintentar[$id] = $grupo[$id];
                        $esperaMayor = max($esperaMayor, (float) ($cabeceras['retry-after'] ?? 1));
                    }
                }
            }
            if (!$reintentar || $this->ahora() + $esperaMayor > $limite) {
                break;
            }
            ($this->dormir)($esperaMayor);
            $pendientes = $reintentar;
        }

        return $resultados;
    }

    // ── Subir y bajar ─────────────────────────────────────────────────────

    /**
     * Sube un archivo de esta máquina a una ruta de la biblioteca.
     *
     * @param string $conflicto 'fail' (lo normal: si ya hay algo con ese
     *                          nombre, AlmacenConflicto), 'replace' o 'rename'.
     * @return array El driveItem que quedó: id, cTag, eTag, size, …
     * @throws AlmacenConflicto|ErrorGraph
     */
    public function subir($ruta, $archivoLocal, $conflicto = 'fail', array $o = [])
    {
        $tamano = @filesize($archivoLocal);
        if ($tamano === false) {
            throw new RuntimeException('No está el archivo que se quiere subir: ' . $archivoLocal);
        }
        if ($tamano > self::LIMITE_SUBIDA_SIMPLE) {
            return $this->subirPorPartes($ruta, $archivoLocal, (int) $tamano, $conflicto, $o);
        }

        $r = $this->pedir('PUT',
            $this->rutaItem($ruta) . '/content?@microsoft.graph.conflictBehavior=' . rawurlencode($conflicto),
            $o + [
                'cuerpo' => (string) file_get_contents($archivoLocal),
                'cabeceras' => ['Content-Type' => 'application/octet-stream'],
                'ruta' => $ruta,
            ]);

        return (array) $r['json'];
    }

    /**
     * La subida por sesión de carga, para lo que pasa de 4 MiB.
     *
     * Tres detalles que cuestan una tarde si no están escritos:
     *
     *   - Los PUT de los trozos NO llevan Authorization: la URL de la sesión ya
     *     viene autorizada, y mandarla hace que Graph rechace el trozo.
     *   - Entre trozos se recibe 202 con nextExpectedRanges: por ahí se
     *     retoma. Si un trozo falla del todo, se le pregunta a la sesión qué
     *     le falta y se sigue desde ahí, en vez de empezar de nuevo.
     *   - El driveItem —el id— solo llega con el último trozo.
     *
     * Si algo sale mal sin remedio, la sesión se cancela: una a medias ocupa
     * cuota hasta que vence.
     */
    private function subirPorPartes($ruta, $archivoLocal, $tamano, $conflicto, array $o)
    {
        $sesion = $this->pedir('POST', $this->rutaItem($ruta) . '/createUploadSession', [
            'json' => ['item' => ['@microsoft.graph.conflictBehavior' => $conflicto]],
            // Pedir otra sesión si la primera no llegó no hace daño: las que
            // no se usan vencen solas.
            'idempotente' => true,
            'ruta' => $ruta,
        ] + $o);
        $url = (string) ($sesion['json']['uploadUrl'] ?? '');
        if ($url === '') {
            throw new ErrorGraph(0, 'sinSesion', '', 'SharePoint no abrió la sesión de carga.');
        }

        $fp = fopen($archivoLocal, 'rb');
        $retomadas = 0;
        $terminado = false;
        try {
            $desde = 0;
            while (true) {
                fseek($fp, $desde);
                $trozo = (string) fread($fp, self::TROZO);
                $hasta = $desde + strlen($trozo) - 1;
                try {
                    $r = $this->pedir('PUT', $url, [
                        'autenticar' => false,
                        'cuerpo' => $trozo,
                        'cabeceras' => ['Content-Range' => "bytes {$desde}-{$hasta}/{$tamano}"],
                        'idempotente' => true,
                        'aceptar' => [202],
                        'ruta' => $ruta,
                    ] + $o);
                } catch (ErrorGraph $e) {
                    if (($e->esTransitorio() || $e->codigoHttp() === 416) && $retomadas < 3) {
                        $retomadas++;
                        $desde = $this->loQueFalta($url, $o);
                        continue;
                    }
                    throw $e;
                }

                if ($r['codigo'] === 202) {
                    $desde = self::inicioDeRango($r['json']['nextExpectedRanges'][0] ?? '', $hasta + 1);
                    continue;
                }
                $terminado = true;
                return (array) $r['json'];
            }
        } finally {
            fclose($fp);
            if (!$terminado) {
                try {
                    $this->pedir('DELETE', $url, ['autenticar' => false, 'aceptar' => [404]] + $o);
                } catch (Throwable $e) {
                    // Si no se pudo cancelar, vence sola.
                }
            }
        }
    }

    /** Desde qué byte hay que seguir, según la sesión. */
    private function loQueFalta($url, array $o)
    {
        $r = $this->pedir('GET', $url, ['autenticar' => false] + $o);
        return self::inicioDeRango($r['json']['nextExpectedRanges'][0] ?? '', 0);
    }

    private static function inicioDeRango($rango, $siNoHay)
    {
        return preg_match('~^(\d+)-~', (string) $rango, $m) ? (int) $m[1] : (int) $siNoHay;
    }

    /**
     * Baja el contenido de un documento a un recurso abierto.
     *
     * @param string $item Ruta de la API del documento: '/drives/…/items/{id}'
     *                     o rutaItem($ruta).
     * @return bool false si no está (404).
     */
    public function bajar($item, $destino, array $o = [])
    {
        // /content contesta con una redirección a una URL firmada, de otro
        // dominio: cURL no le manda el token a otro dominio, que es lo que se
        // quiere.
        $r = $this->pedir('GET', $item . '/content', $o + [
            'seguir' => true,
            'guardar_en' => $destino,
            'aceptar' => [404],
        ]);
        return $r['codigo'] !== 404;
    }

    // ── Token ─────────────────────────────────────────────────────────────

    private function token($limite)
    {
        $ahora = $this->ahora();
        if ($this->token !== null && $this->tokenVence - self::RENOVAR_ANTES > $ahora) {
            return $this->token;
        }

        if ($this->archivoToken !== null && is_file($this->archivoToken)) {
            $guardado = json_decode((string) @file_get_contents($this->archivoToken), true);
            if (is_array($guardado) && !empty($guardado['token'])
                && (float) ($guardado['vence'] ?? 0) - self::RENOVAR_ANTES > $ahora) {
                $this->token = (string) $guardado['token'];
                $this->tokenVence = (float) $guardado['vence'];
                return $this->token;
            }
        }

        $r = $this->pedir('POST',
            'https://login.microsoftonline.com/' . rawurlencode($this->config['tenant_id']) . '/oauth2/v2.0/token',
            [
                'autenticar' => false,
                // Pedir un token dos veces no hace daño.
                'idempotente' => true,
                'cabeceras' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'cuerpo' => http_build_query([
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]),
                'presupuesto' => max(1.0, $limite - $ahora),
            ]);

        $token = (string) ($r['json']['access_token'] ?? '');
        if ($token === '') {
            throw new ErrorGraph($r['codigo'], 'sinToken', '', 'Entra ID contestó pero no dio el token.');
        }
        $this->token = $token;
        $this->tokenVence = $this->ahora() + (int) ($r['json']['expires_in'] ?? 3599);
        $this->guardarEstado($this->archivoToken, ['token' => $this->token, 'vence' => $this->tokenVence]);

        return $this->token;
    }

    private function olvidarToken()
    {
        $this->token = null;
        $this->tokenVence = 0;
        if ($this->archivoToken !== null) {
            @unlink($this->archivoToken);
        }
    }

    // ── Corte ─────────────────────────────────────────────────────────────

    private $circuito = null;

    private function exigirCircuitoCerrado()
    {
        $estado = $this->circuito();
        $faltan = (float) ($estado['hasta'] ?? 0) - $this->ahora();
        if ($faltan > 0) {
            throw new ErrorGraph(503, 'cortado', '',
                'SharePoint no está respondiendo: después de varios intentos seguidos se dejó de '
                . 'insistir por un minuto. Se vuelve a probar en ' . (int) ceil($faltan) . ' s.');
        }
    }

    private function anotarFallo()
    {
        $estado = $this->circuito();
        $estado['fallos'] = (int) ($estado['fallos'] ?? 0) + 1;
        if ($estado['fallos'] >= self::FALLOS_PARA_CORTAR) {
            $estado['hasta'] = $this->ahora() + self::PAUSA_DEL_CORTE;
            $estado['fallos'] = 0;
        }
        $this->guardarCircuito($estado);
    }

    private function anotarExito()
    {
        $estado = $this->circuito();
        if ((int) ($estado['fallos'] ?? 0) !== 0 || (float) ($estado['hasta'] ?? 0) !== 0.0) {
            $this->guardarCircuito(['fallos' => 0, 'hasta' => 0]);
        }
    }

    private function circuito()
    {
        // Se relee en cada petición: otro proceso pudo haberlo abierto.
        if ($this->archivoCircuito !== null && is_file($this->archivoCircuito)) {
            $leido = json_decode((string) @file_get_contents($this->archivoCircuito), true);
            if (is_array($leido)) {
                $this->circuito = $leido;
            }
        }
        return $this->circuito ?? ['fallos' => 0, 'hasta' => 0];
    }

    private function guardarCircuito(array $estado)
    {
        $this->circuito = $estado;
        $this->guardarEstado($this->archivoCircuito, $estado);
    }

    // ── Apoyo ─────────────────────────────────────────────────────────────

    private function ahora()
    {
        return (float) ($this->reloj)();
    }

    /** Espera creciente 1-2-4-8 s, con hasta medio segundo de azar. */
    private function creciente($intento)
    {
        return min(8.0, pow(2, $intento - 1)) + mt_rand(0, 500) / 1000;
    }

    /** Retry-After en segundos. La forma de fecha no la usa Graph. */
    private function retryAfter(array $respuesta)
    {
        $valor = trim((string) ($respuesta['cabeceras']['retry-after'] ?? ''));
        return ctype_digit($valor) ? (float) $valor : null;
    }

    /** Archivo temporal y rename: dos procesos a la vez no dejan un JSON a medias. */
    private function guardarEstado($archivo, array $datos)
    {
        if ($archivo === null) {
            return;
        }
        $carpeta = dirname($archivo);
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
            return;
        }
        $temporal = $archivo . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporal, json_encode($datos)) !== false) {
            @chmod($temporal, 0600);
            if (!@rename($temporal, $archivo)) {
                @unlink($temporal);
            }
        }
    }

    private function registrar($metodo, $url, $codigo, $segundos, $intento)
    {
        if ($this->archivoRegistro === null) {
            return;
        }
        // Solo la ruta: la consulta de una URL de subida es un permiso.
        $ruta = (string) parse_url($url, PHP_URL_PATH);
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== 'graph.microsoft.com') {
            $ruta = $host . $ruta;
        }
        $linea = sprintf("%s\t%s\t%s\t%d\t%dms\tintento %d\n",
            date('Y-m-d H:i:s'), $metodo, $ruta, $codigo, (int) round($segundos * 1000), $intento);

        $carpeta = dirname($this->archivoRegistro);
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0777, true);
        }
        // Que no crezca sin fin: pasado 5 MB se guarda uno anterior y se sigue.
        if (@filesize($this->archivoRegistro) > 5242880) {
            @rename($this->archivoRegistro, $this->archivoRegistro . '.1');
        }
        @file_put_contents($this->archivoRegistro, $linea, FILE_APPEND | LOCK_EX);
    }
}
