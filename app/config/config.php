<?php
/**
 * Configuración general de la aplicación: lo que es igual en todas las
 * computadoras. Lo que cambia de una a otra vive en local.php, que no se
 * versiona (ver local.ejemplo.php).
 *
 * Este archivo detectaba antes si corría en localhost o en un hosting. Ya no
 * hay hosting: la aplicación se instala en la máquina de cada persona y lo
 * único remoto es la base de datos, así que la rama de producción nunca se
 * ejecutaba y solo confundía.
 */

$local = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'local.php')
    ? require __DIR__ . DIRECTORY_SEPARATOR . 'local.php'
    : [];
$local = is_array($local) ? $local : [];

// Errores en pantalla por defecto: es una herramienta interna y quien la usa
// necesita poder mandar la captura del error a quien la mantiene.
$ambiente = ($local['environment'] ?? '') === 'production' ? 'production' : 'development';

return [
    // Información de la aplicación
    'app_name'    => 'XMLConcilia',
    'app_version' => '1.0.0',
    'app_url'     => '',

    // Rutas base
    'base_path' => dirname(__DIR__, 2),
    'base_uri'  => '',

    // Ambiente
    'environment' => $ambiente,

    // Configuración regional
    'timezone' => 'America/Mexico_City',
    'locale'   => 'es_MX',
    'charset'  => 'UTF-8',

    // Configuración de archivos
    // Zona de paso para lo que sube la gente antes de archivarse; los
    // documentos definitivos nunca viven aquí, sino en la carpeta compartida.
    'uploads_path'       => dirname(__DIR__, 2) . '/public/uploads',
    'max_upload_size'    => 10485760, // 10 MB
    'allowed_extensions' => [
        'xml'    => ['xml'],
        'gastos' => ['csv', 'xlsx', 'xls'],
    ],

    // Configuración de sesión
    'session_lifetime' => 7200,
    'session_name'     => 'XMLCONCILIA_SESSION',

    // Configuración de seguridad
    'csrf_protection' => true,

    // Configuración de logs
    'log_path'  => dirname(__DIR__, 2) . '/storage/logs',
    'log_level' => $ambiente === 'production' ? 'error' : 'debug',

    // Extracción de texto de reportes PDF del ERP (Poppler). Cada máquina lo
    // tiene instalado donde le tocó, así que sale de local.php.
    // Vacío = buscar 'pdftotext' en el PATH del sistema.
    'pdftotext_path' => (string) ($local['pdftotext_path'] ?? ''),

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
