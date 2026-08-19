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

    <?php // Elegir con qué empresa se trabaja es cosa de todos los días y por
          // eso sigue acá. Registrarlas, editarlas y borrarlas —que se hace una
          // vez— se mudó a Configuración, junto al resto de los ajustes. ?>
    <div class="card">
        <div class="card-header mb-12">
            <div class="card-title">
                <i class="fas fa-building" style="margin-right:6px;color:var(--navy-light);"></i>
                Empresa con la que trabajas
            </div>
            <a href="<?= $baseUrl ?>/configuracion?ir=empresas" class="btn btn-outline btn-sm"
               title="Registrar, editar o eliminar empresas">
                <i class="fas fa-gear"></i> Administrar
            </a>
        </div>

        <?php if (empty($sociedades)): ?>
        <div style="font-size:12.5px;color:var(--text-muted);">
            Todavía no hay ninguna empresa registrada. La que elijas es con la que trabajas:
            su cédula se compara contra el receptor de cada factura del correo.
            <a href="<?= $baseUrl ?>/configuracion?ir=empresas" style="color:var(--navy-light);font-weight:700;">
                Registra la primera
            </a>.
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:5px;">
            <?php foreach ($sociedades as $soc):
                $enUso = (int) $soc['id'] === $sociedadEnUsoId; ?>
            <form method="POST" action="<?= $baseUrl ?>/sociedades/activar/<?= (int) $soc['id'] ?>">
                <button type="submit" <?= $enUso ? 'disabled' : '' ?>
                        title="<?= $enUso ? 'Estás trabajando con esta empresa' : 'Trabajar con esta empresa' ?>"
                        style="width:100%;display:flex;align-items:center;gap:9px;text-align:left;
                               padding:8px 11px;border-radius:8px;cursor:<?= $enUso ? 'default' : 'pointer' ?>;
                               background:<?= $enUso ? 'var(--gold-pale)' : 'transparent' ?>;
                               border:1.5px solid <?= $enUso ? 'var(--gold-light)' : 'var(--border)' ?>;">
                    <i class="<?= $enUso ? 'fas fa-circle-check' : 'far fa-circle' ?>"
                       style="color:<?= $enUso ? 'var(--ok)' : 'var(--text-light)' ?>;font-size:14px;"></i>
                    <span style="flex:1;min-width:0;">
                        <span style="display:block;font-size:12.5px;font-weight:<?= $enUso ? '800' : '600' ?>;color:var(--navy);">
                            <?= htmlspecialchars($soc['nombre']) ?>
                        </span>
                        <span style="display:block;font-size:11px;color:var(--text-muted);">
                            Cédula <?= htmlspecialchars($soc['cedula']) ?>
                            <?php if (!empty($soc['activa'])): ?>
                            · <span title="Con esta empresa arranca quien nunca ha elegido, y con ella trabajan las tareas automáticas">por omisión</span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <?php if ($enUso): ?>
                    <span class="badge badge-green">En uso</span>
                    <?php endif; ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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
