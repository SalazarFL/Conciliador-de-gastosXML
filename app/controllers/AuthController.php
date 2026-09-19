<?php
/**
 * Controlador de autenticación — login y logout
 *
 * Entrar y salir también se registran en la bitácora, y los intentos fallidos
 * con ellos. Un registro de actividad que solo anota lo que salió bien no
 * sirve para lo que de verdad se le pide a un registro de accesos: notar que
 * alguien lleva veinte intentos con la contraseña de otro.
 */

require_once __DIR__ . '/../models/Bitacora.php';

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
            // Se anota el usuario TECLEADO, no el encontrado: si el nombre no
            // existe, saber qué se intentó es justo el dato que importa. La
            // contraseña no se guarda nunca, ni siquiera fallida.
            Bitacora::acceso(
                'Intento de entrada fallido',
                ['id' => (int) ($user['id'] ?? 0), 'nombre' => $username],
                'denegado',
                ['usuario_tecleado' => $username, 'motivo' => $user ? 'contraseña incorrecta' : 'el usuario no existe']
            );
            $this->showLogin($basePath, 'Usuario o contraseña incorrectos.');
            return;
        }

        if (!(bool)$user['activo']) {
            Bitacora::acceso(
                'Intento de entrada con cuenta desactivada',
                ['id' => (int) $user['id'], 'nombre' => $user['nombre']],
                'denegado',
                ['usuario_tecleado' => $username]
            );
            $this->showLogin($basePath, 'Tu cuenta está desactivada. Contacta al administrador.');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['user_nombre']   = $user['nombre'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_is_admin'] = (bool) ($user['is_admin'] ?? false);

        // El rol se resuelve de la base en cada petición (ver Permisos), no
        // se guarda en la sesión: quitarle un permiso a alguien tiene que
        // surtir efecto en su siguiente pantalla, no cuando vuelva a entrar.
        Permisos::olvidar();

        // La empresa recordada puede ya no estar entre las suyas —se la
        // quitaron mientras no estaba—. Se corrige al entrar, para que no
        // aterrice en un listado vacío sin explicación.
        $this->ajustarEmpresaPermitida();

        $usuarioModel->updateLastAccess($user['id']);

        Bitacora::acceso('Entró al sistema', [
            'id'     => (int) $user['id'],
            'nombre' => (string) $user['nombre'],
        ]);

        $this->redirect($this->url('/'));
    }

    public function logout()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!empty($_SESSION['user_id'])) {
            Bitacora::acceso('Salió del sistema', [
                'id'     => (int) $_SESSION['user_id'],
                'nombre' => (string) ($_SESSION['user_nombre'] ?? ''),
            ]);
        }

        $_SESSION = [];
        session_destroy();
        $this->redirect($this->url('/login'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Deja la sesión apuntando a una empresa que esta persona sí puede ver.
     * Si su preferencia ya no vale, se toma la primera permitida.
     */
    private function ajustarEmpresaPermitida(): void
    {
        try {
            $permitidas = Permisos::sociedadesPermitidas();
            if (!$permitidas) {
                return; // sin restricción: sirve cualquiera
            }

            require_once __DIR__ . '/../models/Sociedad.php';
            $sociedad = new Sociedad();
            $actual = (int) $sociedad->seleccionadaId();

            if (!in_array($actual, $permitidas, true)) {
                $sociedad->seleccionar($permitidas[0]);
            }
        } catch (Throwable $e) {
            // Entrar al sistema no puede depender de esto: si falla, se
            // resuelve la empresa como siempre.
            $this->registrarFallo('ajustar empresa al entrar', $e);
        }
    }

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
