<?php $baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public'; $n = $nota; ?>
<div style="margin-bottom:12px;"><a href="<?= $baseUrl ?>/notas-xml" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Volver</a></div>
<div class="card">
    <div class="card-header"><div class="card-title">NC <?= htmlspecialchars($n['numero_factura_asistente']) ?></div></div>
    <div style="padding:10px 12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px;font-size:12.5px;">
    <?php foreach (['Proveedor'=>$n['proveedor_nombre']??'','Cédula emisor'=>$n['proveedor_cedula']??'','Receptor'=>$n['receptor_id']??'','Fecha de emisión'=>$n['fecha_emision'],'Consecutivo'=>$n['consecutivo_completo'],'Clave'=>$n['clave']??'','Moneda'=>$n['moneda'],'Subtotal'=>number_format((float)$n['subtotal'],2),'IVA'=>number_format((float)$n['iva'],2),'Total'=>number_format((float)$n['total'],2),'Estado PDF'=>$n['estado_pdf']??'pendiente','Fecha de correo'=>$n['fecha_correo']??'—'] as $label=>$value): ?>
        <div><div style="font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;"><?= htmlspecialchars($label) ?></div><div style="color:var(--navy);word-break:break-word;"><?= htmlspecialchars((string)$value) ?></div></div>
    <?php endforeach; ?>
    </div>
    <div style="padding:0 12px 10px;display:flex;gap:7px;flex-wrap:wrap;"><a class="btn btn-primary" target="_blank" href="<?= $baseUrl ?>/documentos/xml/<?= (int)$n['id'] ?>"><i class="fas fa-code"></i> Visualizar XML</a><?php if (!empty($n['ruta_pdf']) && is_file($n['ruta_pdf'])): ?><a class="btn btn-outline" target="_blank" href="<?= $baseUrl ?>/documentos/pdf/<?= (int)$n['id'] ?>"><i class="fas fa-file-pdf"></i> Visualizar PDF</a><?php endif; ?></div>
</div>
