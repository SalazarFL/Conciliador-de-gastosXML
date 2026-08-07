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

        $lineas = $modelo->getLineas($listadoId);
        $idsAntes = method_exists($modelo, 'getFacturaIdsVinculadas')
            ? $modelo->getFacturaIdsVinculadas($listadoId)
            : [];

        // El universo de candidatas es la semana del listado (si tiene):
        // la verificación no es contra el acumulado de todo el sistema
        $registro = $modelo->getListado($listadoId);
        $semanaId = $registro ? (int) ($registro['semana_id'] ?? 0) : 0;
        $facturas = $modelo->getFacturasParaMatching($semanaId);

        $stats = ['respaldada' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
        $usadas = [];

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
                $modelo->actualizarMatch((int) $linea['id'], null, 'sin_respaldo', null, null, null);
                $stats['sin_respaldo']++;
                continue;
            }

            $usadas[(int) $mejor['id']] = true;

            $diferencia = round((float) $linea['total'] - (float) $mejor['total'], 2);
            $estado = abs($diferencia) <= FacturaMatcher::TOLERANCIA_CRC ? 'respaldada' : 'con_diferencia';

            $modelo->actualizarMatch(
                (int) $linea['id'],
                (int) $mejor['id'],
                $estado,
                $estado === 'con_diferencia' ? $diferencia : null,
                round($mejorNumero, 1),
                round($mejorProveedor, 1)
            );
            $stats[$estado]++;
        }

        $stats['archivos_movidos'] = 0;
        $stats['archivos_revisar'] = 0;
        $stats['archivos_errores'] = 0;

        if ($semanaId > 0 && method_exists($modelo, 'asignarRespaldadasASemana')) {
            $modelo->asignarRespaldadasASemana($listadoId, $semanaId);
        }
        if (method_exists($modelo, 'getFacturaIdsVinculadas')) {
            $idsDespues = $modelo->getFacturaIdsVinculadas($listadoId);
            self::organizarArchivos(array_values(array_unique(array_merge($idsAntes, $idsDespues))), $stats);
        }

        return $stats;
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
                self::verificarListado((int) $listado['id'], $modelo);
            }
        } catch (Throwable $e) {
            // Best effort: el siguiente disparo (u otra asignación) la repite
        }
    }

    /** Mueve de inmediato los pares afectados; la tarea programada reintenta cualquier pendiente. */
    private static function organizarArchivos(array $facturaIds, array &$stats)
    {
        if (!$facturaIds) {
            return;
        }
        try {
            require_once __DIR__ . '/OrganizadorDocumentos.php';
            if (DocumentoArchivo::raizConfigurada() === '') {
                return;
            }
            $resultado = (new OrganizadorDocumentos())->organizarIds($facturaIds);
            $stats['archivos_movidos'] = (int) ($resultado['movidos'] ?? 0);
            $stats['archivos_revisar'] = (int) ($resultado['revisar'] ?? 0);
            $stats['archivos_errores'] = (int) ($resultado['errores'] ?? 0);
        } catch (Throwable $e) {
            $stats['archivos_errores']++;
        }
    }
}
