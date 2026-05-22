<?php
/**
 * Definición de rutas de la aplicación
 * Formato: $app->getRouter()->method('uri', 'Controller@method')
 */

// Obtener instancia del router
$router = $app->getRouter();

// --- RUTAS DE AUTENTICACIÓN ---
$router->get('/login',  'AuthController@loginForm');
$router->post('/login', 'AuthController@loginPost');
$router->get('/logout', 'AuthController@logout');

// --- RUTAS DE HOME ---
$router->get('/', 'HomeController@index');
$router->get('/home', 'HomeController@index');

// --- RUTAS DE FACTURAS XML ---
$router->get('/facturas', 'FacturasController@index');
$router->post('/facturas/subir', 'FacturasController@subir');
$router->post('/facturas/cola/iniciar', 'FacturasController@colaIniciar');
$router->post('/facturas/cola/agregar', 'FacturasController@colaAgregar');
$router->post('/facturas/cola/procesar', 'FacturasController@colaProcesar');
$router->get('/facturas/cola/estado/{id}', 'FacturasController@colaEstado');
$router->get('/facturas/ver/{id}', 'FacturasController@ver');

// --- RUTAS DE GASTOS ---
$router->get('/gastos', 'GastosController@index');
$router->post('/gastos/subir', 'GastosController@subir');

// --- RUTAS DE CONCILIACIÓN ---
$router->get('/conciliacion', 'ConciliacionController@index');
$router->post('/conciliacion/ejecutar', 'ConciliacionController@ejecutar');
$router->post('/conciliacion/revisar/{id}', 'ConciliacionController@revisar');

// --- RUTAS DE REPORTES ---
$router->get('/reportes', 'ReportesController@index');
$router->get('/reportes/preview', 'ReportesController@preview');
$router->get('/reportes/exportar', 'ReportesController@exportar');

// --- RUTAS DE USUARIOS (solo admin) ---
$router->get('/usuarios',              'UsuariosController@index');
$router->get('/usuarios/crear',        'UsuariosController@crear');
$router->post('/usuarios/crear',       'UsuariosController@crear');
$router->get('/usuarios/editar/{id}',  'UsuariosController@editar');
$router->post('/usuarios/editar/{id}', 'UsuariosController@editar');
$router->post('/usuarios/eliminar/{id}', 'UsuariosController@eliminar');

// --- RUTAS DE API (AJAX) ---
$router->get('/api/proveedores/buscar', 'ProveedoresController@buscar');
$router->get('/api/estadisticas', 'ReportesController@estadisticas');
