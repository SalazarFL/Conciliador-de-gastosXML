<?php
/**
 * Alcance por sociedad para los modelos que guardan documentos de una empresa.
 *
 * El problema que resuelve: la validación al importar impide que entre un XML
 * de otra sociedad, pero las consultas de lectura pedían todo lo que hubiera
 * en la tabla. Con una sola empresa registrada el filtro era implícito; con
 * dos, los listados se mezclarían.
 *
 * La sociedad se resuelve UNA vez y se recuerda, para no consultarla en cada
 * llamada. Por omisión es la seleccionada en la aplicación, de modo que un
 * modelo instanciado sin decir nada ya queda acotado: olvidarse de llamar a
 * setSociedad() nunca abre el alcance, solo lo deja en la empresa actual.
 * `setSociedad(0)` es la única forma de ver todas, y hay que escribirlo a
 * propósito (reportes del grupo, mantenimiento).
 *
 * "Todas" y "ninguna" son cosas distintas aunque las dos den el id 0:
 *
 * - TODAS es una decisión: alguien escribió `setSociedad(0)`. No se filtra.
 * - NINGUNA es la ausencia de una: no hay empresa en la sesión, ni preferida,
 *   ni ninguna activa a la que caer. Entonces no se ve NADA. Antes se veía
 *   todo, que es exactamente al revés de lo que hay que hacer cuando no se
 *   sabe de quién son los documentos.
 */
trait AlcanceSociedad
{
    private $sociedadIdAlcance = null;

    /**
     * Solo lo pone setSociedad(0). Distingue "quiero verlas todas" de "no hay
     * ninguna": sin esta marca, el id 0 que sale de la resolución automática
     * se leería como permiso para ver el trabajo de todas las empresas.
     */
    private $alcanceTodasPedido = false;

    /**
     * Fija la sociedad de este modelo. 0 = sin acotar (todas las empresas),
     * y hay que pedirlo explícitamente.
     */
    public function setSociedad($sociedadId)
    {
        $this->sociedadIdAlcance = max(0, (int) $sociedadId);
        $this->alcanceTodasPedido = ($this->sociedadIdAlcance === 0);
        return $this;
    }

    /** ¿Se pidió a propósito ver todas las empresas? */
    public function alcanceEsTodas()
    {
        return $this->alcanceTodasPedido;
    }

    /** Id de la sociedad en la que trabaja este modelo (0 = todas). */
    public function sociedadId()
    {
        if ($this->sociedadIdAlcance === null) {
            // static:: y no self:: para que una subclase pueda responder por
            // su cuenta: es el único punto por el que una prueba puede decir
            // "aquí no hay empresa" sin tocar la sesión ni la base.
            $this->sociedadIdAlcance = (int) static::sociedadSeleccionadaId();
        }
        return $this->sociedadIdAlcance;
    }

    /**
     * Añade el filtro a una lista de condiciones WHERE.
     * $alias es el prefijo de la tabla en la consulta ('f.', 'e.', '').
     */
    protected function filtrarPorSociedad(array &$where, array &$params, $alias = '')
    {
        if ($this->alcanceTodasPedido) {
            return;
        }
        $id = $this->sociedadId();
        if ($id > 0) {
            $where[] = $alias . 'sociedad_id = ?';
            $params[] = $id;
            return;
        }
        $where[] = self::SIN_EMPRESA_NO_HAY_NADA;
    }

    /**
     * Igual que filtrarPorSociedad pero para consultas armadas como texto.
     * Devuelve '' cuando el alcance es "todas", así la consulta no cambia.
     */
    protected function condicionSociedad($alias = '', ?array &$params = null)
    {
        if ($this->alcanceTodasPedido) {
            return '';
        }
        $id = $this->sociedadId();
        if ($id <= 0) {
            return ' AND ' . self::SIN_EMPRESA_NO_HAY_NADA;
        }
        if ($params !== null) {
            $params[] = $id;
        }
        return ' AND ' . $alias . 'sociedad_id = ?';
    }

    /**
     * La condición que no cumple ninguna fila. Va escrita así, y no como un
     * `LIMIT 0` o un `return []` antes de consultar, porque estos dos métodos
     * solo saben armar un WHERE: quien los llama sigue su camino normal —une
     * el resto de filtros, cuenta, pagina, suma— y recibe cero filas por la
     * vía de siempre, sin que haya que tocar los 51 sitios que los usan.
     */
    const SIN_EMPRESA_NO_HAY_NADA = '0 = 1';

    /**
     * La empresa con la que trabaja quien hace esta petición.
     *
     * Es POR USUARIO (sesión → preferencia guardada → valor por omisión), no
     * una marca global: dos personas pueden estar en empresas distintas al
     * mismo tiempo sin moverse la una a la otra.
     *
     * El caché y la resolución viven en Sociedad, no aquí: un `static` dentro
     * de un trait se duplica en cada clase que lo usa, así que habría un
     * caché por modelo y nada podría invalidarlos todos al cambiar de empresa.
     */
    public static function sociedadSeleccionadaId()
    {
        if (!class_exists('Sociedad')) {
            require_once __DIR__ . '/../models/Sociedad.php';
        }
        return Sociedad::seleccionadaIdActual();
    }
}
