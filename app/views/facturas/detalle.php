<?php
/**
 * Detalle de una factura electrónica.
 *
 * Se llega acá desde cuatro sitios —el listado de comprobantes, el checklist
 * del pago semanal, una devolución y las notas de crédito—, así que el botón
 * de arriba dice "Volver" y lleva a donde se venía, no a un listado fijo (ver
 * app/helpers/Retorno.php).
 *
 * El orden de la pantalla es el de las preguntas que se le hacen: de quién es,
 * cuánto es, qué documento es y dónde están sus archivos. Los montos van a la
 * derecha y en monospace para poder compararlos de un vistazo con lo que dice
 * el ERP, que es lo que se está haciendo cuando se abre esto.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$factura = $factura ?? [];
$retorno = $retorno ?? ['url' => $baseUrl . '/facturas', 'titulo' => 'Volver a Facturas XML'];

$estado = !empty($factura) ? EstadoArchivo::de($factura) : null;
$moneda = strtoupper(trim((string) ($factura['moneda'] ?? 'CRC'))) ?: 'CRC';
$simbolo = $moneda === 'CRC' ? '₡' : ($moneda === 'USD' ? '$' : '');

/** Etiqueta arriba, valor abajo: el patrón del resto de los detalles. */
$campo = function ($etiqueta, $valor, $mono = false) {
    $valor = trim((string) $valor);
    echo '<div><div style="font-size:10.5px;font-weight:700;color:var(--text-muted);'
       . 'text-transform:uppercase;letter-spacing:.03em;">' . htmlspecialchars($etiqueta) . '</div>'
       . '<div style="color:var(--navy);word-break:break-word;'
       . ($mono ? 'font-family:monospace;font-size:12px;' : '') . '">'
       . ($valor !== '' ? htmlspecialchars($valor) : '<span style="color:#cbd5e1;">—</span>')
       . '</div></div>';
};
?>

<div style="margin-bottom:12px;">
    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($retorno['url']) ?>"
       title="<?= htmlspecialchars($retorno['titulo']) ?>">
        <i class="fas fa-arrow-left"></i> Volver
    </a>
</div>

<?php if (empty($factura)): ?>
<div class="card" style="padding:14px;">Factura no disponible.</div>
<?php else: ?>

<div class="card" style="margin-bottom:10px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-file-invoice-dollar" style="color:var(--gold);margin-right:6px;"></i>
            Factura <?= htmlspecialchars(NumeroFactura::xmlOchoDigitos((string) ($factura['numero_factura_asistente'] ?? ''))) ?>
        </div>
        <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <?php
            /*
             * El mismo vocabulario de marcas que el listado. Que una factura se
             * describa de dos formas según la pantalla es exactamente lo que
             * hace dudar de si son la misma.
             */
            if (!empty($estado['perdido'])) {
                $marcaArchivo = ['id' => (int) $factura['id'], 'estado' => $estado];
                include __DIR__ . '/../partials/marca-archivo.php';
            } elseif (!empty($estado['xml_ok']) && !empty($estado['pdf_ok'])) { ?>
                <span class="badge badge-green"><i class="fas fa-check-circle"></i> XML + PDF</span>
            <?php } elseif (empty($estado['xml_ok']) && empty($estado['pdf_ok'])) { ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-triangle-exclamation"></i> Sin archivos</span>
            <?php } elseif (empty($estado['xml_ok'])) { ?>
                <span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-file-circle-xmark"></i> Falta XML</span>
            <?php } else { ?>
                <span class="badge" style="background:#fef3c7;color:#92400e;"><i class="fas fa-file-pdf"></i> Falta PDF</span>
            <?php } ?>
        </div>
    </div>

    <!-- De quién es y cuánto es: lo que se viene a ver. -->
    <div style="padding:11px 12px;display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;
                border-bottom:1px solid var(--border);">
        <div style="flex:1 1 260px;min-width:0;">
            <div style="font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;">Proveedor</div>
            <div style="font-size:15px;font-weight:700;color:var(--navy);word-break:break-word;">
                <?= htmlspecialchars((string) ($factura['proveedor_nombre'] ?? '')) ?: '<span style="color:#cbd5e1;">—</span>' ?>
            </div>
            <?php if (!empty($factura['proveedor_cedula'])): ?>
            <div style="font-size:12px;color:var(--text-muted);font-family:monospace;">
                Cédula <?= htmlspecialchars((string) $factura['proveedor_cedula']) ?>
            </div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;flex:0 0 auto;">
            <div style="font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;">Total</div>
            <div style="font-size:21px;font-weight:800;color:var(--navy);font-family:monospace;white-space:nowrap;">
                <?= $simbolo ?><?= number_format((float) ($factura['total'] ?? 0), 2) ?>
            </div>
            <div style="font-size:11.5px;color:var(--text-muted);white-space:nowrap;">
                Subtotal <?= number_format((float) ($factura['subtotal'] ?? 0), 2) ?>
                · IVA <?= number_format((float) ($factura['iva'] ?? 0), 2) ?>
                · <?= htmlspecialchars($moneda) ?>
            </div>
        </div>
    </div>

    <!-- Qué documento es -->
    <div style="padding:10px 12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));
                gap:9px;font-size:12.5px;">
        <?php
        $campo('Fecha de emisión', $factura['fecha_emision'] ?? '');
        $campo('Número asistente', NumeroFactura::xmlOchoDigitos((string) ($factura['numero_factura_asistente'] ?? '')), true);
        $campo('Consecutivo', $factura['consecutivo_completo'] ?? '', true);
        $campo('Clave', $factura['clave'] ?? '', true);
        $campo('Receptor', $factura['receptor_id'] ?? '', true);
        $campo('Estado del PDF', $factura['estado_pdf'] ?? 'pendiente');
        $campo('Llegó por correo el', $factura['fecha_correo'] ?? '');
        $campo('Archivada el', $factura['archivado_en'] ?? '');
        ?>
    </div>

    <!-- Dónde están sus archivos -->
    <div style="padding:0 12px 11px;">
        <div style="font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:3px;">Archivos</div>
        <div style="font-family:monospace;font-size:12px;color:var(--navy);word-break:break-all;">
            <i class="fas fa-code" style="width:14px;color:var(--text-muted);"></i>
            <?= htmlspecialchars((string) ($factura['archivo_xml'] ?? '')) ?: '—' ?>
        </div>
        <div style="font-family:monospace;font-size:12px;color:var(--navy);word-break:break-all;">
            <i class="fas fa-file-pdf" style="width:14px;color:var(--text-muted);"></i>
            <?= htmlspecialchars((string) ($factura['archivo_pdf'] ?? '')) ?: '<span style="font-family:inherit;color:var(--text-muted);">sin PDF</span>' ?>
        </div>

        <div style="display:flex;gap:7px;margin-top:10px;flex-wrap:wrap;">
            <?php if (!empty($estado['xml_ok'])): ?>
            <a class="btn btn-primary" target="_blank" rel="noopener"
               href="<?= $baseUrl ?>/documentos/xml/<?= (int) $factura['id'] ?>">
                <i class="fas fa-code"></i> Visualizar XML
            </a>
            <?php endif; ?>
            <?php if (!empty($estado['pdf_ok'])): ?>
            <a class="btn btn-outline" target="_blank" rel="noopener"
               href="<?= $baseUrl ?>/documentos/pdf/<?= (int) $factura['id'] ?>">
                <i class="fas fa-file-pdf"></i> Visualizar PDF
            </a>
            <?php endif; ?>
            <?php if (empty($estado['xml_ok']) && empty($estado['pdf_ok'])): ?>
            <span style="font-size:12.5px;color:var(--text-muted);align-self:center;">
                No hay archivos que abrir: la carpeta compartida no tiene ninguno de los dos.
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div style="font-size:11.5px;color:var(--text-muted);">
    Registro interno #<?= (int) $factura['id'] ?>
</div>

<?php endif; ?>
