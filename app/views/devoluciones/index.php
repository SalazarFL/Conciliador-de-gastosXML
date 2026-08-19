<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$devoluciones = $devoluciones ?? [];
$resumen = $resumen ?? ['pendiente' => 0, 'sin_nc' => 0, 'parcial' => 0, 'verificada' => 0, 'total' => 0];
$filtros = array_replace(
    ['tipo' => '', 'estado' => '', 'proveedor' => '', 'q' => ''],
    is_array($filtros ?? null) ? $filtros : []
);
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];

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
<div class="card" style="margin-bottom:10px;">
    <div class="card-header"><div class="card-title"><i class="fas fa-rotate-left" style="color:var(--gold);margin-right:6px;"></i>Devoluciones a proveedor</div></div>
    <form method="post" action="<?= $baseUrl ?>/devoluciones/subir" enctype="multipart/form-data" style="padding:0;display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
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

    <?php
    /*
     * Las insignias son el filtro de estado, y conservan lo demás: elegir un
     * proveedor y luego pulsar "Sin NC" tiene que dejar las dos cosas puestas,
     * no empezar de cero.
     */
    $urlEstado = function ($estado) use ($baseUrl, $filtros) {
        $q = array_filter([
            'q' => $filtros['q'], 'tipo' => $filtros['tipo'],
            'proveedor' => $filtros['proveedor'], 'estado' => $estado,
        ], 'strlen');
        return $baseUrl . '/devoluciones' . ($q ? '?' . http_build_query($q) : '');
    };
    $insignias = [
        ''           => ['Todas',       'badge badge-navy',  '', (int) $resumen['total']],
        'verificada' => ['Verificadas', 'badge badge-green', '', (int) $resumen['verificada']],
        'parcial'    => ['Parciales',   'badge', 'background:#fef3c7;color:#92400e;', (int) $resumen['parcial']],
        'sin_nc'     => ['Sin NC',      'badge', 'background:#fee2e2;color:#991b1b;', (int) $resumen['sin_nc']],
        'pendiente'  => ['Pendientes',  'badge', 'background:#e2e8f0;color:#334155;', (int) $resumen['pendiente']],
    ];
    ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;padding:7px 0 0;">
        <?php foreach ($insignias as $valor => [$etiqueta, $clase, $estilo, $cuantas]): ?>
        <a href="<?= htmlspecialchars($urlEstado($valor)) ?>" style="text-decoration:none;">
            <span class="<?= $clase ?>" style="<?= $estilo ?><?= $filtros['estado'] === $valor ? 'outline:2px solid var(--navy);outline-offset:1px;' : '' ?>">
                <?= $etiqueta ?>: <?= number_format($cuantas) ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= $baseUrl ?>/devoluciones" class="filter-bar">
        <?php
        // Proveedor, número y tipo de documento. El desplegable "Estado" salió:
        // las insignias de arriba filtran lo mismo con un clic y ya dicen
        // cuántas hay en cada estado. El estado elegido viaja en la URL, así
        // que las insignias y este formulario no se pisan.
        $provFiltro = [
            'valor'    => $filtros['proveedor'] ?? '',
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($filtros['q']) ?>" placeholder="Número, factura o proveedor">
        </div>
        <div>
            <label class="filter-label">Tipo de documento</label>
            <select class="form-control" name="tipo">
                <option value="">Todos</option>
                <option value="boleta_local" <?= $filtros['tipo'] === 'boleta_local' ? 'selected' : '' ?>>Boleta (Ventas)</option>
                <option value="devolucion_proveedor" <?= $filtros['tipo'] === 'devolucion_proveedor' ? 'selected' : '' ?>>Devolución (Cambios)</option>
            </select>
        </div>
        <input type="hidden" name="estado" value="<?= htmlspecialchars($filtros['estado']) ?>">
        <div class="filter-actions">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i> Buscar</button>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones"><i class="fas fa-broom"></i> Limpiar</a>
        </div>
    </form>

    <div style="overflow-x:auto;margin-top:8px;"><table class="data-table">
        <thead><tr>
            <th>Fecha</th><th>Tipo</th><th>N°</th><th>Proveedor</th><th>Factura (boleta)</th>
            <th class="right">NC esperadas</th><th>Vínculos</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($devoluciones)): ?>
        <tr><td colspan="9" style="text-align:center;padding:18px;color:var(--text-muted);">No hay reportes importados con estos filtros.</td></tr>
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
