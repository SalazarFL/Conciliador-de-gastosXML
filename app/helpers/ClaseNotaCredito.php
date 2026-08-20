<?php
/**
 * Qué clase de nota de crédito es, leyendo la forma de su número.
 *
 * El reporte del ERP mezcla cuatro cosas distintas bajo la misma columna, y
 * solo tres de ellas llegan a tener documento electrónico. Saber cuál es cuál
 * decide si la línea se persigue o no:
 *
 *   directa  NC- 17-1-00100001010000012473-684    corrige una factura; el número
 *                                                 largo es el consecutivo de ESA
 *                                                 factura, no el de la nota
 *   costo    NC- 1-1-D-99900001010000670607-189   diferencia de precio o costo
 *   cambio   NC- 17-1-132-0                       cambio de mercadería
 *   ajuste   NC- 4945                             ajuste interno; NUNCA lleva XML
 *
 * Lo que no encaja en ninguna queda en 'revisar' a propósito: es preferible
 * que alguien lo mire a que se descarte en silencio.
 */
class ClaseNotaCredito
{
    public const DIRECTA = 'directa';
    public const COSTO   = 'costo';
    public const CAMBIO  = 'cambio';
    public const AJUSTE  = 'ajuste';
    public const REVISAR = 'revisar';

    /** Las clases que sí deben tener XML y PDF de respaldo. */
    public const CON_RESPALDO = [self::DIRECTA, self::COSTO, self::CAMBIO];

    /**
     * Cómo se llama cada clase en pantalla.
     *
     * Estaba escrito en cada desplegable que las ofrece —el de Seguimiento y
     * el de Notas de crédito— en dos listas distintas. Que las listas no sean
     * iguales es correcto: Seguimiento no ofrece 'ajuste' porque esas notas no
     * se persiguen, nunca llevan XML. Lo que no tenía por qué diferir eran los
     * nombres.
     */
    public const ETIQUETAS = [
        self::DIRECTA => 'Directa (corrige factura)',
        self::COSTO   => 'Diferencia de costo',
        self::CAMBIO  => 'Cambio de mercadería',
        self::AJUSTE  => 'Ajuste',
        self::REVISAR => 'Por revisar',
    ];

    /**
     * Las clases que pide un filtro, saneadas.
     *
     * Llegan separadas por comas —'directa,costo'— y no como lista, para que
     * el filtro siga siendo un texto: así lo recuerda la sesión, viaja en los
     * enlaces y sale en el export sin que nada de eso tenga que aprender a
     * manejar arreglos.
     *
     * $admitidas acota cuáles valen en esa pantalla; por omisión, todas.
     */
    public static function clasesPedidas($valor, ?array $admitidas = null)
    {
        $admitidas = $admitidas ?? array_keys(self::ETIQUETAS);
        $pedidas = is_array($valor) ? $valor : explode(',', (string) $valor);

        $limpias = [];
        foreach ($pedidas as $clase) {
            $clase = strtolower(trim((string) $clase));
            if ($clase !== '' && in_array($clase, $admitidas, true)
                && !in_array($clase, $limpias, true)) {
                $limpias[] = $clase;
            }
        }
        return $limpias;
    }

    public static function clasificar($documento)
    {
        $numero = self::limpiar($documento);
        if ($numero === '') {
            return self::REVISAR;
        }
        if (preg_match('/(^|-)D(-|$)/i', $numero)) {
            return self::COSTO;
        }
        if (preg_match('/\d{20}/', $numero)) {
            return self::DIRECTA;
        }
        if (preg_match('/^\d{1,6}(-\d{1,8}){1,5}$/', $numero)) {
            return self::CAMBIO;
        }
        if (preg_match('/^\d+$/', $numero)) {
            return self::AJUSTE;
        }
        return self::REVISAR;
    }

    public static function llevaRespaldo($documento)
    {
        return in_array(self::clasificar($documento), self::CON_RESPALDO, true);
    }

    /**
     * Deja el número desnudo: sin el prefijo "NC-", sin los "NC" que algunos
     * proveedores intercalan (00NC336241) y sin el nombre del proveedor que a
     * veces queda pegado cuando el reporte parte la celda en dos líneas
     * (1-2-68-0GrupoBMSPS.A). Se exigen cuatro letras seguidas para no comerse
     * la "D" de las notas de costo.
     */
    public static function limpiar($documento)
    {
        $valor = trim((string) $documento);
        $valor = preg_replace('/\s+/', '', $valor);
        $valor = preg_replace('/^NC-*/i', '', $valor);
        $valor = preg_replace('/[A-Za-z][A-Za-z.]{3,}$/', '', $valor);
        $valor = preg_replace('/NC/i', '', $valor);
        return trim($valor, '-');
    }
}
