<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$devoluciones = $devoluciones ?? [];
$resumen = $resumen ?? ['pendiente' => 0, 'sin_nc' => 0, 'parcial' => 0, 'verificada' => 0, 'total' => 0];
$filtros = $filtros ?? ['tipo' => '', 'estado' => '', 'q' => ''];

$badgeEstado = function ($estado) {
    switch ($estado) {
        case 'verificada': return '<span class="badge badge-green">Verificada</span>';
        case 'parcial': return '<span class="badge" style="background:#fef3c7;color:#92400e;">Parcial</span>';
        case 'sin_nc': return '<span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC</span>';
        default: return '<span class="badge badge-navy">Pendiente</span>';
    }
};
$labelTipo = function ($tipo) {
    return $tipo === 'boleta_local' ? 'Boleta (Ventas)' : 'Devolución (Cambios)';
};
?>
<div class="card" style="margin-bottom:14px;">
    <div class="card-header"><div class="card-title"><i class="fas fa-rotate-left" style="color:var(--gold);margin-right:6px;"></i>Devoluciones a proveedor</div></div>
    <form method="post" action="<?= $baseUrl ?>/devoluciones/subir" enctype="multipart/form-data" style="padding:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="file" name="pdf_files[]" accept=".pdf" multiple required class="form-control" style="max-width:520px;">
        <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Importar reportes PDF</button>
        <span style="font-size:12px;color:var(--text-muted);">Acepta "Facturas Boleta Local" y "Reporte de Devolución a Proveedor". Cada PDF se cuadra contra sus totales; si no cuadra, se rechaza.</span>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            Reportes importados <span class="badge badge-navy"><?= (int) $resumen['total'] ?></span>
        </div>
        <form method="post" action="<?= $baseUrl ?>/devoluciones/verificar" style="margin-left:auto;">
            <button class="btn btn-outline btn-sm" type="submit" title="Re-verificar todas las no verificadas">
                <i class="fas fa-rotate"></i> Verificar pendientes
            </button>
        </form>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;padding:12px 14px 0;">
        <a href="<?= $baseUrl ?>/devoluciones" style="text-decoration:none;"><span class="badge badge-navy">Todas: <?= (int) $resumen['total'] ?></span></a>
        <a href="<?= $baseUrl ?>/devoluciones?estado=verificada" style="text-decoration:none;"><span class="badge badge-green">Verificadas: <?= (int) $resumen['verificada'] ?></span></a>
        <a href="<?= $baseUrl ?>/devoluciones?estado=parcial" style="text-decoration:none;"><span class="badge" style="background:#fef3c7;color:#92400e;">Parciales: <?= (int) $resumen['parcial'] ?></span></a>
        <a href="<?= $baseUrl ?>/devoluciones?estado=sin_nc" style="text-decoration:none;"><span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC: <?= (int) $resumen['sin_nc'] ?></span></a>
        <a href="<?= $baseUrl ?>/devoluciones?estado=pendiente" style="text-decoration:none;"><span class="badge" style="background:#e2e8f0;color:#334155;">Pendientes: <?= (int) $resumen['pendiente'] ?></span></a>
    </div>

    <form method="get" action="<?= $baseUrl ?>/devoluciones" class="filter-bar">
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q']) ?>" placeholder="Número, factura o proveedor">
        </div>
        <div>
            <label class="filter-label">Tipo</label>
            <select class="form-control" name="tipo">
                <option value="">Todos</option>
                <option value="boleta_local" <?= $filtros['tipo'] === 'boleta_local' ? 'selected' : '' ?>>Boleta (Ventas)</option>
                <option value="devolucion_proveedor" <?= $filtros['tipo'] === 'devolucion_proveedor' ? 'selected' : '' ?>>Devolución (Cambios)</option>
            </select>
        </div>
        <div>
            <label class="filter-label">Estado</label>
            <select class="form-control" name="estado">
                <option value="">Todos</option>
                <?php foreach (['verificada' => 'Verificada', 'parcial' => 'Parcial', 'sin_nc' => 'Sin NC', 'pendiente' => 'Pendiente'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $filtros['estado'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i> Buscar</button>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones"><i class="fas fa-broom"></i> Limpiar</a>
        </div>
    </form>

    <div style="overflow-x:auto;margin-top:12px;"><table class="data-table">
        <thead><tr>
            <th>Fecha</th><th>Tipo</th><th>N°</th><th>Proveedor</th><th>Factura (boleta)</th>
            <th class="right">NC esperadas</th><th>Vínculos</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($devoluciones)): ?>
        <tr><td colspan="9" style="text-align:center;padding:28px;color:var(--text-muted);">No hay reportes importados con estos filtros.</td></tr>
        <?php endif; ?>
        <?php foreach ($devoluciones as $d): ?>
        <?php
            $montos = [];
            if ((float) $d['nc_esperada_cantidad'] > 0) { $montos[] = 'Cant: ' . number_format((float) $d['nc_esperada_cantidad'], 2); }
            if ((float) $d['nc_esperada_costo'] > 0) { $montos[] = 'Costo: ' . number_format((float) $d['nc_esperada_costo'], 2); }
            if ((float) $d['total'] > 0) { $montos[] = number_format((float) $d['total'], 2); }
        ?>
        <tr>
            <td style="white-space:nowrap;"><?= htmlspecialchars((string) $d['fecha']) ?></td>
            <td><?= $labelTipo((string) $d['tipo']) ?></td>
            <td><strong><?= htmlspecialchars((string) $d['numero']) ?></strong></td>
            <td><?= htmlspecialchars((string) ($d['proveedor_local'] ?: $d['proveedor_nombre_erp'] ?: '—')) ?></td>
            <td style="font-family:monospace;white-space:nowrap;"><?= htmlspecialchars((string) ($d['numero_factura'] ?: '—')) ?></td>
            <td class="right" style="white-space:nowrap;"><?= $montos ? implode('<br>', $montos) : '—' ?></td>
            <td style="white-space:nowrap;">
                <?php if ((int) $d['matches_confirmados'] > 0): ?><span class="badge badge-green"><?= (int) $d['matches_confirmados'] ?> ✓</span><?php endif; ?>
                <?php if ((int) $d['matches_sugeridos'] > 0): ?><span class="badge" style="background:#fef3c7;color:#92400e;"><?= (int) $d['matches_sugeridos'] ?> ?</span><?php endif; ?>
            </td>
            <td><?= $badgeEstado((string) $d['estado']) ?></td>
            <td style="white-space:nowrap;">
                <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones/detalle/<?= (int) $d['id'] ?>" title="Detalle"><i class="fas fa-eye"></i></a>
                <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones/pdf/<?= (int) $d['id'] ?>" target="_blank" title="Ver PDF original"><i class="fas fa-file-pdf"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
