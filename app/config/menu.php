<?php
/**
 * Qué módulos se le ofrecen a cada persona, y en qué orden.
 *
 * Es UNA sola lista, y la usan los dos sitios donde el sistema se presenta:
 * la barra lateral (app/views/layout/header.php) y el panel principal
 * (app/views/home/index.php). Antes cada uno tenía la suya, y por eso el
 * panel ofrecía cuatro atajos mientras la barra enseñaba once módulos: quien
 * entraba veía dos sistemas distintos según dónde mirara.
 *
 * Esconder no es proteger —de eso se encarga el enrutador con
 * config/permisos.php, que comprueba la llave aunque se escriba la dirección
 * a mano—, pero sí es lo que hace que el sistema se entienda: ofrecer un
 * atajo que acaba en "no tienes permiso" no ayuda a nadie.
 *
 * ── Qué lleva cada entrada ────────────────────────────────────────────────
 *   'ruta'     Adónde va, colgando de APP_URL.
 *   'segmento' El trozo de la dirección con el que se marca "estás aquí".
 *              Tiene que terminar donde termina la URI o antes de una barra:
 *              ver navCoincide(), o /facturas-erp marcaría también /facturas.
 *   'titulo'   El nombre corto, el que cabe en la barra.
 *   'icono'    Clase de Font Awesome, sin el 'fas'.
 *   'ayuda'    El texto del globo al posar el cursor.
 *   'resumen'  El renglón que explica el módulo en el panel.
 *   'permiso'  La llave que hace falta. Una lista significa "con cualquiera
 *              de estas basta", y es lo que necesita Configuración, que
 *              hospeda secciones de varios dueños.
 *   'tono'     Color de su pastilla en el panel: navy, oro, verde o gris.
 */

return [

    'trabajo' => [
        'titulo'   => 'Trabajo del día',
        'entradas' => [

            'seguimiento' => [
                'ruta'     => '/seguimiento',
                'segmento' => 'seguimiento',
                'titulo'   => 'Seguimiento',
                'icono'    => 'fa-list-check',
                'ayuda'    => 'Todo lo que le falta respaldo o no cuadra, en una sola lista',
                'resumen'  => 'La cola única: lo que falta y lo que no cuadra',
                'permiso'  => 'seguimiento.ver',
                'tono'     => 'navy',
            ],
        ],
    ],

    'modulos' => [
        'titulo'   => 'Módulos',
        'entradas' => [

            'por_pagar' => [
                'ruta'     => '/por-pagar',
                'segmento' => 'por-pagar',
                'titulo'   => 'Pagos',
                'icono'    => 'fa-file-invoice-dollar',
                'ayuda'    => 'El listado del pago y su verificación contra los XML',
                'resumen'  => 'Verificar el listado del pago',
                'permiso'  => 'por_pagar.ver',
                'tono'     => 'verde',
            ],

            'notas_credito' => [
                'ruta'     => '/notas-credito',
                'segmento' => 'notas-credito',
                'titulo'   => 'Notas de crédito',
                'icono'    => 'fa-file-circle-minus',
                'ayuda'    => 'Los listados por período y su respaldo electrónico',
                'resumen'  => 'Listados por período y su respaldo',
                'permiso'  => 'notas_credito.ver',
                'tono'     => 'verde',
            ],

            'correo' => [
                'ruta'     => '/correo',
                'segmento' => 'correo',
                'titulo'   => 'Correo',
                'icono'    => 'fa-envelope-open-text',
                'ayuda'    => 'La captura de facturas desde los buzones',
                'resumen'  => 'Buscar e importar XML y PDF del buzón',
                'permiso'  => 'correo.ver',
                'tono'     => 'oro',
            ],

            'facturas_erp' => [
                'ruta'     => '/facturas-erp',
                'segmento' => 'facturas-erp',
                'titulo'   => 'Facturas',
                'icono'    => 'fa-file-invoice',
                'ayuda'    => 'Las facturas de la empresa y sus saldos',
                'resumen'  => 'El listado del sistema y sus saldos',
                'permiso'  => 'facturas_erp.ver',
                'tono'     => 'navy',
            ],

            /*
             * Devoluciones no se ofrece por ahora: el módulo está completo y
             * su ruta responde, pero todavía no se presenta como parte del
             * trabajo. Lo que se retiró es la entrada, no la pantalla — para
             * volver a ofrecerla basta con devolver este bloque:
             *
             *   'devoluciones' => [
             *       'ruta' => '/devoluciones', 'segmento' => 'devoluciones',
             *       'titulo' => 'Devoluciones', 'icono' => 'fa-rotate-left',
             *       'ayuda' => 'Los reportes del sistema contra las notas electrónicas',
             *       'resumen' => 'Reportes contra las notas electrónicas',
             *       'permiso' => 'devoluciones.ver', 'tono' => 'verde',
             *   ],
             */
        ],
    ],

    /*
     * Las dos pantallas de carga de comprobantes tienen sección propia. Antes
     * se llegaba a ellas desde dentro de Facturas y de Notas de crédito, y por
     * eso ambas entradas se marcaban activas a la vez: ahora cada módulo se
     * marca solo por su propia ruta.
     */
    'xml' => [
        'titulo'   => 'XML',
        'entradas' => [

            'facturas_xml' => [
                'ruta'     => '/facturas',
                'segmento' => 'facturas',
                'titulo'   => 'Facturas XML',
                'icono'    => 'fa-file-code',
                'ayuda'    => 'Carga y consulta de los comprobantes XML de facturas',
                'resumen'  => 'Cargar y consultar comprobantes de facturas',
                'permiso'  => 'facturas_xml.ver',
                'tono'     => 'navy',
            ],

            'notas_xml' => [
                'ruta'     => '/notas-xml',
                'segmento' => 'notas-xml',
                'titulo'   => 'Notas XML',
                'icono'    => 'fa-file-import',
                'ayuda'    => 'Carga y consulta de los comprobantes XML de notas de crédito',
                'resumen'  => 'Cargar y consultar comprobantes de notas',
                'permiso'  => 'notas_xml.ver',
                'tono'     => 'navy',
            ],
        ],
    ],

    'administracion' => [
        'titulo'   => 'Administración',
        'entradas' => [

            /*
             * Todo lo que se ajusta vive en una sola pantalla, y hospeda
             * secciones de varios dueños —usuarios, roles, bitácora, respaldo,
             * empresas—. Por eso la lista de llaves: exigir solo
             * 'configuracion.ver' dejaría a quien administra usuarios sin
             * poder llegar a la lista de usuarios. Es la misma lista que la
             * ruta declara en config/permisos.php.
             */
            'configuracion' => [
                'ruta'     => '/configuracion',
                'segmento' => 'configuracion',
                'titulo'   => 'Configuración',
                'icono'    => 'fa-gear',
                'ayuda'    => 'Carpeta de XML y PDF, cuentas de correo, empresas, usuarios, roles y respaldo',
                'resumen'  => 'Empresas, usuarios, correo, archivo y respaldo',
                'permiso'  => [
                    'configuracion.ver', 'usuarios.ver', 'usuarios.administrar',
                    'roles.administrar', 'bitacora.ver', 'respaldo.administrar',
                    'empresas.administrar', 'correo.configurar', 'configuracion.archivo',
                    'configuracion.proveedores',
                ],
                'tono'     => 'gris',
            ],

            /*
             * La bitácora tiene entrada propia además de su sección en
             * Configuración: quien audita entra aquí varias veces al día y no
             * tiene por qué pasar por los ajustes cada vez.
             */
            'bitacora' => [
                'ruta'     => '/bitacora',
                'segmento' => 'bitacora',
                'titulo'   => 'Actividad',
                'icono'    => 'fa-clock-rotate-left',
                'ayuda'    => 'Quién hizo qué, y cuándo',
                'resumen'  => 'La bitácora: quién hizo qué, y cuándo',
                'permiso'  => 'bitacora.ver',
                'tono'     => 'gris',
            ],

            'diagnostico' => [
                'ruta'     => '/diagnostico',
                'segmento' => 'diagnostico',
                'titulo'   => 'Diagnóstico',
                'icono'    => 'fa-stethoscope',
                'ayuda'    => 'Revisar la instalación de esta computadora',
                'resumen'  => 'Revisar la instalación de esta computadora',
                'permiso'  => 'diagnostico.ver',
                'tono'     => 'gris',
            ],
        ],
    ],
];
