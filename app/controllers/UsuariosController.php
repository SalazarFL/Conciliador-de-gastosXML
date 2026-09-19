<?php
/**
 * Controlador de gestión de usuarios — solo quien administre usuarios.
 *
 * El listado vive en Configuración → Usuarios, junto al resto de lo que se
 * administra una vez y no todos los días. Aquí quedan los formularios de alta
 * y edición, que sí son pantallas propias, y todos vuelven allá al terminar.
 *
 * Cada alta, cada edición y cada baja deja su renglón en la bitácora con el
 * detalle de qué cambió —el rol, las empresas, si se activó o desactivó—,
 * porque el permiso de otra persona es justamente lo que un administrador
 * necesita poder auditar después.
 */

require_once __DIR__ . '/../models/Bitacora.php';

class UsuariosController extends Controller
{
    private Usuario $model;
    private Rol $roles;

    /** Listado y destino de todas las acciones. */
    private const DESTINO = '/configuracion?ir=usuarios';

    public function __construct()
    {
        $this->requireAuth();
        $this->requirePermiso('usuarios.administrar');
        require_once __DIR__ . '/../models/Usuario.php';
        $this->model = new Usuario();
        $this->roles = new Rol();
    }

    // ── Listado ───────────────────────────────────────────────────────────────

    /** Los enlaces viejos a /usuarios siguen llevando a donde está la lista. */
    public function index()
    {
        $this->redirect($this->url(self::DESTINO));
    }

    // ── Crear ─────────────────────────────────────────────────────────────────

    public function crear()
    {
        if ($this->isPost()) {
            $error = $this->procesarCrear();
            if ($error === null) {
                $this->redirectWithMessage($this->url(self::DESTINO), 'Usuario creado correctamente.', 'success');
            }
            // Volver al formulario con error
            $this->render('usuarios/crear', $this->datosDelFormulario([
                'title' => 'Nuevo Usuario — Nexo Fiscal',
                'error' => $error,
                'old'   => $_POST,
            ]));
            return;
        }

        $this->render('usuarios/crear', $this->datosDelFormulario([
            'title' => 'Nuevo Usuario — Nexo Fiscal',
            'error' => null,
            'old'   => [],
        ]));
    }

    // ── Editar ────────────────────────────────────────────────────────────────

    public function editar($id)
    {
        $id = (int) $id;
        $usuario = $this->model->findById($id);
        if (!$usuario) {
            $this->redirectWithMessage($this->url(self::DESTINO), 'Usuario no encontrado.', 'error');
        }

        if ($this->isPost()) {
            $error = $this->procesarEditar($id, $usuario);
            if ($error === null) {
                $this->redirectWithMessage($this->url(self::DESTINO), 'Usuario actualizado correctamente.', 'success');
            }
            $this->render('usuarios/editar', $this->datosDelFormulario([
                'title'      => 'Editar Usuario — Nexo Fiscal',
                'usuario'    => $usuario,
                'error'      => $error,
                'old'        => $_POST,
                'sociedadesDelUsuario' => $this->model->sociedadesDe($id),
            ]));
            return;
        }

        $this->render('usuarios/editar', $this->datosDelFormulario([
            'title'      => 'Editar Usuario — Nexo Fiscal',
            'usuario'    => $usuario,
            'error'      => null,
            'old'        => [],
            'sociedadesDelUsuario' => $this->model->sociedadesDe($id),
        ]));
    }

    // ── Eliminar ──────────────────────────────────────────────────────────────

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url(self::DESTINO));
        }

        $id = (int) $id;

        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            $this->redirectWithMessage($this->url(self::DESTINO), 'No puedes eliminar tu propia cuenta.', 'error');
        }

        $usuario = $this->model->findById($id);
        if (!$usuario) {
            $this->redirectWithMessage($this->url(self::DESTINO), 'Usuario no encontrado.', 'error');
        }

        // Quedarse sin ningún administrador activo deja la instalación
        // cerrada: solo se arregla entrando a la base de datos a mano.
        if ($this->esAdministrador($usuario) && $this->model->administradoresActivos($id) === 0) {
            $this->redirectWithMessage(
                $this->url(self::DESTINO),
                'No se puede eliminar: es el único administrador activo que queda.',
                'error'
            );
        }

        $this->model->deleteById($id);

        Bitacora::registrar([
            'accion'     => 'Eliminó un usuario',
            'modulo'     => 'administracion',
            'permiso'    => 'usuarios.administrar',
            'entidad'    => 'usuario',
            'entidad_id' => $id,
            'detalle'    => [
                'usuario'  => $usuario['nombre'] ?? '',
                'username' => $usuario['username'] ?? '',
                'cedula'   => $usuario['cedula'] ?? '',
            ],
        ]);

        $this->redirectWithMessage($this->url(self::DESTINO), 'Usuario eliminado.', 'success');
    }

    // ── Lógica privada ────────────────────────────────────────────────────────

    /** Lo que necesitan los dos formularios: los roles y las empresas. */
    private function datosDelFormulario(array $datos): array
    {
        $roles = [];
        $sociedades = [];

        try {
            $roles = $this->roles->paraElegir();
        } catch (Throwable $e) {
            $this->registrarFallo('cargar roles para el formulario de usuario', $e);
        }

        try {
            $sociedades = $this->loadModel('Sociedad')->getTodas();
        } catch (Throwable $e) {
            $this->registrarFallo('cargar empresas para el formulario de usuario', $e);
        }

        return $datos + [
            'roles'      => $roles,
            'sociedades' => $sociedades,
            'sociedadesDelUsuario' => [],
        ];
    }

    private function procesarCrear(): ?string
    {
        $nombre   = trim($_POST['nombre']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $cedula   = Usuario::soloDigitos($_POST['cedula'] ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $activo   = isset($_POST['activo'])   ? 1 : 0;
        $rolId    = (int) ($_POST['rol_id'] ?? 0);

        $empresas = $this->empresasDelFormulario($errorEmpresas);
        if ($errorEmpresas !== null) {
            return $errorEmpresas;
        }

        if ($nombre === '' || $username === '' || $cedula === '' || $password === '') {
            return 'Todos los campos son obligatorios.';
        }
        if (strlen($password) < 6) {
            return 'La contraseña debe tener al menos 6 caracteres.';
        }
        if ($password !== $confirm) {
            return 'Las contraseñas no coinciden.';
        }
        if ($this->model->usernameExists($username)) {
            return 'El nombre de usuario ya está en uso.';
        }
        if ($this->model->cedulaExists($cedula)) {
            return 'Esa cédula ya está registrada en otra cuenta.';
        }
        if ($rolId <= 0) {
            return 'Elige un rol: es lo que decide a qué puede entrar esta persona.';
        }

        // `is_admin` ya no se marca a mano: lo determina el rol. Así no puede
        // haber alguien con la casilla de administrador y un rol que no lo es,
        // que era una contradicción imposible de interpretar después.
        $esAdmin = $this->rolEsAdmin($rolId);

        $id = $this->model->create([
            'nombre'     => $nombre,
            'username'   => $username,
            'cedula'     => $cedula,
            'password'   => $password,
            'activo'     => $activo,
            'is_admin'   => $esAdmin ? 1 : 0,
            'rol_id'     => $rolId,
            'sociedades' => $empresas,
        ]);

        Bitacora::registrar([
            'accion'     => 'Creó un usuario',
            'modulo'     => 'administracion',
            'permiso'    => 'usuarios.administrar',
            'entidad'    => 'usuario',
            'entidad_id' => $id,
            'detalle'    => [
                'usuario'  => $nombre,
                'username' => $username,
                'cedula'   => $cedula,
                'rol'      => $this->nombreRol($rolId),
                'activo'   => $activo ? 'sí' : 'no',
                'empresas' => $empresas ? $this->nombresDeEmpresas($empresas) : 'todas',
            ],
        ]);

        return null;
    }

    private function procesarEditar(int $id, array $original): ?string
    {
        $nombre   = trim($_POST['nombre']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $cedula   = Usuario::soloDigitos($_POST['cedula'] ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $activo   = isset($_POST['activo'])   ? 1 : 0;
        $rolId    = (int) ($_POST['rol_id'] ?? 0);

        $empresas = $this->empresasDelFormulario($errorEmpresas);
        if ($errorEmpresas !== null) {
            return $errorEmpresas;
        }

        $esUnoMismo = $id === (int) ($_SESSION['user_id'] ?? 0);

        // Nadie se quita a sí mismo los permisos con los que está trabajando:
        // el resultado sería quedarse fuera de la pantalla en la que está.
        if ($esUnoMismo) {
            $rolId  = (int) ($original['rol_id'] ?? $rolId);
            $activo = 1;
        }

        if ($nombre === '' || $username === '' || $cedula === '') {
            return 'Nombre, usuario y cédula son obligatorios.';
        }
        if ($this->model->usernameExists($username, $id)) {
            return 'El nombre de usuario ya está en uso por otro usuario.';
        }
        if ($this->model->cedulaExists($cedula, $id)) {
            return 'Esa cédula ya está registrada en otra cuenta.';
        }
        if ($rolId <= 0) {
            return 'Elige un rol: es lo que decide a qué puede entrar esta persona.';
        }

        $esAdmin = $this->rolEsAdmin($rolId);

        // Dejar la instalación sin administradores activos la cierra para
        // todos, incluido quien hace el cambio.
        $dejaDeSerAdmin = $this->esAdministrador($original) && (!$esAdmin || !$activo);
        if ($dejaDeSerAdmin && $this->model->administradoresActivos($id) === 0) {
            return 'No se puede: es el único administrador activo que queda.';
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                return 'La nueva contraseña debe tener al menos 6 caracteres.';
            }
            if ($password !== $confirm) {
                return 'Las contraseñas no coinciden.';
            }
        }

        $empresasAntes = $this->model->sociedadesDe($id);

        $this->model->updateInfo($id, [
            'nombre'     => $nombre,
            'username'   => $username,
            'cedula'     => $cedula,
            'activo'     => $activo,
            'is_admin'   => $esAdmin ? 1 : 0,
            'rol_id'     => $rolId,
            'sociedades' => $empresas,
        ]);

        if ($password !== '') {
            $this->model->updatePassword($id, $password);
        }

        Bitacora::registrar([
            'accion'     => 'Editó un usuario',
            'modulo'     => 'administracion',
            'permiso'    => 'usuarios.administrar',
            'entidad'    => 'usuario',
            'entidad_id' => $id,
            'antes'      => [
                'nombre'   => $original['nombre'] ?? '',
                'username' => $original['username'] ?? '',
                'cedula'   => $original['cedula'] ?? '',
                'activo'   => ((int) ($original['activo'] ?? 0)) ? 'sí' : 'no',
                'rol'      => $this->nombreRol((int) ($original['rol_id'] ?? 0)),
                'empresas' => $empresasAntes ? $this->nombresDeEmpresas($empresasAntes) : 'todas',
            ],
            'despues'    => [
                'nombre'   => $nombre,
                'username' => $username,
                'cedula'   => $cedula,
                'activo'   => $activo ? 'sí' : 'no',
                'rol'      => $this->nombreRol($rolId),
                'empresas' => $empresas ? $this->nombresDeEmpresas($empresas) : 'todas',
            ],
            'detalle'    => $password !== ''
                ? ['nota' => 'También se le cambió la contraseña.']
                : [],
        ]);

        // Actualizar nombre en sesión si es el usuario actual
        if ($esUnoMismo) {
            $_SESSION['user_nombre']   = $nombre;
            $_SESSION['user_username'] = $username;
        }

        // El rol o las empresas pueden haber cambiado: lo resuelto en esta
        // petición ya no vale.
        Permisos::olvidar();

        return null;
    }

    /**
     * Con qué empresas trabaja esta persona, según el formulario.
     *
     * Lista vacía sigue significando "todas" para Permisos::
     * sociedadesPermitidas(), pero ya no se llega ahí por descuido: el
     * formulario obliga a elegir entre "todas" y "solo estas", y si dice
     * "solo estas" tiene que venir al menos una. Antes, no marcar la casilla
     * de limitar daba acceso a todo el grupo sin que nadie lo hubiera decidido.
     *
     * @param string|null $error Queda con el motivo cuando la elección falta.
     */
    private function empresasDelFormulario(?string &$error = null): array
    {
        $error = null;
        $alcance = (string) ($_POST['alcance_empresas'] ?? '');

        if ($alcance === 'todas') {
            return [];
        }
        if ($alcance !== 'algunas') {
            $error = 'Elige con qué empresas trabaja: todas las del grupo, o solo las que marques.';
            return [];
        }

        $ids = (array) ($_POST['sociedades'] ?? []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) {
            $error = 'Marca al menos una empresa, o elige "todas las empresas del grupo".';
        }
        return $ids;
    }

    private function rolEsAdmin(int $rolId): bool
    {
        if ($rolId <= 0) {
            return false;
        }
        $rol = $this->roles->findById($rolId);
        return !empty($rol['es_admin']);
    }

    /** Administrador por rol o por la marca vieja de la ficha. */
    private function esAdministrador(array $usuario): bool
    {
        if (!empty($usuario['is_admin'])) {
            return true;
        }
        return $this->rolEsAdmin((int) ($usuario['rol_id'] ?? 0));
    }

    private function nombreRol(int $rolId): string
    {
        if ($rolId <= 0) {
            return 'Sin rol';
        }
        $rol = $this->roles->findById($rolId);
        return $rol ? (string) $rol['nombre'] : 'Sin rol';
    }

    /** Los nombres de las empresas, para que la bitácora se lea sin ids. */
    private function nombresDeEmpresas(array $ids): string
    {
        try {
            $todas = $this->loadModel('Sociedad')->getTodas();
            $mapa = [];
            foreach ($todas as $s) {
                $mapa[(int) $s['id']] = (string) $s['nombre'];
            }
            $nombres = [];
            foreach ($ids as $id) {
                $nombres[] = $mapa[(int) $id] ?? ('#' . (int) $id);
            }
            return implode(', ', $nombres);
        } catch (Throwable $e) {
            return implode(', ', array_map('intval', $ids));
        }
    }
}
