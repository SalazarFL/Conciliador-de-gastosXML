<?php
/**
 * Revisión de salud de una instalación.
 *
 * Existe por el problema del modelo local: la aplicación corre en la
 * computadora de cada persona y la base de datos es una sola, en el servidor.
 * Cuando alguien dice "no me funciona", quien mantiene el sistema no tiene su
 * pantalla enfrente. Esto convierte esa llamada en una lista concreta: qué
 * está bien, qué está mal y qué hay que hacer.
 *
 * Casi todo lo que puede fallar en este modelo es de instalación, no de
 * lógica: la carpeta compartida sin configurar o todavía sincronizando, PHP
 * sin una extensión, la base actualizada pero el código no (o al revés).
 * Cada revisión devuelve estado + qué hacer, en ese orden.
 *
 * Estados: 'ok' · 'aviso' (funciona, pero algo hay que atender) ·
 * 'error' (algo no va a funcionar).
 */

require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/DocumentoArchivo.php';

class Diagnostico
{
    /** Cambios de estructura que el código de esta versión da por hechos. */
    private const ESQUEMA_ESPERADO = [
        ['facturas_xml', 'sociedad_id', 'database/migration_sociedades_alcance.sql'],
        ['usuarios', 'sociedad_id', 'database/migration_sociedad_por_usuario.sql'],
        ['facturas_erp', 'sociedad_id', 'database/migration_facturas_erp.sql'],
        ['correo_cuenta_sociedades', null, 'database/migration_sociedades_alcance.sql'],
        ['proveedor_alias', null, 'database/migration_proveedor_alias.sql'],
        ['correo_incidencias', 'descartada', 'database/migration_correo_incidencias_descarte.sql'],
        ['correo_incidencias_descartes', null, 'database/migration_correo_incidencias_descarte.sql'],
    ];

    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Todas las revisiones. Devuelve ['estado' => ..., 'revisiones' => [...]]. */
    public function ejecutar()
    {
        $revisiones = array_merge(
            [$this->php(), $this->extensiones(), $this->configuracionDeEstaMaquina(),
             $this->baseDeDatos()],
            $this->esquema(),
            [$this->carpetaCompartida(), $this->escrituraEnCarpeta(), $this->rutasAbsolutas(),
             $this->documentosDentroDelProyecto(), $this->archivosFaltantes()]
        );

        $peor = 'ok';
        foreach ($revisiones as $r) {
            if ($r['estado'] === 'error') {
                $peor = 'error';
            } elseif ($r['estado'] === 'aviso' && $peor === 'ok') {
                $peor = 'aviso';
            }
        }

        return [
            'estado' => $peor,
            'generado_en' => date('Y-m-d H:i:s'),
            'equipo' => php_uname('n'),
            'revisiones' => $revisiones,
        ];
    }

    // ── Revisiones ────────────────────────────────────────────────────

    private function php()
    {
        $version = PHP_VERSION;
        $suficiente = version_compare($version, '7.4', '>=');
        return $this->resultado(
            'PHP',
            $suficiente ? 'ok' : 'error',
            'Versión ' . $version,
            $suficiente ? '' : 'Instala PHP 7.4 o superior (el XAMPP actual trae PHP 8).'
        );
    }

    private function extensiones()
    {
        $necesarias = ['pdo_mysql' => 'leer y escribir en la base',
                       'imap' => 'capturar facturas del correo',
                       'zip' => 'descargar conciliaciones en ZIP',
                       'mbstring' => 'nombres con tildes y ñ'];
        $faltan = [];
        foreach ($necesarias as $ext => $paraQue) {
            if (!extension_loaded($ext)) {
                $faltan[] = $ext . ' (' . $paraQue . ')';
            }
        }
        return $this->resultado(
            'Extensiones de PHP',
            $faltan ? 'error' : 'ok',
            $faltan ? 'Faltan: ' . implode(', ', $faltan) : 'Todas presentes',
            $faltan ? 'Actívalas en php.ini quitando el ";" de la línea extension=… y reinicia Apache.' : ''
        );
    }

    /**
     * ¿Esta computadora sabe a qué base conectarse, y es la compartida?
     *
     * Va antes que la conexión porque los dos fallos se ven igual desde la
     * pantalla y el remedio no tiene nada que ver: falta un archivo de
     * configuración, o el servidor no contesta.
     */
    private function configuracionDeEstaMaquina()
    {
        $nombre = 'Configuración de esta computadora';
        if (!is_file(dirname(__DIR__) . '/config/local.php')) {
            return $this->resultado($nombre, 'error',
                'Falta app/config/local.php',
                "Copia la plantilla y escribe los datos del servidor de base de datos:\n"
                . '  copy app\\config\\local.ejemplo.php app\\config\\local.php');
        }

        try {
            $c = require dirname(__DIR__) . '/config/database.php';
        } catch (Throwable $e) {
            return $this->resultado($nombre, 'error', $e->getMessage(),
                'Compara app/config/local.php con app/config/local.ejemplo.php.');
        }

        // Apuntar al MySQL de la propia máquina no da ningún error visible: la
        // aplicación abre, se ve normal y el trabajo se guarda en una base que
        // nadie más lee. Por eso se avisa aquí y no cuando ya es tarde.
        $propia = in_array(strtolower((string) $c['host']), ['localhost', '127.0.0.1', '::1'], true);
        return $this->resultado(
            $nombre,
            $propia ? 'aviso' : 'ok',
            "Base {$c['database']} en {$c['host']}:{$c['port']}",
            $propia
                ? 'La base está en esta misma computadora, no en el servidor. Si ya existe '
                  . 'la base compartida, corrige "host" en app/config/local.php: lo que '
                  . 'trabajes aquí no lo va a ver nadie más.'
                : ''
        );
    }

    private function baseDeDatos()
    {
        // Sin configuración no hay nada que probar, y la revisión de arriba ya
        // dijo qué hacer. Insistir aquí solo agrega un consejo equivocado
        // —revisar la red— sobre un problema que no es de red.
        if (!is_file(dirname(__DIR__) . '/config/local.php')) {
            return $this->resultado('Base de datos', 'aviso',
                'No se pudo probar: falta la configuración de esta computadora', '');
        }

        try {
            $pdo = $this->pdo();
            $config = require dirname(__DIR__) . '/config/database.php';
            $servidor = $pdo->query('SELECT VERSION()')->fetchColumn();

            // Diez viajes de ida y vuelta. Con la base en el servidor, esta
            // latencia se paga en CADA consulta, y una pantalla hace cientos:
            // es lo que decide si la aplicación se siente ágil o pesada.
            $inicio = microtime(true);
            for ($i = 0; $i < 10; $i++) {
                $pdo->query('SELECT 1')->fetchColumn();
            }
            $ms = (microtime(true) - $inicio) * 100;

            $lenta = $ms >= 40;
            return $this->resultado(
                'Base de datos',
                $lenta ? 'aviso' : 'ok',
                "Conectada a {$config['database']} en {$config['host']} ({$servidor}), "
                . number_format($ms, 1) . ' ms por consulta',
                $lenta
                    ? 'Cada consulta tarda más de 40 ms y las pantallas hacen muchas. Revisa '
                      . 'que el servidor esté en un centro de datos cercano y que la conexión '
                      . 'no esté saturada.'
                    : ''
            );
        } catch (Throwable $e) {
            return $this->resultado(
                'Base de datos',
                'error',
                'No se pudo conectar: ' . $e->getMessage(),
                'Revisa que el servidor de base de datos esté encendido y que esta computadora '
                . 'llegue a él (VPN o Tailscale encendidos, si aplica).'
            );
        }
    }

    private function esquema()
    {
        $revisiones = [];
        $pendientes = [];
        try {
            $pdo = $this->pdo();
            foreach (self::ESQUEMA_ESPERADO as [$tabla, $columna, $archivo]) {
                $existe = $columna === null
                    ? $this->existeTabla($pdo, $tabla)
                    : $this->existeColumna($pdo, $tabla, $columna);
                if (!$existe) {
                    $pendientes[] = $archivo;
                }
            }
            // Al revés: la base todavía guarda XML como texto.
            if ($this->existeColumna($pdo, 'facturas_xml', 'xml_contenido')) {
                $pendientes[] = 'database/migration_rutas_relativas.sql';
            }
        } catch (Throwable $e) {
            return [$this->resultado('Estructura de la base', 'aviso',
                'No se pudo revisar: ' . $e->getMessage(), '')];
        }

        $pendientes = array_values(array_unique($pendientes));
        $revisiones[] = $this->resultado(
            'Estructura de la base',
            $pendientes ? 'error' : 'ok',
            $pendientes
                ? 'Faltan ' . count($pendientes) . ' migración(es) por aplicar'
                : 'Al día con esta versión del código',
            $pendientes
                ? "Aplícalas en orden:\n"
                  . implode("\n", array_map(function ($a) {
                        return '  mysql -u root bd_xmlconcilia < ' . $a;
                    }, $pendientes))
                : ''
        );
        return $revisiones;
    }

    private function carpetaCompartida()
    {
        $raiz = RutaDocumento::raiz();
        if ($raiz === '') {
            return $this->resultado('Carpeta compartida', 'error',
                'Sin configurar',
                'Entra a Correo y escribe la ruta de la carpeta sincronizada de SharePoint '
                . 'en esta computadora (algo como C:\\Users\\<usuario>\\<Empresa>\\Documentos '
                . 'compartidos - Facturas).');
        }
        if (!is_dir($raiz)) {
            return $this->resultado('Carpeta compartida', 'error',
                'Configurada en ' . $raiz . ', pero no existe',
                'Si es la carpeta de SharePoint, ábrela en el Explorador y espera a que '
                . 'termine de sincronizar. Si la ruta cambió, corrígela en Correo.');
        }
        return $this->resultado('Carpeta compartida', 'ok', $raiz, '');
    }

    private function escrituraEnCarpeta()
    {
        $raiz = RutaDocumento::raiz();
        if ($raiz === '' || !is_dir($raiz)) {
            return $this->resultado('Permisos de escritura', 'aviso',
                'No se pudo probar (la carpeta compartida no está disponible)', '');
        }
        $ok = RutaDocumento::permiteEscritura($raiz);
        return $this->resultado(
            'Permisos de escritura',
            $ok ? 'ok' : 'error',
            $ok ? 'Se puede escribir en la carpeta compartida' : 'La carpeta no permite escritura',
            $ok ? '' : 'Pide permiso de edición sobre la biblioteca de SharePoint: con permiso de '
                     . 'solo lectura se pueden consultar documentos, pero no importar.'
        );
    }

    private function rutasAbsolutas()
    {
        try {
            $n = 0;
            foreach ([['facturas_xml', 'ruta_xml'], ['facturas_xml', 'ruta_pdf'],
                      ['correo_bandeja', 'archivo_xml'], ['devoluciones', 'ruta_pdf']] as [$tabla, $campo]) {
                $n += (int) $this->pdo()->query(
                    "SELECT COUNT(*) FROM {$tabla}
                     WHERE {$campo} REGEXP '^([A-Za-z]:|\\\\\\\\|/)'"
                )->fetchColumn();
            }
        } catch (Throwable $e) {
            return $this->resultado('Rutas de documentos', 'aviso',
                'No se pudo revisar: ' . $e->getMessage(), '');
        }

        return $this->resultado(
            'Rutas de documentos',
            $n > 0 ? 'aviso' : 'ok',
            $n > 0
                ? $n . ' fila(s) guardan la ruta completa en vez de la relativa'
                : 'Todas relativas a la carpeta compartida',
            $n > 0
                ? "Esas filas solo abren en la computadora que las creó. Para arreglarlas:\n"
                  . "  php cli/migrar_rutas_relativas.php            (revisa)\n"
                  . "  php cli/migrar_rutas_relativas.php --aplicar  (corrige)"
                : ''
        );
    }

    private function documentosDentroDelProyecto()
    {
        $proyecto = dirname(__DIR__, 2);
        $sospechosas = [
            'storage/correo/xml', 'storage/correo/pdf', 'storage/correo/sin_identificar',
            'storage/devoluciones', 'public/uploads/xml_queue',
        ];
        $encontrados = [];
        foreach ($sospechosas as $rel) {
            $dir = $proyecto . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $n = $this->contarArchivos($dir);
            if ($n > 0) {
                $encontrados[] = "{$rel} ({$n})";
            }
        }
        return $this->resultado(
            'Documentos fuera de la carpeta compartida',
            $encontrados ? 'aviso' : 'ok',
            $encontrados ? implode(', ', $encontrados) : 'Ninguno: el proyecto no guarda documentos',
            $encontrados
                ? "Quedaron documentos dentro del proyecto; nadie más los ve. Muévelos con:\n"
                  . '  php cli/migrar_rutas_relativas.php --aplicar'
                : ''
        );
    }

    private function archivosFaltantes()
    {
        try {
            $filas = $this->pdo()->query(
                "SELECT ruta_xml FROM facturas_xml
                 WHERE ruta_xml IS NOT NULL AND ruta_xml <> ''
                 ORDER BY id DESC LIMIT 200"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return $this->resultado('Documentos recientes en disco', 'aviso',
                'No se pudo revisar: ' . $e->getMessage(), '');
        }

        $revisados = count($filas);
        if ($revisados === 0) {
            return $this->resultado('Documentos recientes en disco', 'ok', 'No hay documentos que revisar', '');
        }

        $faltan = 0;
        foreach ($filas as $ruta) {
            if (!RutaDocumento::existe($ruta)) {
                $faltan++;
            }
        }

        // Que falten todos apunta a la carpeta compartida (mal configurada o
        // sin sincronizar); que falten algunos, a documentos movidos a mano.
        $estado = $faltan === 0 ? 'ok' : ($faltan === $revisados ? 'error' : 'aviso');
        return $this->resultado(
            'Documentos recientes en disco',
            $estado,
            $faltan === 0
                ? "Los {$revisados} más recientes se abren correctamente"
                : "{$faltan} de los {$revisados} más recientes no se encuentran",
            $faltan === 0 ? '' : ($faltan === $revisados
                ? 'No se encontró NINGUNO: casi seguro la carpeta compartida apunta al lugar '
                  . 'equivocado o SharePoint no ha terminado de bajar los archivos.'
                : "Probablemente se movieron a mano. Si siguen dentro de la carpeta "
                  . "compartida, esto vuelve a ubicarlos sin moverlos:\n"
                  . '  php cli/organizar_documentos.php')
        );
    }

    // ── Apoyo ─────────────────────────────────────────────────────────

    private function pdo()
    {
        if ($this->pdo === null) {
            $c = require dirname(__DIR__) . '/config/database.php';
            $this->pdo = new PDO(
                $c['dsn'],
                $c['username'],
                $c['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 // El diagnóstico se corre justamente cuando algo no responde:
                 // tiene que contestar, no quedarse esperando al servidor.
                 PDO::ATTR_TIMEOUT => 10]
            );
        }
        return $this->pdo;
    }

    private function existeTabla(PDO $pdo, $tabla)
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$tabla]);
        return (bool) $stmt->fetchColumn();
    }

    private function existeColumna(PDO $pdo, $tabla, $columna)
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$tabla, $columna]);
        return (bool) $stmt->fetchColumn();
    }

    private function contarArchivos($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) {
            // .gitkeep y demás marcadores no son documentos.
            if ($item->isFile() && strpos($item->getFilename(), '.') !== 0) {
                $n++;
            }
        }
        return $n;
    }

    private function resultado($nombre, $estado, $detalle, $queHacer)
    {
        return [
            'nombre' => $nombre,
            'estado' => $estado,
            'detalle' => $detalle,
            'que_hacer' => $queHacer,
        ];
    }
}
