<?php
/**
 * Verificación del listado por pagar contra las facturas XML.
 *
 * La lógica vivía en PorPagarController (botón "Verificar de nuevo");
 * ahora es compartida y corre sola cada vez que una factura entra o
 * sale de una semana: asignación manual desde Facturas, subida directa
 * de XML y cola de importación (correo incluido).
 */

require_once __DIR__ . '/FacturaMatcher.php';

class PorPagarVerificador
{
    /**
     * Cruza cada línea del listado con la mejor factura XML disponible.
     * El proveedor y el número identifican la factura; el monto solo
     * clasifica (respaldada / con_diferencia) y no impide enviarla a la
     * carpeta semanal. NC y ND quedan fuera.
     * $modelo es el modelo PorPagar ya cargado.
     */
    public static function verificarListado($listadoId, $modelo)
    {
        @set_time_limit(120);

        $registro = $modelo->getListado($listadoId);
        if ($registro && ($registro['estado'] ?? 'abierto') === 'cerrado') {
            $resumen = method_exists($modelo, 'resumenPorEstado')
                ? $modelo->resumenPorEstado($listadoId)
                : [];
            return [
                'respaldada' => (int) ($resumen['respaldada'] ?? 0),
                'con_diferencia' => (int) ($resumen['con_diferencia'] ?? 0),
                'sin_respaldo' => (int) ($resumen['sin_respaldo'] ?? 0),
            ];
        }

        $lineas = $modelo->getLineas($listadoId);

        // El universo de candidatas es la semana del listado (si tiene):
        // la verificación no es contra el acumulado de todo el sistema
        $semanaId = $registro ? (int) ($registro['semana_id'] ?? 0) : 0;
        $facturas = $modelo->getFacturasParaMatching($semanaId);

        $stats = ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        $usadas = [];
        $pendientes = [];

        // Los vínculos manuales (botón "Sin coincidencia") se respetan: la
        // línea conserva su factura y esa factura no queda disponible para
        // las demás. Se marcan primero para que ninguna línea automática
        // "robe" una factura vinculada a mano más abajo en el listado.
        foreach ($lineas as $linea) {
            if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                $usadas[(int) $linea['factura_xml_id']] = true;
                if (isset($stats[$linea['estado']])) {
                    $stats[$linea['estado']]++;
                }
            }
        }

        foreach ($lineas as $linea) {
            if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                continue;
            }

            $mejor = null;
            $mejorScore = 0;
            $mejorNumero = 0;
            $mejorProveedor = 0;

            // Rescate: candidata con proveedor que NO se parece pero con
            // número contundente y monto exacto (ver NUMERO_RESCATE).
            $rescate = null;
            $rescateNumero = 0;
            $rescateProveedor = 0;

            foreach ($facturas as $factura) {
                $fid = (int) $factura['id'];
                if (isset($usadas[$fid])) {
                    continue;
                }

                // El número del listado puede ser el corto ("FACT-26546") o el
                // consecutivo de 20 dígitos: se compara contra ambos campos
                $scoreNumero = max(
                    FacturaMatcher::similaridadNumero($linea['numero'], (string) $factura['numero_factura_asistente']),
                    FacturaMatcher::similaridadNumero($linea['numero'], (string) $factura['consecutivo_completo'])
                );
                if ($scoreNumero < FacturaMatcher::UMBRAL_NUMERO) {
                    continue;
                }

                $scoreProveedor = FacturaMatcher::similaridadTexto(
                    (string) $linea['proveedor_texto'],
                    (string) $factura['proveedor_nombre']
                );

                if ($scoreProveedor < FacturaMatcher::UMBRAL_PROVEEDOR) {
                    // Proveedor distinto (nombre comercial vs razón social):
                    // solo rescatable si el número es contundente Y el monto
                    // cuadra al colón. Gana el número más fuerte.
                    if ($scoreNumero >= FacturaMatcher::NUMERO_RESCATE
                        && abs(round((float) $linea['total'] - (float) $factura['total'], 2)) <= FacturaMatcher::TOLERANCIA_CRC
                        && $scoreNumero > $rescateNumero) {
                        $rescate = $factura;
                        $rescateNumero = $scoreNumero;
                        $rescateProveedor = $scoreProveedor;
                    }
                    continue;
                }

                $score = ($scoreNumero * 0.6) + ($scoreProveedor * 0.4);
                if ($score > $mejorScore) {
                    $mejorScore = $score;
                    $mejor = $factura;
                    $mejorNumero = $scoreNumero;
                    $mejorProveedor = $scoreProveedor;
                }
            }

            // Sin candidata normal: usar el rescate si lo hubo
            if ($mejor === null && $rescate !== null) {
                $mejor = $rescate;
                $mejorNumero = $rescateNumero;
                $mejorProveedor = $rescateProveedor;
            }

            if ($mejor === null) {
                $pendientes[] = self::filaMatch((int) $linea['id'], null, 'sin_respaldo', null, null, null);
                $stats['sin_respaldo']++;
                continue;
            }

            $usadas[(int) $mejor['id']] = true;

            $diferencia = round((float) $linea['total'] - (float) $mejor['total'], 2);
            $estado = abs($diferencia) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $pendientes[] = self::filaMatch(
                (int) $linea['id'],
                (int) $mejor['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null,
                round($mejorNumero, 1),
                round($mejorProveedor, 1)
            );
            $stats[$estado]++;
        }

        // Todas las escrituras juntas: son una por línea y cada una cuesta un
        // viaje a la base. Un listado de 568 líneas se llevaba 50 segundos.
        self::aplicarMatches($modelo, $pendientes);

        // Verificar no mueve archivos. La ubicación en el disco solo cambia
        // por orden explícita de la persona (Correo → Ordenar el archivo).
        if ($semanaId > 0 && method_exists($modelo, 'asignarRespaldadasASemana')) {
            $modelo->asignarRespaldadasASemana($listadoId, $semanaId);
        }

        self::anotarAliasUsados();
        return $stats;
    }

    /** Un cambio de emparejamiento, con las columnas que espera el modelo. */
    private static function filaMatch($id, $facturaId, $estado, $diferencia, $scoreNumero, $scoreProveedor)
    {
        return [
            'id'              => (int) $id,
            'factura_xml_id'  => $facturaId ?: null,
            'estado'          => (string) $estado,
            'diferencia'      => $diferencia,
            'score_numero'    => $scoreNumero,
            'score_proveedor' => $scoreProveedor,
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
                $fila['score_numero'], $fila['score_proveedor']
            );
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

    /**
     * Re-verifica los listados abiertos a los que todavía les falta respaldo.
     *
     * Para cuando entran facturas sin semana asignada —las que llegan por
     * Correo— y por tanto no hay una semana concreta que revisar: cualquier
     * listado abierto puede haberse completado con ellas.
     *
     * Solo mira los que de verdad pueden cambiar: el modelo devuelve en una
     * consulta los listados abiertos con líneas sin respaldo, los más
     * recientes. Los que ya están completos —la mayoría— ni se tocan.
     * Devuelve cuántas líneas pasaron a tener respaldo.
     *
     * El límite es bajo a propósito. Verificar un listado compara cada una de
     * sus líneas contra cada factura candidata: medido sobre datos reales,
     * un listado de 568 líneas son 2.4 millones de comparaciones. Las
     * facturas que llegan hoy pertenecen al pago en curso, no a uno de hace
     * semanas que quedó a medias, así que ampliar el alcance costaría mucho
     * para no encontrar nada.
     */
    public static function verificarAbiertos($modelo, $limite = 3)
    {
        $resueltas = 0;
        try {
            foreach ($modelo->idsAbiertosConFaltantes($limite) as $id) {
                $antes = (int) ($modelo->resumenPorEstado($id)['sin_respaldo'] ?? 0);
                self::verificarListado($id, $modelo);
                $despues = (int) ($modelo->resumenPorEstado($id)['sin_respaldo'] ?? 0);
                $resueltas += max(0, $antes - $despues);
            }
        } catch (Throwable $e) {
            // Best effort: el siguiente lote o el botón manual la repiten.
        }
        return $resueltas;
    }

    /**
     * Re-verifica TODOS los listados de una semana. Es la vía automática
     * que reemplaza al botón "Verificar de nuevo": se llama al asignar o
     * quitar facturas de una semana. Nunca lanza — la verificación no
     * debe romper la operación que la disparó.
     */
    public static function verificarSemana($semanaId, $modelo)
    {
        $semanaId = (int) $semanaId;
        if ($semanaId <= 0) {
            return;
        }

        try {
            foreach ($modelo->getListados(30, $semanaId) as $listado) {
                if (($listado['estado'] ?? 'abierto') === 'cerrado') {
                    continue;
                }
                self::verificarListado((int) $listado['id'], $modelo);
            }
        } catch (Throwable $e) {
            // Best effort: el siguiente disparo (u otra asignación) la repite
        }
    }

}
