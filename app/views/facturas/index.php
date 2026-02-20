<div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
	<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
		<h1>Facturas XML</h1>
		<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/facturas/importar"
		   style="background:#667eea;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;">
			Importar XML
		</a>
	</div>

	<div style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:auto;">
		<table style="width:100%;border-collapse:collapse;min-width:900px;">
			<thead style="background:#f8f9fa;">
				<tr>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Consecutivo</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Número Asistente</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Proveedor ID</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Fecha</th>
					<th style="padding:12px;text-align:right;border-bottom:1px solid #eee;">Total</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Archivo</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($facturas ?? [])): ?>
					<tr>
						<td colspan="7" style="padding:16px;color:#666;">No hay facturas cargadas todavía.</td>
					</tr>
				<?php else: ?>
					<?php foreach ($facturas as $factura): ?>
						<tr>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($factura['consecutivo_completo'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($factura['numero_factura_asistente'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= (int)($factura['proveedor_id'] ?? 0) ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($factura['fecha_emision'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;text-align:right;">$<?= number_format((float)($factura['total'] ?? 0), 2) ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($factura['archivo_xml'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;">
								<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/facturas/ver/<?= (int)$factura['id'] ?>">Ver</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
