<?php
$baseUrl           = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$facturas          = $facturas ?? [];
$historial         = $historial ?? [];
$importacionActiva = $importacionActiva ?? null;
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

        <div style="margin-bottom:14px;">
            <label for="nombre_importacion" style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:5px;">
                <i class="fas fa-tag" style="margin-right:4px;color:var(--gold);"></i>Nombre de la importación <span style="color:#b91c1c;">*</span>
            </label>
            <input type="text" name="nombre_importacion" id="nombre_importacion" required
                   maxlength="120" placeholder="Ej: Facturas enero 2026, Proveedor Bejos semana 3…"
                   class="form-control" style="max-width:420px;font-size:13px;">
            <span style="display:block;font-size:11px;color:var(--text-muted);margin-top:3px;">
                Este nombre identificará la importación al momento de conciliar.
            </span>
        </div>

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-width:220px;">
                <label for="xml_files" class="upload-file-btn">
                    <i class="fas fa-folder-open"></i> Seleccionar Archivos XML
                </label>
                <span id="files-selected-name" style="font-size:12px;color:var(--text-muted);font-style:italic;">
                    Ningún archivo seleccionado
                </span>
                <span style="font-size:11px;color:var(--text-muted);flex-basis:100%;">
                    Sin límite de cantidad: los archivos se suben y procesan por lotes automáticamente.
                </span>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" id="btn-procesar-xml" style="margin-left:auto;">
                <i class="fas fa-cogs"></i> Procesar XML
            </button>
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
                    <th>Fecha</th>
                    <th>Número Factura</th>
                    <th>Proveedor</th>
                    <th class="right">IVA</th>
                    <th class="right">Monto</th>
                    <th class="center">Estado</th>
                    <th class="center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($facturas)): ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            <i class="fas fa-inbox" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                            No hay facturas importadas todavía.<br>
                            <span style="font-size:12px;">Usa el formulario de arriba para subir archivos XML.</span>
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
            ' · Duplicadas: ' + (s.duplicado || 0) +
            ' · Errores: ' + (s.error || 0) +
            ' · Pendientes: ' + (s.pendiente || 0);
    }

    function sleep(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var files  = Array.prototype.slice.call(input.files);
        var nombre = (document.getElementById('nombre_importacion').value || '').trim();

        if (!nombre) { alert('Debes asignar un nombre a la importación.'); return; }
        if (!files.length) { alert('Selecciona al menos un archivo XML.'); return; }

        btn.disabled = true;
        panel.style.display = 'block';
        setBar(0);
        elDetail.textContent = '';

        var importacionId = 0;

        setStatus('Creando importación…');
        postJson(BASE + '/facturas/cola/iniciar', {
            nombre_importacion: nombre,
            total_esperado: files.length
        })
        .then(function (inicio) {
            importacionId = inicio.importacion_id;

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
                            throw new Error('El procesamiento se detuvo sin completar. Revisa el historial de importaciones.');
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
            var problemas  = (s.duplicado || 0) + (s.error || 0);

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
                setStatus('⚠ La importación terminó pero NO se importó ninguna factura (' + problemas + ' con problema). Revisa el detalle.');
                btn.disabled = false;
                return;
            }

            if (problemas > 0) {
                setStatus('Importación completada con avisos: ' + importadas + ' importadas, ' + problemas + ' con problema.');
                setTimeout(function () {
                    window.location.href = BASE + '/facturas?importacion_id=' + importacionId;
                }, 3500);
                return;
            }

            setStatus('¡Importación completada! ' + importadas + ' facturas importadas.');
            setTimeout(function () {
                window.location.href = BASE + '/facturas?importacion_id=' + importacionId;
            }, 800);
        })
        .catch(function (err) {
            setStatus('Error: ' + err.message);
            elDetail.textContent = 'Puedes reintentar con los mismos archivos: los ya importados se marcarán como duplicados y no se repetirán.';
            btn.disabled = false;
        });
    });
})();

function toggleHistory(btn) {
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!expanded));
    btn.nextElementSibling.classList.toggle('open', !expanded);
}
</script>
