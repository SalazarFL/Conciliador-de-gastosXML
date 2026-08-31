<?php
/**
 * La pregunta que sale antes de traer un listado grande: ¿de qué proveedor?
 *
 * Ocupa el lugar de la tabla, no se pone encima: mientras esto se ve, el
 * controlador no ha ejecutado la consulta del listado. Ese es el ahorro; si
 * solo escondiera la tabla no habría servido de nada.
 *
 * La regla de cuándo aparece vive en app/helpers/AlcanceProveedor.php.
 *
 * Uso desde una vista:
 *
 *   $elegirProv = [
 *       'accion'    => $baseUrl . '/facturas',
 *       'opciones'  => $proveedoresFiltro,
 *       'cuantos'   => $totalDocumentos,      // opcional, para el texto
 *       'que'       => 'comprobantes',        // cómo se llaman aquí
 *       'ocultos'   => ['listado_id' => 7],   // opcional
 *   ];
 *   include __DIR__ . '/../partials/elegir-proveedor.php';
 */

require_once __DIR__ . '/../../helpers/AlcanceProveedor.php';

$elegirCfg = array_merge([
    'accion'   => '',
    'opciones' => [],
    'cuantos'  => null,
    'que'      => 'documentos',
    'ocultos'  => [],
], is_array($elegirProv ?? null) ? $elegirProv : []);
?>
<div class="card elegir-prov">
    <div class="elegir-prov-icono" aria-hidden="true"><i class="fas fa-filter-circle-dollar"></i></div>

    <div class="elegir-prov-texto">
        <h2 class="elegir-prov-titulo">¿De qué proveedor?</h2>
        <p class="elegir-prov-detalle">
            <?php if ($elegirCfg['cuantos'] !== null && (int) $elegirCfg['cuantos'] > 0): ?>
            Aquí hay <strong><?= number_format((int) $elegirCfg['cuantos']) ?></strong>
            <?= htmlspecialchars((string) $elegirCfg['que']) ?>.
            <?php endif; ?>
            Elegí uno y se traen solo los suyos. La elección se recuerda: la próxima
            vez esta pantalla se abre directo, hasta que pulses <em>Limpiar</em>.
        </p>
    </div>

    <form method="get" action="<?= htmlspecialchars((string) $elegirCfg['accion']) ?>"
          class="elegir-prov-forma">
        <?php foreach ((array) $elegirCfg['ocultos'] as $claveOculta => $valorOculto): ?>
        <input type="hidden" name="<?= htmlspecialchars((string) $claveOculta) ?>"
               value="<?= htmlspecialchars((string) $valorOculto) ?>">
        <?php endforeach; ?>

        <?php
        // El mismo desplegable de la barra de filtros, para no aprender dos
        // controles distintos para lo mismo. Se envía solo al elegir.
        $provFiltro = [
            'valor'    => '',
            'opciones' => $elegirCfg['opciones'],
            'etiqueta' => '',
            'vacio'    => 'Elegí un proveedor…',
            // Sin el ancho de la barra de filtros: aquí no hay rejilla que
            // ocupar, el desplegable va al lado del botón.
            'ancho'    => false,
        ];
        include __DIR__ . '/filtro-proveedor.php';
        ?>

        <?php /*
         * Ver el listado entero sigue estando a un clic, pero hay que pedirlo.
         * Esa es toda la diferencia con antes: dejó de ser lo que pasaba solo.
         */ ?>
        <button type="submit" name="<?= AlcanceProveedor::PARAM ?>"
                value="<?= AlcanceProveedor::TODOS ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-list"></i> Ver todos
        </button>
    </form>
</div>
<?php unset($elegirCfg, $elegirProv); ?>
