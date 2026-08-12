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
    'pendiente'     => ['pend',    'fa-inbox'],
    'en_gestion'    => ['gold',    'fa-person-digging'],
    'esperando'     => ['warn',    'fa-hourglass-half'],
    'resuelto'      => ['ok',      'fa-circle-check'],
    'no_disponible' => ['default', 'fa-ban'],
    'descartado'    => ['default', 'fa-xmark'],
];

/** Conserva los filtros al cambiar de página o de pestaña. */
$qs = function (array $cambios = []) use ($filtros) {
    $base = array_filter([
        'vista'       => $filtros['vista'],
        'origen'      => $filtros['origen'],
        'tarea'       => $filtros['tarea'],
        'estado'      => $filtros['estado'],
        'clase'       => $filtros['clase'],
        'responsable' => $filtros['responsable'],
        'contexto_id' => $filtros['contexto_id'] ?: '',
        'desde'       => $filtros['desde'],
        'hasta'       => $filtros['hasta'],
        'monto_min'   => $filtros['monto_min'],
        'q'           => $filtros['q'],
        'orden'       => $filtros['orden'],
    ], function ($v) { return $v !== '' && $v !== null; });
    return http_build_query(array_merge($base, $cambios));
};

$moneda = function ($valor, $mon = 'CRC') {
    return ($mon === 'USD' ? '$' : '₡') . number_format((float) $valor, 2);
};
?>

<div class="page-header">
    <div>
        <h1>Seguimiento de documentos</h1>
        <p>
            Todo lo que le falta respaldo o no cuadra, en una sola lista. Un documento está
            completo cuando tiene <strong>su XML y su PDF</strong> y el monto coincide con el reporte.
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento/exportar?<?= $qs() ?>">
            <i class="fas fa-file-csv"></i> Exportar
        </a>
        <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/seguimiento">
            <i class="fas fa-rotate"></i> Limpiar filtros
        </a>
    </div>
</div>

<!-- ══ Tarjetas de situación ══════════════════════════════════════════════ -->
<div class="stats-grid">
    <div class="stat-card navy">
        <div class="stat-label">Pendientes</div>
        <div class="stat-value"><?= number_format((int) $resumen['total']) ?></div>
        <div class="stat-sub"><?= number_format((int) $resumen['sin_tocar']) ?> sin tocar ·
            <?= number_format((int) $resumen['en_curso']) ?> en curso</div>
    </div>
    <div class="stat-card gold">
        <div class="stat-label">Dinero en disputa</div>
        <div class="stat-value" style="font-size:21px;">₡<?= number_format((float) $resumen['monto_diferencia'], 0) ?></div>
        <div class="stat-sub"><?= number_format((int) $resumen['casos_diferencia']) ?> facturas con diferencia</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label">Falta el XML</div>
        <div class="stat-value"><?= number_format((int) $resumen['falta_xml']) ?></div>
        <div class="stat-sub">Hay que bajarlo del correo</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label">Falta el PDF</div>
        <div class="stat-value"><?= number_format((int) $resumen['falta_pdf']) ?></div>
        <div class="stat-sub">El XML ya está, falta la representación</div>
    </div>
    <div class="stat-card white">
        <div class="stat-label">Vencidos</div>
        <div class="stat-value" style="<?= $resumen['vencidos'] > 0 ? 'color:#8F1A1A;' : '' ?>">
            <?= number_format((int) $resumen['vencidos']) ?>
        </div>
        <div class="stat-sub">Pospuestos cuya fecha ya pasó</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Cola de trabajo</div>
            <div class="card-subtitle">
                Ordenada por dinero en juego. Seleccioná renglones para actuar sobre todos a la vez.
            </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ([
                'abierto'   => ['Abiertos', 'fa-list-check'],
                'pospuesto' => ['Pospuestos', 'fa-clock'],
                'cerrado'   => ['Cerrados', 'fa-box-archive'],
                'completo'  => ['Respaldados', 'fa-circle-check'],
                'todo'      => ['Todo', 'fa-layer-group'],
            ] as $clave => [$etiqueta, $icono]):
                $activa = $filtros['vista'] === $clave; ?>
            <a href="?<?= $qs(['vista' => $clave, 'pagina' => 1]) ?>"
               class="btn <?= $activa ? 'btn-primary' : 'btn-outline' ?> btn-sm"
               <?= $activa ? 'aria-current="page"' : '' ?>>
                <i class="fas <?= $icono ?>"></i> <?= $etiqueta ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ Filtros ══ -->
    <form class="filter-bar" method="get" action="<?= $baseUrl ?>/seguimiento">
        <input type="hidden" name="vista" value="<?= htmlspecialchars($filtros['vista']) ?>">

        <div class="filter-span-2">
            <label class="filter-label" for="f-q">Buscar</label>
            <input type="search" id="f-q" name="q" class="form-control"
                   value="<?= htmlspecialchars($filtros['q']) ?>"
                   placeholder="Documento, proveedor o consecutivo…">
        </div>

        <div>
            <label class="filter-label" for="f-origen">Origen</label>
            <select id="f-origen" name="origen" class="form-control">
                <option value="">Todos</option>
                <option value="nota_credito" <?= $filtros['origen'] === 'nota_credito' ? 'selected' : '' ?>>Notas de crédito</option>
                <option value="pago_semanal" <?= $filtros['origen'] === 'pago_semanal' ? 'selected' : '' ?>>Pago semanal</option>
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
            <label class="filter-label" for="f-estado">Gestión</label>
            <select id="f-estado" name="estado" class="form-control">
                <option value="">Cualquiera</option>
                <?php foreach ($estados as $clave => $etiqueta): ?>
                <option value="<?= $clave ?>" <?= $filtros['estado'] === $clave ? 'selected' : '' ?>>
                    <?= htmlspecialchars($etiqueta) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label" for="f-clase">Clase de nota</label>
            <select id="f-clase" name="clase" class="form-control">
                <option value="">Todas</option>
                <?php foreach ([
                    'directa' => 'Directa (corrige factura)',
                    'costo'   => 'Diferencia de costo',
                    'cambio'  => 'Cambio de mercadería',
                    'revisar' => 'Por revisar',
                ] as $clave => $etiqueta): ?>
                <option value="<?= $clave ?>" <?= $filtros['clase'] === $clave ? 'selected' : '' ?>>
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
            <label class="filter-label" for="f-contexto">Listado</label>
            <select id="f-contexto" name="contexto_id" class="form-control">
                <option value="">Todos</option>
                <?php foreach ($contextos as $c): ?>
                <option value="<?= (int) $c['contexto_id'] ?>"
                    <?= (int) $filtros['contexto_id'] === (int) $c['contexto_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(mb_strimwidth((string) $c['contexto'], 0, 34, '…')) ?>
                    (<?= number_format((int) $c['pendientes']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="filter-label" for="f-monto">Monto desde</label>
            <input type="number" id="f-monto" name="monto_min" class="form-control" step="1" min="0"
                   value="<?= htmlspecialchars($filtros['monto_min']) ?>" placeholder="100000">
        </div>

        <div>
            <label class="filter-label" for="f-desde">Desde</label>
            <input type="date" id="f-desde" name="desde" class="form-control" value="<?= htmlspecialchars($filtros['desde']) ?>">
        </div>

        <div>
            <label class="filter-label" for="f-hasta">Hasta</label>
            <input type="date" id="f-hasta" name="hasta" class="form-control" value="<?= htmlspecialchars($filtros['hasta']) ?>">
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
        <div class="seg-acciones-btns">
            <button type="button" class="btn btn-gold btn-sm" data-accion="en_gestion">
                <i class="fas fa-person-digging"></i> Tomar
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="esperando">
                <i class="fas fa-hourglass-half"></i> Pedido al proveedor
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="posponer">
                <i class="fas fa-clock"></i> Posponer
            </button>
            <button type="button" class="btn btn-success btn-sm" data-accion="resuelto">
                <i class="fas fa-circle-check"></i> Resolver
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="no_disponible">
                <i class="fas fa-ban"></i> No va a existir
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="comentar">
                <i class="fas fa-comment-dots"></i> Anotar
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-accion="pendiente">
                <i class="fas fa-rotate-left"></i> Reabrir
            </button>
        </div>
    </div>

    <div class="filter-results">
        Mostrando <strong><?= number_format(count($filas)) ?></strong>
        de <strong><?= number_format((int) $paginacion['total']) ?></strong> renglones
        <?php if ($filtros['vista'] === 'abierto'): ?>
        · <span title="Los pospuestos a futuro y los cerrados no aparecen aquí">solo lo que hay que trabajar hoy</span>
        <?php endif; ?>
    </div>

    <!-- ══ Tabla ══ -->
    <div class="table-wrap">
        <table class="data-table seg-tabla">
            <thead>
                <tr>
                    <th style="width:34px;" class="center">
                        <input type="checkbox" id="chk-todos" aria-label="Seleccionar todos">
                    </th>
                    <th>Documento</th>
                    <th>Proveedor</th>
                    <th class="right">Monto</th>
                    <th class="center">Respaldo</th>
                    <th>Qué falta</th>
                    <th>Gestión</th>
                    <th style="width:1%;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filas)): ?>
                <tr class="empty-row">
                    <td colspan="8">
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
                            · <?= htmlspecialchars(mb_strimwidth((string) $f['contexto'], 0, 26, '…')) ?>
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

                    <!-- Las dos piezas que tiene que tener todo documento -->
                    <td class="center">
                        <div class="seg-respaldo">
                            <?php if ($f['xml_ok']): ?>
                            <a class="seg-chip seg-chip-ok" target="_blank"
                               href="<?= $baseUrl ?>/documentos/xml/<?= (int) $f['factura_xml_id'] ?>"
                               title="Ver el XML">
                                <i class="fas fa-code"></i> XML
                            </a>
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
                            <?php elseif ($f['pdf_historico']): ?>
                            <span class="seg-chip seg-chip-hist" title="Marcado como histórico: no va a existir">
                                <i class="fas fa-file-pdf"></i> PDF
                            </span>
                            <?php else: ?>
                            <span class="seg-chip seg-chip-no" title="Falta el PDF">
                                <i class="fas fa-file-pdf"></i> PDF
                            </span>
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
                        <div class="seg-sub">
                            <?php if (!empty($f['responsable'])): ?>
                                <i class="fas fa-user" style="opacity:.5;"></i> <?= htmlspecialchars($f['responsable']) ?>
                            <?php endif; ?>
                            <?php if (!empty($f['vence_el'])): ?>
                                <span style="<?= $f['vencido'] ? 'color:var(--miss);font-weight:700;' : '' ?>">
                                    <i class="fas fa-clock" style="opacity:.5;"></i> <?= htmlspecialchars($f['vence_el']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ((int) $f['anotaciones'] > 0): ?>
                                <i class="fas fa-comment-dots" style="opacity:.5;"></i> <?= (int) $f['anotaciones'] ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td style="white-space:nowrap;">
                        <?php if (!$f['xml_ok']): ?>
                        <a class="btn btn-outline btn-sm"
                           href="<?= $baseUrl ?>/correo?buscar=<?= urlencode((string) $f['busqueda']) ?>"
                           target="_blank" title="Buscar este documento en el correo">
                            <i class="fas fa-envelope-open-text"></i>
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
        <div style="padding:18px;">
            <p id="dlg-texto" style="font-size:13px;color:var(--text-muted);margin-bottom:14px;"></p>

            <div class="form-group" id="dlg-campo-dias" hidden>
                <label class="form-label" for="dlg-dias">Volver a mostrarlo en</label>
                <select id="dlg-dias" class="form-control">
                    <option value="3">3 días</option>
                    <option value="7" selected>1 semana</option>
                    <option value="15">15 días</option>
                    <option value="30">1 mes</option>
                    <option value="90">3 meses</option>
                </select>
            </div>

            <div class="form-group" id="dlg-campo-responsable" hidden>
                <label class="form-label" for="dlg-responsable">Responsable</label>
                <input type="text" id="dlg-responsable" class="form-control" placeholder="Nombre de quien lo trabaja">
            </div>

            <div class="form-group" id="dlg-campo-motivo" hidden>
                <label class="form-label" for="dlg-motivo">Motivo</label>
                <input type="text" id="dlg-motivo" class="form-control" maxlength="255"
                       placeholder="Por qué se cierra">
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
.seg-tabla td { vertical-align: top; }

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

.seg-acciones {
  display: flex; align-items: center; justify-content: space-between; gap: 14px;
  flex-wrap: wrap; padding: 11px 22px; margin: 0 -24px;
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
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 15px 20px; background: #FAFCFF; border-bottom: 1px solid var(--border-light);
}
.seg-panel-x { background: none; border: none; font-size: 26px; line-height: 1;
               color: var(--text-muted); cursor: pointer; padding: 0 4px; }
.seg-panel-x:hover { color: var(--navy); }
.seg-panel-cuerpo { padding: 18px 20px; overflow-y: auto; flex: 1; }

.seg-dialogo {
  position: relative; margin: auto; width: min(480px, calc(100% - 32px));
  background: var(--card); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
  max-height: calc(100vh - 40px); overflow-y: auto;
}

.seg-datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
             gap: 12px; margin-bottom: 18px; }
.seg-dato-et { font-size: 10px; font-weight: 700; letter-spacing: .08em;
               text-transform: uppercase; color: var(--text-muted); }
.seg-dato-v  { font-size: 13px; color: var(--navy); font-weight: 600; word-break: break-word; }

.seg-linea { display: flex; gap: 10px; padding: 11px 0; border-bottom: 1px solid var(--border-light); }
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
    if (!tabla) { return; }

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
    var ACCIONES = {
        en_gestion:    { titulo: 'Tomar el trabajo',      texto: 'Quedan marcados como en gestión.', responsable: true },
        esperando:     { titulo: 'Pedido al proveedor',   texto: 'Quedan a la espera de respuesta.', posponer: true },
        posponer:      { titulo: 'Posponer',              texto: 'Salen de la cola hasta la fecha que elijas.', posponer: true, estado: null },
        resuelto:      { titulo: 'Marcar como resuelto',  texto: 'Salen de la cola. Se puede reabrir después.', motivo: true },
        no_disponible: { titulo: 'No va a existir',       texto: 'Para documentos anteriores al sistema o que el proveedor no va a emitir.', motivo: true },
        pendiente:     { titulo: 'Reabrir',               texto: 'Vuelven a la cola como pendientes.' },
        comentar:      { titulo: 'Anotar',                texto: 'Deja constancia sin cambiar el estado.', estado: null }
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
        document.getElementById('dlg-campo-dias').hidden = !cfg.posponer;
        document.getElementById('dlg-campo-responsable').hidden = !cfg.responsable;
        document.getElementById('dlg-campo-motivo').hidden = !cfg.motivo;
        document.getElementById('dlg-comentario').value = '';
        document.getElementById('dlg-motivo').value = '';
        dialogo.hidden = false;
        var foco = cfg.responsable ? 'dlg-responsable' : (cfg.motivo ? 'dlg-motivo' : 'dlg-comentario');
        document.getElementById(foco).focus();
    }

    function cerrarDialogo() { dialogo.hidden = true; accionActual = null; }
    dialogo.addEventListener('click', function (e) {
        if (e.target.closest('[data-cerrar-dialogo]')) { cerrarDialogo(); }
    });

    document.getElementById('dlg-confirmar').addEventListener('click', function () {
        var cfg = ACCIONES[accionActual];
        if (!cfg) { return; }

        var cuerpo = new URLSearchParams();
        itemsActuales.forEach(function (i) { cuerpo.append('items[]', i); });

        // `estado: null` en la configuración significa "no tocar el estado".
        var estado = Object.prototype.hasOwnProperty.call(cfg, 'estado') ? cfg.estado : accionActual;
        if (estado) { cuerpo.append('estado', estado); }
        if (cfg.posponer) { cuerpo.append('posponer_dias', document.getElementById('dlg-dias').value); }
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
            alert(err.message);
            boton.disabled = false;
            boton.innerHTML = '<i class="fas fa-check"></i> Aplicar';
        });
    });

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
            '<div style="text-align:center;padding:40px;color:var(--text-muted);">' +
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
            (f.origen === 'nota_credito' ? 'Nota de crédito' : 'Pago semanal') + ' · ' + f.contexto;

        var datos = [
            ['Proveedor', f.proveedor],
            ['Fecha', f.fecha],
            ['Monto', (f.moneda === 'USD' ? '$' : '₡') + Number(f.monto).toLocaleString('es-CR', { minimumFractionDigits: 2 })],
            ['Diferencia', f.diferencia ? (f.moneda === 'USD' ? '$' : '₡') + Number(Math.abs(f.diferencia)).toLocaleString('es-CR', { minimumFractionDigits: 2 }) : '—'],
            ['Qué falta', TAREAS[f.tarea] || f.tarea],
            ['Gestión', ESTADOS[f.seguimiento_estado] || f.seguimiento_estado],
            ['Responsable', f.responsable || '—'],
            ['Pospuesto hasta', f.vence_el || '—'],
            ['Consecutivo XML', f.consecutivo || '—'],
            ['Clase', f.clase || '—']
        ];

        var html = '<div class="seg-datos">';
        datos.forEach(function (d) {
            html += '<div><div class="seg-dato-et">' + esc(d[0]) + '</div>' +
                    '<div class="seg-dato-v">' + esc(d[1]) + '</div></div>';
        });
        html += '</div>';

        // Los dos archivos que tiene que tener todo documento.
        html += '<div class="seg-dato-et" style="margin-bottom:6px;">Respaldo</div><div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">';
        html += f.xml_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" href="' + BASE + '/documentos/xml/' + f.factura_xml_id + '"><i class="fas fa-code"></i> Ver XML</a>'
            : '<span class="badge badge-miss"><i class="fas fa-code"></i> Sin XML</span>';
        html += f.pdf_ok
            ? '<a class="btn btn-outline btn-sm" target="_blank" href="' + BASE + '/documentos/pdf/' + f.factura_xml_id + '"><i class="fas fa-file-pdf"></i> Ver PDF</a>'
            : (f.pdf_historico
                ? '<span class="badge badge-default"><i class="fas fa-file-pdf"></i> PDF histórico</span>'
                : '<span class="badge badge-miss"><i class="fas fa-file-pdf"></i> Sin PDF</span>');
        if (!f.xml_ok) {
            html += '<a class="btn btn-primary btn-sm" target="_blank" href="' + BASE + '/correo?buscar='
                 + encodeURIComponent(f.busqueda) + '"><i class="fas fa-envelope-open-text"></i> Buscar en el correo</a>';
        }
        html += '</div>';

        if (f.motivo_match) {
            html += '<div class="seg-dato-et">Por qué no cuadró</div>' +
                    '<div style="font-size:12.5px;color:var(--text);margin-bottom:18px;">' + esc(f.motivo_match) + '</div>';
        }
        if (f.motivo) {
            html += '<div class="seg-dato-et">Motivo registrado</div>' +
                    '<div style="font-size:12.5px;color:var(--text);margin-bottom:18px;">' + esc(f.motivo) + '</div>';
        }

        html += '<div class="seg-dato-et" style="margin-bottom:4px;">Bitácora</div>';
        if (!bitacora.length) {
            html += '<div style="font-size:12.5px;color:var(--text-muted);padding:10px 0;">' +
                    'Todavía nadie ha anotado nada sobre este documento.</div>';
        } else {
            bitacora.forEach(function (b) {
                var cab = b.usuario_nombre || 'Sistema';
                if (b.estado_nuevo) {
                    cab += ' · ' + (ESTADOS[b.estado_anterior] || b.estado_anterior)
                        + ' → ' + (ESTADOS[b.estado_nuevo] || b.estado_nuevo);
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
