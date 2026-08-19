<?php
/**
 * El filtro de proveedor, igual en todos los listados.
 *
 * Un desplegable con buscador propio. Se busca por código, por cédula o por
 * nombre, y lo que manda es el código y la cédula: son los que identifican al
 * proveedor de verdad. El nombre va como referencia, en gris, porque el mismo
 * proveedor se escribe de tres formas distintas según quién lo haya tecleado.
 *
 * Uso desde una vista, dentro de un <form class="filter-bar">:
 *
 *   <?php $provFiltro = [
 *       'valor'    => $filtros['proveedor'],
 *       'opciones' => $proveedoresFiltro,
 *   ]; include __DIR__ . '/../partials/filtro-proveedor.php'; ?>
 *
 * Al elegir, el formulario se envía solo (como los demás desplegables de la
 * barra). 'autosubmit' => false lo deja esperando al botón Buscar.
 */

// La vista puede llegar aquí sin que el controlador haya cargado el catálogo:
// lo necesita para describir un proveedor elegido que no esté en la lista.
require_once __DIR__ . '/../../models/ProveedorCatalogo.php';

$provCfg = array_merge([
    'nombre'     => 'proveedor',
    'valor'      => '',
    'opciones'   => [],
    'etiqueta'   => 'Proveedor',
    'ancho'      => true,
    'autosubmit' => true,
], is_array($provFiltro ?? null) ? $provFiltro : []);

$provValor    = (string) $provCfg['valor'];
$provOpciones = is_array($provCfg['opciones']) ? $provCfg['opciones'] : [];
$provId       = 'prov-' . substr(md5($provCfg['nombre'] . '|' . count($provOpciones) . '|' . uniqid('', true)), 0, 8);

/**
 * El proveedor elegido puede no estar en la lista de este listado: se llegó
 * con el filtro puesto desde otra pantalla, o sus documentos ya no están.
 * Se agrega igual, para que el filtro diga qué está aplicado en vez de
 * mostrarse vacío.
 */
$provSeleccionada = null;
foreach ($provOpciones as $provOpcion) {
    if ((string) $provOpcion['clave'] === $provValor) {
        $provSeleccionada = $provOpcion;
        break;
    }
}
if ($provValor !== '' && $provSeleccionada === null) {
    $provSeleccionada = ProveedorCatalogo::opcionSuelta($provValor);
    if ($provSeleccionada !== null) {
        array_unshift($provOpciones, $provSeleccionada);
    }
}

/**
 * Cada opción se escribe en una sola línea y sin sangría: un listado con
 * cuatrocientos proveedores multiplica por cuatrocientos cada espacio.
 */
$provOpcionHtml = static function (array $opcion, $elegida) {
    $codigos = implode(', ', $opcion['codigos']);
    // Lo que se busca es lo que se ve: el buscador arma su índice con el
    // texto de la fila. Repetirlo en un atributo sería duplicar el listado
    // entero dentro del HTML, y dos formas de escribirlo que se pueden
    // desincronizar.
    $html = '<li class="prov-opcion' . ($elegida ? ' is-elegida' : '') . '" role="option"'
          . ($elegida ? ' aria-selected="true"' : '')
          . ' data-valor="' . htmlspecialchars((string) $opcion['clave']) . '">';

    $html .= '<span class="prov-opcion-claves">';
    if ($codigos !== '') {
        $html .= '<span class="prov-cod">' . htmlspecialchars($codigos) . '</span>';
    }
    if ($opcion['cedula'] !== '') {
        $html .= '<span class="prov-ced">' . htmlspecialchars($opcion['cedula']) . '</span>';
    }
    $html .= '</span>';

    $nombre = $opcion['nombre'] !== '' ? $opcion['nombre'] : 'Sin nombre';
    $html .= '<span class="prov-nom" title="' . htmlspecialchars($nombre) . '">'
           . htmlspecialchars($nombre) . '</span>';

    if ((int) $opcion['n'] > 0) {
        $html .= '<span class="prov-n">' . number_format((int) $opcion['n']) . '</span>';
    }

    return $html . '</li>';
};
?>
<div class="<?= $provCfg['ancho'] ? 'filter-span-2' : '' ?>">
    <label class="filter-label" for="<?= $provId ?>-btn"><?= htmlspecialchars($provCfg['etiqueta']) ?></label>
    <div class="prov-picker<?= $provValor !== '' ? ' is-elegido' : '' ?>" data-prov-picker
         <?= $provCfg['autosubmit'] ? 'data-prov-autosubmit="1"' : '' ?>>
        <input type="hidden" name="<?= htmlspecialchars($provCfg['nombre']) ?>"
               value="<?= htmlspecialchars($provValor) ?>" data-prov-valor>
        <button type="button" class="form-control prov-picker-boton" id="<?= $provId ?>-btn"
                aria-haspopup="listbox" aria-expanded="false" data-prov-boton>
            <span class="prov-picker-elegido" data-prov-etiqueta>
                <?php if ($provSeleccionada === null): ?>
                Todos los proveedores
                <?php else: ?>
                    <?php if ($provSeleccionada['codigos']): ?>
                    <span class="prov-cod"><?= htmlspecialchars(implode(', ', $provSeleccionada['codigos'])) ?></span>
                    <?php elseif ($provSeleccionada['cedula'] !== ''): ?>
                    <span class="prov-cod"><?= htmlspecialchars($provSeleccionada['cedula']) ?></span>
                    <?php endif; ?>
                    <span class="prov-nom"><?= htmlspecialchars($provSeleccionada['nombre'] !== '' ? $provSeleccionada['nombre'] : 'Sin nombre') ?></span>
                <?php endif; ?>
            </span>
            <i class="fas fa-chevron-down prov-picker-flecha"></i>
        </button>

        <div class="prov-picker-panel" data-prov-panel hidden>
            <div class="prov-picker-buscador">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" class="prov-picker-campo" data-prov-buscar autocomplete="off"
                       placeholder="Código, cédula o nombre…" aria-label="Buscar proveedor">
            </div>
            <ul class="prov-picker-lista" role="listbox" data-prov-lista
                aria-label="Proveedores">
                <li class="prov-opcion prov-opcion-todos<?= $provValor === '' ? ' is-elegida' : '' ?>"
                    role="option" aria-selected="<?= $provValor === '' ? 'true' : 'false' ?>"
                    data-valor="" data-etiqueta="Todos los proveedores">
                    <i class="fas fa-layer-group"></i> Todos los proveedores
                </li>
                <?php foreach ($provOpciones as $provOpcion): ?>
<?= $provOpcionHtml($provOpcion, (string) $provOpcion['clave'] === $provValor) ?>
                <?php endforeach; ?>
            </ul>
            <div class="prov-picker-vacio" data-prov-vacio hidden>
                Ningún proveedor coincide con lo escrito.
            </div>
        </div>
    </div>
</div>
<?php unset($provFiltro, $provCfg, $provValor, $provOpciones, $provId, $provSeleccionada, $provOpcion, $provOpcionHtml); ?>
