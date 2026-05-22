<?php
$baseUrl  = defined('APP_URL') ? APP_URL : '/xmlconcilia/public';
$usuarios = $usuarios ?? [];
$selfId   = (int) ($_SESSION['user_id'] ?? 0);
?>

<div class="page-header">
    <div>
        <h1 style="font-size:20px;font-weight:800;color:var(--navy);">
            <i class="fas fa-users-cog" style="color:var(--gold);margin-right:8px;"></i>Gestión de Usuarios
        </h1>
        <p style="font-size:13px;color:var(--text-muted);margin-top:3px;">
            Administra los usuarios con acceso al sistema
        </p>
    </div>
    <a href="<?= $baseUrl ?>/usuarios/crear" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> Nuevo Usuario
    </a>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">
                <i class="fas fa-list" style="margin-right:6px;color:var(--navy-light);"></i>Usuarios del Sistema
            </div>
            <div class="card-subtitle"><?= count($usuarios) ?> usuario<?= count($usuarios) !== 1 ? 's' : '' ?> registrado<?= count($usuarios) !== 1 ? 's' : '' ?></div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Usuario</th>
                    <th>Correo</th>
                    <th class="center">Rol</th>
                    <th class="center">Estado</th>
                    <th class="center">Último acceso</th>
                    <th class="center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usuarios)): ?>
                <tr class="empty-row">
                    <td colspan="8">
                        <i class="fas fa-users" style="font-size:28px;color:var(--border);display:block;margin-bottom:8px;"></i>
                        No hay usuarios registrados.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td class="muted" style="font-size:12px;"><?= (int)$u['id'] ?></td>
                    <td>
                        <span style="font-weight:700;color:var(--navy);">
                            <?= htmlspecialchars($u['nombre']) ?>
                        </span>
                        <?php if ((int)$u['id'] === $selfId): ?>
                            <span style="font-size:10px;background:#e8f5e9;color:#2e7d32;padding:2px 7px;border-radius:20px;margin-left:6px;font-weight:600;">Tú</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= htmlspecialchars($u['username']) ?></td>
                    <td class="muted" style="font-size:12px;"><?= htmlspecialchars($u['email']) ?></td>
                    <td class="center">
                        <?php if ((int)$u['is_admin']): ?>
                            <span class="badge badge-navy"><i class="fas fa-shield-alt"></i> Admin</span>
                        <?php else: ?>
                            <span class="badge" style="background:#f0f4f8;color:#4a5568;">
                                <i class="fas fa-user"></i> Usuario
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="center">
                        <?php if ((int)$u['activo']): ?>
                            <span class="badge badge-green"><i class="fas fa-check-circle"></i> Activo</span>
                        <?php else: ?>
                            <span class="badge" style="background:#fff5f5;color:#c53030;border:1px solid #fed7d7;">
                                <i class="fas fa-ban"></i> Inactivo
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="center muted" style="font-size:12px;">
                        <?= $u['ultimo_acceso'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($u['ultimo_acceso']))) : '—' ?>
                    </td>
                    <td class="center">
                        <div style="display:flex;gap:6px;justify-content:center;">
                            <a href="<?= $baseUrl ?>/usuarios/editar/<?= (int)$u['id'] ?>"
                               class="btn btn-outline btn-sm" title="Editar">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                            <?php if ((int)$u['id'] !== $selfId): ?>
                            <form method="post" action="<?= $baseUrl ?>/usuarios/eliminar/<?= (int)$u['id'] ?>"
                                  onsubmit="return confirm('¿Eliminar a <?= htmlspecialchars(addslashes($u['nombre'])) ?>? Esta acción no se puede deshacer.')">
                                <button type="submit" class="btn btn-sm"
                                        style="background:#fff5f5;border:1.5px solid #fed7d7;color:#c53030;"
                                        title="Eliminar">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
