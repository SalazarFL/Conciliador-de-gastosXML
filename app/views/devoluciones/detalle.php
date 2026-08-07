<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$dev = $dev ?? [];
$lineas = $lineas ?? [];
$matches = $matches ?? [];
$objetivos = $objetivos ?? [];
$candidatas = $candidatas ?? [];

$esBoleta = ($dev['tipo'] ?? '') === 'boleta_local';
$labelObjetivo = [
    'cantidad' => 'NC por faltante (cantidad)',
    'costo' => 'NC por diferencia de costo',
    'total' => 'NC por devolución',
];
$badgeEstado = function ($estado) {
    switch ($estado) {
        case 'verificada': return '<span class="badge badge-green">Verificada</span>';
        case 'parcial': return '<span class="badge" style="background:#fef3c7;color:#92400e;">Parcial</span>';
        case 'sin_nc': return '<span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC</span>';
        default: return '<span class="badge badge-navy">Pendiente</span>';
    }
};

$matchesPorObjetivo = [];
foreach ($matches as $m) {
    $matchesPorObjetivo[(string) $m['objetivo']][] = $m;
}
$advertencias = [];
if (!empty($dev['advertencias'])) {
    $advertencias = json_decode((string) $dev['advertencias'], true) ?: [];
}
?>
<div style="margin-bottom:12px;">
    <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones"><i class="fas fa-arrow-left"></i> Volver al listado</a>
</div>

<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-rotate-left" style="color:var(--gold);margin-right:6px;"></i>
            <?= $esBoleta ? 'Boleta Local' : 'Devolución a Proveedor' ?> #<?= htmlspecialchars((string) $dev['numero']) ?>
            <?= $badgeEstado((string) $dev['estado']) ?>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/devoluciones/pdf/<?= (int) $dev['id'] ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF original</a>
            <form method="post" action="<?= $baseUrl ?>/devoluciones/verificar/<?= (int) $dev['id'] ?>">
                <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-rotate"></i> Re-verificar</button>
            </form>
            <form method="post" action="<?= $baseUrl ?>/devoluciones/eliminar/<?= (int) $dev['id'] ?>"
                  onsubmit="return confirm('¿Eliminar esta devolución y sus vínculos?');">
                <button class="btn btn-outline btn-sm" type="submit" style="color:#991b1b;"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
    <div style="padding:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;font-size:13px;">
        <div><strong>Proveedor:</strong><br><?= htmlspecialchars((string) ($dev['proveedor_local'] ?: $dev['proveedor_nombre_erp'] ?: '—')) ?>
            <?php if (empty($dev['proveedor_id'])): ?><br><span class="badge" style="background:#fee2e2;color:#991b1b;">Sin equivalente local</span><?php endif; ?>
        </div>
        <div><strong>Fecha:</strong><br><?= htmlspecialchars((string) $dev['fecha']) ?></div>
        <div><strong>Sucursal / Bodega:</strong><br><?= htmlspecialchars(trim((string) $dev['sucursal'] . ' · ' . (string) $dev['bodega'], ' ·')) ?></div>
        <?php if ($esBoleta): ?>
        <div><strong>Factura del proveedor:</strong><br>
            <span style="font-family:monospace;"><?= htmlspecialchars((string) $dev['numero_factura']) ?></span><br>
            <?php if (!empty($dev['factura_xml_id'])): ?>
                <span class="badge badge-green">En base</span>
                <a href="<?= $baseUrl ?>/facturas/ver/<?= (int) $dev['factura_xml_id'] ?>" style="font-size:12px;">ver factura</a>
            <?php else: ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b;">No importada</span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div><strong>Estado ERP / Usuario:</strong><br><?= htmlspecialchars(trim((string) $dev['estado_erp'] . ' · ' . (string) $dev['usuario_erp'], ' ·')) ?></div>
        <?php if (!empty($dev['observaciones'])): ?><div><strong>Observaciones:</strong><br><?= htmlspecialchars((string) $dev['observaciones']) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
    <?php if ($advertencias): ?>
    <div style="margin:0 14px 14px;padding:9px 12px;background:#fff7ed;border:1px solid #fdba74;border-radius:7px;color:#9a3412;font-size:12px;">
        <strong>Advertencias del parser:</strong>
        <ul style="margin:4px 0 0 18px;"><?php foreach ($advertencias as $a): ?><li><?= htmlspecialchars((string) $a) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($objetivos as $objetivo => $monto): ?>
<?php
    $filas = $matchesPorObjetivo[$objetivo] ?? [];
    $confirmado = null;
    foreach ($filas as $f) {
        if ($f['estado'] === 'confirmado') { $confirmado = $f; break; }
    }
?>
<div class="card" style="margin-bottom:14px;">
    <div class="card-header">
        <div class="card-title">
            <?= htmlspecialchars($labelObjetivo[$objetivo] ?? $objetivo) ?> ·
            <strong>₡<?= number_format((float) $monto, 2) ?></strong>
            <?php if ($confirmado): ?><span class="badge badge-green">Confirmado</span>
            <?php elseif ($filas && $filas[0]['estado'] === 'sugerido'): ?><span class="badge" style="background:#fef3c7;color:#92400e;">Con sugerencias</span>
            <?php else: ?><span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC</span><?php endif; ?>
        </div>
        <?php if ($confirmado): ?>
        <form method="post" action="<?= $baseUrl ?>/devoluciones/desvincular" style="margin-left:auto;"
              onsubmit="return confirm('¿Quitar el vínculo y re-verificar?');">
            <input type="hidden" name="devolucion_id" value="<?= (int) $dev['id'] ?>">
            <input type="hidden" name="objetivo" value="<?= htmlspecialchars($objetivo) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-link-slash"></i> Desvincular</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($filas): ?>
    <div style="overflow-x:auto;"><table class="data-table">
        <thead><tr><th>Estado</th><th>NC</th><th>Fecha NC</th><th class="right">Total NC</th><th class="right">Diferencia</th><th>Método</th><th>Motivo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($filas as $m): ?>
        <tr>
            <td>
                <?php if ($m['estado'] === 'confirmado'): ?><span class="badge badge-green">Confirmado</span>
                <?php elseif ($m['estado'] === 'sugerido'): ?><span class="badge" style="background:#fef3c7;color:#92400e;">Sugerido<?= !empty($m['nc_consolidada']) ? ' · consolidada' : '' ?></span>
                <?php elseif ($m['estado'] === 'descartado'): ?><span class="badge" style="background:#e2e8f0;color:#334155;">Descartado</span>
                <?php else: ?><span class="badge" style="background:#fee2e2;color:#991b1b;">Sin NC</span><?php endif; ?>
            </td>
            <td style="font-family:monospace;white-space:nowrap;">
                <?php if (!empty($m['factura_xml_id'])): ?>
                <a href="<?= $baseUrl ?>/notas-xml/ver/<?= (int) $m['factura_xml_id'] ?>"><?= htmlspecialchars((string) $m['nc_consecutivo']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="white-space:nowrap;"><?= htmlspecialchars((string) ($m['nc_fecha'] ?? '—')) ?></td>
            <td class="right"><?= $m['monto_nc'] !== null ? number_format((float) $m['monto_nc'], 2) : '—' ?></td>
            <td class="right"><?= $m['diferencia'] !== null ? number_format((float) $m['diferencia'], 2) : '—' ?></td>
            <td><?= htmlspecialchars((string) $m['metodo']) ?></td>
            <td style="font-size:12px;color:var(--text-muted);max-width:340px;"><?= htmlspecialchars((string) $m['motivo']) ?></td>
            <td style="white-space:nowrap;">
                <?php if ($m['estado'] === 'sugerido'): ?>
                <form method="post" action="<?= $baseUrl ?>/devoluciones/confirmar" style="display:inline;">
                    <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn-primary btn-sm" type="submit" title="Confirmar este vínculo"><i class="fas fa-check"></i></button>
                </form>
                <form method="post" action="<?= $baseUrl ?>/devoluciones/descartar" style="display:inline;">
                    <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit" title="Descartar sugerencia"><i class="fas fa-xmark"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

    <?php if (!$confirmado && !empty($candidatas[$objetivo])): ?>
    <details style="margin:10px 14px 14px;">
        <summary style="cursor:pointer;font-size:13px;color:var(--navy-light);">
            <i class="fas fa-link"></i> Vincular otra NC manualmente (<?= count($candidatas[$objetivo]) ?> candidatas por cercanía de monto)
        </summary>
        <div style="overflow-x:auto;margin-top:8px;"><table class="data-table">
            <thead><tr><th>NC</th><th>Proveedor</th><th>Fecha</th><th class="right">Total</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($candidatas[$objetivo] as $c): ?>
            <tr>
                <td style="font-family:monospace;white-space:nowrap;"><?= htmlspecialchars((string) $c['consecutivo_completo']) ?></td>
                <td><?= htmlspecialchars((string) ($c['proveedor'] ?? '—')) ?></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars((string) $c['fecha_emision']) ?></td>
                <td class="right"><?= number_format((float) $c['total'], 2) ?></td>
                <td>
                    <form method="post" action="<?= $baseUrl ?>/devoluciones/vincular">
                        <input type="hidden" name="devolucion_id" value="<?= (int) $dev['id'] ?>">
                        <input type="hidden" name="objetivo" value="<?= htmlspecialchars($objetivo) ?>">
                        <input type="hidden" name="factura_xml_id" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-link"></i> Vincular</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-header"><div class="card-title">Líneas del reporte <span class="badge badge-navy"><?= count($lineas) ?></span></div></div>
    <div style="overflow-x:auto;"><table class="data-table">
        <thead><tr>
            <?php if ($esBoleta): ?><th>Sección</th><?php endif; ?>
            <th>Código</th><th>Artículo</th><th class="right">Cantidad</th><th class="right">Costo</th>
            <th class="right">Impuesto</th><th class="right">Total</th><?php if ($esBoleta): ?><th class="right">Dif. costo</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($lineas as $l): ?>
        <tr>
            <?php if ($esBoleta): ?><td><span class="badge badge-navy" style="font-size:10px;"><?= htmlspecialchars((string) $l['seccion']) ?></span></td><?php endif; ?>
            <td style="font-family:monospace;"><?= htmlspecialchars((string) $l['codigo']) ?></td>
            <td><?= htmlspecialchars((string) $l['nombre']) ?></td>
            <td class="right"><?= number_format((float) $l['cantidad'], 3) ?></td>
            <td class="right"><?= number_format((float) $l['costo'], 2) ?></td>
            <td class="right"><?= number_format((float) $l['impuesto'], 2) ?></td>
            <td class="right"><strong><?= number_format((float) $l['total'], 2) ?></strong></td>
            <?php if ($esBoleta): ?><td class="right"><?= (float) $l['dif_costo'] > 0 ? number_format((float) $l['dif_costo'], 2) : '—' ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
