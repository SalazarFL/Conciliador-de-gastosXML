<?php
/**
 * Facturas que alguien sacó del listado del ERP.
 *
 * El reporte las sigue trayendo y cada carga las salta. Esta pantalla existe
 * para que esa decisión no sea invisible: quién la tomó, cuándo, por qué, y
 * cuántas cargas la aplicaron. Y para deshacerla.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$quitadas = is_array($quitadas ?? null) ? $quitadas : [];
$puedeDevolver = !empty($puedeDevolver);

function fqFecha($f, $formato = 'd/m/Y')
{
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date($formato, $ts) : '—';
}
?>

<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-box-archive" style="margin-right:6px;color:var(--navy-light);"></i>
            Facturas quitadas del listado
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;">
                <?= number_format(count($quitadas)) ?>
            </span>
        </div>
        <a href="<?= $baseUrl ?>/facturas-erp" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Volver al listado
        </a>
    </div>

    <p class="muted" style="font-size:12px;margin:0 0 10px;">
        El reporte del ERP las sigue trayendo, pero cada carga las salta. Devolver una la vuelve
        a poner en el listado tal como estaba.
    </p>

    <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:12.5px;min-width:900px;">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Proveedor</th>
                    <th>Emitida</th>
                    <th class="right">Monto</th>
                    <th>Motivo</th>
                    <th>Quitada</th>
                    <th class="right" title="Cuántas cargas del reporte la trajeron y la saltaron">Saltada</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$quitadas): ?>
                <tr>
                    <td colspan="8" class="muted" style="text-align:center;padding:18px;">
                        No hay facturas quitadas en esta empresa.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($quitadas as $q):
                    $doc = (string) $q['documento'];
                    $etiqueta = $q['tipo'] . '-' . ($doc !== '' ? $doc : 'sin número');
                ?>
                <tr>
                    <td style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:650;">
                        <?= htmlspecialchars($etiqueta) ?>
                    </td>
                    <td>
                        <?= htmlspecialchars((string) $q['proveedor_nombre']) ?>
                        <?php if ((string) $q['proveedor_codigo'] !== ''): ?>
                        <div class="muted" style="font-size:10.5px;"><?= htmlspecialchars($q['proveedor_codigo']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?= fqFecha($q['fecha_emision']) ?></td>
                    <td class="right" style="white-space:nowrap;">
                        <?= $q['monto'] !== null
                            ? (strpos((string) $q['moneda'], '$') !== false ? '$' : '₡') . number_format((float) $q['monto'], 2)
                            : '—' ?>
                    </td>
                    <td style="font-size:11.5px;">
                        <?= $q['motivo'] !== null && $q['motivo'] !== ''
                            ? htmlspecialchars((string) $q['motivo']) : '<span class="muted">—</span>' ?>
                    </td>
                    <td class="muted" style="font-size:11px;white-space:nowrap;">
                        <?= fqFecha($q['creado_en'], 'd/m/Y H:i') ?>
                        <?php if (!empty($q['usuario_nombre'])): ?>
                        <div><?= htmlspecialchars((string) $q['usuario_nombre']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="right muted"><?= (int) $q['veces_saltada'] ?></td>
                    <td style="white-space:nowrap;">
                        <?php if ($puedeDevolver): ?>
                        <form method="POST" action="<?= $baseUrl ?>/facturas-erp/quitadas/devolver" style="margin:0;"
                              data-confirm="<?= htmlspecialchars($etiqueta . ' de ' . $q['proveedor_nombre'] . ' vuelve al listado, y las próximas cargas del reporte la van a actualizar como a cualquier otra.') ?>"
                              data-confirm-title="Devolver al listado"
                              data-confirm-type="info"
                              data-confirm-accept="Devolver">
                            <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 9px;font-size:11px;">
                                <i class="fas fa-rotate-left" style="margin-right:3px;"></i>Devolver
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
