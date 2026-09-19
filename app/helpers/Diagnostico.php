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
        ['facturas_xml', 'archivo_faltante_en', 'database/migration_archivo_faltante.sql'],
        ['sociedades', 'almacen_documentos', 'database/migration_almacen_graph.sql'],
        ['facturas_xml', 'almacen', 'database/migration_almacen_graph.sql'],
        ['devoluciones', 'almacen', 'database/migration_almacen_graph.sql'],
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
             $this->documentosDentroDelProyecto(), $this->archivosFaltantes()],
            $this->sharepoint()
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
        $suficiente = version_compare($version, '8.4', '>=')
            && version_compare($version, '8.5', '<');
        $accion = '';
        if (version_compare($version, '8.4', '<')) {
            $accion = 'Instala PHP 8.4.x; las versiones anteriores ya no forman parte de la matriz certificada.';
        } elseif (version_compare($version, '8.5', '>=')) {
            $accion = 'Esta rama solo está certificada con PHP 8.4.x; vuelve a ejecutar la certificación antes de usar PHP 8.5 o superior.';
        }
        return $this->resultado(
            'PHP',
            $suficiente ? 'ok' : 'error',
            'Versión ' . $version,
            $accion
        );
    }

    private function extensiones()
    {
        $necesarias = ['pdo_mysql' => 'leer y escribir en la base',
                       'imap' => 'capturar facturas del correo',
                       'zip' => 'leer los XLSX que se cargan y generar los que se exportan',
                       'mbstring' => 'nombres con tildes y ñ',
                       'simplexml' => 'leer comprobantes XML',
                       'fileinfo' => 'validar archivos cargados',
                       'curl' => 'realizar conexiones HTTP seguras',
                       'openssl' => 'cifrar conexiones y datos sensibles'];
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
            // Con la fila entera y no solo la ruta: dónde está cada documento
            // lo dicen su columna `almacen` y su sociedad, y esta consulta no
            // pasa por los modelos que lo resuelven solos al leer.
            // Solo los de disco: los de SharePoint serían doscientas llamadas
            // a la red, y de esos se ocupa la revisión de SharePoint.
            $pdo = $this->pdo();
            $conAlmacen = $this->existeColumna($pdo, 'facturas_xml', 'almacen');
            $filas = $pdo->query(
                'SELECT ruta_xml, sociedad_id' . ($conAlmacen ? ', almacen' : '') . " FROM facturas_xml
                 WHERE ruta_xml IS NOT NULL AND ruta_xml <> ''"
                 . ($conAlmacen ? " AND almacen IS NULL AND ruta_xml NOT LIKE 'sharepoint:%'" : '') . '
                 ORDER BY id DESC LIMIT 200'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $this->resultado('Documentos recientes en disco', 'aviso',
                'No se pudo revisar: ' . $e->getMessage(), '');
        }

        $revisados = count($filas);
        if ($revisados === 0) {
            return $this->resultado('Documentos recientes en disco', 'ok', 'No hay documentos que revisar', '');
        }

        $faltan = 0;
        foreach ($filas as $fila) {
            $ruta = RutaDocumento::hidratarFila($fila, ['ruta_xml'])['ruta_xml'];
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

    /**
     * Las empresas que archivan en SharePoint: si esta computadora llega a la
     * biblioteca, si la tarea que la vigila sigue corriendo y si quedó algo
     * atascado. Sin ninguna empresa allá, no dice nada: no es ruido que
     * alguien tenga que aprender a ignorar.
     *
     * Pregunta a SharePoint UNA vez (el nombre de la biblioteca). Lo demás lo
     * sabe la base.
     */
    private function sharepoint()
    {
        try {
            $pdo = $this->pdo();
            if (!$this->existeColumna($pdo, 'sociedades', 'almacen_documentos')) {
                return [];
            }
            $empresas = $pdo->query("SELECT nombre FROM sociedades WHERE almacen_documentos = 'sharepoint' ORDER BY nombre")
                ->fetchAll(PDO::FETCH_COLUMN);
            $documentos = $this->existeColumna($pdo, 'facturas_xml', 'almacen')
                ? (int) $pdo->query("SELECT COUNT(*) FROM facturas_xml WHERE almacen = 'sharepoint'")->fetchColumn()
                : 0;
        } catch (Throwable $e) {
            return [$this->resultado('SharePoint', 'aviso', 'No se pudo revisar: ' . $e->getMessage(), '')];
        }
        if (!$empresas && $documentos === 0) {
            return [];
        }

        require_once __DIR__ . '/AlmacenEnGraph.php';
        $revisiones = [];
        $quienes = $empresas ? implode(', ', $empresas) : 'ninguna empresa, pero hay documentos allá';

        // 1. ¿Esta computadora llega?
        if (!AlmacenDocumentos::disponible(AlmacenEnGraph::TIPO)) {
            $revisiones[] = $this->resultado('SharePoint en esta computadora', 'error',
                'Sin credenciales. Archivan en SharePoint: ' . $quienes . " ({$documentos} documentos)",
                "Esta computadora no puede abrir ni guardar esos documentos. Copia la plantilla y completa los datos:\n"
                . "  copy app\\config\\graph.ejemplo.php app\\config\\local.graph.php\n"
                . 'El secreto lo tiene quien administra la aplicación en Microsoft Entra ID; no se manda por correo ni por chat.');
        } else {
            try {
                $biblioteca = AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO)->probar();
                $revisiones[] = $this->resultado('SharePoint en esta computadora', 'ok',
                    'Conectada a la biblioteca "' . $biblioteca . '". Archivan allá: ' . $quienes
                    . " ({$documentos} documentos)", '');
            } catch (Throwable $e) {
                $revisiones[] = $this->resultado('SharePoint en esta computadora', 'error',
                    'No contesta: ' . $e->getMessage(),
                    "Prueba la conexión paso a paso con:\n  php cli/probar_sharepoint.php\n"
                    . 'Si dice que falta un certificado raíz, revisa curl.cainfo en php.ini y reinicia Apache.');
            }
        }

        // 2. ¿La vigila alguien?
        try {
            $avisos = [];
            $ultima = $this->existeTabla($pdo, 'almacen_delta')
                ? $pdo->query("SELECT actualizado_en FROM almacen_delta WHERE almacen = 'sharepoint'")->fetchColumn()
                : false;
            if (!$ultima) {
                $avisos[] = 'la revisión de la biblioteca nunca corrió';
            } elseif (time() - strtotime((string) $ultima) > 3600) {
                $avisos[] = 'la revisión de la biblioteca no corre desde ' . $ultima;
            }
            $atascados = $this->existeTabla($pdo, 'almacen_pendientes')
                ? (int) $pdo->query('SELECT COUNT(*) FROM almacen_pendientes WHERE intentos >= 20')->fetchColumn()
                : 0;
            if ($atascados > 0) {
                $avisos[] = $atascados . ' borrado(s) en SharePoint que no se pudieron hacer después de 20 intentos';
            }
            $sinArchivo = (int) $pdo->query("SELECT COUNT(*) FROM facturas_xml
                WHERE almacen = 'sharepoint' AND archivo_faltante_en IS NOT NULL")->fetchColumn();
            $migracion = $this->existeTabla($pdo, 'almacen_migracion')
                ? (int) $pdo->query("SELECT COUNT(*) FROM almacen_migracion WHERE estado IN ('error', 'conflicto')")->fetchColumn()
                : 0;
            if ($migracion > 0) {
                $avisos[] = $migracion . ' archivo(s) de una migración a SharePoint con error o conflicto';
            }
            $revisiones[] = $this->resultado('Vigilancia de SharePoint', $avisos ? 'aviso' : 'ok',
                ($avisos ? ucfirst(implode('; ', $avisos)) . '. ' : 'La tarea programada revisa la biblioteca. ')
                    . $sinArchivo . ' documento(s) de SharePoint marcados sin archivo.',
                $avisos
                    ? "Revisa que la tarea programada de correo esté corriendo en alguna PC con credenciales. Detalle:\n"
                      . "  php cli/organizar_documentos.php --sharepoint --dry-run\n"
                      . "  php cli/migrar_documentos_a_graph.php --sociedad=N   (si hay una migración)\n"
                      . 'Los borrados atascados están en la tabla almacen_pendientes, con su error.'
                    : '');
        } catch (Throwable $e) {
            $revisiones[] = $this->resultado('Vigilancia de SharePoint', 'aviso', 'No se pudo revisar: ' . $e->getMessage(), '');
        }
        return $revisiones;
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
