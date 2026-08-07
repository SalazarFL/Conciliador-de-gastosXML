<div style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
	<h1 style="margin-bottom: 16px;">Detalle de Factura</h1>

	<?php if (empty($factura)): ?>
		<div style="background:#fff;padding:16px;border-radius:8px;">Factura no disponible.</div>
	<?php else: ?>
		<div style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:20px;">
			<p><strong>ID:</strong> <?= (int)$factura['id'] ?></p>
			<p><strong>Consecutivo:</strong> <?= htmlspecialchars($factura['consecutivo_completo'] ?? '') ?></p>
			<p><strong>Número asistente:</strong> <?= htmlspecialchars($factura['numero_factura_asistente'] ?? '') ?></p>
			<p><strong>Fecha emisión:</strong> <?= htmlspecialchars($factura['fecha_emision'] ?? '') ?></p>
					<p><strong>Total:</strong> <?= number_format((float)($factura['total'] ?? 0), 2) ?></p>
			<p><strong>Moneda:</strong> <?= htmlspecialchars($factura['moneda'] ?? 'CRC') ?></p>
			<p><strong>Archivo XML:</strong> <?= htmlspecialchars($factura['archivo_xml'] ?? '') ?></p>
			<div style="display:flex;gap:8px;margin-top:14px;">
				<a class="btn btn-primary" target="_blank" href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/documentos/xml/<?= (int)$factura['id'] ?>"><i class="fas fa-code"></i> Visualizar XML</a>
				<?php if (!empty($factura['ruta_pdf']) && is_file($factura['ruta_pdf'])): ?><a class="btn btn-outline" target="_blank" href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/documentos/pdf/<?= (int)$factura['id'] ?>"><i class="fas fa-file-pdf"></i> Visualizar PDF</a><?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<p style="margin-top:16px;">
		<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/facturas">Volver a Facturas</a>
	</p>
</div>
