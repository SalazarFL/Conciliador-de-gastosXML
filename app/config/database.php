<?php
/**
 * Configuración de la base de datos
 * Detecta automáticamente entorno local (XAMPP) o producción (InfinityFree)
 */

$host    = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$isLocal = ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
         || strpos($host, 'localhost:') === 0);

if ($isLocal) {
    return [
        'driver'    => 'mysql',
        'host'      => 'localhost',
        'port'      => '3306',
        'database'  => 'bd_xmlconcilia',
        'username'  => 'root',
        'password'  => '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
        'strict'    => false,
        'engine'    => 'InnoDB',
    ];
}

// Producción — InfinityFree
return [
    'driver'    => 'mysql',
    'host'      => 'sql210.infinityfree.com',
    'port'      => '3306',
    'database'  => 'if0_41989871_bd_xmlconcilia',
    'username'  => 'if0_41989871',
    'password'  => '6NTf7FAVLUFX',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'strict'    => false,
    'engine'    => 'InnoDB',
];
