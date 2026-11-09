<?php
/**
 * Configuración del sistema: una sola pantalla, alcanzable con el engranaje
 * desde cualquier módulo.
 *
 * Antes esto era un modal que vivía dentro de Correo. La carpeta raíz de XML y
 * PDF, las empresas del grupo o el respaldo de la base no son opciones del
 * correo —las usan todos los módulos—, y para cambiarlas había que saber que
 * se guardaban ahí. Aquí están juntas, agrupadas por tema, con una dirección
 * propia que se puede compartir: /configuracion#empresas.
 *
 * Los POST que atienden estas secciones NO se mudaron: siguen en el
 * controlador dueño de lo que escriben (CorreoController para la carpeta, las
 * cuentas y la automatización; DiagnosticoController para el respaldo;
 * SociedadesController y UsuariosController para lo suyo). Traerlos aquí
 * obligaría a duplicar los helpers con los que trabajan. Es el mismo reparto
 * que ya usan las pantallas de carga de cada módulo.
 */

require_once __DIR__ . '/../helpers/MailFetcher.php';
require_once __DIR__ . '/../helpers/RespaldoBase.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';

class ConfiguracionController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        // Cada sección se decide por su propio permiso, no por un sí/no de
        // administrador: así se puede tener a alguien que administre usuarios
        // sin darle además el respaldo de la base y el diagnóstico.
        $puede = [
            'usuarios' => $this->puede('usuarios.ver') || $this->puede('usuarios.administrar'),
            'roles'    => $this->puede('roles.administrar'),
            'bitacora' => $this->puede('bitacora.ver'),
            'respaldo' => $this->puede('respaldo.administrar'),
            'empresas' => $this->puede('empresas.administrar'),
            'correo'   => $this->puede('correo.configurar'),
            'archivo'  => $this->puede('configuracion.archivo'),
            'proveedores' => $this->puede('configuracion.proveedores'),
        ];

        $esAdmin = Permisos::esAdmin();

        $configLocal    = $this->configLocal();
        $cuentaActivaId = (int) ($configLocal['cuenta_id'] ?? 0);

        $cuentas         = [];
        $sociedades      = [];
        $sociedadActiva  = null;
        $sociedadEnUsoId = 0;

        try {
            $sociedadModel   = $this->loadModel('Sociedad');
            $sociedades      = $sociedadModel->getAll();
            $sociedadActiva  = $sociedadModel->getActiva();
            $sociedadEnUsoId = (int) ($sociedadActiva['id'] ?? 0);

            /*
             * Cuántos comprobantes de cada empresa siguen en la carpeta
             * compartida de antes, sin pasar a SharePoint.
             *
             * La pregunta —"¿por qué no veo mis facturas en SharePoint?"—
             * aparece días después de dejar la carpeta. Aquí queda a la vista
             * mientras sea cierta, y desaparece sola cuando la migración
             * termina.
             */
            $enDisco = $sociedadModel->documentosEnDiscoPorEmpresa();
            foreach ($sociedades as $i => $s) {
                $sociedades[$i]['documentos_en_disco'] = $enDisco[(int) $s['id']] ?? 0;
            }
        } catch (Throwable $e) {
            // Sin base la pantalla sigue sirviendo para la carpeta raíz
        }

        $nombrePorSociedad = [];
        foreach ($sociedades as $s) {
            $nombrePorSociedad[(int) $s['id']] = (string) $s['nombre'];
        }

        try {
            $cuentasModel = $this->loadModel('CorreoCuenta');
            $cuentasModel->seedDesdeArchivo();

            // TODOS los buzones, también los de otras empresas: este es el
            // único lugar donde se corrige una asignación equivocada, y un
            // buzón mal asignado que no se listara sería invisible.
            foreach ($cuentasModel->getAll() as $c) {
                $sociedadesDe = [];
                try {
                    $sociedadesDe = $cuentasModel->sociedadesDe((int) $c['id']);
                } catch (Throwable $e) {
                }

                $nombres = [];
                foreach ($sociedadesDe as $sid) {
                    if (isset($nombrePorSociedad[(int) $sid])) {
                        $nombres[] = $nombrePorSociedad[(int) $sid];
                    }
                }

                // Al front solo van datos no sensibles: nunca la contraseña.
                $cuentas[] = [
                    'id'         => (int) $c['id'],
                    'nombre'     => (string) $c['nombre'],
                    'usuario'    => (string) $c['usuario'],
                    'host'       => (string) $c['host'],
                    'puerto'     => (int) $c['puerto'],
                    'carpeta'    => (string) $c['carpeta'],
                    'indice_retencion_dias' => (int) ($c['indice_retencion_dias'] ?? 1825),
                    'sociedades' => $sociedadesDe,
                    'sociedades_nombres' => $nombres,
                    'atiende_actual' => in_array($sociedadEnUsoId, array_map('intval', $sociedadesDe), true),
                ];
            }
        } catch (Throwable $e) {
        }

        $pagos = [];
        try {
            $pagos = $this->loadModel('Pago')->getAll();
        } catch (Throwable $e) {
        }

        $usuarios = [];
        if ($puede['usuarios']) {
            try {
                require_once __DIR__ . '/../models/Usuario.php';
                $usuarios = (new Usuario())->getAll();
            } catch (Throwable $e) {
                $this->registrarFallo('listar usuarios en configuración', $e);
            }
        }

        // Los roles con lo que concede cada uno y quién lo tiene: la sección
        // dibuja "quién puede qué" de un vistazo, sin entrar rol por rol.
        $roles = [];
        $rolesPermisos = [];
        $rolesUsuarios = [];
        $usuariosSinRol = [];
        if ($puede['roles']) {
            try {
                require_once __DIR__ . '/../models/Rol.php';
                $rolModel = new Rol();
                $roles = $rolModel->getAll();
                $rolesPermisos = $rolModel->permisosPorRol();
                $rolesUsuarios = $rolModel->usuariosPorRol();
                $usuariosSinRol = $rolModel->usuariosSinRol();
            } catch (Throwable $e) {
                $this->registrarFallo('listar roles en configuración', $e);
            }
        }

        // La actividad reciente se muestra aquí mismo —los últimos movimientos
        // son lo que se mira a diario— y el resto se busca en /bitacora, que
        // tiene los filtros. Traer la lista entera a esta pantalla la volvería
        // lenta para todo lo demás que hay en ella.
        $actividad = [];
        $actividadEstado = null;
        if ($puede['bitacora']) {
            try {
                require_once __DIR__ . '/../models/Bitacora.php';
                $bitacora = new Bitacora();
                $actividad = $bitacora->recientes(8);
                $actividadEstado = $bitacora->estado();
            } catch (Throwable $e) {
                $this->registrarFallo('leer la actividad reciente', $e);
            }
        }

        $this->render('configuracion/index', [
            'title'           => 'Configuración - Nexo Fiscal',
            'esAdmin'         => $esAdmin,
            'puede'           => $puede,
            'imapDisponible'  => MailFetcher::extensionDisponible(),
            'configLocal'     => $configLocal,
            'cuentas'         => $cuentas,
            'cuentaActivaId'  => $cuentaActivaId,
            'sociedades'      => $sociedades,
            'sociedadActiva'  => $sociedadActiva,
            'sociedadEnUsoId' => $sociedadEnUsoId,
            // Dónde viven los XML y PDF, leído de local.graph.php: no sale a
            // la red al dibujar la pantalla. Si contesta lo dice Diagnóstico.
            'sharepoint' => $this->sharepoint(),
            'pagos'         => $pagos,
            'pagoActivo'    => $this->pagoActivo(),
            'usuarios'        => $usuarios,
            'roles'           => $roles,
            'rolesPermisos'   => $rolesPermisos,
            'rolesUsuarios'   => $rolesUsuarios,
            'usuariosSinRol'  => $usuariosSinRol,
            'actividad'       => $actividad,
            'actividadEstado' => $actividadEstado,
            'modulosPermisos' => Permisos::modulos(),
            // La tarjeta de respaldo se dibuja ya con datos: si el JS falla o
            // la carpeta compartida no responde, igual se ve el último estado.
            'respaldo'        => $puede['respaldo'] ? RespaldoBase::panel() : null,
            // Cómo está hoy el mapa de códigos: cuántos se saben por el
            // catálogo del ERP y cuántos siguen siendo una deducción.
            'catalogo'        => $puede['proveedores'] ? $this->estadoCatalogo() : null,
            // Ancla a la que saltar (?ir=usuarios), para que quien llegue
            // redirigido desde otra pantalla caiga en su sección.
            'seccionInicial'  => preg_replace('/[^a-z]/', '', strtolower((string) $this->get('ir', ''))),
        ]);
    }

    /**
     * Qué tan sostenido está hoy el mapa de códigos del ERP.
     *
     * Se enseña antes de importar nada porque es lo que explica para qué sirve
     * el botón: un mapa hecho solo de deducciones es el que deja que un
     * proveedor se cuele en el código de otro.
     */
    private function estadoCatalogo()
    {
        try {
            require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
            $modelo = $this->loadModel('ProveedorCodigoErp');
            return $modelo->resumenDelMapa();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Lee el listado de proveedores del ERP y dice qué cambiaría, sin tocar
     * nada.
     *
     * Va antes de escribir porque la importación corrige el mapa —y con él,
     * qué comprobantes se aceptan— sobre cientos de códigos de una sola vez.
     * Enterarse después de lo que cambió no es enterarse.
     */
    public function previaProveedores()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            require_once __DIR__ . '/../helpers/FileUploader.php';
            require_once __DIR__ . '/../helpers/ProveedoresErpCsvParser.php';
            require_once __DIR__ . '/../models/ProveedorCodigoErp.php';

            $config = require __DIR__ . '/../config/config.php';
            $archivo = FileUploader::uploadSingle(
                'catalogo_file',
                $this->carpetaCatalogo(),
                ['csv'],
                $config['max_upload_size'] ?? 10485760
            );

            @set_time_limit(120);
            $lectura = ProveedoresErpCsvParser::parseArchivo($archivo['path']);
            if (!$lectura['ok']) {
                $this->json(['ok' => false, 'message' => implode(' ', $lectura['errores'])], 422);
            }

            $modelo = $this->loadModel('ProveedorCodigoErp');
            $plan = $modelo->importarDesdeErp($lectura['proveedores'], true);
            $contradice = $modelo->emparejamientosQueContradice($lectura['proveedores']);

            // Cada empresa tiene su catálogo: se dice en cuál va a entrar
            // antes de apretar el botón, no después.
            $empresa = $this->loadModel('Sociedad')->findById((int) $plan['sociedad_id']);

            $this->json([
                'ok' => true,
                'empresa' => is_array($empresa) ? (string) $empresa['nombre'] : '',
                'token' => basename($archivo['path']),
                'archivo' => $archivo['original_name'] ?? basename($archivo['path']),
                'impreso_en' => $lectura['meta']['impreso_en'],
                'grupos' => $lectura['meta']['grupos'],
                'plan' => [
                    'leidos' => $plan['leidos'],
                    'confirmados' => $plan['confirmados'],
                    'corregidos' => $plan['corregidos'],
                    'nuevos' => $plan['nuevos'],
                    'proveedores_creados' => $plan['proveedores_creados'],
                    'cambios' => array_slice($plan['cambios'], 0, 50),
                    'choca_con_manual' => $plan['choca_con_manual'],
                    'manuales' => array_slice($plan['manuales'], 0, 50),
                ],
                'sin_cedula' => count($lectura['sin_cedula']),
                // Los grupos enteros, no la cuenta: la pantalla tiene que
                // poder mostrar los códigos y sus nombres para que se pueda
                // decidir cuál es el bueno sin salir a buscarlos a otro lado.
                'compartidas' => array_map(
                    function ($filas, $cedula) {
                        return [
                            'cedula' => (string) $cedula,
                            'codigos' => array_map(function ($f) {
                                return [
                                    'codigo' => (string) $f['codigo'],
                                    'nombre' => (string) ($f['nombre'] ?? ''),
                                    'fila'   => (int) ($f['fila'] ?? 0),
                                ];
                            }, $filas),
                        ];
                    },
                    $lectura['cedulas_compartidas'],
                    array_keys($lectura['cedulas_compartidas'])
                ),
                'contradice' => [
                    'total' => count($contradice),
                    'respaldadas' => count(array_filter(
                        $contradice,
                        function ($f) { return ($f['estado_respaldo'] ?? '') === 'respaldada'; }
                    )),
                    'detalle' => array_map(function ($f) {
                        return [
                            'codigo' => (string) $f['proveedor_codigo'],
                            'nombre' => (string) $f['proveedor_nombre'],
                            'documento' => (string) $f['documento'],
                            'monto' => (float) $f['monto'],
                            'emisor' => (string) $f['razon_social'],
                            'cedula' => (string) $f['rfc'],
                            'estado' => (string) $f['estado_respaldo'],
                        ];
                    }, array_slice($contradice, 0, 50)),
                ],
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Aplica lo que se decidió sobre las cédulas repartidas entre varios códigos.
     *
     * Entran todos, salvo los que se desmarcaron. Antes había que elegir uno
     * solo por cédula y los demás quedaban fuera, pero los puntos de venta de
     * una misma empresa —CEDI, PROMOCIONES, TECNOLOGIA Y FRIO— son todos
     * buenos, y un código que no está en el catálogo no engancha con nada: el
     * 28/09 eran 44 facturas de un pago con su XML en la base. Desmarcar queda
     * para el alta duplicada ("ALPELMUSA" y "ALPEMUSA").
     *
     * Cada cédula tiene que quedar con al menos un código. Una que no manda
     * nada —la pantalla siempre manda la casilla oculta de cada grupo— es un
     * formulario que no sabe de esto, y entran todos.
     *
     * Lo que NO hace: borrar. El código que se desmarca simplemente no entra
     * en esta importación; si ya estaba en la lista de antes, se queda. Quitar
     * algo de la lista es una decisión aparte y tiene su propio botón.
     *
     * @return array Las filas que sí se van a importar.
     */
    private function resolverCedulasCompartidas(array $lectura)
    {
        $grupos = $lectura['cedulas_compartidas'] ?? [];
        if (!$grupos) {
            return $lectura['proveedores'];
        }

        $incluir = $this->post('incluir', []);
        $incluir = is_array($incluir) ? $incluir : [];

        $descartados = [];
        $vacias = [];
        foreach ($grupos as $cedula => $filas) {
            if (!array_key_exists($cedula, $incluir)) {
                continue;
            }
            $codigos = array_column($filas, 'codigo');
            $marcados = array_map('strval', (array) $incluir[$cedula]);
            $quedan = array_values(array_intersect($codigos, $marcados));
            if (!$quedan) {
                $vacias[] = (string) $cedula;
                continue;
            }
            foreach (array_diff($codigos, $quedan) as $c) {
                $descartados[$c] = true;
            }
        }

        if ($vacias) {
            throw new Exception(sprintf(
                '%d cédula(s) se quedaron sin ningún código marcado: %s. '
                . 'Cada una tiene que conservar al menos uno.',
                count($vacias),
                implode(', ', array_slice($vacias, 0, 6)) . (count($vacias) > 6 ? '…' : '')
            ));
        }

        return array_values(array_filter(
            $lectura['proveedores'],
            function ($p) use ($descartados) {
                return !isset($descartados[(string) $p['codigo']]);
            }
        ));
    }

    /** Aplica el catálogo leído en la vista previa. */
    public function importarProveedores()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/configuracion?ir=proveedores'));
        }
        $volver = $this->url('/configuracion?ir=proveedores');

        try {
            require_once __DIR__ . '/../helpers/ProveedoresErpCsvParser.php';
            require_once __DIR__ . '/../models/ProveedorCodigoErp.php';

            $token = basename(trim((string) $this->post('archivo_token', '')));
            $ruta = $this->carpetaCatalogo() . DIRECTORY_SEPARATOR . $token;
            if ($token === '' || !is_file($ruta)) {
                throw new Exception('El archivo de la vista previa ya no está. Volvé a subirlo.');
            }

            @set_time_limit(300);
            $lectura = ProveedoresErpCsvParser::parseArchivo($ruta);
            if (!$lectura['ok']) {
                throw new Exception(implode(' ', $lectura['errores']));
            }

            $filas = $this->resolverCedulasCompartidas($lectura);

            $modelo = $this->loadModel('ProveedorCodigoErp');
            $r = $modelo->importarDesdeErp($filas);

            // Desatar los emparejamientos que el catálogo desmiente es una
            // decisión aparte: borra trabajo hecho, así que se pide a propósito.
            $desatados = 0;
            if ($this->post('desatar', '') !== '') {
                $desatados = $modelo->desatarEmparejamientosContradichos($lectura['proveedores']);
            }

            $msg = sprintf(
                'Listado importado: %s códigos leídos — %s ya estaban así, a %s se les corrigió la '
                . 'cédula y %s se agregaron.',
                number_format($r['leidos'], 0, ',', '.'),
                number_format($r['confirmados'], 0, ',', '.'),
                number_format($r['corregidos'], 0, ',', '.'),
                number_format($r['nuevos'], 0, ',', '.')
            );
            if ($r['proveedores_creados'] > 0) {
                $msg .= ' ' . number_format($r['proveedores_creados'], 0, ',', '.')
                     . ' proveedor(es) se dieron de alta por su cédula.';
            }
            if ($r['sin_cedula'] > 0) {
                $msg .= ' ' . number_format($r['sin_cedula'], 0, ',', '.')
                     . ' código(s) del archivo no traen cédula y quedaron como estaban.';
            }
            if ($r['choca_con_manual'] > 0) {
                $msg .= ' En ' . number_format($r['choca_con_manual'], 0, ',', '.')
                     . ' código(s) asignados a mano el archivo trae otra cédula: se dejó la asignada a mano ('
                     . implode(', ', array_slice(array_column($r['manuales'], 'codigo'), 0, 6))
                     . ($r['choca_con_manual'] > 6 ? '…' : '') . ').';
            }
            if ($desatados > 0) {
                $msg .= ' Se desengancharon ' . number_format($desatados, 0, ',', '.')
                     . ' factura(s) que tenían el comprobante de otro proveedor: vuelven a quedar '
                     . 'sin respaldo.';
            }

            // Códigos nuevos o corregidos destraban facturas que ya tenían su
            // XML en el sistema: se buscan ahora mismo.
            $reparadas = $this->buscarRespaldoTrasCatalogo();
            if ($reparadas > 0) {
                $msg .= ' ' . number_format($reparadas, 0, ',', '.')
                     . ' factura(s) que estaban sin respaldo encontraron su comprobante.';
            }

            $this->redirectWithMessage($volver, $msg, $desatados > 0 ? 'warning' : 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($volver, 'No se pudo importar el listado: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Una página del catálogo, con su búsqueda.
     *
     * Va por JSON y no pintada en la página porque son 1.700 filas y subiendo:
     * meterlas todas en el HTML volvería la pantalla de configuración la más
     * lenta del sistema por una tabla que casi siempre se abre para buscar
     * una sola.
     */
    public function listarProveedores()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $modelo = $this->loadModel('ProveedorCodigoErp');

        $pagina = max(1, (int) $this->post('pagina', 1));
        $porPagina = 25;
        $r = $modelo->listar((string) $this->post('buscar', ''), $porPagina, ($pagina - 1) * $porPagina);

        $this->json([
            'ok' => true,
            'filas' => $r['filas'],
            'total' => $r['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($r['total'] / $porPagina)),
            'resumen' => $modelo->resumenDelMapa(),
            'ignoradas' => $modelo->ignoradas(),
            // Todos (con tope): la pantalla los filtra y pagina sin volver a
            // pedirlos, y con 50 el filtro no encontraba al que estaba en el 60.
            'sin_codigo' => $modelo->comprobantesSinCodigo(500),
        ]);
    }

    /**
     * Los códigos entre los que elegir al darle código a un proveedor.
     *
     * Con `buscar`, lo que coincida por código, nombre o cédula. Sin él, los
     * que se parecen al nombre del proveedor (`nombre`): es lo primero que
     * uno buscaría, y así la lista ya abre con los candidatos.
     */
    public function opcionesProveedor()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $modelo = $this->loadModel('ProveedorCodigoErp');
        $buscar = trim((string) $this->post('buscar', ''));

        $this->json([
            'ok' => true,
            'sugeridas' => $buscar === '',
            'opciones' => $buscar !== ''
                ? $modelo->opcionesDeCodigo($buscar)
                : $modelo->opcionesParecidas((string) $this->post('nombre', '')),
        ]);
    }

    /** Da de alta o corrige un código a mano. */
    public function guardarProveedor()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $modelo = $this->loadModel('ProveedorCodigoErp');

        // Desde "Por resolver" solo se elige entre códigos que existen: la
        // pantalla ya no ofrece escribir uno, y esto lo sostiene aunque
        // alguien mande el formulario a mano.
        if ($this->post('existente', '') === '1' && !$modelo->codigoExiste((string) $this->post('codigo', ''))) {
            $this->json([
                'ok' => false,
                'message' => 'Ese código no está en la lista ni en las facturas del sistema. '
                           . 'Si el proveedor es nuevo, importá de nuevo el listado del sistema.',
            ], 422);
        }

        // 'tambien': el código ya tiene dueño y no se le quita; acepta
        // además los comprobantes de esta cédula.
        $metodo = $this->post('modo', '') === 'tambien' ? 'aceptarTambien' : 'guardarCodigo';
        $r = $modelo->$metodo(
            (string) $this->post('codigo', ''),
            (string) $this->post('cedula', ''),
            (string) $this->post('nombre', ''),
            (int) ($_SESSION['user_id'] ?? 0)
        );

        // Con el código ya diciendo de quién es, las facturas que lo estaban
        // esperando buscan su comprobante ahora, no cuando otra cosa las toque.
        if (!empty($r['ok'])) {
            $r['reparadas'] = $this->buscarRespaldoTrasCatalogo();
        }
        $this->json($r, empty($r['ok']) ? 422 : 200);
    }

    /** Ver PorPagarVerificador::trasCambioDeCatalogo(). */
    private function buscarRespaldoTrasCatalogo()
    {
        require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
        return PorPagarVerificador::trasCambioDeCatalogo(
            $this->loadModel('FacturaErp'),
            $this->loadModel('Factura')
        );
    }

    /** Un código deja de aceptar los comprobantes de una cédula que no es su dueña. */
    public function dejarDeAceptarProveedor()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $r = $this->loadModel('ProveedorCodigoErp')->dejarDeAceptar(
            (string) $this->post('codigo', ''),
            (string) $this->post('cedula', '')
        );
        $this->json($r, empty($r['ok']) ? 422 : 200);
    }

    /** Borra un código del catálogo. */
    public function eliminarProveedor()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $r = $this->loadModel('ProveedorCodigoErp')->eliminarCodigo((string) $this->post('codigo', ''));
        $this->json($r, empty($r['ok']) ? 422 : 200);
    }

    /** Manda a callar una cédula, o la vuelve a despertar. */
    public function ignorarProveedor()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        require_once __DIR__ . '/../models/ProveedorCodigoErp.php';
        $modelo = $this->loadModel('ProveedorCodigoErp');
        $cedula = (string) $this->post('cedula', '');

        $r = $this->post('quitar', '') !== ''
            ? $modelo->dejarDeIgnorar($cedula)
            : $modelo->ignorarCedula(
                $cedula,
                (string) $this->post('nombre', ''),
                (string) $this->post('motivo', ''),
                (int) ($_SESSION['user_id'] ?? 0)
            );
        $this->json($r, empty($r['ok']) ? 422 : 200);
    }

    /** Dónde espera el archivo entre la vista previa y la confirmación. */
    private function carpetaCatalogo()
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
             . DIRECTORY_SEPARATOR . 'catalogo_proveedores';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Un CSV por carga y nadie los borraría nunca: se van solos al día.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $viejo) {
            if (is_file($viejo) && @filemtime($viejo) < time() - 86400) {
                @unlink($viejo);
            }
        }
        return $dir;
    }

    /**
     * Lo que la tarjeta "Archivo de documentos" dice de SharePoint: si esta
     * computadora tiene credenciales, y a qué biblioteca y carpeta apuntan.
     * Sin el secreto, claro.
     *
     * El nombre de la biblioteca sí sale a la red, pero una vez al día (lo
     * recuerda ClienteGraph) y con pocos segundos de espera; si no llega, se
     * enseña el id como antes.
     */
    private function sharepoint()
    {
        require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';
        require_once __DIR__ . '/../helpers/ClienteGraph.php';
        $config = ClienteGraph::configuracion();
        $archivo = dirname(__DIR__) . '/config/local.graph.php';

        // En modo disco SharePoint no se usa: no se sale a la red a buscar el
        // nombre de una biblioteca que quizá ya ni contesta.
        $enDisco = AlmacenDocumentos::archivaEnDisco();
        $raizDisco = $enDisco ? RutaDocumento::raiz() : '';

        $biblioteca = ['nombre' => '', 'url' => ''];
        if ($config !== null && !$enDisco) {
            try {
                $biblioteca = ClienteGraph::desdeConfiguracion()->bibliotecaVisible();
            } catch (Throwable $e) {
                // Se enseña el id, como antes.
            }
        }

        return [
            'disponible' => AlmacenDocumentos::disponible(AlmacenDocumentos::SHAREPOINT),
            'archivo_existe' => is_file($archivo),
            'biblioteca' => (string) $biblioteca['nombre'],
            'biblioteca_url' => (string) $biblioteca['url'],
            'drive_id' => $config === null ? '' : (string) $config['drive_id'],
            'carpeta' => $config === null ? '' : trim(str_replace('\\', '/', (string) ($config['carpeta'] ?? '')), '/'),
            'carpeta_anterior' => DocumentoArchivo::raizConfigurada(),
            // Archivo temporal en disco: solo lo que se sabe sin recorrer la
            // carpeta, porque dibujar la pantalla no puede contar miles de archivos.
            'archiva_en_disco' => $enDisco,
            'disco_raiz' => $raizDisco,
            'disco_escribe' => $raizDisco !== ''
                && AlmacenDocumentos::tipo(AlmacenDocumentos::DISCO)->permiteEscritura(''),
            'disco_libre' => $raizDisco !== '' ? @disk_free_space($raizDisco) : false,
        ];
    }

    /** La configuración de esta computadora (storage/correo/config.json), para pintar. */
    private function configLocal()
    {
        $defaults = ['cuenta_id' => 0];
        $ruta = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'config.json';

        if (is_file($ruta)) {
            $data = json_decode((string) file_get_contents($ruta), true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }

        return $defaults;
    }
}
