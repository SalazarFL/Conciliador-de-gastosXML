<?php
$baseUrl           = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas          = $facturas ?? [];
$historial         = $historial ?? [];
$importacionActiva = $importacionActiva ?? null;
$phpMaxFiles       = max(1, (int) ini_get('max_file_uploads'));
?>

<!-- CARGA DE FACTURAS XML -->

<div class="page-header">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:var(--navy);">
            <i class="fas fa-file-invoice" style="color:var(--gold);margin-right:8px;"></i>Carga de Facturas
        </h1>
    </div>
    <?php if (!empty($facturas)): ?>
    <span class="badge badge-navy" style="font-size:12px;padding:6px 14px;">
        <i class="fas fa-layer-group"></i>
        <?= count($facturas) ?> factura<?= count($facturas) !== 1 ? 's' : '' ?>
        <?php if ($importacionActiva): ?>
        <span style="font-weight:400;opacity:.75;margin-left:4px;">— <?= htmlspecialchars($importacionActiva['archivo_origen']) ?></span>
        <?php endif; ?>
    </span>
    <?php endif; ?>
</div>

<!-- Subir Facturas XML -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-upload" style="margin-right:6px;color:var(--navy-light);"></i>Subir Facturas XML
            </div>
        </div>
    </div>

    <form method="post" action="<?= $baseUrl ?>/facturas/subir" enctype="multipart/form-data" id="form-xml-upload">
        <input type="file" name="xml_files[]" id="xml_files" multiple
               accept=".xml" style="display:none;" onchange="updateFileDisplay(this)">

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-width:220px;">
                <label for="xml_files" class="upload-file-btn">
                    <i class="fas fa-folder-open"></i> Seleccionar Archivos XML
                </label>
                <span id="files-selected-name" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                    Ningún archivo seleccionado
                </span>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" id="btn-procesar-xml" style="margin-left:auto;">
                <i class="fas fa-cogs"></i> Procesar XML
            </button>
        </div>
    </form>
</div>

<!-- Barra de filtro por importación -->
<?php if (!empty($historial)): ?>
<div class="card" style="margin-bottom:14px;padding:12px 20px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">
            <i class="fas fa-filter" style="margin-right:4px;"></i>Importación:
        </span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <a href="<?= $baseUrl ?>/facturas"
               class="btn btn-sm <?= empty($importacionActiva) ? 'btn-primary' : 'btn-outline' ?>">
                Todas
            </a>
            <?php foreach (array_slice($historial, 0, 8) as $imp): ?>
            <?php $esActiva = !empty($importacionActiva) && (int)$importacionActiva['id'] === (int)$imp['id']; ?>
            <a href="<?= $baseUrl ?>/facturas?importacion_id=<?= (int)$imp['id'] ?>"
               class="btn btn-sm <?= $esActiva ? 'btn-primary' : 'btn-outline' ?>"
               title="<?= htmlspecialchars($imp['archivo_origen']) ?>">
                <?= date('d/m/Y', strtotime($imp['fecha_importacion'])) ?>
                <span class="badge badge-navy" style="font-size:10px;padding:1px 6px;margin-left:3px;">
                    <?= (int)$imp['registros_exitosos'] ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if ($importacionActiva): ?>
        <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">
            <?= htmlspecialchars($importacionActiva['archivo_origen']) ?>
            &mdash; <?= date('d/m/Y H:i', strtotime($importacionActiva['fecha_importacion'])) ?>
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Facturas Importadas -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-list" style="margin-right:6px;color:var(--navy-light);"></i>Facturas Importadas
                <?php if ($importacionActiva): ?>
                <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:6px;">
                    (filtrado por importación del <?= date('d/m/Y', strtotime($importacionActiva['fecha_importacion'])) ?>)
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="<?= $baseUrl ?>/conciliacion" class="btn btn-outline btn-sm">
                <i class="fas fa-check-double"></i> Ir a Conciliación
            </a>
            <a href="<?= $baseUrl ?>/facturas?limpiar=1" class="btn btn-outline btn-sm">
                <i class="fas fa-broom"></i> Limpiar
            </a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Número Factura</th>
                    <th>Proveedor</th>
                    <th>Fecha</th>
                    <th class="right">Subtotal</th>
                    <th class="right">IVA</th>
                    <th class="right">Total</th>
                    <th class="center">Estado</th>
                    <th class="center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($facturas)): ?>
                    <tr class="empty-row">
                        <td colspan="8">
                            <i class="fas fa-inbox" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                            No hay facturas importadas todavía.<br>
                            <span style="font-size:12px;">Usa el formulario de arriba para subir archivos XML.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($facturas as $f): ?>
                    <tr>
                        <td>
                            <span style="font-weight:700;color:var(--navy);">
                                <?= htmlspecialchars($f['numero_factura_asistente'] ?? $f['consecutivo_completo'] ?? '—') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($f['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
                        <td class="muted"><?= htmlspecialchars($f['fecha_emision'] ?? '—') ?></td>
                        <td class="right muted"><?= number_format((float)($f['subtotal'] ?? 0), 2) ?></td>
                        <td class="right muted"><?= number_format((float)($f['iva'] ?? 0), 2) ?></td>
                        <td class="right" style="font-weight:700;"><?= number_format((float)($f['total'] ?? 0), 2) ?></td>
                        <td class="center">
                            <span class="badge badge-green"><i class="fas fa-check-circle"></i> Importado</span>
                        </td>
                        <td class="center">
                            <a href="<?= $baseUrl ?>/facturas/ver/<?= (int)$f['id'] ?>"
                               class="btn btn-outline btn-sm" title="Ver detalle">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Panel historial completo -->
<?php if (!empty($historial)): ?>
<div class="history-panel">
    <button class="history-toggle" aria-expanded="false" onclick="toggleHistory(this)">
        <i class="fas fa-chevron-right toggle-icon"></i>
        <i class="fas fa-history" style="color:var(--navy-light);"></i>
        Historial de importaciones XML
        <span class="badge badge-navy" style="margin-left:4px;"><?= count($historial) ?></span>
    </button>
    <div class="history-body">
        <div class="card" style="padding:0;overflow:hidden;">
            <?php foreach ($historial as $imp): ?>
            <?php $esActiva = !empty($importacionActiva) && (int)$importacionActiva['id'] === (int)$imp['id']; ?>
            <a href="<?= $baseUrl ?>/facturas?importacion_id=<?= (int)$imp['id'] ?>"
               class="history-row <?= $esActiva ? 'active' : '' ?>">
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
function updateFileDisplay(input) {
    var label = document.getElementById('files-selected-name');
    var count = input.files.length;
    if (count === 0) {
        label.textContent = 'Ningún archivo seleccionado';
    } else if (count === 1) {
        label.textContent = input.files[0].name;
    } else {
        label.textContent = count + ' archivos seleccionados';
    }
}

function toggleHistory(btn) {
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!expanded));
    btn.nextElementSibling.classList.toggle('open', !expanded);
}
</script>
