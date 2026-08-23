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
            KEY idx_rev_firma (firma)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
                    (sociedad_id, carga_id, contenedor_id, fila_origen, firma, motivo, celdas, campos)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int) $sociedadId,
                    $cargaId !== null ? (int) $cargaId : null,
                    $contenedorId !== null ? (int) $contenedorId : null,
                    (int) ($linea['fila_origen'] ?? 0),
                    $firma,
                    mb_substr((string) ($linea['motivo'] ?? ''), 0, 400),
                    self::json($linea['celdas'] ?? []),
                    self::json($linea['campos'] ?? []),
                ]
            );
            $insertadas++;
        }

        return $insertadas;
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

    public function pendientes($sociedadId, $limite = 200)
    {
        return $this->decodificar($this->fetchAll(
            "SELECT * FROM {$this->tabla()}
              WHERE sociedad_id = ? AND estado = 'pendiente'
              ORDER BY id ASC LIMIT " . max(1, (int) $limite),
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
