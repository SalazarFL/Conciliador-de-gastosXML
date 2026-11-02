<?php
/**
 * Los roles: crearlos y decidir qué puede hacer cada uno.
 *
 * El listado vive en Configuración → Roles, junto a Usuarios, porque son la
 * misma conversación: quién entra y qué puede tocar. El formulario es pantalla
 * propia porque es una matriz de casillas y no cabe en una tarjeta.
 *
 * Hay dos roles que no se pueden borrar (`es_sistema`): Administrador, porque
 * sin él nadie podría volver a entrar a arreglar nada, y Operación, porque es
 * el rol al que caen los usuarios nuevos. Al de administrador tampoco se le
 * editan los permisos: los tiene todos por definición, y poder desmarcarle
 * uno sería poder dejar la instalación a medio cerrar.
 */

require_once __DIR__ . '/../models/Rol.php';
require_once __DIR__ . '/../models/Bitacora.php';

class RolesController extends Controller
{
    private Rol $model;

    private const DESTINO = '/configuracion?ir=roles';

    public function __construct()
    {
        $this->requireAuth();
        $this->requirePermiso('roles.administrar');
        $this->model = new Rol();
    }

    /** Los enlaces a /roles llevan a donde está la lista. */
    public function index()
    {
        $this->redirect($this->url(self::DESTINO));
    }

    // ── Crear ─────────────────────────────────────────────────────────────

    public function crear()
    {
        if ($this->isPost()) {
            $error = $this->procesarCrear();
            if ($error === null) {
                $this->redirectWithMessage($this->url(self::DESTINO), 'Rol creado.', 'success');
            }
            $this->pintarFormulario(null, $error, $_POST);
            return;
        }

        $this->pintarFormulario(null, null, []);
    }

    // ── Editar ────────────────────────────────────────────────────────────

    public function editar($id)
    {
        $id  = (int) $id;
        $rol = $this->model->findById($id);

        if (!$rol) {
            $this->redirectWithMessage($this->url(self::DESTINO), 'Ese rol no existe.', 'error');
        }

        if ($this->isPost()) {
            $error = $this->procesarEditar($id, $rol);
            if ($error === null) {
                $this->redirectWithMessage($this->url(self::DESTINO), 'Rol actualizado.', 'success');
            }
            $this->pintarFormulario($rol, $error, $_POST);
            return;
        }

        $this->pintarFormulario($rol, null, []);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────

    public function eliminar($id)
    {
        if (!$this->isPost()) {
            $this->redirect($this->url(self::DESTINO));
        }

        $id  = (int) $id;
        $rol = $this->model->findById($id);

        if (!$rol) {
            $this->redirectWithMessage($this->url(self::DESTINO), 'Ese rol no existe.', 'error');
        }

        if (!empty($rol['es_sistema'])) {
            $this->redirectWithMessage(
                $this->url(self::DESTINO),
                'El rol "' . $rol['nombre'] . '" es parte del sistema y no se puede eliminar.',
                'error'
            );
        }

        // Borrar un rol con gente dentro los deja sin permisos de golpe, y
        // sin nada en pantalla que explique por qué dejaron de poder trabajar.
        $enUso = $this->model->usuariosDe($id);
        if ($enUso > 0) {
            $this->redirectWithMessage(
                $this->url(self::DESTINO),
                'No se puede eliminar: ' . $enUso . ' usuario' . ($enUso === 1 ? '' : 's')
                    . ' todavía tiene' . ($enUso === 1 ? '' : 'n') . ' este rol. Cámbiaselo primero.',
                'error'
            );
        }

        // Las comprobaciones de arriba deberían hacer que esto no lance nunca,
        // pero el modelo también comprueba por su cuenta —es el que borra— y
        // un 500 no explica nada en una pantalla de administración.
        try {
            $this->model->eliminar($id);
        } catch (Throwable $e) {
            $this->redirectWithMessage(
                $this->url(self::DESTINO),
                'No se pudo eliminar: ' . $e->getMessage(),
                'error'
            );
        }

        Bitacora::registrar([
            'accion'     => 'Eliminó un rol',
            'modulo'     => 'administracion',
            'permiso'    => 'roles.administrar',
            'entidad'    => 'rol',
            'entidad_id' => $id,
            'detalle'    => ['rol' => $rol['nombre']],
        ]);

        Permisos::olvidar();

        $this->redirectWithMessage($this->url(self::DESTINO), 'Rol eliminado.', 'success');
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function pintarFormulario(?array $rol, ?string $error, array $old): void
    {
        $permisosActuales = [];
        if ($rol) {
            $permisosActuales = $this->model->permisosDe((int) $rol['id']);
        }

        // Al volver de un error mandan las casillas que la persona dejó
        // marcadas, no las guardadas: reponer las de la base le borraría el
        // trabajo por una validación de nombre.
        if ($old) {
            $permisosActuales = array_map('strval', (array) ($old['permisos'] ?? []));
        }

        $this->render('roles/form', [
            'title'            => $rol ? 'Editar rol — Nexo Fiscal' : 'Nuevo rol — Nexo Fiscal',
            'rol'              => $rol,
            'error'            => $error,
            'old'              => $old,
            'modulos'          => Permisos::modulos(),
            'permisosActuales' => $permisosActuales,
            'usuariosConEsteRol' => $rol ? $this->model->usuariosDe((int) $rol['id']) : 0,
            'quienes'          => $rol ? ($this->model->usuariosPorRol()[(int) $rol['id']] ?? []) : [],
        ]);
    }

    private function procesarCrear(): ?string
    {
        $nombre      = trim((string) $this->post('nombre', ''));
        $descripcion = trim((string) $this->post('descripcion', ''));
        $permisos    = Permisos::conBases((array) $this->post('permisos', []));

        if ($error = $this->validar($nombre, $permisos, 0)) {
            return $error;
        }

        $id = $this->model->crear($nombre, $descripcion, $permisos);

        Bitacora::registrar([
            'accion'     => 'Creó un rol',
            'modulo'     => 'administracion',
            'permiso'    => 'roles.administrar',
            'entidad'    => 'rol',
            'entidad_id' => $id,
            'detalle'    => [
                'rol'      => $nombre,
                'permisos' => $this->comoTexto($this->model->permisosDe($id)),
            ],
        ]);

        Permisos::olvidar();
        return null;
    }

    private function procesarEditar(int $id, array $rol): ?string
    {
        $nombre      = trim((string) $this->post('nombre', ''));
        $descripcion = trim((string) $this->post('descripcion', ''));
        // Lo marcado sin la base de su módulo ("verificar un pago" sin "ver
        // los pagos") no llega a ninguna pantalla: se completa, como hace la
        // pantalla al marcar la casilla. Ver 'base' en config/permisos.php.
        $permisos    = Permisos::conBases((array) $this->post('permisos', []));

        // El rol de administrador conserva su nombre: es el que busca
        // `sembrarRolesBase` para saber que ya existe, y renombrarlo haría que
        // la instalación se creara otro al día siguiente.
        if (!empty($rol['es_admin'])) {
            $nombre = (string) $rol['nombre'];
        }

        if ($error = $this->validar($nombre, $permisos, $id, !empty($rol['es_admin']))) {
            return $error;
        }

        $antes = $this->model->permisosDe($id);

        $this->model->actualizar($id, $nombre, $descripcion, $permisos);

        $despues = $this->model->permisosDe($id);

        Bitacora::registrar([
            'accion'     => 'Cambió los permisos de un rol',
            'modulo'     => 'administracion',
            'permiso'    => 'roles.administrar',
            'entidad'    => 'rol',
            'entidad_id' => $id,
            'detalle'    => [
                'rol'        => $nombre,
                'concedidos' => $this->comoTexto(array_diff($despues, $antes)) ?: '—',
                'retirados'  => $this->comoTexto(array_diff($antes, $despues)) ?: '—',
                'usuarios_afectados' => $this->model->usuariosDe($id),
            ],
        ]);

        // Quien tenga este rol tiene que notar el cambio en su siguiente
        // pantalla, no en su siguiente sesión.
        Permisos::olvidar();
        return null;
    }

    private function validar(string $nombre, array $permisos, int $id, bool $esAdmin = false): ?string
    {
        if ($nombre === '') {
            return 'El rol necesita un nombre.';
        }
        if (mb_strlen($nombre, 'UTF-8') > 60) {
            return 'El nombre del rol es demasiado largo (máximo 60 caracteres).';
        }
        if ($this->model->nombreExiste($nombre, $id)) {
            return 'Ya hay un rol con ese nombre.';
        }
        // Un rol sin ningún permiso no da acceso a nada y se ve igual que uno
        // bien puesto: es la forma más fácil de dejar a alguien encerrado sin
        // darse cuenta.
        if (!$esAdmin && !$permisos) {
            return 'Marca al menos un permiso: un rol sin permisos deja a esa persona sin poder entrar a nada.';
        }
        return null;
    }

    /** Los permisos en castellano, para que la bitácora se lea. */
    private function comoTexto(iterable $permisos): string
    {
        $textos = [];
        foreach ($permisos as $permiso) {
            $textos[] = Permisos::etiqueta((string) $permiso);
        }
        return implode('; ', $textos);
    }
}
