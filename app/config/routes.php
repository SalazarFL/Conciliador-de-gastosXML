<?php
/**
 * Definición de rutas de la aplicación
 * Formato: $app->getRouter()->method('uri', 'Controller@method')
 */

// Obtener instancia del router
$router = $app->getRouter();

// --- RUTAS DE HOME ---
$router->get('/', 'HomeController@index');
$router->get('/home', 'HomeController@index');

// --- RUTAS DE FACTURAS XML ---
$router->get('/facturas', 'FacturasController@index');
$router->get('/facturas/importar', 'FacturasController@importar');
$router->post('/facturas/subir', 'FacturasController@subir');
$router->get('/facturas/ver/{id}', 'FacturasController@ver');
$router->post('/facturas/eliminar/{id}', 'FacturasController@eliminar');

// --- RUTAS DE GASTOS ---
$router->get('/gastos', 'GastosController@index');
$router->get('/gastos/importar', 'GastosController@importar');
$router->post('/gastos/subir', 'GastosController@subir');
$router->get('/gastos/ver/{id}', 'GastosController@ver');
$router->post('/gastos/eliminar/{id}', 'GastosController@eliminar');

// --- RUTAS DE CONCILIACIÓN ---
$router->get('/conciliacion', 'ConciliacionController@index');
$router->post('/conciliacion/ejecutar', 'ConciliacionController@ejecutar');
$router->get('/conciliacion/resultados', 'ConciliacionController@resultados');
$router->get('/conciliacion/pendientes', 'ConciliacionController@pendientes');
$router->post('/conciliacion/revisar/{id}', 'ConciliacionController@revisar');
$router->get('/conciliacion/exportar', 'ConciliacionController@exportar');

// --- RUTAS DE REPORTES ---
$router->get('/reportes', 'ReportesController@index');
$router->get('/reportes/resumen', 'ReportesController@resumen');
$router->get('/reportes/por-proveedor', 'ReportesController@porProveedor');
$router->get('/reportes/por-estado', 'ReportesController@porEstado');
$router->get('/reportes/diferencias', 'ReportesController@diferencias');
$router->get('/reportes/exportar', 'ReportesController@exportar');

// --- RUTAS DE API (AJAX) ---
$router->post('/api/conciliacion/marcar-revisado', 'ConciliacionController@marcarRevisado');
$router->get('/api/proveedores/buscar', 'ProveedoresController@buscar');
$router->get('/api/estadisticas', 'ReportesController@estadisticas');
