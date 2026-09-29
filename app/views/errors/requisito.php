<?php
/**
 * "Este módulo todavía no se puede usar."
 *
 * No es un 403 y no se parece a uno a propósito. Un 403 dice "esto no es para
 * ti" y la salida es pedirle algo a otra persona. Esto dice "falta un paso" y
 * la salida es hacerlo: por eso lo que lleva no es una disculpa, es el botón
 * que va a la pantalla donde se arregla.
 *
 * Tampoco es un redirect silencioso a Configuración: aparecer de pronto en los
 * ajustes, sin que nadie explique por qué, se lee como que el sistema falla.
 *
 * La pinta Requisitos::rechazar(), que le pasa $requisitosQueFaltan,
 * $moduloCerrado y $baseUrl.
 */
$requisitosQueFaltan = is_array($requisitosQueFaltan ?? null) ? $requisitosQueFaltan : [];
$moduloCerrado = (string) ($moduloCerrado ?? '');
$baseUrl = $baseUrl ?? (defined('APP_URL') ? APP_URL : '/xmlconcilia/public');

$nombreModulo = Permisos::modulos()[$moduloCerrado]['titulo'] ?? 'Este módulo';
$cuantos = count($requisitosQueFaltan);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Falta configurar — Nexo Fiscal</title>
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

        .req-caja {
            background: white;
            border-radius: 12px;
            border-top: 4px solid #F0A500;
            box-shadow: 0 10px 36px rgba(12,36,97,.16);
            padding: 30px 28px;
            max-width: 560px;
            width: 100%;
        }

        .req-icono  { font-size: 40px; text-align: center; margin-bottom: 8px; }
        .req-titulo { font-size: 21px; color: #0C2461; font-weight: 600;
                      text-align: center; margin-bottom: 8px; }

        .req-texto {
            font-size: 13.5px;
            color: #5A6E8A;
            line-height: 1.55;
            text-align: center;
            margin-bottom: 18px;
        }

        .req-paso {
            display: flex;
            gap: 11px;
            align-items: flex-start;
            background: #F7FAFF;
            border: 1px solid #DDE6F0;
            border-radius: 9px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }

        .req-numero {
            flex: 0 0 22px;
            height: 22px;
            border-radius: 50%;
            background: #0C2461;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .req-paso-nombre { font-size: 13.5px; color: #0C2461; font-weight: 600; margin-bottom: 3px; }
        .req-paso-falta  { font-size: 12.5px; color: #1a2b4a; line-height: 1.5; }
        .req-paso-porque { font-size: 12px; color: #5A6E8A; line-height: 1.5; margin-top: 4px; }
        .req-paso-donde  { font-size: 11.5px; color: #94A3B8; margin-top: 5px; }
        .req-paso-donde a { color: #2254BD; text-decoration: none; font-weight: 600; }
        .req-paso-donde a:hover { text-decoration: underline; }

        .req-acciones { display: flex; gap: 8px; justify-content: center;
                        flex-wrap: wrap; margin-top: 18px; }

        .req-btn, .req-btn-suave {
            display: inline-block;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 13px;
            border: none;
        }

        .req-btn { background: #0C2461; color: white; }
        .req-btn:hover { background: #173E8A; }

        .req-btn-suave { background: white; color: #0C2461; border: 1.5px solid #DDE6F0; }
        .req-btn-suave:hover { background: #F7FAFF; }
    </style>
</head>
<body>
    <div class="req-caja">
        <div class="req-icono">🧭</div>
        <h1 class="req-titulo"><?= htmlspecialchars($nombreModulo) ?> todavía no se puede usar</h1>
        <p class="req-texto">
            No es tu rol ni un error: al sistema le falta
            <?= $cuantos === 1 ? 'un paso de configuración' : 'configuración inicial' ?>.
            Se hace una sola vez.
        </p>

        <?php $n = 0; foreach ($requisitosQueFaltan as $requisito): $n++;
            $puedeArreglarlo = empty($requisito['permiso'])
                || Permisos::puede((string) $requisito['permiso']); ?>
        <div class="req-paso">
            <span class="req-numero"><?= $n ?></span>
            <div>
                <div class="req-paso-nombre"><?= htmlspecialchars((string) ($requisito['titulo'] ?? '')) ?></div>
                <div class="req-paso-falta"><?= htmlspecialchars((string) ($requisito['falta'] ?? '')) ?></div>
                <?php if (!empty($requisito['porque'])): ?>
                <div class="req-paso-porque"><?= htmlspecialchars((string) $requisito['porque']) ?></div>
                <?php endif; ?>
                <div class="req-paso-donde">
                    <?php if ($puedeArreglarlo && !empty($requisito['ruta'])): ?>
                    <a href="<?= htmlspecialchars($baseUrl . $requisito['ruta']) ?>">
                        <?= htmlspecialchars((string) ($requisito['donde'] ?? 'Configuración')) ?> →
                    </a>
                    <?php else: ?>
                    <?php // Verlo y no poder hacerlo es información útil: así se
                          // sabe qué pedir, en vez de un botón que acabaría en
                          // "no tienes permiso". ?>
                    Se hace en <?= htmlspecialchars((string) ($requisito['donde'] ?? 'Configuración')) ?>.
                    Pídeselo al administrador del sistema.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="req-acciones">
            <a href="<?= htmlspecialchars($baseUrl) ?>/" class="req-btn">Ir al inicio</a>
            <a href="javascript:history.back()" class="req-btn-suave">Volver atrás</a>
        </div>
    </div>
</body>
</html>
