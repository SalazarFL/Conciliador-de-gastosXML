<?php
/**
 * Lo que una tarea larga le va contando a quien la está esperando.
 *
 * Por qué existe: cargar un pago es una sola petición que puede tardar
 * minutos —lee el archivo, resuelve 529 facturas contra el ERP, empareja
 * comprobantes y copia documentos a la carpeta del pago— y mientras tanto el
 * navegador no muestra nada. Quien la dispara no puede distinguir "está
 * copiando archivos" de "se colgó", y a los dos minutos vuelve a darle al
 * botón, que es la peor de las dos cosas que puede hacer.
 *
 * La petición larga escribe acá en qué va; el navegador lo pregunta por otro
 * lado. No hace falta nada más: ni cola, ni proceso aparte, ni websockets.
 *
 * El archivo y no la sesión: PHP serializa las peticiones de una misma sesión
 * —mientras la carga la tiene abierta, la que viene a preguntar se queda
 * esperando— así que el progreso guardado ahí no se podría leer hasta que ya
 * no sirviera de nada. Un archivo suelto se lee mientras la otra escribe.
 *
 * Nada de lo que se escribe acá es dato de nadie: nombres de fase y conteos.
 * Si se pierde, la carga sigue igual; lo único que se pierde es el aviso.
 */
class ProgresoTarea
{
    /** Cuánto sobrevive un progreso antes de considerarse basura. */
    const VIVE_SEGUNDOS = 3600;

    /** Tope de archivos en la carpeta, por si algo deja de limpiar. */
    const MAX_ARCHIVOS = 200;

    private $archivo;
    private $pasos;
    private $indice = 0;
    private $empezo;

    private function __construct($archivo, array $pasos)
    {
        $this->archivo = $archivo;
        $this->pasos = $pasos;
        $this->empezo = time();
    }

    /**
     * Abre el progreso de una tarea. Con token vacío o inválido devuelve uno
     * que no escribe: así quien reporta no tiene que preguntar si hay alguien
     * mirando, y una llamada sin token no crea archivos sueltos.
     *
     * @param array $pasos Las fases, en orden, para poder decir "3 de 6".
     */
    public static function para($token, array $pasos = [])
    {
        $ruta = self::rutaDe($token);
        $tarea = new self($ruta, $pasos);
        if ($ruta !== null) {
            self::limpiar();
            $tarea->escribir('corriendo', $pasos ? $pasos[0] : 'Preparando…', '');
        }
        return $tarea;
    }

    /** Pasa a la fase siguiente. */
    public function paso($etiqueta, $detalle = '')
    {
        $this->indice++;
        $this->escribir('corriendo', $etiqueta, $detalle);
    }

    /** Agrega información a la fase en curso, sin avanzarla. */
    public function detalle($detalle)
    {
        $this->escribir('corriendo', $this->etiquetaActual(), $detalle);
    }

    public function terminar($detalle = '')
    {
        $this->indice = count($this->pasos);
        $this->escribir('listo', 'Listo', $detalle);
    }

    public function fallar($detalle = '')
    {
        $this->escribir('error', 'No se pudo completar', $detalle);
    }

    /** Lo que ve quien pregunta. Sin progreso guardado devuelve null. */
    public static function leer($token)
    {
        $ruta = self::rutaDe($token);
        if ($ruta === null || !is_file($ruta)) {
            return null;
        }
        $crudo = @file_get_contents($ruta);
        $datos = $crudo === false ? null : json_decode($crudo, true);
        return is_array($datos) ? $datos : null;
    }

    /** Borra el progreso: la tarea terminó y ya nadie lo va a preguntar. */
    public static function olvidar($token)
    {
        $ruta = self::rutaDe($token);
        if ($ruta !== null && is_file($ruta)) {
            @unlink($ruta);
        }
    }

    // ------------------------------------------------------------------

    private function etiquetaActual()
    {
        $i = max(0, min($this->indice, count($this->pasos) - 1));
        return $this->pasos[$i] ?? 'Trabajando…';
    }

    private function escribir($estado, $etiqueta, $detalle)
    {
        if ($this->archivo === null) {
            return;
        }
        // Nunca puede tumbar la tarea que lo reporta: si el disco está lleno
        // —que es justo cuando más se quiere ver el progreso— la carga sigue.
        try {
            @file_put_contents($this->archivo, json_encode([
                'estado' => $estado,
                'paso' => min($this->indice + 1, max(1, count($this->pasos))),
                'de' => max(1, count($this->pasos)),
                'etiqueta' => (string) $etiqueta,
                'detalle' => (string) $detalle,
                'segundos' => time() - $this->empezo,
                'actualizado' => time(),
            ], JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (Throwable $e) {
            $this->archivo = null; // no insistir en cada paso
        }
    }

    /**
     * El token viaja en una URL y termina siendo un nombre de archivo, así que
     * se acepta solo lo que no puede salirse de la carpeta.
     */
    private static function rutaDe($token)
    {
        $token = (string) $token;
        if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return null;
        }
        $dir = self::carpeta();
        return $dir === null ? null : $dir . DIRECTORY_SEPARATOR . $token . '.json';
    }

    private static function carpeta()
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'progreso';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        return $dir;
    }

    /**
     * Se lleva los progresos viejos. Importa más de lo que parece: son
     * archivos diminutos, pero uno por carga y nadie los borraría nunca.
     */
    private static function limpiar()
    {
        $dir = self::carpeta();
        if ($dir === null) {
            return;
        }
        $archivos = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $limite = time() - self::VIVE_SEGUNDOS;
        foreach ($archivos as $a) {
            if (@filemtime($a) < $limite) {
                @unlink($a);
            }
        }
        // Red de seguridad por si el reloj o los permisos impiden lo anterior.
        if (count($archivos) > self::MAX_ARCHIVOS) {
            usort($archivos, function ($x, $y) { return @filemtime($x) <=> @filemtime($y); });
            foreach (array_slice($archivos, 0, count($archivos) - self::MAX_ARCHIVOS) as $a) {
                @unlink($a);
            }
        }
    }
}
