<?php
/** Formato unico para el numero corto procedente de un XML. */
class NumeroFactura
{
    public const DIGITOS_XML = 8;

    /**
     * Conserva los ultimos ocho digitos significativos y completa con ceros.
     * Los valores no numericos se mantienen de forma defensiva en 8 caracteres.
     */
    public static function xmlOchoDigitos($valor)
    {
        $valor = trim((string) $valor);
        $digitos = preg_replace('/\D+/', '', $valor);

        if ($digitos !== '') {
            $digitos = ltrim($digitos, '0');
            $digitos = $digitos !== '' ? $digitos : '0';
            return str_pad(
                substr($digitos, -self::DIGITOS_XML),
                self::DIGITOS_XML,
                '0',
                STR_PAD_LEFT
            );
        }

        $limpio = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $valor));
        $limpio = $limpio !== '' ? substr($limpio, -self::DIGITOS_XML) : '0';
        return str_pad($limpio, self::DIGITOS_XML, '0', STR_PAD_LEFT);
    }
}
