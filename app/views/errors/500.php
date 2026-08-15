<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Error Interno del Servidor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #EFF3FA;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
        }
        
        .error-container {
            background: white;
            border-radius: 12px;
            border-top: 4px solid #F0A500;
            box-shadow: 0 10px 36px rgba(12,36,97,.16);
            padding: 30px 28px;
            text-align: center;
            max-width: 600px;
            width: 100%;
        }
        
        .error-code {
            font-size: 76px;
            font-weight: 700;
            color: #0C2461;
            line-height: 1;
            margin-bottom: 10px;
        }
        
        .error-title {
            font-size: 22px;
            color: #0C2461;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .error-message {
            font-size: 13.5px;
            color: #5A6E8A;
            margin-bottom: 12px;
            line-height: 1.5;
        }
        
        .error-details {
            background: #f8f9fa;
            border-left: 4px solid #f5576c;
            padding: 9px 11px;
            margin: 12px 0;
            text-align: left;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #d63031;
            overflow-x: auto;
        }
        
        .btn-home {
            display: inline-block;
            padding: 8px 15px;
            background: #0C2461;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-home:hover {
            background: #173E8A;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 87, 108, 0.4);
        }
        
        .error-icon {
            font-size: 44px;
            margin-bottom: 8px;
        }
        
        .help-text {
            font-size: 14px;
            color: #999;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <div class="error-code">500</div>
        <h1 class="error-title"><?= htmlspecialchars($title ?? 'Error Interno del Servidor') ?></h1>
        <p class="error-message">
            Ocurrió un error inesperado al procesar tu solicitud. 
            Nuestro equipo ha sido notificado y trabajará para solucionarlo.
        </p>
        
        <?php if (!empty($message)): ?>
        <div class="error-details">
            <?= nl2br(htmlspecialchars($message)) ?>
        </div>
        <?php endif; ?>
        
        <a href="<?= defined('APP_URL') ? APP_URL : '/xmlconcilia/public' ?>" class="btn-home">
            Volver al Inicio
        </a>
        
        <p class="help-text">
            Si el problema persiste, por favor contacta al administrador del sistema.
        </p>
    </div>
</body>
</html>
