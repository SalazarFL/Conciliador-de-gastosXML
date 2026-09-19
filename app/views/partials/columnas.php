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

    try {
        var crudo = localStorage.getItem('columnas:' + listado);
        var elegidas = crudo ? JSON.parse(crudo) : null;
        if (Array.isArray(elegidas) && elegidas.length) {
            // Solo las que este listado sigue teniendo: una elección de hace
            // meses puede nombrar una columna que ya no existe.
            var vivas = elegidas.filter(function (id) { return todas.indexOf(id) >= 0; });
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
