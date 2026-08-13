<?php
/**
 * Seguimiento: la cola única de trabajo.
 *
 * Los demás módulos son para cargar y comparar; este es para trabajar. Junta
 * lo que le falta respaldo o no cuadra —venga de notas de crédito o del pago
 * semanal— y deja constancia de qué hizo cada quien con cada renglón.
 */
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';

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

        $this->render('seguimiento/index', [
            'title' => 'Seguimiento - XMLConcilia',
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
            'contextos' => $this->contextos($sociedad),
            'estados' => Seguimiento::ESTADOS,
            'tareas' => Seguimiento::TAREAS,
        ]);
    }

    /** Los filtros que entiende la pantalla, saneados. */
    private function filtros($sociedad)
    {
        $vistas = ['abierto', 'pospuesto', 'cerrado', 'completo', 'todo'];
        $vista = (string) $this->get('vista', 'abierto');
        $condicionesSaldo = ['activas', 'canceladas'];
        $condicionSaldo = (string) $this->get('condicion_saldo', '');

        return [
            'vista'       => in_array($vista, $vistas, true) ? $vista : 'abierto',
            'origen'      => (string) $this->get('origen', ''),
            'tarea'       => (string) $this->get('tarea', ''),
            'estado'      => (string) $this->get('estado', ''),
            'clase'       => (string) $this->get('clase', ''),
            'responsable' => (string) $this->get('responsable', ''),
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
            'col_estado'    => trim((string) $this->get('col_estado', '')),
            'orden'       => (string) $this->get('orden', 'monto'),
            'sociedad_id' => $sociedad ? (int) $sociedad['id'] : 0,
        ];
    }

    private function fecha($valor)
    {
        $valor = trim((string) $valor);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
    }

    /** Los listados abiertos de ambos módulos, para acotar por origen concreto. */
    private function contextos($sociedad)
    {
        if (!$sociedad) {
            return [];
        }
        $modelo = $this->loadModel('Seguimiento');
        try {
            return $modelo->contextosDisponibles((int) $sociedad['id']);
        } catch (Throwable $e) {
            return [];
        }
    }

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
            $fila['vencido'] = !empty($fila['vence_el'])
                && $fila['vence_el'] < date('Y-m-d')
                && !in_array($fila['seguimiento_estado'], Seguimiento::ESTADOS_CERRADOS, true);
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
            if (($estado = trim((string) $this->post('estado', ''))) !== '') {
                $cambio['estado'] = $estado;
            }
            if ($this->post('responsable', null) !== null) {
                $cambio['responsable'] = trim((string) $this->post('responsable'));
            }
            if ($this->post('vence_el', null) !== null) {
                $cambio['vence_el'] = $this->fecha($this->post('vence_el'));
            }
            if ($this->post('posponer_dias', null) !== null) {
                $dias = max(1, min(365, (int) $this->post('posponer_dias')));
                $cambio['vence_el'] = date('Y-m-d', strtotime("+{$dias} days"));
            }
            if ($this->post('motivo', null) !== null) {
                $cambio['motivo'] = trim((string) $this->post('motivo'));
            }
            $cambio['comentario'] = trim((string) $this->post('comentario', ''));

            if (!isset($cambio['estado']) && $cambio['comentario'] === ''
                && !array_key_exists('responsable', $cambio)
                && !array_key_exists('vence_el', $cambio)
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
            'Saldo', 'Diferencia', 'Tarea', 'XML', 'PDF', 'Estado seguimiento', 'Responsable',
            'Vence', 'Motivo', 'Listado', 'Consecutivo XML', 'Último movimiento',
        ], ';');

        foreach ($filas as $f) {
            fputcsv($salida, [
                $f['origen'] === 'nota_credito' ? 'Nota de crédito' : 'Pago semanal',
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
                $f['responsable'] ?: '',
                $f['vence_el'] ?: '',
                $f['motivo'] ?: '',
                $f['contexto'],
                $f['consecutivo'] ?: '',
                $f['seguimiento_actualizado_en'] ?: '',
            ], ';');
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
