<?php
/**
 * Controlador de gestión de usuarios — solo administrador
 */

class UsuariosController extends Controller
{
    private Usuario $model;

    public function __construct()
    {
        $this->requireAdmin();
        require_once __DIR__ . '/../models/Usuario.php';
        $this->model = new Usuario();
    }

    // ── Listado ───────────────────────────────────────────────────────────────

    public function index()
    {
        $usuarios = $this->model->getAll();
        $this->render('usuarios/index', [
            'title'    => 'Usuarios — XMLConcilia',
            'usuarios' => $usuarios,
        ]);
    }

    // ── Crear ─────────────────────────────────────────────────────────────────

    public function crear()
    {
        if ($this->isPost()) {
            $error = $this->procesarCrear();
            if ($error === null) {
                $this->redirectWithMessage($this->url('/usuarios'), 'Usuario creado correctamente.', 'success');
            }
            // Volver al formulario con error
            $this->render('usuarios/crear', [
                'title' => 'Nuevo Usuario — XMLConcilia',
                'error' => $error,
                'old'   => $_POST,
            ]);
            return;
        }

        $this->render('usuarios/crear', [
            'title' => 'Nuevo Usuario — XMLConcilia',
            'error' => null,
            'old'   => [],
        ]);
    }

    // ── Editar ────────────────────────────────────────────────────────────────

    public function editar($id)
    {
        $id = (int) $id;
        $usuario = $this->model->findById($id);
        if (!$usuario) {
            $this->redirectWithMessage($this->url('/usuarios'), 'Usuario no encontrado.', 'error');
        }

        if ($this->isPost()) {
            $error = $this->procesarEditar($id, $usuario);
            if ($error === null) {
                $this->redirectWithMessage($this->url('/usuarios'), 'Usuario actualizado correctamente.', 'success');
            }
            $this->render('usuarios/editar', [
                'title'   => 'Editar Usuario — XMLConcilia',
                'usuario' => $usuario,
                'error'   => $error,
                'old'     => $_POST,
            ]);
            return;
        }

        $this->render('usuarios/editar', [
            'title'   => 'Editar Usuario — XMLConcilia',
            'usuario' => $usuario,
            'error'   => null,
            'old'     => [],
        ]);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────────

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url('/usuarios'));
        }

        $id = (int) $id;

        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            $this->redirectWithMessage($this->url('/usuarios'), 'No puedes eliminar tu propia cuenta.', 'error');
        }

        $this->model->deleteById($id);
        $this->redirectWithMessage($this->url('/usuarios'), 'Usuario eliminado.', 'success');
    }

    // ── Lógica privada ────────────────────────────────────────────────────────

    private function procesarCrear(): ?string
    {
        $nombre   = trim($_POST['nombre']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $activo   = isset($_POST['activo'])   ? 1 : 0;
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;

        if ($nombre === '' || $username === '' || $email === '' || $password === '') {
            return 'Todos los campos son obligatorios.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'El correo electrónico no es válido.';
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
        if ($this->model->emailExists($email)) {
            return 'El correo electrónico ya está registrado.';
        }

        $this->model->create([
            'nombre'   => $nombre,
            'username' => $username,
            'email'    => $email,
            'password' => $password,
            'activo'   => $activo,
            'is_admin' => $is_admin,
        ]);

        return null;
    }

    private function procesarEditar(int $id, array $original): ?string
    {
        $nombre   = trim($_POST['nombre']   ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $activo   = isset($_POST['activo'])   ? 1 : 0;
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;

        // No permitir quitarle el admin al propio usuario
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            $is_admin = 1;
            $activo   = 1;
        }

        if ($nombre === '' || $username === '' || $email === '') {
            return 'Nombre, usuario y correo son obligatorios.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'El correo electrónico no es válido.';
        }
        if ($this->model->usernameExists($username, $id)) {
            return 'El nombre de usuario ya está en uso por otro usuario.';
        }
        if ($this->model->emailExists($email, $id)) {
            return 'El correo electrónico ya está registrado en otro usuario.';
        }

        $this->model->updateInfo($id, [
            'nombre'   => $nombre,
            'username' => $username,
            'email'    => $email,
            'activo'   => $activo,
            'is_admin' => $is_admin,
        ]);

        if ($password !== '') {
            if (strlen($password) < 6) {
                return 'La nueva contraseña debe tener al menos 6 caracteres.';
            }
            if ($password !== $confirm) {
                return 'Las contraseñas no coinciden.';
            }
            $this->model->updatePassword($id, $password);
        }

        // Actualizar nombre en sesión si es el usuario actual
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            $_SESSION['user_nombre']   = $nombre;
            $_SESSION['user_username'] = $username;
        }

        return null;
    }
}
