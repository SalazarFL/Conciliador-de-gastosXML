<?php
/**
 * Definición de rutas de la aplicación
 * Formato: $app->getRouter()->method('uri', 'Controller@method')
 */

// Obtener instancia del router
/** @var App $app */
$router = $app->getRouter();

// --- RUTAS DE AUTENTICACIÓN ---
$router->get('/login',  'AuthController@loginForm');
$router->post('/login', 'AuthController@loginPost');
$router->get('/logout', 'AuthController@logout');

// --- RUTAS DE HOME ---
$router->get('/', 'HomeController@index');
$router->get('/home', 'HomeController@index');

// --- RUTAS DE SOCIEDADES (se administran desde Inicio) ---
$router->post('/sociedades/crear', 'SociedadesController@crear');
$router->post('/sociedades/editar/{id}', 'SociedadesController@editar');
$router->post('/sociedades/eliminar/{id}', 'SociedadesController@eliminar');
$router->post('/sociedades/activar/{id}', 'SociedadesController@activar');

// --- RUTAS DE FACTURAS XML ---
$router->get('/facturas', 'FacturasController@index');
$router->post('/facturas/subir', 'FacturasController@subir');
$router->post('/facturas/cola/iniciar', 'FacturasController@colaIniciar');
$router->post('/facturas/cola/agregar', 'FacturasController@colaAgregar');
$router->post('/facturas/cola/procesar', 'FacturasController@colaProcesar');
$router->get('/facturas/cola/estado/{id}', 'FacturasController@colaEstado');
$router->get('/facturas/ver/{id}', 'FacturasController@ver');
$router->post('/facturas/semana', 'FacturasController@semanaAsignar');

// --- RUTAS DE CORREO (captura IMAP de facturas, solo local) ---
$router->get('/correo', 'CorreoController@index');
$router->post('/correo/listar', 'CorreoController@listar');
$router->post('/correo/sincronizar', 'CorreoController@sincronizar');
$router->post('/correo/config', 'CorreoController@config');
$router->post('/correo/cuentas/guardar', 'CorreoController@cuentaGuardar');
$router->post('/correo/cuentas/eliminar', 'CorreoController@cuentaEliminar');
$router->post('/correo/cuentas/usar', 'CorreoController@cuentaUsar');
$router->post('/correo/semana/usar', 'CorreoController@semanaUsar');
$router->post('/correo/cuentas/probar', 'CorreoController@cuentaProbar');
$router->post('/correo/carpetas', 'CorreoController@carpetas');
$router->post('/correo/selector/abrir', 'CorreoController@selectorAbrir');
$router->post('/correo/selector/estado', 'CorreoController@selectorEstado');
$router->post('/correo/auto/estado', 'CorreoController@autoSyncEstado');
$router->post('/correo/auto/activar', 'CorreoController@autoSyncActivar');
$router->post('/correo/auto/desactivar', 'CorreoController@autoSyncDesactivar');
$router->post('/correo/contenido', 'CorreoController@contenido');
$router->post('/correo/procesar', 'CorreoController@procesar');
$router->post('/correo/importar', 'CorreoController@importar');
$router->post('/correo/descartar', 'CorreoController@descartar');

// --- RUTAS DE FACTURAS POR PAGAR (listado del pago semanal) ---
$router->get('/por-pagar', 'PorPagarController@index');
$router->post('/por-pagar/subir', 'PorPagarController@subir');
$router->post('/por-pagar/previsualizar', 'PorPagarController@previsualizar');
$router->post('/por-pagar/verificar/{id}', 'PorPagarController@verificar');
$router->post('/por-pagar/eliminar/{id}', 'PorPagarController@eliminar');
$router->get('/por-pagar/exportar', 'PorPagarController@exportar');

// --- RUTAS DE GASTOS ---
$router->get('/gastos', 'GastosController@index');
$router->post('/gastos/subir', 'GastosController@subir');

// --- RUTAS DE CONCILIACIÓN ---
$router->get('/conciliacion', 'ConciliacionController@index');
$router->post('/conciliacion/ejecutar', 'ConciliacionController@ejecutar');
$router->post('/conciliacion/revisar/{id}', 'ConciliacionController@revisar');
$router->get('/conciliacion/descargar-xml', 'ConciliacionController@descargarXml');
$router->get('/conciliacion/mapa-nombres', 'ConciliacionController@mapaNombresPdf');

// --- RUTAS DE REPORTES ---
$router->get('/reportes', 'ReportesController@index');
$router->get('/reportes/preview', 'ReportesController@preview');
$router->get('/reportes/exportar', 'ReportesController@exportar');
$router->get('/reportes/importaciones', 'ReportesController@importaciones');

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
