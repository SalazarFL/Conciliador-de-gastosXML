<?php
/**
 * Hace que cada modelo compruebe su tabla UNA vez por instalación, no una vez
 * por cada vez que alguien lo usa.
 *
 * Por qué existe: cada modelo se aseguraba de su propia tabla en el
 * constructor —`CREATE TABLE IF NOT EXISTS` más un puñado de consultas a
 * `information_schema`—. Con la base en la misma máquina eso costaba décimas
 * de milisegundo y era invisible. Con la base en el servidor, medido: 44 de
 * las 130 consultas de una carga de Correo eran eso, 3.7 segundos por pantalla
 * dedicados a preguntar si existe lo que ya existe.
 *
 * La red de seguridad no se quita, se cobra una sola vez: la primera petición
 * después de actualizar el código comprueba el esquema y deja una marca en
 * `storage/cache/`. Mientras el código y la base sigan siendo los mismos, las
 * peticiones siguientes no gastan ni una consulta.
 *
 * La marca se invalida sola cuando cambia cualquier modelo (un `git pull`
 * cambia las fechas de los archivos) o cuando la configuración apunta a otra
 * base. No hay que acordarse de borrarla.
 */

class Esquema
{
    /** Clases ya comprobadas en ESTA petición. */
    private static $enEstaPeticion = [];

    /** Contenido de la marca persistente, leído una sola vez. */
    private static $marca = null;

    /** Huella de "código + base"; si cambia, hay que volver a comprobar. */
    private static $huella = null;

    /**
     * Ejecuta $asegurar solo si hace falta.
     *
     * @param string   $clave     Normalmente el nombre de la clase del modelo.
     * @param callable $asegurar  El ensureTable() de siempre.
     */
    public static function unaVez($clave, callable $asegurar)
    {
        if (isset(self::$enEstaPeticion[$clave])) {
            return;
        }
        self::$enEstaPeticion[$clave] = true;

        $marca = self::marca();
        if (isset($marca['claves'][$clave])) {
            return;
        }

        $asegurar();

        $marca['claves'][$clave] = true;
        self::guardar($marca);
    }

    /**
     * Olvida lo comprobado y fuerza que se vuelva a revisar.
     * La usan el diagnóstico y las pruebas.
     */
    public static function olvidar()
    {
        self::$enEstaPeticion = [];
        self::$marca = null;
        @unlink(self::archivo());
    }

    // ── Interno ───────────────────────────────────────────────────────

    private static function marca()
    {
        if (self::$marca !== null) {
            return self::$marca;
        }

        $marca = ['huella' => self::huella(), 'claves' => []];
        $crudo = @file_get_contents(self::archivo());
        if ($crudo !== false) {
            $leido = json_decode($crudo, true);
            // Una huella distinta significa código nuevo u otra base: lo
            // anotado antes ya no dice nada sobre esta combinación.
            if (is_array($leido) && ($leido['huella'] ?? null) === $marca['huella']
                && isset($leido['claves']) && is_array($leido['claves'])) {
                $marca['claves'] = $leido['claves'];
            }
        }

        return self::$marca = $marca;
    }

    private static function guardar(array $marca)
    {
        self::$marca = $marca;

        $archivo = self::archivo();
        $dir = dirname($archivo);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        // Archivo temporal y rename: dos peticiones a la vez no pueden dejar
        // un JSON a medio escribir, que se leería como "nada comprobado" y
        // haría volver al problema original sin avisar.
        $temporal = $archivo . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporal, json_encode($marca)) !== false) {
            if (!@rename($temporal, $archivo)) {
                @unlink($temporal);
            }
        }
    }

    private static function archivo()
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'esquema.json';
    }

    /**
     * Cambia cuando cambia el código de los modelos o la base a la que se
     * apunta. Son ~30 llamadas a filemtime, sin red: comparado con una sola
     * consulta al servidor, es gratis.
     */
    private static function huella()
    {
        if (self::$huella !== null) {
            return self::$huella;
        }

        $ultimoCambio = 0;
        foreach (glob(dirname(__DIR__) . '/models/*.php') ?: [] as $archivo) {
            $fecha = @filemtime($archivo);
            if ($fecha > $ultimoCambio) {
                $ultimoCambio = $fecha;
            }
        }

        $config = require dirname(__DIR__) . '/config/database.php';

        return self::$huella = md5($ultimoCambio . '|' . $config['host'] . '|' . $config['database']);
    }
}
