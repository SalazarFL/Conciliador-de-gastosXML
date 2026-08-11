<?php
/**
 * Similitud de números de factura y nombres de proveedor.
 *
 * Extraído del motor de conciliación (ConciliacionController) para que el
 * módulo "Facturas por pagar" use exactamente las mismas reglas: núcleo
 * numérico (la secuencia de dígitos más larga sin ceros a la izquierda),
 * consecutivos de Hacienda que terminan en el número corto, tokens de
 * proveedor con iniciales/abreviaturas y sin sufijos societarios.
 */

class FacturaMatcher
{
    /** Diferencia de monto tolerada por redondeo (colones). */
    public const TOLERANCIA_CRC = 1.00;

    /** Umbral mínimo de similitud de proveedor para considerar candidato. */
    public const UMBRAL_PROVEEDOR = 60;

    /** El número identifica la factura: solo matches fuertes (núcleo exacto
     *  = 100, incrustado en consecutivo = 95). */
    public const UMBRAL_NUMERO = 90;

    /** Rescate cuando el proveedor NO se parece (nombre comercial en el XML
     *  vs razón social en el listado, p. ej. "CENTRO DE PLASTICO DE PEREZ"
     *  vs "WILLIAM PEREZ QUESADA"): con número contundente (≥95) Y monto
     *  que cuadra al colón, la factura se acepta igual — esa combinación
     *  identifica mejor que cualquier nombre. */
    public const NUMERO_RESCATE = 95;

    /**
     * ¿La factura XML puede respaldar esta línea del listado? Mismas reglas
     * del matching de por-pagar: número fuerte y proveedor parecido, o
     * rescate (número contundente + monto al colón).
     *
     * $linea:   numero, proveedor_texto, total
     * $factura: numero_factura_asistente, consecutivo_completo, proveedor_nombre, total
     */
    public static function facturaRespaldaLinea(array $linea, array $factura)
    {
        $scoreNumero = max(
            self::similaridadNumero($linea['numero'], (string) $factura['numero_factura_asistente']),
            self::similaridadNumero($linea['numero'], (string) $factura['consecutivo_completo'])
        );
        if ($scoreNumero < self::UMBRAL_NUMERO) {
            return false;
        }

        $scoreProveedor = self::similaridadTexto(
            (string) $linea['proveedor_texto'],
            (string) $factura['proveedor_nombre']
        );
        if ($scoreProveedor >= self::UMBRAL_PROVEEDOR) {
            return true;
        }

        return $scoreNumero >= self::NUMERO_RESCATE
            && abs(round((float) $linea['total'] - (float) $factura['total'], 2)) <= self::TOLERANCIA_CRC;
    }

    /** Sufijos corporativos y stopwords para normalizar nombres de proveedor. */
    private const STOPWORDS_PROVEEDOR = [
        // Sufijos corporativos
        'SA', 'SAS', 'SRL', 'SL', 'SC', 'SCA',
        'SOCIEDAD', 'ANONIMA', 'SIMPLIFICADA',
        'LTDA', 'LIMITADA', 'LTD', 'LIMITED',
        'CIA', 'COMPANIA', 'COMPANY', 'CO',
        'INC', 'INCORPORATED', 'CORP', 'CORPORATION',
        'CV', 'LLC', 'GMBH', 'AG',
        // Conectores
        'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS',
        'Y', 'E', 'O', 'U',
    ];

    public static function similaridadNumero($a, $b)
    {
        $rawA = (string) $a;
        $rawB = (string) $b;

        $a = self::normalizarNumero($rawA);
        $b = self::normalizarNumero($rawB);

        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        // Núcleo numérico: la secuencia de dígitos más larga sin ceros a la izquierda.
        // Cubre formatos como "0000071176" vs "FACT-1-1-0000071176-360",
        // que representan la misma factura con relleno de ceros o prefijos/sufijos.
        $coreA = self::nucleoNumerico($rawA);
        $coreB = self::nucleoNumerico($rawB);
        if ($coreA !== '' && $coreA === $coreB) {
            return 100;
        }
        // El núcleo de uno aparece como secuencia completa en el otro
        // (ej. núcleo "71176" vs consecutivo "FACT-2-71176").
        if ($coreA !== '' && in_array($coreA, self::secuenciasNumericas($rawB), true)) {
            return 95;
        }
        if ($coreB !== '' && in_array($coreB, self::secuenciasNumericas($rawA), true)) {
            return 95;
        }

        // El número corto está incrustado al final del consecutivo largo con
        // relleno de ceros: "0000005061" vs "FACT-01400020010000005061-3"
        // (el consecutivo de Hacienda termina en el número de factura).
        if (self::nucleoTerminaEn($coreB, $coreA) || self::nucleoTerminaEn($coreA, $coreB)) {
            return 100;
        }

        // Para números cortos (≤6 chars), similar_text() infla el porcentaje.
        // Levenshtein: distancia 1 = posible typo, >1 = distinto.
        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen <= 6) {
            $dist = levenshtein($a, $b);
            return $dist === 1 ? 50 : 0;
        }

        similar_text($a, $b, $pct);
        return $pct;
    }

    /**
     * Secuencias de dígitos del texto, sin ceros a la izquierda.
     * Se ignoran secuencias de menos de 3 dígitos (serie/sucursal como "1"
     * o "36" generarían falsos positivos).
     */
    public static function secuenciasNumericas($value)
    {
        preg_match_all('/\d+/', (string) $value, $m);
        $runs = [];
        foreach ($m[0] as $run) {
            $run = ltrim($run, '0');
            if (strlen($run) >= 3) {
                $runs[] = $run;
            }
        }
        return $runs;
    }

    /** La secuencia numérica más larga: el "número real" de la factura. */
    public static function nucleoNumerico($value)
    {
        $best = '';
        foreach (self::secuenciasNumericas($value) as $run) {
            if (strlen($run) > strlen($best)) {
                $best = $run;
            }
        }
        return $best;
    }

    /**
     * Término con el que se busca una factura del listado en el correo:
     * el número corto. De un consecutivo de 20 dígitos toma los últimos 10
     * sin ceros (00100001010000006756 → 6756); un número corto queda igual.
     * Lo usan el botón "Buscar en correo" de por-pagar y su tarjeta de
     * navegación en el módulo Correo.
     */
    public static function terminoBusquedaCorreo($numero)
    {
        $nucleo = self::nucleoNumerico($numero);
        if (strlen($nucleo) > 10) {
            $nucleo = ltrim(substr($nucleo, -10), '0');
        }
        return $nucleo !== '' ? $nucleo : trim((string) $numero);
    }

    /**
     * ¿El núcleo largo termina en el núcleo corto con relleno de ceros?
     * "1400020010000005061" termina en "5061" y el dígito anterior es 0
     * → mismo número. El guard del cero evita confundir 5061 con ...15061.
     */
    public static function nucleoTerminaEn($largo, $corto)
    {
        $largo = (string) $largo;
        $corto = (string) $corto;

        if (strlen($corto) < 3 || strlen($largo) <= strlen($corto)) {
            return false;
        }
        if (substr($largo, -strlen($corto)) !== $corto) {
            return false;
        }
        return substr($largo, -strlen($corto) - 1, 1) === '0';
    }

    /**
     * Similitud de proveedor basada en tokens: iniciales ("M." ≈ "MIGUEL"),
     * abreviaturas ("CIA" ≈ "COMPANIA"), sin sufijos corporativos.
     *
     * Antes de comparar se resuelven los alias aprendidos: hay nombres que no
     * se parecen en nada y aun así son el mismo proveedor ("COOPEAGRI" y
     * "COOPERATIVA AGRICOLA INDUSTRIAL Y DE SERVICIOS MULTIPLES EL GENERAL").
     * Eso ningún algoritmo lo adivina; lo dijo una persona al emparejar a mano.
     */
    public static function similaridadTexto($a, $b)
    {
        if (self::mismoPorAlias($a, $b)) {
            return 100;
        }

        $tokensA = self::tokenizarProveedor($a);
        $tokensB = self::tokenizarProveedor($b);

        if (empty($tokensA) || empty($tokensB)) {
            return 0;
        }

        // Iterar sobre el conjunto más corto para no penalizar razones
        // sociales largas con iniciales/apellidos extra
        if (count($tokensA) > count($tokensB)) {
            [$tokensA, $tokensB] = [$tokensB, $tokensA];
        }

        $sum = 0;
        $usados = [];
        foreach ($tokensA as $ta) {
            $best = 0;
            $bestIdx = -1;
            foreach ($tokensB as $i => $tb) {
                if (isset($usados[$i])) {
                    continue;
                }
                $s = self::scoreToken($ta, $tb);
                if ($s > $best) {
                    $best = $s;
                    $bestIdx = $i;
                }
            }
            if ($bestIdx >= 0) {
                $usados[$bestIdx] = true;
            }
            $sum += $best;
        }

        return round($sum / count($tokensA), 2);
    }

    /**
     * Comparación conservadora de identidad de proveedor.
     *
     * A diferencia de similaridadTexto(), esta función no acepta nombres que
     * solamente comparten palabras genéricas o que se parecen por ortografía
     * (por ejemplo CIAMESA y COAMESA). Se utiliza cuando una asociación
     * automática exige que ambos documentos pertenezcan al mismo proveedor.
     */
    public static function mismoProveedor($a, $b)
    {
        if (self::mismoPorAlias($a, $b)) {
            return true;
        }

        $tokensA = self::tokenizarProveedor($a);
        $tokensB = self::tokenizarProveedor($b);

        if (empty($tokensA) || empty($tokensB)) {
            return false;
        }

        if ($tokensA === $tokensB) {
            return true;
        }

        // También admite el mismo nombre con sus palabras en otro orden.
        sort($tokensA, SORT_STRING);
        sort($tokensB, SORT_STRING);
        return $tokensA === $tokensB;
    }

    /**
     * Compara dos tokens: igual=100; letra sola prefijo=90; abreviatura ≤3
     * chars prefijo=85; similar_text ≥75 devuelve %; menor descarta (0).
     */
    private static function scoreToken($a, $b)
    {
        if ($a === $b) {
            return 100;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 1) {
            return str_starts_with($b, $a) ? 90 : 0;
        }
        if ($lenB === 1) {
            return str_starts_with($a, $b) ? 90 : 0;
        }

        if ($lenA <= 3 && str_starts_with($b, $a)) {
            return 85;
        }
        if ($lenB <= 3 && str_starts_with($a, $b)) {
            return 85;
        }

        similar_text($a, $b, $pct);
        return $pct >= 75 ? $pct : 0;
    }

    /**
     * Nombre de proveedor → tokens significativos (sin acentos, puntuación,
     * sufijos corporativos ni conectores).
     */
    /**
     * ¿Los dos textos son el mismo proveedor según una equivalencia que
     * alguien enseñó al emparejar a mano?
     *
     * Resuelve cada lado a su nombre canónico y los compara. Así funciona en
     * los tres sentidos: alias contra nombre real, nombre real contra alias, y
     * dos alias distintos del mismo proveedor.
     *
     * Si la tabla de alias no existe todavía, el mapa viene vacío y esto
     * siempre devuelve false: el emparejador se comporta como antes.
     */
    private static function mismoPorAlias($a, $b)
    {
        if (!class_exists('ProveedorAlias')) {
            // Este ayudante también se usa suelto, sin la capa de modelos
            // cargada (pruebas, utilidades de línea de comandos). Ahí no hay
            // base que consultar: se compara solo por parecido, como antes.
            $ruta = __DIR__ . '/../models/ProveedorAlias.php';
            if (!class_exists('Model') || !is_file($ruta)) {
                return false;
            }
            require_once $ruta;
        }

        $normA = ProveedorAlias::normalizar($a);
        $normB = ProveedorAlias::normalizar($b);
        if ($normA === '' || $normB === '' || $normA === $normB) {
            return false; // iguales ya los resuelve la comparación normal
        }

        $mapa = ProveedorAlias::mapa();
        if (!$mapa) {
            return false;
        }

        $canonA = $mapa[$normA] ?? $normA;
        $canonB = $mapa[$normB] ?? $normB;
        if ($canonA !== $canonB) {
            return false;
        }

        // Se anota en memoria cuál alias resolvió la comparación; quien corre
        // la verificación lo vuelca a la base de una sola vez al terminar.
        // Contar aquí sería una escritura dentro del bucle de comparaciones.
        foreach ([$normA, $normB] as $norm) {
            if (isset($mapa[$norm])) {
                self::$aliasAplicados[$norm] = true;
            }
        }

        return true;
    }

    /** Alias que decidieron alguna comparación desde el último volcado. */
    private static $aliasAplicados = [];

    public static function aliasAplicados()
    {
        return array_keys(self::$aliasAplicados);
    }

    public static function olvidarAliasAplicados()
    {
        self::$aliasAplicados = [];
    }

    public static function tokenizarProveedor($value)
    {
        $text = strtoupper(trim((string) $value));
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        // Los puntos de abreviaturas deben desaparecer antes que el resto de
        // la puntuación. Así "S.A." se convierte en "SA" (stopword), no en
        // dos tokens muy cortos "S" y "A" que inflaban la similitud.
        $text = str_replace('.', '', $text);
        $text = preg_replace('/[^A-Z0-9 ]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Cubre variantes espaciadas como "S. A." o "S / A".
        $text = preg_replace('/\bS\s+A\b/', 'SA', $text);

        if ($text === '') {
            return [];
        }

        $tokens = explode(' ', $text);
        $stopwords = array_flip(self::STOPWORDS_PROVEEDOR);

        return array_values(array_filter(
            $tokens,
            fn($t) => $t !== '' && !isset($stopwords[$t])
        ));
    }

    public static function normalizarNumero($value)
    {
        $text = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim((string) $value)));
        return preg_replace('/^0+/', '', $text);
    }
}
