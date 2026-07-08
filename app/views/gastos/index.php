<?php
$baseUrl           = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$gastos            = $gastos ?? [];
$historial         = $historial ?? [];
$importacionActiva = $importacionActiva ?? null;
?>

<div class="page-header">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:var(--navy);">
            <i class="fas fa-file-csv" style="color:var(--gold);margin-right:8px;"></i>Carga de Gastos
        </h1>
    </div>
    <?php if (!empty($gastos)): ?>
    <span class="badge badge-gold" style="font-size:12px;padding:6px 14px;">
        <i class="fas fa-layer-group"></i>
        <?= count($gastos) ?> registro<?= count($gastos) !== 1 ? 's' : '' ?>
    </span>
    <?php endif; ?>
</div>

<!-- Subir Archivo CSV -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-upload" style="margin-right:6px;color:var(--navy-light);"></i>Subir Archivo CSV
            </div>
        </div>
    </div>

    <form method="post" action="<?= $baseUrl ?>/gastos/subir" enctype="multipart/form-data" id="form-gastos-upload">
        <input type="file" name="gastos_file" id="gastos_file" accept=".csv,.xlsx,.xls"
               style="display:none;" onchange="updateGastoDisplay(this)">

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-width:220px;">
                <label for="gastos_file" class="upload-file-btn">
                    <i class="fas fa-folder-open"></i> Seleccionar Archivo CSV
                </label>
                <span id="gasto-file-name" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                    Ningún archivo seleccionado
                </span>
            </div>

            <button type="submit" class="btn btn-gold btn-lg" id="btn-procesar-csv" style="margin-left:auto;">
                <i class="fas fa-file-import"></i> Importar
            </button>
        </div>
    </form>
</div>

<!-- Filtro rápido por importación -->
<?php if (!empty($historial)): ?>
<div class="card" style="margin-bottom:14px;padding:14px 22px;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:700;color:var(--navy);">
            <i class="fas fa-filter" style="margin-right:4px;"></i>Filtrar por importación:
        </span>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= $baseUrl ?>/gastos"
               class="btn btn-sm <?= empty($importacionActiva) ? 'btn-primary' : 'btn-outline' ?>">
                Todas
            </a>
            <?php foreach (array_slice($historial, 0, 8) as $imp): ?>
            <a href="<?= $baseUrl ?>/gastos?importacion_id=<?= (int)$imp['id'] ?>"
               class="btn btn-sm <?= (!empty($importacionActiva) && (int)$importacionActiva['id'] === (int)$imp['id']) ? 'btn-primary' : 'btn-outline' ?>"
               title="<?= htmlspecialchars($imp['archivo_origen']) ?>">
                <?= date('d/m', strtotime($imp['fecha_importacion'])) ?>
                <span class="badge badge-navy" style="font-size:10px;padding:1px 6px;margin-left:2px;">
                    <?= (int)$imp['registros_exitosos'] ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($importacionActiva)): ?>
        <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">
            Mostrando: <?= htmlspecialchars($importacionActiva['archivo_origen']) ?>
            &mdash; <?= date('d/m/Y H:i', strtotime($importacionActiva['fecha_importacion'])) ?>
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Gastos Importados -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-list" style="margin-right:6px;color:var(--navy-light);"></i>
                Gastos Importados
                <?php if (!empty($importacionActiva)): ?>
                <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:8px;">
                    — <?= htmlspecialchars($importacionActiva['archivo_origen']) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $baseUrl ?>/conciliacion" class="btn btn-gold btn-sm">
                <i class="fas fa-check-double"></i> Consolidar Gastos
            </a>
            <a href="<?= $baseUrl ?>/gastos?limpiar=1" class="btn btn-outline btn-sm">
                <i class="fas fa-broom"></i> Limpiar
            </a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Número Factura</th>
                    <th>Proveedor</th>
                    <th class="right">IVA</th>
                    <th class="right">Monto</th>
                    <th class="center">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gastos)): ?>
                    <tr class="empty-row">
                        <td colspan="6">
                            <i class="fas fa-inbox" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                            No hay gastos importados todavía.<br>
                            <span style="font-size:12px;">Usa el formulario de arriba para subir un archivo CSV o XLSX.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gastos as $g): ?>
                    <?php $fechaVis = $g['fecha_max'] ?? ($g['fecha_min'] ?? '—'); ?>
                    <tr>
                        <td class="muted"><?= htmlspecialchars($fechaVis) ?></td>
                        <td style="font-weight:700;color:var(--navy);">
                            <?= htmlspecialchars($g['numero_factura'] ?? '—') ?>
                        </td>
                        <td><?= htmlspecialchars($g['proveedor_texto'] ?? 'Sin proveedor') ?></td>
                        <td class="right"><?= number_format((float)($g['suma_iva'] ?? 0), 2) ?></td>
                        <td class="right" style="font-weight:700;"><?= number_format((float)($g['suma_total'] ?? 0), 2) ?></td>
                        <td class="center">
                            <span class="badge badge-green"><i class="fas fa-check-circle"></i> Importado</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Panel historial de importaciones de gastos -->
<?php if (!empty($historial)): ?>
<div class="history-panel">
    <button class="history-toggle" aria-expanded="false" onclick="toggleHistory(this)">
        <i class="fas fa-chevron-right toggle-icon"></i>
        <i class="fas fa-history" style="color:var(--navy-light);"></i>
        Historial de importaciones CSV/XLSX
        <span class="badge badge-navy" style="margin-left:4px;"><?= count($historial) ?></span>
    </button>
    <div class="history-body">
        <div class="card" style="padding:0;overflow:hidden;">
            <a href="<?= $baseUrl ?>/gastos"
               class="history-row <?= empty($importacionActiva) ? 'active' : '' ?>"
               style="text-decoration:none;border-bottom:1px solid var(--border);">
                <span class="history-row-date"><i class="fas fa-layer-group" style="margin-right:4px;opacity:.5;"></i>Todas las importaciones</span>
                <span class="history-row-file">Ver todos los gastos</span>
                <span class="history-row-stats">
                    <span class="badge badge-navy"><?= count($historial) ?> sesiones</span>
                </span>
            </a>
            <?php foreach ($historial as $imp): ?>
            <?php $esActiva = !empty($importacionActiva) && (int)$importacionActiva['id'] === (int)$imp['id']; ?>
            <a href="<?= $baseUrl ?>/gastos?importacion_id=<?= (int)$imp['id'] ?>"
               class="history-row <?= $esActiva ? 'active' : '' ?>"
               style="text-decoration:none;">
                <span class="history-row-date">
                    <i class="fas fa-calendar-alt" style="margin-right:4px;opacity:.5;"></i>
                    <?= date('d/m/Y H:i', strtotime($imp['fecha_importacion'])) ?>
                </span>
                <span class="history-row-file" title="<?= htmlspecialchars($imp['archivo_origen']) ?>">
                    <?= htmlspecialchars($imp['archivo_origen']) ?>
                </span>
                <span class="history-row-stats">
                    <?php if ((int)$imp['registros_exitosos'] > 0): ?>
                    <span class="badge badge-green"><?= (int)$imp['registros_exitosos'] ?> ok</span>
                    <?php endif; ?>
                    <?php if ((int)$imp['registros_fallidos'] > 0): ?>
                    <span class="badge" style="background:#fee2e2;color:#b91c1c;"><?= (int)$imp['registros_fallidos'] ?> err</span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function updateGastoDisplay(input) {
    var label = document.getElementById('gasto-file-name');
    if (input.files.length > 0) {
        label.textContent = input.files[0].name;
    } else {
        label.textContent = 'Ningún archivo seleccionado';
    }
}

function toggleHistory(btn) {
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!expanded));
    btn.nextElementSibling.classList.toggle('open', !expanded);
}
</script>
