<?php
/**
 * Controlador de Sociedades.
 *
 * La sociedad activa define la cédula contra la que el módulo de correo
 * verifica el receptor de cada factura.
 *
 * Se llega desde dos pantallas y cada una espera volver a la suya: Inicio, que
 * solo cambia con cuál se trabaja, y Configuración → Empresas, donde se
 * registran y se editan. Por eso el destino viaja en el formulario y no está
 * escrito fijo aquí: mandar de vuelta a Inicio a quien estaba administrando
 * empresas le hacía perder el sitio en cada cambio.
 */

class SociedadesController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    /** A dónde volver: la pantalla desde la que se envió el formulario. */
    private function destino(): string
    {
        return $this->post('volver') === 'empresas'
            ? $this->url('/configuracion?ir=empresas')
            : $this->url('/');
    }

    public function crear()
    {
        if (!$this->isPost()) {
            $this->redirect($this->destino());
        }

        $nombre = trim((string) $this->post('nombre', ''));
        $cedula = trim((string) $this->post('cedula', ''));

        if ($nombre === '' || preg_replace('/\D+/', '', $cedula) === '') {
            $this->redirectWithMessage($this->destino(),'Indica el nombre y una cédula con números.', 'error');
        }

        try {
            $this->loadModel('Sociedad')->crear($nombre, $cedula);
            $this->redirectWithMessage($this->destino(),'Sociedad "' . $nombre . '" registrada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->destino(),'No se pudo registrar la sociedad: ' . $e->getMessage(), 'error');
        }
    }

    public function editar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->destino());
        }

        $nombre = trim((string) $this->post('nombre', ''));
        $cedula = trim((string) $this->post('cedula', ''));

        if ($nombre === '' || preg_replace('/\D+/', '', $cedula) === '') {
            $this->redirectWithMessage($this->destino(),'Indica el nombre y una cédula con números.', 'error');
        }

        try {
            $this->loadModel('Sociedad')->actualizar((int) $id, $nombre, $cedula);
            $this->redirectWithMessage($this->destino(),'Sociedad actualizada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->destino(),'No se pudo actualizar: ' . $e->getMessage(), 'error');
        }
    }

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->destino());
        }

        try {
            $modelo = $this->loadModel('Sociedad');
            $sociedad = $modelo->findById((int) $id);
            $eraLaEnUso = (int) ($_SESSION['sociedad_id'] ?? 0) === (int) $id;
            $modelo->eliminar((int) $id);

            // La selección de la sesión apuntaría a una empresa que ya no
            // existe: se limpia para que vuelva a resolverse desde cero.
            if ($eraLaEnUso) {
                unset($_SESSION['sociedad_id']);
                Sociedad::olvidarSeleccion();
            }

            // Si se eliminó la activa, avisar que hay que elegir otra
            if ($sociedad && !empty($sociedad['activa']) && $modelo->getActiva() === null) {
                $this->redirectWithMessage($this->destino(),'Sociedad eliminada. Elige otra sociedad para trabajar.', 'warning');
            }

            $this->redirectWithMessage($this->destino(),'Sociedad eliminada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->destino(),'No se pudo eliminar: ' . $e->getMessage(), 'error');
        }
    }

    public function activar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->destino());
        }

        try {
            $modelo = $this->loadModel('Sociedad');
            $modelo->activar((int) $id);
            $sociedad = $modelo->findById((int) $id);
            $nombre = $sociedad ? $sociedad['nombre'] : '';
            $this->redirectWithMessage($this->destino(),'Trabajando con: ' . $nombre . '.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->destino(),'No se pudo activar: ' . $e->getMessage(), 'error');
        }
    }
}
