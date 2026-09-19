<?php
/**
 * Configuración del sistema.
 *
 * Un menú de temas a la izquierda y UNA sección a la vez a la derecha. El
 * engranaje de la barra superior llega aquí desde cualquier módulo: lo que se
 * ajusta acá —dónde se guardan los XML y PDF, con qué empresa se trabaja, qué
 * buzones se leen— lo usan todos, no solo el correo, donde vivía escondido.
 *
 * Antes las nueve secciones se apilaban en una página larguísima y el índice
 * solo saltaba entre ellas: para cambiar la carpeta había que pasar por encima
 * de los buzones, las empresas y los usuarios, y quien buscaba el respaldo
 * bajaba tres pantallas. Ahora el índice abre y cierra —cada tema es su propia
 * ventana, empieza arriba y termina donde termina— y lo que no se está
 * mirando no estorba ni distrae.
 *
 * Cada sección conserva su ancla (#archivo, #correo, …) y `?ir=respaldo`, para
 * mandar a alguien directo al ajuste del que se le está hablando; el botón
 * «atrás» del navegador desanda los saltos entre secciones.
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
$sharepointDisponible = !empty($sharepointDisponible);
$pagos        = is_array($pagos ?? null) ? $pagos : [];
$pagoActivo   = (int) ($pagoActivo ?? 0);
$usuarios       = is_array($usuarios ?? null) ? $usuarios : [];
$roles          = is_array($roles ?? null) ? $roles : [];
$actividad      = is_array($actividad ?? null) ? $actividad : [];
$actividadEstado = is_array($actividadEstado ?? null) ? $actividadEstado : null;
$modulosPermisos = is_array($modulosPermisos ?? null) ? $modulosPermisos : [];
$respaldo       = is_array($respaldo ?? null) ? $respaldo : null;
$seccionInicial = (string) ($seccionInicial ?? '');
$selfId         = (int) ($_SESSION['user_id'] ?? 0);

// Qué secciones puede ver quien mira. Antes era un solo "esAdmin"; ahora cada
// una tiene su permiso, que es lo que permite un encargado de usuarios que no
// toque el respaldo de la base.
$puede = is_array($puede ?? null) ? $puede : [];
$puedeVer = fn(string $que): bool => !empty($puede[$que]);

$carpetaRaiz    = trim((string) ($configLocal['carpeta_destino'] ?? ''));

// El índice se arma de una lista, no a mano en el HTML: así el buscador del
// índice y las secciones no se pueden desincronizar.
$secciones = [
    ['archivo',        'fa-folder-tree',        'Archivo de documentos', 'Dónde se guardan los XML y PDF'],
    ['correo',         'fa-at',                 'Cuentas de correo',     'Buzones de los que se bajan las facturas'],
    ['automatizacion', 'fa-rotate',             'Automatización',        'Qué hace el sistema solo, en segundo plano'],
    ['empresas',       'fa-building',           'Empresas',              'Con cuál se trabaja y contra qué cédula se verifica'],
];
if ($puedeVer('proveedores')) {
    $secciones[] = ['proveedores', 'fa-address-book', 'Proveedores del sistema', 'Qué código es de qué cédula'];
}
if ($puedeVer('usuarios')) {
    $secciones[] = ['usuarios', 'fa-users-cog', 'Usuarios', 'Quién entra al sistema y con qué rol'];
}
if ($puedeVer('roles')) {
    $secciones[] = ['roles', 'fa-shield-halved', 'Roles y permisos', 'Qué puede hacer cada tipo de usuario'];
}
if ($puedeVer('bitacora')) {
    $secciones[] = ['actividad', 'fa-clock-rotate-left', 'Actividad', 'Quién hizo qué, y cuándo'];
}
if ($puedeVer('respaldo')) {
    $secciones[] = ['respaldo', 'fa-database', 'Respaldo', 'Copias de la base en la carpeta compartida'];
}
$secciones[] = ['sistema', 'fa-desktop', 'Esta computadora', 'Estado de la instalación local'];

// Cuál de las secciones queda abierta al llegar. Se decide acá y no en el
// navegador a propósito: si esperara al JS, la página nacería con las nueve
// apiladas y se vería el salto al cerrarlas. Con esto llega ya con una sola.
$idsSecciones   = array_column($secciones, 0);
$seccionAbierta = in_array($seccionInicial, $idsSecciones, true) ? $seccionInicial : $idsSecciones[0];
?>

<div class="cfg-shell">

    <!-- Sin JS no hay quien abra y cierre: mejor todas visibles, como antes,
         que una sola y ocho inalcanzables. -->
    <noscript><style>.cfg-section { display: block !important; }</style></noscript>

    <!-- ══ Menú de secciones ════════════════════════════════ -->
    <nav class="cfg-nav" id="cfg-nav" aria-label="Secciones de configuración">
        <div class="cfg-nav-buscar">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="cfg-buscar" placeholder="Buscar un ajuste…"
                   autocomplete="off" aria-label="Buscar un ajuste">
        </div>
        <div class="cfg-nav-lista" role="tablist" aria-label="Temas de configuración">
            <?php foreach ($secciones as [$id, $icono, $titulo, $resumen]): ?>
            <?php $abierta = ($seccionAbierta === $id); ?>
            <a href="#<?= $id ?>" data-cfg-nav="<?= $id ?>" title="<?= htmlspecialchars($resumen) ?>"
               role="tab" aria-controls="<?= $id ?>"
               aria-selected="<?= $abierta ? 'true' : 'false' ?>"
               tabindex="<?= $abierta ? '0' : '-1' ?>"
               class="<?= $abierta ? 'active' : '' ?>">
                <i class="fas <?= $icono ?>"></i><span><?= htmlspecialchars($titulo) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="cfg-nav-vacio" id="cfg-nav-vacio" style="display:none;">Ningún ajuste coincide.</div>
    </nav>

    <!-- ══ Secciones ════════════════════════════════════════ -->
    <div class="cfg-main">

        <!-- ── Archivo de documentos ───────────────────────── -->
        <section class="cfg-section<?= $seccionAbierta === 'archivo' ? ' is-activa' : '' ?>"
                 id="archivo" data-cfg-seccion="archivo" role="tabpanel"
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
                             * de advertencia—; PAGOS es donde se trabaja.
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
                                    <code>PAGOS</code>
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
                        al que entre. Nunca se borra nada.
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" id="cfg-guardar">
                        <i class="fas fa-check"></i> Guardar carpeta
                    </button>
                </div>
            </div>

        </section>

        <!-- ── Cuentas de correo ───────────────────────────── -->
        <section class="cfg-section<?= $seccionAbierta === 'correo' ? ' is-activa' : '' ?>"
                 id="correo" data-cfg-seccion="correo" role="tabpanel"
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
        <section class="cfg-section<?= $seccionAbierta === 'automatizacion' ? ' is-activa' : '' ?>"
                 id="automatizacion" data-cfg-seccion="automatizacion" role="tabpanel"
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
                        <label class="cfg-check" title="Sin esto, cada comprobante espera en la Bandeja a que alguien lo autorice">
                            <input id="auto-entrada" type="checkbox" checked> Entrada automática
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
                        <strong>Capturar correos nuevos</strong> baja a la Bandeja los XML y PDF que
                        lleguen a los buzones.
                        <br>
                        <strong>Entrada automática</strong> decide qué pasa después con ellos:
                        <ul style="margin:5px 0 0 16px;padding:0;">
                            <li>
                                <strong>Activada</strong> (recomendado): el comprobante que se lee bien,
                                que Hacienda no rechazó y que viene a nombre de la empresa entra solo —se
                                archiva en la carpeta de su mes, queda registrado y se cruza con el
                                listado que le toca: la factura con el reporte del sistema, la nota de
                                crédito con sus listados—. En la <strong>Bandeja</strong> solo queda lo
                                que hay que resolver a mano: rechazados por Hacienda, de otra cédula y
                                lo que no se pudo guardar.
                            </li>
                            <li>
                                <strong>Desactivada</strong>: todo espera en la Bandeja hasta que una
                                persona lo seleccione y confirme la importación.
                            </li>
                        </ul>
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
        <section class="cfg-section<?= $seccionAbierta === 'empresas' ? ' is-activa' : '' ?>"
                 id="empresas" data-cfg-seccion="empresas" role="tabpanel"
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
                        <div class="cfg-item-sub">
                            Cédula <?= htmlspecialchars($soc['cedula']) ?>
                            <?php $enSharepoint = ($soc['almacen_documentos'] ?? 'disco') === 'sharepoint'; ?>
                            · <span title="Dónde se archivan los documentos nuevos de esta empresa">
                                <i class="fas <?= $enSharepoint ? 'fa-cloud' : 'fa-folder' ?>"></i>
                                <?= $enSharepoint ? 'SharePoint' : 'Carpeta compartida' ?>
                            </span>
                        </div>
                        <?php // Solo quien puede cambiar la carpeta de documentos, y solo si la
                              // base ya tiene la columna: sin ella no hay dónde guardarlo. ?>
                        <?php if ($puedeVer('archivo') && array_key_exists('almacen_documentos', $soc)): ?>
                        <form method="POST" action="<?= $baseUrl ?>/sociedades/almacen/<?= (int) $soc['id'] ?>"
                              style="display:flex;gap:6px;align-items:center;margin-top:6px;"
                              data-confirm="Los documentos NUEVOS de <?= htmlspecialchars($soc['nombre'], ENT_QUOTES) ?> se van a archivar en otro lugar, para todas las computadoras. Lo que ya está no se mueve."
                              data-confirm-title="Cambiar dónde se archiva"
                              data-confirm-accept="Cambiar">
                            <input type="hidden" name="volver" value="empresas">
                            <select name="almacen" class="form-control" style="max-width:220px;height:30px;padding:2px 8px;font-size:12.5px;">
                                <option value="disco" <?= $enSharepoint ? '' : 'selected' ?>>Carpeta compartida</option>
                                <option value="sharepoint" <?= $enSharepoint ? 'selected' : '' ?>
                                        <?= $sharepointDisponible || $enSharepoint ? '' : 'disabled' ?>>
                                    SharePoint<?= $sharepointDisponible ? '' : ' (esta PC no tiene credenciales)' ?>
                                </option>
                            </select>
                            <button type="submit" class="btn btn-outline btn-sm">Cambiar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php // Registrar y borrar empresas es de administración;
                          // elegir con cuál se trabaja lo hace cualquiera. ?>
                    <?php if ($puedeVer('empresas')): ?>
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
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!$puedeVer('empresas')): ?>
                <div class="cfg-foot">
                    <div class="cfg-foot-nota">
                        Aquí eliges con cuál de tus empresas trabajas. Darlas de alta o
                        quitarlas es cosa del administrador.
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($puedeVer('empresas')): ?>
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
                <?php endif; ?>
            </div>
        </section>

        <?php if ($puedeVer('proveedores')): ?>
        <!-- ── Proveedores del ERP ─────────────────────────────
             El mapa código→cédula decide qué comprobante se acepta para cada
             factura. Mientras se armó solo de deducciones, un proveedor que
             facturaba ₡5.000 acabó enganchado a cuatro códigos ajenos: su
             consecutivo chocaba por los últimos ocho dígitos y el monto
             redondo hacía de "prueba". El listado del ERP trae la cédula
             escrita, que es lo que convierte el mapa en un dato. -->
        <?php $cat = array_merge(
            ['total' => 0, 'erp' => 0, 'manual' => 0, 'cedulas' => 0, 'ignoradas' => 0, 'sin_codigo' => 0],
            is_array($catalogo ?? null) ? $catalogo : []
        ); ?>
        <section class="cfg-section<?= $seccionAbierta === 'proveedores' ? ' is-activa' : '' ?>"
                 id="proveedores" data-cfg-seccion="proveedores" role="tabpanel"
                 data-cfg-buscar="proveedores catalogo catálogo cedula cédula codigo código erp mapa importar lista">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono gold"><i class="fas fa-address-book"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Proveedores del sistema</h2>
                        <p>Qué código del sistema pertenece a qué cédula. Es lo que impide que el
                           comprobante de un proveedor se le cuelgue a la factura de otro.</p>
                    </div>
                </div>

                <?php /* Cuatro cosas distintas que antes iban una debajo de la
                         otra: lo que hay que resolver, la lista, lo descartado
                         y la importación. En pestañas se ve una a la vez, y la
                         que abre es la que tiene trabajo pendiente. */
                    $catPestana = (int) $cat['total'] === 0 ? 'importar'
                        : ((int) $cat['sin_codigo'] > 0 ? 'pendientes' : 'codigos');
                ?>
                <div class="cat-resumen">
                    <span><b data-cat-cuenta="total"><?= number_format($cat['total']) ?></b> códigos</span>
                    <span class="cat-punto">·</span>
                    <span title="Varios códigos pueden ser del mismo proveedor: uno por punto de venta">
                        <b data-cat-cuenta="cedulas"><?= number_format($cat['cedulas']) ?></b> proveedores distintos</span>
                    <details class="cat-ayuda">
                        <summary><i class="fas fa-circle-info"></i>¿Para qué sirve esta lista?</summary>
                        <div>
                            <p><strong>Que una factura no se quede con el comprobante de otro.</strong> Si la
                               factura viene con el código 140001807, solo acepta comprobantes de la cédula
                               que ese código tiene anotada acá.</p>
                            <p><strong>Que no se cuele un proveedor desconocido.</strong> Si llega un
                               comprobante de una cédula que no está en esta lista, se pregunta de quién es
                               en lugar de adivinarlo.</p>
                        </div>
                    </details>
                </div>

                <?php if ((int) $cat['total'] === 0): ?>
                <div class="cfg-msg warn" style="margin:10px 14px 0;">
                    <strong>La lista todavía está vacía.</strong> Hasta que importés el listado, nada de
                    esto se revisa. Todo sigue funcionando como antes; lo que no hay es red.
                </div>
                <?php endif; ?>

                <div class="cat-tabs" role="tablist">
                    <button type="button" role="tab" data-cat-tab="pendientes"
                            class="<?= $catPestana === 'pendientes' ? 'is-activa' : '' ?>">
                        <i class="fas fa-circle-question"></i>Por resolver
                        <span class="cat-tab-n alerta" data-cat-cuenta="sin_codigo"><?= number_format($cat['sin_codigo']) ?></span>
                    </button>
                    <button type="button" role="tab" data-cat-tab="codigos"
                            class="<?= $catPestana === 'codigos' ? 'is-activa' : '' ?>">
                        <i class="fas fa-list"></i>Códigos
                        <span class="cat-tab-n" data-cat-cuenta="total"><?= number_format($cat['total']) ?></span>
                    </button>
                    <button type="button" role="tab" data-cat-tab="ignoradas"
                            class="<?= $catPestana === 'ignoradas' ? 'is-activa' : '' ?>">
                        <i class="fas fa-ban"></i>No se procesan
                        <span class="cat-tab-n" data-cat-cuenta="ignoradas"><?= number_format($cat['ignoradas']) ?></span>
                    </button>
                    <button type="button" role="tab" data-cat-tab="importar"
                            class="<?= $catPestana === 'importar' ? 'is-activa' : '' ?>">
                        <i class="fas fa-file-arrow-up"></i>Importar listado
                    </button>
                </div>

                <?php /* Los proveedores que mandaron comprobantes y no están en
                         ningún código. Se calcula, no se guarda: en cuanto
                         alguien le da su código a una cédula, sus comprobantes
                         desaparecen de acá sin que nadie los toque. */ ?>
                <div class="cat-panel" data-cat-panel="pendientes" <?= $catPestana === 'pendientes' ? '' : 'hidden' ?>>
                    <div class="cat-panel-barra">
                        <p class="cat-panel-nota">
                            Mandaron comprobantes y no tienen código. Lo que mandaron está guardado, pero no
                            se les puede buscar factura hasta saber de quién son.
                        </p>
                        <input type="search" id="cat-huer-filtro" class="form-control cat-filtro"
                               placeholder="Filtrar por nombre o cédula">
                    </div>
                    <div id="cat-huerfanos" class="cat-carga">Cargando…</div>
                </div>

                <?php /* El catálogo, editable. Paginado y con búsqueda: son
                         1.700 filas y casi siempre se abre para buscar una. */ ?>
                <div class="cat-panel" data-cat-panel="codigos" <?= $catPestana === 'codigos' ? '' : 'hidden' ?>>
                    <div class="cat-panel-barra">
                        <div style="display:flex;gap:6px;flex:1;min-width:0;">
                            <input type="search" id="cat-buscar" class="form-control cat-filtro"
                                   placeholder="Buscar por código, cédula o nombre">
                            <button type="button" class="btn btn-outline btn-sm" id="cat-buscar-btn" title="Buscar">
                                <i class="fas fa-magnifying-glass"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" id="cat-agregar-btn">
                            <i class="fas fa-plus" style="margin-right:4px;"></i>Agregar un código
                        </button>
                    </div>
                    <div id="cat-tabla" class="cat-carga">Cargando…</div>
                </div>

                <div class="cat-panel" data-cat-panel="ignoradas" <?= $catPestana === 'ignoradas' ? '' : 'hidden' ?>>
                    <p class="cat-panel-nota" style="margin-bottom:10px;">
                        Cédulas de las que ya se dijo que no facturan. Sus comprobantes siguen guardados;
                        lo que no hacen es volver a preguntar.
                    </p>
                    <div id="cat-ignoradas" class="cat-carga">Cargando…</div>
                </div>

                <div class="cat-panel" data-cat-panel="importar" <?= $catPestana === 'importar' ? '' : 'hidden' ?>>
                    <ol class="cat-pasos">
                        <li>En el sistema, abrí el reporte <strong>Lista de Proveedores</strong> y exportalo a CSV
                            <strong>sin filtrar por grupo</strong>; si lo filtrás, los códigos de los demás grupos
                            quedan fuera y van a seguir preguntando.</li>
                        <li>Elegí el archivo y mirá qué cambiaría. Nada se guarda hasta confirmar.</li>
                    </ol>

                    <form id="cat-form" enctype="multipart/form-data" class="cat-subir">
                        <input type="file" name="catalogo_file" id="cat-file" accept=".csv" hidden>
                        <label for="cat-file" class="btn btn-outline btn-sm" style="margin:0;">
                            <i class="fas fa-paperclip" style="margin-right:4px;"></i>Elegir archivo
                        </label>
                        <span id="cat-nombre" style="font-size:12px;color:var(--text-muted);">Ningún archivo seleccionado</span>
                        <button type="submit" class="btn btn-primary btn-sm" id="cat-btn">
                            <i class="fas fa-eye" style="margin-right:4px;"></i>Ver qué cambiaría
                        </button>
                    </form>

                    <details class="cat-ayuda" style="margin-top:10px;">
                        <summary><i class="fas fa-circle-info"></i>Qué se lee del archivo</summary>
                        <div>
                            <p>Solo el código y la cédula: el nombre y el correo vienen cortados por el ancho
                               de la columna, así que no se usan.</p>
                            <p>Que varios códigos tengan la misma cédula es normal: una empresa con varios
                               puntos de venta tiene un código por punto. Se anotan todos.</p>
                        </div>
                    </details>

                    <div id="cat-resultado" style="display:none;margin-top:13px;"></div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($puedeVer('usuarios')): ?>
        <!-- ── Usuarios ────────────────────────────────────── -->
        <section class="cfg-section<?= $seccionAbierta === 'usuarios' ? ' is-activa' : '' ?>"
                 id="usuarios" data-cfg-seccion="usuarios" role="tabpanel"
                 data-cfg-buscar="usuarios acceso contraseña administrador permisos cuenta login rol empresas">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-users-cog"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Usuarios</h2>
                        <p><?= count($usuarios) ?> usuario<?= count($usuarios) !== 1 ? 's' : '' ?> con acceso al sistema.
                           Lo que cada uno puede hacer lo decide su rol.</p>
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
                <?php
                    $esAdminEste = !empty($u['is_admin']) || !empty($u['rol_es_admin']);
                    $empresasDe  = (int) ($u['empresas'] ?? 0);
                ?>
                <div class="cfg-item">
                    <i class="fas fa-user-circle"
                       style="color:<?= (int) $u['activo'] ? 'var(--navy-light)' : 'var(--text-light)' ?>;font-size:16px;width:16px;"></i>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo">
                            <?= htmlspecialchars($u['nombre']) ?>
                            <?php if ((int) $u['id'] === $selfId): ?>
                            <span class="badge badge-green" style="margin-left:5px;">Tú</span>
                            <?php endif; ?>
                            <?php if ($esAdminEste): ?>
                            <span class="badge badge-navy" style="margin-left:4px;">
                                <i class="fas fa-shield-halved"></i> Admin
                            </span>
                            <?php endif; ?>
                            <?php if (!(int) $u['activo']): ?>
                            <span class="badge badge-miss" style="margin-left:4px;">Inactivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="cfg-item-sub">
                            <?= htmlspecialchars($u['username']) ?><?php if (!empty($u['cedula'])): ?> · <?= htmlspecialchars((string) $u['cedula']) ?><?php endif; ?>
                            · último acceso
                            <?= $u['ultimo_acceso'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($u['ultimo_acceso']))) : 'nunca' ?>
                        </div>
                        <div class="cfg-item-sub">
                            <?php if (!empty($u['rol_nombre'])): ?>
                            <span class="badge badge-gold"><i class="fas fa-shield-halved"></i>
                                <?= htmlspecialchars((string) $u['rol_nombre']) ?></span>
                            <?php else: ?>
                            <span class="badge badge-miss" title="Sin rol no puede entrar a ningún módulo">
                                <i class="fas fa-triangle-exclamation"></i> Sin rol</span>
                            <?php endif; ?>
                            <span style="margin-left:5px;">
                                <?= $empresasDe > 0
                                    ? 'limitado a ' . $empresasDe . ' empresa' . ($empresasDe === 1 ? '' : 's')
                                    : 'todas las empresas' ?>
                            </span>
                        </div>
                    </div>
                    <div class="cfg-item-acciones">
                        <a href="<?= $baseUrl ?>/usuarios/editar/<?= (int) $u['id'] ?>"
                           class="btn btn-outline btn-sm" title="Editar">
                            <i class="fas fa-pen"></i>
                        </a>
                        <?php if ((int) $u['id'] !== $selfId): ?>
                        <form method="POST" action="<?= $baseUrl ?>/usuarios/eliminar/<?= (int) $u['id'] ?>"
                              data-confirm="¿Quieres eliminar a <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>? Esta acción no se puede deshacer. Lo que hizo se queda en la bitácora."
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
        <?php endif; ?>

        <?php if ($puedeVer('roles')): ?>
        <!-- ── Roles y permisos ────────────────────────────── -->
        <?php
        /*
         * Un rol es un nombre y una lista de permisos; cada usuario tiene uno.
         * Aquí solo se listan y se entra a editarlos: la matriz de casillas es
         * pantalla propia porque no cabe en una tarjeta.
         */
        $totalPermisos = 0;
        foreach ($modulosPermisos as $m) { $totalPermisos += count($m['permisos'] ?? []); }
        ?>
        <section class="cfg-section<?= $seccionAbierta === 'roles' ? ' is-activa' : '' ?>"
                 id="roles" data-cfg-seccion="roles" role="tabpanel"
                 data-cfg-buscar="roles permisos accesos administrador operacion modulos privilegios">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono"><i class="fas fa-shield-halved"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Roles y permisos</h2>
                        <p>
                            <?= count($roles) ?> rol<?= count($roles) !== 1 ? 'es' : '' ?>
                            repartiendo <?= $totalPermisos ?> permisos sobre
                            <?= count($modulosPermisos) ?> módulos.
                        </p>
                    </div>
                    <a href="<?= $baseUrl ?>/roles/crear" class="btn btn-outline btn-sm">
                        <i class="fas fa-plus"></i> Nuevo rol
                    </a>
                </div>

                <?php if (empty($roles)): ?>
                <div class="cfg-vacio">
                    <i class="fas fa-shield-halved"></i>
                    Todavía no hay roles. Se crean solos la primera vez que alguien entra.
                </div>
                <?php else: ?>
                <?php foreach ($roles as $r): ?>
                <?php
                    $usuariosDelRol = (int) ($r['usuarios'] ?? 0);
                    $permisosDelRol = (int) ($r['permisos'] ?? 0);
                    $esRolAdmin     = !empty($r['es_admin']);
                    $esRolSistema   = !empty($r['es_sistema']);
                ?>
                <div class="cfg-item">
                    <i class="fas <?= $esRolAdmin ? 'fa-shield-halved' : 'fa-id-badge' ?>"
                       style="color:<?= $esRolAdmin ? 'var(--gold-dark)' : 'var(--navy-light)' ?>;font-size:15px;width:16px;"></i>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo">
                            <?= htmlspecialchars((string) $r['nombre']) ?>
                            <?php if ($esRolAdmin): ?>
                            <span class="badge badge-navy" style="margin-left:4px;">Lo puede todo</span>
                            <?php endif; ?>
                            <?php if ($esRolSistema): ?>
                            <span class="badge badge-default" style="margin-left:4px;"
                                  title="Es parte del sistema: se puede editar, no eliminar">
                                <i class="fas fa-lock"></i> Del sistema
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($r['descripcion'])): ?>
                        <div class="cfg-item-sub"><?= htmlspecialchars((string) $r['descripcion']) ?></div>
                        <?php endif; ?>
                        <div class="cfg-item-sub">
                            <?= $esRolAdmin ? 'todos los permisos' : $permisosDelRol . ' permiso' . ($permisosDelRol === 1 ? '' : 's') ?>
                            ·
                            <?= $usuariosDelRol ?> usuario<?= $usuariosDelRol === 1 ? '' : 's' ?>
                            <?php if ($usuariosDelRol === 0 && !$esRolSistema): ?>
                            <span style="color:var(--warn);"> · nadie lo usa</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cfg-item-acciones">
                        <a href="<?= $baseUrl ?>/roles/editar/<?= (int) $r['id'] ?>"
                           class="btn btn-outline btn-sm" title="Ver y cambiar sus permisos">
                            <i class="fas fa-pen"></i>
                        </a>
                        <?php if (!$esRolSistema): ?>
                        <form method="POST" action="<?= $baseUrl ?>/roles/eliminar/<?= (int) $r['id'] ?>"
                              data-confirm="¿Eliminar el rol &quot;<?= htmlspecialchars((string) $r['nombre'], ENT_QUOTES) ?>&quot;? Solo se puede si no lo tiene nadie."
                              data-confirm-title="Eliminar rol"
                              data-confirm-type="danger"
                              data-confirm-accept="Eliminar">
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

                <div class="cfg-foot">
                    <div class="cfg-foot-nota">
                        Un cambio en un rol surte efecto en la siguiente pantalla de quien lo
                        tenga: no hace falta que vuelva a entrar.
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($puedeVer('bitacora')): ?>
        <!-- ── Actividad ───────────────────────────────────── -->
        <?php
        /*
         * Los últimos movimientos, aquí mismo; la búsqueda de verdad —con
         * filtros por persona, módulo y fecha— está en /bitacora. Traer la
         * lista entera a esta pantalla la volvería lenta para todo lo demás.
         */
        $totalActividad = (int) ($actividadEstado['total'] ?? 0);
        $denegados      = (int) ($actividadEstado['denegados_30d'] ?? 0);
        ?>
        <section class="cfg-section<?= $seccionAbierta === 'actividad' ? ' is-activa' : '' ?>"
                 id="actividad" data-cfg-seccion="actividad" role="tabpanel"
                 data-cfg-buscar="actividad bitacora auditoria historial registro quien hizo cambios log">
            <div class="cfg-card">
                <div class="cfg-card-head">
                    <div class="cfg-card-icono gold"><i class="fas fa-clock-rotate-left"></i></div>
                    <div class="cfg-card-titulo">
                        <h2>Actividad</h2>
                        <p>
                            Cada acción que cambia algo queda registrada con quién la hizo.
                            <?php if ($totalActividad > 0): ?>
                            <?= number_format($totalActividad) ?> movimientos guardados
                            · <?= number_format((int) ($actividadEstado['hoy'] ?? 0)) ?> hoy.
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="<?= $baseUrl ?>/bitacora" class="btn btn-primary btn-sm">
                        <i class="fas fa-magnifying-glass"></i> Ver la bitácora
                    </a>
                </div>

                <?php if ($denegados > 0): ?>
                <div class="cfg-row apilada">
                    <div class="cfg-msg warn">
                        <i class="fas fa-ban"></i>
                        <?= number_format($denegados) ?> intento<?= $denegados === 1 ? '' : 's' ?>
                        de entrar sin permiso en los últimos 30 días.
                        <a href="<?= $baseUrl ?>/bitacora?resultado=denegado" style="font-weight:700;">Ver cuáles</a>.
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($actividad)): ?>
                <div class="cfg-vacio">
                    <i class="fas fa-clock-rotate-left"></i>
                    Todavía no hay movimientos registrados. Aparecerán en cuanto alguien
                    cargue, edite o borre algo.
                </div>
                <?php else: ?>
                <?php foreach ($actividad as $a): ?>
                <div class="cfg-item">
                    <i class="fas <?= ($a['resultado'] ?? 'ok') === 'denegado' ? 'fa-ban' : 'fa-circle-check' ?>"
                       style="color:<?= ($a['resultado'] ?? 'ok') === 'denegado' ? 'var(--miss)' : 'var(--ok)' ?>;font-size:14px;width:16px;"></i>
                    <div class="cfg-item-cuerpo">
                        <div class="cfg-item-titulo"><?= htmlspecialchars((string) $a['accion']) ?></div>
                        <div class="cfg-item-sub">
                            <?= htmlspecialchars((string) $a['usuario_nombre']) ?>
                            · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $a['creado_en']))) ?>
                            · <?= htmlspecialchars((string) $a['modulo']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <div class="cfg-foot">
                    <div class="cfg-foot-nota">
                        La bitácora también recoge el historial que cada módulo venía guardando
                        por su cuenta —las anotaciones de Seguimiento, las cargas, los avisos
                        cerrados—, ordenado en la misma línea de tiempo.
                    </div>
                    <a href="<?= $baseUrl ?>/bitacora" class="btn btn-outline btn-sm">
                        Buscar en la bitácora
                    </a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($puedeVer('respaldo')): ?>
        <!-- ── Respaldo de la base ─────────────────────────── -->
        <?php
        $estadoIni = $respaldo['estado'] ?? null;
        $corriendo = is_array($estadoIni) && ($estadoIni['estado'] ?? '') === 'corriendo';
        $autoIni   = $respaldo['automatico'] ?? ['activo' => false, 'hora' => ''];
        $horaIni   = ($autoIni['hora'] ?? '') !== '' ? $autoIni['hora'] : '22:00';
        ?>
        <section class="cfg-section<?= $seccionAbierta === 'respaldo' ? ' is-activa' : '' ?>"
                 id="respaldo" data-cfg-seccion="respaldo" role="tabpanel"
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
        <section class="cfg-section<?= $seccionAbierta === 'sistema' ? ' is-activa' : '' ?>"
                 id="sistema" data-cfg-seccion="sistema" role="tabpanel"
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
    var RESPALDO_INICIAL = <?= $puedeVer('respaldo') ? json_encode([
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

    // ══ Menú de secciones: una abierta, las demás cerradas ══════════
    //
    // El servidor ya manda la página con una sola sección abierta; esto es lo
    // que permite cambiar de sección sin recargar, y lo que mantiene la
    // dirección (#respaldo) al día para poder copiarla y pegársela a alguien.
    (function () {
        var nav       = document.getElementById('cfg-nav');
        var enlaces   = Array.prototype.slice.call(document.querySelectorAll('[data-cfg-nav]'));
        var secciones = Array.prototype.slice.call(document.querySelectorAll('[data-cfg-seccion]'));
        var buscar    = document.getElementById('cfg-buscar');
        var navVacio  = document.getElementById('cfg-nav-vacio');
        if (!enlaces.length) return;

        function existe(id) {
            return secciones.some(function (s) { return s.id === id; });
        }

        function abierta() {
            var s = secciones.filter(function (x) { return x.classList.contains('is-activa'); })[0];
            return s ? s.id : secciones[0].id;
        }

        /**
         * En pantalla angosta el menú es una tira que se desliza, y la sección
         * abierta puede caer fuera de ella —al llegar por ?ir=respaldo, la
         * pestaña marcada quedaba pasado el borde derecho, sin nada visible
         * diciendo dónde se está—. En la columna de escritorio no hace nada:
         * caben todas.
         */
        function aLaVista(enlace) {
            if (enlace && enlace.scrollIntoView) {
                enlace.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        /**
         * Abre una sección y cierra la que estuviera abierta.
         *
         * No hay scroll de por medio: la nueva sección empieza donde empezaba
         * la anterior. Lo único que se corrige es haber quedado a media
         * página, porque entonces la sección recién abierta arrancaría por
         * encima del borde de la pantalla.
         */
        function abrir(id, anotarEnHistorial) {
            if (!existe(id)) return;

            secciones.forEach(function (s) {
                s.classList.toggle('is-activa', s.id === id);
            });

            var enlaceActivo = null;
            enlaces.forEach(function (a) {
                var activo = a.dataset.cfgNav === id;
                a.classList.toggle('active', activo);
                a.setAttribute('aria-selected', activo ? 'true' : 'false');
                a.tabIndex = activo ? 0 : -1;
                if (activo) enlaceActivo = a;
            });

            if (window.scrollY > 0) window.scrollTo(0, 0);
            aLaVista(enlaceActivo);

            if (anotarEnHistorial && ('#' + id) !== window.location.hash) {
                history.pushState(null, '', '#' + id);
            }
        }

        // El enlace no salta al ancla: la abre. Saltar dejaría el título de la
        // sección debajo de la barra superior, que es fija.
        enlaces.forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                abrir(a.dataset.cfgNav, true);
            });
        });

        // Las flechas recorren el menú, como en cualquier lista de pestañas.
        if (nav) {
            nav.addEventListener('keydown', function (ev) {
                var paso = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[ev.key];
                if (!paso || !ev.target.dataset || !ev.target.dataset.cfgNav) return;

                var visibles = enlaces.filter(function (a) { return a.style.display !== 'none'; });
                var i = visibles.indexOf(ev.target);
                if (i < 0) return;

                ev.preventDefault();
                var destino = visibles[(i + paso + visibles.length) % visibles.length];
                abrir(destino.dataset.cfgNav, true);
                destino.focus();
            });
        }

        // Llegadas desde otra pantalla. El ?ir=usuarios ya lo resolvió el
        // servidor; queda el #usuarios, que solo se ve desde acá.
        var inicial = (window.location.hash || '').replace('#', '');
        if (inicial && existe(inicial) && inicial !== abierta()) {
            abrir(inicial, false);
        } else {
            aLaVista(document.querySelector('[data-cfg-nav].active'));
        }

        // Y el botón «atrás» del navegador desanda los saltos entre secciones.
        window.addEventListener('popstate', function () {
            var id = (window.location.hash || '').replace('#', '');
            abrir(existe(id) ? id : secciones[0].id, false);
        });

        // El buscador filtra el menú, no la sección abierta: quien escribe
        // «cédula» quiere que le digan dónde está, no perder de vista lo que
        // estaba mirando. Enter abre la primera coincidencia.
        if (buscar) {
            buscar.addEventListener('input', function () {
                var q = buscar.value.trim().toLowerCase();
                var encontradas = 0;

                enlaces.forEach(function (a) {
                    var s = document.getElementById(a.dataset.cfgNav);
                    // Se busca también dentro del texto de la sección cerrada:
                    // «mysqldump» no aparece en el menú y sin embargo lleva a
                    // Respaldo, que es lo que hace útil un buscador de ajustes.
                    var heno = a.textContent + ' ' + (a.title || '') + ' '
                             + (s ? (s.dataset.cfgBuscar || '') + ' ' + s.textContent : '');
                    var coincide = q === '' || heno.toLowerCase().indexOf(q) >= 0;
                    a.style.display = coincide ? '' : 'none';
                    if (coincide) encontradas++;
                });

                navVacio.style.display = encontradas === 0 ? 'block' : 'none';
            });

            buscar.addEventListener('keydown', function (ev) {
                if (ev.key !== 'Enter') return;
                ev.preventDefault();
                var primero = enlaces.filter(function (a) { return a.style.display !== 'none'; })[0];
                if (primero) abrir(primero.dataset.cfgNav, true);
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
    // tipo —que no cambian nunca— y su pago de pago; no hay dos respuestas
    // posibles que mereciesen una previsualización. Ahora lo acomoda la tarea
    // programada, como mucho cada quince minutos, en cli/sync_correo.php.

    // ══ Automatización de correo (Tarea Programada de Windows) ══════
    var autoEstado      = document.getElementById('auto-estado');
    var autoControles   = document.getElementById('auto-controles');
    var autoIntervalo   = document.getElementById('auto-intervalo');
    var autoCapturar    = document.getElementById('auto-capturar');
    var autoEntrada     = document.getElementById('auto-entrada');
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
                + ' documento(s) nuevo(s)';
            // Con entrada automática lo que interesa es en qué terminaron, no
            // cuántos se bajaron: bajarlos ya no es el final del camino.
            if (c.entrada_automatica) {
                extra += ' (' + Number(c.ingresados || 0).toLocaleString('es-CR') + ' al sistema, '
                    + Number(c.retenidos || 0).toLocaleString('es-CR') + ' a revisar, '
                    + Number(c.fallidos || 0).toLocaleString('es-CR') + ' con problema)';
            }
            extra += ', ' + Number(c.errores || 0).toLocaleString('es-CR') + ' error(es)';
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
        autoEntrada.checked  = info.entrada_automatica !== false;
        // Sin captura no hay nada que hacer entrar: la casilla se apaga
        // sola para no ofrecer un ajuste que no cambia nada.
        autoEntrada.disabled = !autoCapturar.checked;
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
                + (!info.capturar_nuevos
                    ? 'solo se actualiza el índice.'
                    : (info.entrada_automatica !== false
                        ? 'los comprobantes nuevos entran solos y se cruzan con sus listados.'
                        : 'los correos nuevos pasan a revisión manual.'));
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

    autoCapturar.addEventListener('change', function () {
        autoEntrada.disabled = !autoCapturar.checked;
    });

    autoActivar.addEventListener('click', function () {
        autoActivar.disabled = true;
        decir(autoMsg, 'Registrando la tarea de Windows…', null);
        postJson(BASE + '/correo/auto/activar', {
            intervalo_min: autoIntervalo.value,
            capturar_nuevos: autoCapturar.checked ? 1 : 0,
            entrada_automatica: autoEntrada.checked ? 1 : 0,
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

<?php if ($puedeVer('proveedores')): ?>
<script>
/*
 * El catálogo de proveedores del ERP.
 *
 * Se mira antes de aplicar porque la importación corrige el mapa —y con él,
 * qué comprobante se acepta para cada factura— sobre cientos de códigos de una
 * sola vez. Lo que el catálogo desmiente se enseña aparte y se suelta solo si
 * se pide: deshacer un emparejamiento borra trabajo hecho.
 */
(function () {
    var BASE = '<?= $baseUrl ?>';
    var form = document.getElementById('cat-form');
    if (!form || !window.fetch) { return; }

    var file = document.getElementById('cat-file');
    var nombre = document.getElementById('cat-nombre');
    var boton = document.getElementById('cat-btn');
    var caja = document.getElementById('cat-resultado');

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function num(n) { return Number(n || 0).toLocaleString('es-CR'); }
    function monto(n) {
        return '₡' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    file.addEventListener('change', function () {
        nombre.textContent = file.files.length ? file.files[0].name : 'Ningún archivo seleccionado';
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!file.files.length) {
            AppDialog.alert('Elegí el CSV de la Lista de Proveedores del sistema.', {
                title: 'Archivo requerido', type: 'warning'
            });
            return;
        }
        boton.disabled = true;
        boton.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:4px;"></i>Leyendo…';

        fetch(BASE + '/configuracion/proveedores/previa', {
            method: 'POST', body: new FormData(form), credentials: 'same-origin'
        })
            .then(function (r) { return r.json().catch(function () { throw new Error('Respuesta inválida del servidor.'); }); })
            .then(function (r) {
                if (!r.ok) { throw new Error(r.message || 'No se pudo leer el archivo.'); }
                pintar(r);
            })
            .catch(function (err) {
                AppDialog.alert(err.message, { title: 'No se pudo leer el archivo', type: 'danger' });
            })
            .then(function () {
                boton.disabled = false;
                boton.innerHTML = '<i class="fas fa-eye" style="margin-right:4px;"></i>Ver qué cambiaría';
            });
    });

    // Los estados que guarda la base, dichos como los diría una persona.
    var ESTADO_EN_CLARO = {
        respaldada: 'Se da por respaldada',
        con_diferencia: 'Con diferencia',
        sin_respaldo: 'Sin respaldo'
    };

    function pintar(r) {
        var p = r.plan || {};
        var c = r.contradice || {};
        var h = '';

        h += '<div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">'
           + esc(r.archivo) + (r.impreso_en ? ' · impreso el ' + esc(r.impreso_en) : '')
           + (r.grupos && r.grupos.length ? ' · Grupo: ' + esc(r.grupos.join(', ')) : '') + '</div>';

        h += '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;margin-bottom:10px;">'
           + '<span>Códigos en el archivo: <b>' + num(p.leidos) + '</b></span>'
           + '<span style="color:#16a34a;">Ya estaban así: <b>' + num(p.confirmados) + '</b></span>'
           + '<span style="color:#b45309;">Les cambia la cédula: <b>' + num(p.corregidos) + '</b></span>'
           + '<span>Se agregan: <b>' + num(p.nuevos) + '</b></span>'
           + '</div>';

        // Alta de proveedores: es el efecto menos evidente del botón, así que
        // se dice antes y no en el mensaje de después.
        if (p.proveedores_creados) {
            h += '<div class="alert alert-info" style="margin-bottom:10px;font-size:12px;">'
               + 'Se van a registrar <b>' + num(p.proveedores_creados) + '</b> proveedores nuevos, solo con '
               + 'su cédula. Son códigos que todavía no han mandado ningún comprobante. Anotarlos desde '
               + 'ya es lo que permite, cuando llegue uno, saber si es de ellos o no.</div>';
        }

        // Las cédulas repartidas entre varios códigos. Van antes que todo lo
        // demás y bloquean el botón: son la única pregunta del archivo que no
        // se puede contestar sola.
        var grupos = r.compartidas || [];
        if (grupos.length) {
            h += '<div class="alert alert-warning" style="margin-bottom:10px;font-size:12px;">'
               + '<b>' + num(grupos.length) + ' cédula(s) tienen más de un código.</b> '
               + 'Puede ser que sean puntos de venta distintos de la misma empresa, o que alguien la '
               + 'haya dado de alta dos veces por error. El archivo no lo dice, y mientras la cédula '
               + 'esté repartida no sirve para identificar a nadie.'
               + '<br><b>Marcá cuál es el código bueno de cada una.</b> Los demás no entran en esta '
               + 'importación; si ya estaban en la lista, se quedan como están.</div>';

            grupos.forEach(function (g, gi) {
                h += '<div style="border:1px solid #fcd34d;border-radius:8px;margin-bottom:9px;overflow:hidden;">'
                   + '<div style="background:#fffbeb;padding:6px 10px;font-size:11.5px;font-weight:700;'
                   + 'color:#92400e;">Cédula ' + esc(g.cedula) + ' · ' + num(g.codigos.length)
                   + ' códigos</div>';
                g.codigos.forEach(function (c, ci) {
                    h += '<label style="display:flex;gap:8px;align-items:baseline;padding:6px 10px;'
                       + 'border-top:1px solid var(--border-light);cursor:pointer;font-size:12px;">'
                       + '<input type="radio" name="elegido[' + esc(g.cedula) + ']" '
                       + 'value="' + esc(c.codigo) + '" form="cat-aplicar" '
                       + 'data-grupo="' + gi + '"' + (ci === 0 ? '' : '') + '>'
                       + '<span style="font-family:ui-monospace,monospace;">' + esc(c.codigo) + '</span>'
                       + '<span style="color:var(--text-muted);">' + esc(c.nombre) + '</span>'
                       + '<span style="margin-left:auto;color:var(--text-muted);font-size:11px;">fila '
                       + num(c.fila) + '</span></label>';
                });
                h += '</div>';
            });
        }

        // Los grupos que faltan: el archivo real venía filtrado por uno solo.
        if (r.sin_cedula) {
            h += '<div class="alert alert-warning" style="margin-bottom:10px;font-size:12px;">'
               + 'A <b>' + num(r.sin_cedula) + '</b> código(s) del archivo les falta la cédula, así que no '
               + 'se puede saber de quién son. No se tocan: quedan como están.</div>';
        }

        if (p.cambios && p.cambios.length) {
            h += '<div style="font-size:11.5px;font-weight:700;color:var(--navy);margin:10px 0 5px;">'
               + 'Códigos que cambian de dueño</div><div style="overflow-x:auto;">'
               + '<table style="width:100%;border-collapse:collapse;font-size:11.5px;">'
               + '<thead><tr style="background:var(--bg);color:var(--text-muted);text-align:left;">'
               + '<th style="padding:4px 7px;">Código</th><th style="padding:4px 7px;">Proveedor (en el sistema)</th>'
               + '<th style="padding:4px 7px;">Cédula según el archivo</th><th style="padding:4px 7px;">Lo que decía acá</th>'
               + '</tr></thead><tbody>';
            p.cambios.forEach(function (x) {
                h += '<tr style="border-top:1px solid var(--border-light);">'
                   + '<td style="padding:4px 7px;font-family:ui-monospace,monospace;">' + esc(x.codigo) + '</td>'
                   + '<td style="padding:4px 7px;">' + esc(x.nombre) + '</td>'
                   + '<td style="padding:4px 7px;color:#16a34a;font-weight:700;">' + esc(x.cedula_erp) + '</td>'
                   + '<td style="padding:4px 7px;color:#b91c1c;">' + esc(x.cedula_mapa || '—') + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }

        // Lo que el catálogo desmiente. Va con su propia casilla porque
        // soltarlos borra trabajo y no puede ir de arrastre.
        if (c.total) {
            h += '<div class="alert alert-warning" style="margin-top:12px;font-size:12px;">'
               + '<b>' + num(c.total) + ' factura(s) tienen enganchado el comprobante de otro proveedor.</b> '
               + 'El archivo dice que esos códigos son de otra cédula, así que el comprobante que tienen '
               + 'no puede ser de ellas.'
               + (c.respaldadas
                   ? ' <b>' + num(c.respaldadas) + '</b> de esas facturas figuran hoy como respaldadas: '
                   + 'están dadas por resueltas y no lo están, que es lo más delicado de la lista.'
                   : '')
               + '<details style="margin-top:6px;"><summary style="cursor:pointer;font-size:11px;">Ver cuáles</summary>'
               + '<div style="overflow-x:auto;margin-top:6px;"><table style="width:100%;border-collapse:collapse;font-size:11px;">'
               + '<thead><tr style="color:var(--text-muted);text-align:left;">'
               + '<th style="padding:3px 6px;">Código</th><th style="padding:3px 6px;">Documento</th>'
               + '<th style="padding:3px 6px;text-align:right;">Monto</th>'
               + '<th style="padding:3px 6px;">Comprobante que tiene enganchado</th>'
               + '<th style="padding:3px 6px;">Cómo figura hoy</th>'
               + '</tr></thead><tbody>';
            (c.detalle || []).forEach(function (x) {
                h += '<tr style="border-top:1px solid var(--border-light);">'
                   + '<td style="padding:3px 6px;font-family:ui-monospace,monospace;">' + esc(x.codigo) + '</td>'
                   + '<td style="padding:3px 6px;font-family:ui-monospace,monospace;">' + esc(x.documento) + '</td>'
                   + '<td style="padding:3px 6px;text-align:right;">' + monto(x.monto) + '</td>'
                   + '<td style="padding:3px 6px;">' + esc(x.emisor) + ' (' + esc(x.cedula) + ')</td>'
                   + '<td style="padding:3px 6px;">' + esc(ESTADO_EN_CLARO[x.estado] || x.estado) + '</td></tr>';
            });
            h += '</tbody></table></div></details></div>';
        }

        h += '<form method="POST" id="cat-aplicar" action="' + BASE + '/configuracion/proveedores/importar" '
           + 'style="margin-top:13px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
           + '<input type="hidden" name="archivo_token" value="' + esc(r.token) + '">'
           + '<button type="submit" class="btn btn-primary btn-sm" id="cat-aplicar-btn"'
           + (grupos.length ? ' disabled' : '') + '>'
           + '<i class="fas fa-check" style="margin-right:4px;"></i>Importar el listado</button>';
        if (grupos.length) {
            h += '<span id="cat-faltan" style="font-size:11.5px;color:#b45309;"></span>';
        }
        if (c.total) {
            h += '<label style="font-size:11.5px;color:var(--text-muted);display:block;cursor:pointer;'
               + 'max-width:430px;line-height:1.5;">'
               + '<input type="checkbox" name="desatar" value="1" style="margin-right:5px;"> '
               + 'Desenganchar también esas ' + num(c.total) + ' facturas'
               + '<span style="display:block;margin-left:19px;">Vuelven a quedar sin respaldo y se les '
               + 'busca comprobante de nuevo. Esta vez, con el archivo ya importado, no se les puede '
               + 'volver a enganchar el de otro proveedor.</span></label>';
        }
        h += '</form>';

        caja.innerHTML = h;
        caja.style.display = 'block';

        // El botón no se habilita hasta que las cédulas repartidas estén todas
        // decididas, y mientras tanto dice cuántas faltan: un botón apagado sin
        // explicación se lee como que la pantalla se rompió.
        if (grupos.length) {
            var boton = document.getElementById('cat-aplicar-btn');
            var faltan = document.getElementById('cat-faltan');
            var repasar = function () {
                var hechas = 0;
                grupos.forEach(function (g) {
                    if (caja.querySelector('input[name="elegido[' + g.cedula + ']"]:checked')) {
                        hechas++;
                    }
                });
                var restan = grupos.length - hechas;
                boton.disabled = restan > 0;
                faltan.textContent = restan > 0
                    ? 'Faltan ' + num(restan) + ' de ' + num(grupos.length) + ' por decidir'
                    : '';
            };
            caja.addEventListener('change', repasar);
            repasar();
        }
    }

    // ── El catálogo, uno por uno ────────────────────────────────────────
    //
    // Paginado y por JSON: son 1.700 filas y subiendo, y meterlas en el HTML
    // de la pantalla de configuración la volvería la más lenta del sistema por
    // una tabla que casi siempre se abre para buscar una sola.
    var tabla = document.getElementById('cat-tabla');
    var huerfanos = document.getElementById('cat-huerfanos');
    if (tabla) {
        var buscar = document.getElementById('cat-buscar');
        var ignoradas = document.getElementById('cat-ignoradas');
        var filtroHuer = document.getElementById('cat-huer-filtro');
        var paginaActual = 1;

        // Pendientes: llegan todos de una vez y se filtran y paginan acá, que
        // es donde se escribe el filtro. Pedirle a la base por cada tecla
        // repetiría la consulta más cara de la sección.
        var HUER_POR_PAGINA = 15;
        var huerLista = [];
        var huerTotal = 0;
        var huerPagina = 1;

        // ── Pestañas ────────────────────────────────────────────────────
        var pestanas = document.querySelectorAll('[data-cat-tab]');
        var paneles = document.querySelectorAll('[data-cat-panel]');
        function abrirPestana(nombre) {
            Array.prototype.forEach.call(pestanas, function (t) {
                var si = t.getAttribute('data-cat-tab') === nombre;
                t.classList.toggle('is-activa', si);
                t.setAttribute('aria-selected', si ? 'true' : 'false');
            });
            Array.prototype.forEach.call(paneles, function (p) {
                p.hidden = p.getAttribute('data-cat-panel') !== nombre;
            });
        }
        Array.prototype.forEach.call(pestanas, function (t) {
            t.addEventListener('click', function () { abrirPestana(t.getAttribute('data-cat-tab')); });
        });

        // Los números de arriba se vuelven a leer en cada carga: darle código a
        // un proveedor cambia tres a la vez y dejarlos quietos haría dudar de
        // si se guardó.
        function pintarCuentas(res) {
            if (!res) { return; }
            document.querySelectorAll('[data-cat-cuenta]').forEach(function (el) {
                var k = el.getAttribute('data-cat-cuenta');
                if (res[k] == null) { return; }
                el.textContent = num(res[k]);
                if (el.classList.contains('cat-tab-n')) {
                    el.classList.toggle('vacia', !Number(res[k]));
                }
            });
        }

        function fecha(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
        }

        function post(url, datos) {
            var cuerpo = new URLSearchParams();
            Object.keys(datos).forEach(function (k) { cuerpo.append(k, datos[k]); });
            return fetch(BASE + url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: cuerpo.toString()
            }).then(function (r) { return r.json(); });
        }

        function cargar(pagina) {
            paginaActual = pagina || 1;
            post('/configuracion/proveedores/listar', {
                buscar: buscar ? buscar.value : '', pagina: paginaActual
            }).then(pintarTodo).catch(function () {
                tabla.textContent = 'No se pudo cargar la lista.';
            });
        }

        function pintarTodo(r) {
            if (!r || !r.ok) { tabla.textContent = 'No se pudo cargar la lista.'; return; }
            pintarTabla(r);
            pintarCuentas(r.resumen);
            huerLista = r.sin_codigo || [];
            huerTotal = r.resumen && r.resumen.sin_codigo != null ? Number(r.resumen.sin_codigo) : huerLista.length;
            pintarHuerfanos();
            pintarIgnoradas(r.ignoradas || []);
        }

        function pintarTabla(r) {
            var filas = r.filas || [];
            if (!filas.length) {
                tabla.innerHTML = '<div style="padding:14px 0;color:var(--text-muted);">'
                    + (buscar && buscar.value ? 'Ningún código coincide con esa búsqueda.'
                                              : 'Todavía no hay ningún código anotado. Importá el listado del sistema.')
                    + '</div>';
                return;
            }

            var h = '<div class="cat-tabla-wrap"><table class="cat-tabla">'
                  + '<thead><tr><th>Código</th><th>Proveedor</th><th>Origen</th>'
                  + '<th class="accion"></th></tr></thead><tbody>';
            filas.forEach(function (f) {
                h += '<tr>'
                   + '<td class="mono"><b>' + esc(f.proveedor_codigo) + '</b></td>'
                   + '<td><div class="cat-nombre">' + esc(f.nombre_erp || f.razon_social || '—') + '</div>'
                   + '<div class="cat-sub mono">' + esc(f.cedula) + '</div></td>'
                   + '<td><span class="badge ' + (f.origen === 'manual' ? 'badge-gold' : 'badge-navy') + '">'
                   + (f.origen === 'manual' ? 'a mano' : 'del sistema') + '</span></td>'
                   + '<td class="accion"><div class="cat-iconos">'
                   + '<button type="button" class="btn btn-outline btn-sm" title="Cambiar la cédula"'
                   + ' data-cat-editar="' + esc(f.proveedor_codigo) + '"'
                   + ' data-cedula="' + esc(f.cedula) + '" data-nombre="' + esc(f.nombre_erp || '') + '"'
                   + ' data-razon="' + esc(f.razon_social || '') + '">'
                   + '<i class="fas fa-pen"></i></button>'
                   + '<button type="button" class="btn btn-outline btn-sm" title="Borrar de la lista"'
                   + ' data-cat-borrar="' + esc(f.proveedor_codigo) + '"'
                   + ' data-razon="' + esc(f.razon_social || f.nombre_erp || '') + '"><i class="fas fa-trash"></i></button>'
                   + '</div></td></tr>';
            });
            h += '</tbody></table></div>';

            h += '<div class="cat-pie"><span>' + num(r.total) + ' código' + (Number(r.total) === 1 ? '' : 's')
               + (buscar && buscar.value ? ' coinciden' : '') + '</span>';
            if (r.paginas > 1) {
                h += '<span class="cat-paginas">'
                   + '<button type="button" class="btn btn-outline btn-sm" data-cat-pagina="' + (r.pagina - 1) + '"'
                   + (r.pagina <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>'
                   + '<span>' + r.pagina + ' / ' + r.paginas + '</span>'
                   + '<button type="button" class="btn btn-outline btn-sm" data-cat-pagina="' + (r.pagina + 1) + '"'
                   + (r.pagina >= r.paginas ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>'
                   + '</span>';
            }
            h += '</div>';
            tabla.innerHTML = h;
        }

        function pintarHuerfanos() {
            if (!huerfanos) { return; }
            if (!huerLista.length) {
                huerfanos.innerHTML = '<div class="cfg-vacio"><i class="fas fa-circle-check" style="color:#16a34a;"></i>'
                    + 'Todos los comprobantes pertenecen a algún código.</div>';
                return;
            }

            var q = filtroHuer ? filtroHuer.value.trim().toLowerCase() : '';
            var lista = q === '' ? huerLista : huerLista.filter(function (p) {
                return String(p.razon_social || '').toLowerCase().indexOf(q) !== -1
                    || String(p.cedula || '').indexOf(q.replace(/[\s-]/g, '')) !== -1;
            });
            if (!lista.length) {
                huerfanos.innerHTML = '<div class="cfg-vacio">Ningún proveedor pendiente coincide con «'
                    + esc(q) + '».</div>';
                return;
            }

            var paginas = Math.max(1, Math.ceil(lista.length / HUER_POR_PAGINA));
            huerPagina = Math.min(Math.max(1, huerPagina), paginas);
            var desde = (huerPagina - 1) * HUER_POR_PAGINA;
            var visibles = lista.slice(desde, desde + HUER_POR_PAGINA);

            var h = '<div class="cat-tabla-wrap"><table class="cat-tabla">'
                  + '<thead><tr><th>Proveedor</th><th class="num">Comprobantes</th>'
                  + '<th class="accion"></th></tr></thead><tbody>';
            visibles.forEach(function (p) {
                var ultimo = fecha(p.hasta);
                h += '<tr>'
                   + '<td><div class="cat-nombre">' + esc(p.razon_social) + '</div>'
                   + '<div class="cat-sub mono">' + esc(p.cedula) + '</div></td>'
                   + '<td class="num"><b>' + num(p.comprobantes) + '</b>'
                   + (ultimo ? '<div class="cat-sub">último ' + ultimo + '</div>' : '') + '</td>'
                   + '<td class="accion"><div class="cat-asignar">'
                   + '<button type="button" class="btn btn-primary btn-sm"'
                   + ' data-cat-elegir="' + esc(p.cedula) + '" data-nombre="' + esc(p.razon_social) + '">'
                   + '<i class="fas fa-magnifying-glass" style="margin-right:4px;"></i>Elegir código</button>'
                   + '<button type="button" class="cat-descartar"'
                   + ' data-cat-ignorar="' + esc(p.cedula) + '" data-nombre="' + esc(p.razon_social) + '"'
                   + ' title="No volver a preguntar por esta cédula">No se procesa</button>'
                   + '</div></td></tr>';
            });
            h += '</tbody></table></div>';

            h += '<div class="cat-pie"><span>'
               + (q === '' ? '' : num(lista.length) + ' coinciden · ')
               + 'Mostrando ' + num(desde + 1) + '–' + num(desde + visibles.length) + ' de ' + num(lista.length)
               + (q === '' && huerTotal > huerLista.length
                   ? ' (los ' + num(huerLista.length) + ' con más comprobantes, de ' + num(huerTotal) + ')' : '')
               + '</span>';
            if (paginas > 1) {
                h += '<span class="cat-paginas">'
                   + '<button type="button" class="btn btn-outline btn-sm" data-cat-hpagina="' + (huerPagina - 1) + '"'
                   + (huerPagina <= 1 ? ' disabled' : '') + '><i class="fas fa-chevron-left"></i></button>'
                   + '<span>' + huerPagina + ' / ' + paginas + '</span>'
                   + '<button type="button" class="btn btn-outline btn-sm" data-cat-hpagina="' + (huerPagina + 1) + '"'
                   + (huerPagina >= paginas ? ' disabled' : '') + '><i class="fas fa-chevron-right"></i></button>'
                   + '</span>';
            }
            huerfanos.innerHTML = h + '</div>';
        }

        function pintarIgnoradas(lista) {
            if (!ignoradas) { return; }
            if (!lista.length) {
                ignoradas.innerHTML = '<div class="cfg-vacio"><i class="fas fa-ban"></i>'
                    + 'No hay proveedores descartados.</div>';
                return;
            }
            var h = '<div class="cat-tabla-wrap"><table class="cat-tabla">'
                  + '<thead><tr><th>Proveedor</th><th>Motivo</th><th class="accion"></th></tr></thead><tbody>';
            lista.forEach(function (p) {
                h += '<tr>'
                   + '<td><div class="cat-nombre">' + esc(p.nombre || '(sin nombre)') + '</div>'
                   + '<div class="cat-sub mono">' + esc(p.cedula) + '</div></td>'
                   + '<td class="cat-sub">' + esc(p.motivo || '—') + '</td>'
                   + '<td class="accion">'
                   + '<button type="button" class="btn btn-outline btn-sm"'
                   + ' data-cat-despertar="' + esc(p.cedula) + '">Volver a preguntar</button></td></tr>';
            });
            ignoradas.innerHTML = h + '</tbody></table></div>';
        }

        if (filtroHuer) {
            filtroHuer.addEventListener('input', function () { huerPagina = 1; pintarHuerfanos(); });
        }
        // ── Elegir el código de un proveedor ────────────────────────────
        //
        // Se abre debajo de su fila y no en una ventana: así se sigue viendo
        // de quién es la cédula mientras se busca. Abre ya con los códigos
        // cuyo nombre se parece al del proveedor, que es lo primero que uno
        // escribiría.

        function cerrarElegir() {
            if (!huerfanos) { return; }
            var abierta = huerfanos.querySelector('tr.cat-elegir-fila');
            if (abierta) { abierta.remove(); }
            huerfanos.querySelectorAll('tr.is-eligiendo').forEach(function (tr) { tr.classList.remove('is-eligiendo'); });
        }

        function abrirElegir(boton) {
            var ced = boton.getAttribute('data-cat-elegir');
            var nombre = boton.getAttribute('data-nombre') || '';
            var abierta = huerfanos.querySelector('tr.cat-elegir-fila');
            var eraLaMisma = abierta && abierta.getAttribute('data-cedula') === ced;
            cerrarElegir();
            if (eraLaMisma) { return; }

            var fila = boton.closest('tr');
            var tr = document.createElement('tr');
            tr.className = 'cat-elegir-fila';
            tr.setAttribute('data-cedula', ced);
            tr.innerHTML = '<td colspan="3"><div class="cat-elegir">'
                + '<div class="cat-elegir-barra">'
                + '<i class="fas fa-magnifying-glass"></i>'
                + '<input type="text" class="form-control" autocomplete="off"'
                + ' placeholder="Buscar por código, nombre o cédula">'
                + '<button type="button" class="cat-descartar" data-cat-cerrar title="Cerrar (Esc)">'
                + '<i class="fas fa-xmark"></i></button></div>'
                + '<div class="cat-elegir-titulo"></div>'
                + '<div class="cat-opciones"></div></div></td>';
            fila.classList.add('is-eligiendo');
            fila.parentNode.insertBefore(tr, fila.nextSibling);

            var input = tr.querySelector('input');
            var espera = null;
            var pedido = 0;

            function pedir() {
                var q = input.value.trim();
                var n = ++pedido;
                tr.querySelector('.cat-opciones').innerHTML =
                    '<div class="cat-opciones-vacio"><i class="fas fa-spinner fa-spin"></i> Buscando…</div>';
                post('/configuracion/proveedores/opciones', { buscar: q, nombre: nombre })
                    .then(function (r) {
                        if (n !== pedido || !tr.isConnected) { return; }
                        pintarOpciones(tr, r && r.ok ? r : { opciones: [], sugeridas: q === '' }, q, ced, nombre);
                    })
                    .catch(function () {
                        if (n !== pedido) { return; }
                        tr.querySelector('.cat-opciones').innerHTML =
                            '<div class="cat-opciones-vacio">No se pudo buscar. Probá de nuevo.</div>';
                    });
            }

            input.addEventListener('input', function () {
                clearTimeout(espera);
                espera = setTimeout(pedir, 250);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { e.preventDefault(); cerrarElegir(); boton.focus(); return; }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var primera = tr.querySelector('[data-cat-opcion]');
                    if (primera) { primera.click(); }
                }
            });

            input.focus();
            pedir();
        }

        function pintarOpciones(tr, r, q, ced, nombre) {
            var opciones = r.opciones || [];
            var titulo = tr.querySelector('.cat-elegir-titulo');
            var caja = tr.querySelector('.cat-opciones');

            if (r.sugeridas) {
                titulo.textContent = opciones.length
                    ? 'Códigos con un nombre parecido a «' + nombre + '»'
                    : 'No hay códigos con un nombre parecido. Buscá por código, nombre o cédula.';
            } else {
                titulo.textContent = opciones.length
                    ? num(opciones.length) + (opciones.length === 1 ? ' código coincide' : ' códigos coinciden')
                      + (opciones.length >= 30 ? ' (se muestran los primeros 30; afiná la búsqueda)' : '')
                    : 'Ningún código coincide con «' + q + '».';
            }

            var h = '';
            opciones.forEach(function (o) {
                var suyo = o.cedula && o.cedula === ced;
                var estado = suyo
                    ? '<span class="badge badge-ok">Ya es de este proveedor</span>'
                    : (o.cedula
                        ? '<span class="badge badge-warn" title="Si lo elegís, pasa a ser de este proveedor">'
                          + 'Es de ' + esc(o.razon_social || o.cedula) + '</span>'
                        : '<span class="badge badge-green">Sin dueño</span>');
                var detalle = [];
                if (o.cedula) { detalle.push('cédula ' + o.cedula); }
                if (o.facturas) { detalle.push(num(o.facturas) + ' factura' + (o.facturas == 1 ? '' : 's') + ' en el ERP'); }

                h += '<button type="button" class="cat-opcion" data-cat-opcion="' + esc(o.codigo) + '"'
                   + ' data-cedula-actual="' + esc(o.cedula || '') + '"'
                   + ' data-dueno="' + esc(o.razon_social || o.nombre || '') + '">'
                   + '<span class="cat-opcion-codigo">' + esc(o.codigo) + '</span>'
                   + '<span class="cat-opcion-nombre">' + esc(o.nombre || o.razon_social || '(sin nombre)')
                   + (detalle.length ? '<small>' + esc(detalle.join(' · ')) + '</small>' : '') + '</span>'
                   + estado + '</button>';
            });

            // Solo se elige entre códigos que existen. Escribir uno a mano
            // dejaba pasar una cédula o un número mal copiado como código, y
            // ese error no se ve hasta que una factura acepta el comprobante
            // equivocado. Si el código no aparece, lo que falta es importar
            // el listado del sistema.
            caja.innerHTML = h || '<div class="cat-opciones-vacio">'
                + (q === ''
                    ? 'Escribí parte del código, del nombre o de la cédula.'
                    : 'Solo se puede elegir un código que ya exista. Si el proveedor es nuevo, '
                      + 'importá de nuevo el listado del sistema desde la pestaña «Importar listado».')
                + '</div>';
        }

        async function elegirOpcion(b) {
            var tr = b.closest('tr.cat-elegir-fila');
            if (!tr) { return; }
            var ced = tr.getAttribute('data-cedula');
            var boton = huerfanos.querySelector('[data-cat-elegir="' + ced + '"]');
            var nombre = boton ? boton.getAttribute('data-nombre') || '' : '';
            var codigo = b.getAttribute('data-cat-opcion');
            var cedulaActual = b.getAttribute('data-cedula-actual') || '';

            // Guardar un código que ya tiene dueño se lo quita a ese dueño:
            // es la única elección de la lista que deshace algo.
            if (cedulaActual && cedulaActual !== ced) {
                var dueno = b.getAttribute('data-dueno') || cedulaActual;
                var seguir = await AppDialog.confirm(
                    'El código ' + codigo + ' hoy es de ' + dueno + ' (cédula ' + cedulaActual + ').\n\n'
                    + 'Si lo pasás a ' + (nombre || ced) + ', sus facturas van a aceptar los comprobantes de '
                    + 'este proveedor y dejan de aceptar los de aquel.',
                    { title: 'Ese código ya tiene dueño', type: 'warning', confirmText: 'Pasarlo a este proveedor' }
                );
                if (!seguir) { return; }
            }

            tr.querySelectorAll('[data-cat-opcion]').forEach(function (x) { x.disabled = true; });
            post('/configuracion/proveedores/guardar', { codigo: codigo, cedula: ced, nombre: nombre, existente: '1' })
                .then(function (r) {
                    if (!r.ok) {
                        AppDialog.alert(r.message || 'No se pudo guardar.', { title: 'No se guardó', type: 'danger' });
                        tr.querySelectorAll('[data-cat-opcion]').forEach(function (x) { x.disabled = false; });
                        return;
                    }
                    cargar(paginaActual);
                })
                .catch(function () {
                    AppDialog.alert('No se pudo guardar.', { title: 'No se guardó', type: 'danger' });
                    tr.querySelectorAll('[data-cat-opcion]').forEach(function (x) { x.disabled = false; });
                });
        }

        // Un solo oyente para toda la sección: las tablas se repintan enteras
        // en cada cambio, así que enganchar cada botón sería volver a
        // engancharlos todos cada vez.
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-cat-pagina],[data-cat-editar],[data-cat-borrar],'
                  + '[data-cat-elegir],[data-cat-opcion],[data-cat-cerrar],[data-cat-ignorar],'
                  + '[data-cat-despertar],[data-cat-hpagina]') : null;
            if (!b) { return; }

            if (b.hasAttribute('data-cat-hpagina')) {
                huerPagina = parseInt(b.getAttribute('data-cat-hpagina'), 10) || 1;
                pintarHuerfanos();
                return;
            }

            if (b.hasAttribute('data-cat-pagina')) {
                cargar(parseInt(b.getAttribute('data-cat-pagina'), 10));
                return;
            }

            if (b.hasAttribute('data-cat-editar')) {
                formularioCodigo({
                    codigo: b.getAttribute('data-cat-editar'),
                    cedula: b.getAttribute('data-cedula') || '',
                    nombre: b.getAttribute('data-nombre') || '',
                    razon: b.getAttribute('data-razon') || ''
                });
                return;
            }

            if (b.hasAttribute('data-cat-borrar')) {
                borrarCodigo(b.getAttribute('data-cat-borrar'), b.getAttribute('data-razon') || '');
                return;
            }

            if (b.hasAttribute('data-cat-elegir')) {
                abrirElegir(b);
                return;
            }
            if (b.hasAttribute('data-cat-opcion')) {
                elegirOpcion(b);
                return;
            }
            if (b.hasAttribute('data-cat-cerrar')) {
                cerrarElegir();
                return;
            }

            if (b.hasAttribute('data-cat-ignorar')) {
                ignorarCedula(b.getAttribute('data-cat-ignorar'), b.getAttribute('data-nombre') || '');
                return;
            }

            if (b.hasAttribute('data-cat-despertar')) {
                post('/configuracion/proveedores/ignorar', {
                    cedula: b.getAttribute('data-cat-despertar'), quitar: '1'
                }).then(function (r) {
                    if (!r.ok) { avisarFallo(r.message); return; }
                    cargar(paginaActual);
                }).catch(function () { avisarFallo(); });
            }
        });

        function avisarFallo(mensaje) {
            AppDialog.alert(mensaje || 'No se pudo completar. Probá de nuevo.', { title: 'No se guardó', type: 'danger' });
        }

        // ── Formulario de un código ─────────────────────────────────────
        //
        // El mismo para corregir y para agregar. Al corregir, el código se
        // muestra fijo: cambiarlo sería otro código, no una corrección, y se
        // hace borrando y agregando.
        function formularioCodigo(datos) {
            var nuevo = !datos.codigo;
            var cedulaOriginal = String(datos.cedula || '');

            var cuerpo = document.createElement('div');
            cuerpo.className = 'dlg-campos';
            cuerpo.innerHTML =
                (nuevo
                    ? '<div class="dlg-campo"><label for="dlg-cod">Código en el sistema</label>'
                      + '<input id="dlg-cod" class="form-control mono" maxlength="30" autocomplete="off"'
                      + ' placeholder="Ej. 140001807"></div>'
                    : '<div class="dlg-fijo"><span class="dlg-fijo-codigo">' + esc(datos.codigo) + '</span>'
                      + '<span class="dlg-fijo-nombre">' + esc(datos.razon || datos.nombre || '') + '</span></div>')
                + '<div class="dlg-campo"><label for="dlg-ced">Cédula del proveedor</label>'
                + '<input id="dlg-ced" class="form-control mono" inputmode="numeric" maxlength="20"'
                + ' autocomplete="off" value="' + esc(cedulaOriginal) + '" placeholder="Solo números, 9 a 12 dígitos">'
                + '<div class="dlg-ayuda" id="dlg-ced-ayuda">Física 9 dígitos, jurídica 10, DIMEX 11 o 12. '
                + 'Los guiones se quitan solos.</div></div>'
                + '<div class="dlg-campo"><label for="dlg-nom">Nombre en el sistema '
                + '<span class="opcional">(opcional)</span></label>'
                + '<input id="dlg-nom" class="form-control" maxlength="255" autocomplete="off"'
                + ' value="' + esc(datos.nombre || '') + '"></div>';

            var campoCed = cuerpo.querySelector('#dlg-ced');
            var ayudaCed = cuerpo.querySelector('#dlg-ced-ayuda');
            var ayudaNormal = ayudaCed.textContent;

            // Cambiar la cédula de un código que ya existe cambia de quién son
            // los comprobantes que aceptan sus facturas. Se dice mientras se
            // escribe, no después de guardar.
            campoCed.addEventListener('input', function () {
                campoCed.classList.remove('is-invalido');
                var limpia = campoCed.value.replace(/[\s-]+/g, '');
                var cambia = !nuevo && cedulaOriginal !== '' && limpia !== '' && limpia !== cedulaOriginal;
                ayudaCed.classList.toggle('alerta', cambia);
                ayudaCed.textContent = cambia
                    ? 'Al cambiarla, las facturas de ' + datos.codigo + ' van a aceptar los comprobantes de esta '
                      + 'cédula y dejan de aceptar los de ' + cedulaOriginal + '.'
                    : ayudaNormal;
            });

            return AppDialog.form({
                title: nuevo ? 'Agregar un código' : 'Corregir el código ' + datos.codigo,
                type: 'info',
                content: cuerpo,
                confirmText: nuevo ? 'Agregar' : 'Guardar cambios',
                onConfirm: function () {
                    var codigo = nuevo ? cuerpo.querySelector('#dlg-cod').value.trim() : datos.codigo;
                    var cedula = campoCed.value.replace(/[\s-]+/g, '');
                    var nombre = cuerpo.querySelector('#dlg-nom').value.trim();

                    if (nuevo && !/^[A-Za-z0-9._-]{1,30}$/.test(codigo)) {
                        cuerpo.querySelector('#dlg-cod').classList.add('is-invalido');
                        cuerpo.querySelector('#dlg-cod').focus();
                        return codigo === '' ? 'Falta el código.'
                            : 'El código solo admite letras, números, punto, guion y guion bajo.';
                    }
                    if (!/^\d{9,12}$/.test(cedula)) {
                        campoCed.classList.add('is-invalido');
                        campoCed.focus();
                        return cedula === '' ? 'Falta la cédula.'
                            : 'La cédula tiene que tener entre 9 y 12 dígitos, solo números.';
                    }
                    if (!nuevo && cedula === cedulaOriginal && nombre === String(datos.nombre || '')) {
                        return true;   // nada que guardar
                    }

                    return post('/configuracion/proveedores/guardar', { codigo: codigo, cedula: cedula, nombre: nombre })
                        .then(function (r) {
                            if (!r || !r.ok) { return (r && r.message) || 'No se pudo guardar.'; }
                            cargar(nuevo ? 1 : paginaActual);
                            return true;
                        }, function () { return 'No se pudo guardar. Probá de nuevo.'; });
                }
            });
        }

        async function borrarCodigo(codigo, nombre) {
            var seguir = await AppDialog.confirm(
                'Sus facturas dejan de aceptar cualquier comprobante hasta que el código vuelva a estar en la lista.',
                {
                    title: '¿Borrar el código ' + codigo + (nombre ? ' de ' + nombre : '') + '?',
                    type: 'danger', confirmText: 'Borrar'
                }
            );
            if (!seguir) { return; }
            post('/configuracion/proveedores/eliminar', { codigo: codigo }).then(function (r) {
                if (!r.ok) { avisarFallo(r.message); return; }
                cargar(paginaActual);
            }).catch(function () { avisarFallo(); });
        }

        async function ignorarCedula(cedula, nombre) {
            var seguir = await AppDialog.confirm(
                'No se va a volver a preguntar por sus comprobantes. Los que ya entraron se quedan donde están, '
                + 'y se puede deshacer desde la pestaña «No se procesan».',
                { title: '¿Dejar de procesar a ' + (nombre || cedula) + '?', type: 'warning', confirmText: 'No procesar' }
            );
            if (!seguir) { return; }
            post('/configuracion/proveedores/ignorar', { cedula: cedula, nombre: nombre || cedula }).then(function (r) {
                if (!r.ok) { avisarFallo(r.message); return; }
                cargar(paginaActual);
            }).catch(function () { avisarFallo(); });
        }

        var btnBuscar = document.getElementById('cat-buscar-btn');
        if (btnBuscar) { btnBuscar.addEventListener('click', function () { cargar(1); }); }
        if (buscar) {
            buscar.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); cargar(1); }
            });
        }

        var btnAgregar = document.getElementById('cat-agregar-btn');
        if (btnAgregar) {
            btnAgregar.addEventListener('click', function () { formularioCodigo({}); });
        }

        cargar(1);
    }
})();
</script>
<?php endif; ?>
