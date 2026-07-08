<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$estados = $estados ?? [];
?>

<style>
.rep-wrap { max-width:1380px; margin:0 auto; padding:0 12px; }

/* ── Card base ── */
.rep-card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    padding:16px 18px;
    box-shadow:var(--shadow);
    margin-bottom:10px;
}
.rep-card-title {
    margin:0 0 14px;
    font-size:14px;
    font-weight:700;
    color:var(--navy);
    display:flex;
    align-items:center;
    gap:6px;
}
.rep-card-title i { color:var(--gold); font-size:13px; }

/* ── Tipo tabs (radio pills) ── */
.tipo-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.tipo-tab {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 14px;
    border-radius:999px;
    border:1.5px solid var(--border);
    font-size:12px;
    font-weight:600;
    color:var(--text-muted);
    cursor:pointer;
    transition:all .15s;
    user-select:none;
}
.tipo-tab input[type=radio] { display:none; }
.tipo-tab:hover { border-color:var(--navy-light); color:var(--navy); background:#f0f5ff; }
.tipo-tab.active { background:var(--navy); color:#fff; border-color:var(--navy); }

/* ── Filter row ── */
.filter-row {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:8px 10px;
    margin-bottom:10px;
}
.filter-item label {
    display:block;
    font-size:10px;
    font-weight:700;
    color:var(--text-muted);
    margin-bottom:3px;
    text-transform:uppercase;
    letter-spacing:.05em;
}
.filter-item select,
.filter-item input[type=date] {
    width:100%;
    padding:6px 8px;
    border:1.5px solid var(--border);
    border-radius:var(--radius);
    font-size:12px;
    color:var(--text);
    background:#f9fbfd;
    transition:border-color .15s;
    appearance:auto;
}
.filter-item select:focus,
.filter-item input:focus {
    outline:none;
    border-color:var(--navy-light);
    background:#fff;
}

/* Inline checkbox */
.filter-check {
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-size:12px;
    color:var(--text);
    cursor:pointer;
}
.filter-check input[type=checkbox] { width:14px; height:14px; accent-color:var(--navy); cursor:pointer; }

/* ── Collapsible sections ── */
.rep-section {
    border:1px solid var(--border-light);
    border-radius:var(--radius);
    margin-bottom:10px;
    overflow:hidden;
}
.rep-section summary {
    list-style:none;
    display:flex;
    align-items:center;
    gap:8px;
    padding:9px 14px;
    background:var(--bg);
    font-size:12px;
    font-weight:700;
    color:var(--navy);
    cursor:pointer;
    user-select:none;
    border-bottom:1px solid transparent;
    transition:background .15s;
}
.rep-section summary::-webkit-details-marker { display:none; }
.rep-section[open] summary { border-bottom-color:var(--border-light); }
.rep-section summary:hover { background:#eef2fb; }
.rep-section summary .sec-arrow {
    margin-left:auto;
    font-size:10px;
    color:var(--text-muted);
    transition:transform .2s;
}
.rep-section[open] summary .sec-arrow { transform:rotate(180deg); }
.rep-section-body { padding:12px 14px; }

/* ── Importaciones list ── */
.imp-list { display:flex; flex-direction:column; gap:4px; max-height:220px; overflow-y:auto; }
.imp-row {
    display:flex;
    align-items:center;
    gap:10px;
    padding:7px 10px;
    border-radius:8px;
    cursor:pointer;
    transition:background .12s;
    font-size:12px;
}
.imp-row:hover { background:#f0f5ff; }
.imp-row input[type=checkbox] { flex-shrink:0; width:14px; height:14px; accent-color:var(--navy); cursor:pointer; }
.imp-row-date { color:var(--text-muted); min-width:115px; font-size:11px; }
.imp-row-file { flex:1; color:var(--navy); font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.imp-row-badges { display:flex; gap:4px; flex-shrink:0; }
.imp-empty { font-size:12px; color:var(--text-muted); padding:8px 0; }
.imp-actions { display:flex; gap:8px; margin-bottom:8px; }
.imp-link { font-size:11px; color:var(--navy-light); cursor:pointer; background:none; border:none; padding:0; text-decoration:underline; }

/* ── Column pills ── */
.col-pills { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:8px; }
.col-pill {
    padding:3px 11px;
    border-radius:999px;
    font-size:11px;
    font-weight:600;
    cursor:pointer;
    border:1.5px solid var(--navy-light);
    background:#fff;
    color:var(--navy);
    transition:all .15s;
    user-select:none;
}
.col-pill:hover { background:#eef2fb; }
.col-pill.active { background:var(--navy); color:#fff; border-color:var(--navy); }
.col-actions { display:flex; gap:10px; }
.col-link { font-size:11px; color:var(--navy-light); cursor:pointer; background:none; border:none; padding:0; text-decoration:underline; }

/* ── Action buttons ── */
.filter-actions { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; align-items:center; }
.btn-rep {
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:none;
    border-radius:999px;
    padding:8px 18px;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:opacity .15s, box-shadow .15s;
    white-space:nowrap;
}
.btn-rep:disabled { opacity:.4; cursor:not-allowed; }
.btn-rep:not(:disabled):hover { opacity:.88; }
.btn-filtrar   { background:var(--navy);  color:#fff; box-shadow:0 4px 12px rgba(12,36,97,.28); }
.btn-export    { background:var(--gold);  color:var(--navy-dark); box-shadow:0 4px 12px rgba(240,165,0,.25); }
.btn-limpiar   { background:#fff; color:var(--text-muted); border:1.5px solid var(--border); }

/* ── Preview ── */
#preview-card .prev-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:6px;
    margin-bottom:8px;
}
.prev-title { margin:0; font-size:14px; font-weight:700; color:var(--navy); display:flex; align-items:center; gap:6px; }
.prev-title i { color:var(--gold); }
.prev-meta { font-size:11px; color:var(--text-muted); display:flex; gap:12px; flex-wrap:wrap; }
.prev-meta span { display:flex; align-items:center; gap:4px; }

.prev-table-wrap { overflow-x:auto; max-height:520px; overflow-y:auto; border-radius:var(--radius); border:1px solid var(--border-light); }
.prev-table { width:100%; border-collapse:collapse; font-size:12px; }
.prev-table thead th {
    position:sticky; top:0;
    padding:6px 10px;
    background:var(--navy);
    color:#fff;
    text-align:left;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.06em;
    white-space:nowrap;
    border-right:1px solid rgba(255,255,255,.08);
    z-index:2;
}
.prev-table thead th:last-child { border-right:none; }
.prev-table tbody tr:nth-child(even) { background:#f5f8fd; }
.prev-table tbody tr:hover { background:var(--gold-pale); }
.prev-table tbody td {
    padding:5px 10px;
    border-bottom:1px solid var(--border-light);
    white-space:nowrap;
    vertical-align:middle;
}
.prev-table tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }

/* Status badges */
.badge-estado { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.badge-conciliada      { background:var(--ok-bg);   color:var(--ok); }
.badge-con_diferencias { background:var(--diff-bg); color:var(--diff); }
.badge-pendiente       { background:var(--pend-bg); color:var(--pend); }
.badge-gasto_sin_xml   { background:var(--miss-bg); color:var(--miss); }
.badge-default         { background:#eee; color:#555; }
.badge-automatico { background:#e0f2fe; color:#0369a1; }
.badge-manual     { background:#f3e8ff; color:#7e22ce; }

/* Diff */
.diff-pos { color:var(--diff); font-weight:700; }
.diff-neg { color:var(--ok);   font-weight:700; }

/* Hallazgos */
.hallazgo-badge {
    display:inline-block; max-width:280px; overflow:hidden; text-overflow:ellipsis;
    white-space:nowrap; vertical-align:middle; padding:2px 8px;
    border-radius:999px; font-size:10px; font-weight:700;
}
.hallazgo-ok   { background:#d1fae5; color:#065f46; }
.hallazgo-warn { background:#fef9c3; color:#854d0e; }
.hallazgo-info { background:#e0f2fe; color:#0369a1; }

/* Summary */
.summary-bar { display:flex; gap:14px; flex-wrap:wrap; padding:6px 10px; background:var(--bg); border-radius:var(--radius); margin-bottom:8px; border:1px solid var(--border-light); }
.summary-item { font-size:11px; color:var(--text-muted); }
.summary-item strong { color:var(--navy); font-size:13px; }

/* States */
.prev-empty { text-align:center; padding:28px 16px; color:var(--text-light); }
.prev-empty i { font-size:32px; margin-bottom:8px; display:block; }
.prev-empty p { margin:0; font-size:13px; }
.prev-spinner { text-align:center; padding:28px 16px; }
.prev-spinner i { font-size:22px; color:var(--navy-light); }
.prev-spinner p { margin:6px 0 0; font-size:12px; color:var(--text-muted); }
.prev-truncate {
    margin-top:6px; padding:5px 10px; border-radius:var(--radius);
    background:var(--warn-bg); border:1px solid #fde68a;
    color:var(--warn); font-size:11px; display:flex; align-items:center; gap:6px;
}

@media (max-width:820px) {
    .filter-row { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:540px) {
    .rep-wrap { padding:0 4px; }
    .rep-card { padding:10px 12px; }
    .filter-row { grid-template-columns:1fr; }
    .filter-item select,
    .filter-item input[type=date] { font-size:16px; padding:8px 10px; }
    .filter-actions { gap:6px; flex-wrap:wrap; }
    .prev-table-wrap { max-height:380px; }
    .tipo-tabs { gap:4px; }
}
</style>

<div class="rep-wrap">

    <!-- ═══ FILTROS ═══ -->
    <section class="rep-card" id="filtros-card">
        <h2 class="rep-card-title"><i class="fas fa-sliders-h"></i> Filtros de Reporte</h2>

        <!-- Tipo de datos -->
        <div class="tipo-tabs" id="tipo-tabs">
            <label class="tipo-tab active" for="tipo-conc">
                <input type="radio" name="tipo" id="tipo-conc" value="conciliacion" checked>
                <i class="fas fa-exchange-alt"></i> Conciliación
            </label>
            <label class="tipo-tab" for="tipo-facts">
                <input type="radio" name="tipo" id="tipo-facts" value="facturas">
                <i class="fas fa-file-invoice"></i> Facturas
            </label>
            <label class="tipo-tab" for="tipo-gastos">
                <input type="radio" name="tipo" id="tipo-gastos" value="gastos">
                <i class="fas fa-receipt"></i> Gastos
            </label>
        </div>

        <!-- Filtros principales -->
        <div class="filter-row" id="row-filtros">

            <div class="filter-item" id="wrap-estado">
                <label for="f-estado">Estado</label>
                <select id="f-estado">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados as $codigo => $e): ?>
                        <option value="<?= htmlspecialchars($codigo) ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item" id="wrap-match">
                <label for="f-match">Tipo de match</label>
                <select id="f-match">
                    <option value="">Todos</option>
                    <option value="automatico">Automático</option>
                    <option value="manual">Manual</option>
                </select>
            </div>

            <div class="filter-item" id="wrap-moneda" style="display:none;">
                <label for="f-moneda">Moneda</label>
                <select id="f-moneda">
                    <option value="">Todas</option>
                    <option value="MXN">MXN</option>
                    <option value="USD">USD</option>
                    <option value="CRC">CRC</option>
                </select>
            </div>

            <div class="filter-item">
                <label for="f-desde">Fecha desde</label>
                <input id="f-desde" type="date">
            </div>

            <div class="filter-item">
                <label for="f-hasta">Fecha hasta</label>
                <input id="f-hasta" type="date">
            </div>

        </div>

        <div id="wrap-solo-dif" style="margin-bottom:12px;">
            <label class="filter-check">
                <input type="checkbox" id="f-solo-diferencias">
                Solo registros con diferencias
            </label>
        </div>

        <!-- Importaciones -->
        <details class="rep-section" id="imp-section">
            <summary>
                <i class="fas fa-layer-group" style="color:var(--gold);"></i>
                Importaciones
                <span id="imp-total-badge" class="badge badge-navy" style="font-size:10px;padding:1px 7px;margin-left:2px;">0</span>
                <span id="imp-sel-label" style="font-size:11px;color:var(--gold);margin-left:6px;font-weight:600;"></span>
                <i class="fas fa-chevron-down sec-arrow"></i>
            </summary>
            <div class="rep-section-body">
                <div class="imp-actions">
                    <button type="button" class="imp-link" id="imp-sel-all">Seleccionar todas</button>
                    <button type="button" class="imp-link" id="imp-sel-none">Quitar selección</button>
                </div>
                <div id="imp-loading" style="display:none;">
                    <p class="imp-empty"><i class="fas fa-circle-notch fa-spin"></i> Cargando…</p>
                </div>
                <div class="imp-list" id="imp-list"></div>
            </div>
        </details>

        <!-- Columnas a mostrar -->
        <details class="rep-section" id="col-section">
            <summary>
                <i class="fas fa-columns" style="color:var(--gold);"></i>
                Columnas a mostrar
                <span id="col-count-label" style="font-size:11px;color:var(--text-muted);margin-left:6px;"></span>
                <i class="fas fa-chevron-down sec-arrow"></i>
            </summary>
            <div class="rep-section-body">
                <div class="col-actions" style="margin-bottom:8px;">
                    <button type="button" class="col-link" id="col-sel-all">Seleccionar todas</button>
                    <button type="button" class="col-link" id="col-sel-none">Quitar todas</button>
                </div>
                <div class="col-pills" id="col-pills"></div>
            </div>
        </details>

        <!-- Acciones -->
        <div class="filter-actions">
            <button type="button" id="btn-filtrar" class="btn-rep btn-filtrar">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <button type="button" id="btn-export" class="btn-rep btn-export" disabled>
                <i class="fas fa-file-excel"></i> Exportar XLSX
            </button>
            <button type="button" id="btn-limpiar" class="btn-rep btn-limpiar">
                <i class="fas fa-times"></i> Limpiar
            </button>
        </div>
    </section>

    <!-- ═══ SPINNER ═══ -->
    <div id="prev-loading" style="display:none;" class="rep-card">
        <div class="prev-spinner">
            <i class="fas fa-circle-notch fa-spin"></i>
            <p>Cargando datos…</p>
        </div>
    </div>

    <!-- ═══ VACÍO ═══ -->
    <div id="prev-empty" style="display:none;" class="rep-card">
        <div class="prev-empty">
            <i class="fas fa-inbox"></i>
            <p id="prev-empty-msg">No se encontraron registros con esos filtros.</p>
        </div>
    </div>

    <!-- ═══ PREVIEW ═══ -->
    <section id="preview-card" class="rep-card" style="display:none;">
        <div class="prev-header">
            <h2 class="prev-title" id="prev-title"><i class="fas fa-table"></i> Vista previa</h2>
            <div class="prev-meta" id="prev-meta"></div>
        </div>
        <div id="summary-bar" class="summary-bar" style="display:none;"></div>
        <div class="prev-table-wrap" id="prev-table-wrap"></div>
        <div id="prev-truncate" class="prev-truncate" style="display:none;">
            <i class="fas fa-exclamation-triangle"></i>
            Se muestran los primeros 500 registros. La exportación incluye todos.
        </div>
    </section>

</div>

<script>
(function () {
    'use strict';

    const BASE = <?= json_encode(rtrim($baseUrl, '/')) ?>;

    // ── Column definitions per tipo ───────────────────────────────────
    const COLS = {
        conciliacion: [
            'Estado','Score',
            'Fact. Fecha','Fact. N°','Fact. Proveedor','Fact. IVA','Fact. Total',
            'Gas. Fecha','Gas. N°','Gas. Proveedor','Gas. IVA','Gas. Total',
            'Dif. $','Dif. %','Tipo Match','Hallazgos'
        ],
        facturas: ['Fecha','N° Factura','Proveedor','Subtotal','IVA','Total','Moneda','Archivo XML'],
        gastos:   ['Fecha','N° Factura','Proveedor','Items','Base','IVA','Total'],
    };

    // ── State ─────────────────────────────────────────────────────────
    let hiddenCols          = new Set();
    let importacionesData   = [];
    let selectedImportaciones = new Set();

    // ── DOM refs ──────────────────────────────────────────────────────
    const btnFiltrar  = document.getElementById('btn-filtrar');
    const btnExport   = document.getElementById('btn-export');
    const btnLimpiar  = document.getElementById('btn-limpiar');
    const prevLoading = document.getElementById('prev-loading');
    const prevEmpty   = document.getElementById('prev-empty');
    const prevEmptyMsg= document.getElementById('prev-empty-msg');
    const previewCard = document.getElementById('preview-card');
    const prevTitle   = document.getElementById('prev-title');
    const prevMeta    = document.getElementById('prev-meta');
    const summaryBar  = document.getElementById('summary-bar');
    const prevTableW  = document.getElementById('prev-table-wrap');
    const prevTrunc   = document.getElementById('prev-truncate');

    // ── Tipo helper ───────────────────────────────────────────────────
    function getTipo() {
        const checked = document.querySelector('input[name="tipo"]:checked');
        return checked ? checked.value : 'conciliacion';
    }

    // ── Filter visibility ─────────────────────────────────────────────
    function actualizarFiltrosVisibles() {
        const t = getTipo();
        const esConc   = t === 'conciliacion';
        const esFacts  = t === 'facturas';

        document.getElementById('wrap-estado').style.display   = esConc  ? '' : 'none';
        document.getElementById('wrap-match').style.display    = esConc  ? '' : 'none';
        document.getElementById('wrap-moneda').style.display   = esFacts ? '' : 'none';
        document.getElementById('wrap-solo-dif').style.display = esConc  ? '' : 'none';

        if (!esConc) {
            document.getElementById('f-estado').value = '';
            document.getElementById('f-match').value  = '';
            document.getElementById('f-solo-diferencias').checked = false;
        }
        if (!esFacts) {
            document.getElementById('f-moneda').value = '';
        }
    }

    // ── Tipo change ───────────────────────────────────────────────────
    document.querySelectorAll('input[name="tipo"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.tipo-tab').forEach(function(lbl) {
                lbl.classList.toggle('active', lbl.htmlFor === radio.id);
            });
            actualizarFiltrosVisibles();
            buildColPills(this.value);
            loadImportaciones(this.value);
            hideAll();
            btnExport.disabled = true;
        });
    });

    // ── Column pills ──────────────────────────────────────────────────
    function buildColPills(tipo) {
        hiddenCols.clear();
        const cols = COLS[tipo] || [];
        const wrap = document.getElementById('col-pills');
        wrap.innerHTML = '';
        cols.forEach(function(name, idx) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'col-pill active';
            btn.dataset.col = idx;
            btn.textContent = name;
            btn.addEventListener('click', function() {
                if (hiddenCols.has(idx)) {
                    hiddenCols.delete(idx);
                    btn.classList.add('active');
                } else {
                    hiddenCols.add(idx);
                    btn.classList.remove('active');
                }
                updateColCountLabel();
            });
            wrap.appendChild(btn);
        });
        updateColCountLabel();
    }

    function updateColCountLabel() {
        const tipo   = getTipo();
        const total  = (COLS[tipo] || []).length;
        const hidden = hiddenCols.size;
        const shown  = total - hidden;
        const el = document.getElementById('col-count-label');
        el.textContent = shown + ' de ' + total + ' visibles';
    }

    document.getElementById('col-sel-all').addEventListener('click', function() {
        hiddenCols.clear();
        document.querySelectorAll('.col-pill').forEach(function(p) { p.classList.add('active'); });
        updateColCountLabel();
    });
    document.getElementById('col-sel-none').addEventListener('click', function() {
        document.querySelectorAll('.col-pill').forEach(function(p) {
            const idx = parseInt(p.dataset.col);
            hiddenCols.add(idx);
            p.classList.remove('active');
        });
        updateColCountLabel();
    });

    // ── Importaciones ─────────────────────────────────────────────────
    function loadImportaciones(tipo) {
        selectedImportaciones.clear();
        importacionesData = [];
        updateImpSelLabel();

        const list     = document.getElementById('imp-list');
        const loading  = document.getElementById('imp-loading');
        list.innerHTML = '';
        loading.style.display = '';

        var tipoImp = tipo === 'gastos' ? 'gastos' : 'xml';

        fetch(BASE + '/reportes/importaciones?tipo=' + tipoImp)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loading.style.display = 'none';
                importacionesData = data.success ? data.data : [];
                document.getElementById('imp-total-badge').textContent = importacionesData.length;

                if (!importacionesData.length) {
                    list.innerHTML = '<p class="imp-empty">No hay importaciones registradas.</p>';
                    return;
                }

                var html = '';
                importacionesData.forEach(function(imp) {
                    var rawDate = imp.fecha_importacion || '';
                    var fecha   = rawDate.substring(0, 16).replace('T', ' ');
                    var nombre  = escHtml(imp.archivo_origen);
                    html += '<label class="imp-row">';
                    html += '<input type="checkbox" class="imp-check" value="' + imp.id + '">';
                    html += '<span class="imp-row-date">' + fecha + '</span>';
                    html += '<span class="imp-row-file" title="' + nombre + '">' + nombre + '</span>';
                    html += '<span class="imp-row-badges">';
                    if (imp.registros_exitosos > 0) {
                        html += '<span class="badge badge-green" style="font-size:10px;padding:1px 6px;">' + imp.registros_exitosos + ' ok</span>';
                    }
                    if (imp.registros_fallidos > 0) {
                        html += '<span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:10px;padding:1px 6px;">' + imp.registros_fallidos + ' err</span>';
                    }
                    html += '</span></label>';
                });
                list.innerHTML = html;

                list.querySelectorAll('.imp-check').forEach(function(cb) {
                    cb.addEventListener('change', function() {
                        var id = parseInt(this.value);
                        if (this.checked) selectedImportaciones.add(id);
                        else selectedImportaciones.delete(id);
                        updateImpSelLabel();
                    });
                });
            })
            .catch(function() {
                loading.style.display = 'none';
                list.innerHTML = '<p class="imp-empty" style="color:var(--warn);">Error al cargar importaciones.</p>';
            });
    }

    function updateImpSelLabel() {
        var el = document.getElementById('imp-sel-label');
        el.textContent = selectedImportaciones.size > 0
            ? '(' + selectedImportaciones.size + ' seleccionada' + (selectedImportaciones.size > 1 ? 's' : '') + ')'
            : '';
    }

    document.getElementById('imp-sel-all').addEventListener('click', function() {
        document.querySelectorAll('.imp-check').forEach(function(cb) {
            cb.checked = true;
            selectedImportaciones.add(parseInt(cb.value));
        });
        updateImpSelLabel();
    });
    document.getElementById('imp-sel-none').addEventListener('click', function() {
        document.querySelectorAll('.imp-check').forEach(function(cb) { cb.checked = false; });
        selectedImportaciones.clear();
        updateImpSelLabel();
    });

    // ── Query builders ────────────────────────────────────────────────
    function buildQuery() {
        var p = new URLSearchParams();
        var t = getTipo();
        p.set('tipo', t);

        var estado = document.getElementById('f-estado');
        if (estado && estado.value) p.set('estado', estado.value);
        var match = document.getElementById('f-match');
        if (match && match.value) p.set('match_tipo', match.value);
        var moneda = document.getElementById('f-moneda');
        if (moneda && moneda.value) p.set('moneda', moneda.value);
        var desde = document.getElementById('f-desde');
        if (desde && desde.value) p.set('fecha_desde', desde.value);
        var hasta = document.getElementById('f-hasta');
        if (hasta && hasta.value) p.set('fecha_hasta', hasta.value);
        var soloDif = document.getElementById('f-solo-diferencias');
        if (soloDif && soloDif.checked) p.set('solo_diferencias', '1');
        if (selectedImportaciones.size > 0) {
            p.set('importaciones_ids', Array.from(selectedImportaciones).join(','));
        }
        return p.toString();
    }

    function buildExportQuery() {
        var p   = new URLSearchParams(buildQuery());
        var t   = getTipo();
        var total   = (COLS[t] || []).length;
        var visible = [];
        for (var i = 0; i < total; i++) {
            if (!hiddenCols.has(i)) visible.push(i);
        }
        if (visible.length < total && visible.length > 0) {
            p.set('columnas', visible.join(','));
        }
        return p.toString();
    }

    // ── Helpers ───────────────────────────────────────────────────────
    var fmt = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function fmtMonto(v) { var n = parseFloat(v); return isNaN(n) ? (v ?? '') : fmt.format(n); }
    function escHtml(s) { var d = document.createElement('div'); d.textContent = String(s ?? ''); return d.innerHTML; }

    function badgeEstado(codigo) {
        var cls = 'badge-' + (codigo || 'default').replace(/\s/g, '_').toLowerCase();
        return '<span class="badge-estado ' + cls + '">' + escHtml(codigo) + '</span>';
    }
    function badgeMatch(tipo) {
        var map = { automatico:'badge-automatico', manual:'badge-manual' };
        var cls = map[tipo] || 'badge-default';
        return '<span class="badge-estado ' + cls + '">' + escHtml(tipo || '—') + '</span>';
    }
    function diffCell(val) {
        var n = parseFloat(val);
        if (isNaN(n) || n === 0) return '<td class="num">0.00</td>';
        var cls = n > 0 ? 'diff-pos' : 'diff-neg';
        return '<td class="num ' + cls + '">' + fmt.format(Math.abs(n)) + '</td>';
    }
    function badgeHallazgo(val) {
        var s = String(val || '');
        if (!s) return '<td></td>';
        var cls = s === 'Sin diferencias' ? 'hallazgo-ok'
                : (s === 'Sin factura XML' || s === 'Sin gasto asociado' || s === 'Sin datos') ? 'hallazgo-info'
                : 'hallazgo-warn';
        return '<td><span class="hallazgo-badge ' + cls + '" title="' + escHtml(s) + '">' + escHtml(s) + '</span></td>';
    }

    // ── Render functions (respect hiddenCols) ─────────────────────────
    function renderConciliacion(rows) {
        var allCells = [
            function(r){ return '<td>' + badgeEstado(r[0]) + '</td>'; },
            function(r){ return '<td class="num">' + escHtml(r[1]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[2]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[3]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[4]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[5]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[6]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[7]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[8]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[9]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[10]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[11]) + '</td>'; },
            function(r){ return diffCell(r[12]); },
            function(r){ return '<td class="num">' + escHtml(r[13]) + '%</td>'; },
            function(r){ return '<td>' + badgeMatch(r[14]) + '</td>'; },
            function(r){ return badgeHallazgo(r[15]); },
        ];
        return buildTable(COLS.conciliacion, allCells, rows);
    }

    function renderFacturas(rows) {
        var allCells = [
            function(r){ return '<td>' + escHtml(r[0]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[1]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[2]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[3]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[4]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[5]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[6]) + '</td>'; },
            function(r){ return '<td style="color:var(--text-muted);font-size:11px;">' + escHtml(r[7]) + '</td>'; },
        ];
        return buildTable(COLS.facturas, allCells, rows);
    }

    function renderGastos(rows) {
        var allCells = [
            function(r){ return '<td>' + escHtml(r[0]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[1]) + '</td>'; },
            function(r){ return '<td>' + escHtml(r[2]) + '</td>'; },
            function(r){ return '<td class="num">' + escHtml(r[3]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[4]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[5]) + '</td>'; },
            function(r){ return '<td class="num">' + fmtMonto(r[6]) + '</td>'; },
        ];
        return buildTable(COLS.gastos, allCells, rows);
    }

    function buildTable(colNames, cellRenderers, rows) {
        var html = '<table class="prev-table"><thead><tr>';
        colNames.forEach(function(h, i) {
            if (!hiddenCols.has(i)) html += '<th>' + escHtml(h) + '</th>';
        });
        html += '</tr></thead><tbody>';
        rows.forEach(function(r) {
            html += '<tr>';
            cellRenderers.forEach(function(fn, i) {
                if (!hiddenCols.has(i)) html += fn(r);
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    // ── Summary bar ───────────────────────────────────────────────────
    function renderSummary(data) {
        if (!data.summary || !Object.keys(data.summary).length) {
            summaryBar.style.display = 'none';
            return;
        }
        var s = data.summary;
        var html = '';
        if (s.total_registros !== undefined) html += '<span class="summary-item">Registros: <strong>' + s.total_registros + '</strong></span>';
        if (s.total_monto     !== undefined) html += '<span class="summary-item">Monto total: <strong>$' + fmt.format(s.total_monto) + '</strong></span>';
        if (s.total_iva       !== undefined) html += '<span class="summary-item">IVA total: <strong>$' + fmt.format(s.total_iva) + '</strong></span>';
        if (s.dif_promedio    !== undefined) html += '<span class="summary-item">Dif. promedio: <strong>' + fmt.format(s.dif_promedio) + '%</strong></span>';
        summaryBar.innerHTML = html;
        summaryBar.style.display = html ? '' : 'none';
    }

    // ── Show/hide ─────────────────────────────────────────────────────
    function hideAll() {
        prevLoading.style.display = 'none';
        prevEmpty.style.display   = 'none';
        previewCard.style.display = 'none';
        prevTrunc.style.display   = 'none';
    }

    // ── Fetch preview ─────────────────────────────────────────────────
    function fetchPreview() {
        hideAll();
        prevLoading.style.display = '';
        btnFiltrar.disabled = true;
        btnExport.disabled  = true;

        fetch(BASE + '/reportes/preview?' + buildQuery())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                hideAll();
                btnFiltrar.disabled = false;

                if (!data.success) {
                    prevEmptyMsg.textContent = data.message || 'Error al consultar datos.';
                    prevEmpty.style.display = '';
                    return;
                }
                if (!data.total) {
                    prevEmptyMsg.textContent = 'No se encontraron registros con esos filtros.';
                    prevEmpty.style.display = '';
                    return;
                }

                var tipoLabel = { conciliacion:'Conciliación', facturas:'Facturas XML', gastos:'Gastos' };
                prevTitle.innerHTML = '<i class="fas fa-table"></i> ' + (tipoLabel[getTipo()] || 'Vista previa');

                var total = data.total;
                var shown = data.rows.length;
                prevMeta.innerHTML =
                    '<span><i class="fas fa-list-ol"></i> ' + total + ' registro' + (total !== 1 ? 's' : '') + '</span>' +
                    (data.truncated ? '<span style="color:var(--warn)"><i class="fas fa-exclamation-circle"></i> mostrando ' + shown + '</span>' : '');

                renderSummary(data);

                var t = getTipo();
                var tableHtml = t === 'facturas' ? renderFacturas(data.rows)
                              : t === 'gastos'   ? renderGastos(data.rows)
                              : renderConciliacion(data.rows);
                prevTableW.innerHTML = tableHtml;
                prevTrunc.style.display = data.truncated ? '' : 'none';
                previewCard.style.display = '';
                previewCard.scrollIntoView({ behavior:'smooth', block:'start' });
                btnExport.disabled = false;
            })
            .catch(function() {
                hideAll();
                btnFiltrar.disabled = false;
                prevEmptyMsg.textContent = 'Error de conexión al servidor.';
                prevEmpty.style.display = '';
            });
    }

    // ── Events ────────────────────────────────────────────────────────
    btnFiltrar.addEventListener('click', fetchPreview);

    btnExport.addEventListener('click', function() {
        window.location.href = BASE + '/reportes/exportar?' + buildExportQuery();
    });

    btnLimpiar.addEventListener('click', function() {
        document.querySelectorAll('input[name="tipo"]').forEach(function(r) {
            r.checked = r.value === 'conciliacion';
        });
        document.querySelectorAll('.tipo-tab').forEach(function(lbl) {
            lbl.classList.toggle('active', lbl.htmlFor === 'tipo-conc');
        });
        document.getElementById('f-estado').value  = '';
        document.getElementById('f-match').value   = '';
        document.getElementById('f-moneda').value  = '';
        document.getElementById('f-desde').value   = '';
        document.getElementById('f-hasta').value   = '';
        document.getElementById('f-solo-diferencias').checked = false;

        // Reset importaciones
        document.querySelectorAll('.imp-check').forEach(function(cb) { cb.checked = false; });
        selectedImportaciones.clear();
        updateImpSelLabel();

        // Reset columns
        hiddenCols.clear();
        document.querySelectorAll('.col-pill').forEach(function(p) { p.classList.add('active'); });
        updateColCountLabel();

        actualizarFiltrosVisibles();
        hideAll();
        btnExport.disabled = true;
    });

    document.querySelectorAll('.rep-wrap input[type=date], .rep-wrap select').forEach(function(el) {
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') fetchPreview();
        });
    });

    // ── Init ──────────────────────────────────────────────────────────
    actualizarFiltrosVisibles();
    buildColPills('conciliacion');
    loadImportaciones('conciliacion');

})();
</script>
