<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($title ?? 'XMLConcilia — Arrendadora BM PZ S.A.') ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Estilos principales (ruta relativa al servidor para evitar problemas de puerto) -->
    <?php
    $_ap = rtrim(parse_url(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', PHP_URL_PATH), '/');
    $_cssFile = __DIR__ . '/../../../public/assets/css/styles.css';
    $_cssVer  = is_file($_cssFile) ? filemtime($_cssFile) : time();
    ?>
    <link rel="stylesheet" href="<?= $_ap ?>/assets/css/styles.css?v=<?= $_cssVer ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
</head>
<body>

<?php
$baseUrl    = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$uri        = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriClean   = rtrim($uri, '/');

function navActive(string $segment, string $uri): string {
    if ($segment === '') {
        return (str_ends_with($uri, '/public') || str_ends_with($uri, '/public/') || $uri === '' || $uri === '/') ? 'active' : '';
    }
    return (strpos($uri, '/' . $segment) !== false) ? 'active' : '';
}

// Título de la página actual para el topbar (sin descripción: solo el nombre)
$pageLabels = [
    'facturas'     => 'Carga de Facturas XML',
    'correo'       => 'Correo',
    'por-pagar'    => 'Facturas por pagar',
    'notas-credito'=> 'Notas de crédito',
    'devoluciones' => 'Devoluciones',
    'notas-xml'    => 'Notas XML',
    'gastos'       => 'Carga de Gastos CSV',
    'conciliacion' => 'Conciliación',
    'reportes'     => 'Reportes y Exportación',
    'usuarios'     => 'Gestión de Usuarios',
];

$currentLabel = 'Panel Principal';
foreach ($pageLabels as $seg => $label) {
    if (strpos($uriClean, '/' . $seg) !== false) {
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
                    <span style="color:var(--gold);">XML</span><span style="color:#fff;"> Concilia</span>
                </div>
                <div class="logo-mini" title="XML Concilia">
                    <span style="color:var(--gold);">X</span><span style="color:#fff;">C</span>
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
            <div class="sidebar-section-label">Módulos</div>

            <a href="<?= $baseUrl ?>/por-pagar" class="<?= navActive('por-pagar', $uriClean) ?>" title="Facturas por pagar">
                <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                <span class="nav-label">Facturas por pagar</span>
            </a>

            <a href="<?= $baseUrl ?>/notas-credito" class="<?= navActive('notas-credito', $uriClean) ?>" title="Notas de crédito">
                <span class="nav-icon"><i class="fas fa-file-circle-minus"></i></span>
                <span class="nav-label">Notas de crédito</span>
            </a>

            <a href="<?= $baseUrl ?>/devoluciones" class="<?= navActive('devoluciones', $uriClean) ?>" title="Devoluciones">
                <span class="nav-icon"><i class="fas fa-rotate-left"></i></span>
                <span class="nav-label">Devoluciones</span>
            </a>

            <a href="<?= $baseUrl ?>/correo" class="<?= navActive('correo', $uriClean) ?>" title="Correo">
                <span class="nav-icon"><i class="fas fa-envelope-open-text"></i></span>
                <span class="nav-label">Correo</span>
            </a>

            <a href="<?= $baseUrl ?>/facturas" class="<?= navActive('facturas', $uriClean) ?>" title="Carga de Facturas XML">
                <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                <span class="nav-label">Carga de Facturas XML</span>
            </a>

            <a href="<?= $baseUrl ?>/notas-xml" class="<?= navActive('notas-xml', $uriClean) ?>" title="Notas XML">
                <span class="nav-icon"><i class="fas fa-file-code"></i></span>
                <span class="nav-label">Notas XML</span>
            </a>

            <a href="<?= $baseUrl ?>/reportes" class="<?= navActive('reportes', $uriClean) ?>" title="Reportes">
                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                <span class="nav-label">Reportes</span>
            </a>

            <?php if (!empty($_SESSION['user_is_admin'])): ?>
            <div class="sidebar-section-label" style="margin-top:16px;">Administración</div>
            <a href="<?= $baseUrl ?>/usuarios" class="<?= navActive('usuarios', $uriClean) ?>" title="Usuarios">
                <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                <span class="nav-label">Usuarios</span>
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
            <div class="topbar-left">
                <h2><?= htmlspecialchars($currentLabel) ?></h2>
            </div>
            <div class="topbar-right" style="display:flex;align-items:center;gap:14px;">
                <?php if (!empty($_SESSION['user_id'])): ?>
                <?php if (strpos($uriClean, '/correo') !== false): ?>
                <button type="button" onclick="if (window.abrirConfigCorreo) window.abrirConfigCorreo()"
                        style="font-size:12px;color:#0C2461;background:transparent;cursor:pointer;display:flex;align-items:center;gap:5px;padding:5px 12px;border:1.5px solid #c3d0e8;border-radius:8px;font-weight:600;transition:background .2s;"
                        onmouseover="this.style.background='#eef3fb'" onmouseout="this.style.background='transparent'"
                        title="Configuración: carpeta destino y cédula de la empresa">
                    <i class="fas fa-gear"></i>
                </button>
                <?php endif; ?>
                <?php if ($sociedadActiva): ?>
                <span style="font-size:12px;color:var(--navy);background:var(--gold-pale);border:1px solid var(--gold-light);border-radius:999px;padding:5px 12px;display:flex;align-items:center;gap:6px;font-weight:600;"
                      title="Sociedad activa">
                    <i class="fas fa-building" style="color:var(--gold-dark);font-size:13px;"></i>
                    <?= htmlspecialchars($sociedadActiva['nombre']) ?>
                </span>
                <?php endif; ?>
                <span style="font-size:12px;color:#4a5568;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-user-circle" style="color:#0C2461;font-size:16px;"></i>
                    <?= htmlspecialchars($_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? '') ?>
                </span>
                <a href="<?= $baseUrl ?>/logout"
                   style="font-size:12px;color:#e53e3e;text-decoration:none;display:flex;align-items:center;gap:5px;padding:5px 12px;border:1.5px solid #fed7d7;border-radius:8px;font-weight:600;transition:background .2s;"
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
