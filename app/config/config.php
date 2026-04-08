<?php
/**
 * Configuración general de la aplicación
 */

return [
    // Información de la aplicación
    'app_name' => 'XMLConcilia',
    'app_version' => '1.0.0',
    'app_url' => 'http://localhost/xmlconcilia/public',
    
    // Rutas base
    'base_path' => dirname(__DIR__, 2),
    'base_uri' => '/xmlconcilia/public',
    
    // Ambiente (development, production)
    'environment' => 'development',
    
    // Configuración regional
    'timezone' => 'America/Mexico_City',
    'locale' => 'es_MX',
    'charset' => 'UTF-8',
    
    // Configuración de archivos
    'uploads_path' => dirname(__DIR__, 2) . '/public/uploads',
    'max_upload_size' => 10485760, // 10MB en bytes
    'allowed_extensions' => [
        'xml' => ['xml', 'pdf'],
        'gastos' => ['csv', 'xlsx', 'xls']
    ],
    
    // Configuración de sesión
    'session_lifetime' => 7200, // 2 horas
    'session_name' => 'XMLCONCILIA_SESSION',
    
    // Configuración de seguridad
    'encryption_key' => 'change-this-to-random-string-in-production',
    'csrf_protection' => true,
    
    // Configuración de logs
    'log_path' => dirname(__DIR__, 2) . '/storage/logs',
    'log_level' => 'debug', // debug, info, warning, error
    
    // Configuración de conciliación
    'conciliacion' => [
        'peso_numero_factura' => 60,
        'peso_proveedor' => 40,
        'umbral_exacto' => 100,
        'umbral_probable' => 75,
        'umbral_bajo' => 50,
        'tolerancia_redondeo' => 0.05,
        'tolerancia_porcentaje' => 1,
    ],
];
