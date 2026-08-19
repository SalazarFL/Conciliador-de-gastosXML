<?php
/**
 * Diagnóstico de la instalación.
 *
 * Cada revisión es una fila: estado, qué se revisó y —cuando algo falla— qué
 * hacer. El "qué hacer" viene escrito para que quien reporta el problema pueda
 * seguirlo sin ayuda, o copiarlo tal cual al pedirla.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$informe = $informe ?? ['estado' => 'ok', 'revisiones' => [], 'equipo' => '', 'generado_en' => ''];
$esAdmin  = !empty($esAdmin);

$colores = [
    'ok'    => ['var(--ok)',   'var(--ok-bg)',   'fa-circle-check',            'Correcto'],
    'aviso' => ['var(--warn)', 'var(--warn-bg)', 'fa-triangle-exclamation',    'Atender'],
    'error' => ['var(--miss)', 'var(--miss-bg)', 'fa-circle-xmark',            'Impide trabajar'],
];
$resumen = [
    'ok'    => 'Esta computadora está lista para trabajar.',
    'aviso' => 'Se puede trabajar, pero hay cosas pendientes de atender.',
    'error' => 'Hay algo que impide trabajar. Empieza por lo marcado en rojo.',
];
[$colorTitulo, $fondoTitulo] = $colores[$informe['estado']];

$cuenta = ['ok' => 0, 'aviso' => 0, 'error' => 0];
foreach ($informe['revisiones'] as $r) {
    $cuenta[$r['estado']]++;
}
?>

<div class="card mb-20" style="border-left:4px solid <?= $colorTitulo ?>;">
    <div style="padding:10px 12px;">
        <h2 style="margin:0 0 4px;font-size:17px;color:<?= $colorTitulo ?>;">
            <i class="fas <?= $colores[$informe['estado']][2] ?>" style="margin-right:8px;"></i>
            <?= htmlspecialchars($resumen[$informe['estado']], ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p style="margin:0;color:var(--text-muted);font-size:13px;">
            Equipo <strong><?= htmlspecialchars((string) $informe['equipo'], ENT_QUOTES, 'UTF-8') ?></strong>
            · <?= htmlspecialchars((string) $informe['generado_en'], ENT_QUOTES, 'UTF-8') ?>
            · <?= $cuenta['ok'] ?> correcto(s), <?= $cuenta['aviso'] ?> por atender, <?= $cuenta['error'] ?> con error
        </p>
        <p style="margin:6px 0 0;color:var(--text-muted);font-size:12.5px;">
            Si vas a pedir ayuda con un problema, manda una captura de esta página:
            dice en qué computadora estás y qué le falta a esta instalación.
        </p>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:130px;">Estado</th>
                <th style="width:280px;">Revisión</th>
                <th>Resultado y qué hacer</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($informe['revisiones'] as $r):
            [$color, $fondo, $icono, $etiqueta] = $colores[$r['estado']]; ?>
            <tr>
                <td>
                    <span class="badge" style="background:<?= $fondo ?>;color:<?= $color ?>;white-space:nowrap;">
                        <i class="fas <?= $icono ?>" style="margin-right:4px;"></i><?= $etiqueta ?>
                    </span>
                </td>
                <td><strong><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td>
                    <div><?= htmlspecialchars($r['detalle'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($r['que_hacer'] !== ''): ?>
                    <div style="margin-top:6px;padding:7px 9px;background:var(--border-light);
                                border-radius:6px;font-size:12.5px;line-height:1.5;">
                        <strong style="color:var(--navy);">Qué hacer:</strong>
                        <pre style="margin:4px 0 0;font-family:Consolas,monospace;font-size:12.5px;
                                    white-space:pre-wrap;word-break:break-word;"><?=
                            htmlspecialchars($r['que_hacer'], ENT_QUOTES, 'UTF-8') ?></pre>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<p style="margin:10px 0 0;color:var(--text-muted);font-size:12.5px;">
    Lo mismo desde la consola, por si hay que enviarlo por escrito:
    <code>php cli/diagnostico.php</code>
</p>

<?php if ($esAdmin): ?>
<?php // El respaldo de la base se administra en Configuración: respaldar no es
      // diagnosticar, aunque esta sea la pantalla a la que se manda a alguien
      // cuando algo no anda. Acá queda el puente para quien llegó buscándolo. ?>
<p style="margin:10px 0 0;color:var(--text-muted);font-size:12.5px;">
    <i class="fas fa-database" style="margin-right:5px;"></i>
    El respaldo de la base a la carpeta compartida se administra en
    <a href="<?= $baseUrl ?>/configuracion?ir=respaldo" style="color:var(--navy-light);font-weight:700;">Configuración → Respaldo</a>.
</p>
<?php endif; ?>
