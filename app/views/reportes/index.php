<?php
$baseUrl     = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$estados     = $estados ?? [];
$proveedores = $proveedores ?? [];
?>

<style>
.rep-wrap { max-width:1380px; margin:0 auto; padding:0 12px; }

/* Card */
.rep-card {
    background:var(--card);
    border:1px solid var(--border);
    border-radius:var(--radius-lg);
    padding:14px 16px;
    box-shadow:var(--shadow);
    margin-bottom:10px;
}
.rep-card-title {
    margin:0 0 10px;
    font-size:14px;
    font-weight:700;
    color:var(--navy);
    display:flex;
    align-items:center;
    gap:6px;
}
.rep-card-title i { color:var(--gold); font-size:13px; }

/* Filter grid */
.filter-group { margin-bottom:8px; }
.filter-group-label {
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--text-muted);
    margin-bottom:5px;
    padding-bottom:4px;
    border-bottom:1px solid var(--border-light);
}
.filter-row {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
    gap:6px 10px;
}
.filter-item label {
    display:block;
    font-size:10px;
    font-weight:700;
    color:var(--text-muted);
    margin-bottom:2px;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.filter-item select,
.filter-item input[type=text],
.filter-item input[type=date],
.filter-item input[type=number] {
    width:100%;
    padding:5px 8px;
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

/* Checkbox row */
.filter-check-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.filter-check {
    display:flex;
    align-items:center;
    gap:5px;
    font-size:12px;
    color:var(--text);
    cursor:pointer;
}
.filter-check input[type=checkbox] { width:14px; height:14px; accent-color:var(--navy); cursor:pointer; }

/* Buttons */
.filter-actions { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; align-items:center; }

.btn-rep {
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:none;
    border-radius:999px;
    padding:7px 16px;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:opacity .15s, box-shadow .15s;
    flex:0 0 auto;
    width:auto;
    white-space:nowrap;
}
.btn-rep:disabled { opacity:.45; cursor:not-allowed; }
.btn-rep:not(:disabled):hover { opacity:.88; }

.btn-filtrar {
    background:var(--navy);
    color:#fff;
    box-shadow:0 4px 12px rgba(12,36,97,.28);
}
.btn-export-xlsx {
    background:var(--gold);
    color:var(--navy-dark);
    box-shadow:0 4px 12px rgba(240,165,0,.25);
}
.btn-limpiar {
    background:#fff;
    color:var(--text-muted);
    border:1.5px solid var(--border);
}

/* Separador de grupos de filtros */
.filter-divider {
    border:none;
    border-top:1px solid var(--border-light);
    margin:8px 0;
}

/* Preview */
#preview-card .prev-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:6px;
    margin-bottom:8px;
}
#preview-card .prev-title {
    margin:0;
    font-size:14px;
    font-weight:700;
    color:var(--navy);
    display:flex;
    align-items:center;
    gap:6px;
}
#preview-card .prev-title i { color:var(--gold); }
#preview-card .prev-meta { font-size:11px; color:var(--text-muted); display:flex; gap:12px; flex-wrap:wrap; }
#preview-card .prev-meta span { display:flex; align-items:center; gap:4px; }

.prev-table-wrap { overflow-x:auto; max-height:520px; overflow-y:auto; border-radius:var(--radius); border:1px solid var(--border-light); }

.prev-table { width:100%; border-collapse:collapse; font-size:12px; }
.prev-table thead th {
    position:sticky;
    top:0;
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

.badge-estado {
    display:inline-block;
    padding:2px 8px;
    border-radius:999px;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
}

/* Estados */
.badge-conciliada     { background:var(--ok-bg);   color:var(--ok); }
.badge-requiere_revision { background:var(--warn-bg); color:var(--warn); }
.badge-con_diferencias{ background:var(--diff-bg); color:var(--diff); }
.badge-pendiente      { background:var(--pend-bg); color:var(--pend); }
.badge-sin_match      { background:var(--miss-bg); color:var(--miss); }
.badge-default        { background:#eee; color:#555; }

/* Match tipo */
.badge-automatico { background:#e0f2fe; color:#0369a1; }
.badge-manual     { background:#f3e8ff; color:#7e22ce; }
.badge-sin_match2 { background:var(--miss-bg); color:var(--miss); }

/* Diff cell */
.diff-pos { color:var(--diff); font-weight:700; }
.diff-neg { color:var(--ok);   font-weight:700; }

/* Aviso truncado */
.prev-truncate {
    margin-top:6px;
    padding:5px 10px;
    border-radius:var(--radius);
    background:var(--warn-bg);
    border:1px solid #fde68a;
    color:var(--warn);
    font-size:11px;
    display:flex;
    align-items:center;
    gap:6px;
}

/* Empty / spinner */
.prev-empty {
    text-align:center;
    padding:28px 16px;
    color:var(--text-light);
}
.prev-empty i { font-size:32px; margin-bottom:8px; display:block; }
.prev-empty p { margin:0; font-size:13px; }

.prev-spinner { text-align:center; padding:28px 16px; }
.prev-spinner i { font-size:22px; color:var(--navy-light); }
.prev-spinner p { margin:6px 0 0; font-size:12px; color:var(--text-muted); }

/* Summary bar */
.summary-bar {
    display:flex;
    gap:14px;
    flex-wrap:wrap;
    padding:6px 10px;
    background:var(--bg);
    border-radius:var(--radius);
    margin-bottom:8px;
    border:1px solid var(--border-light);
}
.summary-item { font-size:11px; color:var(--text-muted); }
.summary-item strong { color:var(--navy); font-size:13px; }

/* ── Responsive ──────────────────────────────────────────────────── */

/* Tablet: 2 columnas en el grid de filtros */
@media (max-width: 820px) {
    .filter-row {
        grid-template-columns: repeat(2, 1fr);
        gap: 6px 8px;
    }
}

/* Móvil: 1 columna, inputs grandes (evita zoom en iOS), botones compactos */
@media (max-width: 540px) {
    .rep-wrap { padding: 0 4px; }
    .rep-card { padding: 10px 12px; margin-bottom: 8px; }

    .filter-row {
        grid-template-columns: 1fr;
        gap: 5px;
    }

    /* Font-size ≥ 16px previene el auto-zoom de iOS Safari */
    .filter-item select,
    .filter-item input[type=text],
    .filter-item input[type=date],
    .filter-item input[type=number] {
        font-size: 16px;
        padding: 8px 10px;
    }

    /* El label-espaciador del bloque checkboxes no sirve en 1 col */
    #wrap-revisado > label:first-child { display: none; }

    /* Quitar la altura fija del row de checkboxes */
    .filter-check-row { height: auto !important; }

    /* Botones: fila compacta, cada uno solo su contenido */
    .filter-actions { gap: 6px; flex-wrap: wrap; }
    .btn-rep { padding: 8px 16px; font-size: 13px; }

    /* Cabecera del preview: apilar */
    .prev-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    .prev-meta { gap: 8px; }

    .summary-bar { gap: 6px 12px; }
    .prev-table-wrap { max-height: 380px; }
}

/* Pantallas muy pequeñas (< 380 px): botones en columna */
@media (max-width: 380px) {
    .filter-actions { flex-direction: column; align-items: flex-start; }
    .btn-rep { width: auto; }
}
</style>

<div class="rep-wrap">

    <!-- ═══════════════ FILTROS ═══════════════ -->
    <section class="rep-card" id="filtros-card">
        <h2 class="rep-card-title"><i class="fas fa-sliders-h"></i> Filtros de Reporte</h2>

        <!-- GRUPO 1: Qué ver -->
        <div class="filter-group">
            <div class="filter-group-label">¿Qué datos ver?</div>
            <div class="filter-row">

                <div class="filter-item">
                    <label for="f-tipo">Tipo de datos</label>
                    <select id="f-tipo">
                        <option value="conciliacion">Conciliación (cruce)</option>
                        <option value="facturas">Solo Facturas XML</option>
                        <option value="gastos">Solo Gastos</option>
                    </select>
                </div>

                <!-- Solo conciliación -->
                <div class="filter-item" id="wrap-estado">
                    <label for="f-estado">Estado de conciliación</label>
                    <select id="f-estado">
                        <option value="">Todos los estados</option>
                        <?php foreach ($estados as $codigo => $e): ?>
                            <option value="<?= htmlspecialchars($codigo) ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Solo conciliación -->
                <div class="filter-item" id="wrap-match">
                    <label for="f-match">Tipo de match</label>
                    <select id="f-match">
                        <option value="">Todos</option>
                        <option value="automatico">Automático</option>
                        <option value="manual">Manual</option>
                        <option value="sin_match">Sin match</option>
                    </select>
                </div>

                <!-- Solo facturas -->
                <div class="filter-item" id="wrap-moneda" style="display:none;">
                    <label for="f-moneda">Moneda</label>
                    <select id="f-moneda">
                        <option value="">Todas</option>
                        <option value="CRC">CRC – Colón</option>
                        <option value="USD">USD – Dólar</option>
                    </select>
                </div>

            </div>
        </div>

        <hr class="filter-divider">

        <!-- GRUPO 2: Buscar por -->
        <div class="filter-group">
            <div class="filter-group-label">Buscar por</div>
            <div class="filter-row">

                <div class="filter-item">
                    <label for="f-numero">Número de factura</label>
                    <input id="f-numero" type="text" placeholder="Ej. A-001, 2024-…">
                </div>

                <div class="filter-item">
                    <label for="f-proveedor">Proveedor</label>
                    <input id="f-proveedor" type="text" list="lista-proveedores" placeholder="Nombre o RFC…" autocomplete="off">
                    <datalist id="lista-proveedores">
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
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
        </div>

        <hr class="filter-divider">

        <!-- GRUPO 3: Rangos numéricos -->
        <div class="filter-group">
            <div class="filter-group-label">Rangos de montos</div>
            <div class="filter-row">

                <div class="filter-item">
                    <label for="f-monto-min">Monto total mínimo ($)</label>
                    <input id="f-monto-min" type="number" min="0" step="0.01" placeholder="0.00">
                </div>

                <div class="filter-item">
                    <label for="f-monto-max">Monto total máximo ($)</label>
                    <input id="f-monto-max" type="number" min="0" step="0.01" placeholder="Sin límite">
                </div>

                <!-- Solo conciliación -->
                <div class="filter-item" id="wrap-dif">
                    <label for="f-dif-min">Diferencia mínima (%)</label>
                    <input id="f-dif-min" type="number" min="0" max="100" step="0.1" placeholder="0 = cualquiera">
                </div>

                <!-- Solo conciliación -->
                <div class="filter-item" id="wrap-score">
                    <label for="f-score-min">Score mínimo de match</label>
                    <input id="f-score-min" type="number" min="0" max="100" step="1" placeholder="0 = cualquiera">
                </div>

            </div>
        </div>

        <hr class="filter-divider">

        <!-- GRUPO 4: Opciones adicionales -->
        <div class="filter-group">
            <div class="filter-group-label">Opciones adicionales</div>
            <div class="filter-row" style="align-items:end;">

                <div class="filter-item">
                    <label for="f-orden">Ordenar por</label>
                    <select id="f-orden">
                        <option value="fecha">Fecha</option>
                        <option value="monto">Monto total</option>
                        <option value="proveedor">Proveedor</option>
                        <option value="diferencia" id="orden-diferencia">Diferencia</option>
                        <option value="score" id="orden-score">Score de match</option>
                    </select>
                </div>

                <div class="filter-item">
                    <label for="f-orden-dir">Dirección</label>
                    <select id="f-orden-dir">
                        <option value="desc">Descendente (mayor primero)</option>
                        <option value="asc">Ascendente (menor primero)</option>
                    </select>
                </div>

                <!-- Solo conciliación -->
                <div class="filter-item" id="wrap-revisado">
                    <label>&nbsp;</label>
                    <div class="filter-check-row" style="min-height:29px;">
                        <label class="filter-check">
                            <input type="checkbox" id="f-solo-revisados">
                            Solo revisados
                        </label>
                        <label class="filter-check">
                            <input type="checkbox" id="f-solo-diferencias">
                            Solo con diferencias
                        </label>
                    </div>
                </div>

            </div>
        </div>

        <!-- Acciones -->
        <div class="filter-actions">
            <button type="button" id="btn-filtrar" class="btn-rep btn-filtrar">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <button type="button" id="btn-export" class="btn-rep btn-export-xlsx" disabled>
                <i class="fas fa-file-excel"></i> Exportar XLSX
            </button>
            <button type="button" id="btn-limpiar" class="btn-rep btn-limpiar">
                <i class="fas fa-times"></i> Limpiar filtros
            </button>
        </div>
    </section>

    <!-- ═══════════════ SPINNER ═══════════════ -->
    <div id="prev-loading" style="display:none;" class="rep-card">
        <div class="prev-spinner">
            <i class="fas fa-circle-notch fa-spin"></i>
            <p>Cargando datos…</p>
        </div>
    </div>

    <!-- ═══════════════ VACÍO ═══════════════ -->
    <div id="prev-empty" style="display:none;" class="rep-card">
        <div class="prev-empty">
            <i class="fas fa-inbox"></i>
            <p id="prev-empty-msg">No se encontraron registros con esos filtros.</p>
        </div>
    </div>

    <!-- ═══════════════ PREVIEW ═══════════════ -->
    <section id="preview-card" class="rep-card" style="display:none;">
        <div class="prev-header">
            <h2 class="prev-title" id="prev-title">
                <i class="fas fa-table"></i> Vista previa
            </h2>
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

    // Elementos de filtro
    const fTipo       = document.getElementById('f-tipo');
    const fEstado     = document.getElementById('f-estado');
    const fMatch      = document.getElementById('f-match');
    const fMoneda     = document.getElementById('f-moneda');
    const fNumero     = document.getElementById('f-numero');
    const fProveedor  = document.getElementById('f-proveedor');
    const fDesde      = document.getElementById('f-desde');
    const fHasta      = document.getElementById('f-hasta');
    const fMontoMin   = document.getElementById('f-monto-min');
    const fMontoMax   = document.getElementById('f-monto-max');
    const fDifMin     = document.getElementById('f-dif-min');
    const fScoreMin   = document.getElementById('f-score-min');
    const fOrden      = document.getElementById('f-orden');
    const fOrdenDir   = document.getElementById('f-orden-dir');
    const fSoloRevis  = document.getElementById('f-solo-revisados');
    const fSoloDif    = document.getElementById('f-solo-diferencias');

    // Wrappers condicionales
    const wEstado   = document.getElementById('wrap-estado');
    const wMatch    = document.getElementById('wrap-match');
    const wMoneda   = document.getElementById('wrap-moneda');
    const wDif      = document.getElementById('wrap-dif');
    const wScore    = document.getElementById('wrap-score');
    const wRevisado = document.getElementById('wrap-revisado');
    const oOrdDif   = document.getElementById('orden-diferencia');
    const oOrdScore = document.getElementById('orden-score');

    // UI
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

    // ── Visibilidad de filtros según tipo ──────────────────────────────
    function actualizarFiltrosVisibles() {
        const t = fTipo.value;
        const esConciliacion = t === 'conciliacion';
        const esFacturas     = t === 'facturas';

        wEstado.style.display   = esConciliacion ? '' : 'none';
        wMatch.style.display    = esConciliacion ? '' : 'none';
        wMoneda.style.display   = esFacturas     ? '' : 'none';
        wDif.style.display      = esConciliacion ? '' : 'none';
        wScore.style.display    = esConciliacion ? '' : 'none';
        wRevisado.style.display = esConciliacion ? '' : 'none';
        oOrdDif.style.display   = esConciliacion ? '' : 'none';
        oOrdScore.style.display = esConciliacion ? '' : 'none';

        if (!esConciliacion) {
            fEstado.value = '';
            fMatch.value  = '';
            fDifMin.value = '';
            fScoreMin.value = '';
            fSoloRevis.checked = false;
            fSoloDif.checked   = false;
        }
        if (!esFacturas) fMoneda.value = '';

        // Resetear orden si no aplica al tipo actual
        if (!esConciliacion && (fOrden.value === 'diferencia' || fOrden.value === 'score')) {
            fOrden.value = 'fecha';
        }
    }
    fTipo.addEventListener('change', actualizarFiltrosVisibles);
    actualizarFiltrosVisibles();

    // ── Construir query string ─────────────────────────────────────────
    function buildQuery() {
        const p = new URLSearchParams();
        p.set('tipo', fTipo.value);
        if (fEstado.value)    p.set('estado',      fEstado.value);
        if (fMatch.value)     p.set('match_tipo',  fMatch.value);
        if (fMoneda.value)    p.set('moneda',       fMoneda.value);
        if (fNumero.value.trim())    p.set('numero',      fNumero.value.trim());
        if (fProveedor.value.trim()) p.set('proveedor',   fProveedor.value.trim());
        if (fDesde.value)     p.set('fecha_desde',  fDesde.value);
        if (fHasta.value)     p.set('fecha_hasta',  fHasta.value);
        if (fMontoMin.value)  p.set('monto_min',    fMontoMin.value);
        if (fMontoMax.value)  p.set('monto_max',    fMontoMax.value);
        if (fDifMin.value)    p.set('diferencia_min', fDifMin.value);
        if (fScoreMin.value)  p.set('score_min',    fScoreMin.value);
        if (fSoloRevis.checked) p.set('solo_revisados', '1');
        if (fSoloDif.checked)   p.set('solo_diferencias', '1');
        p.set('orden',     fOrden.value);
        p.set('orden_dir', fOrdenDir.value);
        return p.toString();
    }

    // ── Helpers de formato ────────────────────────────────────────────
    const fmt = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function formatMonto(v) {
        const n = parseFloat(v);
        return isNaN(n) ? (v ?? '') : fmt.format(n);
    }
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }
    function badgeEstado(codigo, nombre) {
        const cls = 'badge-' + (codigo || 'default');
        return '<span class="badge-estado ' + cls + '">' + escHtml(nombre || codigo) + '</span>';
    }
    function badgeMatch(tipo) {
        const map = { automatico:'badge-automatico', manual:'badge-manual', sin_match:'badge-sin_match2' };
        const cls = map[tipo] || 'badge-default';
        return '<span class="badge-estado ' + cls + '">' + escHtml(tipo || '—') + '</span>';
    }
    function diffCell(val) {
        const n = parseFloat(val);
        if (isNaN(n) || n === 0) return '<td class="num">0.00</td>';
        const cls = n > 0 ? 'diff-pos' : 'diff-neg';
        return '<td class="num ' + cls + '">' + fmt.format(Math.abs(n)) + '</td>';
    }

    // ── Render tabla ──────────────────────────────────────────────────
    function renderConciliacion(rows) {
        let html = '<table class="prev-table"><thead><tr>';
        ['Estado','Match','Fact. Fecha','Fact. N°','Fact. Proveedor','Fact. IVA','Fact. Total',
         'Gas. Fecha','Gas. N°','Gas. Proveedor','Gas. IVA','Gas. Total',
         'Dif. $','Dif. %','Score'].forEach(function(h){
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';

        rows.forEach(function(r, i){
            html += '<tr>';
            html += '<td>' + badgeEstado(r[0], r[0]) + '</td>';
            html += '<td>' + badgeMatch(r[14]) + '</td>';
            html += '<td>' + escHtml(r[2]) + '</td>';
            html += '<td>' + escHtml(r[3]) + '</td>';
            html += '<td>' + escHtml(r[4]) + '</td>';
            html += '<td class="num">' + formatMonto(r[5]) + '</td>';
            html += '<td class="num">' + formatMonto(r[6]) + '</td>';
            html += '<td>' + escHtml(r[7]) + '</td>';
            html += '<td>' + escHtml(r[8]) + '</td>';
            html += '<td>' + escHtml(r[9]) + '</td>';
            html += '<td class="num">' + formatMonto(r[10]) + '</td>';
            html += '<td class="num">' + formatMonto(r[11]) + '</td>';
            html += diffCell(r[12]);
            html += '<td class="num">' + escHtml(r[13]) + '%</td>';
            html += '<td class="num">' + escHtml(r[1]) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderFacturas(rows) {
        let html = '<table class="prev-table"><thead><tr>';
        ['Fecha','N° Factura','Proveedor','Subtotal','IVA','Total','Moneda','Archivo XML'].forEach(function(h){
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';

        rows.forEach(function(r){
            html += '<tr>';
            html += '<td>' + escHtml(r[0]) + '</td>';
            html += '<td>' + escHtml(r[1]) + '</td>';
            html += '<td>' + escHtml(r[2]) + '</td>';
            html += '<td class="num">' + formatMonto(r[3]) + '</td>';
            html += '<td class="num">' + formatMonto(r[4]) + '</td>';
            html += '<td class="num">' + formatMonto(r[5]) + '</td>';
            html += '<td>' + escHtml(r[6]) + '</td>';
            html += '<td style="color:var(--text-muted);font-size:11px;">' + escHtml(r[7]) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderGastos(rows) {
        let html = '<table class="prev-table"><thead><tr>';
        ['Fecha','N° Factura','Proveedor','Items','Base','IVA','Total'].forEach(function(h){
            html += '<th>' + h + '</th>';
        });
        html += '</tr></thead><tbody>';

        rows.forEach(function(r){
            html += '<tr>';
            html += '<td>' + escHtml(r[0]) + '</td>';
            html += '<td>' + escHtml(r[1]) + '</td>';
            html += '<td>' + escHtml(r[2]) + '</td>';
            html += '<td class="num">' + escHtml(r[3]) + '</td>';
            html += '<td class="num">' + formatMonto(r[4]) + '</td>';
            html += '<td class="num">' + formatMonto(r[5]) + '</td>';
            html += '<td class="num">' + formatMonto(r[6]) + '</td>';
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
        const s = data.summary;
        let html = '';
        if (s.total_registros !== undefined) html += '<span class="summary-item">Registros: <strong>' + s.total_registros + '</strong></span>';
        if (s.total_monto      !== undefined) html += '<span class="summary-item">Monto total: <strong>$' + fmt.format(s.total_monto) + '</strong></span>';
        if (s.total_iva        !== undefined) html += '<span class="summary-item">IVA total: <strong>$' + fmt.format(s.total_iva) + '</strong></span>';
        if (s.dif_promedio     !== undefined) html += '<span class="summary-item">Dif. promedio: <strong>' + fmt.format(s.dif_promedio) + '%</strong></span>';
        if (s.score_promedio   !== undefined) html += '<span class="summary-item">Score prom.: <strong>' + fmt.format(s.score_promedio) + '</strong></span>';
        summaryBar.innerHTML = html;
        summaryBar.style.display = html ? '' : 'none';
    }

    // ── Ocultar todo ──────────────────────────────────────────────────
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
            .then(function(r){ return r.json(); })
            .then(function(data){
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

                // Título
                const tipoLabel = { conciliacion:'Conciliación', facturas:'Facturas XML', gastos:'Gastos' };
                prevTitle.innerHTML = '<i class="fas fa-table"></i> ' + (tipoLabel[fTipo.value] || 'Vista previa');

                // Meta
                const total = data.total;
                const shown = data.rows.length;
                prevMeta.innerHTML =
                    '<span><i class="fas fa-list-ol"></i> ' + total + ' registro' + (total !== 1 ? 's' : '') + '</span>' +
                    (data.truncated ? '<span style="color:var(--warn)"><i class="fas fa-exclamation-circle"></i> mostrando ' + shown + '</span>' : '');

                // Summary
                renderSummary(data);

                // Tabla
                let tableHtml = '';
                if (fTipo.value === 'facturas') {
                    tableHtml = renderFacturas(data.rows);
                } else if (fTipo.value === 'gastos') {
                    tableHtml = renderGastos(data.rows);
                } else {
                    tableHtml = renderConciliacion(data.rows);
                }
                prevTableW.innerHTML = tableHtml;

                prevTrunc.style.display = data.truncated ? '' : 'none';
                previewCard.style.display = '';

                // Scroll suave al preview
                previewCard.scrollIntoView({ behavior:'smooth', block:'start' });

                btnExport.disabled = false;
            })
            .catch(function(){
                hideAll();
                btnFiltrar.disabled = false;
                prevEmptyMsg.textContent = 'Error de conexión al servidor.';
                prevEmpty.style.display = '';
            });
    }

    // ── Eventos ───────────────────────────────────────────────────────
    btnFiltrar.addEventListener('click', fetchPreview);

    btnExport.addEventListener('click', function(){
        window.location.href = BASE + '/reportes/exportar?' + buildQuery();
    });

    btnLimpiar.addEventListener('click', function(){
        fTipo.value = 'conciliacion';
        fEstado.value = '';
        fMatch.value = '';
        fMoneda.value = '';
        fNumero.value = '';
        fProveedor.value = '';
        fDesde.value = '';
        fHasta.value = '';
        fMontoMin.value = '';
        fMontoMax.value = '';
        fDifMin.value = '';
        fScoreMin.value = '';
        fOrden.value = 'fecha';
        fOrdenDir.value = 'desc';
        fSoloRevis.checked = false;
        fSoloDif.checked = false;
        actualizarFiltrosVisibles();
        hideAll();
        btnExport.disabled = true;
    });

    // Enter en cualquier input dispara el filtrado
    document.querySelectorAll('.rep-wrap input, .rep-wrap select').forEach(function(el){
        el.addEventListener('keydown', function(e){
            if (e.key === 'Enter') fetchPreview();
        });
    });

})();
</script>
