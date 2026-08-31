<?php
/**
 * Seguimiento de documentos.
 *
 * Una sola cola con todo lo que le falta respaldo o no cuadra. Cada renglón
 * dice si tiene su XML y su PDF, cuánto dinero hay detrás y en qué va la
 * gestión. Las acciones se aplican a varios renglones a la vez porque el
 * trabajo real es en tandas, no de uno en uno.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';

// La situación de una nota frente a la factura que corrige: etiquetas y
// colores, los mismos que usan Facturas y Notas de crédito.
require_once __DIR__ . '/../../helpers/AplicacionNotaCredito.php';

/*
 * El modo: de qué va esta cola.
 *
 *   sistema  Los registros del ERP, preguntando qué les falta. La de siempre.
 *   correo   Los comprobantes XML cargados, preguntando lo contrario: cuáles
 *            no aparecen todavía en el ERP.
 *
 * La pantalla es la misma —mismas pestañas, misma barra de acciones, mismo
 * expediente— porque el trabajo es el mismo. Lo que cambia es qué preguntas
 * tienen respuesta: un comprobante XML no tiene sucursal, ni clase de nota,
 * ni saldo propio, así que esos controles no se dibujan en el modo Correo en
 * vez de dibujarse vacíos.
 */
$modoSeguimiento = Seguimiento::modo($modoSeguimiento ?? Seguimiento::MODO_SISTEMA);
$esModoCorreo    = $modoSeguimiento === Seguimiento::MODO_CORREO;
$origenes        = is_array($origenes ?? null) ? $origenes : Seguimiento::origenesDe($modoSeguimiento);
$llegadas        = is_array($llegadas ?? null) ? $llegadas : Seguimiento::LLEGADAS;

/*
 * Acá vivían dos mapas de insignias, uno para "Qué falta" y otro para
 * "Estado". Las dos columnas se retiraron porque repetían lo que el renglón ya
 * decía —los chips de Respaldo y la pestaña abierta—, así que sus colores e
 * iconos se fueron con ellas. Lo único propio que tenía "Qué falta", la
 * diferencia de monto, ahora cuelga del monto; el estado es la franja de
 * color del borde (.seg-marca, más abajo).
 */

/**
 * Conserva los filtros al cambiar de página o de pestaña.
 *
 * Con $paraContexto los filtros salen con el prefijo ctx_f_: así viajan a la
 * pantalla donde se busca el electrónico sin pisarle los suyos, que se llaman
 * igual —el 'q' de esta cola no es el buscador del listado de facturas—.
 */
$qs = function (array $cambios = [], $paraContexto = false) use ($filtros) {
    $base = array_filter([
        // El modo primero: sin él, cualquier enlace de esta pantalla —una
        // pestaña, una página, Exportar— devuelve a la cola del sistema.
        'modo'        => $filtros['modo'],
        'vista'       => $filtros['vista'],
        'origen'      => $filtros['origen'],
        'llegada'     => $filtros['llegada'] ?? '',
        'tarea'       => $filtros['tarea'],
        'marca'       => $filtros['marca'],
        'clase'       => $filtros['clase'],
        'aplicacion'  => $filtros['aplicacion'] ?? '',
        'responsable' => $filtros['responsable'],
        'proveedor'   => $filtros['proveedor'] ?? '',
        'sucursal'    => $filtros['sucursal'] ?? '',
        'contexto_id' => $filtros['contexto_id'] ?: '',
        'desde'       => $filtros['desde'],
        'hasta'       => $filtros['hasta'],
        'monto_min'   => $filtros['monto_min'],
        'condicion_saldo' => $filtros['condicion_saldo'],
        'q'           => $filtros['q'],
        'col_documento' => $filtros['col_documento'],
        'col_proveedor' => $filtros['col_proveedor'],
        'col_monto'     => $filtros['col_monto'],
        'col_saldo'     => $filtros['col_saldo'],
        'col_respaldo'  => $filtros['col_respaldo'],
        'col_tarea'     => $filtros['col_tarea'],
        'orden'       => $filtros['orden'],
    ], function ($v) { return $v !== '' && $v !== null; });

    if (!$paraContexto) {
        return http_build_query(array_merge($base, $cambios));
    }

    $conPrefijo = [];
    foreach ($base as $clave => $valor) {
        $conPrefijo['ctx_f_' . $clave] = $valor;
    }
    return http_build_query(array_merge($cambios, $conPrefijo));
};

$moneda = function ($valor, $mon = 'CRC') {
    return ($mon === 'USD' ? '$' : '₡') . number_format((float) $valor, 2);
};

// El día como se escribe acá, no como lo guarda la base.
$fechaDia = function ($valor) {
    $ts = $valor ? strtotime((string) $valor) : false;
    return $ts !== false ? date('d/m/Y', $ts) : '—';
};
?>

<?php /*
 * Acá vivía una fila de cinco tarjetas de situación. Tres de ellas
 * —Pendientes, En revisión, Listas— repetían exactamente el número que las
 * pestañas de abajo ya llevan al lado de su nombre, a dos centímetros de
 * distancia. Las otras dos —dinero en disputa, puestas a mano— eran datos de
 * lectura, no de trabajo: nadie hace nada distinto por verlos, y la cola se
 * ordena sola por dinero en juego.
 *
 * $resumen no se fue con ellas: es de donde salen las cuentas de cada pestaña.
 */ ?>
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Cola de trabajo</div>
        </div>
        <?php // Cada pestaña es un estado y lleva su cuenta; 'Todo' es la única
              // que no lo es, y por eso no muestra número. ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php
            /*
             * Las pestañas del modo. En Correo son tres y no cuatro: un
             * comprobante está en el sistema o no está, y no hay grado
             * intermedio que justifique un "Listas". La tercera —"En
             * seguimiento"— es la única a la que no se llega sola: ahí se
             * apartan a mano los que hay que perseguir, vengan de la pestaña
             * que vengan.
             */
            $segPestanas = $esModoCorreo ? [
                'pendiente' => ['fa-link-slash', 'Comprobantes cargados que todavía no aparecen en el sistema'],
                'revision'  => ['fa-magnifying-glass', 'Apartados a mano para perseguirlos, con el problema descrito'],
                'cerrada'   => ['fa-box-archive', 'Ya tienen su registro en el sistema: no hay nada que perseguir'],
                'todo'      => ['fa-layer-group', 'Todos, en cualquier estado'],
            ] : [
                'pendiente' => ['fa-inbox', 'Con saldo y algo que falta: XML, PDF o el monto no cuadra'],
                'revision'  => ['fa-magnifying-glass', 'Puestas a mano por alguien, con el problema descrito'],
                'lista'     => ['fa-circle-check', 'Con saldo y respaldo completo: listas para pagar o rebajar'],
                'cerrada'   => ['fa-box-archive', 'Sin saldo: ya se pagaron o se aplicaron'],
                'todo'      => ['fa-layer-group', 'Todas, en cualquier estado'],
            ];
            foreach ($segPestanas as $clave => [$icono, $ayuda]):
                $activa = $filtros['vista'] === $clave;
                $etiqueta = $clave === 'todo' ? 'Todo' : $estados[$clave];
                $cuenta = $clave === 'todo' ? null : (int) ($resumen[$clave] ?? 0); ?>
            <a href="?<?= $qs(['vista' => $clave, 'pagina' => 1]) ?>"
               class="btn <?= $activa ? 'btn-primary' : 'btn-outline' ?> btn-sm"
               title="<?= htmlspecialchars($ayuda) ?>"
               <?= $activa ? 'aria-current="page"' : '' ?>>
                <i class="fas <?= $icono ?>"></i> <?= htmlspecialchars($etiqueta) ?>
                <?php if ($cuenta !== null): ?>
                <span class="seg-cuenta"><?= number_format($cuenta) ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>

            <?php // Exportar baja lo que la cola tiene puesto en pantalla, así
                  // que va con ella y no en un encabezado aparte. Es el mismo
                  // lugar que ocupa en Facturas y en Pagos semanales. ?>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento/exportar?<?= $qs() ?>"
               style="margin-left:6px;" title="Bajar en CSV los renglones que muestran los filtros">
                <i class="fas fa-file-csv"></i> Exportar
            </a>
        </div>
    </div>

    <!-- ══ Filtros ══ -->
    <form class="filter-bar" id="seg-filter-form" method="get" action="<?= $baseUrl ?>/seguimiento">
        <input type="hidden" name="vista" value="<?= htmlspecialchars($filtros['vista']) ?>">
        <?php // Sin esto, apretar Filtrar sale del modo Correo y aterriza en
              // la cola del sistema con los filtros de la otra. ?>
        <input type="hidden" name="modo" value="<?= htmlspecialchars($modoSeguimiento) ?>">

        <?php
        /*
         * La barra contesta cinco preguntas y nada más: de quién, cuál, de
         * qué clase, de dónde y qué le falta. El proveedor va primero porque
         * es por donde empieza casi todo el trabajo —se persigue a un
         * proveedor, no a un rango de montos—.
         *
         * Salieron de aquí, y por qué:
         *
         *   Saldo (activas/canceladas)  Es la misma pregunta que la pestaña:
         *                               "cerrada" ES no tener saldo.
         *   Monto desde                 La cola se trabaja por fecha, no por
         *                               tramos de importe; el monto está en
         *                               su columna, a la vista.
         *   Desde / Hasta               En el modo Sistema la lista ya viene
         *                               acotada por el reporte cargado. En el
         *                               modo Correo sí están, más abajo: ahí
         *                               la cola es el archivo entero.
         *   Marca                       Sirve para auditar decisiones, no para
         *                               trabajar la cola.
         *   Listado                     El pago semanal tiene su propia
         *                               pantalla, con su propio checklist.
         *
         * Todas siguen funcionando si llegan por la URL: lo que se retiró es
         * el control, no el filtro.
         */
        $provFiltro = [
            'valor'    => $filtros['proveedor'] ?? '',
            'opciones' => $proveedoresFiltro ?? [],
        ]; include __DIR__ . '/../partials/filtro-proveedor.php';
        ?>

        <div class="filter-span-2">
            <label class="filter-label" for="f-q">Buscar</label>
            <input type="search" id="f-q" name="q" class="form-control"
                   value="<?= htmlspecialchars($filtros['q']) ?>"
                   placeholder="Documento, proveedor o consecutivo…">
        </div>

        <div>
            <label class="filter-label" for="f-origen">Tipo de documento</label>
            <?php // Las claves cambian con el modo —'factura' allá, 'xml_factura'
                  // acá— porque son tablas distintas; la etiqueta es la misma. ?>
            <select id="f-origen" name="origen" class="form-control">
                <option value="">Todos</option>
                <?php foreach ($origenes as $segOrigenClave => $segOrigenNombre): ?>
                <option value="<?= htmlspecialchars($segOrigenClave) ?>"
                    <?= $filtros['origen'] === $segOrigenClave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($segOrigenNombre) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php /*
         * Cómo llegó el comprobante. Solo en el modo Correo: un registro del
         * ERP no llegó por ningún lado, lo digitó alguien. Sirve para separar
         * lo que entró solo por el buzón de lo que alguien subió a mano, que
         * es donde suelen estar los que nadie registró después.
         */ ?>
        <?php if ($esModoCorreo): ?>
        <div>
            <label class="filter-label" for="f-llegada">Cómo llegó</label>
            <select id="f-llegada" name="llegada" class="form-control">
                <option value="">De cualquier forma</option>
                <?php foreach ($llegadas as $segLlegadaClave => $segLlegadaNombre): ?>
                <option value="<?= htmlspecialchars($segLlegadaClave) ?>"
                    <?= ($filtros['llegada'] ?? '') === $segLlegadaClave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($segLlegadaNombre) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php
        /*
         * La clase solo aparece cuando puede haber notas: mirando solo
         * facturas se esconde, porque una factura no tiene clase y dejarla
         * ahí solo ofrece una forma de vaciar la lista sin explicación. El
         * controlador tampoco la arrastra en ese caso.
         *
         * El control es el mismo de Notas de crédito; acá se ofrecen cuatro
         * clases y allá cinco, porque las de 'ajuste' no entran a esta cola.
         */
        ?>
        <?php /*
         * La clase de nota y la sucursal salen del reporte del ERP, no del
         * XML: en el modo Correo no hay ninguna de las dos que preguntar, y
         * dejarlas ahí vacías solo ofrecería dos formas de vaciar la lista.
         */ ?>
        <?php if (!$esModoCorreo): ?>
        <div id="f-clase-campo"<?= $filtros['origen'] === 'factura' ? ' hidden' : '' ?>>
            <?php $claseFiltro = [
                'valor'  => $filtros['clase'],
                'clases' => Seguimiento::CLASES,
                'id'     => 'f-clase',
            ]; include __DIR__ . '/../partials/filtro-clase.php'; ?>
        </div>

        <div>
            <label class="filter-label" for="f-sucursal">Sucursal</label>
            <select id="f-sucursal" name="sucursal" class="form-control">
                <option value="">Todas</option>
                <?php foreach (($sucursales ?? []) as $nombreSucursal => $cuantas): ?>
                <option value="<?= htmlspecialchars((string) $nombreSucursal) ?>"
                    <?= ($filtros['sucursal'] ?? '') === (string) $nombreSucursal ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $nombreSucursal) ?> (<?= number_format((int) $cuantas) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php
        /*
         * Los dos importes. Son los MISMOS parámetros que los buscadores de la
         * cabecera de la tabla —col_monto y col_saldo— a propósito: así hay
         * un solo filtro por concepto, se alcance desde donde se alcance.
         */
        $filtroImporte = [
            'nombre' => 'col_monto', 'etiqueta' => 'Monto',
            'valor' => $filtros['col_monto'] ?? '',
        ]; include __DIR__ . '/../partials/filtro-importe.php';

        $filtroImporte = [
            'nombre' => 'col_saldo', 'etiqueta' => 'Saldo',
            'valor' => $filtros['col_saldo'] ?? '',
        ]; include __DIR__ . '/../partials/filtro-importe.php';
        ?>

        <?php /*
         * 'completo' sí se ofrece: se quedaba fuera del desplegable y por eso
         * se podía preguntar qué le falta a un documento pero no cuáles no
         * tienen nada pendiente, que es la mitad que dice si el trabajo va
         * avanzando. Lleva su propio texto porque bajo el rótulo "Qué falta"
         * la etiqueta de siempre —"Respaldo completo"— se lee al revés.
         */ ?>
        <?php /*
         * En el modo Correo la pregunta es una sola y se lee mejor por su
         * nombre: no es "qué le falta al documento" sino "está en el sistema
         * o no". Las dos opciones son las dos mitades, y las dos hacen falta:
         * una dice qué queda por registrar, la otra si el trabajo avanza.
         */ ?>
        <div>
            <label class="filter-label" for="f-tarea">
                <?= $esModoCorreo ? 'Registro en el sistema' : 'Qué falta' ?>
            </label>
            <select id="f-tarea" name="tarea" class="form-control">
                <option value="">Cualquier cosa</option>
                <?php foreach ($tareas as $clave => $etiqueta): ?>
                <option value="<?= $clave ?>" <?= $filtros['tarea'] === $clave ? 'selected' : '' ?>>
                    <?= (!$esModoCorreo && $clave === 'completo')
                        ? 'Nada: ya está completo'
                        : htmlspecialchars($etiqueta) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php /*
         * Nota de crédito en juego.
         *
         * 'Con nota en juego' cruza los dos orígenes —notas que se pueden
         * aplicar y facturas que tienen nota esperando— porque son las dos
         * caras de lo mismo y quien trabaja la cola las necesita juntas.
         *
         * Las demás opciones son la situación de UNA NOTA, así que no se
         * ofrecen cuando se están mirando facturas: preguntarle a una factura
         * si está "lista para aplicar" no significa nada, y elegirlo vaciaba
         * la cola sin nada en pantalla que lo explicara. Es el mismo trato
         * que recibe el filtro de clase, por la misma razón.
         */
        $segSoloFacturas = ($filtros['origen'] ?? '') === 'factura';
        ?>
        <?php if (!$esModoCorreo): ?>
        <div>
            <label class="filter-label" for="f-aplicacion">Nota de crédito</label>
            <select id="f-aplicacion" name="aplicacion" class="form-control">
                <option value="">Cualquiera</option>
                <option value="con_nota" <?= ($filtros['aplicacion'] ?? '') === 'con_nota' ? 'selected' : '' ?>>
                    Con nota en juego
                </option>
                <?php foreach (AplicacionNotaCredito::ESTADOS as $segApValor => $segApInfo):
                    if (in_array($segApValor, [AplicacionNotaCredito::NO_APLICA, AplicacionNotaCredito::APLICADA], true)) {
                        continue;
                    } ?>
                <option value="<?= $segApValor ?>" data-solo-notas="1"
                        <?= $segSoloFacturas ? 'hidden disabled' : '' ?>
                        <?= ($filtros['aplicacion'] ?? '') === $segApValor ? 'selected' : '' ?>>
                    <?= htmlspecialchars($segApInfo[0]) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php /*
         * El rango de fechas, por fecha de emisión del comprobante.
         *
         * Solo en el modo Correo, y no por capricho: acá la cola es el archivo
         * entero de comprobantes cargados —once mil— y lo que se persigue
         * casi siempre es un período, "qué de agosto no se ha registrado". En
         * el modo Sistema la lista ya viene acotada por el reporte que se
         * cargó, y ahí el rango se retiró en su momento a favor de "Más
         * antiguo"; sigue funcionando si llega por la URL.
         */ ?>
        <?php if ($esModoCorreo): ?>
        <div>
            <label class="filter-label" for="f-desde">Emitido desde</label>
            <input type="date" id="f-desde" name="desde" class="form-control"
                   value="<?= htmlspecialchars((string) ($filtros['desde'] ?? '')) ?>">
        </div>
        <div>
            <label class="filter-label" for="f-hasta">Emitido hasta</label>
            <input type="date" id="f-hasta" name="hasta" class="form-control"
                   value="<?= htmlspecialchars((string) ($filtros['hasta'] ?? '')) ?>">
        </div>
        <?php endif; ?>

        <?php /*
         * Acá vivía el desplegable "Responsable". Salió de los dos modos junto
         * con el campo que lo llenaba: el responsable no es alguien a quien se
         * le teclea el nombre, es quien está usando el sistema. Ahora se pone
         * solo al mandar algo a revisión, así que preguntar por él en la barra
         * era ofrecer un filtro sobre un dato que nadie elegía.
         *
         * El filtro sigue entendiéndose si llega por la URL; lo que se retiró
         * es el control.
         */ ?>

        <div>
            <label class="filter-label" for="f-orden">Ordenar por</label>
            <select id="f-orden" name="orden" class="form-control">
                <?php // La lista la manda el modelo: ofrecer acá una opción que la
                      // consulta no sabe hacer daría un orden distinto del elegido. ?>
                <?php foreach (Seguimiento::ORDENES as $clave => $etiqueta): ?>
                <option value="<?= $clave ?>" <?= $filtros['orden'] === $clave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($etiqueta) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
            <?php // 'limpiar' hace que el servidor olvide los filtros guardados
                  // de esta pantalla: entrar sin criterios los devuelve. ?>
            <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento?modo=<?= htmlspecialchars($modoSeguimiento) ?>&amp;limpiar=1"><i class="fas fa-broom"></i> Limpiar</a>
        </div>
    </form>

    <!-- ══ Barra de acciones en tanda ══ -->
    <div id="acciones" class="seg-acciones" hidden>
        <div class="seg-acciones-cuenta">
            <i class="fas fa-check-double"></i>
            <strong id="acciones-n">0</strong> seleccionados
        </div>
        <?php
        // Mover algo al estado en el que ya está no hace nada, así que la
        // pestaña abierta no se ofrece como destino. En 'Todo' conviven los
        // cuatro estados, así que ahí se ofrecen todos.
        //
        // Revisión es la excepción y no desaparece: es el único diálogo que
        // lleva el motivo y el recordatorio, y sin él no habría forma de
        // cambiárselos a algo que ya está en revisión. Cambia de nombre para
        // que se lea como lo que hace.
        //
        // En el modo Correo son tres, no cuatro, y se llaman como las pestañas
        // a las que mandan: 'Marcar lista' no tiene destino ahí, y un botón
        // que apunta a una pestaña que no se dibuja deja renglones sin salida.
        $acciones = $esModoCorreo ? [
            'revision'  => ['btn-gold',    'fa-magnifying-glass', 'Poner en seguimiento'],
            'cerrada'   => ['btn-outline', 'fa-box-archive',      'Cerrar'],
            'pendiente' => ['btn-outline', 'fa-link-slash',       'A sin vincular'],
        ] : [
            'revision'  => ['btn-gold',    'fa-magnifying-glass', 'Mandar a revisión'],
            'lista'     => ['btn-success', 'fa-circle-check',     'Marcar lista'],
            'cerrada'   => ['btn-outline', 'fa-box-archive',      'Cerrar'],
            'pendiente' => ['btn-outline', 'fa-inbox',            'A pendientes'],
        ];
        if ($filtros['vista'] === 'revision') {
            $acciones['revision'][2] = 'Cambiar motivo o recordatorio';
        }
        ?>
        <div class="seg-acciones-btns">
            <?php foreach ($acciones as $clave => [$clase, $icono, $etiqueta]):
                if ($clave === $filtros['vista'] && $clave !== 'revision') { continue; } ?>
            <button type="button" class="btn <?= $clase ?> btn-sm" data-accion="<?= $clave ?>">
                <i class="fas <?= $icono ?>"></i> <?= htmlspecialchars($etiqueta) ?>
            </button>
            <?php endforeach; ?>
            <?php // Sin esto, lo puesto a mano no tendría salida: se quedaría
                  // fijo para siempre aunque los datos cambiaran. ?>
            <button type="button" class="btn btn-outline btn-sm" data-accion="auto">
                <i class="fas fa-rotate-left"></i> Quitar la marca
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="comentar">
                <i class="fas fa-comment-dots"></i> Anotar
            </button>
        </div>
    </div>

    <div class="filter-results">
        Mostrando <strong><?= number_format(count($filas)) ?></strong>
        de <strong><?= number_format((int) $paginacion['total']) ?></strong> renglones
        <?php if ($filtros['vista'] !== 'todo'): ?>
        · <span title="Las demás pestañas tienen lo suyo; 'Todo' las junta">
            en <?= htmlspecialchars(mb_strtolower($estados[$filtros['vista']])) ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- ══ Tabla ══ -->
    <div class="table-wrap">
        <table class="data-table seg-tabla">
            <thead>
                <?php /*
                 * Siete columnas. Dos de las tres que se habían retirado siguen
                 * fuera, porque no llevaban ningún dato que no estuviera ya en
                 * la fila:
                 *
                 *   Qué falta   Decía lo mismo que los chips de Respaldo en el
                 *               95,8 %: "Falta el XML" al lado de un chip XML
                 *               en rojo. Lo único suyo era "Diferencia de
                 *               monto" (4,2 %), que va bajo el monto.
                 *   Estado      Lo elige la pestaña, así que lo repetía en
                 *               cada fila. Pasó a ser la franja de color del
                 *               borde izquierdo; en "Todo", donde conviven los
                 *               cuatro, además se escribe.
                 *
                 * SALDO VOLVIÓ. Se había plegado bajo el monto porque en el
                 * 45,8 % de los renglones era el mismo número, y así solo se
                 * escribía cuando de verdad difería. Pero el otro 54,2 % es
                 * justo el trabajo de esta cola —lo que todavía se debe— y
                 * plegado no se podía recorrer con la vista, ni ordenar, ni
                 * comparar en paralelo con el monto. Un número que se lee en
                 * columna vale más que el ancho que ahorra.
                 */ ?>
                <tr class="seg-head-labels">
                    <th style="width:34px;" class="center"
                        title="Marcá una y, con Shift, otra más abajo: se marcan todas las del medio.">
                        <input type="checkbox" id="chk-todos" aria-label="Seleccionar todos">
                    </th>
                    <th>Documento</th>
                    <th>Proveedor</th>
                    <th class="right">Monto</th>
                    <th class="right" title="<?= $esModoCorreo
                        ? 'Lo que queda debiendo el registro del sistema al que está enganchado este comprobante'
                        : 'Lo que queda debiendo el documento' ?>">Saldo</th>
                    <th class="center">Respaldo</th>
                    <th style="width:1%;"></th>
                </tr>
                <tr class="seg-search-row">
                    <th class="center" title="Filtros por columna"><i class="fas fa-search"></i></th>
                    <th><input form="seg-filter-form" name="col_documento"
                               value="<?= htmlspecialchars($filtros['col_documento']) ?>"
                               placeholder="Buscar" aria-label="Buscar en documento"></th>
                    <?php // El proveedor y la sucursal se eligen arriba, en la
                          // barra, con su buscador propio: repetir aquí un
                          // campo de texto para lo mismo solo permite pedir
                          // dos cosas distintas a la vez. ?>
                    <th></th>
                    <?php // Cada una con la suya: cuando compartían columna las dos
                          // casillas iban apretadas, una al lado de la otra. ?>
                    <th>
                        <input form="seg-filter-form" name="col_monto" inputmode="decimal"
                               value="<?= htmlspecialchars($filtros['col_monto']) ?>"
                               placeholder="Monto" aria-label="Buscar monto">
                    </th>
                    <th>
                        <input form="seg-filter-form" name="col_saldo" inputmode="decimal"
                               value="<?= htmlspecialchars($filtros['col_saldo']) ?>"
                               placeholder="Saldo" aria-label="Buscar saldo">
                    </th>
                    <th>
                        <select form="seg-filter-form" name="col_respaldo" aria-label="Filtrar respaldo">
                            <option value="">Todos</option>
                            <option value="completo" <?= $filtros['col_respaldo'] === 'completo' ? 'selected' : '' ?>>Completo</option>
                            <option value="sin_xml" <?= $filtros['col_respaldo'] === 'sin_xml' ? 'selected' : '' ?>>Sin XML</option>
                            <option value="sin_pdf" <?= $filtros['col_respaldo'] === 'sin_pdf' ? 'selected' : '' ?>>Sin PDF</option>
                        </select>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filas)): ?>
                <tr class="empty-row">
                    <td colspan="7">
                        <div style="font-size:34px;color:var(--ok);margin-bottom:8px;">
                            <i class="fas fa-circle-check"></i>
                        </div>
                        <strong style="color:var(--navy);font-size:15px;">
                            <?= $esModoCorreo
                                ? 'No hay comprobantes con estos filtros'
                                : 'No hay nada pendiente con estos filtros' ?>
                        </strong>
                        <div style="margin-top:4px;">Probá con otra pestaña o quitá algún filtro.</div>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($filas as $f):
                $clave = $f['origen'] . '|' . $f['referencia_id'];
                $esNc = in_array($f['origen'], ['nota_credito', 'xml_nota'], true);

                /*
                 * El enganche con el sistema: lo único que el modo Correo
                 * viene a contestar, así que va en la fila y no escondido en
                 * el expediente. Con registro se dice cuál —para poder ir a
                 * verlo—; sin registro se dice que no hay, que es el renglón
                 * que hay que trabajar.
                 */
                $sinSistema = $esModoCorreo && ($f['tarea'] ?? '') !== 'completo';
                $docSistema = $esModoCorreo ? trim((string) ($f['sistema_doc'] ?? '')) : '';
                $estadoNombre = $estados[$f['seguimiento_estado']] ?? $f['seguimiento_estado'];
                /*
                 * En una pestaña de un solo estado, escribirlo en cada renglón
                 * es repetir el título del botón que ya está apretado. La
                 * franja de color queda igual —es la que deja ver de un
                 * vistazo cuál se salió del molde—, y el nombre solo se escribe
                 * en "Todo", que es donde conviven los cuatro.
                 */
                $mostrarEstado = ($filtros['vista'] ?? '') === 'todo';

                /*
                 * No tener saldo y no saberlo son cosas distintas, y la columna
                 * las dice distinto. NULL solo llega en el modo Correo, de un
                 * comprobante que todavía no está enganchado a ningún registro
                 * del sistema: ahí no hay un cero, hay una pregunta sin
                 * contestar, y escribir "sin saldo" diría que ya se pagó.
                 */
                $sinSaldoConocido = $f['saldo'] === null || $f['saldo'] === '';
                $sinSaldo = !$sinSaldoConocido && abs((float) $f['saldo']) < 0.005;
                $hayDif = $f['diferencia'] !== null && (float) $f['diferencia'] != 0.0;

                /*
                 * La nota de crédito en juego, mirada desde los dos lados: si
                 * el renglón es una nota, contra qué factura se descuenta; si
                 * es una factura, cuánta nota tiene esperando. Es lo mismo que
                 * sale en Facturas y en Notas de crédito, y tiene que estar
                 * aquí porque esta es la cola donde se trabaja.
                 */
                $segApl = (string) ($f['aplicacion_estado'] ?? '');
                $segColor = $segApl !== '' ? AplicacionNotaCredito::color($segApl) : 'neutro';
                $segEstilo = $segColor === 'ok'
                    ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;'
                    : 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;';
                $notaEnJuego = $esNc && $segColor !== 'neutro';
                $facturaConNota = !$esNc && (int) ($f['notas_vivas'] ?? 0) > 0;

                // La tercera línea solo existe si alguien puso algo encima del
                // renglón. Hoy eso es raro; cuando pase, no se puede esconder.
                $hayTrabajo = !empty($f['responsable']) || !empty($f['recordar_en'])
                    || (int) $f['anotaciones'] > 0 || !empty($f['estado_a_mano']);

                // El emparejador explica por qué no encontró el comprobante.
                // Antes ocupaba dos renglones de texto cortado en la columna
                // "Qué falta"; ahora cuelga del chip que la explicación es.
                $porQueNo = trim((string) ($f['motivo_match'] ?? ''));
            ?>
                <tr data-clave="<?= htmlspecialchars($clave) ?>"
                    data-origen="<?= htmlspecialchars($f['origen']) ?>"
                    data-ref="<?= (int) $f['referencia_id'] ?>"
                    class="seg-e-<?= htmlspecialchars($f['seguimiento_estado']) ?><?= $f['vencido'] ? ' seg-vencido' : '' ?>">

                    <td class="center seg-marca" title="<?= htmlspecialchars($estadoNombre) ?>">
                        <input type="checkbox" class="chk-fila" value="<?= htmlspecialchars($clave) ?>"
                               aria-label="Seleccionar <?= htmlspecialchars($f['documento']) ?>">
                    </td>

                    <td>
                        <div class="seg-doc">
                            <span class="badge badge-<?= $esNc ? 'navy' : 'gold' ?>" style="font-size:10px;">
                                <?= $esNc ? 'NC' : 'FE' ?>
                            </span>
                            <?php // Cabe entero: el más largo de los 7.131 mide
                                  // 36 caracteres y el corte estaba en 32. ?>
                            <strong style="font-family:ui-monospace,monospace;font-size:12.5px;">
                                <?= htmlspecialchars(mb_strimwidth((string) $f['documento'], 0, 44, '…')) ?>
                            </strong>
                        </div>

                        <div class="seg-sub">
                            <?= htmlspecialchars($fechaDia($f['fecha'])) ?>
                            <?php if ($esNc && !empty($f['clase'])): ?>
                            · <span title="Clase de nota deducida del formato del número"><?= htmlspecialchars($f['clase']) ?></span>
                            <?php endif; ?>
                            <?php /*
                             * El contexto solo se enseña en las facturas, donde
                             * dice a qué pago semanal entró y eso cambia de una
                             * a otra. En las notas es el listado del que salen,
                             * y como el módulo mantiene un único acumulado, los
                             * 1.935 renglones traían la misma frase: ocupaba
                             * media sub-línea para no distinguir nada. Se sigue
                             * pudiendo buscar por él.
                             */ ?>
                            <?php if (!$esNc && !$esModoCorreo && !empty($f['contexto'])): ?>
                            · <?= htmlspecialchars(mb_strimwidth((string) $f['contexto'], 0, 30, '…')) ?>
                            <?php endif; ?>
                            <?php if ($esModoCorreo): ?>
                            · <span title="Cómo entró este comprobante">
                                <?= ($f['llegada'] ?? '') === 'correo' ? 'Correo' : 'Carga XML' ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($mostrarEstado): ?>
                            · <span class="seg-estado-txt"><?= htmlspecialchars($estadoNombre) ?></span>
                            <?php endif; ?>

                            <?php /*
                             * El enganche. Es la respuesta del modo Correo, y
                             * por eso va en la primera sub-línea y no colgando
                             * del monto: se recorre la lista buscando los que
                             * dicen que no.
                             */ ?>
                            <?php if ($esModoCorreo && $sinSistema): ?>
                            <span class="badge seg-badge-mini"
                                  style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;"
                                  title="Este comprobante está cargado pero no aparece en ningún registro del ERP">
                                <i class="fas fa-link-slash" style="margin-right:3px;"></i>
                                Sin registro en el sistema
                            </span>
                            <?php elseif ($esModoCorreo && $docSistema !== ''): ?>
                            <a class="badge seg-badge-mini"
                               style="background:#dcfce7;color:#166534;border:1px solid #86efac;"
                               href="<?= $baseUrl ?><?= $esNc ? '/notas-credito?buscar=' : '/facturas-erp?q=' ?><?= urlencode($docSistema) ?>"
                               data-ventana="<?= $esNc ? 'Notas de crédito' : 'Facturas del sistema' ?>"
                               data-ventana-titulo="<?= htmlspecialchars($docSistema) ?>"
                               title="Está registrado en el sistema como <?= htmlspecialchars($docSistema) ?>. Clic para abrirlo.">
                                <i class="fas fa-link" style="margin-right:3px;"></i>
                                <?= htmlspecialchars(mb_strimwidth($docSistema, 0, 26, '…')) ?>
                            </a>
                            <?php elseif ($esModoCorreo): ?>
                            <span class="badge seg-badge-mini"
                                  style="background:#dcfce7;color:#166534;border:1px solid #86efac;"
                                  title="Está registrado en el sistema, pero ese registro no imprime número de documento">
                                <i class="fas fa-link" style="margin-right:3px;"></i> En el sistema
                            </span>
                            <?php endif; ?>

                            <?php /*
                             * La insignia va en esta misma línea y sin el
                             * número de la factura: en los 915 renglones que la
                             * llevan, ese número ya está dentro del documento
                             * de la nota, tres centímetros más arriba. Sigue en
                             * el tooltip y en el enlace.
                             */ ?>
                            <?php if ($notaEnJuego): ?>
                                <?php if (!empty($f['aplicacion_factura_id'])): ?>
                                <a href="<?= $baseUrl ?>/facturas-erp?q=<?= urlencode((string) $f['aplicacion_factura_doc']) ?>"
                                   data-ventana="Facturas del sistema"
                                   data-ventana-titulo="<?= htmlspecialchars((string) $f['aplicacion_factura_doc']) ?>"
                                   class="badge seg-badge-mini" style="<?= $segEstilo ?>"
                                   title="<?= htmlspecialchars(AplicacionNotaCredito::etiqueta($segApl)) ?>. Su factura: <?= htmlspecialchars((string) $f['aplicacion_factura_doc']) ?>, saldo <?= number_format((float) $f['aplicacion_factura_saldo'], 2) ?>. Clic para abrirla.">
                                    <?= htmlspecialchars(AplicacionNotaCredito::etiqueta($segApl)) ?>
                                </a>
                                <?php else: ?>
                                <span class="badge seg-badge-mini" style="<?= $segEstilo ?>">
                                    <?= htmlspecialchars(AplicacionNotaCredito::etiqueta($segApl)) ?>
                                </span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($facturaConNota): ?>
                            <a href="<?= $baseUrl ?>/notas-credito?factura_erp_id=<?= (int) $f['referencia_id'] ?>"
                               data-ventana="Notas de crédito"
                               data-ventana-titulo="<?= htmlspecialchars((string) $f['documento']) ?>"
                               class="badge seg-badge-mini"
                               style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;"
                               title="Esta factura tiene <?= (int) $f['notas_vivas'] ?> nota(s) de crédito sin aplicar. Si se paga completa, la nota queda para otra factura del proveedor.">
                                <i class="fas fa-file-circle-minus" style="margin-right:3px;"></i>
                                Nota<?= (int) $f['notas_vivas'] > 1 ? ' ×' . (int) $f['notas_vivas'] : '' ?>
                                · <?= number_format((float) $f['notas_vivas_saldo'], 2) ?>
                            </a>
                            <?php endif; ?>
                        </div>

                        <?php // Tercera línea: solo si alguien puso algo encima. ?>
                        <?php if ($hayTrabajo): ?>
                        <div class="seg-sub">
                            <?php if (!empty($f['estado_a_mano'])): ?>
                                <span class="seg-mano" title="Alguien lo puso aquí a mano; no se mueve solo">
                                    <i class="fas fa-hand"></i>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($f['responsable'])): ?>
                                <span><i class="fas fa-user" style="opacity:.5;"></i> <?= htmlspecialchars($f['responsable']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($f['recordar_en'])):
                                // Ya pasado el momento, la fecha sobra: lo que
                                // importa es que está esperando desde hace rato.
                                $cada = (int) $f['recordar_cada'];
                                $insiste = $cada > 0 ? ' · insiste cada ' . $cada . ($cada === 1 ? ' día' : ' días') : '';
                            ?>
                                <span style="<?= $f['vencido'] ? 'color:var(--miss);font-weight:700;' : '' ?>"
                                      title="Recordatorio<?= htmlspecialchars($insiste) ?>">
                                    <i class="fas fa-clock" style="opacity:.5;"></i>
                                    <?= htmlspecialchars(substr((string) $f['recordar_en'], 0, 16)) ?>
                                    <?php if ($f['vencido']): ?>· avisando<?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ((int) $f['anotaciones'] > 0): ?>
                                <span><i class="fas fa-comment-dots" style="opacity:.5;"></i> <?= (int) $f['anotaciones'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php // El aviso no cambia nada: dice qué pasó y deja que
                              // una persona decida si mover el renglón o no. ?>
                        <?php if (!empty($f['desajustada'])): ?>
                        <div class="seg-desajuste"
                             title="Los datos cambiaron después de que alguien lo puso aquí">
                            <i class="fas fa-triangle-exclamation"></i>
                            Le tocaría <strong><?= htmlspecialchars(mb_strtolower(
                                $estados[$f['estado_calculado']] ?? $f['estado_calculado'])) ?></strong>
                        </div>
                        <?php endif; ?>

                        <?php // El motivo es obligatorio al mandar a revisión, así
                              // que es lo primero que hay que poder leer sin abrir nada.
                              // Fuera de revisión no se muestra: describe un enredo que
                              // ya no aplica, y sigue guardado en la bitácora. ?>
                        <?php if (!empty($f['motivo']) && $f['seguimiento_estado'] === 'revision'): ?>
                        <div class="seg-motivo" title="<?= htmlspecialchars((string) $f['motivo']) ?>">
                            <?= htmlspecialchars(mb_strimwidth((string) $f['motivo'], 0, 70, '…')) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php // Con el ancho que soltaron las tres columnas que se
                              // fueron, el nombre entra: se cortaba en 3 de cada 10. ?>
                        <?= htmlspecialchars(mb_strimwidth((string) $f['proveedor'], 0, 46, '…')) ?>
                        <?php if (!empty($f['sucursal'])): ?>
                        <div class="seg-sub"><?= htmlspecialchars((string) $f['sucursal']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="right">
                        <strong><?= $moneda($f['monto'], $f['moneda']) ?></strong>
                        <?php // Lo único que decía "Qué falta" y no estaba ya en
                              // los chips. Va acá, pegado al número del que habla:
                              // la diferencia es contra el monto, no contra el saldo. ?>
                        <?php if ($hayDif): ?>
                        <div class="seg-sub seg-dif" title="El comprobante no coincide con el monto del documento">
                            dif. <?= $moneda(abs((float) $f['diferencia']), $f['moneda']) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <?php /*
                     * El saldo, en su propia columna. Vivía colgado del monto y
                     * solo se escribía cuando los dos números diferían, que es
                     * poco más de la mitad de las veces; para leerlos en
                     * paralelo —cuánto se facturó, cuánto queda— hacían falta
                     * dos columnas.
                     *
                     * Tres cosas distintas que decir, y ninguna es la otra:
                     *   sin saldo   se pagó o se aplicó; el número es cero
                     *   —           no hay contra qué mirarlo: en el modo
                     *               Correo, el comprobante no está enganchado
                     *               a ningún registro del sistema
                     *   la cifra    lo que queda debiendo
                     */ ?>
                    <td class="right">
                        <?php if ($sinSaldoConocido): ?>
                        <span style="color:#cbd5e1;" title="<?= $esModoCorreo
                            ? 'No está enganchado a ningún registro del sistema: no hay saldo que mirar'
                            : 'Este documento no trae saldo' ?>">—</span>
                        <?php elseif ($sinSaldo): ?>
                        <span class="seg-saldo-cero" title="Ya se pagó o se aplicó">sin saldo</span>
                        <?php else: ?>
                        <strong><?= $moneda($f['saldo'], $f['moneda']) ?></strong>
                        <?php endif; ?>
                    </td>

                    <!-- Las dos piezas que tiene que tener todo documento. Estos
                         chips son, además, la respuesta a "qué falta": la columna
                         que lo repetía con palabras se retiró. -->
                    <td class="center">
                        <div class="seg-respaldo">
                            <?php if ($f['xml_ok']): ?>
                            <a class="seg-chip seg-chip-ok" target="_blank"
                               href="<?= $baseUrl ?>/documentos/xml/<?= (int) $f['factura_xml_id'] ?>"
                               data-ventana="XML"
                               data-ventana-titulo="<?= htmlspecialchars((string) $f['documento']) ?>"
                               title="Ver el XML">
                                <i class="fas fa-code"></i> XML
                            </a>
                            <?php elseif ($f['xml_perdido']): ?>
                            <span class="seg-chip seg-chip-perdido"
                                  title="El XML se archivó y ya no está en la carpeta compartida">
                                <i class="fas fa-link-slash"></i> XML
                            </span>
                            <?php else: ?>
                            <span class="seg-chip seg-chip-no"
                                  title="<?= $porQueNo !== ''
                                      ? htmlspecialchars($porQueNo)
                                      : 'No hay XML para este documento' ?>">
                                <i class="fas fa-code"></i> XML
                            </span>
                            <?php endif; ?>

                            <?php if ($f['pdf_ok']): ?>
                            <a class="seg-chip seg-chip-ok" target="_blank"
                               href="<?= $baseUrl ?>/documentos/pdf/<?= (int) $f['factura_xml_id'] ?>"
                               data-ventana="PDF"
                               data-ventana-titulo="<?= htmlspecialchars((string) $f['documento']) ?>"
                               title="Ver el PDF">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <?php elseif ($f['pdf_perdido']): ?>
                            <span class="seg-chip seg-chip-perdido"
                                  title="El PDF se archivó y ya no está en la carpeta compartida">
                                <i class="fas fa-link-slash"></i> PDF
                            </span>
                            <?php elseif ($f['pdf_historico']): ?>
                            <span class="seg-chip seg-chip-hist" title="Marcado como histórico: no va a existir">
                                <i class="fas fa-file-pdf"></i> PDF
                            </span>
                            <?php else: ?>
                            <span class="seg-chip seg-chip-no" title="Falta el PDF">
                                <i class="fas fa-file-pdf"></i> PDF
                            </span>
                            <?php endif; ?>

                            <?php
                            /*
                             * El archivo estaba y ya no está, pero el correo
                             * del que salió sí sigue: se vuelve a bajar y se
                             * deja en la misma ruta. Solo se repone si el
                             * contenido da la misma huella que se archivó.
                             */
                            if (!empty($f['recuperable'])): ?>
                            <button type="button" class="seg-chip seg-chip-recuperar"
                                    data-recuperar-doc="<?= (int) $f['factura_xml_id'] ?>"
                                    title="Volver a bajarlo del correo y dejarlo en su carpeta">
                                <i class="fas fa-cloud-arrow-down"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="white-space:nowrap;">
                        <?php
                        /*
                         * Buscar el electrónico de este documento, en los dos
                         * sitios donde puede estar: el correo, si todavía no
                         * entró al sistema, o los comprobantes ya cargados, si
                         * entró pero no se enganchó con este documento.
                         *
                         * Las dos llevan el contexto de la cola —el renglón y
                         * los filtros puestos— para que la pantalla destino
                         * muestre la tarjeta y deje pasar al siguiente sin
                         * volver acá.
                         *
                         * Sin término no hay búsqueda que ofrecer: pasa en una
                         * nota sin consecutivo propio y sin nombre de proveedor.
                         */
                        if (!$f['xml_ok'] && $f['busqueda'] !== ''):
                            $ctxItem = $f['origen'] . ':' . (int) $f['referencia_id'];
                            $ctxQuery = $qs(['ctx' => 'seguimiento', 'ctx_item' => $ctxItem], true);
                            $esNota = $f['origen'] === 'nota_credito';
                            $destinoXml = $esNota ? '/notas-xml' : '/facturas';
                            /*
                             * El término va además en el buscador propio del
                             * destino —'buscar' en el correo, 'q' o 'buscar' en
                             * los listados— para llegar con el resultado en
                             * pantalla y no a una lista entera con la tarjeta
                             * encima. Los filtros de esta cola viajan aparte,
                             * con prefijo, y no lo pisan.
                             */
                            $termino = urlencode((string) $f['busqueda']);
                            $urlCorreo = $baseUrl . '/correo?buscar=' . $termino
                                . ($f['busqueda_fecha'] !== '' ? '&amp;fecha=' . urlencode((string) $f['busqueda_fecha']) : '')
                                . '&amp;' . $ctxQuery;
                            $urlXml = $baseUrl . $destinoXml . '?'
                                . ($esNota ? 'buscar=' : 'q=') . $termino . '&amp;' . $ctxQuery;
                        ?>
                        <?php /*
                         * data-ventana: el correo se abre en un marco grande
                         * sobre esta pantalla, con su buscador y su tarjeta de
                         * "documento que se busca" funcionando. Antes era otra
                         * pestaña, y con treinta renglones son treinta idas y
                         * treinta vueltas a encontrar dónde se iba.
                         */ ?>
                        <a class="btn btn-outline btn-sm"
                           href="<?= $urlCorreo ?>"
                           data-ventana="Correo"
                           data-ventana-titulo="<?= htmlspecialchars((string) $f['documento']) ?>"
                           target="_blank"
                           title="<?= $f['busqueda_por'] === 'proveedor'
                               ? 'Buscarlo en el correo. La nota no trae número propio: se busca por proveedor alrededor de su fecha'
                               : 'Buscar este documento en el correo' ?>">
                            <i class="fas fa-envelope-open-text"></i>
                        </a>
                        <a class="btn btn-outline btn-sm"
                           href="<?= $urlXml ?>"
                           data-ventana="<?= $esNota ? 'Notas XML' : 'Comprobantes XML' ?>"
                           data-ventana-titulo="<?= htmlspecialchars((string) $f['documento']) ?>"
                           target="_blank"
                           title="Buscarlo entre los comprobantes XML ya cargados">
                            <i class="fas fa-file-code"></i>
                        </a>
                        <?php endif; ?>
                        <?php /*
                         * En el modo Correo no hay nada que buscar —el renglón
                         * ES el comprobante—, así que los dos botones de
                         * arriba no se dibujan. Lo que sí hace falta es
                         * abrirlo: su ficha, con las líneas y el detalle del
                         * XML, está en el listado del que salió.
                         */ ?>
                        <?php if ($esModoCorreo && !empty($f['ver_ruta'])): ?>
                        <a class="btn btn-outline btn-sm" target="_blank"
                           href="<?= $baseUrl . htmlspecialchars((string) $f['ver_ruta']) ?>"
                           data-ficha="<?= (int) $f['referencia_id'] ?>"
                           title="Ver la ficha de este comprobante">
                            <i class="fas fa-file-lines"></i>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline btn-sm js-detalle" title="Ver expediente">
                            <i class="fas fa-list-ul"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) $paginacion['paginas'] > 1): ?>
    <div style="padding:14px;display:flex;justify-content:center;gap:10px;align-items:center;">
        <?php if ($paginacion['pagina'] > 1): ?>
        <a class="btn btn-outline btn-sm" href="?<?= $qs(['pagina' => $paginacion['pagina'] - 1]) ?>">← Anterior</a>
        <?php endif; ?>
        <span style="font-size:12px;color:var(--text-muted);">
            Página <?= (int) $paginacion['pagina'] ?> de <?= (int) $paginacion['paginas'] ?>
        </span>
        <?php if ($paginacion['pagina'] < $paginacion['paginas']): ?>
        <a class="btn btn-outline btn-sm" href="?<?= $qs(['pagina' => $paginacion['pagina'] + 1]) ?>">Siguiente →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ Panel de expediente ═══════════════════════════════════════════════ -->
<div id="panel" class="seg-panel" hidden>
    <div class="seg-panel-fondo" data-cerrar></div>
    <aside class="seg-panel-caja" role="dialog" aria-modal="true" aria-labelledby="panel-titulo">
        <header class="seg-panel-cab">
            <div>
                <div id="panel-titulo" class="card-title">Expediente</div>
                <div class="card-subtitle" id="panel-sub">—</div>
            </div>
            <button type="button" class="seg-panel-x" data-cerrar aria-label="Cerrar">&times;</button>
        </header>
        <div class="seg-panel-cuerpo" id="panel-cuerpo"></div>
    </aside>
</div>

<!-- ══ Diálogo de acción ═════════════════════════════════════════════════ -->
<div id="dialogo" class="seg-panel" hidden>
    <div class="seg-panel-fondo" data-cerrar-dialogo></div>
    <div class="seg-dialogo" role="dialog" aria-modal="true" aria-labelledby="dlg-titulo">
        <header class="seg-panel-cab">
            <div class="card-title" id="dlg-titulo">Acción</div>
            <button type="button" class="seg-panel-x" data-cerrar-dialogo aria-label="Cerrar">&times;</button>
        </header>
        <div style="padding:11px 13px;">
            <p id="dlg-texto" style="font-size:12.5px;color:var(--text-muted);margin-bottom:9px;"></p>

            <?php // Frecuencia y hora: un recordatorio que insiste hasta que el
                  // documento salga de revisión. Aparece la primera vez que
                  // alguien abre la aplicación pasada esa hora. ?>
            <div class="form-group" id="dlg-campo-recordatorio" hidden>
                <label class="form-label" for="dlg-cada">Recordármelo</label>
                <div style="display:flex;gap:8px;">
                    <select id="dlg-cada" class="form-control" style="flex:2;">
                        <option value="0">Sin recordatorio</option>
                        <option value="1">Cada día</option>
                        <option value="3">Cada 3 días</option>
                        <option value="7" selected>Cada semana</option>
                        <option value="15">Cada 15 días</option>
                        <option value="30">Cada mes</option>
                    </select>
                    <input type="time" id="dlg-hora" class="form-control" style="flex:1;"
                           value="08:00" aria-label="Hora del recordatorio">
                </div>
                <div id="dlg-recordatorio-nota" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                    El primer aviso sale en una semana a las 08:00, y vuelve a salir
                    cada semana mientras siga en revisión.
                </div>
            </div>

            <?php /*
             * Acá se pedía teclear el responsable. No hacía falta: el
             * responsable de lo que se manda a revisión es quien lo manda, y
             * eso el sistema ya lo sabe. Ahora lo pone el servidor con el
             * nombre de quien tiene la sesión abierta, así que no hay nada
             * que escribir ni forma de escribir el nombre de otro.
             */ ?>

            <div class="form-group" id="dlg-campo-motivo" hidden>
                <label class="form-label" for="dlg-motivo">Cuál es el problema</label>
                <input type="text" id="dlg-motivo" class="form-control" maxlength="255" required
                       placeholder="Ej.: el proveedor mandó el XML con otro monto y no contesta">
            </div>

            <div class="form-group">
                <label class="form-label" for="dlg-comentario">
                    Anotación <span style="font-weight:400;color:var(--text-muted);">(queda en la bitácora)</span>
                </label>
                <textarea id="dlg-comentario" class="form-control" rows="3"
                          placeholder="Ej.: se le escribió a contabilidad del proveedor el 12/08"></textarea>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-outline" data-cerrar-dialogo>Cancelar</button>
                <button type="button" class="btn btn-primary" id="dlg-confirmar">
                    <i class="fas fa-check"></i> Aplicar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Seguimiento ─────────────────────────────────────────────────────── */
.seg-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; display: flex;
           gap: 7px; align-items: center; flex-wrap: wrap; }
.seg-tabla tbody tr.seg-vencido { background: #FFF6F6; }
.seg-tabla tbody tr.seg-vencido:hover { background: #FFEDED; }
.seg-tabla tbody tr.seg-sel { background: #EEF4FF; }
/* Siete columnas: el saldo volvió a tener la suya. */
.seg-tabla { min-width: 1100px; }
.seg-tabla td { vertical-align: top; }

/* El número y su tipo, en la primera línea del renglón */
.seg-doc { display: flex; align-items: center; gap: 7px; }

/*
 * El estado, que era una columna repitiendo el nombre de la pestaña abierta.
 * Como franja no ocupa ancho y se lee de un vistazo bajando la mirada por el
 * borde, que es justo lo que uno hace buscando el renglón raro. El nombre
 * sigue estando: en el title de la celda, y escrito en la pestaña "Todo".
 */
.seg-marca { box-shadow: inset 3px 0 0 0 var(--border); }
.seg-tabla tbody tr.seg-e-pendiente .seg-marca { box-shadow: inset 3px 0 0 0 var(--warn); }
.seg-tabla tbody tr.seg-e-revision  .seg-marca { box-shadow: inset 3px 0 0 0 var(--gold); }
.seg-tabla tbody tr.seg-e-lista     .seg-marca { box-shadow: inset 3px 0 0 0 var(--ok); }
.seg-tabla tbody tr.seg-e-cerrada   .seg-marca { box-shadow: inset 3px 0 0 0 #CBD5E1; }
.seg-estado-txt { font-weight: 700; color: var(--navy); }

/* Insignia de la nota en juego: va dentro de la sub-línea, no en un renglón propio */
.seg-badge-mini {
  font-size: 9.5px; padding: 1px 6px; text-decoration: none; white-space: nowrap;
}

/* La diferencia cuelga del monto, y solo cuando dice algo */
.seg-sub.seg-dif { justify-content: flex-end; color: var(--diff); font-weight: 700; }

/* Saldo cero: se escribe con palabras, en gris, para que no compita con las
   cifras de al lado —lo que se busca recorriendo la columna es dónde queda
   algo, no dónde ya no—. */
.seg-saldo-cero { font-size: 11px; color: var(--text-muted); }
.seg-tabla .seg-search-row th { padding: 5px 4px; background: #F8FAFC; }
.seg-tabla .seg-search-row input,
.seg-tabla .seg-search-row select {
  width: 100%; min-width: 78px; height: 24px; padding: 2px 5px;
  border: 1px solid #CBD5E1; border-radius: 5px; background: #FFF;
  color: var(--navy); font-size: 10.5px; outline: none;
}
.seg-tabla .seg-search-row input:focus,
.seg-tabla .seg-search-row select:focus {
  border-color: var(--gold); box-shadow: 0 0 0 2px rgba(212,160,23,.14);
}
.seg-tabla .seg-search-row .fa-search { color: var(--text-muted); font-size: 11px; }

/* Cuenta de cada pestaña, dentro del propio botón */
.seg-cuenta {
  display: inline-block; margin-left: 5px; padding: 0 5px;
  border-radius: 8px; font-size: 10.5px; font-weight: 700;
  background: rgba(12,36,97,.10); color: inherit;
}
.btn-primary .seg-cuenta { background: rgba(255,255,255,.22); }

/* La manita: lo puso una persona, no el cálculo */
.seg-mano { margin-left: 4px; font-size: 10.5px; color: var(--text-muted); }

/* El aviso de que la marca a mano se quedó atrás */
.seg-desajuste {
  margin-top: 3px; font-size: 11px; font-weight: 600;
  color: #8F1A1A; display: flex; gap: 5px; align-items: center;
}
.seg-motivo {
  margin-top: 3px; font-size: 11px; color: var(--text); font-style: italic;
  border-left: 2px solid var(--gold); padding-left: 6px;
}

.seg-respaldo { display: inline-flex; gap: 4px; }
.seg-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 6px;
  font-size: 10.5px; font-weight: 700; letter-spacing: .02em;
  text-decoration: none; border: 1px solid transparent; white-space: nowrap;
}
.seg-chip-ok   { background: var(--ok-bg);   color: var(--ok);   border-color: #A8E8DA; }
.seg-chip-ok:hover { background: #BFF3E7; color: var(--ok); }
.seg-chip-no   { background: var(--miss-bg); color: var(--miss); border-color: #F5C2C2;
                 opacity: .75; text-decoration: line-through; }
.seg-chip-hist { background: #EDEFF3; color: #6B7280; border-color: #DDE1E8; }
/* Se archivó y ya no está: no es lo mismo que no haberlo tenido nunca, así
   que no va tachado —hay algo que reponer, no algo que conseguir—. */
.seg-chip-perdido { background: #FEF3C7; color: #92400E; border-color: #FDE68A; }
.seg-chip-recuperar {
  background: var(--navy); color: #fff; border-color: var(--navy);
  cursor: pointer; font-family: inherit;
}
.seg-chip-recuperar:hover:not(:disabled) { background: var(--navy-light); border-color: var(--navy-light); }
.seg-chip-recuperar:disabled { opacity: .6; cursor: progress; }

.seg-acciones {
  display: flex; align-items: center; justify-content: space-between; gap: 9px;
  flex-wrap: wrap; padding: 8px 14px; margin: 0 -16px;
  background: linear-gradient(90deg, rgba(12,36,97,.06), rgba(240,165,0,.06));
  border-bottom: 1px solid var(--border-light);
}
.seg-acciones-cuenta { font-size: 13px; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.seg-acciones-cuenta strong { font-size: 16px; }
.seg-acciones-btns { display: flex; gap: 6px; flex-wrap: wrap; }

/**
 * El atributo hidden se apoya en la regla [hidden]{display:none} que trae el
 * navegador, y esa regla pierde contra cualquier selector de clase. Sin esta
 * línea, el display:flex de .seg-panel y .seg-acciones gana: los paneles nacen
 * abiertos encima de la tabla y el botón de cerrar no hace nada, porque poner
 * el atributo desde JavaScript no cambia lo que se ve.
 * La comprueba tests/VistaOcultosTest.php.
 */
.seg-panel[hidden], .seg-acciones[hidden] { display: none !important; }

/* Panel lateral y diálogo comparten el fondo oscurecido */
.seg-panel { position: fixed; inset: 0; z-index: 1200; display: flex; }
.seg-panel-fondo { position: absolute; inset: 0; background: rgba(8,24,71,.45); }
.seg-panel-caja {
  position: relative; margin-left: auto; width: min(560px, 100%);
  background: var(--card); box-shadow: var(--shadow-lg);
  display: flex; flex-direction: column; height: 100%;
  animation: segEntra .18s ease-out;
}
@keyframes segEntra { from { transform: translateX(28px); opacity: .4; } to { transform: none; opacity: 1; } }
.seg-panel-cab {
  display: flex; align-items: center; justify-content: space-between; gap: 9px;
  padding: 9px 13px; background: #FAFCFF; border-bottom: 1px solid var(--border-light);
}
.seg-panel-x { background: none; border: none; font-size: 26px; line-height: 1;
               color: var(--text-muted); cursor: pointer; padding: 0 4px; }
.seg-panel-x:hover { color: var(--navy); }
.seg-panel-cuerpo { padding: 11px 13px; overflow-y: auto; flex: 1; }

.seg-dialogo {
  position: relative; margin: auto; width: min(480px, calc(100% - 32px));
  background: var(--card); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
  max-height: calc(100vh - 40px); overflow-y: auto;
}

.seg-datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
             gap: 8px; margin-bottom: 11px; }
.seg-dato-et { font-size: 10px; font-weight: 700; letter-spacing: .08em;
               text-transform: uppercase; color: var(--text-muted); }
.seg-dato-v  { font-size: 13px; color: var(--navy); font-weight: 600; word-break: break-word; }

.seg-linea { display: flex; gap: 8px; padding: 7px 0; border-bottom: 1px solid var(--border-light); }
.seg-linea:last-child { border-bottom: none; }
.seg-linea-punto { width: 8px; height: 8px; border-radius: 50%; background: var(--gold);
                   margin-top: 6px; flex-shrink: 0; }
.seg-linea-cuerpo { flex: 1; min-width: 0; }
.seg-linea-cab { font-size: 12px; color: var(--navy); font-weight: 700; }
.seg-linea-fecha { font-size: 11px; color: var(--text-light); }
.seg-linea-texto { font-size: 12.5px; color: var(--text); margin-top: 3px; white-space: pre-wrap; }

@media (max-width: 900px) {
  .seg-acciones { flex-direction: column; align-items: stretch; }
  .seg-acciones-btns .btn { flex: 1; justify-content: center; }
}

</style>

<script>
(function () {
    'use strict';
    var BASE = <?= json_encode($baseUrl) ?>;
    // El modo viaja en cada llamada: el servidor decide con él qué estados
    // son válidos y de qué tablas sale el expediente.
    var MODO = <?= json_encode($modoSeguimiento) ?>;
    var ES_CORREO = MODO === 'correo';

    var tabla       = document.querySelector('.seg-tabla');
    var chkTodos    = document.getElementById('chk-todos');
    var barra       = document.getElementById('acciones');
    var contador    = document.getElementById('acciones-n');
    var panel       = document.getElementById('panel');
    var dialogo     = document.getElementById('dialogo');
    var filterForm  = document.getElementById('seg-filter-form');
    // Los filtros de esta cola, prefijados, para los enlaces de "buscar el
    // electrónico" que arma el expediente. Los del renglón se escriben en el
    // HTML; estos son los mismos para todos.
    var CTX_COLA = <?= json_encode($qs(['ctx' => 'seguimiento'], true)) ?>;

    /*
     * La clase de nota se esconde al pasar el tipo de documento a "Facturas",
     * en el acto y sin esperar a enviar: si se quedara puesta, la lista
     * saldría vacía —una factura no tiene clase— y nada lo explicaría.
     *
     * El desplegable en sí lo maneja app.js, que es de donde sale el mismo
     * control en Notas de crédito. Acá solo se decide cuándo tiene sentido.
     *
     * Va antes del `if (!tabla)`: la barra de filtros existe aunque la cola
     * venga sin renglones, que es justo cuando hace falta poder cambiarla.
     */
    (function () {
        var campo  = document.getElementById('f-clase-campo');
        var origen = document.getElementById('f-origen');
        if (!campo || !origen) { return; }

        origen.addEventListener('change', function () {
            var esFactura = origen.value === 'factura';
            campo.hidden = esFactura;
            if (esFactura) {
                window.filtroClaseVaciar(campo.querySelector('[data-clase-picker]'));
            }
        });
    })();

    /*
     * Las situaciones de una nota no se le preguntan a una factura.
     *
     * El desplegable no desaparece entero porque una de sus opciones —"Con
     * nota en juego"— sí es una pregunta sobre facturas, y es justamente la
     * que sirve antes de pagar: cuáles de las que voy a pagar traen una nota
     * esperando. Lo que se va son las opciones que solo tienen sentido del
     * lado de la nota.
     */
    (function () {
        var origen = document.getElementById('f-origen');
        var aplicacion = document.getElementById('f-aplicacion');
        if (!origen || !aplicacion) { return; }

        var soloNotas = Array.prototype.slice.call(
            aplicacion.querySelectorAll('[data-solo-notas]')
        );

        function ajustar() {
            var esFactura = origen.value === 'factura';
            soloNotas.forEach(function (opcion) {
                opcion.hidden = esFactura;
                opcion.disabled = esFactura;
                // Si la que estaba elegida deja de valer, se vuelve a
                // "Cualquiera" en vez de dejar la cola vacía sin explicación.
                if (esFactura && opcion.selected) { aplicacion.value = ''; }
            });
        }

        origen.addEventListener('change', ajustar);
        ajustar();
    })();

    if (!tabla) { return; }

    // Los filtros pequeños del encabezado pertenecen al formulario superior
    // mediante el atributo `form`. Al escribir, se consulta de nuevo toda la
    // cola para que el resultado y la paginación sigan diciendo la verdad.
    if (filterForm) {
        var columnFilters = Array.prototype.slice.call(tabla.querySelectorAll('.seg-search-row input, .seg-search-row select'));
        var filterTimer = null;
        columnFilters.forEach(function (control) {
            var evento = control.tagName === 'SELECT' ? 'change' : 'input';
            control.addEventListener(evento, function () {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(function () { filterForm.requestSubmit(); }, evento === 'change' ? 0 : 450);
            });
        });
    }

    function casillas() {
        return Array.prototype.slice.call(tabla.querySelectorAll('.chk-fila'));
    }
    function seleccion() {
        return casillas().filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }

    function refrescarBarra() {
        var n = seleccion().length;
        contador.textContent = n;
        barra.hidden = n === 0;
        casillas().forEach(function (c) {
            c.closest('tr').classList.toggle('seg-sel', c.checked);
        });
        if (chkTodos) {
            var todas = casillas();
            chkTodos.checked = todas.length > 0 && n === todas.length;
            chkTodos.indeterminate = n > 0 && n < todas.length;
        }
    }

    /* Shift + clic marca de golpe todo lo que hay entre la última casilla que
     * se tocó y esta. La cola se trabaja en tandas —veinte comprobantes del
     * mismo proveedor, una semana entera— y de a un clic es fácil saltarse
     * uno sin notarlo. Ctrl no hace falta: un clic suelto ya suma o quita uno
     * sin tocar los demás. El comportamiento vive en app.js porque lo
     * comparte con las listas del correo. */
    var rango = window.AppSeleccionRango
        ? window.AppSeleccionRango(tabla, '.chk-fila', refrescarBarra)
        : null;

    if (chkTodos) {
        chkTodos.addEventListener('change', function () {
            casillas().forEach(function (c) { c.checked = chkTodos.checked; });
            // El ancla del rango apuntaba a una casilla que esto acaba de
            // pisar: el siguiente Shift se mediría contra algo que nadie tocó.
            if (rango) { rango.olvidarAncla(); }
            refrescarBarra();
        });
    }
    tabla.addEventListener('change', function (e) {
        if (e.target.classList.contains('chk-fila')) { refrescarBarra(); }
    });

    // ── Acciones en tanda ────────────────────────────────────────────────
    //
    // Todas menos 'comentar' ponen una marca a mano, que manda sobre el
    // cálculo y no se mueve sola. 'auto' es la que la quita.
    var ACCIONES = ES_CORREO ? {
        revision:  {
            titulo: 'Poner en seguimiento',
            texto: 'Quedan apartados para perseguirlos, estén o no en el sistema. Hay que decir cuál es el problema; quedan a tu nombre.',
            motivo: true, recordatorio: true
        },
        cerrada:   {
            titulo: 'Cerrar',
            texto: 'Salen del trabajo del día, aunque todavía no aparezcan en el sistema.'
        },
        pendiente: {
            titulo: 'Devolver a sin vincular',
            texto: 'Vuelven a la cola de lo que falta registrar, aunque el sistema ya los tenga.'
        },
        auto:      {
            titulo: 'Quitar la marca',
            texto: 'Dejan de estar puestos a mano: cada uno vuelve a donde le toque según esté o no en el sistema.'
        },
        comentar:  {
            titulo: 'Anotar',
            texto: 'Deja constancia en la bitácora sin cambiar el estado.',
            estado: null
        }
    } : {
        revision:  {
            titulo: 'Mandar a revisión',
            texto: 'Quedan apartados hasta que alguien los saque. Hay que decir cuál es el problema; quedan a tu nombre.',
            motivo: true, recordatorio: true
        },
        lista:     {
            titulo: 'Marcar como listas',
            texto: 'Quedan como respaldadas y sin diferencias, aunque los datos digan otra cosa.'
        },
        cerrada:   {
            titulo: 'Cerrar',
            texto: 'Salen del trabajo del día. Para lo que ya no se debe o no va a existir nunca.'
        },
        pendiente: {
            titulo: 'Devolver a pendientes',
            texto: 'Vuelven a la cola de trabajo, tengan saldo o no.'
        },
        auto:      {
            titulo: 'Quitar la marca',
            texto: 'Dejan de estar puestos a mano: cada uno vuelve al estado que le toque por su saldo y su respaldo.'
        },
        comentar:  {
            titulo: 'Anotar',
            texto: 'Deja constancia en la bitácora sin cambiar el estado.',
            estado: null
        }
    };
    var accionActual = null;
    var itemsActuales = [];

    barra.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-accion]');
        if (!btn) { return; }
        abrirDialogo(btn.dataset.accion, seleccion());
    });

    function abrirDialogo(accion, items) {
        var cfg = ACCIONES[accion];
        if (!cfg || !items.length) { return; }
        accionActual = accion;
        itemsActuales = items;

        document.getElementById('dlg-titulo').textContent = cfg.titulo + ' (' + items.length + ')';
        document.getElementById('dlg-texto').textContent = cfg.texto;
        document.getElementById('dlg-campo-recordatorio').hidden = !cfg.recordatorio;
        document.getElementById('dlg-campo-motivo').hidden = !cfg.motivo;
        document.getElementById('dlg-comentario').value = '';
        document.getElementById('dlg-motivo').value = '';

        dialogo.hidden = false;
        // Mandar a revisión es el único cambio de estado que pide explicar:
        // los demás son decisiones que se ven solas en la propia fila.
        document.getElementById(cfg.motivo ? 'dlg-motivo' : 'dlg-comentario').focus();
    }

    function cerrarDialogo() { dialogo.hidden = true; accionActual = null; }
    dialogo.addEventListener('click', function (e) {
        if (e.target.closest('[data-cerrar-dialogo]')) { cerrarDialogo(); }
    });

    document.getElementById('dlg-confirmar').addEventListener('click', function () {
        var cfg = ACCIONES[accionActual];
        if (!cfg) { return; }

        // Se comprueba acá además de en el servidor: avisar después de mandar
        // cincuenta renglones es hacer perder el trabajo de escribirlos.
        var motivo = document.getElementById('dlg-motivo');
        if (cfg.motivo && motivo.value.trim() === '') {
            AppDialog.alert('Escribí cuál es el problema antes de mandarlo a revisión.',
                            { title: 'Falta la descripción', type: 'warning' });
            motivo.focus();
            return;
        }

        var cuerpo = new URLSearchParams();
        cuerpo.append('modo', MODO);
        itemsActuales.forEach(function (i) { cuerpo.append('items[]', i); });

        // `estado: null` en la configuración significa "no tocar el estado".
        var estado = Object.prototype.hasOwnProperty.call(cfg, 'estado') ? cfg.estado : accionActual;
        if (estado) { cuerpo.append('estado', estado); }
        if (cfg.recordatorio) {
            cuerpo.append('recordar_cada', document.getElementById('dlg-cada').value);
            cuerpo.append('recordar_hora', document.getElementById('dlg-hora').value);
        }
        if (cfg.motivo) { cuerpo.append('motivo', document.getElementById('dlg-motivo').value); }
        cuerpo.append('comentario', document.getElementById('dlg-comentario').value);

        var boton = this;
        boton.disabled = true;
        boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando…';

        fetch(BASE + '/seguimiento/actualizar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: cuerpo.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { throw new Error(d.message || 'No se pudo guardar.'); }
            window.location.reload();
        })
        .catch(function (err) {
            AppDialog.alert(err.message, { title: 'No se pudo guardar', type: 'danger' });
            boton.disabled = false;
            boton.innerHTML = '<i class="fas fa-check"></i> Aplicar';
        });
    });

    // El texto de abajo dice lo que va a pasar de verdad; si no se actualiza al
    // cambiar los campos, miente en cuanto alguien toca el desplegable.
    (function () {
        var cada = document.getElementById('dlg-cada');
        var hora = document.getElementById('dlg-hora');
        var nota = document.getElementById('dlg-recordatorio-nota');
        if (!cada || !hora || !nota) { return; }

        var PLAZOS = {
            '1':  ['mañana', 'todos los días'],
            '3':  ['en 3 días', 'cada 3 días'],
            '7':  ['en una semana', 'cada semana'],
            '15': ['en 15 días', 'cada 15 días'],
            '30': ['en un mes', 'cada mes']
        };

        function refrescar() {
            var plazo = PLAZOS[cada.value];
            if (!plazo) {
                nota.textContent = 'No va a avisar nada: el renglón queda en revisión sin recordatorio.';
                return;
            }
            nota.textContent = 'El primer aviso sale ' + plazo[0] + ' a las ' + (hora.value || '08:00')
                + ', y vuelve a salir ' + plazo[1] + ' mientras siga en revisión.';
        }
        cada.addEventListener('change', refrescar);
        hora.addEventListener('change', refrescar);
        refrescar();
    })();

    // ── Expediente ───────────────────────────────────────────────────────
    tabla.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-detalle');
        if (!btn) { return; }
        var tr = btn.closest('tr');
        abrirPanel(tr.dataset.origen, tr.dataset.ref);
    });

    panel.addEventListener('click', function (e) {
        if (e.target.closest('[data-cerrar]')) { panel.hidden = true; }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (!dialogo.hidden) { cerrarDialogo(); } else if (!panel.hidden) { panel.hidden = true; }
    });

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    var ESTADOS = <?= json_encode($estados, JSON_UNESCAPED_UNICODE) ?>;
    var TAREAS  = <?= json_encode($tareas, JSON_UNESCAPED_UNICODE) ?>;

    function abrirPanel(origen, ref) {
        panel.hidden = false;
        document.getElementById('panel-cuerpo').innerHTML =
            '<div style="text-align:center;padding:22px;color:var(--text-muted);">' +
            '<i class="fas fa-spinner fa-spin fa-2x"></i></div>';

        fetch(BASE + '/seguimiento/detalle?origen=' + encodeURIComponent(origen)
              + '&referencia_id=' + encodeURIComponent(ref)
              + '&modo=' + encodeURIComponent(MODO))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { throw new Error(d.message || 'No se pudo abrir el expediente.'); }
            pintarPanel(d.fila, d.bitacora);
        })
        .catch(function (err) {
            document.getElementById('panel-cuerpo').innerHTML =
                '<div class="badge badge-miss">' + esc(err.message) + '</div>';
        });
    }

    function pintarPanel(f, bitacora) {
        document.getElementById('panel-titulo').textContent = f.documento;
        var esNotaDoc = f.origen === 'nota_credito' || f.origen === 'xml_nota';
        document.getElementById('panel-sub').textContent =
            (esNotaDoc ? 'Nota de crédito' : 'Factura')
            + (f.contexto ? ' · ' + f.contexto : '');

        var plata = function (v) {
            return (f.moneda === 'USD' ? '$' : '₡') +
                   Number(v || 0).toLocaleString('es-CR', { minimumFractionDigits: 2 });
        };

        /*
         * El expediente enseña lo que el renglón tiene, no un molde fijo. Un
         * comprobante XML no trae saldo propio, ni diferencia, ni clase de
         * nota: esas cuatro filas saldrían en '—' las 2.817 veces. En su
         * lugar va lo que sí contesta este modo —si está en el sistema, con
         * qué documento, y cómo llegó—, que es lo que se vino a mirar.
         */
        var datos = ES_CORREO ? [
            ['Proveedor', f.proveedor],
            ['Fecha', f.fecha],
            ['Monto', plata(f.monto)],
            // Sin enganche no hay saldo que mirar; un cero diría que ya se pagó.
            ['Saldo', f.saldo === null ? '—' : plata(f.saldo)],
            ['En el sistema', f.tarea === 'completo' ? 'Sí' : 'No'],
            ['Documento del sistema', f.sistema_doc || '—'],
            ['Cómo llegó', f.llegada === 'correo' ? 'Por correo' : 'Carga a mano'],
            ['Estado', (ESTADOS[f.seguimiento_estado] || f.seguimiento_estado)
                       + (f.estado_a_mano ? ' (a mano)' : ' (calculado)')],
            ['Responsable', f.responsable || '—'],
            ['Recordatorio', f.recordar_en ? f.recordar_en.substring(0, 16)
                + (f.recordar_cada ? ' · cada ' + f.recordar_cada + ' d' : '') : '—'],
            ['Consecutivo XML', f.consecutivo || '—'],
            ['Importación', f.contexto || '—']
        ] : [
            ['Proveedor', f.proveedor],
            ['Fecha', f.fecha],
            ['Monto', plata(f.monto)],
            ['Saldo', plata(f.saldo)],
            ['Diferencia', f.diferencia ? plata(Math.abs(f.diferencia)) : '—'],
            ['Qué falta', TAREAS[f.tarea] || f.tarea],
            ['Estado', (ESTADOS[f.seguimiento_estado] || f.seguimiento_estado)
                       + (f.estado_a_mano ? ' (a mano)' : ' (calculado)')],
            ['Responsable', f.responsable || '—'],
            ['Recordatorio', f.recordar_en ? f.recordar_en.substring(0, 16)
                + (f.recordar_cada ? ' · cada ' + f.recordar_cada + ' d' : '') : '—'],
            ['Consecutivo XML', f.consecutivo || '—'],
            ['Clase', f.clase || '—']
        ];

        var html = '<div class="seg-datos">';
        datos.forEach(function (d) {
            html += '<div><div class="seg-dato-et">' + esc(d[0]) + '</div>' +
                    '<div class="seg-dato-v">' + esc(d[1]) + '</div></div>';
        });
        html += '</div>';

        // El desajuste va arriba del todo: es lo que hay que decidir.
        if (f.desajustada) {
            html += '<div class="seg-desajuste" style="margin-bottom:10px;">' +
                    '<i class="fas fa-triangle-exclamation"></i> Los datos cambiaron: ' +
                    (ES_CORREO ? 'por su registro en el sistema' : 'por su saldo y su respaldo') +
                    ' le tocaría <strong>' +
                    esc((ESTADOS[f.estado_calculado] || f.estado_calculado).toLowerCase()) +
                    '</strong>. Sigue donde lo pusieron hasta que alguien lo mueva.</div>';
        }

        // Los dos archivos que tiene que tener todo documento.
        html += '<div class="seg-dato-et" style="margin-bottom:5px;">Respaldo</div><div style="display:flex;gap:7px;margin-bottom:10px;flex-wrap:wrap;">';
        // El rótulo de la ventana es el mismo documento del que habla el
        // expediente, así que se arma una vez para todos sus botones.
        var rotulo = ' data-ventana-titulo="' + esc(f.documento || '') + '"';
        html += f.xml_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" data-ventana="XML"' + rotulo + ' href="' + BASE + '/documentos/xml/' + f.factura_xml_id + '"><i class="fas fa-code"></i> Ver XML</a>'
            : '<span class="badge badge-miss"><i class="fas fa-code"></i> Sin XML</span>';
        html += f.pdf_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" data-ventana="PDF"' + rotulo + ' href="' + BASE + '/documentos/pdf/' + f.factura_xml_id + '"><i class="fas fa-file-pdf"></i> Ver PDF</a>'
            : (f.pdf_historico
                ? '<span class="badge badge-default"><i class="fas fa-file-pdf"></i> PDF histórico</span>'
                : '<span class="badge badge-miss"><i class="fas fa-file-pdf"></i> Sin PDF</span>');
        // Se archivó y desapareció de la carpeta compartida, que no es lo
        // mismo que no haberlo tenido nunca: este se puede volver a bajar.
        if (f.xml_perdido || f.pdf_perdido) {
            html += '<span class="badge badge-perdido" title="Se archivó y ya no está en la carpeta compartida">' +
                    '<i class="fas fa-link-slash"></i> Archivo perdido</span>';
            if (f.recuperable) {
                html += '<button type="button" class="btn-recuperar" data-recuperar-doc="' + Number(f.factura_xml_id) + '"' +
                        ' title="Volver a bajarlo del correo y dejarlo en su misma carpeta">' +
                        '<i class="fas fa-cloud-arrow-down"></i></button>';
            }
        }
        // Los dos sitios donde puede estar el electrónico: el correo, si aún
        // no entró al sistema, o los comprobantes cargados, si entró pero no
        // se enganchó con este documento.
        if (ES_CORREO && f.ver_ruta) {
            html += '<a class="btn btn-outline btn-sm" target="_blank" href="' + BASE + esc(f.ver_ruta) + '"'
                 + ' data-ficha="' + Number(f.referencia_id) + '">'
                 + '<i class="fas fa-file-lines"></i> Abrir la ficha</a>';
        }
        if (!f.xml_ok && f.busqueda) {
            var ctx = CTX_COLA + '&ctx_item=' + encodeURIComponent(f.origen + ':' + f.referencia_id);
            var esNota = f.origen === 'nota_credito';
            var termino = encodeURIComponent(f.busqueda);
            var urlCorreo = BASE + '/correo?buscar=' + termino
                          + (f.busqueda_fecha ? '&fecha=' + encodeURIComponent(f.busqueda_fecha) : '')
                          + '&' + ctx;
            var urlXml = BASE + (esNota ? '/notas-xml?buscar=' : '/facturas?q=') + termino + '&' + ctx;
            html += '<a class="btn btn-primary btn-sm" target="_blank" data-ventana="Correo"' + rotulo
                 + ' href="' + urlCorreo + '">'
                 + '<i class="fas fa-envelope-open-text"></i> Buscar en el correo'
                 + (f.busqueda_por === 'proveedor' ? ' por proveedor' : '') + '</a>';
            html += '<a class="btn btn-outline btn-sm" target="_blank"'
                 + ' data-ventana="' + (esNota ? 'Notas XML' : 'Comprobantes XML') + '"' + rotulo
                 + ' href="' + urlXml + '">'
                 + '<i class="fas fa-file-code"></i> Buscar en los XML cargados</a>';
        }
        html += '</div>';

        // Por qué la búsqueda no va por número: el del reporte es el de la
        // factura que la nota corrige, no el de la nota.
        if (!f.xml_ok && f.busqueda && f.busqueda_por === 'proveedor') {
            html += '<div style="font-size:11.5px;color:var(--text-muted);margin:-4px 0 10px;">' +
                    'El número de esta nota es el de la factura que corrige, no sirve para ' +
                    'buscarla en el correo. Se busca por proveedor, 15 días antes y después de su fecha.</div>';
        }

        if (f.motivo_match) {
            html += '<div class="seg-dato-et">Por qué no cuadró</div>' +
                    '<div style="font-size:12.5px;color:var(--text);margin-bottom:10px;">' + esc(f.motivo_match) + '</div>';
        }
        if (f.motivo) {
            html += '<div class="seg-dato-et">' +
                    (f.seguimiento_estado === 'revision' ? 'Cuál es el problema' : 'Motivo registrado') +
                    '</div><div style="font-size:12.5px;color:var(--text);margin-bottom:10px;">' +
                    esc(f.motivo) + '</div>';
        }

        html += '<div class="seg-dato-et" style="margin-bottom:4px;">Bitácora</div>';
        if (!bitacora.length) {
            html += '<div style="font-size:12.5px;color:var(--text-muted);padding:10px 0;">' +
                    'Todavía nadie ha anotado nada sobre este documento.</div>';
        } else {
            // 'auto' no es un estado: es haber quitado la marca a mano.
            var nombreEstado = function (v) {
                return v === 'auto' ? 'sin marca' : (ESTADOS[v] || v);
            };
            bitacora.forEach(function (b) {
                var cab = b.usuario_nombre || 'Sistema';
                if (b.estado_nuevo) {
                    cab += ' · ' + nombreEstado(b.estado_anterior) + ' → ' + nombreEstado(b.estado_nuevo);
                }
                html += '<div class="seg-linea"><div class="seg-linea-punto"></div><div class="seg-linea-cuerpo">' +
                        '<div class="seg-linea-cab">' + esc(cab) + '</div>' +
                        '<div class="seg-linea-fecha">' + esc(b.creado_en) + '</div>' +
                        (b.comentario ? '<div class="seg-linea-texto">' + esc(b.comentario) + '</div>' : '') +
                        '</div></div>';
            });
        }

        html += '<div style="margin-top:16px;display:flex;gap:8px;">' +
                '<button type="button" class="btn btn-primary btn-sm" id="panel-anotar">' +
                '<i class="fas fa-comment-dots"></i> Anotar</button></div>';

        document.getElementById('panel-cuerpo').innerHTML = html;
        document.getElementById('panel-anotar').addEventListener('click', function () {
            panel.hidden = true;
            abrirDialogo('comentar', [f.origen + '|' + f.referencia_id]);
        });
    }

    refrescarBarra();
})();
</script>
