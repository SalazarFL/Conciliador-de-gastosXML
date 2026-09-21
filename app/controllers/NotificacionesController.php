<?php
/**
 * La campana de la barra superior y su pantalla de revisión.
 *
 * Por qué existe: lo único que tenía la aplicación para decir algo era el
 * toast de `flash_message`, que dura siete segundos y después no existió
 * nunca. Sirve para "se cargaron 40 facturas"; no sirve para "este código del
 * ERP parece haber cambiado de proveedor, decidí", que es un aviso que tiene
 * que esperar a que alguien lo atienda aunque nadie estuviera viendo la
 * pantalla en ese momento.
 *
 * Los avisos son del equipo, no de cada quien: la base es una sola y si Sofía
 * resuelve una confirmación, queda resuelta para todos.
 */

require_once __DIR__ . '/../models/Notificacion.php';
require_once __DIR__ . '/../models/ProveedorCodigoErp.php';

class NotificacionesController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    /** La pantalla completa, con el historial. */
    public function index()
    {
        $estado = (string) $this->get('estado', 'pendiente');
        if (!in_array($estado, ['pendiente', 'resuelta', 'descartada', 'todas'], true)) {
            $estado = 'pendiente';
        }

        $modelo = $this->loadModel('Notificacion');
        $avisos = $modelo->listar($estado === 'todas' ? '' : $estado);

        foreach ($avisos as &$aviso) {
            $aviso['datos_obj'] = $this->datosDe($aviso);
        }
        unset($aviso);

        $this->render('notificaciones.index', [
            'title'   => 'Avisos',
            'avisos'  => $avisos,
            'estado'  => $estado,
            'resumen' => [
                'pendientes' => $modelo->pendientes(),
            ],
        ]);
    }

    /**
     * Lo que pide la campana para pintarse. Se llama en cada carga de página,
     * así que devuelve lo mínimo y nunca falla con error: si algo va mal, la
     * campana simplemente aparece en cero.
     */
    public function resumen()
    {
        try {
            // Único reloj disponible: no hay tarea programada, así que los
            // recordatorios de seguimiento que ya vencieron se convierten en
            // avisos aquí, cuando alguien abre cualquier pantalla. Va dentro
            // del try de siempre: si falla, la campana se pinta igual.
            $this->loadModel('Seguimiento')->generarRecordatoriosSiToca();

            $modelo = $this->loadModel('Notificacion');
            $avisos = $modelo->recientes();
            foreach ($avisos as &$aviso) {
                $aviso['datos_obj'] = $this->datosDe($aviso);
            }
            unset($aviso);

            $this->json([
                'ok'          => true,
                'pendientes'  => $modelo->pendientes(),
                'avisos'      => $avisos,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => true, 'pendientes' => 0, 'avisos' => []]);
        }
    }

    /**
     * La lista de comprobantes de proveedores sin código (POST, JSON).
     *
     * Estos avisos ya no suenan en la campana —ver Notificacion::FUERA_DE_LA_CAMPANA—
     * porque llegan a decenas y tapaban lo que sí urgía. Su sitio es el botón
     * "Comprobantes sin código" de Correo, que es la pantalla donde se está
     * cuando aparecen. Aquí se sirven todos juntos para poder despacharlos de
     * corrido, sin abandonar el buzón.
     *
     * Devuelve también el conteo de pendientes aunque se estén pidiendo los ya
     * decididos: es el número del botón, y tiene que quedar al día después de
     * resolver sin recargar la página entera.
     */
    public function sinCodigo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $estado = (string) $this->post('estado', 'pendiente');
        if (!in_array($estado, ['pendiente', 'resuelta', 'descartada', 'todas'], true)) {
            $estado = 'pendiente';
        }

        try {
            $modelo = $this->loadModel('Notificacion');
            $filas = $modelo->porTipo(
                'proveedor_sin_codigo',
                $estado === 'todas' ? '' : $estado
            );

            $avisos = [];
            foreach ($filas as $fila) {
                $datos = $this->datosDe($fila);
                $avisos[] = [
                    'id'       => (int) $fila['id'],
                    // La cédula vive en dos sitios por historia: en los datos del
                    // aviso y en su referencia. Con los avisos viejos a la vista,
                    // el respaldo no sobra.
                    'cedula'   => (string) ($datos['cedula'] ?? $fila['ref_clave']),
                    'nombre'   => (string) ($datos['nombre'] ?? ''),
                    'veces'    => (int) $fila['veces'],
                    'estado'   => (string) $fila['estado'],
                    'creada_en' => (string) $fila['creada_en'],
                    'motivo'   => (string) ($fila['motivo'] ?? ''),
                    'resuelta_por_nombre' => (string) ($fila['resuelta_por_nombre'] ?? ''),
                ];
            }

            $this->json([
                'ok' => true,
                'estado' => $estado,
                'pendientes' => $modelo->contarPorTipo('proveedor_sin_codigo'),
                'avisos' => $avisos,
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo leer la lista: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Los códigos entre los que elegir, para la lista de Correo (POST, JSON).
     *
     * El mismo buscador que Configuración → Proveedores, y a propósito: es la
     * misma pregunta hecha desde la otra punta. Allá se parte de un código
     * huérfano y se le busca dueño; acá se parte de un comprobante huérfano y se
     * le busca código. Lo que se elige sale del mismo catálogo.
     *
     * Tiene ruta propia y no se reusa la de Configuración porque los permisos
     * son otros: quién despacha comprobantes y quién administra el catálogo no
     * son la misma persona, y colgarse de aquella ruta obligaba a darle a la
     * primera las llaves de Configuración entera.
     *
     * Con `buscar`, lo que coincida por código, nombre o cédula. Sin él, los que
     * se parecen al nombre del proveedor: la lista abre con los candidatos ya
     * puestos, que es lo primero que uno escribiría.
     */
    public function opcionesCodigo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            $modelo = $this->loadModel('ProveedorCodigoErp');
            $buscar = trim((string) $this->post('buscar', ''));

            $this->json([
                'ok' => true,
                'sugeridas' => $buscar === '',
                'opciones' => $buscar !== ''
                    ? $modelo->opcionesDeCodigo($buscar)
                    : $modelo->opcionesParecidas((string) $this->post('nombre', '')),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo buscar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * "Este proveedor sí nos factura, y este es su código."
     *
     * La primera de las dos salidas del aviso de un comprobante que no
     * pertenece a ningún código del ERP. Da de alta el código en el catálogo,
     * con lo cual el comprobante deja de estar huérfano —y todos los demás de
     * esa misma cédula con él— y las facturas de ese código pueden volver a
     * emparejar.
     */
    public function codigoNuevo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $codigo = trim((string) $this->post('codigo', ''));
        $cedula = trim((string) $this->post('cedula', ''));
        $nombre = trim((string) $this->post('nombre', ''));
        $avisoId = (int) $this->post('aviso_id', 0);

        if ($codigo === '' || $cedula === '') {
            $this->json(['ok' => false, 'message' => 'Falta el código o la cédula.'], 422);
        }

        /*
         * La lista ya no deja escribir un código a mano, y esto lo sostiene
         * aunque alguien mande el formulario por su cuenta. Un código escrito
         * que no existe es casi siempre una cédula o un número mal copiado, y
         * ese error no se ve hasta que una factura acepta el comprobante
         * equivocado —que es justo lo que este aviso quería evitar.
         */
        if ($this->post('existente', '') === '1'
            && !$this->loadModel('ProveedorCodigoErp')->codigoExiste($codigo)) {
            $this->json([
                'ok' => false,
                'message' => 'Ese código no está en la lista ni en las facturas del sistema. '
                           . 'Si el proveedor es nuevo, importá de nuevo el listado del sistema.',
            ], 422);
        }

        try {
            $r = $this->loadModel('ProveedorCodigoErp')
                ->guardarCodigo($codigo, $cedula, $nombre, (int) ($_SESSION['user_id'] ?? 0));
            if (empty($r['ok'])) {
                $this->json(['ok' => false, 'message' => $r['message']], 422);
            }

            if ($avisoId > 0) {
                $this->loadModel('Notificacion')->cerrar(
                    $avisoId, 'resuelta', (int) ($_SESSION['user_id'] ?? 0),
                    'Se le dio el código ' . $codigo
                );
            }

            // Con el código dado de alta, las facturas que se habían quedado
            // sin respaldo por no tener con qué emparejar pueden repararse.
            $this->json(['ok' => true, 'reparadas' => $this->reverificarPendientes()]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * "Este proveedor no nos factura: no lo procesés."
     *
     * La otra salida. El comprobante ya entró y se queda donde está —no se
     * borra nada—, pero la cédula deja de preguntarse: ni este comprobante ni
     * los que lleguen después de ella vuelven a pedir una decisión.
     */
    public function ignorarProveedor()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $cedula = trim((string) $this->post('cedula', ''));
        $avisoId = (int) $this->post('aviso_id', 0);
        if ($cedula === '') {
            $this->json(['ok' => false, 'message' => 'Cédula no indicada.'], 422);
        }

        $r = $this->loadModel('ProveedorCodigoErp')->ignorarCedula(
            $cedula,
            (string) $this->post('nombre', ''),
            (string) $this->post('motivo', ''),
            (int) ($_SESSION['user_id'] ?? 0)
        );
        if (empty($r['ok'])) {
            $this->json(['ok' => false, 'message' => $r['message']], 422);
        }

        if ($avisoId > 0) {
            $this->loadModel('Notificacion')->cerrar(
                $avisoId, 'resuelta', (int) ($_SESSION['user_id'] ?? 0), 'Proveedor marcado como no procesable'
            );
        }

        $this->json(['ok' => true]);
    }

    /**
     * Cierra un aviso sin decidir nada: "ya lo vi".
     *
     * En un recordatorio de Seguimiento cerrar el aviso no basta: el aviso es
     * el recado y la alarma que lo manda vive en el renglón, así que al día
     * siguiente volvía a sonar y el botón parecía roto. Ver
     * Seguimiento::apagarRecordatorio().
     */
    public function cerrar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $id = (int) $this->post('id', 0);
        $estado = (string) $this->post('estado', 'descartada');
        if ($id <= 0) {
            $this->json(['ok' => false, 'message' => 'Aviso no indicado.'], 422);
        }

        // Se lee ANTES de cerrarlo: después ya no se sabría de qué renglón
        // era la alarma que hay que apagar.
        $avisos = $this->loadModel('Notificacion');
        $aviso  = $avisos->porId($id);

        $filas = $avisos->cerrar(
            $id, $estado, (int) ($_SESSION['user_id'] ?? 0), (string) $this->post('motivo', '')
        );

        // Cerrar y no cerrar contestaban lo mismo, y la pantalla recargaba
        // igual: el aviso seguía ahí sin que nadie dijera por qué.
        if ($filas <= 0) {
            $this->json([
                'ok'      => false,
                'message' => $aviso === null
                    ? 'Ese aviso ya no está, o es de otra empresa. Recarga la página.'
                    : 'No se pudo cerrar el aviso. Inténtalo de nuevo.',
            ]);
        }

        $this->apagarAlarmaDe($aviso);

        $this->json(['ok' => true]);
    }

    // ── Auxiliares ─────────────────────────────────────────────────

    /**
     * Apaga la alarma que produjo un recordatorio que se acaba de cerrar.
     *
     * Solo los recordatorios de Seguimiento tienen alarma detrás; los demás
     * avisos son hechos sueltos y cerrarlos ya es todo lo que hay que hacer.
     *
     * El renglón viene en los datos del aviso, y si no —los primeros
     * recordatorios no los guardaban— se saca de `ref_clave`, que es el mismo
     * par escrito como "origen|id".
     *
     * Nunca tumba el cierre: el aviso ya está cerrado cuando esto corre, y
     * que la alarma no se pudiera apagar no es razón para contestar que no se
     * hizo nada.
     */
    private function apagarAlarmaDe($aviso)
    {
        if (!is_array($aviso) || ($aviso['tipo'] ?? '') !== 'seguimiento_recordatorio') {
            return;
        }

        $datos = $this->datosDe($aviso);
        $origen = trim((string) ($datos['origen'] ?? ''));
        $referencia = (int) ($datos['referencia_id'] ?? 0);

        if ($origen === '' || $referencia <= 0) {
            $partes = explode('|', (string) ($aviso['ref_clave'] ?? ''), 2);
            if (count($partes) === 2) {
                $origen = trim($partes[0]);
                $referencia = (int) $partes[1];
            }
        }
        if ($origen === '' || $referencia <= 0) {
            return;
        }

        try {
            $this->loadModel('Seguimiento')->apagarRecordatorio($origen, $referencia, [
                'id'     => $_SESSION['user_id'] ?? null,
                'nombre' => $_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? 'Sistema',
            ]);
        } catch (Throwable $e) {
            // Ver arriba: el aviso ya quedó cerrado.
        }
    }

    private function reverificarPendientes()
    {
        try {
            require_once __DIR__ . '/../helpers/PorPagarVerificador.php';
            return PorPagarVerificador::verificarPendientes(
                $this->loadModel('FacturaErp'),
                $this->loadModel('Factura')
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function datosDe(array $aviso)
    {
        if (empty($aviso['datos'])) {
            return [];
        }
        $obj = json_decode((string) $aviso['datos'], true);
        return is_array($obj) ? $obj : [];
    }
}
