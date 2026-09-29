<?php
/**
 * Un código que acepta también los comprobantes de otra cédula.
 *
 * El caso real: BANCO BAC SAN JOSE y BAC SAN JOSE LEASING facturan contra el
 * mismo código del sistema. Pasarle el código a la segunda se lo quitaba a la
 * primera, y sus facturas dejaban de aceptar sus propios comprobantes. Lo que
 * hacía falta era que el código aceptara a los dos, decidido por una persona
 * para ese código —no que dos cédulas compartan código porque sí—.
 *
 * Lo que se vigila:
 *   - el dueño no cambia y sigue siendo aceptado
 *   - la cédula agregada pasa a ser aceptada, solo en ESE código
 *   - una cédula aceptada ya no sale en "sin código"
 *   - el listado del ERP no desmiente lo que una persona agregó
 *   - se puede quitar, y borrar el código se lo lleva
 *
 * Usa códigos y cédulas sintéticos (99xxxxxxx) y borra lo suyo al terminar.
 *
 * Uso: php tests/ProveedorCodigoTambienTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/models/Proveedor.php';

function assertTambien($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: ProveedorCodigoTambien (sin base de datos disponible)\n";
    exit(0);
}

$COD_BANCO = '990000071';        // el código de los dos
$COD_OTRO = '990000072';         // otro código cualquiera, del banco también
$CED_BANCO = '9990000071';       // el dueño
$CED_LEASING = '9990000072';     // la que se acepta además

$limpiar = function () use ($pdo, $COD_BANCO, $COD_OTRO, $CED_BANCO, $CED_LEASING) {
    // La tabla nace con el modelo: en una base recién puesta todavía no está.
    new ProveedorCodigoErp();
    $pdo->prepare('DELETE FROM proveedor_codigo_tambien WHERE proveedor_codigo IN (?,?)')
        ->execute([$COD_BANCO, $COD_OTRO]);
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?,?)')
        ->execute([$COD_BANCO, $COD_OTRO]);
    $pdo->prepare('DELETE FROM proveedor_cedula_ignorada WHERE cedula IN (?,?)')
        ->execute([$CED_BANCO, $CED_LEASING]);
    $pdo->prepare('DELETE FROM proveedores WHERE rfc IN (?,?)')->execute([$CED_BANCO, $CED_LEASING]);
    ProveedorCodigoErp::olvidarMapa();
};
$limpiar();

try {
    $mapa = new ProveedorCodigoErp();
    $proveedores = new Proveedor();

    // El banco es dueño del código, como lo trae el listado del ERP.
    $mapa->importarDesdeErp([['codigo' => $COD_BANCO, 'cedula' => $CED_BANCO, 'nombre' => 'BANCO PRUEBA']]);
    $idBanco = (int) $proveedores->obtenerOCrear($CED_BANCO, 'BANCO PRUEBA');
    $idLeasing = (int) $proveedores->obtenerOCrear($CED_LEASING, 'BANCO PRUEBA LEASING');
    ProveedorCodigoErp::olvidarMapa();

    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idLeasing) === 'ajeno',
        'antes de decidir nada, el código no acepta al leasing');

    // ── Aceptarlo también ─────────────────────────────────────────────
    $r = $mapa->aceptarTambien($COD_BANCO, $CED_LEASING, 'BANCO PRUEBA LEASING', 0);
    assertTambien(!empty($r['ok']), 'aceptarTambien guarda: ' . ($r['message'] ?? ''));

    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idLeasing) === 'propio',
        'el código acepta ahora los comprobantes del leasing');
    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idBanco) === 'propio',
        'y sigue aceptando los de su dueño: no se le quitó nada');
    $fila = $mapa->porCodigo($COD_BANCO);
    assertTambien($fila && $fila['cedula'] === $CED_BANCO, 'el dueño del código no cambió');
    assertTambien(ProveedorCodigoErp::conoceCedula($CED_LEASING),
        'la cédula aceptada cuenta como conocida: sus comprobantes ya no preguntan');

    $sinCodigo = array_column($mapa->comprobantesSinCodigo(1000), 'cedula');
    assertTambien(!in_array($CED_LEASING, $sinCodigo, true),
        'una cédula aceptada ya no aparece entre los comprobantes sin código');

    $listado = $mapa->listar($COD_BANCO);
    $tambien = $listado['filas'][0]['tambien'] ?? [];
    assertTambien(count($tambien) === 1 && $tambien[0]['cedula'] === $CED_LEASING,
        'el catálogo enseña en la fila del código la cédula que acepta además');

    // ── Solo en ESE código ────────────────────────────────────────────
    $mapa->importarDesdeErp([['codigo' => $COD_OTRO, 'cedula' => $CED_BANCO, 'nombre' => 'BANCO PRUEBA 2']]);
    ProveedorCodigoErp::olvidarMapa();
    assertTambien(ProveedorCodigoErp::veredicto($COD_OTRO, $idLeasing) === 'ajeno',
        'otro código del mismo banco NO acepta al leasing: la decisión fue para uno');
    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idBanco) === 'compartido',
        'con dos códigos del banco, su comprobante ya no dice de cuál es: lo elige una persona');
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute([$COD_OTRO]);
    ProveedorCodigoErp::olvidarMapa();

    // ── El listado del ERP no lo desmiente ────────────────────────────
    $mapa->importarDesdeErp([['codigo' => $COD_BANCO, 'cedula' => $CED_BANCO, 'nombre' => 'BANCO PRUEBA']]);
    ProveedorCodigoErp::olvidarMapa();
    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idLeasing) === 'propio',
        'volver a importar el listado no le quita al código lo que una persona le agregó');

    // ── Casos que no tienen sentido ───────────────────────────────────
    $r = $mapa->aceptarTambien('990000079', $CED_LEASING, '', 0);
    assertTambien(empty($r['ok']), 'un código sin dueño no se comparte: se le da a quien corresponda');
    $r = $mapa->aceptarTambien($COD_BANCO, $CED_BANCO, '', 0);
    assertTambien(!empty($r['ok']) && ProveedorCodigoErp::veredicto($COD_BANCO, $idBanco) === 'propio',
        'aceptar al propio dueño no hace nada raro');
    $cuantas = (int) $pdo->query("SELECT COUNT(*) FROM proveedor_codigo_tambien WHERE proveedor_codigo = '{$COD_BANCO}'")
        ->fetchColumn();
    assertTambien($cuantas === 1, 'el dueño no queda anotado como "también"');

    // ── Quitarlo ──────────────────────────────────────────────────────
    $r = $mapa->dejarDeAceptar($COD_BANCO, $CED_LEASING);
    assertTambien(!empty($r['ok']), 'dejarDeAceptar quita la cédula');
    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idLeasing) === 'ajeno',
        'y el código vuelve a rechazar los comprobantes del leasing');
    assertTambien(ProveedorCodigoErp::veredicto($COD_BANCO, $idBanco) === 'propio',
        'sin tocar a su dueño');

    // ── Borrar el código se lleva lo que aceptaba ─────────────────────
    $mapa->aceptarTambien($COD_BANCO, $CED_LEASING, '', 0);
    $mapa->eliminarCodigo($COD_BANCO);
    $quedan = (int) $pdo->query("SELECT COUNT(*) FROM proveedor_codigo_tambien WHERE proveedor_codigo = '{$COD_BANCO}'")
        ->fetchColumn();
    assertTambien($quedan === 0, 'borrar un código borra también las cédulas que aceptaba');
} finally {
    $limpiar();
}

echo "OK: un código puede aceptar también otra cédula\n";
