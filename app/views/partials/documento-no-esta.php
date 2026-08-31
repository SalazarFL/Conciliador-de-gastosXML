<?php
/**
 * "Ese comprobante no está cargado", cuando se llegó buscando UNO.
 *
 * A estos listados se entra de dos maneras muy distintas. Casi siempre se
 * abren solos, a mirar; pero desde la cola de seguimiento y desde el pago
 * semanal se llega con una pregunta concreta —¿está cargado el XML de este
 * documento?— y con la tarjeta del documento arriba diciendo cuál.
 *
 * El buscador trae por coincidencia, que es lo que hace falta cuando uno se
 * acuerda de un pedazo del número: buscar 336 saca también 00336547 y otros
 * veintiocho. Pero entonces la lista NO contesta la pregunta: hay treinta
 * renglones y ninguno es el 336, y averiguarlo es leerlos uno por uno.
 *
 * Así que la respuesta va encima de la lista, con el botón de lo que sigue
 * —buscarlo en el correo—, y las coincidencias se quedan abajo por si sirven.
 *
 * Uso desde una vista, justo antes de la tabla:
 *
 *   <?php $docNoEsta = [
 *             'navDoc'        => $navDoc ?? null,
 *             'termino'       => $filtros['q'] ?? '',
 *             'cargado'       => $docBuscadoCargado ?? null,
 *             'hayResultados' => !empty($facturas),
 *         ];
 *         include __DIR__ . '/../partials/documento-no-esta.php'; ?>
 */

require_once __DIR__ . '/../../helpers/BusquedaDocumento.php';
require_once __DIR__ . '/../../helpers/NavegacionDocumentos.php';

$noEstaItem = NavegacionDocumentos::documentoBuscado(
    $docNoEsta['navDoc'] ?? null,
    $docNoEsta['termino'] ?? ''
);
if ($noEstaItem === null) {
    return;
}

$noEstaCargado = $docNoEsta['cargado'] ?? null;
$noEstaHay = !empty($docNoEsta['hayResultados']);

/*
 * Tres situaciones y solo una merece aviso:
 *
 *   cargado === true    está: la lista lo trae y no hay nada que decir
 *   cargado === false   no está, y se comprobó por su número
 *   cargado === null    no se pudo comprobar por número —una nota sin
 *                       consecutivo propio se busca por proveedor—, así que
 *                       solo se afirma cuando no hay ningún resultado
 */
if ($noEstaCargado === true || ($noEstaCargado === null && $noEstaHay)) {
    return;
}

$noEstaBusca = trim((string) $noEstaItem['busqueda']);
$noEstaNumero = trim((string) ($noEstaItem['numero'] ?? '')) ?: $noEstaBusca;
$noEstaPorNumero = BusquedaDocumento::esNumero($noEstaBusca);
$noEstaBase = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$noEstaCorreo = $noEstaBase . '/correo?buscar=' . urlencode($noEstaBusca)
    . '&' . (string) ($docNoEsta['navDoc']['params'] ?? '')
    . '&ctx_item=' . urlencode((string) ($noEstaItem['id'] ?? ''));
?>
<div class="doc-falta">
    <i class="fas fa-magnifying-glass-minus doc-falta-icono"></i>
    <div class="doc-falta-texto">
        <div class="doc-falta-titulo">Este comprobante no está cargado</div>
        <div class="doc-falta-detalle">
            <?php if ($noEstaPorNumero): ?>
            No hay ningún XML con el número
            <strong><?= htmlspecialchars($noEstaBusca) ?></strong><?php
            if ($noEstaNumero !== $noEstaBusca): ?>, que es el de
            <span class="doc-falta-doc"><?= htmlspecialchars($noEstaNumero) ?></span><?php
            endif; ?>.
            <?php else: ?>
            No aparece ningún XML a nombre de
            <strong><?= htmlspecialchars($noEstaBusca) ?></strong>.
            Se busca por proveedor porque esta nota no trae número propio: el
            del reporte es el de la factura que corrige.
            <?php endif; ?>
            <?php // Lo de abajo hay que explicarlo: son parecidos, no el que se buscaba. ?>
            <?php if ($noEstaHay): ?>
            Abajo están los que se le parecen.
            <?php else: ?>
            Si el proveedor lo mandó, todavía está en el correo.
            <?php endif; ?>
        </div>
    </div>
    <a class="btn btn-primary btn-sm doc-falta-accion"
       href="<?= htmlspecialchars($noEstaCorreo) ?>"
       data-ventana="Correo"
       data-ventana-titulo="<?= htmlspecialchars($noEstaNumero) ?>"
       title="Buscar este documento en el correo">
        <i class="fas fa-envelope-open-text"></i> Buscarlo en el correo
    </a>
</div>
<?php unset($noEstaItem, $noEstaCargado, $noEstaHay, $noEstaBusca, $noEstaNumero,
            $noEstaPorNumero, $noEstaBase, $noEstaCorreo, $docNoEsta); ?>
