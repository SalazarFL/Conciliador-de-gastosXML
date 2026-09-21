<?php
/**
 * Resuelve las filas del archivo de pago contra las facturas del ERP.
 *
 * El pago dejó de guardar los datos del archivo. El archivo pasó a ser
 * lo que siempre fue en la práctica —una lista de qué se paga este pago— y lo
 * que trae (código de proveedor, documento, monto) sirve solo para encontrar la
 * factura en el listado del ERP y marcarla. La factura del ERP es la única
 * versión de esos datos: es la que el sistema imprime, la que cuadra contra los
 * totales del proveedor y la que no cambia de forma según quién exportó el
 * archivo.
 *
 * ── Qué identifica una factura acá ──
 *
 * El número y el CÓDIGO del proveedor, en ese orden. El código es el que el ERP
 * guarda en cada factura (`facturas_erp.proveedor_codigo`) y el mismo que el
 * reporte de pago imprime a la par del nombre del grupo, así que compararlos es
 * una igualdad —o coincide o no— y no un parecido entre dos formas de escribir
 * un nombre.
 *
 * El monto NO identifica. Se usó como desempate mientras el código no se leía:
 * se comparaba el del archivo contra el del ERP con una tolerancia de un colón.
 * Nunca probó nada —dos facturas del mismo proveedor pueden valer lo mismo— y
 * lo que sí hacía era resolver por monto casos que el código resuelve sin
 * dudar. Lo que el monto sí decide es otra cosa, y pasa antes de llegar acá:
 * una fila del reporte SIN monto es una factura que se lista pero no se paga, y
 * el lector la marca 'sin_monto' para que no entre.
 *
 * Esta clase no escribe: clasifica cada fila del archivo y dice qué factura
 * del ERP le corresponde, o por qué no hay ninguna. Una factura que ya está en
 * el pago de otro pago se resuelve igual —se mueve a esta—, y la fila queda
 * marcada con el pago del que viene.
 *
 * Medido sobre los nueve listados ya cargados, resuelve 2 286 de 2 359 filas
 * (96.9%); en los dos más recientes, el 100%. Lo que no resuelve son facturas
 * anteriores al rango del reporte del ERP cargado.
 */

require_once __DIR__ . '/FacturaMatcher.php';
require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/../models/FacturaErp.php';

class PagoResolutor
{
    /** Tope defensivo, igual que el del comparador. */
    public const MAX_FILAS = 10000;

    /**
     * @param array $filas    Filas leídas del archivo: numero, proveedor,
     *                        codigo, saldo.
     * @param array $erp      Facturas del ERP: id, documento, numero_corto,
     *                        proveedor_codigo, proveedor_nombre, monto, saldo,
     *                        pago_id.
     * @param int   $pagoId Listado que se está cargando (0 si aún no existe):
     *                        las que ya son suyas no se marcan como movidas.
     * @return array ['filas' => [...], 'resumen' => [...], 'ids' => [...]]
     */
    public static function resolver(array $filas, array $erp, $pagoId = 0)
    {
        if (count($filas) > self::MAX_FILAS) {
            throw new InvalidArgumentException(
                'El pago admite hasta ' . number_format(self::MAX_FILAS, 0, '.', ',') . ' facturas por archivo.'
            );
        }

        $indice = self::indexar($erp);
        $pagoId = (int) $pagoId;
        $vistas = [];
        $resueltas = [];
        $salida = [];

        foreach ($filas as $fila) {
            $estadoLeido = (string) ($fila['estado'] ?? '');
            if ($estadoLeido === 'error') {
                $salida[] = self::conEstado($fila, 'error', (string) ($fila['motivo'] ?? 'Fila ilegible.'));
                continue;
            }

            // El reporte la lista pero con el monto en blanco. No es una fila
            // rota ni una factura que falte: es una que no se paga en este pago
            // —el subtotal del grupo la deja fuera—. Se conserva para poder
            // contarla antes de confirmar, que es lo único que hay que hacer
            // con ella.
            if ($estadoLeido === 'sin_monto') {
                $salida[] = self::conEstado($fila, 'sin_monto',
                    'El reporte la lista sin monto: no se paga en este pago.');
                continue;
            }

            $numero = trim((string) ($fila['numero'] ?? ''));
            $proveedor = trim((string) ($fila['proveedor'] ?? ''));
            $codigo = trim((string) ($fila['codigo'] ?? ''));

            $candidatas = self::candidatas($numero, $indice);
            if (!$candidatas) {
                $salida[] = self::conEstado($fila, 'ausente',
                    'No está en el listado de facturas del sistema. Cargá el reporte que la incluya.');
                continue;
            }

            $eleccion = self::elegir($candidatas, $codigo, $proveedor);
            if ($eleccion['factura'] === null) {
                $salida[] = self::conEstado($fila, $eleccion['estado'], $eleccion['motivo']);
                continue;
            }

            $elegida = $eleccion['factura'];
            $erpId = (int) $elegida['id'];

            // El archivo repite la factura: la segunda vez no aporta nada.
            if (isset($vistas[$erpId])) {
                $salida[] = self::conEstado($fila, 'repetida',
                    'La misma factura del sistema ya venía en este archivo.', $elegida);
                continue;
            }
            $vistas[$erpId] = true;

            // Ya la tiene el pago de otro pago: esta carga se la lleva.
            //
            // Antes cortaba la carga entera y había que ir a soltarla a mano
            // en la otro pago. Pero una factura se paga una sola vez, así
            // que dos pagos que la reclaman no son dos verdades: es que se
            // movió de pago, y el archivo que se está cargando es el más
            // reciente. Se resuelve igual que cualquier otra y se deja dicho
            // de dónde viene, porque mover una factura vacía el pago de la
            // otro pago y eso hay que verlo antes de confirmar.
            $otroListado = (int) ($elegida['pago_id'] ?? 0);
            $movidaDesde = ($otroListado > 0 && $otroListado !== $pagoId) ? $otroListado : 0;

            $resueltas[] = $erpId;
            $salida[] = self::conEstado($fila, 'resuelta',
                $movidaDesde > 0 ? 'Viene del pago #' . $movidaDesde . ': se mueve a este.' : '',
                $elegida, $movidaDesde);
        }

        $resumen = array_fill_keys(
            ['resuelta', 'ausente', 'ambigua', 'repetida', 'sin_monto', 'error'],
            0
        );
        // 'reasignada' no es un estado: es cuántas de las resueltas llegan
        // quitándoselas a otro pago. No decide si la carga puede hacerse
        // —esas entran igual—, se cuenta para poder avisarlo.
        $resumen['reasignada'] = 0;
        foreach ($salida as $linea) {
            $estado = (string) $linea['estado'];
            if (isset($resumen[$estado])) {
                $resumen[$estado]++;
            }
            if (!empty($linea['movida_desde'])) {
                $resumen['reasignada']++;
            }
        }

        return ['filas' => $salida, 'resumen' => $resumen, 'ids' => $resueltas];
    }

    /**
     * Índice de las facturas del ERP por las dos formas en que el archivo de
     * pago escribe el documento.
     *
     * El reparto de llaves es el mismo que ya usaba faltantesEnErp: el
     * consecutivo electrónico de veinte dígitos cruza contra `documento`, y
     * todo lo demás —números internos del ERP, con o sin relleno de ceros—
     * contra `numero_corto` reducido a ocho. La llave corta se calcula desde
     * el documento cuando la columna viene vacía, que es uno de cada cuatro
     * renglones.
     */
    private static function indexar(array $erp)
    {
        $indice = ['consecutivo' => [], 'corto' => []];
        foreach ($erp as $factura) {
            $documento = trim((string) ($factura['documento'] ?? ''));
            if (preg_match('/^\d{20}$/', $documento) && ltrim($documento, '0') !== '') {
                $indice['consecutivo'][$documento][] = $factura;
            }

            $corto = trim((string) ($factura['numero_corto'] ?? ''));
            if ($corto === '') {
                $corto = $documento;
            }
            if ($corto !== '' && ltrim($corto, '0') !== '') {
                $indice['corto'][(string) NumeroFactura::xmlOchoDigitos($corto)][] = $factura;
            }
        }
        return $indice;
    }

    private static function candidatas($numero, array $indice)
    {
        $llaves = FacturaErp::llavesDeNumero($numero);
        if ($llaves['consecutivo'] !== '' && isset($indice['consecutivo'][$llaves['consecutivo']])) {
            return $indice['consecutivo'][$llaves['consecutivo']];
        }
        if ($llaves['corto'] !== '' && isset($indice['corto'][(string) $llaves['corto']])) {
            return $indice['corto'][(string) $llaves['corto']];
        }
        return [];
    }

    /**
     * Cuál de las candidatas es, si se puede saber.
     *
     * El número no es único entre proveedores —lo arma cada emisor empezando en
     * 1— así que hace falta saber de quién es, y para eso está el código. Con él
     * la pregunta se contesta o no se contesta, sin grados: de las facturas que
     * tienen ese número, las del código son las candidatas y las demás no lo
     * son.
     *
     * Que NINGUNA sea del código no es un empate: es que ese número, en el
     * sistema, le pertenece a otro proveedor. Se devuelve 'ausente' nombrando
     * al dueño, porque lo que hay que arreglar está en el archivo o en el
     * reporte del ERP, no acá.
     *
     * Que queden VARIAS tampoco se desempata. El ERP admite el mismo documento
     * repetido dentro de un proveedor cuando cambia la fecha —lo reporta como
     * incidencia, no lo bloquea— y ahí ya no queda nada que distinga a una de
     * otra: se prefiere que alguien la mire a asignar la que caiga, porque una
     * asignación errónea paga lo que no se debe.
     *
     * @return array ['factura' => ?array, 'estado' => string, 'motivo' => string]
     */
    private static function elegir(array $candidatas, $codigo, $proveedor)
    {
        if ($codigo !== '') {
            $delCodigo = array_values(array_filter($candidatas, function ($factura) use ($codigo) {
                return trim((string) ($factura['proveedor_codigo'] ?? '')) === $codigo;
            }));

            if (!$delCodigo) {
                return self::sinElegir('ausente',
                    'Ese número está en el sistema, pero a nombre de ' . self::nombrarDuenos($candidatas)
                    . ', no del código ' . $codigo . ' que trae el archivo.');
            }
            if (count($delCodigo) === 1) {
                return ['factura' => $delCodigo[0], 'estado' => 'resuelta', 'motivo' => ''];
            }
            return self::sinElegir('ambigua',
                'El código ' . $codigo . ' tiene ' . count($delCodigo)
                . ' facturas con ese número en el sistema; nada más las distingue.');
        }

        // Sin código en el archivo queda el nombre, que se compara por parecido
        // porque el ERP lo imprime cortado y a su manera. Es el camino de los
        // archivos que no son el reporte agrupado: los que alguien arma a mano
        // en una tabla, y los de antes de que el código se leyera.
        if (count($candidatas) === 1) {
            return ['factura' => $candidatas[0], 'estado' => 'resuelta', 'motivo' => ''];
        }

        if ($proveedor !== '') {
            $delProveedor = array_values(array_filter($candidatas, function ($factura) use ($proveedor) {
                return FacturaMatcher::mismoProveedor($proveedor, (string) ($factura['proveedor_nombre'] ?? ''));
            }));
            if (count($delProveedor) === 1) {
                return ['factura' => $delProveedor[0], 'estado' => 'resuelta', 'motivo' => ''];
            }
        }

        return self::sinElegir('ambigua',
            'El sistema tiene ' . count($candidatas) . ' facturas con ese número y el archivo no trae '
            . 'el código del proveedor para distinguirlas.');
    }

    private static function sinElegir($estado, $motivo)
    {
        return ['factura' => null, 'estado' => $estado, 'motivo' => $motivo];
    }

    /**
     * De quién es el número que el archivo reclama para otro código.
     *
     * Se nombran con código y nombre porque el mensaje se lee para ir a buscar
     * el dato equivocado, y el código es lo que se busca en el ERP.
     */
    private static function nombrarDuenos(array $candidatas)
    {
        $duenos = [];
        foreach ($candidatas as $factura) {
            $etiqueta = trim(
                trim((string) ($factura['proveedor_codigo'] ?? '')) . ' '
                . trim((string) ($factura['proveedor_nombre'] ?? ''))
            );
            if ($etiqueta !== '') {
                $duenos[$etiqueta] = true;
            }
        }
        if (!$duenos) {
            return 'otro proveedor';
        }
        $nombres = array_keys($duenos);
        return implode(' / ', array_slice($nombres, 0, 3)) . (count($nombres) > 3 ? ' …' : '');
    }

    private static function conEstado(array $fila, $estado, $motivo, ?array $erp = null, $movidaDesde = 0)
    {
        return [
            'estado' => $estado,
            'motivo' => $motivo,
            // El pago del que se la quita esta carga, si se la quita a alguno.
            'movida_desde' => (int) $movidaDesde > 0 ? (int) $movidaDesde : null,
            'numero' => trim((string) ($fila['numero'] ?? '')),
            'proveedor' => trim((string) ($fila['proveedor'] ?? '')),
            'saldo' => round((float) ($fila['saldo'] ?? ($fila['total'] ?? 0)), 2),
            'factura_erp_id' => $erp ? (int) $erp['id'] : null,
            'erp' => $erp ? [
                'documento' => (string) $erp['documento'],
                'proveedor' => (string) ($erp['proveedor_nombre'] ?? ''),
                'fecha' => (string) ($erp['fecha_emision'] ?? ''),
                'monto' => round((float) $erp['monto'], 2),
                'saldo' => round((float) $erp['saldo'], 2),
            ] : null,
        ];
    }
}
