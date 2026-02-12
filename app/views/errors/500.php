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
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 60px 40px;
            text-align: center;
            max-width: 600px;
            width: 100%;
        }
        
        .error-code {
            font-size: 120px;
            font-weight: 700;
            color: #f5576c;
            line-height: 1;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 28px;
            color: #333;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .error-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .error-details {
            background: #f8f9fa;
            border-left: 4px solid #f5576c;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #d63031;
            overflow-x: auto;
        }
        
        .btn-home {
            display: inline-block;
            padding: 14px 32px;
            background: #f5576c;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .btn-home:hover {
            background: #d63031;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 87, 108, 0.4);
        }
        
        .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        
        .help-text {
            font-size: 14px;
            color: #999;
            margin-top: 20px;
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
