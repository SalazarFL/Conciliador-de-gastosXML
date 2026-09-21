<?php
/**
 * Lo que tiene que estar puesto ANTES de que el sistema sirva para algo.
 *
 * Hay tres cosas sin las cuales ningún módulo hace su trabajo, solo lo
 * aparenta:
 *
 *   · el catálogo de proveedores — sin él no se sabe de quién es cada código
 *     del ERP, y lo que se importe se empareja por el parecido del nombre:
 *     así fue como una factura terminó con el comprobante de otro proveedor,
 *     y después figuraba respaldada, que es el estado que nadie vuelve a
 *     revisar;
 *   · al menos una empresa — todo documento se guarda y se consulta acotado a
 *     una, así que sin ninguna los listados salen vacíos y las cargas no
 *     tienen dónde caer;
 *   · al menos una cuenta de correo — es de donde entran las facturas; sin
 *     buzón, la bandeja es una pantalla que nunca se llena.
 *
 * ── Por qué se cierra el módulo y no se avisa y ya ────────────────────────
 * Un aviso arriba de una pantalla vacía se lee como "hoy no hay trabajo", no
 * como "falta configurar". Y peor: las pantallas de carga siguen aceptando
 * archivos, así que quien no leyó el aviso sube el listado del mes y lo
 * importa a ciegas. Eso ya pasó. Cerrar la puerta y decir cuál es el paso que
 * falta es lo único que distingue "no está configurado" de "está vacío".
 *
 * ── Esto no es un permiso ─────────────────────────────────────────────────
 * Un permiso dice quién puede entrar; un requisito dice si hay adónde entrar.
 * Por eso son dos listas y dos guardias: al administrador no se le abre el
 * módulo por ser administrador —tampoco a él le sirve—, y a nadie se le cierra
 * por su rol. Se cierra para todos, hasta que el paso esté hecho.
 *
 * ── Cómo añadir uno ───────────────────────────────────────────────────────
 *   1. Decláralo aquí en 'requisitos', con cómo se comprueba y dónde se
 *      arregla.
 *   2. Apúntalo en 'modulos', en cada módulo que no funcione sin él. Los ids
 *      de módulo son los de config/permisos.php: de ahí saca el enrutador a
 *      qué módulo pertenece cada ruta.
 * Un módulo que no aparezca en 'modulos' no se cierra nunca. Configuración y
 * Administración no aparecen a propósito: son justamente donde se arreglan
 * estas tres cosas, y cerrarlas dejaría el sistema sin salida.
 */

return [

    /*
     * ── Los tres pasos ────────────────────────────────────────────────────
     * El orden es el que se le enseña a quien acaba de instalar, y es el
     * orden en que conviene hacerlos: primero de quién son los códigos,
     * después con qué empresa se trabaja, y al final de qué buzón entran las
     * facturas.
     *
     *   'titulo'    El paso, como se nombra en la lista.
     *   'falta'     Qué se ve cuando NO está hecho. Se lee solo.
     *   'porque'    Por qué el módulo no funciona sin esto.
     *   'donde'     El nombre de la pantalla donde se hace, en castellano.
     *   'ruta'      Adónde lleva el botón, colgando de APP_URL.
     *   'permiso'   La llave que hace falta para hacerlo. Quien no la tenga
     *               ve el paso igual, pero se le dice a quién pedírselo en
     *               vez de ofrecerle un botón que acaba en "no tienes
     *               permiso".
     *   'comprobar' Cómo se responde. Una consulta corta, y nada más: esto
     *               se pregunta en cada petición.
     */
    'requisitos' => [

        'proveedores' => [
            'titulo'  => 'El catálogo de proveedores',
            'falta'   => 'Todavía no se ha importado el listado de proveedores del ERP.',
            'porque'  => 'Sin él no se sabe qué código es de qué cédula, y lo que se '
                       . 'importe ahora se emparejaría a ciegas.',
            'donde'   => 'Configuración → Proveedores del sistema',
            'ruta'    => '/configuracion?ir=proveedores',
            'permiso' => 'configuracion.proveedores',
            'comprobar' => static function (): bool {
                require_once __DIR__ . '/../core/Model.php';
                require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
                return ProveedorCodigoErp::hayListado();
            },
        ],

        'empresa' => [
            'titulo'  => 'Al menos una empresa',
            'falta'   => 'Todavía no hay ninguna empresa registrada.',
            'porque'  => 'Cada documento se guarda y se consulta acotado a una empresa: '
                       . 'sin ninguna, los listados salen vacíos y lo que se cargue no '
                       . 'tiene dónde quedar.',
            'donde'   => 'Configuración → Empresas',
            'ruta'    => '/configuracion?ir=empresas',
            'permiso' => 'empresas.administrar',
            'comprobar' => static function (): bool {
                require_once __DIR__ . '/../core/Model.php';
                require_once __DIR__ . '/../models/Sociedad.php';
                return (new Sociedad())->hayAlguna();
            },
        ],

        'correo' => [
            'titulo'  => 'Al menos una cuenta de correo',
            'falta'   => 'Todavía no hay ningún buzón dado de alta.',
            'porque'  => 'Es de donde entran las facturas: sin buzón, la bandeja es una '
                       . 'pantalla que nunca se llena.',
            'donde'   => 'Configuración → Cuentas de correo',
            'ruta'    => '/configuracion?ir=correo',
            'permiso' => 'correo.configurar',
            'comprobar' => static function (): bool {
                require_once __DIR__ . '/../core/Model.php';
                require_once __DIR__ . '/../models/CorreoCuenta.php';
                return (new CorreoCuenta())->contarTotal() > 0;
            },
        ],
    ],

    /*
     * ── Qué necesita cada módulo ──────────────────────────────────────────
     * Los ids son los de config/permisos.php. El enrutador averigua el módulo
     * de una ruta por el permiso que exige ('correo.ver' → 'correo'), así que
     * una ruta nueva queda cubierta sola con solo declarar su permiso.
     *
     * Quién NO está, y por qué:
     *   · 'configuracion' y 'administracion' — es donde se arreglan los tres
     *     pasos. Cerrarlas sería cerrar la puerta por fuera.
     *   · 'avisos' — la campana se consulta desde TODAS las pantallas, y ahí
     *     mismo se resuelve el proveedor al que le falta código. Es parte del
     *     arreglo, no del trabajo que el arreglo habilita.
     *   · 'documentos' — abre el XML o el PDF de un comprobante que ya
     *     existe. Si existe, es que el paso ya estaba hecho cuando entró.
     */
    'modulos' => [
        'seguimiento'   => ['proveedores', 'empresa'],
        'por_pagar'     => ['proveedores', 'empresa'],
        'notas_credito' => ['proveedores', 'empresa'],
        'facturas_erp'  => ['proveedores', 'empresa'],
        'facturas_xml'  => ['proveedores', 'empresa'],
        'notas_xml'     => ['proveedores', 'empresa'],
        'devoluciones'  => ['proveedores', 'empresa'],

        // El único que además necesita el buzón: es su materia prima.
        'correo'        => ['proveedores', 'empresa', 'correo'],
    ],

    /*
     * ── Las que nunca se cierran, aunque su módulo sí ─────────────────────
     * Cuatro rutas viven en CorreoController por historia, pero a quien
     * sirven es a la pantalla de Configuración: el explorador de carpetas del
     * disco y el estado de la captura automática. Cerrarlas por lo mismo que
     * cierra el módulo Correo dejaría medio Configuración contestando errores
     * justo mientras se la está usando para arreglarlo.
     *
     * Lo que se hace con el permiso que ARREGLA un paso —dar de alta el
     * primer buzón, registrar la primera empresa, importar el catálogo— no
     * hace falta apuntarlo aquí: eso se reconoce solo, por el permiso que
     * declara cada requisito. Ver Requisitos::esArreglo().
     */
    'abiertas' => [
        'POST /correo/carpetas',        // el explorador de carpetas del disco
        'POST /correo/selector/abrir',  // el mismo, con la ventana de Windows
        'POST /correo/selector/estado',
        'POST /correo/auto/estado',     // qué dice hoy la sección Automatización
    ],
];
