<?php
/**
 * El filtro de clase de nota, igual en todos los listados.
 *
 * Se eligen VARIAS a la vez. El trabajo se reparte por clase —una nota directa
 * se persigue distinto que una de diferencia de costo—, así que "directas y
 * por revisar" es una pregunta corriente; con un desplegable de una sola
 * opción había que recorrer la lista tantas veces como clases hicieran falta.
 *
 * El valor viaja como texto separado por comas en un campo escondido
 * ('directa,revisar') y no como clase[]: así el filtro sigue siendo una cadena
 * y ni la sesión que lo recuerda, ni los enlaces de las pestañas, ni el export
 * tienen que aprender a manejar arreglos.
 *
 * Uso desde una vista, dentro de un <form class="filter-bar">:
 *
 *   <?php $claseFiltro = [
 *       'valor'   => $filtros['clase'],
 *       'clases'  => Seguimiento::CLASES,   // o ClaseNotaCredito::ETIQUETAS
 *   ]; include __DIR__ . '/../partials/filtro-clase.php'; ?>
 *
 * Cada pantalla pasa las clases que puede contener: Seguimiento no ofrece
 * 'ajuste' porque esas notas nunca entran a su cola.
 */

require_once __DIR__ . '/../../helpers/ClaseNotaCredito.php';

$claseCfg = array_merge([
    'nombre'   => 'clase',
    'valor'    => '',
    'clases'   => ClaseNotaCredito::ETIQUETAS,
    'etiqueta' => 'Clase de nota',
    'id'       => 'filtro-clase',
], is_array($claseFiltro ?? null) ? $claseFiltro : []);

$claseOpciones = is_array($claseCfg['clases']) ? $claseCfg['clases'] : [];
$claseElegidas = ClaseNotaCredito::clasesPedidas($claseCfg['valor'], array_keys($claseOpciones));
$claseId       = (string) $claseCfg['id'];
?>
<div class="clase-picker<?= $claseElegidas ? ' is-elegido' : '' ?>" data-clase-picker>
    <label class="filter-label" for="<?= $claseId ?>-btn"><?= htmlspecialchars($claseCfg['etiqueta']) ?></label>

    <input type="hidden" name="<?= htmlspecialchars($claseCfg['nombre']) ?>"
           value="<?= htmlspecialchars(implode(',', $claseElegidas)) ?>" data-clase-valor>

    <button type="button" class="form-control clase-picker-boton" id="<?= $claseId ?>-btn"
            aria-haspopup="true" aria-expanded="false" data-clase-boton>
        <span data-clase-etiqueta>
            <?php if (!$claseElegidas): ?>
            Todas
            <?php elseif (count($claseElegidas) === 1): ?>
            <?= htmlspecialchars($claseOpciones[$claseElegidas[0]]) ?>
            <?php else: ?>
            <?= count($claseElegidas) ?> clases
            <?php endif; ?>
        </span>
        <i class="fas fa-chevron-down clase-picker-flecha"></i>
    </button>

    <div class="clase-picker-panel" data-clase-panel hidden>
        <?php foreach ($claseOpciones as $claseClave => $claseTexto): ?>
        <label class="clase-picker-opcion">
            <input type="checkbox" value="<?= htmlspecialchars((string) $claseClave) ?>"
                   <?= in_array((string) $claseClave, $claseElegidas, true) ? 'checked' : '' ?>
                   data-clase-casilla>
            <span><?= htmlspecialchars((string) $claseTexto) ?></span>
        </label>
        <?php endforeach; ?>
        <div class="clase-picker-pie">
            <button type="button" class="btn btn-outline btn-sm" data-clase-limpiar>Todas</button>
        </div>
    </div>
</div>
<?php unset($claseFiltro, $claseCfg, $claseOpciones, $claseElegidas, $claseId, $claseClave, $claseTexto); ?>
