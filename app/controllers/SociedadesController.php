<?php
/**
 * Controlador de Sociedades (se administran desde Inicio).
 *
 * La sociedad activa define la cédula contra la que el módulo de correo
 * verifica el receptor de cada factura.
 */

class SociedadesController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function crear()
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/'));
        }

        $nombre = trim((string) $this->post('nombre', ''));
        $cedula = trim((string) $this->post('cedula', ''));

        if ($nombre === '' || preg_replace('/\D+/', '', $cedula) === '') {
            $this->redirectWithMessage($this->url('/'),'Indica el nombre y una cédula con números.', 'error');
        }

        try {
            $this->loadModel('Sociedad')->crear($nombre, $cedula);
            $this->redirectWithMessage($this->url('/'),'Sociedad "' . $nombre . '" registrada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/'),'No se pudo registrar la sociedad: ' . $e->getMessage(), 'error');
        }
    }

    public function editar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/'));
        }

        $nombre = trim((string) $this->post('nombre', ''));
        $cedula = trim((string) $this->post('cedula', ''));

        if ($nombre === '' || preg_replace('/\D+/', '', $cedula) === '') {
            $this->redirectWithMessage($this->url('/'),'Indica el nombre y una cédula con números.', 'error');
        }

        try {
            $this->loadModel('Sociedad')->actualizar((int) $id, $nombre, $cedula);
            $this->redirectWithMessage($this->url('/'),'Sociedad actualizada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/'),'No se pudo actualizar: ' . $e->getMessage(), 'error');
        }
    }

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/'));
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
                $this->redirectWithMessage($this->url('/'),'Sociedad eliminada. Elige otra sociedad para trabajar.', 'warning');
            }

            $this->redirectWithMessage($this->url('/'),'Sociedad eliminada.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/'),'No se pudo eliminar: ' . $e->getMessage(), 'error');
        }
    }

    public function activar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/'));
        }

        try {
            $modelo = $this->loadModel('Sociedad');
            $modelo->activar((int) $id);
            $sociedad = $modelo->findById((int) $id);
            $nombre = $sociedad ? $sociedad['nombre'] : '';
            $this->redirectWithMessage($this->url('/'),'Trabajando con: ' . $nombre . '.', 'success');
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->url('/'),'No se pudo activar: ' . $e->getMessage(), 'error');
        }
    }
}
