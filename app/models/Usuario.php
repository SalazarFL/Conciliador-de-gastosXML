<?php
require_once __DIR__ . '/../core/Model.php';

class Usuario extends Model
{
    protected $table = 'usuarios';

    public function findByUsername(string $username): ?array
    {
        $row = $this->fetchOne(
            "SELECT * FROM usuarios WHERE username = ? LIMIT 1",
            [$username]
        );
        return $row ?: null;
    }

    public function getAll(): array
    {
        return $this->fetchAll(
            "SELECT id, nombre, username, email, activo, is_admin, ultimo_acceso, created_at
             FROM usuarios ORDER BY id ASC"
        );
    }

    public function create(array $data): int
    {
        return (int) $this->insert(
            "INSERT INTO usuarios (nombre, username, email, password_hash, activo, is_admin)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['nombre'],
                $data['username'],
                $data['email'],
                password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                (int) ($data['activo'] ?? 1),
                (int) ($data['is_admin'] ?? 0),
            ]
        );
    }

    public function updateInfo(int $id, array $data): void
    {
        $this->execute(
            "UPDATE usuarios SET nombre=?, username=?, email=?, activo=?, is_admin=?, updated_at=NOW()
             WHERE id=?",
            [
                $data['nombre'],
                $data['username'],
                $data['email'],
                (int) ($data['activo'] ?? 1),
                (int) ($data['is_admin'] ?? 0),
                $id,
            ]
        );
    }

    public function updatePassword(int $id, string $newPassword): void
    {
        $this->execute(
            "UPDATE usuarios SET password_hash=?, updated_at=NOW() WHERE id=?",
            [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $id]
        );
    }

    public function deleteById(int $id): void
    {
        $this->execute("DELETE FROM usuarios WHERE id=?", [$id]);
    }

    public function usernameExists(string $username, int $excludeId = 0): bool
    {
        $count = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios WHERE username=? AND id!=?",
            [$username, $excludeId]
        );
        return $count > 0;
    }

    public function emailExists(string $email, int $excludeId = 0): bool
    {
        $count = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM usuarios WHERE email=? AND id!=?",
            [$email, $excludeId]
        );
        return $count > 0;
    }

    public function updateLastAccess(int $id): void
    {
        $this->execute(
            "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
            [$id]
        );
    }
}
