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
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';

class SeguimientoController extends Controller
{
    private const POR_PAGINA = 50;

    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        $modelo = $this->loadModel('Seguimiento');
        $sociedad = $this->loadModel('Sociedad')->getActiva();

        $filtros = $this->filtros($sociedad);
        $pagina = max(1, (int) $this->get('pagina', 1));

        $cola = $modelo->cola($filtros, $pagina, self::POR_PAGINA);
        $resumen = $modelo->resumen($filtros);
        $dimensiones = $modelo->dimensionesParaFiltro($sociedad ? (int) $sociedad['id'] : 0);

        $this->render('seguimiento/index', [
            'title' => 'Seguimiento - Nexo Fiscal',
            'sociedadActiva' => $sociedad,
            'filas' => $this->decorar($cola['filas']),
            'paginacion' => [
                'total' => $cola['total'],
                'pagina' => $cola['pagina'],
                'paginas' => $cola['paginas'],
                'por_pagina' => $cola['por_pagina'],
            ],
            'resumen' => $resumen,
            'filtros' => $filtros,
            'responsables' => $modelo->responsables(),
            'proveedoresFiltro' => ProveedorCatalogo::opciones($dimensiones['proveedores']),
            'sucursales' => $dimensiones['sucursales'],
            'estados' => Seguimiento::ESTADOS,
            'tareas' => Seguimiento::TAREAS,
        ]);
    }

    /** Los filtros que entiende la pantalla, saneados. */
    private function filtros($sociedad)
    {
        // Las pestañas son los estados; 'todo' es la única que no lo es.
        $vistas = array_merge(array_keys(Seguimiento::ESTADOS), ['todo']);
        $vista = (string) $this->get('vista', 'pendiente');
        $condicionesSaldo = ['activas', 'canceladas'];
        $condicionSaldo = (string) $this->get('condicion_saldo', '');
        $marcas = ['mano', 'auto', 'desajuste'];
        $marca = (string) $this->get('marca', '');

        return [
            'vista'       => in_array($vista, $vistas, true) ? $vista : 'pendiente',
            'origen'      => (string) $this->get('origen', ''),
            'tarea'       => (string) $this->get('tarea', ''),
            'marca'       => in_array($marca, $marcas, true) ? $marca : '',
            'clase'       => (string) $this->get('clase', ''),
            'responsable' => (string) $this->get('responsable', ''),
            'proveedor'   => ProveedorCatalogo::normalizarClave($this->get('proveedor', '')),
            'sucursal'    => trim((string) $this->get('sucursal', '')),
            'contexto_id' => (int) $this->get('contexto_id', 0),
            'desde'       => $this->fecha($this->get('desde', '')),
            'hasta'       => $this->fecha($this->get('hasta', '')),
            'monto_min'   => trim((string) $this->get('monto_min', '')),
            'condicion_saldo' => in_array($condicionSaldo, $condicionesSaldo, true) ? $condicionSaldo : '',
            'q'           => trim((string) $this->get('q', '')),
            'col_documento' => trim((string) $this->get('col_documento', '')),
            'col_proveedor' => trim((string) $this->get('col_proveedor', '')),
            'col_monto'     => trim((string) $this->get('col_monto', '')),
            'col_saldo'     => trim((string) $this->get('col_saldo', '')),
            'col_respaldo'  => trim((string) $this->get('col_respaldo', '')),
            'col_tarea'     => trim((string) $this->get('col_tarea', '')),
            'orden'       => (string) $this->get('orden', 'monto'),
            'sociedad_id' => $sociedad ? (int) $sociedad['id'] : 0,
        ];
    }

    private function fecha($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
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
    // pantalla, y era una pasada más por una pregunta que el pago semanal ya
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
    private function decorar(array $filas)
    {
        foreach ($filas as &$fila) {
            $fila['xml_ok'] = $this->archivoPresente($fila['ruta_xml'] ?? '');
            $fila['pdf_ok'] = $this->archivoPresente($fila['ruta_pdf'] ?? '');
            $fila['pdf_historico'] = ($fila['estado_pdf'] ?? '') === 'no_disponible_historico';
            $fila['busqueda'] = FacturaMatcher::terminoBusquedaCorreo((string) $fila['documento']);
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

    private function archivoPresente($ruta)
    {
        $ruta = trim((string) $ruta);
        return $ruta !== '' && is_file($ruta);
    }

    // ── Acciones ────────────────────────────────────────────────────────────

    /** Cambio de estado, responsable, fecha o motivo sobre uno o varios. */
    public function actualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            $items = $this->itemsDelPost();
            if (!$items) {
                throw new Exception('No se seleccionó ningún renglón.');
            }

            $cambio = [];
            // Vacío = no tocar la marca (una anotación suelta). El valor
            // SIN_MARCA sí la toca: la borra y devuelve el renglón al cálculo.
            if (($estado = trim((string) $this->post('estado', ''))) !== '') {
                $cambio['estado'] = $estado;
            }
            if ($this->post('responsable', null) !== null) {
                $cambio['responsable'] = trim((string) $this->post('responsable'));
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
                && !array_key_exists('responsable', $cambio)
                && !array_key_exists('recordar_en', $cambio)
                && !array_key_exists('motivo', $cambio)) {
                throw new Exception('No hay ningún cambio que guardar.');
            }

            $resultado = $this->loadModel('Seguimiento')->aplicar($items, $cambio, $this->usuario());
            $this->json(['ok' => true] + $resultado);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** El expediente de un renglón: sus datos y su bitácora. */
    public function detalle()
    {
        try {
            $origen = (string) $this->get('origen', '');
            $ref = (int) $this->get('referencia_id', 0);
            $modelo = $this->loadModel('Seguimiento');

            $fila = $modelo->uno($origen, $ref);
            if (!$fila) {
                throw new Exception('El renglón no existe o ya no está en la cola.');
            }

            $decoradas = $this->decorar([$fila]);
            $this->json([
                'ok' => true,
                'fila' => $decoradas[0],
                'bitacora' => $modelo->bitacora($origen, $ref),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** La cola tal como se ve, en CSV, para trabajarla fuera o mandarla. */
    public function exportar()
    {
        $modelo = $this->loadModel('Seguimiento');
        $sociedad = $this->loadModel('Sociedad')->getActiva();
        $filtros = $this->filtros($sociedad);

        $cola = $modelo->cola($filtros, 1, 5000);
        $filas = $this->decorar($cola['filas']);

        $nombre = 'seguimiento_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');

        $salida = fopen('php://output', 'w');
        // BOM: sin él Excel en Windows abre los acentos rotos.
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, [
            'Origen', 'Documento', 'Clase', 'Proveedor', 'Fecha', 'Moneda', 'Monto',
            'Saldo', 'Diferencia', 'Qué falta', 'XML', 'PDF', 'Estado', 'Puesto a mano',
            'Le tocaría', 'Responsable', 'Recordatorio', 'Motivo', 'Listado',
            'Consecutivo XML', 'Último movimiento',
        ], ';', '"', '\\');

        foreach ($filas as $f) {
            fputcsv($salida, [
                $f['origen'] === 'nota_credito' ? 'Nota de crédito' : 'Factura',
                $f['documento'],
                $f['clase'] ?: '',
                $f['proveedor'],
                $f['fecha'],
                $f['moneda'],
                number_format((float) $f['monto'], 2, '.', ''),
                number_format((float) $f['saldo'], 2, '.', ''),
                $f['diferencia'] !== null ? number_format((float) $f['diferencia'], 2, '.', '') : '',
                Seguimiento::TAREAS[$f['tarea']] ?? $f['tarea'],
                $f['xml_ok'] ? 'Sí' : 'No',
                $f['pdf_ok'] ? 'Sí' : ($f['pdf_historico'] ? 'Histórico' : 'No'),
                Seguimiento::ESTADOS[$f['seguimiento_estado']] ?? $f['seguimiento_estado'],
                $f['estado_a_mano'] ? 'Sí' : '',
                // Solo cuando la marca a mano y el cálculo no coinciden: en el
                // resto de las filas repetir el estado no dice nada.
                $f['desajustada'] ? (Seguimiento::ESTADOS[$f['estado_calculado']] ?? $f['estado_calculado']) : '',
                $f['responsable'] ?: '',
                $f['recordar_en'] ?: '',
                $f['motivo'] ?: '',
                $f['contexto'],
                $f['consecutivo'] ?: '',
                $f['seguimiento_actualizado_en'] ?: '',
            ], ';', '"', '\\');
        }
        fclose($salida);
        exit;
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
