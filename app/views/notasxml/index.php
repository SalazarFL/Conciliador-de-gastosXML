<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$notas = $notas ?? [];
$proveedoresFiltro = is_array($proveedoresFiltro ?? null) ? $proveedoresFiltro : [];
$queryBase = http_build_query(array_filter([
    'desde' => $desde ?? '', 'hasta' => $hasta ?? '',
    'buscar' => $buscar ?? '', 'proveedor' => $proveedor ?? '',
], 'strlen'));
$filtrosActivos = 0;
foreach ([$desde ?? '', $hasta ?? '', $buscar ?? '', $proveedor ?? ''] as $valorFiltro) {
    if ((string) $valorFiltro !== '') { $filtrosActivos++; }
}
?>
<div class="card" style="margin-bottom:10px;">
    <div class="card-header" style="flex-wrap:wrap;">
        <div class="card-title"><i class="fas fa-file-circle-minus" style="color:var(--gold);margin-right:6px;"></i>Notas de crédito XML</div>
        <a href="<?= $baseUrl ?>/notas-credito" class="btn btn-outline btn-sm" style="margin-left:auto;">
            <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Volver a Notas de crédito
        </a>
    </div>
    <?php /*
     * La subida vive acá: los comprobantes que entran son las filas de la
     * tabla de abajo. Al terminar se vuelve a verificar el acumulado de notas
     * de la sociedad activa.
     */ ?>
    <form method="post" action="<?= $baseUrl ?>/notas-xml/subir" enctype="multipart/form-data"
          style="padding:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="file" name="xml_files[]" id="ncxml-file" accept=".xml" multiple required style="display:none;">
        <label for="ncxml-file" class="upload-file-btn" style="padding:8px 16px;font-size:12.5px;">
            <i class="fas fa-folder-open"></i> Seleccionar XML
        </label>
        <span id="ncxml-nombre" style="font-size:12px;color:var(--text-muted);font-style:italic;">
            Ningún archivo seleccionado
        </span>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fas fa-file-import" style="margin-right:4px;"></i>Importar notas
        </button>
        <span style="font-size:11.5px;color:var(--text-muted);margin-left:auto;">
            Solo notas de crédito electrónicas (NC).
        </span>
    </form>
    <script>
    document.getElementById('ncxml-file').addEventListener('change', function () {
        var n = this.files.length;
        document.getElementById('ncxml-nombre').textContent = n === 0
            ? 'Ningún archivo seleccionado'
            : (n === 1 ? this.files[0].name : n + ' archivos seleccionados');
    });
    </script>
    <?php if (empty($carpetaRaiz)): ?><div style="margin:8px 0 0;padding:7px 9px;background:#fff7ed;border:1px solid #fdba74;border-radius:7px;color:#9a3412;font-size:11.5px;">Configura primero la carpeta raíz desde el engranaje de Correo.</div><?php endif; ?>
</div>

<?php
/*
 * La tarjeta del documento que se vino a buscar. Solo aparece si se llegó
 * desde la cola de seguimiento.
 */
include __DIR__ . '/../partials/tarjeta-documento.php';
?>

<div class="card">
    <div class="card-header"><div class="card-title">Documentos <span class="badge badge-navy"><?= (int) ($total ?? 0) ?></span></div></div>
    <form method="get" action="<?= $baseUrl ?>/notas-xml" class="filter-bar">
        <?php $provFiltro = [
            'valor'    => $proveedor ?? '',
            'opciones' => $proveedoresFiltro,
        ]; include __DIR__ . '/../partials/filtro-proveedor.php'; ?>
        <div class="filter-span-2">
            <label class="filter-label">Buscar</label>
            <input type="search" class="form-control" name="buscar" value="<?= htmlspecialchars($buscar ?? '') ?>" placeholder="Consecutivo, número o proveedor">
        </div>
        <div>
            <label class="filter-label">Fecha desde</label>
            <input class="form-control" type="date" name="desde" value="<?= htmlspecialchars($desde ?? '') ?>">
        </div>
        <div>
            <label class="filter-label">Fecha hasta</label>
            <input class="form-control" type="date" name="hasta" value="<?= htmlspecialchars($hasta ?? '') ?>">
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($filtrosActivos): ?>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/notas-xml?limpiar=1"><i class="fas fa-broom"></i> Limpiar</a>
            <?php endif; ?>
        </div>
    </form>
    <div class="filter-results">
        <i class="fas fa-filter" style="color:var(--navy-light);"></i>
        Mostrando <strong><?= count($notas) ?></strong> de <strong><?= (int) ($total ?? 0) ?></strong> documentos
        <?php if ($filtrosActivos): ?>
        <span class="badge badge-navy" style="font-size:10px;"><?= $filtrosActivos ?> filtro<?= $filtrosActivos === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>
    <div style="overflow-x:auto;margin-top:8px;"><table class="data-table">
        <thead><tr><th>Fecha</th><th>Proveedor</th><th>Consecutivo</th><th>Número</th><th>Moneda</th><th class="right">Subtotal</th><th class="right">IVA</th><th class="right">Total</th><th>PDF</th><th>Origen</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($notas)): ?><tr><td colspan="11" style="text-align:center;padding:18px;color:var(--text-muted);">No hay notas XML para este rango.</td></tr><?php endif; ?>
        <?php foreach ($notas as $n): ?>
        <tr>
            <td><?= htmlspecialchars($n['fecha_emision']) ?></td><td><?= htmlspecialchars($n['proveedor_nombre'] ?? '—') ?></td>
            <td style="font-family:monospace;white-space:nowrap;"><?= htmlspecialchars($n['consecutivo_completo']) ?></td><td><strong><?= htmlspecialchars($n['numero_factura_asistente']) ?></strong></td>
            <td><?= htmlspecialchars($n['moneda']) ?></td><td class="right"><?= number_format((float)$n['subtotal'],2) ?></td><td class="right"><?= number_format((float)$n['iva'],2) ?></td><td class="right"><strong><?= number_format((float)$n['total'],2) ?></strong></td>
            <td><?php if (!empty($n['ruta_pdf']) && is_file($n['ruta_pdf'])): ?><span class="badge badge-green">Disponible</span><?php else: ?><span class="badge" style="background:#fef3c7;color:#92400e;">Pendiente</span><?php endif; ?></td>
            <td><?= !empty($n['correo_cuenta_id']) ? 'Correo' : 'Carga XML' ?></td>
            <td style="white-space:nowrap;"><a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/notas-xml/ver/<?= (int)$n['id'] ?>" title="Detalle"><i class="fas fa-eye"></i></a> <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/documentos/xml/<?= (int)$n['id'] ?>" target="_blank" title="Ver XML"><i class="fas fa-code"></i></a><?php if (!empty($n['ruta_pdf']) && is_file($n['ruta_pdf'])): ?> <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/documentos/pdf/<?= (int)$n['id'] ?>" target="_blank" title="Ver PDF"><i class="fas fa-file-pdf"></i></a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (($paginas ?? 1) > 1): ?><div style="padding:8px;display:flex;justify-content:center;gap:6px;align-items:center;">
        <?php if ($pagina > 1): ?><a class="btn btn-outline btn-sm" href="?<?= $queryBase ?>&pagina=<?= $pagina-1 ?>">← Anterior</a><?php endif; ?><span style="font-size:12px;">Página <?= (int)$pagina ?> de <?= (int)$paginas ?></span><?php if ($pagina < $paginas): ?><a class="btn btn-outline btn-sm" href="?<?= $queryBase ?>&pagina=<?= $pagina+1 ?>">Siguiente →</a><?php endif; ?>
    </div><?php endif; ?>
</div>

<script>
/* La tarjeta del documento que se busca. Pintarla y avanzar lo hace app.js;
 * acá solo se contesta qué significa "buscar" en esta pantalla: poner el
 * número en el buscador y enviar el filtro.
 *
 * Al pasar al siguiente con las flechas NO se busca solo: la búsqueda recarga
 * la página, y recargar en cada flecha impediría recorrer la lista de un
 * vistazo. Se recorre con las flechas y se busca con la lupa. */
(function () {
    var tarjeta = document.querySelector('[data-navdoc]');
    if (!tarjeta) { return; }

    tarjeta.addEventListener('navdoc:buscar', function (evento) {
        var doc = evento.detail;
        var campo = document.querySelector('form.filter-bar input[name="buscar"]');
        var form = campo && campo.form ? campo.form : document.querySelector('form.filter-bar');
        if (!campo || !form) { return; }

        campo.value = doc.busqueda || doc.numero;
        // El contexto viaja con el envío para que la tarjeta siga ahí después
        // de recargar, apuntando al mismo documento.
        tarjeta.dataset.navdocParams.split('&').concat(['ctx_item=' + encodeURIComponent(doc.id)])
            .forEach(function (par) {
                if (!par) { return; }
                var trozo = par.split('=');
                var oculto = form.querySelector('input[type="hidden"][name="' + trozo[0] + '"]');
                if (!oculto) {
                    oculto = document.createElement('input');
                    oculto.type = 'hidden';
                    oculto.name = decodeURIComponent(trozo[0]);
                    form.appendChild(oculto);
                }
                oculto.value = decodeURIComponent(trozo.slice(1).join('=') || '');
            });

        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
        else { form.submit(); }
    });
})();
</script>
