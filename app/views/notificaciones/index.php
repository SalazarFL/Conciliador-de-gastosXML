<?php
/**
 * Pantalla de avisos: donde se toman las decisiones que la campana anuncia.
 *
 * No es un historial de lo que pasó. Cada aviso de código trae aquí su propio
 * contexto —los proveedores candidatos con cuántas facturas respaldan a cada
 * uno, y los rechazos que lo originaron— porque la decisión "de quién es este
 * código" no se puede tomar leyendo un título: hay que ver los números.
 */
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';

// El controlador siempre las manda; los valores por defecto son para que la
// vista se pueda renderizar suelta (pruebas) sin llenarse de avisos de PHP.
$avisos  = $avisos  ?? [];
$estado  = $estado  ?? 'pendiente';
$resumen = $resumen ?? [];

$pestanas = [
    'pendiente'  => 'Pendientes',
    'resuelta'   => 'Resueltas',
    'descartada' => 'Descartadas',
    'todas'      => 'Todas',
];

$colores = ['alta' => '#e53e3e', 'media' => '#C88A00', 'baja' => '#718096'];
$moneda = function ($n) { return number_format((float) $n, 2, ',', '.'); };

/**
 * Un literal de JavaScript que se pueda meter dentro de un atributo HTML.
 *
 * `json_encode` solo no sirve: envuelve el valor en comillas dobles y eso
 * cierra el `onclick="..."` a media expresión, dejando el botón sin hacer
 * nada. Hay que escapar además para HTML.
 */
$jsAttr = function ($valor) {
    return htmlspecialchars(json_encode((string) $valor), ENT_QUOTES, 'UTF-8');
};
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
        <h1 style="font-size:19px;font-weight:800;color:var(--navy);">Avisos</h1>
        <p style="font-size:12px;color:var(--text-muted);margin-top:2px;">
            Lo que el sistema no puede decidir solo. Son del equipo: si alguien resuelve uno, desaparece para todos.
        </p>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach ($pestanas as $clave => $etiqueta):
            $activa = $estado === $clave; ?>
        <a href="<?= $baseUrl ?>/avisos?estado=<?= $clave ?>"
           class="btn <?= $activa ? 'btn-primary' : 'btn-outline' ?> btn-sm"
           <?= $activa ? 'aria-current="page"' : '' ?>>
            <?= $etiqueta ?>
            <?php if ($clave === 'pendiente' && !empty($resumen['pendientes'])): ?>
                <span style="background:#e53e3e;color:#fff;border-radius:999px;padding:0 5px;font-size:10px;margin-left:3px;">
                    <?= (int) $resumen['pendientes'] ?>
                </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$avisos): ?>
<div class="card" style="text-align:center;padding:48px 20px;">
    <i class="fas fa-check-circle" style="font-size:34px;color:#38a169;"></i>
    <p style="margin-top:12px;font-size:13.5px;font-weight:700;color:var(--navy);">Nada que revisar</p>
    <p style="font-size:12px;color:var(--text-muted);margin-top:4px;">
        Cuando el emparejador encuentre algo que no pueda resolver solo, aparecerá aquí.
    </p>
</div>
<?php else: ?>

<?php foreach ($avisos as $aviso):
    $sev = (string) ($aviso['severidad'] ?? 'media');
    $datos = $aviso['datos_obj'] ?? [];
    $esCodigo = ($aviso['tipo'] ?? '') === 'codigo_proveedor';
    $abierto = ($aviso['estado'] ?? '') === 'pendiente';
?>
<div class="card" style="margin-bottom:13px;border-left:4px solid <?= $colores[$sev] ?? '#718096' ?>;"
     id="aviso-<?= (int) $aviso['id'] ?>">

    <div style="display:flex;align-items:flex-start;gap:11px;">
        <i class="fas <?= $sev === 'alta' ? 'fa-triangle-exclamation' : 'fa-circle-info' ?>"
           style="color:<?= $colores[$sev] ?? '#718096' ?>;font-size:15px;margin-top:2px;"></i>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:13.5px;font-weight:700;color:var(--navy);">
                    <?= htmlspecialchars((string) $aviso['titulo']) ?>
                </span>
                <?php if (!$abierto): ?>
                <span style="font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:999px;
                             background:var(--bg);border:1px solid var(--border);color:var(--text-muted);">
                    <?= htmlspecialchars(ucfirst((string) $aviso['estado'])) ?>
                    <?php if (!empty($aviso['resuelta_por_nombre'])): ?>
                        · <?= htmlspecialchars((string) $aviso['resuelta_por_nombre']) ?>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ((int) $aviso['veces'] > 1): ?>
                <span style="font-size:10.5px;color:var(--text-muted);">
                    ocurrió <?= (int) $aviso['veces'] ?> veces
                </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($aviso['detalle'])): ?>
            <p style="font-size:12px;color:var(--text-muted);margin-top:5px;line-height:1.5;">
                <?= htmlspecialchars((string) $aviso['detalle']) ?>
            </p>
            <?php endif; ?>

            <?php // Un recordatorio sin forma de llegar al documento obliga a
                  // buscarlo a mano en una lista de miles. ?>
            <?php if (($aviso['tipo'] ?? '') === 'seguimiento_recordatorio' && !empty($datos['documento'])): ?>
            <div style="margin-top:9px;">
                <a class="btn btn-outline btn-sm"
                   href="<?= $baseUrl ?>/seguimiento?vista=revision&q=<?= urlencode((string) $datos['documento']) ?>">
                    <i class="fas fa-list-check"></i> Ver en Seguimiento
                </a>
            </div>
            <?php endif; ?>
            <?php if ($esCodigo && $abierto): ?>
            <?php $candidatos = $aviso['candidatos'] ?? []; ?>

            <?php if ($candidatos): ?>
            <div style="margin-top:11px;">
                <div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:6px;">
                    ¿De quién es el código <?= htmlspecialchars((string) $aviso['ref_clave']) ?>?
                </div>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
                    <thead>
                        <tr style="background:var(--bg);color:var(--text-muted);text-align:left;">
                            <th style="padding:5px 8px;font-weight:700;">Proveedor</th>
                            <th style="padding:5px 8px;font-weight:700;">Cédula</th>
                            <th style="padding:5px 8px;font-weight:700;text-align:right;">Facturas que lo respaldan</th>
                            <th style="padding:5px 8px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidatos as $c):
                        $esActual = (int) $c['id'] === (int) ($datos['actual_id'] ?? 0); ?>
                        <tr style="border-top:1px solid var(--border-light);">
                            <td style="padding:6px 8px;">
                                <?= htmlspecialchars((string) $c['razon_social']) ?>
                                <?php if ($esActual): ?>
                                <span style="font-size:10px;color:var(--text-muted);">· es el que usa hoy</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px 8px;color:var(--text-muted);"><?= htmlspecialchars((string) $c['rfc']) ?></td>
                            <td style="padding:6px 8px;text-align:right;font-weight:700;"><?= (int) $c['veces'] ?></td>
                            <td style="padding:6px 8px;text-align:right;">
                                <button type="button" class="btn btn-primary btn-sm"
                                        onclick="avisoConfirmar(<?= (int) $aviso['id'] ?>, <?= $jsAttr($aviso['ref_clave']) ?>, <?= (int) $c['id'] ?>)">
                                    Es este
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($aviso['conflictos'])): ?>
            <details style="margin-top:10px;">
                <summary style="font-size:11px;color:var(--navy-light,#2254BD);cursor:pointer;font-weight:600;">
                    Ver los comprobantes que se rechazaron
                </summary>
                <div style="overflow-x:auto;margin-top:7px;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <thead>
                        <tr style="background:var(--bg);color:var(--text-muted);text-align:left;">
                            <th style="padding:4px 7px;font-weight:700;">Documento</th>
                            <th style="padding:4px 7px;font-weight:700;text-align:right;">Monto ERP</th>
                            <th style="padding:4px 7px;font-weight:700;">Emisor del XML</th>
                            <th style="padding:4px 7px;font-weight:700;text-align:right;">Total XML</th>
                            <th style="padding:4px 7px;font-weight:700;">Cuadraba</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($aviso['conflictos'] as $cf): ?>
                        <tr style="border-top:1px solid var(--border-light);">
                            <td style="padding:5px 7px;font-family:monospace;"><?= htmlspecialchars((string) ($cf['documento'] ?? '—')) ?></td>
                            <td style="padding:5px 7px;text-align:right;"><?= $moneda($cf['monto'] ?? 0) ?></td>
                            <td style="padding:5px 7px;"><?= htmlspecialchars((string) ($cf['nombre_propuesto'] ?? '—')) ?></td>
                            <td style="padding:5px 7px;text-align:right;"><?= $moneda($cf['xml_total'] ?? 0) ?></td>
                            <td style="padding:5px 7px;">
                                <?= !empty($cf['monto_cuadraba'])
                                    ? '<span style="color:#C88A00;font-weight:700;">sí</span>'
                                    : '<span style="color:var(--text-muted);">no</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p style="font-size:10.5px;color:var(--text-muted);margin-top:6px;line-height:1.5;">
                    Que el monto cuadre al colón es lo que hace sospechoso un rechazo: dos facturas de
                    proveedores distintos casi nunca coinciden en número <em>y</em> en monto.
                </p>
            </details>
            <?php endif; ?>

            <div style="margin-top:12px;display:flex;gap:7px;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm"
                        onclick="avisoLiberar(<?= (int) $aviso['id'] ?>, <?= $jsAttr($aviso['ref_clave']) ?>)"
                        title="Deja de bloquear este código sin decidir de quién es">
                    <i class="fas fa-unlock"></i> No sé todavía, que deje de bloquear
                </button>
                <button type="button" class="btn btn-outline btn-sm"
                        onclick="avisoCerrar(<?= (int) $aviso['id'] ?>, 'descartada')">
                    <i class="fas fa-eye-slash"></i> Descartar
                </button>
            </div>

            <?php elseif ($abierto): ?>
            <div style="margin-top:11px;">
                <button type="button" class="btn btn-outline btn-sm"
                        onclick="avisoCerrar(<?= (int) $aviso['id'] ?>, 'resuelta')">
                    <i class="fas fa-check"></i> Marcar como resuelto
                </button>
            </div>
            <?php endif; ?>

            <div style="font-size:10.5px;color:var(--text-muted);margin-top:9px;">
                <?= htmlspecialchars((string) $aviso['creada_en']) ?>
                <?php if (!empty($aviso['motivo'])): ?>
                    · <?= htmlspecialchars((string) $aviso['motivo']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
(function () {
    var base = <?= json_encode($baseUrl) ?>;

    function enviar(ruta, datos) {
        var cuerpo = new URLSearchParams();
        Object.keys(datos).forEach(function (k) { cuerpo.append(k, datos[k]); });

        return fetch(base + ruta, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: cuerpo.toString()
        }).then(function (r) { return r.json(); });
    }

    window.avisoConfirmar = function (avisoId, codigo, proveedorId) {
        enviar('/avisos/codigo/confirmar', {
            aviso_id: avisoId, codigo: codigo, proveedor_id: proveedorId
        }).then(function (r) {
            if (!r.ok) {
                alert(r.message || 'No se pudo confirmar.');
                return;
            }
            // Corregir el mapa puede desatascar facturas que el veto había
            // dejado sin respaldo: vale la pena decir cuántas.
            if (r.reparadas > 0) {
                alert('Confirmado. Se repararon ' + r.reparadas + ' facturas que estaban sin respaldo.');
            }
            location.reload();
        }).catch(function () { alert('No se pudo confirmar.'); });
    };

    window.avisoLiberar = function (avisoId, codigo) {
        enviar('/avisos/codigo/liberar', { aviso_id: avisoId, codigo: codigo })
            .then(function () { location.reload(); })
            .catch(function () { alert('No se pudo liberar el código.'); });
    };

    window.avisoCerrar = function (id, estado) {
        enviar('/avisos/cerrar', { id: id, estado: estado })
            .then(function () { location.reload(); })
            .catch(function () { alert('No se pudo cerrar el aviso.'); });
    };
})();
</script>
