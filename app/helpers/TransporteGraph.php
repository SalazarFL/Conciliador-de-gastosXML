<?php
/**
 * Lo único que ClienteGraph necesita del mundo: mandar una petición HTTP y
 * recibir la respuesta.
 *
 * Está aparte por una regla dura: todas las pruebas corren SIN RED y sin
 * app/config/local.graph.php. Una prueba que necesita un secreto es una prueba
 * que no corre en la máquina de la siguiente persona. Así que todo lo que
 * tiene lógica —reintentos, token, presupuesto de tiempo, subida por partes—
 * vive en ClienteGraph, y esto queda tan tonto que no hace falta probarlo más
 * que con la prueba de vida contra SharePoint de verdad.
 *
 * La petición:
 *   metodo      'GET', 'PUT', …
 *   url         absoluta
 *   cabeceras   ['Nombre' => 'valor']
 *   cuerpo      string o null
 *   timeout     segundos para esta llamada, ya descontado el presupuesto
 *   seguir      seguir redirecciones (bajar contenido redirige a una URL firmada)
 *   guardar_en  un recurso abierto: si la respuesta es 2xx, el cuerpo va ahí
 *               en vez de a memoria. Un PDF de 20 MB no tiene por qué pasar
 *               entero por una variable.
 *
 * La respuesta:
 *   codigo      HTTP, o 0 si ni siquiera hubo conexión
 *   cabeceras   ['nombre-en-minusculas' => 'valor'], las de la última respuesta
 *   cuerpo      string ('' si fue a guardar_en)
 *   error       el texto de cURL cuando codigo es 0
 */
interface TransporteGraph
{
    public function enviar(array $peticion);
}

/**
 * Un transporte que además sabe mandar varias peticiones a la vez.
 *
 * Copiar la carpeta de un pago son cientos de bajadas y subidas que no
 * dependen unas de otras, y cada una pasa casi todo su tiempo esperando a la
 * red: de a una, 308 facturas eran veinticinco minutos. ClienteGraph::
 * enParalelo() lo usa si el transporte lo tiene; si no —las pruebas—, manda
 * de a una con enviar() y el resultado es el mismo.
 */
interface TransporteGraphParalelo extends TransporteGraph
{
    /**
     * @param array $peticiones id => petición, como en enviar()
     * @return array id => respuesta, como en enviar()
     */
    public function enviarVarios(array $peticiones);
}

/**
 * El de verdad.
 *
 * Con UNA conexión para todo el proceso, que se reusa de una llamada a la
 * siguiente. Abrir una nueva cada vez —DNS, TCP y el saludo TLS— costaba unos
 * 300 ms antes de mandar nada, y guardar un comprobante son diez o quince
 * llamadas: medido desde la oficina, cada llamada baja de ~500 ms a ~150 ms.
 * curl_reset() limpia las opciones de la anterior pero conserva la conexión
 * abierta (y el certificado raíz de php.ini, que PHP vuelve a poner).
 */
class TransporteCurl implements TransporteGraphParalelo
{
    /** @var resource|CurlHandle|null */
    private static $conexion = null;

    /**
     * Para las de a varias. Las conexiones abiertas son del multi, no de cada
     * petición: guardándolo, la segunda tanda ya no paga el saludo TLS.
     *
     * @var resource|CurlMultiHandle|null
     */
    private static $multi = null;

    public function enviar(array $peticion)
    {
        if (self::$conexion === null) {
            self::$conexion = curl_init();
        } else {
            curl_reset(self::$conexion);
        }
        $ch = self::$conexion;
        $estado = $this->preparar($ch, $peticion);

        $ok = curl_exec($ch);
        $error = $ok === false ? curl_error($ch) : '';
        $codigo = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['codigo' => $codigo, 'cabeceras' => $estado->recibidas, 'cuerpo' => $estado->cuerpo, 'error' => $error];
    }

    public function enviarVarios(array $peticiones)
    {
        if (!$peticiones) {
            return [];
        }
        if (self::$multi === null) {
            self::$multi = curl_multi_init();
        }
        $multi = self::$multi;

        $enCurso = [];
        foreach ($peticiones as $id => $peticion) {
            $ch = curl_init();
            $enCurso[$id] = ['ch' => $ch, 'estado' => $this->preparar($ch, $peticion)];
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $estadoMulti = curl_multi_exec($multi, $activas);
            if ($activas && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($activas && $estadoMulti === CURLM_OK);

        // El resultado de cada una: curl_multi_info_read es la única forma de
        // saber si una falló sin respuesta (codigo 0) y por qué.
        $errores = [];
        while ($info = curl_multi_info_read($multi)) {
            foreach ($enCurso as $id => $c) {
                if ($c['ch'] === $info['handle'] && $info['result'] !== CURLE_OK) {
                    $errores[$id] = curl_strerror($info['result']);
                }
            }
        }

        $respuestas = [];
        foreach ($enCurso as $id => $c) {
            $error = $errores[$id] ?? '';
            $respuestas[$id] = [
                'codigo' => $error !== '' ? 0 : (int) curl_getinfo($c['ch'], CURLINFO_RESPONSE_CODE),
                'cabeceras' => $c['estado']->recibidas,
                'cuerpo' => $c['estado']->cuerpo,
                'error' => $error,
            ];
            curl_multi_remove_handle($multi, $c['ch']);
            curl_close($c['ch']);
        }
        return $respuestas;
    }

    /**
     * Deja lista una petición en $ch y devuelve dónde van a caer su código,
     * sus cabeceras y su cuerpo a medida que lleguen.
     */
    private function preparar($ch, array $peticion)
    {
        $cabeceras = [];
        foreach (($peticion['cabeceras'] ?? []) as $nombre => $valor) {
            $cabeceras[] = $nombre . ': ' . $valor;
        }
        // Sin esto cURL agrega "Expect: 100-continue" a los cuerpos grandes y
        // espera un segundo una respuesta que Graph no manda.
        $cabeceras[] = 'Expect:';

        $estado = new stdClass();
        $estado->recibidas = [];
        $estado->codigo = 0;
        $estado->cuerpo = '';
        $guardarEn = $peticion['guardar_en'] ?? null;
        $timeout = max(1.0, (float) ($peticion['timeout'] ?? 60));

        curl_setopt_array($ch, [
            CURLOPT_URL            => (string) $peticion['url'],
            CURLOPT_CUSTOMREQUEST  => (string) $peticion['metodo'],
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_FOLLOWLOCATION => !empty($peticion['seguir']),
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT_MS     => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 10.0) * 1000),
            // Cada respuesta de una cadena de redirecciones trae sus propias
            // cabeceras: se guardan las de la última, que es la que importa.
            CURLOPT_HEADERFUNCTION => function ($ch, $linea) use ($estado) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $linea, $m)) {
                    $estado->codigo = (int) $m[1];
                    $estado->recibidas = [];
                } elseif (strpos($linea, ':') !== false) {
                    [$nombre, $valor] = explode(':', $linea, 2);
                    $estado->recibidas[strtolower(trim($nombre))] = trim($valor);
                }
                return strlen($linea);
            },
            // Lo que no es 2xx (el JSON de un error, el cuerpo de una
            // redirección) nunca va al archivo: ensuciaría el documento.
            CURLOPT_WRITEFUNCTION  => function ($ch, $datos) use ($estado, $guardarEn) {
                if (is_resource($guardarEn) && $estado->codigo >= 200 && $estado->codigo < 300) {
                    return (int) fwrite($guardarEn, $datos);
                }
                $estado->cuerpo .= $datos;
                return strlen($datos);
            },
        ]);
        if (isset($peticion['cuerpo']) && $peticion['cuerpo'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $peticion['cuerpo']);
        }
        return $estado;
    }
}

/**
 * Envuelve a otro transporte y anota cada intercambio, para armar los
 * fixtures de tests/fixtures/graph con `cli/probar_sharepoint.php --grabar`.
 *
 * Lo que NO se anota, a propósito: ninguna cabecera de la petición (ahí viaja
 * el token), ningún cuerpo de petición (ahí viaja el secreto, al pedir el
 * token), la parte de consulta de las URL de subida (llevan su propia
 * autorización temporal) y el access_token de la respuesta del token. El
 * repositorio es público.
 */
class TransporteQueGraba implements TransporteGraph
{
    private $real;
    private $intercambios = [];

    public function __construct(TransporteGraph $real)
    {
        $this->real = $real;
    }

    public function enviar(array $peticion)
    {
        $respuesta = $this->real->enviar($peticion);

        $cuerpo = $respuesta['cuerpo'];
        $json = json_decode($cuerpo, true);
        if (is_array($json) && isset($json['access_token'])) {
            $json['access_token'] = 'REDACTADO';
            $cuerpo = json_encode($json);
        }
        if (is_array($json) && isset($json['uploadUrl'])) {
            $json['uploadUrl'] = strtok($json['uploadUrl'], '?') . '?REDACTADO';
            $cuerpo = json_encode($json);
        }

        $this->intercambios[] = [
            'metodo' => $peticion['metodo'],
            'url' => self::sinConsultaSecreta($peticion['url']),
            'respuesta' => [
                'codigo' => $respuesta['codigo'],
                'cabeceras' => array_intersect_key($respuesta['cabeceras'],
                    array_flip(['content-type', 'retry-after', 'location'])),
                // Lo que se bajó a un archivo no se graba: es el documento.
                'cuerpo' => $cuerpo,
            ],
        ];

        return $respuesta;
    }

    public function intercambios()
    {
        return $this->intercambios;
    }

    /** Las URL de Graph llevan la consulta a la vista; las de subida, un permiso. */
    public static function sinConsultaSecreta($url)
    {
        $url = (string) $url;
        if (stripos($url, 'tempauth') !== false || preg_match('~uploadSession|/upload/~i', $url)) {
            return strtok($url, '?') . '?REDACTADO';
        }
        return $url;
    }
}
