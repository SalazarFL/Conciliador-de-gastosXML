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
     * Las claves con las que el contexto viaja en la URL, aparte de los
     * filtros, que van todos con el prefijo 'ctx_f_'.
     */
    private const CLAVES_CONTEXTO = ['ctx', 'ctx_lista', 'ctx_item', 'pp_listado', 'pp_linea'];

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

    /** El checklist de una semana, tal como se estaba viendo. */
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
        // Los mismos filtros del checklist, igual que hace la cola de
        // seguimiento. Sin ellos las flechas recorrían el pago entero —las
        // facturas ya respaldadas incluidas, que ni siquiera tienen botón
        // para llegar acá— y la posición no correspondía a ninguna lista.
        $filtros = self::filtrosDelContexto($get);
        $items = [];
        $idx = 0;

        // Las facturas del pago salen de Facturas ERP: desde que la línea ES
        // la factura del ERP, el pago solo la marca.
        $lineas = $modelo('FacturaErp')->getFacturasPago($listadoId, $filtros);
        foreach ($lineas as $fila) {
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
                (string) ($fila['sucursal'] ?? ''),
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
        // Los filtros vuelven en las dos direcciones, como en la cola de
        // seguimiento: en 'volver', para caer en el checklist tal como se
        // dejó, y en 'params', para que pasar al siguiente con las flechas
        // no rearme una lista distinta a mitad del recorrido.
        return [
            'origen'  => 'pago',
            'titulo'  => (string) $listado['nombre'] . ($semana !== '' ? ' · ' . $semana : ''),
            'volver'  => rtrim($baseUrl, '/') . '/por-pagar?'
                       . http_build_query(array_merge(['listado_id' => $listadoId], $filtros)),
            'idx'     => min($idx, count($items) - 1),
            'total'   => count($lineas),
            'params'  => self::paramsDelContexto('pago', $filtros)
                       . '&ctx_lista=' . $listadoId,
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
        // La cola se rehace en el mismo modo en el que se estaba mirando: el
        // modo viaja entre los filtros del contexto y decide de qué tablas
        // sale la lista, así que recorrerla en el otro daría documentos que
        // no son los que estaban en pantalla.
        $cola = $seguimiento->enModo($filtros['modo'])->cola($filtros, 1, self::TOPE);

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
                (string) ($fila['sucursal'] ?? ''),
                (string) $fila['fecha'],
                (float) $fila['saldo'],
                self::estadoDe($fila),
                // Cada documento se busca entre los comprobantes de su clase:
                // una nota no está en el listado de facturas.
                in_array($fila['origen'], ['nota_credito', 'xml_nota'], true)
                    ? 'notas-xml'
                    : 'facturas'
            );
        }

        if (!$items) {
            return null;
        }

        // Los mismos filtros con los que se armó, para que el enlace de
        // regreso y las flechas hablen de esta cola y no de otra.
        $suyos = array_filter([
            // El modo va primero y siempre: sin él, volver desde la tarjeta
            // aterriza en la cola del sistema aunque se hubiera salido de la
            // del correo.
            'modo' => $filtros['modo'],
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
            'col_monto' => $filtros['col_monto'],
            'col_saldo' => $filtros['col_saldo'],
            'condicion_saldo' => $filtros['condicion_saldo'],
            'q' => $filtros['q'],
            'orden' => $filtros['orden'],
        ], static function ($v) { return $v !== '' && $v !== null; });

        return [
            'origen'  => 'seguimiento',
            'titulo'  => 'Cola de seguimiento' . ($filtros['vista'] !== 'todo'
                ? ' · ' . (Seguimiento::estadosDe($filtros['modo'])[$filtros['vista']]
                           ?? $filtros['vista'])
                : ''),
            'volver'  => rtrim($baseUrl, '/') . '/seguimiento?' . http_build_query($suyos),
            'idx'     => min($idx, count($items) - 1),
            // Si quien responde no dice el total, el de las filas que trajo.
            'total'   => (int) ($cola['total'] ?? count($cola['filas'])),
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
            if (strpos((string) $clave, 'ctx_f_') !== 0) {
                continue;
            }
            // Solo texto: estos filtros van derechos a la consulta del
            // checklist, y un ctx_f_q[]=1 en la URL llegaría hasta un
            // trim() de un arreglo.
            if (is_array($valor) || is_object($valor)) {
                continue;
            }
            $filtros[substr((string) $clave, 6)] = mb_substr((string) $valor, 0, 150, 'UTF-8');
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
     * Todo lo que hace falta para no perder el contexto al hacer algo en la
     * pantalla destino: enviar su barra de filtros, cambiar de página,
     * limpiar.
     *
     * Hace falta porque esas tres cosas se hacen por GET, y en un GET lo que
     * no viaja desaparece. Escribir un criterio a mano y pulsar Buscar
     * borraba la tarjeta del documento que se venía persiguiendo —y con ella
     * la lista por la que se iba— justo cuando más falta hacía: quien filtra
     * a mano lo hace para encontrar ESE documento.
     *
     * Devuelve las claves tal como vinieron, listas para volver a la URL o
     * para dibujarse como campos escondidos del formulario.
     */
    public static function contextoDeLaUrl(array $get)
    {
        $params = [];
        foreach ($get as $clave => $valor) {
            $clave = (string) $clave;
            $suyo = in_array($clave, self::CLAVES_CONTEXTO, true)
                 || strpos($clave, 'ctx_f_') === 0;
            // Los mismos dos recaudos que al leer los filtros: nada de
            // arreglos —terminarían en un trim()— y nada sin tope de largo.
            if (!$suyo || is_array($valor) || is_object($valor)) {
                continue;
            }
            $valor = mb_substr((string) $valor, 0, 150, 'UTF-8');
            if ($valor !== '') {
                $params[$clave] = $valor;
            }
        }
        return $params;
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
     * El documento que se vino a buscar, si el buscador sigue teniendo su
     * término.
     *
     * Devuelve null cuando no se venía buscando nada —lo normal— y también
     * cuando alguien cambió el término a mano: a partir de ahí la lista habla
     * de lo que esa persona escribió y no de este documento, así que decir
     * "no está" sería afirmar algo que no se comprobó.
     */
    public static function documentoBuscado($navDoc, $termino)
    {
        if (!is_array($navDoc) || empty($navDoc['items'])) {
            return null;
        }
        $item = $navDoc['items'][(int) ($navDoc['idx'] ?? 0)] ?? $navDoc['items'][0];
        $busca = trim((string) ($item['busqueda'] ?? ''));
        $termino = trim((string) $termino);

        return ($busca !== '' && $busca === $termino) ? $item : null;
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

    /**
     * Un documento de la lista, con lo que la tarjeta enseña de él.
     *
     * La sucursal puede venir vacía y no es un fallo: la trae el reporte del
     * ERP, así que un comprobante XML mirado por sí solo —la cola en modo
     * Correo— no tiene ninguna. La tarjeta esconde ese renglón cuando pasa.
     */
    private static function item($id, $numero, $busqueda, $proveedor, $sucursal, $fecha, $total, $estado, $destino)
    {
        $ts = $fecha !== '' ? strtotime($fecha) : false;
        return [
            'id'        => $id,
            'numero'    => $numero,
            'busqueda'  => $busqueda,
            'proveedor' => $proveedor,
            'sucursal'  => $sucursal,
            'fecha'     => $ts !== false ? date('d/m/Y', $ts) : '',
            'total'     => round($total, 2),
            'estado'    => $estado,
            'destino'   => $destino,
        ];
    }
}
