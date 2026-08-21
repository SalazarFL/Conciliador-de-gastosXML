<?php
/**
 * A dónde vuelve el botón "Volver".
 *
 * El detalle de un documento no tiene una pantalla madre. Se llega a él desde
 * el listado de comprobantes, desde el checklist del pago semanal, desde una
 * devolución y desde las notas de crédito. Un enlace fijo a "Volver a Facturas"
 * acertaba en uno de esos cuatro casos y en los otros tres sacaba a la persona
 * del sitio donde estaba trabajando —con sus filtros puestos y su lugar en la
 * lista— para dejarla en un listado que no había pedido.
 *
 * El origen lo dice el propio navegador en la cabecera Referer, así que no hace
 * falta que cada enlace lo cargue en la URL. Se comprueba antes de usarlo:
 *
 *   · mismo servidor y dentro de la aplicación, porque un Referer lo escribe
 *     quien quiera y esto termina en un href;
 *   · que no sea la misma pantalla, o "Volver" se quedaría dando vueltas;
 *   · con su cadena de consulta, que es donde viven los filtros y la página en
 *     que se estaba. Volver perdiéndolos es casi no volver.
 *
 * Sin Referer utilizable —se entró por un marcador, el navegador no lo manda,
 * se llegó desde fuera— queda el destino por omisión que fije quien llame.
 */

class Retorno
{
    /**
     * Prefijo de ruta => cómo se nombra esa pantalla después de "Volver".
     *
     * El fragmento incluye la preposición porque no todas la llevan igual: "a
     * Facturas" pero "al pago semanal". Armarlo con un "a " fijo delante daba
     * "Volver a el pago semanal".
     */
    private const PANTALLAS = [
        '/por-pagar'     => 'al pago semanal',
        '/facturas-erp'  => 'a Facturas',
        '/notas-credito' => 'a Notas de crédito',
        '/notas-xml'     => 'a Notas de crédito XML',
        '/facturas'      => 'a Facturas XML',
        '/devoluciones'  => 'a Devoluciones',
        '/seguimiento'   => 'a Seguimiento',
        '/correo'        => 'a Correo',
        '/avisos'        => 'a Avisos',
    ];

    /**
     * @param array  $server  $_SERVER.
     * @param string $baseUrl Raíz de la aplicación (APP_URL).
     * @param string $defecto Ruta a la que volver si no se sabe de dónde se vino.
     * @return array ['url' => string, 'titulo' => string]
     */
    public static function anterior(array $server, $baseUrl, $defecto)
    {
        $porOmision = rtrim((string) $baseUrl, '/') . '/' . ltrim((string) $defecto, '/');
        $destino = self::refererInterno($server, $baseUrl);

        return [
            'url' => $destino ?? $porOmision,
            'titulo' => self::titulo($destino ?? $porOmision, $baseUrl),
        ];
    }

    /** El Referer, si se puede confiar en él; null si no. */
    private static function refererInterno(array $server, $baseUrl)
    {
        $referer = trim((string) ($server['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return null;
        }

        $partes = @parse_url($referer);
        if (!is_array($partes)) {
            return null;
        }

        // Mismo servidor. Un Referer de otro host es, en el mejor de los casos,
        // alguien que llegó desde fuera; en el peor, un enlace preparado.
        $hostActual = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
        $hostReferer = strtolower(trim((string) ($partes['host'] ?? '')));
        if ($hostReferer !== '' && $hostActual !== '' && $hostReferer !== $hostActual) {
            return null;
        }

        $ruta = (string) ($partes['path'] ?? '');
        // "//otro.com/x" es una ruta para parse_url y un salto de servidor para
        // el navegador: se descarta antes de que llegue a un href.
        if ($ruta === '' || strncmp($ruta, '//', 2) === 0) {
            return null;
        }

        // Dentro de la aplicación y no en otra cosa servida por el mismo host.
        $base = rtrim((string) (@parse_url((string) $baseUrl, PHP_URL_PATH) ?: ''), '/');
        if ($base !== '' && $ruta !== $base && strncmp($ruta, $base . '/', strlen($base) + 1) !== 0) {
            return null;
        }

        // Volver a donde ya se está no es volver.
        $actual = (string) ($server['REQUEST_URI'] ?? '');
        $rutaActual = (string) (@parse_url($actual, PHP_URL_PATH) ?: '');
        if ($rutaActual !== '' && rtrim($ruta, '/') === rtrim($rutaActual, '/')) {
            return null;
        }

        $consulta = trim((string) ($partes['query'] ?? ''));
        return $ruta . ($consulta !== '' ? '?' . $consulta : '');
    }

    /** "Volver a Facturas" para el tooltip: el botón dice solo "Volver". */
    private static function titulo($destino, $baseUrl)
    {
        $base = rtrim((string) (@parse_url((string) $baseUrl, PHP_URL_PATH) ?: ''), '/');
        $ruta = (string) (@parse_url((string) $destino, PHP_URL_PATH) ?: '');
        if ($base !== '' && strncmp($ruta, $base, strlen($base)) === 0) {
            $ruta = substr($ruta, strlen($base));
        }
        $ruta = '/' . ltrim($ruta, '/');

        // El más largo primero: /notas-credito no puede resolverse por /notas-xml
        // ni /facturas-erp por /facturas.
        $prefijos = self::PANTALLAS;
        uksort($prefijos, function ($a, $b) { return strlen($b) <=> strlen($a); });

        foreach ($prefijos as $prefijo => $nombre) {
            if ($ruta === $prefijo || strncmp($ruta, $prefijo . '/', strlen($prefijo) + 1) === 0) {
                return 'Volver ' . $nombre;
            }
        }
        return 'Volver a la pantalla anterior';
    }
}
