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
require_once __DIR__ . '/Notificacion.php';
require_once __DIR__ . '/ProveedorCatalogo.php';

class Seguimiento extends Model
{
    protected $table = 'seguimiento';

    /** Rutas absolutas guardadas como relativas: el Model las rehidrata. */
    protected $camposRuta = ['ruta_xml', 'ruta_pdf'];

    public const ESTADOS = [
        'pendiente' => 'Pendientes',
        'revision'  => 'En revisión',
        'lista'     => 'Listas',
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

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS seguimiento (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                origen ENUM('nota_credito','factura') NOT NULL,
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

            $this->renombrarOrigenPagoSemanal();
            $this->migrarEstadosDeGestion();
            $this->agregarRecordatorio();
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
    private function renombrarOrigenPagoSemanal()
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

    // ── La consulta que arma la cola ────────────────────────────────────────

    /**
     * Las dos fuentes, con las mismas columnas, para poder unirlas.
     *
     * `tarea` se calcula aquí y no en PHP porque es lo que se filtra, se
     * ordena y se cuenta: hacerlo fuera obligaría a traerse las 5 000 filas
     * para mostrar 50.
     *
     * `en_juego` es el dinero que hay detrás del renglón: la diferencia si la
     * hay, el monto del documento si no. Es el orden por omisión, porque una
     * diferencia de dos millones no puede quedar debajo de una de cien colones.
     *
     * El reporte de notas trae monto y saldo por separado. La factura del ERP
     * también, con una vuelta: cuando entra a un pago se le congela el saldo
     * de ese momento (`saldo_pago`), y ese es el que manda mientras el pago
     * exista —si no, pagar la factura la borraría de la cola aunque su
     * respaldo siga faltando—.
     */
    private function sqlUnion()
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
                x.consecutivo_completo AS consecutivo,
                " . sprintf($tarea, 'l.estado', 'l.estado') . " AS tarea,
                COALESCE(ABS(l.diferencia), l.monto) AS en_juego
              FROM notas_credito_lineas l
              JOIN notas_credito_listados li ON li.id = l.listado_id
              LEFT JOIN facturas_xml x ON x.id = l.factura_xml_id
             WHERE l.clase IN ('directa', 'costo', 'cambio', 'revisar')
               AND li.id = (SELECT MAX(actual.id)
                              FROM notas_credito_listados actual
                             WHERE actual.sociedad_id = li.sociedad_id)";

        // El documento es la factura del ERP, no el pago semanal.
        //
        // El pago dejó de tener líneas propias: solo marca cuáles de estas
        // facturas se pagan esta semana. Entrar por él dejaba fuera de la cola
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
                x.consecutivo_completo AS consecutivo,
                " . sprintf($tarea, 'pe.estado_respaldo', 'pe.estado_respaldo') . " AS tarea,
                COALESCE(ABS(pe.diferencia), COALESCE(pe.saldo_pago, pe.saldo)) AS en_juego
              FROM facturas_erp pe
              LEFT JOIN porpagar_listados li ON li.id = pe.porpagar_listado_id
              LEFT JOIN facturas_xml x ON x.id = pe.factura_xml_id";

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
    private function sqlBase()
    {
        return "SELECT c.*,
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
        if (isset(self::ESTADOS[$vista])) {
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

        if (!empty($f['origen']) && isset(['nota_credito' => 1, 'factura' => 1][$f['origen']])) {
            $where[] = 'c.origen = ?';
            $params[] = $f['origen'];
        }
        if (!empty($f['tarea']) && isset(self::TAREAS[$f['tarea']])) {
            $where[] = 'c.tarea = ?';
            $params[] = $f['tarea'];
        }
        if (!empty($f['clase']) && $f['clase'] !== 'todas') {
            $where[] = 'c.clase = ?';
            $params[] = $f['clase'];
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
        $proveedor = ProveedorCatalogo::condicion(
            $f['proveedor'] ?? '',
            ['codigo' => 'c.proveedor_codigo', 'nombre' => 'c.proveedor'],
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
            $like = '%' . trim((string) $f['q']) . '%';
            $where[] = '(c.documento LIKE ? OR c.proveedor LIKE ? OR c.consecutivo LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($f['col_documento'])) {
            $like = '%' . trim((string) $f['col_documento']) . '%';
            $where[] = '(c.documento LIKE ? OR c.contexto LIKE ? OR CAST(c.fecha AS CHAR) LIKE ? OR c.clase LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['col_proveedor'])) {
            $like = '%' . trim((string) $f['col_proveedor']) . '%';
            $where[] = '(c.proveedor LIKE ? OR c.sucursal LIKE ?)';
            array_push($params, $like, $like);
        }
        foreach (['col_monto' => 'c.monto', 'col_saldo' => 'c.saldo'] as $clave => $columna) {
            $valor = preg_replace('/[₡$,\s]/u', '', (string) ($f[$clave] ?? ''));
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
        if (!empty($f['col_tarea']) && isset(self::TAREAS[$f['col_tarea']])) {
            $where[] = 'c.tarea = ?';
            $params[] = $f['col_tarea'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private function ordenSql($orden)
    {
        $mapa = [
            'monto'      => 'c.en_juego DESC, c.fecha DESC',
            'antiguedad' => 'c.fecha ASC, c.en_juego DESC',
            'reciente'   => 'c.fecha DESC, c.en_juego DESC',
            'proveedor'  => 'c.proveedor ASC, c.en_juego DESC',
            'movimiento' => 's.actualizado_en DESC, c.en_juego DESC',
        ];
        return $mapa[$orden] ?? $mapa['monto'];
    }

    public function cola(array $filtros, $pagina = 1, $porPagina = 50)
    {
        [$where, $params] = $this->condiciones($filtros);
        $porPagina = max(10, min(200, (int) $porPagina));
        $pagina = max(1, (int) $pagina);
        $offset = ($pagina - 1) * $porPagina;

        $total = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM (" . $this->sqlUnion() . ") c
              LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
              {$where}",
            $params
        );

        // LIMIT y OFFSET van interpolados y no como parámetros: con consultas
        // preparadas emuladas llegarían entrecomillados y MariaDB los rechaza.
        // Son enteros ya acotados arriba, no texto del usuario.
        $filas = $this->fetchAll(
            $this->sqlBase() . " {$where} ORDER BY " . $this->ordenSql($filtros['orden'] ?? '')
            . " LIMIT {$porPagina} OFFSET {$offset}",
            $params
        );

        return [
            'filas' => $filas ?: [],
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => (int) max(1, ceil($total / $porPagina)),
        ];
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
        $fila = $this->fetchOne(
            "SELECT
                COUNT(*) total,
                SUM({$e} = 'pendiente') pendiente,
                SUM({$e} = 'revision')  revision,
                SUM({$e} = 'lista')     lista,
                SUM({$e} = 'cerrada')   cerrada,
                SUM({$e} <> 'cerrada' AND c.tarea = 'falta_xml') falta_xml,
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

    /** Un renglón concreto, para el panel de detalle. */
    public function uno($origen, $referenciaId)
    {
        $fila = $this->fetchOne(
            $this->sqlBase() . ' WHERE c.origen = ? AND c.referencia_id = ? LIMIT 1',
            [(string) $origen, (int) $referenciaId]
        );
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
        $avisos = new Notificacion();
        $dejados = 0;
        $ids = [];

        foreach ($vencidos as $v) {
            $clave = $v['origen'] . '|' . $v['referencia_id'];
            $doc = $documentos[$clave] ?? ['documento' => $clave, 'proveedor' => ''];
            $detalle = trim((string) $v['motivo']);
            if (!empty($doc['proveedor'])) {
                $detalle = $doc['proveedor'] . ($detalle !== '' ? ' — ' . $detalle : '');
            }
            if (!empty($v['responsable'])) {
                $detalle .= ' (a cargo: ' . $v['responsable'] . ')';
            }

            $dejados += $avisos->avisar([
                'tipo' => 'seguimiento_recordatorio',
                'severidad' => 'media',
                'titulo' => 'Recordatorio de seguimiento: ' . $doc['documento'],
                'detalle' => $detalle,
                'firma' => self::firmaAviso($v['origen'], $v['referencia_id']),
                'ref_tabla' => 'seguimiento',
                'ref_clave' => $clave,
                'datos' => [
                    'origen' => $v['origen'],
                    'referencia_id' => (int) $v['referencia_id'],
                    'documento' => $doc['documento'],
                ],
            ]);
            $ids[] = (int) $v['id'];
        }

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
        $porOrigen = ['factura' => [], 'nota_credito' => []];
        foreach ($filas as $f) {
            if (isset($porOrigen[$f['origen']])) {
                $porOrigen[$f['origen']][] = (int) $f['referencia_id'];
            }
        }

        $partes = [];
        $params = [];
        $tablas = ['factura' => 'facturas_erp', 'nota_credito' => 'notas_credito_lineas'];
        foreach ($tablas as $origen => $tabla) {
            if (!$porOrigen[$origen]) {
                continue;
            }
            $huecos = implode(',', array_fill(0, count($porOrigen[$origen]), '?'));
            $partes[] = "SELECT '{$origen}' AS origen, id AS referencia_id, documento,
                                proveedor_nombre AS proveedor
                           FROM {$tabla} WHERE id IN ({$huecos})";
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

    /** Apaga en la campana los avisos de unos renglones. */
    private function cerrarAvisos(array $items, array $usuario)
    {
        try {
            $avisos = new Notificacion();
            foreach ($items as $item) {
                $avisos->cerrarPorFirma(
                    self::firmaAviso($item['origen'], $item['referencia_id']),
                    'resuelta',
                    (int) ($usuario['id'] ?? 0)
                );
            }
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

        $tocaEstado = array_key_exists('estado', $cambio);
        $estado = $tocaEstado ? (string) $cambio['estado'] : '';
        if ($tocaEstado && $estado !== self::SIN_MARCA && !isset(self::ESTADOS[$estado])) {
            throw new Exception('Estado de seguimiento no válido: ' . $estado);
        }
        // La bandeja de enredos no sirve de nada si no dice cuál es el enredo:
        // sin el motivo, quien la abra mañana tiene que adivinar.
        if ($estado === self::ESTADO_A_MANO && trim((string) ($cambio['motivo'] ?? '')) === '') {
            throw new Exception('Para mandar algo a revisión hay que describir el problema.');
        }

        // Salir de revisión apaga el recordatorio y el aviso: los dos existían
        // para un enredo que acaba de dejar de estarlo. Dejarlos sonando sería
        // pedirle a alguien que persiga algo ya resuelto.
        if ($tocaEstado && $estado !== self::ESTADO_A_MANO
            && !array_key_exists('recordar_en', $cambio)) {
            $cambio['recordar_en'] = null;
            $cambio['recordar_cada'] = null;
        }

        $this->begin();
        try {
            $antes = $this->estadosActuales($items);
            $this->upsert($items, $cambio, $usuario);
            $ids = $this->idsDe($items);
            $this->anotar($ids, $antes, $tocaEstado ? $estado : null, self::nota($cambio), $usuario);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        if ($tocaEstado && $estado !== self::ESTADO_A_MANO) {
            $this->cerrarAvisos($items, $usuario);
        }

        return ['afectados' => count($items)];
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
            throw new Exception('El comentario no puede ir vacío.');
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

            $origen = trim((string) ($item['origen'] ?? ''));
            $refCruda = $item['referencia_id'] ?? '';
            // "12abc" no es un id: (int) lo convertiría en 12 y tocaría un
            // renglón que nadie eligió.
            if (!is_numeric($refCruda)) {
                continue;
            }
            $ref = (int) $refCruda;
            if ($ref <= 0 || !in_array($origen, ['nota_credito', 'factura'], true)) {
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
            $filas = $this->fetchAll(
                "SELECT origen, referencia_id, estado FROM seguimiento
                  WHERE (origen, referencia_id) IN ({$huecos})",
                $params
            );
            foreach ($filas ?: [] as $fila) {
                $mapa[$fila['origen'] . '|' . $fila['referencia_id']] = $fila['estado'];
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
            $anterior = $antes[$clave] ?: self::SIN_MARCA;
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
