<?php
/**
 * Cruce conservador entre las filas del reporte y los XML de tipo NC.
 */
require_once __DIR__ . '/FacturaMatcher.php';
require_once __DIR__ . '/NumeroFactura.php';

class NotasCreditoVerificador
{
    public static function verificarListado($listadoId, $modelo, $origen = 'automatico')
    {
        @set_time_limit(120);

        $listado = $modelo->getListado((int) $listadoId);
        if ($listado === null) {
            throw new Exception('El listado de notas de crédito no existe.');
        }

        $lineas = $modelo->getLineasParaMatching((int) $listadoId);
        $facturas = $modelo->getFacturasNcSociedad((string) $listado['sociedad_cedula']);
        $indice = self::indexarFacturas($facturas);
        $usadas = [];
        $pendientes = [];
        $historial = [];
        $manualesNoExactas = [];
        $stats = ['coincide' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        $verificacionId = null;
        $cantidadCambios = 0;

        $transaccionPropia = method_exists($modelo, 'inTransaction')
            && method_exists($modelo, 'begin')
            && !$modelo->inTransaction();
        if ($transaccionPropia) {
            $modelo->begin();
        }

        try {
            if (method_exists($modelo, 'iniciarVerificacion')) {
                $verificacionId = $modelo->iniciarVerificacion((int) $listadoId, (string) $origen);
            }
            if (method_exists($modelo, 'limpiarMatchesAutomaticos')) {
                $modelo->limpiarMatchesAutomaticos((int) $listadoId);
            }

            // Los vínculos manuales tienen prioridad únicamente si conservan el monto exacto.
            foreach ($lineas as $linea) {
                if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                    if (array_key_exists('xml_total', $linea)
                        && $linea['xml_total'] !== null
                        && !self::montosCoinciden($linea['monto'], $linea['xml_total'])) {
                        $manualesNoExactas[(int) $linea['id']] = true;
                        continue;
                    }
                    $usadas[(int) $linea['factura_xml_id']] = true;
                    $estado = (string) $linea['estado'];
                    if (isset($stats[$estado])) {
                        $stats[$estado]++;
                    }
                }
            }

            foreach ($lineas as $linea) {
                if (!empty($linea['match_manual'])
                    && !empty($linea['factura_xml_id'])
                    && !isset($manualesNoExactas[(int) $linea['id']])) {
                    continue;
                }
                if (!empty($linea['bloqueo_automatico'])) {
                    $nuevo = [
                        'factura_xml_id' => null,
                        'estado' => 'sin_respaldo',
                        'diferencia' => null,
                        'motivo_match' => 'Desvinculada manualmente.',
                    ];
                    $pendientes[] = self::filaMatch((int) $linea['id'], null, $nuevo['estado'], null,
                        'ninguno', null, false, $nuevo['motivo_match'], true);
                    $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo, $historial);
                    $stats['sin_respaldo']++;
                    continue;
                }

                $match = self::buscarMatch($linea, $facturas, $usadas, $indice);
                if ($match['factura'] === null) {
                    $nuevo = [
                        'factura_xml_id' => null,
                        'estado' => 'sin_respaldo',
                        'diferencia' => null,
                        'motivo_match' => $match['motivo'],
                    ];
                    $pendientes[] = self::filaMatch((int) $linea['id'], null, $nuevo['estado'], null,
                        'ninguno', $match['score'], false, $nuevo['motivo_match'], false);
                    $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo, $historial);
                    $stats['sin_respaldo']++;
                    continue;
                }

                $factura = $match['factura'];
                $usadas[(int) $factura['id']] = true;
                [$estado, $diferencia] = self::clasificar(
                    (float) $linea['monto'],
                    (float) $factura['total'],
                    (string) $linea['moneda']
                );
                $nuevo = [
                    'factura_xml_id' => (int) $factura['id'],
                    'estado' => $estado,
                    'diferencia' => $diferencia,
                    'motivo_match' => $match['motivo'],
                ];
                $pendientes[] = self::filaMatch((int) $linea['id'], $nuevo['factura_xml_id'], $estado,
                    $diferencia, $match['metodo'], $match['score'], false, $match['motivo'], false);
                $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo, $historial);
                $stats[$estado]++;
            }

            // Todas las escrituras juntas, al final: son una por línea y cada
            // una cuesta un viaje a la base.
            self::aplicarMatches($modelo, $pendientes);
            self::guardarHistorial($modelo, $historial);

            if ($verificacionId !== null && method_exists($modelo, 'finalizarVerificacion')) {
                $modelo->finalizarVerificacion($verificacionId, $stats, $cantidadCambios);
            }

            if ($transaccionPropia) {
                $modelo->commit();
            }
            self::anotarAliasUsados();
            return $stats;
        } catch (Throwable $e) {
            if ($transaccionPropia) {
                $modelo->rollback();
            }
            throw $e;
        }
    }

    /**
     * Vuelca de una sola vez qué alias de proveedor decidieron algún
     * emparejamiento en esta corrida, para que la pantalla de alias muestre
     * cuáles siguen sirviendo. Un contador nunca debe tumbar la verificación.
     */
    private static function anotarAliasUsados()
    {
        try {
            $usados = FacturaMatcher::aliasAplicados();
            FacturaMatcher::olvidarAliasAplicados();
            if ($usados && class_exists('ProveedorAlias')) {
                (new ProveedorAlias())->registrarUsos($usados);
            }
        } catch (Throwable $e) {
            // Sin tabla de alias, o sin capa de modelos (pruebas): da igual.
        }
    }

    public static function verificarTodosSociedad($sociedadId, $modelo, $origen = 'automatico')
    {
        foreach ($modelo->getListadosPorSociedad((int) $sociedadId) as $listado) {
            self::verificarListado((int) $listado['id'], $modelo, (string) $origen);
        }
    }

    /** Un cambio de emparejamiento, con las columnas que espera el modelo. */
    private static function filaMatch($id, $facturaId, $estado, $diferencia, $metodo, $score, $manual, $motivo, $bloqueo)
    {
        return [
            'id'                 => (int) $id,
            'factura_xml_id'     => $facturaId ?: null,
            'estado'             => (string) $estado,
            'diferencia'         => $diferencia,
            'metodo_match'       => (string) $metodo,
            'score_proveedor'    => $score,
            'match_manual'       => $manual ? 1 : 0,
            'bloqueo_automatico' => $bloqueo ? 1 : 0,
            'motivo_match'       => $motivo ?: null,
        ];
    }

    /**
     * Escribe los cambios acumulados. Prefiere la versión por tandas; si el
     * modelo no la trae —las pruebas usan dobles más simples— cae a una
     * consulta por línea, que es lo que se hacía antes.
     */
    private static function aplicarMatches($modelo, array $filas)
    {
        if (!$filas) {
            return;
        }
        if (method_exists($modelo, 'actualizarMatchLote')) {
            $modelo->actualizarMatchLote($filas);
            return;
        }
        foreach ($filas as $fila) {
            $modelo->actualizarMatch(
                $fila['id'], $fila['factura_xml_id'], $fila['estado'], $fila['diferencia'],
                $fila['metodo_match'], $fila['score_proveedor'], (bool) $fila['match_manual'],
                $fila['motivo_match'], $fila['bloqueo_automatico']
            );
        }
    }

    /**
     * Acumula la fila de historial del cambio en lugar de escribirla.
     *
     * La primera verificación de un listado cambia TODAS sus líneas —miles—, y
     * una consulta por línea no cabe en el tiempo de la petición. Se juntan y
     * se guardan de una vez en guardarHistorial(). Si el modelo no sabe armar
     * la fila (las pruebas usan dobles más simples), cae a la escritura de a
     * una, que es lo que se hacía antes.
     */
    private static function registrarCambio($modelo, $verificacionId, array $anterior, array $nuevo, array &$historial)
    {
        if ($verificacionId === null) {
            return 0;
        }
        if (method_exists($modelo, 'filaHistorial')) {
            $fila = $modelo->filaHistorial($verificacionId, $anterior, $nuevo);
            if ($fila === null) {
                return 0;
            }
            $historial[] = $fila;
            return 1;
        }
        if (!method_exists($modelo, 'registrarCambioVerificacion')) {
            return 0;
        }
        return $modelo->registrarCambioVerificacion($verificacionId, $anterior, $nuevo) ? 1 : 0;
    }

    private static function guardarHistorial($modelo, array $historial)
    {
        if ($historial && method_exists($modelo, 'registrarHistorialLote')) {
            $modelo->registrarHistorialLote($historial);
        }
    }

    public static function clasificar($montoReporte, $totalXml, $moneda)
    {
        $diferencia = round((float) $montoReporte - (float) $totalXml, 2);
        $coinciden = self::montosCoinciden($montoReporte, $totalXml);
        return [
            $coinciden ? 'coincide' : 'con_diferencia',
            $coinciden ? null : $diferencia,
        ];
    }

    public static function montosCoinciden($montoReporte, $totalXml)
    {
        return round((float) $montoReporte - (float) $totalXml, 2) === 0.0;
    }

    public static function normalizeCurrency($moneda)
    {
        $value = strtoupper(trim((string) $moneda));
        return in_array($value, ['USD', 'US$', '$'], true) ? 'USD' : 'CRC';
    }

    /** Respuestas ya calculadas de "¿son el mismo proveedor?", por par de nombres. */
    private static $memoProveedor = [];

    /**
     * Compara proveedores recordando lo ya comparado.
     *
     * La comparación es difusa —normaliza, quita sufijos societarios y mide
     * parecido—, así que cuesta. Y se repite muchísimo: medido sobre un
     * listado real, 2.7 millones de llamadas para solo 43 560 pares distintos,
     * porque los mismos 242 nombres de línea se comparan una y otra vez contra
     * los mismos 180 de las facturas.
     *
     * La memoria vive lo que dure la petición. Un alias nuevo aprendido a
     * mitad de una verificación no se reflejaría, pero los alias se aprenden
     * al vincular a mano, nunca durante una verificación.
     */
    private static function mismoProveedorMemo($nombreLinea, $nombreFactura)
    {
        $clave = $nombreLinea . "\x00" . $nombreFactura;
        if (!array_key_exists($clave, self::$memoProveedor)) {
            self::$memoProveedor[$clave] = FacturaMatcher::mismoProveedor($nombreLinea, $nombreFactura);
        }
        return self::$memoProveedor[$clave];
    }

    /**
     * Índice de las facturas por los dos valores con los que mismoNumeroNc()
     * compara, que son igualdades exactas.
     *
     * Sin él, cada línea recorría las 1 636 candidatas para descartarlas una a
     * una: con 2 103 líneas eran 3.4 millones de comparaciones y casi un
     * minuto de CPU. Guarda posiciones, no copias, para no duplicar en memoria
     * todas las facturas.
     */
    private static function indexarFacturas(array $facturas)
    {
        $indice = ['consecutivo' => [], 'ocho' => []];
        foreach ($facturas as $posicion => $factura) {
            $consecutivo = self::digits((string) ($factura['consecutivo_completo'] ?? ''));
            if ($consecutivo !== '') {
                $indice['consecutivo'][$consecutivo][] = $posicion;
            }
            $ocho = NumeroFactura::xmlOchoDigitos($factura['numero_factura_asistente'] ?? '');
            if ((string) $ocho !== '') {
                $indice['ocho'][(string) $ocho][] = $posicion;
            }
        }
        return $indice;
    }

    private static function buscarMatch(array $linea, array $facturas, array $usadas, ?array $indice = null)
    {
        $numeroProveedor = self::digits((string) ($linea['nc_proveedor'] ?? ''));

        // Con número de NC del proveedor el universo se reduce a las que
        // comparten ese consecutivo: se buscan en el índice en vez de recorrer
        // todas. Se conservan las posiciones ordenadas para que el orden de
        // las candidatas sea el mismo de siempre.
        $base = $facturas;
        if ($numeroProveedor !== '' && $indice !== null) {
            $posiciones = array_unique(array_merge(
                $indice['consecutivo'][$numeroProveedor] ?? [],
                $indice['ocho'][(string) NumeroFactura::xmlOchoDigitos($numeroProveedor)] ?? []
            ));
            sort($posiciones);
            $base = [];
            foreach ($posiciones as $posicion) {
                $base[] = $facturas[$posicion];
            }
        }

        $disponibles = array_values(array_filter($base, function ($factura) use ($usadas, $linea) {
            return !isset($usadas[(int) $factura['id']])
                && self::normalizeCurrency($factura['moneda']) === self::normalizeCurrency($linea['moneda']);
        }));

        if ($numeroProveedor !== '') {
            // Se vuelve a comprobar sobre el conjunto ya reducido: el índice
            // acelera, pero quien decide sigue siendo la misma función.
            $disponibles = array_values(array_filter($disponibles, function ($factura) use ($numeroProveedor) {
                return self::mismoNumeroNc($numeroProveedor, $factura);
            }));
            if (empty($disponibles)) {
                return [
                    'factura' => null,
                    'metodo' => 'ninguno',
                    'score' => null,
                    'motivo' => 'No hay un XML disponible con el consecutivo exacto de NC Proveedor ('
                        . $numeroProveedor . ').',
                ];
            }
        }

        $candidatas = [];
        foreach ($disponibles as $factura) {
            $mismoProveedor = self::mismoProveedorMemo(
                (string) $linea['proveedor_nombre'],
                (string) $factura['proveedor_nombre']
            );
            if (!$mismoProveedor && !empty($factura['proveedor_alias'])) {
                $mismoProveedor = self::mismoProveedorMemo(
                    (string) $linea['proveedor_nombre'],
                    (string) $factura['proveedor_alias']
                );
            }
            if (!$mismoProveedor) {
                continue;
            }

            $factura['_score_proveedor'] = 100.0;
            $factura['_diferencia_monto'] = abs(round(
                (float) $linea['monto'] - (float) $factura['total'],
                2
            ));
            $candidatas[] = $factura;
        }

        if (empty($candidatas)) {
            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => null,
                'motivo' => $numeroProveedor !== ''
                    ? 'El XML con el consecutivo de NC Proveedor no corresponde al mismo proveedor.'
                    : 'No hay XML disponibles del mismo proveedor y moneda.',
            ];
        }

        $candidatasExactas = array_values(array_filter($candidatas, function ($factura) use ($linea) {
            return self::montosCoinciden($linea['monto'], $factura['total']);
        }));
        if (empty($candidatasExactas)) {
            usort($candidatas, function ($a, $b) {
                return $a['_diferencia_monto'] <=> $b['_diferencia_monto'];
            });
            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => 100.0,
                'motivo' => self::motivoMontoNoExacto($candidatas[0], $numeroProveedor),
            ];
        }

        if (count($candidatasExactas) > 1) {
            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => 100.0,
                'motivo' => 'Hay varios XML del mismo proveedor con el monto exacto; requiere vinculación manual.',
            ];
        }

        $elegida = $candidatasExactas[0];
        return [
            'factura' => $elegida,
            'metodo' => $numeroProveedor !== '' ? 'numero' : 'atributos',
            'score' => 100.0,
            'motivo' => $numeroProveedor !== ''
                ? self::motivoNumeroProveedor($elegida)
                : 'Coincidencia por mismo proveedor y monto exacto.',
        ];
    }

    public static function mismoNumeroNc($numeroProveedor, array $factura)
    {
        $numero = self::digits((string) $numeroProveedor);
        if ($numero === '') {
            return false;
        }
        return self::digits((string) ($factura['consecutivo_completo'] ?? '')) === $numero
            || NumeroFactura::xmlOchoDigitos($factura['numero_factura_asistente'] ?? '')
                === NumeroFactura::xmlOchoDigitos($numero);
    }

    private static function motivoMontoNoExacto(array $factura, $numeroProveedor = '')
    {
        $prefijo = $numeroProveedor !== ''
            ? 'El XML con el consecutivo exacto de NC Proveedor'
            : 'El XML del mismo proveedor y moneda';
        return $prefijo . ' no tiene el monto exacto del reporte '
            . '(diferencia ' . number_format((float) $factura['_diferencia_monto'], 2, '.', ',') . ').';
    }

    private static function motivoNumeroProveedor(array $factura)
    {
        return 'Coincidencia exacta por NC Proveedor, mismo proveedor y monto.';
    }

    private static function digits($value)
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
