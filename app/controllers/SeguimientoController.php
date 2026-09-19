<?php
/**
 * Seguimiento: la cola única de trabajo.
 *
 * Los demás módulos son para cargar y comparar; este es para trabajar. Junta
 * lo que le falta respaldo o no cuadra —venga de una nota de crédito o de una
 * factura del ERP— y deja constancia de qué hizo cada quien con cada renglón.
 */
// El modelo se carga aquí y no solo con loadModel(): no hay autoload, y
// `actualizar()` usa Seguimiento::normalizarItems() para sanear la selección
// antes de tocar la base. Sin esto, cambiar de estado moría con
// 'Class "Seguimiento" not found' en vez de guardar.
require_once __DIR__ . '/../models/Seguimiento.php';
require_once __DIR__ . '/../models/Sociedad.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../helpers/AplicacionNotaCredito.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';
require_once __DIR__ . '/../helpers/Columnas.php';

class SeguimientoController extends Controller
{
    /** Cuántos renglones de la cola caben en un Excel de una sentada. */
    const TOPE_EXPORTACION = 5000;

    private const POR_PAGINA = 50;

    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        /*
         * El modo decide de qué va la pantalla entera —de los registros del
         * ERP o de los comprobantes XML—, así que cada uno recuerda lo suyo.
         * Con una sola memoria, volver del modo Correo dejaba el modo Sistema
         * filtrado por un tipo de documento que allá no existe.
         */
        $modo = Seguimiento::modo($this->get('modo', ''));

        // La pestaña abierta, la barra y los filtros de columna vuelven como
        // se dejaron. 'contexto_id' no: no tiene control en pantalla, y
        // recordarlo dejaría la cola recortada sin que nada lo explique.
        // 'modo' tampoco: no es un filtro sino de qué trata la pantalla, y va
        // en la URL como el de Correo.
        // 'responsable' tampoco: salió de la barra con el campo que lo llenaba,
        // y un valor guardado en la sesión dejaría la cola recortada al nombre
        // de alguien sin que quede en pantalla el control para quitarlo.
        $this->recordarFiltros('seguimiento:' . $modo, [
            'vista', 'origen', 'tarea', 'marca', 'clase', 'aplicacion',
            'proveedor', 'sucursal', 'llegada', 'desde', 'hasta', 'monto_min',
            'condicion_saldo', 'q', 'col_documento', 'col_proveedor',
            'col_monto', 'col_saldo', 'col_respaldo', 'col_tarea', 'orden',
        ]);

        $modelo = $this->loadModel('Seguimiento')->enModo($modo);
        $sociedad = $this->loadModel('Sociedad')->getActiva();

        $filtros = $this->filtros($sociedad, $modo);
        $pagina = max(1, (int) $this->get('pagina', 1));

        $cola = $modelo->cola($filtros, $pagina, self::POR_PAGINA);
        $resumen = $modelo->resumen($filtros);
        $dimensiones = $this->dimensiones($modelo, $sociedad ? (int) $sociedad['id'] : 0, $modo);

        $this->render('seguimiento/index', [
            'title' => 'Seguimiento - Nexo Fiscal',
            'sociedadActiva' => $sociedad,
            'filas' => $this->decorar($cola['filas'], $modo),
            'paginacion' => [
                'total' => $cola['total'],
                'pagina' => $cola['pagina'],
                'paginas' => $cola['paginas'],
                'por_pagina' => $cola['por_pagina'],
            ],
            'resumen' => $resumen,
            'filtros' => $filtros,
            // El desplegable de responsables se retiró de la barra, así que ya
            // no hace falta preguntar quiénes son: es una consulta menos por
            // carga. `Seguimiento::responsables()` sigue ahí por si vuelve.
            'proveedoresFiltro' => ProveedorCatalogo::opciones($dimensiones['proveedores']),
            'sucursales' => $dimensiones['sucursales'],
            // El selector de modo vive en el topbar, junto al título, igual
            // que el de Correo: es de qué trata la pantalla, no un control
            // más dentro de ella. El layout lo dibuja si ve esta variable.
            'modoSeguimiento' => $modo,
            'estados' => Seguimiento::estadosDe($modo),
            'tareas' => Seguimiento::tareasDe($modo),
            'origenes' => Seguimiento::origenesDe($modo),
            'llegadas' => Seguimiento::LLEGADAS,
        ]);
    }

    /** Cuánto vale lo guardado de los desplegables aunque la base no haya cambiado. */
    private const DIMENSIONES_VIGENCIA = 600;

    /**
     * Proveedores y sucursales para los desplegables, sin recorrer la unión
     * en cada carga.
     *
     * Era la cuarta pasada por la unión entera de cada carga de la pantalla
     * —tan cara como la página— para una lista que cambia cuando se carga un
     * reporte, no al pasar de página. Se guarda en storage/cache por empresa
     * y modo, junto con la huella de las tablas de las que sale: si la base
     * escribió en alguna, o pasaron diez minutos, se vuelve a preguntar. No
     * depende de quién mira, así que lo aprovechan todas las sesiones de esta
     * computadora.
     */
    private function dimensiones(Seguimiento $modelo, $sociedadId, $modo)
    {
        $huella = $modelo->huellaDimensiones();
        $archivo = dirname(__DIR__, 2) . '/storage/cache/seguimiento_dimensiones_'
            . Seguimiento::modo($modo) . '_' . (int) $sociedadId . '.json';

        if ($huella !== '' && is_file($archivo)) {
            $guardado = file_get_contents($archivo);
            $guardado = is_string($guardado) ? json_decode($guardado, true) : null;
            if (is_array($guardado) && ($guardado['huella'] ?? null) === $huella
                && time() - (int) ($guardado['en'] ?? 0) < self::DIMENSIONES_VIGENCIA
                && isset($guardado['datos']['proveedores'], $guardado['datos']['sucursales'])) {
                return $guardado['datos'];
            }
        }

        $datos = $modelo->dimensionesParaFiltro($sociedadId);
        if ($huella !== '') {
            // Sin poder guardarlo la pantalla sigue igual, solo más lenta.
            try {
                if (!is_dir(dirname($archivo))) {
                    mkdir(dirname($archivo), 0777, true);
                }
                file_put_contents($archivo, json_encode(
                    ['huella' => $huella, 'en' => time(), 'datos' => $datos],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                ), LOCK_EX);
            } catch (Throwable $e) {
                $this->registrarFallo('guardar los desplegables de seguimiento', $e);
            }
        }
        return $datos;
    }

    /**
     * Los filtros que entiende la pantalla, saneados.
     *
     * El trabajo lo hace el modelo: la tarjeta de navegación que abre esta
     * cola en Correo y en los listados de XML tiene que rehacer exactamente
     * la misma consulta para poder recorrerla, y dos lecturas de $_GET que
     * se puedan desincronizar darían una lista distinta a la que se ve acá.
     */
    private function filtros($sociedad, $modo = Seguimiento::MODO_SISTEMA)
    {
        // El modo entra con los demás parámetros: filtrosDesde() lo lee de ahí
        // para decidir qué pestañas y qué tipos de documento admite, y así una
        // sola lectura decide la consulta y la pantalla.
        return Seguimiento::filtrosDesde(
            array_merge($_GET, ['modo' => $modo]),
            $sociedad ? (int) $sociedad['id'] : 0
        );
    }

    /**
     * Cada cuántos días insiste el recordatorio. 0 = sin recordatorio.
     *
     * Solo se admiten los plazos que ofrece la pantalla: un número libre desde
     * fuera acabaría en la columna y nadie podría volver a elegirlo desde el
     * desplegable.
     */
    private function frecuencia($valor)
    {
        $dias = (int) $valor;
        return in_array($dias, [1, 3, 7, 15, 30], true) ? $dias : 0;
    }

    /**
     * Quién queda a cargo de un renglón, y cuándo se le pone nombre.
     *
     * El responsable no se teclea: es quien manda el documento a revisión, y
     * eso ya lo sabe la sesión. Antes venía en el POST y había un campo para
     * escribirlo, con dos problemas: había que escribir el propio nombre para
     * decirle al sistema algo que ya sabía, y nada impedía poner el de otra
     * persona —a mano o armando la petición—.
     *
     * Solo al entrar en revisión. En las demás pestañas no hay nadie
     * persiguiendo nada, y sellar un nombre al cerrar un renglón diría que
     * alguien lo tiene entre manos cuando ya no; en una anotación suelta,
     * tampoco: comentar no es hacerse cargo.
     *
     * @return string|null El nombre a guardar, o null para no tocar la columna.
     */
    private static function responsableDe($estado, array $usuario)
    {
        if ((string) $estado !== Seguimiento::ESTADO_A_MANO) {
            return null;
        }
        // Sin nombre en la sesión se guarda vacío, que el modelo convierte en
        // NULL: mejor sin responsable que con uno inventado.
        return trim((string) ($usuario['nombre'] ?? ''));
    }

    /**
     * El primer aviso: dentro de tantos días, a la hora pedida.
     *
     * La hora es del reloj de quien lo pide, y la base guarda esa misma hora
     * de pared. No hay husos de por medio: todas las máquinas y el servidor
     * están en Costa Rica.
     */
    private function momentoRecordatorio($dias, $hora)
    {
        $hora = trim((string) $hora);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora)) {
            $hora = '08:00';
        }
        return date('Y-m-d', strtotime('+' . (int) $dias . ' days')) . ' ' . $hora . ':00';
    }

    // Aquí se armaba la lista de listados para el desplegable "Listado".
    // Salió con el desplegable: recorrer la unión entera es lo caro de esta
    // pantalla, y era una pasada más por una pregunta que el pago ya
    // contesta en su propia pantalla. `Seguimiento::contextosDisponibles()`
    // sigue ahí para cuando haga falta.

    /**
     * Lo que la vista necesita y no sale de la base: el término con el que
     * buscar el documento en el correo y si los archivos siguen en disco.
     *
     * Se comprueba el archivo de verdad, no solo la columna: la base guarda
     * rutas relativas a una carpeta sincronizada, y un documento puede estar
     * registrado pero todavía no haber bajado a esta computadora.
     */
    private function decorar(array $filas, $modo = Seguimiento::MODO_SISTEMA)
    {
        $esCorreo = Seguimiento::modo($modo) === Seguimiento::MODO_CORREO;

        foreach ($filas as &$fila) {
            // No es lo mismo no haber tenido nunca el comprobante que haberlo
            // tenido y haberlo perdido: lo primero se resuelve buscándolo, lo
            // segundo volviéndolo a bajar del correo. Quién es quién lo decide
            // EstadoArchivo, que es el mismo criterio en todos los módulos.
            $archivo = EstadoArchivo::de($fila);
            $fila['xml_ok'] = $archivo['xml_ok'];
            $fila['pdf_ok'] = $archivo['pdf_ok'];
            $fila['xml_perdido'] = !$archivo['xml_ok'] && trim((string) ($fila['ruta_xml'] ?? '')) !== '';
            $fila['pdf_perdido'] = !$archivo['pdf_ok'] && trim((string) ($fila['ruta_pdf'] ?? '')) !== '';
            $fila['recuperable'] = $archivo['recuperable'];
            $fila['pdf_historico'] = ($fila['estado_pdf'] ?? '') === 'no_disponible_historico';
            $this->decorarBusqueda($fila, $esCorreo);
            /*
             * En el modo Correo el renglón ES el comprobante, así que en vez
             * de "buscar su electrónico" lo que hace falta es abrirlo: su
             * ficha está en el listado del que salió, y una nota no vive en el
             * listado de facturas.
             */
            $fila['ver_ruta'] = $esCorreo
                ? (($fila['origen'] ?? '') === 'xml_nota' ? '/notas-xml/ver/' : '/facturas/ver/')
                    . (int) $fila['referencia_id']
                : '';
            // El recordatorio solo tiene sentido mientras el renglón siga en
            // revisión: en las demás pestañas no hay nada que esperar.
            $fila['vencido'] = !empty($fila['recordar_en'])
                && $fila['recordar_en'] <= date('Y-m-d H:i:s')
                && $fila['seguimiento_estado'] === Seguimiento::ESTADO_A_MANO;
            // La marca a mano dejó de concordar con los datos: ni se mueve
            // sola ni se calla, para que alguien decida.
            $fila['desajustada'] = !empty($fila['estado_a_mano'])
                && $fila['estado_a_mano'] !== $fila['estado_calculado'];
        }
        unset($fila);
        return $filas;
    }

    /**
     * Con qué se busca el documento, y por qué.
     *
     * El término lo decide NavegacionDocumentos, que es quien lo necesita para
     * la tarjeta de las pantallas donde se busca; acá se agrega lo que la
     * pantalla enseña alrededor: si se está buscando por número o por
     * proveedor —que cambia el texto del botón— y la fecha de referencia.
     *
     * En una factura el término es su propio número. En una nota NO: el
     * número largo del reporte es el consecutivo de la factura que la nota
     * corrige (ver ClaseNotaCredito), así que buscarlo traía siempre el
     * documento equivocado. Cuando la nota no trae consecutivo propio —lo
     * corriente— no hay número que buscar y se cae al nombre del proveedor,
     * acotado a los 15 días alrededor de su fecha.
     */
    private function decorarBusqueda(array &$fila, $esCorreo = false)
    {
        /*
         * En el modo Correo no hay nada que buscar: el comprobante ya está
         * cargado, es el renglón. Sin término, la pantalla no dibuja los dos
         * botones de búsqueda, que es lo correcto —mandarían a buscar en el
         * correo un XML que se está mirando—.
         */
        if ($esCorreo) {
            $fila['busqueda'] = '';
            $fila['busqueda_por'] = 'numero';
            $fila['busqueda_fecha'] = '';
            return;
        }

        $fila['busqueda'] = NavegacionDocumentos::busquedaDe($fila);
        $fila['busqueda_fecha'] = '';

        $esNotaSinNumero = ($fila['origen'] ?? '') === 'nota_credito'
            && trim((string) ($fila['nc_proveedor'] ?? '')) === '';
        $fila['busqueda_por'] = $esNotaSinNumero ? 'proveedor' : 'numero';

        if ($esNotaSinNumero) {
            $fecha = strtotime((string) ($fila['fecha'] ?? ''));
            if ($fecha !== false) {
                $fila['busqueda_fecha'] = date('d/m/Y', $fecha);
            }
        }
    }

    // ── Acciones ────────────────────────────────────────────────────────────

    /** Cambio de estado, recordatorio o motivo sobre uno o varios. */
    public function actualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }
        $this->exigirPeticionPropia();

        try {
            $items = $this->itemsDelPost();
            if (!$items) {
                throw new InvalidArgumentException('No se seleccionó ningún renglón.');
            }

            $usuario = $this->usuario();

            $cambio = [];
            // Vacío = no tocar la marca (una anotación suelta). El valor
            // SIN_MARCA sí la toca: la borra y devuelve el renglón al cálculo.
            if (($estado = trim((string) $this->post('estado', ''))) !== '') {
                $cambio['estado'] = $estado;
            }

            $responsable = self::responsableDe($cambio['estado'] ?? '', $usuario);
            if ($responsable !== null) {
                $cambio['responsable'] = $responsable;
            }
            if ($this->post('recordar_cada', null) !== null) {
                $cambio['recordar_cada'] = $this->frecuencia($this->post('recordar_cada'));
                $cambio['recordar_en'] = $cambio['recordar_cada'] > 0
                    ? $this->momentoRecordatorio($cambio['recordar_cada'], $this->post('recordar_hora', ''))
                    : null;
            }
            if ($this->post('motivo', null) !== null) {
                $cambio['motivo'] = trim((string) $this->post('motivo'));
            }
            $cambio['comentario'] = trim((string) $this->post('comentario', ''));

            if (!isset($cambio['estado']) && $cambio['comentario'] === ''
                && !array_key_exists('recordar_en', $cambio)
                && !array_key_exists('motivo', $cambio)) {
                throw new InvalidArgumentException('No hay ningún cambio que guardar.');
            }

            // El modo viaja con la tanda porque decide qué estados son válidos:
            // 'lista' existe en Sistema y no en Correo. La empresa no viaja:
            // la pone la sesión, y el modelo solo escribe sobre lo que está en
            // su cola.
            $modelo = $this->loadModel('Seguimiento')
                ->enModo(Seguimiento::modo($this->post('modo', '')))
                ->acotadoA(Sociedad::seleccionadaIdActual());
            $resultado = $modelo->aplicar($items, $cambio, $usuario);
        } catch (Throwable $e) {
            $this->responderFallo('guardar un cambio de seguimiento', $e);
        }
        $this->json(['ok' => true] + $resultado);
    }

    /**
     * El error de una acción, dicho para la persona.
     *
     * Los rechazos de la propia aplicación (InvalidArgumentException) traen un
     * mensaje escrito para quien usa la pantalla y se muestran tal cual. Todo
     * lo demás —la base, un fallo de programación— se anota en el log y la
     * pantalla recibe un mensaje genérico: antes llegaba al navegador el
     * SQLSTATE con el nombre de la columna, que no le sirve a quien trabaja y
     * sí a quien quiera conocer la estructura por dentro.
     */
    private function responderFallo(string $donde, Throwable $e): never
    {
        if ($e instanceof InvalidArgumentException) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $this->registrarFallo($donde, $e);
        $this->json(['ok' => false, 'message' => self::MENSAJE_FALLO], 500);
    }

    private const MENSAJE_FALLO = 'No se pudo completar por un problema del sistema. '
        . 'Inténtalo de nuevo en un momento; si se repite, avisa a quien mantiene Nexo Fiscal.';

    /** El expediente de un renglón: sus datos y su bitácora. */
    public function detalle()
    {
        try {
            $origen = (string) $this->get('origen', '');
            $ref = (int) $this->get('referencia_id', 0);
            $modo = Seguimiento::modo($this->get('modo', ''));
            $modelo = $this->loadModel('Seguimiento')->enModo($modo);

            // De la empresa activa, como la cola que se está viendo; y si no
            // hay ninguna, de una a la que este usuario tenga acceso. Un
            // documento ajeno responde igual que uno inexistente, para que
            // recorrer ids no sirva ni para saber cuáles existen.
            $fila = $modelo->uno($origen, $ref, Sociedad::seleccionadaIdActual());
            if (!$fila || !Permisos::puedeSociedad((int) ($fila['sociedad_id'] ?? 0))) {
                throw new InvalidArgumentException('El renglón no existe o ya no está en la cola.');
            }

            $decoradas = $this->decorar([$fila], $modo);
            // El estado del archivo ya está resuelto arriba; la ruta de una
            // carpeta del disco de la oficina no tiene por qué viajar al
            // navegador.
            unset($decoradas[0]['ruta_xml'], $decoradas[0]['ruta_pdf']);
            $respuesta = [
                'ok' => true,
                'fila' => $decoradas[0],
                'bitacora' => $modelo->bitacora($origen, $ref),
            ];
        } catch (Throwable $e) {
            $this->responderFallo('abrir el expediente de seguimiento', $e);
        }
        $this->json($respuesta);
    }

    /**
     * La cola tal como se ve, en Excel.
     *
     * Antes bajaba un CSV con veintidós columnas fijas mientras la pantalla
     * enseñaba seis: el archivo y lo que se estaba mirando no eran lo mismo, y
     * las veintidós no se podían ver en ningún sitio. Ahora es al revés —las
     * veintidós se pueden encender en la tabla, y el archivo trae exactamente
     * las que estén encendidas—, así que el Excel es lo que hay en pantalla.
     *
     * Cada modo tiene su catálogo: en Correo no hay diferencia contra el ERP
     * que mirar, y en Sistema no hay "cómo llegó".
     */
    public function exportar()
    {
        try {
            $modo = Seguimiento::modo($this->get('modo', ''));
            $modelo = $this->loadModel('Seguimiento')->enModo($modo);
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            $filtros = $this->filtros($sociedad, $modo);

            $filas = $this->decorar($this->colaParaExportar($modelo, $filtros), $modo);

            Columnas::descargar(
                $modo === Seguimiento::MODO_CORREO ? 'seguimiento_correo' : 'seguimiento',
                $filas,
                $this->get('cols', '')
            );
        } catch (Throwable $e) {
            $this->registrarFallo('exportar la cola de seguimiento', $e);
            $this->redirectWithMessage(
                $this->url('/seguimiento'),
                'No se pudo generar el Excel. ' . self::MENSAJE_FALLO,
                'error'
            );
        }
    }

    /**
     * Las filas del Excel: la cola filtrada, hasta TOPE_EXPORTACION.
     *
     * El tope se le pasa a cola() como máximo propio. Sin él, la cola lo
     * recortaba al de una página de la pantalla y el archivo salía con 200
     * renglones sin avisar de que faltaban los demás.
     */
    private function colaParaExportar(Seguimiento $modelo, array $filtros)
    {
        return $modelo->cola($filtros, 1, self::TOPE_EXPORTACION, self::TOPE_EXPORTACION)['filas'];
    }

    private function itemsDelPost()
    {
        $crudos = $this->post('items', []);
        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true) ?: [];
        }
        return is_array($crudos) ? Seguimiento::normalizarItems($crudos) : [];
    }

    private function usuario()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'nombre' => $_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? 'Sistema',
        ];
    }
}
