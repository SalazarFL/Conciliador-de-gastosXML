<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$listados = is_array($listados ?? null) ? $listados : [];
$listado = $listado ?? null;
$lineas = is_array($lineas ?? null) ? $lineas : [];
$resumen = is_array($resumen ?? null) ? $resumen : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$filtrosActivos = 0;
foreach ($filtros as $valorFiltro) {
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
$opciones = is_array($opciones ?? null) ? $opciones : ['proveedores' => [], 'sucursales' => []];
$paginacion = is_array($paginacion ?? null) ? $paginacion : ['page' => 1, 'pages' => 1, 'total' => 0];

function ncFecha($value) {
    $time = $value ? strtotime((string) $value) : false;
    return $time ? date('d/m/Y', $time) : '—';
}
function ncMonto($value, $moneda = 'CRC') {
    return ($moneda === 'USD' ? '$' : '₡') . number_format((float) $value, 2, '.', ',');
}
function ncQuery(array $changes = []) {
    $current = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($current[$key]);
        else $current[$key] = $value;
    }
    return http_build_query($current);
}
?>

<style>
.nc-summary{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.nc-stat{background:#fff;border:1px solid var(--border);border-radius:10px;padding:13px 15px}
.nc-stat strong{display:block;font-size:24px;color:var(--navy);line-height:1.1}.nc-stat span{font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase}
.nc-table-wrap{overflow:auto;max-height:68vh;border:1px solid var(--border);border-radius:9px}
.nc-table{min-width:1750px}.nc-table thead th{position:sticky;z-index:3}.nc-table .nc-head-labels th{top:0}.nc-table .nc-search-row th{top:37px;padding:5px 4px;background:#f8fafc;z-index:4}
.nc-table .nc-search-row input,.nc-table .nc-search-row select{width:100%;min-width:82px;height:28px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;padding:3px 6px;font-size:10.5px;color:var(--navy);outline:none}
.nc-table .nc-search-row input:focus,.nc-table .nc-search-row select:focus{border-color:var(--gold);box-shadow:0 0 0 2px rgba(212,160,23,.14)}
.nc-table .nc-search-row .nc-search-wide{min-width:145px}.nc-table td{vertical-align:top}
.nc-doc{max-width:230px;overflow-wrap:anywhere;font-weight:650;color:var(--navy)}
.nc-provider{max-width:245px;white-space:normal}.nc-reason{font-size:10.5px;color:#64748b;max-width:190px;white-space:normal;margin-top:4px}
.nc-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap;min-width:165px}
.nc-history-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:8px;margin:12px 0}
.nc-history-stat{border:1px solid var(--border);border-radius:8px;padding:10px 12px;background:#f8fafc}.nc-history-stat strong{display:block;font-size:20px;color:var(--navy)}.nc-history-stat span{font-size:10px;text-transform:uppercase;color:var(--text-muted);font-weight:700}
.nc-transition{white-space:nowrap;font-weight:700}.nc-arrow{color:#94a3b8;margin:0 5px}
.nc-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1200;padding:4vh 3vw;overflow:auto}
.nc-modal.open{display:block}.nc-modal-panel{background:#fff;border-radius:12px;max-width:1100px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
.nc-modal-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}.nc-modal-body{padding:16px 18px;max-height:70vh;overflow:auto}
.nc-close{margin-left:auto;background:none;border:0;font-size:23px;color:#64748b;cursor:pointer}
.nc-period{font-size:11px;color:var(--text-muted)}
@media(max-width:900px){.nc-summary{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title"><i class="fas fa-file-circle-minus" style="color:var(--gold);margin-right:6px;"></i>Cargar listado de notas de crédito</div>
    </div>
    <?php if (empty($sociedadActiva)): ?>
        <div class="alert alert-warning">Debes registrar y activar una sociedad desde Inicio antes de cargar un listado.</div>
    <?php else: ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
            Sociedad: <strong style="color:var(--navy);"><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>.
            Se acepta el reporte CSV “Notas de Crédito por Proveedor”.
        </div>
        <form id="nc-upload-form" action="<?= $baseUrl ?>/notas-credito/previsualizar" method="POST" enctype="multipart/form-data"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="file" name="listado_file" id="nc-file" accept=".csv" required style="display:none;">
            <label class="upload-file-btn" for="nc-file"><i class="fas fa-folder-open"></i> Seleccionar CSV</label>
            <span id="nc-file-name" style="font-size:12px;color:var(--text-muted);font-style:italic;">Ningún archivo seleccionado</span>
            <button type="submit" class="btn btn-primary btn-sm" id="nc-preview-btn"><i class="fas fa-eye"></i> Vista previa</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($listados)): ?>
<div class="card mb-20">
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:280px;">
            <label style="font-size:11px;font-weight:700;color:var(--navy);">Listado por período</label>
            <select class="form-control" onchange="location.href='<?= $baseUrl ?>/notas-credito?listado_id='+this.value">
                <?php foreach ($listados as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $listado && (int) $item['id'] === (int) $listado['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($item['nombre']) ?> · <?= (int) $item['total_lineas'] ?> notas
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($listado): ?>
        <form method="POST" action="<?= $baseUrl ?>/notas-credito/verificar/<?= (int) $listado['id'] ?>">
            <button class="btn btn-outline btn-sm"><i class="fas fa-rotate"></i> Verificar nuevamente</button>
        </form>
        <button type="button" class="btn btn-outline btn-sm" id="nc-history-open"
                data-listado="<?= (int) $listado['id'] ?>">
            <i class="fas fa-clock-rotate-left"></i> Historial
        </button>
        <form method="POST" action="<?= $baseUrl ?>/notas-credito/eliminar/<?= (int) $listado['id'] ?>"
              onsubmit="return confirm('¿Eliminar este listado y su CSV de auditoría? Los XML no serán eliminados.');">
            <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Eliminar listado</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($listado): ?>
    <div class="nc-period" style="margin-top:8px;">
        Archivo: <?= htmlspecialchars($listado['archivo_origen']) ?> ·
        Empresa del reporte: <?= htmlspecialchars($listado['empresa_reporte'] ?: '—') ?> ·
        Período: <?= ncFecha($listado['periodo_desde']) ?> al <?= ncFecha($listado['periodo_hasta']) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($listado): ?>
<div class="nc-summary">
    <div class="nc-stat"><strong><?= (int) ($resumen['total'] ?? 0) ?></strong><span>Total de notas</span></div>
    <div class="nc-stat" style="border-left:4px solid #16a34a;"><strong><?= (int) ($resumen['coincide'] ?? 0) ?></strong><span>Coinciden</span></div>
    <div class="nc-stat" style="border-left:4px solid #dc2626;"><strong><?= (int) ($resumen['con_diferencia'] ?? 0) ?></strong><span>Con diferencia</span></div>
    <div class="nc-stat" style="border-left:4px solid #94a3b8;"><strong><?= (int) ($resumen['sin_respaldo'] ?? 0) ?></strong><span>Sin respaldo</span></div>
</div>

<div class="card">
    <form method="GET" action="<?= $baseUrl ?>/notas-credito" class="filter-bar" id="nc-filter-form">
        <input type="hidden" name="listado_id" value="<?= (int) $listado['id'] ?>">
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q'] ?? '') ?>"
                   placeholder="Documento, NC proveedor, entrada o proveedor">
        </div>
        <div>
            <label class="filter-label">Estado</label>
            <select class="form-control" name="estado">
                <option value="">Todos</option>
                <option value="coincide" <?= ($filtros['estado'] ?? '') === 'coincide' ? 'selected' : '' ?>>Coincide</option>
                <option value="con_diferencia" <?= ($filtros['estado'] ?? '') === 'con_diferencia' ? 'selected' : '' ?>>Con diferencia</option>
                <option value="sin_respaldo" <?= ($filtros['estado'] ?? '') === 'sin_respaldo' ? 'selected' : '' ?>>Sin respaldo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Saldo</label>
            <select class="form-control" name="condicion_saldo">
                <option value="">Todos</option>
                <option value="con_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'con_saldo' ? 'selected' : '' ?>>Con saldo</option>
                <option value="sin_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'sin_saldo' ? 'selected' : '' ?>>Sin saldo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">NC proveedor</label>
            <select class="form-control" name="condicion_nc_proveedor">
                <option value="">Todos</option>
                <option value="con_nc_proveedor" <?= ($filtros['condicion_nc_proveedor'] ?? '') === 'con_nc_proveedor' ? 'selected' : '' ?>>Con NC proveedor</option>
                <option value="sin_nc_proveedor" <?= ($filtros['condicion_nc_proveedor'] ?? '') === 'sin_nc_proveedor' ? 'selected' : '' ?>>Sin NC proveedor</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Sucursal</label>
            <select class="form-control" name="sucursal">
                <option value="">Todas</option>
                <?php foreach ($opciones['sucursales'] as $option): ?>
                <option value="<?= htmlspecialchars($option['valor']) ?>" <?= ($filtros['sucursal'] ?? '') === $option['valor'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($option['valor']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filter-label">Proveedor</label>
            <select class="form-control" name="proveedor">
                <option value="">Todos</option>
                <?php foreach ($opciones['proveedores'] as $option): ?>
                <option value="<?= htmlspecialchars($option['valor']) ?>" <?= ($filtros['proveedor'] ?? '') === $option['valor'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($option['valor']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($filtrosActivos): ?>
            <a href="<?= $baseUrl ?>/notas-credito?listado_id=<?= (int) $listado['id'] ?>" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div id="nc-result-status" class="filter-results" style="margin-bottom:12px;">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        <span>
            Mostrando <strong id="nc-result-count"><?= count($lineas) ?></strong> de
            <strong id="nc-list-total"><?= (int) ($resumen['total'] ?? 0) ?></strong> filas.
            La búsqueda se aplica al listado completo. Desplázate horizontalmente para ver todas las columnas.
        </span>
        <?php if ($filtrosActivos): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>
    <div class="nc-table-wrap">
        <table class="data-table nc-table">
            <thead>
                <tr class="nc-head-labels">
                    <th>Estado</th><th>Proveedor</th><th>Sucursal</th>
                    <th>Documento</th><th>Fecha</th><th>NC Proveedor</th><th>Fecha NC proveedor</th>
                    <th class="right">Monto</th><th class="right">Saldo</th>
                    <th>NC XML</th><th class="right">Total XML</th><th class="right">Diferencia</th><th>Acciones</th>
                </tr>
                <tr class="nc-search-row">
                    <th>
                        <select data-nc-filter="col_estado" aria-label="Buscar por estado">
                            <option value="">Todos</option>
                            <option value="coincide">Coincide</option>
                            <option value="con_diferencia">Con diferencia</option>
                            <option value="sin_respaldo">Sin respaldo</option>
                        </select>
                    </th>
                    <th><input class="nc-search-wide" data-nc-filter="proveedor_nombre" placeholder="Buscar" aria-label="Buscar proveedor"></th>
                    <th><input data-nc-filter="sucursal_texto" placeholder="Buscar" aria-label="Buscar sucursal"></th>
                    <th><input class="nc-search-wide" data-nc-filter="documento" placeholder="Buscar" aria-label="Buscar documento"></th>
                    <th><input type="date" data-nc-filter="fecha" aria-label="Buscar fecha"></th>
                    <th><input data-nc-filter="nc_proveedor" placeholder="Buscar" aria-label="Buscar NC proveedor"></th>
                    <th><input type="date" data-nc-filter="fecha_nc_proveedor" aria-label="Buscar fecha NC proveedor"></th>
                    <th><input data-nc-filter="monto" placeholder="Buscar" inputmode="decimal" aria-label="Buscar monto"></th>
                    <th><input data-nc-filter="saldo" placeholder="Buscar" inputmode="decimal" aria-label="Buscar saldo"></th>
                    <th><input data-nc-filter="nc_xml" placeholder="Buscar" aria-label="Buscar NC XML"></th>
                    <th><input data-nc-filter="xml_total" placeholder="Buscar" inputmode="decimal" aria-label="Buscar total XML"></th>
                    <th><input data-nc-filter="diferencia" placeholder="Buscar" inputmode="decimal" aria-label="Buscar diferencia"></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="nc-lines-body">
            <?php if (empty($lineas)): ?>
                <tr><td colspan="13" style="text-align:center;padding:28px;color:var(--text-muted);">No hay notas con estos filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($lineas as $row): ?>
                <?php
                $badge = $row['estado'] === 'coincide' ? 'badge-ok' : ($row['estado'] === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
                $label = $row['estado'] === 'coincide' ? 'Coincide' : ($row['estado'] === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
                ?>
                <tr>
                    <td>
                        <span class="badge <?= $badge ?>"><?= $label ?></span>
                        <?php if (!empty($row['match_manual'])): ?><div class="nc-reason"><i class="fas fa-hand-pointer"></i> Vínculo manual</div><?php endif; ?>
                        <?php if (!empty($row['motivo_match'])): ?><div class="nc-reason"><?= htmlspecialchars($row['motivo_match']) ?></div><?php endif; ?>
                    </td>
                    <td class="nc-provider"><?= htmlspecialchars($row['proveedor_nombre']) ?></td>
                    <td><?= htmlspecialchars($row['sucursal'] ?: '—') ?></td>
                    <td class="nc-doc"><?= htmlspecialchars($row['documento']) ?></td>
                    <td><?= ncFecha($row['fecha']) ?></td>
                    <td class="nc-doc"><?= htmlspecialchars($row['nc_proveedor'] ?: '—') ?></td>
                    <td><?= ncFecha($row['fecha_nc_proveedor']) ?></td>
                    <td class="right"><?= ncMonto($row['monto'], $row['moneda']) ?></td>
                    <td class="right"><?= ncMonto($row['saldo'], $row['moneda']) ?></td>
                    <td>
                        <?php if (!empty($row['factura_xml_id'])): ?>
                            <a href="<?= $baseUrl ?>/notas-xml/ver/<?= (int) $row['factura_xml_id'] ?>" target="_blank" rel="noopener"
                               class="nc-doc" title="Abrir visualización del XML">
                                <?= htmlspecialchars($row['xml_numero'] ?: $row['xml_consecutivo']) ?>
                            </a>
                            <div class="nc-reason"><?= htmlspecialchars($row['xml_proveedor'] ?: '') ?></div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="right"><?= $row['xml_total'] !== null ? ncMonto($row['xml_total'], $row['moneda']) : '—' ?></td>
                    <td class="right" style="<?= $row['estado'] === 'con_diferencia' ? 'color:#dc2626;font-weight:800;' : '' ?>">
                        <?= $row['diferencia'] !== null ? ncMonto($row['diferencia'], $row['moneda']) : '—' ?>
                    </td>
                    <td>
                        <div class="nc-actions">
                            <button type="button" class="btn btn-outline btn-sm nc-link-btn"
                                    data-linea="<?= (int) $row['id'] ?>">
                                <i class="fas fa-link"></i> <?= !empty($row['factura_xml_id']) ? 'Cambiar' : 'Vincular XML' ?>
                            </button>
                            <?php if (!empty($row['factura_xml_id'])): ?>
                            <form method="POST" action="<?= $baseUrl ?>/notas-credito/desvincular"
                                  onsubmit="return confirm('¿Desvincular esta NC XML y bloquear el matching automático para esta fila?');">
                                <input type="hidden" name="linea_id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (empty($listados)): ?>
<div class="card" style="text-align:center;padding:36px;color:var(--text-muted);">
    <i class="fas fa-file-circle-minus" style="font-size:34px;margin-bottom:12px;color:#cbd5e1;"></i>
    <div style="font-weight:700;color:var(--navy);">Aún no hay listados de notas de crédito</div>
    <div style="font-size:12px;margin-top:5px;">Selecciona el CSV del reporte para crear el primero.</div>
</div>
<?php endif; ?>

<!-- Vista previa -->
<div class="nc-modal" id="nc-preview-modal">
    <div class="nc-modal-panel">
        <div class="nc-modal-head">
            <i class="fas fa-eye" style="color:var(--gold);"></i>
            <div><strong>Vista previa del listado</strong><div id="nc-preview-meta" class="nc-period"></div></div>
            <button class="nc-close" type="button" data-close="nc-preview-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div id="nc-preview-stats" class="nc-summary"></div>
            <div id="nc-preview-alert"></div>
            <div class="nc-table-wrap" style="max-height:45vh;">
                <table class="data-table" style="min-width:900px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Fecha</th><th class="right">Monto</th><th class="right">Saldo</th></tr></thead>
                    <tbody id="nc-preview-body"></tbody>
                </table>
            </div>
        </div>
        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
            <button type="button" class="btn btn-outline btn-sm" data-close="nc-preview-modal">Cancelar</button>
            <form method="POST" action="<?= $baseUrl ?>/notas-credito/subir" id="nc-confirm-form">
                <input type="hidden" name="archivo_token" id="nc-token">
                <input type="hidden" name="archivo_nombre" id="nc-original">
                <button class="btn btn-primary btn-sm" id="nc-confirm-btn"><i class="fas fa-database"></i> Importar listado</button>
            </form>
        </div>
    </div>
</div>

<!-- Vinculación manual -->
<div class="nc-modal" id="nc-link-modal">
    <div class="nc-modal-panel">
        <div class="nc-modal-head">
            <i class="fas fa-link" style="color:var(--gold);"></i>
            <div><strong>Vincular NC XML</strong><div id="nc-link-meta" class="nc-period"></div></div>
            <button class="nc-close" type="button" data-close="nc-link-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
                Solo se muestran NC XML cuyo monto es exactamente igual al monto del reporte.
            </div>
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <input class="form-control" id="nc-candidate-q" placeholder="Filtrar por consecutivo o proveedor">
                <button type="button" class="btn btn-outline btn-sm" id="nc-candidate-search"><i class="fas fa-search"></i></button>
            </div>
            <div class="nc-table-wrap" style="max-height:52vh;">
                <table class="data-table" style="min-width:900px;">
                    <thead><tr><th>Consecutivo XML</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th><th class="right">Diferencia</th><th>Similitud</th><th></th></tr></thead>
                    <tbody id="nc-candidate-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Historial de verificaciones -->
<div class="nc-modal" id="nc-history-modal">
    <div class="nc-modal-panel" style="max-width:1250px;">
        <div class="nc-modal-head">
            <i class="fas fa-clock-rotate-left" style="color:var(--gold);"></i>
            <div><strong>Historial de verificaciones</strong><div class="nc-period">Resultados y cambios guardados en cada ejecución.</div></div>
            <button class="nc-close" type="button" data-close="nc-history-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <div style="min-width:310px;flex:1;">
                    <label style="font-size:11px;font-weight:700;">Verificación</label>
                    <select class="form-control" id="nc-history-select"></select>
                </div>
            </div>
            <div id="nc-history-summary"></div>
            <div class="nc-table-wrap" style="max-height:48vh;">
                <table class="data-table" style="min-width:1180px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Estado</th><th>NC XML</th><th>Diferencia</th><th>Motivo anterior</th><th>Motivo nuevo</th></tr></thead>
                    <tbody id="nc-history-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE = <?= json_encode($baseUrl) ?>;
    var file = document.getElementById('nc-file');
    var uploadForm = document.getElementById('nc-upload-form');
    var previewModal = document.getElementById('nc-preview-modal');
    var linkModal = document.getElementById('nc-link-modal');
    var historyModal = document.getElementById('nc-history-modal');
    var linesBody = document.getElementById('nc-lines-body');
    var filterForm = document.getElementById('nc-filter-form');
    var currentLine = 0;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function money(value, currency) {
        return (currency === 'USD' ? '$' : '₡') + Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    function dateEs(value) {
        if (!value) return '—';
        var p = String(value).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : value;
    }
    function dateTimeEs(value) {
        if (!value) return '—';
        var parts = String(value).split(' ');
        return dateEs(parts[0]) + (parts[1] ? ' ' + parts[1].slice(0, 5) : '');
    }
    function stateLabel(value) {
        return {coincide:'Coincide', con_diferencia:'Con diferencia', sin_respaldo:'Sin respaldo'}[value] || value || '—';
    }
    function originLabel(value) {
        return {manual:'Manual', automatico:'Automática', carga_inicial:'Carga inicial'}[value] || value || 'Automática';
    }
    function lineRow(row) {
        var estado = String(row.estado || 'sin_respaldo');
        var badge = estado === 'coincide' ? 'badge-ok' : (estado === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
        var label = estado === 'coincide' ? 'Coincide' : (estado === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
        var estadoHtml = '<span class="badge '+badge+'">'+label+'</span>';
        if (Number(row.match_manual || 0) > 0) {
            estadoHtml += '<div class="nc-reason"><i class="fas fa-hand-pointer"></i> Vínculo manual</div>';
        }
        if (row.motivo_match) {
            estadoHtml += '<div class="nc-reason">'+esc(row.motivo_match)+'</div>';
        }

        var tieneXml = Number(row.factura_xml_id || 0) > 0;
        var xmlHtml = '—';
        if (tieneXml) {
            xmlHtml = '<a href="'+BASE+'/notas-xml/ver/'+Number(row.factura_xml_id)+'" target="_blank" rel="noopener" class="nc-doc" title="Abrir visualización del XML">' +
                esc(row.xml_numero || row.xml_consecutivo || '')+'</a>' +
                '<div class="nc-reason">'+esc(row.xml_proveedor || '')+'</div>';
        }
        var totalXml = row.xml_total !== null && row.xml_total !== '' ? money(row.xml_total, row.moneda) : '—';
        var diferencia = row.diferencia !== null && row.diferencia !== '' ? money(row.diferencia, row.moneda) : '—';
        var diffStyle = estado === 'con_diferencia' ? ' style="color:#dc2626;font-weight:800;"' : '';

        return '<tr>'+
            '<td>'+estadoHtml+'</td>'+
            '<td class="nc-provider">'+esc(row.proveedor_nombre || '')+'</td>'+
            '<td>'+esc(row.sucursal || '—')+'</td>'+
            '<td class="nc-doc">'+esc(row.documento || '')+'</td>'+
            '<td>'+dateEs(row.fecha)+'</td>'+
            '<td class="nc-doc">'+esc(row.nc_proveedor || '—')+'</td>'+
            '<td>'+dateEs(row.fecha_nc_proveedor)+'</td>'+
            '<td class="right">'+money(row.monto, row.moneda)+'</td>'+
            '<td class="right">'+money(row.saldo, row.moneda)+'</td>'+
            '<td>'+xmlHtml+'</td><td class="right">'+totalXml+'</td>'+
            '<td class="right"'+diffStyle+'>'+diferencia+'</td>'+
            '<td><div class="nc-actions">'+
                '<button type="button" class="btn btn-outline btn-sm nc-link-btn" data-linea="'+Number(row.id)+'">' +
                '<i class="fas fa-link"></i> '+(tieneXml ? 'Cambiar' : 'Vincular XML')+'</button>'+
                (tieneXml ? '<form method="POST" action="'+BASE+'/notas-credito/desvincular" onsubmit="return confirm(\'¿Desvincular esta NC XML y bloquear el matching automático para esta fila?\');">' +
                    '<input type="hidden" name="linea_id" value="'+Number(row.id)+'">' +
                    '<button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button></form>' : '')+
                '</div></td></tr>';
    }
    function renderLines(rows) {
        if (!linesBody) return;
        if (!rows.length) {
            linesBody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:28px;color:#64748b;">No hay notas con estos filtros.</td></tr>';
            return;
        }
        linesBody.innerHTML = rows.map(lineRow).join('');
    }
    function openModal(modal) { if (modal) modal.classList.add('open'); }
    function closeModal(modal) { if (modal) modal.classList.remove('open'); }

    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(document.getElementById(button.dataset.close)); });
    });
    [previewModal, linkModal, historyModal].forEach(function (modal) {
        if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });
    });

    function renderHistory(data) {
        var select = document.getElementById('nc-history-select');
        var summary = document.getElementById('nc-history-summary');
        var body = document.getElementById('nc-history-body');
        var runs = data.verificaciones || [];
        var selected = data.seleccionada;
        select.innerHTML = runs.map(function (run) {
            return '<option value="'+Number(run.id)+'"'+(selected && Number(run.id) === Number(selected.id) ? ' selected' : '')+'>'+
                dateTimeEs(run.fecha_inicio)+' · '+originLabel(run.origen)+' · '+Number(run.cantidad_cambios)+' cambios</option>';
        }).join('');
        if (!selected) {
            summary.innerHTML = '<div class="alert alert-info" style="margin-top:12px;">Todavía no hay verificaciones guardadas. La próxima ejecución aparecerá aquí.</div>';
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:26px;color:#64748b;">Sin historial disponible.</td></tr>';
            return;
        }
        summary.innerHTML = '<div class="nc-history-grid">'+
            '<div class="nc-history-stat"><strong>'+Number(selected.coincide)+'</strong><span>Coinciden</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.con_diferencia)+'</strong><span>Con diferencia</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.sin_respaldo)+'</strong><span>Sin respaldo</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.cantidad_cambios)+'</strong><span>Cambios en esta ejecución</span></div></div>';
        var changes = data.cambios || [];
        if (!changes.length) {
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:26px;color:#64748b;">La verificación terminó sin modificar ninguna nota.</td></tr>';
            return;
        }
        body.innerHTML = changes.map(function (change) {
            var oldXml = change.xml_anterior || (change.factura_xml_id_anterior ? 'XML #'+change.factura_xml_id_anterior : 'Sin XML');
            var newXml = change.xml_nuevo || (change.factura_xml_id_nuevo ? 'XML #'+change.factura_xml_id_nuevo : 'Sin XML');
            var oldDiff = change.diferencia_anterior === null ? '—' : money(change.diferencia_anterior, change.moneda);
            var newDiff = change.diferencia_nueva === null ? '—' : money(change.diferencia_nueva, change.moneda);
            return '<tr><td>'+esc(change.fila_origen || '—')+'</td><td class="nc-doc">'+esc(change.documento || '—')+'</td>'+
                '<td class="nc-provider">'+esc(change.proveedor_nombre || '—')+'</td>'+
                '<td class="nc-transition">'+esc(stateLabel(change.estado_anterior))+'<span class="nc-arrow">→</span>'+esc(stateLabel(change.estado_nuevo))+'</td>'+
                '<td class="nc-doc">'+esc(oldXml)+'<span class="nc-arrow">→</span>'+esc(newXml)+'</td>'+
                '<td class="nc-transition">'+oldDiff+'<span class="nc-arrow">→</span>'+newDiff+'</td>'+
                '<td>'+esc(change.motivo_anterior || '—')+'</td><td>'+esc(change.motivo_nuevo || '—')+'</td></tr>';
        }).join('');
    }

    function loadHistory(verificationId) {
        var opener = document.getElementById('nc-history-open');
        if (!opener) return;
        var body = document.getElementById('nc-history-body');
        body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:26px;"><i class="fas fa-spinner fa-spin"></i> Cargando historial…</td></tr>';
        var url = BASE + '/notas-credito/historial/' + Number(opener.dataset.listado);
        if (verificationId) url += '?verificacion_id=' + Number(verificationId);
        fetch(url).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.ok) throw new Error(data.message || 'No fue posible cargar el historial.');
            renderHistory(data);
        }).catch(function (error) {
            body.innerHTML = '<tr><td colspan="8" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>';
        });
    }

    var historyOpen = document.getElementById('nc-history-open');
    if (historyOpen) historyOpen.addEventListener('click', function () { openModal(historyModal); loadHistory(0); });
    var historySelect = document.getElementById('nc-history-select');
    if (historySelect) historySelect.addEventListener('change', function () { loadHistory(Number(historySelect.value)); });

    if (filterForm && linesBody) {
        var columnFilters = Array.prototype.slice.call(document.querySelectorAll('[data-nc-filter]'));
        var filterTimer = null;
        var filterRequest = null;
        var currentUrlParams = new URLSearchParams(window.location.search);

        columnFilters.forEach(function (input) {
            var saved = currentUrlParams.get(input.dataset.ncFilter);
            if (saved !== null) input.value = saved;
        });

        function filterParams() {
            var params = new URLSearchParams(new FormData(filterForm));
            params.delete('page');
            columnFilters.forEach(function (input) {
                var value = String(input.value || '').trim();
                if (value) params.set(input.dataset.ncFilter, value);
                else params.delete(input.dataset.ncFilter);
            });
            return params;
        }

        function runLiveSearch() {
            var params = filterParams();
            var resultCount = document.getElementById('nc-result-count');
            if (filterRequest) filterRequest.abort();
            filterRequest = new AbortController();
            if (resultCount) resultCount.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            window.history.replaceState(null, '', BASE + '/notas-credito?' + params.toString());
            fetch(BASE + '/notas-credito/buscar?' + params.toString(), {signal:filterRequest.signal})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'No fue posible buscar las notas.');
                    renderLines(data.lineas || []);
                    if (resultCount) resultCount.textContent = Number(data.total || 0);
                    var listTotal = document.getElementById('nc-list-total');
                    if (listTotal) listTotal.textContent = Number(data.total_listado || 0);
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    linesBody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:28px;color:#dc2626;">'+esc(error.message)+'</td></tr>';
                    if (resultCount) resultCount.textContent = '0';
                });
        }

        function scheduleLiveSearch(immediate) {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(runLiveSearch, immediate ? 0 : 180);
        }

        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            scheduleLiveSearch(true);
        });
        filterForm.querySelectorAll('input:not([type="hidden"])').forEach(function (input) {
            input.addEventListener('input', function () { scheduleLiveSearch(false); });
        });
        filterForm.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { scheduleLiveSearch(true); });
        });
        columnFilters.forEach(function (input) {
            input.addEventListener(input.tagName === 'SELECT' || input.type === 'date' ? 'change' : 'input', function () {
                scheduleLiveSearch(input.tagName === 'SELECT' || input.type === 'date');
            });
        });
    }

    if (file) file.addEventListener('change', function () {
        document.getElementById('nc-file-name').textContent = file.files.length ? file.files[0].name : 'Ningún archivo seleccionado';
    });

    if (uploadForm) uploadForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!file.files.length) return;
        var button = document.getElementById('nc-preview-btn');
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando…';
        fetch(uploadForm.action, {method:'POST', body:new FormData(uploadForm)})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'No fue posible analizar el archivo.');
                document.getElementById('nc-token').value = data.token;
                document.getElementById('nc-original').value = data.archivo;
                document.getElementById('nc-preview-meta').textContent =
                    data.archivo + ' · ' + (data.empresa || 'Empresa no detectada') + ' · ' +
                    dateEs(data.periodo_desde) + ' al ' + dateEs(data.periodo_hasta);
                var s = data.estadisticas;
                document.getElementById('nc-preview-stats').innerHTML =
                    '<div class="nc-stat"><strong>'+s.total+'</strong><span>Notas</span></div>' +
                    '<div class="nc-stat"><strong>'+s.proveedores+'</strong><span>Proveedores</span></div>' +
                    '<div class="nc-stat"><strong>'+s.sucursales+'</strong><span>Sucursales</span></div>' +
                    '<div class="nc-stat"><strong>'+s.crc+' / '+s.usd+'</strong><span>CRC / USD</span></div>';
                var alert = document.getElementById('nc-preview-alert');
                var confirm = document.getElementById('nc-confirm-btn');
                if (data.duplicado) {
                    alert.innerHTML = '<div class="alert alert-warning">Este archivo ya fue cargado como <strong>'+esc(data.duplicado.nombre)+'</strong>.</div>';
                    confirm.disabled = true;
                } else if (data.errores.length) {
                    alert.innerHTML = '<div class="alert alert-warning">'+data.errores.length+' filas inválidas se omitirán al importar.</div>';
                    confirm.disabled = false;
                } else {
                    alert.innerHTML = '<div class="alert alert-success">Todas las filas fueron interpretadas correctamente.</div>';
                    confirm.disabled = false;
                }
                document.getElementById('nc-preview-body').innerHTML = data.lineas.map(function (row) {
                    return '<tr><td>'+row.fila_origen+'</td><td class="nc-doc">'+esc(row.documento)+'</td>' +
                        '<td>'+esc(row.proveedor_nombre)+'</td><td>'+dateEs(row.fecha)+'</td>' +
                        '<td class="right">'+money(row.monto,row.moneda)+'</td><td class="right">'+money(row.saldo,row.moneda)+'</td></tr>';
                }).join('');
                openModal(previewModal);
            })
            .catch(function (error) { alert(error.message); })
            .finally(function () { button.disabled=false; button.innerHTML='<i class="fas fa-eye"></i> Vista previa'; });
    });

    function loadCandidates() {
        var q = document.getElementById('nc-candidate-q').value || '';
        var body = document.getElementById('nc-candidate-body');
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Buscando NC XML…</td></tr>';
        fetch(BASE + '/notas-credito/candidatas?linea_id=' + currentLine + '&q=' + encodeURIComponent(q))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'No fue posible cargar candidatas.');
                var line = data.linea;
                document.getElementById('nc-link-meta').textContent =
                    line.documento + ' · ' + line.proveedor_nombre + ' · ' + money(line.monto, line.moneda);
                if (!data.candidatas.length) {
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:28px;color:#64748b;">No hay NC XML disponibles con el mismo monto exacto.</td></tr>';
                    return;
                }
                body.innerHTML = data.candidatas.map(function (row) {
                    return '<tr><td class="nc-doc">'+esc(row.consecutivo_completo || row.numero_factura_asistente)+'</td>' +
                        '<td>'+esc(row.proveedor_nombre)+'</td><td>'+dateEs(row.fecha_emision)+'</td>' +
                        '<td class="right">'+money(row.total,row.moneda)+'</td><td class="right">'+money(row.diferencia,row.moneda)+'</td>' +
                        '<td>'+Number(row.score_proveedor).toFixed(1)+'%</td><td>' +
                        '<form method="POST" action="'+BASE+'/notas-credito/vincular">' +
                        '<input type="hidden" name="linea_id" value="'+currentLine+'"><input type="hidden" name="factura_id" value="'+Number(row.id)+'">' +
                        '<button class="btn btn-primary btn-sm"><i class="fas fa-link"></i> Vincular</button></form></td></tr>';
                }).join('');
            })
            .catch(function (error) { body.innerHTML='<tr><td colspan="7" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>'; });
    }

    if (linesBody) linesBody.addEventListener('click', function (event) {
        var button = event.target.closest('.nc-link-btn');
        if (button) {
            currentLine = Number(button.dataset.linea);
            document.getElementById('nc-candidate-q').value = '';
            openModal(linkModal);
            loadCandidates();
        }
    });
    var search = document.getElementById('nc-candidate-search');
    if (search) search.addEventListener('click', loadCandidates);
    var qInput = document.getElementById('nc-candidate-q');
    if (qInput) qInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); loadCandidates(); } });
})();
</script>
