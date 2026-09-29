<?php
require_once __DIR__ . '/../../helpers/Columnas.php';
/**
 * La bitácora: una línea de tiempo con lo que ha hecho cada persona.
 *
 * Dos cosas dan forma a esta pantalla:
 *
 *  1. Por omisión se muestran los últimos 30 días. La consulta puede unir la
 *     bitácora con el historial de siete módulos, y sin acotar por fecha
 *     leería todo lo que la empresa lleva hecho desde que existe para pintar
 *     cincuenta renglones.
 *  2. El "historial anterior" es una casilla y no viene marcado. Lo que este
 *     sistema registra desde ahora está completo y ordenado; lo de antes son
 *     rastros sueltos de cada módulo, útiles cuando se busca algo viejo y
 *     ruido el resto del tiempo.
 */
$baseUrl   = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$filtros   = is_array($filtros ?? null) ? $filtros : [];
$resultado = is_array($resultado ?? null) ? $resultado : ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
$usuarios  = is_array($usuarios ?? null) ? $usuarios : [];
$fuentes   = is_array($fuentes ?? null) ? $fuentes : [];
$modulos   = is_array($modulos ?? null) ? $modulos : [];
$estado    = is_array($estado ?? null) ? $estado : [];
$fallo     = $fallo ?? null;
$puedeDepurar = !empty($puedeDepurar);

$filas   = $resultado['filas'] ?? [];
$pagina  = (int) ($resultado['pagina'] ?? 1);
$paginas = (int) ($resultado['paginas'] ?? 1);

/** Rehace la dirección actual cambiando solo lo que se le pase. */
$qs = function (array $cambios = []) use ($filtros) {
    $actual = [
        'usuario'   => $filtros['usuario_id'] ?: '',
        'modulo'    => $filtros['modulo'] ?? '',
        'origen'    => $filtros['origen'] ?? '',
        'resultado' => $filtros['resultado'] ?? '',
        'texto'     => $filtros['texto'] ?? '',
        'desde'     => $filtros['desde'] ?? '',
        'hasta'     => $filtros['hasta'] ?? '',
        'historial' => !empty($filtros['historial']) ? '1' : '',
    ];
    return http_build_query(array_filter(array_merge($actual, $cambios), fn($v) => $v !== '' && $v !== null));
};

/** El color del renglón según cómo acabó la acción. */
$insignia = function (string $resultado): array {
    return match ($resultado) {
        'denegado' => ['badge-miss', 'fa-ban',           'Denegado'],
        'error'    => ['badge-warn', 'fa-triangle-exclamation', 'Con error'],
        default    => ['badge-ok',   'fa-check',         'Hecho'],
    };
};

/** El detalle guardado, reducido a una línea que se pueda leer de un vistazo. */
$resumirDetalle = function ($detalle): string {
    if ($detalle === null || $detalle === '') {
        return '';
    }
    $datos = json_decode((string) $detalle, true);
    if (!is_array($datos)) {
        return (string) $detalle;     // el historial viejo guarda texto suelto
    }

    $partes = [];
    if (isset($datos['cambios']) && is_array($datos['cambios'])) {
        foreach ($datos['cambios'] as $campo => $cambio) {
            $antes   = is_array($cambio['antes'] ?? null) ? '…' : (string) ($cambio['antes'] ?? '');
            $despues = is_array($cambio['despues'] ?? null) ? '…' : (string) ($cambio['despues'] ?? '');
            $partes[] = $campo . ': ' . ($antes === '' ? '—' : $antes) . ' → ' . ($despues === '' ? '—' : $despues);
        }
    }
    foreach ($datos as $clave => $valor) {
        if ($clave === 'cambios' || $clave === 'parametros' || $clave === 'estado_http') {
            continue;
        }
        $partes[] = $clave . ': ' . (is_array($valor) ? '…' : (string) $valor);
    }
    if (!$partes && isset($datos['parametros']) && is_array($datos['parametros'])) {
        $partes[] = implode(', ', array_slice(array_keys($datos['parametros']), 0, 4));
    }

    return implode(' · ', $partes);
};
?>

<!-- ══ Lo que hay registrado ═══════════════════════════════════════════ -->
<div class="bit-resumen">
    <div class="bit-dato">
        <span class="bit-dato-num"><?= number_format((int) ($estado['total'] ?? 0)) ?></span>
        <span class="bit-dato-tag">registros en total</span>
    </div>
    <div class="bit-dato">
        <span class="bit-dato-num"><?= number_format((int) ($estado['hoy'] ?? 0)) ?></span>
        <span class="bit-dato-tag">hoy</span>
    </div>
    <div class="bit-dato<?= (int) ($estado['denegados_30d'] ?? 0) > 0 ? ' alerta' : '' ?>">
        <span class="bit-dato-num"><?= number_format((int) ($estado['denegados_30d'] ?? 0)) ?></span>
        <span class="bit-dato-tag">intentos sin permiso (30 días)</span>
    </div>
    <div class="bit-dato ancho">
        <span class="bit-dato-tag">Se registra desde</span>
        <span class="bit-dato-txt">
            <?= !empty($estado['desde']) ? htmlspecialchars(date('d/m/Y', strtotime($estado['desde']))) : '—' ?>
        </span>
    </div>

    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
        <a class="btn btn-outline btn-sm" href="<?= $baseUrl ?>/bitacora/exportar?<?= $qs() ?>"
           data-exportar="bitacora"
           title="Bajar a Excel lo que estás viendo: estos filtros y estas columnas">
            <i class="fas fa-file-excel"></i> Exportar
        </a>
        <?php if ($puedeDepurar): ?>
        <button type="button" class="btn btn-outline btn-sm" id="bit-abrir-depurar"
                title="Borrar los registros más antiguos">
            <i class="fas fa-broom"></i> Depurar
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($puedeDepurar): ?>
<form method="POST" action="<?= $baseUrl ?>/bitacora/depurar" id="bit-depurar" class="bit-depurar"
      data-confirm="Se borrarán los registros más antiguos que el plazo elegido. El historial propio de cada módulo no se toca. Esta acción no se puede deshacer."
      data-confirm-title="Depurar la bitácora"
      data-confirm-type="danger"
      data-confirm-accept="Borrar">
    <span>Borrar los registros con más de</span>
    <select name="dias" class="form-control" style="width:auto;">
        <option value="90">90 días</option>
        <option value="180">6 meses</option>
        <option value="365" selected>1 año</option>
        <option value="730">2 años</option>
    </select>
    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-broom"></i> Depurar</button>
    <span class="bit-depurar-nota">
        Solo se borra la bitácora. El historial de cada módulo se queda.
    </span>
</form>
<?php endif; ?>

<!-- ══ Filtros ═════════════════════════════════════════════════════════ -->
<form method="GET" action="<?= $baseUrl ?>/bitacora" class="bit-filtros">
    <div class="bit-filtro">
        <label>Buscar</label>
        <input type="search" name="texto" value="<?= htmlspecialchars($filtros['texto'] ?? '') ?>"
               placeholder="Acción, detalle o persona" class="form-control">
    </div>

    <div class="bit-filtro">
        <label>Quién</label>
        <select name="usuario" class="form-control">
            <option value="">Cualquiera</option>
            <?php foreach ($usuarios as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($filtros['usuario_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $u['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="bit-filtro">
        <label>Módulo</label>
        <select name="modulo" class="form-control">
            <option value="">Todos</option>
            <?php foreach ($modulos as $id => $m): ?>
            <option value="<?= htmlspecialchars($id) ?>" <?= ($filtros['modulo'] ?? '') === $id ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['titulo'] ?? $id) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="bit-filtro">
        <label>Cómo acabó</label>
        <select name="resultado" class="form-control">
            <option value="">Todo</option>
            <option value="ok"       <?= ($filtros['resultado'] ?? '') === 'ok' ? 'selected' : '' ?>>Hecho</option>
            <option value="denegado" <?= ($filtros['resultado'] ?? '') === 'denegado' ? 'selected' : '' ?>>Sin permiso</option>
            <option value="error"    <?= ($filtros['resultado'] ?? '') === 'error' ? 'selected' : '' ?>>Con error</option>
        </select>
    </div>

    <div class="bit-filtro">
        <label>Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($filtros['desde'] ?? '') ?>" class="form-control">
    </div>

    <div class="bit-filtro">
        <label>Hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($filtros['hasta'] ?? '') ?>" class="form-control">
    </div>

    <?php if (count($fuentes) > 1): ?>
    <div class="bit-filtro">
        <label>Origen</label>
        <select name="origen" class="form-control">
            <option value="">Todos</option>
            <?php foreach ($fuentes as $clave => $fuente): ?>
            <option value="<?= htmlspecialchars($clave) ?>" <?= ($filtros['origen'] ?? '') === $clave ? 'selected' : '' ?>>
                <?= htmlspecialchars($fuente['titulo']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <label class="bit-historial" title="Añade el rastro que cada módulo guardaba antes de la bitácora">
        <input type="checkbox" name="historial" value="1" <?= !empty($filtros['historial']) ? 'checked' : '' ?>>
        <span>Incluir el historial anterior</span>
    </label>

    <div class="bit-filtro-acciones">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrar</button>
        <a href="<?= $baseUrl ?>/bitacora?limpiar=1" class="btn btn-outline btn-sm">Limpiar</a>
    </div>
</form>

<?php if ($fallo): ?>
<div class="cfg-msg error" style="margin:12px 0;"><?= htmlspecialchars($fallo) ?></div>
<?php endif; ?>

<!-- ══ La lista ════════════════════════════════════════════════════════ -->
<div style="margin:10px 0 6px;font-size:12px;color:var(--text-muted);">
    <strong><?= number_format((int) ($resultado['total'] ?? 0)) ?></strong> movimientos
    <?php if (!empty($filtros['desde']) || !empty($filtros['hasta'])): ?>
        entre <?= htmlspecialchars($filtros['desde'] ?: 'el principio') ?>
        y <?= htmlspecialchars($filtros['hasta'] ?: 'hoy') ?>
    <?php endif; ?>
</div>

<?php if (!$filas): ?>
<div class="cfg-vacio" style="border:1px solid var(--border-light);border-radius:9px;background:#fff;">
    <i class="fas fa-clock-rotate-left"></i>
    No hay movimientos con esos criterios.
    <?php if (empty($filtros['historial'])): ?>
    <div style="margin-top:5px;font-size:11.5px;">
        Para lo anterior a la bitácora, marca <strong>Incluir el historial anterior</strong>.
    </div>
    <?php endif; ?>
</div>
<?php else: ?>

<?php $columnasListado = 'bitacora'; include __DIR__ . '/../partials/columnas.php'; ?>
<div class="table-wrap">
    <table class="data-table bit-tabla" data-listado="bitacora">
        <thead>
            <tr>
                <th style="width:132px;" data-col="cuando">Cuándo</th>
                <th style="width:150px;" data-col="quien">Quién</th>
                <th style="width:120px;" data-col="modulo">Módulo</th>
                <th data-col="accion">Qué hizo</th>
                <th style="width:96px;" data-col="resultado">Resultado</th>
                <th data-col="detalle">Detalle</th>
                <th style="width:140px;" data-col="origen"
                    title="La bitácora, o el historial que el módulo llevaba antes de ella">Origen</th>
                <?= Columnas::cabecerasExtra('bitacora') ?>
                <th style="width:38px;" data-columnas-boton></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filas as $f): ?>
        <?php
            [$clase, $icono, $texto] = $insignia((string) ($f['resultado'] ?? 'ok'));
            $detalle = $resumirDetalle($f['detalle'] ?? null);
            $propio  = ($f['origen'] ?? '') === 'bitacora';
            $tituloModulo = $modulos[$f['modulo']]['titulo'] ?? $f['modulo'];
        ?>
        <tr class="bit-fila<?= ($f['resultado'] ?? '') === 'denegado' ? ' denegada' : '' ?>">
            <td class="bit-cuando" data-col="cuando">
                <?= htmlspecialchars(date('d/m/Y', strtotime((string) $f['fecha']))) ?>
                <span><?= htmlspecialchars(date('H:i', strtotime((string) $f['fecha']))) ?></span>
            </td>
            <td class="bit-quien" data-col="quien"><?= htmlspecialchars((string) $f['usuario_nombre']) ?></td>
            <td data-col="modulo">
                <span class="badge badge-default"><?= htmlspecialchars((string) $tituloModulo) ?></span>
            </td>
            <td class="bit-accion" data-col="accion"><?= htmlspecialchars((string) $f['accion']) ?></td>
            <td data-col="resultado">
                <span class="badge <?= $clase ?>"><i class="fas <?= $icono ?>"></i> <?= $texto ?></span>
            </td>
            <?php // Recortado a una línea que se lea; entero, en la lupa y en el Excel. ?>
            <td class="bit-detalle" data-col="detalle"><?= $detalle !== '' ? htmlspecialchars($detalle) : '—' ?></td>
            <td class="bit-origen" data-col="origen">
                <?php if (!$propio): ?>
                <span title="Viene del historial propio del módulo, de antes de la bitácora">
                    <i class="fas fa-clock-rotate-left"></i>
                    <?= htmlspecialchars($fuentes[$f['origen']]['titulo'] ?? (string) $f['origen']) ?>
                </span>
                <?php else: ?>
                Bitácora
                <?php endif; ?>
            </td>
            <?= Columnas::celdasExtra('bitacora', $f) ?>
            <td>
                <?php if ($propio): ?>
                <button type="button" class="btn btn-outline btn-sm bit-ver"
                        data-id="<?= (int) $f['ref_id'] ?>" title="Ver todo el detalle">
                    <i class="fas fa-magnifying-glass"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($paginas > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:9px;margin:12px 0;">
    <?php if ($pagina > 1): ?>
    <a class="btn btn-outline btn-sm" href="?<?= $qs(['pagina' => $pagina - 1]) ?>">← Anterior</a>
    <?php endif; ?>
    <span style="font-size:12px;color:var(--text-muted);">Página <?= $pagina ?> de <?= $paginas ?></span>
    <?php if ($pagina < $paginas): ?>
    <a class="btn btn-outline btn-sm" href="?<?= $qs(['pagina' => $pagina + 1]) ?>">Siguiente →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ══ El detalle de un renglón ════════════════════════════════════════ -->
<div class="bit-modal" id="bit-modal" hidden>
    <div class="bit-modal-caja">
        <header>
            <strong>Detalle del movimiento</strong>
            <button type="button" class="btn btn-outline btn-sm" id="bit-cerrar">
                <i class="fas fa-xmark"></i>
            </button>
        </header>
        <div id="bit-modal-cuerpo">Cargando…</div>
    </div>
</div>

<style>
.bit-resumen {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    background: #fff; border: 1px solid var(--border-light, #E4ECF7);
    border-radius: 9px; padding: 11px 14px; margin-bottom: 10px;
}
.bit-dato { display: flex; flex-direction: column; gap: 1px; }
.bit-dato.ancho { padding-left: 16px; border-left: 1px solid var(--border-light, #E4ECF7); }
.bit-dato-num { font-size: 19px; font-weight: 800; color: var(--navy); line-height: 1; }
.bit-dato-txt { font-size: 13px; font-weight: 700; color: var(--navy); }
.bit-dato-tag { font-size: 10.5px; color: var(--text-muted, #5A6E8A); text-transform: uppercase; letter-spacing: .4px; }
.bit-dato.alerta .bit-dato-num { color: var(--miss, #B91C1C); }

.bit-depurar {
    display: none; align-items: center; gap: 8px; flex-wrap: wrap;
    background: #FFFDF7; border: 1px solid #F3D28A; border-radius: 9px;
    padding: 10px 13px; margin-bottom: 10px; font-size: 12.5px; color: #1a2b4a;
}
.bit-depurar.abierta { display: flex; }
.bit-depurar-nota { flex-basis: 100%; font-size: 11px; color: var(--text-muted, #5A6E8A); line-height: 1.4; }

.bit-filtros {
    display: flex; align-items: flex-end; gap: 9px; flex-wrap: wrap;
    background: #fff; border: 1px solid var(--border-light, #E4ECF7);
    border-radius: 9px; padding: 11px 13px;
}
.bit-filtro { display: flex; flex-direction: column; gap: 3px; min-width: 130px; }
.bit-filtro label {
    font-size: 10.5px; font-weight: 700; color: var(--navy);
    text-transform: uppercase; letter-spacing: .4px;
}
.bit-filtro .form-control { font-size: 12.5px; padding: 6px 8px; }
.bit-filtro-acciones { display: flex; gap: 6px; margin-left: auto; }

.bit-historial {
    display: flex; align-items: center; gap: 6px; cursor: pointer;
    font-size: 12.5px; color: var(--navy); padding-bottom: 5px;
}
.bit-historial input { width: 15px; height: 15px; accent-color: var(--gold); cursor: pointer; }

.bit-tabla td { vertical-align: top; }
.bit-fila.denegada { background: #FEF6F6; }
.bit-cuando { font-size: 12px; color: var(--navy); white-space: nowrap; }
.bit-cuando span { margin-left: 4px; font-size: 11px; color: var(--text-muted, #5A6E8A); }
.bit-quien { font-size: 12.5px; font-weight: 600; color: var(--navy); }
.bit-accion { font-size: 12.5px; color: #1a2b4a; }
.bit-detalle {
    font-size: 11.5px; color: var(--text-muted, #5A6E8A); line-height: 1.4;
    overflow-wrap: anywhere;
}
.bit-origen { font-size: 11px; color: #94A3B8; white-space: nowrap; }

.bit-modal {
    position: fixed; inset: 0; background: rgba(12,36,97,.34);
    display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 900;
}
/* El display:flex de arriba gana sobre el hidden del navegador: sin esta
   regla el cuadro nace abierto y tapa la lista sin poder cerrarse. */
.bit-modal[hidden] { display: none; }
.bit-modal-caja {
    background: #fff; border-radius: 11px; width: min(680px, 100%);
    max-height: 82vh; display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 18px 46px rgba(12,36,97,.24);
}
.bit-modal-caja header {
    display: flex; align-items: center; justify-content: space-between; gap: 9px;
    padding: 11px 14px; border-bottom: 1px solid var(--border-light, #E4ECF7); background: #F7FAFF;
    font-size: 13.5px; color: var(--navy);
}
#bit-modal-cuerpo { padding: 13px 15px; overflow: auto; font-size: 12.5px; color: #1a2b4a; }
.bit-campo { display: grid; grid-template-columns: 130px 1fr; gap: 8px; padding: 5px 0;
             border-bottom: 1px dashed var(--border-light, #E4ECF7); }
.bit-campo:last-child { border-bottom: none; }
.bit-campo b { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted, #5A6E8A); }
.bit-campo span { overflow-wrap: anywhere; }
.bit-cambio { color: #1a2b4a; }
.bit-cambio del { color: #B91C1C; text-decoration: line-through; }
.bit-cambio ins { color: #0B6B5A; text-decoration: none; }
</style>

<script>
(function () {
    var base = <?= json_encode($baseUrl) ?>;

    // Depurar viene plegado: es una acción destructiva y no tiene por qué
    // estar ocupando sitio encima de la lista todos los días.
    var abrir = document.getElementById('bit-abrir-depurar');
    var panel = document.getElementById('bit-depurar');
    if (abrir && panel) {
        abrir.addEventListener('click', function () { panel.classList.toggle('abierta'); });
    }

    var modal  = document.getElementById('bit-modal');
    var cuerpo = document.getElementById('bit-modal-cuerpo');
    var cerrar = document.getElementById('bit-cerrar');
    if (!modal) return;

    function escapar(texto) {
        var d = document.createElement('div');
        d.textContent = texto === null || texto === undefined ? '' : String(texto);
        return d.innerHTML;
    }

    function campo(etiqueta, valor) {
        if (valor === null || valor === undefined || valor === '') return '';
        return '<div class="bit-campo"><b>' + escapar(etiqueta) + '</b><span>' + valor + '</span></div>';
    }

    function pintarDetalle(detalle) {
        if (!detalle) return '';
        var html = '';

        if (detalle.cambios) {
            Object.keys(detalle.cambios).forEach(function (campoNombre) {
                var c = detalle.cambios[campoNombre];
                html += campo(campoNombre,
                    '<span class="bit-cambio"><del>' + escapar(c.antes) + '</del> → <ins>'
                    + escapar(c.despues) + '</ins></span>');
            });
        }

        Object.keys(detalle).forEach(function (clave) {
            if (clave === 'cambios') return;
            var valor = detalle[clave];
            if (valor && typeof valor === 'object') {
                valor = Object.keys(valor).map(function (k) {
                    return k + ': ' + (typeof valor[k] === 'object' ? JSON.stringify(valor[k]) : valor[k]);
                }).join(', ');
            }
            html += campo(clave, escapar(valor));
        });

        return html;
    }

    function abrirDetalle(id) {
        modal.hidden = false;
        cuerpo.textContent = 'Cargando…';

        fetch(base + '/bitacora/detalle/' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    cuerpo.textContent = (d && d.message) || 'No se pudo leer ese registro.';
                    return;
                }
                var f = d.fila;
                cuerpo.innerHTML =
                      campo('Cuándo',    escapar(f.fecha))
                    + campo('Quién',     escapar(f.usuario) + (f.rol ? ' <small>(' + escapar(f.rol) + ')</small>' : ''))
                    + campo('Empresa',   escapar(f.empresa))
                    + campo('Qué hizo',  escapar(f.accion))
                    + campo('Módulo',    escapar(f.modulo))
                    + campo('Permiso',   escapar(f.permiso))
                    + campo('Sobre',     escapar(f.entidad))
                    + campo('Resultado', escapar(f.resultado))
                    + campo('Dirección', escapar(f.metodo + ' ' + f.ruta))
                    + campo('Desde',     escapar(f.ip))
                    + pintarDetalle(f.detalle);
            })
            .catch(function () {
                cuerpo.textContent = 'No se pudo contactar con el servidor.';
            });
    }

    document.querySelectorAll('.bit-ver').forEach(function (boton) {
        boton.addEventListener('click', function () { abrirDetalle(boton.getAttribute('data-id')); });
    });

    function cerrarModal() { modal.hidden = true; }
    if (cerrar) cerrar.addEventListener('click', cerrarModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) cerrarModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarModal(); });
})();
</script>
