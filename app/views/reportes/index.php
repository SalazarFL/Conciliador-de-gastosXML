<?php
$baseUrl   = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$estados   = $estados ?? [];
$proveedores = $proveedores ?? [];
?>

<div style="max-width:1380px;margin:0 auto;padding:0 16px;">

	<!-- Encabezado -->
	<div style="margin-bottom:18px;">
		<h1 style="margin:0 0 6px;font-size:28px;color:#14324a;">Reportes y Exportación</h1>
		<p style="margin:0;color:#52606d;font-size:14px;">Filtra los datos, revisa la vista previa y exporta a XLSX.</p>
	</div>

	<!-- ── Filtros ── -->
	<section id="filtros-card" style="background:#fff;border:1px solid #d9e4ec;border-radius:20px;padding:22px 24px;box-shadow:0 12px 30px rgba(15,23,42,.05);margin-bottom:18px;">
		<h2 style="margin:0 0 14px;font-size:18px;color:#14324a;"><i class="fas fa-filter" style="margin-right:6px;opacity:.6;"></i>Filtros</h2>

		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 16px;">

			<!-- Tipo de datos -->
			<div>
				<label for="filtro-tipo" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Tipo de datos</label>
				<select id="filtro-tipo" style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
					<option value="conciliacion">Conciliación</option>
					<option value="facturas">Solo Facturas</option>
					<option value="gastos">Solo Gastos</option>
				</select>
			</div>

			<!-- Estado (solo para conciliación) -->
			<div id="filtro-estado-wrap">
				<label for="filtro-estado" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Estado</label>
				<select id="filtro-estado" style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
					<option value="">Todos</option>
					<?php foreach ($estados as $codigo => $e): ?>
						<option value="<?= htmlspecialchars($codigo) ?>"><?= htmlspecialchars($e['nombre']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<!-- Match tipo (solo conciliación) -->
			<div id="filtro-match-wrap">
				<label for="filtro-match" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Tipo de match</label>
				<select id="filtro-match" style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
					<option value="">Todos</option>
					<option value="automatico">Automático</option>
					<option value="manual">Manual</option>
					<option value="sin_match">Sin match</option>
				</select>
			</div>

			<!-- Proveedor -->
			<div>
				<label for="filtro-proveedor" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Proveedor</label>
				<input id="filtro-proveedor" type="text" list="lista-proveedores" placeholder="Buscar proveedor…" autocomplete="off"
					style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
				<datalist id="lista-proveedores">
					<?php foreach ($proveedores as $p): ?>
						<option value="<?= htmlspecialchars($p) ?>">
					<?php endforeach; ?>
				</datalist>
			</div>

			<!-- Fecha desde -->
			<div>
				<label for="filtro-desde" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Fecha desde</label>
				<input id="filtro-desde" type="date" style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
			</div>

			<!-- Fecha hasta -->
			<div>
				<label for="filtro-hasta" style="display:block;font-size:12px;font-weight:700;color:#3e5060;margin-bottom:4px;">Fecha hasta</label>
				<input id="filtro-hasta" type="date" style="width:100%;padding:8px 10px;border:1px solid #c5d3de;border-radius:10px;font-size:13px;background:#f9fbfd;">
			</div>
		</div>

		<!-- Botones -->
		<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
			<button type="button" id="btn-preview" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:999px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 18px rgba(102,126,234,.25);">
				<i class="fas fa-search" style="margin-right:5px;"></i>Vista previa
			</button>
			<button type="button" id="btn-export" disabled style="background:#0f766e;color:#fff;border:none;border-radius:999px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 6px 18px rgba(15,118,110,.2);opacity:.5;">
				<i class="fas fa-file-excel" style="margin-right:5px;"></i>Exportar XLSX
			</button>
			<button type="button" id="btn-clear" style="background:#fff;color:#52606d;border:1px solid #c5d3de;border-radius:999px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer;">
				Limpiar filtros
			</button>
		</div>
	</section>

	<!-- ── Resultado / Vista previa ── -->
	<section id="preview-card" style="background:#fff;border:1px solid #d9e4ec;border-radius:20px;padding:22px 24px;box-shadow:0 12px 30px rgba(15,23,42,.05);display:none;">
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
			<h2 id="preview-title" style="margin:0;font-size:18px;color:#14324a;"><i class="fas fa-table" style="margin-right:6px;opacity:.6;"></i>Vista previa</h2>
			<span id="preview-count" style="font-size:13px;color:#52606d;"></span>
		</div>
		<div id="preview-table-wrap" style="overflow-x:auto;max-height:520px;overflow-y:auto;">
			<!-- tabla dinámica -->
		</div>
		<div id="preview-truncated" style="display:none;margin-top:10px;padding:8px 12px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12px;">
			<i class="fas fa-exclamation-triangle" style="margin-right:4px;"></i>Se muestran solo los primeros 500 registros en la vista previa. La exportación incluye todos.
		</div>
	</section>

	<!-- Mensaje vacío -->
	<div id="preview-empty" style="display:none;text-align:center;padding:40px 20px;color:#94a3b8;">
		<i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;opacity:.4;"></i>
		<p style="margin:0;font-size:15px;">No se encontraron registros con esos filtros.</p>
	</div>

	<!-- Spinner -->
	<div id="preview-loading" style="display:none;text-align:center;padding:40px 20px;">
		<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#667eea;"></i>
		<p style="margin:8px 0 0;font-size:13px;color:#52606d;">Cargando datos…</p>
	</div>
</div>

<script>
(function () {
	const base = <?= json_encode(rtrim($baseUrl, '/')) ?>;

	const elTipo      = document.getElementById('filtro-tipo');
	const elEstado    = document.getElementById('filtro-estado');
	const elMatch     = document.getElementById('filtro-match');
	const elProveedor = document.getElementById('filtro-proveedor');
	const elDesde     = document.getElementById('filtro-desde');
	const elHasta     = document.getElementById('filtro-hasta');
	const btnPreview  = document.getElementById('btn-preview');
	const btnExport   = document.getElementById('btn-export');
	const btnClear    = document.getElementById('btn-clear');

	const previewCard  = document.getElementById('preview-card');
	const previewTitle = document.getElementById('preview-title');
	const previewCount = document.getElementById('preview-count');
	const previewWrap  = document.getElementById('preview-table-wrap');
	const previewTrunc = document.getElementById('preview-truncated');
	const previewEmpty = document.getElementById('preview-empty');
	const previewLoad  = document.getElementById('preview-loading');

	const estadoWrap = document.getElementById('filtro-estado-wrap');
	const matchWrap  = document.getElementById('filtro-match-wrap');

	function toggleConciliacionFilters() {
		const show = elTipo.value === 'conciliacion';
		estadoWrap.style.display = show ? '' : 'none';
		matchWrap.style.display  = show ? '' : 'none';
		if (!show) { elEstado.value = ''; elMatch.value = ''; }
	}
	elTipo.addEventListener('change', toggleConciliacionFilters);
	toggleConciliacionFilters();

	function buildQuery() {
		const params = new URLSearchParams();
		params.set('tipo', elTipo.value);
		if (elEstado.value)    params.set('estado', elEstado.value);
		if (elMatch.value)     params.set('match_tipo', elMatch.value);
		if (elProveedor.value) params.set('proveedor', elProveedor.value);
		if (elDesde.value)     params.set('fecha_desde', elDesde.value);
		if (elHasta.value)     params.set('fecha_hasta', elHasta.value);
		return params.toString();
	}

	function formatNum(v) {
		const n = parseFloat(v);
		if (isNaN(n)) return v;
		return n.toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function renderTable(headers, rows) {
		if (!rows.length) {
			previewCard.style.display = 'none';
			previewEmpty.style.display = '';
			return;
		}
		previewEmpty.style.display = 'none';
		previewCard.style.display = '';

		let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
		html += '<thead><tr>';
		headers.forEach(function (h) {
			html += '<th style="position:sticky;top:0;padding:8px 10px;background:#eef3f9;color:#1e3a5f;text-align:left;border-bottom:2px solid #c5d3de;white-space:nowrap;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">' + escHtml(h) + '</th>';
		});
		html += '</tr></thead><tbody>';

		rows.forEach(function (row, ri) {
			const bg = ri % 2 === 0 ? '#fff' : '#f7fafc';
			html += '<tr style="background:' + bg + ';">';
			row.forEach(function (cell, ci) {
				const isNum = typeof cell === 'number' || (!isNaN(parseFloat(cell)) && /^-?\d/.test(String(cell)) && ci > 0);
				const align = isNum ? 'right' : 'left';
				const display = isNum ? formatNum(cell) : escHtml(String(cell));
				html += '<td style="padding:6px 10px;border-bottom:1px solid #e8edf2;text-align:' + align + ';white-space:nowrap;">' + display + '</td>';
			});
			html += '</tr>';
		});
		html += '</tbody></table>';
		previewWrap.innerHTML = html;
	}

	function escHtml(s) {
		const d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	btnPreview.addEventListener('click', function () {
		previewCard.style.display  = 'none';
		previewEmpty.style.display = 'none';
		previewTrunc.style.display = 'none';
		previewLoad.style.display  = '';
		btnExport.disabled = true;
		btnExport.style.opacity = '.5';

		fetch(base + '/reportes/preview?' + buildQuery())
			.then(function (r) { return r.json(); })
			.then(function (data) {
				previewLoad.style.display = 'none';
				if (!data.success) {
					previewEmpty.style.display = '';
					previewEmpty.querySelector('p').textContent = data.message || 'Error al consultar datos.';
					return;
				}
				const label = elTipo.value === 'facturas' ? 'facturas' : (elTipo.value === 'gastos' ? 'gastos' : 'registros de conciliación');
				previewCount.textContent = data.total + ' ' + label + (data.truncated ? ' (mostrando 500)' : '');
				previewTitle.innerHTML = '<i class="fas fa-table" style="margin-right:6px;opacity:.6;"></i>Vista previa – ' + escHtml(elTipo.options[elTipo.selectedIndex].text);
				previewTrunc.style.display = data.truncated ? '' : 'none';

				renderTable(data.headers, data.rows);

				if (data.total > 0) {
					btnExport.disabled = false;
					btnExport.style.opacity = '1';
				}
			})
			.catch(function () {
				previewLoad.style.display = 'none';
				previewEmpty.style.display = '';
				previewEmpty.querySelector('p').textContent = 'Error de conexión al servidor.';
			});
	});

	btnExport.addEventListener('click', function () {
		window.location.href = base + '/reportes/exportar?' + buildQuery();
	});

	btnClear.addEventListener('click', function () {
		elTipo.value = 'conciliacion';
		elEstado.value = '';
		elMatch.value = '';
		elProveedor.value = '';
		elDesde.value = '';
		elHasta.value = '';
		toggleConciliacionFilters();
		previewCard.style.display  = 'none';
		previewEmpty.style.display = 'none';
		previewTrunc.style.display = 'none';
		btnExport.disabled = true;
		btnExport.style.opacity = '.5';
	});
})();
</script>
