<?php
/**
 * Lo que hace falta para que una línea que el parser no supo leer deje de
 * desaparecer: una identidad estable entre cargas y una forma de volver a
 * aplicar la corrección que alguien ya hizo a mano.
 *
 * Los dos listados —el del ERP y el de notas de crédito— se vuelven a subir
 * completos cada semana. Sin esto, una línea rota que se corrigió el lunes
 * vuelve a preguntarse el lunes siguiente, y el siguiente, para siempre; la
 * bandeja de revisión se volvería un impuesto semanal y la gente terminaría
 * ignorándola, que es exactamente el problema que vino a resolver.
 */
class LineaRevision
{
    /**
     * Identidad de una línea cruda, estable entre cargas.
     *
     * Las celdas que son importes se dejan fuera a propósito: el saldo de una
     * factura baja cada vez que se le abona, así que una firma que lo
     * incluyera cambiaría todas las semanas y no reconocería nunca la misma
     * línea. Lo que no cambia es el resto —documento, fechas, proveedor, los
     * rótulos pegados— y con eso alcanza para saber que es la misma fila.
     *
     * El número de fila tampoco entra: el reporte pagina, y una hoja de más
     * al principio corre todas las filas de lugar sin que ninguna cambie.
     */
    public static function firma($modulo, array $celdas, $contexto = '')
    {
        $estables = [];
        foreach ($celdas as $celda) {
            $celda = trim((string) $celda);
            if ($celda === '' || self::esImporte($celda)) {
                continue;
            }
            $estables[] = mb_strtoupper($celda, 'UTF-8');
        }

        return substr(hash(
            'sha256',
            (string) $modulo . '|' . trim((string) $contexto) . '|' . implode('~', $estables)
        ), 0, 40);
    }

    /** Un importe con decimales o un número con separadores de miles. */
    public static function esImporte($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return false;
        }
        return (bool) preg_match('/^-?[\d.,]+$/', $valor) && preg_match('/\d/', $valor);
    }

    /**
     * De qué celda salió cada dato que la persona dejó guardado.
     *
     * Es la mitad que hace útil recordar una corrección. Guardar los valores
     * pelados serviría una sola vez: la carga siguiente trae la misma línea
     * rota pero con el saldo ya cambiado, y reponer el saldo viejo sería
     * escribir un dato falso. Guardando además de qué columna salió, la
     * corrección se vuelve una regla de lectura —"el saldo está en la celda
     * 22"— y sigue valiendo cuando el número de adentro cambia.
     *
     * Un campo que la persona escribió de cero (no calza con ninguna celda)
     * queda en null y se reaplica tal cual: es una constante, no una lectura.
     */
    public static function mapaDeCampos(array $campos, array $celdas)
    {
        $mapa = [];
        foreach ($campos as $campo => $valor) {
            $mapa[$campo] = self::indiceDeCelda($valor, $celdas);
        }
        return $mapa;
    }

    /** La celda de la que salió este valor, o null si no salió de ninguna. */
    private static function indiceDeCelda($valor, array $celdas)
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        foreach ($celdas as $indice => $celda) {
            if (self::mismoValor($valor, $celda)) {
                return (int) $indice;
            }
        }
        return null;
    }

    /**
     * Si dos textos dicen lo mismo. Los importes se comparan como números
     * ("1,234.56" y "1234.56" son el mismo saldo) y las fechas por su valor,
     * porque el formulario las devuelve en aaaa-mm-dd y el reporte las
     * imprime en dd/mm/aaaa.
     */
    private static function mismoValor($a, $b)
    {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($a === '' || $b === '') {
            return false;
        }
        if (mb_strtoupper($a, 'UTF-8') === mb_strtoupper($b, 'UTF-8')) {
            return true;
        }

        if (self::esImporte($a) && self::esImporte($b)) {
            return abs(self::numero($a) - self::numero($b)) < 0.005;
        }

        $fechaA = self::fecha($a);
        $fechaB = self::fecha($b);
        return $fechaA !== null && $fechaA === $fechaB;
    }

    /**
     * Vuelve a aplicar una corrección guardada sobre la línea de hoy.
     *
     * Devuelve los campos ya resueltos, o null cuando la línea cambió tanto
     * que la regla vieja dejó de tener sentido. Ese null importa: preferimos
     * volver a preguntar antes que escribir en el listado un dato que salió
     * de una columna que ya no está donde estaba.
     */
    public static function reaplicar(array $memoria, array $celdasActuales)
    {
        $campos = is_array($memoria['campos'] ?? null) ? $memoria['campos'] : [];
        $celdasGuardadas = is_array($memoria['celdas'] ?? null) ? $memoria['celdas'] : [];
        $mapa = is_array($memoria['mapa'] ?? null) ? $memoria['mapa'] : [];

        if (!$campos) {
            return null;
        }

        // La línea vino idéntica: la corrección se aplica tal cual.
        if (self::mismasCeldas($celdasGuardadas, $celdasActuales)) {
            return $campos;
        }

        // Cambió algún número. Solo se puede releer si la fila conserva la
        // misma forma; si le sobran o le faltan columnas, los índices
        // guardados apuntan a otra cosa y no se puede confiar en ellos.
        if (count($celdasGuardadas) !== count($celdasActuales)) {
            return null;
        }

        $resueltos = [];
        foreach ($campos as $campo => $valor) {
            $indice = $mapa[$campo] ?? null;
            if ($indice === null) {
                $resueltos[$campo] = $valor;
                continue;
            }
            if (!array_key_exists($indice, $celdasActuales)) {
                return null;
            }
            $resueltos[$campo] = trim((string) $celdasActuales[$indice]);
        }

        return $resueltos;
    }

    private static function mismasCeldas(array $a, array $b)
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $valor) {
            if (!array_key_exists($i, $b) || trim((string) $valor) !== trim((string) $b[$i])) {
                return false;
            }
        }
        return true;
    }

    /** Un importe del reporte pasado a número. */
    public static function numero($valor)
    {
        return (float) str_replace([',', ' ', "\xC2\xA0"], '', trim((string) $valor));
    }

    /**
     * Una fecha en cualquiera de los formatos que circulan, en aaaa-mm-dd,
     * o null si el texto no es una fecha.
     */
    public static function fecha($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valor, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
                : null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $valor, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3])
                : null;
        }
        return null;
    }
}
