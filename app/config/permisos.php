<?php
/**
 * Qué se puede hacer en el sistema, y qué ruta corresponde a cada cosa.
 *
 * Este archivo es UNA sola lista, y es la lista de verdad: de aquí salen las
 * casillas que el administrador marca al armar un rol, la comprobación que
 * hace el enrutador antes de ejecutar nada, y el nombre en castellano con el
 * que cada acción aparece en la bitácora.
 *
 * Está en código y no en la base a propósito. Un permiso solo significa algo
 * si hay una ruta que lo exige: si la lista viviera en la base, al añadir una
 * pantalla habría que acordarse de insertar su permiso a mano, y al quitar una
 * quedarían permisos fantasma que se siguen ofreciendo y no protegen nada.
 * Lo que sí vive en la base es la asignación —qué permisos tiene cada rol y
 * qué rol tiene cada persona—, que es lo que cambia todos los días.
 *
 * ── Cómo añadir una pantalla nueva ────────────────────────────────────────
 *   1. Registra su ruta en config/routes.php, como siempre.
 *   2. Añádela aquí en 'rutas', con el permiso que exige.
 *   3. Si el permiso es nuevo, decláralo en 'modulos' para que aparezca entre
 *      las casillas del rol.
 * Una ruta que no esté aquí NO queda desprotegida —sigue pidiendo sesión—,
 * pero tampoco se puede restringir por rol, y queda anotada en el registro de
 * la instalación (storage/logs/app.log) para que se note el olvido.
 *
 * ── Las tres marcas de una ruta ───────────────────────────────────────────
 *   'permiso'   La llave que hace falta. 'publica' = sin sesión (el login);
 *               'libre' = cualquiera que haya entrado, sin permiso concreto.
 *   'registrar' El texto con el que la acción entra en la bitácora. Solo lo
 *               llevan las rutas que CAMBIAN algo: los listados y los
 *               endpoints que solo leen (aunque sean POST, que aquí hay
 *               muchos) no ensucian el registro.
 */

return [

    /*
     * ── Los módulos y sus permisos ────────────────────────────────────────
     * El orden es el que se ve en la pantalla de roles. Cada permiso es
     * 'modulo.accion' y el texto es lo que lee el administrador al marcarlo,
     * escrito como se lo explicaría a alguien: qué podrá hacer, no cómo se
     * llama la ruta.
     *
     * Dos marcas opcionales por módulo:
     *   'base'    El permiso sin el cual los demás del módulo no sirven: quien
     *             puede "verificar un pago" pero no "ver los pagos" nunca llega
     *             al botón. La pantalla de roles lo marca sola al marcar otro
     *             del módulo, RolesController lo completa al guardar, y la
     *             lista de roles avisa de los que se quedaron sin él.
     *   'sueltos' Los del módulo que NO dependen de la base, porque se usan
     *             desde otra pantalla (dar de alta un buzón se hace en
     *             Configuración, no en la bandeja del correo).
     *   'oculto'  El módulo existe y su ruta responde, pero config/menu.php no
     *             lo ofrece. Se sigue pudiendo repartir —el día que vuelva al
     *             menú, los roles ya estarán listos—, y la pantalla de roles
     *             lo dice para que nadie se pregunte dónde está.
     */
    'modulos' => [

        'seguimiento' => [
            'titulo'  => 'Seguimiento',
            'icono'   => 'fa-list-check',
            'resumen' => 'La cola única de trabajo: lo que falta y lo que no cuadra.',
            'base'    => 'seguimiento.ver',
            'permisos' => [
                'seguimiento.ver'      => 'Ver la cola de trabajo',
                'seguimiento.editar'   => 'Cambiar estados, responsables y anotaciones',
                'seguimiento.exportar' => 'Exportar la cola a Excel',
            ],
        ],

        'por_pagar' => [
            'titulo'  => 'Pagos',
            'icono'   => 'fa-file-invoice-dollar',
            'resumen' => 'El listado del pago y su verificación contra los XML.',
            'base'    => 'por_pagar.ver',
            'permisos' => [
                'por_pagar.ver'      => 'Ver los pagos y sus facturas',
                'por_pagar.cargar'   => 'Subir el listado del pago',
                'por_pagar.editar'   => 'Verificar y emparejar a mano',
                'por_pagar.eliminar' => 'Borrar pagos y quitar facturas del listado',
                'por_pagar.exportar' => 'Exportar el listado a Excel',
            ],
        ],

        'notas_credito' => [
            'titulo'  => 'Notas de crédito',
            'icono'   => 'fa-file-circle-minus',
            'resumen' => 'Los listados por período y su respaldo electrónico.',
            'base'    => 'notas_credito.ver',
            'permisos' => [
                'notas_credito.ver'      => 'Ver los listados de notas de crédito',
                'notas_credito.cargar'   => 'Subir un listado nuevo',
                'notas_credito.editar'   => 'Verificar, vincular y resolver líneas',
                'notas_credito.exportar' => 'Exportar a Excel',
            ],
        ],

        'correo' => [
            'titulo'  => 'Correo',
            'icono'   => 'fa-envelope-open-text',
            'resumen' => 'La captura de facturas desde los buzones.',
            'base'    => 'correo.ver',
            'sueltos' => ['correo.configurar'],
            'permisos' => [
                'correo.ver'         => 'Ver la bandeja y abrir los mensajes',
                'correo.sincronizar' => 'Bajar correo de los buzones',
                'correo.importar'    => 'Importar los comprobantes de un mensaje',
                'correo.descartar'   => 'Descartar mensajes e incidencias',
                'correo.configurar'  => 'Dar de alta buzones y activar la captura automática',
            ],
        ],

        'facturas_erp' => [
            'titulo'  => 'Facturas',
            'icono'   => 'fa-file-invoice',
            'resumen' => 'El reporte de saldos del sistema.',
            'base'    => 'facturas_erp.ver',
            'permisos' => [
                'facturas_erp.ver'      => 'Ver las facturas de la empresa',
                'facturas_erp.cargar'   => 'Subir el reporte del ERP',
                'facturas_erp.editar'   => 'Resolver incidencias y líneas en revisión, y quitar facturas del listado',
                'facturas_erp.exportar' => 'Exportar a Excel',
            ],
        ],

        'facturas_xml' => [
            'titulo'  => 'Facturas XML',
            'icono'   => 'fa-file-code',
            'resumen' => 'Carga y consulta de los comprobantes de facturas.',
            'base'    => 'facturas_xml.ver',
            'permisos' => [
                'facturas_xml.ver'      => 'Ver y consultar los comprobantes',
                'facturas_xml.cargar'   => 'Subir XML de facturas',
                'facturas_xml.exportar' => 'Exportar el listado a Excel',
            ],
        ],

        'notas_xml' => [
            'titulo'  => 'Notas XML',
            'icono'   => 'fa-file-import',
            'resumen' => 'Carga y consulta de los comprobantes de notas de crédito.',
            'base'    => 'notas_xml.ver',
            'permisos' => [
                'notas_xml.ver'      => 'Ver y consultar los comprobantes',
                'notas_xml.cargar'   => 'Subir XML de notas de crédito',
                'notas_xml.exportar' => 'Exportar el listado a Excel',
            ],
        ],

        'devoluciones' => [
            'titulo'  => 'Devoluciones',
            'icono'   => 'fa-rotate-left',
            'resumen' => 'Los reportes del sistema contra las notas electrónicas.',
            'base'    => 'devoluciones.ver',
            'oculto'  => true,
            'permisos' => [
                'devoluciones.ver'      => 'Ver las devoluciones',
                'devoluciones.cargar'   => 'Subir reportes de devolución',
                'devoluciones.editar'   => 'Verificar, confirmar y vincular',
                'devoluciones.eliminar' => 'Eliminar devoluciones cargadas',
                'devoluciones.exportar' => 'Exportar el listado a Excel',
            ],
        ],

        'documentos' => [
            'titulo'  => 'Documentos',
            'icono'   => 'fa-folder-open',
            'resumen' => 'El XML y el PDF de cada comprobante, desde cualquier módulo.',
            'permisos' => [
                'documentos.ver'       => 'Abrir la ficha de un comprobante',
                'documentos.descargar' => 'Descargar el XML y el PDF',
                'documentos.recuperar' => 'Recuperar archivos que no están en su sitio o subir el PDF que falta',
            ],
        ],

        'avisos' => [
            'titulo'  => 'Avisos',
            'icono'   => 'fa-bell',
            'resumen' => 'La campana: decisiones pendientes del equipo.',
            'base'    => 'avisos.ver',
            'permisos' => [
                'avisos.ver'      => 'Ver los avisos pendientes',
                'avisos.resolver' => 'Resolver, cerrar y decidir sobre un aviso',
            ],
        ],

        'configuracion' => [
            'titulo'  => 'Configuración',
            'icono'   => 'fa-gear',
            'resumen' => 'Los ajustes que valen para todo el sistema.',
            // Sin 'base': cada uno abre Configuración por su cuenta (ver
            // 'GET /configuracion' más abajo), y ninguno necesita al otro.
            //
            // 'configuracion.archivo' decía "Cambiar la carpeta donde se
            // guardan XML y PDF". Desde el 27/09/2026 todo va a SharePoint y
            // no hay carpeta que elegir: las credenciales son un archivo de
            // cada PC (local.graph.php). Lo que concede hoy es entrar a ver esa
            // sección y que el paso "SharePoint" de config/requisitos.php le
            // ofrezca el botón en vez de "pídeselo a…". La clave se conserva
            // porque ya está guardada en los roles.
            'permisos' => [
                'configuracion.ver'      => 'Entrar a Configuración y ver cómo está puesto el sistema',
                'configuracion.archivo'  => 'Revisar la conexión con SharePoint, donde se guardan XML y PDF',
                'configuracion.proveedores' => 'Importar el listado de proveedores del ERP y corregir sus códigos',
                'diagnostico.ver'        => 'Ver el diagnóstico de la instalación',
            ],
        ],

        'administracion' => [
            'titulo'  => 'Administración',
            'icono'   => 'fa-shield-halved',
            'resumen' => 'Quién entra, qué puede hacer y qué hizo. Con cuidado.',
            'permisos' => [
                'usuarios.ver'         => 'Ver la lista de usuarios',
                'usuarios.administrar' => 'Crear, editar y eliminar usuarios',
                'roles.administrar'    => 'Crear roles y decidir sus permisos',
                'empresas.administrar' => 'Dar de alta, editar y eliminar empresas',
                'bitacora.ver'         => 'Consultar la bitácora de actividad',
                'bitacora.depurar'     => 'Borrar los registros antiguos de la bitácora',
                'respaldo.administrar' => 'Generar y programar el respaldo de la base',
            ],
        ],
    ],

    /*
     * ── Qué exige cada ruta ───────────────────────────────────────────────
     * La clave es 'MÉTODO /ruta', tal cual está escrita en routes.php —con
     * sus {id} incluidos—, porque es la forma en que el enrutador la conoce.
     */
    'rutas' => [

        // ── Entrar y salir ────────────────────────────────────────────────
        'GET /login'  => ['permiso' => 'publica'],
        'POST /login' => ['permiso' => 'publica'],
        'GET /logout' => ['permiso' => 'libre'],

        // ── Inicio ────────────────────────────────────────────────────────
        // Sin permiso propio: es la pantalla a la que se cae cuando no se
        // puede entrar a ninguna otra, así que negarla dejaría a alguien sin
        // ningún sitio donde aterrizar.
        'GET /'     => ['permiso' => 'libre'],
        'GET /home' => ['permiso' => 'libre'],

        // ── Configuración ─────────────────────────────────────────────────
        // Una lista significa "con cualquiera de estos basta". La pantalla de
        // configuración hospeda secciones de varios dueños —usuarios, roles,
        // bitácora, respaldo—, así que exigir solo 'configuracion.ver' dejaría
        // a quien administra usuarios sin poder llegar a la lista de usuarios.
        // Cada sección se sigue dibujando según su propio permiso.
        //
        // Esta lista es la única: el engranaje de la barra y la entrada del
        // menú la leen de aquí (Permisos::puedeRuta). Antes cada uno tenía su
        // copia, y al llegar 'configuracion.proveedores' el engranaje se
        // quedó sin ella: quien solo tenía ese permiso veía la entrada del
        // menú pero no el engranaje.
        'GET /configuracion' => ['permiso' => [
            'configuracion.ver', 'usuarios.ver', 'usuarios.administrar',
            'roles.administrar', 'bitacora.ver', 'respaldo.administrar',
            'empresas.administrar', 'correo.configurar', 'configuracion.archivo',
            'configuracion.proveedores',
        ]],
        'POST /configuracion/proveedores/previa'   => ['permiso' => 'configuracion.proveedores'],
        'POST /configuracion/proveedores/listar'   => ['permiso' => 'configuracion.proveedores'],
        'POST /configuracion/proveedores/opciones' => ['permiso' => 'configuracion.proveedores'],
        'POST /configuracion/proveedores/guardar'  => ['permiso' => 'configuracion.proveedores',
                                                       'registrar' => 'Dio de alta o corrigió un código de proveedor'],
        'POST /configuracion/proveedores/dejar-de-aceptar' => ['permiso' => 'configuracion.proveedores',
                                                       'registrar' => 'Hizo que un código dejara de aceptar otra cédula'],
        'POST /configuracion/proveedores/eliminar' => ['permiso' => 'configuracion.proveedores',
                                                       'registrar' => 'Eliminó un código de proveedor de la lista'],
        'POST /configuracion/proveedores/ignorar'  => ['permiso' => 'configuracion.proveedores',
                                                       'registrar' => 'Cambió si un proveedor se procesa'],
        'POST /configuracion/proveedores/importar' => ['permiso' => 'configuracion.proveedores',
                                                       'registrar' => 'Importó el listado de proveedores del sistema'],

        // ── Avisos ────────────────────────────────────────────────────────
        'GET /avisos'                   => ['permiso' => 'avisos.ver'],
        'GET /avisos/resumen'           => ['permiso' => 'avisos.ver'],
        'POST /avisos/cerrar'           => ['permiso' => 'avisos.resolver', 'registrar' => 'Cerró un aviso'],
        'POST /avisos/proveedor/sin-codigo' => ['permiso' => 'avisos.ver'],
        'POST /avisos/proveedor/opciones' => ['permiso' => 'avisos.resolver'],
        'POST /avisos/proveedor/codigo'  => ['permiso' => 'avisos.resolver', 'registrar' => 'Dio de alta el código de un proveedor'],
        'POST /avisos/proveedor/ignorar' => ['permiso' => 'avisos.resolver', 'registrar' => 'Marcó un proveedor como no procesable'],

        // ── Diagnóstico y respaldo ────────────────────────────────────────
        'GET /diagnostico'                           => ['permiso' => 'diagnostico.ver'],
        'POST /diagnostico/respaldo/estado'          => ['permiso' => 'respaldo.administrar'],
        'POST /diagnostico/respaldo/iniciar'         => ['permiso' => 'respaldo.administrar', 'registrar' => 'Generó un respaldo de la base'],
        'POST /diagnostico/respaldo/auto/activar'    => ['permiso' => 'respaldo.administrar', 'registrar' => 'Activó el respaldo automático'],
        'POST /diagnostico/respaldo/auto/desactivar' => ['permiso' => 'respaldo.administrar', 'registrar' => 'Desactivó el respaldo automático'],

        // ── Empresas ──────────────────────────────────────────────────────
        // Dar de alta una empresa es de administración; ELEGIR con cuál se
        // trabaja lo hace cualquiera, y solo entre las que tiene asignadas
        // (lo comprueba SociedadesController: esconder el menú no basta).
        'POST /sociedades/crear'         => ['permiso' => 'empresas.administrar', 'registrar' => 'Registró una empresa'],
        'POST /sociedades/editar/{id}'   => ['permiso' => 'empresas.administrar', 'registrar' => 'Editó una empresa'],
        'POST /sociedades/eliminar/{id}' => ['permiso' => 'empresas.administrar', 'registrar' => 'Eliminó una empresa'],
        'POST /sociedades/activar/{id}'  => ['permiso' => 'libre',                'registrar' => 'Cambió de empresa'],
        // Aquí estaba 'POST /sociedades/almacen/{id}': elegir si una empresa
        // archivaba en la carpeta compartida o en SharePoint. Desde el
        // 27/09/2026 todas archivan en SharePoint y no hay nada que elegir.

        // ── Facturas XML ──────────────────────────────────────────────────
        'GET /facturas'                  => ['permiso' => 'facturas_xml.ver'],
        'GET /facturas/exportar'         => ['permiso' => 'facturas_xml.exportar', 'registrar' => 'Exportó los comprobantes XML'],
        'POST /facturas/subir'           => ['permiso' => 'facturas_xml.cargar', 'registrar' => 'Subió XML de facturas'],
        'POST /facturas/cola/iniciar'    => ['permiso' => 'facturas_xml.cargar', 'registrar' => 'Inició una carga por lotes de XML'],
        'POST /facturas/cola/agregar'    => ['permiso' => 'facturas_xml.cargar'],
        'POST /facturas/cola/procesar'   => ['permiso' => 'facturas_xml.cargar'],
        'GET /facturas/cola/estado/{id}' => ['permiso' => 'facturas_xml.ver'],
        'GET /facturas/ver/{id}'         => ['permiso' => 'facturas_xml.ver'],

        // ── Notas de crédito XML ──────────────────────────────────────────
        'GET /notas-xml'          => ['permiso' => 'notas_xml.ver'],
        'GET /notas-xml/exportar' => ['permiso' => 'notas_xml.exportar', 'registrar' => 'Exportó las notas XML'],
        'POST /notas-xml/subir'   => ['permiso' => 'notas_xml.cargar', 'registrar' => 'Subió XML de notas de crédito'],
        'GET /notas-xml/ver/{id}' => ['permiso' => 'notas_xml.ver'],

        // ── Documentos (transversal) ──────────────────────────────────────
        'GET /documentos/ficha/{id}' => ['permiso' => 'documentos.ver'],
        'GET /documentos/xml/{id}'   => ['permiso' => 'documentos.descargar'],
        'GET /documentos/pdf/{id}'   => ['permiso' => 'documentos.descargar'],
        'POST /documentos/recuperar' => ['permiso' => 'documentos.recuperar', 'registrar' => 'Recuperó archivos de un comprobante'],
        'POST /documentos/pdf/{id}/subir' => ['permiso' => 'documentos.recuperar', 'registrar' => 'Subió a mano el PDF de un comprobante'],

        // ── Correo ────────────────────────────────────────────────────────
        'GET /correo'                        => ['permiso' => 'correo.ver'],
        'POST /correo/listar'                => ['permiso' => 'correo.ver'],
        'POST /correo/sincronizar'           => ['permiso' => 'correo.sincronizar', 'registrar' => 'Sincronizó un buzón'],
        // /correo/config, /correo/carpetas y /correo/selector/* elegían la
        // carpeta compartida de documentos. Ya no hay carpeta que elegir: los
        // documentos van a SharePoint.
        'POST /correo/cuentas/guardar'       => ['permiso' => 'correo.configurar',  'registrar' => 'Guardó una cuenta de correo'],
        'POST /correo/cuentas/eliminar'      => ['permiso' => 'correo.configurar',  'registrar' => 'Eliminó una cuenta de correo'],
        'POST /correo/cuentas/usar'          => ['permiso' => 'correo.ver'],
        'POST /correo/cuentas/probar'        => ['permiso' => 'correo.configurar'],
        'POST /correo/carpetas-buzon'        => ['permiso' => 'correo.ver'],
        'POST /correo/auto/estado'           => ['permiso' => 'correo.ver'],
        'POST /correo/auto/activar'          => ['permiso' => 'correo.configurar',  'registrar' => 'Activó la captura automática de correo'],
        'POST /correo/auto/desactivar'       => ['permiso' => 'correo.configurar',  'registrar' => 'Desactivó la captura automática de correo'],
        'POST /correo/contenido'             => ['permiso' => 'correo.ver'],
        'POST /correo/adjunto'               => ['permiso' => 'correo.ver'],
        'POST /correo/procesar'              => ['permiso' => 'correo.importar',    'registrar' => 'Procesó mensajes del correo'],
        'POST /correo/importar'              => ['permiso' => 'correo.importar',    'registrar' => 'Importó comprobantes desde el correo'],
        'POST /correo/descartar'             => ['permiso' => 'correo.descartar',   'registrar' => 'Descartó un mensaje'],
        'POST /correo/guardar-lote'          => ['permiso' => 'correo.ver'],
        'POST /correo/general/estimar'       => ['permiso' => 'correo.sincronizar'],
        'POST /correo/general/crear'         => ['permiso' => 'correo.sincronizar', 'registrar' => 'Creó una descarga masiva de correo'],
        'POST /correo/general/estado'        => ['permiso' => 'correo.ver'],
        'POST /correo/general/incidencias'   => ['permiso' => 'correo.ver'],
        'POST /correo/incidencias/descartar' => ['permiso' => 'correo.descartar',   'registrar' => 'Descartó incidencias del correo'],
        'POST /correo/incidencias/restaurar' => ['permiso' => 'correo.descartar',   'registrar' => 'Restauró incidencias del correo'],
        'POST /correo/general/procesar'      => ['permiso' => 'correo.sincronizar'],
        'POST /correo/lotes/latido'          => ['permiso' => 'correo.ver'],
        'POST /correo/general/pausar'        => ['permiso' => 'correo.sincronizar', 'registrar' => 'Pausó una descarga de correo'],
        'POST /correo/general/reanudar'      => ['permiso' => 'correo.sincronizar', 'registrar' => 'Reanudó una descarga de correo'],
        'POST /correo/general/cancelar'      => ['permiso' => 'correo.sincronizar', 'registrar' => 'Canceló una descarga de correo'],

        // ── Seguimiento ───────────────────────────────────────────────────
        'GET /seguimiento'             => ['permiso' => 'seguimiento.ver'],
        'POST /seguimiento/actualizar' => ['permiso' => 'seguimiento.editar',   'registrar' => 'Actualizó un renglón de seguimiento'],
        'GET /seguimiento/detalle'     => ['permiso' => 'seguimiento.ver'],
        'GET /seguimiento/exportar'    => ['permiso' => 'seguimiento.exportar', 'registrar' => 'Exportó la cola de seguimiento'],

        // ── Pagos ───────────────────────────────────────────────
        'GET /por-pagar'                      => ['permiso' => 'por_pagar.ver'],
        'POST /por-pagar/subir'               => ['permiso' => 'por_pagar.cargar',   'registrar' => 'Subió el listado de pago'],
        'POST /por-pagar/previsualizar'       => ['permiso' => 'por_pagar.cargar'],
        'GET /por-pagar/progreso'             => ['permiso' => 'por_pagar.cargar'],
        'POST /por-pagar/comparar-listado'    => ['permiso' => 'por_pagar.cargar'],
        'POST /por-pagar/actualizar-listado'  => ['permiso' => 'por_pagar.cargar',   'registrar' => 'Actualizó el listado del pago'],
        'POST /por-pagar/verificar/{id}'      => ['permiso' => 'por_pagar.editar',   'registrar' => 'Verificó un pago'],
        'POST /por-pagar/pago/borrar/{id}'    => ['permiso' => 'por_pagar.eliminar', 'registrar' => 'Borró un pago'],
        'POST /por-pagar/factura/quitar/{id}' => ['permiso' => 'por_pagar.eliminar', 'registrar' => 'Quitó una factura del listado'],
        'GET /por-pagar/exportar'             => ['permiso' => 'por_pagar.exportar', 'registrar' => 'Exportó el pago'],
        'GET /por-pagar/sin-coincidencia'     => ['permiso' => 'por_pagar.ver'],
        'POST /por-pagar/forzar'              => ['permiso' => 'por_pagar.editar',   'registrar' => 'Forzó un emparejamiento'],
        'POST /por-pagar/vincular-mismo-numero' => ['permiso' => 'por_pagar.editar', 'registrar' => 'Vinculó el XML de su mismo número'],

        // ── Facturas del ERP ──────────────────────────────────────────────
        'GET /facturas-erp'                        => ['permiso' => 'facturas_erp.ver'],
        'POST /facturas-erp/previa'                => ['permiso' => 'facturas_erp.cargar'],
        'POST /facturas-erp/subir'                 => ['permiso' => 'facturas_erp.cargar',   'registrar' => 'Subió el reporte de facturas del ERP'],
        'GET /facturas-erp/incidencias'            => ['permiso' => 'facturas_erp.ver'],
        'POST /facturas-erp/incidencias/descartar' => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Descartó incidencias del ERP'],
        'POST /facturas-erp/incidencias/restaurar' => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Restauró incidencias del ERP'],
        'GET /facturas-erp/revision'               => ['permiso' => 'facturas_erp.ver'],
        'POST /facturas-erp/revision/guardar'      => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Guardó líneas en revisión del ERP'],
        'POST /facturas-erp/revision/descartar'    => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Descartó líneas en revisión del ERP'],
        'POST /facturas-erp/revision/enredo'       => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Resolvió un enredo de filas iguales del ERP'],
        'POST /facturas-erp/revision/olvidar'      => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Olvidó una corrección guardada del ERP'],
        'GET /facturas-erp/exportar'               => ['permiso' => 'facturas_erp.exportar', 'registrar' => 'Exportó las facturas del ERP'],
        'POST /facturas-erp/quitar'                => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Quitó una factura del listado del ERP'],
        'GET /facturas-erp/quitadas'               => ['permiso' => 'facturas_erp.ver'],
        'POST /facturas-erp/quitadas/devolver'     => ['permiso' => 'facturas_erp.editar',   'registrar' => 'Devolvió al listado una factura quitada del ERP'],

        // ── Notas de crédito ──────────────────────────────────────────────
        'GET /notas-credito'                     => ['permiso' => 'notas_credito.ver'],
        'GET /notas-credito/exportar'            => ['permiso' => 'notas_credito.exportar', 'registrar' => 'Exportó el listado de notas de crédito'],
        'POST /notas-credito/previa'             => ['permiso' => 'notas_credito.cargar'],
        'POST /notas-credito/subir'              => ['permiso' => 'notas_credito.cargar', 'registrar' => 'Subió un listado de notas de crédito'],
        'GET /notas-credito/buscar'              => ['permiso' => 'notas_credito.ver'],
        'POST /notas-credito/verificar/{id}'     => ['permiso' => 'notas_credito.editar', 'registrar' => 'Verificó un listado de notas de crédito'],
        'GET /notas-credito/historial/{id}'      => ['permiso' => 'notas_credito.ver'],
        'GET /notas-credito/aplicables/{id}'     => ['permiso' => 'notas_credito.ver'],
        'GET /notas-credito/candidatas'          => ['permiso' => 'notas_credito.ver'],
        'POST /notas-credito/vincular'           => ['permiso' => 'notas_credito.editar', 'registrar' => 'Vinculó una nota de crédito a su XML'],
        'POST /notas-credito/desvincular'        => ['permiso' => 'notas_credito.editar', 'registrar' => 'Desvinculó una nota de crédito'],
        'GET /notas-credito/revision'            => ['permiso' => 'notas_credito.ver'],
        'POST /notas-credito/revision/guardar'   => ['permiso' => 'notas_credito.editar', 'registrar' => 'Guardó líneas en revisión de notas de crédito'],
        'POST /notas-credito/revision/descartar' => ['permiso' => 'notas_credito.editar', 'registrar' => 'Descartó líneas en revisión de notas de crédito'],
        'POST /notas-credito/revision/olvidar'   => ['permiso' => 'notas_credito.editar', 'registrar' => 'Olvidó una corrección guardada de notas de crédito'],

        // ── Devoluciones ──────────────────────────────────────────────────
        'GET /devoluciones'                 => ['permiso' => 'devoluciones.ver'],
        'GET /devoluciones/exportar'        => ['permiso' => 'devoluciones.exportar', 'registrar' => 'Exportó el listado de devoluciones'],
        'POST /devoluciones/subir'          => ['permiso' => 'devoluciones.cargar',   'registrar' => 'Subió un reporte de devoluciones'],
        'GET /devoluciones/detalle/{id}'    => ['permiso' => 'devoluciones.ver'],
        'POST /devoluciones/verificar'      => ['permiso' => 'devoluciones.editar',   'registrar' => 'Verificó devoluciones'],
        'POST /devoluciones/verificar/{id}' => ['permiso' => 'devoluciones.editar',   'registrar' => 'Verificó una devolución'],
        'POST /devoluciones/confirmar'      => ['permiso' => 'devoluciones.editar',   'registrar' => 'Confirmó una devolución'],
        'POST /devoluciones/descartar'      => ['permiso' => 'devoluciones.editar',   'registrar' => 'Descartó una devolución'],
        'POST /devoluciones/vincular'       => ['permiso' => 'devoluciones.editar',   'registrar' => 'Vinculó una devolución'],
        'POST /devoluciones/desvincular'    => ['permiso' => 'devoluciones.editar',   'registrar' => 'Desvinculó una devolución'],
        'POST /devoluciones/eliminar/{id}'  => ['permiso' => 'devoluciones.eliminar', 'registrar' => 'Eliminó una devolución'],
        'GET /devoluciones/pdf/{id}'        => ['permiso' => 'devoluciones.ver'],

        // ── Usuarios ──────────────────────────────────────────────────────
        'GET /usuarios'                => ['permiso' => 'usuarios.ver'],
        'GET /usuarios/crear'          => ['permiso' => 'usuarios.administrar'],
        'POST /usuarios/crear'         => ['permiso' => 'usuarios.administrar', 'registrar' => 'Creó un usuario'],
        'GET /usuarios/editar/{id}'    => ['permiso' => 'usuarios.administrar'],
        'POST /usuarios/editar/{id}'   => ['permiso' => 'usuarios.administrar', 'registrar' => 'Editó un usuario'],
        'POST /usuarios/eliminar/{id}' => ['permiso' => 'usuarios.administrar', 'registrar' => 'Eliminó un usuario'],

        // ── Roles ─────────────────────────────────────────────────────────
        'GET /roles'                => ['permiso' => 'roles.administrar'],
        'GET /roles/crear'          => ['permiso' => 'roles.administrar'],
        'POST /roles/crear'         => ['permiso' => 'roles.administrar', 'registrar' => 'Creó un rol'],
        'GET /roles/editar/{id}'    => ['permiso' => 'roles.administrar'],
        'POST /roles/editar/{id}'   => ['permiso' => 'roles.administrar', 'registrar' => 'Cambió los permisos de un rol'],
        'POST /roles/eliminar/{id}' => ['permiso' => 'roles.administrar', 'registrar' => 'Eliminó un rol'],

        // ── Bitácora ──────────────────────────────────────────────────────
        // Consultarla no se registra: cada visita generaría un renglón nuevo
        // y la bitácora acabaría hablando solo de sí misma. Exportarla sí,
        // porque saca los datos de la aplicación.
        'GET /bitacora'              => ['permiso' => 'bitacora.ver'],
        'GET /bitacora/detalle/{id}' => ['permiso' => 'bitacora.ver'],
        'GET /bitacora/exportar'     => ['permiso' => 'bitacora.ver',     'registrar' => 'Exportó la bitácora'],
        'POST /bitacora/depurar'     => ['permiso' => 'bitacora.depurar', 'registrar' => 'Depuró la bitácora'],
    ],
];
