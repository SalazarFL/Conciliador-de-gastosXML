<?php
/**
 * La bandeja de líneas que un listado no supo leer.
 *
 * Antes, una fila del reporte que no encajaba en ningún patrón se descartaba
 * con un `continue` y no dejaba rastro: no se importaba, no se contaba y no
 * salía en ninguna pantalla. Una factura de dos millones perdida por un pie
 * de página mal alineado se veía exactamente igual que no perder nada.
 *
 * Ahora cada una de esas filas queda aquí, con sus celdas crudas, para que
 * alguien decida: corregirla y meterla al listado como una línea más, o
 * descartarla. Las dos decisiones se recuerdan por la firma de la línea, así
 * que la carga de la semana siguiente no vuelve a preguntar lo mismo.
 *
 * Una sola clase sirve a los dos módulos porque el mecanismo es idéntico y
 * duplicarlo garantizaría que dentro de un año se comporten distinto. Lo que
 * cambia entre uno y otro —qué campos tiene una línea y cómo se inserta en su
 * listado— vive en el modelo de cada módulo, no aquí.
 */

require_once __DIR__ . '/../helpers/LineaRevision.php';

class LineaEnRevision extends Model
{
    /** Los módulos que tienen bandeja, y el prefijo de sus tablas. */
    const MODULOS = ['facturas_erp', 'notas_credito'];

    private $modulo;

    public function __construct($modulo)
    {
        $modulo = (string) $modulo;
        if (!in_array($modulo, self::MODULOS, true)) {
            throw new InvalidArgumentException('Módulo sin bandeja de revisión: ' . $modulo);
        }
        $this->modulo = $modulo;
        $this->table = $modulo . '_revision';

        Esquema::unaVez(static::class . ':' . $modulo, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        $revision = $this->tabla();
        $memoria = $this->tablaMemoria();

        $this->execute("CREATE TABLE IF NOT EXISTS {$revision} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sociedad_id INT UNSIGNED NOT NULL,
            carga_id INT UNSIGNED NULL,
            contenedor_id INT UNSIGNED NULL,
            fila_origen INT UNSIGNED NOT NULL DEFAULT 0,
            firma VARCHAR(40) NOT NULL,
            -- Qué líneas son el mismo enredo. Vacío en la mayoría: una línea
            -- ilegible se resuelve sola y no tiene con quién agruparse. Las
            -- que comparten identidad, en cambio, no se pueden decidir de a
            -- una —elegir cuál entra exige ver a las otras— y llevan acá la
            -- clave que las choca, que es lo que las junta en pantalla.
            grupo VARCHAR(190) NOT NULL DEFAULT '',
            motivo VARCHAR(400) NOT NULL DEFAULT '',
            celdas LONGTEXT NULL,
            campos LONGTEXT NULL,
            estado ENUM('pendiente','incluida','descartada') NOT NULL DEFAULT 'pendiente',
            resultado_id INT UNSIGNED NULL,
            nota VARCHAR(255) NULL,
            resuelta_en DATETIME NULL,
            resuelta_por INT UNSIGNED NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rev_pendientes (sociedad_id, estado, id),
            KEY idx_rev_carga (carga_id),
            KEY idx_rev_firma (firma),
            KEY idx_rev_grupo (sociedad_id, estado, grupo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->ponerseAlDia();

        // Lo que ya se decidió una vez. Sin esta tabla, recordar no serviría
        // de nada: el reporte se vuelve a subir completo cada semana y la
        // misma línea rota volvería a parar en la bandeja indefinidamente.
        $this->execute("CREATE TABLE IF NOT EXISTS {$memoria} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sociedad_id INT UNSIGNED NOT NULL,
            firma VARCHAR(40) NOT NULL,
            accion ENUM('incluir','descartar') NOT NULL,
            campos LONGTEXT NULL,
            celdas LONGTEXT NULL,
            mapa LONGTEXT NULL,
            motivo VARCHAR(255) NULL,
            veces_aplicada INT UNSIGNED NOT NULL DEFAULT 0,
            usuario_id INT UNSIGNED NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_rev_memoria (sociedad_id, firma)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * La bandeja es anterior a los enredos: en las bases ya instaladas hay que
     * agregarle la columna que los agrupa. Sin ella, las líneas gemelas se
     * verían como líneas sueltas y volvería a poder decidirse cada una por su
     * lado, que es justo lo que no se puede hacer con ellas.
     */
    private function ponerseAlDia()
    {
        try {
            $tiene = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                    AND COLUMN_NAME = 'grupo'",
                [$this->tabla()]
            );
            if ($tiene === 0) {
                $this->execute(
                    "ALTER TABLE {$this->tabla()}
                       ADD COLUMN grupo VARCHAR(190) NOT NULL DEFAULT '' AFTER firma,
                       ADD KEY idx_rev_grupo (sociedad_id, estado, grupo)"
                );
            }
        } catch (Throwable $e) {
            // Sin permisos de DDL la bandeja sigue funcionando como antes.
        }
    }

    private function tabla() { return $this->modulo . '_revision'; }
    private function tablaMemoria() { return $this->modulo . '_revision_memoria'; }

    // ------------------------------------------------------------------
    // Lo que decide la carga
    // ------------------------------------------------------------------

    /**
     * Reparte las líneas que el parser no pudo leer entre las tres cosas que
     * les pueden pasar: entrar solas porque ya hay una corrección guardada,
     * descartarse solas porque ya se dijo que no van, o quedar preguntando.
     *
     * No escribe nada: devuelve el reparto para que la carga lo aplique
     * dentro de su propia transacción. Así una carga que falla a la mitad no
     * deja media bandeja escrita.
     */
    public function repartir($sociedadId, array $lineas)
    {
        $memoria = $this->memoriaPorFirma($sociedadId);

        $corregidas = [];   // entran al listado con la corrección recordada
        $descartadas = [];  // no entran, ya se dijo que no
        $pendientes = [];   // hay que preguntar

        foreach ($lineas as $linea) {
            $firma = (string) ($linea['firma'] ?? '');
            $recordado = $firma !== '' ? ($memoria[$firma] ?? null) : null;

            if ($recordado === null) {
                $pendientes[] = $linea;
                continue;
            }

            if ($recordado['accion'] === 'descartar') {
                $descartadas[] = $linea;
                continue;
            }

            $campos = LineaRevision::reaplicar($recordado, (array) ($linea['celdas'] ?? []));
            if ($campos === null) {
                // La línea cambió de forma: la regla guardada apuntaría a una
                // columna que ya no es la misma. Mejor volver a preguntar que
                // escribir en el listado un dato sacado del lugar equivocado.
                $linea['motivo'] = 'La corrección guardada ya no calza con esta línea: cambió de forma. '
                    . $linea['motivo'];
                $pendientes[] = $linea;
                continue;
            }

            $linea['campos'] = $campos;
            $linea['memoria_id'] = (int) $recordado['id'];
            $corregidas[] = $linea;
        }

        return [
            'corregidas' => $corregidas,
            'descartadas' => $descartadas,
            'pendientes' => $pendientes,
        ];
    }

    /** Deja en la bandeja las líneas que hay que preguntar. */
    public function registrarPendientes($sociedadId, array $lineas, $cargaId = null, $contenedorId = null)
    {
        if (!$lineas) {
            return 0;
        }

        // Una firma que ya está preguntando no se duplica: el reporte se sube
        // entero cada semana y la bandeja se llenaría de copias de la misma
        // línea, todas esperando la misma respuesta.
        $yaPreguntando = [];
        foreach ($this->fetchAll(
            "SELECT firma FROM {$this->tabla()} WHERE sociedad_id = ? AND estado = 'pendiente'",
            [(int) $sociedadId]
        ) ?: [] as $fila) {
            $yaPreguntando[(string) $fila['firma']] = true;
        }

        $insertadas = 0;
        foreach ($lineas as $linea) {
            $firma = (string) ($linea['firma'] ?? '');
            if ($firma !== '' && isset($yaPreguntando[$firma])) {
                continue;
            }
            $yaPreguntando[$firma] = true;

            $this->execute(
                "INSERT INTO {$this->tabla()}
                    (sociedad_id, carga_id, contenedor_id, fila_origen, firma, grupo, motivo, celdas, campos)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int) $sociedadId,
                    $cargaId !== null ? (int) $cargaId : null,
                    $contenedorId !== null ? (int) $contenedorId : null,
                    (int) ($linea['fila_origen'] ?? 0),
                    $firma,
                    mb_substr((string) ($linea['grupo'] ?? ''), 0, 190),
                    mb_substr((string) ($linea['motivo'] ?? ''), 0, 400),
                    self::json($linea['celdas'] ?? []),
                    self::json($linea['campos'] ?? []),
                ]
            );
            $insertadas++;
        }

        return $insertadas;
    }

    /**
     * Retira los enredos que la carga nueva ya no trae.
     *
     * Es la salida por el otro lado: un enredo se puede resolver decidiendo, o
     * arreglándolo en el ERP y volviendo a exportar el reporte. Sin esto, la
     * segunda no serviría de nada —las filas viejas seguirían pendientes y
     * seguirían cerrando el listado— y peor: decidir sobre ellas metería al
     * listado una factura que el reporte de hoy ya no tiene así.
     *
     * Solo alcanza a los enredos. Una línea ilegible que deja de venir se
     * queda esperando a propósito: es un pendiente, no un bloqueo, y borrarla
     * porque el reporte cambió sería perder la pregunta sin contestarla.
     *
     * @param array $firmasPresentes Las firmas que sí trae la carga de ahora.
     * @return int Cuántos enredos se retiraron.
     */
    public function retirarEnredosAusentes($sociedadId, array $firmasPresentes)
    {
        $params = [(int) $sociedadId];
        $sql = "UPDATE {$this->tabla()}
                   SET estado = 'descartada',
                       nota = 'El reporte dejó de traer estas filas: el enredo se arregló en el origen.',
                       resuelta_en = NOW()
                 WHERE sociedad_id = ? AND estado = 'pendiente' AND grupo <> ''";

        $firmas = array_values(array_unique(array_filter(array_map('strval', $firmasPresentes))));
        if ($firmas) {
            $sql .= ' AND firma NOT IN (' . implode(',', array_fill(0, count($firmas), '?')) . ')';
            $params = array_merge($params, $firmas);
        }

        try {
            return (int) $this->execute($sql, $params);
        } catch (Throwable $e) {
            // Que no se pueda limpiar no puede tumbar una carga que ya entró.
            return 0;
        }
    }

    /** Anota que una corrección recordada volvió a usarse. */
    public function marcarMemoriaAplicada(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }
        $this->execute(
            "UPDATE {$this->tablaMemoria()} SET veces_aplicada = veces_aplicada + 1
              WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
    }

    // ------------------------------------------------------------------
    // Lo que decide la persona
    // ------------------------------------------------------------------

    /**
     * Marca una línea como resuelta. La inserción en el listado la hace el
     * modelo del módulo: esta clase no sabe qué es una factura.
     */
    public function marcarIncluida($id, array $campos, $resultadoId, $usuarioId = null)
    {
        $this->execute(
            "UPDATE {$this->tabla()}
                SET estado = 'incluida', campos = ?, resultado_id = ?,
                    resuelta_en = NOW(), resuelta_por = ?
              WHERE id = ?",
            [self::json($campos), (int) $resultadoId, $usuarioId !== null ? (int) $usuarioId : null, (int) $id]
        );
    }

    public function marcarDescartada($id, $nota = '', $usuarioId = null)
    {
        $this->execute(
            "UPDATE {$this->tabla()}
                SET estado = 'descartada', nota = ?, resuelta_en = NOW(), resuelta_por = ?
              WHERE id = ?",
            [mb_substr((string) $nota, 0, 255) ?: null, $usuarioId !== null ? (int) $usuarioId : null, (int) $id]
        );
    }

    /**
     * Guarda la decisión para las cargas siguientes.
     *
     * El mapa de campos es lo que la vuelve reutilizable: sin él, recordar
     * una inclusión repondría cada semana el saldo del día en que se corrigió.
     */
    public function recordar($sociedadId, array $linea, $accion, array $campos = [], $motivo = '', $usuarioId = null)
    {
        $firma = (string) ($linea['firma'] ?? '');
        if ($firma === '') {
            return 0;
        }

        $celdas = is_array($linea['celdas'] ?? null) ? $linea['celdas'] : [];
        $mapa = $accion === 'incluir' ? LineaRevision::mapaDeCampos($campos, $celdas) : [];

        $this->execute(
            "INSERT INTO {$this->tablaMemoria()}
                (sociedad_id, firma, accion, campos, celdas, mapa, motivo, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                accion = VALUES(accion), campos = VALUES(campos), celdas = VALUES(celdas),
                mapa = VALUES(mapa), motivo = VALUES(motivo), usuario_id = VALUES(usuario_id)",
            [
                (int) $sociedadId,
                $firma,
                $accion === 'descartar' ? 'descartar' : 'incluir',
                self::json($campos),
                self::json($celdas),
                self::json($mapa),
                mb_substr((string) $motivo, 0, 255) ?: null,
                $usuarioId !== null ? (int) $usuarioId : null,
            ]
        );

        return 1;
    }

    /** Olvida una decisión: la línea vuelve a preguntarse en la próxima carga. */
    public function olvidar($sociedadId, $memoriaId)
    {
        $this->execute(
            "DELETE FROM {$this->tablaMemoria()} WHERE id = ? AND sociedad_id = ?",
            [(int) $memoriaId, (int) $sociedadId]
        );
    }

    // ------------------------------------------------------------------
    // Consultas de pantalla
    // ------------------------------------------------------------------

    /**
     * Las líneas sueltas que esperan decisión. Los enredos quedan fuera: se
     * resuelven en grupo y tienen su propia consulta.
     *
     * "Suelta" incluye a la que se quedó sola en su grupo. Un enredo del que
     * ya se resolvieron las demás dejó de ser un enredo: no hay entre qué
     * elegir. Sin esta salida esa línea no saldría ni acá ni entre los
     * enredos —que piden dos— y quedaría invisible, pendiente para siempre y
     * con el listado cerrado por algo que nadie podría ver.
     */
    public function pendientes($sociedadId, $limite = 200)
    {
        return $this->decodificar($this->fetchAll(
            "SELECT x.* FROM {$this->tabla()} x
              WHERE x.sociedad_id = ? AND x.estado = 'pendiente'
                AND (x.grupo = '' OR (
                        SELECT COUNT(*) FROM {$this->tabla()} y
                         WHERE y.sociedad_id = x.sociedad_id
                           AND y.estado = 'pendiente' AND y.grupo = x.grupo
                    ) < 2)
              ORDER BY x.id ASC LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: []);
    }

    public function contarPendientes($sociedadId)
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tabla()} WHERE sociedad_id = ? AND estado = 'pendiente'",
            [(int) $sociedadId]
        );
    }

    /**
     * Los enredos sin resolver, cada uno con todas sus líneas juntas.
     *
     * Van aparte de `pendientes()` porque no se deciden igual: una línea
     * ilegible es una pregunta que se contesta sola, y un enredo es una
     * pregunta sobre varias filas a la vez —cuál de estas entra— que no tiene
     * sentido plantear de a una.
     *
     * @return array grupo => lista de líneas
     */
    public function enredosPendientes($sociedadId, $limite = 200)
    {
        $filas = $this->decodificar($this->fetchAll(
            "SELECT * FROM {$this->tabla()}
              WHERE sociedad_id = ? AND estado = 'pendiente' AND grupo <> ''
              ORDER BY grupo ASC, fila_origen ASC, id ASC LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: []);

        // Un grupo con una sola línea ya no es un enredo: no hay entre qué
        // elegir. Sale por `pendientes()` como la línea suelta que quedó.
        $porGrupo = [];
        foreach ($filas as $fila) {
            $porGrupo[(string) $fila['grupo']][] = $fila;
        }
        return array_filter($porGrupo, function ($lineas) { return count($lineas) > 1; });
    }

    /** Cuántos enredos distintos están esperando una decisión. */
    public function contarEnredos($sociedadId)
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM (
                 SELECT grupo FROM {$this->tabla()}
                  WHERE sociedad_id = ? AND estado = 'pendiente' AND grupo <> ''
                  GROUP BY grupo HAVING COUNT(*) > 1
             ) g",
            [(int) $sociedadId]
        );
    }

    /** Las líneas de un enredo, para resolverlo entero de una sola vez. */
    public function lineasDelGrupo($sociedadId, $grupo)
    {
        $grupo = trim((string) $grupo);
        if ($grupo === '') {
            return [];
        }
        return $this->decodificar($this->fetchAll(
            "SELECT * FROM {$this->tabla()}
              WHERE sociedad_id = ? AND estado = 'pendiente' AND grupo = ?
              ORDER BY fila_origen ASC, id ASC",
            [(int) $sociedadId, $grupo]
        ) ?: []);
    }

    public function resueltas($sociedadId, $limite = 100)
    {
        return $this->decodificar($this->fetchAll(
            "SELECT * FROM {$this->tabla()}
              WHERE sociedad_id = ? AND estado <> 'pendiente'
              ORDER BY resuelta_en DESC, id DESC LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: []);
    }

    public function buscar($sociedadId, $id)
    {
        $fila = $this->fetchOne(
            "SELECT * FROM {$this->tabla()} WHERE id = ? AND sociedad_id = ?",
            [(int) $id, (int) $sociedadId]
        );
        if (!$fila) {
            return null;
        }
        $filas = $this->decodificar([$fila]);
        return $filas[0];
    }

    /** Las decisiones recordadas, para poder verlas y deshacerlas. */
    public function memoria($sociedadId, $limite = 200)
    {
        $filas = $this->fetchAll(
            "SELECT * FROM {$this->tablaMemoria()} WHERE sociedad_id = ?
              ORDER BY actualizado_en DESC, id DESC LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: [];

        foreach ($filas as $i => $fila) {
            $filas[$i]['campos'] = self::desdeJson($fila['campos'] ?? null);
            $filas[$i]['celdas'] = self::desdeJson($fila['celdas'] ?? null);
            $filas[$i]['mapa'] = self::desdeJson($fila['mapa'] ?? null);
        }
        return $filas;
    }

    private function memoriaPorFirma($sociedadId)
    {
        $porFirma = [];
        foreach ($this->fetchAll(
            "SELECT * FROM {$this->tablaMemoria()} WHERE sociedad_id = ?",
            [(int) $sociedadId]
        ) ?: [] as $fila) {
            $porFirma[(string) $fila['firma']] = [
                'id' => (int) $fila['id'],
                'accion' => (string) $fila['accion'],
                'campos' => self::desdeJson($fila['campos'] ?? null),
                'celdas' => self::desdeJson($fila['celdas'] ?? null),
                'mapa' => self::desdeJson($fila['mapa'] ?? null),
            ];
        }
        return $porFirma;
    }

    private function decodificar(array $filas)
    {
        foreach ($filas as $i => $fila) {
            $filas[$i]['celdas'] = self::desdeJson($fila['celdas'] ?? null);
            $filas[$i]['campos'] = self::desdeJson($fila['campos'] ?? null);
        }
        return $filas;
    }

    private static function json($valor)
    {
        return json_encode(
            $valor === null ? [] : $valor,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private static function desdeJson($crudo)
    {
        $valor = json_decode((string) $crudo, true);
        return is_array($valor) ? $valor : [];
    }
}
