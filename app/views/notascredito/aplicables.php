<?php
/**
 * Las notas de crédito que se le pueden aplicar a una factura.
 *
 * Se abre en una ventana desde el botón "Notas aplicables" de Seguimiento y
 * de Pagos (partials/notas-aplicables.php). Qué notas cuentan lo decide
 * NotaCredito::notasAplicablesDeFacturas; aquí solo se enseñan, con su
 * respaldo, que es lo que hay que tener antes de aplicar una.
 */
require_once __DIR__ . '/../../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../../helpers/AplicacionNotaCredito.php';

$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$tituloPagina = 'Notas aplicables';
$notas = is_array($notas ?? null) ? $notas : [];
$importe = static function ($valor) { return '₡' . number_format((float) $valor, 2); };

$totalSaldo = 0.0;
$hayCambio = false;
foreach ($notas as $n) {
    $totalSaldo += (float) $n['saldo'];
    $hayCambio = $hayCambio || in_array($n['clase'], AplicacionNotaCredito::CLASES_POR_PROVEEDOR, true);
}
?>
<style>
.na-ficha { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; padding:12px 14px; font-size:12.5px; }
.na-ficha-rotulo { font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; }
.na-ficha-valor { color:var(--navy); font-weight:600; word-break:break-word; }
.na-regla { margin:0 14px 12px; padding:8px 10px; font-size:11.5px; color:var(--text-muted);
            background:#f8fafc; border:1px solid var(--border); border-radius:6px; line-height:1.5; }
.na-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:5px;
           font-size:11px; font-weight:600; text-decoration:none; white-space:nowrap; }
.na-chip-ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.na-chip-no { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
.na-clase { font-size:11px; color:var(--text-muted); }
</style>

<?php if (!$factura): ?>
<div class="card">
    <div style="padding:22px;text-align:center;color:var(--text-muted);font-size:13px;">
        <i class="fas fa-circle-info" style="font-size:20px;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
        Esta factura no es de la empresa activa, o ya no está en el listado del sistema.
    </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:12px;">
    <div class="card-header"><div class="card-title">Factura <?= htmlspecialchars((string) $factura['documento']) ?></div></div>
    <div class="na-ficha">
        <div><div class="na-ficha-rotulo">Proveedor</div><div class="na-ficha-valor"><?= htmlspecialchars((string) $factura['proveedor_nombre']) ?></div></div>
        <?php if (trim((string) ($factura['sucursal'] ?? '')) !== ''): ?>
        <div><div class="na-ficha-rotulo">Sucursal</div><div class="na-ficha-valor"><?= htmlspecialchars((string) $factura['sucursal']) ?></div></div>
        <?php endif; ?>
        <div><div class="na-ficha-rotulo">Monto</div><div class="na-ficha-valor"><?= $importe($factura['monto']) ?></div></div>
        <div><div class="na-ficha-rotulo">Saldo</div><div class="na-ficha-valor"><?= $importe($factura['saldo']) ?></div></div>
        <div><div class="na-ficha-rotulo">Notas aplicables</div><div class="na-ficha-valor"><?= count($notas) ?> · <?= $importe($totalSaldo) ?></div></div>
    </div>
    <div class="na-regla">
        Cuentan las <strong>directas</strong> que corrigen esta factura, las de <strong>diferencia de costo</strong>
        que la nombran y a las que les quedan más de <?= $importe(AplicacionNotaCredito::MINIMO_COSTO) ?>,
        y las de <strong>cambio de mercadería</strong> de este proveedor, del monto que sean.
        <?php if ($hayCambio): ?>
        Una nota de cambio no nombra ninguna factura: sale en todas las de su proveedor que tienen saldo,
        así que no se suma entre facturas.
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <?php if (!$notas): ?>
    <div style="padding:22px;text-align:center;color:var(--text-muted);font-size:13px;">
        <i class="fas fa-check" style="font-size:20px;display:block;margin-bottom:8px;color:#cbd5e1;"></i>
        Esta factura no tiene notas de crédito aplicables.
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table" style="font-size:12.5px;">
            <thead>
                <tr>
                    <th>Nota</th>
                    <th>Clase</th>
                    <th>Fecha</th>
                    <th class="right">Monto</th>
                    <th class="right">Saldo</th>
                    <th class="center">Documento electrónico</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notas as $n): ?>
                <tr>
                    <td>
                        <strong style="color:var(--navy);"><?= htmlspecialchars((string) $n['documento']) ?></strong>
                        <?php if (trim((string) ($n['nc_proveedor'] ?? '')) !== ''): ?>
                        <div class="na-clase">NC del proveedor: <?= htmlspecialchars((string) $n['nc_proveedor']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="na-clase"><?= htmlspecialchars(ClaseNotaCredito::ETIQUETAS[$n['clase']] ?? (string) $n['clase']) ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars((string) $n['fecha']) ?></td>
                    <td class="right" style="white-space:nowrap;"><?= $importe($n['monto']) ?></td>
                    <td class="right" style="white-space:nowrap;"><strong><?= $importe($n['saldo']) ?></strong></td>
                    <td class="center" style="white-space:nowrap;">
                        <?php if (!empty($n['archivo_xml_ok'])): ?>
                        <a class="na-chip na-chip-ok" target="_blank"
                           href="<?= $baseUrl ?>/documentos/xml/<?= (int) $n['factura_xml_id'] ?>" title="Ver el XML de la nota">
                            <i class="fas fa-code"></i> XML
                        </a>
                        <?php else: ?>
                        <span class="na-chip na-chip-no" title="Todavía no hay XML de esta nota en el sistema">
                            <i class="fas fa-code"></i> Sin XML
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($n['archivo_pdf_ok'])): ?>
                        <a class="na-chip na-chip-ok" target="_blank"
                           href="<?= $baseUrl ?>/documentos/pdf/<?= (int) $n['factura_xml_id'] ?>" title="Ver el PDF de la nota">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <?php else: ?>
                        <span class="na-chip na-chip-no" title="Todavía no hay PDF de esta nota en el sistema">
                            <i class="fas fa-file-pdf"></i> Sin PDF
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
