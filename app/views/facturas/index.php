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

        <div style="margin-bottom:14px;">
            <label for="semana_id" style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:5px;">
                <i class="fas fa-calendar-week" style="margin-right:4px;color:var(--gold);"></i>Semana de trabajo
            </label>
            <select name="semana_id" id="semana_id" class="form-control" style="max-width:300px;font-size:13px;"
                    onchange="semanaCambio(this)">
                <option value="" <?= $semanaFiltro === 0 ? 'selected' : '' ?>>— Sin semana —</option>
                <?php foreach (($semanas ?? []) as $sem): ?>
                <option value="<?= (int) $sem['id'] ?>" <?= $semanaFiltro === (int) $sem['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['nombre']) ?>
                </option>
                <?php endforeach; ?>
                <option value="nueva">➕ Nueva semana…</option>
            </select>
            <div id="semana-nueva-box" style="display:none;margin-top:6px;">
                <input type="text" name="semana_nueva" id="semana_nueva" maxlength="100"
                       placeholder="Semana <?= date('d/m/Y') ?>"
                       class="form-control" style="max-width:300px;font-size:12.5px;">
            </div>

        </div>

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-width:220px;">
                <label for="xml_files" class="upload-file-btn">
                    <i class="fas fa-folder-open"></i> Seleccionar Archivos XML
                </label>
                <span id="files-selected-name" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                    Ningún archivo seleccionado
                </span>

            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-left:auto;">
                <?php if (!empty($facturas)): ?>
                <span class="badge badge-navy" style="font-size:12px;padding:6px 14px;">
                    <i class="fas fa-layer-group"></i>
                    <?= count($facturas) ?> factura<?= count($facturas) !== 1 ? 's' : '' ?>
                    <?php if ($importacionActiva): ?>
                    <span style="font-weight:400;opacity:.75;margin-left:4px;">— <?= htmlspecialchars($importacionActiva['archivo_origen']) ?></span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-lg" id="btn-procesar-xml">
                    <i class="fas fa-cogs"></i> Procesar XML
                </button>
            </div>
        </div>
    </form>

    <!-- Progreso de importación por lotes -->
    <div id="upload-progress" style="display:none;margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
            <span id="up-status" style="font-weight:700;color:var(--navy);">Preparando…</span>
            <span id="up-counts" style="color:var(--text-muted);"></span>
        </div>
        <div style="background:#e2e8f0;border-radius:8px;height:14px;overflow:hidden;">
            <div id="up-bar" style="background:linear-gradient(90deg,#0c2461,#1e3a8a);height:100%;width:0%;transition:width .3s;"></div>
        </div>
        <div id="up-detail" style="font-size:11px;color:var(--text-muted);margin-top:6px;"></div>
    </div>
</div>

<script>
// El selector "Semana de trabajo" controla la lista de abajo: al cambiar
// se recarga mostrando las facturas de esa opción ("Sin semana" = las no
// asignadas). "Nueva semana" solo abre el campo del nombre, sin recargar.
function semanaCambio(sel) {
    var box = document.getElementById('semana-nueva-box');
    if (sel.value === 'nueva') {
        box.style.display = 'block';
        document.getElementById('semana_nueva').focus();
        return;
    }
    box.style.display = 'none';
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

// ── Importación masiva por lotes (sin límite de archivos) ──
// Sube en chunks de CHUNK_SIZE (bajo el max_file_uploads de PHP) y procesa
// en lotes vía la cola del servidor, con progreso en vivo.
(function () {
    var form = document.getElementById('form-xml-upload');
    if (!form || !window.fetch || !window.FormData) return; // sin JS moderno: POST clásico (máx. 20)

    var CHUNK_SIZE  = 10;
    var BATCH_LIMIT = 10;
    var BASE = '<?= $baseUrl ?>';

    var input    = document.getElementById('xml_files');
    var btn      = document.getElementById('btn-procesar-xml');
    var panel    = document.getElementById('upload-progress');
    var elStatus = document.getElementById('up-status');
    var elCounts = document.getElementById('up-counts');
    var elBar    = document.getElementById('up-bar');
    var elDetail = document.getElementById('up-detail');

    function setStatus(t) { elStatus.textContent = t; }
    function setBar(p) { elBar.style.width = Math.max(0, Math.min(100, p)) + '%'; }

    function sendForm(url, fd) {
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            });
    }

    function postJson(url, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return sendForm(url, fd);
    }

    function pintarStats(estado) {
        var s = (estado && estado.stats) || {};
        elCounts.textContent =
            'Importadas: ' + (s.importado || 0) +
            ' · Ya en esta semana: ' + (s.duplicado || 0) +
            ' · Errores: ' + (s.error || 0) +
            ' · Pendientes: ' + (s.pendiente || 0);
    }

    function sleep(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var files  = Array.prototype.slice.call(input.files);

        if (!files.length) { alert('Selecciona al menos un archivo XML.'); return; }

        btn.disabled = true;
        panel.style.display = 'block';
        setBar(0);
        elDetail.textContent = '';

        var importacionId = 0;
        var semanaDestino = '';

        setStatus('Creando importación…');
        var selSemana = document.getElementById('semana_id');
        var inpSemanaNueva = document.getElementById('semana_nueva');
        postJson(BASE + '/facturas/cola/iniciar', {
            total_esperado: files.length,
            semana_id: selSemana ? selSemana.value : '',
            semana_nueva: inpSemanaNueva ? inpSemanaNueva.value : ''
        })
        .then(function (inicio) {
            importacionId = inicio.importacion_id;
            semanaDestino = inicio.semana_id || '';

            // Fase 1: subir archivos en chunks (primer 30% de la barra)
            var cadena = Promise.resolve();
            for (var i = 0; i < files.length; i += CHUNK_SIZE) {
                (function (offset) {
                    cadena = cadena.then(function () {
                        var lote = files.slice(offset, offset + CHUNK_SIZE);
                        var fd = new FormData();
                        fd.append('importacion_id', importacionId);
                        lote.forEach(function (f) { fd.append('xml_files[]', f, f.name); });

                        setStatus('Subiendo archivos… (' + Math.min(offset + lote.length, files.length) + '/' + files.length + ')');
                        return sendForm(BASE + '/facturas/cola/agregar', fd).then(function () {
                            setBar(((offset + lote.length) / files.length) * 30);
                        });
                    });
                })(i);
            }
            return cadena;
        })
        .then(function procesarLoop() {
            setStatus('Procesando facturas…');
            var sinAvance = 0;

            function paso() {
                return postJson(BASE + '/facturas/cola/procesar', {
                    importacion_id: importacionId,
                    limit: BATCH_LIMIT
                }).then(function (r) {
                    var estado = r.estado || {};
                    setBar(30 + ((estado.progress_percent || 0) * 0.7));
                    pintarStats(estado);

                    if (r.completed) return estado;

                    if (!r.processed_in_batch) {
                        sinAvance++;
                        if (sinAvance >= 5) {
                            throw new Error('El procesamiento se detuvo sin completar. Vuelve a intentarlo.');
                        }
                        return sleep(2000).then(paso);
                    }

                    sinAvance = 0;
                    return paso();
                });
            }

            return paso();
        })
        .then(function (estado) {
            setBar(100);
            var s = (estado && estado.stats) || {};
            var importadas = s.importado || 0;
            var repetidas = s.duplicado || 0;
            var erroresImportacion = s.error || 0;

            // Mostrar los primeros problemas reales (archivo + motivo)
            var issues = (estado && estado.recent_issues) || [];
            if (issues.length) {
                var lineas = issues.slice(0, 5).map(function (it) {
                    var motivo = (it.error_texto || it.estado || '').toString().split(':').pop().trim();
                    return '• ' + (it.archivo_original || '¿?') + ' → ' + (it.estado || '') + (motivo ? ' (' + motivo.substring(0, 90) + ')' : '');
                });
                elDetail.innerHTML = lineas.join('<br>');
            }

            if (importadas === 0) {
                if (repetidas > 0 && erroresImportacion === 0) {
                    setStatus('Las ' + repetidas + ' facturas ya estaban en la semana seleccionada; no se duplicaron.');
                } else {
                    setStatus('⚠ La importación terminó sin facturas nuevas (' + erroresImportacion + ' con error). Revisa el detalle.');
                }
                btn.disabled = false;
                return;
            }

            // Volver a la vista de la semana elegida: ahí quedan las recién subidas
            var urlDestino = BASE + '/facturas' + (semanaDestino ? '?semana_id=' + semanaDestino : '');

            if (repetidas > 0 || erroresImportacion > 0) {
                setStatus('Importación completada: ' + importadas + ' importadas, '
                    + repetidas + ' ya estaban en esta semana y ' + erroresImportacion + ' con error.');
                setTimeout(function () {
                    window.location.href = urlDestino;
                }, 3500);
                return;
            }

            setStatus('¡Importación completada! ' + importadas + ' facturas importadas.');
            setTimeout(function () {
                window.location.href = urlDestino;
            }, 800);
        })
        .catch(function (err) {
            setStatus('Error: ' + err.message);
            elDetail.textContent = 'Puedes reintentar: solo se bloquearán las facturas que ya estén en la semana seleccionada.';
            btn.disabled = false;
        });
    });
})();
</script>
