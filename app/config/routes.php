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

// --- DIAGNÓSTICO DE LA INSTALACIÓN ---
// La aplicación corre en la computadora de cada persona: esta página dice qué
// le falta a ESTA instalación, para no depender de acceso remoto.
$router->get('/diagnostico', 'DiagnosticoController@index');

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

// --- RUTAS DE NOTAS DE CRÉDITO XML ---
$router->get('/notas-xml', 'NotasXmlController@index');
$router->post('/notas-xml/subir', 'NotasXmlController@subir');
$router->get('/notas-xml/ver/{id}', 'NotasXmlController@ver');
$router->get('/documentos/xml/{id}', 'DocumentosController@xml');
$router->get('/documentos/pdf/{id}', 'DocumentosController@pdf');

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
$router->post('/correo/carpetas-buzon', 'CorreoController@carpetasBuzon');
$router->post('/correo/selector/abrir', 'CorreoController@selectorAbrir');
$router->post('/correo/selector/estado', 'CorreoController@selectorEstado');
$router->post('/correo/organizar', 'CorreoController@organizar');
$router->post('/correo/organizar/previsualizar', 'CorreoController@organizarPrevisualizar');
$router->post('/correo/auto/estado', 'CorreoController@autoSyncEstado');
$router->post('/correo/auto/activar', 'CorreoController@autoSyncActivar');
$router->post('/correo/auto/desactivar', 'CorreoController@autoSyncDesactivar');
$router->post('/correo/contenido', 'CorreoController@contenido');
$router->post('/correo/adjunto', 'CorreoController@adjunto');
$router->post('/correo/procesar', 'CorreoController@procesar');
$router->post('/correo/importar', 'CorreoController@importar');
$router->post('/correo/descartar', 'CorreoController@descartar');
$router->post('/correo/general/estimar', 'CorreoController@generalEstimar');
$router->post('/correo/general/crear', 'CorreoController@generalCrear');
$router->post('/correo/general/estado', 'CorreoController@generalEstado');
$router->post('/correo/general/incidencias', 'CorreoController@generalIncidencias');
$router->post('/correo/general/procesar', 'CorreoController@generalProcesar');
$router->post('/correo/general/pausar', 'CorreoController@generalPausar');
$router->post('/correo/general/reanudar', 'CorreoController@generalReanudar');
$router->post('/correo/general/cancelar', 'CorreoController@generalCancelar');

// --- RUTAS DE SEGUIMIENTO (la cola única de trabajo) ---
// Junta notas de crédito y pago semanal: lo que no tiene XML, lo que no tiene
// PDF y lo que no cuadra, con la gestión de cada renglón.
$router->get('/seguimiento', 'SeguimientoController@index');
$router->post('/seguimiento/actualizar', 'SeguimientoController@actualizar');
$router->get('/seguimiento/detalle', 'SeguimientoController@detalle');
$router->get('/seguimiento/exportar', 'SeguimientoController@exportar');

// --- RUTAS DE FACTURAS POR PAGAR (listado del pago semanal) ---
$router->get('/por-pagar', 'PorPagarController@index');
$router->post('/por-pagar/subir', 'PorPagarController@subir');
$router->post('/por-pagar/previsualizar', 'PorPagarController@previsualizar');
$router->post('/por-pagar/comparar-listado', 'PorPagarController@compararListado');
$router->post('/por-pagar/verificar/{id}', 'PorPagarController@verificar');
$router->post('/por-pagar/cerrar/{id}', 'PorPagarController@cerrar');
$router->post('/por-pagar/eliminar/{id}', 'PorPagarController@eliminar');
$router->post('/por-pagar/factura/eliminar/{id}', 'PorPagarController@eliminarFactura');
$router->get('/por-pagar/exportar', 'PorPagarController@exportar');
$router->get('/por-pagar/sin-coincidencia', 'PorPagarController@sinCoincidencia');
$router->post('/por-pagar/forzar', 'PorPagarController@forzar');

// --- RUTAS DE FACTURAS ERP (reporte "Facturas por Proveedor", saldos) ---
$router->get('/facturas-erp', 'FacturasErpController@index');
$router->post('/facturas-erp/subir', 'FacturasErpController@subir');
$router->get('/facturas-erp/incidencias', 'FacturasErpController@incidencias');
$router->post('/facturas-erp/incidencias/descartar', 'FacturasErpController@descartarIncidencias');
$router->post('/facturas-erp/incidencias/restaurar', 'FacturasErpController@restaurarIncidencias');
$router->get('/facturas-erp/exportar', 'FacturasErpController@exportar');

// --- RUTAS DE NOTAS DE CRÉDITO (listados por período) ---
$router->get('/notas-credito', 'NotasCreditoController@index');
$router->get('/notas-credito/buscar', 'NotasCreditoController@buscar');
$router->post('/notas-credito/previsualizar', 'NotasCreditoController@previsualizar');
$router->post('/notas-credito/subir', 'NotasCreditoController@subir');
$router->post('/notas-credito/verificar/{id}', 'NotasCreditoController@verificar');
$router->get('/notas-credito/historial/{id}', 'NotasCreditoController@historial');
$router->get('/notas-credito/candidatas', 'NotasCreditoController@candidatas');
$router->post('/notas-credito/vincular', 'NotasCreditoController@vincular');
$router->post('/notas-credito/desvincular', 'NotasCreditoController@desvincular');
$router->post('/notas-credito/eliminar/{id}', 'NotasCreditoController@eliminar');

// --- RUTAS DE DEVOLUCIONES (reportes PDF del ERP ↔ NC electrónicas) ---
$router->get('/devoluciones', 'DevolucionesController@index');
$router->post('/devoluciones/subir', 'DevolucionesController@subir');
$router->get('/devoluciones/detalle/{id}', 'DevolucionesController@detalle');
$router->post('/devoluciones/verificar', 'DevolucionesController@verificar');
$router->post('/devoluciones/verificar/{id}', 'DevolucionesController@verificar');
$router->post('/devoluciones/confirmar', 'DevolucionesController@confirmar');
$router->post('/devoluciones/descartar', 'DevolucionesController@descartar');
$router->post('/devoluciones/vincular', 'DevolucionesController@vincular');
$router->post('/devoluciones/desvincular', 'DevolucionesController@desvincular');
$router->post('/devoluciones/eliminar/{id}', 'DevolucionesController@eliminar');
$router->get('/devoluciones/pdf/{id}', 'DevolucionesController@pdf');

// --- RUTAS DE GASTOS (legado: fuera del menú, accesible por URL) ---
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
