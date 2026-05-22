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

// Determinar el título de la página actual para el topbar
$pageLabels = [
    'facturas'     => ['Carga de Facturas XML',  ''],
    'gastos'       => ['Carga de Gastos CSV',     ''],
    'conciliacion' => ['Conciliación',             ''],
    'reportes'     => ['Reportes y Exportación',  ''],
    'usuarios'     => ['Gestión de Usuarios',      'Administra las cuentas de acceso al sistema'],
];

$currentLabel    = ['Panel Principal', ''];
foreach ($pageLabels as $seg => $labels) {
    if (strpos($uriClean, '/' . $seg) !== false) {
        $currentLabel = $labels;
        break;
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
                <div style="font-size:22px;font-weight:800;letter-spacing:.5px;line-height:1.1;text-align:center;">
                    <span style="color:var(--gold);">XML</span><span style="color:#fff;"> Concilia</span>
                </div>
            </a>
            <div class="sidebar-logo-subtitle">Sistema de Conciliación</div>
        </div>

        <!-- Navegación -->
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Módulos</div>

            <a href="<?= $baseUrl ?>/facturas" class="<?= navActive('facturas', $uriClean) ?>">
                <span class="nav-icon"><i class="fas fa-file-invoice"></i></span>
                Carga de Facturas XML
            </a>

            <a href="<?= $baseUrl ?>/gastos" class="<?= navActive('gastos', $uriClean) ?>">
                <span class="nav-icon"><i class="fas fa-file-csv"></i></span>
                Carga de Gastos CSV
            </a>

            <a href="<?= $baseUrl ?>/conciliacion" class="<?= navActive('conciliacion', $uriClean) ?>">
                <span class="nav-icon"><i class="fas fa-check-double"></i></span>
                Conciliación
            </a>

            <a href="<?= $baseUrl ?>/reportes" class="<?= navActive('reportes', $uriClean) ?>">
                <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                Reportes
            </a>

            <?php if (!empty($_SESSION['user_is_admin'])): ?>
            <div class="sidebar-section-label" style="margin-top:16px;">Administración</div>
            <a href="<?= $baseUrl ?>/usuarios" class="<?= navActive('usuarios', $uriClean) ?>">
                <span class="nav-icon"><i class="fas fa-users-cog"></i></span>
                Usuarios
            </a>
            <?php endif; ?>
        </nav>

        <!-- Footer del sidebar -->
        <div class="sidebar-bottom">
            <a href="<?= $baseUrl ?>/" title="Ir al inicio">
                <span class="nav-icon"><i class="fas fa-house"></i></span>
                Inicio
            </a>
        </div>

    </aside>
    <!-- ── /sidebar ── -->

    <!-- ══ MAIN ═══════════════════════════════════════ -->
    <div class="app-main">

        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <h2><?= htmlspecialchars($currentLabel[0]) ?></h2>
                <?php if (!empty($currentLabel[1])): ?>
                <p><?= htmlspecialchars($currentLabel[1]) ?></p>
                <?php endif; ?>
            </div>
            <div class="topbar-right" style="display:flex;align-items:center;gap:14px;">
                <?php if (!empty($_SESSION['user_id'])): ?>
                <a href="<?= $baseUrl ?>/assets/docs/<?= rawurlencode('Manual Sistema de Conciliación.pdf') ?>"
                   target="_blank"
                   style="font-size:12px;color:#0C2461;text-decoration:none;display:flex;align-items:center;gap:5px;padding:5px 12px;border:1.5px solid #c3d0e8;border-radius:8px;font-weight:600;transition:background .2s;"
                   onmouseover="this.style.background='#eef3fb'" onmouseout="this.style.background='transparent'"
                   title="Abrir manual de usuario">
                    <i class="fas fa-book"></i> Manual
                </a>
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

        if (isset($_SESSION['flash_message'])) {
            $flash  = $_SESSION['flash_message'];
            $type   = $flash['type'] ?? 'info';
            $msg    = $flash['message'] ?? '';
            $det    = is_array($flash['details'] ?? null) ? $flash['details'] : [];

            $extractList = function ($key) use ($det) {
                if (empty($det[$key]) || !is_array($det[$key])) return [];
                return array_values(array_unique(array_filter(array_map('strval', $det[$key]))));
            };

            $failedFiles    = $extractList('failed_files');
            $duplicateFiles = $extractList('duplicate_files');
            $serverWarning  = trim((string) ($det['server_limit_warning'] ?? ''));

            $icons  = ['success'=>'fa-check-circle','error'=>'fa-exclamation-circle','warning'=>'fa-exclamation-triangle','info'=>'fa-info-circle'];
            $titles = ['success'=>'Importación completada','error'=>'Error en importación','warning'=>'Importación con observaciones','info'=>'Información'];

            echo "<div id='flash-toast' class='flash-toast {$type}' role='status' aria-live='polite'>";
            echo "<div class='flash-toast-header'>";
            echo   "<div class='flash-toast-title'><i class='fas {$icons[$type]}'></i><span>" . htmlspecialchars($titles[$type] ?? 'Mensaje') . "</span></div>";
            echo   "<button type='button' class='flash-toast-close' onclick='document.getElementById(\"flash-toast\").style.display=\"none\"' aria-label='Cerrar'>&times;</button>";
            echo "</div>";
            echo "<div class='flash-toast-body'>";
            echo "<div>" . htmlspecialchars($msg) . "</div>";

            if ($serverWarning !== '') {
                echo "<div style='margin-top:9px;padding:7px 10px;border-radius:8px;background:rgba(0,0,0,.06);font-size:12px;'>⚠ " . htmlspecialchars($serverWarning) . "</div>";
            }

            if (!empty($duplicateFiles)) {
                echo "<div class='flash-toast-list-wrap'><div class='flash-toast-list-title'>Ya importados (" . count($duplicateFiles) . ")</div><ul class='flash-toast-list'>";
                foreach ($duplicateFiles as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
                echo "</ul></div>";
            }

            if (!empty($failedFiles)) {
                echo "<div class='flash-toast-list-wrap'><div class='flash-toast-list-title'>Con error (" . count($failedFiles) . ")</div><ul class='flash-toast-list'>";
                foreach ($failedFiles as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
                echo "</ul></div>";
            }

            echo "</div></div>";
            unset($_SESSION['flash_message']);
        }
        ?>

        <!-- Contenido de la página -->
        <main class="page-content">
