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
 * lógica: SharePoint sin credenciales en esta computadora, PHP sin una
 * extensión, la base actualizada pero el código no (o al revés).
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
        ['facturas_xml', 'almacen', 'database/migration_almacen_graph.sql'],
        ['devoluciones', 'almacen', 'database/migration_almacen_graph.sql'],
        ['facturas_xml', 'tipo_cambio', 'database/migration_tipo_cambio.sql'],
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
            [$this->clavesDeBuzonCifradas()],
            $this->sharepoint(),
            [$this->documentosSinMigrar(), $this->rutasAbsolutas(), $this->documentosDentroDelProyecto()]
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
            // Esta no se ve por una columna que falte, sino por el tipo de una
            // que está: mientras usuarios.id tenga signo, las diecisiete
            // columnas que lo referencian no pueden tener clave foránea, y
            // borrar a una persona deja sus rastros apuntando al vacío.
            if ($this->usuariosIdConSigno($pdo)) {
                $pendientes[] = 'database/migration_usuarios_id_unsigned.sql';
            }
            // Tampoco se ve por una columna: `facturas_xml.sociedad_id` existe
            // desde hace tiempo, lo que faltaba era la clave. Sin ella, borrar
            // una empresa se lleva sus facturas a un sociedad_id inexistente.
            if (!$this->tieneClaveForanea($pdo, 'facturas_xml', 'sociedad_id', 'sociedades')) {
                $pendientes[] = 'database/migration_claves_sociedad.sql';
            }
            // El último grupo: roles, pagos e importaciones. Se pregunta por
            // rol_permisos porque es la que más duele sin clave —permisos
            // colgando de un rol borrado— y no depende de que haya datos.
            if (!$this->tieneClaveForanea($pdo, 'rol_permisos', 'rol_id', 'roles')) {
                $pendientes[] = 'database/migration_claves_rol_pago_importacion.sql';
            }
            // El seguimiento apunta a tres tablas según su `origen`, así que no
            // puede tener una clave directa: lleva tres columnas derivadas, una
            // por destino, y la clave va en ellas.
            if (!$this->existeColumna($pdo, 'seguimiento', 'ref_factura_xml')) {
                $pendientes[] = 'database/migration_seguimiento_referencias.sql';
            }
            // Y el resto: buzones, proveedores, cargas y listados.
            if (!$this->tieneClaveForanea($pdo, 'correo_indice', 'cuenta_id', 'correo_cuentas')) {
                $pendientes[] = 'database/migration_claves_correo_cargas.sql';
            }
            // Mientras `devoluciones.sociedad_id` admita NULL puede haber
            // devoluciones de nadie, y el resumen se las contaba a todas.
            if ($this->columnaAdmiteNull($pdo, 'devoluciones', 'sociedad_id')) {
                $pendientes[] = 'database/migration_devoluciones_sin_empresa.sql';
            }
            // Sin `cedula_norm` nada impide dos empresas con la misma cédula
            // escrita distinto (3-101-123456 y 3101123456).
            if (!$this->existeColumna($pdo, 'sociedades', 'cedula_norm')) {
                $pendientes[] = 'database/migration_sociedad_cedula_unica.sql';
            }
            // Y mientras `hash_xml` admita NULL, la unicidad de la bandeja no
            // deduplica: dos NULL no son iguales, así que el correo cuyo XML no
            // se pudo leer abre un renglón nuevo en cada reprocesamiento.
            if ($this->columnaAdmiteNull($pdo, 'correo_bandeja', 'hash_xml')) {
                $pendientes[] = 'database/migration_bandeja_dedup_nula.sql';
            }
            // Al revés otra vez: estas columnas sobran. `proveedor_normalizado`
            // no la escribe nadie desde el relleno de 002, así que está en NULL
            // o caduca, y arrastra un índice construido sobre eso.
            if ($this->existeColumna($pdo, 'facturas_xml', 'proveedor_normalizado')) {
                $pendientes[] = 'database/migration_limpieza_columnas_indices.sql';
            }
            // Y la moneda por omisión heredada de la plantilla mexicana.
            if ($this->monedaPorOmisionAjena($pdo)) {
                $pendientes[] = 'database/migration_comentarios_y_moneda.sql';
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

    /**
     * Que ninguna contraseña de buzón siga guardada sin cifrar.
     *
     * Por qué se revisa: antes, una contraseña sin el prefijo `enc:v1:` se leía
     * como base64 —que no es cifrado, es una forma de escribir— y el sistema
     * funcionaba igual. No fallaba nada, no avisaba nada, y mientras tanto esa
     * contraseña estaba en claro para cualquiera con la tabla, un respaldo o un
     * app.log viejo. Un agujero que no da síntomas no se cierra solo.
     *
     * Ahora Secreto::descifrar() no las lee, así que una fila así deja la cuenta
     * sin conectar; esta revisión es la que dice POR QUÉ, y qué hacer. Pasa al
     * restaurar un respaldo anterior al cifrado.
     */
    private function clavesDeBuzonCifradas()
    {
        $nombre = 'Contraseñas de los buzones';

        try {
            $pdo = $this->pdo();
            if (!$this->existeTabla($pdo, 'correo_cuentas')) {
                return $this->resultado($nombre, 'ok', 'Todavía no hay buzones registrados.', '');
            }

            $total = (int) $pdo->query('SELECT COUNT(*) FROM correo_cuentas')->fetchColumn();
            if ($total === 0) {
                return $this->resultado($nombre, 'ok', 'Todavía no hay buzones registrados.', '');
            }

            $sinCifrar = (int) $pdo->query(
                "SELECT COUNT(*) FROM correo_cuentas
                  WHERE password IS NULL OR password = '' OR password NOT LIKE 'enc:v1:%'"
            )->fetchColumn();
        } catch (Throwable $e) {
            // Sin base, la revisión de la base ya lo dijo; insistir aquí solo
            // agrega un consejo equivocado sobre un problema que no es este.
            return $this->resultado($nombre, 'ok', 'No se pudo comprobar (la base no responde).', '');
        }

        if ($sinCifrar === 0) {
            return $this->resultado($nombre, 'ok',
                $total . ' buzón(es), todas las contraseñas cifradas.', '');
        }

        return $this->resultado($nombre, 'error',
            $sinCifrar . ' de ' . $total . ' contraseña(s) de buzón NO están cifradas',
            "Esas cuentas no van a poder conectarse, y mientras sigan así su contraseña se lee "
            . "de la tabla o de un respaldo sin ninguna clave. Pásalas al formato cifrado:\n"
            . "  php cli/cifrar_claves_correo.php --ver     (dice qué haría)\n"
            . "  php cli/cifrar_claves_correo.php\n"
            . 'Si alguna sale como ilegible, vuelve a escribir su contraseña en Configuración → Correo.');
    }

    /**
     * Filas que guardan la ruta completa de un disco en vez de una relativa:
     * solo abren en la computadora que las escribió.
     *
     * No cuentan las de la zona de paso de cada PC (storage/correo/transito):
     * esas son de esa computadora a propósito —lo que se captura y se archiva
     * en la misma corrida no sale de ella—. Ver RutaDocumento::carpetaTrabajoLocal().
     */
    private function rutasAbsolutas()
    {
        try {
            $n = 0;
            foreach ([['facturas_xml', 'ruta_xml'], ['facturas_xml', 'ruta_pdf'],
                      ['correo_bandeja', 'archivo_xml'], ['devoluciones', 'ruta_pdf']] as [$tabla, $campo]) {
                $n += (int) $this->pdo()->query(
                    "SELECT COUNT(*) FROM {$tabla}
                     WHERE {$campo} REGEXP '^([A-Za-z]:|\\\\\\\\|/)'
                       AND REPLACE({$campo}, '\\\\', '/') NOT LIKE '%/storage/correo/transito/%'"
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
                : 'Ninguna guarda la ruta completa de un disco',
            $n > 0
                ? "Esas filas solo abren en la computadora que las creó. Son de antes de SharePoint: si apuntan a la "
                  . "carpeta compartida de antes, se pasan a relativas y después a SharePoint:\n"
                  . "  php cli/migrar_rutas_relativas.php --raiz=RUTA   (revisa; con --aplicar, corrige)\n"
                  . '  php cli/migrar_documentos_a_graph.php --sociedad=N --todo'
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
            'Documentos guardados dentro del proyecto',
            $encontrados ? 'aviso' : 'ok',
            $encontrados ? implode(', ', $encontrados) : 'Ninguno: el proyecto no guarda documentos',
            $encontrados
                ? "Quedaron documentos dentro del proyecto; nadie más los ve. Muévelos con:\n"
                  . '  php cli/migrar_rutas_relativas.php --aplicar'
                : ''
        );
    }

    /**
     * Lo que se archivó en la carpeta compartida antes de SharePoint y todavía
     * no se migró. Mientras quede algo, esos documentos se abren solo en las
     * computadoras que tengan esa carpeta, y no están en la biblioteca.
     *
     * Además de contarlos, prueba unos cuantos de los más recientes: si aquí
     * no abre ninguno, esta PC no tiene la carpeta de antes, y no es que
     * falten.
     */
    private function documentosSinMigrar()
    {
        $nombre = 'Documentos todavía en la carpeta compartida';
        try {
            $pdo = $this->pdo();
            if (!$this->existeColumna($pdo, 'facturas_xml', 'almacen')) {
                return $this->resultado($nombre, 'error', 'Falta la columna facturas_xml.almacen',
                    'Aplica database/migration_almacen_graph.sql: sin ella no se sabe qué documento está en SharePoint.');
            }
            $porEmpresa = $pdo->query(
                "SELECT COALESCE(s.nombre, 'Sin empresa') AS empresa, f.sociedad_id, COUNT(*) AS n
                   FROM facturas_xml f LEFT JOIN sociedades s ON s.id = f.sociedad_id
                  WHERE f.almacen IS NULL AND COALESCE(f.ruta_xml, '') <> '' AND f.ruta_xml NOT LIKE 'sharepoint:%'
                  GROUP BY f.sociedad_id, s.nombre ORDER BY n DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $muestra = $pdo->query(
                "SELECT ruta_xml, sociedad_id, almacen FROM facturas_xml
                  WHERE almacen IS NULL AND COALESCE(ruta_xml, '') <> '' AND ruta_xml NOT LIKE 'sharepoint:%'
                  ORDER BY id DESC LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $this->resultado($nombre, 'aviso', 'No se pudo revisar: ' . $e->getMessage(), '');
        }

        $total = array_sum(array_map('intval', array_column($porEmpresa, 'n')));
        if ($total === 0) {
            return $this->resultado($nombre, 'ok', 'Ninguno: todo está en SharePoint', '');
        }

        $abren = 0;
        foreach ($muestra as $fila) {
            $ruta = RutaDocumento::hidratarFila($fila, ['ruta_xml'])['ruta_xml'];
            if ($ruta !== '' && RutaDocumento::existe($ruta)) {
                $abren++;
            }
        }
        $detalle = [];
        foreach ($porEmpresa as $fila) {
            $detalle[] = $fila['empresa'] . ': ' . (int) $fila['n'];
        }
        $comandos = [];
        foreach ($porEmpresa as $fila) {
            if ((int) $fila['sociedad_id'] > 0) {
                $comandos[] = '  php cli/migrar_documentos_a_graph.php --sociedad=' . (int) $fila['sociedad_id'] . ' --todo';
            }
        }

        return $this->resultado($nombre, 'aviso',
            $total . ' comprobante(s) sin pasar a SharePoint (' . implode(', ', $detalle) . '). '
                . ($abren === 0
                    ? 'Esta computadora no tiene la carpeta de antes: aquí no se abren.'
                    : $abren . ' de los ' . count($muestra) . ' más recientes se abren desde esta computadora.'),
            "Pásalos a SharePoint desde una computadora que tenga la carpeta de antes, con el usuario de Windows "
            . "que sincroniza OneDrive:\n" . implode("\n", $comandos)
            . "\nSi esa computadora ya no tiene la carpeta configurada, agrega --carpeta=RUTA.");
    }

    /**
     * SharePoint, donde viven los XML y PDF de todas las empresas: si esta
     * computadora llega, si la tarea que vigila la biblioteca sigue corriendo
     * y si quedó algo atascado. Es obligatorio: sin credenciales, esta PC no
     * puede ni guardar ni abrir un comprobante.
     *
     * Pregunta a SharePoint UNA vez (dónde está la biblioteca o la carpeta).
     * Lo demás lo sabe la base.
     */
    private function sharepoint()
    {
        require_once __DIR__ . '/AlmacenEnGraph.php';
        $revisiones = [];
        $documentos = null;
        try {
            $pdo = $this->pdo();
            if ($this->existeColumna($pdo, 'facturas_xml', 'almacen')) {
                $documentos = (int) $pdo->query("SELECT COUNT(*) FROM facturas_xml WHERE almacen = 'sharepoint'")->fetchColumn();
            }
        } catch (Throwable $e) {
            $pdo = null;
        }
        $cuantos = $documentos === null ? '' : " ({$documentos} documentos allá)";

        // 1. ¿Esta computadora llega?
        if (!AlmacenDocumentos::disponible(AlmacenEnGraph::TIPO)) {
            $revisiones[] = $this->resultado('SharePoint en esta computadora', 'error',
                'Sin credenciales' . $cuantos,
                "Esta computadora no puede guardar ni abrir documentos: todos viven en SharePoint. Copia la plantilla y completa los datos:\n"
                . "  copy app\\config\\graph.ejemplo.php app\\config\\local.graph.php\n"
                . 'El secreto lo tiene quien administra la aplicación en Microsoft Entra ID; no se manda por correo ni por chat. '
                . 'Guía: docs/SHAREPOINT.md.');
            return $revisiones;
        }
        try {
            $donde = AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO)->probar();
            $revisiones[] = $this->resultado('SharePoint en esta computadora', 'ok',
                'Conectada: ' . $donde . $cuantos, '');
        } catch (Throwable $e) {
            $revisiones[] = $this->resultado('SharePoint en esta computadora', 'error',
                'No contesta: ' . $e->getMessage(),
                "Prueba la conexión paso a paso con:\n  php cli/probar_sharepoint.php\n"
                . 'Si dice que falta un certificado raíz, revisa curl.cainfo en php.ini y reinicia Apache.');
        }
        if ($pdo === null) {
            return $revisiones;
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
                      . "  php cli/organizar_documentos.php --dry-run\n"
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

    /**
     * Si `usuarios.id` sigue siendo INT con signo.
     *
     * Es la marca de que falta migration_usuarios_id_unsigned.sql. No se puede
     * preguntar por una columna que falte —no falta ninguna—, ni por las claves
     * foráneas directamente: una base a medio migrar podría tener algunas y no
     * todas, y el tipo del padre es lo único que o está o no está.
     */
    private function usuariosIdConSigno(PDO $pdo)
    {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute(['usuarios', 'id']);
        $tipo = (string) $stmt->fetchColumn();

        // Sin fila no hay nada que reportar: si `usuarios` no existe, la base
        // no está instalada y eso lo dice otra revisión.
        return $tipo !== '' && stripos($tipo, 'unsigned') === false;
    }

    /**
     * Si `facturas_xml.moneda` todavía tiene por omisión la moneda de México.
     *
     * Viene de la plantilla con la que nació el proyecto. En la práctica no se
     * usa —el modelo siempre guarda un valor, y por omisión CRC—, pero un
     * DEFAULT que miente es una trampa para el día que alguien inserte sin
     * pasar por el modelo.
     */
    private function monedaPorOmisionAjena(PDO $pdo)
    {
        $stmt = $pdo->prepare("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'facturas_xml'
                                 AND COLUMN_NAME = 'moneda' LIMIT 1");
        $stmt->execute();
        return strtoupper(trim((string) $stmt->fetchColumn(), "'")) === 'MXN';
    }

    /** Si `tabla.columna` todavía admite NULL. */
    private function columnaAdmiteNull(PDO $pdo, $tabla, $columna)
    {
        $stmt = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$tabla, $columna]);
        // Sin fila no hay nada que reportar: si la tabla no existe, lo dice
        // otra revisión.
        return $stmt->fetchColumn() === 'YES';
    }

    /** Si `tabla.columna` ya tiene clave foránea hacia `referida`. */
    private function tieneClaveForanea(PDO $pdo, $tabla, $columna, $referida)
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = ? AND COLUMN_NAME = ?
                                 AND REFERENCED_TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$tabla, $columna, $referida]);
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
