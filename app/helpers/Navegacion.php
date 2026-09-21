<?php
/**
 * Los módulos que ESTE usuario puede usar, ya filtrados.
 *
 * Lee config/menu.php —la lista de qué existe— y le pregunta a Permisos por
 * cada entrada. Lo que devuelve es lo que se dibuja: ni la barra lateral ni
 * el panel vuelven a comprobar nada, porque si cada uno decidiera por su
 * cuenta volverían a enseñar cosas distintas.
 *
 * Una sección sin entradas no se devuelve. Un encabezado "Administración"
 * sobre un hueco no informa de nada y hace pensar que algo se rompió.
 *
 * ── Lo que no se puede y lo que todavía no se puede ───────────────────────
 * Son dos cosas distintas y se dibujan distinto. Lo que el rol no incluye
 * desaparece: no es para esa persona y enseñárselo solo la manda a pedir un
 * permiso que quizá no necesita. Lo que le falta un paso de configuración se
 * queda a la vista pero apagado, con el paso que falta escrito: ahí el
 * mensaje es "esto sí es tuyo, y se arregla así". Ver app/core/Requisitos.php.
 */

require_once __DIR__ . '/../core/Permisos.php';
require_once __DIR__ . '/../core/Requisitos.php';

class Navegacion
{
    /** La lista cruda, leída del disco una sola vez. */
    private static $catalogo = null;

    /** Lo ya filtrado para esta petición. */
    private static $secciones = null;

    /** Todo lo declarado, sin filtrar: lo usan las pruebas. */
    public static function catalogo(): array
    {
        if (self::$catalogo === null) {
            $archivo = __DIR__ . '/../config/menu.php';
            $leido = is_file($archivo) ? require $archivo : [];
            self::$catalogo = is_array($leido) ? $leido : [];
        }
        return self::$catalogo;
    }

    /**
     * Las secciones con lo que el usuario puede abrir, en el orden declarado.
     *
     *   ['modulos' => ['titulo' => 'Módulos', 'entradas' => ['correo' => [...]]]]
     *
     * Cada entrada llega con su 'id' puesto, para que quien la dibuje pueda
     * colgarle un contador sin volver a mirar la clave del array.
     *
     * Y con dos marcas más, que son las que deciden si se dibuja un enlace o
     * una pastilla apagada:
     *   'bloqueada' true cuando al módulo le falta un paso de configuración.
     *   'falta'     Los pasos que le faltan, ya declarados: el título, por
     *               qué hace falta y dónde se hace.
     */
    public static function secciones(): array
    {
        if (self::$secciones !== null) {
            return self::$secciones;
        }

        $resultado = [];
        foreach (self::catalogo() as $idSeccion => $seccion) {
            $entradas = [];
            foreach ($seccion['entradas'] ?? [] as $id => $entrada) {
                if (!self::permitida($entrada)) {
                    continue;
                }
                $entrada['id']    = $id;
                $entrada['falta'] = Requisitos::faltanDelModulo(self::moduloDe($entrada));
                $entrada['bloqueada'] = $entrada['falta'] !== [];
                $entradas[$id] = $entrada;
            }
            if ($entradas) {
                $resultado[$idSeccion] = [
                    'titulo'   => (string) ($seccion['titulo'] ?? ''),
                    'entradas' => $entradas,
                ];
            }
        }

        return self::$secciones = $resultado;
    }

    /** Las entradas permitidas en una sola lista, sin secciones. */
    public static function entradas(): array
    {
        $planas = [];
        foreach (self::secciones() as $seccion) {
            foreach ($seccion['entradas'] as $id => $entrada) {
                $planas[$id] = $entrada;
            }
        }
        return $planas;
    }

    /** Cuántos módulos puede abrir. Cero significa "rol sin nada asignado". */
    public static function cuantos(): int
    {
        return count(self::entradas());
    }

    /**
     * A qué módulo pertenece una entrada del menú.
     *
     * Se deduce de su permiso —'correo.ver' vive en el módulo 'correo'—, que
     * es EXACTAMENTE como lo deduce el enrutador para la ruta. Si se dedujera
     * de otra cosa, el menú podría dibujar abierto lo que la ruta cierra, y
     * volveríamos al atajo que contesta un error al pulsarlo.
     */
    private static function moduloDe(array $entrada): string
    {
        $permiso = $entrada['permiso'] ?? null;
        if ($permiso === null) {
            return '';
        }
        if (is_array($permiso)) {
            $permiso = reset($permiso);
        }
        return Permisos::moduloDe((string) $permiso);
    }

    /** Cuántos módulos están a la vista pero cerrados por falta de un paso. */
    public static function cuantosBloqueados(): int
    {
        $cuantos = 0;
        foreach (self::entradas() as $entrada) {
            if (!empty($entrada['bloqueada'])) {
                $cuantos++;
            }
        }
        return $cuantos;
    }

    /** Si el usuario tiene la llave de una entrada (lista = con una basta). */
    private static function permitida(array $entrada): bool
    {
        $permiso = $entrada['permiso'] ?? null;
        if ($permiso === null) {
            return true;
        }
        return is_array($permiso)
            ? Permisos::puedeAlguno($permiso)
            : Permisos::puede((string) $permiso);
    }

    /** Olvida lo filtrado. Lo llaman los cambios de rol y las pruebas. */
    public static function olvidar(): void
    {
        self::$secciones = null;
    }
}
