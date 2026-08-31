<?php
/**
 * Una caja para buscar por importe, igual en todos los listados.
 *
 * Se escribe el monto que se tiene a mano y salen los documentos cuyo importe
 * coincide. Aquí hubo dos cajas —"desde" y "hasta"— y estaban de más: quien
 * busca un pago tiene el número delante y quiere encontrar ESE documento, no
 * acotar un tramo.
 *
 * La regla de la coincidencia vive en app/helpers/BusquedaImporte.php.
 *
 * Uso desde una vista, dentro de un <form class="filter-bar">:
 *
 *   $filtroImporte = [
 *       'nombre'   => 'monto',
 *       'etiqueta' => 'Monto',
 *       'valor'    => $filtros['monto'],
 *   ];
 *   include __DIR__ . '/../partials/filtro-importe.php';
 */

$impCfg = array_merge([
    'nombre'   => 'monto',
    'etiqueta' => 'Monto',
    'valor'    => '',
    'ayuda'    => '',
], is_array($filtroImporte ?? null) ? $filtroImporte : []);

$impNombre = (string) $impCfg['nombre'];
$impValor  = (string) $impCfg['valor'];
?>
<div>
    <label class="filter-label" for="importe-<?= htmlspecialchars($impNombre) ?>">
        <?= htmlspecialchars((string) $impCfg['etiqueta']) ?>
        <?php if ($impValor !== ''): ?><span class="importe-puesto" title="Este filtro está aplicado">•</span><?php endif; ?>
    </label>
    <?php /*
     * type="text" y no type="number": las flechitas del navegador no sirven de
     * nada con importes de seis cifras, y con number el pegado de
     * "₡370,639.06" se descarta entero en vez de limpiarse. El teclado
     * numérico del teléfono se pide con inputmode.
     */ ?>
    <input type="search" inputmode="decimal" class="form-control importe-caja"
           id="importe-<?= htmlspecialchars($impNombre) ?>"
           name="<?= htmlspecialchars($impNombre) ?>"
           value="<?= htmlspecialchars($impValor) ?>"
           placeholder="Buscar importe"
           title="Escribí el importe, entero o a medias: 127725 encuentra ₡127,725.56.">
    <?php if ((string) $impCfg['ayuda'] !== ''): ?>
    <div class="importe-ayuda"><?= htmlspecialchars((string) $impCfg['ayuda']) ?></div>
    <?php endif; ?>
</div>
<?php unset($impCfg, $impNombre, $impValor, $filtroImporte); ?>
