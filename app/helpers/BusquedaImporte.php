<?php
/**
 * Buscar un documento por su importe.
 *
 * Se escribe el monto que se tiene a mano y salen los documentos cuyo importe
 * coincide. Nada de "desde" y "hasta": quien busca un pago tiene el número
 * delante —en el estado de cuenta, en el correo del proveedor, en el papel—
 * y lo que quiere es encontrar ESE documento, no acotar un tramo.
 *
 * La coincidencia es por dentro del número, no exacta, porque el importe se
 * recuerda a medias tan a menudo como entero: quien escribe 127725 encuentra
 * ₡127,725.56 sin tener que acordarse de los céntimos. El precio de eso es que
 * escribir 1000 también trae 21.000,50; es el mismo trato que ya hacían los
 * buscadores de columna de Seguimiento y de Notas de crédito, y por eso se
 * comportan todos igual.
 *
 * Lo dibuja app/views/partials/filtro-importe.php.
 */

class BusquedaImporte
{
    /**
     * El número que hay dentro de lo que se escribió, o '' si no hay ninguno.
     *
     * Se aceptan el símbolo de moneda, los espacios y las comas de millar
     * porque la gente copia y pega los importes de la propia pantalla, donde
     * salen escritos así: ₡370,639,934.06. El punto es el decimal, igual que
     * en toda la aplicación.
     */
    public static function numero($valor)
    {
        if (is_array($valor) || is_object($valor)) {
            return '';
        }
        $limpio = preg_replace('/[₡$\s,]/u', '', (string) $valor);

        // Lo que queda tiene que poder ser parte de un importe: dígitos, un
        // punto decimal y, a lo sumo, un signo delante. Cualquier otra cosa
        // —una letra, un comodín de SQL— no filtra: mejor traer de más que
        // buscar dentro de la columna equivocada.
        return preg_match('/^-?[0-9]*\.?[0-9]*$/', $limpio) && trim($limpio, '-.') !== ''
            ? $limpio
            : '';
    }

    /** ¿Se escribió algo con lo que buscar? */
    public static function hay($valor)
    {
        return self::numero($valor) !== '';
    }

    /**
     * La condición SQL, con su parámetro.
     *
     * Devuelve '' cuando no hay nada que buscar, para que quien la usa pueda
     * meterla en su lista de condiciones sin preguntar antes.
     *
     * Se compara el número convertido a texto y no con una resta contra un
     * rango: es lo que permite encontrar ₡127,725.56 escribiendo 127725, que
     * es como se busca de verdad.
     *
     * @param string $columna Ya escrita como va en la consulta ('e.monto',
     *                        'COALESCE(e.saldo_pago, e.saldo)'…).
     */
    public static function condicion($columna, $valor, array &$params)
    {
        $numero = self::numero($valor);
        if ($numero === '') {
            return '';
        }

        $params[] = '%' . $numero . '%';
        return "CAST({$columna} AS CHAR) LIKE ?";
    }
}
