<?php
/**
 * "Archivo perdido" y el botón para volver a bajarlo, en el mismo renglón.
 *
 * Aparece en todos los módulos que enseñan un documento, porque el problema
 * aparece en todos: la fila tiene ruta y al final de esa ruta ya no hay nada.
 * Enterarse y arreglarlo son el mismo gesto, así que la marca y el botón van
 * juntos donde uno está mirando, y no en otra pantalla.
 *
 * No pinta nada cuando el documento está completo: es una excepción, no una
 * columna más.
 *
 * Uso desde una vista, con la fila ya pasada por EstadoArchivo:
 *
 *   <?php $marcaArchivo = [
 *             'id' => (int) $fila['factura_xml_id'],
 *             'estado' => EstadoArchivo::de($fila),
 *         ];
 *         include __DIR__ . '/../partials/marca-archivo.php'; ?>
 */

$marcaEstado = is_array($marcaArchivo['estado'] ?? null) ? $marcaArchivo['estado'] : null;
$marcaId = (int) ($marcaArchivo['id'] ?? 0);
if ($marcaEstado === null || empty($marcaEstado['perdido']) || $marcaId <= 0) {
    return;
}
$marcaQueFalta = trim((string) ($marcaEstado['que_falta'] ?? ''));
?>
<span class="badge badge-perdido"
      title="El <?= htmlspecialchars($marcaQueFalta !== '' ? $marcaQueFalta : 'archivo') ?> de este documento se archivó y ya no está en la carpeta compartida">
    <i class="fas fa-link-slash"></i>
    <?= $marcaQueFalta !== '' ? 'Falta el ' . htmlspecialchars($marcaQueFalta) : 'Archivo perdido' ?>
</span>
<?php if (!empty($marcaEstado['recuperable'])): ?>
<button type="button" class="btn-recuperar" data-recuperar-doc="<?= $marcaId ?>"
        title="Volver a bajarlo del correo y dejarlo en su misma carpeta">
    <i class="fas fa-cloud-arrow-down"></i>
</button>
<?php endif; ?>
<?php unset($marcaEstado, $marcaId, $marcaQueFalta, $marcaArchivo); ?>
