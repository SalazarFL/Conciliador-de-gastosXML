<?php
/**
 * Verificación del pago semanal contra los comprobantes electrónicos.
 *
 * Desde que el pago semanal dejó de tener facturas propias, esto cambió de
 * naturaleza. Antes se cruzaba el texto del archivo del ERP —número escrito de
 * cinco formas, proveedor recortado a lo ancho de la columna— contra los XML, y
 * hacía falta un emparejador difuso con umbrales, rescates y aprendizaje de
 * alias. Ahora la línea del pago ES la factura del ERP, y esa fila trae el
 * consecutivo electrónico de veinte dígitos en `documento`: el mismo número que
 * el XML lleva en `consecutivo_completo`. El cruce es una igualdad.
 *
 * Queda una vía difusa, y solo una: uno de cada cuatro renglones del ERP no
 * trae consecutivo sino el número interno (FACT-12339). Esos se buscan por la
 * llave corta de ocho dígitos y se exige además que el proveedor coincida,
 * porque un número corto sí se repite entre emisores.
 *
 * El monto no identifica: clasifica. Cuadra al colón → respaldada; no cuadra →
 * con_diferencia, que es donde alguien tiene que mirar. Se compara contra
 * `monto` (el total de la factura) y no contra `saldo`, que es lo que se debe y
 * baja a cero al pagar.
 */

require_once __DIR__ . '/FacturaMatcher.php';
require_once __DIR__ . '/NumeroFactura.php';

class PorPagarVerificador
{
    /**
     * Cruza las facturas del pago con los XML disponibles.
     *
     * @param int    $listadoId
     * @param object $erp      Modelo FacturaErp.
     * @param object $facturas Modelo Factura (candidatas XML).
     * @return array Conteo por estado.
     */
    public static function verificarListado($listadoId, $erp, $facturas = null)
    {
        @set_time_limit(120);

        $listadoId = (int) $listadoId;
        $lineas = $erp->getFacturasPagoParaMatching($listadoId);
        if (!$lineas) {
            return ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        }

        $candidatas = $facturas !== null && method_exists($facturas, 'getCandidatasParaPago')
            ? $facturas->getCandidatasParaPago()
            : [];
        $indice = self::indexar($candidatas);

        $stats = ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        $usadas = [];
        $pendientes = [];

        // Los vínculos hechos a mano se respetan y su XML no vuelve a estar
        // disponible. Se marcan antes para que ninguna fila automática se lo
        // lleve por venir primero en el recorrido.
        foreach ($lineas as $linea) {
            if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                $usadas[(int) $linea['factura_xml_id']] = true;
                $estado = (string) $linea['estado'];
                if (isset($stats[$estado])) {
                    $stats[$estado]++;
                }
            }
        }

        foreach ($lineas as $linea) {
            if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                continue;
            }

            $xml = self::buscarXml($linea, $indice, $usadas);
            if ($xml === null) {
                $pendientes[] = self::fila((int) $linea['id'], null, 'sin_respaldo', null, null, null);
                $stats['sin_respaldo']++;
                continue;
            }

            $usadas[(int) $xml['id']] = true;
            $diferencia = round((float) $linea['monto'] - (float) $xml['total'], 2);
            $estado = abs($diferencia) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $pendientes[] = self::fila(
                (int) $linea['id'],
                (int) $xml['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null,
                (float) $xml['_score_numero'],
                (float) $xml['_score_proveedor']
            );
            $stats[$estado]++;
        }

        if ($pendientes) {
            $erp->actualizarRespaldoLote($pendientes);
        }

        // La semana del comprobante se deduce del pago, no se elige. Verificar
        // sigue sin mover un solo archivo —eso solo pasa por orden expresa—,
        // pero deja anotado a qué semana pertenece cada XML para cuando esa
        // orden llegue.
        if (method_exists($erp, 'sincronizarSemanaXml')) {
            $erp->sincronizarSemanaXml($listadoId);
        }
        return $stats;
    }

    /**
     * Índice de los XML por las dos llaves con las que el ERP los nombra.
     *
     * Sin él cada fila del pago recorría todas las candidatas: con 414 filas y
     * 5 700 XML son 2.4 millones de comparaciones por verificación.
     */
    private static function indexar(array $candidatas)
    {
        $indice = ['consecutivo' => [], 'corto' => []];
        foreach ($candidatas as $xml) {
            $consecutivo = preg_replace('/\D+/', '', (string) ($xml['consecutivo_completo'] ?? ''));
            if (preg_match('/^\d{20}$/', $consecutivo)) {
                $indice['consecutivo'][$consecutivo][] = $xml;
            }
            $corto = (string) NumeroFactura::xmlOchoDigitos($xml['numero_factura_asistente'] ?? '');
            if ($corto !== '' && ltrim($corto, '0') !== '') {
                $indice['corto'][$corto][] = $xml;
            }
        }
        return $indice;
    }

    /**
     * El XML de una factura del ERP, si está.
     *
     * El consecutivo manda: es único a nivel país —lo emite Hacienda— así que
     * una igualdad basta y no hace falta comprobar nada más. Solo cuando el
     * ERP no trae consecutivo se cae a la llave corta, y ahí sí se exige el
     * proveedor: "FACT-12339" lo usan muchos emisores distintos.
     */
    private static function buscarXml(array $linea, array $indice, array $usadas)
    {
        $documento = trim((string) ($linea['documento'] ?? ''));
        if (preg_match('/^\d{20}$/', $documento)) {
            foreach ($indice['consecutivo'][$documento] ?? [] as $xml) {
                if (isset($usadas[(int) $xml['id']])) {
                    continue;
                }
                $xml['_score_numero'] = 100.0;
                $xml['_score_proveedor'] = 100.0;
                return $xml;
            }
        }

        $corto = trim((string) ($linea['numero_corto'] ?? ''));
        if ($corto === '') {
            $corto = $documento;
        }
        $corto = (string) NumeroFactura::xmlOchoDigitos($corto);
        if ($corto === '' || ltrim($corto, '0') === '') {
            return null;
        }

        $proveedor = (string) ($linea['proveedor_nombre'] ?? '');
        $mejor = null;
        $mejorScore = 0.0;
        $empates = 0;

        foreach ($indice['corto'][$corto] ?? [] as $xml) {
            if (isset($usadas[(int) $xml['id']])) {
                continue;
            }
            $score = FacturaMatcher::similaridadTexto($proveedor, (string) ($xml['proveedor_nombre'] ?? ''));
            if ($score < FacturaMatcher::UMBRAL_PROVEEDOR) {
                continue;
            }
            if ($score > $mejorScore) {
                $mejor = $xml;
                $mejorScore = $score;
                $empates = 1;
            } elseif ($score === $mejorScore) {
                $empates++;
            }
        }

        // Dos proveedores igual de parecidos con el mismo número corto: no hay
        // forma de saber cuál es, y elegir al azar es peor que no elegir.
        if ($mejor === null || $empates !== 1) {
            return null;
        }

        $mejor['_score_numero'] = 60.0;
        $mejor['_score_proveedor'] = round($mejorScore, 1);
        return $mejor;
    }

    private static function fila($id, $xmlId, $estado, $diferencia, $scoreNumero, $scoreProveedor)
    {
        return [
            'id'              => (int) $id,
            'factura_xml_id'  => $xmlId ?: null,
            'estado_respaldo' => (string) $estado,
            'diferencia'      => $diferencia,
            'score_numero'    => $scoreNumero,
            'score_proveedor' => $scoreProveedor,
        ];
    }

    /**
     * Re-verifica los pagos abiertos que todavía tienen facturas sin respaldo.
     *
     * Es lo que corre cuando entra un XML por una vía que no sabe a qué pago
     * pertenece. Ya casi nunca hace falta: el importador enlaza el XML con su
     * factura del ERP en el momento, y con eso el pago queda al día. Se
     * conserva para lo que llegue por caminos viejos y para reparar a mano.
     *
     * Nunca lanza: verificar no debe tumbar la operación que la disparó.
     */
    public static function verificarAbiertos($erp, $facturas = null, $limite = 3)
    {
        $resueltas = 0;
        try {
            foreach ($erp->idsPagosAbiertosConFaltantes($limite) as $id) {
                $antes = (int) ($erp->resumenRespaldoPago($id)['sin_respaldo'] ?? 0);
                self::verificarListado($id, $erp, $facturas);
                $despues = (int) ($erp->resumenRespaldoPago($id)['sin_respaldo'] ?? 0);
                $resueltas += max(0, $antes - $despues);
            }
        } catch (Throwable $e) {
            // Best effort: el siguiente lote o el botón manual la repiten.
        }
        return $resueltas;
    }

    /** Re-verifica todos los pagos abiertos de una semana. */
    public static function verificarSemana($semanaId, $erp, $facturas = null)
    {
        $semanaId = (int) $semanaId;
        if ($semanaId <= 0) {
            return;
        }
        try {
            foreach ($erp->idsPagosAbiertosDeSemana($semanaId) as $id) {
                self::verificarListado((int) $id, $erp, $facturas);
            }
        } catch (Throwable $e) {
            // Best effort: otra asignación o el botón manual la repiten.
        }
    }
}
