<?php
$baseUrl = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$old     = $old ?? [];
$error   = $error ?? null;
?>

<div class="page-header">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:var(--navy);">
            <i class="fas fa-user-plus" style="color:var(--gold);margin-right:8px;"></i>Nuevo Usuario
        </h1>
        <p style="font-size:13px;color:var(--text-muted);margin-top:3px;">
            Crea una nueva cuenta de acceso al sistema
        </p>
    </div>
    <a href="<?= $baseUrl ?>/usuarios" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Volver
    </a>
</div>

<div class="card" style="max-width:560px;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-id-card" style="margin-right:6px;color:var(--navy-light);"></i>Datos del Usuario
        </div>
    </div>

    <?php if ($error): ?>
    <div style="margin:0 16px;padding:8px 11px;background:#fff5f5;border:1px solid #fca5a5;border-radius:8px;color:#b91c1c;font-size:12.5px;display:flex;align-items:center;gap:7px;">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= $baseUrl ?>/usuarios/crear" style="padding:12px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">

            <div style="grid-column:1/-1;">
                <label class="form-label-inline">Nombre completo <span style="color:#e53e3e">*</span></label>
                <input type="text" name="nombre" class="form-control-inline"
                       value="<?= htmlspecialchars($old['nombre'] ?? '') ?>"
                       placeholder="Ej. Juan Pérez" required>
            </div>

            <div>
                <label class="form-label-inline">Nombre de usuario <span style="color:#e53e3e">*</span></label>
                <input type="text" name="username" class="form-control-inline"
                       value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                       placeholder="Ej. jperez" required autocomplete="off">
            </div>

            <div>
                <label class="form-label-inline">Correo electrónico <span style="color:#e53e3e">*</span></label>
                <input type="email" name="email" class="form-control-inline"
                       value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                       placeholder="correo@ejemplo.com" required>
            </div>

            <div>
                <label class="form-label-inline">Contraseña <span style="color:#e53e3e">*</span></label>
                <input type="password" name="password" class="form-control-inline"
                       placeholder="Mínimo 6 caracteres" required autocomplete="new-password">
            </div>

            <div>
                <label class="form-label-inline">Confirmar contraseña <span style="color:#e53e3e">*</span></label>
                <input type="password" name="password_confirm" class="form-control-inline"
                       placeholder="Repite la contraseña" required autocomplete="new-password">
            </div>

        </div>

        <div style="display:flex;gap:9px;margin-top:10px;padding-top:9px;border-top:1px solid var(--border-light,#E4ECF7);">
            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:var(--navy);">
                <input type="checkbox" name="activo" value="1"
                       <?= isset($old['activo']) || empty($old) ? 'checked' : '' ?>
                       style="width:16px;height:16px;accent-color:var(--gold);">
                <span><strong>Cuenta activa</strong></span>
            </label>
            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:var(--navy);">
                <input type="checkbox" name="is_admin" value="1"
                       <?= !empty($old['is_admin']) ? 'checked' : '' ?>
                       style="width:16px;height:16px;accent-color:var(--gold);">
                <span><strong>Administrador</strong> <span style="font-size:12px;color:var(--text-muted);">(acceso a Gestión de Usuarios)</span></span>
            </label>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:16px;">
            <a href="<?= $baseUrl ?>/usuarios" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Crear Usuario
            </button>
        </div>
    </form>
</div>

<style>
.form-label-inline {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: var(--navy);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 3px;
}
.form-control-inline {
    width: 100%;
    padding: 7px 9px;
    border: 1.5px solid #dde6f0;
    border-radius: 7px;
    font-size: 13px;
    color: #1a2b4a;
    background: #f7fafd;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.form-control-inline:focus {
    outline: none;
    border-color: var(--gold);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(240,165,0,.12);
}
</style>
