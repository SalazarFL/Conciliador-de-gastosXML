<?php
/**
 * Punto de entrada de la aplicación
 * XMLConcilia - Verificador de Facturas XML vs Gastos
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

// Iniciar sesión si no está activa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Definir directorio raíz
define('ROOT_PATH', dirname(__DIR__));

// Cargar clases del core
require_once ROOT_PATH . '/app/core/App.php';
require_once ROOT_PATH . '/app/core/Controller.php';
require_once ROOT_PATH . '/app/core/Model.php';

// Crear e inicializar aplicación
global $app;
$app = new App();

// Ejecutar aplicación
$app->run();
