<div style="max-width: 1280px; margin: 0 auto; padding: 0 14px;">
	<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
		<h1 style="margin:0;font-size:22px;">Conciliacion</h1>
		<form method="post" action="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/conciliacion/ejecutar" style="margin:0;">
			<button type="submit" style="background:#0f766e;color:#fff;border:none;border-radius:4px;padding:8px 12px;font-size:13px;cursor:pointer;">
				Conciliar
			</button>
		</form>
	</div>

	<?php if (!empty($load_error ?? null)): ?>
		<div style="margin-bottom:10px;padding:8px 10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;font-size:12px;">
			No fue posible cargar datos de conciliacion: <?= htmlspecialchars($load_error) ?>
		</div>
	<?php endif; ?>

	<?php if (!empty($resumen ?? [])): ?>
		<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
			<?php foreach ($resumen as $item): ?>
				<?php $color = $item['color'] ?? '#94a3b8'; ?>
				<div style="border:1px solid <?= htmlspecialchars($color) ?>; border-radius:5px; padding:5px 8px; font-size:12px; background:#fff;">
					<strong><?= htmlspecialchars($item['nombre'] ?? '') ?>:</strong>
					<?= (int) ($item['total'] ?? 0) ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #dbe1e7;border-radius:6px;overflow:auto;">
		<table style="width:100%;border-collapse:collapse;min-width:1400px;font-size:12px;line-height:1.2;">
			<thead style="background:#f4f6f8;position:sticky;top:0;z-index:2;">
				<tr>
					<th colspan="5" style="border:1px solid #d9dee4;padding:6px;text-align:center;">Facturas</th>
					<th colspan="5" style="border:1px solid #d9dee4;padding:6px;text-align:center;">Gastos</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:6px;text-align:center;">Match</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:6px;text-align:center;">Estado</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:6px;text-align:center;">Validacion manual</th>
				</tr>
				<tr>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Fecha</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Numero</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Proveedor</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:right;">Iva</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:right;">Total</th>

					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Fecha</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Numero</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:left;">Proveedor</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:right;">Iva</th>
					<th style="border:1px solid #d9dee4;padding:5px;text-align:right;">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($conciliaciones ?? [])): ?>
					<tr>
						<td colspan="13" style="padding:12px;border:1px solid #e5e7eb;color:#64748b;">No hay conciliaciones aún. Presiona <strong>Conciliar</strong> para generar coincidencias.</td>
					</tr>
				<?php else: ?>
					<?php foreach ($conciliaciones as $row): ?>
						<?php
						$color = $row['estado_color'] ?? '#94a3b8';
						$bg = 'rgba(148,163,184,.10)';
						if ($color === '#28a745') {
							$bg = 'rgba(40,167,69,.09)';
						} elseif ($color === '#ffc107') {
							$bg = 'rgba(255,193,7,.15)';
						} elseif ($color === '#dc3545') {
							$bg = 'rgba(220,53,69,.09)';
						} elseif ($color === '#17a2b8') {
							$bg = 'rgba(23,162,184,.10)';
						} elseif ($color === '#fd7e14') {
							$bg = 'rgba(253,126,20,.10)';
						}
						?>
						<tr style="background:<?= htmlspecialchars($bg) ?>;">
							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['factura_fecha'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['factura_numero'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['factura_proveedor'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:right;"><?= number_format((float) ($row['factura_iva'] ?? 0), 2) ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:right;"><?= number_format((float) ($row['factura_total'] ?? 0), 2) ?></td>

							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['gasto_fecha'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['gasto_numero'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;"><?= htmlspecialchars($row['gasto_proveedor'] ?? '') ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:right;"><?= number_format((float) ($row['gasto_iva'] ?? 0), 2) ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:right;"><?= number_format((float) ($row['gasto_total'] ?? 0), 2) ?></td>

							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:center;font-weight:700;"><?= number_format((float) ($row['match_score'] ?? 0), 2) ?></td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;text-align:center;">
								<span style="display:inline-block;padding:2px 6px;border-radius:999px;border:1px solid <?= htmlspecialchars($color) ?>;font-size:11px;">
									<?= htmlspecialchars($row['estado_nombre'] ?? '') ?>
								</span>
							</td>
							<td style="border:1px solid #e5e7eb;padding:4px 5px;">
								<form method="post" action="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/conciliacion/revisar/<?= (int) ($row['conciliacion_id'] ?? 0) ?>" style="display:flex;gap:4px;align-items:center;">
									<select name="estado_codigo" style="padding:3px 4px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;min-width:130px;">
										<?php foreach ($estados as $codigo => $estado): ?>
											<option value="<?= htmlspecialchars($codigo) ?>" <?= ($codigo === ($row['estado_codigo'] ?? '')) ? 'selected' : '' ?>>
												<?= htmlspecialchars($estado['nombre']) ?>
											</option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="comentario" value="<?= htmlspecialchars($row['revision_comentario'] ?? ($row['notas'] ?? '')) ?>" placeholder="Comentario" style="padding:3px 4px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;width:160px;" />
									<button type="submit" style="padding:3px 7px;border:none;background:#1d4ed8;color:#fff;border-radius:4px;font-size:11px;cursor:pointer;">Guardar</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
