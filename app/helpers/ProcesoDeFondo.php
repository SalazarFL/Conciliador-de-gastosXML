<?php
/**
 * Lanza un script de cli/ oculto y sin esperarlo, desde una página.
 *
 * Es lo mismo que ya hacían Correo (sincronizar ahora) y Diagnóstico (el
 * respaldo manual), cada uno con su copia: wscript con un .vbs que corre PHP
 * sin ventana y devuelve el control al instante. Con exec() a secas, PHP se
 * queda esperando a que termine, y quien cargó el pago también.
 *
 * Nunca lanza: si no se puede, devuelve false y lo que iba a hacer el proceso
 * lo termina haciendo la tarea programada, como antes.
 */
class ProcesoDeFondo
{
    /**
     * @param string $script   Nombre dentro de cli/, p. ej. 'copiar_pagos.php'.
     * @param array  $args     Argumentos, ya sin comillas.
     * @return bool Si se lanzó.
     */
    public static function lanzar($script, array $args = [])
    {
        try {
            if (!function_exists('exec')) {
                return false;
            }
            $raiz = dirname(__DIR__, 2);
            $ruta = $raiz . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . basename((string) $script);
            $php = self::rutaPhp();
            if ($php === null || !is_file($ruta)) {
                return false;
            }
            $cmd = '"' . $php . '" "' . $ruta . '"';
            foreach ($args as $arg) {
                $cmd .= ' "' . str_replace('"', '', (string) $arg) . '"';
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                @exec($cmd . ' > /dev/null 2>&1 &');
                return true;
            }
            $dir = $raiz . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'correo';
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return false;
            }
            // Uno por lanzamiento: un motor lanza a sus ayudantes uno detrás
            // de otro, y dos pantallas pueden lanzar a la vez. Con un solo
            // archivo, uno podía ejecutar el que el otro acababa de escribir.
            $vbs = $dir . DIRECTORY_SEPARATOR . pathinfo($ruta, PATHINFO_FILENAME)
                . '_launch_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.vbs';
            if (@file_put_contents($vbs,
                    'CreateObject("WScript.Shell").Run "' . str_replace('"', '""', $cmd) . '", 0, False' . "\r\n") === false) {
                return false;
            }
            // wscript termina en cuanto arrancó el proceso (Run con False no
            // lo espera), así que al volver el .vbs ya se leyó.
            @exec('wscript.exe //B //Nologo "' . $vbs . '"', $salida, $codigo);
            @unlink($vbs);
            return $codigo === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** El PHP de consola que acompaña al del servidor. */
    public static function rutaPhp()
    {
        $candidatos = [
            trim((string) getenv('XMLCONCILIA_PHP_CLI')),
            'C:\\WebServer\\PHP84\\php.exe',
        ];
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidatos[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        $phprc = getenv('PHPRC');
        if ($phprc) {
            $candidatos[] = rtrim($phprc, '\\/') . DIRECTORY_SEPARATOR . 'php.exe';
        }
        foreach ($candidatos as $c) {
            if ($c !== '' && @is_file($c)) {
                return $c;
            }
        }
        return null;
    }
}
