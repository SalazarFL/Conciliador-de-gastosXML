<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
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
    'por-pagar'    => 'Pagos semanales',
    'notas-credito'=> 'Notas de crédito',
    // Devoluciones sigue teniendo su título: la pantalla existe y se llega
    // por la URL, lo que se retiró es la entrada del menú.
    'devoluciones' => 'Devoluciones',
    'notas-xml'    => 'Notas de crédito · Carga XML',
    'usuarios'     => 'Gestión de Usuarios',
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
            <div class="sidebar-section-label">Trabajo del día</div>

            <a href="<?= $baseUrl ?>/seguimiento" class="<?= navActive('seguimiento', $uriClean) ?>"
               title="Todo lo que le falta respaldo o no cuadra, en una sola lista">
                <span class="nav-icon"><i class="fas fa-list-check"></i></span>
                <span class="nav-label">Seguimiento</span>
            </a>

            <div class="sidebar-section-label" style="margin-top:16px;">Módulos</div>

            <a href="<?= $baseUrl ?>/por-pagar" class="<?= navActive('por-pagar', $uriClean) ?>" title="Pagos semanales">
                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="nav-label">Pagos semanales</span>
            </a>

            <a href="<?= $baseUrl ?>/notas-credito"
               class="<?= navCoincide('notas-credito', $uriClean) || navCoincide('notas-xml', $uriClean) ? 'active' : '' ?>"
               title="Notas de crédito y carga de comprobantes XML">
                <span class="nav-icon"><i class="fas fa-file-circle-minus"></i></span>
                <span class="nav-label">Notas de crédito</span>
            </a>

            <?php /*
             * Devoluciones no se ofrece por ahora: el módulo está completo y
             * su ruta responde, pero todavía no se presenta como parte del
             * trabajo. Se retiró la entrada del menú, no la pantalla — para
             * volver a ofrecerlo basta con devolver este enlace.
             *
             *   <a href="/devoluciones" ...>Devoluciones</a>
             */ ?>

            <a href="<?= $baseUrl ?>/correo" class="<?= navActive('correo', $uriClean) ?>" title="Correo">
                <span class="nav-icon"><i class="fas fa-envelope-open-text"></i></span>
                <span class="nav-label">Correo</span>
            </a>

            <a href="<?= $baseUrl ?>/facturas-erp"
               class="<?= navCoincide('facturas-erp', $uriClean) || navCoincide('facturas', $uriClean) ? 'active' : '' ?>"
               title="Las facturas de la empresa y la carga de sus comprobantes XML">
                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="nav-label">Facturas</span>
            </a>

            <div class="sidebar-section-label" style="margin-top:16px;">Administración</div>
            <?php // Todo lo que se ajusta vive en una sola pantalla; el
                  // engranaje de la barra superior lleva a la misma. ?>
            <a href="<?= $baseUrl ?>/configuracion" class="<?= navActive('configuracion', $uriClean) ?>"
               title="Carpeta de XML y PDF, cuentas de correo, empresas y respaldo">
                <span class="nav-icon"><i class="fas fa-gear"></i></span>
                <span class="nav-label">Configuración</span>
            </a>
            <?php if (!empty($_SESSION['user_is_admin'])): ?>
            <a href="<?= $baseUrl ?>/diagnostico" class="<?= navActive('diagnostico', $uriClean) ?>"
               title="Revisar la instalación de esta computadora">
                <span class="nav-icon"><i class="fas fa-stethoscope"></i></span>
                <span class="nav-label">Diagnóstico</span>
            </a>
            <?php endif; ?>
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

                <a href="<?= $baseUrl ?>/configuracion"
                   class="topbar-action"
                   style="font-size:11.5px;color:#0C2461;text-decoration:none;background:<?= $enConfiguracion ? '#eef3fb' : 'transparent' ?>;display:flex;align-items:center;gap:4px;padding:3px 8px;border:1px solid #c3d0e8;border-radius:7px;font-weight:600;transition:background .2s;"
                   onmouseover="this.style.background='#eef3fb'"
                   onmouseout="this.style.background='<?= $enConfiguracion ? '#eef3fb' : 'transparent' ?>'"
                   <?= $enConfiguracion ? 'aria-current="page"' : '' ?>
                   title="Configuración: carpeta de XML y PDF, cuentas de correo, empresas y respaldo">
                    <i class="fas fa-gear"></i>
                </a>
                <?php if ($sociedadActiva): ?>
                <span class="topbar-society" style="font-size:11.5px;color:var(--navy);background:var(--gold-pale);border:1px solid var(--gold-light);border-radius:999px;padding:3px 8px;display:flex;align-items:center;gap:5px;font-weight:600;"
                      title="Sociedad activa">
                    <i class="fas fa-building" style="color:var(--gold-dark);font-size:13px;"></i>
                    <?= htmlspecialchars($sociedadActiva['nombre']) ?>
                </span>
                <?php endif; ?>
                <span class="topbar-user" style="font-size:11.5px;color:#4a5568;display:flex;align-items:center;gap:5px;">
                    <i class="fas fa-user-circle" style="color:#0C2461;font-size:14px;"></i>
                    <?= htmlspecialchars($_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? '') ?>
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
                <?php if ($serverWarning !== '' || !empty($duplicateFiles) || !empty($failedFiles)): ?>
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
