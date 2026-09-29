<?php
/**
 * "No tienes permiso para esta pantalla."
 *
 * Es una página aparte y no un redirect silencioso a Inicio a propósito: si
 * alguien pulsa un enlace y aparece en otro sitio sin explicación, lo lee como
 * que el sistema falla y vuelve a intentarlo tres veces. Aquí se dice qué
 * pasó, qué permiso falta y a quién pedírselo.
 *
 * La pinta Permisos::rechazar(), que le pasa $permisoFaltante y $baseUrl.
 */
$permisoFaltante = $permisoFaltante ?? '';
$baseUrl = $baseUrl ?? (defined('APP_URL') ? APP_URL : '/xmlconcilia/public');
$queHaceFalta = $permisoFaltante !== '' ? Permisos::etiqueta($permisoFaltante) : '';
$quienEres = $_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin permiso — Nexo Fiscal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            max-width: 520px;
            width: 100%;
        }

        .error-icon  { font-size: 44px; margin-bottom: 8px; }
        .error-code  { font-size: 60px; font-weight: 700; color: #0C2461; line-height: 1; margin-bottom: 10px; }
        .error-title { font-size: 21px; color: #0C2461; margin-bottom: 8px; font-weight: 600; }

        .error-message {
            font-size: 13.5px;
            color: #5A6E8A;
            margin-bottom: 16px;
            line-height: 1.55;
        }

        .permiso {
            display: inline-block;
            background: #F7FAFF;
            border: 1px solid #DDE6F0;
            border-radius: 8px;
            padding: 9px 13px;
            margin-bottom: 18px;
            font-size: 12.5px;
            color: #1a2b4a;
            text-align: left;
        }

        .permiso strong { display: block; font-size: 11px; text-transform: uppercase;
                          letter-spacing: .5px; color: #5A6E8A; margin-bottom: 3px; }

        .acciones { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }

        .btn-home, .btn-back {
            display: inline-block;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 13px;
            transition: all .2s ease;
            cursor: pointer;
            border: none;
        }

        .btn-home { background: #0C2461; color: white; }
        .btn-home:hover { background: #173E8A; }

        .btn-back { background: white; color: #0C2461; border: 1.5px solid #DDE6F0; }
        .btn-back:hover { background: #F7FAFF; }

        .quien { margin-top: 16px; font-size: 11.5px; color: #94A3B8; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">🔒</div>
        <div class="error-code">403</div>
        <h1 class="error-title">Esta pantalla no es para tu rol</h1>
        <p class="error-message">
            Tu rol no incluye esta parte del sistema.
        </p>

        <?php if ($queHaceFalta !== ''): ?>
        <div class="permiso">
            <strong>Permiso que hace falta</strong>
            <?= htmlspecialchars($queHaceFalta) ?>
        </div>
        <?php endif; ?>

        <p class="error-message">
            Si lo necesitas, pídele el permiso al administrador.
        </p>

        <div class="acciones">
            <a href="<?= htmlspecialchars($baseUrl) ?>/" class="btn-home">Ir al inicio</a>
            <a href="javascript:history.back()" class="btn-back">Volver atrás</a>
        </div>

        <?php if ($quienEres !== ''): ?>
        <div class="quien">
            Entraste como <?= htmlspecialchars($quienEres) ?>
            · rol <?= htmlspecialchars(Permisos::nombreRol()) ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
