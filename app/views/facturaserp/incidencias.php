<?php
/**
 * Historial de incidencias de las cargas del listado del ERP.
 * Cada carga vuelve a evaluar el archivo entero, así que una misma factura
 * problemática aparece una vez por carga: eso es el historial.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$incidencias = is_array($incidencias ?? null) ? $incidencias : [];
$resumenTipos = is_array($resumenTipos ?? null) ? $resumenTipos : [];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$cargas = is_array($cargas ?? null) ? $cargas : [];
$catalogo = is_array($catalogo ?? null) ? $catalogo : [];
$pagina = (int) ($pagina ?? 1);
$totalPaginas = (int) ($totalPaginas ?? 1);
$total = (int) ($total ?? 0);

$totalDescartadas = (int) ($totalDescartadas ?? 0);
$filtros = array_replace([
    'ver' => 'vigentes', 'carga_id' => 0, 'tipo' => '', 'severidad' => '', 'proveedor' => '', 'texto' => '',
], is_array($filtros ?? null) ? $filtros : []);
$viendoDescartadas = $filtros['ver'] === 'descartadas';

$query = array_filter([
    'ver' => $filtros['ver'] !== 'vigentes' ? $filtros['ver'] : '',
    'carga' => $filtros['carga_id'] ?: '', 'tipo' => $filtros['tipo'],
    'severidad' => $filtros['severidad'], 'proveedor' => $filtros['proveedor'], 'q' => $filtros['texto'],
], function ($v) { return $v !== '' && $v !== null && $v !== 0; });
$queryStr = $query ? '?' . http_build_query($query) : '';

$etiqueta = function ($tipo) use ($catalogo) {
    return $catalogo[$tipo][1] ?? ucfirst(str_replace('_', ' ', (string) $tipo));
};
function ieFecha($f)
{
    $ts = $f ? strtotime((string) $f) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
}
$alertas = 0;
foreach ($resumenTipos as $r) { if ($r['severidad'] === 'alerta') { $alertas += $r['n']; } }
?>

<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-triangle-exclamation" style="margin-right:6px;color:var(--warn);"></i>
            Historial de incidencias
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;">
                <?= number_format($total) ?>
            </span>
        </div>
        <a href="<?= $baseUrl ?>/facturas-erp" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Volver al listado
        </a>
    </div>

    <p style="font-size:11.5px;color:var(--text-muted);margin:0 0 12px;">
        Cada carga vuelve a revisar el archivo completo, así que una factura problemática deja
        una incidencia por carga. Las de tipo <strong>alerta</strong> piden que alguien las mire;
        las de <strong>aviso</strong> solo dejan constancia. Lo que descartes deja de verse
        <strong>también en las cargas siguientes</strong>.
    </p>

    <!-- Vigentes / descartadas -->
    <?php
    $sinVer = $query; unset($sinVer['ver']);
    $urlVigentes = $baseUrl . '/facturas-erp/incidencias' . ($sinVer ? '?' . http_build_query($sinVer) : '');
    $urlDescartadas = $baseUrl . '/facturas-erp/incidencias?' . http_build_query(array_merge($sinVer, ['ver' => 'descartadas']));
    ?>
    <div style="display:flex;gap:3px;margin-bottom:9px;border-bottom:1px solid var(--border,#e5e7eb);">
        <a href="<?= htmlspecialchars($urlVigentes) ?>"
           style="padding:5px 10px;font-size:12px;text-decoration:none;border-bottom:2px solid <?= $viendoDescartadas ? 'transparent' : 'var(--navy)' ?>;color:<?= $viendoDescartadas ? 'var(--text-muted)' : 'var(--navy)' ?>;font-weight:<?= $viendoDescartadas ? '400' : '700' ?>;">
            Vigentes
        </a>
        <a href="<?= htmlspecialchars($urlDescartadas) ?>"
           style="padding:5px 10px;font-size:12px;text-decoration:none;border-bottom:2px solid <?= $viendoDescartadas ? 'var(--navy)' : 'transparent' ?>;color:<?= $viendoDescartadas ? 'var(--navy)' : 'var(--text-muted)' ?>;font-weight:<?= $viendoDescartadas ? '700' : '400' ?>;">
            Descartadas<?= $totalDescartadas > 0 ? ' (' . number_format($totalDescartadas) . ')' : '' ?>
        </a>
    </div>

    <!-- Filtro rápido por tipo -->
    <?php if ($resumenTipos): ?>
    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:9px;">
        <?php
        $sinTipo = $query; unset($sinTipo['tipo']);
        $urlTodos = $baseUrl . '/facturas-erp/incidencias' . ($sinTipo ? '?' . http_build_query($sinTipo) : '');
        ?>
        <a href="<?= htmlspecialchars($urlTodos) ?>"
           class="badge <?= $filtros['tipo'] === '' ? 'badge-navy' : 'badge-default' ?>"
           style="text-decoration:none;font-size:11px;padding:4px 10px;">
            Todas (<?= number_format(array_sum(array_column($resumenTipos, 'n'))) ?>)
        </a>
        <?php foreach ($resumenTipos as $tipo => $info):
            $u = $baseUrl . '/facturas-erp/incidencias?' . http_build_query(array_merge($sinTipo, ['tipo' => $tipo]));
            $activo = $filtros['tipo'] === $tipo;
            $clase = $info['severidad'] === 'alerta' ? 'badge-diff' : 'badge-default';
        ?>
        <a href="<?= htmlspecialchars($u) ?>"
           class="badge <?= $activo ? 'badge-navy' : $clase ?>"
           style="text-decoration:none;font-size:11px;padding:4px 10px;"
           title="<?= htmlspecialchars($etiqueta($tipo)) ?>">
            <?php if ($info['severidad'] === 'alerta'): ?><i class="fas fa-circle-exclamation" style="margin-right:4px;"></i><?php endif; ?>
            <?= htmlspecialchars($etiqueta($tipo)) ?> (<?= number_format($info['n']) ?>)
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="GET" action="<?= $baseUrl ?>/facturas-erp/incidencias" class="filter-bar">
        <?php
        // El proveedor primero. El desplegable "Tipo" salió: las pastillas de
        // arriba hacen lo mismo con un clic y además dicen cuántas hay de
        // cada tipo, que es la mitad de la respuesta.
        $provFiltro = [
            'valor'    => $filtros['proveedor'],
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Proveedor, documento o detalle"
                   value="<?= htmlspecialchars($filtros['texto']) ?>">
        </div>
        <div>
            <label class="filter-label">Severidad</label>
            <select name="severidad" class="form-control">
                <option value="">Todas</option>
                <option value="alerta" <?= $filtros['severidad'] === 'alerta' ? 'selected' : '' ?>>Alerta</option>
                <option value="aviso" <?= $filtros['severidad'] === 'aviso' ? 'selected' : '' ?>>Aviso</option>
            </select>
        </div>
        <div class="filter-span-2">
            <label class="filter-label">Carga</label>
            <select name="carga" class="form-control">
                <option value="">Todas las cargas</option>
                <?php foreach ($cargas as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $filtros['carga_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                    #<?= (int) $c['id'] ?> · <?= date('d/m/Y H:i', strtotime((string) $c['creado_en'])) ?>
                    · <?= htmlspecialchars(mb_substr((string) $c['archivo_origen'], 0, 24)) ?>
                    (<?= number_format((int) $c['incidencias']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-filter" style="margin-right:4px;"></i>Filtrar
            </button>
            <a href="<?= $baseUrl ?>/facturas-erp/incidencias?limpiar=1" class="btn btn-outline btn-sm">Limpiar</a>
        </div>
    </form>

    <form method="POST" id="fe-inc-form"
          action="<?= $baseUrl ?><?= $viendoDescartadas ? '/facturas-erp/incidencias/restaurar' : '/facturas-erp/incidencias/descartar' ?><?= htmlspecialchars($queryStr) ?>">

    <?php if ($incidencias): ?>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;margin-bottom:10px;
                background:#f8fafd;border:1px solid var(--border,#e5e7eb);border-radius:6px;">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;white-space:nowrap;">
            <input type="checkbox" id="fe-inc-todos" onchange="feIncMarcarTodos(this)">
            Seleccionar todo
        </label>
        <span id="fe-inc-conteo" class="muted" style="font-size:11.5px;">0 seleccionadas</span>

        <?php if ($viendoDescartadas): ?>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">
                <i class="fas fa-rotate-left" style="margin-right:4px;"></i>Restaurar seleccionadas
            </button>
            <span style="font-size:11px;color:var(--text-muted);">
                Vuelven a mostrarse y se levanta el descarte permanente.
            </span>
        <?php else: ?>
            <input type="text" name="motivo" class="form-control" placeholder="Motivo (opcional)"
                   maxlength="255" style="max-width:260px;font-size:12px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer;white-space:nowrap;"
                   title="Marcado, la incidencia vuelve a aparecer en la próxima carga">
                <input type="checkbox" name="solo_esta_vez" value="1">
                Solo esta vez
            </label>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">
                <i class="fas fa-eye-slash" style="margin-right:4px;"></i>Descartar seleccionadas
            </button>
            <?php if ($total > count($incidencias)): ?>
            <button type="submit" name="todas_del_filtro" value="1" class="btn btn-outline btn-sm"
                    data-confirm="Se descartarán las <?= number_format($total) ?> incidencias que cumplen el filtro actual."
                    data-confirm-title="Descartar incidencias filtradas"
                    data-confirm-type="warning"
                    data-confirm-accept="Descartar todas">
                Descartar las <?= number_format($total) ?> del filtro
            </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:12.5px;min-width:1140px;">
            <thead>
                <tr>
                    <th style="width:28px;"></th>
                    <th>Tipo</th>
                    <th>Proveedor</th>
                    <th>Documento</th>
                    <th>Emitida</th>
                    <th class="right">Monto</th>
                    <th class="right">Saldo</th>
                    <th style="min-width:300px;">Detalle</th>
                    <th>Carga</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$incidencias): ?>
                <tr>
                    <td colspan="9" class="muted" style="text-align:center;padding:18px;">
                        <?php if ($viendoDescartadas): ?>
                            No hay incidencias descartadas.
                        <?php elseif ($total === 0 && !$query): ?>
                            Todavía no hay incidencias registradas. Aparecen al cargar un listado.
                        <?php else: ?>
                            Ninguna incidencia vigente coincide con el filtro.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($incidencias as $i):
                    $esAlerta = $i['severidad'] === 'alerta';
                ?>
                <tr>
                    <td>
                        <input type="checkbox" name="ids[]" value="<?= (int) $i['id'] ?>"
                               class="fe-inc-check" onchange="feIncContar()">
                    </td>
                    <td>
                        <span class="badge <?= $esAlerta ? 'badge-diff' : 'badge-default' ?>"
                              style="font-size:10px;padding:2px 7px;white-space:nowrap;">
                            <?php if ($esAlerta): ?><i class="fas fa-circle-exclamation" style="margin-right:3px;"></i><?php endif; ?>
                            <?= htmlspecialchars($etiqueta($i['tipo'])) ?>
                        </span>
                    </td>
                    <td>
                        <?= htmlspecialchars((string) $i['proveedor_nombre']) ?: '<span class="muted">—</span>' ?>
                        <?php if ($i['proveedor_codigo'] !== ''): ?>
                        <div class="muted" style="font-size:10.5px;"><?= htmlspecialchars($i['proveedor_codigo']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:ui-monospace,monospace;font-size:11px;">
                        <?= $i['documento'] !== '' ? htmlspecialchars($i['documento']) : '<span class="muted">sin número</span>' ?>
                    </td>
                    <td><?= ieFecha($i['fecha_emision']) ?></td>
                    <td class="right"><?= $i['monto'] !== null ? number_format((float) $i['monto'], 2) : '—' ?></td>
                    <td class="right">
                        <?php if ($i['saldo_anterior'] !== null && $i['saldo_nuevo'] !== null): ?>
                            <span class="muted"><?= number_format((float) $i['saldo_anterior'], 2) ?></span>
                            <i class="fas fa-arrow-right" style="font-size:9px;margin:0 3px;color:var(--text-muted);"></i>
                            <strong><?= number_format((float) $i['saldo_nuevo'], 2) ?></strong>
                        <?php elseif ($i['saldo_nuevo'] !== null): ?>
                            <?= number_format((float) $i['saldo_nuevo'], 2) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11.5px;">
                        <?= htmlspecialchars((string) $i['detalle']) ?>
                        <?php if (!empty($i['descartada']) && !empty($i['motivo'])): ?>
                        <div class="muted" style="font-size:10.5px;margin-top:2px;">
                            <i class="fas fa-eye-slash" style="margin-right:3px;"></i>
                            Motivo: <?= htmlspecialchars((string) $i['motivo']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="muted" style="font-size:11px;white-space:nowrap;">
                        #<?= (int) $i['carga_id'] ?>
                        <div><?= $i['carga_creada'] ? date('d/m/Y H:i', strtotime((string) $i['carga_creada'])) : '' ?></div>
                        <?php if (!empty($i['descartada_en'])): ?>
                        <div style="color:var(--text-muted);">
                            descartada <?= date('d/m/Y', strtotime((string) $i['descartada_en'])) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </form>

    <?php if ($totalPaginas > 1): ?>
    <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;font-size:12px;">
        <?php $qs = function ($p) use ($baseUrl, $query) {
            return $baseUrl . '/facturas-erp/incidencias?' . http_build_query(array_merge($query, ['pagina' => $p]));
        }; ?>
        <?php if ($pagina > 1): ?>
            <a href="<?= htmlspecialchars($qs($pagina - 1)) ?>" class="btn btn-outline btn-sm">Anterior</a>
        <?php endif; ?>
        <span class="muted">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
        <?php if ($pagina < $totalPaginas): ?>
            <a href="<?= htmlspecialchars($qs($pagina + 1)) ?>" class="btn btn-outline btn-sm">Siguiente</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function feIncMarcarTodos(origen) {
    var checks = document.querySelectorAll('.fe-inc-check');
    for (var i = 0; i < checks.length; i++) { checks[i].checked = origen.checked; }
    feIncContar();
}
function feIncContar() {
    var n = document.querySelectorAll('.fe-inc-check:checked').length;
    var el = document.getElementById('fe-inc-conteo');
    if (el) { el.textContent = n + (n === 1 ? ' seleccionada' : ' seleccionadas'); }
}
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('fe-inc-form');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
        // "Descartar las N del filtro" no necesita selección: las resuelve el servidor.
        if (ev.submitter && ev.submitter.name === 'todas_del_filtro') return;
        if (document.querySelectorAll('.fe-inc-check:checked').length === 0) {
            ev.preventDefault();
            AppDialog.alert('Selecciona al menos una incidencia para continuar.', {
                title: 'No hay incidencias seleccionadas', type: 'warning'
            });
        }
    });
});
</script>
