<?php
/**
 * El botón "Notas aplicables" del renglón de una factura.
 *
 * Lo usan Seguimiento, Pagos y Facturas del sistema, en su propia columna. Antes la nota colgaba
 * como insignia debajo del número de la factura, apretada entre otras marcas;
 * ahora es siempre el mismo botón en el mismo sitio, y apagado cuando no hay
 * nada que ver, para que la columna se lea de arriba abajo de un vistazo.
 *
 * Abre en una ventana la lista de las notas que se le pueden aplicar a esta
 * factura: las directas y las de costo que la nombran, y las de cambio de su
 * proveedor (ver NotaCredito::notasAplicablesDeFacturas).
 *
 * Espera:
 *   $naFacturaId  id de la factura en facturas_erp
 *   $naDocumento  su número, para el título de la ventana
 *   $naCuantas    cuántas notas aplicables tiene
 *   $naSaldo      lo que suman sus saldos
 *   $baseUrl
 */
$naCuantas = (int) ($naCuantas ?? 0);
?>
<?php if ($naCuantas > 0 && (int) $naFacturaId > 0): ?>
<a href="<?= $baseUrl ?>/notas-credito/aplicables/<?= (int) $naFacturaId ?>"
   class="btn btn-sm btn-notas-aplicables"
   data-ventana="Notas aplicables"
   data-ventana-titulo="<?= htmlspecialchars((string) $naDocumento) ?>"
   title="<?= $naCuantas ?> nota(s) de crédito por ₡<?= number_format((float) $naSaldo, 2) ?> que se le pueden aplicar a esta factura. Si se paga completa, las notas quedan para otra factura del proveedor.">
    <i class="fas fa-file-circle-minus"></i> Notas aplicables
    <span class="btn-notas-aplicables-n"><?= $naCuantas ?></span>
</a>
<?php else: ?>
<button type="button" class="btn btn-sm btn-notas-aplicables" disabled
        title="Esta factura no tiene notas de crédito aplicables">
    <i class="fas fa-file-circle-minus"></i> Notas aplicables
</button>
<?php endif; ?>
