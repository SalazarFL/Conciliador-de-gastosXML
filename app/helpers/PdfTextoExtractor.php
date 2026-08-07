<?php
/**
 * Extrae la capa de texto de un PDF con pdftotext (Poppler), preservando
 * el layout en columnas (-layout). Requiere el binario configurado en
 * config.php ('pdftotext_path') o disponible en el PATH.
 */

class PdfTextoExtractor
{
    public static function extraer($rutaPdf, $binario = null)
    {
        if (!is_file($rutaPdf)) {
            throw new RuntimeException('PDF no encontrado: ' . $rutaPdf);
        }

        $bin = $binario !== null && $binario !== '' ? $binario : self::binario();

        $tmp = tempnam(sys_get_temp_dir(), 'pdftxt');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de extracción.');
        }

        $cmd = escapeshellarg($bin) . ' -layout -enc UTF-8 '
            . escapeshellarg($rutaPdf) . ' ' . escapeshellarg($tmp) . ' 2>&1';

        $salida = [];
        $codigo = 1;
        exec($cmd, $salida, $codigo);

        $texto = $codigo === 0 ? (string) file_get_contents($tmp) : '';
        @unlink($tmp);

        if ($codigo !== 0) {
            throw new RuntimeException(
                'pdftotext falló (código ' . $codigo . '): ' . trim(implode(' ', $salida))
                . '. Verifique pdftotext_path en config.php.'
            );
        }
        if (trim($texto) === '') {
            throw new RuntimeException('El PDF no tiene capa de texto extraíble: ' . basename($rutaPdf));
        }

        return $texto;
    }

    private static function binario()
    {
        $config = require dirname(__DIR__) . '/config/config.php';
        $ruta = trim((string) ($config['pdftotext_path'] ?? ''));

        if ($ruta !== '') {
            if (!is_file($ruta)) {
                throw new RuntimeException('pdftotext_path configurado pero no existe: ' . $ruta);
            }
            return $ruta;
        }

        return 'pdftotext';
    }
}
