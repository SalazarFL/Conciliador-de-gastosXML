<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse por CLI.\n");
    exit(1);
}

$rootSecretFile = getenv('XMLCONCILIA_MARIADB_ROOT_SECRET')
    ?: 'C:/WebServer/secrets/mariadb114-root.txt';

if (!is_file($rootSecretFile)) {
    fwrite(STDERR, "No existe el archivo protegido de la cuenta raiz.\n");
    exit(1);
}

$rootPassword = trim((string) file_get_contents($rootSecretFile));
$local = require dirname(__DIR__) . '/app/config/local.php';
$db = is_array($local) ? ($local['database'] ?? null) : null;

if (!is_array($db)) {
    fwrite(STDERR, "local.php no contiene la configuracion de base de datos.\n");
    exit(1);
}

$database = trim((string) ($db['database'] ?? ''));
$username = trim((string) ($db['username'] ?? ''));
$password = (string) ($db['password'] ?? '');

foreach ([$database, $username] as $identifier) {
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
        fwrite(STDERR, "La base o el usuario contiene caracteres no permitidos.\n");
        exit(1);
    }
}

if ($database === '' || $username === '' || $password === '') {
    fwrite(STDERR, "Las credenciales de la aplicacion estan incompletas.\n");
    exit(1);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$root = new PDO(
    'mysql:host=127.0.0.1;port=3307;charset=utf8mb4',
    'root',
    $rootPassword,
    $options
);

foreach (['127.0.0.1', 'localhost'] as $host) {
    $account = $root->quote($username) . '@' . $root->quote($host);
    $root->exec("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY " . $root->quote($password));
    $root->exec("ALTER USER {$account} IDENTIFIED BY " . $root->quote($password));
    $root->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO {$account}");
}

$source = new PDO(
    "mysql:host=127.0.0.1;port=3306;dbname={$database};charset=utf8mb4",
    $username,
    $password,
    $options
);
$target = new PDO(
    "mysql:host=127.0.0.1;port=3307;dbname={$database};charset=utf8mb4",
    $username,
    $password,
    $options
);

$tables = $target->query(
    'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES '
    . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
)->fetchAll();

$mismatches = [];
$targetRows = 0;

foreach ($tables as $table) {
    $name = (string) $table['TABLE_NAME'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Nombre de tabla inesperado: {$name}");
    }

    $sourceCount = (int) $source->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();
    $targetCount = (int) $target->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();
    $targetRows += $targetCount;

    if ($sourceCount !== $targetCount) {
        $mismatches[] = [
            'table' => $name,
            'source' => $sourceCount,
            'target' => $targetCount,
        ];
    }
}

$routineQuery = "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = "
    . $target->quote($database);
$sourceRoutines = (int) $source->query($routineQuery)->fetchColumn();
$targetRoutines = (int) $target->query($routineQuery)->fetchColumn();

$result = [
    'application_accounts_provisioned' => 2,
    'tables_and_views' => count($tables),
    'target_total_rows' => $targetRows,
    'row_count_mismatches' => $mismatches,
    'source_routines' => $sourceRoutines,
    'target_routines' => $targetRoutines,
    'target_version' => (string) $target->query('SELECT VERSION()')->fetchColumn(),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($mismatches === [] && $sourceRoutines === $targetRoutines ? 0 : 2);
