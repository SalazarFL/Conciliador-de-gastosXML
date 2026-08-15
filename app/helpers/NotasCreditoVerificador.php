<?php
/**
 * Cruce conservador entre las filas del reporte y los XML de tipo NC.
 */
require_once __DIR__ . '/FacturaMatcher.php';
require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/ClaseNotaCredito.php';

class NotasCreditoVerificador
{
    public static function verificarListado($listadoId, $modelo, $origen = 'automatico', array $soloLineas = [])
    {
        @set_time_limit(120);

        $transaccionPropia = method_exists($modelo, 'inTransaction')
            && method_exists($modelo, 'begin')
            && !$modelo->inTransaction();
        if ($transaccionPropia) {
            $modelo->begin();
        }

        try {
            // La decisión de qué XML está libre debe tomarse dentro de la
            // misma transacción que lo asigna. El bloqueo del encabezado
            // serializa verificaciones concurrentes del mismo acumulado.
            if (method_exists($modelo, 'bloquearListado')) {
                $modelo->bloquearListado((int) $listadoId);
            }

            $listado = $modelo->getListado((int) $listadoId);
            if ($listado === null) {
                throw new Exception('El listado de notas de crédito no existe.');
            }

            $todasLasLineas = $modelo->getLineasParaMatching((int) $listadoId);
            $idsObjetivo = array_fill_keys(array_values(array_unique(array_filter(array_map('intval', $soloLineas)))), true);
            $incremental = !empty($idsObjetivo);
            $lineas = $incremental
                ? array_values(array_filter($todasLasLineas, function ($linea) use ($idsObjetivo) {
                    return isset($idsObjetivo[(int) $linea['id']]);
                }))
                : $todasLasLineas;
            if ($incremental && !$lineas) {
                if ($transaccionPropia) {
                    $modelo->commit();
                }
                return ['coincide' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
            }

            $facturas = $modelo->getFacturasNcSociedad((string) $listado['sociedad_cedula']);
            $referencias = method_exists($modelo, 'getReferenciasNcSociedad')
                ? $modelo->getReferenciasNcSociedad((string) $listado['sociedad_cedula'])
                : [];
            $indice = self::indexarFacturas($facturas, $referencias);
            $usadas = [];
            $pendientes = [];
            $historial = [];
            $preservadas = [];
            $stats = ['coincide' => 0, 'con_diferencia' => 0, 'sin_respaldo' => 0];
            $verificacionId = null;
            $cantidadCambios = 0;

            if (method_exists($modelo, 'iniciarVerificacion')) {
                $verificacionId = $modelo->iniciarVerificacion((int) $listadoId, (string) $origen);
            }
            if (!$incremental && method_exists($modelo, 'limpiarMatchesAutomaticos')) {
                $modelo->limpiarMatchesAutomaticos((int) $listadoId);
            }

            if ($incremental) {
                // Una carga del ERP no cambia la identidad ni el monto: solo
                // el saldo. Por eso los vínculos ya establecidos se reservan
                // tal cual y únicamente se buscan XML para las filas nuevas o
                // recuperadas que aún no tienen uno. Así una actualización de
                // saldos no reescribe decisiones de matching anteriores.
                foreach ($todasLasLineas as $linea) {
                    if (empty($linea['factura_xml_id'])) {
                        continue;
                    }
                    $usadas[(int) $linea['factura_xml_id']] = true;
                    if (isset($idsObjetivo[(int) $linea['id']])) {
                        $preservadas[(int) $linea['id']] = true;
                        $estado = (string) $linea['estado'];
                        if (isset($stats[$estado])) {
                            $stats[$estado]++;
                        }
                    }
                }
            } else {
                // Un vínculo manual es una decisión explícita, incluso cuando
                // fue confirmado con diferencia de monto. Siempre se reserva
                // antes de repartir los XML automáticamente.
                foreach ($lineas as $linea) {
                    if (!empty($linea['match_manual']) && !empty($linea['factura_xml_id'])) {
                        $usadas[(int) $linea['factura_xml_id']] = true;
                        $estado = (string) $linea['estado'];
                        if (isset($stats[$estado])) {
                            $stats[$estado]++;
                        }
                    }
                }
            }

            // Resolver una línea contra las notas que queden libres. Se usa en
            // dos pasadas y por eso vive acá, con el estado compartido.
            $resolver = function (array $linea, $permitirReferenciaSinMonto)
                use ($modelo, $facturas, $indice, &$usadas, &$pendientes, &$historial, &$stats,
                     &$cantidadCambios, $verificacionId) {
                $match = self::buscarMatch($linea, $facturas, $usadas, $indice, $permitirReferenciaSinMonto);
                if (!empty($match['diferir'])) {
                    return false;
                }

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
                    return true;
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
                return true;
            };

            // Las que solo se sostienen por la referencia esperan a la segunda
            // pasada: una nota consolidada cita varias facturas y solo puede
            // respaldar una línea. Si se reparte por orden de id, la primera
            // que la cite se la lleva y deja sin respaldo a la línea cuyo monto
            // sí cuadraba —pasó de verdad con una NC de SIGMA que acredita tres
            // facturas—. Repartiendo primero las coincidencias de monto, la
            // referencia se queda solo con lo que nadie reclamó.
            $diferidas = [];

            foreach ($lineas as $linea) {
                if (isset($preservadas[(int) $linea['id']])) {
                    continue;
                }
                if (!empty($linea['match_manual'])
                    && !empty($linea['factura_xml_id'])) {
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

                // Una nota de ajuste interno casi nunca tiene documento
                // electrónico: de las 478 que traen número de NC del proveedor,
                // solo 7 existen como XML (1.5%), contra 85% de las directas y
                // 92% de las de cambio. Emparejarlas por proveedor y monto
                // producía 217 vínculos falsos que además dejaban sin respaldo
                // a la línea dueña de ese XML.
                //
                // Se salta solo cuando NO hay consecutivo que comprobar. Con
                // consecutivo se deja pasar: buscarMatch() exige entonces
                // coincidencia exacta de consecutivo, proveedor y monto, y con
                // esas tres no queda margen para un falso positivo.
                if (self::esAjuste($linea) && self::digits($linea['nc_proveedor'] ?? '') === '') {
                    $nuevo = [
                        'factura_xml_id' => null,
                        'estado' => 'sin_respaldo',
                        'diferencia' => null,
                        'motivo_match' => 'Nota de ajuste interno: no lleva documento electrónico.',
                    ];
                    $pendientes[] = self::filaMatch((int) $linea['id'], null, $nuevo['estado'], null,
                        'ninguno', null, false, $nuevo['motivo_match'], false);
                    $cantidadCambios += self::registrarCambio($modelo, $verificacionId, $linea, $nuevo, $historial);
                    $stats['sin_respaldo']++;
                    continue;
                }

                if (!$resolver($linea, false)) {
                    $diferidas[] = $linea;
                }
            }

            foreach ($diferidas as $linea) {
                $resolver($linea, true);
            }

            if ($incremental && $pendientes) {
                $anteriores = [];
                foreach ($todasLasLineas as $linea) {
                    $anteriores[(int) $linea['id']] = $linea;
                }
                $pendientes = array_values(array_filter(
                    $pendientes,
                    function ($fila) use ($anteriores) {
                        $id = (int) $fila['id'];
                        return !isset($anteriores[$id])
                            || !self::matchSinCambios($anteriores[$id], $fila);
                    }
                ));
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

    /**
     * ¿Es una nota de ajuste interno, de las que nunca llevan XML?
     *
     * Se prefiere la clase ya guardada en la línea; si no viene —listados
     * cargados antes de existir la columna, o dobles de prueba— se deduce del
     * número, que es de donde salió en primer lugar.
     */
    private static function esAjuste(array $linea)
    {
        if (!class_exists('ClaseNotaCredito')) {
            return false;
        }
        $clase = (string) ($linea['clase'] ?? '');
        if ($clase === '') {
            $clase = ClaseNotaCredito::clasificar($linea['documento'] ?? '');
        }
        return $clase === ClaseNotaCredito::AJUSTE;
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
            // La columna es VARCHAR(255) y los motivos con pistas de cercanía
            // llegan a rozarlo: se recorta acá y no en cada texto.
            'motivo_match'       => $motivo ? mb_substr((string) $motivo, 0, 255, 'UTF-8') : null,
        ];
    }

    /** Una recarga idéntica no debe tocar ni siquiera la metadata de matching. */
    private static function matchSinCambios(array $anterior, array $nuevo)
    {
        $xmlAnterior = !empty($anterior['factura_xml_id']) ? (int) $anterior['factura_xml_id'] : null;
        $xmlNuevo = !empty($nuevo['factura_xml_id']) ? (int) $nuevo['factura_xml_id'] : null;
        $diferenciaAnterior = ($anterior['diferencia'] ?? null) === null
            ? null
            : number_format(round((float) $anterior['diferencia'], 2), 2, '.', '');
        $diferenciaNueva = ($nuevo['diferencia'] ?? null) === null
            ? null
            : number_format(round((float) $nuevo['diferencia'], 2), 2, '.', '');
        $scoreAnterior = ($anterior['score_proveedor'] ?? null) === null
            ? null
            : number_format(round((float) $anterior['score_proveedor'], 1), 1, '.', '');
        $scoreNuevo = ($nuevo['score_proveedor'] ?? null) === null
            ? null
            : number_format(round((float) $nuevo['score_proveedor'], 1), 1, '.', '');
        $motivoAnterior = trim((string) ($anterior['motivo_match'] ?? '')) ?: null;
        $motivoNuevo = trim((string) ($nuevo['motivo_match'] ?? '')) ?: null;

        return $xmlAnterior === $xmlNuevo
            && (string) ($anterior['estado'] ?? 'sin_respaldo') === (string) $nuevo['estado']
            && $diferenciaAnterior === $diferenciaNueva
            && (string) ($anterior['metodo_match'] ?? 'ninguno') === (string) $nuevo['metodo_match']
            && $scoreAnterior === $scoreNuevo
            && (int) !empty($anterior['match_manual']) === (int) !empty($nuevo['match_manual'])
            && (int) !empty($anterior['bloqueo_automatico']) === (int) !empty($nuevo['bloqueo_automatico'])
            && $motivoAnterior === $motivoNuevo;
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
    private static function indexarFacturas(array $facturas, array $referencias = [])
    {
        $indice = ['consecutivo' => [], 'ocho' => [], 'referencia' => []];
        $posicionPorId = [];
        foreach ($facturas as $posicion => $factura) {
            $posicionPorId[(int) $factura['id']] = $posicion;
            $consecutivo = self::digits((string) ($factura['consecutivo_completo'] ?? ''));
            if ($consecutivo !== '') {
                $indice['consecutivo'][$consecutivo][] = $posicion;
            }
            $ocho = NumeroFactura::xmlOchoDigitos($factura['numero_factura_asistente'] ?? '');
            if ((string) $ocho !== '') {
                $indice['ocho'][(string) $ocho][] = $posicion;
            }
        }

        // Qué factura acredita cada NC, por el consecutivo citado en su XML.
        // Una misma nota puede citar varias facturas y una factura puede tener
        // varias notas: el índice guarda todas las combinaciones y quien busca
        // decide si hay una sola candidata razonable.
        foreach ($referencias as $referencia) {
            $posicion = $posicionPorId[(int) ($referencia['factura_xml_id'] ?? 0)] ?? null;
            if ($posicion === null) {
                continue;
            }
            $consecutivo = self::consecutivoReferenciado($referencia);
            if ($consecutivo === '') {
                continue;
            }
            if (!in_array($posicion, $indice['referencia'][$consecutivo] ?? [], true)) {
                $indice['referencia'][$consecutivo][] = $posicion;
            }
        }

        return $indice;
    }

    /**
     * El consecutivo de 20 dígitos de la factura que una referencia cita.
     *
     * El parser ya lo extrae cuando el proveedor escribió la clave de 50
     * dígitos, que es lo normal (1 208 de 1 500 referencias medidas). Los que
     * ponen el consecutivo pelado —31 casos— también se aceptan: son 20
     * dígitos y no hay nada que interpretar. Cualquier otra cosa ("BOLETA #
     * D6039", "SIN DOCUMENTO DE REFERENCIA", números de 4 o 10 dígitos) se
     * descarta: adivinar ahí es inventar un vínculo.
     */
    public static function consecutivoReferenciado(array $referencia)
    {
        $consecutivo = self::digits((string) ($referencia['consecutivo_ref'] ?? ''));
        if (strlen($consecutivo) === 20) {
            return $consecutivo;
        }
        $numero = self::digits((string) ($referencia['numero_ref'] ?? ''));
        if (strlen($numero) === 50) {
            return substr($numero, 21, 20);
        }
        return strlen($numero) === 20 ? $numero : '';
    }

    /**
     * El consecutivo de la factura que una línea directa está acreditando.
     *
     * En el reporte del ERP una nota directa se numera con el consecutivo de
     * la FACTURA corregida, no con el suyo (NC- 17-1-00100001010000012473-684).
     * Ese número de 20 dígitos es la otra punta del puente.
     *
     * Solo para directas: en las demás clases el número largo, cuando existe,
     * no significa lo mismo.
     */
    public static function consecutivoFacturaDirecta(array $linea)
    {
        $clase = (string) ($linea['clase'] ?? '');
        if ($clase === '') {
            $clase = ClaseNotaCredito::clasificar($linea['documento'] ?? '');
        }
        if ($clase !== ClaseNotaCredito::DIRECTA) {
            return '';
        }

        $documento = preg_replace('/\s+/', '', (string) ($linea['documento'] ?? ''));
        return preg_match('/\d{20}/', (string) $documento, $m) ? $m[0] : '';
    }

    private static function buscarMatch(
        array $linea,
        array $facturas,
        array $usadas,
        ?array $indice = null,
        $permitirReferenciaSinMonto = true
    ) {
        $numeroProveedor = self::digits((string) ($linea['nc_proveedor'] ?? ''));

        // Puente por referencia, solo para las notas directas: el reporte las
        // numera con el consecutivo de la factura corregida y el XML de la
        // nota cita esa misma factura. Se usa cuando el proveedor no dio el
        // número de su NC; si lo dio, ese consecutivo identifica la nota de
        // forma más directa y sigue mandando.
        //
        // La referencia MARCA candidatas, no reduce el universo. Restringir la
        // búsqueda a las notas que citan la factura parecía lo natural y salió
        // caro: probado contra los datos reales, dos líneas que ya estaban
        // bien resueltas por monto exacto se rompieron, porque la nota
        // referenciada tapaba a la que de verdad cuadraba. Marcando, la
        // referencia solo agrega certeza: desempata y explica, nunca desplaza
        // una coincidencia de monto.
        $consecutivoFactura = $numeroProveedor === ''
            ? self::consecutivoFacturaDirecta($linea)
            : '';
        $idsReferenciados = [];
        if ($consecutivoFactura !== '' && $indice !== null) {
            foreach ($indice['referencia'][$consecutivoFactura] ?? [] as $posicion) {
                $idsReferenciados[(int) $facturas[$posicion]['id']] = true;
            }
        }
        $hayReferencia = !empty($idsReferenciados);

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
            $factura['_referenciada'] = isset($idsReferenciados[(int) $factura['id']]);
            $candidatas[] = $factura;
        }

        if (empty($candidatas)) {
            if ($hayReferencia) {
                return [
                    'factura' => null,
                    'metodo' => 'ninguno',
                    'score' => null,
                    'motivo' => 'La nota que cita esta factura es de otro proveedor; requiere revisión manual.',
                ];
            }
            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => null,
                'motivo' => $numeroProveedor !== ''
                    ? 'El XML con el consecutivo de NC Proveedor no corresponde al mismo proveedor.'
                    : 'No hay XML disponibles del mismo proveedor y moneda.',
            ];
        }

        $referenciadas = array_values(array_filter($candidatas, function ($factura) {
            return !empty($factura['_referenciada']);
        }));
        $candidatasExactas = array_values(array_filter($candidatas, function ($factura) use ($linea) {
            return self::montosCoinciden($linea['monto'], $factura['total']);
        }));

        if (empty($candidatasExactas)) {
            usort($candidatas, function ($a, $b) {
                return $a['_diferencia_monto'] <=> $b['_diferencia_monto'];
            });

            // Ninguna nota del proveedor trae el monto del reporte, pero una
            // dice en su propio XML que acredita justamente esta factura, y el
            // reporte numera la línea con esa misma factura. Con las dos
            // puntas apuntando al mismo sitio la identidad ya está probada: el
            // monto deja de decidir si es la nota y solo dice si cuadra. Se
            // vincula y queda 'con_diferencia', que es donde se revisa.
            // Medido sobre los datos reales son 36 líneas de un listado que
            // antes quedaban sin respaldo y sin una sola pista; varias son
            // diferencias de un céntimo.
            if (count($referenciadas) === 1) {
                if (!$permitirReferenciaSinMonto) {
                    return ['factura' => null, 'metodo' => 'ninguno', 'score' => null,
                            'motivo' => '', 'diferir' => true];
                }
                return [
                    'factura' => $referenciadas[0],
                    'metodo' => 'referencia',
                    'score' => 100.0,
                    'motivo' => 'La nota cita esta factura en su XML y es del mismo proveedor, '
                        . 'pero el monto no coincide (diferencia '
                        . number_format((float) $referenciadas[0]['_diferencia_monto'], 2, '.', ',') . ').',
                ];
            }

            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => 100.0,
                'motivo' => count($referenciadas) > 1
                    ? 'Hay ' . count($referenciadas) . ' notas suyas que citan esta factura y ninguna '
                        . 'trae el monto del reporte; requiere vinculación manual.'
                    : self::motivoConCercania($linea, $candidatas, $numeroProveedor),
            ];
        }

        if (count($candidatasExactas) > 1) {
            // Varias notas del proveedor con el monto exacto: antes se
            // abandonaba siempre. Si una sola de ellas cita la factura de la
            // línea, esa es —y la referencia es justo lo que faltaba para
            // distinguirlas.
            $exactasReferenciadas = array_values(array_filter($candidatasExactas, function ($factura) {
                return !empty($factura['_referenciada']);
            }));
            if (count($exactasReferenciadas) === 1) {
                return [
                    'factura' => $exactasReferenciadas[0],
                    'metodo' => 'referencia',
                    'score' => 100.0,
                    'motivo' => 'Varias notas del proveedor traen este monto; solo esta cita la factura en su XML.',
                ];
            }
            return [
                'factura' => null,
                'metodo' => 'ninguno',
                'score' => 100.0,
                'motivo' => 'Hay varios XML del mismo proveedor con el monto exacto; requiere vinculación manual.',
            ];
        }

        $elegida = $candidatasExactas[0];
        if (!empty($elegida['_referenciada'])) {
            return [
                'factura' => $elegida,
                'metodo' => 'referencia',
                'score' => 100.0,
                'motivo' => 'La nota cita esta factura en su XML; coinciden proveedor y monto.',
            ];
        }
        return [
            'factura' => $elegida,
            'metodo' => $numeroProveedor !== '' ? 'numero' : 'atributos',
            'score' => 100.0,
            'motivo' => $numeroProveedor !== ''
                ? self::motivoNumeroProveedor($elegida)
                : 'Coincidencia por mismo proveedor y monto exacto.',
        ];
    }

    /** Ventana en días dentro de la que una NC del mismo proveedor se sugiere. */
    public const DIAS_CERCANIA = 30;

    /** Cuántas notas cercanas hacen que la pista deje de servir de pista. */
    public const MAX_CERCANAS = 5;

    /**
     * Motivo de una línea sin monto exacto, con la pista de cercanía de fecha.
     *
     * Sin referencia utilizable no queda con qué identificar la nota, así que
     * lo único honesto es señalar a mano dónde mirar. Se dice cuántas notas
     * del mismo proveedor hay alrededor de la fecha y cuál es la más cercana;
     * elegir sigue siendo de la persona, y por eso nunca se vincula sola.
     *
     * Por encima de MAX_CERCANAS la pista no ayuda —medido sobre datos reales,
     * casi un cuarto de las directas tiene más de diez notas del proveedor en
     * la ventana—, así que se dice el número y nada más.
     *
     * El motivo se guarda en una columna de 255: por eso es corto y no lista
     * consecutivos. Para elegir está el buscador de candidatas.
     */
    private static function motivoConCercania(array $linea, array $candidatas, $numeroProveedor)
    {
        $base = self::motivoMontoNoExacto($candidatas[0], $numeroProveedor);
        if (self::consecutivoFacturaDirecta($linea) === '') {
            return $base;
        }

        $fechaLinea = strtotime((string) ($linea['fecha'] ?? ''));
        if ($fechaLinea === false) {
            return $base;
        }

        $cercanas = [];
        foreach ($candidatas as $candidata) {
            $fechaNc = strtotime((string) ($candidata['fecha_emision'] ?? ''));
            if ($fechaNc === false) {
                continue;
            }
            $dias = (int) round(abs($fechaNc - $fechaLinea) / 86400);
            if ($dias <= self::DIAS_CERCANIA) {
                $cercanas[] = ['dias' => $dias, 'factura' => $candidata];
            }
        }

        if (!$cercanas) {
            return $base . ' Tampoco hay notas suyas cerca de la fecha.';
        }
        if (count($cercanas) > self::MAX_CERCANAS) {
            return $base . ' Hay ' . count($cercanas) . ' notas suyas dentro de los '
                . self::DIAS_CERCANIA . ' días; elegí a mano.';
        }

        usort($cercanas, function ($a, $b) {
            return $a['dias'] <=> $b['dias'];
        });
        $masCercana = $cercanas[0]['factura'];
        return $base . ' ' . count($cercanas) . ' nota(s) suyas cerca de la fecha; la más próxima es '
            . self::etiquetaNc($masCercana) . ' (' . $cercanas[0]['dias'] . ' días). Revisión manual.';
    }

    /** Cómo se nombra una NC dentro de un motivo, sin gastar los 255 caracteres. */
    private static function etiquetaNc(array $factura)
    {
        $numero = trim((string) ($factura['consecutivo_completo'] ?? ''));
        if ($numero === '') {
            $numero = (string) NumeroFactura::xmlOchoDigitos($factura['numero_factura_asistente'] ?? '');
        }
        return ($numero !== '' ? $numero : '#' . (int) $factura['id'])
            . ' por ' . number_format((float) $factura['total'], 2, '.', ',');
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
