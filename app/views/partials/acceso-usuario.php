<?php
/**
 * El bloque de acceso de un usuario: su rol y a qué empresas llega.
 *
 * Lo comparten el alta y la edición porque es la misma decisión en los dos
 * sitios, y tenerla dos veces garantizaba que una de las dos se quedara atrás.
 *
 * Espera: $roles, $sociedades, $sociedadesDelUsuario, $old y, en edición,
 * $usuario y $isSelf.
 */
$roles      = is_array($roles ?? null) ? $roles : [];
$sociedades = is_array($sociedades ?? null) ? $sociedades : [];
$asignadas  = array_flip(array_map('intval', (array) ($sociedadesDelUsuario ?? [])));
$old        = is_array($old ?? null) ? $old : [];
$usuario    = $usuario ?? null;
$isSelf     = !empty($isSelf);

// Al volver de un error mandan las casillas que la persona dejó puestas.
if ($old) {
    $asignadas = array_flip(array_map('intval', (array) ($old['sociedades'] ?? [])));
}

$rolElegido = (int) ($old['rol_id'] ?? $usuario['rol_id'] ?? 0);

/*
 * Con qué empresas trabaja: 'todas' o 'algunas'. Es una elección obligatoria y
 * no una casilla que se puede dejar sin tocar.
 *
 * Antes esto era "Limitar a ciertas empresas" desmarcado por omisión, y no
 * marcarlo significaba "todas". El problema no era el valor sino que nadie
 * decidía: dar de alta a alguien sin pensar en las empresas le daba acceso a
 * todo el grupo, y de la pantalla no se distinguía "quiero que vea todas" de
 * "no me fijé". Con dos opciones y ninguna preseleccionada en el alta, hay que
 * decirlo.
 */
$alcance = $old['alcance_empresas'] ?? null;
if ($alcance !== 'todas' && $alcance !== 'algunas') {
    // En edición se deduce de lo que ya tiene; en el alta no hay nada elegido.
    $alcance = $usuario !== null ? (!empty($asignadas) ? 'algunas' : 'todas') : '';
}
?>

<div class="acc-bloque">

    <!-- ── Rol ─────────────────────────────────────────────────────────── -->
    <div class="acc-fila">
        <div>
            <label class="form-label-inline">Rol <span style="color:#e53e3e">*</span></label>
            <select name="rol_id" class="form-control-inline" required <?= $isSelf ? 'disabled' : '' ?>>
                <option value="">Elige un rol…</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= $rolElegido === (int) $r['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $r['nombre']) ?><?= !empty($r['es_admin']) ? ' — lo puede todo' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($isSelf): ?>
            <input type="hidden" name="rol_id" value="<?= (int) ($usuario['rol_id'] ?? 0) ?>">
            <?php endif; ?>
        </div>
        <p class="acc-ayuda">
            El rol es lo que decide a qué módulos entra y qué puede hacer dentro de
            cada uno. Se arman en
            <a href="<?= (defined('APP_URL') ? APP_URL : '') ?>/configuracion?ir=roles">Configuración → Roles</a>.
            <?php if ($isSelf): ?>
            <br><strong>No puedes cambiar tu propio rol:</strong> si te quitaras un
            permiso que estás usando, te quedarías fuera de esta misma pantalla.
            <?php endif; ?>
        </p>
    </div>

    <!-- ── Empresas ────────────────────────────────────────────────────── -->
    <?php if ($sociedades): ?>
    <div class="acc-fila">
        <div>
            <label class="form-label-inline">Empresas <span style="color:#e53e3e">*</span></label>
            <label class="acc-check">
                <input type="radio" name="alcance_empresas" value="todas"
                       class="acc-alcance" <?= $alcance === 'todas' ? 'checked' : '' ?> required>
                <span>Todas las empresas del grupo</span>
            </label>
            <label class="acc-check">
                <input type="radio" name="alcance_empresas" value="algunas"
                       class="acc-alcance" <?= $alcance === 'algunas' ? 'checked' : '' ?> required>
                <span>Solo las que se marquen</span>
            </label>
        </div>
        <p class="acc-ayuda">
            Hay que elegir una de las dos: es lo que decide con qué empresas
            trabaja esta persona —en los listados, en el selector de Inicio y en
            lo que se importa—. Con "solo las que se marquen" hay que marcar al
            menos una. Los administradores las ven todas de cualquier forma.
        </p>
    </div>

    <div class="acc-empresas" id="acc-empresas" <?= $alcance === 'algunas' ? '' : 'hidden' ?>>
        <?php foreach ($sociedades as $s): ?>
        <label class="acc-empresa">
            <input type="checkbox" name="sociedades[]" value="<?= (int) $s['id'] ?>"
                   <?= isset($asignadas[(int) $s['id']]) ? 'checked' : '' ?>>
            <span>
                <?= htmlspecialchars((string) $s['nombre']) ?>
                <small><?= htmlspecialchars((string) ($s['cedula'] ?? '')) ?></small>
            </span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.acc-bloque {
    margin-top: 14px; padding: 11px; background: #f8fafd;
    border: 1px solid #dde6f0; border-radius: 8px;
}
.acc-fila {
    display: grid; grid-template-columns: minmax(190px, 1fr) 1.4fr; gap: 12px;
    align-items: start; padding-bottom: 9px;
}
.acc-fila + .acc-fila { border-top: 1px dashed #dde6f0; padding-top: 9px; }
.acc-ayuda { font-size: 11.5px; color: var(--text-muted, #5A6E8A); line-height: 1.45; margin: 0; }
.acc-ayuda a { color: var(--navy-light); font-weight: 600; }

.acc-check {
    display: flex; align-items: center; gap: 7px; cursor: pointer;
    font-size: 13px; color: var(--navy); margin-top: 3px;
}
.acc-check input { width: 16px; height: 16px; accent-color: var(--gold); cursor: pointer; }
.acc-check + .acc-check { margin-top: 1px; }

.acc-empresas {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 3px; margin-top: 6px; padding-top: 9px; border-top: 1px dashed #dde6f0;
}
.acc-empresa {
    display: flex; align-items: center; gap: 7px; cursor: pointer;
    padding: 5px 6px; border-radius: 6px; font-size: 12.5px; color: #1a2b4a;
}
.acc-empresa:hover { background: #fff; }
.acc-empresa input { width: 15px; height: 15px; accent-color: var(--gold); cursor: pointer; }
.acc-empresa small { display: block; font-size: 10.5px; color: #94A3B8; }
</style>

<script>
(function () {
    var opciones = document.querySelectorAll('.acc-alcance');
    var lista    = document.getElementById('acc-empresas');
    if (!opciones.length || !lista) return;

    function elegida() {
        for (var i = 0; i < opciones.length; i++) {
            if (opciones[i].checked) return opciones[i].value;
        }
        return '';
    }

    function refrescar() {
        var algunas = elegida() === 'algunas';
        lista.hidden = !algunas;
        // Que el navegador lo pida antes de mandar el formulario: llegar al
        // servidor para que conteste "marca al menos una" es un viaje de más.
        var casillas = lista.querySelectorAll('input[type=checkbox]');
        var alguna = false;
        for (var i = 0; i < casillas.length; i++) {
            if (casillas[i].checked) { alguna = true; break; }
        }
        for (var j = 0; j < casillas.length; j++) {
            casillas[j].required = algunas && !alguna;
        }
    }

    for (var i = 0; i < opciones.length; i++) {
        opciones[i].addEventListener('change', refrescar);
    }
    lista.addEventListener('change', refrescar);
    refrescar();
})();
</script>
