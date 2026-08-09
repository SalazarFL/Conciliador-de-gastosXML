<?php
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$imapDisponible = $imapDisponible ?? false;
$configExiste   = $configExiste ?? false;
$configurado    = $configurado ?? false;
$configResumen  = $configResumen ?? null;
$bandeja        = $bandeja ?? [];
$historial      = $historial ?? [];
$conteo         = $conteo ?? [];
$configLocal    = is_array($configLocal ?? null) ? $configLocal : ['carpeta_destino' => ''];
$sociedadActiva = $sociedadActiva ?? null;
$buscarInicial  = trim((string) ($buscarInicial ?? ''));
$abrirCorreoUid = max(0, (int) ($abrirCorreoUid ?? 0));
$abrirCorreoCarpeta = trim((string) ($abrirCorreoCarpeta ?? ''));
// $cuentas: con las que se trabaja (las de la empresa en curso).
// $cuentasAdmin: todas, solo para el ⚙, donde se corrigen las asignaciones.
$cuentas        = is_array($cuentas ?? null) ? $cuentas : [];
$cuentasAdmin   = is_array($cuentasAdmin ?? null) ? $cuentasAdmin : $cuentas;
$cuentaActivaId = (int) ($cuentaActivaId ?? 0);
$semanas        = is_array($semanas ?? null) ? $semanas : [];
$ppNav          = is_array($ppNav ?? null) ? $ppNav : null;
$carpetasCorreo = is_array($carpetasCorreo ?? null) ? $carpetasCorreo : [];
$modoCorreo      = in_array(($modoCorreo ?? 'facturas'), ['facturas','descargas'], true) ? $modoCorreo : 'facturas';
$loteGeneral     = is_array($loteGeneral ?? null) ? $loteGeneral : null;

$listo = $imapDisponible && $configurado;
$pendientes = (int) ($conteo['pendiente'] ?? 0);
$diasDefault = is_array($configResumen) ? (int) $configResumen['dias_atras'] : 14;
?>

<style>
/* Esta página aprovecha todo el alto/ancho disponible */
.page-content { padding: 5px 7px 7px; }
.correo-modo-descargas {
    grid-template-columns: var(--lista-w) minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr);
}
.correo-modo-descargas .correo-lista { grid-row: 1; }
.correo-modo-descargas .correo-facturas-panel,
.correo-modo-descargas .rz-bandeja,
.correo-modo-descargas .rz-inferior { display:none; }
.correo-modo-descargas #btn-procesar-sel,
.correo-modo-descargas #btn-procesar-uno,
.correo-modo-descargas #cb-correos-visibles { display:none; }
@media (max-width:1100px) {
    .correo-modo-descargas { grid-template-columns:1fr; grid-template-rows:none; }
    .correo-modo-descargas .rz-lista { display:none; }
}
</style>

<?php if (!$listo): ?>
<div style="background:#fff8e6;border:1px solid #f6d55c;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#7c5e00;margin-bottom:10px;">
    <i class="fas fa-triangle-exclamation" style="margin-right:6px;"></i>
    <?php if (!$imapDisponible): ?>
    <strong>No disponible en este servidor:</strong> la extensión <code>imap</code> de PHP no está activa.
    <?php elseif (!$configExiste && !empty($hayCuentasEnSistema)): ?>
    <?php // Sí hay buzones registrados, pero ninguno atiende a esta empresa.
          // Decir "agrega la primera cuenta" aquí llevaría a registrar dos
          // veces el mismo buzón. ?>
    <strong>Ningún buzón atiende a <?= htmlspecialchars((string) ($sociedadActiva['nombre'] ?? 'esta sociedad')) ?>:</strong>
    los buzones registrados están asignados a otras empresas. Abre el engranaje
    <i class="fas fa-gear"></i>, edita el buzón que corresponda y marca esta empresa en
    <em>Sociedades que atiende</em>. No hace falta registrarlo de nuevo.
    <?php elseif (!$configExiste): ?>
    <strong>No hay cuentas de correo:</strong> pulsa el engranaje <i class="fas fa-gear"></i> (arriba a la derecha) y agrega la primera cuenta.
    <?php else: ?>
    <strong>Cuenta incompleta:</strong> revisa host, usuario y contraseña de la cuenta en el <i class="fas fa-gear"></i>.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // El selector de modo vive en el topbar, junto al título del módulo
      // (app/views/layout/header.php): es de qué trata la pantalla entera. ?>

<?php if ($modoCorreo === 'descargas'): ?>
<?php // Los identificadores del bloque siguen llamándose general-*: son
      // internos (JS y CSS) y renombrarlos no cambiaría nada para quien usa
      // la pantalla. El nombre visible del modo es "Descargas". ?>
<div class="card" id="general-box" style="margin-bottom:8px;padding:10px 12px;">
    <div style="display:flex;align-items:end;gap:9px;flex-wrap:wrap;">
        <label style="font-size:11px;font-weight:700;">Desde (fecha del correo)<input type="date" id="general-desde" class="form-control" value="<?= date('Y-m-01') ?>"></label>
        <label style="font-size:11px;font-weight:700;">Hasta<input type="date" id="general-hasta" class="form-control" value="<?= date('Y-m-d') ?>"></label>
        <label style="font-size:11px;font-weight:700;">Correo a buscar (opcional)<input type="email" id="general-correo" class="form-control" placeholder="proveedor@dominio.com" autocomplete="off" style="min-width:220px;"></label>
        <button type="button" id="general-iniciar" class="btn btn-primary btn-sm" <?= $listo ? '' : 'disabled' ?>><i class="fas fa-play"></i> Iniciar búsqueda completa</button>
        <button type="button" id="general-pausar" class="btn btn-outline btn-sm" style="display:none;"><i class="fas fa-pause"></i> Pausar</button>
        <button type="button" id="general-reanudar" class="btn btn-primary btn-sm" style="display:none;"><i class="fas fa-play"></i> Reanudar</button>
        <button type="button" id="general-cancelar" class="btn btn-outline btn-sm" style="display:none;color:#b91c1c;border-color:#fecaca;"><i class="fas fa-stop"></i> Cancelar</button>
        <button type="button" id="general-historial" class="btn btn-outline btn-sm"><i class="fas fa-clock-rotate-left"></i> Historial de incidencias</button>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">Cuenta seleccionada · todas las carpetas salvo Borradores, Enviados, Spam y Papelera · el correo indicado se busca en remitente, CC y Reply-To · organiza por fecha de emisión del XML.</div>
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted);margin-top:5px;cursor:pointer;">
        <input type="checkbox" id="general-incluir-procesados" style="margin:0;">
        Volver a revisar los correos ya procesados <span style="color:#94a3b8;">(normalmente se saltan: son los que ya se leyeron en corridas anteriores)</span>
    </label>
    <div id="general-estado" style="margin-top:8px;font-size:12px;color:var(--navy);"></div>
    <div style="height:8px;background:#e2e8f0;border-radius:8px;overflow:hidden;margin-top:5px;"><div id="general-barra" style="height:100%;width:0;background:linear-gradient(90deg,var(--navy),var(--gold));transition:width .25s;"></div></div>
    <div id="general-incidencias" style="font-size:11px;color:#9a3412;margin-top:6px;max-height:72px;overflow:auto;"></div>
</div>
<?php endif; ?>

<div class="correo-layout correo-modo-<?= htmlspecialchars($modoCorreo) ?>" id="correo-layout">

    <!-- Divisores arrastrables (doble clic para restaurar) -->
    <div class="correo-resizer vert rz-lista" data-rz="lista" title="Arrastra para redimensionar · doble clic para restaurar"></div>
    <div class="correo-resizer vert rz-bandeja" data-rz="bandeja" title="Arrastra para redimensionar · doble clic para restaurar"></div>
    <div class="correo-resizer horiz rz-inferior" data-rz="inferior" title="Arrastra para redimensionar · doble clic para restaurar"></div>

    <!-- ── Panel 1: buscador + lista de correos ── -->
    <div class="correo-panel correo-lista">
        <?php /* El panel de carpetas se pinta según la preferencia guardada ANTES
                 del primer render: si se dejaba al JS del final de la página, el
                 navegador alcanzaba a pintarlo abierto y se veía la animación de
                 cierre en cada recarga. 'correo-sin-animacion' suprime además
                 cualquier transición hasta que termina el montaje inicial. */ ?>
        <script>
        (function () {
            var panel = document.currentScript.parentElement;
            panel.classList.add('correo-sin-animacion');
            try {
                if (localStorage.getItem('correoCarpetasVisibles') === '0') {
                    panel.classList.add('carpetas-cerradas');
                }
            } catch (e) {}
        })();
        </script>
        <aside class="correo-carpetas-pane" id="correo-carpetas-pane" aria-label="Carpetas del correo">
            <div class="correo-carpetas-head">
                <span><i class="fas fa-folder-tree"></i> Carpetas</span>
                <span style="gap:1px;">
                    <button type="button" id="btn-colapsar-carpetas" title="Cerrar todas las carpetas" aria-label="Cerrar todas las carpetas">
                        <i class="fas fa-compress"></i>
                    </button>
                    <button type="button" id="btn-cerrar-carpetas" title="Ocultar carpetas" aria-label="Ocultar carpetas">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </span>
            </div>
            <div class="correo-carpetas-tree" id="correo-carpetas-tree">
                <div class="correo-carpetas-cargando">
                    <i class="fas fa-spinner fa-spin"></i> Cargando…
                </div>
            </div>
        </aside>
        <?php if ($ppNav): ?>
        <!-- Tarjeta "Facturas por pagar": factura seleccionada + navegación
             (ancho de la columna del correo; persiste al cambiar de cuenta) -->
        <div id="pp-nav" style="flex-shrink:0;padding:5px 8px;background:#f8fafc;border-bottom:1px solid var(--border);border-left:3px solid var(--gold);">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                <span id="pp-circulo" title="" style="width:10px;height:10px;border-radius:50%;flex:none;background:#94a3b8;box-shadow:0 0 0 2px rgba(148,163,184,.2);"></span>
                <div id="pp-numero" style="flex:1;min-width:0;font-weight:700;color:var(--navy);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                <button type="button" id="pp-cerrar" title="Cerrar" style="background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer;line-height:1;padding:0 2px;flex:none;">&times;</button>
            </div>
            <div id="pp-proveedor" style="font-size:11.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:2px;"></div>
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:6px;margin-bottom:4px;">
                <span id="pp-fecha" style="font-size:11.5px;color:var(--text-muted);"></span>
                <span id="pp-monto" style="font-weight:700;color:var(--navy);font-size:13px;white-space:nowrap;"></span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <button type="button" class="btn btn-outline btn-sm" id="pp-prev" title="Factura anterior del listado" style="padding:3px 9px;">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <span id="pp-pos" title="<?= htmlspecialchars($ppNav['listado'] . ($ppNav['semana'] !== '' ? ' · ' . $ppNav['semana'] : '')) ?>"
                      style="font-size:12px;color:var(--text-muted);flex:1;text-align:center;white-space:nowrap;"></span>
                <button type="button" class="btn btn-primary btn-sm" id="pp-buscar" title="Buscar este número en el correo" style="padding:3px 10px;">
                    <i class="fas fa-magnifying-glass"></i>
                </button>
                <button type="button" class="btn btn-outline btn-sm" id="pp-next" title="Siguiente factura del listado" style="padding:3px 9px;">
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>
        <div class="correo-buscador">
            <div class="correo-cuenta-fila">
                <button type="button" class="btn btn-outline btn-sm" id="btn-carpetas-toggle"
                        title="Mostrar u ocultar las carpetas del correo" aria-label="Mostrar u ocultar carpetas">
                    <i class="fas fa-folder-tree"></i>
                </button>
            <?php if (count($cuentas) > 1): ?>
            <select id="sel-cuenta" class="form-control" style="font-size:11.5px;padding:4px 8px;"
                    title="Cuenta de correo con la que trabajas">
                <?php foreach ($cuentas as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $cuentaActivaId ? 'selected' : '' ?>>
                    📧 <?= htmlspecialchars($c['nombre']) ?> — <?= htmlspecialchars($c['usuario']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php elseif (count($cuentas) === 1): ?>
            <div style="font-size:10.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                 title="<?= htmlspecialchars($cuentas[0]['usuario']) ?>">
                📧 <?= htmlspecialchars($cuentas[0]['usuario']) ?>
            </div>
            <?php endif; ?>
                <div class="correo-carpeta-actual" id="correo-carpeta-actual" title="Carpeta seleccionada">
                    <i class="fas fa-inbox"></i>
                    <span>Entrada</span>
                </div>
            </div>
            <input type="text" id="buscar-texto" class="form-control"
                   placeholder="Buscar correos…"
                   style="font-size:12px;padding:5px 8px;" autocomplete="off" <?= $listo ? '' : 'disabled' ?>>
            <div class="fila correo-filtros">
                <select id="sel-ambito" class="form-control" <?= $listo ? '' : 'disabled' ?>
                        title="Qué partes del correo se revisarán">
                    <option value="asunto_remitente" selected>General</option>
                    <option value="asunto">Solo asunto</option>
                    <option value="remitente">Por correo</option>
                    <option value="todo">Todo (más lento)</option>
                </select>
                <select id="sel-alcance" class="form-control" <?= $listo ? '' : 'disabled' ?>
                        title="Buscar solo en la carpeta seleccionada o en todo el buzón">
                    <option value="carpeta">Esta carpeta</option>
                    <option value="buzon" selected>Todo el buzón</option>
                </select>
                <select id="sel-dias" class="form-control" <?= $listo ? '' : 'disabled' ?>>
                    <?php foreach ([7, 14, 30, 60, 90, 180, 365] as $d): ?>
                    <option value="<?= $d ?>" <?= $d === $diasDefault ? 'selected' : '' ?>><?= $d ?> días</option>
                    <?php endforeach; ?>
                    <option value="0">Todas las fechas</option>
                </select>
                <button type="button" class="btn btn-primary btn-sm" id="btn-cargar" title="Buscar en el buzón" <?= $listo ? '' : 'disabled' ?>>
                    <i class="fas fa-magnifying-glass"></i>
                </button>
            </div>
            <div class="fila" style="align-items:center;">
                <label style="font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:4px;cursor:pointer;">
                    <input type="checkbox" id="cb-correos-visibles" title="Seleccionar los correos visibles"> todos
                </label>
                <span id="correos-info" style="font-size:12px;color:var(--text-muted);flex:1;text-align:right;"></span>
                <button type="button" class="btn btn-primary btn-sm" id="btn-procesar-sel" disabled
                        title="Procesar los correos seleccionados">
                    <i class="fas fa-download"></i> <span id="sel-count">0</span>
                </button>
            </div>
            <div id="sync-info" style="font-size:11.5px;color:var(--text-muted);"></div>
        </div>
        <div class="correo-panel-body" id="lista-correos">
            <div class="correo-vacio-panel" id="lista-vacia">
                <i class="fas fa-envelopes-bulk"></i>
                <?= $listo ? 'Busca en el buzón para listar correos' : 'Configura el buzón para empezar' ?>
            </div>
        </div>
    </div>

    <!-- ── Panel 2: contenido del correo ── -->
    <div class="correo-panel">
        <div class="correo-panel-head">
            <i class="fas fa-envelope-open" style="color:var(--navy-light);"></i> Contenido del correo
        </div>
        <div class="correo-vacio-panel" id="contenido-vacio">
            <i class="fas fa-envelope"></i>
            Selecciona un correo de la lista
        </div>
        <div class="correo-contenido-meta" id="contenido-meta" style="display:none;">
            <div id="ct-asunto" style="font-size:13px;font-weight:700;color:var(--navy);"></div>
            <div id="ct-meta" style="font-size:11px;color:var(--text-muted);margin-top:2px;"></div>
            <div id="ct-adjuntos" style="margin-top:4px;display:flex;gap:3px;flex-wrap:wrap;"></div>
            <div style="margin-top:5px;display:flex;gap:4px;align-items:center;">
                <button type="button" class="btn btn-primary btn-sm" id="btn-procesar-uno">
                    <i class="fas fa-download"></i> Procesar este correo
                </button>
                <span id="ct-badge"></span>
            </div>
        </div>
        <div class="correo-panel-body" id="contenido-body" style="display:none;">
            <div class="correo-cuerpo" id="ct-cuerpo"></div>
        </div>
    </div>

    <!-- ── Panel 3: bandeja de revisión ── -->
    <div class="correo-panel correo-facturas-panel">
        <div class="correo-panel-head">
            <i class="fas fa-inbox" style="color:var(--navy-light);"></i> Bandeja de revisión
            <?php if ($pendientes > 0): ?>
            <span class="badge badge-navy" style="font-size:9px;padding:1px 5px;"><?= $pendientes ?></span>
            <?php endif; ?>
            <div class="correo-bandeja-acciones" style="margin-left:auto;display:flex;gap:3px;align-items:center;">
                <select id="sel-semana-imp" class="form-control" title="Semana a la que se asignan las facturas al importar"
                        style="font-size:10px;padding:2px 4px;max-width:112px;" <?= $pendientes === 0 ? 'disabled' : '' ?>>
                    <option value="" <?= (int) ($semanaActiva ?? 0) === 0 ? 'selected' : '' ?>>Semana…</option>
                    <?php foreach ($semanas as $sem): ?>
                    <option value="<?= (int) $sem['id'] ?>" <?= (int) ($semanaActiva ?? 0) === (int) $sem['id'] ? 'selected' : '' ?>><?= htmlspecialchars($sem['nombre']) ?></option>
                    <?php endforeach; ?>
                    <option value="nueva">➕ Nueva…</option>
                </select>
                <input type="checkbox" id="cb-todas" title="Seleccionar todas" <?= empty($bandeja) ? 'disabled' : '' ?>>
                <button type="button" class="btn btn-primary btn-sm" id="btn-importar" title="Importar seleccionadas"
                        <?= $pendientes === 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-file-import"></i>
                </button>
                <button type="button" class="btn btn-outline btn-sm" id="btn-descartar" title="Descartar seleccionadas"
                        style="color:#b91c1c;border-color:#fed7d7;" <?= empty($bandeja) ? 'disabled' : '' ?>>
                    <i class="fas fa-trash-can"></i>
                </button>
            </div>
        </div>
        <div class="correo-panel-body">
            <?php if (empty($bandeja)): ?>
            <div class="correo-vacio-panel">
                <i class="fas fa-inbox"></i>
                Procesa correos para llenar la bandeja
            </div>
            <?php else: ?>
                <?php foreach ($bandeja as $fila): ?>
                <div class="bandeja-item">
                    <input type="checkbox" class="cb-row" value="<?= (int) $fila['id'] ?>"
                           data-estado="<?= htmlspecialchars($fila['estado']) ?>">
                    <div class="bandeja-item-main"
                         title="<?= htmlspecialchars(($fila['remitente'] ?? '') . ' — ' . ($fila['asunto'] ?? '')) ?>">
                        <div class="bandeja-num"><?= htmlspecialchars($fila['numero_corto'] ?? '—') ?></div>
                        <div class="bandeja-prov">
                            <?= htmlspecialchars($fila['proveedor'] ?? '—') ?> · <?= htmlspecialchars($fila['fecha_emision'] ?? '') ?>
                        </div>
                    </div>
                    <span class="bandeja-total"><?= number_format((float) ($fila['total'] ?? 0), 2) ?></span>
                    <i class="fas fa-file-pdf"
                       style="color:<?= !empty($fila['archivo_pdf']) ? '#16a34a' : '#cbd5e1' ?>;"
                       title="<?= !empty($fila['archivo_pdf']) ? htmlspecialchars(basename($fila['archivo_pdf'])) : 'Sin PDF' ?>"></i>
                    <?php if ($fila['estado'] === 'rechazada'): ?>
                    <i class="fas fa-ban" style="color:#dc2626;" title="Rechazada por Hacienda — no se puede importar"></i>
                    <?php elseif ($fila['estado'] === 'otra_cedula'): ?>
                    <i class="fas fa-user-slash" style="color:#ea580c;" title="El receptor no es la cédula de la empresa — no se puede importar"></i>
                    <?php else: ?>
                    <i class="fas fa-clock" style="color:var(--navy-light);" title="Pendiente de importar"></i>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Panel 4: actividad (progreso, resumen, historial) ── -->
    <div class="correo-panel correo-inferior correo-facturas-panel">
        <div class="correo-panel-head">
            <i class="fas fa-wave-square" style="color:var(--navy-light);"></i> Actividad
            <?php if (!empty($historial)): ?>
            <span style="font-weight:400;font-size:11px;color:var(--text-muted);">— historial: <?= count($historial) ?></span>
            <?php endif; ?>
        </div>
        <div class="correo-panel-body correo-actividad-body" style="padding:6px 8px;">

            <div id="buscar-resumen" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:5px 8px;font-size:11px;color:#166534;margin-bottom:6px;"></div>

            <div id="correo-progress" style="display:none;margin-bottom:6px;">
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-bottom:3px;flex-wrap:wrap;gap:4px;">
                    <span id="cor-status" style="font-weight:700;color:var(--navy);">Preparando…</span>
                    <span id="cor-counts" style="color:var(--text-muted);"></span>
                </div>
                <div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;">
                    <div id="cor-bar" style="background:linear-gradient(90deg,#0c2461,#1e3a8a);height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <div id="cor-detail" style="font-size:10.5px;color:var(--text-muted);margin-top:3px;"></div>
            </div>

            <?php if (!empty($historial)): ?>
            <table class="data-table correo-actividad-tabla" style="font-size:11.5px;">
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Proveedor</th>
                        <th class="right">Total</th>
                        <th class="center">Estado</th>
                        <th class="center"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $fila): ?>
                    <tr>
                        <td style="font-weight:700;color:var(--navy);"><?= htmlspecialchars($fila['numero_corto'] ?? '—') ?></td>
                        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= htmlspecialchars($fila['proveedor'] ?? '—') ?>
                        </td>
                        <td class="right"><?= number_format((float) ($fila['total'] ?? 0), 2) ?></td>
                        <td class="center">
                            <?php if ($fila['estado'] === 'importada'): ?>
                            <span class="badge badge-green"><i class="fas fa-check-circle"></i> Importada</span>
                            <?php else: ?>
                            <span class="badge" style="background:#fee2e2;color:#b91c1c;"><i class="fas fa-times-circle"></i> Descartada</span>
                            <?php endif; ?>
                        </td>
                        <td class="center">
                            <?php if ($fila['estado'] === 'importada' && !empty($fila['importacion_id'])): ?>
                            <a href="<?= $baseUrl ?>/facturas?importacion_id=<?= (int) $fila['importacion_id'] ?>"
                               class="btn btn-outline btn-sm" title="Ver la importación">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php elseif (empty($bandeja)): ?>
            <div style="font-size:11.5px;color:var(--text-muted);">
                Aquí verás el progreso de los procesos y el historial de la bandeja.
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- Visor en memoria: el servidor transmite el adjunto y no guarda copias. -->
<div id="modal-adjunto" role="dialog" aria-modal="true" aria-labelledby="visor-adjunto-nombre"
     style="display:none;position:fixed;inset:0;background:rgba(12,36,97,.62);z-index:500;align-items:center;justify-content:center;padding:18px;">
    <div style="background:#fff;border-radius:12px;width:min(1100px,96vw);height:min(820px,94vh);display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(12,36,97,.35);overflow:hidden;">
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:#f8fafc;">
            <i class="fas fa-eye" style="color:var(--gold);"></i>
            <div id="visor-adjunto-nombre" style="font-size:13px;font-weight:700;color:var(--navy);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;"></div>
            <button type="button" class="btn btn-outline btn-sm" id="visor-adjunto-descargar" disabled
                    title="Guardar voluntariamente una copia en el equipo">
                <i class="fas fa-download"></i> Descargar
            </button>
            <button type="button" id="visor-adjunto-cerrar" aria-label="Cerrar vista previa"
                    style="background:none;border:none;font-size:24px;color:#64748b;cursor:pointer;line-height:1;padding:0 3px;">&times;</button>
        </div>
        <div id="visor-adjunto-contenido" style="position:relative;flex:1;min-height:0;background:#e2e8f0;">
            <div id="visor-adjunto-estado" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;color:#475569;font-size:13px;">
                <i class="fas fa-spinner fa-spin"></i> Leyendo el archivo desde el correo…
            </div>
            <iframe id="visor-adjunto-pdf" title="Vista previa PDF"
                    style="display:none;border:0;width:100%;height:100%;background:#fff;"></iframe>
            <pre id="visor-adjunto-xml"
                 style="display:none;margin:0;width:100%;height:100%;box-sizing:border-box;overflow:auto;padding:16px;background:#fff;color:#0f172a;font:12px/1.5 Consolas,Monaco,monospace;white-space:pre-wrap;word-break:break-word;"></pre>
        </div>
        <div style="padding:6px 12px;border-top:1px solid var(--border);font-size:10.5px;color:var(--text-muted);background:#fff;">
            Vista temporal: no se guarda en la base de datos ni se crea una copia en el servidor.
        </div>
    </div>
</div>

<?php if ($modoCorreo === 'descargas'): ?>
<div id="modal-incidencias" role="dialog" aria-modal="true" aria-labelledby="incidencias-titulo"
     style="display:none;position:fixed;inset:0;background:rgba(12,36,97,.55);z-index:520;align-items:center;justify-content:center;padding:18px;">
    <div style="background:#fff;border-radius:12px;width:min(1250px,97vw);height:min(800px,94vh);display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(12,36,97,.35);overflow:hidden;">
        <div style="display:flex;align-items:center;gap:10px;padding:12px 15px;border-bottom:1px solid var(--border);background:#f8fafc;">
            <i class="fas fa-triangle-exclamation" style="color:var(--gold);"></i>
            <div style="flex:1;min-width:0;">
                <div id="incidencias-titulo" style="font-size:14px;font-weight:800;color:var(--navy);">Historial de incidencias</div>
                <div id="incidencias-resumen" style="font-size:10.5px;color:var(--text-muted);">Cargando…</div>
            </div>
            <button type="button" id="incidencias-cerrar" aria-label="Cerrar"
                    style="background:none;border:none;font-size:24px;color:#64748b;cursor:pointer;line-height:1;padding:0 3px;">&times;</button>
        </div>
        <div style="display:flex;gap:8px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--border);flex-wrap:wrap;">
            <input type="text" id="incidencias-q" class="form-control" style="min-width:260px;flex:1;font-size:12px;"
                   placeholder="Buscar por asunto, motivo, remitente o carpeta">
            <select id="incidencias-tipo" class="form-control" style="width:auto;min-width:170px;font-size:12px;">
                <option value="">Todos los tipos</option>
            </select>
            <button type="button" id="incidencias-buscar" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
        </div>
        <div style="flex:1;min-height:0;overflow:auto;">
            <table class="data-table" style="min-width:1050px;">
                <thead style="position:sticky;top:0;z-index:2;background:#f3f7fb;">
                    <tr><th>Fecha</th><th>Lote</th><th>Tipo</th><th>Asunto del correo</th><th>Motivo</th><th>Carpeta</th><th></th></tr>
                </thead>
                <tbody id="incidencias-body"><tr><td colspan="7" style="padding:30px;text-align:center;color:var(--text-muted);">Cargando…</td></tr></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:center;align-items:center;gap:8px;padding:9px 14px;border-top:1px solid var(--border);background:#f8fafc;">
            <button type="button" id="incidencias-prev" class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i> Anterior</button>
            <span id="incidencias-pagina" style="font-size:11.5px;color:var(--text-muted);min-width:110px;text-align:center;">Página 1 de 1</span>
            <button type="button" id="incidencias-next" class="btn btn-outline btn-sm">Siguiente <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal de configuración (engranaje de la barra superior). -->
<div id="modal-config" style="display:none;position:fixed;inset:0;background:rgba(12,36,97,.45);z-index:400;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:540px;width:92%;padding:20px 22px;box-shadow:0 16px 48px rgba(12,36,97,.3);max-height:92vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <div style="font-size:15px;font-weight:800;color:var(--navy);">
                <i class="fas fa-gear" style="color:var(--gold);margin-right:6px;"></i>Configuración del módulo
            </div>
            <button type="button" id="cfg-cerrar" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>

        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px;">
            <i class="fas fa-folder-open" style="margin-right:4px;color:var(--gold);"></i>Carpeta raíz de XML y PDF
        </label>
        <div style="display:flex;gap:6px;">
            <input type="text" id="cfg-carpeta" class="form-control" style="font-size:13px;flex:1;"
                   placeholder="Elige una carpeta con «Examinar»…"
                   value="<?= htmlspecialchars($configLocal['carpeta_destino']) ?>">
            <button type="button" class="btn btn-outline btn-sm" id="cfg-examinar" style="white-space:nowrap;">
                <i class="fas fa-folder-open"></i> Examinar
            </button>
        </div>

        <!-- Explorador de carpetas (se abre con «Examinar») -->
        <div id="cfg-explorador" style="display:none;border:1px solid var(--border,#e2e8f0);border-radius:8px;margin-top:8px;overflow:hidden;">
            <div style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#f8fafc;border-bottom:1px solid var(--border,#e2e8f0);">
                <button type="button" class="btn btn-outline btn-sm" id="exp-subir" title="Subir un nivel" style="padding:2px 8px;">
                    <i class="fas fa-arrow-up"></i>
                </button>
                <span id="exp-ruta" style="font-size:11.5px;color:var(--navy);font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:rtl;text-align:left;">Este equipo</span>
            </div>
            <div id="exp-lista" style="max-height:210px;overflow-y:auto;font-size:12.5px;background:#fff;">
                <div style="padding:14px;text-align:center;color:var(--text-muted);font-size:11.5px;">Cargando…</div>
            </div>
            <div style="display:flex;gap:6px;padding:6px 8px;background:#f8fafc;border-top:1px solid var(--border,#e2e8f0);align-items:center;">
                <input type="text" id="exp-nueva" class="form-control" placeholder="Nueva carpeta…" style="font-size:11.5px;padding:4px 8px;flex:1;">
                <button type="button" class="btn btn-outline btn-sm" id="exp-crear" title="Crear carpeta aquí" style="padding:2px 8px;">
                    <i class="fas fa-folder-plus"></i>
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="exp-usar" title="Usar la carpeta abierta">
                    <i class="fas fa-check"></i> Usar esta
                </button>
            </div>
        </div>

        <div style="font-size:11px;color:var(--text-muted);margin:6px 0 14px;">
            Dentro de esta raíz se crea <code>AAAA/MM MES/Facturas</code> o
            <code>AAAA/MM MES/Notas de crédito</code>. El nombre se mantiene como
            <code>FE_PROVEEDOR_120726_00004354</code> o <code>NC_...</code>.
        </div>

        <!-- ── Actualización automática del índice (Tarea Programada de Windows) ── -->
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px;">
            <i class="fas fa-rotate" style="margin-right:4px;color:var(--gold);"></i>Actualizar el índice automáticamente
        </label>
        <div id="auto-box" style="font-size:12px;background:#f3f7fb;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px 12px;">
            <div id="auto-estado" style="color:var(--text-muted);">Consultando…</div>
            <div id="auto-controles" style="display:none;margin-top:8px;align-items:center;gap:8px;flex-wrap:wrap;">
                <label style="font-size:11.5px;color:var(--navy);font-weight:600;">Cada
                    <select id="auto-intervalo" class="form-control" style="display:inline-block;width:auto;font-size:12px;padding:2px 6px;margin:0 4px;">
                        <option value="5">5 min</option>
                        <option value="10" selected>10 min</option>
                        <option value="15">15 min</option>
                        <option value="30">30 min</option>
                        <option value="60">1 hora</option>
                    </select>
                </label>
                <button type="button" class="btn btn-primary btn-sm" id="auto-activar"><i class="fas fa-play"></i> Activar</button>
                <button type="button" class="btn btn-outline btn-sm" id="auto-desactivar" style="display:none;color:#b91c1c;border-color:#fed7d7;"><i class="fas fa-stop"></i> Desactivar</button>
            </div>
            <div id="auto-msg" style="display:none;font-size:11.5px;margin-top:8px;border-radius:6px;padding:6px 10px;"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin:4px 0 14px;">
            Mantiene el índice al día en segundo plano <strong>aunque cierres esta página</strong>. Requiere que el equipo y XAMPP (MySQL) estén encendidos.
        </div>

        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:4px;">
            <i class="fas fa-id-card" style="margin-right:4px;color:var(--gold);"></i>Verificación de cédula
        </label>
        <div style="font-size:12px;background:#f3f7fb;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:8px 12px;">
            <?php if ($sociedadActiva): ?>
            Verificando contra <strong><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>
            (cédula <strong><?= htmlspecialchars($sociedadActiva['cedula']) ?></strong>).
            <?php else: ?>
            <span style="color:#b45309;">Sin sociedad activa: no se verifica el receptor de las facturas.</span>
            <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin:4px 0 14px;">
            La cédula la define la sociedad activa, que se elige en
            <a href="<?= $baseUrl ?>/" style="color:var(--navy-light);">Inicio</a>.
            Las facturas a nombre de otra cédula quedan bloqueadas en la bandeja.
        </div>

        <!-- ── Cuentas de correo ── -->
        <div style="border-top:1px solid var(--border,#e2e8f0);margin:2px 0 12px;"></div>
        <label style="display:block;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">
            <i class="fas fa-at" style="margin-right:4px;color:var(--gold);"></i>Cuentas de correo
        </label>

        <?php // El ⚙ administra TODOS los buzones —también los de otras
              // empresas—, porque es el único lugar donde se corrige una
              // asignación equivocada. El resto del módulo solo ve los de la
              // empresa en curso. ?>
        <div id="cta-lista" style="border:1px solid var(--border,#e2e8f0);border-radius:8px;overflow:hidden;margin-bottom:8px;<?= empty($cuentasAdmin) ? 'display:none;' : '' ?>">
            <?php foreach ($cuentasAdmin as $c): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;">
                <i class="fas <?= (int) $c['id'] === $cuentaActivaId ? 'fa-circle-check' : 'fa-envelope' ?>"
                   style="color:<?= (int) $c['id'] === $cuentaActivaId ? 'var(--ok,#16a34a)' : '#94a3b8' ?>;width:14px;"
                   title="<?= (int) $c['id'] === $cuentaActivaId ? 'Cuenta en uso' : '' ?>"></i>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:var(--navy);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($c['nombre']) ?>
                    </div>
                    <div style="font-size:10.5px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($c['usuario']) ?> · <?= htmlspecialchars($c['host']) ?>
                    </div>
                    <?php
                    // A qué empresas atiende. Es lo que explica que un buzón
                    // esté aquí y no aparezca en el módulo.
                    $socNombres = $c['sociedades_nombres'] ?? [];
                    $atiendeActual = in_array((int) ($sociedadActiva['id'] ?? 0), array_map('intval', $c['sociedades'] ?? []), true);
                    ?>
                    <div style="font-size:10px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
                                color:<?= $atiendeActual ? 'var(--ok)' : 'var(--warn)' ?>;">
                        <i class="fas <?= $atiendeActual ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"
                           style="margin-right:3px;"></i>
                        <?php if ($socNombres): ?>
                        <?= htmlspecialchars(implode(' · ', $socNombres)) ?>
                        <?php else: ?>
                        Sin empresa asignada — no aparece en ningún módulo
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm cta-editar" style="padding:2px 8px;" title="Editar"
                        data-id="<?= (int) $c['id'] ?>"
                        data-nombre="<?= htmlspecialchars($c['nombre']) ?>"
                        data-host="<?= htmlspecialchars($c['host']) ?>"
                        data-puerto="<?= (int) $c['puerto'] ?>"
                        data-usuario="<?= htmlspecialchars($c['usuario']) ?>"
                        data-carpeta="<?= htmlspecialchars($c['carpeta']) ?>"
                        data-sociedades="<?= htmlspecialchars(implode(',', $c['sociedades'] ?? [])) ?>">
                    <i class="fas fa-pen"></i>
                </button>
                <button type="button" class="btn btn-outline btn-sm cta-eliminar" style="padding:2px 8px;color:#b91c1c;border-color:#fed7d7;"
                        title="Eliminar" data-id="<?= (int) $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre']) ?>">
                    <i class="fas fa-trash-can"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="button" class="btn btn-outline btn-sm" id="cta-nueva" style="margin-bottom:8px;">
            <i class="fas fa-plus"></i> Agregar cuenta
        </button>

        <!-- Form de cuenta (crear/editar) -->
        <div id="cta-form" style="display:none;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:10px 12px;margin-bottom:12px;background:#f8fafc;">
            <input type="hidden" id="cta-id" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div style="grid-column:1 / -1;">
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Nombre de la cuenta</label>
                    <input type="text" id="cta-nombre" class="form-control" style="font-size:12px;" placeholder="Facturas MG">
                </div>
                <div>
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Host IMAP</label>
                    <input type="text" id="cta-host" class="form-control" style="font-size:12px;" placeholder="mail.tuempresa.com">
                </div>
                <div>
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Puerto</label>
                    <input type="number" id="cta-puerto" class="form-control" style="font-size:12px;" value="993">
                </div>
                <div>
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Usuario (correo)</label>
                    <input type="text" id="cta-usuario" class="form-control" style="font-size:12px;" placeholder="facturas@tuempresa.com">
                </div>
                <div>
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Contraseña</label>
                    <input type="password" id="cta-password" class="form-control" style="font-size:12px;" autocomplete="new-password">
                </div>
                <div>
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Carpeta inicial</label>
                    <input type="text" id="cta-carpeta" class="form-control" style="font-size:12px;" value="INBOX">
                </div>
                <div style="grid-column:1 / -1;">
                    <label style="font-size:10.5px;font-weight:700;color:var(--navy);">Sociedades que usan este buzón</label>
                    <div id="cta-sociedades" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                        <?php foreach (($sociedadesTodas ?? []) as $s): ?>
                        <label style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;cursor:pointer;">
                            <input type="checkbox" class="cta-soc" value="<?= (int) $s['id'] ?>" style="margin:0;">
                            <?= htmlspecialchars($s['nombre']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px;">
                        Un mismo buzón puede recibir facturas de varias empresas del grupo. Solo aparece en las que marque.
                    </div>
                </div>
            </div>
            <div id="cta-msg" style="display:none;font-size:11.5px;border-radius:6px;padding:6px 10px;margin-top:8px;"></div>
            <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px;">
                <button type="button" class="btn btn-outline btn-sm" id="cta-cancelar">Cancelar</button>
                <button type="button" class="btn btn-outline btn-sm" id="cta-probar">
                    <i class="fas fa-plug"></i> Probar conexión
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="cta-guardar">
                    <i class="fas fa-check"></i> Guardar cuenta
                </button>
            </div>
        </div>

        <div id="cfg-msg" style="display:none;font-size:12px;border-radius:8px;padding:8px 12px;margin-bottom:12px;"></div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button type="button" class="btn btn-outline btn-sm" id="cfg-cancelar">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" id="cfg-guardar">
                <i class="fas fa-check"></i> Guardar
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE = '<?= $baseUrl ?>';
    var MODO_CORREO = '<?= $modoCorreo ?>';
    var CHUNK_PROCESAR = 8;
    var BATCH_LIMIT = 10;
    var CARPETAS_INICIALES = <?= json_encode(array_values($carpetasCorreo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    // ── Elementos ──
    var btnCargar    = document.getElementById('btn-cargar');
    var inputBuscar  = document.getElementById('buscar-texto');
    var selAmbito    = document.getElementById('sel-ambito');
    var selAlcance   = document.getElementById('sel-alcance');
    var selDias      = document.getElementById('sel-dias');
    var listaEl      = document.getElementById('lista-correos');
    var infoCorreos  = document.getElementById('correos-info');
    var btnProcesar  = document.getElementById('btn-procesar-sel');
    var selCount     = document.getElementById('sel-count');
    var cbVisibles   = document.getElementById('cb-correos-visibles');
    var listaPanel   = document.querySelector('.correo-lista');
    var carpetasTree = document.getElementById('correo-carpetas-tree');
    var carpetaActualEl = document.getElementById('correo-carpeta-actual');
    var btnCarpetasToggle = document.getElementById('btn-carpetas-toggle');
    var btnCerrarCarpetas = document.getElementById('btn-cerrar-carpetas');

    var ctVacio      = document.getElementById('contenido-vacio');
    var ctMetaBox    = document.getElementById('contenido-meta');
    var ctBody       = document.getElementById('contenido-body');
    var ctAsunto     = document.getElementById('ct-asunto');
    var ctMeta       = document.getElementById('ct-meta');
    var ctAdjuntos   = document.getElementById('ct-adjuntos');
    var ctCuerpo     = document.getElementById('ct-cuerpo');
    var ctBadge      = document.getElementById('ct-badge');
    var btnProcUno   = document.getElementById('btn-procesar-uno');
    var modalAdjunto = document.getElementById('modal-adjunto');
    var visorNombre  = document.getElementById('visor-adjunto-nombre');
    var visorEstado  = document.getElementById('visor-adjunto-estado');
    var visorPdf     = document.getElementById('visor-adjunto-pdf');
    var visorXml     = document.getElementById('visor-adjunto-xml');
    var visorCerrar  = document.getElementById('visor-adjunto-cerrar');
    var visorDescargar = document.getElementById('visor-adjunto-descargar');

    var btnImportar  = document.getElementById('btn-importar');
    var btnDescartar = document.getElementById('btn-descartar');
    var cbTodas      = document.getElementById('cb-todas');
    var panel        = document.getElementById('correo-progress');
    var elStatus     = document.getElementById('cor-status');
    var elCounts     = document.getElementById('cor-counts');
    var elBar        = document.getElementById('cor-bar');
    var elDetail     = document.getElementById('cor-detail');
    var elResumen    = document.getElementById('buscar-resumen');

    // ── Estado ──
    var correos = [];
    var seleccion = {};      // clave -> {uid, carpeta} (el UID solo es único por carpeta)
    var totalBuzon = 0;
    var correoActual = null;
    var textoBusquedaCargada = '';
    var carpetaActiva = 'INBOX';
    var carpetaNombreActiva = 'Entrada';
    var carpetasDatos = Array.isArray(CARPETAS_INICIALES) ? CARPETAS_INICIALES : [];
    var carpetasExpandidas = {};
    var paginaActual = 1;
    var paginasTotal = 1;
    var porPagina = 500;
    var contextoBusquedaActivo = {};
    var visorObjectUrl = '';
    var visorAbort = null;
    var visorArchivoNombre = '';
    var visorSolicitud = 0;

    function setStatus(t) { elStatus.textContent = t; }
    function setBar(p) { elBar.style.width = Math.max(0, Math.min(100, p)) + '%'; }
    function sleep(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

    // Cuenta de correo con la que se trabaja: viaja en cada petición
    var CUENTA_ID = <?= (int) $cuentaActivaId ?>;
    var SOCIEDAD_ACTIVA_ID = <?= (int) ($sociedadActiva['id'] ?? 0) ?>;
    var CLAVE_CARPETAS = 'correoCarpetasAbiertas_' + CUENTA_ID;

    function postJson(url, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        if (!fd.has('cuenta_id')) fd.append('cuenta_id', CUENTA_ID);
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            });
    }

    function etiquetaEspecial(ruta, etiqueta) {
        var r = String(ruta || '').toLowerCase();
        if (r === 'inbox') return 'Entrada';
        if (/(^|[.\/])(drafts?|borradores?)$/.test(r)) return 'Borradores';
        if (/(^|[.\/])(sent|sent items|enviados?)$/.test(r)) return 'Enviados';
        if (/(^|[.\/])(spam|junk)$/.test(r)) return 'SPAM';
        if (/(^|[.\/])(trash|papelera)$/.test(r)) return 'Papelera';
        if (/(^|[.\/])(archive|archivo)$/.test(r)) return 'Archivo';
        return etiqueta;
    }

    function iconoCarpeta(ruta, abierto) {
        var r = String(ruta || '').toLowerCase();
        if (ruta === '') return 'fa-layer-group';
        if (r === 'inbox') return 'fa-inbox';
        if (/(draft|borrador)/.test(r)) return 'fa-pen';
        if (/(sent|enviado)/.test(r)) return 'fa-paper-plane';
        if (/(spam|junk)/.test(r)) return 'fa-fire';
        if (/(trash|papelera)/.test(r)) return 'fa-trash-can';
        if (/(archive|archivo)/.test(r)) return 'fa-box-archive';
        return abierto ? 'fa-folder-open' : 'fa-folder';
    }

    function prioridadCarpeta(nodo) {
        var r = String(nodo.ruta || '').toLowerCase();
        if (r === 'inbox') return 0;
        if (/(draft|borrador)/.test(r)) return 1;
        if (/(sent|enviado)/.test(r)) return 2;
        if (/(spam|junk)/.test(r)) return 3;
        if (/(trash|papelera)/.test(r)) return 4;
        if (/(archive|archivo)/.test(r)) return 5;
        return 10;
    }

    function ordenarNodos(a, b) {
        var pa = prioridadCarpeta(a);
        var pb = prioridadCarpeta(b);
        if (pa !== pb) return pa - pb;
        return String(a.etiqueta).localeCompare(String(b.etiqueta), 'es', {
            numeric: true,
            sensitivity: 'base'
        });
    }

    function construirArbolCarpetas(datos) {
        var nodos = {};
        var raices = [];

        function asegurar(ruta, etiqueta, padre) {
            if (!nodos[ruta]) {
                nodos[ruta] = {
                    ruta: ruta,
                    etiqueta: etiqueta,
                    padre: padre || null,
                    hijos: [],
                    item: null
                };
                if (padre && nodos[padre]) nodos[padre].hijos.push(nodos[ruta]);
                else raices.push(nodos[ruta]);
            }
            return nodos[ruta];
        }

        (datos || []).forEach(function (item) {
            var ruta = String(item.carpeta || '');
            if (!ruta) return;
            var delimitador = String(item.delimitador || '.');
            var partes = ruta.split(delimitador);
            var nombreLegible = String(item.nombre || '').split('/');
            var inicio = partes[0].toUpperCase() === 'INBOX' && partes.length > 1 ? 1 : 0;
            var prefijo = inicio === 1 ? 'INBOX' : '';
            var padre = null;

            for (var i = inicio; i < partes.length; i++) {
                var actual = prefijo ? prefijo + delimitador + partes[i] : partes[i];
                var etiqueta = partes[i];
                if (i === partes.length - 1 && nombreLegible.length) {
                    etiqueta = nombreLegible[nombreLegible.length - 1] || etiqueta;
                }
                etiqueta = etiquetaEspecial(actual, etiqueta);
                var nodo = asegurar(actual, etiqueta, padre);
                padre = actual;
                prefijo = actual;
                if (i === partes.length - 1) nodo.item = item;
            }
        });

        raices.sort(ordenarNodos);
        Object.keys(nodos).forEach(function (k) { nodos[k].hijos.sort(ordenarNodos); });
        return raices;
    }

    // Solo se persisten las carpetas ABIERTAS. Guardar un mapa ruta→bool hacía
    // que "cerrada" dependiera de que existiera la clave en false, y cualquier
    // pérdida del registro reabría el árbol al recargar. Con una lista de
    // abiertas, lo que no está en la lista está cerrado: no hay forma de que
    // una carpeta cerrada vuelva a abrirse sola.
    function guardarExpandidas() {
        try {
            var abiertas = Object.keys(carpetasExpandidas).filter(function (ruta) {
                return carpetasExpandidas[ruta];
            });
            localStorage.setItem(CLAVE_CARPETAS, JSON.stringify(abiertas));
        } catch (e) {}
    }

    /**
     * Abre o cierra una carpeta. Al cerrar se BORRA la clave (no se guarda
     * false): el estado persistido es exactamente la lista de abiertas.
     */
    function alternarCarpeta(ruta, abrir) {
        if (abrir) {
            carpetasExpandidas[ruta] = true;
        } else {
            delete carpetasExpandidas[ruta];
        }
        guardarExpandidas();
        renderCarpetas();
    }

    /** Abre los ancestros de una ruta para que quede visible en el árbol. */
    function expandirAncestros(ruta) {
        var partes = String(ruta || '').split('.');
        var cambio = false;
        for (var i = 1; i < partes.length; i++) {
            var ancestro = partes.slice(0, i).join('.');
            if (ancestro && !carpetasExpandidas[ancestro]) {
                carpetasExpandidas[ancestro] = true;
                cambio = true;
            }
        }
        return cambio;
    }

    function filaCarpeta(nodo, profundidad) {
        var contenedor = document.createElement('div');
        var tieneHijos = nodo.hijos.length > 0;
        var abierto = tieneHijos && !!carpetasExpandidas[nodo.ruta];
        var row = document.createElement('div');
        row.className = 'correo-folder-row' + (carpetaActiva === nodo.ruta ? ' active' : '');
        row.style.paddingLeft = (4 + profundidad * 13) + 'px';
        row.title = nodo.etiqueta;
        if (nodo.item && nodo.item.mensajes !== null && nodo.item.mensajes !== undefined) {
            row.title += ' · ' + nodo.item.mensajes + ' mensajes';
        }

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'correo-folder-toggle' + (tieneHijos ? '' : ' sin-hijos');
        toggle.innerHTML = '<i class="fas fa-chevron-' + (abierto ? 'down' : 'right') + '"></i>';
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            alternarCarpeta(nodo.ruta, !abierto);
        });
        row.appendChild(toggle);

        var icon = document.createElement('i');
        icon.className = 'fas ' + iconoCarpeta(nodo.ruta, abierto) + ' correo-folder-icon';
        row.appendChild(icon);

        var label = document.createElement('span');
        label.className = 'correo-folder-label';
        label.textContent = nodo.etiqueta;
        row.appendChild(label);

        if (nodo.item && nodo.ruta === 'INBOX'
            && nodo.item.no_leidos !== null && nodo.item.no_leidos !== undefined) {
            var count = document.createElement('span');
            count.className = 'correo-folder-count';
            count.textContent = nodo.item.no_leidos;
            count.title = 'No leídos';
            row.appendChild(count);
        }

        row.addEventListener('click', function () {
            if (nodo.item && nodo.item.seleccionable !== false) {
                seleccionarCarpeta(nodo.ruta, nodo.etiqueta);
            } else if (tieneHijos) {
                alternarCarpeta(nodo.ruta, !abierto);
            }
        });
        contenedor.appendChild(row);

        if (tieneHijos) {
            var hijos = document.createElement('div');
            hijos.className = 'correo-folder-children';
            hijos.hidden = !abierto;
            nodo.hijos.forEach(function (hijo) {
                hijos.appendChild(filaCarpeta(hijo, profundidad + 1));
            });
            contenedor.appendChild(hijos);
        }
        return contenedor;
    }

    function renderCarpetas() {
        if (!carpetasTree) return;
        carpetasTree.innerHTML = '';

        construirArbolCarpetas(carpetasDatos).forEach(function (raiz) {
            carpetasTree.appendChild(filaCarpeta(raiz, 0));
        });
    }

    function seleccionarCarpeta(ruta, nombre) {
        carpetaActiva = String(ruta || 'INBOX');
        carpetaNombreActiva = nombre || 'Entrada';
        // Una carpeta a la que se llega desde fuera (lupa de por-pagar) puede
        // estar dentro de ramas cerradas: se abren sus ancestros para que se
        // vea seleccionada, y esa apertura sí queda guardada.
        if (expandirAncestros(carpetaActiva)) {
            guardarExpandidas();
        }
        if (selAlcance) selAlcance.value = 'carpeta';
        if (carpetaActualEl) {
            carpetaActualEl.querySelector('i').className =
                'fas ' + iconoCarpeta(carpetaActiva, false);
            carpetaActualEl.querySelector('span').textContent = carpetaNombreActiva;
            carpetaActualEl.title = carpetaNombreActiva;
        }
        renderCarpetas();
        cargarCorreos();
    }

    function mostrarPanelCarpetas(mostrar) {
        if (!listaPanel) return;
        listaPanel.classList.toggle('carpetas-cerradas', !mostrar);
        if (btnCarpetasToggle) {
            btnCarpetasToggle.classList.toggle('active', mostrar);
            btnCarpetasToggle.title = mostrar ? 'Ocultar carpetas' : 'Mostrar carpetas';
        }
        try { localStorage.setItem('correoCarpetasVisibles', mostrar ? '1' : '0'); } catch (e) {}
    }

    // Estado del árbol: lista de rutas abiertas. La clave v2 distingue "nunca
    // configurado" (null → se sugiere el año en curso, y se guarda de una vez)
    // de "el usuario cerró todo" (lista vacía → se respeta y el árbol queda
    // cerrado en cada recarga).
    try {
        var guardadoCarpetas = localStorage.getItem(CLAVE_CARPETAS);
        if (guardadoCarpetas === null) {
            // Migración del formato viejo (mapa ruta→bool), si existe.
            var previo = localStorage.getItem('correoCarpetasExpandidas_' + CUENTA_ID);
            if (previo) {
                var mapa = JSON.parse(previo) || {};
                Object.keys(mapa).forEach(function (ruta) {
                    if (mapa[ruta]) carpetasExpandidas[ruta] = true;
                });
            } else {
                carpetasExpandidas['INBOX.' + String(new Date().getFullYear())] = true;
            }
            guardarExpandidas(); // se persiste ya: no se re-sugiere en cada recarga
        } else {
            (JSON.parse(guardadoCarpetas) || []).forEach(function (ruta) {
                if (ruta) carpetasExpandidas[String(ruta)] = true;
            });
        }
    } catch (e) {
        carpetasExpandidas = {};
    }

    var carpetasVisibles = true;
    try { carpetasVisibles = localStorage.getItem('correoCarpetasVisibles') !== '0'; } catch (e) {}
    mostrarPanelCarpetas(carpetasVisibles);
    renderCarpetas();

    // Montaje terminado: se devuelven las transiciones para que el botón de
    // mostrar/ocultar carpetas sí anime cuando el usuario lo pulse.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            if (listaPanel) listaPanel.classList.remove('correo-sin-animacion');
        });
    });

    if (btnCarpetasToggle) {
        btnCarpetasToggle.addEventListener('click', function () {
            mostrarPanelCarpetas(listaPanel.classList.contains('carpetas-cerradas'));
        });
    }
    if (btnCerrarCarpetas) {
        btnCerrarCarpetas.addEventListener('click', function () { mostrarPanelCarpetas(false); });
    }
    var btnColapsarCarpetas = document.getElementById('btn-colapsar-carpetas');
    if (btnColapsarCarpetas) {
        btnColapsarCarpetas.addEventListener('click', function () {
            carpetasExpandidas = {};
            guardarExpandidas();
            renderCarpetas();
        });
    }

    if (CUENTA_ID > 0) {
        postJson(BASE + '/correo/carpetas-buzon', {})
            .then(function (r) {
                if (Array.isArray(r.carpetas) && r.carpetas.length) {
                    carpetasDatos = r.carpetas;
                    renderCarpetas();
                }
            })
            .catch(function () {
                // El árbol local sigue disponible si IMAP está ocupado.
            });
    }

    function mostrarError(msg) {
        elResumen.textContent = '';
        var i = document.createElement('i');
        i.className = 'fas fa-times-circle';
        i.style.marginRight = '5px';
        elResumen.appendChild(i);
        elResumen.appendChild(document.createTextNode(msg));
        elResumen.style.display = 'block';
        elResumen.style.background = '#fff5f5';
        elResumen.style.borderColor = '#fed7d7';
        elResumen.style.color = '#b91c1c';
    }

    // ── Resumen y re-búsqueda tras el reload ──
    try {
        var guardado = sessionStorage.getItem('correoResumen');
        if (guardado) {
            sessionStorage.removeItem('correoResumen');
            elResumen.innerHTML = guardado;
            elResumen.style.display = 'block';
        }
        var ultima = sessionStorage.getItem('correoUltimaBusqueda');
        if (sessionStorage.getItem('correoAutoListar') === '1') {
            sessionStorage.removeItem('correoAutoListar');
            if (ultima) {
                var u = JSON.parse(ultima);
                if (inputBuscar) inputBuscar.value = u.texto || '';
                if (selAmbito && u.ambito) selAmbito.value = u.ambito;
                if (selAlcance && u.alcance) selAlcance.value = u.alcance;
                if (selDias && u.dias !== undefined) selDias.value = String(u.dias);
            }
            // Con la tarjeta de por-pagar abierta NO se relanza la búsqueda
            // sola tras un reload: el texto guardado puede ser el de la
            // factura anterior y confunde. Ahí manda la lupa de la tarjeta.
            setTimeout(function () {
                var pp = document.getElementById('pp-nav');
                if (pp && pp.style.display !== 'none') return;
                if (btnCargar && !btnCargar.disabled) cargarCorreos();
            }, 150);
        }
    } catch (e) { /* sessionStorage bloqueado */ }

    // Búsqueda prellenada (?buscar= — llega desde "Facturas por pagar")
    var BUSCAR_INICIAL = <?= json_encode($buscarInicial) ?>;
    var ABRIR_CORREO_UID = <?= (int) $abrirCorreoUid ?>;
    var ABRIR_CORREO_CARPETA = <?= json_encode($abrirCorreoCarpeta) ?>;
    var ABRIR_CORREO_PENDIENTE = ABRIR_CORREO_UID > 0;
    if (BUSCAR_INICIAL && inputBuscar && btnCargar && !btnCargar.disabled) {
        inputBuscar.value = BUSCAR_INICIAL;
        setTimeout(function () {
            // Un enlace procedente de por-pagar usa el mismo motor propio de
            // la lupa de la tarjeta (PP_NAV ya está asignado al ejecutarse).
            var origenBusqueda = 'bandeja';
            var fechaReferencia = '';
            var numeroContexto = '';
            try {
                if (PP_NAV && PP_NAV.lineas && PP_NAV.lineas.length) {
                    var i = Math.max(0, Math.min(PP_NAV.lineas.length - 1, PP_NAV.idx || 0));
                    origenBusqueda = 'tarjeta';
                    fechaReferencia = PP_NAV.lineas[i].fecha || '';
                    numeroContexto = PP_NAV.lineas[i].numero || '';
                }
            } catch (e) {}
            cargarCorreos({
                origenBusqueda: origenBusqueda,
                fechaReferencia: fechaReferencia,
                numeroContexto: numeroContexto
            });
        }, 200);
    }

    // ── Tarjeta de navegación de "Facturas por pagar" ──
    // Muestra la factura seleccionada del listado y permite recorrerlo con
    // las flechas; la lupa pone el número de la factura visible en el
    // buscador y ejecuta la búsqueda.
    var PP_NAV = <?= json_encode($ppNav) ?>;
    var ppCard = document.getElementById('pp-nav');

    // Parámetros pp_* de la factura visible; se arrastran al cambiar de cuenta
    // para que la tarjeta se quede. '' = tarjeta cerrada o sin contexto.
    var ppSuffixActual = '';

    if (PP_NAV && ppCard && PP_NAV.lineas && PP_NAV.lineas.length) {
        var ppIdx = Math.max(0, Math.min(PP_NAV.lineas.length - 1, PP_NAV.idx || 0));
        var ppEstados = {
            respaldada:     { color: '#16a34a', nombre: 'Respaldada' },
            con_diferencia: { color: '#dc2626', nombre: 'Con diferencia' },
            sin_respaldo:   { color: '#94a3b8', nombre: 'Sin respaldo' }
        };
        var ppPrev = document.getElementById('pp-prev');
        var ppNext = document.getElementById('pp-next');

        function ppPintar() {
            var l = PP_NAV.lineas[ppIdx];
            var est = ppEstados[l.estado] || ppEstados.sin_respaldo;

            var circulo = document.getElementById('pp-circulo');
            circulo.style.background = est.color;
            circulo.style.boxShadow = '0 0 0 3px ' + est.color + '33';
            circulo.title = est.nombre;

            var numero = document.getElementById('pp-numero');
            numero.textContent = l.numero;
            numero.title = l.numero + ' — ' + est.nombre;

            var proveedor = document.getElementById('pp-proveedor');
            proveedor.textContent = l.proveedor;
            proveedor.title = l.proveedor;

            document.getElementById('pp-fecha').textContent = l.fecha || '—';
            document.getElementById('pp-monto').textContent = '₡' +
                Number(l.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('pp-pos').textContent = (ppIdx + 1) + ' / ' + PP_NAV.lineas.length;

            ppPrev.disabled = ppIdx === 0;
            ppNext.disabled = ppIdx === PP_NAV.lineas.length - 1;
            ppSuffixActual = 'pp_listado=' + PP_NAV.listado_id + '&pp_linea=' + l.id;

            // Mantener la URL al día con la factura visible: si la página se
            // recarga (p. ej. al procesar correos), la tarjeta reabre en ESTA
            // factura y no en la inicial. También suelta el ?buscar= original
            // para que el reload no re-ejecute la búsqueda vieja (la última
            // búsqueda se restaura sola desde sessionStorage).
            try { history.replaceState(null, '', BASE + '/correo?' + ppSuffixActual); } catch (e) {}
        }

        ppPrev.addEventListener('click', function () {
            if (ppIdx > 0) { ppIdx--; ppPintar(); }
        });
        ppNext.addEventListener('click', function () {
            if (ppIdx < PP_NAV.lineas.length - 1) { ppIdx++; ppPintar(); }
        });

        document.getElementById('pp-buscar').addEventListener('click', function () {
            if (!inputBuscar || !btnCargar || btnCargar.disabled) return;
            var l = PP_NAV.lineas[ppIdx];
            inputBuscar.value = l.busqueda || l.numero;
            // Motor de tarjeta: ignora los selectores de la bandeja, busca
            // 15 días antes/después y amplía a todo el buzón si no encuentra.
            cargarCorreos({
                origenBusqueda: 'tarjeta',
                fechaReferencia: l.fecha || '',
                numeroContexto: l.numero || ''
            });
        });

        document.getElementById('pp-cerrar').addEventListener('click', function () {
            ppCard.style.display = 'none';
            ppSuffixActual = ''; // ya no se arrastra al cambiar de cuenta
            // Quitar los parámetros pp_* para que un reload no la resucite
            try { history.replaceState(null, '', BASE + '/correo' + (MODO_CORREO !== 'facturas' ? '?modo=' + encodeURIComponent(MODO_CORREO) : '')); } catch (e) {}
        });

        ppPintar();
    } else if (ppCard) {
        ppCard.style.display = 'none';
    }

    function mostrarYRecargar(html, autoListar) {
        try {
            sessionStorage.setItem('correoResumen', html);
            if (autoListar) sessionStorage.setItem('correoAutoListar', '1');
        } catch (e) {}
        window.location.reload();
    }

    // ── Filtro local instantáneo ──
    function normalizar(s) {
        s = String(s || '').toLowerCase();
        try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
        return s;
    }

    function correosFiltrados() {
        var q = normalizar(inputBuscar.value).trim();
        if (q === '') return correos;

        // La respuesta del servidor ya contiene todas las coincidencias del
        // término buscado, incluso las halladas solo en adjuntos o contenido.
        // No volver a descartarlas con el filtro parcial del navegador.
        if (q === textoBusquedaCargada) return correos;

        var terminos = q.split(/\s+/);
        return correos.filter(function (c) {
            return terminos.every(function (t) { return c._haystack.indexOf(t) !== -1; });
        });
    }

    function fechaCorta(f) {
        if (!f || f.length < 16) return '';
        return f.substring(8, 10) + '/' + f.substring(5, 7) + '/' + f.substring(2, 4) + ' ' + f.substring(11, 16);
    }

    function fechaLista(f) {
        if (!f || f.length < 10) return '';
        return f.substring(8, 10) + '/' + f.substring(5, 7) + '/' + f.substring(2, 4);
    }

    // Nota junto al contador: mes al que se acotó la búsqueda (tarjeta)
    var mesNota = '';

    function actualizarContadores(filtrados) {
        var n = Object.keys(seleccion).length;
        selCount.textContent = n;
        btnProcesar.disabled = n === 0;

        var txt = '';
        if (correos.length && filtrados.length === correos.length) {
            var inicio = (paginaActual - 1) * porPagina + 1;
            var fin = inicio + correos.length - 1;
            txt = inicio + '–' + fin + ' de ' + totalBuzon;
        } else if (correos.length) {
            txt = filtrados.length + ' visibles de ' + totalBuzon;
        }
        if (paginasTotal > 1) txt += ' · pág. ' + paginaActual + '/' + paginasTotal;
        infoCorreos.textContent = correos.length ? txt + mesNota : '';
    }

    function renderPaginacion() {
        if (paginasTotal <= 1) return;

        var pager = document.createElement('div');
        pager.className = 'correo-paginacion';

        var anterior = document.createElement('button');
        anterior.type = 'button';
        anterior.className = 'btn btn-outline btn-sm';
        anterior.disabled = paginaActual <= 1;
        anterior.innerHTML = '<i class="fas fa-chevron-left"></i> Anterior';
        anterior.addEventListener('click', function () {
            if (paginaActual > 1) cargarCorreos(Object.assign(
                {}, contextoBusquedaActivo, { pagina: paginaActual - 1 }
            ));
        });

        var estado = document.createElement('span');
        estado.className = 'correo-paginacion-estado';
        estado.textContent = 'Página ' + paginaActual + ' de ' + paginasTotal;

        var siguiente = document.createElement('button');
        siguiente.type = 'button';
        siguiente.className = 'btn btn-primary btn-sm';
        siguiente.disabled = paginaActual >= paginasTotal;
        siguiente.innerHTML = 'Siguiente <i class="fas fa-chevron-right"></i>';
        siguiente.addEventListener('click', function () {
            if (paginaActual < paginasTotal) cargarCorreos(Object.assign(
                {}, contextoBusquedaActivo, { pagina: paginaActual + 1 }
            ));
        });

        pager.appendChild(anterior);
        pager.appendChild(estado);
        pager.appendChild(siguiente);
        listaEl.appendChild(pager);
    }

    // ── Render de la lista ──
    function renderCorreos() {
        var filtrados = correosFiltrados();
        listaEl.innerHTML = '';

        if (!filtrados.length) {
            var vacio = document.createElement('div');
            vacio.className = 'correo-vacio-panel';
            var ic = document.createElement('i');
            ic.className = 'fas fa-envelopes-bulk';
            vacio.appendChild(ic);
            vacio.appendChild(document.createTextNode(correos.length
                ? 'Nada coincide con el filtro'
                : 'Sin resultados: prueba otro término o rango'));
            listaEl.appendChild(vacio);
        }

        filtrados.forEach(function (c) {
            var item = document.createElement('div');
            item.className = 'correo-item'
                + (correoActual && c.clave === correoActual.clave ? ' sel' : '');

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !!seleccion[c.clave];
            cb.addEventListener('click', function (e) { e.stopPropagation(); });
            cb.addEventListener('change', function () {
                if (cb.checked) seleccion[c.clave] = { uid: c.uid, carpeta: c.carpeta || '' };
                else delete seleccion[c.clave];
                actualizarContadores(correosFiltrados());
            });
            item.appendChild(cb);

            var main = document.createElement('div');
            main.className = 'correo-item-main';
            var asunto = document.createElement('div');
            asunto.className = 'correo-item-asunto';
            asunto.textContent = c.asunto || '(sin asunto)';
            asunto.title = c.asunto || '';
            var meta = document.createElement('div');
            meta.className = 'correo-item-meta';
            var carpetaTxt = (c.carpeta_nombre && c.carpeta_nombre !== 'INBOX') ? ' · 📁 ' + c.carpeta_nombre : '';
            var ccTxt = c.cc ? ' · CC: ' + c.cc : '';
            var replyTxt = c.reply_to ? ' · Responder a: ' + c.reply_to : '';
            meta.textContent = (c.remitente || '—') + ccTxt + replyTxt + carpetaTxt;
            meta.title = (c.remitente || '') + (c.cc ? ' — CC: ' + c.cc : '')
                + (c.reply_to ? ' — Responder a: ' + c.reply_to : '')
                + (carpetaTxt ? ' — carpeta: ' + c.carpeta_nombre : '');
            main.appendChild(asunto);
            main.appendChild(meta);
            item.appendChild(main);

            var fecha = document.createElement('time');
            fecha.className = 'correo-item-fecha';
            fecha.textContent = fechaLista(c.fecha);
            fecha.title = fechaCorta(c.fecha);
            if (c.fecha) fecha.dateTime = String(c.fecha).replace(' ', 'T');
            item.appendChild(fecha);

            item.addEventListener('click', function () { seleccionarCorreo(c); });
            listaEl.appendChild(item);
        });

        renderPaginacion();
        actualizarContadores(filtrados);
    }

    // ── Contenido del correo seleccionado ──
    function seleccionarCorreo(c) {
        correoActual = c;

        var items = listaEl.querySelectorAll('.correo-item');
        for (var i = 0; i < items.length; i++) items[i].classList.remove('sel');
        renderCorreos(); // re-render marca .sel

        ctVacio.style.display = 'none';
        ctMetaBox.style.display = 'block';
        ctBody.style.display = 'block';

        ctAsunto.textContent = c.asunto || '(sin asunto)';
        ctMeta.textContent = (c.remitente || '—') + (c.cc ? ' · CC: ' + c.cc : '')
            + (c.reply_to ? ' · Responder a: ' + c.reply_to : '') + ' · ' + fechaCorta(c.fecha);
        ctBadge.textContent = '';
        btnProcUno.style.display = 'inline-flex';
        ctAdjuntos.innerHTML = '';

        if (c._cuerpo !== undefined) {
            ctCuerpo.textContent = c._cuerpo;
            pintarAdjuntos(c._adjuntos || []);
            return;
        }

        ctCuerpo.textContent = 'Cargando contenido…';
        postJson(BASE + '/correo/contenido', { uid: c.uid, carpeta: c.carpeta || '' })
            .then(function (r) {
                c._cuerpo = r.cuerpo || '(vacío)';
                c._adjuntos = r.adjuntos || [];
                c.cc = r.cc || c.cc || '';
                c.reply_to = r.reply_to || c.reply_to || '';
                if (!correoActual || correoActual.clave !== c.clave) return; // ya seleccionó otro
                ctCuerpo.textContent = c._cuerpo;
                ctMeta.textContent = (c.remitente || '—') + (c.cc ? ' · CC: ' + c.cc : '')
                    + (c.reply_to ? ' · Responder a: ' + c.reply_to : '') + ' · ' + fechaCorta(c.fecha);
                pintarAdjuntos(c._adjuntos);
            })
            .catch(function (err) {
                if (!correoActual || correoActual.clave !== c.clave) return;
                ctCuerpo.textContent = 'No se pudo cargar el contenido: ' + err.message;
            });
    }

    function cerrarVisorAdjunto() {
        visorSolicitud++;
        if (visorAbort) {
            visorAbort.abort();
            visorAbort = null;
        }
        if (visorObjectUrl) {
            URL.revokeObjectURL(visorObjectUrl);
            visorObjectUrl = '';
        }
        visorPdf.removeAttribute('src');
        visorPdf.style.display = 'none';
        visorXml.textContent = '';
        visorXml.style.display = 'none';
        visorEstado.style.display = 'flex';
        visorEstado.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Leyendo el archivo desde el correo…';
        visorDescargar.disabled = true;
        visorArchivoNombre = '';
        modalAdjunto.style.display = 'none';
    }

    function abrirVisorAdjunto(correo, adjunto) {
        if (!correo || !adjunto || !adjunto.visualizable || !adjunto.seccion) return;

        cerrarVisorAdjunto();
        var solicitud = ++visorSolicitud;
        modalAdjunto.style.display = 'flex';
        visorNombre.textContent = adjunto.nombre || 'Adjunto';
        visorArchivoNombre = adjunto.nombre
            || (adjunto.tipo_vista === 'pdf' ? 'documento.pdf' : 'documento.xml');
        visorEstado.style.display = 'flex';

        var fd = new FormData();
        fd.append('cuenta_id', CUENTA_ID);
        fd.append('uid', correo.uid);
        fd.append('carpeta', correo.carpeta || '');
        fd.append('seccion', adjunto.seccion);

        visorAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        fetch(BASE + '/correo/adjunto', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: visorAbort ? visorAbort.signal : undefined
        }).then(function (res) {
            if (res.ok) return res.blob();
            return res.json().catch(function () { return null; }).then(function (body) {
                throw new Error((body && body.message) || ('Error HTTP ' + res.status));
            });
        }).then(function (blob) {
            if (solicitud !== visorSolicitud || modalAdjunto.style.display === 'none') return;
            visorAbort = null;
            visorObjectUrl = URL.createObjectURL(blob);
            visorDescargar.disabled = false;
            visorEstado.style.display = 'none';

            if (adjunto.tipo_vista === 'pdf') {
                visorPdf.src = visorObjectUrl;
                visorPdf.style.display = 'block';
                return;
            }

            return blob.text().then(function (texto) {
                if (solicitud !== visorSolicitud || modalAdjunto.style.display === 'none') return;
                visorXml.textContent = texto;
                visorXml.style.display = 'block';
            });
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (solicitud !== visorSolicitud) return;
            visorAbort = null;
            visorEstado.style.display = 'flex';
            visorEstado.textContent = 'No se pudo abrir el archivo: '
                + (err.message || 'error desconocido');
        });
    }

    if (visorCerrar) visorCerrar.addEventListener('click', cerrarVisorAdjunto);
    if (modalAdjunto) {
        modalAdjunto.addEventListener('click', function (e) {
            if (e.target === modalAdjunto) cerrarVisorAdjunto();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalAdjunto
            && modalAdjunto.style.display !== 'none') {
            cerrarVisorAdjunto();
        }
    });
    if (visorDescargar) {
        visorDescargar.addEventListener('click', function () {
            if (!visorObjectUrl) return;
            var enlace = document.createElement('a');
            enlace.href = visorObjectUrl;
            enlace.download = visorArchivoNombre || 'adjunto';
            document.body.appendChild(enlace);
            enlace.click();
            enlace.remove();
        });
    }

    function pintarAdjuntos(adjuntos) {
        ctAdjuntos.innerHTML = '';
        adjuntos.forEach(function (dato) {
            var adjunto = typeof dato === 'string'
                ? { nombre: dato, visualizable: false, tipo_vista: '' }
                : dato;
            var nombre = String(adjunto.nombre || '');
            var ext = nombre.split('.').pop().toLowerCase();
            var chip = document.createElement(adjunto.visualizable ? 'button' : 'span');
            if (adjunto.visualizable) chip.type = 'button';
            chip.className = 'badge';
            chip.style.fontSize = '10px';
            chip.style.padding = '2px 8px';
            chip.style.border = '0';
            chip.style.background = ext === 'xml' ? '#dbe9ff' : (ext === 'pdf' ? '#fee2e2' : '#f1f5f9');
            chip.style.color = ext === 'xml' ? '#1a4db3' : (ext === 'pdf' ? '#b91c1c' : '#475569');
            chip.style.cursor = adjunto.visualizable ? 'pointer' : 'default';
            var ic = document.createElement('i');
            ic.className = 'fas ' + (ext === 'pdf' ? 'fa-file-pdf' : (ext === 'xml' ? 'fa-file-code' : 'fa-paperclip'));
            ic.style.marginRight = '4px';
            chip.appendChild(ic);
            chip.appendChild(document.createTextNode(nombre));
            chip.title = adjunto.visualizable
                ? nombre + ' · Clic para visualizar sin guardar'
                : nombre + (['pdf', 'xml'].indexOf(ext) !== -1
                    ? ' · Supera el límite de 15 MB'
                    : ' · Vista previa no disponible');
            if (adjunto.visualizable) {
                var correo = correoActual;
                chip.addEventListener('click', function () {
                    abrirVisorAdjunto(correo, adjunto);
                });
            }
            ctAdjuntos.appendChild(chip);
        });
    }

    if (btnProcUno) {
        btnProcUno.addEventListener('click', function () {
            if (correoActual) procesarItems([{ uid: correoActual.uid, carpeta: correoActual.carpeta || '' }]);
        });
    }

    // ── Buscar en el buzón (servidor IMAP) ──
    // opts.origenBusqueda distingue la lupa de la tarjeta de la lupa y los
    // filtros propios de la bandeja.
    function cargarCorreos(opts) {
        opts = (opts && typeof opts === 'object' && !(opts instanceof Event)) ? opts : {};
        var texto = inputBuscar ? inputBuscar.value.trim() : '';
        var paginaSolicitada = Math.max(1, parseInt(opts.pagina || 1, 10) || 1);
        var buscarEnCarpeta = selAlcance && selAlcance.value === 'carpeta';

        var esTarjeta = opts.origenBusqueda === 'tarjeta';
        contextoBusquedaActivo = esTarjeta ? {
            origenBusqueda: 'tarjeta',
            fechaReferencia: opts.fechaReferencia || '',
            numeroContexto: opts.numeroContexto || ''
        } : {};

        if (selDias.value === '0' && texto === '' && !buscarEnCarpeta) {
            if (!confirm('Vas a listar TODO el buzón sin término de búsqueda; puede tardar y solo se muestran los 500 más recientes. ¿Continuar?')) {
                return;
            }
        }

        try {
            sessionStorage.setItem('correoUltimaBusqueda', JSON.stringify({
                texto: texto,
                ambito: selAmbito.value,
                alcance: selAlcance ? selAlcance.value : 'buzon',
                dias: selDias.value
            }));
        } catch (e) {}

        btnCargar.disabled = true;
        btnCargar.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        elResumen.style.display = 'none';

        postJson(BASE + '/correo/listar', {
            dias: selDias.value,
            texto: texto,
            ambito: selAmbito ? selAmbito.value : 'asunto_remitente',
            carpeta: buscarEnCarpeta ? carpetaActiva : '',
            pagina: paginaSolicitada,
            origen_busqueda: esTarjeta ? 'tarjeta' : 'bandeja',
            fecha_referencia: esTarjeta ? (opts.fechaReferencia || '') : '',
            numero_contexto: esTarjeta ? (opts.numeroContexto || '') : ''
        })
            .then(function (r) {
                textoBusquedaCargada = normalizar(texto).trim();
                correos = (r.correos || []).map(function (c) {
                    c._haystack = normalizar((c.asunto || '') + ' ' + (c.remitente || '') + ' '
                        + (c.cc || '') + ' ' + (c.reply_to || '') + ' ' + (c.adjuntos || '') + ' ' + (c.fecha || ''));
                    return c;
                });
                totalBuzon = r.total || correos.length;
                paginaActual = parseInt(r.pagina || paginaSolicitada, 10);
                paginasTotal = Math.max(1, parseInt(r.paginas || 1, 10));
                porPagina = Math.max(1, parseInt(r.por_pagina || 500, 10));
                if (r.origen_busqueda === 'tarjeta' && r.fecha_desde && r.fecha_hasta) {
                    var desde = r.fecha_desde.substring(8, 10) + '/' + r.fecha_desde.substring(5, 7);
                    var hasta = r.fecha_hasta.substring(8, 10) + '/' + r.fecha_hasta.substring(5, 7);
                    mesNota = r.rango_aplicado
                        ? ' · rango ' + desde + '–' + hasta
                        : ' · sin resultados en ' + desde + '–' + hasta + ', todo el buzón';
                } else if (r.mes) {
                    mesNota = ' · ' + r.mes.substring(5, 7) + '/' + r.mes.substring(0, 4);
                } else if (r.mes_probado) {
                    mesNota = ' · nada en ' + r.mes_probado.substring(5, 7) + '/' + r.mes_probado.substring(0, 4) + ', todas las fechas';
                } else {
                    mesNota = '';
                }
                seleccion = {};
                cbVisibles.checked = false;
                renderCorreos();
                if (ABRIR_CORREO_PENDIENTE) {
                    var correoObjetivo = correos.find(function (correo) {
                        return Number(correo.uid || 0) === ABRIR_CORREO_UID
                            && (ABRIR_CORREO_CARPETA === '' || String(correo.carpeta || '') === ABRIR_CORREO_CARPETA);
                    });
                    if (correoObjetivo) {
                        ABRIR_CORREO_PENDIENTE = false;
                        seleccionarCorreo(correoObjetivo);
                    }
                }
                listaEl.scrollTop = 0;
            })
            .catch(function (err) { mostrarError(err.message); })
            .then(function () {
                btnCargar.disabled = false;
                btnCargar.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
            });
    }

    if (btnCargar) btnCargar.addEventListener('click', cargarCorreos);
    if (selAlcance) {
        selAlcance.addEventListener('change', function () {
            if (btnCargar && !btnCargar.disabled) cargarCorreos();
        });
    }

    // ── Cambio de cuenta de correo (recarga con la cuenta elegida) ──
    var selCuenta = document.getElementById('sel-cuenta');
    if (selCuenta) {
        selCuenta.addEventListener('change', function () {
            selCuenta.disabled = true;

            // Conservar la búsqueda actual al cambiar de cuenta: se guarda el
            // término (aunque no se haya buscado aún) y, si hay texto, se vuelve
            // a ejecutar sobre la nueva cuenta tras el reload.
            try {
                var textoActual = inputBuscar ? inputBuscar.value.trim() : '';
                sessionStorage.setItem('correoUltimaBusqueda', JSON.stringify({
                    texto: textoActual,
                    ambito: selAmbito ? selAmbito.value : 'asunto_remitente',
                    alcance: selAlcance ? selAlcance.value : 'buzon',
                    dias: selDias ? selDias.value : '0'
                }));
                if (textoActual !== '') sessionStorage.setItem('correoAutoListar', '1');
            } catch (e) {}

            postJson(BASE + '/correo/cuentas/usar', { id: selCuenta.value })
                .then(function () {
                    // Arrastrar la tarjeta de por-pagar (si está abierta) a la
                    // nueva cuenta para que no se pierda al cambiar de correo.
                    var qsCuenta = [];
                    if (MODO_CORREO !== 'facturas') qsCuenta.push('modo=' + encodeURIComponent(MODO_CORREO));
                    if (ppSuffixActual) qsCuenta.push(ppSuffixActual);
                    window.location.href = BASE + '/correo' + (qsCuenta.length ? '?' + qsCuenta.join('&') : '');
                })
                .catch(function (err) {
                    alert('No se pudo cambiar de cuenta: ' + err.message);
                    selCuenta.disabled = false;
                });
        });
    }

    // ── Índice local: sincronización por tandas en segundo plano ──
    // Cada petición avanza ~20s de carpetas y se repite hasta terminar
    // (la construcción inicial con miles de correos toma varias tandas).
    // Solo se auto-ejecuta si la última sync fue hace más de 10 minutos.
    var syncInfo = document.getElementById('sync-info');
    var syncEnCurso = false;
    var SYNC_CADA_MS = 10 * 60 * 1000;

    function pintarSyncInfo(texto) {
        if (!syncInfo) return;
        syncInfo.innerHTML = '';
        syncInfo.appendChild(document.createTextNode(texto + ' · '));
        var a = document.createElement('a');
        a.href = '#';
        a.textContent = '↻ actualizar';
        a.style.color = 'var(--navy-light)';
        a.addEventListener('click', function (e) { e.preventDefault(); sincronizarIndice(true); });
        syncInfo.appendChild(a);
    }

    function sincronizarIndice(forzar) {
        if (!syncInfo || syncEnCurso) return;

        if (!forzar) {
            try {
                // Clave versionada: fuerza una primera sincronización al
                // desplegar el índice con destinatarios CC.
                var ts = parseInt(localStorage.getItem('correoSyncTsV2_' + CUENTA_ID) || '0', 10);
                if (ts && Date.now() - ts < SYNC_CADA_MS) {
                    pintarSyncInfo('Índice actualizado hace ' + Math.max(1, Math.round((Date.now() - ts) / 60000)) + ' min');
                    return;
                }
            } catch (e) {}
        }

        syncEnCurso = true;
        var acumNuevos = 0;
        var tandas = 0;
        var backlogMeta = 0; // mayor rezago visto: da un % de avance honesto

        function paso() {
            return postJson(BASE + '/correo/sincronizar', {}).then(function (r) {
                var s = r.stats || {};
                acumNuevos += s.nuevos || 0;
                tandas++;
                var pendientesMeta = (s.adjuntos_pendientes || 0) + (s.cc_pendientes || 0);
                if (pendientesMeta > backlogMeta) backlogMeta = pendientesMeta;

                if (!r.completado && tandas < 60) {
                    if (s.restantes) {
                        syncInfo.innerHTML = '<i class="fas fa-rotate fa-spin"></i> actualizando índice… ('
                            + (r.total_indexados || 0) + ' correos, quedan ' + s.restantes + ' carpetas)';
                    } else {
                        // Fase 2: adjuntos, CC y Reply-To.
                        var avance = backlogMeta > pendientesMeta
                            ? ' · ' + Math.round((backlogMeta - pendientesMeta) * 100 / backlogMeta) + '%'
                            : '';
                        syncInfo.innerHTML = '<i class="fas fa-rotate fa-spin"></i> indexando archivos, CC y Responder a… (quedan '
                            + (pendientesMeta || '?') + avance + ')';
                    }
                    return paso();
                }

                if (s.en_curso) {
                    // Otro usuario (o la tarea automática) ya está actualizando:
                    // no se guarda el timestamp para reintentar en la próxima visita.
                    pintarSyncInfo('Índice: ' + (r.total_indexados || 0) + ' correos · otra actualización en curso');
                    return;
                }

                try { localStorage.setItem('correoSyncTsV2_' + CUENTA_ID, String(Date.now())); } catch (e) {}
                if (!r.completado && pendientesMeta > 0) {
                    // Tope de tandas alcanzado con rezago: decirlo tal cual,
                    // la tarea programada (o la próxima visita) lo continuará.
                    pintarSyncInfo('Índice: ' + (r.total_indexados || 0) + ' correos · '
                        + pendientesMeta + ' por indexar (continúa en segundo plano)');
                    return;
                }
                pintarSyncInfo('Índice: ' + (r.total_indexados || 0) + ' correos'
                    + (acumNuevos ? ' · +' + acumNuevos + ' nuevos' : ' · al día'));
            });
        }

        syncInfo.innerHTML = '<i class="fas fa-rotate fa-spin"></i> actualizando índice del buzón…';
        paso()
            .catch(function (err) {
                pintarSyncInfo('Índice sin actualizar: ' + String(err.message).substring(0, 70));
            })
            .then(function () { syncEnCurso = false; });
    }

    if (btnCargar && !btnCargar.disabled) {
        setTimeout(function () { sincronizarIndice(false); }, 300);
    }

    // ── Modal de configuración (carpeta destino + cédula) ──
    var modalCfg   = document.getElementById('modal-config');
    var cfgCarpeta = document.getElementById('cfg-carpeta');
    var cfgMsg     = document.getElementById('cfg-msg');
    var cfgGuardar = document.getElementById('cfg-guardar');

    function cfgMostrarMsg(texto, esError) {
        cfgMsg.textContent = texto;
        cfgMsg.style.display = 'block';
        cfgMsg.style.background = esError ? '#fff5f5' : '#f0fdf4';
        cfgMsg.style.border = '1px solid ' + (esError ? '#fed7d7' : '#86efac');
        cfgMsg.style.color = esError ? '#b91c1c' : '#166534';
    }

    window.abrirConfigCorreo = function () {
        if (!modalCfg) return;
        cfgMsg.style.display = 'none';
        modalCfg.style.display = 'flex';
        cfgCarpeta.focus();
        autoRefrescar();
    };

    function cerrarConfig() { if (modalCfg) modalCfg.style.display = 'none'; }

    if (modalCfg) {
        document.getElementById('cfg-cerrar').addEventListener('click', cerrarConfig);
        document.getElementById('cfg-cancelar').addEventListener('click', cerrarConfig);
        modalCfg.addEventListener('click', function (e) {
            if (e.target === modalCfg) cerrarConfig();
        });

        cfgGuardar.addEventListener('click', function () {
            cfgGuardar.disabled = true;
            postJson(BASE + '/correo/config', {
                carpeta_destino: cfgCarpeta.value.trim()
            })
                .then(function () {
                    cfgMostrarMsg('Configuración guardada.', false);
                    setTimeout(cerrarConfig, 1200);
                })
                .catch(function (err) {
                    cfgMostrarMsg(err.message, true);
                })
                .then(function () { cfgGuardar.disabled = false; });
        });
    }

    // ── Actualización automática del índice (Tarea Programada de Windows) ──
    var autoEstado     = document.getElementById('auto-estado');
    var autoControles  = document.getElementById('auto-controles');
    var autoIntervalo  = document.getElementById('auto-intervalo');
    var autoActivar    = document.getElementById('auto-activar');
    var autoDesactivar = document.getElementById('auto-desactivar');
    var autoMsg        = document.getElementById('auto-msg');

    function autoMostrarMsg(texto, esError) {
        if (!autoMsg) return;
        autoMsg.textContent = texto;
        autoMsg.style.display = 'block';
        autoMsg.style.background = esError ? '#fff5f5' : '#f0fdf4';
        autoMsg.style.border = '1px solid ' + (esError ? '#fed7d7' : '#86efac');
        autoMsg.style.color = esError ? '#b91c1c' : '#166534';
    }

    function autoFmtUltima(u) {
        if (!u || !u.ultima_corrida) return '';
        var extra = '';
        if (u.pendientes_total > 0) {
            extra = ' — faltan ' + Number(u.pendientes_total).toLocaleString('es-CR') + ' correos por indexar';
        } else if (u.todo_al_dia) {
            extra = ' — índice al día';
        }
        return 'Última corrida automática: ' + u.ultima_corrida + extra + '.';
    }

    function autoPintar(info) {
        if (!autoEstado) return;
        if (!info || !info.soportado) {
            autoEstado.innerHTML = '<span style="color:#b45309;">Solo disponible en Windows con XAMPP local (necesita PowerShell y exec()).</span>';
            if (autoControles) autoControles.style.display = 'none';
            return;
        }

        autoControles.style.display = 'flex';
        if (info.intervalo_min) autoIntervalo.value = String(info.intervalo_min);

        var activo = info.activo && info.tarea_instalada;
        autoActivar.innerHTML = activo
            ? '<i class="fas fa-rotate"></i> Volver a aplicar'
            : '<i class="fas fa-play"></i> Activar';
        autoDesactivar.style.display = activo ? 'inline-block' : 'none';

        var linea;
        if (activo) {
            linea = '<strong style="color:#166534;">Activa</strong> — el índice se actualiza solo cada ' + info.intervalo_min + ' min.';
        } else if (info.activo && !info.tarea_instalada) {
            linea = '<span style="color:#b45309;">Configurada, pero la tarea de Windows no aparece. Pulsa «Activar» para recrearla.</span>';
        } else {
            linea = '<strong>Desactivada</strong> — el índice solo se actualiza con este módulo abierto.';
        }

        var ultima = autoFmtUltima(info.ultima);
        autoEstado.innerHTML = linea + (ultima
            ? '<div style="margin-top:4px;color:var(--text-muted);font-size:11px;">' + ultima + '</div>'
            : '');
    }

    function autoRefrescar() {
        if (!autoEstado) return;
        autoEstado.textContent = 'Consultando…';
        if (autoMsg) autoMsg.style.display = 'none';
        postJson(BASE + '/correo/auto/estado', {})
            .then(autoPintar)
            .catch(function (err) { autoEstado.textContent = 'No se pudo consultar el estado: ' + err.message; });
    }

    if (autoActivar) {
        autoActivar.addEventListener('click', function () {
            autoActivar.disabled = true;
            autoMostrarMsg('Registrando la tarea de Windows…', false);
            postJson(BASE + '/correo/auto/activar', { intervalo_min: autoIntervalo.value })
                .then(function (r) {
                    autoMostrarMsg(r.message || 'Activada.', false);
                    autoRefrescar();
                })
                .catch(function (err) { autoMostrarMsg(err.message, true); })
                .then(function () { autoActivar.disabled = false; });
        });
    }

    if (autoDesactivar) {
        autoDesactivar.addEventListener('click', function () {
            autoDesactivar.disabled = true;
            postJson(BASE + '/correo/auto/desactivar', {})
                .then(function (r) {
                    autoMostrarMsg(r.message || 'Desactivada.', false);
                    autoRefrescar();
                })
                .catch(function (err) { autoMostrarMsg(err.message, true); })
                .then(function () { autoDesactivar.disabled = false; });
        });
    }

    // ── Cuentas de correo (agregar / editar / eliminar / probar) ──
    var ctaForm = document.getElementById('cta-form');

    function ctaMsg(texto, esError) {
        var el = document.getElementById('cta-msg');
        el.textContent = texto;
        el.style.display = 'block';
        el.style.background = esError ? '#fff5f5' : '#f0fdf4';
        el.style.border = '1px solid ' + (esError ? '#fed7d7' : '#86efac');
        el.style.color = esError ? '#b91c1c' : '#166534';
    }

    function ctaAbrirForm(datos) {
        datos = datos || {};
        document.getElementById('cta-id').value = datos.id || 0;
        document.getElementById('cta-nombre').value = datos.nombre || '';
        document.getElementById('cta-host').value = datos.host || '';
        document.getElementById('cta-puerto').value = datos.puerto || 993;
        document.getElementById('cta-usuario').value = datos.usuario || '';
        document.getElementById('cta-carpeta').value = datos.carpeta || 'INBOX';
        var pass = document.getElementById('cta-password');
        pass.value = '';
        pass.placeholder = datos.id ? '(dejar vacío para no cambiarla)' : '';

        // Buzón existente: se marcan sus sociedades. Buzón nuevo: la que esté
        // en uso, para que no nazca sin dueño y quede invisible.
        var asignadas = String(datos.sociedades || '').split(',').filter(Boolean);
        Array.prototype.forEach.call(document.querySelectorAll('.cta-soc'), function (chk) {
            chk.checked = datos.id
                ? asignadas.indexOf(chk.value) >= 0
                : String(chk.value) === String(SOCIEDAD_ACTIVA_ID);
        });

        document.getElementById('cta-msg').style.display = 'none';
        ctaForm.style.display = 'block';
        document.getElementById('cta-nombre').focus();
    }

    function ctaDatosForm() {
        var sociedades = Array.prototype.filter
            .call(document.querySelectorAll('.cta-soc'), function (c) { return c.checked; })
            .map(function (c) { return c.value; });
        return {
            id: document.getElementById('cta-id').value,
            nombre: document.getElementById('cta-nombre').value.trim(),
            host: document.getElementById('cta-host').value.trim(),
            puerto: document.getElementById('cta-puerto').value,
            usuario: document.getElementById('cta-usuario').value.trim(),
            password: document.getElementById('cta-password').value,
            carpeta: document.getElementById('cta-carpeta').value.trim(),
            sociedades: sociedades.join(',')
        };
    }

    if (ctaForm) {
        document.getElementById('cta-nueva').addEventListener('click', function () { ctaAbrirForm(); });
        document.getElementById('cta-cancelar').addEventListener('click', function () { ctaForm.style.display = 'none'; });

        Array.prototype.forEach.call(document.querySelectorAll('.cta-editar'), function (btn) {
            btn.addEventListener('click', function () { ctaAbrirForm(btn.dataset); });
        });

        Array.prototype.forEach.call(document.querySelectorAll('.cta-eliminar'), function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('¿Eliminar la cuenta "' + btn.dataset.nombre + '"? Su índice local dejará de usarse.')) return;
                postJson(BASE + '/correo/cuentas/eliminar', { id: btn.dataset.id })
                    .then(function () { window.location.href = BASE + '/correo'; })
                    .catch(function (err) { cfgMostrarMsg(err.message, true); });
            });
        });

        document.getElementById('cta-probar').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            ctaMsg('Conectando…', false);
            postJson(BASE + '/correo/cuentas/probar', ctaDatosForm())
                .then(function (r) { ctaMsg(r.message || 'Conexión exitosa.', false); })
                .catch(function (err) { ctaMsg(err.message, true); })
                .then(function () { btn.disabled = false; });
        });

        document.getElementById('cta-guardar').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            postJson(BASE + '/correo/cuentas/guardar', ctaDatosForm())
                .then(function () { window.location.href = BASE + '/correo'; })
                .catch(function (err) {
                    ctaMsg(err.message, true);
                    btn.disabled = false;
                });
        });
    }

    // ── Explorador de carpetas (elige la carpeta destino sin escribir) ──
    var cfgExaminar  = document.getElementById('cfg-examinar');
    var cfgExplor    = document.getElementById('cfg-explorador');
    var expSubir     = document.getElementById('exp-subir');
    var expRutaEl    = document.getElementById('exp-ruta');
    var expLista     = document.getElementById('exp-lista');
    var expNueva     = document.getElementById('exp-nueva');
    var expCrear     = document.getElementById('exp-crear');
    var expUsar      = document.getElementById('exp-usar');

    var expEstado = { ruta: '', esRaiz: true, padre: null, cargando: false };

    function expInfo(texto, esError) {
        expLista.innerHTML = '';
        var d = document.createElement('div');
        d.style.cssText = 'padding:14px;text-align:center;font-size:11.5px;color:' + (esError ? '#b91c1c' : 'var(--text-muted)');
        d.textContent = texto;
        expLista.appendChild(d);
    }

    function cargarExplorador(ruta, accion, nombre) {
        if (!expLista || expEstado.cargando) return;
        expEstado.cargando = true;
        expInfo('Cargando…');

        var datos = { ruta: ruta || '' };
        if (accion) { datos.accion = accion; datos.nombre = nombre || ''; }

        postJson(BASE + '/correo/carpetas', datos)
            .then(function (r) {
                expEstado.ruta = r.ruta || '';
                expEstado.esRaiz = !!r.es_raiz;
                expEstado.padre = (r.padre === undefined ? null : r.padre);

                expRutaEl.textContent = r.es_raiz ? 'Este equipo' : (r.ruta || '');
                expRutaEl.title = r.es_raiz ? 'Este equipo' : (r.ruta || '');
                expSubir.disabled = r.es_raiz;
                expUsar.disabled = r.es_raiz;                 // no se "usa" la lista de unidades
                expNueva.disabled = r.es_raiz;
                expCrear.disabled = r.es_raiz;
                if (expNueva) expNueva.value = '';

                var carpetas = r.carpetas || [];
                expLista.innerHTML = '';

                if (!r.es_raiz && r.escribible === false) {
                    var aviso = document.createElement('div');
                    aviso.style.cssText = 'padding:6px 10px;font-size:10.5px;color:#b45309;background:#fffbeb;border-bottom:1px solid #fde68a;';
                    aviso.textContent = '⚠ No se puede escribir en esta carpeta; elige o crea otra.';
                    expLista.appendChild(aviso);
                }

                if (!carpetas.length) {
                    var vac = document.createElement('div');
                    vac.style.cssText = 'padding:14px;text-align:center;font-size:11.5px;color:var(--text-muted);';
                    vac.textContent = r.es_raiz ? 'No se detectaron unidades.' : '(Sin subcarpetas — puedes usar esta o crear una)';
                    expLista.appendChild(vac);
                }

                carpetas.forEach(function (c) {
                    var row = document.createElement('div');
                    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;border-bottom:1px solid #f1f5f9;';
                    row.addEventListener('mouseenter', function () { row.style.background = '#eef2ff'; });
                    row.addEventListener('mouseleave', function () { row.style.background = ''; });

                    var ic = document.createElement('i');
                    ic.className = r.es_raiz ? 'fas fa-hard-drive' : 'fas fa-folder';
                    ic.style.color = r.es_raiz ? 'var(--navy-light)' : 'var(--gold)';
                    ic.style.width = '14px';

                    var nom = document.createElement('span');
                    nom.textContent = c.nombre;
                    nom.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';

                    var flecha = document.createElement('i');
                    flecha.className = 'fas fa-chevron-right';
                    flecha.style.cssText = 'color:#cbd5e1;font-size:10px;';

                    row.appendChild(ic);
                    row.appendChild(nom);
                    row.appendChild(flecha);
                    row.addEventListener('click', function () { cargarExplorador(c.ruta); });
                    expLista.appendChild(row);
                });
            })
            .catch(function (err) {
                expInfo(err.message, true);
            })
            .then(function () { expEstado.cargando = false; });
    }

    // «Examinar» abre el explorador NATIVO de Windows (diálogo del sistema).
    // El servidor lo lanza en el escritorio del usuario y aquí se consulta
    // cada segundo si ya eligió carpeta. Si el diálogo no se puede abrir,
    // se cae al explorador integrado del modal como respaldo.
    var selectorNativo = { activo: false, timer: null };

    function detenerSelector() {
        selectorNativo.activo = false;
        if (selectorNativo.timer) { clearTimeout(selectorNativo.timer); selectorNativo.timer = null; }
        if (cfgExaminar) {
            cfgExaminar.disabled = false;
            cfgExaminar.innerHTML = '<i class="fas fa-folder-open"></i> Examinar';
        }
    }

    if (cfgExaminar) {
        cfgExaminar.addEventListener('click', function () {
            if (selectorNativo.activo) return;
            selectorNativo.activo = true;
            cfgExaminar.disabled = true;
            cfgExaminar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Abriendo…';
            cfgMsg.style.display = 'none';
            cfgExplor.style.display = 'none';

            postJson(BASE + '/correo/selector/abrir', {})
                .then(function (r) {
                    cfgMostrarMsg('Elige la carpeta en la ventana del explorador de Windows (puede tardar unos segundos en aparecer)…', false);

                    var intentos = 0;
                    function consultar() {
                        if (!selectorNativo.activo) return;
                        if (modalCfg.style.display === 'none') { detenerSelector(); return; }
                        if (++intentos > 300) { // ~5 minutos
                            detenerSelector();
                            cfgMostrarMsg('Se agotó el tiempo de espera del explorador de Windows.', true);
                            return;
                        }

                        postJson(BASE + '/correo/selector/estado', { token: r.token })
                            .then(function (e) {
                                if (e.estado === 'esperando') {
                                    selectorNativo.timer = setTimeout(consultar, 1000);
                                    return;
                                }
                                detenerSelector();
                                if (e.estado === 'ok') {
                                    cfgCarpeta.value = e.ruta;
                                    cfgMostrarMsg('Carpeta elegida: ' + e.ruta + ' — pulsa Guardar para aplicarla.', false);
                                } else {
                                    cfgMsg.style.display = 'none'; // canceló el diálogo
                                }
                            })
                            .catch(function (err) {
                                detenerSelector();
                                // El lanzador falló en segundo plano: mismo
                                // respaldo que si /selector/abrir fallara
                                cfgMostrarMsg('No se pudo abrir el explorador de Windows (' + err.message + '). Elige la carpeta aquí:', true);
                                cfgExplor.style.display = 'block';
                                cargarExplorador((cfgCarpeta.value || '').trim());
                            });
                    }

                    selectorNativo.timer = setTimeout(consultar, 900);
                })
                .catch(function (err) {
                    detenerSelector();
                    // Respaldo: explorador integrado dentro del modal
                    cfgMostrarMsg('No se pudo abrir el explorador de Windows (' + err.message + '). Elige la carpeta aquí:', true);
                    cfgExplor.style.display = 'block';
                    cargarExplorador((cfgCarpeta.value || '').trim());
                });
        });
    }

    if (expSubir) {
        expSubir.addEventListener('click', function () {
            if (!expEstado.esRaiz) cargarExplorador(expEstado.padre || '');
        });
    }

    if (expUsar) {
        expUsar.addEventListener('click', function () {
            if (expEstado.esRaiz || !expEstado.ruta) return;
            cfgCarpeta.value = expEstado.ruta;
            cfgExplor.style.display = 'none';
        });
    }

    if (expCrear) {
        expCrear.addEventListener('click', function () {
            var nombre = (expNueva.value || '').trim();
            if (!nombre) { expNueva.focus(); return; }
            cargarExplorador(expEstado.ruta, 'crear', nombre);
        });
    }
    if (expNueva) {
        expNueva.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); expCrear.click(); }
        });
    }

    if (inputBuscar) {
        inputBuscar.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); cargarCorreos(); }
        });
        // Escribir filtra al instante lo ya cargado; Enter busca en el buzón
        inputBuscar.addEventListener('input', function () {
            if (correos.length) renderCorreos();
        });
    }

    if (cbVisibles) {
        cbVisibles.addEventListener('change', function () {
            correosFiltrados().forEach(function (c) {
                if (cbVisibles.checked) seleccion[c.clave] = { uid: c.uid, carpeta: c.carpeta || '' };
                else delete seleccion[c.clave];
            });
            renderCorreos();
        });
    }

    // ── Procesar correos seleccionados ──
    function procesarItems(items) {
        if (!items.length) {
            alert('Marca al menos un correo para procesar.');
            return;
        }

        btnProcesar.disabled = true;
        btnCargar.disabled = true;
        panel.style.display = 'block';
        setBar(0);
        elCounts.textContent = '';
        elDetail.textContent = '';

        var totales = { procesados: 0, nuevas: 0, ya_existentes: 0, aceptadas: 0, rechazadas: 0, otra_cedula: 0, pdfs_guardados: 0, pdfs_sin_identificar: 0, sin_adjuntos: 0 };
        var errores = [];
        var hechos = 0;

        var cadena = Promise.resolve();
        for (var i = 0; i < items.length; i += CHUNK_PROCESAR) {
            (function (lote) {
                cadena = cadena.then(function () {
                    setStatus('Procesando correos… (' + Math.min(hechos + lote.length, items.length) + '/' + items.length + ')');
                    return postJson(BASE + '/correo/procesar', { items: JSON.stringify(lote) }).then(function (r) {
                        hechos += lote.length;
                        totales.procesados += r.procesados || 0;
                        totales.nuevas += r.nuevas || 0;
                        totales.ya_existentes += r.ya_existentes || 0;
                        totales.aceptadas += r.aceptadas || 0;
                        totales.rechazadas += r.rechazadas || 0;
                        totales.otra_cedula += r.otra_cedula || 0;
                        totales.pdfs_guardados += r.pdfs_guardados || 0;
                        totales.pdfs_sin_identificar += r.pdfs_sin_identificar || 0;
                        totales.sin_adjuntos += r.sin_adjuntos || 0;
                        (r.errores || []).forEach(function (e) { errores.push(e); });

                        setBar((hechos / items.length) * 100);
                        elCounts.textContent =
                            'Nuevas: ' + totales.nuevas +
                            ' · Ya existentes: ' + totales.ya_existentes +
                            ' · PDFs: ' + totales.pdfs_guardados;
                    });
                });
            })(items.slice(i, i + CHUNK_PROCESAR));
        }

        cadena
            .then(function () {
                setBar(100);
                var partes = [
                    '<i class="fas fa-check-circle" style="margin-right:5px;"></i>',
                    '<strong>' + totales.nuevas + '</strong> factura' + (totales.nuevas !== 1 ? 's' : '') + ' nueva' + (totales.nuevas !== 1 ? 's' : '') + ' en bandeja'
                ];
                partes.push('· ' + totales.pdfs_guardados + ' PDF' + (totales.pdfs_guardados !== 1 ? 's' : ''));
                if (totales.aceptadas > 0) partes.push('· ' + totales.aceptadas + ' verificada' + (totales.aceptadas !== 1 ? 's' : '') + ' por Hacienda');
                if (totales.rechazadas > 0) partes.push('· <strong style="color:#b91c1c;">' + totales.rechazadas + ' rechazada' + (totales.rechazadas !== 1 ? 's' : '') + ' por Hacienda</strong>');
                if (totales.otra_cedula > 0) partes.push('· <strong style="color:#ea580c;">' + totales.otra_cedula + ' con otra cédula (no son de la empresa)</strong>');
                if (totales.pdfs_sin_identificar > 0) partes.push('· ' + totales.pdfs_sin_identificar + ' PDF sin identificar');
                if (totales.sin_adjuntos > 0) partes.push('· ' + totales.sin_adjuntos + ' sin adjuntos');

                var html = partes.join(' ');
                if (errores.length) {
                    html += '<div style="margin-top:6px;color:#b45309;">'
                         + errores.slice(0, 8).map(function (e) {
                               var div = document.createElement('div');
                               div.textContent = '• ' + String(e).substring(0, 160);
                               return div.innerHTML;
                           }).join('<br>')
                         + '</div>';
                }

                setStatus('¡Listo! Actualizando…');
                setTimeout(function () { mostrarYRecargar(html, true); }, 700);
            })
            .catch(function (err) {
                setStatus('Error: ' + err.message);
                elDetail.textContent = 'Puedes volver a intentarlo con los mismos correos.';
                btnProcesar.disabled = false;
                btnCargar.disabled = false;
            });
    }

    if (btnProcesar) {
        btnProcesar.addEventListener('click', function () {
            procesarItems(Object.values(seleccion));
        });
    }

    // ── Bandeja ──
    if (cbTodas) {
        cbTodas.addEventListener('change', function () {
            document.querySelectorAll('.cb-row').forEach(function (cb) { cb.checked = cbTodas.checked; });
        });
    }

    // Recordar la semana elegida en la bandeja para los demás módulos.
    // "Nueva…" se crea recién al importar; aquí solo se guardan semanas reales
    // o "Sin semana" (valor vacío → 0).
    var selSemanaImpPersist = document.getElementById('sel-semana-imp');
    if (selSemanaImpPersist) {
        selSemanaImpPersist.addEventListener('change', function () {
            if (selSemanaImpPersist.value === 'nueva') return;
            postJson(BASE + '/correo/semana/usar', { semana_id: selSemanaImpPersist.value || 0 })
                .catch(function () {}); // recordar la semana es best-effort
        });
    }

    function idsSeleccionados(filtroEstado) {
        return Array.prototype.slice.call(document.querySelectorAll('.cb-row:checked'))
            .filter(function (cb) { return !filtroEstado || cb.dataset.estado === filtroEstado; })
            .map(function (cb) { return cb.value; });
    }

    if (btnImportar) {
        btnImportar.addEventListener('click', function () {
            var ids = idsSeleccionados('pendiente');
            if (!ids.length) {
                alert('Marca al menos una factura pendiente para importar.');
                return;
            }

            // Semana de trabajo a la que quedarán asignadas las facturas
            var selSemanaImp = document.getElementById('sel-semana-imp');
            var semanaId = selSemanaImp ? selSemanaImp.value : '';
            var semanaNueva = '';
            if (semanaId === 'nueva') {
                semanaNueva = prompt('Nombre de la semana nueva:', 'Semana ' + new Date().toLocaleDateString('es-CR'));
                if (semanaNueva === null) return; // canceló
            }

            btnImportar.disabled = true;
            if (btnDescartar) btnDescartar.disabled = true;
            panel.style.display = 'block';
            setBar(0);
            elDetail.textContent = '';
            setStatus('Encolando ' + ids.length + ' factura' + (ids.length !== 1 ? 's' : '') + '…');

            var importacionId = 0;
            var archivosGuardados = 0;
            var avisoCarpeta = '';
            var avisosArchivos = [];

            postJson(BASE + '/correo/importar', {
                ids: ids.join(','),
                semana_id: semanaId,
                semana_nueva: semanaNueva
            })
                .then(function (r) {
                    importacionId = r.importacion_id;
                    archivosGuardados = r.archivos_guardados || 0;
                    avisoCarpeta = r.aviso_carpeta || '';
                    avisosArchivos = r.avisos || [];
                    setBar(15);
                    setStatus('Procesando facturas…');

                    var sinAvance = 0;
                    function paso() {
                        return postJson(BASE + '/facturas/cola/procesar', {
                            importacion_id: importacionId,
                            limit: BATCH_LIMIT
                        }).then(function (pr) {
                            var estado = pr.estado || {};
                            var s = estado.stats || {};
                            setBar(15 + ((estado.progress_percent || 0) * 0.85));
                            elCounts.textContent =
                                'Importadas: ' + (s.importado || 0) +
                                ' · Ya en esta semana: ' + (s.duplicado || 0) +
                                ' · Errores: ' + (s.error || 0) +
                                ' · Pendientes: ' + (s.pendiente || 0);

                            if (pr.completed) return estado;

                            if (!pr.processed_in_batch) {
                                sinAvance++;
                                if (sinAvance >= 5) {
                                    throw new Error('El procesamiento se detuvo sin completar. Revisa el historial de importaciones en Facturas.');
                                }
                                return sleep(2000).then(paso);
                            }

                            sinAvance = 0;
                            return paso();
                        });
                    }

                    return paso();
                })
                .then(function (estado) {
                    setBar(100);
                    var s = (estado && estado.stats) || {};
                    var importadas = s.importado || 0;
                    var repetidas = s.duplicado || 0;
                    var erroresImportacion = s.error || 0;

                    var html = '<i class="fas fa-check-circle" style="margin-right:5px;"></i>'
                        + '<strong>' + importadas + '</strong> factura' + (importadas !== 1 ? 's' : '') + ' importada' + (importadas !== 1 ? 's' : '')
                        + (repetidas > 0 ? ' · ' + repetidas + ' ya estaba' + (repetidas !== 1 ? 'n' : '') + ' en esta semana' : '')
                        + (erroresImportacion > 0 ? ' · ' + erroresImportacion + ' con error' : '')
                        + (archivosGuardados > 0 ? ' · ' + archivosGuardados + ' archivo' + (archivosGuardados !== 1 ? 's' : '') + ' renombrado' + (archivosGuardados !== 1 ? 's' : '') + ' en la carpeta' : '')
                        + ' — <a href="' + BASE + '/facturas?importacion_id=' + importacionId + '" style="color:#166534;font-weight:700;">Ver en Facturas</a>';
                    if (avisoCarpeta) {
                        html += '<div style="margin-top:6px;color:#b45309;">⚠ ' + avisoCarpeta + '</div>';
                    }
                    // Archivos que NO llegaron completos a la carpeta destino
                    // (XML/PDF con copia fallida o factura sin PDF)
                    if (avisosArchivos.length) {
                        html += '<div style="margin-top:6px;color:#b45309;">'
                             + avisosArchivos.slice(0, 8).map(function (a) {
                                   var div = document.createElement('div');
                                   div.textContent = '⚠ ' + String(a).substring(0, 160);
                                   return div.innerHTML;
                               }).join('<br>')
                             + '</div>';
                    }

                    setStatus('¡Listo! Actualizando…');
                    setTimeout(function () { mostrarYRecargar(html, true); }, 900);
                })
                .catch(function (err) {
                    setStatus('Error: ' + err.message);
                    elDetail.textContent = 'Puedes reintentar: solo se bloquearán las facturas que ya estén en la semana seleccionada.';
                    btnImportar.disabled = false;
                    if (btnDescartar) btnDescartar.disabled = false;
                });
        });
    }

    if (btnDescartar) {
        btnDescartar.addEventListener('click', function () {
            var ids = idsSeleccionados(null);
            if (!ids.length) {
                alert('Marca al menos una fila para descartar.');
                return;
            }
            if (!confirm('¿Descartar ' + ids.length + ' fila' + (ids.length !== 1 ? 's' : '') + '? Se elimina su XML; los PDF guardados se conservan.')) {
                return;
            }

            btnDescartar.disabled = true;
            postJson(BASE + '/correo/descartar', { ids: ids.join(',') })
                .then(function (r) {
                    mostrarYRecargar('<i class="fas fa-check-circle" style="margin-right:5px;"></i>'
                        + r.descartadas + ' fila' + (r.descartadas !== 1 ? 's' : '') + ' descartada' + (r.descartadas !== 1 ? 's' : '') + '.', true);
                })
                .catch(function (err) {
                    alert('No fue posible descartar: ' + err.message);
                    btnDescartar.disabled = false;
                });
        });
    }
})();

// ── Paneles redimensionables (arrastrar bordes; persiste en el navegador) ──
(function () {
    var layout = document.getElementById('correo-layout');
    if (!layout) return;

    var LS = 'correoLayout';
    var VARS = { lista: '--lista-w', bandeja: '--bandeja-w', inferior: '--sup-h' };

    // Restaurar tamaños guardados
    try {
        var guardado = JSON.parse(localStorage.getItem(LS) || '{}');
        Object.keys(guardado).forEach(function (k) {
            if (VARS[k]) layout.style.setProperty(VARS[k], guardado[k]);
        });
    } catch (e) {}

    var listaPanelRz = layout.querySelector('.correo-lista');
    if (listaPanelRz && !listaPanelRz.classList.contains('carpetas-cerradas')) {
        var anchoLista = parseFloat(getComputedStyle(layout).getPropertyValue('--lista-w')) || 0;
        if (anchoLista < 470) layout.style.setProperty('--lista-w', '470px');
    }

    function guardar(rz, valor) {
        try {
            var g = JSON.parse(localStorage.getItem(LS) || '{}');
            g[rz] = valor;
            localStorage.setItem(LS, JSON.stringify(g));
        } catch (e) {}
    }

    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

    Array.prototype.forEach.call(layout.querySelectorAll('.correo-resizer'), function (rz) {
        var tipo = rz.dataset.rz;

        rz.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var rect = layout.getBoundingClientRect();
            rz.classList.add('arrastrando');
            document.body.classList.add('correo-resizing');
            document.body.style.cursor = (tipo === 'inferior') ? 'row-resize' : 'col-resize';

            function mover(ev) {
                var valor;
                if (tipo === 'lista') {
                    var minLista = listaPanelRz && !listaPanelRz.classList.contains('carpetas-cerradas') ? 470 : 220;
                    valor = clamp(ev.clientX - rect.left, minLista, rect.width * 0.55);
                    layout.style.setProperty('--lista-w', valor + 'px');
                } else if (tipo === 'bandeja') {
                    valor = clamp(rect.right - ev.clientX, 200, rect.width * 0.55);
                    layout.style.setProperty('--bandeja-w', valor + 'px');
                } else {
                    valor = clamp(ev.clientY - rect.top, 180, rect.height - 130);
                    layout.style.setProperty('--sup-h', valor + 'px');
                }
            }

            function soltar() {
                document.removeEventListener('mousemove', mover);
                document.removeEventListener('mouseup', soltar);
                rz.classList.remove('arrastrando');
                document.body.classList.remove('correo-resizing');
                document.body.style.cursor = '';
                guardar(tipo, getComputedStyle(layout).getPropertyValue(VARS[tipo]).trim());
            }

            document.addEventListener('mousemove', mover);
            document.addEventListener('mouseup', soltar);
        });

        // Doble clic: restaurar el tamaño por defecto de ese divisor
        rz.addEventListener('dblclick', function () {
            layout.style.removeProperty(VARS[tipo]);
            try {
                var g = JSON.parse(localStorage.getItem(LS) || '{}');
                delete g[tipo];
                localStorage.setItem(LS, JSON.stringify(g));
            } catch (e) {}
        });
    });
})();
</script>

<?php if ($modoCorreo === 'descargas'): ?>
<script>
(function () {
    var BASE = '<?= $baseUrl ?>';
    var CUENTA = <?= (int) $cuentaActivaId ?>;
    var lote = <?= json_encode($loteGeneral, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var ejecutandoPeticion = false;
    var detenido = false;
    var estado = document.getElementById('general-estado');
    var barra = document.getElementById('general-barra');
    var inc = document.getElementById('general-incidencias');
    var iniciar = document.getElementById('general-iniciar');
    var pausar = document.getElementById('general-pausar');
    var reanudar = document.getElementById('general-reanudar');
    var cancelar = document.getElementById('general-cancelar');
    var historialBtn = document.getElementById('general-historial');
    var historialModal = document.getElementById('modal-incidencias');
    var historialCerrar = document.getElementById('incidencias-cerrar');
    var historialBody = document.getElementById('incidencias-body');
    var historialResumen = document.getElementById('incidencias-resumen');
    var historialQ = document.getElementById('incidencias-q');
    var historialTipo = document.getElementById('incidencias-tipo');
    var historialBuscar = document.getElementById('incidencias-buscar');
    var historialPrev = document.getElementById('incidencias-prev');
    var historialNext = document.getElementById('incidencias-next');
    var historialPaginaEl = document.getElementById('incidencias-pagina');
    var historialPagina = 1;
    var historialPaginas = 1;

    function post(ruta, datos) {
        var fd = new FormData();
        Object.keys(datos || {}).forEach(function (k) { fd.append(k, datos[k]); });
        fd.append('cuenta_id', CUENTA);
        return fetch(BASE + ruta, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function (res) { return res.json().catch(function(){return null;}).then(function(body){
                if (!res.ok || !body || body.ok === false) throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                return body;
            }); });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function fechaHistorial(value) {
        var text = String(value || '');
        if (text.length < 16) return text || '—';
        return text.substring(8,10) + '/' + text.substring(5,7) + '/' + text.substring(0,4)
            + ' ' + text.substring(11,16);
    }

    function cargarHistorial(pagina) {
        historialPagina = Math.max(1, Number(pagina || 1));
        historialBody.innerHTML = '<tr><td colspan="7" style="padding:30px;text-align:center;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Cargando incidencias…</td></tr>';
        post('/correo/general/incidencias', {
            pagina: historialPagina,
            q: historialQ.value || '',
            tipo: historialTipo.value || ''
        }).then(function (r) {
            historialPagina = Number(r.page || 1);
            historialPaginas = Number(r.pages || 1);
            historialResumen.textContent = Number(r.total || 0) + ' incidencias guardadas para esta cuenta';
            historialPaginaEl.textContent = 'Página ' + historialPagina + ' de ' + historialPaginas;
            historialPrev.disabled = historialPagina <= 1;
            historialNext.disabled = historialPagina >= historialPaginas;

            var tipoActual = historialTipo.value;
            var tiposExistentes = {};
            Array.prototype.forEach.call(historialTipo.options, function (option) { tiposExistentes[option.value] = true; });
            (r.tipos || []).forEach(function (tipo) {
                if (tiposExistentes[tipo]) return;
                var option = document.createElement('option');
                option.value = tipo;
                option.textContent = tipo;
                historialTipo.appendChild(option);
            });
            historialTipo.value = tipoActual;

            if (!(r.rows || []).length) {
                historialBody.innerHTML = '<tr><td colspan="7" style="padding:30px;text-align:center;color:#64748b;">No hay incidencias con estos filtros.</td></tr>';
                return;
            }
            historialBody.innerHTML = (r.rows || []).map(function (row) {
                var asunto = String(row.asunto || '').trim();
                var termino = asunto || String(row.remitente || '').trim();
                var puedeAbrir = Number(row.uid || 0) > 0 && termino !== '';
                var url = BASE + '/correo?modo=general&buscar=' + encodeURIComponent(termino)
                    + '&abrir_uid=' + encodeURIComponent(row.uid || '')
                    + '&abrir_carpeta=' + encodeURIComponent(row.carpeta || '');
                var accion = puedeAbrir
                    ? '<a class="btn btn-outline btn-sm" href="'+escapeHtml(url)+'" title="Buscar y abrir el correo original"><i class="fas fa-envelope-open-text"></i> Ver correo</a>'
                    : '<span style="font-size:10.5px;color:#94a3b8;">Sin referencia</span>';
                return '<tr>'+
                    '<td style="white-space:nowrap;font-size:11px;">'+escapeHtml(fechaHistorial(row.creado_en))+'</td>'+
                    '<td style="font-weight:700;color:var(--navy);">#'+Number(row.lote_id || 0)+'</td>'+
                    '<td><span class="badge" style="background:#fff7ed;color:#9a3412;">'+escapeHtml(row.tipo || 'incidencia')+'</span></td>'+
                    '<td style="max-width:260px;"><div style="font-weight:700;color:var(--navy);white-space:normal;">'+escapeHtml(asunto || '(Sin asunto)')+'</div>'+
                    '<div style="font-size:10.5px;color:#64748b;">'+escapeHtml(row.remitente || '')+'</div></td>'+
                    '<td style="max-width:390px;white-space:normal;font-size:11.5px;">'+escapeHtml(row.mensaje || '')+'</td>'+
                    '<td style="max-width:190px;overflow-wrap:anywhere;font-size:10.5px;color:#64748b;">'+escapeHtml(row.carpeta || '—')+'</td>'+
                    '<td style="white-space:nowrap;">'+accion+'</td></tr>';
            }).join('');
        }).catch(function (e) {
            historialBody.innerHTML = '<tr><td colspan="7" style="padding:30px;text-align:center;color:#b91c1c;">'+escapeHtml(e.message)+'</td></tr>';
        });
    }

    historialBtn.addEventListener('click', function () {
        historialModal.style.display = 'flex';
        cargarHistorial(1);
    });
    historialCerrar.addEventListener('click', function () { historialModal.style.display = 'none'; });
    historialModal.addEventListener('click', function (event) {
        if (event.target === historialModal) historialModal.style.display = 'none';
    });
    historialBuscar.addEventListener('click', function () { cargarHistorial(1); });
    historialQ.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); cargarHistorial(1); }
    });
    historialTipo.addEventListener('change', function () { cargarHistorial(1); });
    historialPrev.addEventListener('click', function () { if (historialPagina > 1) cargarHistorial(historialPagina - 1); });
    historialNext.addEventListener('click', function () { if (historialPagina < historialPaginas) cargarHistorial(historialPagina + 1); });

    function render(l, incidencias) {
        lote = l || lote;
        if (!lote) { estado.textContent = 'Define el rango y pulsa Iniciar.'; return; }
        var total = Number(lote.total_mensajes || 0), hechos = Number(lote.procesados || 0);
        var pct = total ? Math.min(100, Math.round(hechos * 100 / total)) : (lote.estado === 'completado' ? 100 : 0);
        barra.style.width = pct + '%';
        estado.innerHTML = '<strong>Lote #' + lote.id + ' · ' + lote.estado + '</strong> — '
            + hechos + '/' + total + ' correos · ' + Number(lote.documentos_importados || 0) + ' documentos importados'
            + ' · ' + Number(lote.duplicados || 0) + ' duplicados · ' + Number(lote.pdf_pendientes || 0) + ' PDF pendientes';
        iniciar.style.display = ['ejecutando','pausado'].indexOf(lote.estado) >= 0 ? 'none' : '';
        pausar.style.display = lote.estado === 'ejecutando' ? '' : 'none';
        reanudar.style.display = lote.estado === 'pausado' ? '' : 'none';
        cancelar.style.display = ['ejecutando','pausado','pendiente'].indexOf(lote.estado) >= 0 ? '' : 'none';
        if (Array.isArray(incidencias)) {
            inc.innerHTML = incidencias.slice(0, 8).map(function(x){
                var d=document.createElement('div'); d.textContent='⚠ ' + x.tipo + ': ' + x.mensaje; return d.innerHTML;
            }).join('<br>');
        }
    }

    function paso() {
        if (detenido || ejecutandoPeticion || !lote || lote.estado !== 'ejecutando') return;
        ejecutandoPeticion = true;
        post('/correo/general/procesar', {lote_id:lote.id, limit:6})
            .then(function(r){ render(r.lote, r.incidencias || []); })
            .catch(function(e){ estado.textContent = 'Error: ' + e.message; })
            .then(function(){
                ejecutandoPeticion = false;
                if (lote && lote.estado === 'ejecutando' && !detenido) setTimeout(paso, 500);
            });
    }

    iniciar.addEventListener('click', function(){
        var desde=document.getElementById('general-desde').value, hasta=document.getElementById('general-hasta').value;
        var correo=document.getElementById('general-correo').value.trim();
        if (!desde || !hasta) { alert('Indica ambas fechas.'); return; }
        if (correo && !document.getElementById('general-correo').checkValidity()) { alert('Indica un correo válido.'); return; }
        var incluirProcesados = document.getElementById('general-incluir-procesados').checked;
        iniciar.disabled=true; estado.textContent='Calculando correos candidatos…';
        post('/correo/general/estimar', {fecha_desde:desde, fecha_hasta:hasta, correo_busqueda:correo})
            .then(function(r){
                var filtro = correo ? ' que coinciden con ' + correo : '';
                var yaVistos = Number(r.procesados || 0);
                // Con la casilla marcada se revisa todo el rango; si no, solo
                // lo que ninguna corrida anterior haya procesado ya.
                var aRevisar = incluirProcesados ? Number(r.total || 0) : Number(r.nuevos || 0);
                var mensaje = 'Se revisarán ' + aRevisar + ' correos' + filtro + ' de la cuenta seleccionada.';
                if (yaVistos > 0) {
                    mensaje += incluirProcesados
                        ? '\n\nIncluye ' + yaVistos + ' que ya se habían procesado antes.'
                        : '\n\nSe omiten ' + yaVistos + ' ya procesados en corridas anteriores.';
                }
                if (aRevisar === 0) mensaje += '\n\nNo hay nada nuevo que revisar en este rango.';
                if (!confirm(mensaje + '\n\n¿Iniciar?')) throw new Error('__cancelado__');
                estado.textContent='Creando el lote…';
                return post('/correo/general/crear', {
                    fecha_desde:desde, fecha_hasta:hasta, correo_busqueda:correo,
                    incluir_procesados: incluirProcesados ? '1' : '0'
                });
            })
            .then(function(r){ detenido=false; render(r.lote, []); paso(); })
            .catch(function(e){ if(e.message!=='__cancelado__') estado.textContent='No se pudo iniciar: '+e.message; })
            .then(function(){ iniciar.disabled=false; });
    });
    pausar.addEventListener('click', function(){ detenido=true; post('/correo/general/pausar',{lote_id:lote.id}).then(function(r){render(r.lote,[]);}); });
    reanudar.addEventListener('click', function(){ detenido=false; post('/correo/general/reanudar',{lote_id:lote.id}).then(function(r){render(r.lote,[]);paso();}); });
    cancelar.addEventListener('click', function(){ if(!confirm('Se detendrá lo pendiente; lo ya importado se conserva.'))return; detenido=true; post('/correo/general/cancelar',{lote_id:lote.id}).then(function(r){render(r.lote,[]);}); });

    render(lote, []);
    if (lote && lote.estado === 'ejecutando') paso();
})();
</script>
<?php endif; ?>
