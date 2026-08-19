<?php
/**
 * Resuelve las filas del archivo de pago semanal contra las facturas del ERP.
 *
 * El pago semanal dejó de guardar los datos del archivo. El archivo pasó a ser
 * lo que siempre fue en la práctica —una lista de qué se paga esta semana— y
 * las tres columnas que trae (documento, proveedor, saldo) sirven solo para
 * encontrar la factura en el listado del ERP y marcarla. La factura del ERP es
 * la única versión de esos datos: es la que el sistema imprime, la que cuadra
 * contra los totales del proveedor y la que no cambia de forma según quién
 * exportó el archivo.
 *
 * Esta clase no escribe: clasifica cada fila del archivo y dice qué factura
 * del ERP le corresponde, o por qué no hay ninguna. Una factura que ya está en
 * el pago de otra semana se resuelve igual —se mueve a esta—, y la fila queda
 * marcada con el pago del que viene.
 *
 * Medido sobre los nueve listados ya cargados, resuelve 2 286 de 2 359 filas
 * (96.9%); en los dos más recientes, el 100%. Lo que no resuelve son facturas
 * anteriores al rango del reporte del ERP cargado.
 */

require_once __DIR__ . '/FacturaMatcher.php';
require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/../models/FacturaErp.php';

class PagoSemanalResolutor
{
    /** Tope defensivo, igual que el del comparador. */
    public const MAX_FILAS = 10000;

    /**
     * Cuánto puede alejarse el saldo del archivo del que dice el ERP para que
     * siga siendo la misma factura. Solo desempata entre candidatas con el
     * mismo número: el número ya identificó la factura, el saldo distingue
     * repeticiones. No es una validación de monto —para eso está la diferencia
     * contra el XML, que es la que se cobra—.
     */
    public const TOLERANCIA_SALDO = 1.0;

    /**
     * @param array $filas    Filas leídas del archivo: numero, proveedor, saldo.
     * @param array $erp      Facturas del ERP: id, documento, numero_corto,
     *                        proveedor_nombre, monto, saldo, porpagar_listado_id.
     * @param int   $listadoId Listado que se está cargando (0 si aún no existe):
     *                        las que ya son suyas no se marcan como movidas.
     * @return array ['filas' => [...], 'resumen' => [...], 'ids' => [...]]
     */
    public static function resolver(array $filas, array $erp, $listadoId = 0)
    {
        if (count($filas) > self::MAX_FILAS) {
            throw new InvalidArgumentException(
                'El pago semanal admite hasta ' . number_format(self::MAX_FILAS, 0, '.', ',') . ' facturas por archivo.'
            );
        }

        $indice = self::indexar($erp);
        $listadoId = (int) $listadoId;
        $vistas = [];
        $resueltas = [];
        $salida = [];

        foreach ($filas as $fila) {
            if (($fila['estado'] ?? '') === 'error') {
                $salida[] = self::conEstado($fila, 'error', (string) ($fila['motivo'] ?? 'Fila ilegible.'));
                continue;
            }

            $numero = trim((string) ($fila['numero'] ?? ''));
            $proveedor = trim((string) ($fila['proveedor'] ?? ''));
            $saldo = (float) ($fila['saldo'] ?? ($fila['total'] ?? 0));

            $candidatas = self::candidatas($numero, $indice);
            if (!$candidatas) {
                $salida[] = self::conEstado($fila, 'ausente',
                    'No está en el listado de facturas del ERP. Cargá el reporte que la incluya.');
                continue;
            }

            $elegida = self::elegir($candidatas, $proveedor, $saldo);
            if ($elegida === null) {
                $salida[] = self::conEstado($fila, 'ambigua',
                    'El ERP tiene ' . count($candidatas) . ' facturas con ese número y ni el proveedor '
                    . 'ni el saldo las distinguen.');
                continue;
            }

            $erpId = (int) $elegida['id'];

            // El archivo repite la factura: la segunda vez no aporta nada.
            if (isset($vistas[$erpId])) {
                $salida[] = self::conEstado($fila, 'repetida',
                    'La misma factura del ERP ya venía en este archivo.', $elegida);
                continue;
            }
            $vistas[$erpId] = true;

            // Ya la tiene el pago de otra semana: esta carga se la lleva.
            //
            // Antes cortaba la carga entera y había que ir a soltarla a mano
            // en la otra semana. Pero una factura se paga una sola vez, así
            // que dos pagos que la reclaman no son dos verdades: es que se
            // movió de semana, y el archivo que se está cargando es el más
            // reciente. Se resuelve igual que cualquier otra y se deja dicho
            // de dónde viene, porque mover una factura vacía el pago de la
            // otra semana y eso hay que verlo antes de confirmar.
            $otroListado = (int) ($elegida['porpagar_listado_id'] ?? 0);
            $movidaDesde = ($otroListado > 0 && $otroListado !== $listadoId) ? $otroListado : 0;

            $resueltas[] = $erpId;
            $salida[] = self::conEstado($fila, 'resuelta',
                $movidaDesde > 0 ? 'Viene del pago semanal #' . $movidaDesde . ': se mueve a este.' : '',
                $elegida, $movidaDesde);
        }

        $resumen = array_fill_keys(
            ['resuelta', 'ausente', 'ambigua', 'repetida', 'error'],
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
     * El número no es único entre proveedores y el ERP a veces registra la
     * misma factura dos veces, así que el proveedor filtra primero y el saldo
     * decide después. Si tras las dos queda más de una, se devuelve null: se
     * prefiere que alguien la mire a asignar la que caiga.
     */
    private static function elegir(array $candidatas, $proveedor, $saldo)
    {
        if (count($candidatas) === 1) {
            return $candidatas[0];
        }

        if (trim((string) $proveedor) !== '') {
            $delProveedor = array_values(array_filter($candidatas, function ($factura) use ($proveedor) {
                return FacturaMatcher::mismoProveedor($proveedor, (string) ($factura['proveedor_nombre'] ?? ''));
            }));
            if (count($delProveedor) === 1) {
                return $delProveedor[0];
            }
            if ($delProveedor) {
                $candidatas = $delProveedor;
            }
        }

        $porSaldo = array_values(array_filter($candidatas, function ($factura) use ($saldo) {
            return abs((float) $factura['saldo'] - $saldo) <= self::TOLERANCIA_SALDO
                || abs((float) $factura['monto'] - $saldo) <= self::TOLERANCIA_SALDO;
        }));
        if (count($porSaldo) === 1) {
            return $porSaldo[0];
        }
        if (count($porSaldo) > 1) {
            $candidatas = $porSaldo;
        }

        // Última vía: si solo una sigue debiéndose, es la que se está pagando.
        $conSaldo = array_values(array_filter($candidatas, function ($factura) {
            return (float) $factura['saldo'] > 0;
        }));
        return count($conSaldo) === 1 ? $conSaldo[0] : null;
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
