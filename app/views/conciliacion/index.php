<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$stats = $stats ?? [];
$lastImports = $lastImports ?? [];
$facturaBorder = '#0f4c81';
$gastoBorder = '#bf6a02';
$formatImportDate = function ($value) {
	if (empty($value)) {
		return 'Sin importaciones';
	}

	$ts = strtotime((string) $value);
	return $ts ? date('d/m/Y H:i', $ts) : (string) $value;
};
?>

<div style="max-width: 1380px; margin: 0 auto; padding: 0 16px;">
	<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px;">
		<div>
			<h1 style="margin:0 0 8px;font-size:30px;color:#14324a;">Panel de conciliacion</h1>
			<p style="margin:0;color:#52606d;max-width:820px;">Importa las facturas XML, carga el archivo de gastos y ejecuta la conciliacion desde esta misma pantalla. Los listados completos se consultan en ventanas flotantes sin salir del flujo.</p>
		</div>
		<form method="post" action="<?= $baseUrl ?>/conciliacion/ejecutar" style="margin:0;">
			<button type="submit" style="background:#0f766e;color:#fff;border:none;border-radius:999px;padding:12px 18px;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px rgba(15,118,110,.2);">
				Ejecutar conciliacion
			</button>
		</form>
	</div>

	<?php if (!empty($load_error ?? null)): ?>
		<div style="margin-bottom:14px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;font-size:13px;">
			No fue posible cargar datos del panel: <?= htmlspecialchars($load_error) ?>
		</div>
	<?php endif; ?>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:18px;">
		<div style="background:linear-gradient(135deg,#123b5d,#1e5f90);color:#fff;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(18,59,93,.16);">
			<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.82;">Facturas XML</div>
			<div style="font-size:30px;font-weight:800;margin-top:8px;"><?= number_format((int) ($stats['total_facturas'] ?? 0)) ?></div>
			<div style="margin-top:6px;font-size:13px;opacity:.9;">Monto total <?= number_format((float) ($stats['monto_facturas'] ?? 0), 2) ?></div>
		</div>
		<div style="background:linear-gradient(135deg,#7c3b00,#d97706);color:#fff;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(191,106,2,.16);">
			<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.82;">Gastos consolidados</div>
			<div style="font-size:30px;font-weight:800;margin-top:8px;"><?= number_format((int) ($stats['total_gastos'] ?? 0)) ?></div>
			<div style="margin-top:6px;font-size:13px;opacity:.9;">Monto total <?= number_format((float) ($stats['monto_gastos'] ?? 0), 2) ?></div>
		</div>
		<div style="background:#ffffff;border:1px solid #dbe7f0;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.06);">
			<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">Conciliadas</div>
			<div style="font-size:30px;font-weight:800;margin-top:8px;color:#0f766e;"><?= number_format((int) ($stats['total_conciliadas'] ?? 0)) ?></div>
			<div style="margin-top:6px;font-size:13px;color:#52606d;">Registros resueltos sin intervención</div>
		</div>
		<div style="background:#ffffff;border:1px solid #dbe7f0;border-radius:18px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.06);">
			<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">Pendientes o con diferencias</div>
			<div style="font-size:30px;font-weight:800;margin-top:8px;color:#b45309;"><?= number_format((int) ($stats['pendientes_revision'] ?? 0)) ?></div>
			<div style="margin-top:6px;font-size:13px;color:#52606d;">Requieren revisión manual</div>
		</div>
	</div>

	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-bottom:18px;align-items:start;">
		<section style="background:#fff;border:1px solid #d9e4ec;border-radius:20px;padding:20px;box-shadow:0 12px 30px rgba(15,23,42,.05);">
			<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px;">
				<div>
					<h2 style="margin:0 0 6px;font-size:20px;color:#14324a;">Carga de facturas XML / PDF</h2>
					<p style="margin:0;color:#5b6874;font-size:13px;">Sube XML CFDI o PDF. Si el PDF es imagen, se intenta OCR para extraer datos y guardar solo los campos.</p>
				</div>
				<button type="button" data-modal-target="modal-facturas" onclick="openConciliacionModal('modal-facturas')" style="border:1px solid #bed6e8;background:#f3f9fd;color:#0f4c81;border-radius:999px;padding:9px 12px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">
					Ver listado
				</button>
			</div>

			<div style="margin-bottom:14px;padding:12px 14px;border-radius:14px;background:#f7fbff;border:1px solid #d7e8f4;font-size:13px;color:#28455d;">
				<div><strong>Ultima importacion:</strong> <?= htmlspecialchars($formatImportDate($lastImports['xml']['fecha_importacion'] ?? null)) ?></div>
				<div style="margin-top:4px;"><strong>Archivo:</strong> <?= htmlspecialchars($lastImports['xml']['archivo_origen'] ?? 'Sin importaciones') ?></div>
			</div>

			<form method="post" action="<?= $baseUrl ?>/facturas/subir" enctype="multipart/form-data">
				<label for="xml_files" style="display:block;margin-bottom:8px;font-size:13px;font-weight:700;color:#14324a;">Archivos XML o PDF</label>
				<input type="file" id="xml_files" name="xml_files[]" accept=".xml,.pdf,application/pdf,text/xml" multiple required style="display:block;width:100%;padding:10px;border:1px dashed #9fc3de;border-radius:14px;background:#fbfdff;margin-bottom:10px;">
				<div style="font-size:12px;color:#60717f;margin-bottom:14px;">Puedes seleccionar varios archivos. Los PDF solo se usan para extracción y luego se eliminan.</div>
				<button type="submit" style="background:#0f4c81;color:#fff;border:none;border-radius:12px;padding:11px 16px;font-weight:700;cursor:pointer;">
					Importar documentos
				</button>
			</form>
		</section>

		<section style="background:#fff;border:1px solid #e6dccd;border-radius:20px;padding:20px;box-shadow:0 12px 30px rgba(15,23,42,.05);">
			<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px;">
				<div>
					<h2 style="margin:0 0 6px;font-size:20px;color:#4e2b00;">Carga de gastos</h2>
					<p style="margin:0;color:#6b5a46;font-size:13px;">Carga el Excel o CSV con encabezados Fecha, Numero, Proveedor, Iva y Total.</p>
				</div>
				<button type="button" data-modal-target="modal-gastos" onclick="openConciliacionModal('modal-gastos')" style="border:1px solid #f0cfaa;background:#fff7ed;color:#bf6a02;border-radius:999px;padding:9px 12px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">
					Ver listado
				</button>
			</div>

			<div style="margin-bottom:14px;padding:12px 14px;border-radius:14px;background:#fff8f1;border:1px solid #f2dec1;font-size:13px;color:#684a28;">
				<div><strong>Ultima importacion:</strong> <?= htmlspecialchars($formatImportDate($lastImports['gastos']['fecha_importacion'] ?? null)) ?></div>
				<div style="margin-top:4px;"><strong>Archivo:</strong> <?= htmlspecialchars($lastImports['gastos']['archivo_origen'] ?? 'Sin importaciones') ?></div>
			</div>

			<form method="post" action="<?= $baseUrl ?>/gastos/subir" enctype="multipart/form-data">
				<label for="gastos_file" style="display:block;margin-bottom:8px;font-size:13px;font-weight:700;color:#4e2b00;">Archivo de gastos</label>
				<input type="file" id="gastos_file" name="gastos_file" accept=".csv,.xlsx,.xls" required style="display:block;width:100%;padding:10px;border:1px dashed #e9c59b;border-radius:14px;background:#fffdfa;margin-bottom:10px;">
				<div style="font-size:12px;color:#7a6856;margin-bottom:8px;">Formato soportado: CSV o XLSX. Si tienes XLS, guárdalo primero como XLSX o CSV.</div>
				<div style="font-size:12px;color:#7a6856;margin-bottom:14px;">Encabezados obligatorios: Fecha, Numero, Proveedor, Iva, Total.</div>
				<button type="submit" style="background:#bf6a02;color:#fff;border:none;border-radius:12px;padding:11px 16px;font-weight:700;cursor:pointer;">
					Importar gastos
				</button>
			</form>
		</section>

		<section style="background:linear-gradient(160deg,#eef8f6,#ffffff);border:1px solid #cfe6de;border-radius:20px;padding:20px;box-shadow:0 12px 30px rgba(15,23,42,.05);">
			<h2 style="margin:0 0 6px;font-size:20px;color:#0f5149;">Ejecucion y seguimiento</h2>
			<p style="margin:0 0 16px;color:#496b67;font-size:13px;">Cuando termines de importar, ejecuta la conciliacion para recalcular coincidencias. El proceso actual reemplaza la corrida anterior.</p>

			<?php if (!empty($resumen ?? [])): ?>
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
					<?php foreach ($resumen as $item): ?>
						<?php $color = $item['color'] ?? '#94a3b8'; ?>
						<div style="border:1px solid <?= htmlspecialchars($color) ?>;border-radius:999px;padding:7px 10px;font-size:12px;background:#fff;">
							<strong><?= htmlspecialchars($item['nombre'] ?? '') ?>:</strong>
							<?= (int) ($item['total'] ?? 0) ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?= $baseUrl ?>/conciliacion/ejecutar" style="margin:0 0 14px;">
				<button type="submit" style="background:#0f766e;color:#fff;border:none;border-radius:12px;padding:12px 16px;font-weight:700;cursor:pointer;width:100%;">
					Conciliar ahora
				</button>
			</form>

			<form method="post" action="<?= $baseUrl ?>/conciliacion/limpiar-pruebas" style="margin:0 0 14px;" onsubmit="return confirm('Esto eliminara todas las facturas y conciliaciones cargadas. Deseas continuar?');">
				<button type="submit" style="background:#b91c1c;color:#fff;border:none;border-radius:12px;padding:11px 16px;font-weight:700;cursor:pointer;width:100%;">
					Eliminar facturas y conciliaciones (pruebas)
				</button>
			</form>

			<div style="font-size:12px;color:#5b6874;line-height:1.5;">
				<div>1. Importa los XML.</div>
				<div>2. Importa el archivo de gastos.</div>
				<div>3. Ejecuta la conciliacion y revisa diferencias abajo.</div>
			</div>
		</section>
	</div>

	<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
		<div>
			<h2 style="margin:0 0 4px;font-size:22px;color:#14324a;">Resultados de conciliacion</h2>
			<p style="margin:0;color:#667583;font-size:13px;">Compara factura contra gasto y corrige el estado manualmente cuando haga falta.</p>
		</div>
		<div style="display:flex;gap:8px;flex-wrap:wrap;">
			<button type="button" data-modal-target="modal-facturas" onclick="openConciliacionModal('modal-facturas')" style="border:1px solid #bed6e8;background:#fff;color:#0f4c81;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700;cursor:pointer;">Ver facturas</button>
			<button type="button" data-modal-target="modal-gastos" onclick="openConciliacionModal('modal-gastos')" style="border:1px solid #f0cfaa;background:#fff;color:#bf6a02;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700;cursor:pointer;">Ver gastos</button>
		</div>
	</div>

	<div style="background:#fff;border:1px solid #dbe1e7;border-radius:18px;overflow:auto;box-shadow:0 16px 35px rgba(15,23,42,.05);">
		<table style="width:100%;border-collapse:collapse;min-width:1400px;font-size:12px;line-height:1.25;">
			<thead style="background:#f4f6f8;position:sticky;top:0;z-index:2;">
				<tr>
					<th colspan="5" style="border:2px solid <?= $facturaBorder ?>;padding:8px;text-align:center;background:#eff6ff;">Facturas</th>
					<th colspan="5" style="border:2px solid <?= $gastoBorder ?>;padding:8px;text-align:center;background:#fff7ed;">Gastos</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:8px;text-align:center;">Match</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:8px;text-align:center;">Estado</th>
					<th rowspan="2" style="border:1px solid #d9dee4;padding:8px;text-align:center;">Validacion manual</th>
				</tr>
				<tr>
					<th style="border:1px solid <?= $facturaBorder ?>;padding:6px;text-align:left;background:#eff6ff;">Fecha</th>
					<th style="border:1px solid <?= $facturaBorder ?>;padding:6px;text-align:left;background:#eff6ff;">Numero</th>
					<th style="border:1px solid <?= $facturaBorder ?>;padding:6px;text-align:left;background:#eff6ff;">Proveedor</th>
					<th style="border:1px solid <?= $facturaBorder ?>;padding:6px;text-align:right;background:#eff6ff;">Iva</th>
					<th style="border:1px solid <?= $facturaBorder ?>;padding:6px;text-align:right;background:#eff6ff;">Total</th>
					<th style="border:1px solid <?= $gastoBorder ?>;padding:6px;text-align:left;background:#fff7ed;">Fecha</th>
					<th style="border:1px solid <?= $gastoBorder ?>;padding:6px;text-align:left;background:#fff7ed;">Numero</th>
					<th style="border:1px solid <?= $gastoBorder ?>;padding:6px;text-align:left;background:#fff7ed;">Proveedor</th>
					<th style="border:1px solid <?= $gastoBorder ?>;padding:6px;text-align:right;background:#fff7ed;">Iva</th>
					<th style="border:1px solid <?= $gastoBorder ?>;padding:6px;text-align:right;background:#fff7ed;">Total</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($conciliaciones ?? [])): ?>
					<tr>
						<td colspan="13" style="padding:18px;border:1px solid #e5e7eb;color:#64748b;">No hay conciliaciones aún. Importa la información y presiona <strong>Conciliar ahora</strong>.</td>
					</tr>
				<?php else: ?>
					<?php
					$cellOk = 'background:#e8f7ec;';
					$cellWarn = 'background:#fff8db;';
					$cellMissing = 'background:#ffe7e7;';
					$baseFacturaCell = 'border:1px solid ' . $facturaBorder . ';padding:5px 6px;';
					$baseGastoCell = 'border:1px solid ' . $gastoBorder . ';padding:5px 6px;';
					$normText = function ($v) {
						$t = strtoupper(trim((string) $v));
						$t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
						$t = preg_replace('/\s+/', ' ', $t);
						return trim($t);
					};
					$normNum = function ($v) {
						$t = strtoupper(trim((string) $v));
						$t = preg_replace('/[^A-Z0-9]/', '', $t);
						$t = preg_replace('/^0+/', '', $t);
						return $t;
					};
					$montoTolerancia = 1.00;
					$eqAmount = function ($a, $b) use ($montoTolerancia) {
						return abs(((float) $a) - ((float) $b)) <= $montoTolerancia;
					};
					?>
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
						$facturaFecha = (string) ($row['factura_fecha'] ?? '');
						$gastoFecha = (string) ($row['gasto_fecha'] ?? '');
						$facturaNumero = (string) ($row['factura_numero'] ?? '');
						$gastoNumero = (string) ($row['gasto_numero'] ?? '');
						$facturaProveedor = (string) ($row['factura_proveedor'] ?? '');
						$gastoProveedor = (string) ($row['gasto_proveedor'] ?? '');
						$facturaIva = (float) ($row['factura_iva'] ?? 0);
						$gastoIva = (float) ($row['gasto_iva'] ?? 0);
						$facturaTotal = (float) ($row['factura_total'] ?? 0);
						$gastoTotal = (float) ($row['gasto_total'] ?? 0);
						$bothFecha = ($facturaFecha !== '' && $gastoFecha !== '');
						$bothNumero = ($facturaNumero !== '' && $gastoNumero !== '');
						$bothProveedor = ($facturaProveedor !== '' && $gastoProveedor !== '');
						$bothIva = (($row['factura_iva'] ?? null) !== null && ($row['gasto_iva'] ?? null) !== null);
						$bothTotal = (($row['factura_total'] ?? null) !== null && ($row['gasto_total'] ?? null) !== null);
						$eqFecha = $bothFecha && ($facturaFecha === $gastoFecha);
						$eqNumero = $bothNumero && ($normNum($facturaNumero) === $normNum($gastoNumero));
						$eqProveedor = $bothProveedor && ($normText($facturaProveedor) === $normText($gastoProveedor));
						$eqIva = $bothIva && $eqAmount($facturaIva, $gastoIva);
						$eqTotal = $bothTotal && $eqAmount($facturaTotal, $gastoTotal);
						$styleFacturaFecha = $baseFacturaCell . ($bothFecha ? ($eqFecha ? $cellOk : $cellWarn) : $cellMissing);
						$styleFacturaNumero = $baseFacturaCell . ($bothNumero ? ($eqNumero ? $cellOk : $cellWarn) : $cellMissing);
						$styleFacturaProveedor = $baseFacturaCell . ($bothProveedor ? ($eqProveedor ? $cellOk : $cellWarn) : $cellMissing);
						$styleFacturaIva = $baseFacturaCell . ($bothIva ? ($eqIva ? $cellOk : $cellWarn) : $cellMissing);
						$styleFacturaTotal = $baseFacturaCell . ($bothTotal ? ($eqTotal ? $cellOk : $cellWarn) : $cellMissing);
						$styleGastoFecha = $baseGastoCell . ($bothFecha ? ($eqFecha ? $cellOk : $cellWarn) : $cellMissing);
						$styleGastoNumero = $baseGastoCell . ($bothNumero ? ($eqNumero ? $cellOk : $cellWarn) : $cellMissing);
						$styleGastoProveedor = $baseGastoCell . ($bothProveedor ? ($eqProveedor ? $cellOk : $cellWarn) : $cellMissing);
						$styleGastoIva = $baseGastoCell . ($bothIva ? ($eqIva ? $cellOk : $cellWarn) : $cellMissing);
						$styleGastoTotal = $baseGastoCell . ($bothTotal ? ($eqTotal ? $cellOk : $cellWarn) : $cellMissing);
						?>
						<tr style="background:<?= htmlspecialchars($bg) ?>;">
							<td style="<?= $styleFacturaFecha ?>"><?= htmlspecialchars($facturaFecha) ?></td>
							<td style="<?= $styleFacturaNumero ?>"><?= htmlspecialchars($facturaNumero) ?></td>
							<td style="<?= $styleFacturaProveedor ?>"><?= htmlspecialchars($facturaProveedor) ?></td>
							<td style="<?= $styleFacturaIva ?>text-align:right;"><?= number_format($facturaIva, 2) ?></td>
							<td style="<?= $styleFacturaTotal ?>text-align:right;"><?= number_format($facturaTotal, 2) ?></td>
							<td style="<?= $styleGastoFecha ?>"><?= htmlspecialchars($gastoFecha) ?></td>
							<td style="<?= $styleGastoNumero ?>"><?= htmlspecialchars($gastoNumero) ?></td>
							<td style="<?= $styleGastoProveedor ?>"><?= htmlspecialchars($gastoProveedor) ?></td>
							<td style="<?= $styleGastoIva ?>text-align:right;"><?= number_format($gastoIva, 2) ?></td>
							<td style="<?= $styleGastoTotal ?>text-align:right;"><?= number_format($gastoTotal, 2) ?></td>
							<td style="border:1px solid #e5e7eb;padding:5px 6px;text-align:center;font-weight:700;"><?= number_format((float) ($row['match_score'] ?? 0), 2) ?></td>
							<td style="border:1px solid #e5e7eb;padding:5px 6px;text-align:center;">
								<span style="display:inline-block;padding:3px 7px;border-radius:999px;border:1px solid <?= htmlspecialchars($color) ?>;font-size:11px;background:#fff;">
									<?= htmlspecialchars($row['estado_nombre'] ?? '') ?>
								</span>
							</td>
							<td style="border:1px solid #e5e7eb;padding:5px 6px;">
								<form method="post" action="<?= $baseUrl ?>/conciliacion/revisar/<?= (int) ($row['conciliacion_id'] ?? 0) ?>" style="display:flex;gap:4px;align-items:center;">
									<select name="estado_codigo" style="padding:4px 5px;border:1px solid #cbd5e1;border-radius:6px;font-size:11px;min-width:130px;">
										<?php foreach ($estados as $codigo => $estado): ?>
											<option value="<?= htmlspecialchars($codigo) ?>" <?= ($codigo === ($row['estado_codigo'] ?? '')) ? 'selected' : '' ?>>
												<?= htmlspecialchars($estado['nombre']) ?>
											</option>
										<?php endforeach; ?>
									</select>
									<input type="text" name="comentario" value="<?= htmlspecialchars($row['revision_comentario'] ?? ($row['notas'] ?? '')) ?>" placeholder="Comentario" style="padding:4px 5px;border:1px solid #cbd5e1;border-radius:6px;font-size:11px;width:160px;" />
									<button type="submit" style="padding:4px 8px;border:none;background:#1d4ed8;color:#fff;border-radius:6px;font-size:11px;cursor:pointer;">Guardar</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<div id="modal-facturas" data-modal onclick="if (event.target === this) { closeConciliacionModal('modal-facturas'); }" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,23,42,.56);padding:24px;">
		<div style="max-width:1180px;margin:0 auto;background:#fff;border-radius:22px;box-shadow:0 30px 60px rgba(15,23,42,.3);overflow:hidden;max-height:calc(100vh - 48px);display:flex;flex-direction:column;">
			<div style="display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #e2e8f0;gap:12px;">
				<div>
					<h3 style="margin:0 0 4px;font-size:20px;color:#14324a;">Listado de facturas XML</h3>
					<p style="margin:0;color:#60717f;font-size:13px;">Consulta las facturas sin abandonar el panel principal.</p>
				</div>
				<button type="button" data-modal-close onclick="closeConciliacionModal('modal-facturas')" style="border:none;background:#e2e8f0;color:#334155;width:36px;height:36px;border-radius:999px;font-size:18px;cursor:pointer;">&times;</button>
			</div>
			<div style="padding:18px;overflow:auto;">
				<table style="width:100%;border-collapse:collapse;min-width:950px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Consecutivo</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Numero</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Proveedor</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Fecha</th>
							<th style="padding:12px;text-align:right;border-bottom:1px solid #e2e8f0;">Iva</th>
							<th style="padding:12px;text-align:right;border-bottom:1px solid #e2e8f0;">Total</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Archivo</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Accion</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($facturas ?? [])): ?>
							<tr>
								<td colspan="8" style="padding:16px;color:#64748b;">No hay facturas cargadas todavía.</td>
							</tr>
						<?php else: ?>
							<?php foreach ($facturas as $factura): ?>
								<tr>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($factura['consecutivo_completo'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($factura['numero_factura_asistente'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($factura['proveedor_nombre'] ?? 'SIN PROVEEDOR') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($factura['fecha_emision'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;text-align:right;"><?= number_format((float) ($factura['iva'] ?? 0), 2) ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;text-align:right;"><?= number_format((float) ($factura['total'] ?? 0), 2) ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($factura['archivo_xml'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><a href="<?= $baseUrl ?>/facturas/ver/<?= (int) ($factura['id'] ?? 0) ?>" style="color:#1d4ed8;text-decoration:none;font-weight:700;">Ver</a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div id="modal-gastos" data-modal onclick="if (event.target === this) { closeConciliacionModal('modal-gastos'); }" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,23,42,.56);padding:24px;">
		<div style="max-width:1080px;margin:0 auto;background:#fff;border-radius:22px;box-shadow:0 30px 60px rgba(15,23,42,.3);overflow:hidden;max-height:calc(100vh - 48px);display:flex;flex-direction:column;">
			<div style="display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #e2e8f0;gap:12px;">
				<div>
					<h3 style="margin:0 0 4px;font-size:20px;color:#4e2b00;">Listado de gastos consolidados</h3>
					<p style="margin:0;color:#7a6856;font-size:13px;">Consulta el consolidado de gastos sin cambiar de pantalla.</p>
				</div>
				<button type="button" data-modal-close onclick="closeConciliacionModal('modal-gastos')" style="border:none;background:#e2e8f0;color:#334155;width:36px;height:36px;border-radius:999px;font-size:18px;cursor:pointer;">&times;</button>
			</div>
			<div style="padding:18px;overflow:auto;">
				<table style="width:100%;border-collapse:collapse;min-width:780px;">
					<thead style="background:#f8fafc;">
						<tr>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Numero</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Proveedor</th>
							<th style="padding:12px;text-align:center;border-bottom:1px solid #e2e8f0;">Items</th>
							<th style="padding:12px;text-align:left;border-bottom:1px solid #e2e8f0;">Fecha</th>
							<th style="padding:12px;text-align:right;border-bottom:1px solid #e2e8f0;">Iva</th>
							<th style="padding:12px;text-align:right;border-bottom:1px solid #e2e8f0;">Total</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($gastos ?? [])): ?>
							<tr>
								<td colspan="6" style="padding:16px;color:#64748b;">No hay gastos cargados todavía.</td>
							</tr>
						<?php else: ?>
							<?php foreach ($gastos as $gasto): ?>
								<?php $fechaVisible = $gasto['fecha_max'] ?? ($gasto['fecha_min'] ?? ''); ?>
								<tr>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($gasto['numero_factura'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($gasto['proveedor_texto'] ?? '') ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;text-align:center;"><?= (int) ($gasto['cantidad_items'] ?? 0) ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;"><?= htmlspecialchars($fechaVisible) ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;text-align:right;"><?= number_format((float) ($gasto['suma_iva'] ?? 0), 2) ?></td>
									<td style="padding:12px;border-bottom:1px solid #eef2f7;text-align:right;"><?= number_format((float) ($gasto['suma_total'] ?? 0), 2) ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<script>
function openConciliacionModal(modalId) {
	var modal = document.getElementById(modalId);
	if (!modal) {
		return;
	}

	modal.style.display = 'block';
	document.body.style.overflow = 'hidden';
}

function closeConciliacionModal(modalId) {
	var modal = document.getElementById(modalId);
	if (!modal) {
		return;
	}

	modal.style.display = 'none';
	document.body.style.overflow = '';
}

document.addEventListener('keydown', function (event) {
	if (event.key === 'Escape') {
		closeConciliacionModal('modal-facturas');
		closeConciliacionModal('modal-gastos');
	}
});
</script>
