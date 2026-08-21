<?php
/**
 * Configuración del sistema.
 *
 * Una sola página, con índice al lado y una sección por tema. El engranaje de
 * la barra superior llega aquí desde cualquier módulo: lo que se ajusta acá
 * —dónde se guardan los XML y PDF, con qué empresa se trabaja, qué buzones se
 * leen— lo usan todos, no solo el correo, que es donde vivía escondido.
 *
 * Cada sección tiene ancla propia (#archivo, #correo, …) para poder mandar a
 * alguien directo al ajuste del que se le está hablando.
 */
$baseUrl        = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$esAdmin        = !empty($esAdmin);
$imapDisponible = !empty($imapDisponible);
$configLocal    = is_array($configLocal ?? null) ? $configLocal : ['carpeta_destino' => ''];
$cuentas        = is_array($cuentas ?? null) ? $cuentas : [];
$cuentaActivaId = (int) ($cuentaActivaId ?? 0);
$sociedades     = is_array($sociedades ?? null) ? $sociedades : [];
$sociedadActiva = $sociedadActiva ?? null;
$sociedadEnUsoId = (int) ($sociedadEnUsoId ?? 0);
$semanas        = is_array($semanas ?? null) ? $semanas : [];
$semanaActiva   = (int) ($semanaActiva ?? 0);
$usuarios       = is_array($usuarios ?? null) ? $usuarios : [];
$respaldo       = is_array($respaldo ?? null) ? $respaldo : null;
$seccionInicial = (string) ($seccionInicial ?? '');
$selfId         = (int) ($_SESSION['user_id'] ?? 0);

$carpetaRaiz    = trim((string) ($configLocal['carpeta_destino'] ?? ''));

// El índice se arma de una lista, no a mano en el HTML: así el buscador del
// índice y las secciones no se pueden desincronizar.
$secciones = [
    ['archivo',        'fa-folder-tree',        'Archivo de documentos', 'Dónde se guardan los XML y PDF'],
    ['correo',         'fa-at',                 'Cuentas de correo',     'Buzones de los que se bajan las facturas'],
    ['automatizacion', 'fa-rotate',             'Automatización',        'Qué hace el sistema solo, en segundo plano'],
    ['empresas',       'fa-building',           'Empresas',              'Con cuál se trabaja y contra qué cédula se verifica'],
];
if ($esAdmin) {
    $secciones[] = ['usuarios', 'fa-users-cog', 'Usuarios',  'Quién entra al sistema'];
    $secciones[] = ['respaldo', 'fa-database',  'Respaldo',  'Copias de la base en la carpeta compartida'];
}
$secciones[] = ['sistema', 'fa-desktop', 'Esta computadora', 'Estado de la instalación local'];
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-gear" style="color:var(--gold);margin-right:8px;"></i>Configuración</h1>
        <p>
            Lo que vale para todo el sistema, en un solo lugar. Los cambios de esta pantalla
            afectan a <strong>todos los módulos</strong>; los de <em>Archivo</em> y
            <em>Automatización</em>, solo a esta computadora.
        </p>
    </div>
</div>

<div class="cfg-shell">

    <!-- ══ Índice ═══════════════════════════════════════════ -->
    <nav class="cfg-nav" id="cfg-nav" aria-label="Secciones de configuración">
        <div class="cfg-nav-buscar">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="cfg-buscar" placeholder="Buscar un ajuste…"
                   autocomplete="off" aria-label="Buscar un ajuste">
        </div>
        <?php foreach ($secciones as [$id, $icono, $titulo, $resumen]): ?>
        <a href="#<?= $id ?>" data-cfg-nav="<?= $id ?>" title="<?= htmlspecialchars($resumen) ?>">
            <i class="fas <?= $icono ?>"></i><span><?= htmlspecialchars($titulo) ?></span>
        </a>
        <?php endforeach; ?>
        <div class="cfg-nav-vacio" id="cfg-nav-vacio" style="display:none;">Ningún ajuste coincide.</div>
    </nav>

    <!-- ══ Secciones ════════════════════════════════════════ -->
    <div class="cfg-main">

        <!-- ── Archivo de documentos ───────────────────────── -->
        <section class="cfg-section" id="archivo" data-cfg-seccion="archivo"
                 data-cfg-buscar="archivo documentos carpeta raiz raíz xml pdf ruta sharepoint ordenar mover mes">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono gold"><i class="fas fa-folder-tree"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Archivo de documentos</h2>
                        <p>Dónde quedan guardados los XML y los PDF de esta computadora.</p>
                    </div>
                </div>

                <div class="cfg-row apilada">
                    <div>
                        <div class="cfg-row-label">
                            <i class="fas fa-folder-open" style="color:var(--gold);"></i>Carpeta raíz de XML y PDF
                        </div>
                        <div class="cfg-row-ayuda">
                            <?php /*
                             * El árbol, dibujado, en vez de la ruta escrita en prosa: la
                             * pregunta que trae a la gente a esta pantalla no es cómo se
                             * llama la carpeta, sino cuál se puede tocar. SISTEMA es lo
                             * que la aplicación administra —y lleva dentro su propia nota
                             * de advertencia—; PAGOS SEMANALES es donde se trabaja.
                             */ ?>
                            Dentro de esta raíz la aplicación arma su propia estructura, y no
                            todas las carpetas son iguales:
                            <div class="cfg-arbol">
                                <div class="cfg-arbol-linea">
                                    <i class="fas fa-folder-closed" style="color:var(--navy-light);"></i>
                                    <code>SISTEMA</code>
                                    <span class="cfg-arbol-tag no-tocar">no se toca</span>
                                </div>
                                <div class="cfg-arbol-hijo">
                                    <code>AAAA/MM MES/Facturas</code> · <code>Notas de crédito</code> ·
                                    <code>Notas de débito</code>, según la fecha de emisión, con el nombre
                                    <code>FE_PROVEEDOR_120726_00004354</code>. Es lo que bajó del correo:
                                    la aplicación guarda dónde está cada archivo, así que moverlos a mano
                                    los da por perdidos.
                                </div>
                                <div class="cfg-arbol-linea">
                                    <i class="fas fa-folder-open" style="color:var(--gold);"></i>
                                    <code>PAGOS SEMANALES</code>
                                    <span class="cfg-arbol-tag para-trabajar">para trabajar</span>
                                </div>
                                <div class="cfg-arbol-hijo">
                                    Una carpeta por pago, con una <strong>copia</strong> de cada respaldo.
                                    Se arma sola, se envía y se puede borrar sin afectar nada.
                                </div>
                                <div class="cfg-arbol-linea">
                                    <i class="fas fa-folder-closed" style="color:var(--text-muted);"></i>
                                    <code>_TRABAJO</code>
                                    <span class="cfg-arbol-tag no-tocar">no se toca</span>
                                </div>
                                <div class="cfg-arbol-hijo">
                                    Lo que está en tránsito: adjuntos de la bandeja sin importar,
                                    archivos subidos a mano y los respaldos de la base.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="cfg-row-ancha" style="display:flex;gap:6px;">
                        <input type="text" id="cfg-carpeta" class="form-control" style="flex:1;"
                               placeholder="Elige una carpeta con «Examinar»…"
                               value="<?= htmlspecialchars($carpetaRaiz) ?>">
                        <button type="button" class="btn btn-outline btn-sm" id="cfg-examinar" style="white-space:nowrap;">
                            <i class="fas fa-folder-open"></i> Examinar
                        </button>
                    </div>

                    <!-- Explorador integrado: respaldo de «Examinar» -->
                    <div class="cfg-explorador cfg-row-ancha" id="cfg-explorador" style="display:none;">
                        <div class="cfg-explorador-barra">
                            <button type="button" class="btn btn-outline btn-sm" id="exp-subir"
                                    title="Subir un nivel" style="padding:2px 8px;">
                                <i class="fas fa-arrow-up"></i>
                            </button>
                            <span id="exp-ruta" class="cfg-ruta" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Este equipo</span>
                        </div>
                        <div id="exp-lista" class="cfg-explorador-lista">
                            <div class="cfg-vacio">Cargando…</div>
                        </div>
                        <div class="cfg-explorador-barra" style="border-bottom:none;border-top:1px solid var(--border-light);">
                            <input type="text" id="exp-nueva" class="form-control"
                                   placeholder="Nueva carpeta…" style="font-size:11.5px;padding:4px 8px;flex:1;">
                            <button type="button" class="btn btn-outline btn-sm" id="exp-crear"
                                    title="Crear carpeta aquí" style="padding:2px 8px;">
                                <i class="fas fa-folder-plus"></i>
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="exp-usar" title="Usar la carpeta abierta">
                                <i class="fas fa-check"></i> Usar esta
                            </button>
                        </div>
                    </div>

                    <div class="cfg-row-ancha cfg-msg" id="cfg-msg" style="display:none;"></div>
                </div>

                <div class="cfg-foot">
                    <div class="cfg-foot-nota">
                        <?php if ($carpetaRaiz === ''): ?>
                        <i class="fas fa-triangle-exclamation" style="color:var(--warn);margin-right:4px;"></i>
                        Sin carpeta raíz no se pueden archivar documentos ni ordenar el archivo.
                        <?php else: ?>
                        <i class="fas fa-circle-check" style="color:var(--ok);margin-right:4px;"></i>
                        Guardando en <span class="cfg-ruta"><?= htmlspecialchars($carpetaRaiz) ?></span>
                        · el archivo se acomoda solo: cada documento a su mes dentro de
                        <code>SISTEMA</code> y una <strong>copia</strong> a la carpeta del pago
                        semanal. Nunca se borra nada.
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="cfg-guardar">
                        <i class="fas fa-check"></i> Guardar carpeta
                    </button>
                </div>
            </div>

        </section>

        <!-- ── Cuentas de correo ───────────────────────────── -->
        <section class="cfg-section" id="correo" data-cfg-seccion="correo"
                 data-cfg-buscar="correo cuentas buzon buzón imap host puerto contraseña usuario indice índice retencion retención sociedades">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono gold"><i class="fas fa-at"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Cuentas de correo</h2>
                        <p>
                            Buzones IMAP de los que se bajan facturas.
                            Aquí se ven <strong>todos</strong>, también los de otras empresas: es el único
                            sitio donde se corrige una asignación equivocada.
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" id="cta-nueva">
                        <i class="fas fa-plus"></i> Agregar cuenta
                    </button>
                </div>

                <?php if (!$imapDisponible): ?>
                <div class="cfg-row apilada">
                    <div class="cfg-msg warn">
                        <i class="fas fa-triangle-exclamation"></i>
                        La extensión <code>imap</code> de PHP no está activa en este servidor: las cuentas se
                        pueden registrar, pero no se podrá leer ningún buzón hasta activarla.
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($cuentas)): ?>
                <div class="cfg-vacio">
                    <i class="fas fa-inbox"></i>
                    Todavía no hay ninguna cuenta registrada.
                </div>
                <?php else: ?>
                <?php foreach ($cuentas as $c): ?>
                <div class="cfg-item">
                    <i class="fas <?= $c['id'] === $cuentaActivaId ? 'fa-circle-check' : 'fa-envelope' ?>"
                       style="color:<?= $c['id'] === $cuentaActivaId ? 'var(--ok)' : 'var(--text-light)' ?>;width:15px;"
                       title="<?= $c['id'] === $cuentaActivaId ? 'Cuenta en uso' : 'Cuenta registrada' ?>"></i>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo">
                            <?= htmlspecialchars($c['nombre']) ?>
                            <?php if ($c['id'] === $cuentaActivaId): ?>
                            <span class="badge badge-green" style="margin-left:5px;">En uso</span>
                            <?php endif; ?>
                        </div>
                        <div class="cfg-item-sub">
                            <?= htmlspecialchars($c['usuario']) ?> · <?= htmlspecialchars($c['host']) ?>:<?= (int) $c['puerto'] ?>
                        </div>
                        <?php // A qué empresas atiende. Es lo que explica que un buzón
                              // esté aquí y no aparezca dentro del módulo de correo. ?>
                        <div class="cfg-item-sub" style="color:<?= $c['atiende_actual'] ? 'var(--ok)' : 'var(--warn)' ?>;">
                            <i class="fas <?= $c['atiende_actual'] ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                            <?php if (!empty($c['sociedades_nombres'])): ?>
                            <?= htmlspecialchars(implode(' · ', $c['sociedades_nombres'])) ?>
                            <?php else: ?>
                            Sin empresa asignada — no aparece en ningún módulo
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cfg-item-acciones">
                        <button type="button" class="btn btn-outline btn-sm cta-editar" title="Editar"
                                data-id="<?= $c['id'] ?>"
                                data-nombre="<?= htmlspecialchars($c['nombre']) ?>"
                                data-host="<?= htmlspecialchars($c['host']) ?>"
                                data-puerto="<?= (int) $c['puerto'] ?>"
                                data-usuario="<?= htmlspecialchars($c['usuario']) ?>"
                                data-carpeta="<?= htmlspecialchars($c['carpeta']) ?>"
                                data-indice-retencion-dias="<?= (int) $c['indice_retencion_dias'] ?>"
                                data-sociedades="<?= htmlspecialchars(implode(',', $c['sociedades'])) ?>">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button type="button" class="btn btn-outline btn-sm cta-eliminar" title="Eliminar"
                                style="color:#b91c1c;border-color:#F5B5B5;"
                                data-id="<?= $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre']) ?>">
                            <i class="fas fa-trash-can"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Formulario de cuenta (crear / editar) -->
                <div id="cta-form" style="display:none;border-top:1px solid var(--border-light);padding:12px 14px;background:#F7FAFF;">
                    <input type="hidden" id="cta-id" value="0">
                    <div style="font-size:12.5px;font-weight:800;color:var(--navy);margin-bottom:9px;" id="cta-form-titulo">
                        Nueva cuenta de correo
                    </div>
                    <div class="cfg-campos">
                        <div class="cfg-campo-ancho">
                            <label for="cta-nombre">Nombre de la cuenta</label>
                            <input type="text" id="cta-nombre" class="form-control" placeholder="Facturas MG">
                        </div>
                        <div>
                            <label for="cta-host">Host IMAP</label>
                            <input type="text" id="cta-host" class="form-control" placeholder="mail.tuempresa.com">
                        </div>
                        <div>
                            <label for="cta-puerto">Puerto</label>
                            <input type="number" id="cta-puerto" class="form-control" value="993">
                        </div>
                        <div>
                            <label for="cta-usuario">Usuario (correo)</label>
                            <input type="text" id="cta-usuario" class="form-control" placeholder="facturas@tuempresa.com">
                        </div>
                        <div>
                            <label for="cta-password">Contraseña</label>
                            <input type="password" id="cta-password" class="form-control" autocomplete="new-password">
                        </div>
                        <div>
                            <label for="cta-carpeta">Carpeta inicial</label>
                            <input type="text" id="cta-carpeta" class="form-control" value="INBOX">
                        </div>
                        <div>
                            <label for="cta-indice-retencion">Retención del índice (días)</label>
                            <input type="number" id="cta-indice-retencion" class="form-control"
                                   min="0" max="3650" value="1825">
                            <div class="cfg-row-ayuda">
                                1825 = 5 años. 0 conserva todos los encabezados.
                                No borra correos ni documentos.
                            </div>
                        </div>
                        <div class="cfg-campo-ancho">
                            <label>Empresas que usan este buzón</label>
                            <div id="cta-sociedades" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px;">
                                <?php if (empty($sociedades)): ?>
                                <span class="cfg-row-ayuda">
                                    No hay empresas registradas. Regístralas en
                                    <a href="#empresas" style="color:var(--navy-light);">Empresas</a>.
                                </span>
                                <?php else: ?>
                                <?php foreach ($sociedades as $s): ?>
                                <label class="cfg-check">
                                    <input type="checkbox" class="cta-soc" value="<?= (int) $s['id'] ?>">
                                    <?= htmlspecialchars($s['nombre']) ?>
                                </label>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="cfg-row-ayuda">
                                Un mismo buzón puede recibir facturas de varias empresas del grupo.
                                Solo aparece en las que marques.
                            </div>
                        </div>
                    </div>
                    <div class="cfg-msg" id="cta-msg" style="display:none;margin-top:9px;"></div>
                    <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:10px;">
                        <button type="button" class="btn btn-outline btn-sm" id="cta-cancelar">Cancelar</button>
                        <button type="button" class="btn btn-outline btn-sm" id="cta-probar">
                            <i class="fas fa-plug"></i> Probar conexión
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="cta-guardar">
                            <i class="fas fa-check"></i> Guardar cuenta
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Automatización ──────────────────────────────── -->
        <section class="cfg-section" id="automatizacion" data-cfg-seccion="automatizacion"
                 data-cfg-buscar="automatizacion automatización tarea programada windows sincronizar indice índice captura segundo plano intervalo">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-rotate"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Automatización de correo</h2>
                        <p>
                            Una tarea programada de Windows mantiene el índice del buzón al día
                            <strong>aunque cierres esta página</strong>. Requiere que el equipo y MariaDB
                            estén encendidos.
                        </p>
                    </div>
                </div>

                <div class="cfg-row apilada">
                    <div id="auto-estado" style="font-size:12px;color:var(--text-muted);">Consultando…</div>

                    <div id="auto-controles" style="display:none;gap:12px;flex-wrap:wrap;align-items:center;margin-top:4px;">
                        <label class="cfg-check">Revisar cada
                            <input id="auto-intervalo" type="number" min="1" max="1440" value="10"
                                   class="form-control" style="width:66px;font-size:12px;padding:3px 6px;">
                            min
                        </label>
                        <label class="cfg-check">
                            <input id="auto-capturar" type="checkbox"> Capturar correos nuevos
                        </label>
                        <label class="cfg-check">Máximo por corrida
                            <input id="auto-max-correos" type="number" min="1" max="200" value="20"
                                   class="form-control" style="width:62px;font-size:12px;padding:3px 6px;">
                        </label>
                        <label class="cfg-check">Intentos
                            <input id="auto-max-intentos" type="number" min="1" max="10" value="3"
                                   class="form-control" style="width:54px;font-size:12px;padding:3px 6px;">
                        </label>
                    </div>

                    <div class="cfg-row-ayuda">
                        La captura descarga comprobantes nuevos a la <strong>Bandeja de revisión</strong>.
                        No los importa ni los guarda en carpetas finales: una persona debe seleccionarlos
                        y confirmar la importación.
                    </div>

                    <div class="cfg-msg" id="auto-msg" style="display:none;"></div>
                </div>

                <div class="cfg-foot">
                    <div class="cfg-foot-nota">Solo disponible en el servidor Windows.</div>
                    <button type="button" class="btn btn-outline btn-sm" id="auto-desactivar"
                            style="display:none;color:#b91c1c;border-color:#F5B5B5;">
                        <i class="fas fa-stop"></i> Desactivar
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="auto-activar">
                        <i class="fas fa-play"></i> Activar
                    </button>
                </div>
            </div>
        </section>

        <!-- ── Empresas ────────────────────────────────────── -->
        <section class="cfg-section" id="empresas" data-cfg-seccion="empresas"
                 data-cfg-buscar="empresas sociedades cedula cédula receptor verificacion verificación grupo activa">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono gold"><i class="fas fa-building"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Empresas del grupo</h2>
                        <p>
                            Con la empresa marcada es con la que trabajas: su cédula se compara contra
                            el receptor de cada factura, y las facturas a nombre de otra cédula quedan
                            bloqueadas en la bandeja.
                        </p>
                    </div>
                </div>

                <div class="cfg-row apilada" style="padding-bottom:6px;">
                    <?php if ($sociedadActiva): ?>
                    <div class="cfg-msg ok">
                        <i class="fas fa-circle-check"></i>
                        Verificando contra <strong><?= htmlspecialchars($sociedadActiva['nombre']) ?></strong>
                        (cédula <strong><?= htmlspecialchars($sociedadActiva['cedula']) ?></strong>).
                    </div>
                    <?php else: ?>
                    <div class="cfg-msg warn">
                        <i class="fas fa-triangle-exclamation"></i>
                        Sin empresa elegida no se verifica el receptor de las facturas.
                        Registra una abajo y márcala.
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($sociedades)): ?>
                <div class="cfg-vacio">
                    <i class="fas fa-building"></i>
                    Todavía no hay ninguna empresa registrada.
                </div>
                <?php else: ?>
                <?php foreach ($sociedades as $soc):
                    $enUso = (int) $soc['id'] === $sociedadEnUsoId; ?>
                <div class="cfg-item">
                    <?php if ($enUso): ?>
                    <i class="fas fa-circle-check" style="color:var(--ok);width:15px;" title="Trabajando con esta empresa"></i>
                    <?php else: ?>
                    <form method="POST" action="<?= $baseUrl ?>/sociedades/activar/<?= (int) $soc['id'] ?>" style="display:flex;">
                        <input type="hidden" name="volver" value="empresas">
                        <button type="submit" title="Trabajar con esta empresa"
                                style="background:none;border:none;cursor:pointer;color:var(--text-light);font-size:14px;padding:0;width:15px;">
                            <i class="far fa-circle"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo">
                            <?= htmlspecialchars($soc['nombre']) ?>
                            <?php if ($enUso): ?>
                            <span class="badge badge-green" style="margin-left:5px;">En uso</span>
                            <?php endif; ?>
                            <?php if (!empty($soc['activa'])): ?>
                            <span class="badge badge-default" style="margin-left:4px;"
                                  title="Con esta empresa arranca quien nunca ha elegido, y con ella trabajan las tareas automáticas">
                                por omisión
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="cfg-item-sub">Cédula <?= htmlspecialchars($soc['cedula']) ?></div>
                    </div>
                    <div class="cfg-item-acciones">
                        <button type="button" class="btn btn-outline btn-sm soc-editar" title="Editar"
                                data-id="<?= (int) $soc['id'] ?>"
                                data-nombre="<?= htmlspecialchars($soc['nombre']) ?>"
                                data-cedula="<?= htmlspecialchars($soc['cedula']) ?>">
                            <i class="fas fa-pen"></i>
                        </button>
                        <form method="POST" action="<?= $baseUrl ?>/sociedades/eliminar/<?= (int) $soc['id'] ?>"
                              data-confirm="¿Quieres eliminar la empresa <?= htmlspecialchars($soc['nombre'], ENT_QUOTES) ?>?"
                              data-confirm-title="Eliminar empresa"
                              data-confirm-type="danger"
                              data-confirm-accept="Eliminar">
                            <input type="hidden" name="volver" value="empresas">
                            <button type="submit" class="btn btn-outline btn-sm" title="Eliminar"
                                    style="color:#b91c1c;border-color:#F5B5B5;">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Agregar / editar: el mismo formulario cambia de destino al editar -->
                <form method="POST" action="<?= $baseUrl ?>/sociedades/crear" id="form-sociedad"
                      style="border-top:1px solid var(--border-light);padding:12px 14px;background:#F7FAFF;">
                    <input type="hidden" name="volver" value="empresas">
                    <div style="font-size:12.5px;font-weight:800;color:var(--navy);margin-bottom:9px;" id="soc-form-titulo">
                        Nueva empresa
                    </div>
                    <div class="cfg-campos">
                        <div>
                            <label for="soc-nombre">Nombre (informativo)</label>
                            <input type="text" name="nombre" id="soc-nombre" class="form-control" required
                                   placeholder="EMPRESA EJEMPLO S.A.">
                        </div>
                        <div>
                            <label for="soc-cedula">Cédula</label>
                            <input type="text" name="cedula" id="soc-cedula" class="form-control" required
                                   placeholder="3101123456">
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:10px;">
                        <button type="button" class="btn btn-outline btn-sm" id="soc-cancelar" style="display:none;">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm" id="soc-submit">
                            <i class="fas fa-plus"></i> Agregar empresa
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($esAdmin): ?>
        <!-- ── Usuarios ────────────────────────────────────── -->
        <section class="cfg-section" id="usuarios" data-cfg-seccion="usuarios"
                 data-cfg-buscar="usuarios acceso contraseña administrador permisos cuenta login">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-users-cog"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Usuarios</h2>
                        <p><?= count($usuarios) ?> usuario<?= count($usuarios) !== 1 ? 's' : '' ?> con acceso al sistema.</p>
                    </div>
                    <a href="<?= $baseUrl ?>/usuarios/crear" class="btn btn-outline btn-sm">
                        <i class="fas fa-user-plus"></i> Nuevo usuario
                    </a>
                </div>

                <?php if (empty($usuarios)): ?>
                <div class="cfg-vacio">
                    <i class="fas fa-users"></i>
                    No hay usuarios registrados.
                </div>
                <?php else: ?>
                <?php foreach ($usuarios as $u): ?>
                <div class="cfg-item">
                    <i class="fas fa-user-circle"
                       style="color:<?= (int) $u['activo'] ? 'var(--navy-light)' : 'var(--text-light)' ?>;font-size:16px;width:16px;"></i>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo">
                            <?= htmlspecialchars($u['nombre']) ?>
                            <?php if ((int) $u['id'] === $selfId): ?>
                            <span class="badge badge-green" style="margin-left:5px;">Tú</span>
                            <?php endif; ?>
                            <?php if ((int) $u['is_admin']): ?>
                            <span class="badge badge-navy" style="margin-left:4px;">
                                <i class="fas fa-shield-halved"></i> Admin
                            </span>
                            <?php endif; ?>
                            <?php if (!(int) $u['activo']): ?>
                            <span class="badge badge-miss" style="margin-left:4px;">Inactivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="cfg-item-sub">
                            <?= htmlspecialchars($u['username']) ?> · <?= htmlspecialchars($u['email']) ?>
                            · último acceso
                            <?= $u['ultimo_acceso'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($u['ultimo_acceso']))) : 'nunca' ?>
                        </div>
                    </div>
                    <div class="cfg-item-acciones">
                        <a href="<?= $baseUrl ?>/usuarios/editar/<?= (int) $u['id'] ?>"
                           class="btn btn-outline btn-sm" title="Editar">
                            <i class="fas fa-pen"></i>
                        </a>
                        <?php if ((int) $u['id'] !== $selfId): ?>
                        <form method="POST" action="<?= $baseUrl ?>/usuarios/eliminar/<?= (int) $u['id'] ?>"
                              data-confirm="¿Quieres eliminar a <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>? Esta acción no se puede deshacer."
                              data-confirm-title="Eliminar usuario"
                              data-confirm-type="danger"
                              data-confirm-accept="Eliminar">
                            <input type="hidden" name="volver" value="usuarios">
                            <button type="submit" class="btn btn-outline btn-sm" title="Eliminar"
                                    style="color:#b91c1c;border-color:#F5B5B5;">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Respaldo de la base ─────────────────────────── -->
        <?php
        $estadoIni = $respaldo['estado'] ?? null;
        $corriendo = is_array($estadoIni) && ($estadoIni['estado'] ?? '') === 'corriendo';
        $autoIni   = $respaldo['automatico'] ?? ['activo' => false, 'hora' => ''];
        $horaIni   = ($autoIni['hora'] ?? '') !== '' ? $autoIni['hora'] : '22:00';
        ?>
        <section class="cfg-section" id="respaldo" data-cfg-seccion="respaldo"
                 data-cfg-buscar="respaldo copia base datos sharepoint mysqldump nocturno restaurar">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-database"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Respaldo de la base de datos</h2>
                        <p>
                            Deja una copia en la carpeta compartida, donde SharePoint la sincroniza al resto
                            de las computadoras. <strong>Hay que apretarlo en la computadora que sí alcanza
                            la base</strong> — desde otra saldría por la red, que es justo lo que falla
                            cuando este respaldo hace falta.
                        </p>
                    </div>
                </div>

                <?php if (($respaldo['carpetaError'] ?? '') !== ''): ?>
                <div class="cfg-row apilada">
                    <div class="cfg-msg error"><?= htmlspecialchars((string) ($respaldo['carpetaError'] ?? '')) ?></div>
                </div>
                <?php elseif (($respaldo['mysqldump'] ?? null) === null): ?>
                <div class="cfg-row apilada">
                    <div class="cfg-msg error">
                        No se encontró <code>mysqldump.exe</code> en esta computadora (se buscó en la
                        instalación de MariaDB y en el PATH). Sin él no se puede respaldar.
                    </div>
                </div>
                <?php endif; ?>

                <div class="cfg-row">
                    <div>
                        <div class="cfg-row-label">Respaldo automático</div>
                        <div class="cfg-row-ayuda">
                            Un respaldo que depende de que alguien se acuerde no es un respaldo.
                            Se conservan los últimos <?= (int) ($respaldo['conserva'] ?? 5) ?>;
                            los más viejos se borran solos para no llenarle el SharePoint a nadie.
                        </div>
                    </div>
                    <div class="cfg-row-control">
                        <span style="font-size:12px;color:var(--navy);font-weight:600;">Todas las noches a las</span>
                        <input type="time" id="respaldo-hora" class="form-control"
                               value="<?= htmlspecialchars($horaIni, ENT_QUOTES, 'UTF-8') ?>" style="width:auto;">
                        <button type="button" class="btn btn-outline btn-sm" id="respaldo-auto-activar">
                            <?= !empty($autoIni['activo']) ? 'Cambiar la hora' : 'Activar' ?>
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" id="respaldo-auto-desactivar"
                                style="color:#b91c1c;border-color:#F5B5B5;<?= empty($autoIni['activo']) ? 'display:none;' : '' ?>">
                            Desactivar
                        </button>
                    </div>
                    <div class="cfg-row-ancha" id="respaldo-auto-msg"
                         style="font-size:11.5px;color:<?= !empty($autoIni['activo']) ? 'var(--ok)' : 'var(--text-muted)' ?>;">
                        <?= !empty($autoIni['activo'])
                            ? 'Activo' . (($autoIni['hora'] ?? '') !== '' ? ' · próxima a las ' . htmlspecialchars($autoIni['hora'], ENT_QUOTES, 'UTF-8') : '')
                            : 'Desactivado: solo se respalda cuando alguien aprieta el botón.' ?>
                    </div>
                </div>

                <div class="cfg-row apilada">
                    <div>
                        <div class="cfg-row-label">Respaldos en la carpeta compartida</div>
                        <div class="cfg-row-ayuda">
                            <span class="cfg-ruta"><?= htmlspecialchars((string) ($respaldo['carpeta'] ?? '')) ?></span>
                        </div>
                    </div>
                    <div class="cfg-row-ancha" id="respaldo-lista"></div>
                    <div class="cfg-row-ayuda">
                        Lo mismo desde la consola: <code>php cli/respaldar_base.php</code>.
                        Para levantarlo en la otra computadora, cuando el archivo ya sincronizó:
                        <code>.\scripts\copiar-base.ps1 -Desde ultimo</code>
                    </div>
                </div>

                <div class="cfg-foot">
                    <div class="cfg-foot-nota" id="respaldo-msg"></div>
                    <button type="button" class="btn btn-primary btn-sm" id="respaldo-generar"
                            <?= $corriendo ? 'disabled' : '' ?>>
                        <i class="fas fa-download"></i> Generar respaldo ahora
                    </button>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Esta computadora ────────────────────────────── -->
        <section class="cfg-section" id="sistema" data-cfg-seccion="sistema"
                 data-cfg-buscar="sistema computadora instalacion instalación diagnostico diagnóstico php imap version versión equipo">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-desktop"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Esta computadora</h2>
                        <p>La aplicación corre en cada equipo: lo que le falte a este se revisa aparte.</p>
                    </div>
                </div>

                <div class="cfg-row">
                    <div>
                        <div class="cfg-row-label">Diagnóstico de la instalación</div>
                        <div class="cfg-row-ayuda">
                            Qué le falta a <em>esta</em> instalación: extensiones de PHP, permisos de
                            carpetas, conexión a la base. Si vas a pedir ayuda, manda una captura de ahí.
                        </div>
                    </div>
                    <div class="cfg-row-control">
                        <a href="<?= $baseUrl ?>/diagnostico" class="btn btn-outline btn-sm">
                            <i class="fas fa-stethoscope"></i> Abrir diagnóstico
                        </a>
                    </div>
                </div>

                <div class="cfg-row">
                    <div>
                        <div class="cfg-row-label">Extensión IMAP de PHP</div>
                        <div class="cfg-row-ayuda">Sin ella no se puede leer ningún buzón.</div>
                    </div>
                    <div class="cfg-row-control">
                        <?php if ($imapDisponible): ?>
                        <span class="badge badge-ok"><i class="fas fa-circle-check"></i> Activa</span>
                        <?php else: ?>
                        <span class="badge badge-miss"><i class="fas fa-circle-xmark"></i> No disponible</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>

<script>
(function () {
    var BASE = <?= json_encode($baseUrl) ?>;
    var SOCIEDAD_ACTIVA_ID = <?= $sociedadEnUsoId ?>;
    var RESPALDO_INICIAL = <?= $esAdmin ? json_encode([
        'estado'   => $estadoIni ?? null,
        'archivos' => $respaldo['archivos'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) : 'null' ?>;

    function postJson(url, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
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

    /**
     * Pinta un .cfg-msg con uno de los tonos del sistema. Se cambian solo las
     * clases de tono: el elemento puede traer además las de su sitio en la
     * rejilla, y reescribir className entero lo sacaría de su columna.
     */
    function decir(el, texto, tono) {
        if (!el) return;
        el.textContent = texto;
        el.classList.remove('ok', 'warn', 'error');
        if (tono) el.classList.add(tono);
        el.style.display = 'block';
    }

    function callar(el) { if (el) el.style.display = 'none'; }

    // ══ Índice: sección visible, salto por ancla y buscador ══════════
    (function () {
        var enlaces  = Array.prototype.slice.call(document.querySelectorAll('[data-cfg-nav]'));
        var secciones = Array.prototype.slice.call(document.querySelectorAll('[data-cfg-seccion]'));
        var buscar   = document.getElementById('cfg-buscar');
        var navVacio = document.getElementById('cfg-nav-vacio');
        if (!enlaces.length) return;

        function marcar(id) {
            enlaces.forEach(function (a) {
                a.classList.toggle('active', a.dataset.cfgNav === id);
            });
        }

        /**
         * Queda marcada la sección que encabeza lo que se está leyendo: la
         * última cuya cabecera ya pasó por debajo de la barra superior.
         *
         * Salvo al final del documento, donde eso deja de valer: las últimas
         * secciones ya no pueden subir hasta arriba por más que se baje, y la
         * regla marcaba una de la que apenas asoma el pie. Ahí manda la que
         * ocupa más pantalla, que es la que se está mirando.
         */
        function actualizarMarca() {
            var alto = window.innerHeight;
            var visibles = secciones.filter(function (s) { return s.style.display !== 'none'; });
            if (!visibles.length) return;

            var alFinal = (window.scrollY + alto) >= (document.documentElement.scrollHeight - 4);
            var elegida = null;

            if (alFinal) {
                var mejorArea = -1;
                visibles.forEach(function (s) {
                    var r = s.getBoundingClientRect();
                    // Lo que tapa la barra superior no cuenta como visible.
                    var area = Math.min(r.bottom, alto) - Math.max(r.top, 60);
                    if (area > mejorArea) { mejorArea = area; elegida = s; }
                });
            } else {
                visibles.forEach(function (s) {
                    if (s.getBoundingClientRect().top <= 120) elegida = s;
                });
            }

            marcar((elegida || visibles[0]).id);
        }

        var marcaPendiente = false;
        function pedirMarca() {
            if (marcaPendiente) return;
            marcaPendiente = true;
            requestAnimationFrame(function () {
                marcaPendiente = false;
                actualizarMarca();
            });
        }

        window.addEventListener('scroll', pedirMarca, { passive: true });
        window.addEventListener('resize', pedirMarca);

        enlaces.forEach(function (a) {
            a.addEventListener('click', function () { marcar(a.dataset.cfgNav); });
        });

        // Llegadas desde otra pantalla: /configuracion?ir=usuarios o #usuarios
        var destino = <?= json_encode($seccionInicial) ?> || (window.location.hash || '').replace('#', '');
        var seccionDestino = destino ? document.getElementById(destino) : null;

        if (seccionDestino) {
            marcar(destino);

            // Quien ya empezó a moverse mandó: no se le arrastra la página
            // debajo de las manos por un reanclaje tardío.
            var usuarioMovio = false;
            ['wheel', 'touchmove', 'keydown'].forEach(function (ev) {
                window.addEventListener(ev, function () { usuarioMovio = true; }, { once: true, passive: true });
            });

            function anclar() {
                if (usuarioMovio) return;
                seccionDestino.scrollIntoView({ block: 'start' });
                marcar(destino);
            }

            requestAnimationFrame(anclar);

            // Y otra vez cada vez que la página crezca: el estado de la
            // automatización y la lista de respaldos llegan del servidor
            // después —consultar la tarea de Windows tarda— y empujan todo
            // hacia abajo. Con un solo salto, el enlace a una sección de abajo
            // caía siempre corto. Se deja de vigilar a los ocho segundos: para
            // entonces ya llegó todo, y nadie quiere una página que se reacomoda
            // sola más tarde.
            if ('ResizeObserver' in window) {
                var lienzo = document.querySelector('.cfg-main');
                var vigia = new ResizeObserver(function () { anclar(); });
                vigia.observe(lienzo);
                setTimeout(function () { vigia.disconnect(); }, 8000);
                ['wheel', 'touchmove', 'keydown'].forEach(function (ev) {
                    window.addEventListener(ev, function () { vigia.disconnect(); }, { once: true, passive: true });
                });
            }
        } else {
            actualizarMarca();
        }

        // El buscador filtra secciones enteras, no controles sueltos: quien
        // escribe "cédula" quiere llegar a Empresas, no ver un campo aislado
        // fuera del texto que lo explica.
        if (buscar) {
            buscar.addEventListener('input', function () {
                var q = buscar.value.trim().toLowerCase();
                var encontradas = 0;

                secciones.forEach(function (s) {
                    var heno = (s.dataset.cfgBuscar || '') + ' ' + s.textContent.toLowerCase();
                    var coincide = q === '' || heno.toLowerCase().indexOf(q) >= 0;
                    s.style.display = coincide ? '' : 'none';
                    if (coincide) encontradas++;
                });

                enlaces.forEach(function (a) {
                    var s = document.getElementById(a.dataset.cfgNav);
                    a.style.display = (s && s.style.display === 'none') ? 'none' : '';
                });

                navVacio.style.display = encontradas === 0 ? 'block' : 'none';
            });
        }
    })();

    // ══ Carpeta raíz de XML y PDF ═══════════════════════════════════
    var cfgCarpeta = document.getElementById('cfg-carpeta');
    var cfgMsg     = document.getElementById('cfg-msg');
    var cfgGuardar = document.getElementById('cfg-guardar');

    cfgGuardar.addEventListener('click', function () {
        cfgGuardar.disabled = true;
        postJson(BASE + '/correo/config', { carpeta_destino: cfgCarpeta.value.trim() })
            .then(function () {
                decir(cfgMsg, 'Carpeta guardada. Vuelve a cargar la página para verla reflejada abajo.', 'ok');
            })
            .catch(function (err) { decir(cfgMsg, err.message, 'error'); })
            .then(function () { cfgGuardar.disabled = false; });
    });

    // ── Explorador de carpetas (respaldo de «Examinar») ──
    var cfgExaminar = document.getElementById('cfg-examinar');
    var cfgExplor   = document.getElementById('cfg-explorador');
    var expSubir    = document.getElementById('exp-subir');
    var expRutaEl   = document.getElementById('exp-ruta');
    var expLista    = document.getElementById('exp-lista');
    var expNueva    = document.getElementById('exp-nueva');
    var expCrear    = document.getElementById('exp-crear');
    var expUsar     = document.getElementById('exp-usar');

    var expEstado = { ruta: '', esRaiz: true, padre: null, cargando: false };

    function expInfo(texto, esError) {
        expLista.innerHTML = '';
        var d = document.createElement('div');
        d.className = 'cfg-vacio';
        if (esError) d.style.color = 'var(--miss)';
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
                expEstado.ruta   = r.ruta || '';
                expEstado.esRaiz = !!r.es_raiz;
                expEstado.padre  = (r.padre === undefined ? null : r.padre);

                expRutaEl.textContent = r.es_raiz ? 'Este equipo' : (r.ruta || '');
                expRutaEl.title       = expRutaEl.textContent;
                expSubir.disabled = r.es_raiz;
                expUsar.disabled  = r.es_raiz;   // no se "usa" la lista de unidades
                expNueva.disabled = r.es_raiz;
                expCrear.disabled = r.es_raiz;
                expNueva.value = '';

                var carpetas = r.carpetas || [];
                expLista.innerHTML = '';

                if (!r.es_raiz && r.escribible === false) {
                    var aviso = document.createElement('div');
                    aviso.className = 'cfg-msg warn';
                    aviso.style.borderRadius = '0';
                    aviso.textContent = 'No se puede escribir en esta carpeta; elige o crea otra.';
                    expLista.appendChild(aviso);
                }

                if (!carpetas.length) {
                    var vac = document.createElement('div');
                    vac.className = 'cfg-vacio';
                    vac.textContent = r.es_raiz
                        ? 'No se detectaron unidades.'
                        : '(Sin subcarpetas — puedes usar esta o crear una)';
                    expLista.appendChild(vac);
                }

                carpetas.forEach(function (c) {
                    var fila = document.createElement('div');
                    fila.className = 'cfg-explorador-fila';

                    var ic = document.createElement('i');
                    ic.className = r.es_raiz ? 'fas fa-hard-drive' : 'fas fa-folder';
                    ic.style.color = r.es_raiz ? 'var(--navy-light)' : 'var(--gold)';
                    ic.style.width = '14px';

                    var nom = document.createElement('span');
                    nom.textContent = c.nombre;
                    nom.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';

                    var flecha = document.createElement('i');
                    flecha.className = 'fas fa-chevron-right';
                    flecha.style.cssText = 'color:var(--border);font-size:10px;';

                    fila.appendChild(ic);
                    fila.appendChild(nom);
                    fila.appendChild(flecha);
                    fila.addEventListener('click', function () { cargarExplorador(c.ruta); });
                    expLista.appendChild(fila);
                });
            })
            .catch(function (err) { expInfo(err.message, true); })
            .then(function () { expEstado.cargando = false; });
    }

    // «Examinar» abre el explorador NATIVO de Windows: el servidor lo lanza en
    // el escritorio del usuario y aquí se consulta cada segundo si ya eligió.
    // Si el diálogo no se puede abrir, se cae al explorador integrado.
    var selectorNativo = { activo: false, timer: null };

    function detenerSelector() {
        selectorNativo.activo = false;
        if (selectorNativo.timer) { clearTimeout(selectorNativo.timer); selectorNativo.timer = null; }
        cfgExaminar.disabled = false;
        cfgExaminar.innerHTML = '<i class="fas fa-folder-open"></i> Examinar';
    }

    function explorarAqui(motivo) {
        decir(cfgMsg, 'No se pudo abrir el explorador de Windows (' + motivo + '). Elige la carpeta aquí:', 'warn');
        cfgExplor.style.display = 'block';
        cargarExplorador((cfgCarpeta.value || '').trim());
    }

    cfgExaminar.addEventListener('click', function () {
        if (selectorNativo.activo) return;
        selectorNativo.activo = true;
        cfgExaminar.disabled = true;
        cfgExaminar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Abriendo…';
        callar(cfgMsg);
        cfgExplor.style.display = 'none';

        postJson(BASE + '/correo/selector/abrir', {})
            .then(function (r) {
                decir(cfgMsg, 'Elige la carpeta en la ventana del explorador de Windows '
                    + '(puede tardar unos segundos en aparecer)…', null);

                var intentos = 0;
                function consultar() {
                    if (!selectorNativo.activo) return;
                    if (++intentos > 300) {   // ~5 minutos
                        detenerSelector();
                        decir(cfgMsg, 'Se agotó el tiempo de espera del explorador de Windows.', 'error');
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
                                decir(cfgMsg, 'Carpeta elegida: ' + e.ruta
                                    + ' — pulsa «Guardar carpeta» para aplicarla.', 'ok');
                            } else {
                                callar(cfgMsg);   // canceló el diálogo
                            }
                        })
                        .catch(function (err) {
                            // El lanzador falló en segundo plano: mismo
                            // respaldo que si /selector/abrir fallara.
                            detenerSelector();
                            explorarAqui(err.message);
                        });
                }

                selectorNativo.timer = setTimeout(consultar, 900);
            })
            .catch(function (err) {
                detenerSelector();
                explorarAqui(err.message);
            });
    });

    expSubir.addEventListener('click', function () {
        if (!expEstado.esRaiz) cargarExplorador(expEstado.padre || '');
    });

    expUsar.addEventListener('click', function () {
        if (expEstado.esRaiz || !expEstado.ruta) return;
        cfgCarpeta.value = expEstado.ruta;
        cfgExplor.style.display = 'none';
        decir(cfgMsg, 'Carpeta elegida: ' + expEstado.ruta + ' — pulsa «Guardar carpeta» para aplicarla.', 'ok');
    });

    expCrear.addEventListener('click', function () {
        var nombre = (expNueva.value || '').trim();
        if (!nombre) { expNueva.focus(); return; }
        cargarExplorador(expEstado.ruta, 'crear', nombre);
    });

    expNueva.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); expCrear.click(); }
    });

    // Aquí vivía «Ordenar el archivo»: un desplegable de alcance, un botón de
    // previsualización y otro de confirmación. Salió porque no había nada que
    // decidir. La carpeta de un documento la deciden su fecha de emisión y su
    // tipo —que no cambian nunca— y su semana de pago; no hay dos respuestas
    // posibles que mereciesen una previsualización. Ahora lo acomoda la tarea
    // programada, como mucho cada quince minutos, en cli/sync_correo.php.

    // ══ Automatización de correo (Tarea Programada de Windows) ══════
    var autoEstado      = document.getElementById('auto-estado');
    var autoControles   = document.getElementById('auto-controles');
    var autoIntervalo   = document.getElementById('auto-intervalo');
    var autoCapturar    = document.getElementById('auto-capturar');
    var autoMaxCorreos  = document.getElementById('auto-max-correos');
    var autoMaxIntentos = document.getElementById('auto-max-intentos');
    var autoActivar     = document.getElementById('auto-activar');
    var autoDesactivar  = document.getElementById('auto-desactivar');
    var autoMsg         = document.getElementById('auto-msg');

    function autoFmtUltima(u) {
        if (!u || !u.ultima_corrida) return '';
        var extra = '';
        if (u.pendientes_total > 0) {
            extra = ' — faltan ' + Number(u.pendientes_total).toLocaleString('es-CR') + ' correos por indexar';
        } else if (u.todo_al_dia) {
            extra = ' — índice al día';
        }
        if (u.captura && u.captura.corrida) {
            var c = u.captura.corrida;
            extra += ' — captura: ' + Number(c.documentos || 0).toLocaleString('es-CR')
                + ' documento(s) nuevo(s), ' + Number(c.errores || 0).toLocaleString('es-CR') + ' error(es)';
        }
        return 'Última corrida automática: ' + u.ultima_corrida + extra + '.';
    }

    function autoPintar(info) {
        if (!info || !info.soportado) {
            autoEstado.innerHTML = '<span style="color:var(--warn);">'
                + 'Solo disponible en el servidor Windows (necesita PowerShell y exec()).</span>';
            autoControles.style.display = 'none';
            autoActivar.disabled = true;
            return;
        }

        autoControles.style.display = 'flex';
        if (info.intervalo_min) autoIntervalo.value = String(info.intervalo_min);
        autoCapturar.checked = !!info.capturar_nuevos;
        if (info.max_correos_corrida) autoMaxCorreos.value = String(info.max_correos_corrida);
        if (info.max_intentos) autoMaxIntentos.value = String(info.max_intentos);

        var activo = info.activo && info.tarea_instalada;
        autoActivar.innerHTML = activo
            ? '<i class="fas fa-rotate"></i> Volver a aplicar'
            : '<i class="fas fa-play"></i> Activar';
        autoDesactivar.style.display = activo ? 'inline-flex' : 'none';

        var linea;
        if (activo) {
            linea = '<strong style="color:var(--ok);">Activa</strong> — cada ' + info.intervalo_min + ' min; '
                + (info.capturar_nuevos
                    ? 'los correos nuevos pasan a revisión manual.'
                    : 'solo se actualiza el índice.');
        } else if (info.activo && !info.tarea_instalada) {
            linea = '<span style="color:var(--warn);">Configurada, pero la tarea de Windows no aparece. '
                + 'Pulsa «Activar» para recrearla.</span>';
        } else {
            linea = '<strong>Desactivada</strong> — el índice solo se actualiza con el módulo de correo abierto.';
        }

        var ultima = autoFmtUltima(info.ultima);
        var cola = '';
        if (info.cola_captura) {
            var q = info.cola_captura;
            cola = '<div style="margin-top:4px;color:var(--text-muted);font-size:11px;">'
                + 'Cola: ' + Number(q.pendiente || 0).toLocaleString('es-CR') + ' pendiente(s), '
                + Number(q.procesando || 0).toLocaleString('es-CR') + ' en proceso, '
                + Number(q.error || 0).toLocaleString('es-CR') + ' con error. '
                + 'Bandeja por revisar: ' + Number(info.revision_pendiente || 0).toLocaleString('es-CR') + '.</div>';
        }
        autoEstado.innerHTML = linea
            + (ultima ? '<div style="margin-top:4px;color:var(--text-muted);font-size:11px;">' + ultima + '</div>' : '')
            + cola;
    }

    function autoRefrescar() {
        autoEstado.textContent = 'Consultando…';
        callar(autoMsg);
        postJson(BASE + '/correo/auto/estado', {})
            .then(autoPintar)
            .catch(function (err) {
                autoEstado.textContent = 'No se pudo consultar el estado: ' + err.message;
            });
    }

    autoActivar.addEventListener('click', function () {
        autoActivar.disabled = true;
        decir(autoMsg, 'Registrando la tarea de Windows…', null);
        postJson(BASE + '/correo/auto/activar', {
            intervalo_min: autoIntervalo.value,
            capturar_nuevos: autoCapturar.checked ? 1 : 0,
            max_correos_corrida: autoMaxCorreos.value,
            max_intentos: autoMaxIntentos.value
        })
            .then(function (r) {
                decir(autoMsg, r.message || 'Activada.', 'ok');
                autoRefrescar();
            })
            .catch(function (err) { decir(autoMsg, err.message, 'error'); })
            .then(function () { autoActivar.disabled = false; });
    });

    autoDesactivar.addEventListener('click', function () {
        autoDesactivar.disabled = true;
        postJson(BASE + '/correo/auto/desactivar', {})
            .then(function (r) {
                decir(autoMsg, r.message || 'Desactivada.', 'ok');
                autoRefrescar();
            })
            .catch(function (err) { decir(autoMsg, err.message, 'error'); })
            .then(function () { autoDesactivar.disabled = false; });
    });

    autoRefrescar();

    // ══ Cuentas de correo ═══════════════════════════════════════════
    var ctaForm    = document.getElementById('cta-form');
    var ctaTitulo  = document.getElementById('cta-form-titulo');
    var ctaMsgEl   = document.getElementById('cta-msg');

    function ctaAbrirForm(datos) {
        datos = datos || {};
        document.getElementById('cta-id').value = datos.id || 0;
        document.getElementById('cta-nombre').value = datos.nombre || '';
        document.getElementById('cta-host').value = datos.host || '';
        document.getElementById('cta-puerto').value = datos.puerto || 993;
        document.getElementById('cta-usuario').value = datos.usuario || '';
        document.getElementById('cta-carpeta').value = datos.carpeta || 'INBOX';
        document.getElementById('cta-indice-retencion').value =
            datos.indiceRetencionDias || datos.indice_retencion_dias || 1825;

        var pass = document.getElementById('cta-password');
        pass.value = '';
        pass.placeholder = datos.id ? '(dejar vacío para no cambiarla)' : '';

        ctaTitulo.textContent = datos.id
            ? 'Editando «' + (datos.nombre || 'cuenta') + '»'
            : 'Nueva cuenta de correo';

        // Buzón existente: se marcan sus empresas. Buzón nuevo: la que esté en
        // uso, para que no nazca sin dueño y quede invisible.
        var asignadas = String(datos.sociedades || '').split(',').filter(Boolean);
        Array.prototype.forEach.call(document.querySelectorAll('.cta-soc'), function (chk) {
            chk.checked = datos.id
                ? asignadas.indexOf(chk.value) >= 0
                : String(chk.value) === String(SOCIEDAD_ACTIVA_ID);
        });

        callar(ctaMsgEl);
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
            indice_retencion_dias: document.getElementById('cta-indice-retencion').value,
            sociedades: sociedades.join(',')
        };
    }

    /**
     * Vuelve a esta misma sección tras un cambio que rehace la lista. Se quita
     * el ?ir= con el que se pudo haber llegado: manda a dónde volver ahora, no
     * de dónde se venía.
     */
    function recargarEn(seccion) {
        var destino = new URL(window.location.href);
        destino.searchParams.delete('ir');
        destino.hash = '#' + seccion;
        window.location.href = destino.toString();
        window.location.reload();
    }

    document.getElementById('cta-nueva').addEventListener('click', function () { ctaAbrirForm(); });
    document.getElementById('cta-cancelar').addEventListener('click', function () {
        ctaForm.style.display = 'none';
    });

    Array.prototype.forEach.call(document.querySelectorAll('.cta-editar'), function (btn) {
        btn.addEventListener('click', function () { ctaAbrirForm(btn.dataset); });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.cta-eliminar'), function (btn) {
        btn.addEventListener('click', async function () {
            if (!(await AppDialog.confirm(
                'La cuenta "' + btn.dataset.nombre + '" se eliminará y su índice local dejará de utilizarse.',
                { title: 'Eliminar cuenta de correo', type: 'danger', confirmText: 'Eliminar cuenta' }
            ))) return;
            postJson(BASE + '/correo/cuentas/eliminar', { id: btn.dataset.id })
                .then(function () { recargarEn('correo'); })
                .catch(function (err) { AppDialog.alert(err.message, { title: 'No se pudo eliminar', type: 'danger' }); });
        });
    });

    document.getElementById('cta-probar').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        decir(ctaMsgEl, 'Conectando…', null);
        postJson(BASE + '/correo/cuentas/probar', ctaDatosForm())
            .then(function (r) { decir(ctaMsgEl, r.message || 'Conexión exitosa.', 'ok'); })
            .catch(function (err) { decir(ctaMsgEl, err.message, 'error'); })
            .then(function () { btn.disabled = false; });
    });

    document.getElementById('cta-guardar').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        postJson(BASE + '/correo/cuentas/guardar', ctaDatosForm())
            .then(function () { recargarEn('correo'); })
            .catch(function (err) {
                decir(ctaMsgEl, err.message, 'error');
                btn.disabled = false;
            });
    });

    // ══ Empresas: el mismo formulario agrega y edita ════════════════
    var formSoc   = document.getElementById('form-sociedad');
    var socSubmit = document.getElementById('soc-submit');
    var socCancel = document.getElementById('soc-cancelar');

    var socTitulo = document.getElementById('soc-form-titulo');

    Array.prototype.forEach.call(document.querySelectorAll('.soc-editar'), function (btn) {
        btn.addEventListener('click', function () {
            formSoc.action = BASE + '/sociedades/editar/' + btn.dataset.id;
            document.getElementById('soc-nombre').value = btn.dataset.nombre;
            document.getElementById('soc-cedula').value = btn.dataset.cedula;
            socTitulo.textContent = 'Editando «' + btn.dataset.nombre + '»';
            socSubmit.innerHTML = '<i class="fas fa-check"></i> Guardar cambios';
            socCancel.style.display = 'inline-flex';
            document.getElementById('soc-nombre').focus();
        });
    });

    socCancel.addEventListener('click', function () {
        formSoc.action = BASE + '/sociedades/crear';
        formSoc.reset();
        socTitulo.textContent = 'Nueva empresa';
        socSubmit.innerHTML = '<i class="fas fa-plus"></i> Agregar empresa';
        socCancel.style.display = 'none';
    });

    // ══ Respaldo de la base (solo administradores) ══════════════════
    var respGenerar = document.getElementById('respaldo-generar');
    if (respGenerar) {
        var respMsg     = document.getElementById('respaldo-msg');
        var respLista   = document.getElementById('respaldo-lista');
        var respHora    = document.getElementById('respaldo-hora');
        var respOn      = document.getElementById('respaldo-auto-activar');
        var respOff     = document.getElementById('respaldo-auto-desactivar');
        var respAutoMsg = document.getElementById('respaldo-auto-msg');
        var respSondeo  = null;

        function respDecir(texto, color) {
            respMsg.textContent = texto || '';
            respMsg.style.color = color || 'var(--text-muted)';
        }

        function respPintarLista(archivos) {
            if (!archivos || !archivos.length) {
                respLista.innerHTML = '<div class="cfg-row-ayuda">Todavía no hay ninguno.</div>';
                return;
            }
            var html = '<table class="data-table" style="font-size:12px;"><tbody>';
            archivos.forEach(function (a, i) {
                html += '<tr>'
                    + '<td class="cfg-ruta">' + a.nombre
                    + (i === 0 ? ' <span class="badge badge-ok">el más nuevo</span>' : '')
                    + '</td>'
                    + '<td style="white-space:nowrap;text-align:right;color:var(--text-muted);">' + a.tamano + '</td>'
                    + '<td style="white-space:nowrap;text-align:right;color:var(--text-muted);">' + a.fecha + '</td>'
                    + '</tr>';
            });
            respLista.innerHTML = html + '</tbody></table>';
        }

        // Mientras corre se pregunta cada 2 s. El proceso vive fuera del
        // navegador: cerrar la página no lo detiene y al volver se ve cómo
        // terminó.
        function respArrancarSondeo() {
            if (respSondeo) return;
            respSondeo = setInterval(respRefrescar, 2000);
        }
        function respPararSondeo() {
            if (!respSondeo) return;
            clearInterval(respSondeo);
            respSondeo = null;
        }

        function respPintarEstado(d) {
            respPintarLista(d.archivos);

            var e = d.estado;
            if (!e) { respDecir(''); return; }

            if (e.estado === 'corriendo') {
                respGenerar.disabled = true;
                respDecir(e.mensaje || 'Respaldo en curso…', 'var(--navy)');
                respArrancarSondeo();
                return;
            }

            respGenerar.disabled = false;
            respPararSondeo();
            if (e.estado === 'error') {
                respDecir(e.mensaje || 'Falló el respaldo.', 'var(--miss)');
            } else if (e.estado === 'ok') {
                respDecir('Último: ' + (e.terminado_en || '') + ' · ' + (e.mensaje || ''), 'var(--ok)');
            }
        }

        function respRefrescar() {
            return postJson(BASE + '/diagnostico/respaldo/estado', {})
                .then(respPintarEstado)
                .catch(function (err) {
                    respDecir(err.message, 'var(--miss)');
                    respPararSondeo();
                });
        }

        respGenerar.addEventListener('click', function () {
            respGenerar.disabled = true;
            respDecir('Lanzando…', 'var(--navy)');
            postJson(BASE + '/diagnostico/respaldo/iniciar', {})
                .then(function () { respArrancarSondeo(); return respRefrescar(); })
                .catch(function (err) {
                    respGenerar.disabled = false;
                    respDecir(err.message, 'var(--miss)');
                });
        });

        respOn.addEventListener('click', function () {
            respOn.disabled = true;
            postJson(BASE + '/diagnostico/respaldo/auto/activar', { hora: respHora.value })
                .then(function (r) {
                    respOn.disabled = false;
                    respOn.textContent = 'Cambiar la hora';
                    respOff.style.display = 'inline-flex';
                    respAutoMsg.textContent = r.message;
                    respAutoMsg.style.color = 'var(--ok)';
                })
                .catch(function (err) {
                    respOn.disabled = false;
                    respAutoMsg.textContent = err.message;
                    respAutoMsg.style.color = 'var(--miss)';
                });
        });

        respOff.addEventListener('click', function () {
            respOff.disabled = true;
            postJson(BASE + '/diagnostico/respaldo/auto/desactivar', {})
                .then(function (r) {
                    respOff.disabled = false;
                    respOff.style.display = 'none';
                    respOn.textContent = 'Activar';
                    respAutoMsg.textContent = r.message;
                    respAutoMsg.style.color = 'var(--text-muted)';
                })
                .catch(function (err) {
                    respOff.disabled = false;
                    respAutoMsg.textContent = err.message;
                    respAutoMsg.style.color = 'var(--miss)';
                });
        });

        if (RESPALDO_INICIAL) respPintarEstado(RESPALDO_INICIAL);
    }
})();
</script>
