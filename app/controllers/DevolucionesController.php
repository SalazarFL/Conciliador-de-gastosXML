<?php
require_once __DIR__ . '/../helpers/DevolucionImporter.php';
require_once __DIR__ . '/../helpers/DevolucionVerificador.php';

class DevolucionesController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        $modelo = $this->loadModel('Devolucion');
        $sociedad = $this->loadModel('Sociedad')->getActiva();

        $filtros = [
            'sociedad_id' => $sociedad ? (int) $sociedad['id'] : null,
            'tipo' => trim((string) $this->get('tipo', '')),
            'estado' => trim((string) $this->get('estado', '')),
            'q' => trim((string) $this->get('q', '')),
        ];

        $this->render('devoluciones/index', [
            'title' => 'Devoluciones - XMLConcilia',
            'sociedadActiva' => $sociedad,
            'devoluciones' => $modelo->listar($filtros),
            'resumen' => $modelo->resumen($sociedad ? (int) $sociedad['id'] : null),
            'filtros' => $filtros,
        ]);
    }

    public function subir()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }

        require_once __DIR__ . '/../helpers/FileUploader.php';
        $config = require __DIR__ . '/../config/config.php';
        $tempDir = rtrim((string) $config['uploads_path'], '/\\') . DIRECTORY_SEPARATOR . 'devoluciones_tmp';

        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
            $archivos = FileUploader::uploadMultiple(
                'pdf_files',
                $tempDir,
                ['pdf'],
                $config['max_upload_size'] ?? 10485760
            );

            $modelo = $this->loadModel('Devolucion');
            $importer = new DevolucionImporter($modelo);
            $contexto = [
                'sociedad_id' => $sociedad ? (int) $sociedad['id'] : null,
                'proveedores' => $modelo->proveedoresActivos(),
            ];

            $conteo = ['importada' => 0, 'duplicada' => 0, 'rechazada' => 0];
            $detalles = [];
            foreach ($archivos as $archivo) {
                try {
                    $r = $importer->importar($archivo['path'], $contexto + [
                        'nombre_original' => $archivo['original_name'],
                    ]);
                } catch (Throwable $e) {
                    $r = ['estado' => 'rechazada', 'archivo' => $archivo['original_name'], 'errores' => [$e->getMessage()]];
                } finally {
                    @unlink($archivo['path']);
                }

                $conteo[$r['estado']] = ($conteo[$r['estado']] ?? 0) + 1;
                if ($r['estado'] === 'importada') {
                    $detalles[] = $r['archivo'] . ' → ' . $r['tipo'] . ' #' . $r['numero']
                        . ' (' . $r['verificacion'] . ')';
                } elseif ($r['estado'] === 'duplicada') {
                    $detalles[] = $r['archivo'] . ' → ya estaba importado (#' . $r['numero'] . ').';
                } else {
                    $detalles[] = $r['archivo'] . ' → RECHAZADO: ' . implode(' ', $r['errores'] ?? []);
                }
            }

            $tipo = $conteo['rechazada'] > 0 ? ($conteo['importada'] > 0 ? 'warning' : 'error') : 'success';
            $this->redirectWithMessage(
                $this->url('/devoluciones'),
                sprintf(
                    'Importadas: %d · Duplicadas: %d · Rechazadas: %d',
                    $conteo['importada'], $conteo['duplicada'], $conteo['rechazada']
                ),
                $tipo,
                $detalles
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/devoluciones'), $e->getMessage(), 'error');
        }
    }

    public function detalle($id)
    {
        $modelo = $this->loadModel('Devolucion');
        $dev = $modelo->getDevolucion((int) $id);
        if ($dev === null) {
            $this->redirectWithMessage($this->url('/devoluciones'), 'La devolución no existe.', 'error');
        }

        $matches = $modelo->getMatches((int) $id);
        $objetivos = DevolucionVerificador::objetivos($dev);

        // Candidatas para vinculación manual de los objetivos aún sin confirmar.
        $confirmados = [];
        foreach ($matches as $m) {
            if ($m['estado'] === 'confirmado') {
                $confirmados[(string) $m['objetivo']] = true;
            }
        }
        $candidatas = [];
        foreach ($objetivos as $objetivo => $monto) {
            if (!isset($confirmados[$objetivo])) {
                $candidatas[$objetivo] = $modelo->ncCandidatas(
                    !empty($dev['proveedor_id']) ? (int) $dev['proveedor_id'] : null,
                    $monto
                );
            }
        }

        $this->render('devoluciones/detalle', [
            'title' => 'Devolución ' . $dev['numero'] . ' - XMLConcilia',
            'dev' => $dev,
            'lineas' => $modelo->getLineas((int) $id),
            'matches' => $matches,
            'objetivos' => $objetivos,
            'candidatas' => $candidatas,
        ]);
    }

    public function verificar($id = null)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }
        $modelo = $this->loadModel('Devolucion');

        try {
            if ($id !== null) {
                $estado = DevolucionVerificador::verificar((int) $id, $modelo);
                $this->redirectWithMessage(
                    $this->url('/devoluciones/detalle/' . (int) $id),
                    'Verificación completada: ' . $estado . '.'
                );
            }
            $stats = DevolucionVerificador::verificarPendientes($modelo);
            $this->redirectWithMessage(
                $this->url('/devoluciones'),
                sprintf(
                    'Verificadas: %d · Parciales: %d · Sin NC: %d',
                    $stats['verificada'], $stats['parcial'], $stats['sin_nc']
                )
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/devoluciones'), $e->getMessage(), 'error');
        }
    }

    /** Confirmar manualmente un match sugerido. */
    public function confirmar()
    {
        $this->accionSobreMatch(function ($modelo, $match) {
            $modelo->actualizarMatchEstado((int) $match['id'], 'confirmado', 'manual');
            return 'Match confirmado.';
        });
    }

    /** Descartar un match sugerido. */
    public function descartar()
    {
        $this->accionSobreMatch(function ($modelo, $match) {
            $modelo->actualizarMatchEstado((int) $match['id'], 'descartado');
            return 'Sugerencia descartada.';
        });
    }

    /** Vincular manualmente una NC a un objetivo. */
    public function vincular()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }
        $modelo = $this->loadModel('Devolucion');
        $devolucionId = (int) $this->post('devolucion_id', 0);

        try {
            $objetivo = (string) $this->post('objetivo', '');
            $facturaXmlId = (int) $this->post('factura_xml_id', 0);
            $dev = $modelo->getDevolucion($devolucionId);
            if ($dev === null || !in_array($objetivo, ['cantidad', 'costo', 'total'], true) || $facturaXmlId <= 0) {
                throw new Exception('Datos de vinculación incompletos.');
            }
            $objetivos = DevolucionVerificador::objetivos($dev);
            if (!isset($objetivos[$objetivo])) {
                throw new Exception('El objetivo no aplica a esta devolución.');
            }
            $nc = $modelo->getNc($facturaXmlId);
            if ($nc === null) {
                throw new Exception('La NC seleccionada no existe.');
            }

            $modelo->crearMatchManual(
                $devolucionId,
                $objetivo,
                $objetivos[$objetivo],
                $facturaXmlId,
                (float) $nc['total'],
                'Vinculada manualmente por el usuario.'
            );
            $this->recalcularEstado($modelo, $devolucionId);
            $this->redirectWithMessage(
                $this->url('/devoluciones/detalle/' . $devolucionId),
                'NC vinculada al objetivo ' . $objetivo . '.'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url($devolucionId > 0 ? '/devoluciones/detalle/' . $devolucionId : '/devoluciones'),
                $e->getMessage(),
                'error'
            );
        }
    }

    /** Quitar los vínculos de un objetivo y re-verificar la devolución. */
    public function desvincular()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }
        $modelo = $this->loadModel('Devolucion');
        $devolucionId = (int) $this->post('devolucion_id', 0);

        try {
            $objetivo = (string) $this->post('objetivo', '');
            $modelo->eliminarMatchesObjetivo($devolucionId, $objetivo);
            $estado = DevolucionVerificador::verificar($devolucionId, $modelo);
            $this->redirectWithMessage(
                $this->url('/devoluciones/detalle/' . $devolucionId),
                'Vínculo eliminado; re-verificado (' . $estado . ').'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url($devolucionId > 0 ? '/devoluciones/detalle/' . $devolucionId : '/devoluciones'),
                $e->getMessage(),
                'error'
            );
        }
    }

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }
        $modelo = $this->loadModel('Devolucion');

        try {
            $dev = $modelo->getDevolucion((int) $id);
            if ($dev === null) {
                throw new Exception('La devolución no existe.');
            }
            $modelo->eliminar((int) $id);
            if (!empty($dev['ruta_pdf']) && is_file($dev['ruta_pdf'])) {
                @unlink($dev['ruta_pdf']);
            }
            $this->redirectWithMessage(
                $this->url('/devoluciones'),
                'Devolución #' . $dev['numero'] . ' eliminada.'
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/devoluciones'), $e->getMessage(), 'error');
        }
    }

    public function pdf($id)
    {
        $modelo = $this->loadModel('Devolucion');
        $dev = $modelo->getDevolucion((int) $id);
        if ($dev === null || empty($dev['ruta_pdf']) || !is_file($dev['ruta_pdf'])) {
            $this->redirectWithMessage($this->url('/devoluciones'), 'El PDF de la devolución no está disponible.', 'error');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename((string) $dev['archivo_pdf']) . '"');
        header('Content-Length: ' . filesize($dev['ruta_pdf']));
        readfile($dev['ruta_pdf']);
        exit;
    }

    private function accionSobreMatch($accion)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/devoluciones'));
        }
        $modelo = $this->loadModel('Devolucion');
        $matchId = (int) $this->post('match_id', 0);

        try {
            $match = $modelo->getMatch($matchId);
            if ($match === null) {
                throw new Exception('El match no existe.');
            }
            $mensaje = $accion($modelo, $match);
            $this->recalcularEstado($modelo, (int) $match['devolucion_id']);
            $this->redirectWithMessage(
                $this->url('/devoluciones/detalle/' . (int) $match['devolucion_id']),
                $mensaje
            );
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/devoluciones'), $e->getMessage(), 'error');
        }
    }

    private function recalcularEstado($modelo, $devolucionId)
    {
        $dev = $modelo->getDevolucion((int) $devolucionId);
        if ($dev === null) {
            return;
        }
        $objetivos = DevolucionVerificador::objetivos($dev);
        $confirmados = [];
        foreach ($modelo->getMatches((int) $devolucionId) as $m) {
            if ($m['estado'] === 'confirmado' && isset($objetivos[(string) $m['objetivo']])) {
                $confirmados[(string) $m['objetivo']] = true;
            }
        }
        $modelo->actualizarEstado(
            (int) $devolucionId,
            DevolucionVerificador::estadoGlobal(count($objetivos), count($confirmados))
        );
    }
}
