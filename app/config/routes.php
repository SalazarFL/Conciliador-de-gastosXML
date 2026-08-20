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

// Acá vivía /carga: una pantalla que reunía los cuatro formularios de subida.
// La idea era no tener que saber de antemano qué módulo pedía qué archivo,
// pero el precio era ir a otra pantalla para cargar y volver para ver el
// resultado, cada vez. Cada formulario volvió a su módulo —donde se mira lo
// que ese archivo produce— y los POST se quedaron en el controlador que
// siempre los atendió, ahora bajo la ruta de su propio módulo.

// --- CONFIGURACIÓN (el engranaje de la barra superior) ---
// Una sola pantalla para lo que vale en todo el sistema: la carpeta raíz de
// XML y PDF, las cuentas de correo, la automatización, las empresas, los
// usuarios y el respaldo. Antes era un modal dentro de Correo y había que
// saber que se guardaba ahí.
//
// Los POST de cada sección NO se mudaron: los sigue
// atendiendo el controlador dueño de lo que escribe —CorreoController,
// DiagnosticoController, SociedadesController, UsuariosController—, porque
// comparten helpers con el resto de su módulo.
$router->get('/configuracion', 'ConfiguracionController@index');

// --- AVISOS (la campana de la barra superior) ---
// Lo que no puede vivir en un toast de siete segundos: las decisiones que
// alguien tiene que tomar, esperando hasta que las tome.
$router->get('/avisos', 'NotificacionesController@index');
$router->get('/avisos/resumen', 'NotificacionesController@resumen');
$router->post('/avisos/cerrar', 'NotificacionesController@cerrar');
$router->post('/avisos/codigo/confirmar', 'NotificacionesController@confirmarCodigo');
$router->post('/avisos/codigo/liberar', 'NotificacionesController@liberarCodigo');

// --- DIAGNÓSTICO DE LA INSTALACIÓN ---
// La aplicación corre en la computadora de cada persona: esta página dice qué
// le falta a ESTA instalación, para no depender de acceso remoto.
$router->get('/diagnostico', 'DiagnosticoController@index');
// Respaldo de la base a la carpeta compartida: lo genera la computadora que
// alcanza el servidor y las demás lo levantan de SharePoint. Solo admin.
$router->post('/diagnostico/respaldo/estado', 'DiagnosticoController@respaldoEstado');
$router->post('/diagnostico/respaldo/iniciar', 'DiagnosticoController@respaldoIniciar');
$router->post('/diagnostico/respaldo/auto/activar', 'DiagnosticoController@respaldoAutoActivar');
$router->post('/diagnostico/respaldo/auto/desactivar', 'DiagnosticoController@respaldoAutoDesactivar');

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
// Guardado masivo: se lleva los XML y PDF de la bandeja en un ZIP y no
// toca nada de la bandeja; es aparte del flujo de importación.
$router->post('/correo/guardar-lote', 'CorreoController@guardarLote');
$router->post('/correo/general/estimar', 'CorreoController@generalEstimar');
$router->post('/correo/general/crear', 'CorreoController@generalCrear');
$router->post('/correo/general/estado', 'CorreoController@generalEstado');
$router->post('/correo/general/incidencias', 'CorreoController@generalIncidencias');
// Descarte de incidencias revisadas, igual que en Facturas ERP: la marca vive
// por firma, así que reprocesar el correo no las resucita.
$router->post('/correo/incidencias/descartar', 'CorreoController@incidenciasDescartar');
$router->post('/correo/incidencias/restaurar', 'CorreoController@incidenciasRestaurar');
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
$router->post('/por-pagar/actualizar-listado', 'PorPagarController@actualizarListado');
$router->post('/por-pagar/verificar/{id}', 'PorPagarController@verificar');
$router->post('/por-pagar/semana/borrar/{id}', 'PorPagarController@borrarSemana');
$router->post('/por-pagar/factura/quitar/{id}', 'PorPagarController@quitarFactura');
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
$router->post('/notas-credito/previa', 'NotasCreditoController@previsualizar');
$router->post('/notas-credito/subir', 'NotasCreditoController@subir');
$router->get('/notas-credito/buscar', 'NotasCreditoController@buscar');
$router->post('/notas-credito/verificar/{id}', 'NotasCreditoController@verificar');
$router->get('/notas-credito/historial/{id}', 'NotasCreditoController@historial');
$router->get('/notas-credito/candidatas', 'NotasCreditoController@candidatas');
$router->post('/notas-credito/vincular', 'NotasCreditoController@vincular');
$router->post('/notas-credito/desvincular', 'NotasCreditoController@desvincular');

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

// Acá vivían Gastos, Conciliación y Reportes. Los tres salieron del sistema.
//
// Conciliación cruzaba las facturas XML contra un listado de "gastos" cargado
// aparte, y Gastos existía solo para alimentarla. Ese cruce lo hace hoy el pago
// semanal contra Facturas ERP, que es el registro real de la empresa y no un
// archivo suelto; las tres tablas de aquella época (conciliaciones,
// conciliacion_corridas, gastos_consolidados, gastos_raw) quedaron en cero
// filas, o sea que el flujo nunca llegó a usarse en serio.
//
// Reportes exportaba justamente esas tres cosas: conciliación, gastos y un
// listado de facturas. Lo que hoy se exporta sale de cada módulo, con los
// filtros de ese módulo (pago semanal, notas de crédito, seguimiento,
// facturas ERP), que es donde la gente ya está mirando los datos.

// --- RUTAS DE USUARIOS (solo admin) ---
$router->get('/usuarios',              'UsuariosController@index');
$router->get('/usuarios/crear',        'UsuariosController@crear');
$router->post('/usuarios/crear',       'UsuariosController@crear');
$router->get('/usuarios/editar/{id}',  'UsuariosController@editar');
$router->post('/usuarios/editar/{id}', 'UsuariosController@editar');
$router->post('/usuarios/eliminar/{id}', 'UsuariosController@eliminar');

// Las dos rutas de API que había —buscar proveedores y estadísticas— no las
// llamaba ninguna pantalla: quedaron de una versión anterior. Cada módulo
// resuelve hoy sus propias búsquedas con su propio endpoint.
