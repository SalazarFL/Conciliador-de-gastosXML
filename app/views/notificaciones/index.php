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
?>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
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

<?php if (!$avisos): ?>
<div class="card" style="text-align:center;padding:48px 20px;">
    <i class="fas fa-check-circle" style="font-size:34px;color:#38a169;"></i>
    <p style="margin-top:12px;font-size:13.5px;font-weight:700;color:var(--navy);">Nada que revisar</p>
    <p style="font-size:12px;color:var(--text-muted);margin-top:4px;">
        Lo que no se pueda resolver solo sale acá.
    </p>
</div>
<?php else: ?>

<?php foreach ($avisos as $aviso):
    $sev = (string) ($aviso['severidad'] ?? 'media');
    $datos = $aviso['datos_obj'] ?? [];
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

            <?php // El índice del buzón se pone al día abriendo Correo, así que
                  // el aviso trae el atajo que lo intenta de una vez. El aviso
                  // se retira solo cuando la cola llega a cero: no hay que
                  // volver acá a marcarlo. ?>
            <?php if (($aviso['tipo'] ?? '') === 'indice_correo'): ?>
            <div style="margin-top:9px;">
                <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/correo?sync=1">
                    <i class="fas fa-rotate"></i> Actualizar el índice ahora
                </a>
            </div>
            <?php endif; ?>
            <?php /* Los comprobantes de proveedores sin código se decidían aquí
                     mismo, con su campo y sus dos salidas. Llegaban a decenas y
                     tapaban con veinte renglones los tres avisos que sí había
                     que atender ese día, así que se mudaron enteros a Correo →
                     Comprobantes sin código, que los muestra todos juntos. La
                     campana ya ni los cuenta: ver Notificacion::FUERA_DE_LA_CAMPANA. */ ?>
            <?php if ($abierto): ?>
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

    /*
     * Antes recargaba pasara lo que pasara. Cuando el cierre no llegaba a
     * escribir nada, la pantalla volvía con el aviso en su sitio y sin decir
     * una palabra: idéntico a que el botón estuviera roto. Ahora solo recarga
     * si el servidor dice que sí, y si no, cuenta lo que pasó.
     */
    function avisar(mensaje, titulo) {
        if (window.AppDialog) {
            window.AppDialog.alert(mensaje, { title: titulo, type: 'danger' });
        } else {
            alert(mensaje);
        }
    }

    window.avisoCerrar = function (id, estado) {
        enviar('/avisos/cerrar', { id: id, estado: estado })
            .then(function (r) {
                if (r && r.ok) { location.reload(); return; }
                avisar((r && r.message) || 'No se pudo cerrar el aviso.', 'El aviso sigue abierto');
            })
            .catch(function () {
                avisar('No se pudo cerrar el aviso. Revisa la conexión y vuelve a intentarlo.',
                       'El aviso sigue abierto');
            });
    };
})();
</script>
