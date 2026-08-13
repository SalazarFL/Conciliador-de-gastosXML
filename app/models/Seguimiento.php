<?php
/**
 * La cola única de trabajo: todo lo que le falta respaldo o no cuadra, venga
 * de notas de crédito o del pago semanal, en una sola lista.
 *
 * Cada renglón responde tres preguntas: ¿tiene su XML?, ¿tiene su PDF?, ¿el
 * monto cuadra? Mientras alguna falle, hay tarea. La tarea concreta es la
 * primera que falle, en ese orden: sin XML no tiene sentido hablar del PDF.
 *
 * El estado de seguimiento vive en su propia tabla y la fila se crea solo
 * cuando alguien actúa (ver database/migrations/004_seguimiento.sql). Un
 * renglón sin fila es 'pendiente'.
 */
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';

class Seguimiento extends Model
{
    protected $table = 'seguimiento';

    /** Rutas absolutas guardadas como relativas: el Model las rehidrata. */
    protected $camposRuta = ['ruta_xml', 'ruta_pdf'];

    public const ESTADOS = [
        'pendiente'     => 'Pendiente',
        'en_gestion'    => 'En gestión',
        'esperando'     => 'Esperando al proveedor',
        'resuelto'      => 'Resuelto',
        'no_disponible' => 'No va a existir',
        'descartado'    => 'Descartado',
    ];

    /** Los que ya no son trabajo: salen de la cola pero quedan consultables. */
    public const ESTADOS_CERRADOS = ['resuelto', 'no_disponible', 'descartado'];

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
                origen ENUM('nota_credito','pago_semanal') NOT NULL,
                referencia_id INT UNSIGNED NOT NULL,
                estado ENUM('pendiente','en_gestion','esperando','resuelto','no_disponible','descartado')
                    NOT NULL DEFAULT 'pendiente',
                responsable VARCHAR(120) NULL,
                vence_el DATE NULL,
                motivo VARCHAR(255) NULL,
                actualizado_por VARCHAR(120) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_seguimiento_referencia (origen, referencia_id),
                KEY idx_seguimiento_estado (estado, vence_el),
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
        } catch (Throwable $e) {
            // database/migrations/004_seguimiento.sql cubre instalaciones sin DDL en runtime.
        }
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
     * El reporte de notas trae monto y saldo por separado. El pago semanal no
     * trae una columna de saldo: su total es precisamente el importe pendiente
     * incluido para pagar, por lo que se usa como saldo operativo en esta cola.
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
             WHERE l.clase IN ('directa', 'costo', 'cambio', 'revisar')";

        $pp = "SELECT
                'pago_semanal' AS origen,
                pf.id AS referencia_id,
                pf.numero AS documento,
                NULL AS clase,
                pf.proveedor_texto AS proveedor,
                NULL AS sucursal,
                pf.fecha AS fecha,
                'CRC' AS moneda,
                pf.total AS monto,
                pf.total AS saldo,
                pf.diferencia AS diferencia,
                NULL AS motivo_match,
                pf.factura_xml_id AS factura_xml_id,
                li.id AS contexto_id,
                li.nombre AS contexto,
                li.sociedad_id AS sociedad_id,
                x.ruta_xml AS ruta_xml,
                x.ruta_pdf AS ruta_pdf,
                x.estado_pdf AS estado_pdf,
                x.consecutivo_completo AS consecutivo,
                " . sprintf($tarea, 'pf.estado', 'pf.estado') . " AS tarea,
                COALESCE(ABS(pf.diferencia), pf.total) AS en_juego
              FROM porpagar_facturas pf
              JOIN porpagar_listados li ON li.id = pf.listado_id
              LEFT JOIN facturas_xml x ON x.id = pf.factura_xml_id
             WHERE li.estado <> 'cerrado'";

        return "({$nc}) UNION ALL ({$pp})";
    }

    /**
     * La cola con el seguimiento pegado. Se resuelve en una sola consulta
     * porque desde fuera de la oficina cada viaje a la base cuesta ~100 ms.
     */
    private function sqlBase()
    {
        return "SELECT c.*,
                       s.id AS seguimiento_id,
                       COALESCE(s.estado, 'pendiente') AS seguimiento_estado,
                       s.responsable,
                       s.vence_el,
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
     * 'abierto' es el filtro por omisión y significa: hay algo que hacer y
     * nadie lo cerró. Un renglón pospuesto a futuro tampoco es trabajo de hoy,
     * así que sale de la cola hasta que llegue su fecha.
     */
    private function condiciones(array $f)
    {
        $where = [];
        $params = [];

        if (!empty($f['sociedad_id'])) {
            $where[] = 'c.sociedad_id = ?';
            $params[] = (int) $f['sociedad_id'];
        }

        $vista = $f['vista'] ?? 'abierto';
        if ($vista === 'abierto') {
            $where[] = "c.tarea <> 'completo'";
            $where[] = "COALESCE(s.estado, 'pendiente') NOT IN ('" . implode("','", self::ESTADOS_CERRADOS) . "')";
            $where[] = '(s.vence_el IS NULL OR s.vence_el <= CURDATE())';
        } elseif ($vista === 'pospuesto') {
            $where[] = 's.vence_el IS NOT NULL AND s.vence_el > CURDATE()';
        } elseif ($vista === 'cerrado') {
            $where[] = "COALESCE(s.estado, 'pendiente') IN ('" . implode("','", self::ESTADOS_CERRADOS) . "')";
        } elseif ($vista === 'completo') {
            $where[] = "c.tarea = 'completo'";
        }
        // 'todo' no agrega condición.

        if (!empty($f['origen']) && isset(['nota_credito' => 1, 'pago_semanal' => 1][$f['origen']])) {
            $where[] = 'c.origen = ?';
            $params[] = $f['origen'];
        }
        if (!empty($f['tarea']) && isset(self::TAREAS[$f['tarea']])) {
            $where[] = 'c.tarea = ?';
            $params[] = $f['tarea'];
        }
        if (!empty($f['estado']) && isset(self::ESTADOS[$f['estado']])) {
            $where[] = "COALESCE(s.estado, 'pendiente') = ?";
            $params[] = $f['estado'];
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
        if (!empty($f['col_estado']) && isset(self::ESTADOS[$f['col_estado']])) {
            $where[] = "COALESCE(s.estado, 'pendiente') = ?";
            $params[] = $f['col_estado'];
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
     * Los números de las tarjetas de arriba. Se calculan sobre la cola abierta
     * con los mismos filtros, menos el de tarea: si no, al filtrar por "falta
     * el PDF" las demás tarjetas mostrarían cero y no se podría comparar.
     */
    public function resumen(array $filtros)
    {
        $f = $filtros;
        unset($f['tarea'], $f['col_tarea']);
        [$where, $params] = $this->condiciones($f);

        $fila = $this->fetchOne(
            "SELECT
                COUNT(*) total,
                SUM(c.tarea = 'falta_xml') falta_xml,
                SUM(c.tarea = 'falta_pdf') falta_pdf,
                SUM(c.tarea = 'diferencia') diferencia,
                SUM(c.tarea = 'completo') completo,
                SUM(COALESCE(s.estado, 'pendiente') = 'pendiente') sin_tocar,
                SUM(COALESCE(s.estado, 'pendiente') IN ('en_gestion', 'esperando')) en_curso,
                SUM(c.tarea = 'diferencia') AS casos_diferencia,
                COALESCE(SUM(CASE WHEN c.tarea = 'diferencia' THEN ABS(c.diferencia) ELSE 0 END), 0) monto_diferencia,
                SUM(s.vence_el IS NOT NULL AND s.vence_el < CURDATE()
                    AND COALESCE(s.estado,'pendiente') NOT IN ('resuelto','no_disponible','descartado')) vencidos
             FROM (" . $this->sqlUnion() . ") c
             LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
             {$where}",
            $params
        );

        $vacio = [
            'total' => 0, 'falta_xml' => 0, 'falta_pdf' => 0, 'diferencia' => 0, 'completo' => 0,
            'sin_tocar' => 0, 'en_curso' => 0, 'casos_diferencia' => 0, 'monto_diferencia' => 0,
            'vencidos' => 0,
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
     * Los listados que alimentan la cola, para poder acotar a uno solo.
     * Solo los que tienen algo pendiente: ofrecer listados ya resueltos en el
     * desplegable es ruido.
     */
    public function contextosDisponibles($sociedadId)
    {
        $filas = $this->fetchAll(
            "SELECT c.origen, c.contexto_id, c.contexto, COUNT(*) pendientes
               FROM (" . $this->sqlUnion() . ") c
               LEFT JOIN seguimiento s ON s.origen = c.origen AND s.referencia_id = c.referencia_id
              WHERE c.sociedad_id = ?
                AND c.tarea <> 'completo'
                AND COALESCE(s.estado, 'pendiente') NOT IN ('" . implode("','", self::ESTADOS_CERRADOS) . "')
              GROUP BY c.origen, c.contexto_id, c.contexto
              ORDER BY c.origen, pendientes DESC",
            [(int) $sociedadId]
        );
        return $filas ?: [];
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

    // ── Escritura ───────────────────────────────────────────────────────────

    /**
     * Aplica un cambio a varios renglones a la vez y deja la bitácora.
     *
     * Se hace por tandas y no de a uno porque la persona va a seleccionar
     * cincuenta renglones y darle a "en gestión": de a una consulta por
     * renglón, con la base fuera de la oficina, eso es medio minuto de espera.
     *
     * @param array $items  [['origen' => 'nota_credito', 'referencia_id' => 12], ...]
     */
    public function aplicar(array $items, array $cambio, array $usuario)
    {
        $items = self::normalizarItems($items);
        if (!$items) {
            return ['afectados' => 0];
        }

        $estado = $cambio['estado'] ?? null;
        if ($estado !== null && !isset(self::ESTADOS[$estado])) {
            throw new Exception('Estado de seguimiento no válido: ' . $estado);
        }

        $this->begin();
        try {
            $antes = $this->estadosActuales($items);
            $this->upsert($items, $cambio, $usuario);
            $ids = $this->idsDe($items);
            $this->anotar($ids, $antes, $estado, $cambio['comentario'] ?? '', $usuario);
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['afectados' => count($items)];
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
            if ($ref <= 0 || !in_array($origen, ['nota_credito', 'pago_semanal'], true)) {
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
     * por tanda. Solo se pisan los campos que vienen en el cambio: mandar
     * "en gestión" sin responsable no debe borrar el responsable que ya había.
     */
    private function upsert(array $items, array $cambio, array $usuario)
    {
        $estado = $cambio['estado'] ?? null;
        $tieneResponsable = array_key_exists('responsable', $cambio);
        $tieneVence = array_key_exists('vence_el', $cambio);
        $tieneMotivo = array_key_exists('motivo', $cambio);

        $sets = ['actualizado_por = VALUES(actualizado_por)', 'actualizado_en = CURRENT_TIMESTAMP'];
        if ($estado !== null)     { $sets[] = 'estado = VALUES(estado)'; }
        if ($tieneResponsable)    { $sets[] = 'responsable = VALUES(responsable)'; }
        if ($tieneVence)          { $sets[] = 'vence_el = VALUES(vence_el)'; }
        if ($tieneMotivo)         { $sets[] = 'motivo = VALUES(motivo)'; }

        $responsable = $tieneResponsable ? ($cambio['responsable'] ?: null) : null;
        $vence = $tieneVence ? ($cambio['vence_el'] ?: null) : null;
        $motivo = $tieneMotivo ? (trim((string) $cambio['motivo']) ?: null) : null;
        $quien = $usuario['nombre'] ?? null;

        $hueco = '(?, ?, ?, ?, ?, ?, ?)';
        foreach (array_chunk($items, 200) as $tanda) {
            $params = [];
            foreach ($tanda as $item) {
                $params[] = $item['origen'];
                $params[] = $item['referencia_id'];
                $params[] = $estado ?? 'pendiente';
                $params[] = $responsable;
                $params[] = $vence;
                $params[] = $motivo;
                $params[] = $quien;
            }
            $this->execute(
                'INSERT INTO seguimiento
                    (origen, referencia_id, estado, responsable, vence_el, motivo, actualizado_por)
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
     */
    private function anotar(array $ids, array $antes, $estadoNuevo, $comentario, array $usuario)
    {
        $comentario = trim((string) $comentario);
        $filas = [];
        foreach ($ids as $clave => $seguimientoId) {
            $anterior = $antes[$clave] ?? 'pendiente';
            $cambioEstado = $estadoNuevo !== null && $estadoNuevo !== $anterior;
            if (!$cambioEstado && $comentario === '') {
                continue;
            }
            $filas[] = [
                $seguimientoId,
                $usuario['id'] ?? null,
                $usuario['nombre'] ?? null,
                $cambioEstado ? $anterior : null,
                $cambioEstado ? $estadoNuevo : null,
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
