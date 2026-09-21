<?php
/**
 * El paso a paso de pasar los documentos de una empresa a SharePoint.
 *
 * Una fila por archivo (tabla, fila, campo), con su estado:
 *
 *   inventariado → subido → verificado → reescrito
 *                                          └→ revertido (si se vuelve atrás)
 *   omitido    no estaba en el disco al inventariar: ya estaba roto, no frena
 *   conflicto  en SharePoint ya hay OTRA cosa con ese nombre, o el archivo del
 *              disco no es el que la base archivó: hay que mirarlo
 *   error      falló la subida demasiadas veces, o la verificación no cuadró
 *
 * En la base y no en un archivo: la migración de una empresa son horas, puede
 * correr desde cualquier PC y tiene que sobrevivir a que la maten a mitad.
 * Retomar es pedir los que siguen en su estado.
 *
 * Ver app/helpers/MigracionGraph.php.
 */
class AlmacenMigracion extends Model
{
    protected $table = 'almacen_migracion';

    /** Los que todavía no terminaron: impiden cortar la empresa. */
    public const PENDIENTES = ['inventariado', 'subido', 'verificado', 'error', 'conflicto'];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sociedad_id` INT UNSIGNED NOT NULL,
                `tabla` VARCHAR(30) NOT NULL,
                `fila_id` INT UNSIGNED NOT NULL,
                `campo` VARCHAR(20) NOT NULL,
                `ruta` VARCHAR(700) NOT NULL,
                `sha256` CHAR(64) NULL DEFAULT NULL,
                `tamano` BIGINT UNSIGNED NULL DEFAULT NULL,
                `estado` VARCHAR(15) NOT NULL DEFAULT 'inventariado',
                `item_id` VARCHAR(128) NULL DEFAULT NULL,
                `etag` VARCHAR(128) NULL DEFAULT NULL,
                `intentos` INT UNSIGNED NOT NULL DEFAULT 0,
                `error` VARCHAR(500) NULL DEFAULT NULL,
                `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migracion_archivo` (`tabla`, `fila_id`, `campo`),
                KEY `idx_migracion_estado` (`sociedad_id`, `estado`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // Sin permisos de DDL: la crea database/migration_almacen_graph.sql.
        }
    }

    // ── Lo que hay que migrar ─────────────────────────────────────────────

    /**
     * Los documentos de la empresa que siguen en disco, tal como están
     * guardados: sin pasar por la hidratación, que es justo lo que se quiere
     * cambiar.
     */
    public function documentosEnDisco($sociedadId)
    {
        $sociedadId = (int) $sociedadId;
        $archivos = [];
        foreach ($this->fetchAll(
            "SELECT id, ruta_xml, ruta_pdf, hash_xml, hash_pdf FROM facturas_xml
              WHERE sociedad_id = ? AND almacen IS NULL
                AND (COALESCE(ruta_xml, '') <> '' OR COALESCE(ruta_pdf, '') <> '')
              ORDER BY id",
            [$sociedadId]
        ) ?: [] as $f) {
            foreach (['ruta_xml' => 'hash_xml', 'ruta_pdf' => 'hash_pdf'] as $campo => $hash) {
                if (trim((string) $f[$campo]) !== '') {
                    $archivos[] = ['tabla' => 'facturas_xml', 'fila_id' => (int) $f['id'], 'campo' => $campo,
                                   'ruta' => (string) $f[$campo], 'sha256' => (string) $f[$hash]];
                }
            }
        }
        // El PDF de una devolución vive años: entra igual que una factura.
        foreach ($this->fetchAll(
            "SELECT id, ruta_pdf, hash_pdf FROM devoluciones
              WHERE sociedad_id = ? AND almacen IS NULL AND COALESCE(ruta_pdf, '') <> '' ORDER BY id",
            [$sociedadId]
        ) ?: [] as $d) {
            $archivos[] = ['tabla' => 'devoluciones', 'fila_id' => (int) $d['id'], 'campo' => 'ruta_pdf',
                           'ruta' => (string) $d['ruta_pdf'], 'sha256' => (string) $d['hash_pdf']];
        }
        return $archivos;
    }

    /**
     * Anota un archivo en el inventario. Si ya estaba, solo se pone al día lo
     * que todavía no empezó (o lo que se revirtió): lo subido no se toca.
     */
    public function inventariar($sociedadId, array $a, $estado, $tamano, $error = null)
    {
        return $this->execute(
            "INSERT INTO {$this->table} (sociedad_id, tabla, fila_id, campo, ruta, sha256, tamano, estado, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 ruta     = IF(estado IN ('inventariado', 'omitido', 'revertido'), VALUES(ruta), ruta),
                 sha256   = IF(estado IN ('inventariado', 'omitido', 'revertido'), VALUES(sha256), sha256),
                 tamano   = IF(estado IN ('inventariado', 'omitido', 'revertido'), VALUES(tamano), tamano),
                 error    = IF(estado IN ('inventariado', 'omitido', 'revertido'), VALUES(error), error),
                 intentos = IF(estado IN ('omitido', 'revertido'), 0, intentos),
                 estado   = IF(estado IN ('inventariado', 'omitido', 'revertido'), VALUES(estado), estado)",
            [(int) $sociedadId, $a['tabla'], (int) $a['fila_id'], $a['campo'], $a['ruta'],
             $a['sha256'] !== '' ? strtolower($a['sha256']) : null, $tamano, $estado, $error]
        );
    }

    public function enEstado($sociedadId, $estado, $limite = 0)
    {
        $sql = "SELECT * FROM {$this->table} WHERE sociedad_id = ? AND estado = ? ORDER BY id";
        if ((int) $limite > 0) {
            $sql .= ' LIMIT ' . (int) $limite;
        }
        return $this->fetchAll($sql, [(int) $sociedadId, (string) $estado]) ?: [];
    }

    /** Todo lo anotado de la empresa, en cualquier estado. */
    public function todos($sociedadId)
    {
        return $this->fetchAll("SELECT * FROM {$this->table} WHERE sociedad_id = ?", [(int) $sociedadId]) ?: [];
    }

    /** Unos cuantos al azar, para la verificación que sí baja el contenido. */
    public function muestra($sociedadId, $estado, $cuantos)
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE sociedad_id = ? AND estado = ? ORDER BY RAND() LIMIT " . max(1, (int) $cuantos),
            [(int) $sociedadId, (string) $estado]
        ) ?: [];
    }

    public function porFila($sociedadId, $tabla, $filaId)
    {
        return $this->fetchAll(
            "SELECT * FROM {$this->table} WHERE sociedad_id = ? AND tabla = ? AND fila_id = ?",
            [(int) $sociedadId, (string) $tabla, (int) $filaId]
        ) ?: [];
    }

    public function marcar($id, $estado, array $datos = [])
    {
        $sets = ['estado = ?'];
        $params = [(string) $estado];
        foreach (['item_id', 'etag', 'sha256', 'tamano', 'error'] as $columna) {
            if (array_key_exists($columna, $datos)) {
                $sets[] = $columna . ' = ?';
                $params[] = $columna === 'error' && $datos[$columna] !== null
                    ? mb_substr((string) $datos[$columna], 0, 500, 'UTF-8')
                    : $datos[$columna];
            }
        }
        if (!empty($datos['intento'])) {
            $sets[] = 'intentos = intentos + 1';
        }
        if (!empty($datos['reiniciar_intentos'])) {
            $sets[] = 'intentos = 0';
        }
        $params[] = (int) $id;
        return $this->execute("UPDATE {$this->table} SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * Las filas listas para reescribir: todo lo suyo verificado (o omitido
     * porque ya no estaba), y al menos un archivo que pasar.
     *
     * @return array [['tabla' => …, 'fila_id' => …], …]
     */
    public function filasListas($sociedadId, $limite = 200)
    {
        return $this->fetchAll(
            "SELECT tabla, fila_id FROM {$this->table} WHERE sociedad_id = ?
              GROUP BY tabla, fila_id
             HAVING SUM(estado = 'verificado') > 0
                AND SUM(estado NOT IN ('verificado', 'omitido', 'reescrito')) = 0
              ORDER BY tabla, fila_id LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: [];
    }

    /** estado => cantidad */
    public function resumen($sociedadId)
    {
        $cuenta = [];
        foreach ($this->fetchAll(
            "SELECT estado, COUNT(*) AS n FROM {$this->table} WHERE sociedad_id = ? GROUP BY estado",
            [(int) $sociedadId]
        ) ?: [] as $fila) {
            $cuenta[$fila['estado']] = (int) $fila['n'];
        }
        return $cuenta;
    }

    public function cuantosPendientes($sociedadId)
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE sociedad_id = ? AND estado IN ('"
            . implode("','", self::PENDIENTES) . "')",
            [(int) $sociedadId]
        );
    }

    /** Lo que falló, para volver a intentarlo cuando alguien lo miró. */
    public function reintentar($sociedadId)
    {
        return $this->execute(
            "UPDATE {$this->table} SET estado = 'inventariado', intentos = 0, error = NULL
              WHERE sociedad_id = ? AND estado IN ('error', 'conflicto')",
            [(int) $sociedadId]
        );
    }

    /** Los que fallaron, con su motivo, para mostrarlos. */
    public function fallidos($sociedadId, $limite = 50)
    {
        return $this->fetchAll(
            "SELECT tabla, fila_id, campo, ruta, estado, error FROM {$this->table}
              WHERE sociedad_id = ? AND estado IN ('error', 'conflicto', 'omitido')
              ORDER BY estado, id LIMIT " . max(1, (int) $limite),
            [(int) $sociedadId]
        ) ?: [];
    }

    // ── Escribir en las tablas de documentos ─────────────────────────────

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        return self::getDB()->inTransaction() ? self::getDB()->rollBack() : true;
    }

    /**
     * Pasa una fila a SharePoint: `almacen`, los ids y el prefijo de la ruta.
     * La ruta en la biblioteca es la misma relativa de siempre; lo que cambia
     * es que se guarda con `sharepoint:` delante, porque una ruta sin prefijo
     * es de disco (ver AlmacenDocumentos, "Cuál se usa"). Volver atrás es
     * devolverFila(), que pone la ruta del inventario, sin prefijo.
     *
     * Solo si la fila sigue en disco y con las mismas rutas que se
     * inventariaron: si el organizador la movió entretanto, lo subido ya no es
     * lo que la fila dice, y no se toca.
     *
     * @param array $archivos campo => ['ruta', 'item_id', 'etag']
     * @return bool si se pasó
     */
    public function pasarFila($tabla, $filaId, array $archivos)
    {
        $sets = ["almacen = 'sharepoint'"];
        $setParams = [];
        $where = ['id = ?', 'almacen IS NULL'];
        $whereParams = [(int) $filaId];
        foreach ($archivos as $campo => $a) {
            $ext = $campo === 'ruta_pdf' ? 'pdf' : 'xml';
            if ($tabla === 'devoluciones' && $ext !== 'pdf') {
                continue;
            }
            $sets[] = "{$campo} = ?";
            $setParams[] = 'sharepoint:' . RutaDocumento::relativa((string) $a['ruta']);
            $sets[] = "item_{$ext}_id = ?";
            $setParams[] = (string) $a['item_id'] ?: null;
            if ($tabla === 'facturas_xml') {
                $sets[] = "etag_{$ext} = ?";
                $setParams[] = (string) $a['etag'] ?: null;
            }
            $where[] = "{$campo} = ?";
            $whereParams[] = (string) $a['ruta'];
        }
        return $this->execute(
            'UPDATE ' . $this->tablaDocumentos($tabla) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . implode(' AND ', $where),
            array_merge($setParams, $whereParams)
        ) > 0;
    }

    /**
     * La vuelta a disco de una fila: `almacen` en NULL, sin ids, y cada campo
     * con la ruta de disco que se le da (la del inventario, o la de lo que se
     * bajó).
     *
     * @param array $rutas campo => ruta relativa de disco
     */
    public function devolverFila($tabla, $filaId, array $rutas)
    {
        $sets = ['almacen = NULL'];
        $params = [];
        if ($tabla === 'facturas_xml') {
            $sets = array_merge($sets, ['item_xml_id = NULL', 'item_pdf_id = NULL', 'etag_xml = NULL', 'etag_pdf = NULL']);
        } else {
            $sets[] = 'item_pdf_id = NULL';
        }
        foreach ($rutas as $campo => $ruta) {
            $sets[] = "{$campo} = ?";
            $params[] = (string) $ruta;
            $sets[] = ($campo === 'ruta_pdf' ? 'archivo_pdf' : 'archivo_xml') . ' = ?';
            $params[] = basename(str_replace('\\', '/', (string) $ruta));
        }
        $params[] = (int) $filaId;
        return $this->execute('UPDATE ' . $this->tablaDocumentos($tabla) . ' SET ' . implode(', ', $sets)
            . ' WHERE id = ?', $params);
    }

    /**
     * Los documentos de la empresa que hoy se leen de SharePoint: los pasados
     * y los que se archivaron allá después del corte.
     */
    public function documentosEnNube($sociedadId)
    {
        $filas = [];
        foreach ($this->fetchAll(
            "SELECT 'facturas_xml' AS tabla, id, ruta_xml, ruta_pdf, almacen FROM facturas_xml
              WHERE sociedad_id = ? AND (almacen IS NOT NULL OR ruta_xml LIKE 'sharepoint:%' OR ruta_pdf LIKE 'sharepoint:%')
             UNION ALL
             SELECT 'devoluciones', id, NULL, ruta_pdf, almacen FROM devoluciones
              WHERE sociedad_id = ? AND (almacen IS NOT NULL OR ruta_pdf LIKE 'sharepoint:%')",
            [(int) $sociedadId, (int) $sociedadId]
        ) ?: [] as $f) {
            $filas[] = $f;
        }
        return $filas;
    }

    public function marcarRevertidos($sociedadId, $tabla, $filaId)
    {
        return $this->execute(
            "UPDATE {$this->table} SET estado = 'revertido' WHERE sociedad_id = ? AND tabla = ? AND fila_id = ?
                AND estado IN ('reescrito', 'verificado', 'subido')",
            [(int) $sociedadId, (string) $tabla, (int) $filaId]
        );
    }

    private function tablaDocumentos($tabla)
    {
        if (!in_array($tabla, ['facturas_xml', 'devoluciones'], true)) {
            throw new InvalidArgumentException('Tabla de documentos desconocida: ' . $tabla);
        }
        return $tabla;
    }
}
