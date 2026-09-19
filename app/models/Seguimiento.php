<?php
/**
 * La cola única de trabajo: todo lo que le falta respaldo o no cuadra, venga
 * de una nota de crédito o de una factura del ERP, en una sola lista.
 *
 * Cada renglón responde tres preguntas: ¿tiene su XML?, ¿tiene su PDF?, ¿el
 * monto cuadra? Mientras alguna falle, hay tarea. La tarea concreta es la
 * primera que falle, en ese orden: sin XML no tiene sentido hablar del PDF.
 *
 * De ahí sale el ESTADO, que es en qué pestaña vive el documento:
 *
 *   sin saldo                          -> cerrada   (ya se pagó o se aplicó)
 *   con saldo y algo que falta         -> pendiente
 *   con saldo y respaldo completo      -> lista     (lista para pagar o rebajar)
 *
 * 'revision' no se calcula nunca: es la bandeja de los enredos y se entra a
 * ella a mano, explicando el problema. El saldo no la afecta.
 *
 * Encima del cálculo puede haber una MARCA A MANO, que manda siempre y no se
 * mueve sola: quien puso una factura donde la puso sabía por qué. Cuando los
 * datos dejan de concordar con la marca —llegó el XML de algo que está en
 * revisión, se pagó algo marcado como pendiente— la fila lo avisa, pero no se
 * cambia sola: eso lo decide una persona (ver `desajustada` en la vista).
 *
 * La marca vive en su propia tabla y la fila se crea solo cuando alguien actúa
 * (ver database/migrations/004_seguimiento.sql). Sin fila —o con fila pero sin
 * estado, que es lo que deja una anotación suelta— manda el cálculo.
 */
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/AplicacionNotaCredito.php';
require_once __DIR__ . '/Notificacion.php';
require_once __DIR__ . '/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/BusquedaImporte.php';

class Seguimiento extends Model
{
    protected $table = 'seguimiento';

    /** Rutas absolutas guardadas como relativas: el Model las rehidrata. */
    protected $camposRuta = ['ruta_xml', 'ruta_pdf'];

    /**
     * Los dos modos de la cola: la misma pantalla, dos preguntas inversas.
     *
     *   sistema  Arranca del ERP —facturas y notas del reporte— y pregunta
     *            qué le falta a cada registro: su XML, su PDF, o que cuadre.
     *            Es la cola de siempre.
     *
     *   correo   Arranca de los comprobantes XML ya cargados —los que
     *            entraron por el correo y los que alguien subió a mano— y
     *            pregunta lo contrario: cuáles de estos NO aparecen en el
     *            sistema. Un XML sin registro en el ERP es plata que llegó
     *            y que nadie registró, y hasta ahora no había dónde verla:
     *            los listados de Facturas XML y Notas XML enseñan lo que
     *            hay, no lo que falta del otro lado.
     *
     * Comparten toda la maquinaria —pestañas, marca a mano, responsable,
     * recordatorio, bitácora, exportación— porque el trabajo es el mismo:
     * perseguir documentos hasta que dejen de faltar.
     */
    public const MODOS = [
        'sistema' => 'Sistema',
        'correo'  => 'Correo',
    ];

    public const MODO_SISTEMA = 'sistema';
    public const MODO_CORREO  = 'correo';

    public const ESTADOS = [
        'pendiente' => 'Pendientes',
        'revision'  => 'En revisión',
        'lista'     => 'Listas',
        'cerrada'   => 'Cerradas',
    ];

    /**
     * Las pestañas del modo Correo. Son tres y no cuatro: 'lista' no existe
     * acá porque no hay grado intermedio —un comprobante está en el sistema
     * o no está—, y meter una pestaña que nunca se llena sola sería ofrecer
     * un casillero sin pregunta detrás.
     *
     * Las claves son las mismas que en Sistema a propósito: la marca a mano
     * vive en la columna `estado` de la tabla, que es un ENUM, y dos juegos
     * de nombres para las mismas tres casillas obligarían a traducir en cada
     * lectura y en cada escritura.
     */
    public const ESTADOS_CORREO = [
        'pendiente' => 'Sin vincular',
        'revision'  => 'En seguimiento',
        'cerrada'   => 'Cerradas',
    ];

    /** El único que no se calcula: se entra a mano y exige explicar por qué. */
    public const ESTADO_A_MANO = 'revision';

    /** Devolver un renglón al cálculo: borra la marca en vez de ponerle otra. */
    public const SIN_MARCA = 'auto';

    public const TAREAS = [
        'falta_xml'  => 'Falta el XML',
        'falta_pdf'  => 'Falta el PDF',
        'diferencia' => 'Diferencia de monto',
        'completo'   => 'Respaldo completo',
    ];

    /**
     * Lo que le falta a un comprobante en el modo Correo: una sola cosa.
     *
     * No se pregunta por el XML —el renglón ES el XML— ni por el monto: si
     * el comprobante no está en el sistema no hay contra qué compararlo, y
     * si está, la diferencia ya la persigue el modo Sistema desde el otro
     * lado. Lo que falta el PDF se sigue viendo en su chip, pero no es
     * tarea acá: un PDF ausente no impide registrar la factura en el ERP.
     */
    public const TAREAS_CORREO = [
        'sin_sistema' => 'No está en el sistema',
        'completo'    => 'Ya está en el sistema',
    ];

    /**
     * De qué tabla sale cada renglón, por modo. Los cuatro valores conviven
     * en la columna `origen` de `seguimiento`, así que la gestión de un modo
     * no puede pisar la del otro aunque los id se repitan entre tablas.
     */
    public const ORIGENES = [
        self::MODO_SISTEMA => [
            'factura'      => 'Facturas',
            'nota_credito' => 'Notas de crédito',
        ],
        self::MODO_CORREO => [
            'xml_factura' => 'Facturas',
            'xml_nota'    => 'Notas de crédito',
        ],
    ];

    /**
     * Cómo se ordena la cola. El primero es el de omisión.
     *
     * Vive acá y no en la vista porque el desplegable tiene que ofrecer
     * exactamente lo que la consulta sabe hacer: una opción de más en la
     * pantalla es un orden que en silencio cae en otro.
     *
     * Salió de aquí "Dinero en juego", que era el orden por omisión. La cola
     * se trabaja por fecha —lo que entró este pago—, no de mayor a menor
     * importe: encabezar siempre con las mismas facturas grandes escondía al
     * fondo lo recién llegado, que es lo que hay que registrar. El monto
     * sigue en su columna, y "Monto desde" sigue acotando por él si llega
     * por la URL.
     */
    public const ORDENES = [
        'reciente'   => 'Más reciente',
        'antiguedad' => 'Más antiguo',
        'proveedor'  => 'Proveedor',
        'movimiento' => 'Último movimiento',
    ];

    public const ORDEN_POR_OMISION = 'reciente';

    /** Cómo llegó el comprobante, para acotar el modo Correo. */
    public const LLEGADAS = [
        'correo' => 'Llegaron por correo',
        'carga'  => 'Subidos a mano',
    ];

    /** El modo pedido, o el de siempre si no se reconoce. */
    public static function modo($valor)
    {
        $valor = trim((string) $valor);
        return isset(self::MODOS[$valor]) ? $valor : self::MODO_SISTEMA;
    }

    /** Las pestañas de un modo. */
    public static function estadosDe($modo)
    {
        return self::modo($modo) === self::MODO_CORREO ? self::ESTADOS_CORREO : self::ESTADOS;
    }

    /** El desplegable "qué falta" de un modo. */
    public static function tareasDe($modo)
    {
        return self::modo($modo) === self::MODO_CORREO ? self::TAREAS_CORREO : self::TAREAS;
    }

    /** El desplegable "tipo de documento" de un modo. */
    public static function origenesDe($modo)
    {
        return self::ORIGENES[self::modo($modo)];
    }

    /** El modo en el que está esta instancia. */
    private $modo = self::MODO_SISTEMA;

    /**
     * Pone la cola en un modo. Devuelve $this para poder encadenarlo con el
     * loadModel() del controlador, que no admite argumentos.
     */
    public function enModo($modo)
    {
        $this->modo = self::modo($modo);
        return $this;
    }

    private function esCorreo()
    {
        return $this->modo === self::MODO_CORREO;
    }

    /**
     * Las clases de nota que entran a la cola: todas menos 'ajuste'.
     *
     * Las de ajuste no se persiguen —nunca llevan XML, se aplican solas— así
     * que la cola no las trae y el filtro no debe ofrecerlas. Los nombres
     * salen de ClaseNotaCredito para que esta pantalla y la de Notas de
     * crédito llamen igual a lo mismo.
     *
     * Una factura no tiene clase (la unión la trae en NULL), así que filtrar
     * por clase deja fuera las facturas por su propia cuenta.
     */
    public const CLASES = [
        ClaseNotaCredito::DIRECTA => ClaseNotaCredito::ETIQUETAS[ClaseNotaCredito::DIRECTA],
        ClaseNotaCredito::COSTO   => ClaseNotaCredito::ETIQUETAS[ClaseNotaCredito::COSTO],
        ClaseNotaCredito::CAMBIO  => ClaseNotaCredito::ETIQUETAS[ClaseNotaCredito::CAMBIO],
        ClaseNotaCredito::REVISAR => ClaseNotaCredito::ETIQUETAS[ClaseNotaCredito::REVISAR],
    ];

    /** Las clases que pide el filtro, acotadas a las que esta cola contiene. */
    public static function clasesPedidas($valor)
    {
        return ClaseNotaCredito::clasesPedidas($valor, array_keys(self::CLASES));
    }

    /**
     * Los filtros de la cola, leídos de una petición y saneados.
     *
     * Vive en el modelo y no en el controlador porque son varias pantallas las
     * que necesitan rehacer esta misma cola: la tarjeta de navegación que se
     * lleva un documento a Correo o al listado de XML recorre la cola filtrada
     * tal como quedó, y dos lecturas distintas de los mismos parámetros
     * acabarían recorriendo una lista que no es la que se está viendo.
     */
    public static function filtrosDesde(array $get, $sociedadId = 0)
    {
        $leer = static function ($clave) use ($get) {
            return isset($get[$clave]) && !is_array($get[$clave]) ? (string) $get[$clave] : '';
        };

        /*
         * El modo va en los filtros y no aparte porque decide todo lo demás:
         * qué pestañas hay, qué tipos de documento se ofrecen y qué significa
         * "qué falta". Leerlo en dos sitios distintos daría una pantalla
         * pintada en un modo y una consulta hecha en el otro.
         */
        $modo = self::modo($leer('modo'));
        $esCorreo = $modo === self::MODO_CORREO;

        // Las pestañas son los estados; 'todo' es la única que no lo es.
        $vistas = array_merge(array_keys(self::estadosDe($modo)), ['todo']);
        $vista = $leer('vista');
        if (!isset($get['vista'])) {
            $vista = 'pendiente';
        }

        /*
         * La clase es una pregunta sobre notas: una factura no tiene ninguna.
         * Si se está mirando solo facturas, la clase no se arrastra —el
         * desplegable tampoco se dibuja—, porque una clase colada junto a
         * 'facturas' vacía la lista entera sin nada en pantalla que lo
         * explique: en la unión, las facturas traen la clase en NULL.
         *
         * Se guarda como texto separado por comas: 'directa,costo'.
         */
        $origen = $leer('origen');
        if (!isset(self::origenesDe($modo)[$origen])) {
            $origen = '';
        }
        // La clase y la situación de la nota salen del reporte del ERP, no del
        // XML: en el modo Correo no hay ninguna de las dos que preguntar.
        $soloFacturas = $origen === 'factura' || $origen === 'xml_factura';
        $clases = ($esCorreo || $soloFacturas) ? [] : self::clasesPedidas($leer('clase'));

        $marca = $leer('marca');
        $condicionSaldo = $leer('condicion_saldo');

        /*
         * Situación de la nota frente a su factura. 'con_nota' no es un
         * estado sino la pregunta cruzada: todo lo que tiene nota de crédito
         * en juego, venga del lado de la nota o del de la factura.
         *
         * Igual que con la clase, un estado de nota no se arrastra cuando se
         * están mirando solo facturas: en la unión las facturas traen la
         * situación en NULL, así que colarlo vaciaba la cola entera sin nada
         * en pantalla que lo explicara. 'con_nota' sí sobrevive, porque esa
         * pregunta también es sobre facturas.
         */
        $aplicacion = $esCorreo ? '' : $leer('aplicacion');
        if ($aplicacion !== 'con_nota' && !isset(AplicacionNotaCredito::ESTADOS[$aplicacion])) {
            $aplicacion = '';
        }
        if ($origen === 'factura' && $aplicacion !== '' && $aplicacion !== 'con_nota') {
            $aplicacion = '';
        }

        // Cómo llegó el comprobante. Solo el modo Correo puede contestarlo:
        // un registro del ERP no llegó por ningún lado, se digitó.
        $llegada = $esCorreo ? $leer('llegada') : '';
        if (!isset(self::LLEGADAS[$llegada])) {
            $llegada = '';
        }

        $tarea = $leer('tarea');
        if (!isset(self::tareasDe($modo)[$tarea])) {
            $tarea = '';
        }

        /*
         * El rango de fechas, enderezado si viene al revés.
         *
         * Con dos selectores de fecha en la barra es fácil poner el 31 en
         * "desde" y el 1 en "hasta", y tal cual eso da una lista vacía sin
         * nada en pantalla que lo explique: parece que no hay documentos de
         * ese mes. Se entiende como lo que se quiso decir, que es lo mismo
         * que hace el listado de Facturas XML.
         */
        $desde = self::fechaFiltro($leer('desde'));
        $hasta = self::fechaFiltro($leer('hasta'));
        if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }


        return [
            'modo'        => $modo,
            'llegada'     => $llegada,
            'aplicacion'  => $aplicacion,
            'vista'       => in_array($vista, $vistas, true) ? $vista : 'pendiente',
            'origen'      => $origen,
            'tarea'       => $tarea,
            'marca'       => in_array($marca, ['mano', 'auto', 'desajuste'], true) ? $marca : '',
            'clase'       => implode(',', $clases),
            'responsable' => $leer('responsable'),
            'proveedor'   => ProveedorCatalogo::normalizarClave($leer('proveedor')),
            'sucursal'    => trim($leer('sucursal')),
            'contexto_id' => (int) $leer('contexto_id'),
            'desde'       => $desde,
            'hasta'       => $hasta,
            'monto_min'   => trim($leer('monto_min')),
            'condicion_saldo' => in_array($condicionSaldo, ['activas', 'canceladas'], true) ? $condicionSaldo : '',
            'q'           => trim($leer('q')),
            'col_documento' => trim($leer('col_documento')),
            'col_proveedor' => trim($leer('col_proveedor')),
            // Los dos importes con los que se busca un documento. Se limpian
            // como en los demás listados: se copian y pegan de la pantalla,
            // donde salen con símbolo y comas de millar.
            'col_monto'     => BusquedaImporte::numero($leer('col_monto')),
            'col_saldo'     => BusquedaImporte::numero($leer('col_saldo')),
            'col_respaldo'  => trim($leer('col_respaldo')),
            'col_tarea'     => trim($leer('col_tarea')),
            'orden'       => isset(self::ORDENES[$leer('orden')])
                                ? $leer('orden')
                                : self::ORDEN_POR_OMISION,
            'sociedad_id' => (int) $sociedadId,
        ];
    }

    /**
     * Una fecha que existe en el calendario, o ''.
     *
     * El formato solo no alcanza: '2026-02-30' lo cumple, llega a la consulta
     * y deja la cola vacía sin nada en pantalla que explique por qué. Una
     * fecha que no existe se ignora, igual que una mal escrita.
     */
    private static function fechaFiltro($valor)
    {
        $valor = trim((string) $valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
            return '';
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valor : '';
    }

    /**
     * Lo que se escribió en un buscador, listo para ir dentro de un LIKE.
     *
     * `%` y `_` son comodines de SQL, no texto: buscar "50%_A" traía "50 de
     * cualquier cosa seguida de A". Se escapan para que se busque lo que la
     * persona escribió, letra por letra. La barra invertida primero, que es
     * con la que se escapan las otras dos.
     */
    private static function like($valor)
    {
        return '%' . addcslashes(trim((string) $valor), '\\%_') . '%';
    }

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS seguimiento (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                -- Los dos primeros son del modo Sistema (registros del ERP) y los
                -- dos últimos del modo Correo (comprobantes XML). Conviven en la
                -- misma tabla porque la gestión es la misma; lo que no pueden es
                -- confundirse, y el origen es lo que los mantiene aparte.
                origen ENUM('nota_credito','factura','xml_factura','xml_nota') NOT NULL,
                referencia_id INT UNSIGNED NOT NULL,
                -- NULL = sin marca a mano: manda el cálculo. Es lo que permite
                -- anotar un renglón sin por eso congelarle el estado.
                estado ENUM('pendiente','revision','lista','cerrada') NULL DEFAULT NULL,
                responsable VARCHAR(120) NULL,
                -- El recordatorio: cuándo vuelve a molestar (fecha Y hora),
                -- cada cuántos días insiste, y cuándo se avisó por última vez.
                recordar_en DATETIME NULL,
                recordar_cada SMALLINT UNSIGNED NULL,
                avisado_en DATETIME NULL,
                motivo VARCHAR(255) NULL,
                actualizado_por VARCHAR(120) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_seguimiento_referencia (origen, referencia_id),
                KEY idx_seguimiento_recordar (estado, recordar_en),
                KEY idx_seguimiento_responsable (responsable, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->execute("CREATE TABLE IF NOT EXISTS seguimiento_bitacora (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seguimiento_id BIGINT UNSIGNED NOT NULL,
                usuario_id INT UNSIGNED NULL,
                usuario_nombre VARCHAR(120) NULL,
                estado_anterior VARCHAR(30) NULL,
                estado_nuevo VARCHAR(30) NULL,
                comentario TEXT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bitacora_seguimiento (seguimiento_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $this->renombrarOrigenPago();
            $this->migrarEstadosDeGestion();
            $this->agregarRecordatorio();
            $this->admitirOrigenesDelCorreo();
        } catch (Throwable $e) {
            // database/migrations/004_seguimiento.sql cubre instalaciones sin DDL en runtime.
        }
    }

    /**
     * De posponer a recordar.
     *
     * `vence_el` era una fecha suelta: el renglón salía de la cola y volvía
     * ese día, a ninguna hora en particular y una sola vez. Un recordatorio
     * de verdad necesita saber la hora —a las ocho de la mañana no es lo
     * mismo que a las seis de la tarde— y cada cuánto insistir, porque un
     * proveedor que no contesta no contesta una vez sola.
     */
    private function agregarRecordatorio()
    {
        $existe = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento'
                AND COLUMN_NAME = 'recordar_en'"
        );
        if ($existe > 0) {
            return;
        }

        $this->execute('ALTER TABLE seguimiento
            ADD COLUMN recordar_en DATETIME NULL AFTER responsable,
            ADD COLUMN recordar_cada SMALLINT UNSIGNED NULL AFTER recordar_en,
            ADD COLUMN avisado_en DATETIME NULL AFTER recordar_cada');

        // Lo pospuesto se conserva como un recordatorio de una sola vez a las
        // ocho: es la hora a la que alguien habría mirado la lista de todos
        // modos, y nadie eligió otra porque no se podía.
        $this->execute("UPDATE seguimiento SET recordar_en = TIMESTAMP(vence_el, '08:00:00')
                         WHERE vence_el IS NOT NULL");

        // El índice viejo apuntaba a la columna que se va.
        try {
            $this->execute('ALTER TABLE seguimiento DROP INDEX idx_seguimiento_estado');
        } catch (Throwable $e) {
        }
        $this->execute('ALTER TABLE seguimiento DROP COLUMN vence_el');
        $this->execute('ALTER TABLE seguimiento ADD KEY idx_seguimiento_recordar (estado, recordar_en)');
    }

    /**
     * De los seis estados de gestión a las cuatro pestañas.
     *
     * Antes el estado decía en qué iba el trámite (en gestión, esperando al
     * proveedor…) y la pestaña se deducía de él. Ahora el estado ES la pestaña,
     * y solo existe cuando alguien la puso a mano. La equivalencia:
     *
     *   en_gestion, esperando      -> revision  (alguien lo tenía entre manos)
     *   resuelto                   -> lista
     *   no_disponible, descartado  -> cerrada   (no va a existir / no aplica)
     *   pendiente                  -> sin marca (que lo diga el cálculo)
     *
     * 'pendiente' se borra en vez de conservarse porque antes era el valor por
     * omisión de cualquier fila —bastaba anotar un comentario para tenerlo—, y
     * conservarlo congelaría en Pendientes documentos que nadie clasificó.
     */
    private function migrarEstadosDeGestion()
    {
        $tipo = (string) $this->fetchColumn(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento' AND COLUMN_NAME = 'estado'"
        );
        if (strpos($tipo, "'en_gestion'") === false) {
            return;
        }

        $this->execute("ALTER TABLE seguimiento MODIFY estado
            ENUM('pendiente','en_gestion','esperando','resuelto','no_disponible','descartado',
                 'revision','lista','cerrada') NULL DEFAULT NULL");
        $this->execute("UPDATE seguimiento SET estado = CASE
                            WHEN estado IN ('en_gestion','esperando')     THEN 'revision'
                            WHEN estado = 'resuelto'                      THEN 'lista'
                            WHEN estado IN ('no_disponible','descartado') THEN 'cerrada'
                            ELSE NULL END");
        $this->execute("ALTER TABLE seguimiento
                        MODIFY estado ENUM('pendiente','revision','lista','cerrada') NULL DEFAULT NULL");
    }

    /**
     * El origen se llamaba 'pago_semanal' cuando el pago tenía líneas propias.
     * Hoy el renglón ES la factura del ERP y el pago solo la marca, así que el
     * valor pasó a llamarse 'factura'. El `referencia_id` no cambia —ya era el
     * id de facturas_erp—, así que la gestión ya registrada sigue apuntando a
     * donde apuntaba.
     */
    private function renombrarOrigenPago()
    {
        $tipo = (string) $this->fetchColumn(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento' AND COLUMN_NAME = 'origen'"
        );
        if (strpos($tipo, "'pago_semanal'") === false) {
            return;
        }

        // En tres pasos: el ENUM tiene que admitir los dos nombres antes de
        // poder reescribir las filas, y solo después se retira el viejo.
        $this->execute("ALTER TABLE seguimiento
                        MODIFY origen ENUM('nota_credito','pago_semanal','factura') NOT NULL");
        $this->execute("UPDATE seguimiento SET origen = 'factura' WHERE origen = 'pago_semanal'");
        $this->execute("ALTER TABLE seguimiento MODIFY origen ENUM('nota_credito','factura') NOT NULL");
    }

    /**
     * Deja entrar a la tabla los renglones del modo Correo.
     *
     * La gestión de un comprobante XML se guarda en la misma tabla que la de
     * un registro del ERP —es la misma marca, el mismo responsable, la misma
     * bitácora— y solo el `origen` las distingue. Como es un ENUM, admitir el
     * modo nuevo es ampliarlo; no hay que mover ninguna fila, porque las que
     * ya están siguen apuntando a donde apuntaban.
     */
    private function admitirOrigenesDelCorreo()
    {
        $tipo = (string) $this->fetchColumn(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seguimiento'
                AND COLUMN_NAME = 'origen'"
        );
        if ($tipo === '' || strpos($tipo, "'xml_factura'") !== false) {
            return;
        }

        $this->execute("ALTER TABLE seguimiento MODIFY origen
            ENUM('nota_credito','factura','xml_factura','xml_nota') NOT NULL");
    }

    // ── La consulta que arma la cola ────────────────────────────────────────

    /**
     * Las dos fuentes, con las mismas columnas, para poder unirlas.
     *
     * `tarea` se calcula aquí y no en PHP porque es lo que se filtra, se
     * ordena y se cuenta: hacerlo fuera obligaría a traerse las 5 000 filas
     * para mostrar 50.
     *
     * `en_juego` es el dinero que hay detrás del renglón: la diferencia si la
     * hay, el monto del documento si no. Era el orden por omisión de la cola;
     * hoy no ordena nada —se trabaja por fecha— y lo único que sigue usándolo
     * es el filtro "Monto desde", que ya no tiene control en la barra pero se
     * entiende si llega por la URL.
     *
     * El reporte de notas trae monto y saldo por separado. La factura del ERP
     * también, con una vuelta: cuando entra a un pago se le congela el saldo
     * de ese momento (`saldo_pago`), y ese es el que manda mientras el pago
     * exista —si no, pagar la factura la borraría de la cola aunque su
     * respaldo siga faltando—.
     */
    private function sqlUnion()
    {
        return $this->esCorreo() ? $this->sqlUnionCorreo() : $this->sqlUnionSistema();
    }

    /**
     * La cola del modo Correo: los comprobantes XML, mirados al revés.
     *
     * Las mismas columnas que la unión del sistema —para que el filtrado, el
     * conteo, el orden y la vista sirvan sin bifurcarse— rellenando con NULL
     * lo que un comprobante no tiene: no hay sucursal, ni clase, ni saldo
     * propio, ni nota de crédito en juego. Y dos que solo existen acá:
     *
     *   sistema_doc  el documento del ERP al que está enganchado, si lo está.
     *                Es lo único que el renglón viene a contestar, así que se
     *                enseña en la fila y no solo se filtra.
     *   llegada      si entró por el correo o lo subió alguien a mano.
     *
     * `saldo` es el del registro del ERP al que está enganchado, no el del
     * comprobante: un XML no tiene saldo: dice cuánto se facturó, no cuánto
     * queda por pagar. Sin enganche viene NULL, que la columna Saldo enseña
     * como un guion —no es cero, es que todavía no hay contra qué mirarlo—.
     *
     * El enganche se resuelve una sola vez, contra una tabla derivada que se
     * queda con el PRIMER registro de cada comprobante. Un JOIN directo no
     * servía: nada impide que dos registros del ERP apunten al mismo
     * comprobante —pasa cuando alguien carga dos veces el reporte y el
     * emparejador acierta las dos— y ahí el renglón saldría duplicado en la
     * cola. Agrupando primero, cada comprobante entra una vez y de ese
     * enganche salen las tres cosas que hacen falta —si existe, con qué
     * documento y con cuánto saldo— en vez de tres subconsultas correlacionadas.
     */
    private function sqlUnionCorreo()
    {
        $recuperable = EstadoArchivo::columnaRecuperable('x.');

        // La única diferencia entre las dos mitades: de qué tabla del sistema
        // cuelga el enganche. Una factura se registra en facturas_erp; una
        // nota, como línea del reporte de notas de crédito.
        $rama = static function ($origen, $tabla, $saldoSis, $tipoWhere) use ($recuperable) {
            return "SELECT
                '{$origen}' AS origen,
                x.id AS referencia_id,
                x.numero_factura_asistente AS documento,
                NULL AS nc_proveedor,
                NULL AS clase,
                COALESCE(p.razon_social, '') AS proveedor,
                '' AS proveedor_codigo,
                p.rfc AS proveedor_cedula,
                x.proveedor_id AS proveedor_id,
                '' AS sucursal,
                x.fecha_emision AS fecha,
                x.moneda AS moneda,
                x.total AS monto,
                {$saldoSis} AS saldo,
                NULL AS diferencia,
                NULL AS motivo_match,
                x.id AS factura_xml_id,
                x.importacion_id AS contexto_id,
                i.archivo_origen AS contexto,
                x.sociedad_id AS sociedad_id,
                x.ruta_xml AS ruta_xml,
                x.ruta_pdf AS ruta_pdf,
                x.estado_pdf AS estado_pdf,
                {$recuperable} AS recuperable,
                x.consecutivo_completo AS consecutivo,
                CASE WHEN x.correo_cuenta_id IS NULL THEN 'carga' ELSE 'correo' END AS llegada,
                sis.documento AS sistema_doc,
                CASE WHEN sis.id IS NULL THEN 'sin_sistema' ELSE 'completo' END AS tarea,
                x.total AS en_juego,
                NULL AS aplicacion_estado,
                NULL AS aplicacion_factura_id,
                NULL AS aplicacion_factura_doc,
                NULL AS aplicacion_factura_saldo,
                0 AS notas_vivas,
                0 AS notas_vivas_saldo
              FROM facturas_xml x
              LEFT JOIN proveedores p ON p.id = x.proveedor_id
              LEFT JOIN importaciones i ON i.id = x.importacion_id
              LEFT JOIN (SELECT factura_xml_id, MIN(id) AS id
                           FROM {$tabla} WHERE factura_xml_id IS NOT NULL
                          GROUP BY factura_xml_id) eng ON eng.factura_xml_id = x.id
              LEFT JOIN {$tabla} sis ON sis.id = eng.id
             WHERE {$tipoWhere}";
        };

        // tipo_documento en NULL es de antes de que la columna existiera, y
        // entonces solo se cargaban facturas: se cuenta como FE, igual que en
        // el listado de Facturas XML.
        // Una factura del ERP que entró a un pago lleva su saldo congelado en
        // `saldo_pago`, y ese es el que manda mientras el pago exista: es el
        // mismo criterio con el que lo lee el modo Sistema. Las líneas del
        // reporte de notas no tienen esa vuelta.
        $fe = $rama('xml_factura', 'facturas_erp', 'COALESCE(sis.saldo_pago, sis.saldo)',
            "(x.tipo_documento IS NULL OR x.tipo_documento = 'FE')");
        $nc = $rama('xml_nota', 'notas_credito_lineas', 'sis.saldo',
            "x.tipo_documento = 'NC'");

        return "({$fe}) UNION ALL ({$nc})";
    }

    private function sqlUnionSistema()
    {
        $tarea = "CASE
                    WHEN %s = 'sin_respaldo' THEN 'falta_xml'
                    WHEN %s = 'con_diferencia' THEN 'diferencia'
                    WHEN x.ruta_pdf IS NULL OR x.ruta_pdf = '' THEN
                        CASE WHEN x.estado_pdf = 'no_disponible_historico' THEN 'completo' ELSE 'falta_pdf' END
                    ELSE 'completo'
                  END";

        $nc = "SELECT
                'nota_credito' AS origen,
                l.id AS referencia_id,
                l.documento AS documento,
                l.nc_proveedor AS nc_proveedor,
                l.clase AS clase,
                l.proveedor_nombre AS proveedor,
                l.proveedor_codigo AS proveedor_codigo,
                x.proveedor_id AS proveedor_id,
                l.sucursal AS sucursal,
                l.fecha AS fecha,
                l.moneda AS moneda,
                l.monto AS monto,
                l.saldo AS saldo,
                l.diferencia AS diferencia,
                l.motivo_match AS motivo_match,
                l.factura_xml_id AS factura_xml_id,
                li.id AS contexto_id,
                li.nombre AS contexto,
                li.sociedad_id AS sociedad_id,
                x.ruta_xml AS ruta_xml,
                x.ruta_pdf AS ruta_pdf,
                x.estado_pdf AS estado_pdf,
                " . EstadoArchivo::columnaRecuperable('x.') . " AS recuperable,
                x.consecutivo_completo AS consecutivo,
                " . sprintf($tarea, 'l.estado', 'l.estado') . " AS tarea,
                COALESCE(ABS(l.diferencia), l.monto) AS en_juego,
                /*
                 * Contra qué factura se descuenta esta nota. Es otra pregunta
                 * que la del respaldo: el respaldo es si el documento existe,
                 * esto es si la plata tiene dónde aplicarse. Las dos hacen
                 * falta en la misma cola, porque quien la trabaja decide con
                 * las dos a la vez.
                 */
                l.estado_aplicacion AS aplicacion_estado,
                l.factura_erp_id AS aplicacion_factura_id,
                fe.documento AS aplicacion_factura_doc,
                fe.saldo AS aplicacion_factura_saldo,
                0 AS notas_vivas,
                0 AS notas_vivas_saldo
              FROM notas_credito_lineas l
              JOIN notas_credito_listados li ON li.id = l.listado_id
              LEFT JOIN facturas_xml x ON x.id = l.factura_xml_id
              LEFT JOIN facturas_erp fe ON fe.id = l.factura_erp_id
             WHERE l.clase IN ('" . implode("', '", array_keys(self::CLASES)) . "')
               AND li.id = (SELECT MAX(actual.id)
                              FROM notas_credito_listados actual
                             WHERE actual.sociedad_id = li.sociedad_id)";

        // El documento es la factura del ERP, no el pago.
        //
        // El pago dejó de tener líneas propias: solo marca cuáles de estas
        // facturas se pagan este pago. Entrar por él dejaba fuera de la cola
        // toda factura sin respaldo que nadie hubiera metido todavía en un
        // pago —y las sacaba otra vez en cuanto el pago se archivaba—, cuando
        // el XML se le debe igual. Ahora entran todas y el pago, si lo hay,
        // viaja como contexto.
        //
        // Tampoco se filtra por saldo: las que ya no lo tienen son justamente
        // las que llenan la pestaña Cerradas.
        $fe = "SELECT
                'factura' AS origen,
                pe.id AS referencia_id,
                pe.documento AS documento,
                NULL AS nc_proveedor,
                NULL AS clase,
                pe.proveedor_nombre AS proveedor,
                pe.proveedor_codigo AS proveedor_codigo,
                x.proveedor_id AS proveedor_id,
                pe.sucursal AS sucursal,
                pe.fecha_emision AS fecha,
                'CRC' AS moneda,
                pe.monto AS monto,
                COALESCE(pe.saldo_pago, pe.saldo) AS saldo,
                pe.diferencia AS diferencia,
                NULL AS motivo_match,
                pe.factura_xml_id AS factura_xml_id,
                li.id AS contexto_id,
                li.nombre AS contexto,
                pe.sociedad_id AS sociedad_id,
                x.ruta_xml AS ruta_xml,
                x.ruta_pdf AS ruta_pdf,
                x.estado_pdf AS estado_pdf,
                " . EstadoArchivo::columnaRecuperable('x.') . " AS recuperable,
                x.consecutivo_completo AS consecutivo,
                " . sprintf($tarea, 'pe.estado_respaldo', 'pe.estado_respaldo') . " AS tarea,
                COALESCE(ABS(pe.diferencia), COALESCE(pe.saldo_pago, pe.saldo)) AS en_juego,
                /*
                 * Del lado de la factura la pregunta se da vuelta: no contra
                 * qué se aplica, sino cuánta nota tiene esperando.
                 *
                 * Sale de un agregado que se arma una vez —una fila por
                 * factura con notas aplicables— y no de un JOIN directo, que
                 * duplicaría el renglón de una factura con varias notas.
                 * Antes eran dos subconsultas correlacionadas que corrían por
                 * cada una de las ~8 500 facturas: medido contra la copia de
                 * la base, la unión pasó de 200 a 143 ms con la misma salida
                 * fila por fila.
                 */
                NULL AS aplicacion_estado,
                NULL AS aplicacion_factura_id,
                NULL AS aplicacion_factura_doc,
                NULL AS aplicacion_factura_saldo,
                COALESCE(nv.n, 0) AS notas_vivas,
                COALESCE(nv.saldo, 0) AS notas_vivas_saldo
              FROM facturas_erp pe
              LEFT JOIN pagos li ON li.id = pe.pago_id
              LEFT JOIN facturas_xml x ON x.id = pe.factura_xml_id
              LEFT JOIN (SELECT factura_erp_id, COUNT(*) AS n, SUM(saldo) AS saldo
                           FROM notas_credito_lineas
                          WHERE estado_aplicacion = 'aplicable'
                            AND factura_erp_id IS NOT NULL
                          GROUP BY factura_erp_id) nv ON nv.factura_erp_id = pe.id";

        return "({$nc}) UNION ALL ({$fe})";
    }

    /**
     * El estado que le toca a un renglón por sus propios datos.
     *
     * El saldo manda sobre el respaldo: sin saldo la factura ya se pagó y no
     * hay nada que perseguir, tenga o no su XML. 'revision' no aparece aquí
     * porque no se calcula: se pone a mano.
     */
    private function sqlCalculado()
    {
        /*
         * En el modo Correo la pregunta es una sola, así que el cálculo
         * también: el comprobante está en el sistema o no está. Enganchado no
         * hay nada que perseguir y se cierra solo; sin enganchar se queda en
         * "Sin vincular" hasta que aparezca su registro o hasta que alguien
         * lo mande a seguimiento a mano, que es la tercera pestaña y la única
         * a la que no se llega sola.
         */
        if ($this->esCorreo()) {
            return "CASE WHEN c.tarea = 'completo' THEN 'cerrada' ELSE 'pendiente' END";
        }

        return "CASE
                  WHEN ABS(c.saldo) <= 0.005 THEN 'cerrada'
                  WHEN c.tarea <> 'completo'  THEN 'pendiente'
                  ELSE 'lista'
                END";
    }

    /** El estado real: la marca a mano si la hay, el cálculo si no. */
    private function sqlEfectivo()
    {
        return 'COALESCE(s.estado, ' . $this->sqlCalculado() . ')';
    }

    /**
     * La cola con el seguimiento pegado. Se resuelve en una sola consulta
     * porque desde fuera de la oficina cada viaje a la base cuesta ~100 ms.
     */
    private function sqlBase($conTotal = false)
    {
        // El total de la paginación viaja en cada fila de la página: contar
        // aparte era recorrer la unión entera otra vez, y la unión es lo caro.
        return "SELECT " . ($conTotal ? 'COUNT(*) OVER () AS total_filas, ' : '') . "c.*,
                       s.id AS seguimiento_id,
                       " . $this->sqlEfectivo() . " AS seguimiento_estado,
                       " . $this->sqlCalculado() . " AS estado_calculado,
                       s.estado AS estado_a_mano,
                       s.responsable,
                       s.recordar_en,
                       s.recordar_cada,
                       s.avisado_en,
                       s.motivo,
                       s.actualizado_por,
                       s.actualizado_en AS seguimiento_actualizado_en,
                       (SELECT COUNT(*) FROM seguimiento_bitacora b WHERE b.seguimiento_id = s.id) AS anotaciones
                  FROM (" . $this->sqlUnion() . ") c
                  LEFT JOIN seguimiento s
                         ON s.origen = c.origen AND s.referencia_id = c.referencia_id";
    }

    /**
     * Traduce los filtros de la pantalla a SQL.
     *
     * La pestaña ES el estado: se compara contra el efectivo, no contra la
     * marca ni contra el cálculo por separado, porque lo que la persona ve en
     * la pantalla es el efectivo.
     */
    private function condiciones(array $f)
    {
        $where = [];
        $params = [];

        if (!empty($f['sociedad_id'])) {
            $where[] = 'c.sociedad_id = ?';
            $params[] = (int) $f['sociedad_id'];
        }

        $vista = $f['vista'] ?? 'pendiente';
        if (isset(self::estadosDe($this->modo)[$vista])) {
            $where[] = $this->sqlEfectivo() . ' = ?';
            $params[] = $vista;
        }
        // 'todo' no agrega condición.

        // Puestas a mano o desajustadas: para revisar las decisiones tomadas
        // sobre la cola, no los documentos.
        $marca = (string) ($f['marca'] ?? '');
        if ($marca === 'mano') {
            $where[] = 's.estado IS NOT NULL';
        } elseif ($marca === 'auto') {
            $where[] = 's.estado IS NULL';
        } elseif ($marca === 'desajuste') {
            $where[] = 's.estado IS NOT NULL AND s.estado <> ' . $this->sqlCalculado();
        }

        if (!empty($f['origen']) && isset(self::origenesDe($this->modo)[$f['origen']])) {
            $where[] = 'c.origen = ?';
            $params[] = $f['origen'];
        }
        if (!empty($f['tarea']) && isset(self::tareasDe($this->modo)[$f['tarea']])) {
            $where[] = 'c.tarea = ?';
            $params[] = $f['tarea'];
        }
        // Cómo llegó el comprobante: solo existe en el modo Correo, donde el
        // renglón es un archivo que entró por algún lado.
        if ($this->esCorreo() && isset(self::LLEGADAS[$f['llegada'] ?? ''])) {
            $where[] = 'c.llegada = ?';
            $params[] = (string) $f['llegada'];
        }
        // Varias clases a la vez porque el trabajo se reparte por clase —una
        // directa se persigue distinto que una de costo— y mirarlas de a una
        // obliga a recorrer la cola tantas veces como clases haya.
        $clases = self::clasesPedidas($f['clase'] ?? '');
        if ($clases) {
            $where[] = 'c.clase IN (' . implode(',', array_fill(0, count($clases), '?')) . ')';
            foreach ($clases as $clase) {
                $params[] = $clase;
            }
        }
        if (!empty($f['responsable'])) {
            $where[] = 's.responsable = ?';
            $params[] = (string) $f['responsable'];
        }
        if (!empty($f['contexto_id'])) {
            $where[] = 'c.contexto_id = ?';
            $params[] = (int) $f['contexto_id'];
        }
        /*
         * Por el código del ERP y por el nombre escrito, que es lo único que
         * tienen las notas que llegan sin código.
         *
         * Por el emisor del comprobante NO, aunque la columna esté ahí: hay
         * facturas enlazadas a un XML de otro proveedor —un emparejamiento
         * cruzado, que es un error, no un dato— y reconocerlas por el emisor
         * metía el documento de un proveedor dentro de la lista de otro. Esos
         * cruces se avisan por su lado, en la campana.
         */
        /*
         * En el modo Correo el proveedor es el EMISOR del comprobante, y eso
         * el XML sí lo dice sin ambigüedad: trae su cédula. No se busca por
         * código del ERP —un comprobante no tiene ninguno— ni hace falta caer
         * al nombre escrito.
         */
        $proveedor = ProveedorCatalogo::condicion(
            $f['proveedor'] ?? '',
            $this->esCorreo()
                ? ['proveedor_id' => 'c.proveedor_id', 'cedula' => 'c.proveedor_cedula']
                : ['codigo' => 'c.proveedor_codigo', 'nombre' => 'c.proveedor'],
            $params
        );
        if ($proveedor !== '') {
            $where[] = $proveedor;
        }
        if (isset($f['sucursal']) && $f['sucursal'] !== '') {
            $where[] = 'c.sucursal = ?';
            $params[] = (string) $f['sucursal'];
        }
        if (isset($f['desde']) && $f['desde'] !== '') {
            $where[] = 'c.fecha >= ?';
            $params[] = (string) $f['desde'];
        }
        if (isset($f['hasta']) && $f['hasta'] !== '') {
            $where[] = 'c.fecha <= ?';
            $params[] = (string) $f['hasta'];
        }
        if (isset($f['monto_min']) && $f['monto_min'] !== '') {
            $where[] = 'c.en_juego >= ?';
            $params[] = (float) $f['monto_min'];
        }
        if (($f['condicion_saldo'] ?? '') === 'activas') {
            $where[] = 'ABS(c.saldo) > 0.005';
        } elseif (($f['condicion_saldo'] ?? '') === 'canceladas') {
            $where[] = 'ABS(c.saldo) <= 0.005';
        }
        if (!empty($f['q'])) {
            $like = self::like($f['q']);
            $where[] = '(c.documento LIKE ? OR c.proveedor LIKE ? OR c.consecutivo LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($f['col_documento'])) {
            $like = self::like($f['col_documento']);
            $where[] = '(c.documento LIKE ? OR c.contexto LIKE ? OR CAST(c.fecha AS CHAR) LIKE ? OR c.clase LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['col_proveedor'])) {
            $like = self::like($f['col_proveedor']);
            $where[] = '(c.proveedor LIKE ? OR c.sucursal LIKE ?)';
            array_push($params, $like, $like);
        }
        /*
         * Los dos importes. Se escribe el que se tiene a mano y salen los
         * documentos cuyo monto o saldo coincide; se alcanzan desde la barra y
         * desde la cabecera de la tabla, con el mismo parámetro, para que haya
         * un solo filtro por concepto.
         */
        foreach (['col_monto' => 'c.monto', 'col_saldo' => 'c.saldo'] as $clave => $columna) {
            // Pasa por BusquedaImporte aunque filtrosDesde() ya lo haga: la
            // navegación arma filtros por su cuenta, y un importe crudo con
            // bytes rotos o un comodín no puede llegar al LIKE.
            $valor = BusquedaImporte::numero($f[$clave] ?? '');
            if ($valor !== '') {
                $where[] = "CAST({$columna} AS CHAR) LIKE ?";
                $params[] = '%' . $valor . '%';
            }
        }

        $respaldo = (string) ($f['col_respaldo'] ?? '');
        if ($respaldo === 'completo') {
            $where[] = "c.factura_xml_id IS NOT NULL AND c.ruta_xml IS NOT NULL AND c.ruta_xml <> ''";
            $where[] = "((c.ruta_pdf IS NOT NULL AND c.ruta_pdf <> '') OR c.estado_pdf = 'no_disponible_historico')";
        } elseif ($respaldo === 'sin_xml') {
            $where[] = "(c.factura_xml_id IS NULL OR c.ruta_xml IS NULL OR c.ruta_xml = '')";
        } elseif ($respaldo === 'sin_pdf') {
            $where[] = "(c.ruta_pdf IS NULL OR c.ruta_pdf = '') AND COALESCE(c.estado_pdf, '') <> 'no_disponible_historico'";
        }
        if (!empty($f['col_tarea']) && isset(self::tareasDe($this->modo)[$f['col_tarea']])) {
            $where[] = 'c.tarea = ?';
            $params[] = $f['col_tarea'];
        }

        /*
         * Situación frente a la factura que corrige.
         *
         * 'con_nota' es la misma pregunta desde el otro lado y por eso vale
         * para los dos orígenes: una nota que se puede aplicar y una factura
         * que tiene notas esperando son las dos caras de lo mismo, y quien
         * trabaja la cola quiere verlas juntas.
         */
        $aplicacion = (string) ($f['aplicacion'] ?? '');
        if ($aplicacion === 'con_nota') {
            $where[] = "(c.aplicacion_estado = 'aplicable' OR c.notas_vivas > 0)";
        } elseif ($aplicacion !== '' && isset(AplicacionNotaCredito::ESTADOS[$aplicacion])) {
            $where[] = 'c.aplicacion_estado = ?';
            $params[] = $aplicacion;
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * El ORDER BY de la cola, con un desempate que la hace recorrible.
     *
     * `fecha` es un DATE, así que ordenando por "más reciente" —que es el
     * orden de omisión— empatan todas las filas del mismo día: 834 de los
     * 11.336 comprobantes comparten además el monto. Sin un criterio único al
     * final, MariaDB puede devolver esos empates en distinto orden en cada
     * consulta, y como las páginas son consultas distintas, un renglón salía
     * en dos páginas mientras otro no salía en ninguna.
     *
     * (origen, referencia_id) es único en la unión —la referencia es la clave
     * primaria de su tabla, y el origen dice de qué tabla—, así que cerrar con
     * los dos deja un orden total y las páginas dejan de moverse.
     */
    private function ordenSql($orden)
    {
        $mapa = [
            'reciente'   => 'c.fecha DESC, c.referencia_id DESC',
            'antiguedad' => 'c.fecha ASC, c.referencia_id ASC',
            'proveedor'  => 'c.proveedor ASC, c.fecha DESC, c.referencia_id DESC',
            'movimiento' => 's.actualizado_en DESC, c.fecha DESC, c.referencia_id DESC',
        ];
        $elegido = $mapa[$orden] ?? $mapa[self::ORDEN_POR_OMISION];
        return $elegido . ', c.origen ASC';
    }

    /** Lo más que trae una página de la pantalla. */
    public const POR_PAGINA_MAXIMO = 200;

    /**
     * Una página de la cola, con su total.
     *
     * @param int $maximo Hasta cuántas filas se admiten. La pantalla se queda
     *                    en POR_PAGINA_MAXIMO; quien necesite más de una vez
     *                    —la exportación, la tarjeta de navegación— lo pide
     *                    con su propio tope. Antes el 200 estaba fijo aquí y
     *                    el Excel salía con 200 renglones aunque pidiera 5 000.
     */
    public function cola(array $filtros, $pagina = 1, $porPagina = 50, $maximo = self::POR_PAGINA_MAXIMO)
    {
        [$where, $params] = $this->condiciones($filtros);
        $maximo = max(10, (int) $maximo);
        $porPagina = max(10, min($maximo, (int) $porPagina));
        // Una página escrita a mano en la URL (?pagina=99999999999999999999)
        // satura el entero, y multiplicarla por el tamaño desbordaba a un
        // float que MariaDB no acepta en el OFFSET. El tope es holgado —ninguna
        // cola llega a millones de páginas— y deja la cuenta en enteros; la
        // página real se endereza abajo, cuando ya se sabe cuántas hay.
        $pagina = min(max(1, (int) $pagina), intdiv(PHP_INT_MAX, $porPagina * 2));
        $orden = $this->ordenSql($filtros['orden'] ?? '');

        $filas = $this->paginaDeCola($where, $params, $orden, $porPagina, $pagina);
        if ($filas) {
            $total = (int) ($filas[0]['total_filas'] ?? count($filas));
        } elseif ($pagina === 1) {
            $total = 0;
        } else {
            // Una página vacía que no es la primera: se pidió una más allá de
            // la última. Solo aquí hace falta contar aparte, para mostrar la
            // última en vez de una lista vacía.
            $total = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM (" . $this->sqlUnion() . ") c
                  LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
                  {$where}",
                $params
            );
            $ultima = (int) max(1, ceil($total / $porPagina));
            if ($pagina > $ultima) {
                $pagina = $ultima;
                if ($total > 0) {
                    $filas = $this->paginaDeCola($where, $params, $orden, $porPagina, $pagina);
                }
            }
        }

        foreach ($filas as &$fila) {
            unset($fila['total_filas']);
        }
        unset($fila);

        return [
            'filas' => $filas,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => (int) max(1, ceil($total / $porPagina)),
        ];
    }

    private function paginaDeCola($where, array $params, $orden, $porPagina, $pagina)
    {
        // LIMIT y OFFSET van interpolados y no como parámetros: con consultas
        // preparadas emuladas llegarían entrecomillados y MariaDB los rechaza.
        // Son enteros ya acotados en cola(), no texto del usuario.
        $offset = ((int) $pagina - 1) * (int) $porPagina;
        return $this->fetchAll(
            $this->sqlBase(true) . " {$where} ORDER BY {$orden}"
            . ' LIMIT ' . (int) $porPagina . ' OFFSET ' . $offset,
            $params
        ) ?: [];
    }

    /**
     * Los números de las tarjetas y de las pestañas.
     *
     * Se calculan con los mismos filtros PERO sin la pestaña ni la tarea: si
     * no, estando en Pendientes la cuenta de En revisión daría cero y las
     * pestañas no podrían mostrar cuánto hay en cada una.
     */
    public function resumen(array $filtros)
    {
        $f = $filtros;
        unset($f['tarea'], $f['col_tarea']);
        // 'todo' explícito y no quitar la clave: sin ella, condiciones() cae en
        // su pestaña por omisión y las tarjetas contarían solo Pendientes.
        $f['vista'] = 'todo';
        [$where, $params] = $this->condiciones($f);

        // El alias no se puede reusar dentro del mismo SELECT, así que la
        // expresión del estado efectivo se repite en cada cuenta.
        $e = $this->sqlEfectivo();
        // La tarea que cuenta como "lo que falta" no se llama igual en los dos
        // modos: en Sistema es no tener el XML; en Correo, no estar en el ERP.
        $faltante = $this->esCorreo() ? 'sin_sistema' : 'falta_xml';
        $fila = $this->fetchOne(
            "SELECT
                COUNT(*) total,
                SUM({$e} = 'pendiente') pendiente,
                SUM({$e} = 'revision')  revision,
                SUM({$e} = 'lista')     lista,
                SUM({$e} = 'cerrada')   cerrada,
                SUM({$e} <> 'cerrada' AND c.tarea = '{$faltante}') falta_xml,
                SUM({$e} <> 'cerrada' AND c.tarea = 'falta_pdf') falta_pdf,
                SUM({$e} <> 'cerrada' AND c.tarea = 'diferencia') casos_diferencia,
                COALESCE(SUM(CASE WHEN {$e} <> 'cerrada' AND c.tarea = 'diferencia'
                                  THEN ABS(c.diferencia) ELSE 0 END), 0) monto_diferencia,
                SUM({$e} = 'revision' AND s.recordar_en IS NOT NULL AND s.recordar_en <= NOW()) vencidos,
                SUM(s.estado IS NOT NULL) a_mano,
                SUM(s.estado IS NOT NULL AND s.estado <> " . $this->sqlCalculado() . ") desajustadas
             FROM (" . $this->sqlUnion() . ") c
             LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
             {$where}",
            $params
        );

        $vacio = [
            'total' => 0, 'pendiente' => 0, 'revision' => 0, 'lista' => 0, 'cerrada' => 0,
            'falta_xml' => 0, 'falta_pdf' => 0, 'casos_diferencia' => 0, 'monto_diferencia' => 0,
            'vencidos' => 0, 'a_mano' => 0, 'desajustadas' => 0,
        ];
        if (!$fila) {
            return $vacio;
        }
        foreach ($vacio as $clave => $_) {
            $vacio[$clave] = $fila[$clave] === null ? 0 : $fila[$clave] + 0;
        }
        return $vacio;
    }

    /**
     * Un renglón concreto, para el panel de detalle.
     *
     * Acotado a la empresa igual que la cola: el expediente trae proveedor,
     * montos, el motivo escrito a mano y la bitácora, y sin este filtro
     * bastaba con cambiar el `referencia_id` de la URL para leer los de otra
     * empresa. Un renglón ajeno responde lo mismo que uno que no existe.
     *
     * @param int $sociedadId 0 = sin acotar, igual que en condiciones().
     */
    public function uno($origen, $referenciaId, $sociedadId = 0)
    {
        $sql = $this->sqlBase() . ' WHERE c.origen = ? AND c.referencia_id = ?';
        $params = [(string) $origen, (int) $referenciaId];
        if ((int) $sociedadId > 0) {
            $sql .= ' AND c.sociedad_id = ?';
            $params[] = (int) $sociedadId;
        }
        $fila = $this->fetchOne($sql . ' LIMIT 1', $params);
        return $fila ?: null;
    }

    /**
     * Los listados a los que pertenece algo de la cola, para poder acotar a
     * uno solo. Solo los que tienen trabajo vivo —pendiente o en revisión—:
     * ofrecer listados enteros ya cerrados en el desplegable es ruido. Las
     * facturas que no están en ningún pago no tienen listado y no aparecen acá.
     */
    public function contextosDisponibles($sociedadId)
    {
        $filas = $this->fetchAll(
            "SELECT c.origen, c.contexto_id, c.contexto, COUNT(*) pendientes
               FROM (" . $this->sqlUnion() . ") c
               LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
              WHERE c.sociedad_id = ?
                AND c.contexto_id IS NOT NULL
                AND " . $this->sqlEfectivo() . " IN ('pendiente', 'revision')
              GROUP BY c.origen, c.contexto_id, c.contexto
              ORDER BY c.origen, pendientes DESC",
            [(int) $sociedadId]
        );
        return $filas ?: [];
    }

    /**
     * Proveedores y sucursales de la cola, para los dos desplegables.
     *
     * Salen de la misma unión que la cola —así ofrecen lo que realmente hay
     * que perseguir, y su cuenta es la de esta pantalla y no la del listado
     * del que vino cada renglón— y en UNA sola pasada: recorrer la unión es
     * lo caro de esta consulta, y la pantalla ya la recorre para la lista,
     * el total y el resumen.
     */
    public function dimensionesParaFiltro($sociedadId)
    {
        $where = '';
        $params = [];
        if ((int) $sociedadId > 0) {
            $where = 'WHERE c.sociedad_id = ?';
            $params[] = (int) $sociedadId;
        }

        $filas = $this->fetchAll(
            "SELECT COALESCE(c.proveedor_codigo, '') AS codigo,
                    c.proveedor_id AS proveedor_id,
                    COALESCE(c.sucursal, '') AS sucursal,
                    MAX(c.proveedor) AS nombre,
                    COUNT(*) AS n
               FROM (" . $this->sqlUnion() . ") c
               {$where}
              GROUP BY COALESCE(c.proveedor_codigo, ''), c.proveedor_id, COALESCE(c.sucursal, '')",
            $params
        ) ?: [];

        $sucursales = [];
        foreach ($filas as $fila) {
            $sucursal = trim((string) $fila['sucursal']);
            if ($sucursal === '') {
                continue;
            }
            $sucursales[$sucursal] = ($sucursales[$sucursal] ?? 0) + (int) $fila['n'];
        }
        ksort($sucursales, SORT_NATURAL | SORT_FLAG_CASE);

        return ['proveedores' => $filas, 'sucursales' => $sucursales];
    }

    /**
     * Una firma de las tablas de las que salen los desplegables.
     *
     * dimensionesParaFiltro() recorre la unión entera —es tan cara como la
     * página— para una respuesta que cambia cuando se carga un reporte o
     * entran comprobantes, no entre una página y la siguiente. La firma es el
     * último momento en que la base escribió en cada tabla: si no cambió, lo
     * guardado sigue valiendo. Vacía si no se puede saber; entonces no se
     * guarda nada y se consulta siempre, que es lo seguro.
     */
    public function huellaDimensiones()
    {
        try {
            return (string) $this->fetchColumn(
                "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME, '=', COALESCE(UPDATE_TIME, '-')) ORDER BY TABLE_NAME)
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('facturas_erp', 'notas_credito_lineas', 'notas_credito_listados',
                                       'facturas_xml', 'proveedores')"
            );
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Quiénes tienen algo asignado, para el desplegable de responsable. */
    public function responsables()
    {
        $filas = $this->fetchAll(
            "SELECT DISTINCT responsable FROM seguimiento
              WHERE responsable IS NOT NULL AND responsable <> '' ORDER BY responsable"
        );
        return array_column($filas ?: [], 'responsable');
    }

    // ── Recordatorios ───────────────────────────────────────────────────────

    /** La firma con la que un renglón se identifica en la campana. */
    private static function firmaAviso($origen, $referenciaId)
    {
        return 'seguimiento:' . $origen . ':' . (int) $referenciaId;
    }

    /**
     * Deja en la campana los recordatorios que ya vencieron.
     *
     * Se llama desde el resumen de la campana, que se pide en cada carga de
     * página: es el único reloj que hay. La aplicación corre en la máquina de
     * cada quien y no hay tarea programada, así que un recordatorio no puede
     * sonar solo; aparece la primera vez que alguien abre la aplicación pasada
     * su hora. Por eso la consulta tiene que ser barata y no fallar nunca.
     *
     * La insistencia sale de `recordar_cada`: se vuelve a avisar cuando han
     * pasado esos días desde el último aviso. La firma es la misma siempre, de
     * modo que insistir suma `veces` en el aviso que ya existe en vez de llenar
     * la campana de copias del mismo pendiente.
     *
     * @return int Cuántos avisos se dejaron.
     */
    public function generarRecordatorios($limite = 50)
    {
        /*
         * Un solo barrido a la vez, en todo el grupo.
         *
         * La campana se pide en cada carga de página de cada persona, así que
         * dos barridos a la vez son lo normal: los dos leían el mismo lote de
         * vencidos antes de que ninguno marcara `avisado_en`, y el mismo
         * pendiente sonaba dos veces. El candado es de la base —vale entre
         * computadoras— y se pide sin esperar: si otro ya está barriendo, este
         * no tiene nada que hacer.
         */
        if ((int) $this->fetchColumn('SELECT GET_LOCK(?, 0)', [self::CANDADO_RECORDATORIOS]) !== 1) {
            return 0;
        }
        try {
            return $this->barrerRecordatorios($limite);
        } finally {
            try {
                $this->fetchColumn('SELECT RELEASE_LOCK(?)', [self::CANDADO_RECORDATORIOS]);
            } catch (Throwable $e) {
                // Si no se pudo soltar, lo suelta la base al cerrar la conexión.
            }
        }
    }

    private const CANDADO_RECORDATORIOS = 'xmlconcilia_seguimiento_recordatorios';

    /** Cada cuánto barre como mucho la campana de una misma computadora. */
    public const RECORDATORIOS_CADA_SEGUNDOS = 300;

    /**
     * El barrido de la campana, pero no en cada carga de página.
     *
     * Los recordatorios tienen hora y cada cuántos DÍAS insisten: mirarlos en
     * cada navegación —que es lo que hacía la campana— era una escritura
     * posible repartida por todas las pantallas para una pregunta que cambia
     * de respuesta cada varios minutos, no cada segundo. Con una marca en
     * storage/cache, cada computadora barre como mucho una vez cada cinco
     * minutos; cli/recordatorios.php hace lo mismo desde el Programador de
     * tareas, y el candado de generarRecordatorios() ordena a los dos.
     *
     * @return int Cuántos avisos se dejaron (0 si no tocaba).
     */
    public function generarRecordatoriosSiToca($marca = null, $cadaSegundos = self::RECORDATORIOS_CADA_SEGUNDOS)
    {
        $marca = $marca ?? dirname(__DIR__, 2) . '/storage/cache/seguimiento_recordatorios.marca';
        clearstatcache(true, $marca);
        if (is_file($marca) && time() - (int) filemtime($marca) < (int) $cadaSegundos) {
            return 0;
        }
        // La marca se pone antes de barrer: si el barrido falla, reintentarlo
        // en la carga siguiente no lo arregla, igual que con `avisado_en`. Sin
        // poder escribirla se barre igual: el candado sigue ordenando.
        try {
            if (!is_dir(dirname($marca))) {
                mkdir(dirname($marca), 0777, true);
            }
            touch($marca);
        } catch (Throwable $e) {
        }
        return $this->generarRecordatorios();
    }

    private function barrerRecordatorios($limite)
    {
        $vencidos = $this->fetchAll(
            "SELECT s.id, s.origen, s.referencia_id, s.motivo, s.responsable,
                    s.recordar_en, s.recordar_cada
               FROM seguimiento s
              WHERE s.estado = 'revision'
                AND s.recordar_en IS NOT NULL
                AND s.recordar_en <= NOW()
                AND (s.avisado_en IS NULL
                     OR (s.recordar_cada IS NOT NULL
                         AND DATE_ADD(s.avisado_en, INTERVAL s.recordar_cada DAY) <= NOW()))
              ORDER BY s.recordar_en ASC
              LIMIT " . max(1, (int) $limite)
        ) ?: [];
        if (!$vencidos) {
            return 0;
        }

        $documentos = $this->documentosDe($vencidos);
        $avisos = [];
        $ids = [];

        foreach ($vencidos as $v) {
            $clave = $v['origen'] . '|' . $v['referencia_id'];
            $doc = $documentos[$clave] ?? ['documento' => $clave, 'proveedor' => '', 'sociedad_id' => null];
            $detalle = trim((string) $v['motivo']);
            if (!empty($doc['proveedor'])) {
                $detalle = $doc['proveedor'] . ($detalle !== '' ? ' — ' . $detalle : '');
            }
            if (!empty($v['responsable'])) {
                $detalle .= ' (a cargo: ' . $v['responsable'] . ')';
            }

            $avisos[] = [
                'tipo' => 'seguimiento_recordatorio',
                'severidad' => 'media',
                'titulo' => 'Recordatorio de seguimiento: ' . $doc['documento'],
                'detalle' => $detalle,
                'firma' => self::firmaAviso($v['origen'], $v['referencia_id']),
                // El pendiente es de la empresa del documento: su campana, no
                // la de todas.
                'sociedad_id' => (int) ($doc['sociedad_id'] ?? 0),
                'ref_tabla' => 'seguimiento',
                'ref_clave' => $clave,
                'datos' => [
                    'origen' => $v['origen'],
                    'referencia_id' => (int) $v['referencia_id'],
                    'documento' => $doc['documento'],
                ],
            ];
            $ids[] = (int) $v['id'];
        }

        // Todos en una escritura: de a uno eran tantos viajes como vencidos.
        $dejados = (new Notificacion())->avisarVarios($avisos);

        // Se marca aunque `avisar` haya fallado: reintentarlo en la carga de
        // página siguiente no arregla nada y sí lo intentaría mil veces.
        $this->execute(
            'UPDATE seguimiento SET avisado_en = NOW() WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        return $dejados;
    }

    /**
     * Documento y proveedor de unos pocos renglones, sin pasar por la unión
     * entera: son los dos datos que necesita el texto del aviso.
     */
    private function documentosDe(array $filas)
    {
        /*
         * Los cuatro orígenes a la vez y no los del modo: el reloj de los
         * recordatorios es uno solo —la campana, que se pide en cada carga de
         * página— y no sabe desde qué pantalla se puso cada uno. Un aviso sin
         * nombre de documento diría "xml_factura|412", que no le sirve a nadie.
         *
         * Los dos del modo Correo salen de facturas_xml, que no guarda el
         * nombre del proveedor sino su id, así que esas dos ramas llevan JOIN.
         */
        $tablas = [
            'factura'      => ['facturas_erp', false],
            'nota_credito' => ['notas_credito_lineas', false],
            'xml_factura'  => ['facturas_xml', true],
            'xml_nota'     => ['facturas_xml', true],
        ];

        $porOrigen = array_fill_keys(array_keys($tablas), []);
        foreach ($filas as $f) {
            if (isset($porOrigen[$f['origen']])) {
                $porOrigen[$f['origen']][] = (int) $f['referencia_id'];
            }
        }

        $partes = [];
        $params = [];
        foreach ($tablas as $origen => [$tabla, $esXml]) {
            if (!$porOrigen[$origen]) {
                continue;
            }
            $huecos = implode(',', array_fill(0, count($porOrigen[$origen]), '?'));
            /*
             * Cada rama trae también de qué empresa es el documento: el aviso
             * que sale de aquí se sella con ella para que suene en la campana
             * de esa empresa y no en la de las demás. La línea de nota no la
             * lleva encima, la hereda de su acumulado.
             */
            if ($esXml) {
                $partes[] = "SELECT '{$origen}' AS origen, d.id AS referencia_id,
                          d.numero_factura_asistente AS documento,
                          COALESCE(pr.razon_social, '') AS proveedor,
                          d.sociedad_id AS sociedad_id
                     FROM {$tabla} d
                     LEFT JOIN proveedores pr ON pr.id = d.proveedor_id
                    WHERE d.id IN ({$huecos})";
            } elseif ($tabla === 'notas_credito_lineas') {
                $partes[] = "SELECT '{$origen}' AS origen, d.id AS referencia_id, d.documento,
                          d.proveedor_nombre AS proveedor, li.sociedad_id AS sociedad_id
                     FROM {$tabla} d
                     JOIN notas_credito_listados li ON li.id = d.listado_id
                    WHERE d.id IN ({$huecos})";
            } else {
                $partes[] = "SELECT '{$origen}' AS origen, id AS referencia_id, documento,
                          proveedor_nombre AS proveedor, sociedad_id
                     FROM {$tabla} WHERE id IN ({$huecos})";
            }
            $params = array_merge($params, $porOrigen[$origen]);
        }
        if (!$partes) {
            return [];
        }

        $mapa = [];
        foreach ($this->fetchAll(implode(' UNION ALL ', $partes), $params) ?: [] as $fila) {
            $mapa[$fila['origen'] . '|' . $fila['referencia_id']] = $fila;
        }
        return $mapa;
    }

    /** Apaga en la campana los avisos de unos renglones, en una escritura. */
    private function cerrarAvisos(array $items, array $usuario)
    {
        try {
            (new Notificacion())->cerrarPorFirmas(
                array_map(static fn($item) => self::firmaAviso($item['origen'], $item['referencia_id']), $items),
                'resuelta',
                (int) ($usuario['id'] ?? 0)
            );
        } catch (Throwable $e) {
            // La campana nunca tumba lo que la disparó.
        }
    }

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * Aplica un cambio a varios renglones a la vez y deja la bitácora.
     *
     * Se hace por tandas y no de a uno porque la persona va a seleccionar
     * cincuenta renglones y mandarlos a revisión: de a una consulta por
     * renglón, con la base fuera de la oficina, eso es medio minuto de espera.
     *
     * `estado` admite tres formas: uno de ESTADOS (marca a mano), SIN_MARCA
     * (devolver al cálculo) o no venir del todo (no tocar la marca, que es lo
     * que hace una anotación suelta).
     *
     * @param array $items  [['origen' => 'nota_credito', 'referencia_id' => 12], ...]
     */
    public function aplicar(array $items, array $cambio, array $usuario)
    {
        $items = self::normalizarItems($items);
        if (!$items) {
            return ['afectados' => 0];
        }
        // Un "marcar todo" sobre la cola entera eran INSERT de miles de filas
        // y una transacción larga que bloqueaba a todos los demás. Se rechaza
        // antes de tocar nada; la pantalla nunca manda más de una página.
        if (count($items) > self::TOPE_TANDA) {
            throw new InvalidArgumentException(sprintf(
                'Son %d renglones: como mucho se cambian %d de una vez. Acota la cola con los filtros y hazlo por partes.',
                count($items), self::TOPE_TANDA
            ));
        }

        $tocaEstado = array_key_exists('estado', $cambio);
        $estado = $tocaEstado ? (string) $cambio['estado'] : '';
        // Contra las pestañas del modo, no contra las cuatro: 'lista' no existe
        // en Correo, y admitirlo dejaría renglones en una pestaña que esa
        // pantalla no dibuja y de la que por lo tanto no se puede salir.
        if ($tocaEstado && $estado !== self::SIN_MARCA
            && !isset(self::estadosDe($this->modo)[$estado])) {
            throw new InvalidArgumentException('Estado de seguimiento no válido: ' . $estado);
        }
        // Y contra el modo de cada renglón: la selección viaja desde el
        // navegador, y un comprobante XML colado en una tanda del modo Sistema
        // se quedaba en 'lista', que su pantalla no dibuja.
        if ($tocaEstado && $estado !== self::SIN_MARCA) {
            foreach ($items as $item) {
                if (!isset(self::estadosDe(self::modoDeOrigen($item['origen']))[$estado])) {
                    throw new InvalidArgumentException('Estado de seguimiento no válido para ese documento: ' . $estado);
                }
            }
        }

        // Los textos libres se limpian de lo que no se ve —espacio duro, ancho
        // cero— además de los espacios de siempre: un motivo hecho solo de eso
        // pasaba por descrito sin decir nada.
        if (array_key_exists('motivo', $cambio)) {
            $cambio['motivo'] = self::textoLimpio($cambio['motivo']);
        }
        if (array_key_exists('comentario', $cambio)) {
            $cambio['comentario'] = self::textoLimpio($cambio['comentario']);
        }
        // La bandeja de enredos no sirve de nada si no dice cuál es el enredo:
        // sin el motivo, quien la abra mañana tiene que adivinar.
        if ($estado === self::ESTADO_A_MANO && ($cambio['motivo'] ?? '') === '') {
            throw new InvalidArgumentException('Para mandar algo a revisión hay que describir el problema.');
        }
        // Lo que no cabe se dice aquí, con palabras, y no como el error de la
        // base que llegaba al navegador con el nombre de la columna.
        if (mb_strlen((string) ($cambio['motivo'] ?? ''), 'UTF-8') > self::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException(sprintf(
                'El motivo tiene %d caracteres y caben %d. Resúmelo y deja el detalle en el comentario.',
                mb_strlen($cambio['motivo'], 'UTF-8'), self::MOTIVO_MAXIMO
            ));
        }
        if (strlen(self::nota($cambio)) > self::ANOTACION_MAXIMA_BYTES) {
            throw new InvalidArgumentException('La anotación es demasiado larga para guardarse. Acórtala o divídela en varias.');
        }

        // Un recordatorio vive solo en revisión. Salir de ella lo apaga, y
        // uno que llega junto a otro estado no se guarda: quedaría callado y
        // volvería a sonar el día que el renglón regresara a revisión, por un
        // enredo que ya no es el mismo.
        if ($tocaEstado && $estado !== self::ESTADO_A_MANO) {
            $cambio['recordar_en'] = null;
            $cambio['recordar_cada'] = null;
        }

        // Solo lo que está en la cola de la empresa activa. La clave única de
        // la tabla evita pisar otra gestión, pero no dice si el documento
        // existe ni de quién es: sin esto, un POST armado a mano anotaba
        // documentos de otra empresa.
        $descartados = 0;
        if ($this->empresaAcotada !== null) {
            $enCola = $this->enLaCola($items, $this->empresaAcotada);
            $descartados = count($items) - count($enCola);
            $items = $enCola;
            if (!$items) {
                throw new InvalidArgumentException('Ninguno de los renglones seleccionados está en la cola de la empresa activa.');
            }
        }

        // Siempre en el mismo orden: dos tandas que se solapan toman los
        // candados de las filas en la misma secuencia y no se cruzan.
        usort($items, static fn($a, $b) => [$a['origen'], $a['referencia_id']] <=> [$b['origen'], $b['referencia_id']]);

        for ($intento = 1; ; $intento++) {
            $this->begin();
            try {
                $antes = $this->estadosActuales($items);
                // Vaciar el motivo de algo que sigue en revisión lo dejaba
                // mudo sin rastro. Se mira aquí, con el estado ya bloqueado.
                if (!$tocaEstado && array_key_exists('motivo', $cambio) && $cambio['motivo'] === ''
                    && in_array(self::ESTADO_A_MANO, $antes, true)) {
                    throw new InvalidArgumentException(
                        'Lo que está en revisión tiene que conservar la descripción del problema. Para quitarla, sácalo de revisión.'
                    );
                }
                $this->upsert($items, $cambio, $usuario);
                $ids = $this->idsDe($items);
                $this->anotar($ids, $antes, $tocaEstado ? $estado : null, self::nota($cambio), $usuario);
                $this->commit();
                break;
            } catch (Throwable $e) {
                $this->rollback();
                if (!self::esReintentable($e, $intento)) {
                    throw $e;
                }
                // Un respiro corto y distinto para cada uno, para que los dos
                // que chocaron no vuelvan a chocar en el mismo instante.
                usleep(random_int(20000, 120000) * $intento);
            }
        }

        if ($tocaEstado && $estado !== self::ESTADO_A_MANO) {
            $this->cerrarAvisos($items, $usuario);
        }

        $resultado = ['afectados' => count($items)];
        if ($descartados > 0) {
            $resultado['descartados'] = $descartados;
        }
        return $resultado;
    }

    /** Lo más que se cambia de una vez. */
    public const TOPE_TANDA = 2000;

    /** `seguimiento.motivo` es VARCHAR(255). */
    public const MOTIVO_MAXIMO = 255;

    /** `seguimiento_bitacora.comentario` es TEXT: 65 535 bytes. */
    private const ANOTACION_MAXIMA_BYTES = 65535;

    /** null = sin acotar (pruebas y procesos); 0 = solo que exista; >0 = de esa empresa. */
    private $empresaAcotada = null;

    /**
     * Acota las escrituras a lo que está en la cola de una empresa. Devuelve
     * $this, igual que enModo(), para encadenarlo tras loadModel().
     *
     * @param int $sociedadId 0 = cualquier empresa, pero el documento tiene
     *                        que existir en la cola.
     */
    public function acotadoA($sociedadId)
    {
        $this->empresaAcotada = max(0, (int) $sociedadId);
        return $this;
    }

    /** El modo al que pertenece un origen. */
    private static function modoDeOrigen($origen)
    {
        return isset(self::ORIGENES[self::MODO_CORREO][$origen]) ? self::MODO_CORREO : self::MODO_SISTEMA;
    }

    /**
     * Un interbloqueo o una espera de candado agotada no son un error de la
     * persona: la base deshizo la transacción y pide repetirla. Se repite unas
     * pocas veces antes de rendirse; la espera agotada, una sola, porque cada
     * intento ya esperó lo suyo.
     */
    private static function esReintentable(Throwable $e, $intento)
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        if ($codigo === 1213 || ($codigo === 0 && (string) $e->getCode() === '40001')) {
            return $intento < 3;
        }
        return $codigo === 1205 && $intento < 2;
    }

    /**
     * Un texto libre sin lo que no se ve en sus bordes: espacios de cualquier
     * clase (el duro, el ideográfico…) y caracteres de formato como el de
     * ancho cero, que trim() no conoce.
     */
    private static function textoLimpio($texto)
    {
        $texto = (string) $texto;
        $limpio = preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $texto);
        // Con bytes que no son UTF-8 la expresión no aplica: se cae a trim().
        return $limpio === null ? trim($texto) : $limpio;
    }

    /**
     * De unos renglones, los que existen en la cola de la empresa.
     *
     * Contra las tablas de las que sale cada rama de la unión y con sus mismas
     * condiciones —las notas, del último acumulado y sin las de ajuste; los
     * comprobantes, por su tipo—, pero sin armar la unión entera: son unos
     * pocos ids que se miran por clave primaria.
     */
    private function enLaCola(array $items, $sociedadId)
    {
        $porOrigen = [];
        foreach ($items as $item) {
            $porOrigen[$item['origen']][] = (int) $item['referencia_id'];
        }

        $clases = "'" . implode("', '", array_keys(self::CLASES)) . "'";
        $ramas = [
            'factura' => ['facturas_erp d', 'd.sociedad_id', ''],
            'nota_credito' => [
                'notas_credito_lineas d JOIN notas_credito_listados li ON li.id = d.listado_id',
                'li.sociedad_id',
                " AND d.clase IN ({$clases})
                  AND li.id = (SELECT MAX(actual.id) FROM notas_credito_listados actual
                                WHERE actual.sociedad_id = li.sociedad_id)",
            ],
            'xml_factura' => ['facturas_xml d', 'd.sociedad_id', " AND (d.tipo_documento IS NULL OR d.tipo_documento = 'FE')"],
            'xml_nota' => ['facturas_xml d', 'd.sociedad_id', " AND d.tipo_documento = 'NC'"],
        ];

        $partes = [];
        $params = [];
        foreach ($ramas as $origen => [$desde, $columnaEmpresa, $extra]) {
            if (empty($porOrigen[$origen])) {
                continue;
            }
            $sql = "SELECT '{$origen}' AS origen, d.id AS referencia_id, 1 AS en_cola
                      FROM {$desde}
                     WHERE d.id IN (" . implode(', ', array_fill(0, count($porOrigen[$origen]), '?')) . "){$extra}";
            $params = array_merge($params, $porOrigen[$origen]);
            if ((int) $sociedadId > 0) {
                $sql .= " AND {$columnaEmpresa} = ?";
                $params[] = (int) $sociedadId;
            }
            $partes[] = $sql;
        }

        $existen = [];
        foreach ($this->fetchAll(implode(' UNION ALL ', $partes), $params) ?: [] as $fila) {
            $existen[$fila['origen'] . '|' . (int) $fila['referencia_id']] = true;
        }
        return array_values(array_filter($items, static fn($item) =>
            isset($existen[$item['origen'] . '|' . $item['referencia_id']])));
    }

    /**
     * Lo que queda escrito en la bitácora de un cambio.
     *
     * El motivo se copia además de guardarse en la fila porque la fila solo
     * conserva el último: sin esta copia, corregir la descripción de un enredo
     * borraría para siempre la anterior y con ella la mitad de la historia.
     */
    private static function nota(array $cambio)
    {
        $comentario = trim((string) ($cambio['comentario'] ?? ''));
        $motivo = trim((string) ($cambio['motivo'] ?? ''));
        if ($motivo === '') {
            return $comentario;
        }
        return 'Problema: ' . $motivo . ($comentario !== '' ? "
" . $comentario : '');
    }

    /** Solo un comentario, sin tocar el estado. */
    public function comentar($origen, $referenciaId, $comentario, array $usuario)
    {
        $comentario = trim((string) $comentario);
        if ($comentario === '') {
            throw new InvalidArgumentException('El comentario no puede ir vacío.');
        }
        return $this->aplicar(
            [['origen' => $origen, 'referencia_id' => $referenciaId]],
            ['comentario' => $comentario],
            $usuario
        );
    }

    public function bitacora($origen, $referenciaId, $limite = 50)
    {
        $filas = $this->fetchAll(
            "SELECT b.* FROM seguimiento_bitacora b
               JOIN seguimiento s ON s.id = b.seguimiento_id
              WHERE s.origen = ? AND s.referencia_id = ?
              ORDER BY b.id DESC LIMIT " . max(1, (int) $limite),
            [(string) $origen, (int) $referenciaId]
        );
        return $filas ?: [];
    }

    /**
     * Deja la selección en una lista limpia de renglones existentes.
     *
     * Acepta las dos formas en que viajan: el arreglo {origen, referencia_id}
     * y la cadena "origen|id", que es como salen las casillas de la tabla.
     * Descarta lo que no reconoce en vez de fallar —una casilla rara no debe
     * tumbar una tanda de cincuenta— y quita repetidos, porque un mismo
     * renglón dos veces escribiría dos anotaciones idénticas.
     *
     * Es estática y pública a propósito: es lógica pura y se prueba sin base.
     */
    public static function normalizarItems(array $items)
    {
        $limpios = [];
        $vistos = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $partes = explode('|', $item, 2);
                $item = count($partes) === 2
                    ? ['origen' => $partes[0], 'referencia_id' => $partes[1]]
                    : [];
            }
            if (!is_array($item)) {
                continue;
            }

            // Un arreglo donde va el origen no es un renglón: convertirlo a
            // texto tumbaba la tanda entera con "Array to string conversion".
            if (!is_scalar($item['origen'] ?? '')) {
                continue;
            }
            $origen = trim((string) ($item['origen'] ?? ''));
            $ref = self::idDeReferencia($item['referencia_id'] ?? null);
            // Los cuatro orígenes, no los del modo: la selección viaja desde
            // el navegador y validarla contra el modo obligaría a que esto
            // dejara de ser estático. No hace falta: los valores son disjuntos
            // y la clave única (origen, referencia_id) impide que la gestión
            // de un comprobante caiga sobre la de un registro del ERP.
            if ($ref === null || !isset(self::ORIGENES[self::MODO_SISTEMA][$origen])
                          && !isset(self::ORIGENES[self::MODO_CORREO][$origen])) {
                continue;
            }

            $clave = $origen . '|' . $ref;
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $limpios[] = ['origen' => $origen, 'referencia_id' => $ref];
        }
        return $limpios;
    }

    /** `referencia_id` es INT UNSIGNED. */
    private const REFERENCIA_MAXIMA = 4294967295;

    /**
     * El id de un renglón, o null si lo que llegó no es exactamente uno.
     *
     * Solo enteros escritos como enteros. "12abc" no, pero tampoco "7.9" ni
     * "1e3": is_numeric() los acepta y (int) los volvía 7 y 1000, que son
     * renglones que nadie eligió. Tampoco lo que no cabe en la columna, que
     * la base habría recortado a otro id.
     */
    private static function idDeReferencia($crudo)
    {
        if (is_float($crudo)) {
            // 7.0 viaja así desde un JSON; 7.5 no es un id.
            $crudo = is_finite($crudo) && floor($crudo) === $crudo && abs($crudo) < 1e15
                ? (string) (int) $crudo
                : '';
        } elseif (is_int($crudo)) {
            $crudo = (string) $crudo;
        } elseif (!is_string($crudo)) {
            return null;
        }

        // Solo espacios: trim() sin lista quita también el byte nulo, y
        // "1\0" no es un id que alguien haya elegido.
        $crudo = trim($crudo, " \t\n\r\v\f");
        if (!preg_match('/^\d+$/D', $crudo)) {
            return null;
        }
        $digitos = ltrim($crudo, '0');
        if ($digitos === '' || strlen($digitos) > 10) {
            return null;
        }
        $id = (int) $digitos;
        return $id <= self::REFERENCIA_MAXIMA ? $id : null;
    }

    private function estadosActuales(array $items)
    {
        $mapa = [];
        foreach (array_chunk($items, 300) as $tanda) {
            $huecos = implode(', ', array_fill(0, count($tanda), '(?, ?)'));
            $params = [];
            foreach ($tanda as $item) {
                $params[] = $item['origen'];
                $params[] = $item['referencia_id'];
            }
            // FOR UPDATE: el estado anterior se lee con la fila tomada hasta
            // el commit. Sin candado, dos personas moviendo el mismo renglón
            // leían el mismo estado viejo y la bitácora quedaba con dos
            // anotaciones que decían salir de él, una de ellas mentira.
            $filas = $this->fetchAll(
                "SELECT origen, referencia_id, estado FROM seguimiento
                  WHERE (origen, referencia_id) IN ({$huecos})
                  FOR UPDATE",
                $params
            );
            foreach ($filas ?: [] as $fila) {
                $mapa[$fila['origen'] . '|' . (int) $fila['referencia_id']] = $fila['estado'];
            }
        }
        return $mapa;
    }

    /**
     * Crea la fila si no existía y la actualiza si sí, en una sola consulta
     * por tanda. Solo se pisan los campos que vienen en el cambio: mandar algo
     * a revisión sin responsable no debe borrar el responsable que ya había.
     */
    private function upsert(array $items, array $cambio, array $usuario)
    {
        $tocaEstado = array_key_exists('estado', $cambio);
        $tieneResponsable = array_key_exists('responsable', $cambio);
        $tieneRecordatorio = array_key_exists('recordar_en', $cambio);
        $tieneMotivo = array_key_exists('motivo', $cambio);

        $sets = ['actualizado_por = VALUES(actualizado_por)', 'actualizado_en = CURRENT_TIMESTAMP'];
        if ($tocaEstado)          { $sets[] = 'estado = VALUES(estado)'; }
        if ($tieneResponsable)    { $sets[] = 'responsable = VALUES(responsable)'; }
        if ($tieneRecordatorio) {
            // Los tres van juntos siempre: cambiar el momento sin reiniciar
            // `avisado_en` dejaría el aviso callado hasta cumplirse el ciclo
            // viejo, que es justo lo que se acaba de cambiar.
            $sets[] = 'recordar_en = VALUES(recordar_en)';
            $sets[] = 'recordar_cada = VALUES(recordar_cada)';
            $sets[] = 'avisado_en = NULL';
        }
        if ($tieneMotivo)         { $sets[] = 'motivo = VALUES(motivo)'; }

        // SIN_MARCA y "no tocar el estado" acaban los dos en NULL, pero solo el
        // primero lo escribe: el segundo ni siquiera aparece en los SET.
        $estado = $tocaEstado && $cambio['estado'] !== self::SIN_MARCA
            ? (string) $cambio['estado']
            : null;
        $responsable = $tieneResponsable ? ($cambio['responsable'] ?: null) : null;
        $recordarEn = $tieneRecordatorio ? ($cambio['recordar_en'] ?: null) : null;
        $recordarCada = $tieneRecordatorio && !empty($cambio['recordar_cada'])
            ? (int) $cambio['recordar_cada']
            : null;
        $motivo = $tieneMotivo ? (trim((string) $cambio['motivo']) ?: null) : null;
        $quien = $usuario['nombre'] ?? null;

        $hueco = '(?, ?, ?, ?, ?, ?, ?, ?)';
        foreach (array_chunk($items, 200) as $tanda) {
            $params = [];
            foreach ($tanda as $item) {
                $params[] = $item['origen'];
                $params[] = $item['referencia_id'];
                $params[] = $estado;
                $params[] = $responsable;
                $params[] = $recordarEn;
                $params[] = $recordarCada;
                $params[] = $motivo;
                $params[] = $quien;
            }
            $this->execute(
                'INSERT INTO seguimiento
                    (origen, referencia_id, estado, responsable, recordar_en, recordar_cada,
                     motivo, actualizado_por)
                 VALUES ' . implode(', ', array_fill(0, count($tanda), $hueco)) . '
                 ON DUPLICATE KEY UPDATE ' . implode(', ', $sets),
                $params
            );
        }
    }

    private function idsDe(array $items)
    {
        $mapa = [];
        foreach (array_chunk($items, 300) as $tanda) {
            $huecos = implode(', ', array_fill(0, count($tanda), '(?, ?)'));
            $params = [];
            foreach ($tanda as $item) {
                $params[] = $item['origen'];
                $params[] = $item['referencia_id'];
            }
            $filas = $this->fetchAll(
                "SELECT id, origen, referencia_id FROM seguimiento
                  WHERE (origen, referencia_id) IN ({$huecos})",
                $params
            );
            foreach ($filas ?: [] as $fila) {
                $mapa[$fila['origen'] . '|' . $fila['referencia_id']] = (int) $fila['id'];
            }
        }
        return $mapa;
    }

    /**
     * Una fila de bitácora por renglón tocado. No se anota cuando no cambió
     * nada y no hay comentario: la bitácora es para leerla, y llenarla de
     * "pendiente → pendiente" la vuelve inservible.
     *
     * Quitar la marca también es una decisión y se anota: queda como SIN_MARCA,
     * que en la pantalla se lee "sin marca (lo decide el cálculo)".
     */
    private function anotar(array $ids, array $antes, $estadoNuevo, $comentario, array $usuario)
    {
        $comentario = trim((string) $comentario);
        $filas = [];
        foreach ($ids as $clave => $seguimientoId) {
            // Un renglón que nadie había tocado no tiene fila previa: su
            // estado anterior es el del cálculo, que en la bitácora se lee
            // "sin marca".
            $anterior = ($antes[$clave] ?? null) ?: self::SIN_MARCA;
            $nuevo = $estadoNuevo === null ? null : ($estadoNuevo ?: self::SIN_MARCA);
            $cambioEstado = $nuevo !== null && $nuevo !== $anterior;
            if (!$cambioEstado && $comentario === '') {
                continue;
            }
            $filas[] = [
                $seguimientoId,
                $usuario['id'] ?? null,
                $usuario['nombre'] ?? null,
                $cambioEstado ? $anterior : null,
                $cambioEstado ? $nuevo : null,
                $comentario !== '' ? $comentario : null,
            ];
        }
        if (!$filas) {
            return;
        }

        $hueco = '(?, ?, ?, ?, ?, ?)';
        foreach (array_chunk($filas, 200) as $tanda) {
            $params = [];
            foreach ($tanda as $fila) {
                foreach ($fila as $valor) {
                    $params[] = $valor;
                }
            }
            $this->execute(
                'INSERT INTO seguimiento_bitacora
                    (seguimiento_id, usuario_id, usuario_nombre, estado_anterior, estado_nuevo, comentario)
                 VALUES ' . implode(', ', array_fill(0, count($tanda), $hueco)),
                $params
            );
        }
    }

    public function begin()    { return self::getDB()->beginTransaction(); }
    public function commit()   { return self::getDB()->commit(); }
    public function rollback()
    {
        return self::getDB()->inTransaction() ? self::getDB()->rollBack() : false;
    }
}
