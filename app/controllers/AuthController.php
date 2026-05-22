<?php
/**
 * Controlador de autenticación — login y logout
 */

class AuthController extends Controller
{
    public function loginForm()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!empty($_SESSION['user_id'])) {
            $this->redirect($this->url('/'));
        }

        $this->renderPartial('auth/login', [
            'basePath'  => rtrim(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', '/'),
            'csrfToken' => $this->generateCsrf(),
            'error'     => null,
        ]);
    }

    public function loginPost()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $basePath = rtrim(defined('APP_URL') ? APP_URL : '/xmlconcilia/public', '/');

        // CSRF
        $submitted = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
            $this->showLogin($basePath, 'Token de seguridad inválido. Recarga la página.');
            return;
        }
        unset($_SESSION['csrf_token']);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $this->showLogin($basePath, 'Ingresa tu usuario y contraseña.');
            return;
        }

        require_once __DIR__ . '/../models/Usuario.php';
        $usuarioModel = new Usuario();
        $user = $usuarioModel->findByUsername($username);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->showLogin($basePath, 'Usuario o contraseña incorrectos.');
            return;
        }

        if (!(bool)$user['activo']) {
            $this->showLogin($basePath, 'Tu cuenta está desactivada. Contacta al administrador.');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['user_nombre']   = $user['nombre'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_is_admin'] = (bool) ($user['is_admin'] ?? false);

        $usuarioModel->updateLastAccess($user['id']);

        $this->redirect($this->url('/'));
    }

    public function logout()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION = [];
        session_destroy();
        $this->redirect($this->url('/login'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function showLogin(string $basePath, string $error): void
    {
        $this->renderPartial('auth/login', [
            'basePath'  => $basePath,
            'csrfToken' => $this->generateCsrf(),
            'error'     => $error,
        ]);
    }

    private function generateCsrf(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
