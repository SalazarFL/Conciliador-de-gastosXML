<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['create', 'delete'], true)) {
    fwrite(STDERR, "Uso: php usuario-smoke-migracion.php create|delete\n");
    exit(1);
}

$rootSecretFile = getenv('XMLCONCILIA_MARIADB_ROOT_SECRET')
    ?: 'C:/WebServer/secrets/mariadb114-root.txt';
$rootPassword = is_file($rootSecretFile)
    ? trim((string) file_get_contents($rootSecretFile))
    : '';

if ($rootPassword === '') {
    fwrite(STDERR, "No se encontro la credencial raiz protegida.\n");
    exit(1);
}

$dbPort = (int) (getenv('XMLCONCILIA_SMOKE_DB_PORT') ?: 3307);
if ($dbPort < 1 || $dbPort > 65535) {
    fwrite(STDERR, "Puerto de base invalido para la prueba.\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host=127.0.0.1;port={$dbPort};dbname=bd_xmlconcilia;charset=utf8mb4",
    'root',
    $rootPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$username = '__migration_smoke__';
$email = 'migration-smoke@invalid.local';
$delete = $pdo->prepare('DELETE FROM usuarios WHERE username = ? OR email = ?');
$delete->execute([$username, $email]);

if ($action === 'delete') {
    echo "Usuario temporal eliminado.\n";
    exit(0);
}

$password = bin2hex(random_bytes(24));
$insert = $pdo->prepare(
    'INSERT INTO usuarios (nombre, username, email, password_hash, activo, is_admin) '
    . 'VALUES (?, ?, ?, ?, 1, 1)'
);
$insert->execute([
    'Prueba temporal de migracion',
    $username,
    $email,
    password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
]);

echo json_encode([
    'username' => $username,
    'password' => $password,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
