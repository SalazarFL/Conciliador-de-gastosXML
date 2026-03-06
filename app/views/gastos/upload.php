<div style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
	<h1 style="margin-bottom:16px;">Importar Gastos</h1>

	<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
		<div style="margin-bottom:16px;padding:12px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;">
			<strong>Formato esperado del archivo (CSV o XLSX):</strong>
			<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;">
				<thead>
					<tr>
						<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Columna</th>
						<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Tipo</th>
						<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px;">Ejemplo</th>
					</tr>
				</thead>
				<tbody>
					<tr><td style="padding:6px;">Fecha</td><td style="padding:6px;">Fecha</td><td style="padding:6px;">06/02/2025</td></tr>
					<tr><td style="padding:6px;">Numero</td><td style="padding:6px;">Numero</td><td style="padding:6px;">4510215</td></tr>
					<tr><td style="padding:6px;">Proveedor</td><td style="padding:6px;">General</td><td style="padding:6px;">ICE</td></tr>
					<tr><td style="padding:6px;">Iva</td><td style="padding:6px;">Numero</td><td style="padding:6px;">11851.59</td></tr>
					<tr><td style="padding:6px;">Total</td><td style="padding:6px;">Numero</td><td style="padding:6px;">106992.50</td></tr>
				</tbody>
			</table>
		</div>

		<form method="post" action="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/gastos/subir" enctype="multipart/form-data">
			<div style="margin-bottom: 14px;">
				<label for="gastos_file" style="display:block;margin-bottom:8px;font-weight:600;">Archivo de gastos</label>
				<input type="file" id="gastos_file" name="gastos_file" accept=".csv,.xlsx,.xls" required>
			</div>

			<p style="color:#666;margin-bottom:14px;">Encabezados obligatorios: <strong>Fecha, Numero, Proveedor, Iva, Total</strong>.</p>
			<p style="color:#666;margin-bottom:14px;">Se soportan archivos <strong>.csv</strong> y <strong>.xlsx</strong>. Si tienes <strong>.xls</strong>, guárdalo primero como .xlsx o .csv.</p>

			<button type="submit" style="background:#667eea;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;">
				Procesar carga
			</button>
			<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/gastos" style="margin-left:10px;color:#555;">Volver al listado</a>
		</form>
	</div>
</div>
