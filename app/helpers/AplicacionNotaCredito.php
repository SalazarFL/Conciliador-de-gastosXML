<?php
/**
 * A qué factura corrige una nota de crédito, y si todavía se puede aplicar.
 *
 * El sistema no aplica nada: el que aplica es el ERP, y por eso su reporte
 * trae el saldo de cada nota, que es lo que le queda sin usar. Lo que aquí se
 * hace es MIRAR: cruzar el saldo de la nota con el de la factura que nombra y
 * decir en qué situación está, para que nadie pague una factura sin darse
 * cuenta de que tenía una nota esperando.
 *
 * Cómo se sabe a qué factura va
 * -----------------------------
 * El número de una nota directa lleva dentro el consecutivo electrónico de
 * 20 dígitos de la factura que corrige:
 *
 *     NC- 17-1-00100001010000012473-684
 *                └──────┬───────┘
 *              consecutivo de la factura
 *
 * En el consecutivo de Hacienda los dígitos 9 y 10 son el TIPO de documento:
 * 01 = factura electrónica, 03 = nota de crédito. Esa es toda la regla, y
 * hace falta porque no todas las notas "directas" apuntan a una factura:
 * 23 de las 939 del listado real traen tipo 03, o sea SU PROPIO número, y no
 * dicen a qué factura van. Sin mirar el tipo, esas 23 se buscarían como si
 * fueran facturas y no aparecerían nunca, o peor, engancharían con la factura
 * equivocada que casualmente terminara en los mismos ocho dígitos.
 *
 * Las de costo traen el mismo consecutivo que las directas (447 de 451) y se
 * cruzan igual, con una condición más: solo cuentan mientras les quede más
 * de MINIMO_COSTO por aplicar. Por debajo no se persiguen contra la factura.
 *
 * Las de cambio no traen consecutivo de ninguna clase —545 de 545 en el
 * listado real, "Sin Boleta" en la entrada— y eso no es un defecto de
 * lectura: una nota de cambio de mercadería se puede aplicar a cualquier
 * factura del proveedor, así que el documento no tiene a quién nombrar. Para
 * esas, la unidad de análisis es el proveedor y no la factura: se ofrecen en
 * todas las facturas con saldo del mismo proveedor, del monto que sean.
 */
class AplicacionNotaCredito
{
    /** Las clases que apuntan a una factura concreta y se pueden cruzar. */
    const CLASES_CON_FACTURA = ['directa', 'costo'];

    /** Las clases que valen para cualquier factura de su proveedor. */
    const CLASES_POR_PROVEEDOR = ['cambio'];

    /**
     * Lo que le tiene que quedar sin aplicar a una nota de costo para que
     * cuente, en colones. Es contra el saldo y no contra el monto: una nota
     * de ₡15.000 a la que le quedan ₡3.000 ya no se ofrece.
     */
    const MINIMO_COSTO = 10000;

    /** Los tipos del ERP que son facturas (y no notas u otros movimientos). */
    const TIPOS_FACTURA = ['F', 'FE', 'FACT'];

    /** Tipo de documento dentro del consecutivo: factura electrónica. */
    const TIPO_FACTURA = '01';

    // ── Estados ──────────────────────────────────────────────────────
    //
    // Salen de cruzar dos saldos y nada más. No hay ninguno que signifique
    // "error": una nota cuya factura ya está en cero es una situación normal
    // —la nota se puede aplicar a otra factura del mismo proveedor—, así que
    // se informa, no se alarma.

    /** Nota con saldo y factura con saldo: se puede aplicar hoy. */
    const APLICABLE = 'aplicable';

    /**
     * Nota con saldo, pero la factura que nombra ya está en cero. En una de
     * cambio, que no nombra ninguna: su proveedor no tiene factura con saldo.
     */
    const FACTURA_LIQUIDADA = 'factura_liquidada';

    /** La nota ya no tiene saldo: el ERP la aplicó. */
    const APLICADA = 'aplicada';

    /** Apunta a una factura que no está en el listado del ERP. */
    const SIN_FACTURA = 'sin_factura';

    /** Trae su propio número, no el de una factura: hay que mirarla. */
    const SIN_REFERENCIA = 'sin_referencia';

    /**
     * No se aplica contra facturas: un ajuste, o una de costo a la que ya no
     * le queda más de MINIMO_COSTO.
     */
    const NO_APLICA = 'no_aplica';

    /**
     * Cómo se llama y de qué color va cada estado.
     * [etiqueta, color, si pide atención al pagar]
     */
    const ESTADOS = [
        self::APLICABLE         => ['Lista para aplicar', 'ok', true],
        self::FACTURA_LIQUIDADA => ['Sin factura con saldo', 'aviso', false],
        self::APLICADA          => ['Ya aplicada', 'neutro', false],
        self::SIN_FACTURA       => ['Su factura no está en el listado', 'aviso', false],
        self::SIN_REFERENCIA    => ['No dice a qué factura va', 'aviso', false],
        self::NO_APLICA         => ['—', 'neutro', false],
    ];

    /** Dos saldos con una diferencia menor a medio centavo son el mismo. */
    const EPSILON = 0.005;

    /**
     * El consecutivo de 20 dígitos de la FACTURA que corrige esta nota, o
     * null si el número no nombra ninguna.
     *
     * Se recorren todos los grupos de 20 dígitos y no solo el primero: hay
     * documentos que arrastran pegado el número de la propia nota.
     */
    public static function consecutivoFactura($documento)
    {
        if (!preg_match_all('/\d{20}/', (string) $documento, $m)) {
            return null;
        }
        foreach ($m[0] as $consecutivo) {
            if (substr($consecutivo, 8, 2) === self::TIPO_FACTURA) {
                return $consecutivo;
            }
        }
        return null;
    }

    /** Los ocho dígitos con los que este módulo cruza contra el ERP. */
    public static function clavesCorta($consecutivo)
    {
        return substr((string) $consecutivo, -8);
    }

    /**
     * En qué situación está una nota. $factura es la fila del ERP a la que
     * apunta, o null si no se encontró. Para una de cambio, que no apunta a
     * ninguna, $proveedorConSaldo dice si su proveedor tiene alguna factura
     * con saldo donde aplicarla.
     */
    public static function estado($clase, $documento, $saldoNota, ?array $factura = null, $proveedorConSaldo = false)
    {
        $clase = (string) $clase;
        if (in_array($clase, self::CLASES_POR_PROVEEDOR, true)) {
            if ((float) $saldoNota <= self::EPSILON) {
                return self::APLICADA;
            }
            return $proveedorConSaldo ? self::APLICABLE : self::FACTURA_LIQUIDADA;
        }
        if (!in_array($clase, self::CLASES_CON_FACTURA, true)) {
            return self::NO_APLICA;
        }
        // Una de costo chica no se persigue contra la factura: no hay nada
        // que decidir sobre ella, igual que con un ajuste. La que ya no tiene
        // saldo sigue su camino y sale como aplicada, que es lo que es.
        $saldo = (float) $saldoNota;
        if ($clase === 'costo' && $saldo > self::EPSILON && $saldo <= self::MINIMO_COSTO) {
            return self::NO_APLICA;
        }
        if (self::consecutivoFactura($documento) === null) {
            return self::SIN_REFERENCIA;
        }
        // El orden importa: una nota sin saldo ya está aplicada y no hay nada
        // que decidir sobre ella, aunque su factura siga viva.
        if ((float) $saldoNota <= self::EPSILON) {
            return self::APLICADA;
        }
        if ($factura === null) {
            return self::SIN_FACTURA;
        }
        return (float) $factura['saldo'] > self::EPSILON
            ? self::APLICABLE
            : self::FACTURA_LIQUIDADA;
    }

    public static function etiqueta($estado)
    {
        return self::ESTADOS[$estado][0] ?? '—';
    }

    public static function color($estado)
    {
        return self::ESTADOS[$estado][1] ?? 'neutro';
    }

    /** Si este estado tiene que frenar a alguien que va a pagar la factura. */
    public static function avisaAlPagar($estado)
    {
        return !empty(self::ESTADOS[$estado][2]);
    }
}
