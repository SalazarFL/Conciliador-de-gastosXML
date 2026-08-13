<?php
$baseUrl           = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas          = $facturas ?? [];
$historial         = $historial ?? [];
$importacionActiva = $importacionActiva ?? null;
$semanas           = is_array($semanas ?? null) ? $semanas : [];
$semanaFiltro      = (int) ($semanaFiltro ?? 0);
$filtros           = array_merge([
    'q' => '', 'proveedor' => '', 'fecha_desde' => '', 'fecha_hasta' => '',
    'monto_desde' => '', 'monto_hasta' => '', 'respaldo' => '', 'alcance' => '',
], is_array($filtros ?? null) ? $filtros : []);
$filtrosActivos = 0;
foreach ($filtros as $valorFiltro) {
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
$hayFiltros = $filtrosActivos > 0;
$parametrosLimpiar = $importacionActiva
    ? ['importacion_id' => (int) $importacionActiva['id']]
    : ['semana_id' => $semanaFiltro];
$urlLimpiarFiltros = $baseUrl . '/facturas?' . http_build_query($parametrosLimpiar);
?>

<!-- CARGA DE FACTURAS XML -->

<!-- Semana de trabajo + acceso a la carga -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-calendar-week" style="margin-right:6px;color:var(--navy-light);"></i>Semana de trabajo
            </div>
        </div>
        <a href="<?= $baseUrl ?>/facturas-erp" class="btn btn-outline btn-sm" style="margin-left:auto;">
            <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Volver a Facturas ERP
        </a>
    </div>

    <div style="padding:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <!-- Este selector NO es un formulario de carga: filtra la lista de
             abajo. Vivía dentro del formulario de subida y se fue con él, pero
             la lista lo sigue necesitando. -->
        <select id="semana_id" class="form-control" style="max-width:300px;font-size:13px;"
                onchange="semanaCambio(this)">
            <option value="" <?= $semanaFiltro === 0 ? 'selected' : '' ?>>— Sin semana —</option>
            <?php foreach (($semanas ?? []) as $sem): ?>
            <option value="<?= (int) $sem['id'] ?>" <?= $semanaFiltro === (int) $sem['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($sem['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <a href="<?= $baseUrl ?>/carga" class="btn btn-primary btn-sm">
            <i class="fas fa-inbox" style="margin-right:4px;"></i>Cargar XML
        </a>

        <?php if (!empty($facturas)): ?>
        <span class="badge badge-navy" style="font-size:12px;padding:6px 14px;">
            <i class="fas fa-layer-group"></i>
            <?= count($facturas) ?> factura<?= count($facturas) !== 1 ? 's' : '' ?>
            <?php if ($importacionActiva): ?>
            <span style="font-weight:400;opacity:.75;margin-left:4px;">— <?= htmlspecialchars($importacionActiva['archivo_origen']) ?></span>
            <?php endif; ?>
        </span>
        <?php endif; ?>

        <span style="font-size:11.5px;color:var(--text-muted);margin-left:auto;">
            Los XML se cargan en <a href="<?= $baseUrl ?>/carga">Carga de documentos</a>.
        </span>
    </div>
</div>

<script>
// El selector "Semana de trabajo" controla la lista de abajo: al cambiar
// se recarga mostrando las facturas de esa opción ("Sin semana" = las no
// asignadas). "Nueva semana" solo abre el campo del nombre, sin recargar.
function semanaCambio(sel) {
    // "Sin semana" navega con semana_id=0 explícito para que se recuerde
    // como selección (y no se confunda con una llegada sin parámetro).
    var base = '<?= $baseUrl ?>/facturas';
    var params = new URLSearchParams(window.location.search);
    params.set('semana_id', sel.value === '' ? '0' : sel.value);
    params.delete('importacion_id');
    params.delete('limpiar');
    params.delete('alcance');
    window.location.href = base + '?' + params.toString();
}
</script>

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
        </div>
    </div>

    <form method="get" action="<?= $baseUrl ?>/facturas" class="filter-bar">
        <?php if ($importacionActiva): ?>
        <input type="hidden" name="importacion_id" value="<?= (int) $importacionActiva['id'] ?>">
        <?php else: ?>
        <input type="hidden" name="semana_id" value="<?= $semanaFiltro ?>">
        <?php endif; ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q"
                   value="<?= htmlspecialchars((string) $filtros['q']) ?>"
                   placeholder="Número, clave, proveedor o archivo">
        </div>
        <div>
            <label class="filter-label">Proveedor</label>
            <input type="search" class="form-control" name="proveedor"
                   value="<?= htmlspecialchars((string) $filtros['proveedor']) ?>"
                   placeholder="Nombre o cédula">
        </div>
        <div>
            <label class="filter-label">Fecha desde</label>
            <input type="date" class="form-control" name="fecha_desde"
                   value="<?= htmlspecialchars((string) $filtros['fecha_desde']) ?>">
        </div>
        <div>
            <label class="filter-label">Fecha hasta</label>
            <input type="date" class="form-control" name="fecha_hasta"
                   value="<?= htmlspecialchars((string) $filtros['fecha_hasta']) ?>">
        </div>
        <div>
            <label class="filter-label">Monto desde</label>
            <input type="number" min="0" step="0.01" class="form-control" name="monto_desde"
                   value="<?= htmlspecialchars((string) $filtros['monto_desde']) ?>" placeholder="0.00">
        </div>
        <div>
            <label class="filter-label">Monto hasta</label>
            <input type="number" min="0" step="0.01" class="form-control" name="monto_hasta"
                   value="<?= htmlspecialchars((string) $filtros['monto_hasta']) ?>" placeholder="Sin límite">
        </div>
        <div>
            <label class="filter-label">Respaldo</label>
            <select class="form-control" name="respaldo" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="con_par" <?= $filtros['respaldo'] === 'con_par' ? 'selected' : '' ?>>Con XML y PDF</option>
                <option value="sin_par" <?= $filtros['respaldo'] === 'sin_par' ? 'selected' : '' ?>>Sin par completo</option>
            </select>
        </div>
        <?php if (!$importacionActiva): ?>
        <div>
            <label class="filter-label">Alcance</label>
            <select class="form-control" name="alcance" onchange="this.form.submit()">
                <option value="">Semana seleccionada</option>
                <option value="todas" <?= $filtros['alcance'] === 'todas' ? 'selected' : '' ?>>Todas las semanas</option>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($hayFiltros): ?>
            <a href="<?= htmlspecialchars($urlLimpiarFiltros) ?>" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="filter-results">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        Mostrando <strong><?= count($facturas) ?></strong> factura<?= count($facturas) !== 1 ? 's' : '' ?>
        <?= $hayFiltros ? 'con los filtros seleccionados' : 'en esta vista' ?>
        <?php if ($hayFiltros): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Número Factura</th>
                    <th>Proveedor</th>
                    <th class="center">Semana</th>
                    <th class="right">IVA</th>
                    <th class="right">Monto</th>
                    <th class="center">Respaldo</th>
                    <th class="center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($facturas)): ?>
                    <tr class="empty-row">
                        <td colspan="8">
                            <i class="fas fa-inbox" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                            <?= $hayFiltros ? 'No se encontraron facturas con estos filtros.' : 'No hay facturas importadas todavía.' ?><br>
                            <span style="font-size:12px;"><?= $hayFiltros ? 'Cambia o limpia los criterios de búsqueda.' : 'Usa el formulario de arriba para subir archivos XML.' ?></span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($facturas as $f): ?>
                    <tr>
                        <td class="muted"><?= htmlspecialchars($f['fecha_emision'] ?? '—') ?></td>
                        <td>
                            <span style="font-weight:700;color:var(--navy);">
                                <?= htmlspecialchars($f['numero_factura_asistente'] ?? $f['consecutivo_completo'] ?? '—') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($f['proveedor_nombre'] ?? 'Sin proveedor') ?></td>
                        <td class="center" style="white-space:nowrap;">
                            <?php if (!empty($f['semana_nombre'])): ?>
                            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;"><?= htmlspecialchars($f['semana_nombre']) ?></span>
                            <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline btn-sm"
                                    style="padding:2px 7px;font-size:10px;margin-left:4px;"
                                    title="<?= empty($f['semana_id']) ? 'Asignar semana' : 'Cambiar de semana' ?>"
                                    onclick="semAsignar(this, <?= (int) $f['id'] ?>, <?= (int) ($f['semana_id'] ?? 0) ?>)">
                                <i class="fas fa-pen"></i>
                            </button>
                        </td>
                        <td class="right muted"><?= number_format((float)($f['iva'] ?? 0), 2) ?></td>
                        <td class="right" style="font-weight:700;"><?= number_format((float)($f['total'] ?? 0), 2) ?></td>
                        <td class="center">
                            <?php if (!empty($f['_par_completo'])): ?>
                            <span class="badge badge-green"><i class="fas fa-check-circle"></i> XML + PDF</span>
                            <?php elseif (empty($f['_xml_disponible']) && empty($f['_pdf_disponible'])): ?>
                            <span class="badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-triangle-exclamation"></i> Sin archivos</span>
                            <?php elseif (empty($f['_xml_disponible'])): ?>
                            <span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-file-circle-xmark"></i> Falta XML</span>
                            <?php else: ?>
                            <span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-file-pdf"></i> Falta PDF</span>
                            <?php endif; ?>
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

<script>
// ── Asignar / cambiar semana desde la columna Semana ──
// El lápiz convierte la celda en un selector; al elegir se guarda por AJAX
// y se recarga la vista (si la factura ya no corresponde al filtro, sale
// de la lista, que es lo esperado).
var SEMANAS_LISTA = <?= json_encode(array_map(function ($s) {
    return ['id' => (int) $s['id'], 'nombre' => (string) $s['nombre']];
}, $semanas), JSON_UNESCAPED_UNICODE) ?>;

function semAsignar(btn, facturaId, semanaActual) {
    var td = btn.closest('td');
    var original = td.innerHTML;

    var sel = document.createElement('select');
    sel.className = 'form-control';
    sel.style.cssText = 'font-size:11.5px;padding:3px 6px;max-width:190px;display:inline-block;';

    var opciones = [['', '— Sin semana —']];
    SEMANAS_LISTA.forEach(function (s) { opciones.push([String(s.id), s.nombre]); });
    opciones.push(['nueva', '➕ Nueva semana…']);
    opciones.forEach(function (o) {
        var op = document.createElement('option');
        op.value = o[0];
        op.textContent = o[1];
        if (o[0] === (semanaActual ? String(semanaActual) : '')) op.selected = true;
        sel.appendChild(op);
    });

    td.innerHTML = '';
    td.appendChild(sel);
    sel.focus();

    var guardando = false;

    // Clic fuera sin elegir = cancelar y restaurar la celda
    sel.addEventListener('blur', function () {
        if (!guardando) td.innerHTML = original;
    });

    sel.addEventListener('change', function () {
        var valor = sel.value;
        var nombreNueva = '';

        if (valor === 'nueva') {
            nombreNueva = prompt('Nombre de la nueva semana:', 'Semana <?= date('d/m/Y') ?>');
            if (nombreNueva === null) { td.innerHTML = original; return; }
        } else if (valor === (semanaActual ? String(semanaActual) : '')) {
            td.innerHTML = original;
            return;
        }

        guardando = true;
        sel.disabled = true;

        var fd = new FormData();
        fd.append('factura_id', facturaId);
        fd.append('semana_id', valor);
        fd.append('semana_nueva', nombreNueva);

        fetch('<?= $baseUrl ?>/facturas/semana', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            })
            .then(function () { window.location.reload(); })
            .catch(function (err) {
                alert('No se pudo cambiar la semana: ' + err.message);
                td.innerHTML = original;
            });
    });
}

</script>
