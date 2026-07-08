<?php
/**
 * Configuración general de la aplicación
 * Detecta automáticamente si corre en localhost (XAMPP) o producción (InfinityFree)
 */

$host    = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$isLocal = ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
         || strpos($host, 'localhost:') === 0);

// En local: app/ está fuera de public/ → uploads en public/uploads/
// En server: app/ está dentro de htdocs/ → uploads en htdocs/uploads/
$uploadsPath = $isLocal
    ? dirname(__DIR__, 2) . '/public/uploads'
    : dirname(__DIR__, 2) . '/uploads';

return [
    // Información de la aplicación
    'app_name'    => 'XMLConcilia',
    'app_version' => '1.0.0',
    'app_url'     => '',

    // Rutas base
    'base_path' => dirname(__DIR__, 2),
    'base_uri'  => '',

    // Ambiente
    'environment' => $isLocal ? 'development' : 'production',

    // Configuración regional
    'timezone' => 'America/Mexico_City',
    'locale'   => 'es_MX',
    'charset'  => 'UTF-8',

    // Configuración de archivos
    'uploads_path'       => $uploadsPath,
    'max_upload_size'    => 10485760, // 10 MB
    'allowed_extensions' => [
        'xml'    => ['xml'],
        'gastos' => ['csv', 'xlsx', 'xls'],
    ],

    // Configuración de sesión
    'session_lifetime' => 7200,
    'session_name'     => 'XMLCONCILIA_SESSION',

    // Configuración de seguridad
    'encryption_key'  => 'change-this-to-random-string-in-production',
    'csrf_protection' => true,

    // Configuración de logs
    'log_path'  => dirname(__DIR__, 2) . '/storage/logs',
    'log_level' => $isLocal ? 'debug' : 'error',

    // Configuración de conciliación
    'conciliacion' => [
        'peso_numero_factura'  => 60,
        'peso_proveedor'       => 40,
        'umbral_exacto'        => 100,
        'umbral_probable'      => 75,
        'umbral_bajo'          => 50,
        'tolerancia_redondeo'  => 0.05,
        'tolerancia_porcentaje' => 1,
    ],
];
