<?php
$baseUrl             = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats               ??= [];
$importacionesXml    ??= [];
$importacionesGastos ??= [];
$conciliaciones      ??= [];
$estados             ??= [];
$resumen             ??= [];
$facturas            ??= [];
$gastos              ??= [];
$corridas            ??= [];
$corridaActiva       ??= [];
$corridaActiva       = is_array($corridaActiva) ? $corridaActiva : [];
$load_error          ??= '';
?>

<div class="conc-page">
<div class="page-header">
    <div>
        <h1 class="conc-title">
            <i class="fas fa-check-double" style="color:var(--gold);margin-right:8px;"></i>Conciliacion
        </h1>
    </div>
</div>

<?php if (!empty($load_error ?? null)): ?>
    <div class="alert alert-danger mb-16">
        <i class="fas fa-exclamation-circle"></i>
        No fue posible cargar datos del panel: <?= htmlspecialchars($load_error) ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card navy">
        <div class="stat-label">Facturas XML</div>
        <div class="stat-value"><?= number_format((int) ($stats['total_facturas'] ?? 0)) ?></div>
        <div class="stat-sub">Monto total <?= number_format((float) ($stats['monto_facturas'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card gold">
        <div class="stat-label">Gastos Consolidados</div>
        <div class="stat-value"><?= number_format((int) ($stats['total_gastos'] ?? 0)) ?></div>
        <div class="stat-sub">Monto total <?= number_format((float) ($stats['monto_gastos'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card white">
        <div class="stat-label">Conciliadas</div>
        <div class="stat-value"><?= number_format((int) ($stats['total_conciliadas'] ?? 0)) ?></div>
        <div class="stat-sub">Registros resueltos sin intervencion</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label">Pendientes / Diferencias</div>
        <div class="stat-value"><?= number_format((int) ($stats['pendientes_revision'] ?? 0)) ?></div>
        <div class="stat-sub">Requieren revision manual</div>
    </div>
</div>

<div class="conc-grid">
    <section class="card conc-card">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fas fa-file-invoice"></i> Facturas XML cargadas</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" data-modal-target="modal-facturas">
                <i class="fas fa-list"></i> Ver listado
            </button>
        </div>

        <div class="conc-meta">
            <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">
                <i class="fas fa-filter" style="margin-right:3px;"></i>Importación a conciliar
            </label>
            <?php if (empty($importacionesXml)): ?>
                <span style="font-size:12px;color:var(--text-muted);font-style:italic;">Sin importaciones registradas</span>
            <?php else: ?>
                <select name="importacion_id_xml" form="form-conciliar" class="form-control" style="font-size:12px;">
                    <option value="0">Todas (<?= count($importacionesXml) ?> importaciones)</option>
                    <?php foreach ($importacionesXml as $imp): ?>
                    <option value="<?= (int)$imp['id'] ?>">
                        <?= date('d/m/Y', strtotime($imp['fecha_importacion'])) ?>
                        &mdash; <?= htmlspecialchars($imp['archivo_origen']) ?>
                        (<?= (int)$imp['registros_exitosos'] ?> fact.)
                    </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="conc-cta">
            <a href="<?= $baseUrl ?>/facturas" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> Ir a Facturas XML
            </a>
        </div>
    </section>

    <section class="card conc-card">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fas fa-file-csv"></i> Listado de gastos cargado</div>
            </div>
            <button type="button" class="btn btn-outline-gold btn-sm" data-modal-target="modal-gastos">
                <i class="fas fa-list"></i> Ver listado
            </button>
        </div>

        <div class="conc-meta gastos">
            <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">
                <i class="fas fa-filter" style="margin-right:3px;"></i>Importación a conciliar
            </label>
            <?php if (empty($importacionesGastos)): ?>
                <span style="font-size:12px;color:var(--text-muted);font-style:italic;">Sin importaciones registradas</span>
            <?php else: ?>
                <select name="importacion_id_gastos" form="form-conciliar" class="form-control" style="font-size:12px;">
                    <option value="0">Todas (<?= count($importacionesGastos) ?> importaciones)</option>
                    <?php foreach ($importacionesGastos as $imp): ?>
                    <option value="<?= (int)$imp['id'] ?>">
                        <?= date('d/m/Y', strtotime($imp['fecha_importacion'])) ?>
                        &mdash; <?= htmlspecialchars($imp['archivo_origen']) ?>
                        (<?= (int)$imp['registros_exitosos'] ?> reg.)
                    </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="conc-cta">
            <a href="<?= $baseUrl ?>/gastos" class="btn btn-gold">
                <i class="fas fa-arrow-right"></i> Ir a Gastos
            </a>
        </div>
    </section>

    <section class="card conc-card">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fas fa-sliders-h"></i> Ejecucion y seguimiento</div>
            </div>
        </div>

        <?php if (!empty($resumen ?? [])): ?>
            <div class="d-flex flex-wrap gap-8 mb-12 conc-summary-badges">
                <?php foreach ($resumen as $item): ?>
                    <?php
                    $codigoResumen = (string)($item['codigo'] ?? '');
                    $badgeClass = 'badge-default';
                    if ($codigoResumen === 'conciliada') {
                        $badgeClass = 'badge-ok';
                    } elseif ($codigoResumen === 'requiere_revision') {
                        $badgeClass = 'badge-warn';
                    } elseif ($codigoResumen === 'con_diferencias' || $codigoResumen === 'pendiente' || $codigoResumen === 'gasto_sin_xml') {
                        $badgeClass = 'badge-miss';
                    }
                    ?>
                    <span class="badge <?= $badgeClass ?>">
                        <strong><?= htmlspecialchars($item['nombre'] ?? '') ?>:</strong>&nbsp;<?= (int) ($item['total'] ?? 0) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="conc-actions-stack">
            <form method="post" action="<?= $baseUrl ?>/conciliacion/ejecutar" id="form-conciliar">
                <button type="submit" class="btn btn-success conc-exec-btn">
                    <i class="fas fa-check-double"></i> Conciliar ahora
                </button>
            </form>
            <a href="<?= $baseUrl ?>/conciliacion?limpiar=1" class="btn btn-outline" style="width:100%;max-width:320px;justify-content:center;">
                <i class="fas fa-broom"></i> Limpiar
            </a>
        </div>

    </section>
</div>

<div class="card conc-results-card">
    <div class="card-header">
        <div>
            <div class="card-title"><i class="fas fa-table"></i> Resultados de conciliacion</div>
            <?php if (!empty($corridaActiva)): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">
                <i class="fas fa-bookmark" style="color:var(--gold);margin-right:4px;"></i>
                <strong><?= htmlspecialchars($corridaActiva['nombre']) ?></strong>
                &mdash; <?= date('d/m/Y H:i', strtotime($corridaActiva['fecha_ejecucion'])) ?>
                (<?= (int) $corridaActiva['total_registros'] ?> registros)
            </div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-8 flex-wrap" style="align-items:center;">
            <?php if (!empty($corridas)): ?>
            <form method="get" action="<?= $baseUrl ?>/conciliacion" class="d-flex" style="gap:6px;align-items:center;">
                <label for="corrida_id" style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;white-space:nowrap;">
                    <i class="fas fa-history" style="margin-right:3px;"></i>Cargar guardada:
                </label>
                <select name="corrida_id" id="corrida_id" class="form-control" style="font-size:12px;max-width:340px;"
                        onchange="this.form.submit()">
                    <?php foreach ($corridas as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (!empty($corridaActiva) && (int) $corridaActiva['id'] === (int) $c['id']) ? 'selected' : '' ?>>
                        <?= date('d/m/Y H:i', strtotime($c['fecha_ejecucion'])) ?> — <?= htmlspecialchars($c['nombre']) ?> (<?= (int) $c['total_registros'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <?php if (!empty($corridaActiva)): ?>
            <a href="<?= $baseUrl ?>/conciliacion/descargar-xml?corrida_id=<?= (int) $corridaActiva['id'] ?>"
               class="btn btn-gold btn-sm" title="Descarga las facturas XML con gasto asociado, renombradas FE_PROVEEDOR_fecha_numero">
                <i class="fas fa-file-archive"></i> Descargar XML (ZIP)
            </a>
            <button type="button" class="btn btn-outline btn-sm" id="btn-renombrar-pdf"
                    data-corrida="<?= (int) $corridaActiva['id'] ?>"
                    title="Selecciona la carpeta con tus PDF (nombrados con el número de factura) y se renombran igual que los XML. Los PDF no se suben al servidor.">
                <i class="fas fa-file-pdf"></i> Renombrar PDFs
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" data-modal-target="modal-facturas">Ver facturas</button>
            <button type="button" class="btn btn-outline-gold btn-sm" data-modal-target="modal-gastos">Ver listado de gastos</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table conc-results-table">
            <thead>
                <tr>
                    <th colspan="4" class="conc-group-head factura">Facturas</th>
                    <th colspan="4" class="conc-group-head gasto">Gastos</th>
                </tr>
                <tr>
                    <th class="conc-field-head factura">Fecha</th>
                    <th class="conc-field-head factura">Numero</th>
                    <th class="conc-field-head factura">Proveedor</th>
                    <th class="conc-field-head factura right">Total</th>
                    <th class="conc-field-head gasto">Fecha</th>
                    <th class="conc-field-head gasto">Numero</th>
                    <th class="conc-field-head gasto">Proveedor</th>
                    <th class="conc-field-head gasto right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conciliaciones ?? [])): ?>
                    <tr class="empty-row">
                        <td colspan="8">No hay conciliaciones aun. Carga la informacion desde los modulos de Facturas y Gastos, luego presiona <strong>Conciliar ahora</strong>.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $normText = function ($v) {
                        $t = strtoupper(trim((string) $v));
                        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
                        $t = preg_replace('/\s+/', ' ', $t);
                        return trim($t);
                    };
                    // Núcleo numérico: secuencia de dígitos más larga sin ceros a la izquierda.
                    // "FACT-1-1-0000071176-360" y "0000071176" comparten núcleo "71176".
                    $nucleoNum = function ($v) {
                        preg_match_all('/\d+/', (string) $v, $m);
                        $best = '';
                        foreach ($m[0] as $run) {
                            $run = ltrim($run, '0');
                            if (strlen($run) >= 3 && strlen($run) > strlen($best)) {
                                $best = $run;
                            }
                        }
                        return $best;
                    };
                    $numEq = function ($a, $b) use ($nucleoNum) {
                        $na = ltrim(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $a))), '0');
                        $nb = ltrim(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $b))), '0');
                        if ($na !== '' && $na === $nb) {
                            return true;
                        }
                        $ca = $nucleoNum($a);
                        return $ca !== '' && $ca === $nucleoNum($b);
                    };
                    $montoTolerancia = 0.01; // solo redondeo de centavos cuenta como "match" exacto.
                    $eqAmount = function ($a, $b) use ($montoTolerancia) {
                        return abs(((float) $a) - ((float) $b)) <= $montoTolerancia;
                    };
                    $cellClass = function ($both, $equal) {
                        if (!$both) {
                            return 'conc-miss';
                        }
                        return $equal ? 'conc-match' : 'conc-warn';
                    };
                    ?>

                    <?php foreach ($conciliaciones as $row): ?>
                        <?php
                        $facturaFecha = (string) ($row['factura_fecha'] ?? '');
                        $gastoFecha = (string) ($row['gasto_fecha'] ?? '');
                        $facturaNumero = (string) ($row['factura_numero'] ?? '');
                        $gastoNumero = (string) ($row['gasto_numero'] ?? '');
                        $facturaProveedor = (string) ($row['factura_proveedor'] ?? '');
                        $gastoProveedor = (string) ($row['gasto_proveedor'] ?? '');
                        $facturaTotal = (float) ($row['factura_total'] ?? 0);
                        $gastoTotal = (float) ($row['gasto_total'] ?? 0);

                        $bothFecha = $facturaFecha !== '' && $gastoFecha !== '';
                        $bothNumero = $facturaNumero !== '' && $gastoNumero !== '';
                        $bothProveedor = $facturaProveedor !== '' && $gastoProveedor !== '';
                        $bothTotal = ($row['factura_total'] ?? null) !== null && ($row['gasto_total'] ?? null) !== null;

                        $eqFecha = $bothFecha && $facturaFecha === $gastoFecha;
                        $eqNumero = $bothNumero && $numEq($facturaNumero, $gastoNumero);
                        $eqProveedor = $bothProveedor && $normText($facturaProveedor) === $normText($gastoProveedor);
                        $eqTotal = $bothTotal && $eqAmount($facturaTotal, $gastoTotal);
                        ?>
                        <tr>
                            <td class="<?= $cellClass($bothFecha, $eqFecha) ?>" style="border:1px solid #0c2461;"><?= htmlspecialchars($facturaFecha) ?></td>
                            <td class="col-num <?= $cellClass($bothNumero, $eqNumero) ?>" style="border:1px solid #0c2461;" title="<?= htmlspecialchars($facturaNumero) ?>"><?= htmlspecialchars($facturaNumero) ?></td>
                            <td class="col-prov <?= $cellClass($bothProveedor, $eqProveedor) ?>" style="border:1px solid #0c2461;" title="<?= htmlspecialchars($facturaProveedor) ?>"><?= htmlspecialchars($facturaProveedor) ?></td>
                            <td class="right <?= $cellClass($bothTotal, $eqTotal) ?>" style="border:1px solid #0c2461;"><?= number_format($facturaTotal, 2) ?></td>

                            <td class="<?= $cellClass($bothFecha, $eqFecha) ?>" style="border:1px solid #f0a500;"><?= htmlspecialchars($gastoFecha) ?></td>
                            <td class="col-num <?= $cellClass($bothNumero, $eqNumero) ?>" style="border:1px solid #f0a500;" title="<?= htmlspecialchars($gastoNumero) ?>"><?= htmlspecialchars($gastoNumero) ?></td>
                            <td class="col-prov <?= $cellClass($bothProveedor, $eqProveedor) ?>" style="border:1px solid #f0a500;" title="<?= htmlspecialchars($gastoProveedor) ?>"><?= htmlspecialchars($gastoProveedor) ?></td>
                            <td class="right <?= $cellClass($bothTotal, $eqTotal) ?>" style="border:1px solid #f0a500;"><?= number_format($gastoTotal, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<div id="modal-facturas" data-modal class="conc-modal">
    <div class="conc-modal-panel facturas">
        <div class="conc-modal-head">
            <div>
                <h3 class="mb-4 text-navy">Listado de facturas XML</h3>
                <p class="mb-0 text-muted fs-sm">Consulta las facturas sin abandonar el panel principal.</p>
            </div>
            <button type="button" class="conc-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="conc-modal-body">
            <table class="data-table" style="min-width:950px;">
                <thead>
                    <tr>
                        <th>Consecutivo</th>
                        <th>Numero</th>
                        <th>Proveedor</th>
                        <th>Fecha</th>
                        <th class="right">Iva</th>
                        <th class="right">Total</th>
                        <th>Archivo</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facturas ?? [])): ?>
                        <tr class="empty-row"><td colspan="8">No hay facturas cargadas todavia.</td></tr>
                    <?php else: ?>
                        <?php foreach ($facturas as $factura): ?>
                            <tr>
                                <td><?= htmlspecialchars($factura['consecutivo_completo'] ?? '') ?></td>
                                <td><?= htmlspecialchars($factura['numero_factura_asistente'] ?? '') ?></td>
                                <td><?= htmlspecialchars($factura['proveedor_nombre'] ?? 'SIN PROVEEDOR') ?></td>
                                <td><?= htmlspecialchars($factura['fecha_emision'] ?? '') ?></td>
                                <td class="right"><?= number_format((float) ($factura['iva'] ?? 0), 2) ?></td>
                                <td class="right"><?= number_format((float) ($factura['total'] ?? 0), 2) ?></td>
                                <td><?= htmlspecialchars($factura['archivo_xml'] ?? '') ?></td>
                                <td><a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/facturas/ver/<?= (int) ($factura['id'] ?? 0) ?>">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-gastos" data-modal class="conc-modal">
    <div class="conc-modal-panel gastos">
        <div class="conc-modal-head">
            <div>
                <h3 class="mb-4 text-gold">Listado de gastos consolidados</h3>
                <p class="mb-0 text-muted fs-sm">Consulta el consolidado de gastos sin cambiar de pantalla.</p>
            </div>
            <button type="button" class="conc-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="conc-modal-body">
            <table class="data-table" style="min-width:780px;">
                <thead>
                    <tr>
                        <th>Numero</th>
                        <th>Proveedor</th>
                        <th class="center">Items</th>
                        <th>Fecha</th>
                        <th class="right">Iva</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($gastos ?? [])): ?>
                        <tr class="empty-row"><td colspan="6">No hay gastos cargados todavia.</td></tr>
                    <?php else: ?>
                        <?php foreach ($gastos as $gasto): ?>
                            <?php $fechaVisible = $gasto['fecha_max'] ?? ($gasto['fecha_min'] ?? ''); ?>
                            <tr>
                                <td><?= htmlspecialchars($gasto['numero_factura'] ?? '') ?></td>
                                <td><?= htmlspecialchars($gasto['proveedor_texto'] ?? '') ?></td>
                                <td class="center"><?= (int) ($gasto['cantidad_items'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($fechaVisible) ?></td>
                                <td class="right"><?= number_format((float) ($gasto['suma_iva'] ?? 0), 2) ?></td>
                                <td class="right"><?= number_format((float) ($gasto['suma_total'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ── Renombrador de PDFs (File System Access API) ──
// Renombra los PDF locales del usuario con el mismo nombre FE_ que los XML.
// Los archivos NO se suben al servidor: solo se descarga el mapa número→nombre.
(function () {
    var btn = document.getElementById('btn-renombrar-pdf');
    if (!btn) return;

    var BASE = '<?= $baseUrl ?>';

    // Número corto: secuencia de dígitos más larga, sin ceros a la izquierda.
    function numeroCorto(texto) {
        var runs = String(texto).match(/\d+/g) || [];
        var core = '';
        for (var i = 0; i < runs.length; i++) {
            var r = runs[i].replace(/^0+/, '');
            if (r.length > core.length) core = r;
        }
        return core;
    }

    btn.addEventListener('click', function () {
        if (!window.showDirectoryPicker) {
            alert('Esta función requiere Chrome o Edge actualizados (acceso a carpetas del navegador).');
            return;
        }

        var corridaId = btn.getAttribute('data-corrida') || '0';

        fetch(BASE + '/conciliacion/mapa-nombres?corrida_id=' + corridaId, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'No se pudo obtener el mapa de nombres.');
                if (!data.total) throw new Error('La conciliación no tiene facturas con gasto asociado.');
                return window.showDirectoryPicker({ mode: 'readwrite' }).then(function (dir) {
                    return renombrarEnCarpeta(dir, data.mapa);
                });
            })
            .then(function (resumen) {
                var msg = 'Renombrados: ' + resumen.ok + '\n' +
                          'Ya tenían el nombre correcto: ' + resumen.yaCorrectos + '\n' +
                          'Sin coincidencia: ' + resumen.sinMatch.length + '\n' +
                          'Errores: ' + resumen.errores.length;
                if (resumen.sinMatch.length) {
                    msg += '\n\nSin coincidencia (primeros 10):\n' + resumen.sinMatch.slice(0, 10).join('\n');
                }
                if (resumen.errores.length) {
                    msg += '\n\nErrores (primeros 5):\n' + resumen.errores.slice(0, 5).join('\n');
                }
                alert(msg);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return; // usuario canceló el selector
                alert('Error: ' + (err.message || err));
            });
    });

    function renombrarEnCarpeta(dir, mapa) {
        var resumen = { ok: 0, yaCorrectos: 0, sinMatch: [], errores: [] };

        function procesarEntrada(nombre, handle) {
            if (handle.kind !== 'file' || !/\.pdf$/i.test(nombre)) return Promise.resolve();

            var core = numeroCorto(nombre.replace(/\.pdf$/i, ''));
            var destino = core && mapa[core] ? mapa[core] + '.pdf' : null;

            if (!destino) {
                resumen.sinMatch.push(nombre);
                return Promise.resolve();
            }
            if (nombre === destino) {
                resumen.yaCorrectos++;
                return Promise.resolve();
            }
            return handle.move(destino)
                .then(function () { resumen.ok++; })
                .catch(function (e) {
                    resumen.errores.push(nombre + ' → ' + destino + ' (' + (e.message || e.name) + ')');
                });
        }

        // Recolectar primero todas las entradas, luego renombrar
        // (renombrar mientras se itera puede saltarse archivos).
        var entradas = [];
        var iterator = dir.entries()[Symbol.asyncIterator]();

        function recolectar() {
            return iterator.next().then(function (r) {
                if (r.done) return null;
                entradas.push(r.value);
                return recolectar();
            });
        }

        return recolectar()
            .then(function () {
                var cadena = Promise.resolve();
                entradas.forEach(function (par) {
                    cadena = cadena.then(function () { return procesarEntrada(par[0], par[1]); });
                });
                return cadena;
            })
            .then(function () { return resumen; });
    }
})();
</script>