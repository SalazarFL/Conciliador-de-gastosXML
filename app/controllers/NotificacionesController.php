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

        // Cada aviso de código trae su propio contexto: los candidatos y los
        // vetos que lo originaron. Se resuelve en la misma pantalla, sin tener
        // que ir a buscar a qué proveedor pertenece cada cédula.
        $mapa = $this->loadModel('ProveedorCodigoErp');
        foreach ($avisos as &$aviso) {
            $aviso['datos_obj'] = $this->datosDe($aviso);
            if (($aviso['tipo'] ?? '') === 'codigo_proveedor' && $aviso['ref_clave'] !== '') {
                $aviso['candidatos'] = $mapa->proveedoresSugeridos($aviso['ref_clave']);
                $aviso['conflictos'] = $mapa->conflictosDe($aviso['ref_clave'], 5);
            }
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
            $this->loadModel('Seguimiento')->generarRecordatorios();

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
     * "Este código es de este proveedor."
     *
     * Es la decisión humana explícita, y por eso manda sobre el contador: una
     * persona que sabe que el código cambió de dueño vale más que setenta
     * emparejamientos viejos. Queda con origen 'manual' y la cosecha no lo
     * vuelve a tocar.
     */
    public function confirmarCodigo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $codigo = trim((string) $this->post('codigo', ''));
        $proveedorId = (int) $this->post('proveedor_id', 0);
        $avisoId = (int) $this->post('aviso_id', 0);

        if ($codigo === '' || $proveedorId <= 0) {
            $this->json(['ok' => false, 'message' => 'Falta el código o el proveedor.'], 422);
        }

        try {
            $ok = $this->loadModel('ProveedorCodigoErp')
                ->confirmarManual($codigo, $proveedorId, (int) ($_SESSION['user_id'] ?? 0));
            if (!$ok) {
                $this->json(['ok' => false, 'message' => 'No se pudo guardar la confirmación.'], 500);
            }

            if ($avisoId > 0) {
                $this->loadModel('Notificacion')->cerrar(
                    $avisoId, 'resuelta', (int) ($_SESSION['user_id'] ?? 0), 'Código confirmado a mano'
                );
            }

            // Con el mapa corregido, los pagos que quedaron sin respaldo por
            // culpa del veto pueden repararse solos.
            $reparadas = $this->reverificarPendientes();

            $this->json(['ok' => true, 'reparadas' => $reparadas]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo confirmar: ' . $e->getMessage()], 500);
        }
    }

    /** Cierra un aviso sin decidir nada: "ya lo vi". */
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

        $filas = $this->loadModel('Notificacion')
            ->cerrar($id, $estado, (int) ($_SESSION['user_id'] ?? 0), (string) $this->post('motivo', ''));

        $this->json(['ok' => $filas > 0]);
    }

    /**
     * "No sé de quién es, pero que deje de bloquear."
     *
     * La salida honesta para cuando no hay con qué decidir: el código queda en
     * disputa, la guarda se abstiene y el emparejamiento vuelve a comportarse
     * como antes de que existiera el mapa. No es la mejor respuesta, pero es
     * mejor que dejar facturas atascadas esperando una decisión que nadie
     * puede tomar todavía.
     */
    public function liberarCodigo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $codigo = trim((string) $this->post('codigo', ''));
        $avisoId = (int) $this->post('aviso_id', 0);
        if ($codigo === '') {
            $this->json(['ok' => false, 'message' => 'Código no indicado.'], 422);
        }

        $this->loadModel('ProveedorCodigoErp')->ponerEnDisputa($codigo, true);
        if ($avisoId > 0) {
            $this->loadModel('Notificacion')->cerrar(
                $avisoId, 'resuelta', (int) ($_SESSION['user_id'] ?? 0), 'Código liberado sin decidir'
            );
        }

        $this->json(['ok' => true, 'reparadas' => $this->reverificarPendientes()]);
    }

    // ── Auxiliares ─────────────────────────────────────────────────

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
