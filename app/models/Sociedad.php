<?php
/**
 * Modelo de Sociedad.
 *
 * Empresas del grupo. La empresa con la que se trabaja define la cédula que
 * el módulo de correo usa para verificar el receptor de cada factura, y con
 * la que queda sellado todo lo que se importe.
 *
 * Esa selección es POR USUARIO, no del sistema. Antes era la columna `activa`
 * —una sola marca compartida—, así que si dos personas trabajaban a la vez, la
 * que cambiaba de empresa movía también la de la otra sin aviso, y los
 * documentos quedaban sellados con la sociedad equivocada.
 *
 * Se resuelve en tres escalones (ver seleccionadaId):
 *   1. la sesión en curso   2. la preferencia guardada del usuario
 *   3. `activa`, como valor por omisión del sistema y para procesos sin
 *      sesión (los workers de cli/, que además ya reciben su sociedad).
 */

class Sociedad extends Model
{
    protected $table = 'sociedades';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre` VARCHAR(255) NOT NULL,
            `cedula` VARCHAR(30) NOT NULL,
            `activa` TINYINT(1) NOT NULL DEFAULT 0,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->execute($sql);
        } catch (Throwable $e) {
            // Sin permisos de DDL: la migración manual la crea
        }

        // La columna `almacen_documentos` (dónde archivaba lo nuevo cada
        // empresa) quedó en las bases que ya la tienen, pero no se crea ni se
        // lee: desde el 27/09/2026 todas archivan en SharePoint. Ver
        // AlmacenDocumentos, "Cuál se usa".
    }

    /**
     * Cuántos comprobantes de la empresa siguen en la carpeta compartida de
     * antes. Se abren mientras esa carpeta esté en la computadora, pero no
     * están en SharePoint hasta migrarlos (cli/migrar_documentos_a_graph.php).
     */
    public function documentosEnDisco($id)
    {
        try {
            return (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM facturas_xml
                  WHERE sociedad_id = ? AND almacen IS NULL AND COALESCE(ruta_xml, '') <> ''
                    AND ruta_xml NOT LIKE 'sharepoint:%'",
                [(int) $id]
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * ¿Hay siquiera una empresa registrada?
     *
     * Sin filtrar por quién pregunta, a propósito: no es "cuáles puedo ver"
     * —eso es getAll()— sino si el sistema está puesto en marcha. Lo usa
     * Requisitos, que cierra los módulos mientras no lo esté.
     */
    public function hayAlguna()
    {
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}") > 0;
    }

    /**
     * Cuántos comprobantes, de todas las empresas, siguen en la carpeta
     * compartida de antes: lo que falta migrar para que no quede nada fuera
     * de SharePoint. [sociedad_id => cuántos], solo las que tienen alguno.
     */
    public function documentosEnDiscoPorEmpresa()
    {
        try {
            $filas = $this->fetchAll(
                "SELECT sociedad_id, COUNT(*) AS n FROM facturas_xml
                  WHERE almacen IS NULL AND COALESCE(ruta_xml, '') <> '' AND ruta_xml NOT LIKE 'sharepoint:%'
                  GROUP BY sociedad_id"
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(int) $fila['sociedad_id']] = (int) $fila['n'];
        }
        return $mapa;
    }

    /**
     * Las empresas que esta persona puede ver.
     *
     * Filtra por lo que el administrador le asignó (usuario_sociedades). Sin
     * asignación, o siendo administrador, salen todas —ver
     * Permisos::sociedadesPermitidas()—. El filtro está aquí y no en cada
     * pantalla porque este método lo llaman el selector de Inicio, la
     * configuración y la barra superior: hacerlo tres veces sería olvidarlo
     * una.
     */
    public function getAll()
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        $permitidas = [];
        try {
            $permitidas = Permisos::sociedadesPermitidas();
        } catch (Throwable $e) {
            // Sin poder resolver permisos se listan todas, que es como se
            // comportaba antes: el filtro no puede dejar a nadie sin empresa.
        }

        if ($permitidas) {
            $sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($permitidas), '?')) . ')';
            $params = $permitidas;
        }

        return $this->fetchAll($sql . ' ORDER BY activa DESC, nombre ASC', $params) ?: [];
    }

    /**
     * TODAS las empresas, sin filtrar por quién mira. La usa la pantalla que
     * reparte accesos: para poder asignarle a alguien una empresa hay que
     * poder verla, aunque quien reparte no trabaje con ella.
     */
    public function getTodas()
    {
        return $this->fetchAll("SELECT * FROM {$this->table} ORDER BY activa DESC, nombre ASC") ?: [];
    }

    /**
     * La sociedad con la que se está trabajando (o null si no hay ninguna).
     * Es la de ESTE usuario, no una marca global.
     */
    public function getActiva()
    {
        $id = $this->seleccionadaId();
        if ($id > 0) {
            $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);
            if ($fila) {
                return $fila;
            }
        }
        // La seleccionada ya no existe (la borraron): se cae al valor por
        // omisión para no dejar la aplicación sin empresa.
        $fila = $this->fetchOne("SELECT * FROM {$this->table} WHERE activa = 1 LIMIT 1");
        return $fila ?: null;
    }

    /**
     * Id de la empresa en curso, cacheado por petición: lo consultan todos los
     * modelos acotados y no cambia a mitad de una. Vive aquí y no en el trait
     * porque un `static` dentro de un trait se duplica en cada clase que lo
     * usa, y entonces nada podría invalidarlos todos de una vez.
     */
    public static function seleccionadaIdActual()
    {
        if (self::$cacheSeleccionada !== null) {
            return self::$cacheSeleccionada;
        }
        try {
            self::$cacheSeleccionada = (int) (new self())->seleccionadaId();
        } catch (Throwable $e) {
            // Sin tabla de sociedades (instalación nueva) no se acota nada.
            self::$cacheSeleccionada = 0;
        }
        return self::$cacheSeleccionada;
    }

    /** Lo llama seleccionar() al cambiar de empresa. */
    public static function olvidarSeleccion()
    {
        self::$cacheSeleccionada = null;
    }

    private static $cacheSeleccionada = null;

    /**
     * Id de la empresa con la que trabaja quien hace esta petición.
     * Sesión → preferencia guardada del usuario → valor por omisión.
     */
    public function seleccionadaId()
    {
        if (!empty($_SESSION['sociedad_id'])) {
            $idSesion = (int) $_SESSION['sociedad_id'];
            if ($idSesion > 0 && $this->fetchColumn(
                "SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1",
                [$idSesion]
            )) {
                return $idSesion;
            }

            // Puede ocurrir si un administrador elimina una sociedad mientras
            // otro usuario mantiene una sesión abierta. La selección inválida
            // no debe dejar todos los módulos filtrando por un id inexistente.
            unset($_SESSION['sociedad_id']);
        }

        $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
        if ($usuarioId > 0) {
            $preferida = (int) $this->fetchColumn(
                'SELECT sociedad_id FROM usuarios WHERE id = ? LIMIT 1',
                [$usuarioId]
            );
            // Se recuerda en la sesión para no consultarlo en cada petición.
            if ($preferida > 0 && $this->fetchColumn("SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1", [$preferida])) {
                $_SESSION['sociedad_id'] = $preferida;
                return $preferida;
            }
        }

        return (int) $this->fetchColumn("SELECT id FROM {$this->table} WHERE activa = 1 ORDER BY id LIMIT 1");
    }

    /**
     * Cambia la empresa de ESTE usuario: en la sesión (efecto inmediato) y en
     * su ficha (para el próximo ingreso). No toca a los demás usuarios.
     */
    public function seleccionar($id)
    {
        $id = (int) $id;
        if ($id <= 0 || !$this->fetchColumn("SELECT 1 FROM {$this->table} WHERE id = ? LIMIT 1", [$id])) {
            throw new InvalidArgumentException('La sociedad indicada no existe.');
        }

        $_SESSION['sociedad_id'] = $id;

        $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
        if ($usuarioId > 0) {
            $this->execute('UPDATE usuarios SET sociedad_id = ? WHERE id = ?', [$id, $usuarioId]);
        }

        // El alcance se cachea por petición: si no se limpia, los modelos
        // seguirían consultando la empresa anterior.
        self::olvidarSeleccion();
        return true;
    }

    /**
     * Qué empresa ya tiene esa cédula, si alguna. Null si está libre.
     *
     * Compara solo los dígitos: `3-101-123456` y `3101123456` son la misma
     * cédula escrita de dos formas, y antes eran dos empresas distintas porque
     * nada comprobaba nada. La unicidad de la base
     * (migration_sociedad_cedula_unica.sql) lo impide de verdad; esto es para
     * poder decirlo en español en vez de soltar un "Duplicate entry".
     *
     * @param int $exceptoId Al editar, la empresa que se está editando no
     *                       cuenta como duplicada de sí misma.
     */
    public function cedulaEnUso($cedula, $exceptoId = 0)
    {
        $digitos = preg_replace('/\D+/', '', (string) $cedula);
        if ($digitos === '') {
            return null;
        }
        return $this->fetchOne(
            "SELECT id, nombre, cedula FROM {$this->table}
              WHERE REGEXP_REPLACE(cedula, '[^0-9]', '') = ? AND id <> ?
              LIMIT 1",
            [$digitos, (int) $exceptoId]
        ) ?: null;
    }

    public function crear($nombre, $cedula)
    {
        $cedula = self::normalizarCedula($cedula);
        $this->exigirCedulaLibre($cedula, 0);

        // La primera sociedad registrada queda activa automáticamente
        $esPrimera = (int) $this->fetchColumn("SELECT COUNT(*) FROM {$this->table}") === 0;

        $sql = "INSERT INTO {$this->table} (nombre, cedula, activa) VALUES (?, ?, ?)";
        return $this->insert($sql, [$nombre, $cedula, $esPrimera ? 1 : 0]);
    }

    public function actualizar($id, $nombre, $cedula)
    {
        $cedula = self::normalizarCedula($cedula);
        $this->exigirCedulaLibre($cedula, (int) $id);

        $sql = "UPDATE {$this->table} SET nombre = ?, cedula = ? WHERE id = ?";
        return $this->execute($sql, [$nombre, $cedula, (int) $id]);
    }

    /**
     * La cédula de una empresa, comprobada y guardada solo con dígitos.
     *
     * Una empresa del grupo es siempre persona jurídica, así que su cédula es
     * la jurídica de Hacienda: diez dígitos que empiezan por 3. Quien la
     * escribe la escribe como quiere —3-101-639680, 3101639680— y las dos
     * son la misma; se guarda normalizada porque así es como viene el
     * receptor en el XML y como se guarda la del usuario, y es lo que hace
     * que se crucen sin limpiar nada en cada consulta.
     *
     * Antes bastaba con que tuviera un número dentro. Un 78999999999999999
     * entraba sin protestar y la empresa quedaba dada de alta con una cédula
     * que no existe: ninguna factura vuelve a cruzar con ella, y eso no se ve
     * hasta que alguien pregunta por qué esa empresa no recibe nada.
     */
    public static function normalizarCedula($cedula): string
    {
        $digitos = (string) preg_replace('/\D+/', '', (string) $cedula);

        if (preg_match('/^3\d{9}$/', $digitos)) {
            return $digitos;
        }

        $cuantos = strlen($digitos);
        if ($cuantos === 0) {
            $detalle = 'Esa no trae números.';
        } elseif ($cuantos === 10) {
            $detalle = 'Esa tiene diez, pero no empieza por 3: así son las de '
                     . 'persona física, no las de una empresa.';
        } else {
            $detalle = 'Esa tiene ' . $cuantos . ($cuantos === 1 ? ' dígito.' : ' dígitos.');
        }

        throw new InvalidArgumentException(
            'La cédula de una empresa es la jurídica: diez dígitos que empiezan '
            . 'por 3, como 3101639680. ' . $detalle
        );
    }

    /** Lanza con un mensaje legible si esa cédula ya es de otra empresa. */
    private function exigirCedulaLibre($cedula, $exceptoId)
    {
        $otra = $this->cedulaEnUso($cedula, $exceptoId);
        if ($otra) {
            throw new RuntimeException(
                'Esa cédula ya es de "' . $otra['nombre'] . '" (' . $otra['cedula']
                . '). Dos empresas con la misma cédula reparten su trabajo entre '
                . 'dos fichas y nadie se entera de que son la misma.'
            );
        }
    }

    /**
     * Qué tiene esta empresa que impide borrarla. Vacío = se puede.
     *
     * Las claves foráneas de migration_claves_sociedad.sql ya impiden el
     * borrado, pero solas contestan con un SQLSTATE 1451 que no le dice nada a
     * nadie. Esto es para contestar en español y con números: "tiene 14.043
     * facturas y 87 pagos", que es lo que hay que saber para decidir.
     *
     * Solo mira las tablas con RESTRICT. Las de CASCADE se borran solas con la
     * empresa —un permiso de acceso, una bandeja de revisión— y no son motivo
     * para detener a nadie.
     */
    public function loQueImpideBorrar($id)
    {
        $id = (int) $id;
        $tablas = [
            'facturas_xml'         => 'comprobante(s) electrónico(s)',
            'facturas_erp'         => 'factura(s) del ERP',
            'devoluciones'         => 'devolución(es)',
            'pagos'                => 'pago(s)',
            'notas_credito_cargas' => 'carga(s) de notas de crédito',
            'correo_bandeja'       => 'documento(s) en la bandeja de correo',
        ];

        $motivos = [];
        foreach ($tablas as $tabla => $comoSeLlama) {
            try {
                $n = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM `{$tabla}` WHERE sociedad_id = ?",
                    [$id]
                );
            } catch (Throwable $e) {
                // Una tabla que todavía no exista en esta base no es un
                // impedimento; lo que no se puede es dar un falso "sí se
                // puede" por no haber podido contar.
                continue;
            }
            if ($n > 0) {
                $motivos[] = number_format($n, 0, ',', '.') . ' ' . $comoSeLlama;
            }
        }
        return $motivos;
    }

    /**
     * Borra la empresa. Lanza si todavía tiene documentos.
     *
     * Antes era un DELETE pelado y la base lo dejaba pasar: las catorce mil
     * facturas se quedaban con un `sociedad_id` que ya no existía, invisibles
     * en todas las pantallas —filtran por empresa— y sin forma de reasignarlas
     * desde ningún lado.
     */
    public function eliminar($id)
    {
        $motivos = $this->loQueImpideBorrar($id);
        if ($motivos) {
            throw new RuntimeException(
                'No se puede eliminar: la empresa todavía tiene '
                . implode(', ', $motivos) . '. Hay que mover o dar de baja '
                . 'eso primero; si se borrara la empresa, quedarían sin dueño '
                . 'y no saldrían en ninguna pantalla.'
            );
        }
        return $this->execute("DELETE FROM {$this->table} WHERE id = ?", [(int) $id]);
    }

    /**
     * Cambiar de empresa. Antes hacía un UPDATE global (`activa`) que movía la
     * empresa de todos los usuarios a la vez; ahora es por usuario.
     */
    public function activar($id)
    {
        return $this->seleccionar($id);
    }

    /**
     * Valor por omisión del sistema: con qué empresa arranca un usuario que
     * nunca ha elegido, y con cuál trabajan los procesos sin sesión. Es lo
     * único que sigue guardándose en la columna `activa`.
     */
    public function definirPorOmision($id)
    {
        $this->execute("UPDATE {$this->table} SET activa = 0 WHERE activa = 1");
        return $this->execute("UPDATE {$this->table} SET activa = 1 WHERE id = ?", [(int) $id]);
    }
}
