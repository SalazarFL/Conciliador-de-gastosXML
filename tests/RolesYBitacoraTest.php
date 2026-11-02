<?php
/**
 * Los roles reparten, la bitácora anota.
 *
 * Lo que se comprueba aquí, en orden de lo que dolería que se rompiera:
 *
 *  1. Que la instalación se siembra sola con Administrador y Operación, y que
 *     Operación NO se queda con la administración. Si eso falla, subir esta
 *     función deja a toda la oficina con permiso para repartir permisos.
 *  2. Que un usuario hereda lo de su rol y solo eso.
 *  3. Que "sin empresas asignadas" significa TODAS y no ninguna: al revés,
 *     el día de la puesta en marcha nadie vería nada.
 *  4. Que la bitácora guarda quién y qué cambió —y que NO guarda contraseñas
 *     ni tokens, que es el descuido que convertiría la auditoría en el
 *     agujero.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Permisos.php';
require_once __DIR__ . '/../app/models/Rol.php';
require_once __DIR__ . '/../app/models/Bitacora.php';

$fallos = 0;

function assertRoles($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: RolesYBitacora (sin base de datos disponible)\n";
    exit(0);
}

// Los modelos leen $_SESSION; en CLI no existe hasta que se declara.
$_SESSION = [];

$marca = '__test_roles__';
$usuarioId = 0;
$rolTemporalId = 0;

$limpiar = function () use ($pdo, $marca, &$usuarioId, &$rolTemporalId) {
    if ($usuarioId > 0) {
        $pdo->prepare('DELETE FROM usuario_sociedades WHERE usuario_id = ?')->execute([$usuarioId]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = ? AND username = ?')->execute([$usuarioId, $marca]);
    }
    if ($rolTemporalId > 0) {
        $pdo->prepare('DELETE FROM rol_permisos WHERE rol_id = ?')->execute([$rolTemporalId]);
        $pdo->prepare('DELETE FROM roles WHERE id = ? AND nombre LIKE ?')->execute([$rolTemporalId, $marca . '%']);
    }
    $pdo->prepare('DELETE FROM bitacora WHERE accion LIKE ?')->execute([$marca . '%']);
};

try {
    // La marca de esquema dice "ya comprobado"; en una prueba hay que forzarlo.
    Esquema::olvidar();

    $rol = new Rol();

    // ── 1. Los roles base ────────────────────────────────────────────────
    $adminId = $rol->idPorNombre(Rol::ADMINISTRADOR);
    $operId  = $rol->idPorNombre(Rol::OPERACION);

    assertRoles($adminId > 0, 'la instalación se siembra con el rol Administrador');
    assertRoles($operId > 0, 'la instalación se siembra con el rol Operación');

    $delAdmin = $rol->findById($adminId);
    assertRoles(!empty($delAdmin['es_admin']), 'Administrador está marcado como "lo puede todo"');
    assertRoles(!empty($delAdmin['es_sistema']), 'Administrador no se puede eliminar');

    $permisosOper = $rol->permisosDe($operId);
    assertRoles(in_array('seguimiento.ver', $permisosOper, true),
        'Operación entra al trabajo del día');
    assertRoles(in_array('correo.importar', $permisosOper, true),
        'Operación importa comprobantes del correo');

    foreach (['usuarios.administrar', 'roles.administrar', 'bitacora.ver', 'bitacora.depurar',
              'empresas.administrar', 'respaldo.administrar', 'correo.configurar'] as $vedado) {
        assertRoles(!in_array($vedado, $permisosOper, true),
            "Operación NO tiene '{$vedado}': la administración no se reparte sola");
    }

    assertRoles($rol->permisosDe($adminId) === [],
        'al rol administrador no se le guardan permisos sueltos: los tiene todos por definición');

    // ── 2. Lo que hereda un usuario ──────────────────────────────────────
    $pdo->prepare('DELETE FROM usuarios WHERE username = ?')->execute([$marca]);
    $pdo->prepare(
        'INSERT INTO usuarios (nombre, username, cedula, password_hash, activo, is_admin, rol_id)
         VALUES (?, ?, ?, ?, 1, 0, ?)'
    )->execute(['Prueba de roles', $marca, '900000001',
                password_hash('irrelevante', PASSWORD_BCRYPT), $operId]);
    $usuarioId = (int) $pdo->lastInsertId();

    $heredados = $rol->permisosDeUsuario($usuarioId);
    assertRoles(in_array('seguimiento.ver', $heredados, true),
        'el usuario hereda los permisos de su rol');
    assertRoles(!in_array('roles.administrar', $heredados, true),
        'y no hereda ninguno que su rol no tenga');

    $suRol = $rol->rolDeUsuario($usuarioId);
    assertRoles(($suRol['nombre'] ?? '') === Rol::OPERACION, 'se resuelve el rol del usuario');
    assertRoles(empty($suRol['es_admin']), 'y no se le toma por administrador');

    // ── 3. El alcance por empresa ────────────────────────────────────────
    assertRoles($rol->sociedadesDeUsuario($usuarioId) === [],
        'sin filas registradas, el usuario llega a TODAS las empresas');

    $sociedades = $pdo->query('SELECT id FROM sociedades ORDER BY id LIMIT 1')->fetchAll(PDO::FETCH_COLUMN);
    if ($sociedades) {
        $rol->fijarSociedades($usuarioId, [(int) $sociedades[0]]);
        assertRoles($rol->sociedadesDeUsuario($usuarioId) === [(int) $sociedades[0]],
            'se guarda la empresa a la que se le limita');

        $rol->fijarSociedades($usuarioId, []);
        assertRoles($rol->sociedadesDeUsuario($usuarioId) === [],
            'y una lista vacía le devuelve el acceso a todas, no se lo quita');
    }

    // ── 4. Un rol nuevo, con permisos elegidos ───────────────────────────
    $rolTemporalId = $rol->crear($marca . ' Contabilidad', 'Rol de prueba',
        ['por_pagar.ver', 'por_pagar.exportar', 'permiso.inventado']);

    $delNuevo = $rol->permisosDe($rolTemporalId);
    assertRoles(in_array('por_pagar.ver', $delNuevo, true), 'el rol nuevo guarda lo que se le marcó');
    assertRoles(!in_array('permiso.inventado', $delNuevo, true),
        'y descarta claves que no están en el catálogo: un permiso inventado no protegería nada');

    // ── 5. La bitácora ───────────────────────────────────────────────────
    $_SESSION['user_id'] = $usuarioId;
    $_SESSION['user_nombre'] = 'Prueba de roles';

    Bitacora::registrar([
        'accion'     => $marca . ' editó algo',
        'modulo'     => 'administracion',
        'entidad'    => 'usuario',
        'entidad_id' => $usuarioId,
        'antes'      => ['rol' => 'Operación', 'activo' => 'sí'],
        'despues'    => ['rol' => 'Contabilidad', 'activo' => 'sí'],
    ]);

    $bitacora = new Bitacora();
    $lista = $bitacora->listar(['texto' => $marca, 'desde' => date('Y-m-d')]);
    assertRoles($lista['total'] >= 1, 'el movimiento queda registrado y se puede buscar');

    $fila = $lista['filas'][0] ?? [];
    assertRoles(($fila['usuario_nombre'] ?? '') === 'Prueba de roles',
        'la bitácora guarda el nombre de quien lo hizo, no solo su id');

    $detalle = json_decode((string) ($fila['detalle'] ?? ''), true);
    assertRoles(isset($detalle['cambios']['rol']),
        'guarda el campo que cambió, con su valor anterior y el nuevo');
    assertRoles(!isset($detalle['cambios']['activo']),
        'y no repite los campos que quedaron igual: si no, no se ve qué cambió');

    // Lo que NUNCA debe entrar, ni por el camino automático del enrutador.
    $_POST = ['nombre' => 'Ana', 'password' => 'secreto', 'password_confirm' => 'secreto',
              'csrf_token' => 'abc123', 'motivo' => 'corrección'];
    $capturar = new ReflectionMethod('Bitacora', 'datosDeLaPeticion');
    $capturar->setAccessible(true);
    $guardado = $capturar->invoke(null, ['id' => '42']);
    $_POST = [];

    assertRoles(!isset($guardado['password']), 'la contraseña NO se guarda en la bitácora');
    assertRoles(!isset($guardado['password_confirm']), 'ni su confirmación');
    assertRoles(!isset($guardado['csrf_token']), 'ni el token de seguridad');
    assertRoles(($guardado['nombre'] ?? '') === 'Ana', 'el resto del formulario sí se guarda');
    assertRoles(($guardado['id'] ?? '') === '42', 'y los parámetros de la ruta también');

    // ── 6. El historial anterior de cada módulo ──────────────────────────
    $fuentes = $bitacora->fuentesDisponibles(true);
    assertRoles(isset($fuentes['bitacora']), 'la bitácora propia siempre participa en la línea de tiempo');

    $unido = $bitacora->listar([
        'historial' => true,
        'desde'     => date('Y-m-d', strtotime('-365 days')),
    ]);
    assertRoles(is_array($unido['filas']),
        'la consulta que une la bitácora con el historial de los módulos corre sin errores');

} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: excepción inesperada — " . $e->getMessage() . "\n");
    $fallos++;
} finally {
    $limpiar();
}

if ($fallos > 0) {
    fwrite(STDERR, "RolesYBitacora: {$fallos} fallas\n");
    exit(1);
}

echo "OK: RolesYBitacora\n";
exit(0);
