<?php
/**
 * Cada cuánto hay que volver a preguntarle a una carpeta si cambió algo.
 *
 * La sincronización recorre las carpetas del buzón preguntándole a cada una
 * cuántos mensajes tiene ahora. Es una pregunta barata —un viaje de ida y
 * vuelta— pero son muchas, y el viaje cuesta lo que cuesta la red: contra
 * mail.bm.cr son unos 105 ms que no dependen de esta computadora.
 *
 * Hasta acá todas las carpetas se preguntaban con la misma frecuencia, y eso
 * no aguanta el crecimiento: el costo es carpetas × buzones, y con 17
 * sociedades son unas 1.680 carpetas contra las 106 de hoy. La vuelta pasaría
 * de 58 segundos a 14 minutos, con dos minutos y medio de presupuesto.
 *
 * Pero no todas las carpetas se parecen. En el buzón real de hoy, 88 de las
 * 106 no reciben un correo desde hace más de dos semanas: son las carpetas de
 * meses cerrados —"CORREOS 2024/10 OCTUBRE"— donde ya no entra nada. Se
 * estaban revisando cada cinco minutos igual que la bandeja de entrada.
 *
 * Así que hay tres ritmos:
 *
 *   Base      La carpeta donde entra el correo nuevo. Es la que importa y se
 *             mira casi seguido.
 *   Activa    Recibió algo en las últimas dos semanas. Sigue viva.
 *   Archivo   Nada desde hace más de dos semanas. Solo cambia cuando una
 *             persona archiva un correo a mano, y eso no es urgente.
 *
 * Una carpeta de archivo sigue revisándose cada hora, no se abandona: si
 * alguien archiva algo, aparece en el índice dentro de la hora. Lo que se deja
 * de hacer es preguntarle doce veces por hora a una carpeta de 2024.
 *
 * Con los números reales eso baja la vuelta de 106 carpetas por corrida a 26.
 *
 * ── Por qué hace falta ordenar por urgencia y no por antigüedad ──
 *
 * Antes las carpetas se atendían de la más vieja a la más nueva, que con un
 * solo ritmo era lo correcto. Con tres ritmos deja de serlo: una carpeta de
 * archivo revisada hace 50 minutos lleva más tiempo sin mirarse que la bandeja
 * de entrada revisada hace 4 —pero la bandeja ya está vencida y la de archivo
 * no. Ordenar por antigüedad pura pondría el archivo primero y dejaría el
 * correo nuevo esperando.
 *
 * Por eso se ordena por cuánto se pasó cada carpeta de SU propio plazo.
 */
class RitmoCarpetas
{
    /** La carpeta donde entra el correo: es a la que se le presta atención. */
    const VENTANA_BASE = 60;

    /** Recibió algo hace poco. Es el ritmo que tenían todas hasta ahora. */
    const VENTANA_ACTIVA = 300;

    /** Meses cerrados: solo cambian si alguien archiva a mano. */
    const VENTANA_ARCHIVO = 3600;

    /** Cuánto tiene que estar quieta una carpeta para pasar a archivo. */
    const DIAS_PARA_ENFRIARSE = 14;

    /**
     * Cada cuántos segundos volver a preguntarle a esta carpeta.
     *
     * @param bool     $esBase     Si es la carpeta donde entra el correo.
     * @param int|null $edadCambio Segundos desde el último cambio detectado en
     *                             ella, o null si nunca se anotó uno.
     */
    public static function ventana($esBase, $edadCambio)
    {
        if ($esBase) {
            return self::VENTANA_BASE;
        }

        // Sin cambio anotado es archivo, no lo contrario. Son las carpetas que
        // el servidor lista pero que nunca tuvieron nada: en el buzón real son
        // 217 de 323 —estructura de carpetas vacías, o carpetas cuyos mensajes
        // ya venció la retención—. "La hemos mirado muchas veces y nunca
        // encontramos nada" es justamente la definición de carpeta quieta.
        //
        // Esto no se aplica a una carpeta que todavía no se sincronizó nunca:
        // esa siempre toca y va primera, y de eso se encargan toca() y
        // urgencia() mirando edad_sync, no esta ventana.
        if ($edadCambio === null) {
            return self::VENTANA_ARCHIVO;
        }

        return (int) $edadCambio > self::DIAS_PARA_ENFRIARSE * 86400
            ? self::VENTANA_ARCHIVO
            : self::VENTANA_ACTIVA;
    }

    /**
     * Si a esta carpeta ya le toca. Una que nunca se sincronizó siempre toca:
     * es la única forma de que entre al índice la primera vez.
     *
     * @param int|null $edadSync Segundos desde la última revisión, o null.
     */
    public static function toca($edadSync, $ventana)
    {
        return $edadSync === null || (int) $edadSync >= (int) $ventana;
    }

    /**
     * Cuánto se pasó una carpeta de su propio plazo, para ordenar la cola.
     *
     * 1.0 es "justo vencida", 2.0 es "lleva el doble de su plazo". Comparar
     * esto entre carpetas de ritmos distintos es comparar peras con peras;
     * comparar sus antigüedades a secas, no.
     *
     * Una carpeta nunca sincronizada va primero que cualquier otra.
     */
    public static function urgencia($edadSync, $ventana)
    {
        if ($edadSync === null) {
            return INF;
        }
        $ventana = max(1, (int) $ventana);

        return (int) $edadSync / $ventana;
    }

    /**
     * Ordena las carpetas por urgencia, de la más vencida a la menos, y deja
     * fuera las que todavía están dentro de su plazo.
     *
     * @param array $carpetas Cada una: ['carpeta' => string, 'es_base' => bool,
     *                        'edad_sync' => int|null, 'edad_cambio' => int|null]
     * @return array Los nombres de las carpetas que toca revisar, en orden.
     */
    public static function porRevisar(array $carpetas)
    {
        $cola = [];
        foreach ($carpetas as $c) {
            $ventana = self::ventana(!empty($c['es_base']), $c['edad_cambio'] ?? null);
            $edadSync = $c['edad_sync'] ?? null;
            if (!self::toca($edadSync, $ventana)) {
                continue;
            }
            $cola[] = [
                'carpeta'  => (string) $c['carpeta'],
                'urgencia' => self::urgencia($edadSync, $ventana),
            ];
        }

        // Estable en el desempate por nombre: sin eso, dos carpetas igual de
        // vencidas pueden alternarse entre tandas y ninguna terminar nunca.
        usort($cola, function ($a, $b) {
            return $b['urgencia'] <=> $a['urgencia']
                ?: strcmp($a['carpeta'], $b['carpeta']);
        });

        return array_map(function ($c) { return $c['carpeta']; }, $cola);
    }

    /** Para el registro y la pantalla: cuántas hay de cada ritmo. */
    public static function resumen(array $carpetas)
    {
        $conteo = ['base' => 0, 'activas' => 0, 'archivo' => 0];
        foreach ($carpetas as $c) {
            if (!empty($c['es_base'])) {
                $conteo['base']++;
                continue;
            }
            $ventana = self::ventana(false, $c['edad_cambio'] ?? null);
            $conteo[$ventana === self::VENTANA_ARCHIVO ? 'archivo' : 'activas']++;
        }

        return $conteo;
    }
}
