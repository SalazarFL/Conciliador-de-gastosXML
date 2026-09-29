<?php
/**
 * Qué columnas se ven, aplicado ANTES de que la página se pinte.
 *
 * Uso, justo antes de la tabla:
 *
 *     <?php $columnasListado = 'facturas'; include __DIR__ . '/../partials/columnas.php'; ?>
 *
 * Son dos cosas y las dos hacen falta:
 *
 *   la hoja de estilo   Sale del servidor con las columnas que vienen
 *                       apagadas de fábrica ya escondidas. Si esto lo hiciera
 *                       solo el JavaScript, la tabla se pintaría entera y las
 *                       columnas de más desaparecerían a la vista de todos, en
 *                       cada carga de cada listado.
 *
 *   el guion de dos     Reescribe esa hoja con lo que esta persona eligió,
 *   líneas              guardado en el navegador. Va aquí, en medio del
 *                       documento y no al final, para que corra antes de que
 *                       el navegador dibuje la tabla que viene debajo. Es el
 *                       mismo truco con el que el correo abre su panel de
 *                       carpetas sin animación en cada recarga.
 *
 * Una columna que pasó a venir encendida de fábrica DESPUÉS de que alguien
 * guardó su elección (Columnas::nuevas) se le enciende igual, al lado de la
 * que tenía antes en el catálogo: si no, quien ya había acomodado sus
 * columnas no la vería nunca. La fecha de su elección se guarda con ella
 * ('columnas:<listado>:fecha'), y una elección más nueva que la columna ya
 * sabe de ella: si la apagó, se queda apagada.
 *
 * De ahí en adelante manda app.js (bloque "QUÉ COLUMNAS SE VEN"), que pone el
 * botón de la esquina y reescribe esta misma hoja cada vez que se toca una
 * casilla. La forma de la regla está escrita en los dos sitios —acá y allá— y
 * tiene que ser la misma; es el precio de que no haya parpadeo.
 */

require_once __DIR__ . '/../../helpers/Columnas.php';

$colsListado  = (string) ($columnasListado ?? '');
$colsCatalogo = Columnas::catalogo($colsListado);

if ($colsCatalogo):
    $colsTodas    = array_keys($colsCatalogo);
    $colsDefecto  = Columnas::porDefecto($colsListado);
    $colsApagadas = array_values(array_diff($colsTodas, $colsDefecto));
    $colsNuevas   = Columnas::nuevas($colsListado);

    /** La regla que esconde una lista de columnas de este listado. */
    $colsRegla = static function (array $ids) use ($colsListado) {
        if (!$ids) { return ''; }
        $trozos = [];
        foreach ($ids as $id) {
            $trozos[] = '[data-listado="' . $colsListado . '"] [data-col="' . $id . '"]';
        }
        return implode(',', $trozos) . '{display:none}';
    };
?>
<style id="cols-<?= htmlspecialchars($colsListado, ENT_QUOTES) ?>"><?= $colsRegla($colsApagadas) ?></style>
<script>
(function () {
    var listado = <?= json_encode($colsListado, JSON_UNESCAPED_UNICODE) ?>;
    var todas   = <?= json_encode($colsTodas, JSON_UNESCAPED_UNICODE) ?>;
    var puestas = <?= json_encode($colsDefecto, JSON_UNESCAPED_UNICODE) ?>;
    var nuevas  = <?= json_encode((object) $colsNuevas, JSON_UNESCAPED_UNICODE) ?>;

    // app.js necesita lo mismo para repintar: el catálogo en su orden y qué
    // columnas son nuevas. Se deja a mano en vez de volver a escribirlo allá.
    window.columnasCatalogo = window.columnasCatalogo || {};
    window.columnasCatalogo[listado] = { orden: todas, nuevas: nuevas };

    try {
        var crudo = localStorage.getItem('columnas:' + listado);
        var elegidas = crudo ? JSON.parse(crudo) : null;
        if (Array.isArray(elegidas) && elegidas.length) {
            // Solo las que este listado sigue teniendo: una elección de hace
            // meses puede nombrar una columna que ya no existe.
            var vivas = elegidas.filter(function (id) { return todas.indexOf(id) >= 0; });
            // Y las que se encendieron de fábrica después de esa elección,
            // cada una detrás de la que tiene antes en el catálogo.
            var fecha = localStorage.getItem('columnas:' + listado + ':fecha') || '';
            todas.forEach(function (id, i) {
                if (!nuevas[id] || nuevas[id] <= fecha || vivas.indexOf(id) >= 0) { return; }
                var lugar = 0;
                for (var j = i - 1; j >= 0; j--) {
                    var k = vivas.indexOf(todas[j]);
                    if (k >= 0) { lugar = k + 1; break; }
                }
                vivas.splice(lugar, 0, id);
            });
            if (vivas.length) { puestas = vivas; }
        }
    } catch (e) {
        // Sin navegador que recuerde, valen las de fábrica: la hoja que ya
        // vino del servidor se queda como está.
        return;
    }

    var apagadas = todas.filter(function (id) { return puestas.indexOf(id) < 0; });
    var hoja = document.getElementById('cols-' + listado);
    if (!hoja) { return; }
    hoja.textContent = apagadas.length
        ? '[data-listado="' + listado + '"] [data-col="'
            + apagadas.join('"],[data-listado="' + listado + '"] [data-col="') + '"]{display:none}'
        : '';
})();
</script>
<?php endif; ?>
