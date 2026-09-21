<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <?php // app.js lo pone en cada POST de la pantalla: ver app/core/Csrf.php. ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token()) ?>">
    <title><?= htmlspecialchars($title ?? 'Nexo Fiscal') ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Estilos principales (ruta relativa al servidor para evitar problemas de puerto) -->
    <?php
    $_ap = rtrim(parse_url(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', PHP_URL_PATH), '/');
    $_cssFile = __DIR__ . '/../../../public/assets/css/styles.css';
    $_cssVer  = is_file($_cssFile) ? filemtime($_cssFile) : time();
    $_jsFile  = __DIR__ . '/../../../public/assets/js/app.js';
    $_jsVer   = is_file($_jsFile) ? filemtime($_jsFile) : time();
    ?>
    <link rel="stylesheet" href="<?= $_ap ?>/assets/css/styles.css?v=<?= $_cssVer ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body data-base="<?= htmlspecialchars($_ap) ?>">

<!-- Se carga antes de las vistas para que sus acciones puedan usar AppDialog. -->
<script src="<?= $_ap ?>/assets/js/app.js?v=<?= $_jsVer ?>"></script>

<?php
$baseUrl    = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$uri        = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriClean   = rtrim($uri, '/');

/**
 * El segmento tiene que terminar donde termina la URI o antes de una barra.
 * Con una comparación por prefijo, /facturas-erp marcaría activo también el
 * ítem de /facturas.
 */
function navCoincide(string $segment, string $uri): bool {
    return (bool) preg_match('~/' . preg_quote($segment, '~') . '(/|$)~', $uri);
}

function navActive(string $segment, string $uri): string {
    if ($segment === '') {
        return (str_ends_with($uri, '/public') || str_ends_with($uri, '/public/') || $uri === '' || $uri === '/') ? 'active' : '';
    }
    return navCoincide($segment, $uri) ? 'active' : '';
}

// Título de la página actual para el topbar (sin descripción: solo el nombre)
$pageLabels = [
    'seguimiento'  => 'Seguimiento de documentos',
    'facturas'     => 'Facturas · Carga XML',
    'facturas-erp' => 'Facturas',
    'correo'       => 'Correo',
    'por-pagar'    => 'Pagos',
    'notas-credito'=> 'Notas de crédito',
    // Devoluciones sigue teniendo su título: la pantalla existe y se llega
    // por la URL, lo que se retiró es la entrada del menú.
    'devoluciones' => 'Devoluciones',
    'notas-xml'    => 'Notas de crédito · Carga XML',
    'usuarios'     => 'Gestión de Usuarios',
    'roles'        => 'Roles y permisos',
    'bitacora'     => 'Bitácora de actividad',
    'diagnostico'  => 'Diagnóstico de la instalación',
    'avisos'       => 'Avisos',
    'configuracion'=> 'Configuración',
];

$currentLabel = 'Panel Principal';
foreach ($pageLabels as $seg => $label) {
    if (navCoincide($seg, $uriClean)) {
        $currentLabel = $label;
        break;
    }
}

// Una vista puede decir su propio nombre y pisar el del módulo: es lo que
// hacen las pantallas que no son la portada (ver Controller::render). Así el
// título sigue estando una sola vez, arriba, y no hace falta un <h1> debajo
// repitiendo lo mismo o corrigiéndolo.
if (!empty($tituloPagina)) {
    $currentLabel = (string) $tituloPagina;
}

/*
 * Qué ofrece el menú.
 *
 * La lista no está aquí: vive en config/menu.php y la filtra Navegacion, que
 * es la misma que dibuja el panel principal. Cuando cada uno tenía la suya,
 * la barra enseñaba once módulos y el panel ofrecía cuatro atajos.
 *
 * Esconder un módulo no es lo que protege —de eso se encarga el enrutador,
 * que comprueba el permiso aunque se escriba la dirección a mano—, pero sí es
 * lo que hace que el sistema se entienda: un menú con seis entradas que
 * devuelven "no tienes permiso" no informa de nada y parece averiado.
 */
require_once __DIR__ . '/../../helpers/Navegacion.php';
$seccionesMenu = Navegacion::secciones();

$puedeVerMenu = fn(string $permiso): bool => Permisos::puede($permiso);

// El engranaje de la barra superior lleva a la misma pantalla que la entrada
// del menú, y se ofrece con la misma regla: Configuración hospeda secciones
// de varios dueños —usuarios, roles, bitácora, respaldo—, así que basta con
// poder entrar a CUALQUIERA de ellas.
$puedeConfiguracion = Permisos::puedeAlguno([
    'configuracion.ver', 'usuarios.ver', 'usuarios.administrar',
    'roles.administrar', 'bitacora.ver', 'respaldo.administrar',
    'empresas.administrar', 'correo.configurar', 'configuracion.archivo',
]);

// Sociedad activa: algunos controladores ya la cargan para su propia vista
// (Home, Correo, Por-pagar); en las demás páginas se busca aquí para que
// el nombre siempre aparezca en la topbar.
if (!isset($sociedadActiva)) {
    $sociedadActiva = null;
    try {
        $sociedadActiva = $this->loadModel('Sociedad')->getActiva();
    } catch (Throwable $e) {
    }
}
?>

<!-- Mobile toggle -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Abrir menú">
    <i class="fas fa-bars"></i>
</button>

<!-- ══ APP LAYOUT ══════════════════════════════════════════ -->
<div class="app-layout">

    <!-- ══ SIDEBAR ═══════════════════════════════════ -->
    <aside class="sidebar" id="sidebar">

        <!-- Logo texto -->
        <div class="sidebar-logo">
            <a href="<?= $baseUrl ?>/" style="text-decoration:none;">
                <div class="logo-full" style="font-size:22px;font-weight:800;letter-spacing:.5px;line-height:1.1;text-align:center;">
                    <span style="color:var(--gold);">Nexo</span><span style="color:#fff;"> Fiscal</span>
                </div>
                <div class="logo-mini" title="Nexo Fiscal">
                    <span style="color:var(--gold);">N</span><span style="color:#fff;">F</span>
                </div>
            </a>
        </div>

        <div class="sidebar-bottom">
            <a href="<?= $baseUrl ?>/" title="Ir al inicio">
                <span class="nav-icon"><i class="fas fa-house"></i></span>
                <span class="nav-label">Inicio</span>
            </a>
        </div>

        <!-- Navegación -->
        <nav class="sidebar-nav">
            <?php foreach ($seccionesMenu as $idSeccion => $seccion): ?>
            <div class="sidebar-section-label"<?= $idSeccion === array_key_first($seccionesMenu) ? '' : ' style="margin-top:16px;"' ?>><?= htmlspecialchars($seccion['titulo']) ?></div>
                <?php foreach ($seccion['entradas'] as $entrada): ?>
                    <?php if (!empty($entrada['bloqueada'])):
                        /*
                         * A la vista pero cerrado: al módulo le falta un paso
                         * de configuración. Se dibuja apagado y sin enlace —no
                         * hay adónde llevar todavía— y el globo dice cuál es
                         * el paso. Quien escriba la dirección a mano choca con
                         * la misma puerta: ver app/core/Requisitos.php.
                         */
                        $falta = implode(' · ', array_map(
                            fn($r) => (string) ($r['titulo'] ?? ''), $entrada['falta']
                        )); ?>
            <span class="nav-bloqueada"
                  title="<?= htmlspecialchars($entrada['titulo'] . ' — falta configurar: ' . $falta) ?>">
                <span class="nav-icon"><i class="fas <?= $entrada['icono'] ?>"></i></span>
                <span class="nav-label"><?= htmlspecialchars($entrada['titulo']) ?></span>
                <span class="nav-candado"><i class="fas fa-lock"></i></span>
            </span>
                    <?php else: ?>
            <a href="<?= $baseUrl . $entrada['ruta'] ?>"
               class="<?= navActive($entrada['segmento'], $uriClean) ?>"
               title="<?= htmlspecialchars($entrada['ayuda']) ?>">
                <span class="nav-icon"><i class="fas <?= $entrada['icono'] ?>"></i></span>
                <span class="nav-label"><?= htmlspecialchars($entrada['titulo']) ?></span>
            </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <!-- Footer del sidebar -->

    </aside>
    <!-- ── /sidebar ── -->

    <script>
    // En escritorio se expande al posar el cursor; en móvil manda el botón.
    (function () {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        var hoverTimer = null;
        var hoverDelay = 500;

        sidebar.addEventListener('mouseenter', function () {
            if (window.innerWidth <= 900) return;

            hoverTimer = setTimeout(function () {
                sidebar.classList.add('expanded');
            }, hoverDelay);
        });

        sidebar.addEventListener('mouseleave', function () {
            clearTimeout(hoverTimer);
            sidebar.classList.remove('expanded');
        });
    })();
    </script>

    <!-- ══ MAIN ═══════════════════════════════════════ -->
    <div class="app-main">

        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left" style="display:flex;align-items:center;gap:9px;min-width:0;">
                <h2 style="white-space:nowrap;"><?= htmlspecialchars($currentLabel) ?></h2>

                <?php // El modo de Correo va junto al título: es de qué trata la
                      // pantalla entera, no un control más dentro de ella.
                if (isset($modoCorreo) && strpos($uriClean, '/correo') !== false): ?>
                <div style="display:flex;align-items:center;gap:5px;padding-left:9px;
                            border-left:1px solid var(--border-light);"
                     title="El buzón y su árbol de carpetas se mantienen en los tres modos.">
                    <?php foreach ([
                        'facturas'  => ['fa-file-invoice',   'Facturas'],
                        'descargas' => ['fa-cloud-arrow-down', 'Descargas'],
                    ] as $clave => [$icono, $etiqueta]):
                        $actual = $modoCorreo === $clave; ?>
                    <a href="<?= $baseUrl ?>/correo?modo=<?= $clave ?>"
                       class="btn <?= $actual ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                       style="white-space:nowrap;"
                       <?= $actual ? 'aria-current="page"' : '' ?>>
                        <i class="fas <?= $icono ?>"></i> <?= $etiqueta ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php /*
                 * Comprobantes sin código: el botón vive aquí, al lado de los
                 * modos, porque no pertenece a ninguno de los dos. Estos avisos
                 * se sacaron de la campana —ver Notificacion::FUERA_DE_LA_CAMPANA—
                 * porque llegan a decenas y tapaban lo que sí urgía; el trabajo
                 * de catalogarlos se hace de corrido, mirando la lista entera,
                 * y por eso tiene su propia puerta en la pantalla donde
                 * aparecen. El panel que abre está en app/views/correo/index.php.
                 */
                if (isset($modoCorreo) && strpos($uriClean, '/correo') !== false
                    && $puedeVerMenu('avisos.ver')): ?>
                <button type="button" id="btn-sin-codigo" class="btn btn-outline btn-sm"
                        style="white-space:nowrap;"
                        title="Comprobantes que llegaron de proveedores que el sistema todavía no conoce">
                    <i class="fas fa-user-tag"></i> Comprobantes sin código
                    <span id="sin-codigo-badge" class="badge badge-navy"
                          style="font-size:9px;padding:1px 5px;margin-left:2px;<?= (int) ($sinCodigoPendientes ?? 0) > 0 ? '' : 'display:none;' ?>">
                        <?= (int) ($sinCodigoPendientes ?? 0) ?>
                    </span>
                </button>
                <?php endif; ?>

                <?php /*
                 * El modo de Seguimiento, en el mismo sitio y por la misma
                 * razón que el de Correo: no es un filtro dentro de la cola,
                 * es de qué cola se está hablando. Sistema persigue lo que le
                 * falta a los registros del ERP; Correo persigue lo contrario,
                 * los comprobantes XML que todavía no están en el ERP.
                 *
                 * Cambiar de modo no arrastra los filtros: cada uno recuerda
                 * los suyos, y un tipo de documento del otro modo vaciaría la
                 * lista sin nada en pantalla que lo explicara.
                 */
                if (isset($modoSeguimiento) && strpos($uriClean, '/seguimiento') !== false): ?>
                <div style="display:flex;align-items:center;gap:5px;padding-left:9px;
                            border-left:1px solid var(--border-light);"
                     title="Sistema: qué le falta a las facturas que ya están registradas. Correo: qué comprobantes llegaron y todavía no están registrados.">
                    <?php foreach ([
                        'sistema' => ['fa-database',           'Sistema'],
                        'correo'  => ['fa-envelope-open-text', 'Correo'],
                    ] as $claveSeg => [$iconoSeg, $etiquetaSeg]):
                        $actualSeg = $modoSeguimiento === $claveSeg; ?>
                    <a href="<?= $baseUrl ?>/seguimiento?modo=<?= $claveSeg ?>"
                       class="btn <?= $actualSeg ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                       style="white-space:nowrap;"
                       <?= $actualSeg ? 'aria-current="page"' : '' ?>>
                        <i class="fas <?= $iconoSeg ?>"></i> <?= $etiquetaSeg ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="topbar-right">
                <?php
                // La configuración estaba solo en Correo, que es donde vivía el
                // modal, y quien necesitaba cambiar la carpeta raíz desde
                // cualquier otra pantalla tenía que saber que se guardaba ahí.
                // No es una opción del correo: la carpeta de XML y PDF, las
                // empresas o el respaldo los usan todos los módulos. Ahora es
                // una pantalla propia, /configuracion, y este botón lleva a
                // ella desde donde sea.
                $enConfiguracion = navCoincide('configuracion', $uriClean);
                ?>
                <?php if (!empty($_SESSION['user_id'])): ?>

                <?php // Descarga de correos en curso. Va en la barra de todas
                      // las pantallas porque el trabajo es del sistema, no de
                      // la pantalla que lo empezó: irse a otro módulo o
                      // cambiar de buzón ya no lo detiene ni lo esconde.
                      // Fuera del modo Descargas este renglón además lo hace
                      // avanzar; dentro de él manda el motor de esa pantalla.
                      //
                      // Solo para quien tenga correo: sin permiso, su latido
                      // chocaría con la guardia del enrutador cada minuto, en
                      // todas las pantallas, para no enseñar nada.
                $enModoDescargas = isset($modoCorreo) && $modoCorreo === 'descargas'
                                   && strpos($uriClean, '/correo') !== false; ?>
                <?php if ($puedeVerMenu('correo.ver')): ?>
                <a href="<?= $baseUrl ?>/correo?modo=descargas" id="descargaChip"
                   class="topbar-action" style="display:none;align-items:center;gap:6px;
                          font-size:11.5px;color:var(--navy);text-decoration:none;font-weight:600;
                          background:#eef3fb;border:1px solid #c3d0e8;border-radius:7px;padding:3px 8px;"
                   title="Descarga de correos en curso">
                    <i class="fas fa-cloud-arrow-down"></i>
                    <span id="descargaChipTexto">Descargando…</span>
                    <span style="width:52px;height:5px;background:#d7e0f0;border-radius:6px;overflow:hidden;">
                        <span id="descargaChipBarra" style="display:block;height:100%;width:0%;
                              background:linear-gradient(90deg,#0c2461,#1e3a8a);transition:width .3s;"></span>
                    </span>
                </a>

                <script>
                (function () {
                    var chip  = document.getElementById('descargaChip');
                    var texto = document.getElementById('descargaChipTexto');
                    var barra = document.getElementById('descargaChipBarra');
                    if (!chip) return;

                    var base = <?= json_encode($baseUrl) ?>;
                    // En el modo Descargas solo se mira: esa pantalla ya tiene
                    // su propio bucle y dos motores abrirían dos conexiones.
                    var soloMirar = <?= $enModoDescargas ? 'true' : 'false' ?>;
                    var SIN_TRABAJO_MS = 60000; // nada que hacer: preguntar de vez en cuando
                    var enVuelo = false;
                    var reloj = null;

                    function programar(ms) {
                        clearTimeout(reloj);
                        reloj = setTimeout(latido, ms);
                    }

                    function pintar(lote) {
                        if (!lote) {
                            chip.style.display = 'none';
                            return;
                        }
                        var total = Number(lote.total_mensajes || 0);
                        var hechos = Number(lote.procesados || 0);
                        var pct = total ? Math.min(100, Math.round(hechos * 100 / total)) : 0;
                        chip.style.display = 'flex';
                        texto.textContent = hechos.toLocaleString('es-CR') + '/'
                                          + total.toLocaleString('es-CR') + ' correos';
                        barra.style.width = pct + '%';
                        chip.title = 'Descarga de correos en curso — lote #' + lote.id
                                   + (lote.cuenta_nombre ? ' · ' + lote.cuenta_nombre : '')
                                   + ' · ' + pct + '%. Clic para verla.';
                    }

                    function latido() {
                        clearTimeout(reloj);
                        if (enVuelo) return;
                        enVuelo = true;
                        var fd = new FormData();
                        fd.append('avanzar', soloMirar ? '0' : '1');
                        fetch(base + '/correo/lotes/latido', {
                            method: 'POST', body: fd, credentials: 'same-origin'
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                enVuelo = false;
                                pintar(d && d.ok ? d.lote : null);
                                // Con trabajo en curso se encadena de una vez:
                                // cada viaje ya trae dentro su tanda de correos.
                                // Si el buzón no contestó, se espera: insistir
                                // contra una red caída no adelanta nada.
                                var hayTrabajo = d && d.ok && d.lote && !d.error;
                                programar(hayTrabajo ? 1200 : SIN_TRABAJO_MS);
                            })
                            .catch(function () {
                                // Si la base o el buzón no contestan, el
                                // renglón calla y se vuelve a preguntar luego.
                                enVuelo = false;
                                programar(SIN_TRABAJO_MS);
                            });
                    }

                    latido();
                    // Al volver a la pestaña, retomar sin esperar el turno: el
                    // navegador frena los temporizadores en segundo plano.
                    document.addEventListener('visibilitychange', function () {
                        if (!document.hidden) latido();
                    });
                })();
                </script>
                <?php endif; ?>

                <?php if ($puedeVerMenu('avisos.ver')): ?>
                <!-- Campana de avisos. Va junto al engranaje porque son la
                     misma clase de cosa: no pertenecen a la pantalla que se
                     está mirando, sino al sistema.
                     Se pide en una sola petición aparte, y no dentro del PHP
                     de esta plantilla, para que una base lenta o caída no
                     retrase el pintado de TODAS las pantallas por culpa de un
                     contador. Si no contesta, la campana calla. -->
                <div class="avisos-wrap">
                    <button type="button" id="avisosBtn" class="avisos-btn"
                            aria-haspopup="true" aria-expanded="false"
                            title="Avisos que esperan una decisión">
                        <i class="fas fa-bell"></i>
                        <span class="avisos-badge" id="avisosBadge" style="display:none;">0</span>
                    </button>

                    <div class="avisos-panel" id="avisosPanel" role="dialog" aria-label="Avisos">
                        <div class="avisos-panel-head">
                            <span>Avisos</span>
                            <a href="<?= $baseUrl ?>/avisos" style="font-size:11px;color:var(--navy-light,#2254BD);text-decoration:none;">Ver todos</a>
                        </div>
                        <div id="avisosLista">
                            <div class="avisos-vacio">Cargando…</div>
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    var btn   = document.getElementById('avisosBtn');
                    var panel = document.getElementById('avisosPanel');
                    var lista = document.getElementById('avisosLista');
                    var badge = document.getElementById('avisosBadge');
                    if (!btn || !panel) return;

                    var base = <?= json_encode($baseUrl) ?>;
                    var cargado = false;

                    function esc(t) {
                        var d = document.createElement('div');
                        d.textContent = t == null ? '' : String(t);
                        return d.innerHTML;
                    }

                    function pintar(datos) {
                        var n = parseInt(datos.pendientes, 10) || 0;
                        // Una campana que siempre muestra un número deja de
                        // significar algo: en cero no se pinta nada.
                        badge.style.display = n > 0 ? 'block' : 'none';
                        badge.textContent = n > 99 ? '99+' : n;
                        btn.classList.toggle('tiene-pendientes', n > 0);

                        var avisos = datos.avisos || [];
                        if (!avisos.length) {
                            lista.innerHTML = '<div class="avisos-vacio">'
                                + '<i class="fas fa-check-circle" style="font-size:20px;display:block;margin-bottom:7px;color:#38a169;"></i>'
                                + 'Nada pendiente.</div>';
                            return;
                        }

                        var html = '';
                        avisos.forEach(function (a) {
                            var sev = a.severidad || 'media';
                            var ico = sev === 'alta' ? 'fa-triangle-exclamation' : 'fa-circle-info';
                            html += '<div class="aviso-item">'
                                  +   '<i class="fas ' + ico + ' aviso-icono ' + esc(sev) + '"></i>'
                                  +   '<div class="aviso-cuerpo">'
                                  +     '<div class="aviso-titulo">' + esc(a.titulo) + '</div>'
                                  +     (a.detalle ? '<div class="aviso-detalle">' + esc(a.detalle) + '</div>' : '')
                                  +     (parseInt(a.veces, 10) > 1
                                          ? '<div class="aviso-meta">Ocurrió ' + esc(a.veces) + ' veces</div>' : '')
                                  +   '</div>'
                                  + '</div>';
                        });
                        html += '<div class="avisos-panel-pie">'
                              + '<a href="' + base + '/avisos" style="color:var(--navy-light,#2254BD);text-decoration:none;">Revisar y decidir</a>'
                              + '</div>';
                        lista.innerHTML = html;
                    }

                    function cargar() {
                        fetch(base + '/avisos/resumen', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function (r) { return r.json(); })
                            .then(pintar)
                            .catch(function () {
                                // Si la base no contesta, la campana calla. No
                                // es el trabajo de un aviso romper la pantalla.
                                lista.innerHTML = '<div class="avisos-vacio">No se pudieron cargar los avisos.</div>';
                            });
                    }

                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        var abierto = panel.classList.toggle('abierto');
                        btn.setAttribute('aria-expanded', abierto ? 'true' : 'false');
                        if (abierto && !cargado) { cargado = true; cargar(); }
                    });

                    document.addEventListener('click', function (e) {
                        if (!panel.contains(e.target) && !btn.contains(e.target)) {
                            panel.classList.remove('abierto');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape') {
                            panel.classList.remove('abierto');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });

                    // Una sola petición trae el número y los renglones: el
                    // número hay que tenerlo siempre —es lo que dice si hay
                    // que mirar— y traer los ocho de arriba con él no cuesta
                    // otro viaje.
                    cargar();
                })();
                </script>
                <?php endif; ?>

                <?php if ($puedeConfiguracion): ?>
                <a href="<?= $baseUrl ?>/configuracion"
                   class="topbar-action"
                   style="font-size:11.5px;color:#0C2461;text-decoration:none;background:<?= $enConfiguracion ? '#eef3fb' : 'transparent' ?>;display:flex;align-items:center;gap:4px;padding:3px 8px;border:1px solid #c3d0e8;border-radius:7px;font-weight:600;transition:background .2s;"
                   onmouseover="this.style.background='#eef3fb'"
                   onmouseout="this.style.background='<?= $enConfiguracion ? '#eef3fb' : 'transparent' ?>'"
                   <?= $enConfiguracion ? 'aria-current="page"' : '' ?>
                   title="Configuración: carpeta de XML y PDF, cuentas de correo, empresas, usuarios y roles">
                    <i class="fas fa-gear"></i>
                </a>
                <?php endif; ?>
                <?php if ($sociedadActiva): ?>
                <span class="topbar-society" style="font-size:11.5px;color:var(--navy);background:var(--gold-pale);border:1px solid var(--gold-light);border-radius:999px;padding:3px 8px;display:flex;align-items:center;gap:5px;font-weight:600;"
                      title="Sociedad activa">
                    <i class="fas fa-building" style="color:var(--gold-dark);font-size:13px;"></i>
                    <?= htmlspecialchars($sociedadActiva['nombre']) ?>
                </span>
                <?php else: ?>
                <?php // Sin empresa las pantallas salen vacías a propósito: no
                      // se sabe de quién son los documentos, así que no se
                      // muestra ninguno. Antes se mostraban todos. Si no se
                      // dice aquí, la aplicación parece rota en vez de
                      // acotada, y el aviso va en la barra y no en cada
                      // pantalla porque el problema es el mismo en todas. ?>
                <span class="topbar-society" style="font-size:11.5px;color:#9a3412;background:#fff7ed;border:1px solid #fdba74;border-radius:999px;padding:3px 8px;display:flex;align-items:center;gap:5px;font-weight:600;"
                      title="No hay ninguna empresa seleccionada, así que los listados salen vacíos. Elegí una en Configuración → Empresas.">
                    <i class="fas fa-triangle-exclamation" style="color:#c2410c;font-size:13px;"></i>
                    Sin empresa
                </span>
                <?php endif; ?>
                <?php // El rol se lee junto al nombre: saber con qué permisos
                      // se está trabajando explica por qué un botón no está,
                      // sin tener que ir a preguntar. ?>
                <span class="topbar-user" style="font-size:11.5px;color:#4a5568;display:flex;align-items:center;gap:5px;"
                      title="Tu rol: <?= htmlspecialchars(Permisos::nombreRol()) ?>">
                    <i class="fas fa-user-circle" style="color:#0C2461;font-size:14px;"></i>
                    <?= htmlspecialchars($_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? '') ?>
                    <span style="color:#94A3B8;font-size:10.5px;">· <?= htmlspecialchars(Permisos::nombreRol()) ?></span>
                </span>
                <a href="<?= $baseUrl ?>/logout"
                   class="topbar-action"
                   style="font-size:11.5px;color:#e53e3e;text-decoration:none;display:flex;align-items:center;gap:4px;padding:3px 8px;border:1px solid #fed7d7;border-radius:7px;font-weight:600;transition:background .2s;"
                   onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background='transparent'"
                   title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Flash messages -->
        <?php
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (isset($_SESSION['flash_message'])):
            $flash  = $_SESSION['flash_message'];
            $fType  = $flash['type'] ?? 'info';
            $fMsg   = $flash['message'] ?? '';
            $det    = is_array($flash['details'] ?? null) ? $flash['details'] : [];

            $extractList = function ($key) use ($det) {
                if (empty($det[$key]) || !is_array($det[$key])) return [];
                return array_values(array_unique(array_filter(array_map('strval', $det[$key]))));
            };

            $failedFiles    = $extractList('failed_files');
            $duplicateFiles = $extractList('duplicate_files');
            $serverWarning  = trim((string) ($det['server_limit_warning'] ?? ''));

            /*
             * Lista con título propio. Las dos de arriba nacieron para la
             * carga de archivos y llevan su título puesto ("Ya existían", "Con
             * error"); esta la titula quien la manda, porque no todo lo que
             * hay que enumerar es un archivo que falló. La usa el pago
             * para nombrar las facturas que no llegaron a su carpeta.
             */
            $avisoLista   = is_array($det['aviso_lista'] ?? null) ? $det['aviso_lista'] : [];
            $avisoTitulo  = trim((string) ($avisoLista['titulo'] ?? ''));
            $avisoItems   = is_array($avisoLista['items'] ?? null)
                ? array_values(array_filter(array_map('strval', $avisoLista['items'])))
                : [];
            if ($avisoTitulo === '') { $avisoItems = []; }

            $icons  = ['success' => 'fa-check-circle', 'error' => 'fa-times-circle', 'warning' => 'fa-exclamation-triangle', 'info' => 'fa-info-circle'];
            $titles = ['success' => 'Listo', 'error' => 'Error', 'warning' => 'Atención', 'info' => 'Información'];

            // Error/warning stays longer, success/info dismisses after 7s
            $duration = in_array($fType, ['error', 'warning']) ? 10000 : 7000;

            unset($_SESSION['flash_message']);
        ?>
        <div id="flash-toast-container" aria-live="polite">
            <div id="flash-toast" class="flash-toast <?= htmlspecialchars($fType) ?>"
                 data-duration="<?= $duration ?>" role="status">
                <div class="flash-toast-inner">
                    <div class="flash-toast-icon">
                        <i class="fas <?= $icons[$fType] ?? 'fa-info-circle' ?>"></i>
                    </div>
                    <div class="flash-toast-content">
                        <div class="flash-toast-title"><?= htmlspecialchars($titles[$fType] ?? 'Mensaje') ?></div>
                        <div class="flash-toast-msg"><?= htmlspecialchars($fMsg) ?></div>
                    </div>
                    <button type="button" class="flash-toast-close" onclick="dismissToast()" aria-label="Cerrar">&times;</button>
                </div>
                <?php if ($serverWarning !== '' || !empty($duplicateFiles) || !empty($failedFiles) || !empty($avisoItems)): ?>
                <div class="flash-toast-extra">
                    <?php if ($serverWarning !== ''): ?>
                    <div class="flash-toast-warning-box">
                        <i class="fas fa-exclamation-triangle" style="margin-right:5px;"></i><?= htmlspecialchars($serverWarning) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($duplicateFiles)): ?>
                    <div class="flash-toast-list-wrap">
                        <div class="flash-toast-list-title">Ya existían (<?= count($duplicateFiles) ?>)</div>
                        <ul class="flash-toast-list">
                            <?php foreach ($duplicateFiles as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($failedFiles)): ?>
                    <div class="flash-toast-list-wrap">
                        <div class="flash-toast-list-title">Con error (<?= count($failedFiles) ?>)</div>
                        <ul class="flash-toast-list">
                            <?php foreach ($failedFiles as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($avisoItems)): ?>
                    <div class="flash-toast-list-wrap">
                        <div class="flash-toast-list-title"><?= htmlspecialchars($avisoTitulo) ?></div>
                        <ul class="flash-toast-list">
                            <?php foreach ($avisoItems as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="flash-toast-progress">
                    <div class="flash-toast-progress-bar" id="flash-progress-bar"></div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var toast    = document.getElementById('flash-toast');
            var bar      = document.getElementById('flash-progress-bar');
            var duration = parseInt(toast.dataset.duration, 10) || 5000;
            var timer;

            function dismissToast() {
                clearTimeout(timer);
                toast.classList.remove('show');
                toast.classList.add('hide');
            }
            window.dismissToast = dismissToast;

            // Slide in
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    toast.classList.add('show');
                    // Progress bar shrink
                    bar.style.transition = 'transform ' + duration + 'ms linear';
                    bar.style.transform  = 'scaleX(0)';
                    timer = setTimeout(dismissToast, duration);
                });
            });

            // Pause on hover
            toast.addEventListener('mouseenter', function () {
                clearTimeout(timer);
                bar.style.transition = 'none';
            });
            toast.addEventListener('mouseleave', function () {
                var remaining = parseFloat(bar.style.transform.replace('scaleX(', '')) || 0;
                var timeLeft  = remaining * duration;
                bar.style.transition = 'transform ' + timeLeft + 'ms linear';
                bar.style.transform  = 'scaleX(0)';
                timer = setTimeout(dismissToast, timeLeft);
            });
        })();
        </script>
        <?php endif; ?>

        <!-- Contenido de la página -->
        <main class="page-content">
