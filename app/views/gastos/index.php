<div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
	<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
		<h1>Gastos Consolidados</h1>
		<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/gastos/importar"
		   style="background:#667eea;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;">
			Importar CSV
		</a>
	</div>

	<div style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:auto;">
		<table style="width:100%;border-collapse:collapse;min-width:800px;">
			<thead style="background:#f8f9fa;">
				<tr>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Número</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Proveedor</th>
					<th style="padding:12px;text-align:center;border-bottom:1px solid #eee;">Items</th>
					<th style="padding:12px;text-align:left;border-bottom:1px solid #eee;">Fecha</th>
					<th style="padding:12px;text-align:right;border-bottom:1px solid #eee;">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($gastos ?? [])): ?>
					<tr>
						<td colspan="5" style="padding:16px;color:#666;">No hay gastos cargados todavía.</td>
					</tr>
				<?php else: ?>
					<?php foreach ($gastos as $gasto): ?>
						<?php $fechaVisible = $gasto['fecha_max'] ?? ($gasto['fecha_min'] ?? ''); ?>
						<tr>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($gasto['numero_factura'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($gasto['proveedor_texto'] ?? '') ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;text-align:center;"><?= (int)($gasto['cantidad_items'] ?? 0) ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;"><?= htmlspecialchars($fechaVisible) ?></td>
							<td style="padding:12px;border-bottom:1px solid #f0f0f0;text-align:right;"><?= number_format((float)($gasto['suma_total'] ?? 0), 2) ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
