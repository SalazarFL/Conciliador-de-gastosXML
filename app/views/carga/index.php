<?php
/**
 * Carga de documentos: las cuatro entradas de archivos, en un solo lugar.
 *
 * El orden de las tarjetas no es decorativo: primero los dos listados del ERP,
 * que son los que mandan lo que hay que pagar, y debajo los comprobantes
 * electrónicos que los respaldan. Quien empieza una semana los recorre de
 * arriba abajo.
 *
 * Los POST siguen yendo a los controladores dueños de cada modelo (ver
 * CargaController): esta pantalla solo los reúne.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$sociedadActiva = $sociedadActiva ?? null;
$ultimas = $ultimas ?? ['listado_facturas' => null, 'listado_notas' => null];
$carpetaRaiz = (string) ($carpetaRaiz ?? '');

/** "hace 2 h", que es lo que se quiere saber, y no un timestamp que hay que restar. */
function cargaHace($fecha)
{
    $ts = strtotime((string) $fecha);
    if (!$ts) { return ''; }
    $seg = time() - $ts;
    if ($seg < 3600)  { return 'hace ' . max(1, (int) round($seg / 60)) . ' min'; }
    if ($seg < 86400) { return 'hace ' . (int) round($seg / 3600) . ' h'; }
    return 'hace ' . (int) round($seg / 86400) . ' día(s)';
}
?>

<style>
.carga-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:10px; }
.carga-card { background:#fff; border:1px solid var(--border); border-radius:10px; overflow:hidden;
              border-left:4px solid var(--navy); display:flex; flex-direction:column; }
.carga-card.es-xml { border-left-color:var(--gold); }
.carga-head { padding:10px 14px 7px; }
.carga-head h3 { margin:0 0 2px; font-size:14px; color:var(--navy); }
.carga-head p  { margin:0; font-size:12px; color:var(--text-muted); line-height:1.4; }
.carga-body { padding:8px 14px 10px; margin-top:auto; }
.carga-ultima { font-size:11px; color:var(--text-muted); padding:6px 14px; background:var(--border-light);
                border-top:1px solid var(--border); }
.carga-fila { display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
.carga-nombre { font-size:12px; color:var(--text-muted); font-style:italic; }
.carga-modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000;
               align-items:center; justify-content:center; padding:12px; }
.carga-modal.abierto { display:flex; }
.carga-modal-panel { background:#fff; border-radius:10px; width:min(1000px,100%); max-height:88vh;
                     display:flex; flex-direction:column; overflow:hidden; }
.carga-modal-head { padding:9px 13px; border-bottom:1px solid var(--border); display:flex; gap:8px;
                    align-items:center; }
.carga-modal-head .cerrar { margin-left:auto; background:none; border:0; font-size:24px; cursor:pointer;
                            color:var(--text-muted); line-height:1; }
.carga-modal-body { padding:10px 13px; overflow:auto; }
.carga-modal-pie { padding:9px 13px; border-top:1px solid var(--border); display:flex;
                   justify-content:flex-end; gap:7px; }
.carga-resumen { display:flex; gap:12px; flex-wrap:wrap; font-size:12px; margin-bottom:8px; }
.carga-resumen b { color:var(--navy); }
</style>

<div class="card mb-20" style="border-left:4px solid var(--gold);">
    <div style="padding:0;">
        <h2 style="margin:0 0 3px;font-size:16px;color:var(--navy);">
            <i class="fas fa-inbox" style="margin-right:8px;color:var(--gold);"></i>
            Carga de documentos
        </h2>
        <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.45;">
            Todo lo que entra al sistema desde un archivo se carga aquí. Primero los listados del ERP,
            que dicen qué hay que pagar; después los comprobantes electrónicos que los respaldan.
        </p>
        <p style="margin:5px 0 0;font-size:12px;color:var(--text-muted);line-height:1.45;">
            El <strong>pago semanal</strong> se carga en
            <a href="<?= $baseUrl ?>/por-pagar">Pagos semanales</a> y las
            <strong>devoluciones</strong> en <a href="<?= $baseUrl ?>/devoluciones">Devoluciones</a>:
            son parte del trabajo de esos módulos, no una carga suelta.
        </p>
    </div>
</div>

<?php if (empty($sociedadActiva)): ?>
<div class="alert alert-warning">
    No hay una sociedad activa. Elegí una en <a href="<?= $baseUrl ?>/">Inicio</a> antes de cargar nada:
    los listados del ERP no dicen a qué empresa pertenecen y se sellan con la sociedad seleccionada.
</div>
<?php else: ?>
<div style="font-size:12px;color:var(--text-muted);margin-bottom:9px;">
    Sociedad activa: <strong style="color:var(--navy);"><?= htmlspecialchars($sociedadActiva['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
    <?php if ($carpetaRaiz !== ''): ?>
    · Carpeta de documentos: <span style="font-family:Consolas,monospace;font-size:11.5px;"><?= htmlspecialchars($carpetaRaiz, ENT_QUOTES, 'UTF-8') ?></span>
    <?php endif; ?>
</div>

<div class="carga-grid">

    <!-- ── 1. Listado de facturas del ERP ───────────────────────── -->
    <div class="carga-card">
        <div class="carga-head">
            <h3><i class="fas fa-file-csv" style="margin-right:6px;color:var(--navy-light);"></i>Listado de facturas (ERP)</h3>
            <p>Reporte <em>“Facturas por Proveedor”</em> en CSV. Es la base de todo: el pago semanal
               exige que cada factura ya esté en uno de estos listados.</p>
        </div>
        <div class="carga-body">
            <form method="POST" action="<?= $baseUrl ?>/carga/listado-facturas" enctype="multipart/form-data"
                  class="carga-fila" id="form-erp">
                <input type="file" name="listado_file" id="erp-file" accept=".csv" required style="display:none;">
                <label class="upload-file-btn" for="erp-file"><i class="fas fa-folder-open"></i> Seleccionar CSV</label>
                <span class="carga-nombre" id="erp-nombre">Ningún archivo seleccionado</span>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">
                    <i class="fas fa-database"></i> Cargar listado
                </button>
            </form>
        </div>
        <div class="carga-ultima">
            <?php if ($ultimas['listado_facturas']): ?>
                Último: <strong><?= htmlspecialchars($ultimas['listado_facturas']['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                · <?= number_format($ultimas['listado_facturas']['lineas'], 0, ',', '.') ?> filas
                · <?= cargaHace($ultimas['listado_facturas']['fecha']) ?>
                · <a href="<?= $baseUrl ?>/facturas-erp">ver Facturas ERP</a>
            <?php else: ?>
                Todavía no se ha cargado ningún listado del ERP.
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 2. Listado de notas de crédito ────────────────────────── -->
    <div class="carga-card">
        <div class="carga-head">
            <h3><i class="fas fa-file-csv" style="margin-right:6px;color:var(--navy-light);"></i>Actualizar notas de crédito</h3>
            <p>Reporte <em>“Notas de Crédito por Proveedor”</em> en CSV. La carga actualiza los saldos
               del acumulado y agrega únicamente los documentos nuevos.</p>
        </div>
        <div class="carga-body">
            <form id="form-nc" action="<?= $baseUrl ?>/carga/listado-notas/previa" method="POST"
                  enctype="multipart/form-data" class="carga-fila">
                <input type="file" name="listado_file" id="nc-file" accept=".csv" required style="display:none;">
                <label class="upload-file-btn" for="nc-file"><i class="fas fa-folder-open"></i> Seleccionar CSV</label>
                <span class="carga-nombre" id="nc-nombre">Ningún archivo seleccionado</span>
                <button type="submit" class="btn btn-primary btn-sm" id="nc-btn" style="margin-left:auto;">
                    <i class="fas fa-eye"></i> Vista previa
                </button>
            </form>
        </div>
        <div class="carga-ultima">
            <?php if ($ultimas['listado_notas']): ?>
                Último: <strong><?= htmlspecialchars($ultimas['listado_notas']['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                · <?= number_format($ultimas['listado_notas']['lineas'], 0, ',', '.') ?> filas
                · <?= cargaHace($ultimas['listado_notas']['fecha']) ?>
                · <a href="<?= $baseUrl ?>/notas-credito">ver Notas de crédito</a>
            <?php else: ?>
                Todavía no se ha cargado el reporte de notas.
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 3. Comprobantes XML de facturas ───────────────────────── -->
    <div class="carga-card es-xml">
        <div class="carga-head">
            <h3><i class="fas fa-file-code" style="margin-right:6px;color:var(--gold);"></i>Comprobantes XML de facturas</h3>
            <p>Las facturas electrónicas (FE). Se suben por tandas, sin límite de cantidad.
               Los mensajes de Hacienda y las notas de débito se descartan solos.</p>
        </div>
        <div class="carga-body">
            <form method="post" action="<?= $baseUrl ?>/carga/comprobantes-facturas"
                  enctype="multipart/form-data" id="form-xml">
                <!--
                  Acá se elegía la semana a la que iban las facturas. Ya no se
                  pregunta: al guardarse, cada comprobante busca su factura en
                  el listado del ERP por el consecutivo, y si esa factura está
                  en un pago semanal, hereda esa semana. Elegirla a mano era
                  una decisión que nadie tenía por qué tomar, y equivocarse
                  dejaba la factura fuera del pago sin que nada lo avisara.
                -->
                <input type="file" name="xml_files[]" id="xml_files" multiple accept=".xml" style="display:none;">
                <div class="carga-fila">
                    <label for="xml_files" class="upload-file-btn"><i class="fas fa-folder-open"></i> Seleccionar XML</label>
                    <span class="carga-nombre" id="xml-nombre">Ningún archivo seleccionado</span>
                    <button type="submit" class="btn btn-primary btn-sm" id="xml-btn" style="margin-left:auto;">
                        <i class="fas fa-cogs"></i> Procesar XML
                    </button>
                </div>
            </form>

            <div id="xml-progreso" style="display:none;margin-top:14px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;gap:6px;flex-wrap:wrap;">
                    <span id="xml-estado" style="font-weight:700;color:var(--navy);">Preparando…</span>
                    <span id="xml-conteo" style="color:var(--text-muted);"></span>
                </div>
                <div style="background:#e2e8f0;border-radius:8px;height:14px;overflow:hidden;">
                    <div id="xml-barra" style="background:linear-gradient(90deg,#0c2461,#1e3a8a);height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <div id="xml-detalle" style="font-size:11.5px;color:var(--text-muted);margin-top:8px;line-height:1.6;"></div>
            </div>
        </div>
        <div class="carga-ultima">
            Cada factura se engancha sola con su fila del ERP · <a href="<?= $baseUrl ?>/facturas">ver Facturas XML</a>
        </div>
    </div>

    <!-- ── 4. Comprobantes XML de notas de crédito ───────────────── -->
    <div class="carga-card es-xml">
        <div class="carga-head">
            <h3><i class="fas fa-file-code" style="margin-right:6px;color:var(--gold);"></i>Comprobantes XML de notas</h3>
            <p>Las notas de crédito electrónicas (NC). Al terminar se vuelve a verificar el
               acumulado de notas de la sociedad activa.</p>
        </div>
        <div class="carga-body">
            <form method="post" action="<?= $baseUrl ?>/carga/comprobantes-notas"
                  enctype="multipart/form-data" class="carga-fila">
                <input type="file" name="xml_files[]" id="ncxml-file" accept=".xml" multiple required style="display:none;">
                <label class="upload-file-btn" for="ncxml-file"><i class="fas fa-folder-open"></i> Seleccionar XML</label>
                <span class="carga-nombre" id="ncxml-nombre">Ningún archivo seleccionado</span>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">
                    <i class="fas fa-file-import"></i> Importar notas
                </button>
            </form>
        </div>
        <div class="carga-ultima">
            <a href="<?= $baseUrl ?>/notas-xml">ver Notas XML cargadas</a>
        </div>
    </div>

</div>

<!-- Vista previa del listado de notas -->
<div class="carga-modal" id="nc-modal">
    <div class="carga-modal-panel">
        <div class="carga-modal-head">
            <i class="fas fa-eye" style="color:var(--gold);"></i>
            <div>
                <strong>Vista previa de la actualización</strong>
                <div id="nc-meta" style="font-size:12px;color:var(--text-muted);"></div>
            </div>
            <button class="cerrar" type="button" data-cerrar>&times;</button>
        </div>
        <div class="carga-modal-body">
            <div id="nc-resumen" class="carga-resumen"></div>
            <div id="nc-aviso"></div>
            <div style="overflow:auto;max-height:45vh;">
                <table class="table" style="min-width:900px;font-size:12.5px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Fecha</th>
                    <th style="text-align:right;">Monto</th><th style="text-align:right;">Saldo</th></tr></thead>
                    <tbody id="nc-cuerpo"></tbody>
                </table>
            </div>
        </div>
        <div class="carga-modal-pie">
            <button type="button" class="btn btn-outline btn-sm" data-cerrar>Cancelar</button>
            <form method="POST" action="<?= $baseUrl ?>/carga/listado-notas" id="nc-confirmar">
                <input type="hidden" name="archivo_token" id="nc-token">
                <input type="hidden" name="archivo_nombre" id="nc-original">
                <button class="btn btn-primary btn-sm" id="nc-confirmar-btn">
                    <i class="fas fa-rotate"></i> Actualizar saldos
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var BASE = '<?= $baseUrl ?>';

    // Nombre del archivo elegido, en los cuatro formularios.
    [['erp-file','erp-nombre'],['nc-file','nc-nombre'],['xml_files','xml-nombre'],['ncxml-file','ncxml-nombre']]
    .forEach(function (par) {
        var input = document.getElementById(par[0]);
        var salida = document.getElementById(par[1]);
        if (!input || !salida) { return; }
        input.addEventListener('change', function () {
            var n = input.files.length;
            salida.textContent = n === 0 ? 'Ningún archivo seleccionado'
                : (n === 1 ? input.files[0].name : n + ' archivos seleccionados');
        });
    });

    function enviar(url, fd) {
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
        return enviar(url, fd);
    }
    function dormir(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c];
        });
    }
    function moneda(v, m) {
        return (m === 'USD' ? '$' : '₡') +
            Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fecha(v) {
        if (!v) { return '—'; }
        var p = String(v).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : v;
    }

    // ── Vista previa del listado de notas ──────────────────────
    var formNc = document.getElementById('form-nc');
    var modalNc = document.getElementById('nc-modal');
    if (formNc && modalNc) {
        modalNc.addEventListener('click', function (e) {
            if (e.target === modalNc || e.target.hasAttribute('data-cerrar')) {
                modalNc.classList.remove('abierto');
            }
        });

        formNc.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('nc-btn');
            btn.disabled = true;
            enviar(formNc.action, new FormData(formNc))
                .then(function (d) {
                    document.getElementById('nc-meta').textContent =
                        (d.empresa || '') + (d.periodo_desde ? ' · ' + d.periodo_desde + ' al ' + d.periodo_hasta : '');
                    var s = d.estadisticas || {};
                    var impacto = d.impacto || {};
                    document.getElementById('nc-resumen').innerHTML =
                        '<span>Notas: <b>' + (s.total || 0) + '</b></span>' +
                        '<span>Nuevas: <b>' + (impacto.nuevas || 0) + '</b></span>' +
                        '<span>Saldo cambia: <b>' + (impacto.actualizadas || 0) + '</b></span>' +
                        '<span>Sin cambios: <b>' + (impacto.sin_cambio || 0) + '</b></span>';

                    var aviso = document.getElementById('nc-aviso');
                    var confirmar = document.getElementById('nc-confirmar-btn');
                    aviso.innerHTML = '';
                    confirmar.disabled = false;
                    if (d.duplicado) {
                        aviso.innerHTML = '<div class="alert alert-info" style="margin-bottom:12px;">' +
                            'Este mismo archivo ya fue procesado. Puedes aplicarlo de nuevo; las filas iguales no se tocarán.</div>';
                    } else if (d.errores && d.errores.length) {
                        aviso.innerHTML = '<div class="alert alert-warning" style="margin-bottom:12px;">' +
                            d.errores.length + ' fila(s) inválidas se van a omitir.</div>';
                    }

                    document.getElementById('nc-cuerpo').innerHTML = (d.lineas || []).map(function (r) {
                        return '<tr><td>' + esc(r.fila_origen) + '</td><td>' + esc(r.documento) + '</td>' +
                               '<td>' + esc(r.proveedor_nombre) + '</td><td>' + fecha(r.fecha) + '</td>' +
                               '<td style="text-align:right;">' + moneda(r.monto, r.moneda) + '</td>' +
                               '<td style="text-align:right;">' + moneda(r.saldo, r.moneda) + '</td></tr>';
                    }).join('');

                    document.getElementById('nc-token').value = d.token || '';
                    document.getElementById('nc-original').value = d.archivo || '';
                    modalNc.classList.add('abierto');
                })
                .catch(function (err) {
                    AppDialog.alert(err.message, { title: 'No se pudo analizar el archivo', type: 'danger' });
                })
                .then(function () { btn.disabled = false; });
        });
    }

    // ── XML de facturas: se sube por tandas contra la cola ─────
    // El navegador manda de a CHUNK archivos (por debajo del
    // max_file_uploads de PHP) y luego pide procesar en lotes. Sin esto,
    // subir 300 XML de una semana se topaba con el límite del servidor y
    // se perdían en silencio los que sobraban.
    var formXml = document.getElementById('form-xml');
    if (formXml && window.fetch && window.FormData) {
        var CHUNK = 10, LOTE = 10;
        var entrada = document.getElementById('xml_files');
        var boton   = document.getElementById('xml-btn');
        var panel   = document.getElementById('xml-progreso');
        var estado  = document.getElementById('xml-estado');
        var conteo  = document.getElementById('xml-conteo');
        var barra   = document.getElementById('xml-barra');
        var detalle = document.getElementById('xml-detalle');

        function decir(t) { estado.textContent = t; }
        function avanzar(p) { barra.style.width = Math.max(0, Math.min(100, p)) + '%'; }
        function pintar(e) {
            var s = (e && e.stats) || {};
            conteo.textContent = 'Importadas: ' + (s.importado || 0) +
                ' · Ya en esta semana: ' + (s.duplicado || 0) +
                ' · Errores: ' + (s.error || 0) + ' · Pendientes: ' + (s.pendiente || 0);
        }

        formXml.addEventListener('submit', function (e) {
            e.preventDefault();
            var archivos = Array.prototype.slice.call(entrada.files);
            if (!archivos.length) {
                AppDialog.alert('Selecciona al menos un archivo XML para comenzar la carga.', {
                    title: 'Archivos requeridos', type: 'warning'
                });
                return;
            }

            boton.disabled = true;
            panel.style.display = 'block';
            avanzar(0);
            detalle.textContent = '';
            decir('Creando importación…');

            var importacionId = 0;

            postJson(BASE + '/facturas/cola/iniciar', { total_esperado: archivos.length })
            .then(function (inicio) {
                importacionId = inicio.importacion_id;
                var cadena = Promise.resolve();
                for (var i = 0; i < archivos.length; i += CHUNK) {
                    (function (desde) {
                        cadena = cadena.then(function () {
                            var tanda = archivos.slice(desde, desde + CHUNK);
                            var fd = new FormData();
                            fd.append('importacion_id', importacionId);
                            tanda.forEach(function (f) { fd.append('xml_files[]', f, f.name); });
                            decir('Subiendo archivos… (' + Math.min(desde + tanda.length, archivos.length) + '/' + archivos.length + ')');
                            return enviar(BASE + '/facturas/cola/agregar', fd).then(function () {
                                avanzar(((desde + tanda.length) / archivos.length) * 30);
                            });
                        });
                    })(i);
                }
                return cadena;
            })
            .then(function () {
                decir('Procesando facturas…');
                var sinAvance = 0;
                function paso() {
                    return postJson(BASE + '/facturas/cola/procesar', {
                        importacion_id: importacionId, limit: LOTE
                    }).then(function (r) {
                        var e2 = r.estado || {};
                        avanzar(30 + ((e2.progress_percent || 0) * 0.7));
                        pintar(e2);
                        if (r.completed) { return e2; }
                        if (!r.processed_in_batch) {
                            sinAvance++;
                            if (sinAvance >= 5) { throw new Error('El procesamiento se detuvo sin completar. Volvé a intentarlo.'); }
                            return dormir(2000).then(paso);
                        }
                        sinAvance = 0;
                        return paso();
                    });
                }
                return paso();
            })
            .then(function (e2) {
                avanzar(100);
                var s = (e2 && e2.stats) || {};
                var importadas = s.importado || 0, repetidas = s.duplicado || 0, fallos = s.error || 0;

                var problemas = (e2 && e2.recent_issues) || [];
                if (problemas.length) {
                    detalle.innerHTML = problemas.slice(0, 5).map(function (it) {
                        var motivo = (it.error_texto || it.estado || '').toString().split(':').pop().trim();
                        return '• ' + (it.archivo_original || '¿?') + ' → ' + (it.estado || '') +
                               (motivo ? ' (' + motivo.substring(0, 90) + ')' : '');
                    }).join('<br>');
                }

                if (importadas === 0) {
                    decir(repetidas > 0 && fallos === 0
                        ? 'Las ' + repetidas + ' facturas ya estaban cargadas; no se duplicaron.'
                        : '⚠ Terminó sin facturas nuevas (' + fallos + ' con error). Revisá el detalle.');
                    boton.disabled = false;
                    return;
                }
                var destino = BASE + '/facturas';
                if (repetidas > 0 || fallos > 0) {
                    decir('Listo: ' + importadas + ' importadas, ' + repetidas + ' ya estaban y ' + fallos + ' con error.');
                    setTimeout(function () { window.location.href = destino; }, 3500);
                    return;
                }
                decir('¡Listo! ' + importadas + ' facturas importadas.');
                setTimeout(function () { window.location.href = destino; }, 800);
            })
            .catch(function (err) {
                decir('Error: ' + err.message);
                detalle.textContent = 'Podés reintentar: solo se bloquean las facturas que ya estén en la semana seleccionada.';
                boton.disabled = false;
            });
        });
    }
})();
</script>
