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

$badgeTarea = [
    'falta_xml'  => ['miss', 'fa-file-circle-xmark'],
    'falta_pdf'  => ['warn', 'fa-file-pdf'],
    'diferencia' => ['diff', 'fa-scale-unbalanced'],
    'completo'   => ['ok',   'fa-circle-check'],
];
$badgeEstado = [
    'pendiente' => ['pend',    'fa-inbox'],
    'revision'  => ['gold',    'fa-magnifying-glass'],
    'lista'     => ['ok',      'fa-circle-check'],
    'cerrada'   => ['default', 'fa-box-archive'],
];

/**
 * Conserva los filtros al cambiar de página o de pestaña.
 *
 * Con $paraContexto los filtros salen con el prefijo ctx_f_: así viajan a la
 * pantalla donde se busca el electrónico sin pisarle los suyos, que se llaman
 * igual —el 'q' de esta cola no es el buscador del listado de facturas—.
 */
$qs = function (array $cambios = [], $paraContexto = false) use ($filtros) {
    $base = array_filter([
        'vista'       => $filtros['vista'],
        'origen'      => $filtros['origen'],
        'tarea'       => $filtros['tarea'],
        'marca'       => $filtros['marca'],
        'clase'       => $filtros['clase'],
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
?>

<div class="page-header">
    <div>
        <h1>Seguimiento de documentos</h1>
        <p>
            Facturas y notas de crédito en una sola lista. Cada una vive en un estado según su
            <strong>saldo</strong> y su <strong>respaldo</strong> —XML y PDF, con el monto cuadrado—,
            y cualquiera se puede mover a mano.
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento/exportar?<?= $qs() ?>">
            <i class="fas fa-file-csv"></i> Exportar
        </a>
        <?php // 'limpiar' hace que el servidor olvide los filtros guardados
              // de esta pantalla: entrar sin criterios los devuelve. ?>
        <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento?limpiar=1">
            <i class="fas fa-rotate"></i> Limpiar filtros
        </a>
    </div>
</div>

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
            <div class="card-subtitle">
                Ordenada por dinero en juego. Seleccioná renglones para actuar sobre todos a la vez.
            </div>
        </div>
        <?php // Cada pestaña es un estado y lleva su cuenta; 'Todo' es la única
              // que no lo es, y por eso no muestra número. ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ([
                'pendiente' => ['fa-inbox', 'Con saldo y algo que falta: XML, PDF o el monto no cuadra'],
                'revision'  => ['fa-magnifying-glass', 'Puestas a mano por alguien, con el problema descrito'],
                'lista'     => ['fa-circle-check', 'Con saldo y respaldo completo: listas para pagar o rebajar'],
                'cerrada'   => ['fa-box-archive', 'Sin saldo: ya se pagaron o se aplicaron'],
                'todo'      => ['fa-layer-group', 'Todas, en cualquier estado'],
            ] as $clave => [$icono, $ayuda]):
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
        </div>
    </div>

    <!-- ══ Filtros ══ -->
    <form class="filter-bar" id="seg-filter-form" method="get" action="<?= $baseUrl ?>/seguimiento">
        <input type="hidden" name="vista" value="<?= htmlspecialchars($filtros['vista']) ?>">

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
         *   Monto desde                 Lo contesta mejor "Ordenar por dinero
         *                               en juego", que además no esconde nada.
         *   Desde / Hasta               Lo mismo con "Más antiguo": lo que se
         *                               busca es lo viejo, no un rango exacto.
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
            <select id="f-origen" name="origen" class="form-control">
                <option value="">Todos</option>
                <option value="nota_credito" <?= $filtros['origen'] === 'nota_credito' ? 'selected' : '' ?>>Notas de crédito</option>
                <option value="factura" <?= $filtros['origen'] === 'factura' ? 'selected' : '' ?>>Facturas</option>
            </select>
        </div>

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

        <div>
            <label class="filter-label" for="f-tarea">Qué falta</label>
            <select id="f-tarea" name="tarea" class="form-control">
                <option value="">Cualquier cosa</option>
                <?php foreach ($tareas as $clave => $etiqueta): if ($clave === 'completo') continue; ?>
                <option value="<?= $clave ?>" <?= $filtros['tarea'] === $clave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($etiqueta) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label" for="f-responsable">Responsable</label>
            <select id="f-responsable" name="responsable" class="form-control">
                <option value="">Cualquiera</option>
                <?php foreach ($responsables as $r): ?>
                <option value="<?= htmlspecialchars($r) ?>" <?= $filtros['responsable'] === $r ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label" for="f-orden">Ordenar por</label>
            <select id="f-orden" name="orden" class="form-control">
                <?php foreach ([
                    'monto'      => 'Dinero en juego',
                    'antiguedad' => 'Más antiguo',
                    'reciente'   => 'Más reciente',
                    'proveedor'  => 'Proveedor',
                    'movimiento' => 'Último movimiento',
                ] as $clave => $etiqueta): ?>
                <option value="<?= $clave ?>" <?= $filtros['orden'] === $clave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($etiqueta) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
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
        $acciones = [
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
                <tr class="seg-head-labels">
                    <th style="width:34px;" class="center">
                        <input type="checkbox" id="chk-todos" aria-label="Seleccionar todos">
                    </th>
                    <th>Documento</th>
                    <th>Proveedor</th>
                    <th class="right">Monto</th>
                    <th class="right">Saldo</th>
                    <th class="center">Respaldo</th>
                    <th>Qué falta</th>
                    <th>Estado</th>
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
                    <th><input form="seg-filter-form" name="col_monto" inputmode="decimal"
                               value="<?= htmlspecialchars($filtros['col_monto']) ?>"
                               placeholder="Buscar" aria-label="Buscar monto"></th>
                    <th><input form="seg-filter-form" name="col_saldo" inputmode="decimal"
                               value="<?= htmlspecialchars($filtros['col_saldo']) ?>"
                               placeholder="Buscar" aria-label="Buscar saldo"></th>
                    <th>
                        <select form="seg-filter-form" name="col_respaldo" aria-label="Filtrar respaldo">
                            <option value="">Todos</option>
                            <option value="completo" <?= $filtros['col_respaldo'] === 'completo' ? 'selected' : '' ?>>Completo</option>
                            <option value="sin_xml" <?= $filtros['col_respaldo'] === 'sin_xml' ? 'selected' : '' ?>>Sin XML</option>
                            <option value="sin_pdf" <?= $filtros['col_respaldo'] === 'sin_pdf' ? 'selected' : '' ?>>Sin PDF</option>
                        </select>
                    </th>
                    <?php // "Qué falta" ya está en la barra, con las mismas
                          // opciones: dos controles para lo mismo en la misma
                          // pantalla solo sirven para contradecirse. ?>
                    <th></th>
                    <?php // El estado lo elige la pestaña y cómo llegó a él, el
                          // filtro "Marca" de arriba: acá no queda nada que
                          // buscar, y repetir el control con el mismo nombre
                          // dentro del mismo formulario los desincronizaría. ?>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filas)): ?>
                <tr class="empty-row">
                    <td colspan="9">
                        <div style="font-size:34px;color:var(--ok);margin-bottom:8px;">
                            <i class="fas fa-circle-check"></i>
                        </div>
                        <strong style="color:var(--navy);font-size:15px;">No hay nada pendiente con estos filtros</strong>
                        <div style="margin-top:4px;">Probá con otra pestaña o quitá algún filtro.</div>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($filas as $f):
                $clave = $f['origen'] . '|' . $f['referencia_id'];
                [$tClase, $tIcono] = $badgeTarea[$f['tarea']] ?? ['default', 'fa-circle'];
                [$eClase, $eIcono] = $badgeEstado[$f['seguimiento_estado']] ?? ['default', 'fa-circle'];
                $esNc = $f['origen'] === 'nota_credito';
            ?>
                <tr data-clave="<?= htmlspecialchars($clave) ?>"
                    data-origen="<?= htmlspecialchars($f['origen']) ?>"
                    data-ref="<?= (int) $f['referencia_id'] ?>"
                    class="<?= $f['vencido'] ? 'seg-vencido' : '' ?>">

                    <td class="center">
                        <input type="checkbox" class="chk-fila" value="<?= htmlspecialchars($clave) ?>"
                               aria-label="Seleccionar <?= htmlspecialchars($f['documento']) ?>">
                    </td>

                    <td>
                        <div style="display:flex;align-items:center;gap:7px;">
                            <span class="badge badge-<?= $esNc ? 'navy' : 'gold' ?>" style="font-size:10px;">
                                <?= $esNc ? 'NC' : 'FE' ?>
                            </span>
                            <strong style="font-family:ui-monospace,monospace;font-size:12.5px;">
                                <?= htmlspecialchars(mb_strimwidth((string) $f['documento'], 0, 32, '…')) ?>
                            </strong>
                        </div>
                        <div class="seg-sub">
                            <?= htmlspecialchars((string) $f['fecha']) ?>
                            <?php if ($esNc && !empty($f['clase'])): ?>
                            · <span title="Clase de nota deducida del formato del número"><?= htmlspecialchars($f['clase']) ?></span>
                            <?php endif; ?>
                            <?php // Una factura que todavía no entró a ningún pago no tiene listado. ?>
                            <?php if (!empty($f['contexto'])): ?>
                            · <?= htmlspecialchars(mb_strimwidth((string) $f['contexto'], 0, 26, '…')) ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <?= htmlspecialchars(mb_strimwidth((string) $f['proveedor'], 0, 30, '…')) ?>
                        <?php if (!empty($f['sucursal'])): ?>
                        <div class="seg-sub"><?= htmlspecialchars((string) $f['sucursal']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="right">
                        <strong><?= $moneda($f['monto'], $f['moneda']) ?></strong>
                        <?php if ($f['diferencia'] !== null && (float) $f['diferencia'] != 0.0): ?>
                        <div class="seg-sub" style="color:var(--diff);font-weight:700;">
                            dif. <?= $moneda(abs((float) $f['diferencia']), $f['moneda']) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td class="right">
                        <strong style="<?= abs((float) $f['saldo']) <= 0.005 ? 'color:var(--text-muted);' : 'color:var(--navy);' ?>">
                            <?= $moneda($f['saldo'], $f['moneda']) ?>
                        </strong>
                    </td>

                    <!-- Las dos piezas que tiene que tener todo documento -->
                    <td class="center">
                        <div class="seg-respaldo">
                            <?php if ($f['xml_ok']): ?>
                            <a class="seg-chip seg-chip-ok" target="_blank"
                               href="<?= $baseUrl ?>/documentos/xml/<?= (int) $f['factura_xml_id'] ?>"
                               title="Ver el XML">
                                <i class="fas fa-code"></i> XML
                            </a>
                            <?php elseif ($f['xml_perdido']): ?>
                            <span class="seg-chip seg-chip-perdido"
                                  title="El XML se archivó y ya no está en la carpeta compartida">
                                <i class="fas fa-link-slash"></i> XML
                            </span>
                            <?php else: ?>
                            <span class="seg-chip seg-chip-no" title="No hay XML para este documento">
                                <i class="fas fa-code"></i> XML
                            </span>
                            <?php endif; ?>

                            <?php if ($f['pdf_ok']): ?>
                            <a class="seg-chip seg-chip-ok" target="_blank"
                               href="<?= $baseUrl ?>/documentos/pdf/<?= (int) $f['factura_xml_id'] ?>"
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

                    <td>
                        <span class="badge badge-<?= $tClase ?>">
                            <i class="fas <?= $tIcono ?>"></i>
                            <?= htmlspecialchars($tareas[$f['tarea']] ?? $f['tarea']) ?>
                        </span>
                        <?php if (!empty($f['motivo_match'])): ?>
                        <div class="seg-sub" title="<?= htmlspecialchars((string) $f['motivo_match']) ?>">
                            <?= htmlspecialchars(mb_strimwidth((string) $f['motivo_match'], 0, 46, '…')) ?>
                        </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="badge badge-<?= $eClase ?>">
                            <i class="fas <?= $eIcono ?>"></i>
                            <?= htmlspecialchars($estados[$f['seguimiento_estado']] ?? $f['seguimiento_estado']) ?>
                        </span>
                        <?php if (!empty($f['estado_a_mano'])): ?>
                        <span class="seg-mano" title="Alguien lo puso aquí a mano; no se mueve solo">
                            <i class="fas fa-hand"></i>
                        </span>
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

                        <div class="seg-sub">
                            <?php if (!empty($f['responsable'])): ?>
                                <i class="fas fa-user" style="opacity:.5;"></i> <?= htmlspecialchars($f['responsable']) ?>
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
                                <i class="fas fa-comment-dots" style="opacity:.5;"></i> <?= (int) $f['anotaciones'] ?>
                            <?php endif; ?>
                        </div>

                        <?php // El motivo es obligatorio al mandar a revisión, así
                              // que es lo primero que hay que poder leer sin abrir nada.
                              // Fuera de revisión no se muestra: describe un enredo que
                              // ya no aplica, y sigue guardado en la bitácora. ?>
                        <?php if (!empty($f['motivo']) && $f['seguimiento_estado'] === 'revision'): ?>
                        <div class="seg-motivo" title="<?= htmlspecialchars((string) $f['motivo']) ?>">
                            <?= htmlspecialchars(mb_strimwidth((string) $f['motivo'], 0, 52, '…')) ?>
                        </div>
                        <?php endif; ?>
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
                        <a class="btn btn-outline btn-sm"
                           href="<?= $urlCorreo ?>"
                           target="_blank"
                           title="<?= $f['busqueda_por'] === 'proveedor'
                               ? 'Buscarlo en el correo. La nota no trae número propio: se busca por proveedor alrededor de su fecha'
                               : 'Buscar este documento en el correo' ?>">
                            <i class="fas fa-envelope-open-text"></i>
                        </a>
                        <a class="btn btn-outline btn-sm"
                           href="<?= $urlXml ?>"
                           target="_blank"
                           title="Buscarlo entre los comprobantes XML ya cargados">
                            <i class="fas fa-file-code"></i>
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

            <div class="form-group" id="dlg-campo-responsable" hidden>
                <label class="form-label" for="dlg-responsable">Responsable</label>
                <input type="text" id="dlg-responsable" class="form-control" placeholder="Nombre de quien lo trabaja">
            </div>

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
.seg-tabla { min-width: 1280px; }
.seg-tabla td { vertical-align: top; }
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

    if (chkTodos) {
        chkTodos.addEventListener('change', function () {
            casillas().forEach(function (c) { c.checked = chkTodos.checked; });
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
    var ACCIONES = {
        revision:  {
            titulo: 'Mandar a revisión',
            texto: 'Quedan apartados hasta que alguien los saque. Hay que decir cuál es el problema.',
            motivo: true, responsable: true, recordatorio: true
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
        document.getElementById('dlg-campo-responsable').hidden = !cfg.responsable;
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
        itemsActuales.forEach(function (i) { cuerpo.append('items[]', i); });

        // `estado: null` en la configuración significa "no tocar el estado".
        var estado = Object.prototype.hasOwnProperty.call(cfg, 'estado') ? cfg.estado : accionActual;
        if (estado) { cuerpo.append('estado', estado); }
        if (cfg.recordatorio) {
            cuerpo.append('recordar_cada', document.getElementById('dlg-cada').value);
            cuerpo.append('recordar_hora', document.getElementById('dlg-hora').value);
        }
        if (cfg.responsable) { cuerpo.append('responsable', document.getElementById('dlg-responsable').value); }
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
              + '&referencia_id=' + encodeURIComponent(ref))
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
        document.getElementById('panel-sub').textContent =
            (f.origen === 'nota_credito' ? 'Nota de crédito' : 'Factura')
            + (f.contexto ? ' · ' + f.contexto : '');

        var datos = [
            ['Proveedor', f.proveedor],
            ['Fecha', f.fecha],
            ['Monto', (f.moneda === 'USD' ? '$' : '₡') + Number(f.monto).toLocaleString('es-CR', { minimumFractionDigits: 2 })],
            ['Saldo', (f.moneda === 'USD' ? '$' : '₡') + Number(f.saldo).toLocaleString('es-CR', { minimumFractionDigits: 2 })],
            ['Diferencia', f.diferencia ? (f.moneda === 'USD' ? '$' : '₡') + Number(Math.abs(f.diferencia)).toLocaleString('es-CR', { minimumFractionDigits: 2 }) : '—'],
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
                    '<i class="fas fa-triangle-exclamation"></i> Los datos cambiaron: por su saldo y su ' +
                    'respaldo le tocaría <strong>' +
                    esc((ESTADOS[f.estado_calculado] || f.estado_calculado).toLowerCase()) +
                    '</strong>. Sigue donde lo pusieron hasta que alguien lo mueva.</div>';
        }

        // Los dos archivos que tiene que tener todo documento.
        html += '<div class="seg-dato-et" style="margin-bottom:5px;">Respaldo</div><div style="display:flex;gap:7px;margin-bottom:10px;flex-wrap:wrap;">';
        html += f.xml_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" href="' + BASE + '/documentos/xml/' + f.factura_xml_id + '"><i class="fas fa-code"></i> Ver XML</a>'
            : '<span class="badge badge-miss"><i class="fas fa-code"></i> Sin XML</span>';
        html += f.pdf_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" href="' + BASE + '/documentos/pdf/' + f.factura_xml_id + '"><i class="fas fa-file-pdf"></i> Ver PDF</a>'
            : (f.pdf_historico
                ? '<span class="badge badge-default"><i class="fas fa-file-pdf"></i> PDF histórico</span>'
                : '<span class="badge badge-miss"><i class="fas fa-file-pdf"></i> Sin PDF</span>');
        // Los dos sitios donde puede estar el electrónico: el correo, si aún
        // no entró al sistema, o los comprobantes cargados, si entró pero no
        // se enganchó con este documento.
        if (!f.xml_ok && f.busqueda) {
            var ctx = CTX_COLA + '&ctx_item=' + encodeURIComponent(f.origen + ':' + f.referencia_id);
            var esNota = f.origen === 'nota_credito';
            var termino = encodeURIComponent(f.busqueda);
            var urlCorreo = BASE + '/correo?buscar=' + termino
                          + (f.busqueda_fecha ? '&fecha=' + encodeURIComponent(f.busqueda_fecha) : '')
                          + '&' + ctx;
            var urlXml = BASE + (esNota ? '/notas-xml?buscar=' : '/facturas?q=') + termino + '&' + ctx;
            html += '<a class="btn btn-primary btn-sm" target="_blank" href="' + urlCorreo + '">'
                 + '<i class="fas fa-envelope-open-text"></i> Buscar en el correo'
                 + (f.busqueda_por === 'proveedor' ? ' por proveedor' : '') + '</a>';
            html += '<a class="btn btn-outline btn-sm" target="_blank" href="' + urlXml + '">'
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
