<?php
/**
 * ¿Este PDF es de esta factura?
 *
 * Subir el PDF a mano existe porque hay proveedores que no lo adjuntan: el
 * de la 00000065 de Antonio Granados Fallas lo mandó como enlace de OneDrive
 * y, para el sistema, ese PDF nunca llegó. Quien lo baja y lo sube tiene
 * delante una carpeta de Descargas llena de "Documento factura
 * electrónica.pdf", y subir el de otra factura es fácil y no se nota: la
 * carpeta del pago se ve completa y lleva el respaldo equivocado.
 *
 * Por eso, antes de archivarlo, se lee el texto del PDF y se busca la clave
 * (50 dígitos) o el consecutivo (20) de la factura. Solo los dígitos: cada
 * sistema de facturación los imprime a su manera —con espacios, guiones,
 * partidos en dos renglones—.
 *
 * Un PDF sin texto (escaneado) no se puede comprobar: se deja pasar y se
 * dice. Uno con texto que no nombra la factura se frena, y quien lo sube
 * decide si igual es ese.
 */

class PdfDeFactura
{
    /** El archivo empieza como un PDF, no solo se llama así. */
    public static function esPdf($ruta)
    {
        $fp = @fopen((string) $ruta, 'rb');
        if ($fp === false) {
            return false;
        }
        $inicio = (string) fread($fp, 1024);
        fclose($fp);
        return strpos($inicio, '%PDF-') !== false;
    }

    /**
     * @return bool|null true si el texto nombra la factura, false si no,
     *         null si no hay texto con qué comprobarlo.
     */
    public static function corresponde($texto, array $fila)
    {
        $digitos = preg_replace('/\D+/', '', (string) $texto);
        if ($digitos === '') {
            return null;
        }
        foreach (['clave', 'consecutivo_completo'] as $campo) {
            $buscado = preg_replace('/\D+/', '', (string) ($fila[$campo] ?? ''));
            if (strlen($buscado) >= 20 && strpos($digitos, $buscado) !== false) {
                return true;
            }
        }
        return false;
    }
}
