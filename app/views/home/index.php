<?php
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats          = $stats ?? ['total_facturas' => 0, 'bandeja_pendientes' => 0];
$sociedades     = $sociedades ?? [];
$sociedadActiva = $sociedadActiva ?? null;

// La empresa marcada es la de ESTE usuario, no la columna `activa` de la
// tabla. `activa` es solo el valor por omisión del sistema: con dos personas
// en empresas distintas, leerla aquí marcaba la fila equivocada y dejaba sin
// botón —imposible de seleccionar— justamente a la que había que elegir.
$sociedadEnUsoId = (int) ($sociedadActiva['id'] ?? 0);
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
        <div class="stat-sub">Sube el listado en Pagos semanales</div>
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
            Registra las sociedades del grupo. La que <strong>elijas</strong> es con la que trabajas:
            su cédula se compara contra el receptor de cada factura del correo.
        </div>
        <?php else: ?>
        <table class="data-table" style="font-size:12.5px;margin-bottom:12px;">
            <thead>
                <tr>
                    <th style="width:34px;" title="Sociedad con la que estás trabajando"></th>
                    <th>Nombre</th>
                    <th>Cédula</th>
                    <th class="center" style="width:90px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sociedades as $soc):
                    $enUso = (int) $soc['id'] === $sociedadEnUsoId; ?>
                <tr id="soc-fila-<?= (int) $soc['id'] ?>">
                    <td class="center">
                        <?php if ($enUso): ?>
                        <i class="fas fa-circle-check" style="color:var(--ok);" title="Trabajando con esta sociedad"></i>
                        <?php else: ?>
                        <form method="POST" action="<?= $baseUrl ?>/sociedades/activar/<?= (int) $soc['id'] ?>" style="display:inline;">
                            <button type="submit" title="Trabajar con esta sociedad"
                                    style="background:none;border:none;cursor:pointer;color:#cbd5e1;font-size:14px;padding:0;">
                                <i class="far fa-circle"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:<?= $enUso ? '700' : '400' ?>;color:var(--navy);">
                        <span class="soc-ver"><?= htmlspecialchars($soc['nombre']) ?></span>
                        <?php if (!empty($soc['activa'])): ?>
                        <span style="margin-left:6px;font-size:10.5px;font-weight:600;color:var(--text-light);
                                     text-transform:uppercase;letter-spacing:.04em;"
                              title="Con esta empresa arranca quien nunca ha elegido, y con ella trabajan las tareas automáticas">
                            por omisión
                        </span>
                        <?php endif; ?>
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
                              data-confirm="¿Quieres eliminar la sociedad <?= htmlspecialchars($soc['nombre'], ENT_QUOTES) ?>?"
                              data-confirm-title="Eliminar sociedad"
                              data-confirm-type="danger"
                              data-confirm-accept="Eliminar">
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
                       placeholder="EMPRESA EJEMPLO S.A.">
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
    <div style="display:flex;flex-direction:column;gap:6px;">
        <?php
        $accesos = [
            ['/por-pagar', 'fa-file-invoice-dollar', 'Pagos semanales', 'Verificar el listado del pago semanal', 'rgba(15,118,110,.09)', '#0f766e'],
            ['/correo', 'fa-envelope-open-text', 'Facturas desde Correo', 'Buscar e importar XML y PDF del buzón', 'rgba(240,165,0,.10)', 'var(--gold-dark)'],
            ['/facturas-erp', 'fa-file-invoice-dollar', 'Facturas ERP', 'Consultar el ERP y cargar facturas XML', 'rgba(27,58,107,.09)', 'var(--navy)'],
            ['/seguimiento', 'fa-list-check', 'Seguimiento', 'Lo que falta de respaldo o no cuadra', 'rgba(27,58,107,.07)', 'var(--navy-light)'],
        ];
        ?>
        <?php foreach ($accesos as $a): ?>
        <a href="<?= $baseUrl . $a[0] ?>" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;padding:9px 12px;margin-top:0;"
           onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(27,58,107,.14)'"
           onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="display:flex;align-items:center;gap:9px;">
                <div style="width:34px;height:34px;border-radius:9px;background:<?= $a[4] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas <?= $a[1] ?>" style="font-size:15px;color:<?= $a[5] ?>;"></i>
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
