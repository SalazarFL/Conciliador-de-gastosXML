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
 */
trait AlcanceSociedad
{
    private $sociedadIdAlcance = null;

    /**
     * Fija la sociedad de este modelo. 0 = sin acotar (todas las empresas),
     * y hay que pedirlo explícitamente.
     */
    public function setSociedad($sociedadId)
    {
        $this->sociedadIdAlcance = max(0, (int) $sociedadId);
        return $this;
    }

    /** Id de la sociedad en la que trabaja este modelo (0 = todas). */
    public function sociedadId()
    {
        if ($this->sociedadIdAlcance === null) {
            $this->sociedadIdAlcance = (int) self::sociedadSeleccionadaId();
        }
        return $this->sociedadIdAlcance;
    }

    /**
     * Añade el filtro a una lista de condiciones WHERE.
     * $alias es el prefijo de la tabla en la consulta ('f.', 'e.', '').
     */
    protected function filtrarPorSociedad(array &$where, array &$params, $alias = '')
    {
        $id = $this->sociedadId();
        if ($id > 0) {
            $where[] = $alias . 'sociedad_id = ?';
            $params[] = $id;
        }
    }

    /**
     * Igual que filtrarPorSociedad pero para consultas armadas como texto.
     * Devuelve '' cuando el alcance es "todas", así la consulta no cambia.
     */
    protected function condicionSociedad($alias = '', ?array &$params = null)
    {
        $id = $this->sociedadId();
        if ($id <= 0) {
            return '';
        }
        if ($params !== null) {
            $params[] = $id;
        }
        return ' AND ' . $alias . 'sociedad_id = ?';
    }

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
