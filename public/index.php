<?php
/**
 * Punto de entrada de la aplicación
 * Nexo Fiscal - Control documental y conciliación
 */

// Servir archivos estáticos directamente si mod_rewrite no los intercepta.
// Ejecutar ANTES de session_start() para no crear sesiones innecesarias.
(function () {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim(dirname($scriptName), '/');
    $rel  = (strpos($uri, $base) === 0) ? substr($uri, strlen($base)) : $uri;
    $rel  = '/' . ltrim(rawurldecode($rel), '/');

    if (strpos($rel, '/assets/') !== 0) return;

    $file = __DIR__ . $rel;
    if (!is_file($file)) return;

    $mime = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: max-age=86400, public');
    readfile($file);
    exit;
})();

// Iniciar sesión con ruta dentro del directorio permitido.
// En hosting con open_basedir (InfinityFree) el /tmp predeterminado puede estar bloqueado.
if (session_status() === PHP_SESSION_NONE) {
    $_rootPath   = is_dir(__DIR__ . '/app') ? __DIR__ : dirname(__DIR__);
    $_sessionDir = $_rootPath . '/storage/sessions';
    if (!is_dir($_sessionDir)) {
        @mkdir($_sessionDir, 0700, true);
    }
    if (is_dir($_sessionDir) && is_writable($_sessionDir)) {
        session_save_path($_sessionDir);
    }
    session_start();
}

// Definir directorio raíz
// En hosting con open_basedir (ej. InfinityFree), app/ vive dentro de htdocs/;
// en local, app/ está en el directorio padre de public/.
define('ROOT_PATH', is_dir(__DIR__ . '/app') ? __DIR__ : dirname(__DIR__));

// Cargar clases del core
require_once ROOT_PATH . '/app/core/App.php';
require_once ROOT_PATH . '/app/core/Controller.php';
require_once ROOT_PATH . '/app/core/Model.php';

// Crear e inicializar aplicación
global $app;
$app = new App();

// Ejecutar aplicación
$app->run();
