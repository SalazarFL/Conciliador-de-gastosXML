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
$respaldo = $respaldo ?? null;

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

<?php if ($esAdmin && $respaldo !== null): ?>
<?php
/**
 * Respaldo de la base a la carpeta compartida.
 *
 * Solo para administradores: escribe en la carpeta que todos sincronizan.
 * La tarjeta se dibuja ya con datos del servidor —no en blanco esperando al
 * JS— para que siga diciendo algo útil si el sondeo falla.
 */
$estadoIni = $respaldo['estado'] ?? null;
$corriendo = is_array($estadoIni) && ($estadoIni['estado'] ?? '') === 'corriendo';
$autoIni   = $respaldo['automatico'] ?? ['activo' => false, 'hora' => ''];
$horaIni   = ($autoIni['hora'] ?? '') !== '' ? $autoIni['hora'] : '22:00';
?>
<div class="card mt-20" id="respaldo-card" style="border-left:4px solid var(--navy);">
    <div style="padding:10px 12px;">
        <h2 style="margin:0 0 4px;font-size:16px;color:var(--navy);">
            <i class="fas fa-database" style="margin-right:8px;"></i>
            Respaldo de la base de datos
        </h2>
        <p style="margin:0 0 9px;color:var(--text-muted);font-size:12.5px;line-height:1.5;">
            Genera una copia de la base y la deja en la carpeta compartida, donde SharePoint
            la sincroniza al resto de las computadoras. <strong>Hay que apretarlo en la
            computadora que sí alcanza la base</strong> — desde otra tendría que salir por la
            red, que es justo lo que falla cuando este respaldo hace falta.
        </p>

        <?php if (($respaldo['carpetaError'] ?? '') !== ''): ?>
        <div style="padding:10px 12px;background:var(--miss-bg);color:var(--miss);border-radius:6px;
                    font-size:13px;margin-bottom:12px;">
            <?= htmlspecialchars($respaldo['carpetaError'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php elseif (($respaldo['mysqldump'] ?? null) === null): ?>
        <div style="padding:10px 12px;background:var(--miss-bg);color:var(--miss);border-radius:6px;
                    font-size:13px;margin-bottom:12px;">
            No se encontró <code>mysqldump.exe</code> en esta computadora
            (se buscó en la instalación de MariaDB y en el PATH). Sin él no se puede respaldar.
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" id="respaldo-generar"
                    <?= $corriendo ? 'disabled' : '' ?>>
                <i class="fas fa-download" style="margin-right:6px;"></i>
                Generar respaldo ahora
            </button>
            <span id="respaldo-msg" style="font-size:13px;color:var(--text-muted);"></span>
        </div>

        <div style="margin-top:9px;padding:8px 10px;background:var(--border-light);border-radius:6px;
                    font-size:12.5px;line-height:1.5;">
            <div>
                <strong style="color:var(--navy);">Carpeta:</strong>
                <span style="font-family:Consolas,monospace;font-size:12.5px;word-break:break-all;">
                    <?= htmlspecialchars((string) ($respaldo['carpeta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div style="color:var(--text-muted);">
                Se conservan los últimos <?= (int) ($respaldo['conserva'] ?? 5) ?>;
                los más viejos se borran solos para no llenarle el SharePoint a nadie.
            </div>
        </div>

        <!-- Automático -->
        <div style="margin-top:9px;display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
            <span style="font-size:13px;color:var(--navy);"><strong>Todas las noches a las</strong></span>
            <input type="time" id="respaldo-hora" value="<?= htmlspecialchars($horaIni, ENT_QUOTES, 'UTF-8') ?>"
                   style="padding:5px 8px;border:1px solid var(--border);border-radius:5px;font-size:13px;">
            <button type="button" class="btn btn-outline btn-sm" id="respaldo-auto-activar">
                <?= !empty($autoIni['activo']) ? 'Cambiar la hora' : 'Activar' ?>
            </button>
            <button type="button" class="btn btn-outline btn-sm" id="respaldo-auto-desactivar"
                    <?= empty($autoIni['activo']) ? 'style="display:none;"' : '' ?>>Desactivar</button>
            <span id="respaldo-auto-msg" style="font-size:13px;color:<?= !empty($autoIni['activo']) ? 'var(--ok)' : 'var(--text-muted)' ?>;">
                <?= !empty($autoIni['activo'])
                    ? 'Activo' . (($autoIni['hora'] ?? '') !== '' ? ' · próxima a las ' . htmlspecialchars($autoIni['hora'], ENT_QUOTES, 'UTF-8') : '')
                    : 'Desactivado: solo se respalda cuando alguien aprieta el botón.' ?>
            </span>
        </div>

        <!-- Lo que hay hoy en la carpeta compartida -->
        <div style="margin-top:10px;">
            <div style="font-size:13px;color:var(--navy);margin-bottom:6px;">
                <strong>Respaldos en la carpeta compartida</strong>
            </div>
            <div id="respaldo-lista"></div>
        </div>

        <p style="margin:9px 0 0;color:var(--text-muted);font-size:12px;line-height:1.5;">
            Lo mismo desde la consola: <code>php cli/respaldar_base.php</code>.
            Para levantarlo en la otra computadora, cuando el archivo ya sincronizó:
            <code>.\scripts\copiar-base.ps1 -Desde ultimo</code>
        </p>
    </div>
</div>

<script>
(function () {
    var URL_ESTADO  = '<?= $baseUrl ?>/diagnostico/respaldo/estado';
    var URL_INICIAR = '<?= $baseUrl ?>/diagnostico/respaldo/iniciar';
    var URL_ON      = '<?= $baseUrl ?>/diagnostico/respaldo/auto/activar';
    var URL_OFF     = '<?= $baseUrl ?>/diagnostico/respaldo/auto/desactivar';

    var btn      = document.getElementById('respaldo-generar');
    var msg      = document.getElementById('respaldo-msg');
    var lista    = document.getElementById('respaldo-lista');
    var hora     = document.getElementById('respaldo-hora');
    var btnOn    = document.getElementById('respaldo-auto-activar');
    var btnOff   = document.getElementById('respaldo-auto-desactivar');
    var autoMsg  = document.getElementById('respaldo-auto-msg');
    var sondeo   = null;

    function postJson(url, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().catch(function () { return null; }).then(function (body) {
                    if (!res.ok || !body || body.ok === false) {
                        throw new Error((body && body.message) || ('Error HTTP ' + res.status));
                    }
                    return body;
                });
            });
    }

    function decir(texto, color) {
        msg.textContent = texto || '';
        msg.style.color = color || 'var(--text-muted)';
    }

    function pintarLista(archivos) {
        if (!archivos || !archivos.length) {
            lista.innerHTML = '<div style="font-size:13px;color:var(--text-muted);">' +
                'Todavía no hay ninguno.</div>';
            return;
        }
        var html = '<table class="data-table" style="font-size:12.5px;"><tbody>';
        archivos.forEach(function (a, i) {
            html += '<tr>' +
                '<td style="font-family:Consolas,monospace;font-size:12.5px;word-break:break-all;">' +
                    a.nombre +
                    (i === 0 ? ' <span class="badge" style="background:var(--ok-bg);color:var(--ok);">' +
                               'el más nuevo</span>' : '') +
                '</td>' +
                '<td style="white-space:nowrap;text-align:right;color:var(--text-muted);">' + a.tamano + '</td>' +
                '<td style="white-space:nowrap;text-align:right;color:var(--text-muted);">' + a.fecha + '</td>' +
                '</tr>';
        });
        lista.innerHTML = html + '</tbody></table>';
    }

    function pintarEstado(d) {
        pintarLista(d.archivos);

        var e = d.estado;
        if (!e) { decir(''); return; }

        if (e.estado === 'corriendo') {
            btn.disabled = true;
            decir(e.mensaje || 'Respaldo en curso...', 'var(--navy)');
            arrancarSondeo();
            return;
        }

        btn.disabled = false;
        pararSondeo();
        if (e.estado === 'error') {
            decir(e.mensaje || 'Falló el respaldo.', 'var(--miss)');
        } else if (e.estado === 'ok') {
            decir('Último: ' + (e.terminado_en || '') + ' · ' + (e.mensaje || ''), 'var(--ok)');
        }
    }

    function refrescar() {
        return postJson(URL_ESTADO, {}).then(pintarEstado).catch(function (err) {
            decir(err.message, 'var(--miss)');
            pararSondeo();
        });
    }

    // Mientras corre se pregunta cada 2 s. El proceso vive fuera del navegador,
    // así que cerrar la página no lo detiene: al volver se ve cómo terminó.
    function arrancarSondeo() {
        if (sondeo) return;
        sondeo = setInterval(refrescar, 2000);
    }
    function pararSondeo() {
        if (!sondeo) return;
        clearInterval(sondeo);
        sondeo = null;
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        decir('Lanzando...', 'var(--navy)');
        postJson(URL_INICIAR, {})
            .then(function () { arrancarSondeo(); return refrescar(); })
            .catch(function (err) {
                btn.disabled = false;
                decir(err.message, 'var(--miss)');
            });
    });

    btnOn.addEventListener('click', function () {
        btnOn.disabled = true;
        postJson(URL_ON, { hora: hora.value })
            .then(function (r) {
                btnOn.disabled = false;
                btnOn.textContent = 'Cambiar la hora';
                btnOff.style.display = '';
                autoMsg.textContent = r.message;
                autoMsg.style.color = 'var(--ok)';
            })
            .catch(function (err) {
                btnOn.disabled = false;
                autoMsg.textContent = err.message;
                autoMsg.style.color = 'var(--miss)';
            });
    });

    btnOff.addEventListener('click', function () {
        btnOff.disabled = true;
        postJson(URL_OFF, {})
            .then(function (r) {
                btnOff.disabled = false;
                btnOff.style.display = 'none';
                btnOn.textContent = 'Activar';
                autoMsg.textContent = r.message;
                autoMsg.style.color = 'var(--text-muted)';
            })
            .catch(function (err) {
                btnOff.disabled = false;
                autoMsg.textContent = err.message;
                autoMsg.style.color = 'var(--miss)';
            });
    });

    pintarEstado(<?= json_encode([
        'estado'   => $estadoIni,
        'archivos' => $respaldo['archivos'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
})();
</script>
<?php endif; ?>
