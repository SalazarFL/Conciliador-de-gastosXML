<?php
/**
 * Cascada de verificación devolución → NC electrónica:
 *
 *  Nivel 1 (referencia, determinístico — solo boletas): la factura de la
 *  boleta se ubica por su consecutivo de 20 dígitos; las NC que citan su
 *  clave en InformacionReferencia son las candidatas fuertes. Monto exacto
 *  ⇒ confirmado; NC consolidada (cita varias facturas) o con diferencia
 *  ⇒ sugerido.
 *
 *  Nivel 2 (proveedor + monto + fecha): NC del mismo proveedor con total
 *  igual al esperado (±tolerancia) dentro de la ventana de fechas. Una sola
 *  candidata exacta ⇒ confirmado; varias ⇒ sugeridas.
 *
 *  Nivel 2.5 (líneas): cuando ninguna NC cuadra por monto (NC parcial o
 *  consolidada), se comparan las líneas del reporte contra las líneas de las
 *  NC del proveedor en la ventana: nº de líneas, cantidades, códigos (EAN si
 *  el proveedor lo usa) y nombres fuzzy. Solo produce sugerencias — los
 *  nombres/códigos del proveedor no son confiables para confirmar solos.
 *
 *  Nivel 3: sin candidatas ⇒ sin_nc (a revisión/vinculación manual).
 *
 *  Los matches manuales confirmados nunca se tocan.
 */

require_once __DIR__ . '/FacturaMatcher.php';

class DevolucionVerificador
{
    const TOLERANCIA = 0.05;
    const VENTANA_DIAS_ANTES = 5;
    const VENTANA_DIAS_DESPUES = 120;
    const MAX_SUGERIDAS = 5;
    const SCORE_MINIMO_LINEAS = 45;
    const MAX_LINEAS_COMPARADAS = 40;

    /** Verifica una devolución; devuelve el estado resultante. */
    public static function verificar($devolucionId, Devolucion $modelo, array $contexto = [])
    {
        $dev = $modelo->getDevolucion((int) $devolucionId);
        if ($dev === null) {
            throw new RuntimeException('La devolución no existe.');
        }

        $transaccionPropia = !$modelo->inTransaction();
        if ($transaccionPropia) {
            $modelo->begin();
        }

        try {
            self::resolverProveedor($dev, $modelo, $contexto);
            self::resolverFactura($dev, $modelo);

            $manuales = $modelo->objetivosManuales((int) $dev['id']);
            $modelo->limpiarMatchesAutomaticos((int) $dev['id']);

            $confirmados = 0;
            $objetivos = self::objetivos($dev);
            $usadasEnDevolucion = [];
            $usadasGlobales = $contexto['nc_usadas'] ?? $modelo->ncConfirmadasPorMonto();
            $lineasDev = $modelo->getLineas((int) $dev['id']);

            foreach ($objetivos as $objetivo => $monto) {
                if (in_array($objetivo, $manuales, true)) {
                    $confirmados++;
                    continue;
                }
                $resultado = self::verificarObjetivo($dev, $objetivo, $monto, $modelo, $usadasEnDevolucion, $usadasGlobales, $lineasDev);
                if ($resultado === 'confirmado') {
                    $confirmados++;
                }
            }

            $estado = self::estadoGlobal(count($objetivos), $confirmados);
            $modelo->actualizarEstado((int) $dev['id'], $estado);

            if ($transaccionPropia) {
                $modelo->commit();
            }
            return $estado;
        } catch (Throwable $e) {
            if ($transaccionPropia) {
                $modelo->rollback();
            }
            throw $e;
        }
    }

    /** Verifica todas las no verificadas. Devuelve conteos por estado. */
    public static function verificarPendientes(Devolucion $modelo, $sociedadId = null)
    {
        @set_time_limit(300);
        $stats = ['verificada' => 0, 'parcial' => 0, 'sin_nc' => 0, 'pendiente' => 0];
        $contexto = ['nc_usadas' => $modelo->ncConfirmadasPorMonto()];
        foreach ($modelo->pendientesDeVerificar($sociedadId) as $fila) {
            $estado = self::verificar((int) $fila['id'], $modelo, $contexto);
            if (isset($stats[$estado])) {
                $stats[$estado]++;
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------

    /** Expectativas de NC de la devolución: [objetivo => monto]. */
    public static function objetivos(array $dev)
    {
        $objetivos = [];
        if ($dev['tipo'] === 'boleta_local') {
            if ((float) $dev['nc_esperada_cantidad'] > 0) {
                $objetivos['cantidad'] = (float) $dev['nc_esperada_cantidad'];
            }
            if ((float) $dev['nc_esperada_costo'] > 0) {
                $objetivos['costo'] = (float) $dev['nc_esperada_costo'];
            }
        } elseif ((float) $dev['total'] > 0) {
            $objetivos['total'] = (float) $dev['total'];
        }
        return $objetivos;
    }

    private static function verificarObjetivo(array $dev, $objetivo, $monto, Devolucion $modelo, array &$usadasEnDevolucion, array &$usadasGlobales, array $lineasDev = [])
    {
        $base = [
            'devolucion_id' => (int) $dev['id'],
            'objetivo' => $objetivo,
            'monto_esperado' => round($monto, 2),
            'nc_consolidada' => 0,
        ];

        // Nivel 1: NC que referencian la clave de la factura de la boleta.
        if (!empty($dev['factura_clave'])) {
            $referentes = $modelo->ncPorClaveReferenciada((string) $dev['factura_clave']);
            $referentes = array_values(array_filter($referentes, function ($nc) use ($usadasEnDevolucion) {
                return !isset($usadasEnDevolucion[(int) $nc['id']]);
            }));

            foreach ($referentes as $nc) {
                if (abs((float) $nc['total'] - $monto) <= self::TOLERANCIA) {
                    $usadasEnDevolucion[(int) $nc['id']] = true;
                    $modelo->crearMatch($base + [
                        'factura_xml_id' => (int) $nc['id'],
                        'metodo' => 'referencia',
                        'estado' => 'confirmado',
                        'monto_nc' => (float) $nc['total'],
                        'diferencia' => null,
                        'motivo' => 'La NC cita la clave de la factura de esta boleta y el monto coincide.',
                    ]);
                    return 'confirmado';
                }
            }

            if (!empty($referentes)) {
                // Hay NC que citan la factura pero ninguna con el monto de esta
                // expectativa: sugerencias (consolidadas o con diferencia).
                foreach (array_slice($referentes, 0, self::MAX_SUGERIDAS) as $nc) {
                    $consolidada = (int) $nc['total_referencias'] > 1;
                    $modelo->crearMatch($base + [
                        'factura_xml_id' => (int) $nc['id'],
                        'metodo' => 'referencia',
                        'estado' => 'sugerido',
                        'monto_nc' => (float) $nc['total'],
                        'diferencia' => round($monto - (float) $nc['total'], 2),
                        'nc_consolidada' => $consolidada ? 1 : 0,
                        'motivo' => $consolidada
                            ? 'La NC cita esta factura y ' . ((int) $nc['total_referencias'] - 1)
                                . ' más: su total cubre varias boletas. Confirmar manualmente.'
                            : 'La NC cita la factura de esta boleta pero el monto difiere.',
                    ]);
                }
                return 'sugerido';
            }
        }

        // Nivel 2: proveedor + monto + ventana de fechas.
        if (!empty($dev['proveedor_id']) && !empty($dev['fecha'])) {
            $desde = date('Y-m-d', strtotime($dev['fecha'] . ' -' . self::VENTANA_DIAS_ANTES . ' days'));
            $hasta = date('Y-m-d', strtotime($dev['fecha'] . ' +' . self::VENTANA_DIAS_DESPUES . ' days'));
            $candidatas = $modelo->ncPorProveedorYMonto(
                (int) $dev['proveedor_id'], $monto, $desde, $hasta, self::TOLERANCIA
            );
            $candidatas = array_values(array_filter($candidatas, function ($nc) use ($usadasEnDevolucion, $usadasGlobales) {
                return !isset($usadasEnDevolucion[(int) $nc['id']])
                    && !isset($usadasGlobales[(int) $nc['id']]);
            }));

            if (count($candidatas) === 1) {
                $nc = $candidatas[0];
                $usadasEnDevolucion[(int) $nc['id']] = true;
                $usadasGlobales[(int) $nc['id']] = true;
                $modelo->crearMatch($base + [
                    'factura_xml_id' => (int) $nc['id'],
                    'metodo' => 'monto',
                    'estado' => 'confirmado',
                    'monto_nc' => (float) $nc['total'],
                    'diferencia' => null,
                    'motivo' => 'Única NC del proveedor con el monto exacto dentro de la ventana de fechas.',
                ]);
                return 'confirmado';
            }

            if (count($candidatas) > 1) {
                foreach (array_slice($candidatas, 0, self::MAX_SUGERIDAS) as $nc) {
                    $modelo->crearMatch($base + [
                        'factura_xml_id' => (int) $nc['id'],
                        'metodo' => 'monto',
                        'estado' => 'sugerido',
                        'monto_nc' => (float) $nc['total'],
                        'diferencia' => round($monto - (float) $nc['total'], 2),
                        'motivo' => 'Varias NC del proveedor con este monto; elegir manualmente.',
                    ]);
                }
                return 'sugerido';
            }
        }

        // Nivel 2.5: comparación de líneas contra las NC del proveedor en la
        // ventana (captura NC parciales o consolidadas que el monto no ve).
        if (self::sugerirPorLineas($dev, $base, $monto, $lineasDev, $modelo, $usadasEnDevolucion, $usadasGlobales)) {
            return 'sugerido';
        }

        // Nivel 3: nada.
        $motivo = 'No se encontró NC';
        if (empty($dev['proveedor_id'])) {
            $motivo .= ' (proveedor del ERP sin equivalente local: "'
                . (string) $dev['proveedor_nombre_erp'] . '")';
        } elseif ($dev['tipo'] === 'boleta_local' && empty($dev['factura_clave'])) {
            $motivo .= ' (la factura ' . (string) $dev['numero_factura']
                . ' no está importada; sin ella solo se buscó por proveedor y monto)';
        } else {
            $motivo .= ' del proveedor con este monto en la ventana de fechas';
        }
        $modelo->crearMatch($base + [
            'factura_xml_id' => null,
            'metodo' => 'ninguno',
            'estado' => 'sin_nc',
            'monto_nc' => null,
            'diferencia' => null,
            'motivo' => $motivo . '.',
        ]);
        return 'sin_nc';
    }

    /**
     * Nivel 2.5: puntúa las NC del proveedor en la ventana comparando líneas.
     * Crea matches 'sugerido' (método 'lineas') si alguna supera el umbral.
     */
    private static function sugerirPorLineas(array $dev, array $base, $monto, array $lineasDev, Devolucion $modelo, array &$usadasEnDevolucion, array &$usadasGlobales)
    {
        if (empty($dev['proveedor_id']) || empty($dev['fecha'])) {
            return false;
        }
        $lineasObjetivo = self::lineasDelObjetivo($dev, $base['objetivo'], $lineasDev);
        if (!$lineasObjetivo) {
            return false;
        }

        $desde = date('Y-m-d', strtotime($dev['fecha'] . ' -' . self::VENTANA_DIAS_ANTES . ' days'));
        $hasta = date('Y-m-d', strtotime($dev['fecha'] . ' +' . self::VENTANA_DIAS_DESPUES . ' days'));
        $candidatas = $modelo->ncPorProveedorEnVentana((int) $dev['proveedor_id'], $desde, $hasta);
        $candidatas = array_values(array_filter($candidatas, function ($nc) use ($usadasEnDevolucion, $usadasGlobales) {
            return (int) $nc['num_lineas'] > 0
                && !isset($usadasEnDevolucion[(int) $nc['id']])
                && !isset($usadasGlobales[(int) $nc['id']]);
        }));
        if (!$candidatas) {
            return false;
        }

        $lineasNc = $modelo->lineasDeNcs(array_map(function ($nc) { return (int) $nc['id']; }, $candidatas));

        $puntuadas = [];
        foreach ($candidatas as $nc) {
            $detalleNc = $lineasNc[(int) $nc['id']] ?? [];
            if (!$detalleNc) {
                continue;
            }
            $puntaje = self::puntuarLineas($lineasObjetivo, $detalleNc);
            if ($puntaje['score'] >= self::SCORE_MINIMO_LINEAS) {
                $puntuadas[] = ['nc' => $nc, 'puntaje' => $puntaje];
            }
        }
        if (!$puntuadas) {
            return false;
        }

        usort($puntuadas, function ($a, $b) {
            return $b['puntaje']['score'] <=> $a['puntaje']['score'];
        });

        foreach (array_slice($puntuadas, 0, self::MAX_SUGERIDAS) as $p) {
            $nc = $p['nc'];
            $modelo->crearMatch($base + [
                'factura_xml_id' => (int) $nc['id'],
                'metodo' => 'lineas',
                'estado' => 'sugerido',
                'monto_nc' => (float) $nc['total'],
                'diferencia' => round($monto - (float) $nc['total'], 2),
                'motivo' => 'Sugerencia por líneas (afinidad ' . $p['puntaje']['score'] . '/100): '
                    . $p['puntaje']['motivo'] . '.',
            ]);
        }
        return true;
    }

    /** Líneas del reporte que corresponden a la expectativa de NC. */
    private static function lineasDelObjetivo(array $dev, $objetivo, array $lineasDev)
    {
        if ($dev['tipo'] !== 'boleta_local') {
            return $lineasDev;
        }
        $seccion = $objetivo === 'cantidad' ? 'NOTA CANTIDAD' : 'NOTA COSTO';
        return array_values(array_filter($lineasDev, function ($l) use ($seccion) {
            return (string) ($l['seccion'] ?? '') === $seccion;
        }));
    }

    /**
     * Afinidad 0-100 entre las líneas del reporte y las de una NC:
     * nº de líneas (35) + cantidades (35) + códigos (20) + nombres fuzzy (10).
     */
    private static function puntuarLineas(array $lineasDev, array $lineasNc)
    {
        $lineasDev = array_slice($lineasDev, 0, self::MAX_LINEAS_COMPARADAS);
        $lineasNc = array_slice($lineasNc, 0, self::MAX_LINEAS_COMPARADAS);
        $nDev = count($lineasDev);
        $nNc = count($lineasNc);
        $score = 0.0;
        $motivos = [];

        if ($nNc === $nDev) {
            $score += 35;
            $motivos[] = 'mismo nº de líneas (' . $nDev . ')';
        } elseif (abs($nNc - $nDev) === 1) {
            $score += 15;
            $motivos[] = 'nº de líneas cercano (' . $nDev . ' vs ' . $nNc . ')';
        } else {
            $motivos[] = $nDev . ' líneas vs ' . $nNc . ' de la NC';
        }

        // Cantidades: emparejamiento voraz de multiconjuntos.
        $pendientes = array_map(function ($l) { return round((float) $l['cantidad'], 3); }, $lineasNc);
        $coincidenCantidad = 0;
        foreach ($lineasDev as $l) {
            $q = round((float) $l['cantidad'], 3);
            foreach ($pendientes as $i => $qNc) {
                if ($qNc !== null && abs($q - $qNc) <= 0.001) {
                    $coincidenCantidad++;
                    $pendientes[$i] = null;
                    break;
                }
            }
        }
        if ($coincidenCantidad > 0) {
            $score += 35 * $coincidenCantidad / $nDev;
            $motivos[] = $coincidenCantidad . '/' . $nDev . ' cantidades coinciden';
        }

        // Códigos: solo aportan cuando el proveedor usa el mismo código (EAN).
        $codigosNc = [];
        foreach ($lineasNc as $l) {
            $c = preg_replace('/\D+/', '', (string) ($l['codigo_comercial'] ?? ''));
            if ($c !== '') {
                $codigosNc[$c] = true;
            }
        }
        $coincidenCodigo = 0;
        foreach ($lineasDev as $l) {
            $c = preg_replace('/\D+/', '', (string) ($l['codigo'] ?? ''));
            if ($c !== '' && isset($codigosNc[$c])) {
                $coincidenCodigo++;
            }
        }
        if ($coincidenCodigo > 0) {
            $score += 20 * $coincidenCodigo / $nDev;
            $motivos[] = $coincidenCodigo . ' código(s) idéntico(s)';
        }

        // Nombres: promedio de la mejor similitud por línea (señal débil).
        $sumaSimilitud = 0.0;
        foreach ($lineasDev as $l) {
            $mejor = 0.0;
            foreach ($lineasNc as $ln) {
                $s = (float) FacturaMatcher::similaridadTexto(
                    (string) ($l['nombre'] ?? ''),
                    (string) ($ln['detalle'] ?? '')
                );
                if ($s > $mejor) {
                    $mejor = $s;
                }
            }
            $sumaSimilitud += $mejor;
        }
        $promedioSimilitud = $sumaSimilitud / max(1, $nDev);
        if ($promedioSimilitud >= 40) {
            $score += 10 * min(1, $promedioSimilitud / 100);
            $motivos[] = 'nombres ~' . round($promedioSimilitud) . '%';
        }

        return ['score' => (int) round($score), 'motivo' => implode(', ', $motivos)];
    }

    /** Resuelve el proveedor local por nombre del ERP (una sola vez). */
    private static function resolverProveedor(array &$dev, Devolucion $modelo, array $contexto)
    {
        if (!empty($dev['proveedor_id']) || trim((string) $dev['proveedor_nombre_erp']) === '') {
            return;
        }

        $proveedores = $contexto['proveedores'] ?? $modelo->proveedoresActivos();
        foreach ($proveedores as $p) {
            $coincide = FacturaMatcher::mismoProveedor(
                (string) $dev['proveedor_nombre_erp'],
                (string) $p['razon_social']
            );
            if (!$coincide && !empty($p['alias'])) {
                $coincide = FacturaMatcher::mismoProveedor(
                    (string) $dev['proveedor_nombre_erp'],
                    (string) $p['alias']
                );
            }
            if ($coincide) {
                $modelo->asignarProveedor((int) $dev['id'], (int) $p['id']);
                $dev['proveedor_id'] = (int) $p['id'];
                return;
            }
        }
    }

    /** Ubica la factura de la boleta por su consecutivo (una sola vez). */
    private static function resolverFactura(array &$dev, Devolucion $modelo)
    {
        if ($dev['tipo'] !== 'boleta_local' || !empty($dev['factura_xml_id'])) {
            return;
        }
        $factura = $modelo->facturaPorConsecutivo((string) $dev['numero_factura']);
        if ($factura !== null) {
            $modelo->asignarFactura((int) $dev['id'], (int) $factura['id']);
            $dev['factura_xml_id'] = (int) $factura['id'];
            $dev['factura_clave'] = (string) $factura['clave'];
            // La factura confirma el proveedor mejor que el nombre del ERP.
            if (empty($dev['proveedor_id']) && !empty($factura['proveedor_id'])) {
                $modelo->asignarProveedor((int) $dev['id'], (int) $factura['proveedor_id']);
                $dev['proveedor_id'] = (int) $factura['proveedor_id'];
            }
        }
    }

    public static function estadoGlobal($totalObjetivos, $confirmados)
    {
        if ($totalObjetivos === 0) {
            return 'verificada'; // nada que acreditar (montos en cero)
        }
        if ($confirmados >= $totalObjetivos) {
            return 'verificada';
        }
        return $confirmados > 0 ? 'parcial' : 'sin_nc';
    }
}
