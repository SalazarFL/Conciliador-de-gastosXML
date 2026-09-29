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
// Una sola pantalla para lo que vale en todo el sistema: dónde viven los XML
// y PDF (SharePoint), las cuentas de correo, la automatización, las empresas,
// los usuarios y el respaldo. Antes era un modal dentro de Correo y había que
// saber que se guardaba ahí.
//
// Los POST de cada sección NO se mudaron: los sigue
// atendiendo el controlador dueño de lo que escribe —CorreoController,
// DiagnosticoController, SociedadesController, UsuariosController—, porque
// comparten helpers con el resto de su módulo.
$router->get('/configuracion', 'ConfiguracionController@index');
$router->post('/configuracion/proveedores/previa', 'ConfiguracionController@previaProveedores');
$router->post('/configuracion/proveedores/importar', 'ConfiguracionController@importarProveedores');
$router->post('/configuracion/proveedores/listar', 'ConfiguracionController@listarProveedores');
$router->post('/configuracion/proveedores/opciones', 'ConfiguracionController@opcionesProveedor');
$router->post('/configuracion/proveedores/guardar', 'ConfiguracionController@guardarProveedor');
$router->post('/configuracion/proveedores/eliminar', 'ConfiguracionController@eliminarProveedor');
$router->post('/configuracion/proveedores/dejar-de-aceptar', 'ConfiguracionController@dejarDeAceptarProveedor');
$router->post('/configuracion/proveedores/ignorar', 'ConfiguracionController@ignorarProveedor');

// --- AVISOS (la campana de la barra superior) ---
// Lo que no puede vivir en un toast de siete segundos: las decisiones que
// alguien tiene que tomar, esperando hasta que las tome.
$router->get('/avisos', 'NotificacionesController@index');
$router->get('/avisos/resumen', 'NotificacionesController@resumen');
$router->post('/avisos/cerrar', 'NotificacionesController@cerrar');
// Los comprobantes de proveedores sin código se sacaron de la campana y se
// atienden desde Correo: ver Notificacion::FUERA_DE_LA_CAMPANA.
$router->post('/avisos/proveedor/sin-codigo', 'NotificacionesController@sinCodigo');
$router->post('/avisos/proveedor/opciones', 'NotificacionesController@opcionesCodigo');
$router->post('/avisos/proveedor/codigo', 'NotificacionesController@codigoNuevo');
$router->post('/avisos/proveedor/ignorar', 'NotificacionesController@ignorarProveedor');

// --- DIAGNÓSTICO DE LA INSTALACIÓN ---
// La aplicación corre en la computadora de cada persona: esta página dice qué
// le falta a ESTA instalación, para no depender de acceso remoto.
$router->get('/diagnostico', 'DiagnosticoController@index');
// Respaldo de la base: lo genera la computadora que alcanza el servidor y
// queda en esa misma computadora (storage/backups/RESPALDOS). Solo admin.
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
$router->get('/facturas/exportar', 'FacturasController@exportar');
$router->post('/facturas/subir', 'FacturasController@subir');
$router->post('/facturas/cola/iniciar', 'FacturasController@colaIniciar');
$router->post('/facturas/cola/agregar', 'FacturasController@colaAgregar');
$router->post('/facturas/cola/procesar', 'FacturasController@colaProcesar');
$router->get('/facturas/cola/estado/{id}', 'FacturasController@colaEstado');
$router->get('/facturas/ver/{id}', 'FacturasController@ver');

// --- RUTAS DE NOTAS DE CRÉDITO XML ---
$router->get('/notas-xml', 'NotasXmlController@index');
$router->get('/notas-xml/exportar', 'NotasXmlController@exportar');
$router->post('/notas-xml/subir', 'NotasXmlController@subir');
$router->get('/notas-xml/ver/{id}', 'NotasXmlController@ver');
// La ficha del comprobante en JSON: el cuadro que se abre sobre el listado
// en vez de mandar a otra pestaña (ver public/assets/js/app.js, visor de
// ficha). Sirve a facturas y a notas de crédito, que son la misma tabla.
$router->get('/documentos/ficha/{id}', 'DocumentosController@ficha');
$router->get('/documentos/xml/{id}', 'DocumentosController@xml');
$router->get('/documentos/pdf/{id}', 'DocumentosController@pdf');
$router->post('/documentos/recuperar', 'DocumentosController@recuperar');
$router->post('/documentos/pdf/{id}/subir', 'DocumentosController@subirPdf');

// --- RUTAS DE CORREO (captura IMAP de facturas, solo local) ---
$router->get('/correo', 'CorreoController@index');
$router->post('/correo/listar', 'CorreoController@listar');
$router->post('/correo/sincronizar', 'CorreoController@sincronizar');
$router->post('/correo/cuentas/guardar', 'CorreoController@cuentaGuardar');
$router->post('/correo/cuentas/eliminar', 'CorreoController@cuentaEliminar');
$router->post('/correo/cuentas/usar', 'CorreoController@cuentaUsar');
$router->post('/correo/cuentas/probar', 'CorreoController@cuentaProbar');
$router->post('/correo/carpetas-buzon', 'CorreoController@carpetasBuzon');
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
// La descarga en curso avanza desde cualquier pantalla del sistema, no solo
// desde el modo Descargas que la inició.
$router->post('/correo/lotes/latido', 'CorreoController@lotesLatido');
$router->post('/correo/general/pausar', 'CorreoController@generalPausar');
$router->post('/correo/general/reanudar', 'CorreoController@generalReanudar');
$router->post('/correo/general/cancelar', 'CorreoController@generalCancelar');

// --- RUTAS DE SEGUIMIENTO (la cola única de trabajo) ---
// Junta notas de crédito y pago: lo que no tiene XML, lo que no tiene
// PDF y lo que no cuadra, con la gestión de cada renglón.
$router->get('/seguimiento', 'SeguimientoController@index');
$router->post('/seguimiento/actualizar', 'SeguimientoController@actualizar');
$router->get('/seguimiento/detalle', 'SeguimientoController@detalle');
$router->get('/seguimiento/exportar', 'SeguimientoController@exportar');

// --- RUTAS DE FACTURAS POR PAGAR (listado del pago) ---
$router->get('/por-pagar', 'PorPagarController@index');
$router->post('/por-pagar/subir', 'PorPagarController@subir');
$router->post('/por-pagar/previsualizar', 'PorPagarController@previsualizar');
$router->get('/por-pagar/progreso', 'PorPagarController@progreso');
$router->post('/por-pagar/comparar-listado', 'PorPagarController@compararListado');
$router->post('/por-pagar/actualizar-listado', 'PorPagarController@actualizarListado');
$router->post('/por-pagar/verificar/{id}', 'PorPagarController@verificar');
$router->post('/por-pagar/pago/borrar/{id}', 'PorPagarController@borrarPago');
$router->post('/por-pagar/factura/quitar/{id}', 'PorPagarController@quitarFactura');
$router->get('/por-pagar/exportar', 'PorPagarController@exportar');
$router->get('/por-pagar/sin-coincidencia', 'PorPagarController@sinCoincidencia');
$router->post('/por-pagar/forzar', 'PorPagarController@forzar');

// --- RUTAS DE FACTURAS ERP (reporte "Facturas por Proveedor", saldos) ---
$router->get('/facturas-erp', 'FacturasErpController@index');
$router->post('/facturas-erp/previa', 'FacturasErpController@previsualizar');
$router->post('/facturas-erp/subir', 'FacturasErpController@subir');
$router->get('/facturas-erp/incidencias', 'FacturasErpController@incidencias');
$router->post('/facturas-erp/incidencias/descartar', 'FacturasErpController@descartarIncidencias');
$router->post('/facturas-erp/incidencias/restaurar', 'FacturasErpController@restaurarIncidencias');
$router->get('/facturas-erp/revision', 'FacturasErpController@revision');
$router->post('/facturas-erp/revision/guardar', 'FacturasErpController@guardarRevision');
$router->post('/facturas-erp/revision/descartar', 'FacturasErpController@descartarRevision');
$router->post('/facturas-erp/revision/enredo', 'FacturasErpController@resolverEnredo');
$router->post('/facturas-erp/revision/olvidar', 'FacturasErpController@olvidarRevision');
$router->get('/facturas-erp/exportar', 'FacturasErpController@exportar');
$router->post('/facturas-erp/quitar', 'FacturasErpController@quitar');
$router->get('/facturas-erp/quitadas', 'FacturasErpController@quitadas');
$router->post('/facturas-erp/quitadas/devolver', 'FacturasErpController@devolverQuitada');

// --- RUTAS DE NOTAS DE CRÉDITO (listados por período) ---
$router->get('/notas-credito', 'NotasCreditoController@index');
$router->get('/notas-credito/exportar', 'NotasCreditoController@exportar');
$router->post('/notas-credito/previa', 'NotasCreditoController@previsualizar');
$router->post('/notas-credito/subir', 'NotasCreditoController@subir');
$router->get('/notas-credito/buscar', 'NotasCreditoController@buscar');
$router->post('/notas-credito/verificar/{id}', 'NotasCreditoController@verificar');
$router->get('/notas-credito/historial/{id}', 'NotasCreditoController@historial');
$router->get('/notas-credito/aplicables/{id}', 'NotasCreditoController@aplicables');
$router->get('/notas-credito/candidatas', 'NotasCreditoController@candidatas');
$router->post('/notas-credito/vincular', 'NotasCreditoController@vincular');
$router->post('/notas-credito/desvincular', 'NotasCreditoController@desvincular');
$router->get('/notas-credito/revision', 'NotasCreditoController@revision');
$router->post('/notas-credito/revision/guardar', 'NotasCreditoController@guardarRevision');
$router->post('/notas-credito/revision/descartar', 'NotasCreditoController@descartarRevision');
$router->post('/notas-credito/revision/olvidar', 'NotasCreditoController@olvidarRevision');

// --- RUTAS DE DEVOLUCIONES (reportes PDF del ERP ↔ NC electrónicas) ---
$router->get('/devoluciones', 'DevolucionesController@index');
$router->get('/devoluciones/exportar', 'DevolucionesController@exportar');
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
// contra Facturas ERP, que es el registro real de la empresa y no un
// archivo suelto; las tres tablas de aquella época (conciliaciones,
// conciliacion_corridas, gastos_consolidados, gastos_raw) quedaron en cero
// filas, o sea que el flujo nunca llegó a usarse en serio.
//
// Reportes exportaba justamente esas tres cosas: conciliación, gastos y un
// listado de facturas. Lo que hoy se exporta sale de cada módulo, con los
// filtros de ese módulo (pago, notas de crédito, seguimiento,
// facturas ERP), que es donde la gente ya está mirando los datos.

// --- RUTAS DE USUARIOS (quien administre usuarios) ---
$router->get('/usuarios',              'UsuariosController@index');
$router->get('/usuarios/crear',        'UsuariosController@crear');
$router->post('/usuarios/crear',       'UsuariosController@crear');
$router->get('/usuarios/editar/{id}',  'UsuariosController@editar');
$router->post('/usuarios/editar/{id}', 'UsuariosController@editar');
$router->post('/usuarios/eliminar/{id}', 'UsuariosController@eliminar');

// --- RUTAS DE ROLES (qué puede hacer cada quien) ---
// El catálogo de permisos vive en config/permisos.php, no en la base: un
// permiso solo significa algo si hay una ruta que lo exige. Lo que se guarda
// aquí es el reparto —qué permisos tiene cada rol—, que es lo que cambia.
$router->get('/roles',                'RolesController@index');
$router->get('/roles/crear',          'RolesController@crear');
$router->post('/roles/crear',         'RolesController@crear');
$router->get('/roles/editar/{id}',    'RolesController@editar');
$router->post('/roles/editar/{id}',   'RolesController@editar');
$router->post('/roles/eliminar/{id}', 'RolesController@eliminar');

// --- BITÁCORA DE ACTIVIDAD (quién hizo qué) ---
// Una sola línea de tiempo: lo que registra el enrutador desde ahora y el
// rastro que cada módulo ya venía dejando en sus propias tablas.
$router->get('/bitacora',              'BitacoraController@index');
$router->get('/bitacora/detalle/{id}', 'BitacoraController@detalleRenglon');
$router->get('/bitacora/exportar',     'BitacoraController@exportar');
$router->post('/bitacora/depurar',     'BitacoraController@depurar');

// Las dos rutas de API que había —buscar proveedores y estadísticas— no las
// llamaba ninguna pantalla: quedaron de una versión anterior. Cada módulo
// resuelve hoy sus propias búsquedas con su propio endpoint.
