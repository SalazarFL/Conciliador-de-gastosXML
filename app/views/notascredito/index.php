<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$listados = is_array($listados ?? null) ? $listados : [];
$listado = $listado ?? null;
$lineas = is_array($lineas ?? null) ? $lineas : [];
$resumen = is_array($resumen ?? null) ? $resumen : [];
$filtros = is_array($filtros ?? null) ? $filtros : [];
$filtrosActivos = 0;
foreach ($filtros as $valorFiltro) {
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
$opciones = is_array($opciones ?? null) ? $opciones : ['sucursales' => []];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$paginacion = is_array($paginacion ?? null) ? $paginacion : ['page' => 1, 'pages' => 1, 'total' => 0];

function ncFecha($value) {
    $time = $value ? strtotime((string) $value) : false;
    return $time ? date('d/m/Y', $time) : '—';
}
function ncMonto($value, $moneda = 'CRC') {
    return ($moneda === 'USD' ? '$' : '₡') . number_format((float) $value, 2, '.', ',');
}
function ncQuery(array $changes = []) {
    $current = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($current[$key]);
        else $current[$key] = $value;
    }
    return http_build_query($current);
}
?>

<style>
.nc-table-wrap{overflow:auto;max-height:68vh;border:1px solid var(--border);border-radius:9px}
.nc-table{min-width:1650px}.nc-table thead th{position:sticky;z-index:3}.nc-table .nc-head-labels th{top:0}.nc-table .nc-search-row th{top:29px;padding:3px;background:#f8fafc;z-index:4}
.nc-table .nc-search-row input,.nc-table .nc-search-row select{width:100%;min-width:78px;height:24px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;padding:2px 5px;font-size:10px;color:var(--navy);outline:none}
.nc-table .nc-search-row input:focus,.nc-table .nc-search-row select:focus{border-color:var(--gold);box-shadow:0 0 0 2px rgba(212,160,23,.14)}
.nc-table .nc-search-row .nc-search-wide{min-width:145px}.nc-table td{vertical-align:top}
.nc-doc{max-width:230px;overflow-wrap:anywhere;font-weight:650;color:var(--navy)}
.nc-provider{max-width:245px;white-space:normal}.nc-reason{font-size:10.5px;color:#64748b;max-width:190px;white-space:normal;margin-top:4px}
.nc-detail-btn{margin-top:6px;white-space:nowrap}
.nc-detail-context{padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:#f8fafc;margin-bottom:10px}
.nc-detail-context strong{display:block;color:var(--navy);font-size:13px;overflow-wrap:anywhere}
.nc-detail-context span{display:block;color:var(--text-muted);font-size:11px;margin-top:3px;overflow-wrap:anywhere}
.nc-detail-reason{font-size:13px;line-height:1.55;color:var(--text);white-space:pre-wrap;overflow-wrap:anywhere}
.nc-actions{display:flex;gap:4px;align-items:center;flex-wrap:wrap;min-width:150px}
.nc-history-grid{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:6px;margin:8px 0}
.nc-history-stat{border:1px solid var(--border);border-radius:7px;padding:7px 9px;background:#f8fafc}.nc-history-stat strong{display:block;font-size:17px;color:var(--navy)}.nc-history-stat span{font-size:9.5px;text-transform:uppercase;color:var(--text-muted);font-weight:700}
.nc-transition{white-space:nowrap;font-weight:700}.nc-arrow{color:#94a3b8;margin:0 5px}
.nc-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1200;padding:2vh 2vw;overflow:auto}
.nc-modal.open{display:block}.nc-modal-panel{background:#fff;border-radius:12px;max-width:1100px;margin:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
.nc-modal-head{padding:9px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}.nc-modal-body{padding:10px 13px;max-height:78vh;overflow:auto}
.nc-close{margin-left:auto;background:none;border:0;font-size:23px;color:#64748b;cursor:pointer}
.nc-period{font-size:11px;color:var(--text-muted)}
</style>

<div class="card mb-20">
    <div class="card-header mb-12" style="flex-wrap:wrap;">
        <div class="card-title"><i class="fas fa-file-circle-minus" style="color:var(--gold);margin-right:6px;"></i>Notas de crédito acumuladas</div>
        <a href="<?= $baseUrl ?>/notas-xml" class="btn btn-primary btn-sm" style="margin-left:auto;"
           title="Importar comprobantes XML de notas de crédito">
            <i class="fas fa-file-code" style="margin-right:4px;"></i>Cargar notas XML
        </a>
    </div>
    <?php if (empty($sociedadActiva)): ?>
        <div class="alert alert-warning">Debes registrar y activar una sociedad desde Inicio antes de cargar un listado.</div>
    <?php else: ?>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
            Sociedad: <strong style="color:var(--navy);"><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>.
            Cada reporte CSV actualiza saldos y agrega los documentos que todavía no existen.
        </div>
        <a href="<?= $baseUrl ?>/carga" class="btn btn-primary btn-sm">
            <i class="fas fa-inbox" style="margin-right:4px;"></i>Actualizar desde CSV
        </a>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            La vista previa indica cuántas notas son nuevas, cuántos saldos cambian y cuántas filas quedarán intactas.
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($listados)): ?>
<div class="card mb-20">
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:280px;">
            <label style="font-size:11px;font-weight:700;color:var(--navy);">Estado acumulado de la sociedad</label>
            <div style="font-size:14px;font-weight:750;color:var(--navy);padding-top:7px;">
                <?= $listado ? number_format((int) $listado['total_lineas'], 0, ',', '.') : 0 ?> notas conservadas
            </div>
        </div>
        <?php if ($listado): ?>
        <form method="POST" action="<?= $baseUrl ?>/notas-credito/verificar/<?= (int) $listado['id'] ?>">
            <button class="btn btn-outline btn-sm"><i class="fas fa-rotate"></i> Verificar nuevamente</button>
        </form>
        <button type="button" class="btn btn-outline btn-sm" id="nc-history-open"
                data-listado="<?= (int) $listado['id'] ?>">
            <i class="fas fa-clock-rotate-left"></i> Historial
        </button>
        <?php endif; ?>
    </div>
    <?php if ($listado): ?>
    <div class="nc-period" style="margin-top:8px;">
        Última carga: <?= htmlspecialchars($listado['archivo_origen']) ?> ·
        Empresa del reporte: <?= htmlspecialchars($listado['empresa_reporte'] ?: '—') ?> ·
        Período: <?= ncFecha($listado['periodo_desde']) ?> al <?= ncFecha($listado['periodo_hasta']) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /*
 * Acá vivía una fila de cuatro cifras: total, coinciden, con diferencia y sin
 * respaldo. La tabla de abajo las dice una por una y el filtro de la columna
 * Estado lleva a cada grupo. El total sigue vivo donde sirve: en "N filas",
 * que además se actualiza con cada búsqueda.
 */ ?>
<?php if ($listado): ?>
<div class="card">
    <form method="GET" action="<?= $baseUrl ?>/notas-credito" class="filter-bar" id="nc-filter-form">
        <input type="hidden" name="listado_id" value="<?= (int) $listado['id'] ?>">
        <?php
        /*
         * Proveedor, documento, clase, sucursal y estado. "NC proveedor
         * (con/sin)" salió de la barra: la fila de buscadores de la tabla
         * tiene su propia columna, y ahí se ve además cuál es.
         */
        $provFiltro = [
            'valor'    => $filtros['proveedor'] ?? '',
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q'] ?? '') ?>"
                   placeholder="Documento, NC proveedor, entrada o proveedor">
        </div>
        <div>
            <label class="filter-label">Clase de nota</label>
            <select class="form-control" name="clase">
                <option value="">Todas</option>
                <?php foreach ([
                    'directa' => 'Directa (corrige factura)',
                    'costo'   => 'Diferencia de costo',
                    'cambio'  => 'Cambio de mercadería',
                    'ajuste'  => 'Ajuste',
                    'revisar' => 'Por revisar',
                ] as $claveClase => $etiquetaClase): ?>
                <option value="<?= $claveClase ?>" <?= ($filtros['clase'] ?? '') === $claveClase ? 'selected' : '' ?>>
                    <?= htmlspecialchars($etiquetaClase) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filter-label">Sucursal</label>
            <select class="form-control" name="sucursal">
                <option value="">Todas</option>
                <?php foreach ($opciones['sucursales'] as $option): ?>
                <option value="<?= htmlspecialchars($option['valor']) ?>" <?= ($filtros['sucursal'] ?? '') === $option['valor'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($option['valor']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filter-label">Estado</label>
            <select class="form-control" name="estado">
                <option value="">Todos</option>
                <option value="coincide" <?= ($filtros['estado'] ?? '') === 'coincide' ? 'selected' : '' ?>>Coincide</option>
                <option value="con_diferencia" <?= ($filtros['estado'] ?? '') === 'con_diferencia' ? 'selected' : '' ?>>Con diferencia</option>
                <option value="sin_respaldo" <?= ($filtros['estado'] ?? '') === 'sin_respaldo' ? 'selected' : '' ?>>Sin respaldo</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Saldo</label>
            <select class="form-control" name="condicion_saldo">
                <option value="">Todos</option>
                <option value="con_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'con_saldo' ? 'selected' : '' ?>>Con saldo</option>
                <option value="sin_saldo" <?= ($filtros['condicion_saldo'] ?? '') === 'sin_saldo' ? 'selected' : '' ?>>Sin saldo</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($filtrosActivos): ?>
            <a href="<?= $baseUrl ?>/notas-credito?listado_id=<?= (int) $listado['id'] ?>&amp;limpiar=1" class="btn btn-outline btn-sm"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <div id="nc-result-status" class="filter-results" style="margin-bottom:12px;">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        <span>
            Mostrando <strong id="nc-result-count"><?= count($lineas) ?></strong> de
            <strong id="nc-list-total"><?= (int) ($resumen['total'] ?? 0) ?></strong> filas.
            La búsqueda se aplica al acumulado completo. Desplázate horizontalmente para ver todas las columnas.
        </span>
        <?php if ($filtrosActivos): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>
    <div class="nc-table-wrap">
        <table class="data-table nc-table">
            <thead>
                <tr class="nc-head-labels">
                    <th>Estado</th><th>Proveedor</th><th>Sucursal</th>
                    <th>Documento</th><th>Fecha</th><th>NC Proveedor</th><th>Fecha NC proveedor</th>
                    <th class="right">Monto</th><th class="right">Saldo</th>
                    <th>NC XML</th><th class="right">Total XML</th><th class="right">Diferencia</th><th>Acciones</th>
                </tr>
                <?php
                /*
                 * Los filtros de columna se escriben con su valor puesto: la
                 * tabla de abajo ya viene filtrada por ellos —del enlace o de
                 * lo que el módulo recordó— y dejarlos en blanco haría creer
                 * que no hay ningún filtro aplicado. El JS los vuelve a pisar
                 * solo cuando la clave viene en la URL.
                 */
                $ncCol = static function ($clave) use ($filtros) {
                    return htmlspecialchars((string) ($filtros[$clave] ?? ''));
                };
                ?>
                <tr class="nc-search-row">
                    <th>
                        <select data-nc-filter="col_estado" aria-label="Buscar por estado">
                            <?php foreach ([
                                ''               => 'Todos',
                                'coincide'       => 'Coincide',
                                'con_diferencia' => 'Con diferencia',
                                'sin_respaldo'   => 'Sin respaldo',
                            ] as $ncEstadoValor => $ncEstadoTexto): ?>
                            <option value="<?= $ncEstadoValor ?>"<?= ($filtros['col_estado'] ?? '') === $ncEstadoValor ? ' selected' : '' ?>><?= $ncEstadoTexto ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th><input class="nc-search-wide" data-nc-filter="proveedor_nombre" value="<?= $ncCol('proveedor_nombre') ?>" placeholder="Buscar" aria-label="Buscar proveedor"></th>
                    <th><input data-nc-filter="sucursal_texto" value="<?= $ncCol('sucursal_texto') ?>" placeholder="Buscar" aria-label="Buscar sucursal"></th>
                    <th><input class="nc-search-wide" data-nc-filter="documento" value="<?= $ncCol('documento') ?>" placeholder="Buscar" aria-label="Buscar documento"></th>
                    <th><input type="date" data-nc-filter="fecha" value="<?= $ncCol('fecha') ?>" aria-label="Buscar fecha"></th>
                    <th><input data-nc-filter="nc_proveedor" value="<?= $ncCol('nc_proveedor') ?>" placeholder="Buscar" aria-label="Buscar NC proveedor"></th>
                    <th><input type="date" data-nc-filter="fecha_nc_proveedor" value="<?= $ncCol('fecha_nc_proveedor') ?>" aria-label="Buscar fecha NC proveedor"></th>
                    <th><input data-nc-filter="monto" value="<?= $ncCol('monto') ?>" placeholder="Buscar" inputmode="decimal" aria-label="Buscar monto"></th>
                    <th><input data-nc-filter="saldo" value="<?= $ncCol('saldo') ?>" placeholder="Buscar" inputmode="decimal" aria-label="Buscar saldo"></th>
                    <th><input data-nc-filter="nc_xml" value="<?= $ncCol('nc_xml') ?>" placeholder="Buscar" aria-label="Buscar NC XML"></th>
                    <th><input data-nc-filter="xml_total" value="<?= $ncCol('xml_total') ?>" placeholder="Buscar" inputmode="decimal" aria-label="Buscar total XML"></th>
                    <th><input data-nc-filter="diferencia" value="<?= $ncCol('diferencia') ?>" placeholder="Buscar" inputmode="decimal" aria-label="Buscar diferencia"></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="nc-lines-body">
            <?php if (empty($lineas)): ?>
                <tr><td colspan="13" style="text-align:center;padding:18px;color:var(--text-muted);">No hay notas con estos filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($lineas as $row): ?>
                <?php
                $badge = $row['estado'] === 'coincide' ? 'badge-ok' : ($row['estado'] === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
                $label = $row['estado'] === 'coincide' ? 'Coincide' : ($row['estado'] === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
                $motivoMatch = trim((string) ($row['motivo_match'] ?? ''));
                $tieneDetalle = !empty($row['match_manual']) || $motivoMatch !== '';
                ?>
                <tr>
                    <td>
                        <span class="badge <?= $badge ?>"><?= $label ?></span>
                        <?php if ($tieneDetalle): ?>
                        <div>
                            <button type="button" class="btn btn-outline btn-sm nc-detail-btn"
                                    data-estado="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                    data-documento="<?= htmlspecialchars((string) $row['documento'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-proveedor="<?= htmlspecialchars((string) $row['proveedor_nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-motivo="<?= htmlspecialchars($motivoMatch, ENT_QUOTES, 'UTF-8') ?>"
                                    data-manual="<?= !empty($row['match_manual']) ? '1' : '0' ?>"
                                    aria-haspopup="dialog" aria-controls="nc-detail-modal">
                                <i class="fas fa-circle-info"></i> Ver detalles
                            </button>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="nc-provider"><?= htmlspecialchars($row['proveedor_nombre']) ?></td>
                    <td><?= htmlspecialchars($row['sucursal'] ?: '—') ?></td>
                    <td class="nc-doc"><?= htmlspecialchars($row['documento']) ?></td>
                    <td><?= ncFecha($row['fecha']) ?></td>
                    <td class="nc-doc"><?= htmlspecialchars($row['nc_proveedor'] ?: '—') ?></td>
                    <td><?= ncFecha($row['fecha_nc_proveedor']) ?></td>
                    <td class="right"><?= ncMonto($row['monto'], $row['moneda']) ?></td>
                    <td class="right"><?= ncMonto($row['saldo'], $row['moneda']) ?></td>
                    <td>
                        <?php if (!empty($row['factura_xml_id'])): ?>
                            <a href="<?= $baseUrl ?>/notas-xml/ver/<?= (int) $row['factura_xml_id'] ?>" target="_blank" rel="noopener"
                               class="nc-doc" title="Abrir visualización del XML">
                                <?= htmlspecialchars($row['xml_numero'] ?: $row['xml_consecutivo']) ?>
                            </a>
                            <div class="nc-reason"><?= htmlspecialchars($row['xml_proveedor'] ?: '') ?></div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="right"><?= $row['xml_total'] !== null ? ncMonto($row['xml_total'], $row['moneda']) : '—' ?></td>
                    <td class="right" style="<?= $row['estado'] === 'con_diferencia' ? 'color:#dc2626;font-weight:800;' : '' ?>">
                        <?= $row['diferencia'] !== null ? ncMonto($row['diferencia'], $row['moneda']) : '—' ?>
                    </td>
                    <td>
                        <div class="nc-actions">
                            <button type="button" class="btn btn-outline btn-sm nc-link-btn"
                                    data-linea="<?= (int) $row['id'] ?>">
                                <i class="fas fa-link"></i> <?= !empty($row['factura_xml_id']) ? 'Cambiar' : 'Vincular XML' ?>
                            </button>
                            <?php if (!empty($row['factura_xml_id'])): ?>
                            <form method="POST" action="<?= $baseUrl ?>/notas-credito/desvincular"
                                  data-confirm="La nota de crédito XML se desvinculará y el emparejamiento automático quedará bloqueado para esta fila."
                                  data-confirm-title="Desvincular nota de crédito"
                                  data-confirm-type="warning"
                                  data-confirm-accept="Desvincular">
                                <input type="hidden" name="linea_id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif (empty($listados)): ?>
<div class="card" style="text-align:center;padding:22px;color:var(--text-muted);">
    <i class="fas fa-file-circle-minus" style="font-size:34px;margin-bottom:12px;color:#cbd5e1;"></i>
    <div style="font-weight:700;color:var(--navy);">Aún no hay notas de crédito del ERP</div>
    <div style="font-size:12px;margin-top:5px;">Carga el primer CSV para iniciar el acumulado.</div>
</div>
<?php endif; ?>

<!-- Detalle del resultado de verificación -->
<div class="nc-modal" id="nc-detail-modal" role="dialog" aria-modal="true" aria-labelledby="nc-detail-title">
    <div class="nc-modal-panel" style="max-width:620px;">
        <div class="nc-modal-head">
            <i class="fas fa-circle-info" style="color:var(--gold);"></i>
            <strong id="nc-detail-title">Detalle de verificación</strong>
            <button class="nc-close" type="button" data-close="nc-detail-modal" aria-label="Cerrar">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div class="nc-detail-context">
                <strong id="nc-detail-document"></strong>
                <span id="nc-detail-provider"></span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:9px;">
                <span id="nc-detail-state" class="badge"></span>
                <span id="nc-detail-manual" class="badge badge-navy" style="display:none;"><i class="fas fa-hand-pointer"></i> Vínculo manual</span>
            </div>
            <div class="nc-detail-reason" id="nc-detail-reason"></div>
        </div>
    </div>
</div>

<!-- Vinculación manual -->
<div class="nc-modal" id="nc-link-modal">
    <div class="nc-modal-panel">
        <div class="nc-modal-head">
            <i class="fas fa-link" style="color:var(--gold);"></i>
            <div><strong>Vincular NC XML</strong><div id="nc-link-meta" class="nc-period"></div></div>
            <button class="nc-close" type="button" data-close="nc-link-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">
                Solo se muestran NC XML cuyo monto es exactamente igual al monto del reporte.
            </div>
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <input class="form-control" id="nc-candidate-q" placeholder="Filtrar por consecutivo o proveedor">
                <button type="button" class="btn btn-outline btn-sm" id="nc-candidate-search"><i class="fas fa-search"></i></button>
            </div>
            <div class="nc-table-wrap" style="max-height:52vh;">
                <table class="data-table" style="min-width:900px;">
                    <thead><tr><th>Consecutivo XML</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th><th class="right">Diferencia</th><th>Similitud</th><th></th></tr></thead>
                    <tbody id="nc-candidate-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Historial de verificaciones -->
<div class="nc-modal" id="nc-history-modal">
    <div class="nc-modal-panel" style="max-width:1250px;">
        <div class="nc-modal-head">
            <i class="fas fa-clock-rotate-left" style="color:var(--gold);"></i>
            <div><strong>Historial de verificaciones</strong><div class="nc-period">Resultados y cambios guardados en cada ejecución.</div></div>
            <button class="nc-close" type="button" data-close="nc-history-modal">&times;</button>
        </div>
        <div class="nc-modal-body">
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                <div style="min-width:310px;flex:1;">
                    <label style="font-size:11px;font-weight:700;">Verificación</label>
                    <select class="form-control" id="nc-history-select"></select>
                </div>
            </div>
            <div id="nc-history-summary"></div>
            <div class="nc-table-wrap" style="max-height:48vh;">
                <table class="data-table" style="min-width:1180px;">
                    <thead><tr><th>Fila</th><th>Documento</th><th>Proveedor</th><th>Estado</th><th>NC XML</th><th>Diferencia</th><th>Motivo anterior</th><th>Motivo nuevo</th></tr></thead>
                    <tbody id="nc-history-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE = <?= json_encode($baseUrl) ?>;
    var detailModal = document.getElementById('nc-detail-modal');
    var linkModal = document.getElementById('nc-link-modal');
    var historyModal = document.getElementById('nc-history-modal');
    var linesBody = document.getElementById('nc-lines-body');
    var filterForm = document.getElementById('nc-filter-form');
    var currentLine = 0;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function money(value, currency) {
        return (currency === 'USD' ? '$' : '₡') + Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    function dateEs(value) {
        if (!value) return '—';
        var p = String(value).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : value;
    }
    function dateTimeEs(value) {
        if (!value) return '—';
        var parts = String(value).split(' ');
        return dateEs(parts[0]) + (parts[1] ? ' ' + parts[1].slice(0, 5) : '');
    }
    function stateLabel(value) {
        return {coincide:'Coincide', con_diferencia:'Con diferencia', sin_respaldo:'Sin respaldo'}[value] || value || '—';
    }
    function originLabel(value) {
        return {manual:'Manual', automatico:'Automática', carga_inicial:'Carga inicial'}[value] || value || 'Automática';
    }
    function lineRow(row) {
        var estado = String(row.estado || 'sin_respaldo');
        var badge = estado === 'coincide' ? 'badge-ok' : (estado === 'con_diferencia' ? 'badge-diff' : 'badge-miss');
        var label = estado === 'coincide' ? 'Coincide' : (estado === 'con_diferencia' ? 'Con diferencia' : 'Sin respaldo');
        var estadoHtml = '<span class="badge '+badge+'">'+label+'</span>';
        if (Number(row.match_manual || 0) > 0 || row.motivo_match) {
            estadoHtml += '<div><button type="button" class="btn btn-outline btn-sm nc-detail-btn"' +
                ' data-estado="'+esc(label)+'" data-documento="'+esc(row.documento || '')+'"' +
                ' data-proveedor="'+esc(row.proveedor_nombre || '')+'" data-motivo="'+esc(row.motivo_match || '')+'"' +
                ' data-manual="'+(Number(row.match_manual || 0) > 0 ? '1' : '0')+'" aria-haspopup="dialog" aria-controls="nc-detail-modal">' +
                '<i class="fas fa-circle-info"></i> Ver detalles</button></div>';
        }

        var tieneXml = Number(row.factura_xml_id || 0) > 0;
        var xmlHtml = '—';
        if (tieneXml) {
            xmlHtml = '<a href="'+BASE+'/notas-xml/ver/'+Number(row.factura_xml_id)+'" target="_blank" rel="noopener" class="nc-doc" title="Abrir visualización del XML">' +
                esc(row.xml_numero || row.xml_consecutivo || '')+'</a>' +
                '<div class="nc-reason">'+esc(row.xml_proveedor || '')+'</div>';
        }
        var totalXml = row.xml_total !== null && row.xml_total !== '' ? money(row.xml_total, row.moneda) : '—';
        var diferencia = row.diferencia !== null && row.diferencia !== '' ? money(row.diferencia, row.moneda) : '—';
        var diffStyle = estado === 'con_diferencia' ? ' style="color:#dc2626;font-weight:800;"' : '';

        return '<tr>'+
            '<td>'+estadoHtml+'</td>'+
            '<td class="nc-provider">'+esc(row.proveedor_nombre || '')+'</td>'+
            '<td>'+esc(row.sucursal || '—')+'</td>'+
            '<td class="nc-doc">'+esc(row.documento || '')+'</td>'+
            '<td>'+dateEs(row.fecha)+'</td>'+
            '<td class="nc-doc">'+esc(row.nc_proveedor || '—')+'</td>'+
            '<td>'+dateEs(row.fecha_nc_proveedor)+'</td>'+
            '<td class="right">'+money(row.monto, row.moneda)+'</td>'+
            '<td class="right">'+money(row.saldo, row.moneda)+'</td>'+
            '<td>'+xmlHtml+'</td><td class="right">'+totalXml+'</td>'+
            '<td class="right"'+diffStyle+'>'+diferencia+'</td>'+
            '<td><div class="nc-actions">'+
                '<button type="button" class="btn btn-outline btn-sm nc-link-btn" data-linea="'+Number(row.id)+'">' +
                '<i class="fas fa-link"></i> '+(tieneXml ? 'Cambiar' : 'Vincular XML')+'</button>'+
                (tieneXml ? '<form method="POST" action="'+BASE+'/notas-credito/desvincular" data-confirm="La nota de crédito XML se desvinculará y el emparejamiento automático quedará bloqueado para esta fila." data-confirm-title="Desvincular nota de crédito" data-confirm-type="warning" data-confirm-accept="Desvincular">' +
                    '<input type="hidden" name="linea_id" value="'+Number(row.id)+'">' +
                    '<button class="btn btn-outline btn-sm" title="Desvincular"><i class="fas fa-link-slash"></i></button></form>' : '')+
                '</div></td></tr>';
    }
    function renderLines(rows) {
        if (!linesBody) return;
        if (!rows.length) {
            linesBody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:18px;color:#64748b;">No hay notas con estos filtros.</td></tr>';
            return;
        }
        linesBody.innerHTML = rows.map(lineRow).join('');
    }
    function openModal(modal) { if (modal) modal.classList.add('open'); }
    function closeModal(modal) { if (modal) modal.classList.remove('open'); }

    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(document.getElementById(button.dataset.close)); });
    });
    [detailModal, linkModal, historyModal].forEach(function (modal) {
        if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });
    });

    function showDetail(button) {
        var estado = button.dataset.estado || 'Sin respaldo';
        var state = document.getElementById('nc-detail-state');
        state.textContent = estado;
        state.className = 'badge ' + (estado === 'Coincide' ? 'badge-ok' : (estado === 'Con diferencia' ? 'badge-diff' : 'badge-miss'));
        document.getElementById('nc-detail-document').textContent = button.dataset.documento || 'Documento sin identificar';
        document.getElementById('nc-detail-provider').textContent = button.dataset.proveedor || 'Proveedor sin identificar';
        document.getElementById('nc-detail-reason').textContent = button.dataset.motivo || 'Este vínculo se realizó manualmente.';
        document.getElementById('nc-detail-manual').style.display = button.dataset.manual === '1' ? 'inline-block' : 'none';
        openModal(detailModal);
    }

    function renderHistory(data) {
        var select = document.getElementById('nc-history-select');
        var summary = document.getElementById('nc-history-summary');
        var body = document.getElementById('nc-history-body');
        var runs = data.verificaciones || [];
        var selected = data.seleccionada;
        select.innerHTML = runs.map(function (run) {
            return '<option value="'+Number(run.id)+'"'+(selected && Number(run.id) === Number(selected.id) ? ' selected' : '')+'>'+
                dateTimeEs(run.fecha_inicio)+' · '+originLabel(run.origen)+' · '+Number(run.cantidad_cambios)+' cambios</option>';
        }).join('');
        if (!selected) {
            summary.innerHTML = '<div class="alert alert-info" style="margin-top:12px;">Todavía no hay verificaciones guardadas. La próxima ejecución aparecerá aquí.</div>';
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;color:#64748b;">Sin historial disponible.</td></tr>';
            return;
        }
        summary.innerHTML = '<div class="nc-history-grid">'+
            '<div class="nc-history-stat"><strong>'+Number(selected.coincide)+'</strong><span>Coinciden</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.con_diferencia)+'</strong><span>Con diferencia</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.sin_respaldo)+'</strong><span>Sin respaldo</span></div>'+
            '<div class="nc-history-stat"><strong>'+Number(selected.cantidad_cambios)+'</strong><span>Cambios en esta ejecución</span></div></div>';
        var changes = data.cambios || [];
        if (!changes.length) {
            body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;color:#64748b;">La verificación terminó sin modificar ninguna nota.</td></tr>';
            return;
        }
        body.innerHTML = changes.map(function (change) {
            var oldXml = change.xml_anterior || (change.factura_xml_id_anterior ? 'XML #'+change.factura_xml_id_anterior : 'Sin XML');
            var newXml = change.xml_nuevo || (change.factura_xml_id_nuevo ? 'XML #'+change.factura_xml_id_nuevo : 'Sin XML');
            var oldDiff = change.diferencia_anterior === null ? '—' : money(change.diferencia_anterior, change.moneda);
            var newDiff = change.diferencia_nueva === null ? '—' : money(change.diferencia_nueva, change.moneda);
            return '<tr><td>'+esc(change.fila_origen || '—')+'</td><td class="nc-doc">'+esc(change.documento || '—')+'</td>'+
                '<td class="nc-provider">'+esc(change.proveedor_nombre || '—')+'</td>'+
                '<td class="nc-transition">'+esc(stateLabel(change.estado_anterior))+'<span class="nc-arrow">→</span>'+esc(stateLabel(change.estado_nuevo))+'</td>'+
                '<td class="nc-doc">'+esc(oldXml)+'<span class="nc-arrow">→</span>'+esc(newXml)+'</td>'+
                '<td class="nc-transition">'+oldDiff+'<span class="nc-arrow">→</span>'+newDiff+'</td>'+
                '<td>'+esc(change.motivo_anterior || '—')+'</td><td>'+esc(change.motivo_nuevo || '—')+'</td></tr>';
        }).join('');
    }

    function loadHistory(verificationId) {
        var opener = document.getElementById('nc-history-open');
        if (!opener) return;
        var body = document.getElementById('nc-history-body');
        body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:18px;"><i class="fas fa-spinner fa-spin"></i> Cargando historial…</td></tr>';
        var url = BASE + '/notas-credito/historial/' + Number(opener.dataset.listado);
        if (verificationId) url += '?verificacion_id=' + Number(verificationId);
        fetch(url).then(function (response) { return response.json(); }).then(function (data) {
            if (!data.ok) throw new Error(data.message || 'No fue posible cargar el historial.');
            renderHistory(data);
        }).catch(function (error) {
            body.innerHTML = '<tr><td colspan="8" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>';
        });
    }

    var historyOpen = document.getElementById('nc-history-open');
    if (historyOpen) historyOpen.addEventListener('click', function () { openModal(historyModal); loadHistory(0); });
    var historySelect = document.getElementById('nc-history-select');
    if (historySelect) historySelect.addEventListener('change', function () { loadHistory(Number(historySelect.value)); });

    if (filterForm && linesBody) {
        var columnFilters = Array.prototype.slice.call(document.querySelectorAll('[data-nc-filter]'));
        var filterTimer = null;
        var filterRequest = null;
        var currentUrlParams = new URLSearchParams(window.location.search);

        columnFilters.forEach(function (input) {
            var saved = currentUrlParams.get(input.dataset.ncFilter);
            if (saved !== null) input.value = saved;
        });

        function filterParams() {
            var params = new URLSearchParams(new FormData(filterForm));
            params.delete('page');
            columnFilters.forEach(function (input) {
                var value = String(input.value || '').trim();
                if (value) params.set(input.dataset.ncFilter, value);
                else params.delete(input.dataset.ncFilter);
            });
            return params;
        }

        function runLiveSearch() {
            var params = filterParams();
            var resultCount = document.getElementById('nc-result-count');
            if (filterRequest) filterRequest.abort();
            filterRequest = new AbortController();
            if (resultCount) resultCount.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            window.history.replaceState(null, '', BASE + '/notas-credito?' + params.toString());
            fetch(BASE + '/notas-credito/buscar?' + params.toString(), {signal:filterRequest.signal})
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || 'No fue posible buscar las notas.');
                    renderLines(data.lineas || []);
                    if (resultCount) resultCount.textContent = Number(data.total || 0);
                    var listTotal = document.getElementById('nc-list-total');
                    if (listTotal) listTotal.textContent = Number(data.total_listado || 0);
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    linesBody.innerHTML = '<tr><td colspan="13" style="text-align:center;padding:18px;color:#dc2626;">'+esc(error.message)+'</td></tr>';
                    if (resultCount) resultCount.textContent = '0';
                });
        }

        function scheduleLiveSearch(immediate) {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(runLiveSearch, immediate ? 0 : 180);
        }

        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            scheduleLiveSearch(true);
        });
        filterForm.querySelectorAll('input:not([type="hidden"])').forEach(function (input) {
            input.addEventListener('input', function () { scheduleLiveSearch(false); });
        });
        filterForm.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { scheduleLiveSearch(true); });
        });
        columnFilters.forEach(function (input) {
            input.addEventListener(input.tagName === 'SELECT' || input.type === 'date' ? 'change' : 'input', function () {
                scheduleLiveSearch(input.tagName === 'SELECT' || input.type === 'date');
            });
        });
    }

    function loadCandidates() {
        var q = document.getElementById('nc-candidate-q').value || '';
        var body = document.getElementById('nc-candidate-body');
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;"><i class="fas fa-spinner fa-spin"></i> Buscando NC XML…</td></tr>';
        fetch(BASE + '/notas-credito/candidatas?linea_id=' + currentLine + '&q=' + encodeURIComponent(q))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'No fue posible cargar candidatas.');
                var line = data.linea;
                document.getElementById('nc-link-meta').textContent =
                    line.documento + ' · ' + line.proveedor_nombre + ' · ' + money(line.monto, line.moneda);
                if (!data.candidatas.length) {
                    body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:18px;color:#64748b;">No hay NC XML disponibles de este proveedor y moneda.</td></tr>';
                    return;
                }
                // Las de monto distinto ahora también se listan, pero no se
                // vinculan de un clic: llevan la diferencia marcada y piden
                // confirmación, para que aceptarla sea una decisión y no un
                // descuido.
                body.innerHTML = data.candidatas.map(function (row) {
                    var exacto = !!row.monto_exacto;
                    var extra = exacto ? '' : '<input type="hidden" name="aceptar_diferencia" value="1">';
                    var confirma = exacto ? '' :
                        ' data-confirm="El monto del XML no coincide con el reporte. La nota quedará marcada con diferencia." data-confirm-title="Vincular con diferencia" data-confirm-type="warning" data-confirm-accept="Vincular de todos modos"';
                    return '<tr'+(exacto ? '' : ' style="background:#fffbeb;"')+'><td class="nc-doc">'+esc(row.consecutivo_completo || row.numero_factura_asistente)+'</td>' +
                        '<td>'+esc(row.proveedor_nombre)+'</td><td>'+dateEs(row.fecha_emision)+'</td>' +
                        '<td class="right">'+money(row.total,row.moneda)+'</td>' +
                        '<td class="right"'+(exacto ? '' : ' style="color:#b45309;font-weight:700;"')+'>'+money(row.diferencia,row.moneda)+'</td>' +
                        '<td>'+Number(row.score_proveedor).toFixed(1)+'%</td><td>' +
                        '<form method="POST" action="'+BASE+'/notas-credito/vincular"'+confirma+'>' +
                        '<input type="hidden" name="linea_id" value="'+currentLine+'"><input type="hidden" name="factura_id" value="'+Number(row.id)+'">' + extra +
                        '<button class="btn '+(exacto ? 'btn-primary' : 'btn-outline')+' btn-sm"><i class="fas fa-link"></i> '+(exacto ? 'Vincular' : 'Vincular con diferencia')+'</button></form></td></tr>';
                }).join('');
            })
            .catch(function (error) { body.innerHTML='<tr><td colspan="7" style="color:#dc2626;padding:20px;">'+esc(error.message)+'</td></tr>'; });
    }

    if (linesBody) linesBody.addEventListener('click', function (event) {
        var detailButton = event.target.closest('.nc-detail-btn');
        if (detailButton) {
            showDetail(detailButton);
            return;
        }
        var button = event.target.closest('.nc-link-btn');
        if (button) {
            currentLine = Number(button.dataset.linea);
            document.getElementById('nc-candidate-q').value = '';
            openModal(linkModal);
            loadCandidates();
        }
    });
    var search = document.getElementById('nc-candidate-search');
    if (search) search.addEventListener('click', loadCandidates);
    var qInput = document.getElementById('nc-candidate-q');
    if (qInput) qInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); loadCandidates(); } });
})();
</script>
