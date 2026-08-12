<?php
/**
 * Que lo marcado con `hidden` empiece realmente oculto.
 *
 * El navegador oculta con la regla `[hidden] { display: none }`, que es de
 * menor especificidad que cualquier selector de clase. Si una clase declara
 * `display:` y no hay una regla `[hidden]` que la contrarreste, el elemento
 * nace visible y el botón de cerrar no puede cerrarlo: poner el atributo desde
 * JavaScript no cambia nada de lo que se ve.
 *
 * Pasó de verdad con la pantalla de seguimiento: al entrar salían abiertos el
 * panel de expediente y el diálogo de acción, encima de la tabla y sin forma
 * de salir. Esta prueba lo detecta sin abrir un navegador.
 */

function assertOculto($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$vistas = glob(__DIR__ . '/../app/views/**/*.php') ?: [];
$revisadas = 0;
$comprobaciones = 0;

foreach ($vistas as $vista) {
    $html = file_get_contents($vista);
    if ($html === false || stripos($html, 'hidden') === false) {
        continue;
    }
    $revisadas++;

    // Las clases de los elementos que nacen con el atributo `hidden`.
    if (!preg_match_all('/<[a-z]+[^>]*\bclass="([^"]+)"[^>]*\shidden(?=[\s>])/i', $html, $conClase)) {
        continue;
    }

    // Las reglas CSS del propio archivo (estas vistas llevan su <style>).
    $reglas = [];
    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $html, $css, PREG_SET_ORDER)) {
        foreach ($css as $regla) {
            $reglas[] = ['selector' => trim($regla[1]), 'cuerpo' => $regla[2]];
        }
    }

    foreach ($conClase[1] as $listaClases) {
        foreach (preg_split('/\s+/', trim($listaClases)) as $clase) {
            if ($clase === '') {
                continue;
            }

            // ¿Alguna regla le da `display` a esta clase?
            $declaraDisplay = false;
            foreach ($reglas as $r) {
                if (strpos($r['selector'], '[hidden]') !== false) {
                    continue;
                }
                if (preg_match('/(^|[\s,])\.' . preg_quote($clase, '/') . '($|[\s,:{])/', $r['selector'] . ' ')
                    && preg_match('/(^|[;\s])display\s*:/i', $r['cuerpo'])) {
                    $declaraDisplay = true;
                    break;
                }
            }
            if (!$declaraDisplay) {
                continue;
            }

            // Si lo declara, tiene que haber una regla que lo apague con [hidden].
            $tieneGuardia = false;
            foreach ($reglas as $r) {
                if (preg_match('/\.' . preg_quote($clase, '/') . '\[hidden\]/', $r['selector'])
                    && preg_match('/display\s*:\s*none/i', $r['cuerpo'])) {
                    $tieneGuardia = true;
                    break;
                }
            }

            $comprobaciones++;
            assertOculto(
                $tieneGuardia,
                basename($vista) . ": .{$clase} lleva el atributo hidden y declara display, "
                . "pero no hay una regla .{$clase}[hidden]{display:none}. "
                . 'El elemento va a nacer visible y no se va a poder cerrar.'
            );
        }
    }
}

echo "OK: VistaOcultos ({$comprobaciones} clases comprobadas en {$revisadas} vistas)\n";
