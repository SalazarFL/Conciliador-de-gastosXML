<?php
/**
 * Punto de entrada de la aplicación
 * XMLConcilia - Verificador de Facturas XML vs Gastos
 */

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
