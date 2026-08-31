<?php
/**
 * El contexto del documento buscado, escondido dentro de un formulario.
 *
 * Una barra de filtros se envía por GET, y en un GET lo que no está en el
 * formulario desaparece de la URL. Sin estos campos, escribir un criterio a
 * mano y pulsar Buscar borraba la tarjeta del documento que se venía
 * persiguiendo: se perdía el número, el proveedor y la posición en la lista
 * justo cuando más falta hacían, porque quien filtra a mano lo hace para
 * encontrar ESE documento.
 *
 * 'ctx_item' lo reescribe app.js cada vez que la tarjeta cambia de documento
 * con las flechas: si se enviara el que venía en la URL de entrada, buscar a
 * mano devolvería la tarjeta al principio del recorrido.
 *
 * Uso: include dentro del <form method="get"> de la barra de filtros.
 */
require_once __DIR__ . '/../../helpers/NavegacionDocumentos.php';

foreach (NavegacionDocumentos::contextoDeLaUrl($_GET) as $claveCtx => $valorCtx): ?>
<input type="hidden" name="<?= htmlspecialchars($claveCtx) ?>" value="<?= htmlspecialchars($valorCtx) ?>">
<?php endforeach;
unset($claveCtx, $valorCtx);
