<?php
/**
 * Una parte de un correo, recortada del mensaje entero y no pedida al servidor.
 *
 * Bajar un correo del lote costaba un viaje al servidor por cada adjunto —la
 * estructura, después el XML, después el PDF, después el otro XML—, de unos
 * 110 ms cada uno contra mail.bm.cr, y casi todo ese tiempo es espera. El
 * mensaje entero llega en un solo viaje de unos 160 ms. Esto hace el resto:
 * dado el mensaje crudo y una sección como la numera IMAP ("2", "1.3"), da
 * exactamente los bytes que habría dado BODY[sección] —la parte sin sus
 * encabezados, todavía en su codificación de transporte—.
 *
 * La numeración es la de IMAP (RFC 3501, 6.4.5):
 *
 *   - las partes de un multipart se cuentan desde 1;
 *   - un mensaje que no es multipart tiene una sola parte, la "1": su cuerpo;
 *   - dentro de un message/rfc822, la numeración sigue por el mensaje que
 *     trae dentro: "2.1" es su primera parte, o su cuerpo si no es multipart.
 *
 * Ante cualquier duda contesta null, nunca un recorte aproximado: quien llama
 * pide entonces esa parte al servidor, como antes. Un adjunto mal recortado
 * es una factura corrupta; uno pedido de más es 110 ms.
 */
class SeccionMime
{
    /**
     * @param string $crudo   El mensaje entero, encabezados incluidos (BODY[]).
     * @param string $seccion "1", "2.1", …
     * @return string|null El cuerpo de esa parte, o null si no se sabe.
     */
    public static function recortar($crudo, $seccion)
    {
        $seccion = (string) $seccion;
        if (!is_string($crudo) || $crudo === '' || !preg_match('/^[1-9]\d*(?:\.[1-9]\d*)*$/', $seccion)) {
            return null;
        }
        return self::enMensaje($crudo, array_map('intval', explode('.', $seccion)));
    }

    /** Dentro de un mensaje (el de arriba o uno adjunto como message/rfc822). */
    private static function enMensaje($texto, array $camino)
    {
        $entidad = self::entidad($texto);
        if ($entidad === null) {
            return null;
        }
        if (!self::esMultipart($entidad)) {
            // Una sola parte: la "1" es el cuerpo, y no hay nada debajo.
            return $camino === [1] ? $entidad['cuerpo'] : null;
        }
        return self::enMultipart($entidad, $camino);
    }

    private static function enMultipart(array $entidad, array $camino)
    {
        $partes = self::partes($entidad);
        $n = array_shift($camino);
        if ($partes === null || !isset($partes[$n - 1])) {
            return null;
        }
        $hija = self::entidad($partes[$n - 1]);
        if ($hija === null) {
            return null;
        }
        if (!$camino) {
            // Una parte que es a su vez multipart no se recorta: el servidor
            // cuenta como suyo el salto de línea que queda entre su cierre y
            // el delimitador siguiente, y la regla del RFC no. Nunca es un
            // adjunto, así que no vale la pena imitarlo.
            return self::esMultipart($hija) ? null : $hija['cuerpo'];
        }
        if (self::esMultipart($hija)) {
            return self::enMultipart($hija, $camino);
        }
        if (self::tipo($hija) === 'message/rfc822') {
            return self::enMensaje($hija['cuerpo'], $camino);
        }
        return null;
    }

    /**
     * Encabezados y cuerpo de una entidad MIME. Los encabezados terminan en la
     * primera línea vacía; una entidad que empieza con una línea vacía no
     * tiene encabezados.
     *
     * @return array{encabezados:string,cuerpo:string}|null
     */
    private static function entidad($texto)
    {
        if (strncmp($texto, "\r\n", 2) === 0) {
            return ['encabezados' => '', 'cuerpo' => (string) substr($texto, 2)];
        }
        if (strncmp($texto, "\n", 1) === 0) {
            return ['encabezados' => '', 'cuerpo' => (string) substr($texto, 1)];
        }
        if (!preg_match('/\r?\n\r?\n/', $texto, $m, PREG_OFFSET_CAPTURE)) {
            // Solo encabezados: el cuerpo está vacío.
            return ['encabezados' => $texto, 'cuerpo' => ''];
        }
        $corte = $m[0][1];
        return [
            'encabezados' => (string) substr($texto, 0, $corte),
            'cuerpo' => (string) substr($texto, $corte + strlen($m[0][0])),
        ];
    }

    /** El Content-Type sin parámetros, en minúsculas; text/plain si no lo dice. */
    private static function tipo(array $entidad)
    {
        $valor = self::encabezado($entidad['encabezados'], 'content-type');
        if ($valor === null) {
            return 'text/plain';
        }
        return strtolower(trim((string) strtok($valor, ';')));
    }

    private static function esMultipart(array $entidad)
    {
        return strncmp(self::tipo($entidad), 'multipart/', 10) === 0;
    }

    /**
     * Las partes de un multipart, cada una con sus encabezados.
     *
     * Entre dos delimitadores va la parte; el salto de línea que precede a un
     * delimitador es del delimitador, no de la parte (RFC 2046, 5.1.1). Lo que
     * hay antes del primero (el preámbulo) y después del de cierre no cuenta.
     *
     * null si falta el boundary o no aparece el de cierre: un multipart
     * cortado es justo el caso en que no hay que adivinar.
     */
    private static function partes(array $entidad)
    {
        // En multipart/digest una parte sin Content-Type es message/rfc822,
        // no text/plain. Son rarísimos en facturas: al servidor.
        if (self::tipo($entidad) === 'multipart/digest') {
            return null;
        }
        $limite = self::parametro((string) self::encabezado($entidad['encabezados'], 'content-type'), 'boundary');
        if ($limite === null || $limite === '') {
            return null;
        }
        $cuerpo = $entidad['cuerpo'];
        $patron = '/(?:^|\r?\n)--' . preg_quote($limite, '/') . '(--)?[ \t]*(?:\r?\n|$)/';
        if (!preg_match_all($patron, $cuerpo, $delimitadores, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $partes = [];
        $desde = null;
        $cerrado = false;
        foreach ($delimitadores as $d) {
            $inicio = $d[0][1];
            if ($desde !== null) {
                $partes[] = (string) substr($cuerpo, $desde, $inicio - $desde);
            }
            if (isset($d[1]) && $d[1][0] === '--') {
                $cerrado = true;
                break;
            }
            $desde = $inicio + strlen($d[0][0]);
        }
        return $cerrado ? $partes : null;
    }

    /** El valor de un encabezado (el primero), desplegado; null si no está. */
    private static function encabezado($encabezados, $nombre)
    {
        $desplegados = preg_replace('/\r?\n[ \t]+/', ' ', (string) $encabezados);
        if (preg_match('/^' . preg_quote($nombre, '/') . '[ \t]*:(.*)$/mi', $desplegados, $m)) {
            return trim(rtrim($m[1], "\r"));
        }
        return null;
    }

    /** Un parámetro de un encabezado estructurado, con o sin comillas. */
    private static function parametro($valor, $nombre)
    {
        $patron = '/;\s*' . preg_quote($nombre, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i';
        if (!preg_match($patron, (string) $valor, $m)) {
            return null;
        }
        if (isset($m[2]) && $m[2] !== '') {
            return $m[2];
        }
        return stripslashes($m[1]);
    }
}
