<div style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
	<h1 style="margin-bottom: 16px;">Importar Facturas XML</h1>

	<div style="background:#fff;border-radius:8px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
		<form method="post" action="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/facturas/subir" enctype="multipart/form-data">
			<div style="margin-bottom: 14px;">
				<label for="xml_files" style="display:block;margin-bottom:8px;font-weight:600;">Archivos XML (uno o varios)</label>
				<input type="file" id="xml_files" name="xml_files[]" accept=".xml" multiple required>
			</div>

			<p style="color:#666;margin-bottom:14px;">Formato soportado: <strong>.xml</strong></p>

			<button type="submit" style="background:#667eea;color:#fff;border:none;padding:10px 14px;border-radius:6px;cursor:pointer;">
				Procesar carga
			</button>
			<a href="<?= (defined('APP_URL') ? APP_URL : '/xmlconcilia/public') ?>/facturas" style="margin-left:10px;color:#555;">Volver al listado</a>
		</form>
	</div>
</div>
