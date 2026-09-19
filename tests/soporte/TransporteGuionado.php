<?php
/**
 * Un Graph de mentira que contesta lo que dice un guion, en orden.
 *
 * Sirve para dos cosas, y es la misma clase para las dos:
 *
 *   Guionado   La prueba arma el guion: "al próximo GET contesta 429 con
 *              Retry-After 7". Así se ensayan los días malos de Microsoft
 *              —ritmo, caídas, token vencido, 409, una subida cortada— sin
 *              esperar a que pasen.
 *
 *   Grabado    desdeArchivo() lee un intercambio real guardado con
 *              `cli/probar_sharepoint.php --grabar`.
 *
 * En los dos casos, una petición que el guion no esperaba FALLA. Es lo que
 * detecta una ida y vuelta a Graph agregada por descuido: con 40.000
 * documentos, una llamada de más por documento son 40.000 llamadas.
 */

require_once __DIR__ . '/../../app/helpers/TransporteGraph.php';

class TransporteGuionado implements TransporteGraph
{
    /** @var array cada paso: [metodo, patrón de URL (regex), respuesta o callable] */
    private $guion = [];

    /** Lo que llegó, para que la prueba mire cabeceras y cuerpos. */
    public $recibidas = [];

    public static function desdeArchivo($archivo)
    {
        $t = new self();
        foreach (json_decode((string) file_get_contents($archivo), true) as $paso) {
            $url = preg_quote(strtok($paso['url'], '?'), '~');
            $t->luego($paso['metodo'], '~^' . $url . '~', $paso['respuesta']);
        }
        return $t;
    }

    /**
     * @param array|callable $respuesta ['codigo' => …, 'cabeceras' => […],
     *        'cuerpo' => string|array (se codifica como JSON)] o una función
     *        que recibe la petición y la devuelve.
     */
    public function luego($metodo, $patron, $respuesta)
    {
        $this->guion[] = [strtoupper($metodo), $patron, $respuesta];
        return $this;
    }

    /** El mismo paso, $veces seguidas. */
    public function repetir($veces, $metodo, $patron, $respuesta)
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->luego($metodo, $patron, $respuesta);
        }
        return $this;
    }

    /** El token de Entra ID, que casi todas las pruebas necesitan primero. */
    public function conToken($token = 'token-1', $vence = 3599)
    {
        return $this->luego('POST', '~login\.microsoftonline\.com~',
            ['codigo' => 200, 'cuerpo' => ['access_token' => $token, 'expires_in' => $vence]]);
    }

    public function enviar(array $peticion)
    {
        $this->recibidas[] = $peticion;
        if (!$this->guion) {
            throw new LogicException('Petición que el guion no esperaba: '
                . $peticion['metodo'] . ' ' . $peticion['url']);
        }
        [$metodo, $patron, $respuesta] = array_shift($this->guion);
        if ($metodo !== strtoupper($peticion['metodo']) || !preg_match($patron, $peticion['url'])) {
            throw new LogicException('Se esperaba ' . $metodo . ' ' . $patron . ' y llegó '
                . $peticion['metodo'] . ' ' . $peticion['url']);
        }
        if (is_callable($respuesta)) {
            $respuesta = $respuesta($peticion);
        }

        $cuerpo = $respuesta['cuerpo'] ?? '';
        if (is_array($cuerpo)) {
            $cuerpo = json_encode($cuerpo);
        }
        $codigo = (int) ($respuesta['codigo'] ?? 200);
        $destino = $peticion['guardar_en'] ?? null;
        if (is_resource($destino) && $codigo >= 200 && $codigo < 300) {
            fwrite($destino, $cuerpo);
            $cuerpo = '';
        }

        return [
            'codigo' => $codigo,
            'cabeceras' => array_change_key_case($respuesta['cabeceras'] ?? [], CASE_LOWER),
            'cuerpo' => $cuerpo,
            'error' => (string) ($respuesta['error'] ?? ''),
        ];
    }

    /** ¿Se usó todo el guion? Lo que sobra es algo que no se llegó a pedir. */
    public function pendientes()
    {
        return count($this->guion);
    }
}
