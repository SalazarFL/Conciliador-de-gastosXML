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
 *
 * Dos permisos distintos, porque son dos cosas distintas: dar de alta una
 * empresa es administración (`empresas.administrar`, lo comprueba el
 * enrutador); ELEGIR con cuál se trabaja lo hace cualquiera, pero solo entre
 * las que tiene asignadas, y eso se comprueba aquí —esconder los botones de
 * las demás no serviría de nada contra un POST escrito a mano.
 */

require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Sociedad.php';

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

        if ($nombre === '') {
            $this->redirectWithMessage($this->destino(),'Indica el nombre de la empresa.', 'error');
        }

        // La cédula se comprueba aquí solo para contestar sin el "No se pudo
        // registrar la sociedad:" delante. La comprobación de verdad está en
        // el modelo, que es por donde pasa todo el que la escriba.
        try {
            $cedula = Sociedad::normalizarCedula($cedula);
        } catch (InvalidArgumentException $e) {
            $this->redirectWithMessage($this->destino(), $e->getMessage(), 'error');
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

        if ($nombre === '') {
            $this->redirectWithMessage($this->destino(),'Indica el nombre de la empresa.', 'error');
        }

        try {
            $cedula = Sociedad::normalizarCedula($cedula);
        } catch (InvalidArgumentException $e) {
            $this->redirectWithMessage($this->destino(), $e->getMessage(), 'error');
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

        // Aquí está la restricción por empresa de verdad. El selector de
        // Inicio solo ofrece las permitidas, pero un POST no viene
        // necesariamente de ese selector.
        if (!Permisos::puedeSociedad((int) $id)) {
            Bitacora::registrar([
                'accion'     => 'Intentó trabajar con una empresa que no tiene asignada',
                'modulo'     => 'administracion',
                'permiso'    => 'empresas.administrar',
                'entidad'    => 'sociedad',
                'entidad_id' => (int) $id,
                'resultado'  => 'denegado',
            ]);
            $this->redirectWithMessage(
                $this->destino(),
                'No tienes acceso a esa empresa. Habla con el administrador.',
                'error'
            );
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

    /**
     * Dónde se archiva lo NUEVO de una empresa: la carpeta compartida o
     * SharePoint.
     *
     * No mueve nada de lo que ya está —cada documento sigue donde dice su
     * fila; pasar lo existente es cli/migrar_documentos_a_graph.php—. Por eso
     * cambiarlo es inmediato y volver atrás también.
     *
     * Antes de pasar a SharePoint se prueba de verdad que esta PC llega a la
     * biblioteca, y si no llega no se guarda nada: el ajuste lo usan todas las
     * PC, y quien lo cambia tiene que ver en el momento que funciona, no
     * enterarse mañana por un documento que no se archivó.
     */
    public function almacen($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->destino());
        }
        require_once __DIR__ . '/../helpers/AlmacenDocumentos.php';

        $tipo = strtolower(trim((string) $this->post('almacen', '')));
        $modelo = $this->loadModel('Sociedad');
        $sociedad = $modelo->findById((int) $id);
        if (!$sociedad) {
            $this->redirectWithMessage($this->destino(), 'Esa empresa no existe.', 'error');
        }
        $nombre = (string) $sociedad['nombre'];

        $biblioteca = '';
        if ($tipo === 'sharepoint') {
            try {
                $biblioteca = AlmacenDocumentos::tipo('sharepoint')->probar();
            } catch (Throwable $e) {
                $this->redirectWithMessage($this->destino(),
                    'No se cambió nada: esta computadora no llega a SharePoint. ' . $e->getMessage(), 'error');
            }
        }

        try {
            $modelo->definirAlmacen((int) $id, $tipo);
        } catch (Throwable $e) {
            $this->redirectWithMessage($this->destino(), 'No se cambió nada: ' . $e->getMessage(), 'error');
        }

        if ($tipo !== 'sharepoint') {
            $this->redirectWithMessage($this->destino(),
                'Desde ahora, lo nuevo de ' . $nombre . ' se archiva en la carpeta compartida. '
                . 'Lo que ya estaba en SharePoint sigue ahí.', 'success');
        }

        // Lo que ya tenía no se mueve con el ajuste. Se dice cuánto y cómo se
        // sube, porque quien acaba de pasar la empresa va a ir a buscar sus
        // documentos a SharePoint y no los va a encontrar.
        $enDisco = $modelo->documentosEnDisco((int) $id);
        $mensaje = 'Desde ahora, lo nuevo de ' . $nombre . ' se archiva en SharePoint'
            . ($biblioteca !== '' ? ' ("' . $biblioteca . '")' : '') . '.';
        if ($enDisco > 0) {
            $mensaje .= ' Sus ' . number_format($enDisco, 0, ',', '.') . ' comprobantes anteriores siguen en la '
                . 'carpeta compartida y se abren desde ahí como siempre, pero NO están en SharePoint hasta '
                . 'migrarlos: php cli/migrar_documentos_a_graph.php --sociedad=' . (int) $id . ' --todo';
        }
        $this->redirectWithMessage($this->destino(), $mensaje, $enDisco > 0 ? 'warning' : 'success');
    }
}
