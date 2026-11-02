<?php
/**
 * Modelo del pago.
 *
 * Un pago es una tanda de facturas que se paga junta: se le carga el archivo
 * que dice cuáles son, se verifica que cada una tenga su comprobante, y sus
 * XML y PDF se copian a una carpeta con su nombre para poder mandarla.
 *
 * ── Por qué ya no se llama «semana» ──
 *
 * Este modelo se llamó Semana mientras se pagó una vez por semana. Pero un
 * pago puede ser de un solo día, pueden salir tres en siete días, o uno cada
 * quince. Con el nombre viejo había que inventar cosas como «Semana pago
 * 2708» para un pago del jueves, y dos pagos del mismo lunes no se podían
 * distinguir ni ordenar: eran la misma semana.
 *
 * Por eso el pago tiene ahora su propia `fecha_pago`, que es lo que lo
 * identifica —el nombre pasa a ser una etiqueta opcional, para cuando hace
 * falta decir «el urgente» o «el de planilla»—. Cada cuánto se paga dejó de
 * ser un dato del sistema: se ve en las fechas, y puede cambiar sin que haya
 * que tocar nada.
 *
 * ── Por qué acá está también el archivo ──
 *
 * Esto vivía en dos tablas: `pagos` con el nombre y la fecha, y
 * `porpagar_listados` con el archivo que se le cargó. Nunca hubo un pago con
 * dos listados ni un listado sin pago —iban siempre de a uno—, así que eran la
 * misma ficha partida en dos. Se notaba en los nombres: el listado se
 * bautizaba pegándole "Pago " al nombre del pago, y en la base quedaban filas
 * llamadas "Pago Pago Semana 200826".
 *
 * Peor era en las consultas: cada factura del ERP llevaba dos apuntadores
 * —`pago_id` y `porpagar_listado_id`— que decían lo mismo, se escribían juntos
 * y se borraban juntos. Dos formas de decir lo mismo son dos formas de que se
 * contradigan. Ahora es una. Ver database/migrations/007_pago_unico.sql.
 *
 * Los pagos son de cada sociedad, no del grupo: al cambiar de empresa cambia
 * la lista. Ver también database/migrations/006_pagos.sql.
 */

class Pago extends Model
{
    use AlcanceSociedad;

    protected $table = 'pagos';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        // La tabla se llamaba `semanas`. Si esta instalación viene de antes,
        // se renombra acá mismo: es el mismo dato y no hay nada que copiar.
        // La migración 006 hace lo mismo para los servidores donde el usuario
        // de la web no puede ejecutar DDL.
        try {
            $vieja = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'semanas'"
            );
            $nueva = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$this->table]
            );
            if ($vieja === 1 && $nueva === 0) {
                $this->execute("RENAME TABLE `semanas` TO `{$this->table}`");
            }
        } catch (Throwable $e) {
        }

        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
                `nombre` VARCHAR(100) NOT NULL,
                `fecha_pago` DATE NULL DEFAULT NULL,
                `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL,
                `archivo_origen` VARCHAR(255) NULL DEFAULT NULL,
                `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0,
                `archivo_subido_en` DATETIME NULL DEFAULT NULL,
                `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_pagos_sociedad` (`sociedad_id`),
                KEY `idx_pagos_fecha` (`sociedad_id`, `fecha_pago`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
        }

        foreach ([
            'carpeta_pago'      => "ADD COLUMN `carpeta_pago` VARCHAR(100) NULL DEFAULT NULL AFTER `nombre`",
            'fecha_pago'        => "ADD COLUMN `fecha_pago` DATE NULL DEFAULT NULL AFTER `nombre`",
            // Lo que aportaba el listado antes de que fueran una sola ficha.
            'archivo_origen'    => "ADD COLUMN `archivo_origen` VARCHAR(255) NULL DEFAULT NULL AFTER `carpeta_pago`",
            'total_lineas'      => "ADD COLUMN `total_lineas` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `archivo_origen`",
            'archivo_subido_en' => "ADD COLUMN `archivo_subido_en` DATETIME NULL DEFAULT NULL AFTER `total_lineas`",
        ] as $columna => $alter) {
            try {
                if (!$this->fetchOne("SHOW COLUMNS FROM {$this->table} LIKE '{$columna}'")) {
                    $this->execute("ALTER TABLE {$this->table} {$alter}");
                }
            } catch (Throwable $e) {
            }
        }

        // Los pagos de antes de que la fecha existiera: la del día en que se
        // crearon es la mejor que hay, y se cargaban el mismo día del pago.
        try {
            $this->execute(
                "UPDATE {$this->table} SET fecha_pago = DATE(creado_en) WHERE fecha_pago IS NULL"
            );
        } catch (Throwable $e) {
        }

        $this->absorberListados();
    }

    /**
     * Se traga `porpagar_listados`, si todavía está.
     *
     * Es lo mismo que hace database/migrations/007_pago_unico.sql, escrito acá
     * para las instalaciones donde el usuario de la web sí puede ejecutar DDL
     * y nadie va a correr la migración a mano.
     *
     * Todo va dentro de try: si falla —sin permisos, o con la tabla en uso—
     * la aplicación sigue funcionando contra lo que haya, y la migración
     * corrida a mano lo resuelve después. Lo que NO hace es borrar la tabla
     * vieja mientras `porpagar_facturas` tenga filas: esa tabla la referencia
     * con una clave foránea, y quedarse sin el padre le rompería el vínculo.
     */
    private function absorberListados()
    {
        try {
            $existe = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'porpagar_listados'"
            );
            if ($existe === 0) {
                return;
            }

            // Un pago para cada listado que no tuviera: perderlo sería perder
            // las facturas que le pertenecen.
            $huerfanos = $this->fetchAll(
                "SELECT id, sociedad_id, nombre, archivo_origen, fecha_subida
                   FROM porpagar_listados WHERE pago_id IS NULL"
            ) ?: [];
            foreach ($huerfanos as $l) {
                $nuevoId = (int) $this->insert(
                    "INSERT INTO {$this->table}
                        (sociedad_id, nombre, fecha_pago, archivo_origen, archivo_subido_en, creado_en)
                     VALUES (?, ?, DATE(?), ?, ?, ?)",
                    [
                        $l['sociedad_id'] ?: null,
                        mb_substr((string) $l['nombre'], 0, 100, 'UTF-8'),
                        $l['fecha_subida'], $l['archivo_origen'],
                        $l['fecha_subida'], $l['fecha_subida'],
                    ]
                );
                $this->execute(
                    "UPDATE porpagar_listados SET pago_id = ? WHERE id = ?",
                    [$nuevoId, (int) $l['id']]
                );
            }

            $this->execute(
                "UPDATE {$this->table} p
                   JOIN porpagar_listados l ON l.pago_id = p.id
                    SET p.archivo_origen    = COALESCE(p.archivo_origen, l.archivo_origen),
                        p.archivo_subido_en = COALESCE(p.archivo_subido_en, l.fecha_subida),
                        p.sociedad_id       = COALESCE(p.sociedad_id, l.sociedad_id)"
            );

            // El apuntador que sobraba: las facturas del ERP pasan a mirar al
            // pago y nada más.
            $tieneColumna = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_erp'
                   AND COLUMN_NAME = 'porpagar_listado_id'"
            );
            if ($tieneColumna > 0) {
                $this->execute(
                    "UPDATE facturas_erp e
                       JOIN porpagar_listados l ON l.id = e.porpagar_listado_id
                        SET e.pago_id = l.pago_id
                      WHERE e.porpagar_listado_id IS NOT NULL"
                );
                $this->execute("ALTER TABLE facturas_erp DROP INDEX IF EXISTS `idx_porpagar_listado`");
                $this->execute("ALTER TABLE facturas_erp DROP COLUMN IF EXISTS `porpagar_listado_id`");
            }

            $lineasViejas = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'porpagar_facturas'"
            ) > 0
                ? (int) $this->fetchColumn("SELECT COUNT(*) FROM porpagar_facturas")
                : 0;

            if ($lineasViejas === 0) {
                try {
                    $this->execute(
                        "ALTER TABLE porpagar_facturas DROP FOREIGN KEY IF EXISTS `fk_porpagar_listado`"
                    );
                } catch (Throwable $e) {
                }
                $this->execute("DROP TABLE IF EXISTS porpagar_listados");
            }

            $this->execute(
                "UPDATE {$this->table} p
                    SET p.total_lineas = (SELECT COUNT(*) FROM facturas_erp e WHERE e.pago_id = p.id)"
            );
        } catch (Throwable $e) {
            // La migración a mano lo resuelve; mientras tanto se trabaja con
            // lo que haya.
        }
    }

    /**
     * Los pagos de la sociedad en curso, del más reciente al más viejo.
     *
     * Ordena por fecha y no por id: un pago se puede cargar tarde —el del
     * martes se sube el jueves— y en la lista tiene que salir donde le toca
     * por su fecha, no por cuándo alguien encontró el archivo. El id desempata
     * los del mismo día, que es justo lo que el nombre viejo no permitía.
     */
    public function getAll($limite = 60)
    {
        $params = [];
        $sql = "SELECT * FROM {$this->table} WHERE 1=1"
             . $this->condicionSociedad('', $params)
             . " ORDER BY COALESCE(fecha_pago, DATE(creado_en)) DESC, id DESC"
             . " LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    /**
     * Crea un pago. El nombre es opcional: sin él se nombra por su fecha, que
     * es como se lo llama en voz alta («el pago del 3»).
     */
    public function crear($nombre = '', $sociedadId = 0, $fechaPago = '')
    {
        $fechaPago = self::normalizarFecha($fechaPago);
        if ($fechaPago === '') {
            $fechaPago = date('Y-m-d');
        }

        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            $nombre = 'Pago ' . date('d/m/Y', strtotime($fechaPago));
        }

        $sociedadId = (int) $sociedadId > 0 ? (int) $sociedadId : $this->sociedadId();
        return $this->insert(
            "INSERT INTO {$this->table} (nombre, fecha_pago, sociedad_id) VALUES (?, ?, ?)",
            [mb_substr($nombre, 0, 100, 'UTF-8'), $fechaPago, $sociedadId > 0 ? $sociedadId : null]
        );
    }

    /**
     * Los nombres de carpeta que ya usan los pagos de la sociedad en curso.
     *
     * Es el selector de carpeta cuando los documentos están en SharePoint:
     * ahí no se recorre la biblioteca para llenarlo —serían llamadas por red
     * cada vez que alguien abre Pagos—, y la base ya sabe qué carpetas hay.
     */
    public function carpetasUsadas()
    {
        $params = [];
        $sql = "SELECT DISTINCT carpeta_pago FROM {$this->table}
                 WHERE carpeta_pago IS NOT NULL AND carpeta_pago <> ''"
             . $this->condicionSociedad('', $params);
        $nombres = array_map('strval', array_column($this->fetchAll($sql, $params) ?: [], 'carpeta_pago'));
        natcasesort($nombres);
        return array_values($nombres);
    }

    public function configurarCarpetaPago($pagoId, $carpeta)
    {
        $carpeta = trim((string) $carpeta);
        return $this->execute(
            "UPDATE {$this->table} SET carpeta_pago = ? WHERE id = ?",
            [$carpeta !== '' ? mb_substr($carpeta, 0, 100, 'UTF-8') : null, (int) $pagoId]
        );
    }

    /** Cambia la fecha de un pago que se creó con la equivocada. */
    public function configurarFecha($pagoId, $fechaPago)
    {
        $fechaPago = self::normalizarFecha($fechaPago);
        if ($fechaPago === '') {
            return 0;
        }
        return $this->execute(
            "UPDATE {$this->table} SET fecha_pago = ? WHERE id = ?",
            [$fechaPago, (int) $pagoId]
        );
    }

    /**
     * Resuelve la selección de un formulario: 'nuevo' crea un pago con el
     * nombre y la fecha indicados; un id numérico se valida contra la tabla.
     * Devuelve el id del pago o null (sin asignar).
     */
    public function resolverSeleccion($valor, $nombreNuevo = '', $fechaNueva = '')
    {
        $valor = trim((string) $valor);

        // 'nueva' es como se llamaba antes de que el pago dejara de ser
        // semanal. Se sigue aceptando: una pestaña que quedó abierta desde
        // antes del cambio manda todavía ese valor.
        if ($valor === 'nuevo' || $valor === 'nueva') {
            return (int) $this->crear($nombreNuevo, 0, $fechaNueva);
        }

        // Se valida contra los pagos de la sociedad en curso: un id de otra
        // empresa no se acepta aunque llegue en el formulario.
        $id = (int) $valor;
        if ($id > 0 && !empty($this->existePara($id))) {
            return $id;
        }

        return null;
    }

    /**
     * Un pago por su id, siempre acotado.
     *
     * El findById heredado no sabe de empresas, y por él entraban los ids que
     * llegan en la dirección o en el formulario: preparar la carpeta de un
     * pago, verificarlo, borrarlo. Con dos empresas eso alcanzaba para
     * trabajar sobre el pago de la otra escribiendo su número a mano. Quien
     * necesite mirar sin acotar tiene que pedirlo con setSociedad(0).
     */
    public function findById($id)
    {
        $params = [(int) $id];
        $sql = "SELECT * FROM {$this->table} WHERE id = ?"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    /** ¿Ese pago es de la sociedad en la que se está trabajando? */
    public function existePara($pagoId)
    {
        $params = [(int) $pagoId];
        $sql = "SELECT 1 FROM {$this->table} WHERE id = ?"
             . $this->condicionSociedad('', $params) . ' LIMIT 1';
        return (bool) $this->fetchColumn($sql, $params);
    }

    // ── Lo que aportaba el listado ───────────────────────────────────────

    /**
     * Los pagos para la pantalla: con el nombre de su empresa al lado.
     *
     * getAll() alimenta el selector y con el nombre y la fecha le alcanza;
     * esto alimenta la pantalla, que además dice de qué archivo salió cada uno
     * y de qué empresa es.
     */
    public function getConDetalle($limite = 60)
    {
        $params = [];
        $sql = "SELECT p.*, s.nombre AS sociedad_nombre
                  FROM {$this->table} p
                  LEFT JOIN sociedades s ON s.id = p.sociedad_id
                 WHERE 1=1" . $this->condicionSociedad('p.', $params) . "
                 ORDER BY COALESCE(p.fecha_pago, DATE(p.creado_en)) DESC, p.id DESC
                 LIMIT " . max(1, (int) $limite);
        return $this->fetchAll($sql, $params) ?: [];
    }

    /** Un pago con el nombre de su empresa, o null si no es de esta. */
    public function getConSociedad($id)
    {
        $params = [(int) $id];
        $sql = "SELECT p.*, s.nombre AS sociedad_nombre
                  FROM {$this->table} p
                  LEFT JOIN sociedades s ON s.id = p.sociedad_id
                 WHERE p.id = ?" . $this->condicionSociedad('p.', $params) . " LIMIT 1";
        $fila = $this->fetchOne($sql, $params);
        return $fila ?: null;
    }

    /** Deja anotado de qué archivo salió el pago y cuándo se subió. */
    public function registrarArchivo($pagoId, $archivoOrigen, $sociedadId = null)
    {
        return $this->execute(
            "UPDATE {$this->table}
                SET archivo_origen = ?, archivo_subido_en = NOW(),
                    sociedad_id = COALESCE(sociedad_id, ?)
              WHERE id = ?",
            [
                mb_substr((string) $archivoOrigen, 0, 255, 'UTF-8'),
                $sociedadId ?: null,
                (int) $pagoId,
            ]
        );
    }

    /** El contador de la cabecera sale de contar las facturas marcadas. */
    public function actualizarTotalLineas($pagoId)
    {
        return $this->execute(
            "UPDATE {$this->table}
                SET total_lineas = (SELECT COUNT(*) FROM facturas_erp WHERE pago_id = ?)
              WHERE id = ?",
            [(int) $pagoId, (int) $pagoId]
        );
    }

    /**
     * Borra el pago y suelta sus facturas.
     *
     * Soltarlas es lo que antes era borrar líneas: las facturas del ERP no se
     * eliminan nunca —son el registro del sistema de la empresa—, vuelven a
     * quedar disponibles para otro pago. Con su XML, si el enlace era
     * automático (ver FacturaErp::SOLTAR_DEL_PAGO).
     */
    public function eliminar($id)
    {
        require_once __DIR__ . '/FacturaErp.php';
        $this->execute(
            "UPDATE facturas_erp
                SET " . FacturaErp::SOLTAR_DEL_PAGO . "
              WHERE pago_id = ?",
            [(int) $id]
        );
        // Y los comprobantes dejan de pertenecerle, para que el organizador
        // los saque de su carpeta en el próximo acomodo.
        $this->execute("UPDATE facturas_xml SET pago_id = NULL WHERE pago_id = ?", [(int) $id]);
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $id]);
    }

    public function begin() { return self::getDB()->beginTransaction(); }
    public function commit() { return self::getDB()->commit(); }
    public function rollback()
    {
        if (self::getDB()->inTransaction()) {
            return self::getDB()->rollBack();
        }
        return true;
    }

    /**
     * Cómo se llama un pago en pantalla: su fecha, y el nombre al lado cuando
     * dice algo que la fecha no dice.
     *
     * Un pago llamado "Pago 03/09/2026" no gana nada repitiendo la fecha; uno
     * llamado "Urgente proveedores" sí. Por eso se compara con el nombre que
     * se habría puesto solo: si es ese, se muestra la fecha y nada más.
     */
    public static function etiqueta(array $pago)
    {
        $fecha = self::normalizarFecha($pago['fecha_pago'] ?? '');
        $nombre = trim((string) ($pago['nombre'] ?? ''));

        if ($fecha === '') {
            return $nombre !== '' ? $nombre : ('Pago #' . (int) ($pago['id'] ?? 0));
        }

        $enLetras = date('d/m/Y', strtotime($fecha));
        if ($nombre === '' || $nombre === 'Pago ' . $enLetras) {
            return 'Pago ' . $enLetras;
        }

        return $enLetras . ' · ' . $nombre;
    }

    /**
     * Una fecha que se pueda guardar, o '' si no la hay.
     *
     * Acepta lo que manda un <input type="date"> (AAAA-MM-DD) y lo que alguien
     * escribe a mano (DD/MM/AAAA), porque las dos formas llegan: la primera del
     * formulario y la segunda de los nombres viejos.
     */
    public static function normalizarFecha($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valor, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
        }

        return '';
    }
}
