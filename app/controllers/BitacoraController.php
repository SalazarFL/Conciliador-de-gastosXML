<?php
/**
 * La bitácora: quién hizo qué, cuándo, y desde dónde.
 *
 * Es una sola línea de tiempo, no una lista de tablas. Lo que este sistema
 * registra desde ahora (el enrutador anota cada acción que cambia algo) y lo
 * que ya venía registrando cada módulo por su cuenta —quién cambió un estado
 * de seguimiento, quién subió un reporte, quién cerró un aviso— se mezclan
 * aquí ordenados por fecha. Ver Bitacora::fuentes(): nada se copia ni se
 * migra, se lee cada cosa en su sitio.
 *
 * Se llega desde Configuración → Actividad, que muestra los últimos
 * movimientos y manda aquí para buscar en serio.
 */

require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../helpers/Columnas.php';

class BitacoraController extends Controller
{
    private Bitacora $model;

    /** Cuántos renglones caben en una página del listado. */
    private const POR_PAGINA = 50;

    /** Techo de la exportación: un Excel de 100 mil filas no lo abre nadie. */
    private const TOPE_EXPORTACION = 20000;

    public function __construct()
    {
        $this->requireAuth();
        $this->requirePermiso('bitacora.ver');
        $this->model = new Bitacora();
    }

    // ── Listado ───────────────────────────────────────────────────────────

    public function index()
    {
        $this->recordarFiltros('bitacora', [
            'usuario', 'modulo', 'origen', 'resultado', 'texto', 'desde', 'hasta', 'historial',
        ]);

        $filtros = $this->filtrosPedidos();

        $resultado = ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1];
        $usuarios  = [];
        $fuentes   = [];
        $estado    = ['total' => 0, 'desde' => null, 'hasta' => null, 'hoy' => 0, 'denegados_30d' => 0];
        $fallo     = null;

        try {
            $resultado = $this->model->listar($filtros + ['por_pagina' => self::POR_PAGINA]);
            $usuarios  = $this->model->usuariosConActividad();
            $fuentes   = $this->model->fuentesDisponibles(true);
            $estado    = $this->model->estado();
        } catch (Throwable $e) {
            $this->registrarFallo('listar la bitácora', $e);
            $fallo = 'No se pudo leer la bitácora: ' . $e->getMessage();
        }

        $this->render('bitacora/index', [
            'title'      => 'Actividad — Nexo Fiscal',
            'tituloPagina' => 'Bitácora de actividad',
            'filtros'    => $filtros,
            'resultado'  => $resultado,
            'usuarios'   => $usuarios,
            'fuentes'    => $fuentes,
            'modulos'    => Permisos::modulos(),
            'estado'     => $estado,
            'fallo'      => $fallo,
            'puedeDepurar' => $this->puede('bitacora.depurar'),
        ]);
    }

    // ── El detalle de un renglón ──────────────────────────────────────────

    /**
     * El JSON que abre el cuadro de detalle sobre el listado, en vez de
     * mandar a otra pantalla por una fila. Solo sirve renglones propios de la
     * bitácora: los del historial de cada módulo no tienen más detalle que el
     * que ya se ve en la lista.
     */
    public function detalleRenglon($id)
    {
        try {
            $fila = $this->model->detalle((int) $id);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo leer ese registro.'], 500);
            return;
        }

        if (!$fila) {
            $this->json(['ok' => false, 'message' => 'Ese registro ya no existe.'], 404);
            return;
        }

        $detalle = null;
        if (!empty($fila['detalle'])) {
            $detalle = json_decode((string) $fila['detalle'], true);
            if ($detalle === null) {
                // Los renglones antiguos guardaban texto suelto.
                $detalle = ['nota' => (string) $fila['detalle']];
            }
        }

        $this->json([
            'ok'   => true,
            'fila' => [
                'id'         => (int) $fila['id'],
                'fecha'      => $fila['creado_en'],
                'usuario'    => $fila['nombre_mostrado'],
                'rol'        => $fila['rol_nombre'],
                'empresa'    => $fila['sociedad_nombre'],
                'modulo'     => $fila['modulo'],
                'accion'     => $fila['accion'],
                'permiso'    => $fila['permiso'],
                'entidad'    => trim(((string) $fila['entidad']) . ' ' . ((string) $fila['entidad_id'])),
                'metodo'     => $fila['metodo'],
                'ruta'       => $fila['ruta'],
                'ip'         => $fila['ip'],
                'resultado'  => $fila['resultado'],
                'detalle'    => $detalle,
            ],
        ]);
    }

    // ── Exportar ──────────────────────────────────────────────────────────

    /** Lo mismo que se está viendo, con los mismos filtros, en Excel. */
    public function exportar()
    {
        $filtros = $this->filtrosPedidos();
        $filtros['pagina'] = 1;
        $filtros['por_pagina'] = 200;

        try {
            $filas = [];
            $pagina = 1;

            // Se pide por páginas y no todo de golpe: la consulta unida puede
            // tocar ocho tablas y traerlas enteras a memoria es la forma de
            // que la exportación tumbe el proceso justo cuando más falta hace.
            do {
                $filtros['pagina'] = $pagina;
                $tanda = $this->model->listar($filtros);
                foreach ($tanda['filas'] as $f) {
                    $filas[] = $f;
                }
                $pagina++;
            } while (count($filas) < self::TOPE_EXPORTACION && $pagina <= (int) $tanda['paginas']);

            // Las columnas que estén encendidas en la tabla, no una lista fija:
            // el archivo es lo que se está viendo.
            Columnas::descargar('bitacora', $filas, $this->get('cols', ''));
        } catch (Throwable $e) {
            $this->registrarFallo('exportar la bitácora', $e);
            $this->redirectWithMessage(
                $this->url('/bitacora'),
                'No se pudo generar el Excel: ' . $e->getMessage(),
                'error'
            );
        }
    }

    // ── Depurar ───────────────────────────────────────────────────────────

    /**
     * Borra lo más viejo. La bitácora crece con cada acción de cada persona,
     * y una tabla que nunca se poda acaba siendo el motivo por el que alguien
     * decide apagar el registro entero.
     */
    public function depurar()
    {
        $this->requirePermiso('bitacora.depurar');

        if (!$this->isPost()) {
            $this->redirect($this->url('/bitacora'));
        }

        $dias = (int) $this->post('dias', 365);

        try {
            $borrados = $this->model->depurar($dias);
            $this->redirectWithMessage(
                $this->url('/bitacora'),
                $borrados > 0
                    ? number_format($borrados) . ' registros anteriores a ' . max(30, $dias) . ' días fueron eliminados.'
                    : 'No había registros más antiguos que ' . max(30, $dias) . ' días.',
                'success'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url('/bitacora'),
                'No se pudo depurar: ' . $e->getMessage(),
                'error'
            );
        }
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /**
     * Lo que se pidió filtrar.
     *
     * Sin fechas, se muestran los últimos 30 días. No es un capricho: la
     * consulta que une la bitácora con el historial de siete módulos leería
     * si no todo lo que la empresa ha hecho desde que existe, para pintar
     * cincuenta renglones.
     */
    private function filtrosPedidos(): array
    {
        $desde = trim((string) $this->get('desde', ''));
        $hasta = trim((string) $this->get('hasta', ''));

        if ($desde === '' && $hasta === '') {
            $desde = date('Y-m-d', strtotime('-30 days'));
        }

        return [
            'usuario_id' => (int) $this->get('usuario', 0),
            'modulo'     => trim((string) $this->get('modulo', '')),
            'origen'     => trim((string) $this->get('origen', '')),
            'resultado'  => in_array($this->get('resultado', ''), ['ok', 'denegado', 'error'], true)
                ? (string) $this->get('resultado') : '',
            'texto'      => trim((string) $this->get('texto', '')),
            'desde'      => $this->fechaValida($desde),
            'hasta'      => $this->fechaValida($hasta),
            'historial'  => $this->get('historial', '') !== '',
            'pagina'     => max(1, (int) $this->get('pagina', 1)),
        ];
    }

    private function fechaValida(string $fecha): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : '';
    }

}
