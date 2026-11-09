<?php
/**
 * Respaldo de la base de datos, en la computadora que lo genera.
 *
 * Por qué existe: la base vive en una sola computadora y no siempre se puede
 * llegar a ella —Tailscale se cae, la máquina se apaga—. Cuando eso pasa nadie
 * puede trabajar y tampoco se puede sacar una copia, que es justo cuando más
 * falta hace. Por eso la máquina que hospeda la base deja escrita su copia
 * sin que nadie se la pida:
 *
 *   máquina con la base  ──> storage/backups/RESPALDOS/*.sql.gz
 *          mysqldump
 *
 * Antes el destino era _TRABAJO/RESPALDOS de la carpeta compartida, que
 * OneDrive repartía a las demás. Desde el 27/09/2026 no hay carpeta
 * compartida, y a SharePoint van XML y PDF y nada más (AlmacenEnGraph lo
 * rechaza): un volcado de la base no es un documento de ninguna empresa.
 * Llevarlo a otra computadora es copiarlo a mano.
 *
 * El volcado corre SIEMPRE contra la base que tenga configurada la computadora
 * donde se ejecuta. En la que hospeda la base eso es local y tarda menos de un
 * minuto; desde otra máquina sale por la red y solo sirve si la red está bien
 * —que es el caso que este archivo existe para evitar—.
 *
 * Se comprime a .gz: 150 MB por respaldo contra 20 MB, en un disco que anda
 * justo.
 */

require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/AlmacenDocumentos.php';

class RespaldoBase
{
    /** Subcarpeta de storage/backups donde quedan los respaldos. */
    public const CARPETA = 'RESPALDOS';

    /** Cuántos se conservan; los viejos se borran. */
    public const CONSERVAR = 5;

    /** Nombre de la tarea programada de Windows. */
    public const TAREA = 'XMLConcilia_RespaldoBase';

    /**
     * Sin --routines: el usuario `xmlconcilia` no puede hacer
     * SHOW CREATE PROCEDURE y mysqldump aborta entero si se le pide. No se
     * pierde nada — el único procedimiento que quedaba, `sp_marcar_revisado`,
     * era del módulo de conciliaciones, retirado junto con sus tablas.
     */
    public const SIN_RUTINAS = true;

    // ── Ubicaciones ────────────────────────────────────────────────

    private static function storage($sub = '')
    {
        $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'correo';
        $ruta = $sub !== '' ? $base . DIRECTORY_SEPARATOR . $sub : $base;
        if (!is_dir($ruta) && !@mkdir($ruta, 0777, true) && !is_dir($ruta)) {
            throw new RuntimeException('No se pudo crear ' . $ruta);
        }
        return $ruta;
    }

    /** Estado de la última corrida, que es lo que la pantalla consulta. */
    public static function rutaEstado()
    {
        return self::storage() . DIRECTORY_SEPARATOR . 'respaldo_estado.json';
    }

    /** Un respaldo a la vez: el botón y la tarea nocturna pueden coincidir. */
    public static function rutaLock()
    {
        return self::storage() . DIRECTORY_SEPARATOR . 'respaldo.lock';
    }

    /**
     * Donde quedan los .sql.gz: storage/backups/RESPALDOS de esta
     * computadora. La crea si falta. Ver el encabezado: ni la carpeta
     * compartida, que ya no existe, ni SharePoint, que es solo de documentos.
     */
    public static function carpetaDestino()
    {
        $local = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'backups' . DIRECTORY_SEPARATOR . self::CARPETA;
        if (!is_dir($local) && !@mkdir($local, 0777, true) && !is_dir($local)) {
            throw new RuntimeException('No se pudo crear ' . $local);
        }
        return $local;
    }

    /** Cliente de volcado del servidor de base de datos. */
    public static function rutaMysqldump()
    {
        $candidatos = [
            trim((string) getenv('XMLCONCILIA_MARIADB_DUMP')),
            'C:\\WebServer\\MariaDB114\\bin\\mariadb-dump.exe',
            'C:\\WebServer\\MariaDB114\\bin\\mysqldump.exe',
        ];
        foreach ($candidatos as $c) {
            if (@is_file($c)) {
                return $c;
            }
        }
        // Último recurso: el PATH. MariaDB conserva también el alias mysqldump.
        $nombres = DIRECTORY_SEPARATOR === '\\'
            ? ['where mariadb-dump', 'where mysqldump']
            : ['which mariadb-dump', 'which mysqldump'];
        foreach ($nombres as $cmd) {
            $salida = [];
            @exec($cmd . ' 2>&1', $salida, $codigo);
            if ($codigo === 0 && !empty($salida[0]) && @is_file(trim($salida[0]))) {
                return trim($salida[0]);
            }
        }
        return null;
    }

    // ── Estado ─────────────────────────────────────────────────────

    public static function leerEstado()
    {
        $ruta = self::rutaEstado();
        if (!is_file($ruta)) {
            return null;
        }
        $datos = json_decode((string) @file_get_contents($ruta), true);
        return is_array($datos) ? $datos : null;
    }

    private static function escribirEstado(array $datos)
    {
        @file_put_contents(
            self::rutaEstado(),
            json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Respaldos que hay hoy en esta computadora, del más nuevo al más viejo.
     */
    public static function listar()
    {
        try {
            $carpeta = self::carpetaDestino();
        } catch (Throwable $e) {
            return [];
        }
        $archivos = @glob($carpeta . DIRECTORY_SEPARATOR . '*.sql.gz');
        if (!is_array($archivos)) {
            return [];
        }
        $filas = [];
        foreach ($archivos as $a) {
            $filas[] = [
                'nombre'    => basename($a),
                'bytes'     => (int) @filesize($a),
                'fecha'     => date('Y-m-d H:i:s', (int) @filemtime($a)),
                'timestamp' => (int) @filemtime($a),
            ];
        }
        usort($filas, function ($x, $y) { return $y['timestamp'] - $x['timestamp']; });
        return $filas;
    }

    /**
     * Todo lo que la tarjeta de respaldo necesita para dibujarse.
     *
     * Vive aquí y no en un controlador porque la piden dos: la pantalla de
     * Configuración, que es donde se administra, y el sondeo de
     * /diagnostico/respaldo/estado, que la refresca mientras corre. Armarla en
     * un solo lugar evita que el render inicial y el sondeo digan cosas
     * distintas.
     */
    public static function panel()
    {
        $carpeta = '';
        $error = '';
        try {
            $carpeta = self::carpetaDestino();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $archivos = self::listar();
        foreach ($archivos as $i => $a) {
            $archivos[$i]['tamano'] = self::humano($a['bytes']);
        }

        return [
            'estado'       => self::leerEstado(),
            'archivos'     => array_slice($archivos, 0, 10),
            'carpeta'      => $carpeta,
            'carpetaError' => $error,
            'conserva'     => self::CONSERVAR,
            'automatico'   => self::tareaProgramada(),
            'mysqldump'    => self::rutaMysqldump(),
        ];
    }

    /** ¿Existe la tarea nocturna en este Windows? Devuelve la hora si la hay. */
    public static function tareaProgramada()
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            return ['activo' => false, 'hora' => ''];
        }
        $salida = [];
        $codigo = 1;
        @exec('schtasks /query /TN "' . self::TAREA . '" /FO LIST 2>&1', $salida, $codigo);
        if ($codigo !== 0) {
            return ['activo' => false, 'hora' => ''];
        }
        // La hora se saca del "Next Run Time"/"Hora próxima ejecución", que
        // schtasks localiza: se busca el patrón de hora, no la etiqueta.
        $hora = '';
        foreach ($salida as $linea) {
            if (preg_match('/(\d{1,2}:\d{2}):\d{2}/', $linea, $m)) {
                $hora = $m[1];
                break;
            }
        }
        return ['activo' => true, 'hora' => $hora];
    }

    // ── El trabajo ─────────────────────────────────────────────────

    /**
     * Genera el respaldo. Devuelve el estado final (el mismo que queda escrito
     * en respaldo_estado.json). No lanza: los errores viajan en el estado,
     * porque quien llama casi siempre es una tarea de fondo sin nadie mirando.
     *
     * @param string $motivo 'manual' o 'automatico', solo para el registro.
     */
    public static function ejecutar($motivo = 'manual')
    {
        @set_time_limit(0);

        $lock = @fopen(self::rutaLock(), 'c');
        if ($lock === false) {
            return self::fallar($motivo, 'No se pudo abrir el archivo de bloqueo.');
        }
        // Sin bloquear: si ya hay uno corriendo se contesta al momento en vez
        // de dejar la petición del navegador esperando un minuto.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $previo = self::leerEstado();
            if (is_array($previo) && ($previo['estado'] ?? '') === 'corriendo') {
                return $previo;
            }
            return self::fallar($motivo, 'Ya hay un respaldo en curso.');
        }

        $temporal = null;
        $parcial  = null;
        try {
            $config = require dirname(__DIR__) . '/config/database.php';

            $inicio = microtime(true);
            self::escribirEstado([
                'estado'      => 'corriendo',
                'fase'        => 'exportando',
                'motivo'      => $motivo,
                'equipo'      => self::equipo(),
                'origen'      => $config['host'] . ':' . $config['port'],
                'iniciado_en' => date('Y-m-d H:i:s'),
                'mensaje'     => 'Exportando la base...',
            ]);

            $mysqldump = self::rutaMysqldump();
            if ($mysqldump === null) {
                throw new RuntimeException(
                    'No se encontró mariadb-dump.exe ni mysqldump.exe en la instalación del servidor o en el PATH.'
                );
            }
            if (!function_exists('exec')) {
                throw new RuntimeException('exec() está deshabilitado en PHP; no se puede ejecutar mysqldump.');
            }

            $carpeta = self::carpetaDestino();
            if (!RutaDocumento::permiteEscritura($carpeta)) {
                throw new RuntimeException('No se puede escribir en la carpeta de respaldos: ' . $carpeta);
            }

            $tablas = self::tablasBase($config);
            if (!$tablas) {
                throw new RuntimeException('La base ' . $config['database'] . ' no tiene ninguna tabla.');
            }

            $temporal = self::storage('tmp') . DIRECTORY_SEPARATOR
                . 'respaldo_' . bin2hex(random_bytes(6)) . '.sql';

            self::volcar($mysqldump, $config, $tablas, $temporal);

            $crudo = (int) @filesize($temporal);
            if ($crudo <= 0) {
                throw new RuntimeException('mysqldump terminó pero el archivo quedó vacío.');
            }

            self::escribirEstado([
                'estado'      => 'corriendo',
                'fase'        => 'comprimiendo',
                'motivo'      => $motivo,
                'equipo'      => self::equipo(),
                'origen'      => $config['host'] . ':' . $config['port'],
                'iniciado_en' => date('Y-m-d H:i:s', (int) $inicio),
                'mensaje'     => 'Comprimiendo (' . self::humano($crudo) . ')...',
            ]);

            // El nombre del equipo va en el archivo a propósito: las dos
            // máquinas pueden generar respaldos, y una copia que se lleva a
            // mano de una a otra tiene que seguir diciendo de dónde salió.
            $nombre = sprintf(
                '%s_%s_%s.sql.gz',
                $config['database'],
                self::equipo(),
                date('Ymd_His')
            );
            // Se comprime a un nombre temporal y se renombra al final: si el
            // proceso se corta a la mitad, lo que queda no se confunde con un
            // .gz bueno, que parece entero hasta que falla al abrir.
            $parcial = $carpeta . DIRECTORY_SEPARATOR . $nombre . '.parcial';
            $destino = $carpeta . DIRECTORY_SEPARATOR . $nombre;

            self::comprimir($temporal, $parcial);
            @unlink($temporal);
            $temporal = null;

            if (!@rename($parcial, $destino)) {
                throw new RuntimeException('No se pudo renombrar el respaldo.');
            }
            $parcial = null;

            $borrados = self::podar($carpeta);

            // Con los documentos archivados en disco, la base sola no alcanza:
            // sin los XML y PDF, restaurarla deja filas apuntando a la nada.
            // Un fallo aquí NO tumba el respaldo de la base, que ya está hecho.
            $documentos = AlmacenDocumentos::archivaEnDisco() ? self::copiarDocumentos($carpeta) : null;

            $estado = [
                'estado'       => 'ok',
                'fase'         => 'listo',
                'motivo'       => $motivo,
                'equipo'       => self::equipo(),
                'origen'       => $config['host'] . ':' . $config['port'],
                'iniciado_en'  => date('Y-m-d H:i:s', (int) $inicio),
                'terminado_en' => date('Y-m-d H:i:s'),
                'segundos'     => round(microtime(true) - $inicio, 1),
                'archivo'      => $nombre,
                'carpeta'      => $carpeta,
                'bytes'        => (int) @filesize($destino),
                'bytes_crudo'  => $crudo,
                'tablas'       => count($tablas),
                'borrados'     => $borrados,
                'mensaje'      => 'Respaldo listo: ' . $nombre . ' (' . self::humano((int) @filesize($destino)) . ')'
                                . ($documentos === null ? '' : ' · ' . $documentos['mensaje']),
                'documentos'   => $documentos,
            ];
            self::escribirEstado($estado);
            return $estado;
        } catch (Throwable $e) {
            if ($temporal !== null) { @unlink($temporal); }
            if ($parcial !== null)  { @unlink($parcial); }
            return self::fallar($motivo, $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ── Piezas ─────────────────────────────────────────────────────

    /** Subcarpeta, dentro de la de respaldos, con el espejo de los documentos. */
    public const CARPETA_DOCUMENTOS = 'DOCUMENTOS';

    /**
     * Espejo de la carpeta de documentos en disco junto a los respaldos de la
     * base. robocopy /MIR copia solo lo que cambió, así que la noche que no
     * entró nada cuesta segundos. No lanza: devuelve ['ok', 'mensaje'].
     */
    private static function copiarDocumentos($carpetaRespaldos)
    {
        $origen = RutaDocumento::raiz();
        if ($origen === '' || !is_dir($origen)) {
            return ['ok' => false, 'mensaje' => 'documentos NO copiados: no hay carpeta de documentos'];
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            return ['ok' => false, 'mensaje' => 'documentos NO copiados: robocopy solo existe en Windows'];
        }
        $destino = $carpetaRespaldos . DIRECTORY_SEPARATOR . self::CARPETA_DOCUMENTOS;
        $inicio = microtime(true);
        $comando = 'robocopy ' . escapeshellarg($origen) . ' ' . escapeshellarg($destino)
            . ' /MIR /R:1 /W:1 /XJ /NFL /NDL /NP /NJH /NJS 2>&1';
        $salida = [];
        $codigo = 0;
        @exec($comando, $salida, $codigo);
        $segundos = round(microtime(true) - $inicio, 1);

        // robocopy: 0-7 es éxito (bits de "copió", "sobraba", etc.); 8 o más, fallos.
        if ($codigo >= 8) {
            return [
                'ok' => false,
                'mensaje' => 'documentos NO copiados (robocopy ' . $codigo . '): '
                    . mb_strimwidth(trim(implode(' ', $salida)), 0, 200, '…'),
            ];
        }

        return ['ok' => true, 'mensaje' => 'documentos copiados a ' . self::CARPETA_DOCUMENTOS . ' en ' . $segundos . ' s'];
    }

    /**
     * Solo tablas reales: las vistas se recrean al restaurar y pedírselas a
     * mysqldump exige el permiso SHOW VIEW, que el usuario del servidor puede
     * no tener. Pedir la lista aquí y pasarla explícita también hace que una
     * tabla nueva entre sola en el próximo respaldo.
     */
    private static function tablasBase(array $config)
    {
        $pdo = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $sql = 'SELECT table_name FROM information_schema.tables
                 WHERE table_schema = ? AND table_type = ? ORDER BY table_name';
        $st = $pdo->prepare($sql);
        $st->execute([$config['database'], 'BASE TABLE']);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * La contraseña va en un archivo temporal y no en la línea de comandos,
     * donde la vería cualquiera que liste procesos en esa máquina.
     */
    private static function volcar($mysqldump, array $config, array $tablas, $salida)
    {
        $cnf = self::storage('tmp') . DIRECTORY_SEPARATOR . 'my_' . bin2hex(random_bytes(6)) . '.cnf';
        $ini = "[client]\r\nuser=" . $config['username'] . "\r\npassword=" . $config['password']
             . "\r\nhost=" . $config['host'] . "\r\nport=" . $config['port'] . "\r\n";
        if (@file_put_contents($cnf, $ini) === false) {
            throw new RuntimeException('No se pudo escribir la configuración temporal de mysqldump.');
        }

        try {
            $args = [
                '"' . $mysqldump . '"',
                '--defaults-extra-file="' . $cnf . '"',
                '--single-transaction',   // copia consistente sin bloquear a nadie
                '--quick',
                '--default-character-set=utf8mb4',
                '--add-drop-table',
                '--result-file="' . $salida . '"',   // y no ">": la redirección mete BOM
                escapeshellarg($config['database']),
            ];
            foreach ($tablas as $t) {
                $args[] = escapeshellarg($t);
            }

            $lineas = [];
            $codigo = 1;
            @exec(implode(' ', $args) . ' 2>&1', $lineas, $codigo);

            if ($codigo !== 0) {
                $detalle = trim(implode(' ', array_slice($lineas, 0, 4)));
                throw new RuntimeException(
                    'mysqldump falló (código ' . $codigo . ')'
                    . ($detalle !== '' ? ': ' . $detalle : '.')
                );
            }
        } finally {
            @unlink($cnf);
        }
    }

    /** Comprime por trozos: el archivo no cabe entero en memoria. */
    private static function comprimir($origen, $destino)
    {
        $entrada = @fopen($origen, 'rb');
        if ($entrada === false) {
            throw new RuntimeException('No se pudo leer el volcado temporal.');
        }
        $gz = @gzopen($destino, 'wb6');
        if ($gz === false) {
            fclose($entrada);
            throw new RuntimeException('No se pudo crear el archivo comprimido del respaldo.');
        }
        try {
            while (!feof($entrada)) {
                $trozo = fread($entrada, 1048576);
                if ($trozo === false) {
                    throw new RuntimeException('Se cortó la lectura del volcado temporal.');
                }
                if ($trozo !== '' && gzwrite($gz, $trozo) === false) {
                    throw new RuntimeException('Se cortó la escritura del respaldo.');
                }
            }
        } finally {
            gzclose($gz);
            fclose($entrada);
        }
    }

    /**
     * Deja solo los últimos CONSERVAR: el disco de esta instalación anda
     * justo, y cinco copias alcanzan para volver atrás una semana.
     */
    private static function podar($carpeta)
    {
        $archivos = self::listar();
        $borrados = 0;
        foreach (array_slice($archivos, self::CONSERVAR) as $viejo) {
            if (@unlink($carpeta . DIRECTORY_SEPARATOR . $viejo['nombre'])) {
                $borrados++;
            }
        }
        // Restos de una corrida que se cortó a medias.
        foreach ((array) @glob($carpeta . DIRECTORY_SEPARATOR . '*.parcial') as $resto) {
            if (@filemtime($resto) < time() - 86400) {
                @unlink($resto);
            }
        }
        return $borrados;
    }

    private static function fallar($motivo, $mensaje)
    {
        $estado = [
            'estado'       => 'error',
            'fase'         => 'error',
            'motivo'       => $motivo,
            'equipo'       => self::equipo(),
            'terminado_en' => date('Y-m-d H:i:s'),
            'mensaje'      => $mensaje,
        ];
        self::escribirEstado($estado);
        return $estado;
    }

    public static function equipo()
    {
        $nombre = getenv('COMPUTERNAME');
        if (!$nombre) {
            $nombre = @php_uname('n');
        }
        // El nombre entra en el nombre del archivo: nada que confunda a Windows.
        return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $nombre) ?: 'equipo';
    }

    public static function humano($bytes)
    {
        $bytes = (float) $bytes;
        if ($bytes >= 1073741824) { return round($bytes / 1073741824, 1) . ' GB'; }
        if ($bytes >= 1048576)    { return round($bytes / 1048576, 1) . ' MB'; }
        if ($bytes >= 1024)       { return round($bytes / 1024) . ' KB'; }
        return (int) $bytes . ' B';
    }
}
