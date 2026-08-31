<?php
/**
 * Cuánto listado traer al entrar: el de un proveedor, o el entero.
 *
 * Estos cuatro listados tienen miles de renglones —8.519 comprobantes, 5.196
 * facturas del ERP— y abrirlos desde el menú los traía todos, o las primeras
 * doscientas filas de todos, sin que nadie los hubiera pedido. Se paga en
 * memoria, en tiempo y sobre todo en la red: la base vive en otra máquina.
 *
 * Así que antes de traer nada se pregunta de quién, y "todos" es una respuesta
 * legítima que hay que dar a propósito. La elección se recuerda por módulo
 * —de eso ya se encarga recordarFiltros()— así que la pregunta sale la
 * primera vez y después de Limpiar, no en cada visita.
 *
 * Lo que NO se pregunta:
 *
 *   - Cuando se llega persiguiendo un documento concreto desde la cola de
 *     seguimiento o desde el pago semanal. Ahí ya se sabe qué se busca, y
 *     poner una pregunta en medio del camino sería estorbar.
 *   - Cuando ya hay un proveedor puesto, venga de la URL o de lo que el
 *     módulo recuerda.
 */

class AlcanceProveedor
{
    /** El parámetro con el que viaja "ya decidí: quiero verlos todos". */
    public const PARAM = 'alcance';

    /** Su único valor con significado. */
    public const TODOS = 'todos';

    /**
     * ¿Hay que preguntar de qué proveedor antes de traer el listado?
     *
     * @param array  $get       Los parámetros de la petición, ya con lo que el
     *                          módulo recuerde (recordarFiltros los repone).
     * @param string $proveedor El proveedor ya resuelto por el controlador.
     */
    public static function hayQuePreguntar(array $get, $proveedor)
    {
        // Se viene persiguiendo un documento: la pregunta estorba.
        if (self::deCamino($get)) {
            return false;
        }
        // Ya hay proveedor elegido, sea de la URL o de lo que se recuerda.
        if (trim((string) $proveedor) !== '') {
            return false;
        }
        return !self::pidioTodos($get);
    }

    /** ¿Se pidió el listado entero, a propósito? */
    public static function pidioTodos(array $get)
    {
        $valor = $get[self::PARAM] ?? '';
        if (is_array($valor)) {
            return false;
        }
        return strtolower(trim((string) $valor)) === self::TODOS;
    }

    /**
     * ¿Se está de paso, persiguiendo el electrónico de un documento?
     *
     * Son los mismos parámetros con los que NavegacionDocumentos arma la
     * tarjeta, incluidos los nombres viejos del pago semanal.
     */
    private static function deCamino(array $get)
    {
        foreach (['ctx', 'ctx_item', 'pp_listado', 'pp_linea'] as $clave) {
            $valor = $get[$clave] ?? '';
            if (!is_array($valor) && trim((string) $valor) !== '') {
                return true;
            }
        }
        return false;
    }
}
