<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — Nexo Fiscal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #e8eef7;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }

        .login-wrap { width: 100%; max-width: 390px; }

        .login-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 48px rgba(12,36,97,.14), 0 2px 8px rgba(12,36,97,.08);
            overflow: hidden;
        }

        /* ── Header con logo ── */
        .login-header {
            background: linear-gradient(160deg, #0C2461 0%, #1a3a7c 100%);
            padding: 20px 26px 18px;
            text-align: center;
        }

        .login-logo-text {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: .5px;
            margin-bottom: 8px;
            line-height: 1;
        }

        .login-logo-text .nexo   { color: #F0A500; }
        .login-logo-text .fiscal { color: #fff; }

        .login-header-divider {
            width: 40px;
            height: 3px;
            background: #F0A500;
            border-radius: 2px;
            margin: 0 auto 8px;
        }

        .login-header h1 {
            color: #fff;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: .3px;
            margin-bottom: 4px;
        }

        .login-header p {
            color: rgba(255,255,255,.6);
            font-size: 13px;
        }

        /* ── Cuerpo del formulario ── */
        .login-body { padding: 17px 26px 15px; }

        .alert-error {
            background: #fff;
            border-left: 4px solid #ef4444;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(239,68,68,.12);
            padding: 9px 11px;
            color: #1a202c;
            font-size: 13px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideDown .3s ease;
        }

        .alert-error-icon {
            width: 32px;
            height: 32px;
            background: #fee2e2;
            color: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .alert-error-text { flex: 1; }
        .alert-error-title { font-weight: 700; font-size: 12px; color: #dc2626; margin-bottom: 2px; }
        .alert-error-msg   { color: #4a5568; font-size: 12.5px; }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-group { margin-bottom: 10px; }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #0C2461;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 4px;
        }

        .input-wrap { position: relative; }

        .input-wrap i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: #8a9ab0;
            font-size: 15px;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 8px 10px 8px 34px;
            border: 1.5px solid #dde6f0;
            border-radius: 8px;
            font-size: 13px;
            color: #1a2b4a;
            background: #f7fafd;
            transition: border-color .2s, box-shadow .2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #F0A500;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(240,165,0,.12);
        }

        .form-input::placeholder { color: #b0bec8; }

        .btn-login {
            width: 100%;
            padding: 8px;
            background: #F0A500;
            border: none;
            border-radius: 8px;
            color: #0C2461;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            letter-spacing: .3px;
            transition: background .2s, transform .1s, box-shadow .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 3px;
        }

        .btn-login:hover {
            background: #d4920a;
            box-shadow: 0 4px 16px rgba(240,165,0,.3);
        }

        .btn-login:active { transform: scale(.98); }

        /* ── Footer de la tarjeta ── */
        .login-footer {
            text-align: center;
            padding: 8px 26px 10px;
            border-top: 1px solid #f0f4f8;
            font-size: 12px;
            color: #aab4c0;
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .login-body, .login-header { padding-left: 18px; padding-right: 18px; }
            .login-footer { padding-left: 18px; padding-right: 18px; }
        }
    </style>
</head>
<body>

<div class="login-wrap">
    <div class="login-card">

        <!-- Header -->
        <div class="login-header">
            <div class="login-logo-text">
                <span class="nexo">Nexo</span><span class="fiscal"> Fiscal</span>
            </div>
            <div class="login-header-divider"></div>
            <h1>Bienvenido</h1>
            <p>Control documental y conciliación</p>
        </div>

        <!-- Formulario -->
        <div class="login-body">

            <?php if (!empty($error)): ?>
            <div class="alert-error">
                <div class="alert-error-icon"><i class="fas fa-times-circle"></i></div>
                <div class="alert-error-text">
                    <div class="alert-error-title">Acceso denegado</div>
                    <div class="alert-error-msg"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($basePath) ?>/login" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="username">Usuario</label>
                    <div class="input-wrap">
                        <i class="fas fa-user"></i>
                        <input type="text"
                               id="username"
                               name="username"
                               class="form-input"
                               placeholder="Nombre de usuario"
                               autocomplete="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               required
                               autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Contraseña</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-input"
                               placeholder="Tu contraseña"
                               autocomplete="current-password"
                               required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="login-footer">
            &copy; <?= date('Y') ?> Nexo Fiscal &mdash; Todos los derechos reservados
        </div>

    </div>
</div>

</body>
</html>
