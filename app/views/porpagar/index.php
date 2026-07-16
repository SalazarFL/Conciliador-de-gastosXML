<?php
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$listados       = $listados ?? [];
$listado        = $listado ?? null;
$lineas         = $lineas ?? [];
$resumen        = $resumen ?? [];
$sociedadActiva = $sociedadActiva ?? null;
$semanas        = is_array($semanas ?? null) ? $semanas : [];
$semanaFiltro   = (int) ($semanaFiltro ?? 0);

$respaldadas   = (int) ($resumen['respaldada'] ?? 0);
$conDiferencia = (int) ($resumen['con_diferencia'] ?? 0);
$sinRespaldo   = (int) ($resumen['sin_respaldo'] ?? 0);
$totalLineas   = $respaldadas + $conDiferencia + $sinRespaldo;
$montoTotal    = (float) ($resumen['respaldada_monto'] ?? 0)
               + (float) ($resumen['con_diferencia_monto'] ?? 0)
               + (float) ($resumen['sin_respaldo_monto'] ?? 0);

function ppFecha($f) {
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}
?>

<div class="page-header">
    <div>
        <h1>Facturas por pagar</h1>
        <p>
            Sube el listado del pago semanal y verifica que cada factura tenga su respaldo XML.
            <?php if ($sociedadActiva): ?>
            Sociedad: <strong><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>.
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- ── Subir listado ── -->
<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-file-arrow-up" style="margin-right:6px;color:var(--navy-light);"></i>
            Subir listado del pago semanal
        </div>
    </div>
    <form method="POST" action="<?= $baseUrl ?>/por-pagar/subir" enctype="multipart/form-data" id="pp-form-subir"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1.4;min-width:220px;">
            <label style="font-size:11px;font-weight:700;color:var(--navy);display:block;margin-bottom:3px;">Archivo (CSV o XLSX)</label>
            <input type="file" name="listado_file" class="form-control" style="font-size:12.5px;padding:6px;" accept=".csv,.xlsx" required>
        </div>
        <div style="flex:1;min-width:180px;">
            <label style="font-size:11px;font-weight:700;color:var(--navy);display:block;margin-bottom:3px;">
                <i class="fas fa-calendar-week" style="margin-right:3px;color:var(--gold);"></i>Semana de trabajo
            </label>
            <select name="semana_id" class="form-control" style="font-size:12.5px;"
                    onchange="ppSemanaCambio(this)">
                <option value="" <?= $semanaFiltro === 0 ? 'selected' : '' ?>>— Sin semana —</option>
                <?php foreach ($semanas as $sem): ?>
                <option value="<?= (int) $sem['id'] ?>" <?= $semanaFiltro === (int) $sem['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['nombre']) ?>
                </option>
                <?php endforeach; ?>
                <option value="nueva">➕ Nueva semana…</option>
            </select>
            <input type="text" name="semana_nueva" id="pp-semana-nueva" maxlength="100"
                   placeholder="Semana <?= date('d/m/Y') ?>"
                   class="form-control" style="display:none;font-size:12px;margin-top:5px;">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;" id="pp-btn-subir">
            <i class="fas fa-eye"></i> Vista previa
        </button>
        <div style="flex-basis:100%;font-size:11px;color:var(--text-muted);">
            Columnas requeridas: <code>Fecha</code>, <code>Numero</code>, <code>Proveedor</code>, <code>Total</code>.
            La fecha es informativa (no se compara). Montos en colones.
            Abajo se muestra el listado de la semana elegida; con semana asignada, se verifica
            <strong>solo contra las facturas de esa semana</strong>.
        </div>
    </form>
</div>

<script>
// El selector "Semana de trabajo" controla lo que se ve abajo: al cambiar
// se recarga con el listado de esa opción ("Sin semana" = los no asignados).
// "Nueva semana" solo abre el campo del nombre, sin recargar.
function ppSemanaCambio(sel) {
    var box = document.getElementById('pp-semana-nueva');
    if (sel.value === 'nueva') {
        box.style.display = 'block';
        box.focus();
        return;
    }
    box.style.display = 'none';
    // "Sin semana" navega con semana_id=0 explícito para que se recuerde
    // como selección (y no se confunda con una llegada sin parámetro).
    var base = '<?= $baseUrl ?>/por-pagar';
    window.location.href = base + '?semana_id=' + (sel.value === '' ? '0' : sel.value);
}
</script>

<!-- ── Vista previa de la importación ── -->
<div id="ppv-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;overflow:auto;">
    <div style="background:#fff;border-radius:12px;max-width:780px;width:92%;margin:5vh auto;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <i class="fas fa-eye" style="color:var(--gold);font-size:16px;"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;color:var(--navy);">Vista previa de la importación</div>
                <div id="ppv-destino" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button type="button" id="ppv-cerrar" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div id="ppv-resumen" style="padding:10px 18px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;border-bottom:1px solid var(--border);flex-shrink:0;"></div>
        <div style="overflow:auto;flex:1;">
            <table class="data-table" style="font-size:12.5px;">
                <thead>
                    <tr><th></th><th>Número</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th></tr>
                </thead>
                <tbody id="ppv-tbody"></tbody>
            </table>
        </div>
        <div style="padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;">
            <button type="button" class="btn btn-outline btn-sm" id="ppv-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" id="ppv-importar"></button>
        </div>
    </div>
</div>

<script>
// Vista previa: el formulario de subida ya no importa directo — primero
// analiza el archivo (sin escribir nada) y muestra qué es nuevo, qué ya
// estaba en el listado de la semana y qué viene ilegible. Importar de
// verdad reenvía el archivo guardado (token) a /por-pagar/subir.
(function () {
    var form = document.getElementById('pp-form-subir');
    var overlay = document.getElementById('ppv-overlay');
    if (!form || !overlay) return;

    var btnSubir = document.getElementById('pp-btn-subir');
    var btnImportar = document.getElementById('ppv-importar');
    var vistaActual = null;

    function cerrar() { overlay.style.display = 'none'; }
    document.getElementById('ppv-cerrar').addEventListener('click', cerrar);
    document.getElementById('ppv-cancelar').addEventListener('click', cerrar);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrar(); });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function fmtMonto(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        btnSubir.disabled = true;
        btnSubir.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando…';

        fetch('<?= $baseUrl ?>/por-pagar/previsualizar', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Respuesta inválida del servidor.'); }); })
            .then(function (r) {
                if (!r.ok) throw new Error(r.message || 'No se pudo analizar el archivo.');
                vistaActual = r;
                pintar(r);
                overlay.style.display = 'block';
            })
            .catch(function (err) { alert(err.message); })
            .then(function () {
                btnSubir.disabled = false;
                btnSubir.innerHTML = '<i class="fas fa-eye"></i> Vista previa';
            });
    });

    function pintar(r) {
        document.getElementById('ppv-destino').textContent = r.listado_existente
            ? 'Se añadirán SOLO las nuevas al listado existente "' + r.listado_existente + '" · ' + r.archivo
            : 'Se creará un listado nuevo · ' + r.archivo;

        document.getElementById('ppv-resumen').innerHTML =
            '<span style="color:#16a34a;font-weight:800;">' + r.nuevas + ' nueva' + (r.nuevas !== 1 ? 's' : '') + '</span>' +
            '<span style="color:var(--text-muted);">₡' + fmtMonto(r.monto_nuevas) + ' en nuevas</span>' +
            (r.repetidas ? '<span style="color:#b45309;font-weight:600;">' + r.repetidas + ' ya estaba' + (r.repetidas !== 1 ? 'n' : '') + ' (se omiten)</span>' : '') +
            (r.errores ? '<span style="color:#b91c1c;font-weight:600;">' + r.errores + ' fila' + (r.errores !== 1 ? 's' : '') + ' ilegible' + (r.errores !== 1 ? 's' : '') + '</span>' : '');

        document.getElementById('ppv-tbody').innerHTML = (r.lineas || []).map(function (l) {
            var badge;
            if (l.estado === 'nueva') {
                badge = '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;">nueva</span>';
            } else if (l.estado === 'repetida') {
                badge = '<span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;">ya estaba</span>';
            } else {
                badge = '<span style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;white-space:nowrap;" title="' + esc(l.motivo) + '">error</span>';
            }
            var fecha = l.fecha ? String(l.fecha).split('-').reverse().join('/') : '—';
            return '<tr' + (l.estado !== 'nueva' ? ' style="opacity:.55;"' : '') + '>'
                + '<td>' + badge + '</td>'
                + '<td style="white-space:nowrap;font-weight:600;">' + esc(l.numero) + '</td>'
                + '<td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(l.proveedor) + '">' + esc(l.proveedor) + '</td>'
                + '<td style="white-space:nowrap;">' + fecha + '</td>'
                + '<td class="right" style="white-space:nowrap;">' + fmtMonto(l.total) + '</td>'
                + '</tr>';
        }).join('');

        btnImportar.disabled = r.nuevas === 0;
        btnImportar.innerHTML = r.nuevas === 0
            ? 'Nada nuevo que importar'
            : '<i class="fas fa-check-double"></i> Importar ' + r.nuevas + ' nueva' + (r.nuevas !== 1 ? 's' : '');
    }

    btnImportar.addEventListener('click', function () {
        if (!vistaActual) return;
        btnImportar.disabled = true;
        btnImportar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando…';

        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '<?= $baseUrl ?>/por-pagar/subir';
        var semanaNueva = form.querySelector('[name="semana_nueva"]');
        var campos = {
            archivo_token: vistaActual.token,
            archivo_nombre: vistaActual.archivo,
            semana_id: form.querySelector('[name="semana_id"]').value,
            semana_nueva: semanaNueva ? semanaNueva.value : ''
        };
        Object.keys(campos).forEach(function (k) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = k;
            i.value = campos[k];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    });
})();
</script>

<?php if ($listado): ?>

<!-- ── Resumen del listado ── -->
<div class="stats-grid mb-20">
    <div class="stat-card navy">
        <div class="stat-label"><i class="fas fa-list-check" style="margin-right:5px;"></i>Facturas por pagar</div>
        <div class="stat-value"><?= $totalLineas ?></div>
        <div class="stat-sub">₡<?= number_format($montoTotal, 2) ?></div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-circle-check" style="margin-right:5px;"></i>Respaldadas</div>
        <div class="stat-value" style="color:var(--ok);"><?= $respaldadas ?></div>
        <div class="stat-sub">XML encontrado y monto cuadra</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-triangle-exclamation" style="margin-right:5px;"></i>Con diferencia</div>
        <div class="stat-value" style="color:#dc2626;"><?= $conDiferencia ?></div>
        <div class="stat-sub">El monto no cuadra con el XML</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-file-circle-question" style="margin-right:5px;"></i>Sin respaldo</div>
        <div class="stat-value" style="color:var(--warn);"><?= $sinRespaldo ?></div>
        <div class="stat-sub">Falta el XML: búscalo en el correo</div>
    </div>
</div>

<!-- ── Checklist ── -->
<div class="card">
    <div class="card-header mb-12" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-clipboard-check" style="margin-right:6px;color:var(--navy-light);"></i>
            <?= htmlspecialchars($listado['nombre']) ?>
            <?php if (!empty($listado['semana_nombre'])): ?>
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;"
                  title="Verificado solo contra las facturas de esta semana">
                <i class="fas fa-calendar-week"></i> <?= htmlspecialchars($listado['semana_nombre']) ?>
            </span>
            <?php endif; ?>
            <span style="font-weight:400;font-size:11.5px;color:var(--text-muted);">
                — subido el <?= ppFecha($listado['fecha_subida']) ?>
                <?= !empty($listado['sociedad_nombre']) ? ' · ' . htmlspecialchars($listado['sociedad_nombre']) : '' ?>
                <?= empty($listado['semana_nombre']) ? ' · verificado contra todas las facturas' : '' ?>
            </span>
        </div>

        <?php if (count($listados) > 1): ?>
        <form method="GET" action="<?= $baseUrl ?>/por-pagar">
            <input type="hidden" name="semana_id" value="<?= (int) $semanaFiltro ?>">
            <select name="listado_id" class="form-control" style="font-size:12px;padding:5px 8px;" onchange="this.form.submit()">
                <?php foreach ($listados as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === (int) $listado['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['nombre']) ?><?= !empty($l['semana_nombre']) ? ' · ' . htmlspecialchars($l['semana_nombre']) : '' ?> (<?= (int) $l['total_lineas'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <form method="POST" action="<?= $baseUrl ?>/por-pagar/verificar/<?= (int) $listado['id'] ?>" style="display:inline;">
            <button type="submit" class="btn btn-primary btn-sm" title="Volver a cruzar contra las facturas actuales (útil después de importar del correo)">
                <i class="fas fa-rotate"></i> Verificar de nuevo
            </button>
        </form>
        <a href="<?= $baseUrl ?>/por-pagar/exportar?listado_id=<?= (int) $listado['id'] ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-file-csv"></i> Exportar
        </a>
        <form method="POST" action="<?= $baseUrl ?>/por-pagar/eliminar/<?= (int) $listado['id'] ?>" style="display:inline;"
              onsubmit="return confirm('¿Eliminar este listado y sus resultados?');">
            <button type="submit" class="btn btn-outline btn-sm" title="Eliminar listado" style="color:#b91c1c;border-color:#fed7d7;">
                <i class="fas fa-trash-can"></i>
            </button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:12.5px;">
            <thead>
                <tr>
                    <th style="width:120px;">Estado</th>
                    <th>Fecha</th>
                    <th>Número</th>
                    <th>Proveedor</th>
                    <th class="right">Total listado</th>
                    <th>Factura XML</th>
                    <th class="right">Diferencia</th>
                    <th class="center" style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lineas as $linea): ?>
                <tr>
                    <td>
                        <?php if ($linea['estado'] === 'respaldada'): ?>
                        <span class="badge badge-ok"><i class="fas fa-check-circle"></i> Respaldada</span>
                        <?php elseif ($linea['estado'] === 'con_diferencia'): ?>
                        <span class="badge" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-triangle-exclamation"></i> Diferencia</span>
                        <?php else: ?>
                        <span class="badge" style="background:#f1f5f9;color:#475569;"><i class="fas fa-file-circle-question"></i> Sin respaldo</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?= ppFecha($linea['fecha']) ?></td>
                    <td style="max-width:210px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:var(--navy);"
                        title="<?= htmlspecialchars($linea['numero']) ?>">
                        <?= htmlspecialchars($linea['numero']) ?>
                    </td>
                    <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($linea['proveedor_texto']) ?>">
                        <?= htmlspecialchars($linea['proveedor_texto']) ?>
                    </td>
                    <td class="right" style="white-space:nowrap;"><?= number_format((float) $linea['total'], 2) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($linea['factura_xml_id'])): ?>
                        <span style="font-weight:600;color:var(--navy);"><?= htmlspecialchars(ltrim((string) $linea['xml_numero'], '0')) ?></span>
                        <span style="color:var(--text-muted);font-size:11.5px;">· <?= number_format((float) $linea['xml_total'], 2) ?></span>
                        <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="right" style="white-space:nowrap;">
                        <?php if ($linea['estado'] === 'con_diferencia' && $linea['diferencia'] !== null): ?>
                        <?php $dif = (float) $linea['diferencia']; ?>
                        <span style="color:<?= $dif > 0 ? '#16a34a' : '#b91c1c' ?>;font-weight:700;">
                            <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="center" style="white-space:nowrap;">
                        <?php if ($linea['estado'] === 'sin_respaldo'): ?>
                        <a href="<?= $baseUrl ?>/correo?buscar=<?= urlencode((string) $linea['numero_busqueda']) ?>&pp_listado=<?= (int) $listado['id'] ?>&pp_linea=<?= (int) $linea['id'] ?>"
                           class="btn btn-outline btn-sm" title="Buscar esta factura en el correo">
                            <i class="fas fa-envelope-open-text"></i> Buscar en correo
                        </a>
                        <?php elseif (!empty($linea['factura_xml_id'])): ?>
                        <a href="<?= $baseUrl ?>/facturas/ver/<?= (int) $linea['factura_xml_id'] ?>"
                           class="btn btn-outline btn-sm" title="Ver la factura XML">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div class="card" style="text-align:center;padding:40px 20px;color:var(--text-muted);">
    <i class="fas fa-file-invoice-dollar" style="font-size:34px;color:#cbd5e1;margin-bottom:10px;display:block;"></i>
    <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px;">
        No hay listados <?= $semanaFiltro === 0 ? 'sin semana' : 'para esta semana' ?>
    </div>
    <div style="font-size:12.5px;">
        Elige otra semana en el selector de arriba, o sube el listado del pago de esta semana.
    </div>
</div>

<?php endif; ?>
