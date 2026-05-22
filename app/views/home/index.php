<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats   = $stats ?? ['total_facturas'=>0,'total_gastos'=>0,'total_conciliadas'=>0,'pendientes_revision'=>0];
?>

<div class="page-header">
    <div>
        <h1>Panel Principal</h1>
        <p>Resumen del estado actual de la conciliación. Accede a cada módulo desde el menú lateral.</p>
    </div>
</div>

<!-- Estadísticas -->
<div class="stats-grid mb-20">
    <div class="stat-card navy">
        <div class="stat-label"><i class="fas fa-file-invoice" style="margin-right:5px;"></i>Facturas XML</div>
        <div class="stat-value"><?= number_format((int)($stats['total_facturas'] ?? 0)) ?></div>
        <div class="stat-sub">Importadas en el sistema</div>
    </div>
    <div class="stat-card gold">
        <div class="stat-label"><i class="fas fa-receipt" style="margin-right:5px;"></i>Gastos Consolidados</div>
        <div class="stat-value"><?= number_format((int)($stats['total_gastos'] ?? 0)) ?></div>
        <div class="stat-sub">Registros activos</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-check-circle" style="margin-right:5px;"></i>Conciliadas</div>
        <div class="stat-value" style="color:var(--ok);"><?= number_format((int)($stats['total_conciliadas'] ?? 0)) ?></div>
        <div class="stat-sub">Coincidencias automáticas</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label"><i class="fas fa-clock" style="margin-right:5px;"></i>Pendientes</div>
        <div class="stat-value" style="color:var(--warn);"><?= number_format((int)($stats['pendientes_revision'] ?? 0)) ?></div>
        <div class="stat-sub">Requieren revisión manual</div>
    </div>
</div>

<!-- Accesos rápidos -->
<div class="grid-2 mb-20">

    <a href="<?= $baseUrl ?>/facturas" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(27,58,107,.16)'"
       onmouseout="this.style.transform='';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(27,58,107,.09);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-file-invoice" style="font-size:20px;color:var(--navy);"></i>
            </div>
            <div style="flex:1;min-height:56px;display:flex;flex-direction:column;justify-content:flex-start;">
                <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:3px;">Carga de Facturas XML</div>
                <div style="font-size:12px;color:var(--text-muted);">Importar y gestionar facturas CFDI</div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--border);"></i>
        </div>
    </a>

    <a href="<?= $baseUrl ?>/gastos" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(240,165,0,.14)'"
       onmouseout="this.style.transform='';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(240,165,0,.10);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-file-csv" style="font-size:20px;color:var(--gold-dark);"></i>
            </div>
            <div style="flex:1;min-height:56px;display:flex;flex-direction:column;justify-content:flex-start;">
                <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:3px;">Carga de Gastos CSV</div>
                <div style="font-size:12px;color:var(--text-muted);">Importar listado de gastos CSV / XLSX</div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--border);"></i>
        </div>
    </a>

    <a href="<?= $baseUrl ?>/conciliacion" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(15,118,110,.14)'"
       onmouseout="this.style.transform='';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(15,118,110,.09);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-check-double" style="font-size:20px;color:#0f766e;"></i>
            </div>
            <div style="flex:1;min-height:56px;display:flex;flex-direction:column;justify-content:flex-start;">
                <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:3px;">Conciliación</div>
                <div style="font-size:12px;color:var(--text-muted);">Ejecutar conciliación automática</div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--border);"></i>
        </div>
    </a>

    <a href="<?= $baseUrl ?>/reportes" class="card" style="text-decoration:none;transition:transform .14s,box-shadow .14s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(27,58,107,.12)'"
       onmouseout="this.style.transform='';this.style.boxShadow=''">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="width:48px;height:48px;border-radius:12px;background:rgba(27,58,107,.07);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-chart-bar" style="font-size:20px;color:var(--navy-light);"></i>
            </div>
            <div style="flex:1;min-height:56px;display:flex;flex-direction:column;justify-content:flex-start;">
                <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:3px;">Reportes</div>
                <div style="font-size:12px;color:var(--text-muted);">Filtrar y exportar datos a XLSX</div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--border);"></i>
        </div>
    </a>

</div>

<!-- Estados del sistema -->
<div class="card">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-traffic-light" style="margin-right:6px;color:var(--navy-light);"></i>
            Estados de Conciliación
        </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:#f3f7fb;">
            <span class="badge badge-ok"><i class="fas fa-check-circle"></i> Conciliada</span>
            <span style="font-size:12px;color:var(--text-muted);">Match 100% automático</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:#f3f7fb;">
            <span class="badge badge-warn"><i class="fas fa-search"></i> Requiere Revisión</span>
            <span style="font-size:12px;color:var(--text-muted);">Match parcial 75–99%</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:#f3f7fb;">
            <span class="badge badge-diff"><i class="fas fa-exclamation-triangle"></i> Con Diferencias</span>
            <span style="font-size:12px;color:var(--text-muted);">Montos no coinciden</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:#f3f7fb;">
            <span class="badge badge-pend"><i class="fas fa-clock"></i> Pendiente</span>
            <span style="font-size:12px;color:var(--text-muted);">Sin gasto asociado</span>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:#f3f7fb;">
            <span class="badge badge-miss"><i class="fas fa-times-circle"></i> Sin XML</span>
            <span style="font-size:12px;color:var(--text-muted);">Gasto sin factura</span>
        </div>
    </div>
</div>
