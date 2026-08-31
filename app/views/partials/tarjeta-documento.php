<?php
/**
 * La tarjeta del documento que se está buscando, igual en las tres pantallas
 * donde se busca: el correo, el listado de facturas XML y el de notas XML.
 *
 * Dice qué documento se busca —número, proveedor, sucursal, fecha, monto y si
 * ya tiene respaldo— y trae flechas para pasar al siguiente sin volver al
 * módulo del que se vino. Buscar cincuenta comprobantes seguidos sin esto es perder de
 * vista cuál se estaba buscando y cuántos faltan.
 *
 * El contexto lo arma NavegacionDocumentos y el comportamiento vive en app.js.
 * La pantalla que la incluye solo escucha dos eventos en el elemento:
 *
 *   navdoc:buscar   se pidió buscar el documento visible (detalle: el item)
 *   navdoc:cambia   se pasó a otro documento con las flechas
 *
 * Uso desde una vista:
 *
 *   <?php $navDoc = $navDoc ?? null;
 *         include __DIR__ . '/../partials/tarjeta-documento.php'; ?>
 */

$navDocCtx = is_array($navDoc ?? null) ? $navDoc : null;
if ($navDocCtx === null || empty($navDocCtx['items'])) {
    return;
}
?>
<div class="navdoc" id="navdoc"
     data-navdoc
     data-navdoc-params="<?= htmlspecialchars((string) $navDocCtx['params']) ?>"
     data-navdoc-idx="<?= (int) $navDocCtx['idx'] ?>"
     data-navdoc-total="<?= (int) ($navDocCtx['total'] ?? count($navDocCtx['items'])) ?>"
     data-navdoc-titulo="<?= htmlspecialchars((string) $navDocCtx['titulo']) ?>"
     data-navdoc-items="<?= htmlspecialchars(json_encode($navDocCtx['items'], JSON_UNESCAPED_UNICODE)) ?>">

    <div class="navdoc-linea">
        <span class="navdoc-punto" data-navdoc-punto title=""></span>
        <div class="navdoc-numero" data-navdoc-numero></div>
        <a class="navdoc-volver" href="<?= htmlspecialchars((string) $navDocCtx['volver']) ?>"
           title="Volver a <?= htmlspecialchars((string) $navDocCtx['titulo']) ?>">
            <i class="fas fa-arrow-turn-up fa-flip-horizontal"></i>
        </a>
        <button type="button" class="navdoc-cerrar" data-navdoc-cerrar title="Cerrar">&times;</button>
    </div>

    <div class="navdoc-proveedor" data-navdoc-proveedor></div>

    <?php
    /*
     * A qué sucursal llegó el documento —CEDI, BM Centro San Vito—. En
     * renglón propio y no pegada al proveedor porque el nombre ya se come el
     * ancho de la tarjeta, y ahí la sucursal quedaría siempre cortada.
     *
     * Nace escondido: hay documentos sin sucursal (la trae el reporte del
     * ERP) y un icono con nada al lado no informa de nada. Lo enciende app.js
     * cuando el documento visible tiene una.
     */
    ?>
    <div class="navdoc-sucursal" data-navdoc-sucursal hidden>
        <i class="fas fa-store"></i>
        <span data-navdoc-sucursal-texto></span>
    </div>

    <div class="navdoc-linea navdoc-cifras">
        <span data-navdoc-fecha></span>
        <span class="navdoc-monto" data-navdoc-monto></span>
    </div>

    <div class="navdoc-linea">
        <button type="button" class="btn btn-outline btn-sm navdoc-flecha" data-navdoc-prev
                title="Documento anterior">
            <i class="fas fa-arrow-left"></i>
        </button>
        <span class="navdoc-pos" data-navdoc-pos></span>
        <button type="button" class="btn btn-primary btn-sm navdoc-flecha" data-navdoc-buscar
                title="Buscar este documento">
            <i class="fas fa-magnifying-glass"></i>
        </button>
        <button type="button" class="btn btn-outline btn-sm navdoc-flecha" data-navdoc-next
                title="Documento siguiente">
            <i class="fas fa-arrow-right"></i>
        </button>
    </div>
</div>
<?php unset($navDocCtx); ?>
