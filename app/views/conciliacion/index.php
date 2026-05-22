<?php
$baseUrl             = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats               = $stats ?? [];
$importacionesXml    = $importacionesXml ?? [];
$importacionesGastos = $importacionesGastos ?? [];
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
        </div>
        <div class="d-flex gap-8 flex-wrap">
            <button type="button" class="btn btn-outline btn-sm" data-modal-target="modal-facturas">Ver facturas</button>
            <button type="button" class="btn btn-outline-gold btn-sm" data-modal-target="modal-gastos">Ver listado de gastos</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table conc-results-table">
            <thead>
                <tr>
                    <th colspan="5" class="conc-group-head factura">Facturas</th>
                    <th colspan="5" class="conc-group-head gasto">Gastos</th>
                    <th rowspan="2" class="center">Match</th>
                    <th rowspan="2" class="center">Estado</th>
                    <th rowspan="2" class="center">Validacion manual</th>
                </tr>
                <tr>
                    <th class="conc-field-head factura">Fecha</th>
                    <th class="conc-field-head factura">Numero</th>
                    <th class="conc-field-head factura">Proveedor</th>
                    <th class="conc-field-head factura right">Iva</th>
                    <th class="conc-field-head factura right">Total</th>
                    <th class="conc-field-head gasto">Fecha</th>
                    <th class="conc-field-head gasto">Numero</th>
                    <th class="conc-field-head gasto">Proveedor</th>
                    <th class="conc-field-head gasto right">Iva</th>
                    <th class="conc-field-head gasto right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conciliaciones ?? [])): ?>
                    <tr class="empty-row">
                        <td colspan="13">No hay conciliaciones aun. Carga la informacion desde los modulos de Facturas y Gastos, luego presiona <strong>Conciliar ahora</strong>.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $normText = function ($v) {
                        $t = strtoupper(trim((string) $v));
                        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
                        $t = preg_replace('/\s+/', ' ', $t);
                        return trim($t);
                    };
                    $normNum = function ($v) {
                        $t = strtoupper(trim((string) $v));
                        $t = preg_replace('/[^A-Z0-9]/', '', $t);
                        $t = preg_replace('/^0+/', '', $t);
                        return $t;
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
                        $facturaIva = (float) ($row['factura_iva'] ?? 0);
                        $gastoIva = (float) ($row['gasto_iva'] ?? 0);
                        $facturaTotal = (float) ($row['factura_total'] ?? 0);
                        $gastoTotal = (float) ($row['gasto_total'] ?? 0);

                        $bothFecha = ($facturaFecha !== '' && $gastoFecha !== '');
                        $bothNumero = ($facturaNumero !== '' && $gastoNumero !== '');
                        $bothProveedor = ($facturaProveedor !== '' && $gastoProveedor !== '');
                        $bothIva = (($row['factura_iva'] ?? null) !== null && ($row['gasto_iva'] ?? null) !== null);
                        $bothTotal = (($row['factura_total'] ?? null) !== null && ($row['gasto_total'] ?? null) !== null);

                        $eqFecha = $bothFecha && ($facturaFecha === $gastoFecha);
                        $eqNumero = $bothNumero && ($normNum($facturaNumero) === $normNum($gastoNumero));
                        $eqProveedor = $bothProveedor && ($normText($facturaProveedor) === $normText($gastoProveedor));
                        $eqIva = $bothIva && $eqAmount($facturaIva, $gastoIva);
                        $eqTotal = $bothTotal && $eqAmount($facturaTotal, $gastoTotal);

                        $estadoCodigo = (string) ($row['estado_codigo'] ?? '');
                        $estadoBadgeClass = 'badge-default';
                        if ($estadoCodigo === 'conciliada') {
                            $estadoBadgeClass = 'badge-ok';
                        } elseif ($estadoCodigo === 'requiere_revision') {
                            $estadoBadgeClass = 'badge-warn';
                        } elseif ($estadoCodigo === 'con_diferencias' || $estadoCodigo === 'pendiente' || $estadoCodigo === 'gasto_sin_xml') {
                            $estadoBadgeClass = 'badge-miss';
                        }
                        ?>
                        <tr>
                            <td class="<?= $cellClass($bothFecha, $eqFecha) ?>" style="border:1px solid #0c2461;"><?= htmlspecialchars($facturaFecha) ?></td>
                            <td class="col-num <?= $cellClass($bothNumero, $eqNumero) ?>" style="border:1px solid #0c2461;" title="<?= htmlspecialchars($facturaNumero) ?>"><?= htmlspecialchars($facturaNumero) ?></td>
                            <td class="col-prov <?= $cellClass($bothProveedor, $eqProveedor) ?>" style="border:1px solid #0c2461;" title="<?= htmlspecialchars($facturaProveedor) ?>"><?= htmlspecialchars($facturaProveedor) ?></td>
                            <td class="right <?= $cellClass($bothIva, $eqIva) ?>" style="border:1px solid #0c2461;"><?= number_format($facturaIva, 2) ?></td>
                            <td class="right <?= $cellClass($bothTotal, $eqTotal) ?>" style="border:1px solid #0c2461;"><?= number_format($facturaTotal, 2) ?></td>

                            <td class="<?= $cellClass($bothFecha, $eqFecha) ?>" style="border:1px solid #f0a500;"><?= htmlspecialchars($gastoFecha) ?></td>
                            <td class="col-num <?= $cellClass($bothNumero, $eqNumero) ?>" style="border:1px solid #f0a500;" title="<?= htmlspecialchars($gastoNumero) ?>"><?= htmlspecialchars($gastoNumero) ?></td>
                            <td class="col-prov <?= $cellClass($bothProveedor, $eqProveedor) ?>" style="border:1px solid #f0a500;" title="<?= htmlspecialchars($gastoProveedor) ?>"><?= htmlspecialchars($gastoProveedor) ?></td>
                            <td class="right <?= $cellClass($bothIva, $eqIva) ?>" style="border:1px solid #f0a500;"><?= number_format($gastoIva, 2) ?></td>
                            <td class="right <?= $cellClass($bothTotal, $eqTotal) ?>" style="border:1px solid #f0a500;"><?= number_format($gastoTotal, 2) ?></td>

                            <td class="center">
                                <span class="badge badge-navy" style="font-size:10px;" title="Score ponderado: monto 40%, número 25%, proveedor 20%, fecha 15%"><?= number_format((float) ($row['match_score'] ?? 0), 1) ?>%</span>
                            </td>
                            <td class="center">
                                <span class="badge <?= $estadoBadgeClass ?>" style="font-size:10px;white-space:nowrap;"><?= htmlspecialchars($row['estado_nombre'] ?? '') ?></span>
                            </td>
                            <td style="min-width:145px;">
                                <form method="post" action="<?= $baseUrl ?>/conciliacion/revisar/<?= (int) ($row['conciliacion_id'] ?? 0) ?>" class="d-flex conc-review-form">
                                    <select name="estado_codigo" class="form-control conc-review-select">
                                        <?php foreach ($estados as $codigo => $estado): ?>
                                            <option value="<?= htmlspecialchars($codigo) ?>" <?= ($codigo === ($row['estado_codigo'] ?? '')) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($estado['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="comentario" value="<?= htmlspecialchars($row['revision_comentario'] ?? ($row['notas'] ?? '')) ?>" placeholder="Comentario" class="form-control conc-review-input">
                                    <button type="submit" class="btn btn-primary btn-sm" title="Guardar"><i class="fas fa-check"></i></button>
                                </form>
                            </td>
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