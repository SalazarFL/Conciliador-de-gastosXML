<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($title ?? 'XMLConcilia - Sistema de Conciliación') ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Estilos personalizados -->
    <link rel="stylesheet" href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/assets/css/styles.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f6fa;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.5em;
            font-weight: bold;
            text-decoration: none;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-menu {
            display: flex;
            list-style: none;
            gap: 10px;
        }
        
        .navbar-menu a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 4px;
            transition: background 0.3s ease;
        }
        
        .navbar-menu a:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .navbar-menu a.active {
            background: rgba(255,255,255,0.3);
        }
        
        main {
            flex: 1;
            padding: 30px 0;
        }
        
        .flash-toast {
            position: fixed;
            top: 50%;
            left: 50%;
            right: auto;
            transform: translate(-50%, -50%);
            width: min(440px, calc(100vw - 24px));
            z-index: 2000;
            border-radius: 12px;
            box-shadow: 0 16px 32px rgba(0,0,0,0.18);
            border: 1px solid transparent;
            overflow: hidden;
        }

        .flash-toast-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            font-weight: 600;
        }

        .flash-toast-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .flash-toast-close {
            border: 0;
            background: transparent;
            color: inherit;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 6px;
        }

        .flash-toast-close:hover {
            background: rgba(0,0,0,0.08);
        }

        .flash-toast-body {
            padding: 10px 12px 12px;
            font-size: 14px;
        }

        .flash-toast-list-wrap {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed rgba(0,0,0,0.2);
        }

        .flash-toast-list-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }

        .flash-toast-list {
            max-height: 130px;
            overflow: auto;
            padding-left: 18px;
            margin: 0;
            font-size: 13px;
        }

        .flash-toast-list li {
            margin-bottom: 3px;
            word-break: break-word;
        }
        
        .flash-toast.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .flash-toast.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .flash-toast.warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .flash-toast.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        
        @media (max-width: 768px) {
            .navbar-content {
                flex-direction: column;
                gap: 15px;
            }
            
            .navbar-menu {
                flex-direction: column;
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/" class="navbar-brand">
                <i class="fas fa-file-invoice"></i>
                XMLConcilia
            </a>
            
            <ul class="navbar-menu">
                <li><a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/" <?= ($_SERVER['REQUEST_URI'] ?? '') == '/' ? 'class="active"' : '' ?>>
                    <i class="fas fa-home"></i> Inicio
                </a></li>
                <li><a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/conciliacion">
                    <i class="fas fa-table-columns"></i> Panel Único
                </a></li>
                <li><a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>/reportes">
                    <i class="fas fa-chart-bar"></i> Reportes
                </a></li>
            </ul>
        </div>
    </nav>
    
    <?php
    // Mostrar mensaje flash si existe
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        $type = $flash['type'] ?? 'info';
        $message = $flash['message'] ?? '';
        $details = is_array($flash['details'] ?? null) ? $flash['details'] : [];

        $extractList = function ($key) use ($details) {
            if (empty($details[$key]) || !is_array($details[$key])) return [];
            return array_values(array_unique(array_filter(array_map('strval', $details[$key]))));
        };

        $failedFiles     = $extractList('failed_files');
        $withoutTemplate = $extractList('without_template');
        $duplicateFiles  = $extractList('duplicate_files');
        $serverWarning   = trim((string) ($details['server_limit_warning'] ?? ''));

        $icons = [
            'success' => 'fa-check-circle',
            'error'   => 'fa-exclamation-circle',
            'warning' => 'fa-exclamation-triangle',
            'info'    => 'fa-info-circle',
        ];
        $titles = [
            'success' => 'Importación completada',
            'error'   => 'Error en importación',
            'warning' => 'Importación con observaciones',
            'info'    => 'Información',
        ];

        echo "<div id='flash-toast' class='flash-toast {$type}' role='status' aria-live='polite'>";
        echo "<div class='flash-toast-header'>";
        echo   "<div class='flash-toast-title'><i class='fas {$icons[$type]}'></i><span>" . htmlspecialchars($titles[$type] ?? 'Mensaje') . "</span></div>";
        echo   "<button type='button' class='flash-toast-close' onclick='closeFlashToast()' aria-label='Cerrar'>&times;</button>";
        echo "</div>";
        echo "<div class='flash-toast-body'>";

        // Mensaje principal con resumen numérico
        echo "<div style='font-size:14px;'>" . htmlspecialchars($message) . "</div>";

        // Aviso de límite del servidor (más llamativo)
        if ($serverWarning !== '') {
            echo "<div style='margin-top:10px;padding:8px 10px;border-radius:8px;background:rgba(0,0,0,0.07);font-size:12px;'>";
            echo "⚠ " . htmlspecialchars($serverWarning);
            echo "</div>";
        }

        // Archivos ya existentes (duplicados)
        if (!empty($duplicateFiles)) {
            echo "<div class='flash-toast-list-wrap'>";
            echo "<div class='flash-toast-list-title'>Ya importados anteriormente (" . count($duplicateFiles) . ")</div>";
            echo "<ul class='flash-toast-list'>";
            foreach ($duplicateFiles as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
            echo "</ul></div>";
        }

        // Sin plantilla de parseo (PDFs)
        if (!empty($withoutTemplate)) {
            echo "<div class='flash-toast-list-wrap'>";
            echo "<div class='flash-toast-list-title'>Sin plantilla PDF (" . count($withoutTemplate) . ")</div>";
            echo "<ul class='flash-toast-list'>";
            foreach ($withoutTemplate as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
            echo "</ul></div>";
        }

        // Errores reales de parseo/DB
        if (!empty($failedFiles)) {
            echo "<div class='flash-toast-list-wrap'>";
            echo "<div class='flash-toast-list-title'>Con error de importación (" . count($failedFiles) . ")</div>";
            echo "<ul class='flash-toast-list'>";
            foreach ($failedFiles as $f) echo "<li>" . htmlspecialchars($f) . "</li>";
            echo "</ul></div>";
        }

        echo "</div></div>";

        unset($_SESSION['flash_message']);
    }
    ?>

    <script>
        function closeFlashToast() {
            var toast = document.getElementById('flash-toast');
            if (toast) {
                toast.style.display = 'none';
            }
        }

    </script>
    
    <main>

