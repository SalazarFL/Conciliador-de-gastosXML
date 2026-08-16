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
 * el XML lleva en `consecutivo_completo`.
 *
 * El consecutivo NO es único a nivel país. Durante un tiempo este archivo dijo
 * lo contrario y trataba la igualdad como prueba suficiente; es falso y costó
 * emparejamientos malos. El número lo arma el emisor:
 *
 *     001      00001      01       0000000144
 *     sucursal terminal   tipo     correlativo
 *
 * y el correlativo lo lleva cada proveedor por su cuenta empezando en 1, así
 * que la factura 144 de un emisor pequeño y la 144 de otro son el mismo
 * número. Lo único único a nivel país es la clave de 50 dígitos. Por eso la
 * igualdad de consecutivo propone, y la guarda de cédula dispone: si el emisor
 * del comprobante no es el proveedor de esa línea del ERP, se rechaza y se
 * sigue buscando en la lista, que es donde suele estar el bueno.
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
        // Los rechazos de la guarda se juntan aquí y se escriben al final: no
        // se toca la base dentro del bucle de comparaciones.
        $vetos = [];

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

            $xml = self::buscarXml($linea, $indice, $usadas, $vetos);
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

        // Anotar los rechazos y volver a deducir el mapa, en ese orden: los
        // vetos describen lo que pasó con el mapa que había, y la cosecha lee
        // los estados que `actualizarRespaldoLote` acaba de escribir.
        self::aprenderDelMapa($lineas, $vetos);

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
     * El consecutivo propone. Como se repite entre emisores, el índice puede
     * devolver varios candidatos y hay que preguntarle a la guarda de cédula
     * de quién es cada uno. Un candidato ajeno NO corta la búsqueda: se salta y
     * se sigue con el siguiente, porque en los casos reales el comprobante
     * correcto estaba en esa misma lista, más abajo, y se perdía solo porque
     * el otro tenía el `id` más bajo.
     *
     * Cuando el ERP no trae consecutivo se cae a la llave corta, y ahí manda el
     * proveedor: "FACT-12339" lo usan muchos emisores. La cédula, cuando el
     * mapa la conoce, vale más que el parecido del nombre —confirma identidad
     * en vez de estimarla— así que rescata los casos donde los nombres no se
     * parecen en nada.
     */
    private static function buscarXml(array $linea, array $indice, array $usadas, array &$vetos)
    {
        $codigo = trim((string) ($linea['proveedor_codigo'] ?? ''));
        $monto = (float) ($linea['monto'] ?? 0);

        $documento = trim((string) ($linea['documento'] ?? ''));
        if (preg_match('/^\d{20}$/', $documento)) {
            foreach ($indice['consecutivo'][$documento] ?? [] as $xml) {
                if (isset($usadas[(int) $xml['id']])) {
                    continue;
                }
                if (self::esAjeno($codigo, $linea, $xml, $monto, $vetos)) {
                    continue;
                }
                $xml['_score_numero'] = 100.0;
                $xml['_score_proveedor'] = self::scoreProveedor($codigo, $linea, $xml);
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
            if (self::esAjeno($codigo, $linea, $xml, $monto, $vetos)) {
                continue;
            }

            // La cédula confirmada gana sobre el texto: es identidad, no
            // parecido. Es lo que rescata a los proveedores cuyo nombre en el
            // ERP no se parece al del XML.
            $confirmado = self::veredicto($codigo, $xml['proveedor_id'] ?? 0) === 'propio';
            $score = $confirmado
                ? 100.0
                : FacturaMatcher::similaridadTexto($proveedor, (string) ($xml['proveedor_nombre'] ?? ''));
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

    /**
     * El veredicto del mapa, cargándolo solo si se puede.
     *
     * Este verificador también se usa suelto, sin la capa de modelos cargada
     * (pruebas, utilidades de línea de comandos). Ahí no hay base que
     * consultar, así que la guarda no opina y el emparejamiento se comporta
     * como antes de que existiera. Mismo criterio que usa FacturaMatcher con
     * los alias de proveedor: pedir el modelo desde arriba del archivo haría
     * fallar a quien solo quiere comparar números.
     */
    private static function veredicto($codigo, $proveedorIdXml)
    {
        if (!class_exists('ProveedorCodigoErp')) {
            $ruta = __DIR__ . '/../models/ProveedorCodigoErp.php';
            if (!class_exists('Model') || !is_file($ruta)) {
                return 'desconocido';
            }
            require_once $ruta;
        }
        return ProveedorCodigoErp::veredicto($codigo, $proveedorIdXml);
    }

    /**
     * ¿La guarda rechaza este comprobante para esta línea?
     *
     * Anota el rechazo de paso. Lleva el dato de si el monto cuadraba porque
     * es lo que distingue las dos causas posibles: cuando NO cuadra es una
     * colisión de consecutivo y el rechazo es rutina; cuando cuadra al colón
     * es la firma de un código que cambió de proveedor, y eso lo tiene que
     * mirar una persona.
     */
    private static function esAjeno($codigo, array $linea, array $xml, $monto, array &$vetos)
    {
        if (self::veredicto($codigo, $xml['proveedor_id'] ?? 0) !== 'ajeno') {
            return false;
        }

        $vetos[] = [
            'codigo'                 => $codigo,
            'proveedor_id_propuesto' => (int) ($xml['proveedor_id'] ?? 0),
            'factura_erp_id'         => (int) ($linea['id'] ?? 0),
            'factura_xml_id'         => (int) ($xml['id'] ?? 0),
            'monto_cuadraba'         => abs($monto - (float) ($xml['total'] ?? 0)) <= FacturaMatcher::TOLERANCIA_CRC,
        ];
        return true;
    }

    /**
     * Qué tan seguro es que el proveedor sea el mismo, de verdad.
     *
     * Antes esta vía escribía 100.0 fijo, sin comparar nada. La fila quedaba
     * diciendo "el proveedor coincidió perfectamente" cuando nadie había
     * mirado, y por eso los emparejamientos malos no se distinguían de los
     * buenos en ninguna pantalla. Ahora 100 significa una sola cosa: la cédula
     * del emisor confirma el código. Sin mapa, el número que se guarda es el
     * parecido de los nombres, que es lo único que realmente se sabe.
     */
    private static function scoreProveedor($codigo, array $linea, array $xml)
    {
        if (self::veredicto($codigo, $xml['proveedor_id'] ?? 0) === 'propio') {
            return 100.0;
        }
        return round(FacturaMatcher::similaridadTexto(
            (string) ($linea['proveedor_nombre'] ?? ''),
            (string) ($xml['proveedor_nombre'] ?? '')
        ), 1);
    }

    /**
     * Guarda lo aprendido en esta corrida: los rechazos y los contadores.
     *
     * La cosecha es un recálculo desde los emparejamientos, no un incremento.
     * Un pago se reverifica cada vez que alguien vincula algo a mano, y sumar
     * uno por corrida inflaría los contadores hasta volverlos mentira. Contando
     * desde la fuente, correr esto de más nunca hace daño.
     *
     * Nunca lanza: aprender no puede tumbar una verificación.
     */
    private static function aprenderDelMapa(array $lineas, array $vetos)
    {
        // Sin la capa de modelos no hay nada que aprender ni dónde guardarlo.
        if (!class_exists('ProveedorCodigoErp') || !class_exists('Model')) {
            return;
        }

        try {
            $mapa = new ProveedorCodigoErp();

            foreach ($vetos as $veto) {
                $mapa->registrarVeto($veto);
            }

            $codigos = [];
            foreach ($lineas as $linea) {
                $codigo = trim((string) ($linea['proveedor_codigo'] ?? ''));
                if ($codigo !== '') {
                    $codigos[$codigo] = true;
                }
            }
            if ($codigos) {
                $mapa->cosechar(array_keys($codigos));
            }
        } catch (Throwable $e) {
            // El emparejamiento ya quedó escrito; esto es lo que se aprende.
        }
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
     * Re-verifica los pagos que todavía tienen facturas sin respaldo.
     *
     * Es lo que corre cuando entra un XML por una vía que no sabe a qué pago
     * pertenece. Ya casi nunca hace falta: el importador enlaza el XML con su
     * factura del ERP en el momento, y con eso el pago queda al día. Se
     * conserva para lo que llegue por caminos viejos y para reparar a mano.
     *
     * Ningún pago queda fuera por antigüedad: el pago semanal no se cierra, así
     * que un XML que aparece tarde repara la semana a la que pertenece aunque
     * ya se haya pagado, y lo hace para todos los que miran la misma base.
     *
     * Nunca lanza: verificar no debe tumbar la operación que la disparó.
     */
    public static function verificarPendientes($erp, $facturas = null, $limite = 3)
    {
        $resueltas = 0;
        try {
            foreach ($erp->idsPagosConFaltantes($limite) as $id) {
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

    /** Re-verifica todos los pagos de una semana. */
    public static function verificarSemana($semanaId, $erp, $facturas = null)
    {
        $semanaId = (int) $semanaId;
        if ($semanaId <= 0) {
            return;
        }
        try {
            foreach ($erp->idsPagosDeSemana($semanaId) as $id) {
                self::verificarListado((int) $id, $erp, $facturas);
            }
        } catch (Throwable $e) {
            // Best effort: otra asignación o el botón manual la repiten.
        }
    }
}
