<?php
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../core/Permisos.php';

/**
 * Quién hizo qué, y cuándo.
 *
 * ── Cómo se llena, sin tener que acordarse ────────────────────────────────
 * No hay que llamar a nada desde los controladores para que una acción quede
 * registrada. El enrutador avisa (`vigilar`) antes de ejecutar la ruta, y el
 * renglón se escribe al terminar la petición, con el resultado ya conocido.
 * Eso es a propósito: casi todos los controladores de esta aplicación
 * terminan en un redirect() o un json() que hacen exit, así que cualquier
 * código escrito "después de la acción" no llegaría a correr nunca. Un
 * register_shutdown_function sí.
 *
 * Qué se registra lo decide config/permisos.php: solo las rutas con
 * 'registrar'. Las que únicamente leen —y aquí hay muchos POST que solo
 * leen— no dejan rastro, porque una bitácora en la que el 95% de los
 * renglones son "abrió la bandeja" no la mira nadie.
 *
 * Cuando hace falta contar algo que la ruta por sí sola no dice —qué usuario
 * se editó, qué cambió—, el controlador llama a `registrar()` con el detalle.
 * Ese renglón sustituye al automático de esa petición, para que un mismo acto
 * no aparezca dos veces.
 *
 * ── El historial anterior ─────────────────────────────────────────────────
 * Antes de esto ya había rastro de usuario repartido por media docena de
 * tablas: quién cambió un estado de seguimiento, quién subió un reporte,
 * quién descartó una incidencia, quién cerró un aviso. Nada de eso se copia
 * ni se migra: se LEE en su sitio y se mezcla en la misma lista (ver
 * `fuentes()`), para que la pantalla del administrador sea una sola línea de
 * tiempo y no "la bitácora nueva" más seis pantallas viejas.
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    /** El renglón que se escribirá al terminar la petición, si nadie lo pisa. */
    private static $pendiente = null;

    /** true si un controlador ya contó esta petición con su propio detalle. */
    private static $contadaAMano = false;

    /** Tablas que existen en esta base, preguntadas una sola vez. */
    private static $tablasVistas = [];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        try {
            $this->crearTabla();
        } catch (Throwable $e) {
            // Sin permisos de DDL la crea a mano
            // database/migrations/005_roles_y_bitacora.sql. Si tampoco está,
            // escribir en la bitácora falla en silencio: auditar no puede
            // impedir trabajar.
            self::anotarFallo($e);
        }
    }

    private function crearTabla()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS bitacora (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
            `usuario_nombre` VARCHAR(120) NULL DEFAULT NULL,
            `rol_nombre` VARCHAR(60) NULL DEFAULT NULL,
            `sociedad_id` INT UNSIGNED NULL DEFAULT NULL,
            `modulo` VARCHAR(40) NOT NULL DEFAULT 'general',
            `accion` VARCHAR(160) NOT NULL,
            `permiso` VARCHAR(60) NULL DEFAULT NULL,
            `entidad` VARCHAR(60) NULL DEFAULT NULL,
            `entidad_id` VARCHAR(60) NULL DEFAULT NULL,
            `detalle` TEXT NULL DEFAULT NULL,
            `metodo` VARCHAR(8) NOT NULL DEFAULT 'GET',
            `ruta` VARCHAR(190) NOT NULL DEFAULT '',
            `ip` VARCHAR(45) NULL DEFAULT NULL,
            `resultado` ENUM('ok','denegado','error') NOT NULL DEFAULT 'ok',
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_bitacora_fecha` (`creado_en`),
            KEY `idx_bitacora_usuario` (`usuario_id`, `creado_en`),
            KEY `idx_bitacora_modulo` (`modulo`, `creado_en`),
            KEY `idx_bitacora_resultado` (`resultado`, `creado_en`),
            KEY `idx_bitacora_entidad` (`entidad`, `entidad_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // ══ ESCRITURA ═════════════════════════════════════════════════════════

    /**
     * La llama el enrutador con la ruta REGISTRADA (con sus {id}), antes de
     * ejecutar nada. Si esa ruta cambia algo, deja programado el renglón.
     */
    public static function vigilar(string $metodo, string $uri, array $params = []): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $regla = Permisos::reglaDeRuta($metodo, $uri);
        $accion = $regla['registrar'] ?? null;
        if (!$accion) {
            return;
        }

        // El permiso puede venir como lista ("con cualquiera basta"); para
        // agrupar por módulo basta el primero, que es el dueño de la pantalla.
        $permiso = $regla['permiso'] ?? '';
        if (is_array($permiso)) {
            $permiso = (string) (reset($permiso) ?: '');
        }

        self::$pendiente = [
            'accion'  => (string) $accion,
            'permiso' => (string) $permiso,
            'metodo'  => strtoupper($metodo),
            'ruta'    => $uri,
            'params'  => $params,
        ];

        register_shutdown_function([self::class, 'cerrarPeticion']);
    }

    /**
     * Se ejecuta al terminar la petición, pase lo que pase por el camino.
     * Aquí ya se sabe si aquello salió bien: un 500 o un 4xx quedan marcados
     * como error en vez de dar por hecho que la acción se completó.
     */
    public static function cerrarPeticion(): void
    {
        $pendiente = self::$pendiente;
        self::$pendiente = null;

        if (!$pendiente || self::$contadaAMano) {
            return;
        }

        $codigo = (int) (http_response_code() ?: 200);
        $resultado = $codigo >= 400 ? 'error' : 'ok';

        self::escribir([
            'accion'    => $pendiente['accion'],
            'permiso'   => $pendiente['permiso'],
            'modulo'    => Permisos::moduloDe($pendiente['permiso']),
            'metodo'    => $pendiente['metodo'],
            'ruta'      => $pendiente['ruta'],
            'resultado' => $resultado,
            'detalle'   => [
                'parametros' => self::datosDeLaPeticion($pendiente['params']),
                'estado_http' => $codigo,
            ],
        ]);
    }

    /**
     * El detalle que solo conoce el controlador: qué se editó, con qué
     * valores, qué había antes. Sustituye al renglón automático de esta
     * petición para que el acto aparezca una sola vez.
     *
     *   Bitacora::registrar([
     *       'accion'   => 'Editó un usuario',
     *       'modulo'   => 'administracion',
     *       'entidad'  => 'usuario',
     *       'entidad_id' => $id,
     *       'antes'    => ['rol' => 'Operación'],
     *       'despues'  => ['rol' => 'Contabilidad'],
     *   ]);
     */
    public static function registrar(array $datos): void
    {
        self::$contadaAMano = true;

        $detalle = $datos['detalle'] ?? [];
        if (!is_array($detalle)) {
            $detalle = ['nota' => (string) $detalle];
        }

        // Antes/después se guardan ya reducidos a lo que de verdad cambió:
        // una ficha entera repetida dos veces obliga a compararla a ojo.
        if (isset($datos['antes']) || isset($datos['despues'])) {
            $cambios = self::soloLoQueCambio(
                is_array($datos['antes'] ?? null) ? $datos['antes'] : [],
                is_array($datos['despues'] ?? null) ? $datos['despues'] : []
            );
            if ($cambios) {
                $detalle['cambios'] = $cambios;
            }
        }

        self::escribir([
            'accion'     => (string) ($datos['accion'] ?? 'Acción sin nombre'),
            'modulo'     => (string) ($datos['modulo'] ?? 'general'),
            'permiso'    => (string) ($datos['permiso'] ?? ''),
            'entidad'    => $datos['entidad'] ?? null,
            'entidad_id' => isset($datos['entidad_id']) ? (string) $datos['entidad_id'] : null,
            'resultado'  => (string) ($datos['resultado'] ?? 'ok'),
            'metodo'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ruta'       => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            'detalle'    => $detalle,
        ]);
    }

    /**
     * Un intento de entrar donde no se puede. Se registra aunque no haya
     * pasado nada: saber que alguien llegó a una puerta cerrada es la mitad
     * de para qué sirve un registro de accesos.
     */
    public static function denegado(string $uri, string $permiso): void
    {
        self::escribir([
            'accion'    => 'Intentó entrar sin permiso',
            'modulo'    => Permisos::moduloDe($permiso),
            'permiso'   => $permiso,
            'resultado' => 'denegado',
            'metodo'    => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ruta'      => $uri,
            'detalle'   => ['permiso_que_falta' => $permiso],
        ]);
    }

    /** Entrar y salir del sistema. Lo llama AuthController. */
    public static function acceso(string $accion, array $usuario = [], string $resultado = 'ok', array $detalle = []): void
    {
        self::escribir([
            'accion'     => $accion,
            'modulo'     => 'administracion',
            'entidad'    => 'usuario',
            'entidad_id' => isset($usuario['id']) ? (string) $usuario['id'] : null,
            'resultado'  => $resultado,
            'metodo'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'ruta'       => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
            'detalle'    => $detalle,
            'usuario'    => $usuario,
        ]);
    }

    /**
     * El INSERT. Envuelto entero: la bitácora no puede tumbar la operación
     * que está anotando —eso convertiría una función de auditoría en la causa
     * de que no se pueda trabajar—, así que si falla, calla y lo deja dicho
     * en storage/logs/app.log.
     */
    private static function escribir(array $fila): void
    {
        try {
            $usuario = $fila['usuario'] ?? [];

            $usuarioId = (int) ($usuario['id'] ?? $_SESSION['user_id'] ?? 0);
            $nombre    = (string) ($usuario['nombre']
                ?? $_SESSION['user_nombre']
                ?? $_SESSION['user_username']
                ?? 'Sistema');

            $rol = null;
            try {
                $rol = Permisos::rol()['nombre'] ?? null;
            } catch (Throwable $e) {
            }

            $sociedadId = (int) ($_SESSION['sociedad_id'] ?? 0);

            $detalle = $fila['detalle'] ?? [];
            $json = $detalle
                ? json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                : null;
            if ($json !== null && strlen($json) > 60000) {
                $json = json_encode(
                    ['nota' => 'El detalle era demasiado grande y se recortó.'],
                    JSON_UNESCAPED_UNICODE
                );
            }

            (new self())->insert(
                "INSERT INTO bitacora
                    (usuario_id, usuario_nombre, rol_nombre, sociedad_id, modulo, accion,
                     permiso, entidad, entidad_id, detalle, metodo, ruta, ip, resultado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $usuarioId > 0 ? $usuarioId : null,
                    mb_substr($nombre, 0, 120, 'UTF-8'),
                    $rol !== null ? mb_substr((string) $rol, 0, 60, 'UTF-8') : null,
                    $sociedadId > 0 ? $sociedadId : null,
                    mb_substr((string) ($fila['modulo'] ?? 'general'), 0, 40, 'UTF-8'),
                    mb_substr((string) $fila['accion'], 0, 160, 'UTF-8'),
                    ($fila['permiso'] ?? '') !== '' ? mb_substr((string) $fila['permiso'], 0, 60, 'UTF-8') : null,
                    isset($fila['entidad']) ? mb_substr((string) $fila['entidad'], 0, 60, 'UTF-8') : null,
                    isset($fila['entidad_id']) ? mb_substr((string) $fila['entidad_id'], 0, 60, 'UTF-8') : null,
                    $json,
                    mb_substr((string) ($fila['metodo'] ?? 'GET'), 0, 8, 'UTF-8'),
                    mb_substr((string) ($fila['ruta'] ?? ''), 0, 190, 'UTF-8'),
                    self::ip(),
                    in_array($fila['resultado'] ?? 'ok', ['ok', 'denegado', 'error'], true)
                        ? $fila['resultado'] : 'ok',
                ]
            );
        } catch (Throwable $e) {
            self::anotarFallo($e);
        }
    }

    // ── Lo que se guarda de la petición ───────────────────────────────────

    /**
     * Los datos que venían en el formulario, con dos podas.
     *
     * Fuera las contraseñas y los tokens: guardarlos en una tabla que un
     * administrador consulta a diario es exactamente el descuido que este
     * módulo existe para evitar. Y fuera los valores enormes —el contenido de
     * un archivo pegado, un JSON de mil líneas—, que no dicen nada al leerlos
     * y engordan la tabla hasta hacerla inservible.
     */
    private static function datosDeLaPeticion(array $paramsDeRuta): array
    {
        $prohibidas = ['password', 'password_confirm', 'contrasena', 'contrasena_confirm',
                       'clave', 'csrf_token', 'token', 'pass'];

        $datos = [];

        foreach ($paramsDeRuta as $clave => $valor) {
            $datos[$clave] = self::recortar($valor);
        }

        foreach ($_POST as $clave => $valor) {
            $claveMin = strtolower((string) $clave);
            if (in_array($claveMin, $prohibidas, true) || strpos($claveMin, 'password') !== false) {
                continue;
            }
            $datos[$clave] = self::recortar($valor);
        }

        if (!empty($_FILES)) {
            $archivos = [];
            foreach ($_FILES as $campo => $info) {
                $nombres = is_array($info['name'] ?? null) ? $info['name'] : [$info['name'] ?? ''];
                foreach ($nombres as $nombre) {
                    if ((string) $nombre !== '') {
                        $archivos[] = (string) $nombre;
                    }
                }
                unset($campo);
            }
            if ($archivos) {
                $datos['archivos'] = array_slice($archivos, 0, 20);
            }
        }

        return $datos;
    }

    /** Un valor legible: los textos largos se cortan y las listas se resumen. */
    private static function recortar($valor)
    {
        if (is_array($valor)) {
            $total = count($valor);
            if ($total > 10) {
                return array_slice(array_map([self::class, 'recortar'], array_slice($valor, 0, 10)), 0, 10)
                    + ['…' => '(' . ($total - 10) . ' más)'];
            }
            return array_map([self::class, 'recortar'], $valor);
        }

        $texto = (string) $valor;
        return mb_strlen($texto, 'UTF-8') > 300
            ? mb_substr($texto, 0, 300, 'UTF-8') . '…'
            : $texto;
    }

    /**
     * De dos fichas, solo los campos distintos. Es lo que hace legible un
     * "editó un usuario": interesa que le cambió el rol, no las otras nueve
     * columnas que quedaron igual.
     */
    public static function soloLoQueCambio(array $antes, array $despues): array
    {
        $cambios = [];
        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $a = $antes[$campo] ?? null;
            $d = $despues[$campo] ?? null;

            $iguales = is_array($a) || is_array($d)
                ? (json_encode($a) === json_encode($d))
                : ((string) $a === (string) $d);

            if (!$iguales) {
                $cambios[$campo] = ['antes' => $a, 'despues' => $d];
            }
        }
        return $cambios;
    }

    private static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip !== '' ? mb_substr((string) $ip, 0, 45, 'UTF-8') : null;
    }

    private static function anotarFallo(Throwable $e): void
    {
        try {
            $archivo = dirname(__DIR__, 2) . '/storage/logs/app.log';
            if (!is_dir(dirname($archivo))) {
                @mkdir(dirname($archivo), 0777, true);
            }
            @file_put_contents($archivo, sprintf(
                "[%s] BITACORA: no se pudo registrar — %s: %s\n",
                date('Y-m-d H:i:s'), get_class($e), $e->getMessage()
            ), FILE_APPEND);
        } catch (Throwable $otro) {
        }
    }

    // ══ LECTURA ═══════════════════════════════════════════════════════════

    /**
     * De dónde sale la línea de tiempo.
     *
     * La primera es la bitácora propiamente dicha; las demás son el rastro
     * que el sistema ya venía dejando en las tablas de cada módulo, desde
     * antes de que existiera este registro. Se leen en su sitio, sin copiar
     * nada: así el historial viejo aparece igual que el nuevo, y ninguna
     * migración puede desincronizarlos.
     *
     * Cada fuente entrega las mismas columnas —origen, ref_id, fecha,
     * usuario_id, usuario_nombre, modulo, accion, detalle, resultado— para
     * poder unirlas. Si una tabla no existe en esta instalación, simplemente
     * no participa.
     */
    public static function fuentes(): array
    {
        return [
            'bitacora' => [
                'titulo' => 'Bitácora del sistema',
                'tabla'  => 'bitacora',
                'sql'    => "SELECT 'bitacora' AS origen, b.id AS ref_id, b.creado_en AS fecha,
                                    b.usuario_id AS usuario_id,
                                    COALESCE(b.usuario_nombre, u.nombre, 'Sistema') AS usuario_nombre,
                                    b.modulo AS modulo, b.accion AS accion,
                                    b.detalle AS detalle, b.resultado AS resultado
                               FROM bitacora b
                               LEFT JOIN usuarios u ON u.id = b.usuario_id",
                'fecha'   => 'b.creado_en',
                'usuario' => 'b.usuario_id',
            ],

            'seguimiento' => [
                'titulo' => 'Seguimiento (historial anterior)',
                'tabla'  => 'seguimiento_bitacora',
                'sql'    => "SELECT 'seguimiento' AS origen, s.id AS ref_id, s.creado_en AS fecha,
                                    s.usuario_id AS usuario_id,
                                    COALESCE(s.usuario_nombre, u.nombre, 'Sistema') AS usuario_nombre,
                                    'seguimiento' AS modulo,
                                    CONCAT('Cambió un renglón de seguimiento: ',
                                           COALESCE(s.estado_anterior, '—'), ' → ',
                                           COALESCE(s.estado_nuevo, '—')) AS accion,
                                    s.comentario AS detalle, 'ok' AS resultado
                               FROM seguimiento_bitacora s
                               LEFT JOIN usuarios u ON u.id = s.usuario_id",
                'fecha'   => 's.creado_en',
                'usuario' => 's.usuario_id',
            ],

            'facturas_erp_cargas' => [
                'titulo' => 'Cargas del reporte del sistema',
                'tabla'  => 'facturas_erp_cargas',
                'sql'    => "SELECT 'facturas_erp_cargas' AS origen, c.id AS ref_id, c.creado_en AS fecha,
                                    c.usuario_id AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'facturas_erp' AS modulo,
                                    'Subió el reporte de facturas del sistema' AS accion,
                                    CONCAT(c.archivo_origen, ' · ', c.filas_leidas, ' filas') AS detalle,
                                    'ok' AS resultado
                               FROM facturas_erp_cargas c
                               LEFT JOIN usuarios u ON u.id = c.usuario_id",
                'fecha'   => 'c.creado_en',
                'usuario' => 'c.usuario_id',
            ],

            'facturas_erp_descartes' => [
                'titulo' => 'Descartes de incidencias del sistema',
                'tabla'  => 'facturas_erp_descartes',
                'sql'    => "SELECT 'facturas_erp_descartes' AS origen, d.id AS ref_id, d.creado_en AS fecha,
                                    d.usuario_id AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'facturas_erp' AS modulo,
                                    'Descartó una incidencia del sistema' AS accion,
                                    CONCAT_WS(' · ', NULLIF(d.documento, ''), NULLIF(d.proveedor_nombre, ''),
                                              NULLIF(d.motivo, '')) AS detalle,
                                    'ok' AS resultado
                               FROM facturas_erp_descartes d
                               LEFT JOIN usuarios u ON u.id = d.usuario_id",
                'fecha'   => 'd.creado_en',
                'usuario' => 'd.usuario_id',
            ],

            'notas_credito_cargas' => [
                'titulo' => 'Cargas de notas de crédito',
                'tabla'  => 'notas_credito_cargas',
                'sql'    => "SELECT 'notas_credito_cargas' AS origen, n.id AS ref_id, n.creado_en AS fecha,
                                    n.usuario_id AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'notas_credito' AS modulo,
                                    'Subió un listado de notas de crédito' AS accion,
                                    CONCAT(n.archivo_origen, ' · ', n.filas_leidas, ' filas') AS detalle,
                                    'ok' AS resultado
                               FROM notas_credito_cargas n
                               LEFT JOIN usuarios u ON u.id = n.usuario_id",
                'fecha'   => 'n.creado_en',
                'usuario' => 'n.usuario_id',
            ],

            'correo_descartes' => [
                'titulo' => 'Descartes de incidencias del correo',
                'tabla'  => 'correo_incidencias_descartes',
                'sql'    => "SELECT 'correo_descartes' AS origen, 0 AS ref_id, k.creado_en AS fecha,
                                    k.usuario_id AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'correo' AS modulo,
                                    'Descartó una incidencia del correo' AS accion,
                                    CONCAT_WS(' · ', NULLIF(k.asunto, ''), NULLIF(k.motivo, '')) AS detalle,
                                    'ok' AS resultado
                               FROM correo_incidencias_descartes k
                               LEFT JOIN usuarios u ON u.id = k.usuario_id",
                'fecha'   => 'k.creado_en',
                'usuario' => 'k.usuario_id',
            ],

            'avisos' => [
                'titulo' => 'Avisos resueltos',
                'tabla'  => 'notificaciones',
                'sql'    => "SELECT 'avisos' AS origen, a.id AS ref_id, a.resuelta_en AS fecha,
                                    a.resuelta_por AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'avisos' AS modulo,
                                    CONCAT('Cerró un aviso (', a.estado, '): ', a.titulo) AS accion,
                                    a.motivo AS detalle, 'ok' AS resultado
                               FROM notificaciones a
                               LEFT JOIN usuarios u ON u.id = a.resuelta_por",
                'fecha'     => 'a.resuelta_en',
                'usuario'   => 'a.resuelta_por',
                'condicion' => 'a.resuelta_en IS NOT NULL',
            ],

            'alias_proveedor' => [
                'titulo' => 'Alias de proveedor aprendidos',
                'tabla'  => 'proveedor_alias',
                'sql'    => "SELECT 'alias_proveedor' AS origen, x.id AS ref_id, x.creado_en AS fecha,
                                    x.creado_por AS usuario_id, COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                                    'documentos' AS modulo,
                                    'Enseñó un alias de proveedor' AS accion,
                                    CONCAT(x.alias_texto, ' → ', COALESCE(p.razon_social, '')) AS detalle,
                                    'ok' AS resultado
                               FROM proveedor_alias x
                               LEFT JOIN usuarios u ON u.id = x.creado_por
                               LEFT JOIN proveedores p ON p.id = x.proveedor_id",
                'fecha'   => 'x.creado_en',
                'usuario' => 'x.creado_por',
            ],
        ];
    }

    /** Solo las fuentes cuya tabla existe de verdad en esta base. */
    public function fuentesDisponibles(bool $incluirHistorial = true): array
    {
        $disponibles = [];
        foreach (self::fuentes() as $clave => $fuente) {
            if ($clave !== 'bitacora' && !$incluirHistorial) {
                continue;
            }
            if ($this->tablaExiste($fuente['tabla'])) {
                $disponibles[$clave] = $fuente;
            }
        }
        return $disponibles;
    }

    private function tablaExiste(string $tabla): bool
    {
        if (array_key_exists($tabla, self::$tablasVistas)) {
            return self::$tablasVistas[$tabla];
        }

        try {
            $existe = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$tabla]
            ) > 0;
        } catch (Throwable $e) {
            $existe = false;
        }

        return self::$tablasVistas[$tabla] = $existe;
    }

    /**
     * La lista que ve el administrador.
     *
     * @param array $f  usuario_id, modulo, origen, resultado, texto,
     *                  desde, hasta, historial (bool), pagina, por_pagina
     */
    public function listar(array $f = []): array
    {
        $pagina    = max(1, (int) ($f['pagina'] ?? 1));
        $porPagina = min(200, max(10, (int) ($f['por_pagina'] ?? 50)));
        $offset    = ($pagina - 1) * $porPagina;

        $historial = !empty($f['historial']);
        $fuentes   = $this->fuentesDisponibles($historial);

        // Si se pidió una fuente concreta, las demás no participan: así el
        // filtro "solo cargas del ERP" no obliga a leer las otras siete.
        $origen = (string) ($f['origen'] ?? '');
        if ($origen !== '' && isset($fuentes[$origen])) {
            $fuentes = [$origen => $fuentes[$origen]];
        }

        if (!$fuentes) {
            return ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => $porPagina];
        }

        $partes = [];
        $params = [];

        foreach ($fuentes as $fuente) {
            $where = [];
            if (!empty($fuente['condicion'])) {
                $where[] = $fuente['condicion'];
            }
            // Las fechas y el usuario se empujan DENTRO de cada consulta, no
            // se dejan para el final: filtrar después obligaría a materializar
            // el histórico completo de todas las tablas antes de descartarlo.
            if (!empty($f['desde'])) {
                $where[] = $fuente['fecha'] . ' >= ?';
                $params[] = $f['desde'] . ' 00:00:00';
            }
            if (!empty($f['hasta'])) {
                $where[] = $fuente['fecha'] . ' <= ?';
                $params[] = $f['hasta'] . ' 23:59:59';
            }
            if (!empty($f['usuario_id'])) {
                $where[] = $fuente['usuario'] . ' = ?';
                $params[] = (int) $f['usuario_id'];
            }

            $partes[] = $fuente['sql'] . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        }

        $union = implode("\nUNION ALL\n", $partes);

        $filtros = ['1=1'];
        if (!empty($f['modulo'])) {
            $filtros[] = 't.modulo = ?';
            $params[] = (string) $f['modulo'];
        }
        if (!empty($f['resultado'])) {
            $filtros[] = 't.resultado = ?';
            $params[] = (string) $f['resultado'];
        }
        if (trim((string) ($f['texto'] ?? '')) !== '') {
            $filtros[] = '(t.accion LIKE ? OR t.detalle LIKE ? OR t.usuario_nombre LIKE ?)';
            $like = '%' . trim((string) $f['texto']) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $condicion = implode(' AND ', $filtros);

        $total = (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM (\n{$union}\n) t WHERE {$condicion}",
            $params
        );

        $filas = $this->fetchAll(
            "SELECT * FROM (\n{$union}\n) t
              WHERE {$condicion}
           ORDER BY t.fecha DESC, t.ref_id DESC
              LIMIT {$porPagina} OFFSET {$offset}",
            $params
        ) ?: [];

        return [
            'filas'      => $filas,
            'total'      => $total,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int) ceil($total / $porPagina)),
            'por_pagina' => $porPagina,
        ];
    }

    /** Los últimos movimientos, para la tarjeta de Configuración. */
    public function recientes(int $limite = 8): array
    {
        $limite = max(1, min(50, $limite));
        return $this->fetchAll(
            "SELECT b.id, b.creado_en, b.usuario_id, b.modulo, b.accion, b.resultado,
                    COALESCE(b.usuario_nombre, u.nombre, 'Sistema') AS usuario_nombre
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
           ORDER BY b.id DESC
              LIMIT {$limite}"
        ) ?: [];
    }

    /** Un renglón concreto de la bitácora propia, con su detalle completo. */
    public function detalle(int $id): ?array
    {
        $fila = $this->fetchOne(
            "SELECT b.*, COALESCE(b.usuario_nombre, u.nombre, 'Sistema') AS nombre_mostrado,
                    s.nombre AS sociedad_nombre
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
               LEFT JOIN sociedades s ON s.id = b.sociedad_id
              WHERE b.id = ? LIMIT 1",
            [$id]
        );
        return $fila ?: null;
    }

    /** Quién aparece en la bitácora, para el desplegable del filtro. */
    public function usuariosConActividad(): array
    {
        return $this->fetchAll(
            "SELECT DISTINCT b.usuario_id AS id,
                    COALESCE(u.nombre, b.usuario_nombre) AS nombre
               FROM bitacora b
               LEFT JOIN usuarios u ON u.id = b.usuario_id
              WHERE b.usuario_id IS NOT NULL
           ORDER BY nombre ASC"
        ) ?: [];
    }

    /** Cuánto ocupa y desde cuándo hay registro. Para la tarjeta y la poda. */
    public function estado(): array
    {
        try {
            $fila = $this->fetchOne(
                "SELECT COUNT(*) AS total, MIN(creado_en) AS desde, MAX(creado_en) AS hasta FROM bitacora"
            ) ?: [];
            $hoy = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM bitacora WHERE creado_en >= CURDATE()"
            );
            $denegados = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM bitacora WHERE resultado = 'denegado' AND creado_en >= (NOW() - INTERVAL 30 DAY)"
            );
        } catch (Throwable $e) {
            return ['total' => 0, 'desde' => null, 'hasta' => null, 'hoy' => 0, 'denegados_30d' => 0];
        }

        return [
            'total'         => (int) ($fila['total'] ?? 0),
            'desde'         => $fila['desde'] ?? null,
            'hasta'         => $fila['hasta'] ?? null,
            'hoy'           => $hoy,
            'denegados_30d' => $denegados,
        ];
    }

    /**
     * Borra lo anterior a N días. Solo toca la tabla propia: el historial de
     * los módulos es dato de trabajo, no registro de auditoría, y borrarlo
     * desde aquí dejaría a Seguimiento sin sus anotaciones.
     */
    public function depurar(int $dias): int
    {
        $dias = max(30, (int) $dias);
        return (int) $this->execute(
            "DELETE FROM bitacora WHERE creado_en < (NOW() - INTERVAL {$dias} DAY)"
        );
    }
}
