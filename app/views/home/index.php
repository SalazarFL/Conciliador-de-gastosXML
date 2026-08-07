<?php
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats          = $stats ?? ['total_facturas' => 0, 'bandeja_pendientes' => 0];
$sociedades     = $sociedades ?? [];
$sociedadActiva = $sociedadActiva ?? null;
$ultimoListado  = $ultimoListado ?? null;
$resumenListado = $resumenListado ?? [];

$respaldadas   = (int) ($resumenListado['respaldada'] ?? 0);
$conDiferencia = (int) ($resumenListado['con_diferencia'] ?? 0);
$sinRespaldo   = (int) ($resumenListado['sin_respaldo'] ?? 0);
$totalLineas   = $respaldadas + $conDiferencia + $sinRespaldo;
?>

<!-- Estadísticas -->
<div class="stats-grid mb-20">
    <div class="stat-card navy">
        <div class="stat-label"><i class="fas fa-file-invoice" style="margin-right:5px;"></i>Facturas XML</div>
        <div class="stat-value"><?= number_format((int) $stats['total_facturas']) ?></div>
        <div class="stat-sub">Importadas en el sistema</div>
    </div>
    <div class="stat-card gold">
        <div class="stat-label"><i class="fas fa-envelope-open-text" style="margin-right:5px;"></i>Bandeja de correo</div>
        <div class="stat-value"><?= number_format((int) $stats['bandeja_pendientes']) ?></div>
        <div class="stat-sub">Facturas pendientes de importar</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-file-invoice-dollar" style="margin-right:5px;"></i>Último pago semanal</div>
        <?php if ($ultimoListado): ?>
        <div class="stat-value" style="color:<?= ($sinRespaldo + $conDiferencia) > 0 ? 'var(--warn)' : 'var(--ok)' ?>;">
            <?= $respaldadas ?>/<?= $totalLineas ?>
        </div>
        <div class="stat-sub">Respaldadas · <?= htmlspecialchars($ultimoListado['nombre']) ?></div>
        <?php else: ?>
        <div class="stat-value" style="color:var(--text-muted);">—</div>
        <div class="stat-sub">Aún no has subido un listado</div>
        <?php endif; ?>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-triangle-exclamation" style="margin-right:5px;"></i>Por resolver</div>
        <?php if ($ultimoListado): ?>
        <div class="stat-value" style="color:<?= ($sinRespaldo + $conDiferencia) > 0 ? 'var(--warn)' : 'var(--ok)' ?>;">
            <?= $sinRespaldo + $conDiferencia ?>
        </div>
        <div class="stat-sub"><?= $sinRespaldo ?> sin respaldo · <?= $conDiferencia ?> con diferencia</div>
        <?php else: ?>
        <div class="stat-value" style="color:var(--text-muted);">—</div>
        <div class="stat-sub">Sube el listado en Facturas por pagar</div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2 mb-20" style="align-items:start;">

    <!-- ── Sociedades ── -->
    <div class="card">
        <div class="card-header mb-12">
            <div class="card-title">
                <i class="fas fa-building" style="margin-right:6px;color:var(--navy-light);"></i>
                Sociedades
            </div>
        </div>

        <?php if (empty($sociedades)): ?>
        <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:12px;">
            Registra las sociedades del grupo. La <strong>activa</strong> es con la que trabajas:
            su cédula se compara contra el receptor de cada factura del correo.
        </div>
        <?php else: ?>
        <table class="data-table" style="font-size:12.5px;margin-bottom:12px;">
            <thead>
                <tr>
                    <th style="width:34px;" title="Sociedad activa"></th>
                    <th>Nombre</th>
                    <th>Cédula</th>
                    <th class="center" style="width:90px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sociedades as $soc): ?>
                <tr id="soc-fila-<?= (int) $soc['id'] ?>">
                    <td class="center">
                        <?php if (!empty($soc['activa'])): ?>
                        <i class="fas fa-circle-check" style="color:var(--ok);" title="Sociedad activa"></i>
                        <?php else: ?>
                        <form method="POST" action="<?= $baseUrl ?>/sociedades/activar/<?= (int) $soc['id'] ?>" style="display:inline;">
                            <button type="submit" title="Trabajar con esta sociedad"
                                    style="background:none;border:none;cursor:pointer;color:#cbd5e1;font-size:14px;padding:0;">
                                <i class="far fa-circle"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:<?= !empty($soc['activa']) ? '700' : '400' ?>;color:var(--navy);">
                        <span class="soc-ver"><?= htmlspecialchars($soc['nombre']) ?></span>
                    </td>
                    <td><span class="soc-ver"><?= htmlspecialchars($soc['cedula']) ?></span></td>
                    <td class="center" style="white-space:nowrap;">
                        <button type="button" class="btn btn-outline btn-sm" title="Editar"
                                onclick="socEditar(<?= (int) $soc['id'] ?>, this)"
                                data-nombre="<?= htmlspecialchars($soc['nombre']) ?>"
                                data-cedula="<?= htmlspecialchars($soc['cedula']) ?>">
                            <i class="fas fa-pen"></i>
                        </button>
                        <form method="POST" action="<?= $baseUrl ?>/sociedades/eliminar/<?= (int) $soc['id'] ?>" style="display:inline;"
                              onsubmit="return confirm('¿Eliminar la sociedad <?= htmlspecialchars($soc['nombre'], ENT_QUOTES) ?>?');">
                            <button type="submit" class="btn btn-outline btn-sm" title="Eliminar" style="color:#b91c1c;border-color:#fed7d7;">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Agregar / editar (el mismo form cambia de destino al editar) -->
        <form method="POST" action="<?= $baseUrl ?>/sociedades/crear" id="form-sociedad"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:2;min-width:180px;">
                <label style="font-size:11px;font-weight:700;color:var(--navy);display:block;margin-bottom:3px;">Nombre (informativo)</label>
                <input type="text" name="nombre" id="soc-nombre" class="form-control" style="font-size:12.5px;" required
                       placeholder="ARRENDADORA BM PZ S.A.">
            </div>
            <div style="flex:1;min-width:130px;">
                <label style="font-size:11px;font-weight:700;color:var(--navy);display:block;margin-bottom:3px;">Cédula</label>
                <input type="text" name="cedula" id="soc-cedula" class="form-control" style="font-size:12.5px;" required
                       placeholder="3101123456">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" id="soc-submit" style="margin-bottom:1px;">
                <i class="fas fa-plus"></i> Agregar
            </button>
            <button type="button" class="btn btn-outline btn-sm" id="soc-cancelar" style="display:none;margin-bottom:1px;"
                    onclick="socCancelarEdicion()">Cancelar</button>
        </form>
    </div>

    <!-- ── Accesos rápidos ── -->
    <div style="display:flex;flex-direction:column;gap:8px;">
        <?php
        $accesos = [
            ['/por-pagar', 'fa-file-invoice-dollar', 'Facturas por pagar', 'Verificar el listado del pago semanal', 'rgba(15,118,110,.09)', '#0f766e'],
            ['/correo', 'fa-envelope-open-text', 'Facturas desde Correo', 'Buscar e importar XML y PDF del buzón', 'rgba(240,165,0,.10)', 'var(--gold-dark)'],
            ['/facturas', 'fa-file-invoice', 'Carga de Facturas XML', 'Importar y gestionar facturas', 'rgba(27,58,107,.09)', 'var(--navy)'],
            ['/reportes', 'fa-chart-bar', 'Reportes', 'Filtrar y exportar datos', 'rgba(27,58,107,.07)', 'var(--navy-light)'],
        ];
        ?>
        <?php foreach ($accesos as $a): ?>
        <a href="<?= $baseUrl . $a[0] ?>" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;padding:14px 18px;margin-top:0;"
           onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(27,58,107,.14)'"
           onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="display:flex;align-items:center;gap:5px;">
                <div style="width:42px;height:42px;border-radius:11px;background:<?= $a[4] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $a[1] ?>" style="font-size:18px;color:<?= $a[5] ?>;"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:13.5px;font-weight:700;color:var(--navy);"><?= $a[2] ?></div>
                    <div style="font-size:11.5px;color:var(--text-muted);"><?= $a[3] ?></div>
                </div>
                <i class="fas fa-chevron-right" style="color:var(--border);"></i>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

</div>

<script>
var SOC_BASE = '<?= $baseUrl ?>';

function socEditar(id, btn) {
    var form = document.getElementById('form-sociedad');
    form.action = SOC_BASE + '/sociedades/editar/' + id;
    document.getElementById('soc-nombre').value = btn.dataset.nombre;
    document.getElementById('soc-cedula').value = btn.dataset.cedula;
    document.getElementById('soc-submit').innerHTML = '<i class="fas fa-check"></i> Guardar cambios';
    document.getElementById('soc-cancelar').style.display = 'inline-flex';
    document.getElementById('soc-nombre').focus();
}

function socCancelarEdicion() {
    var form = document.getElementById('form-sociedad');
    form.action = SOC_BASE + '/sociedades/crear';
    form.reset();
    document.getElementById('soc-submit').innerHTML = '<i class="fas fa-plus"></i> Agregar';
    document.getElementById('soc-cancelar').style.display = 'none';
}
</script>
