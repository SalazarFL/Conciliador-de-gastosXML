<?php
/**
 * Crear o editar un rol.
 *
 * La parte que importa es la matriz: un bloque por módulo y una casilla por
 * cosa que se puede hacer, con el texto en castellano de lo que concede. No
 * son claves técnicas en una lista alfabética porque quien reparte permisos
 * no piensa en 'por_pagar.eliminar', piensa en "que no pueda borrar pagos".
 *
 * Cada módulo tiene un "Todo" que marca su bloque entero, que es como se
 * reparte de verdad: casi siempre se da o se quita un módulo completo, y las
 * excepciones son dos o tres casillas.
 */
$baseUrl  = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$rol      = $rol ?? null;
$error    = $error ?? null;
$old      = $old ?? [];
$modulos  = $modulos ?? [];
$marcados = array_flip($permisosActuales ?? []);
$usuariosConEsteRol = (int) ($usuariosConEsteRol ?? 0);

$esNuevo    = $rol === null;
$esAdmin    = !$esNuevo && !empty($rol['es_admin']);
$esSistema  = !$esNuevo && !empty($rol['es_sistema']);
$accion     = $esNuevo ? $baseUrl . '/roles/crear' : $baseUrl . '/roles/editar/' . (int) $rol['id'];

$valor = function (string $campo, string $porOmision = '') use ($old, $rol) {
    if (array_key_exists($campo, $old)) {
        return (string) $old[$campo];
    }
    return (string) ($rol[$campo] ?? $porOmision);
};

$tituloPagina = $esNuevo ? 'Nuevo rol' : 'Editar rol: ' . $rol['nombre'];
?>

<div class="card" style="max-width:980px;">
    <div class="card-header">
        <div class="card-title" style="margin-right:auto;">
            <i class="fas fa-shield-halved" style="margin-right:6px;color:var(--gold);"></i>
            <?= $esNuevo ? 'Nuevo rol' : 'Editar rol' ?>
        </div>
        <a href="<?= $baseUrl ?>/configuracion?ir=roles" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>

    <?php if ($error): ?>
    <div style="margin:0 16px;padding:8px 11px;background:#fff5f5;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;font-size:12.5px;display:flex;align-items:center;gap:7px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= $accion ?>" style="padding:12px;">

        <!-- ── Identidad del rol ───────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:minmax(200px,1fr) 2fr;gap:12px;">
            <div>
                <label class="form-label-inline">Nombre del rol <span style="color:#e53e3e">*</span></label>
                <input type="text" name="nombre" class="form-control-inline" maxlength="60"
                       value="<?= htmlspecialchars($valor('nombre')) ?>"
                       placeholder="Ej. Contabilidad" required
                       <?= $esAdmin ? 'readonly title="El rol de administrador conserva su nombre"' : '' ?>>
            </div>
            <div>
                <label class="form-label-inline">Para qué es</label>
                <input type="text" name="descripcion" class="form-control-inline" maxlength="255"
                       value="<?= htmlspecialchars($valor('descripcion')) ?>"
                       placeholder="Una línea que explique a quién se le pone este rol">
            </div>
        </div>

        <?php if ($esAdmin): ?>
        <div class="rol-aviso" style="margin-top:12px;">
            <i class="fas fa-shield-halved"></i>
            <div>
                <strong>Este rol lo puede todo.</strong>
                No tiene permisos marcados porque los tiene todos, incluidos los que se
                añadan en el futuro. Por eso no se le pueden desmarcar: dejarlo a medias
                sería la forma de cerrar la instalación sin querer.
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$esNuevo && $usuariosConEsteRol > 0): ?>
        <div class="rol-aviso warn" style="margin-top:12px;">
            <i class="fas fa-users"></i>
            <div>
                <strong><?= $usuariosConEsteRol ?> usuario<?= $usuariosConEsteRol === 1 ? '' : 's' ?></strong>
                tiene<?= $usuariosConEsteRol === 1 ? '' : 'n' ?> este rol. Lo que cambies aquí
                les cambia lo que pueden hacer en su siguiente pantalla, no hace falta que
                vuelvan a entrar.
            </div>
        </div>
        <?php endif; ?>

        <!-- ── La matriz ───────────────────────────────────────────────── -->
        <?php if (!$esAdmin): ?>
        <div style="display:flex;align-items:center;gap:9px;margin:16px 0 8px;">
            <div style="font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.5px;">
                Qué puede hacer
            </div>
            <div style="flex:1;height:1px;background:var(--border-light,#E4ECF7);"></div>
            <span id="rol-cuenta" class="badge badge-navy" style="font-size:11px;"></span>
            <button type="button" class="btn btn-outline btn-sm" data-rol-todo="1">Marcar todo</button>
            <button type="button" class="btn btn-outline btn-sm" data-rol-todo="0">Quitar todo</button>
        </div>

        <div class="rol-matriz">
            <?php foreach ($modulos as $idModulo => $modulo): ?>
            <?php
                $permisos = $modulo['permisos'] ?? [];
                $delModulo = array_keys($permisos);
                $marcadosAqui = count(array_intersect($delModulo, array_keys($marcados)));
                $esAdministracion = $idModulo === 'administracion';
            ?>
            <section class="rol-modulo<?= $esAdministracion ? ' delicado' : '' ?>" data-rol-modulo="<?= htmlspecialchars($idModulo) ?>">
                <header>
                    <span class="rol-modulo-icono"><i class="fas <?= htmlspecialchars($modulo['icono'] ?? 'fa-cube') ?>"></i></span>
                    <div style="flex:1;min-width:0;">
                        <div class="rol-modulo-titulo"><?= htmlspecialchars($modulo['titulo'] ?? $idModulo) ?></div>
                        <div class="rol-modulo-resumen"><?= htmlspecialchars($modulo['resumen'] ?? '') ?></div>
                    </div>
                    <label class="rol-todo" title="Marcar o quitar todo este módulo">
                        <input type="checkbox" data-rol-modulo-todo
                               <?= $marcadosAqui === count($delModulo) && $delModulo ? 'checked' : '' ?>>
                        <span>Todo</span>
                    </label>
                </header>

                <?php if ($esAdministracion): ?>
                <p class="rol-nota">
                    Con esto se reparten los accesos de los demás. Dáselo solo a quien
                    de verdad administre el sistema.
                </p>
                <?php endif; ?>

                <div class="rol-permisos">
                    <?php foreach ($permisos as $clave => $texto): ?>
                    <label class="rol-permiso">
                        <input type="checkbox" name="permisos[]" value="<?= htmlspecialchars($clave) ?>"
                               <?= isset($marcados[$clave]) ? 'checked' : '' ?>>
                        <span>
                            <?= htmlspecialchars($texto) ?>
                            <code><?= htmlspecialchars($clave) ?></code>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:16px;padding-top:12px;border-top:1px solid var(--border-light,#E4ECF7);">
            <a href="<?= $baseUrl ?>/configuracion?ir=roles" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $esNuevo ? 'Crear rol' : 'Guardar cambios' ?>
            </button>
        </div>

        <?php if ($esSistema): ?>
        <div style="margin-top:9px;font-size:11.5px;color:var(--text-muted);text-align:right;">
            <i class="fas fa-lock"></i> Este rol es parte del sistema: se puede editar, pero no eliminar.
        </div>
        <?php endif; ?>
    </form>
</div>

<style>
.form-label-inline {
    display: block; font-size: 11px; font-weight: 700; color: var(--navy);
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px;
}
.form-control-inline {
    width: 100%; padding: 7px 9px; border: 1.5px solid #dde6f0; border-radius: 7px;
    font-size: 13px; color: #1a2b4a; background: #f7fafd;
    transition: border-color .2s, box-shadow .2s; box-sizing: border-box;
}
.form-control-inline:focus {
    outline: none; border-color: var(--gold); background: #fff;
    box-shadow: 0 0 0 3px rgba(240,165,0,.12);
}
.form-control-inline[readonly] { background: #eef2f8; color: #5A6E8A; }

.rol-aviso {
    display: flex; gap: 9px; align-items: flex-start;
    background: #F7FAFF; border: 1px solid #DDE6F0; border-radius: 8px;
    padding: 9px 11px; font-size: 12.5px; color: #1a2b4a; line-height: 1.45;
}
.rol-aviso i { color: var(--navy-light); margin-top: 2px; }
.rol-aviso.warn { background: var(--warn-bg, #FFF8E7); border-color: #F3D28A; }
.rol-aviso.warn i { color: var(--warn, #A66A00); }

.rol-matriz {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 10px;
}
.rol-modulo {
    border: 1px solid var(--border-light, #E4ECF7); border-radius: 9px;
    background: #fff; overflow: hidden;
}
.rol-modulo.delicado { border-color: #F3D28A; background: #FFFDF7; }
.rol-modulo header {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 11px; background: #F7FAFF; border-bottom: 1px solid var(--border-light, #E4ECF7);
}
.rol-modulo.delicado header { background: #FFF8E7; border-bottom-color: #F3D28A; }
.rol-modulo-icono {
    width: 26px; height: 26px; border-radius: 7px; display: grid; place-items: center;
    background: rgba(12,36,97,.08); color: var(--navy); font-size: 12.5px; flex: none;
}
.rol-modulo.delicado .rol-modulo-icono { background: rgba(240,165,0,.16); color: var(--gold-dark, #A66A00); }
.rol-modulo-titulo { font-size: 13px; font-weight: 700; color: var(--navy); }
.rol-modulo-resumen { font-size: 11px; color: var(--text-muted, #5A6E8A); line-height: 1.3; }

.rol-todo {
    display: flex; align-items: center; gap: 5px; cursor: pointer; flex: none;
    font-size: 11px; font-weight: 700; color: var(--navy-light); text-transform: uppercase;
    letter-spacing: .4px;
}
.rol-todo input { width: 15px; height: 15px; accent-color: var(--gold); cursor: pointer; }

.rol-nota {
    margin: 0; padding: 7px 11px 0; font-size: 11.5px; color: var(--warn, #A66A00); line-height: 1.4;
}

.rol-permisos { padding: 6px 11px 10px; display: flex; flex-direction: column; gap: 2px; }
.rol-permiso {
    display: flex; align-items: flex-start; gap: 8px; cursor: pointer;
    padding: 5px 6px; border-radius: 6px; font-size: 12.5px; color: #1a2b4a; line-height: 1.35;
}
.rol-permiso:hover { background: #F7FAFF; }
.rol-permiso input { width: 15px; height: 15px; accent-color: var(--gold); cursor: pointer; margin-top: 1px; flex: none; }
.rol-permiso code {
    display: block; font-size: 10.5px; color: #94A3B8; font-family: ui-monospace, Menlo, monospace;
}
</style>

<script>
(function () {
    var form = document.querySelector('form[action*="/roles/"]');
    if (!form) return;

    var cuenta = document.getElementById('rol-cuenta');

    function casillas(dentro) {
        return Array.prototype.slice.call((dentro || form).querySelectorAll('input[name="permisos[]"]'));
    }

    function refrescar() {
        var todas = casillas();
        var marcadas = todas.filter(function (c) { return c.checked; });

        if (cuenta) {
            cuenta.textContent = marcadas.length + ' de ' + todas.length + ' permisos';
        }

        // El "Todo" de cada módulo refleja lo que hay dentro, incluido el
        // estado a medias: sin eso parecería que el módulo está completo
        // cuando solo tiene una casilla puesta.
        form.querySelectorAll('[data-rol-modulo]').forEach(function (modulo) {
            var suyas = casillas(modulo);
            var suyasMarcadas = suyas.filter(function (c) { return c.checked; }).length;
            var todo = modulo.querySelector('[data-rol-modulo-todo]');
            if (!todo) return;
            todo.checked = suyas.length > 0 && suyasMarcadas === suyas.length;
            todo.indeterminate = suyasMarcadas > 0 && suyasMarcadas < suyas.length;
        });
    }

    form.addEventListener('change', function (e) {
        var todo = e.target.closest('[data-rol-modulo-todo]');
        if (todo) {
            var modulo = e.target.closest('[data-rol-modulo]');
            casillas(modulo).forEach(function (c) { c.checked = e.target.checked; });
        }
        refrescar();
    });

    form.querySelectorAll('[data-rol-todo]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var valor = boton.getAttribute('data-rol-todo') === '1';
            casillas().forEach(function (c) { c.checked = valor; });
            refrescar();
        });
    });

    refrescar();
})();
</script>
