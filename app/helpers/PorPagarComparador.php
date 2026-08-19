<?php
/**
 * Compara un archivo de pago contra las facturas que la semana ya tiene.
 *
 * Se volvió mucho más simple de lo que era, y no por recortarlo: por lo que
 * dejó de existir. Cuando el pago guardaba una copia de cada renglón del
 * archivo, comparar significaba cotejar cuatro campos —número, proveedor,
 * fecha, total— y decidir si un nombre escrito distinto era la misma factura o
 * una nueva. De ahí salían los estados 'modificada' y la mitad de las reglas.
 *
 * Ahora el archivo no aporta datos: aporta una selección. Cada renglón se
 * resuelve a una factura del ERP —eso lo hace PagoSemanalResolutor— y comparar
 * es restar dos conjuntos de identificadores. No puede haber una factura
 * "modificada" porque no hay copia que se desincronice: los datos son los del
 * ERP, y son los mismos antes y después.
 *
 * Sigue siendo de solo lectura. Aplicar lo que sale de acá es una orden aparte.
 */

class PorPagarComparador
{
    /**
     * @param array $resolucion Salida de PagoSemanalResolutor::resolver().
     * @param array $asignadas  Facturas del ERP hoy en el pago (id, documento,
     *                          proveedor_nombre, fecha_emision, saldo…).
     * @return array ['lineas' => [...], 'resumen' => [...]]
     */
    public static function comparar(array $resolucion, array $asignadas)
    {
        $actuales = [];
        foreach ($asignadas as $factura) {
            $actuales[(int) $factura['id']] = $factura;
        }

        $lineas = [];
        $vistas = [];

        foreach ($resolucion['filas'] as $fila) {
            $erpId = (int) ($fila['factura_erp_id'] ?? 0);
            $estado = (string) $fila['estado'];

            // Lo que el resolutor no pudo resolver se informa tal cual: no es
            // una diferencia con la semana, es un problema del archivo o del
            // reporte del ERP, y se arregla en otro sitio.
            if ($estado !== 'resuelta') {
                $lineas[] = self::linea($estado, $fila, null, $fila['motivo']);
                continue;
            }

            $vistas[$erpId] = true;
            if (isset($actuales[$erpId])) {
                $lineas[] = self::linea('igual', $fila, $actuales[$erpId],
                    'Ya está en el pago de esta semana.');
            } else {
                $lineas[] = self::linea('nueva', $fila, null,
                    empty($fila['movida_desde'])
                        ? 'Entraría al pago de esta semana.'
                        : 'Entraría a esta semana quitándosela al pago #' . (int) $fila['movida_desde'] . '.');
            }
        }

        // Lo que la semana tiene y el archivo nuevo no menciona.
        foreach ($actuales as $erpId => $factura) {
            if (isset($vistas[$erpId])) {
                continue;
            }
            $lineas[] = [
                'estado' => 'faltante',
                'motivo' => 'Está en el pago pero no viene en el archivo nuevo.',
                'factura_erp_id' => $erpId,
                'numero' => (string) $factura['documento'],
                'proveedor' => (string) ($factura['proveedor_nombre'] ?? ''),
                'fecha' => (string) ($factura['fecha_emision'] ?? ''),
                'saldo' => round((float) ($factura['saldo_pago'] ?? $factura['saldo'] ?? 0), 2),
                'estado_respaldo' => (string) ($factura['estado'] ?? ($factura['estado_respaldo'] ?? '')),
            ];
        }

        // 'en_otro_pago' salió de la lista: una factura que ya está en el pago
        // de otra semana dejó de ser un conflicto y entra como cualquier otra,
        // moviéndose. Sigue contándose aparte, en 'reasignada'.
        $resumen = array_fill_keys(
            ['nueva', 'igual', 'faltante', 'ausente', 'ambigua', 'repetida', 'error'],
            0
        );
        $resumen['reasignada'] = (int) ($resolucion['resumen']['reasignada'] ?? 0);
        foreach ($lineas as $linea) {
            $estado = (string) $linea['estado'];
            if (isset($resumen[$estado])) {
                $resumen[$estado]++;
            }
        }

        return ['lineas' => $lineas, 'resumen' => $resumen];
    }

    private static function linea($estado, array $fila, $erp, $motivo)
    {
        $datos = $erp ?: ($fila['erp'] ?? null);
        return [
            'estado' => $estado,
            'motivo' => (string) $motivo,
            'factura_erp_id' => $fila['factura_erp_id'] ?? null,
            // Lo que se enseña es lo que dice el ERP cuando la factura se
            // encontró; solo cuando no se encontró se cae al texto del archivo,
            // que es justo el caso en que hay que verlo para corregirlo.
            'numero' => $datos ? (string) ($datos['documento'] ?? $fila['numero']) : (string) $fila['numero'],
            'proveedor' => $datos
                ? (string) ($datos['proveedor'] ?? ($datos['proveedor_nombre'] ?? $fila['proveedor']))
                : (string) $fila['proveedor'],
            'fecha' => $datos ? (string) ($datos['fecha'] ?? ($datos['fecha_emision'] ?? '')) : '',
            'saldo' => round((float) $fila['saldo'], 2),
            'saldo_erp' => $datos && isset($datos['saldo']) ? round((float) $datos['saldo'], 2) : null,
            'estado_respaldo' => $erp ? (string) ($erp['estado'] ?? '') : '',
        ];
    }
}
