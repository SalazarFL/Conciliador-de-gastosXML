<?php
/**
 * Compara un archivo de pago contra las lineas guardadas de una semana.
 *
 * Es deliberadamente de solo lectura: clasifica y describe diferencias,
 * pero nunca crea, actualiza ni elimina facturas del listado existente.
 */

require_once __DIR__ . '/FacturaMatcher.php';

class PorPagarComparador
{
    /** Tope defensivo para evitar comparaciones abusivamente grandes. */
    public const MAX_LINEAS = 10000;

    /**
     * @param array $actuales  Lineas de porpagar_facturas (proveedor_texto).
     * @param array $entrantes Lineas ya leidas del CSV/XLSX (proveedor).
     * @return array Lineas clasificadas y resumen por estado.
     */
    public static function comparar(array $actuales, array $entrantes)
    {
        if (count($actuales) > self::MAX_LINEAS || count($entrantes) > self::MAX_LINEAS) {
            throw new InvalidArgumentException(
                'El comparador admite hasta ' . number_format(self::MAX_LINEAS, 0, '.', ',') . ' facturas por listado.'
            );
        }

        $actuales = array_values(array_map([self::class, 'normalizarActual'], $actuales));
        $indiceActuales = self::indexarActuales($actuales);
        $usadas = [];
        $clavesEntrantes = [];
        $lineas = [];

        foreach ($entrantes as $entrante) {
            if (($entrante['estado'] ?? '') === 'error') {
                $lineas[] = $entrante;
                continue;
            }

            $nueva = self::normalizarEntrante($entrante);
            $claveEntrante = self::claveDocumento($nueva);
            if (isset($clavesEntrantes[$claveEntrante])) {
                $nueva['estado'] = 'duplicada';
                $nueva['motivo'] = 'La factura viene repetida dentro del archivo nuevo.';
                $nueva['cambios'] = [];
                $lineas[] = $nueva;
                continue;
            }
            $clavesEntrantes[$claveEntrante] = true;

            $indiceActual = self::buscarActual($nueva, $actuales, $usadas, $indiceActuales);
            if ($indiceActual === null) {
                $nueva['estado'] = 'nueva';
                $nueva['motivo'] = 'No existe en el listado actual de la semana.';
                $nueva['cambios'] = [];
                $lineas[] = $nueva;
                continue;
            }

            $usadas[$indiceActual] = true;
            $actual = $actuales[$indiceActual];
            $cambios = self::detectarCambios($actual, $nueva);
            $nueva['estado'] = empty($cambios) ? 'igual' : 'modificada';
            $nueva['motivo'] = empty($cambios)
                ? 'Coincide con el listado actual.'
                : 'Cambios: ' . implode(', ', array_keys($cambios)) . '.';
            $nueva['cambios'] = $cambios;
            $nueva['anterior'] = self::datosPublicos($actual);
            $lineas[] = $nueva;
        }

        // Lo que sigue en la semana pero no vino en el archivo se informa;
        // no se interpreta como una orden de borrado.
        foreach ($actuales as $indice => $actual) {
            if (isset($usadas[$indice])) {
                continue;
            }
            $faltante = self::datosPublicos($actual);
            $faltante['estado'] = 'faltante';
            $faltante['motivo'] = 'Existe en la semana, pero no aparece en el archivo nuevo. No se eliminará.';
            $faltante['cambios'] = [];
            $faltante['anterior'] = self::datosPublicos($actual);
            $lineas[] = $faltante;
        }

        $resumen = array_fill_keys(['nueva', 'modificada', 'igual', 'faltante', 'duplicada', 'error'], 0);
        foreach ($lineas as $linea) {
            $estado = (string) ($linea['estado'] ?? 'error');
            if (isset($resumen[$estado])) {
                $resumen[$estado]++;
            }
        }

        return ['lineas' => $lineas, 'resumen' => $resumen];
    }

    private static function normalizarActual(array $linea)
    {
        return [
            'id' => isset($linea['id']) ? (int) $linea['id'] : null,
            'fecha' => self::fecha($linea['fecha'] ?? null),
            'numero' => trim((string) ($linea['numero'] ?? '')),
            'proveedor' => trim((string) ($linea['proveedor_texto'] ?? ($linea['proveedor'] ?? ''))),
            'total' => round((float) ($linea['total'] ?? 0), 2),
        ];
    }

    private static function normalizarEntrante(array $linea)
    {
        return [
            'fecha' => self::fecha($linea['fecha'] ?? null),
            'numero' => trim((string) ($linea['numero'] ?? '')),
            'proveedor' => trim((string) ($linea['proveedor'] ?? ($linea['proveedor_texto'] ?? ''))),
            'total' => round((float) ($linea['total'] ?? 0), 2),
        ];
    }

    private static function datosPublicos(array $linea)
    {
        return [
            'fecha' => $linea['fecha'],
            'numero' => $linea['numero'],
            'proveedor' => $linea['proveedor'],
            'total' => $linea['total'],
        ];
    }

    /** Elige la coincidencia de identidad mas clara entre las no usadas. */
    private static function buscarActual(array $nueva, array $actuales, array $usadas, array $indiceActuales)
    {
        $mejorIndice = null;
        $mejorPuntaje = -1;
        $empates = 0;
        $candidatos = [];

        foreach (self::clavesNumero($nueva['numero']) as $claveNumero) {
            foreach ($indiceActuales[$claveNumero] ?? [] as $indice) {
                $candidatos[$indice] = true;
            }
        }
        if (empty($candidatos)) {
            return null;
        }

        foreach (array_keys($candidatos) as $indice) {
            if (isset($usadas[$indice])) {
                continue;
            }
            $actual = $actuales[$indice];

            $scoreNumero = FacturaMatcher::similaridadNumero($nueva['numero'], $actual['numero']);
            if ($scoreNumero < FacturaMatcher::NUMERO_RESCATE) {
                continue;
            }

            $numeroExacto = self::texto($nueva['numero']) === self::texto($actual['numero']);
            $proveedorExacto = self::texto($nueva['proveedor']) === self::texto($actual['proveedor']);
            $proveedorEstricto = FacturaMatcher::mismoProveedor($nueva['proveedor'], $actual['proveedor']);
            $proveedorRelacionado = $proveedorExacto || $proveedorEstricto
                || self::unoEmpiezaIgualQueElOtro($nueva['proveedor'], $actual['proveedor']);
            $mismoTotal = abs($nueva['total'] - $actual['total']) < 0.005;
            $mismaFecha = $nueva['fecha'] !== null && $nueva['fecha'] === $actual['fecha'];

            // El numero no es global entre proveedores: aun cuando numero,
            // monto o fecha coincidan, el proveedor debe seguir siendo el
            // mismo o una variante textual muy clara del nombre anterior.
            if (!$proveedorRelacionado) {
                continue;
            }

            $puntaje = ($numeroExacto ? 1000 : $scoreNumero)
                + ($proveedorExacto ? 200 : 120)
                + ($mismoTotal ? 40 : 0)
                + ($mismaFecha ? 5 : 0);

            if ($puntaje > $mejorPuntaje) {
                $mejorIndice = $indice;
                $mejorPuntaje = $puntaje;
                $empates = 1;
            } elseif ($puntaje === $mejorPuntaje) {
                $empates++;
            }
        }

        // Evita asociar al azar si dos proveedores tienen el mismo numero
        // y ninguna otra caracteristica permite distinguirlos.
        return $empates === 1 ? $mejorIndice : null;
    }

    /**
     * ¿Un nombre es el otro con algo pegado al final?
     *
     * No es laxitud gratuita: son dos cosas que pasan de verdad en estos
     * listados. Una, el reporte se anota a mano y durante meses esas notas
     * entraron pegadas al nombre ("MARJAVA SUPERMERCADOS S.A YA DESCARGADO Y
     * REVISADO" quedó así en 82 renglones de la semana 130826). Dos, el ERP
     * corta el nombre a lo ancho de la columna, así que el mismo proveedor
     * aparece entero en un archivo y truncado en otro.
     *
     * Sin esto, limpiar la lectura vuelve "nuevas" a las facturas que ya
     * estaban, que es peor que el problema que se quería resolver.
     *
     * Se exigen al menos dos palabras en común para que no baste con que dos
     * proveedores compartan la primera. El número ya tuvo que parecerse antes
     * de llegar aquí, así que esto no empareja nada por sí solo.
     */
    private static function unoEmpiezaIgualQueElOtro($a, $b)
    {
        $tokensA = FacturaMatcher::tokenizarProveedor($a);
        $tokensB = FacturaMatcher::tokenizarProveedor($b);

        $corto = count($tokensA) <= count($tokensB) ? $tokensA : $tokensB;
        $largo = $corto === $tokensA ? $tokensB : $tokensA;

        if (count($corto) < 2 || count($corto) === count($largo)) {
            return false;
        }

        return array_slice($largo, 0, count($corto)) === $corto;
    }

    private static function indexarActuales(array $actuales)
    {
        $indice = [];
        foreach ($actuales as $posicion => $actual) {
            foreach (self::clavesNumero($actual['numero']) as $clave) {
                $indice[$clave][] = $posicion;
            }
        }
        return $indice;
    }

    private static function clavesNumero($numero)
    {
        $claves = [];
        $normalizado = FacturaMatcher::normalizarNumero($numero);
        if ($normalizado !== '') {
            $claves[] = 'n:' . $normalizado;
        }
        $nucleo = FacturaMatcher::nucleoNumerico($numero);
        if ($nucleo !== '') {
            $claves[] = 'c:' . $nucleo;
            // El matcher admite que un consecutivo largo termine en el
            // numero corto con relleno de ceros. Indexamos esos sufijos para
            // conservar esa regla sin volver a una busqueda cuadratica.
            $largo = strlen($nucleo);
            for ($i = 0; $i < $largo - 3; $i++) {
                if ($nucleo[$i] !== '0') {
                    continue;
                }
                $sufijo = ltrim(substr($nucleo, $i + 1), '0');
                if (strlen($sufijo) >= 3) {
                    $claves[] = 'c:' . $sufijo;
                }
            }
        }
        // FacturaMatcher tambien reconoce cuando una secuencia numerica
        // completa aparece dentro de un formato con serie o consecutivo.
        foreach (FacturaMatcher::secuenciasNumericas($numero) as $secuencia) {
            $claves[] = 'c:' . $secuencia;
        }
        return array_values(array_unique($claves));
    }

    /** Numero fuerte + mismo proveedor: no confunde numeraciones recicladas. */
    private static function claveDocumento(array $linea)
    {
        // Conserva serie y prefijos alfabeticos: SERIE-A-100 y SERIE-B-100
        // pueden ser facturas distintas aunque compartan el nucleo 100.
        $numero = FacturaMatcher::normalizarNumero($linea['numero']);
        $tokens = FacturaMatcher::tokenizarProveedor($linea['proveedor']);
        sort($tokens, SORT_STRING);
        $proveedor = !empty($tokens) ? implode(' ', $tokens) : self::texto($linea['proveedor']);
        return $numero . '|' . $proveedor;
    }

    private static function detectarCambios(array $actual, array $nueva)
    {
        $cambios = [];
        if (self::texto($actual['numero']) !== self::texto($nueva['numero'])) {
            $cambios['número'] = ['anterior' => $actual['numero'], 'nuevo' => $nueva['numero']];
        }
        if (self::texto($actual['proveedor']) !== self::texto($nueva['proveedor'])) {
            $cambios['proveedor'] = ['anterior' => $actual['proveedor'], 'nuevo' => $nueva['proveedor']];
        }
        if ($actual['fecha'] !== $nueva['fecha']) {
            $cambios['fecha'] = ['anterior' => $actual['fecha'], 'nuevo' => $nueva['fecha']];
        }
        if (abs($actual['total'] - $nueva['total']) >= 0.005) {
            $cambios['total'] = ['anterior' => $actual['total'], 'nuevo' => $nueva['total']];
        }
        return $cambios;
    }

    private static function texto($valor)
    {
        $texto = mb_strtoupper(trim((string) $valor), 'UTF-8');
        return preg_replace('/\s+/', ' ', $texto);
    }

    private static function fecha($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;
    }
}
