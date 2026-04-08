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
        
        .flash-message {
            max-width: 1200px;
            margin: 20px auto;
            padding: 15px 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .flash-message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .flash-message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .flash-message.warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .flash-message.info {
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
        
        $icons = [
            'success' => 'fa-check-circle',
            'error' => 'fa-exclamation-circle',
            'warning' => 'fa-exclamation-triangle',
            'info' => 'fa-info-circle'
        ];
        
        echo "<div class='flash-message {$type}'>";
        echo "<i class='fas {$icons[$type]}'></i>";
        echo htmlspecialchars($message);
        echo "</div>";
        
        unset($_SESSION['flash_message']);
    }
    ?>
    
    <main>

