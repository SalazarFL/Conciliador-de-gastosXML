<?php
/**
 * Qué significa escribir algo en el buscador de un listado de comprobantes.
 *
 * Buscaba el término dentro de TODOS los campos con LIKE '%…%', y entre esos
 * campos está la clave: cincuenta dígitos que llevan dentro la fecha, la
 * cédula del emisor, el consecutivo y un código de seguridad. Con eso, buscar
 * la factura 336 —que es lo que manda el botón "Buscarlo entre los
 * comprobantes XML" de la cola de seguimiento— devolvía 124 comprobantes: 124
 * claves que en algún punto de sus cincuenta dígitos dicen "336", y ninguno
 * era la factura 336. La cédula hacía lo mismo con otras 69.
 *
 * La pantalla quedaba diciendo "124 facturas con los filtros seleccionados"
 * cuando la respuesta verdadera era "ninguna": ese comprobante no está
 * cargado, que es justo por lo que el renglón de seguimiento pedía buscarlo.
 * Enseñar 124 resultados que no son hace dudar de si está o no, que es lo
 * único que se venía a averiguar.
 *
 * Así que un término de solo dígitos se busca donde ese número significa algo:
 *
 *   número corto      por coincidencia: quien busca 336 puede acordarse de un
 *                     pedazo del número, y 00336547 es un resultado legítimo
 *   consecutivo       igual, por coincidencia
 *   clave             solo si el término es largo de verdad (11 dígitos o
 *                     más); tres dígitos dentro de una clave no dicen nada
 *   cédula            exacta, nunca por pedazos: 336 no es media cédula
 *
 * Que el documento que se venía a buscar no esté entre las coincidencias es
 * otra pregunta, y la contesta aparte la pantalla (ver
 * app/views/partials/documento-no-esta.php): enseña las que se le parecen y
 * dice, encima de ellas, que el que se buscaba no está cargado.
 *
 * Y un término con letras se busca como texto, con LIKE, que es lo que hace
 * falta para un nombre de proveedor o un nombre de archivo.
 *
 * No se buscan números dentro del nombre del archivo: el nombre se arma con
 * el mismo número que ya se busca en sus dos columnas —así que no alcanza
 * nada nuevo— y lleva la fecha pegada (FE_PROVEEDOR_200826_00008060.xml), que
 * es de donde salían coincidencias que nadie pidió.
 */

require_once __DIR__ . '/NumeroFactura.php';

class BusquedaDocumento
{
    /**
     * A partir de cuántos dígitos un término es lo bastante específico para
     * buscarlo dentro de una clave. Diez es el largo de una cédula jurídica;
     * por debajo, cualquier cosa coincide con cualquier cosa.
     */
    public const DIGITOS_PARA_CLAVE = 11;

    /** Y hasta cuántos cabe en la columna del número corto. */
    public const DIGITOS_DE_UN_NUMERO = NumeroFactura::DIGITOS_XML;

    /**
     * El trozo de WHERE para este término, o '' si no hay nada que filtrar.
     *
     * @param string $termino  Lo que se escribió en el buscador.
     * @param array  $columnas Las que existan de: numero, consecutivo, clave,
     *                         cedula, texto (una o varias: proveedor, nombres
     *                         de archivo). Las que no se pasen no se buscan.
     * @param array  $params   Se le agregan los valores, en orden.
     */
    public static function condicion($termino, array $columnas, array &$params)
    {
        $termino = trim((string) $termino);
        if ($termino === '') {
            return '';
        }

        $partes = self::esNumero($termino)
            ? self::porNumero($termino, $columnas, $params)
            : self::porTexto($termino, $columnas, $params);

        // Un término que no encaja en ninguna columna no puede traer todo el
        // listado: no encuentra nada, y eso es una respuesta.
        return $partes ? '(' . implode(' OR ', $partes) . ')' : '1=0';
    }

    /**
     * ¿Es un número? Se aceptan los separadores con los que la gente copia y
     * pega un consecutivo —espacios, guiones, puntos—, pero una sola letra ya
     * lo convierte en texto.
     */
    public static function esNumero($termino)
    {
        $termino = trim((string) $termino);
        return $termino !== '' && preg_match('/^[0-9][0-9\s.\-]*$/', $termino) === 1;
    }

    /** Solo los dígitos, que es con lo que se compara. */
    public static function digitos($termino)
    {
        return (string) preg_replace('/\D+/', '', (string) $termino);
    }

    private static function porNumero($termino, array $columnas, array &$params)
    {
        $digitos = self::digitos($termino);
        if ($digitos === '') {
            return [];
        }
        $largo = strlen($digitos);
        $like = '%' . $digitos . '%';
        $partes = [];

        // Por coincidencia dentro del número: quien busca puede acordarse solo
        // de un pedazo. Más largo que la columna no cabe, así que no se pide.
        if (!empty($columnas['numero']) && $largo <= self::DIGITOS_DE_UN_NUMERO) {
            $partes[] = $columnas['numero'] . ' LIKE ?';
            $params[] = $like;
        }
        if (!empty($columnas['consecutivo'])) {
            $partes[] = $columnas['consecutivo'] . ' LIKE ?';
            $params[] = $like;
        }
        // La clave solo con un término largo. Es de donde salían los 124.
        if (!empty($columnas['clave']) && $largo >= self::DIGITOS_PARA_CLAVE) {
            $partes[] = $columnas['clave'] . ' LIKE ?';
            $params[] = $like;
        }

        // La cédula, siempre entera. Se guarda con y sin guiones según de
        // dónde venga, igual que en el filtro de proveedor.
        if (!empty($columnas['cedula'])) {
            $partes[] = "REPLACE(REPLACE(" . $columnas['cedula'] . ", '-', ''), ' ', '') = ?";
            $params[] = $digitos;
        }

        return $partes;
    }

    private static function porTexto($termino, array $columnas, array &$params)
    {
        $like = '%' . $termino . '%';
        $partes = [];

        foreach (['numero', 'consecutivo', 'clave', 'cedula'] as $clave) {
            if (!empty($columnas[$clave])) {
                $partes[] = $columnas[$clave] . ' LIKE ?';
                $params[] = $like;
            }
        }
        foreach ((array) ($columnas['texto'] ?? []) as $columna) {
            if ((string) $columna !== '') {
                $partes[] = $columna . ' LIKE ?';
                $params[] = $like;
            }
        }

        return $partes;
    }
}
