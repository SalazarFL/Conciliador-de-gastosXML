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
        $usadas = [];
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
                    $modelo->actualizarMatch((int) $linea['id'], null, $nuevo['estado'], null, 'ninguno',
                        null, false, $nuevo['motivo_match'], true);
                    $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo);
                    $stats['sin_respaldo']++;
                    continue;
                }

                $match = self::buscarMatch($linea, $facturas, $usadas);
                if ($match['factura'] === null) {
                    $nuevo = [
                        'factura_xml_id' => null,
                        'estado' => 'sin_respaldo',
                        'diferencia' => null,
                        'motivo_match' => $match['motivo'],
                    ];
                    $modelo->actualizarMatch((int) $linea['id'], null, $nuevo['estado'], null, 'ninguno',
                        $match['score'], false, $nuevo['motivo_match']);
                    $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo);
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
                $modelo->actualizarMatch((int) $linea['id'], $nuevo['factura_xml_id'], $estado,
                    $diferencia, $match['metodo'], $match['score'], false, $match['motivo']);
                $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo);
                $stats[$estado]++;
            }

            if ($verificacionId !== null && method_exists($modelo, 'finalizarVerificacion')) {
                $modelo->finalizarVerificacion($verificacionId, $stats, $cantidadCambios);
            }

            if ($transaccionPropia) {
                $modelo->commit();
            }
            return $stats;
        } catch (Throwable $e) {
            if ($transaccionPropia) {
                $modelo->rollback();
            }
            throw $e;
        }
    }

    public static function verificarTodosSociedad($sociedadId, $modelo, $origen = 'automatico')
    {
        foreach ($modelo->getListadosPorSociedad((int) $sociedadId) as $listado) {
            self::verificarListado((int) $listado['id'], $modelo, (string) $origen);
        }
    }

    private static function registrarCambio($modelo, $verificacionId, array $anterior, array $nuevo)
    {
        if ($verificacionId === null || !method_exists($modelo, 'registrarCambioVerificacion')) {
            return 0;
        }
        return $modelo->registrarCambioVerificacion($verificacionId, $anterior, $nuevo) ? 1 : 0;
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

    private static function buscarMatch(array $linea, array $facturas, array $usadas)
    {
        $disponibles = array_values(array_filter($facturas, function ($factura) use ($usadas, $linea) {
            return !isset($usadas[(int) $factura['id']])
                && self::normalizeCurrency($factura['moneda']) === self::normalizeCurrency($linea['moneda']);
        }));

        $numeroProveedor = self::digits((string) ($linea['nc_proveedor'] ?? ''));
        if ($numeroProveedor !== '') {
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
            $mismoProveedor = FacturaMatcher::mismoProveedor(
                (string) $linea['proveedor_nombre'],
                (string) $factura['proveedor_nombre']
            );
            if (!$mismoProveedor && !empty($factura['proveedor_alias'])) {
                $mismoProveedor = FacturaMatcher::mismoProveedor(
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
