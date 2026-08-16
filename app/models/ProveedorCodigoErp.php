<?php
/**
 * Equivalencia entre el código de proveedor del ERP y el emisor del XML.
 *
 * Para qué sirve: el emparejamiento del pago semanal cruza por el consecutivo
 * de veinte dígitos, y ese número NO es único a nivel país —lo arma el emisor,
 * empezando en 1— así que dos proveedores distintos pueden tener el mismo. Sin
 * este mapa, `buscarXml()` se lleva el primero de la lista y no hay forma de
 * notarlo. Con él, puede preguntar "¿este comprobante lo emitió el proveedor
 * que dice esta línea?" y seguir buscando si no.
 *
 * De dónde sale: no hace falta que nadie enseñe nada. Se deduce de los
 * emparejamientos ya verificados, y SOLO de los que quedaron `respaldada` —los
 * que cuadraron al colón—. Ese filtro es el que hace confiable la cosecha: los
 * enlaces malos viven todos en `con_diferencia`, porque un XML del proveedor
 * equivocado prácticamente nunca cuadra el monto.
 *
 * El contador `veces_confirmado` NO decide si se aprende; eso lo decide el
 * estado. Decide quién gana cuando dos datos se contradicen.
 */

class ProveedorCodigoErp extends Model
{
    protected $table = 'proveedor_codigo_erp';

    /**
     * El mapa completo, leído una vez por petición.
     *
     * Son unos cientos de filas y las consulta cada comparación del bucle de
     * emparejamiento; traerlas de a una contra el servidor sería el mismo
     * error que ya costó segundos por pantalla en Correo.
     */
    private static $mapa = null;

    public function __construct()
    {
        Esquema::unaVez(static::class, function () {
            $this->ensureTables();
            // Idempotente: recalcula los contadores desde los emparejamientos
            // reales. Corre una vez por instalación y luego solo cuando cambia
            // el código, que es justo cuando conviene resincronizar.
            $this->cosechar();
        });
    }

    private function ensureTables()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS proveedor_codigo_erp (
            `proveedor_codigo` VARCHAR(30) NOT NULL,
            `proveedor_id` INT UNSIGNED NOT NULL,
            `cedula` VARCHAR(20) NOT NULL DEFAULT '',
            `nombre_erp` VARCHAR(255) NOT NULL DEFAULT '',
            `veces_confirmado` INT UNSIGNED NOT NULL DEFAULT 1,
            `origen` VARCHAR(20) NOT NULL DEFAULT 'cosecha',
            `en_disputa` TINYINT(1) NOT NULL DEFAULT 0,
            `confirmado_por` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`proveedor_codigo`),
            KEY `idx_proveedor` (`proveedor_id`),
            KEY `idx_origen` (`origen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->execute("CREATE TABLE IF NOT EXISTS proveedor_codigo_conflictos (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `proveedor_codigo` VARCHAR(30) NOT NULL,
            `proveedor_id_mapa` INT UNSIGNED NOT NULL,
            `proveedor_id_propuesto` INT UNSIGNED NOT NULL,
            `factura_erp_id` INT UNSIGNED NULL DEFAULT NULL,
            `factura_xml_id` INT UNSIGNED NULL DEFAULT NULL,
            `monto_cuadraba` TINYINT(1) NOT NULL DEFAULT 0,
            `visto_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_codigo` (`proveedor_codigo`),
            KEY `idx_par` (`proveedor_codigo`, `proveedor_id_propuesto`),
            KEY `idx_visto` (`visto_en`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ── Cosecha ────────────────────────────────────────────────────────

    /**
     * Recalcula el mapa desde los emparejamientos verificados.
     *
     * Es un RECÁLCULO, no un incremento, y esa diferencia importa. Un pago se
     * reverifica cada vez que alguien vincula algo a mano; si cada corrida
     * sumara uno, los contadores se inflarían solos y dejarían de significar
     * "cuántas facturas respaldan esto" —el mismo problema que `registrarUsos`
     * de ProveedorAlias tuvo que esquivar—. Contando desde la fuente, el
     * número siempre es el de verdad y correr esto de más nunca hace daño.
     *
     * Lo confirmado a mano no se toca nunca: una persona sabe más que el
     * conteo.
     *
     * @param array $codigos Limitar a estos códigos; vacío = todo el mapa.
     */
    public function cosechar(array $codigos = [])
    {
        $filtro = '';
        $params = [];
        $codigos = array_values(array_unique(array_filter(array_map('strval', $codigos), 'strlen')));
        if ($codigos) {
            $filtro = ' AND e.proveedor_codigo IN (' . implode(',', array_fill(0, count($codigos), '?')) . ')';
            $params = $codigos;
        }

        try {
            return (int) $this->execute(
                "INSERT INTO proveedor_codigo_erp
                        (proveedor_codigo, proveedor_id, cedula, nombre_erp, veces_confirmado, origen)
                 SELECT e.proveedor_codigo, f.proveedor_id, p.rfc,
                        MAX(e.proveedor_nombre), COUNT(*) AS veces, 'cosecha'
                   FROM facturas_erp e
                   JOIN facturas_xml f ON f.id = e.factura_xml_id
                   JOIN proveedores  p ON p.id = f.proveedor_id
                  WHERE e.estado_respaldo = 'respaldada'
                    AND p.rfc IS NOT NULL AND p.rfc <> ''
                        {$filtro}
                  GROUP BY e.proveedor_codigo, f.proveedor_id, p.rfc
                  ORDER BY veces DESC
                 ON DUPLICATE KEY UPDATE
                    proveedor_id     = IF(origen = 'manual', proveedor_id,
                                          IF(VALUES(veces_confirmado) > veces_confirmado, VALUES(proveedor_id), proveedor_id)),
                    cedula           = IF(origen = 'manual', cedula,
                                          IF(VALUES(veces_confirmado) > veces_confirmado, VALUES(cedula), cedula)),
                    nombre_erp       = VALUES(nombre_erp),
                    veces_confirmado = IF(origen = 'manual', veces_confirmado,
                                          GREATEST(veces_confirmado, VALUES(veces_confirmado)))",
                $params
            );
        } catch (Throwable $e) {
            // Sin mapa el emparejamiento sigue funcionando como antes.
            return 0;
        } finally {
            self::olvidarMapa();
        }
    }

    // ── Lectura ────────────────────────────────────────────────────────

    /** proveedor_codigo => ['proveedor_id', 'cedula', 'veces', 'disputa'] */
    public static function mapa()
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }

        self::$mapa = [];
        try {
            $filas = (new self())->fetchAll(
                'SELECT proveedor_codigo, proveedor_id, cedula, veces_confirmado, en_disputa
                   FROM proveedor_codigo_erp'
            ) ?: [];
            foreach ($filas as $f) {
                self::$mapa[(string) $f['proveedor_codigo']] = [
                    'proveedor_id' => (int) $f['proveedor_id'],
                    'cedula'       => (string) $f['cedula'],
                    'veces'        => (int) $f['veces_confirmado'],
                    'disputa'      => !empty($f['en_disputa']),
                ];
            }
        } catch (Throwable $e) {
            // Instalación que no ha corrido la migración: sin mapa, sin guarda.
            self::$mapa = [];
        }

        return self::$mapa;
    }

    public static function olvidarMapa()
    {
        self::$mapa = null;
    }

    /**
     * ¿Este comprobante lo emitió el proveedor de este código del ERP?
     *
     *   'propio'      -> sí; se acepta
     *   'ajeno'       -> no, y el mapa está seguro; se rechaza y se sigue buscando
     *   'desconocido' -> el mapa no sabe (no hay fila, o está en disputa, o el
     *                    XML no trae emisor). Se acepta: sin información, la
     *                    guarda no puede ser más estricta que antes de existir.
     */
    public static function veredicto($codigo, $proveedorIdXml)
    {
        $codigo = trim((string) $codigo);
        $proveedorIdXml = (int) $proveedorIdXml;
        if ($codigo === '' || $proveedorIdXml <= 0) {
            return 'desconocido';
        }

        $fila = self::mapa()[$codigo] ?? null;
        if ($fila === null || $fila['disputa']) {
            return 'desconocido';
        }

        return $fila['proveedor_id'] === $proveedorIdXml ? 'propio' : 'ajeno';
    }

    public function porCodigo($codigo)
    {
        try {
            return $this->fetchOne(
                'SELECT m.*, p.razon_social
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id
                  WHERE m.proveedor_codigo = ? LIMIT 1',
                [(string) $codigo]
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Para la pantalla de administración. */
    public function listar()
    {
        try {
            return $this->fetchAll(
                'SELECT m.proveedor_codigo, m.cedula, m.nombre_erp, m.veces_confirmado,
                        m.origen, m.en_disputa, m.creado_en,
                        p.razon_social, u.nombre AS confirmado_por_nombre
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id
                   LEFT JOIN usuarios u ON u.id = m.confirmado_por
                  ORDER BY m.en_disputa DESC, m.veces_confirmado DESC'
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Conflictos ─────────────────────────────────────────────────────

    /**
     * Anota un veto y decide si hay que molestar a una persona.
     *
     * Casi todos los vetos son colisiones de consecutivo y el veto es
     * correcto: no hay nada que decidir y el emparejador simplemente sigue
     * buscando. Lo que sí pide una persona es el patrón contrario —que el
     * comprobante vetado cuadre al colón con el monto del ERP—, porque esa es
     * la firma de un código que cambió de dueño y no la de una colisión: dos
     * facturas de proveedores distintos rarísima vez coinciden en número Y en
     * monto.
     *
     * Cuando el mapa solo tenía UNA confirmación, además, deja de vetar: dos
     * afirmaciones igual de respaldadas son un empate, y un mapa que no sabe
     * no puede seguir bloqueando. Vuelve a comportarse como antes hasta que
     * alguien decida.
     *
     * @return bool Si el código quedó en disputa.
     */
    public function registrarVeto(array $v)
    {
        $codigo = trim((string) ($v['codigo'] ?? ''));
        $propuesto = (int) ($v['proveedor_id_propuesto'] ?? 0);
        if ($codigo === '' || $propuesto <= 0) {
            return false;
        }

        $fila = self::mapa()[$codigo] ?? null;
        if ($fila === null) {
            return false;
        }

        $cuadraba = !empty($v['monto_cuadraba']);
        $disputa = $cuadraba && $fila['veces'] <= 1;

        try {
            $this->execute(
                'INSERT INTO proveedor_codigo_conflictos
                    (proveedor_codigo, proveedor_id_mapa, proveedor_id_propuesto,
                     factura_erp_id, factura_xml_id, monto_cuadraba)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $codigo, $fila['proveedor_id'], $propuesto,
                    (int) ($v['factura_erp_id'] ?? 0) ?: null,
                    (int) ($v['factura_xml_id'] ?? 0) ?: null,
                    $cuadraba ? 1 : 0,
                ]
            );

            if ($disputa) {
                $this->execute(
                    'UPDATE proveedor_codigo_erp SET en_disputa = 1 WHERE proveedor_codigo = ?',
                    [$codigo]
                );
                self::olvidarMapa();
            }
        } catch (Throwable $e) {
            return false;
        }

        if ($cuadraba) {
            $this->avisarConflicto($codigo, $fila, $propuesto, $disputa);
        }
        return $disputa;
    }

    /**
     * Deja el aviso en la campana. La firma es el par (código, propuesto): el
     * mismo desacuerdo detectado en veinte verificaciones es un aviso, no
     * veinte.
     */
    private function avisarConflicto($codigo, array $fila, $propuesto, $disputa)
    {
        try {
            require_once __DIR__ . '/Notificacion.php';
            require_once __DIR__ . '/Proveedor.php';

            $nombres = $this->nombresDe([$fila['proveedor_id'], $propuesto]);
            $actual = $nombres[$fila['proveedor_id']] ?? ('proveedor #' . $fila['proveedor_id']);
            $nuevo  = $nombres[$propuesto] ?? ('proveedor #' . $propuesto);

            (new Notificacion())->avisar([
                'tipo'      => 'codigo_proveedor',
                'severidad' => $disputa ? 'alta' : 'media',
                'titulo'    => 'El código ' . $codigo . ' podría haber cambiado de proveedor',
                'detalle'   => 'Llegó un comprobante de "' . $nuevo . '" cuyo monto cuadra exacto con una '
                             . 'factura del código ' . $codigo . ', pero el mapa dice que ese código es de "'
                             . $actual . '" (' . $fila['veces'] . ' ' . ($fila['veces'] === 1 ? 'confirmación' : 'confirmaciones') . '). '
                             . ($disputa
                                ? 'Como el mapa solo tenía una confirmación, dejó de bloquear ese código hasta que alguien decida.'
                                : 'El comprobante se rechazó y el mapa se mantuvo.'),
                'firma'     => 'codigo_proveedor|' . $codigo . '|' . $propuesto,
                'ref_tabla' => 'proveedor_codigo_erp',
                'ref_clave' => (string) $codigo,
                'datos'     => [
                    'codigo'          => $codigo,
                    'actual_id'       => $fila['proveedor_id'],
                    'actual_nombre'   => $actual,
                    'actual_cedula'   => $fila['cedula'],
                    'actual_veces'    => $fila['veces'],
                    'propuesto_id'    => $propuesto,
                    'propuesto_nombre'=> $nuevo,
                    'en_disputa'      => $disputa,
                ],
            ]);
        } catch (Throwable $e) {
            // El veto ya quedó anotado; el aviso es un extra.
        }
    }

    private function nombresDe(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        try {
            $filas = $this->fetchAll(
                'SELECT id, razon_social FROM proveedores WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $mapa = [];
        foreach ($filas as $f) {
            $mapa[(int) $f['id']] = (string) $f['razon_social'];
        }
        return $mapa;
    }

    /** Los vetos anotados para un código, lo más nuevo arriba. */
    public function conflictosDe($codigo, $limite = 20)
    {
        try {
            return $this->fetchAll(
                'SELECT c.*, pm.razon_social AS nombre_mapa, pp.razon_social AS nombre_propuesto,
                        e.documento, e.monto, x.total AS xml_total
                   FROM proveedor_codigo_conflictos c
                   LEFT JOIN proveedores pm ON pm.id = c.proveedor_id_mapa
                   LEFT JOIN proveedores pp ON pp.id = c.proveedor_id_propuesto
                   LEFT JOIN facturas_erp e ON e.id = c.factura_erp_id
                   LEFT JOIN facturas_xml x ON x.id = c.factura_xml_id
                  WHERE c.proveedor_codigo = ?
                  ORDER BY c.visto_en DESC
                  LIMIT ' . max(1, (int) $limite),
                [(string) $codigo]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Decisión humana ────────────────────────────────────────────────

    /**
     * Una persona dice a qué proveedor pertenece este código.
     *
     * Manda sobre cualquier contador: 72 confirmaciones automáticas no le ganan
     * a alguien que sabe que el código cambió de dueño. Queda con
     * `origen = 'manual'`, y desde entonces la cosecha no lo vuelve a tocar.
     */
    public function confirmarManual($codigo, $proveedorId, $usuarioId = 0)
    {
        $codigo = trim((string) $codigo);
        $proveedorId = (int) $proveedorId;
        if ($codigo === '' || $proveedorId <= 0) {
            return false;
        }

        try {
            $proveedor = $this->fetchOne(
                'SELECT id, rfc, razon_social FROM proveedores WHERE id = ? LIMIT 1',
                [$proveedorId]
            );
            if (!$proveedor) {
                return false;
            }

            $this->execute(
                "INSERT INTO proveedor_codigo_erp
                    (proveedor_codigo, proveedor_id, cedula, nombre_erp, veces_confirmado,
                     origen, en_disputa, confirmado_por)
                 VALUES (?, ?, ?, ?, 1, 'manual', 0, ?)
                 ON DUPLICATE KEY UPDATE
                    proveedor_id   = VALUES(proveedor_id),
                    cedula         = VALUES(cedula),
                    origen         = 'manual',
                    en_disputa     = 0,
                    confirmado_por = VALUES(confirmado_por)",
                [
                    $codigo, $proveedorId, (string) ($proveedor['rfc'] ?? ''),
                    (string) ($proveedor['razon_social'] ?? ''),
                    (int) $usuarioId > 0 ? (int) $usuarioId : null,
                ]
            );

            self::olvidarMapa();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Deja de vetar por este código sin decidir de quién es.
     *
     * Es la salida para "no sé todavía, pero no quiero que siga bloqueando".
     */
    public function ponerEnDisputa($codigo, $enDisputa = true)
    {
        try {
            $filas = (int) $this->execute(
                'UPDATE proveedor_codigo_erp SET en_disputa = ? WHERE proveedor_codigo = ?',
                [$enDisputa ? 1 : 0, (string) $codigo]
            );
            self::olvidarMapa();
            return $filas > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Candidatos para el desplegable de confirmación manual. */
    public function proveedoresSugeridos($codigo, $limite = 12)
    {
        try {
            return $this->fetchAll(
                "SELECT p.id, p.rfc, p.razon_social, COUNT(*) AS veces
                   FROM facturas_erp e
                   JOIN facturas_xml f ON f.id = e.factura_xml_id
                   JOIN proveedores  p ON p.id = f.proveedor_id
                  WHERE e.proveedor_codigo = ? AND p.rfc <> ''
                  GROUP BY p.id, p.rfc, p.razon_social
                  ORDER BY veces DESC
                  LIMIT " . max(1, (int) $limite),
                [(string) $codigo]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
