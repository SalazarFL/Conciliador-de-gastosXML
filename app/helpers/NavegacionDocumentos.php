<?php
/**
 * La lista que recorre la tarjeta de "buscar el electrónico de un documento".
 *
 * Buscar el XML de un documento es ir a otra pantalla —el correo, o el listado
 * de comprobantes ya cargados— y volver. Haciendo eso cincuenta veces seguidas
 * lo que se pierde no es tiempo de clic: es saber cuál se estaba buscando y
 * cuántos quedan. Por eso la pantalla destino muestra una tarjeta con los
 * datos del documento y flechas para pasar al siguiente sin regresar.
 *
 * La lista de la que se pasa depende de DÓNDE se venía:
 *
 *   pago         el checklist de la semana, en su orden
 *   seguimiento  la cola de trabajo, con los filtros tal como quedaron
 *
 * Los filtros viajan en la URL y la cola se rehace acá con ellos: recorrer una
 * lista distinta de la que se estaba viendo sería peor que no tener flechas.
 *
 * Esta clase no dibuja nada. El marcado está en
 * app/views/partials/tarjeta-documento.php y el comportamiento en app.js.
 */

require_once __DIR__ . '/FacturaMatcher.php';

class NavegacionDocumentos
{
    /**
     * Tope de documentos que se llevan a la tarjeta.
     *
     * La lista entera viaja al navegador como JSON. Con una cola de miles de
     * renglones eso es medio megabyte en cada carga de la pantalla destino
     * para poder pulsar "siguiente" doscientas veces. Quien llegue al tope
     * vuelve al módulo y sigue desde ahí.
     */
    public const TOPE = 300;

    /** Los orígenes que sabe reconstruir. */
    public const ORIGENES = ['pago', 'seguimiento'];

    /**
     * Lee la petición y arma el contexto, o null si no se venía de ningún
     * sitio (que es lo normal: la pantalla destino se abre sola casi siempre).
     *
     * @param array      $get    Parámetros de la petición.
     * @param callable   $modelo function(string $nombre): object — cargador de
     *                           modelos del controlador que pregunta.
     * @param string     $baseUrl Para armar el enlace de regreso.
     */
    public static function desde(array $get, callable $modelo, $baseUrl)
    {
        $origen = self::origenPedido($get);
        if ($origen === '') {
            return null;
        }

        if ($origen === 'pago') {
            return self::desdePago($get, $modelo, $baseUrl);
        }
        return self::desdeSeguimiento($get, $modelo, $baseUrl);
    }

    /**
     * Qué origen pide la URL.
     *
     * 'pp_listado' se sigue aceptando: era el nombre que tenía cuando el único
     * origen posible era el pago semanal, y anda en enlaces ya guardados.
     */
    private static function origenPedido(array $get)
    {
        $ctx = strtolower(trim((string) ($get['ctx'] ?? '')));
        if (in_array($ctx, self::ORIGENES, true)) {
            return $ctx;
        }
        return !empty($get['pp_listado']) ? 'pago' : '';
    }

    /** El checklist de una semana, en el orden en que se ve. */
    private static function desdePago(array $get, callable $modelo, $baseUrl)
    {
        $listadoId = (int) ($get['ctx_lista'] ?? $get['pp_listado'] ?? 0);
        if ($listadoId <= 0) {
            return null;
        }

        $listado = $modelo('PorPagar')->getListado($listadoId);
        if ($listado === null) {
            return null;
        }

        $actual = (string) ($get['ctx_item'] ?? $get['pp_linea'] ?? '');
        $items = [];
        $idx = 0;

        // Las facturas del pago salen de Facturas ERP: desde que la línea ES
        // la factura del ERP, el pago solo la marca.
        foreach ($modelo('FacturaErp')->getFacturasPago($listadoId) as $fila) {
            if ((string) (int) $fila['id'] === $actual) {
                $idx = count($items);
            }
            if (count($items) >= self::TOPE) {
                break;
            }
            $items[] = self::item(
                (string) (int) $fila['id'],
                (string) $fila['documento'],
                FacturaMatcher::terminoBusquedaCorreo((string) $fila['documento']),
                (string) $fila['proveedor_nombre'],
                (string) $fila['fecha_emision'],
                // El saldo del pago, que es el que enseña el checklist.
                (float) $fila['saldo_pago'],
                (string) $fila['estado'],
                'facturas'
            );
        }

        if (!$items) {
            return null;
        }

        $semana = trim((string) ($listado['semana_nombre'] ?? ''));
        return [
            'origen'  => 'pago',
            'titulo'  => (string) $listado['nombre'] . ($semana !== '' ? ' · ' . $semana : ''),
            'volver'  => rtrim($baseUrl, '/') . '/por-pagar?listado_id=' . $listadoId,
            'idx'     => min($idx, count($items) - 1),
            'params'  => http_build_query(['ctx' => 'pago', 'ctx_lista' => $listadoId]),
            'items'   => $items,
        ];
    }

    /**
     * La cola de seguimiento, con los filtros con los que se estaba mirando.
     *
     * El identificador de un renglón es 'origen:id' —'factura:12',
     * 'nota_credito:340'— porque la cola une dos tablas y los id se repiten
     * entre ellas: solo con el número, "siguiente" saltaría al documento
     * equivocado en cuanto una nota y una factura compartieran id.
     */
    private static function desdeSeguimiento(array $get, callable $modelo, $baseUrl)
    {
        require_once __DIR__ . '/../models/Seguimiento.php';

        $seguimiento = $modelo('Seguimiento');
        $sociedadId = 0;
        try {
            $sociedad = $modelo('Sociedad')->getActiva();
            $sociedadId = $sociedad ? (int) $sociedad['id'] : 0;
        } catch (Throwable $e) {
            $sociedadId = 0;
        }

        $filtros = Seguimiento::filtrosDesde(self::filtrosDelContexto($get), $sociedadId);
        $cola = $seguimiento->cola($filtros, 1, self::TOPE);

        $actual = (string) ($get['ctx_item'] ?? '');
        $items = [];
        $idx = 0;

        foreach ($cola['filas'] as $fila) {
            $clave = (string) $fila['origen'] . ':' . (int) $fila['referencia_id'];
            if ($clave === $actual) {
                $idx = count($items);
            }
            $items[] = self::item(
                $clave,
                (string) $fila['documento'],
                self::busquedaDe($fila),
                (string) $fila['proveedor'],
                (string) $fila['fecha'],
                (float) $fila['saldo'],
                self::estadoDe($fila),
                // Cada documento se busca entre los comprobantes de su clase:
                // una nota no está en el listado de facturas.
                $fila['origen'] === 'nota_credito' ? 'notas-xml' : 'facturas'
            );
        }

        if (!$items) {
            return null;
        }

        // Los mismos filtros con los que se armó, para que el enlace de
        // regreso y las flechas hablen de esta cola y no de otra.
        $suyos = array_filter([
            'vista' => $filtros['vista'],
            'origen' => $filtros['origen'],
            'tarea' => $filtros['tarea'],
            'marca' => $filtros['marca'],
            'clase' => $filtros['clase'],
            'responsable' => $filtros['responsable'],
            'proveedor' => $filtros['proveedor'],
            'sucursal' => $filtros['sucursal'],
            'contexto_id' => $filtros['contexto_id'] ?: '',
            'desde' => $filtros['desde'],
            'hasta' => $filtros['hasta'],
            'condicion_saldo' => $filtros['condicion_saldo'],
            'q' => $filtros['q'],
            'orden' => $filtros['orden'],
        ], static function ($v) { return $v !== '' && $v !== null; });

        return [
            'origen'  => 'seguimiento',
            'titulo'  => 'Cola de seguimiento' . ($filtros['vista'] !== 'todo'
                ? ' · ' . (Seguimiento::ESTADOS[$filtros['vista']] ?? $filtros['vista'])
                : ''),
            'volver'  => rtrim($baseUrl, '/') . '/seguimiento?' . http_build_query($suyos),
            'idx'     => min($idx, count($items) - 1),
            'params'  => self::paramsDelContexto('seguimiento', $suyos),
            'items'   => $items,
        ];
    }

    /**
     * Los filtros de la cola, sin el prefijo con el que viajan.
     *
     * Viajan como ctx_f_vista, ctx_f_q, ctx_f_proveedor… y no con su nombre
     * propio porque la pantalla a la que llegan tiene filtros suyos que se
     * llaman igual: el 'q' de la cola de seguimiento aterrizaría en el
     * buscador del listado de facturas, y el listado además lo guardaría como
     * su último filtro. El prefijo los mantiene aparte.
     */
    private static function filtrosDelContexto(array $get)
    {
        $filtros = [];
        foreach ($get as $clave => $valor) {
            if (strpos((string) $clave, 'ctx_f_') === 0) {
                $filtros[substr((string) $clave, 6)] = $valor;
            }
        }
        return $filtros;
    }

    /** El mismo juego de filtros, listo para viajar en una URL. */
    public static function paramsDelContexto($origen, array $filtros)
    {
        $params = ['ctx' => $origen];
        foreach ($filtros as $clave => $valor) {
            if ($valor !== '' && $valor !== null) {
                $params['ctx_f_' . $clave] = $valor;
            }
        }
        return http_build_query($params);
    }

    /**
     * Con qué se busca el documento.
     *
     * En una factura, su propio número. En una nota de crédito NO: el número
     * largo del reporte es el consecutivo de la factura que la nota corrige
     * (ver ClaseNotaCredito), así que buscarlo trae siempre el documento
     * equivocado. Lo que identifica a la nota es su consecutivo de proveedor;
     * cuando el reporte no lo trae —lo corriente— no hay número que buscar y
     * se cae al nombre del proveedor.
     */
    public static function busquedaDe(array $fila)
    {
        if (($fila['origen'] ?? '') !== 'nota_credito') {
            return FacturaMatcher::terminoBusquedaCorreo((string) $fila['documento']);
        }

        $numeroNota = trim((string) ($fila['nc_proveedor'] ?? ''));
        if ($numeroNota !== '') {
            return FacturaMatcher::terminoBusquedaCorreo($numeroNota);
        }

        $tokens = array_slice(FacturaMatcher::tokenizarProveedor($fila['proveedor'] ?? ''), 0, 2);
        return implode(' ', $tokens);
    }

    /**
     * El color del punto de la tarjeta, en el vocabulario del respaldo que ya
     * usaba la del pago semanal: respaldada / con diferencia / sin respaldo.
     */
    private static function estadoDe(array $fila)
    {
        $tarea = (string) ($fila['tarea'] ?? '');
        if ($tarea === 'diferencia') {
            return 'con_diferencia';
        }
        return $tarea === 'completo' ? 'respaldada' : 'sin_respaldo';
    }

    private static function item($id, $numero, $busqueda, $proveedor, $fecha, $total, $estado, $destino)
    {
        $ts = $fecha !== '' ? strtotime($fecha) : false;
        return [
            'id'        => $id,
            'numero'    => $numero,
            'busqueda'  => $busqueda,
            'proveedor' => $proveedor,
            'fecha'     => $ts !== false ? date('d/m/Y', $ts) : '',
            'total'     => round($total, 2),
            'estado'    => $estado,
            'destino'   => $destino,
        ];
    }
}
