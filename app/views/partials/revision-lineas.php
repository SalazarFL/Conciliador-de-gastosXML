<?php
/**
 * La bandeja de líneas que un listado no supo leer.
 *
 * Una sola pantalla sirve a Facturas y a Notas de crédito porque el trabajo
 * es el mismo —mirar las celdas crudas, corregir lo que haga falta y decidir
 * si entra— y lo único que cambia son los campos de cada módulo, que llegan
 * en $campos. Duplicarla garantizaría que dentro de un año una tuviera un
 * botón que la otra no.
 *
 * Espera: $modulo, $tituloModulo, $sustantivo, $volverA, $pendientes,
 * $resueltas, $memoria, $campos.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$pendientes = is_array($pendientes ?? null) ? $pendientes : [];
$resueltas = is_array($resueltas ?? null) ? $resueltas : [];
$memoria = is_array($memoria ?? null) ? $memoria : [];
$campos = is_array($campos ?? null) ? $campos : [];
$modulo = (string) ($modulo ?? '');
$sustantivo = (string) ($sustantivo ?? 'línea');
$sustantivoPlural = (string) ($sustantivoPlural ?? $sustantivo . 's');
$tituloModulo = (string) ($tituloModulo ?? 'Listado');
$destino = (string) ($destino ?? 'el listado');

$fechaCorta = function ($valor) {
    $ts = $valor ? strtotime((string) $valor) : false;
    return $ts !== false ? date('d/m/Y H:i', $ts) : '—';
};
?>

<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-inbox" style="margin-right:6px;color:var(--warn,#d97706);"></i>
            <?= htmlspecialchars(ucfirst($sustantivoPlural)) ?> en revisión
            <span class="badge badge-navy" style="font-size:10px;padding:2px 8px;margin-left:4px;">
                <?= number_format(count($pendientes)) ?>
            </span>
        </div>
        <a href="<?= $baseUrl ?>/<?= htmlspecialchars($modulo) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Volver a <?= htmlspecialchars($tituloModulo) ?>
        </a>
    </div>

    <?php if (!$pendientes): ?>
        <div style="padding:22px;text-align:center;color:var(--text-muted);font-size:12.5px;">
            <i class="fas fa-circle-check" style="font-size:20px;color:var(--ok,#16a34a);display:block;margin-bottom:8px;"></i>
            No hay nada esperando. Todo lo que traía el archivo se leyó o ya fue resuelto.
        </div>
    <?php endif; ?>

    <?php foreach ($pendientes as $linea): ?>
    <?php $valores = is_array($linea['campos'] ?? null) ? $linea['campos'] : []; ?>
    <div style="border:1px solid var(--border,#e5e7eb);border-radius:8px;margin-bottom:14px;overflow:hidden;">

        <div style="background:#fff7ed;border-bottom:1px solid #fed7aa;padding:8px 12px;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;">
            <strong style="font-size:12.5px;color:#9a3412;">Fila <?= (int) $linea['fila_origen'] ?></strong>
            <span style="font-size:11.5px;color:#7c2d12;"><?= htmlspecialchars((string) $linea['motivo']) ?></span>
            <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">
                detectada el <?= htmlspecialchars($fechaCorta($linea['creado_en'] ?? null)) ?>
            </span>
        </div>

        <!-- Las celdas tal como vinieron. Sin esto no hay forma de saber qué
             dice de verdad la fila, que es lo primero que uno necesita. -->
        <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid var(--border,#e5e7eb);overflow-x:auto;">
            <div style="font-size:10.5px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">
                Celdas del archivo
            </div>
            <div style="display:flex;gap:4px;font-family:ui-monospace,Consolas,monospace;font-size:11px;white-space:nowrap;">
                <?php foreach ((array) ($linea['celdas'] ?? []) as $i => $celda): ?>
                    <span style="border:1px solid #cbd5e1;border-radius:4px;padding:2px 6px;background:#fff;">
                        <span style="color:#94a3b8;"><?= (int) $i ?></span>
                        <?= htmlspecialchars((string) $celda) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="POST" action="<?= $baseUrl ?>/<?= htmlspecialchars($modulo) ?>/revision/guardar"
              style="padding:12px;">
            <input type="hidden" name="id" value="<?= (int) $linea['id'] ?>">

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:9px;">
                <?php foreach ($campos as $campo => $definicion): ?>
                <div>
                    <label style="display:block;font-size:10.5px;color:var(--text-muted);margin-bottom:2px;">
                        <?= htmlspecialchars($definicion[0]) ?>
                    </label>
                    <input type="text" name="campo_<?= htmlspecialchars($campo) ?>"
                           class="form-control"
                           style="font-size:12px;padding:5px 7px;<?= $definicion[1] === 'importe' ? 'text-align:right;' : '' ?>"
                           value="<?= htmlspecialchars((string) ($valores[$campo] ?? '')) ?>"
                           <?= $definicion[1] === 'importe' ? 'inputmode="decimal"' : '' ?>>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:11px;">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-check" style="margin-right:4px;"></i>Guardar e incluir
                </button>
                <label style="font-size:11.5px;color:var(--text-muted);display:flex;align-items:center;gap:5px;cursor:pointer;">
                    <input type="checkbox" name="recordar" value="1" checked>
                    Recordar esta corrección para las próximas cargas
                </label>
            </div>
        </form>

        <form method="POST" action="<?= $baseUrl ?>/<?= htmlspecialchars($modulo) ?>/revision/descartar"
              style="padding:0 12px 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"
              onsubmit="return confirm('¿Descartar esta fila? No va a entrar en <?= htmlspecialchars($destino) ?>.');">
            <input type="hidden" name="id" value="<?= (int) $linea['id'] ?>">
            <input type="text" name="nota" class="form-control" placeholder="Por qué no va (opcional)"
                   style="font-size:11.5px;padding:5px 7px;max-width:280px;">
            <button type="submit" class="btn btn-outline btn-sm">
                <i class="fas fa-xmark" style="margin-right:4px;"></i>Descartar
            </button>
            <label style="font-size:11.5px;color:var(--text-muted);display:flex;align-items:center;gap:5px;cursor:pointer;">
                <input type="checkbox" name="recordar" value="1" checked>
                No volver a preguntar por esta fila
            </label>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($memoria): ?>
<div class="card mb-20">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-brain" style="margin-right:6px;color:var(--navy);"></i>
            Decisiones recordadas
            <span class="badge badge-default" style="font-size:10px;padding:2px 8px;margin-left:4px;">
                <?= number_format(count($memoria)) ?>
            </span>
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="table" style="font-size:12px;">
        <thead>
            <tr>
                <th>Decisión</th>
                <th>Fila del archivo</th>
                <th>Motivo</th>
                <th class="right">Veces aplicada</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($memoria as $m): ?>
            <tr>
                <td>
                    <?php if ($m['accion'] === 'incluir'): ?>
                        <span class="badge badge-ok">Incluir corregida</span>
                    <?php else: ?>
                        <span class="badge badge-default">Descartar</span>
                    <?php endif; ?>
                </td>
                <td style="font-family:ui-monospace,Consolas,monospace;font-size:11px;color:var(--text-muted);">
                    <?= htmlspecialchars(mb_substr(implode(' | ', (array) ($m['celdas'] ?? [])), 0, 90)) ?>
                </td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars((string) ($m['motivo'] ?? '')) ?></td>
                <td class="right"><?= number_format((int) ($m['veces_aplicada'] ?? 0)) ?></td>
                <td class="right">
                    <form method="POST" action="<?= $baseUrl ?>/<?= htmlspecialchars($modulo) ?>/revision/olvidar"
                          onsubmit="return confirm('¿Olvidar esta decisión?');" style="margin:0;">
                        <input type="hidden" name="memoria_id" value="<?= (int) $m['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm">Olvidar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($resueltas): ?>
<div class="card">
    <div class="card-header mb-12">
        <div class="card-title">
            <i class="fas fa-clock-rotate-left" style="margin-right:6px;color:var(--text-muted);"></i>
            Ya resueltas
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="table" style="font-size:12px;">
        <thead>
            <tr>
                <th>Fila</th>
                <th>Qué pasó</th>
                <th>Motivo por el que no se pudo leer</th>
                <th>Cuándo</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resueltas as $r): ?>
            <tr>
                <td><?= (int) $r['fila_origen'] ?></td>
                <td>
                    <?php if ($r['estado'] === 'incluida'): ?>
                        <span class="badge badge-ok">Entró al listado</span>
                    <?php else: ?>
                        <span class="badge badge-default">Descartada</span>
                        <?php if (!empty($r['nota'])): ?>
                            <span style="color:var(--text-muted);"><?= htmlspecialchars((string) $r['nota']) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars((string) $r['motivo']) ?></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($fechaCorta($r['resuelta_en'] ?? null)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
