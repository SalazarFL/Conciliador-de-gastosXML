<?php
/**
 * El catálogo de proveedores es por empresa.
 *
 * Cada empresa importa el listado de proveedores de SU ERP, y el mismo código
 * puede ser un proveedor en una y otro distinto en la otra. Con un catálogo
 * único para el grupo, importar el listado de la segunda empresa le cambiaba
 * la cédula a los códigos de la primera, y sus facturas pasaban a aceptar los
 * comprobantes del proveedor equivocado.
 *
 * Lo que se vigila:
 *   - el mismo código vale una cédula en cada empresa, sin pisarse
 *   - la guarda, el portón y la pregunta por cédula miran solo la empresa
 *   - asignar a mano, aceptar otra cédula e ignorar una cédula no cruzan
 *   - sin empresa no se escribe nada
 *
 * Crea dos empresas de prueba, usa códigos y cédulas sintéticos (99xxxxxxx) y
 * borra todo lo suyo al terminar.
 *
 * Uso: php tests/ProveedorCodigoPorEmpresaTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/models/Proveedor.php';

function assertPorEmpresa($condicion, $mensaje)
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
    echo "SKIP: ProveedorCodigoPorEmpresa (sin base de datos disponible)\n";
    exit(0);
}

$COD = '990000081';          // el mismo código en las dos empresas
$CED_A = '9990000081';       // su dueño en la empresa A
$CED_B = '9990000082';       // su dueño en la empresa B
$CED_SUELTA = '9990000083';  // una cédula que A decide no procesar
$marca = substr(md5(uniqid('', true)), 0, 6);

$empresas = [];
$limpiar = function () use ($pdo, &$empresas, $CED_A, $CED_B, $CED_SUELTA) {
    new ProveedorCodigoErp();
    if ($empresas) {
        $marcas = implode(',', array_map('intval', $empresas));
        foreach (['proveedor_codigo_tambien', 'proveedor_codigo_erp', 'proveedor_cedula_ignorada'] as $tabla) {
            $pdo->exec("DELETE FROM {$tabla} WHERE sociedad_id IN ({$marcas})");
        }
        $pdo->exec("DELETE FROM sociedades WHERE id IN ({$marcas})");
    }
    $pdo->prepare('DELETE FROM proveedores WHERE rfc IN (?,?,?)')->execute([$CED_A, $CED_B, $CED_SUELTA]);
    ProveedorCodigoErp::olvidarMapa();
};

try {
    // El modelo primero: es el que pone las tablas al día.
    new ProveedorCodigoErp();

    $alta = $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)');
    foreach (['A', 'B', 'SIN LISTA'] as $k => $letra) {
        $alta->execute(['EMPRESA ' . $letra . ' ' . $marca, '39990' . $k . random_int(10000, 99999)]);
        $empresas[$letra] = (int) $pdo->lastInsertId();
    }
    [$A, $B, $SIN] = [$empresas['A'], $empresas['B'], $empresas['SIN LISTA']];

    $enA = (new ProveedorCodigoErp())->setSociedad($A);
    $enB = (new ProveedorCodigoErp())->setSociedad($B);

    // ── Cada empresa importa su listado ────────────────────────────────
    $enA->importarDesdeErp([['codigo' => $COD, 'cedula' => $CED_A, 'nombre' => 'PROVEEDOR DE A']]);
    $planB = $enB->importarDesdeErp([['codigo' => $COD, 'cedula' => $CED_B, 'nombre' => 'PROVEEDOR DE B']], true);
    assertPorEmpresa($planB['nuevos'] === 1 && $planB['corregidos'] === 0,
        'para B el código es nuevo: que A lo tenga no es una cédula que corregir');
    $enB->importarDesdeErp([['codigo' => $COD, 'cedula' => $CED_B, 'nombre' => 'PROVEEDOR DE B']]);

    $filas = $pdo->prepare('SELECT sociedad_id, cedula FROM proveedor_codigo_erp WHERE proveedor_codigo = ? ORDER BY sociedad_id');
    $filas->execute([$COD]);
    $porEmpresa = array_column($filas->fetchAll(), 'cedula', 'sociedad_id');
    assertPorEmpresa(($porEmpresa[$A] ?? '') === $CED_A, 'en A el código sigue siendo del proveedor de A');
    assertPorEmpresa(($porEmpresa[$B] ?? '') === $CED_B, 'y en B es del proveedor de B');

    // ── La guarda pregunta en el catálogo de la empresa de la factura ──
    $proveedores = new Proveedor();
    $idA = (int) $proveedores->obtenerOCrear($CED_A, 'PROVEEDOR DE A');
    $idB = (int) $proveedores->obtenerOCrear($CED_B, 'PROVEEDOR DE B');
    ProveedorCodigoErp::olvidarMapa();

    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idA, $A) === 'propio',
        'una factura de A acepta el comprobante de su proveedor');
    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idB, $A) === 'ajeno',
        'y no el del proveedor que tiene ese código en B');
    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idB, $B) === 'propio',
        'una factura de B acepta el de su proveedor');
    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idA, $B) === 'ajeno',
        'y no el de A');

    // ── El portón y la pregunta por cédula ─────────────────────────────
    assertPorEmpresa(ProveedorCodigoErp::hayListado($A) && ProveedorCodigoErp::hayListado($B),
        'A y B tienen su listado');
    assertPorEmpresa(!ProveedorCodigoErp::hayListado($SIN),
        'una empresa sin listado propio no se sirve del de las otras');
    assertPorEmpresa(ProveedorCodigoErp::conoceCedula($CED_A, $A),
        'A conoce a su proveedor');
    assertPorEmpresa(!ProveedorCodigoErp::conoceCedula($CED_A, $B),
        'B no: un comprobante de ese emisor en B pregunta por su código');

    // ── Lo que se decide a mano queda en su empresa ────────────────────
    $r = $enA->guardarCodigo($COD, $CED_SUELTA, 'CORREGIDO EN A');
    assertPorEmpresa(!empty($r['ok']), 'A corrige a mano su código');
    $filas->execute([$COD]);
    $porEmpresa = array_column($filas->fetchAll(), 'cedula', 'sociedad_id');
    assertPorEmpresa(($porEmpresa[$B] ?? '') === $CED_B, 'y el código de B no se entera');
    // Se vuelve a como estaba, para lo que sigue.
    $enA->guardarCodigo($COD, $CED_A, 'PROVEEDOR DE A');

    $r = $enB->aceptarTambien($COD, $CED_A, 'PROVEEDOR DE A');
    assertPorEmpresa(!empty($r['ok']), 'B acepta además al proveedor de A en su código');
    ProveedorCodigoErp::olvidarMapa();
    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idA, $B) === 'propio',
        'en B ese comprobante ahora se acepta');
    assertPorEmpresa(ProveedorCodigoErp::veredicto($COD, $idB, $A) === 'ajeno',
        'y en A no cambia nada');

    $enA->ignorarCedula($CED_SUELTA, 'NO FACTURA A A');
    assertPorEmpresa(ProveedorCodigoErp::conoceCedula($CED_SUELTA, $A),
        'A ya no pregunta por la cédula que decidió no procesar');
    assertPorEmpresa(!ProveedorCodigoErp::conoceCedula($CED_SUELTA, $B),
        'B sí: que no le facture a A no dice nada de B');
    assertPorEmpresa(count($enA->ignoradas()) === 1 && count($enB->ignoradas()) === 0,
        'cada empresa ve solo sus cédulas ignoradas');

    // ── La lista de la pantalla ────────────────────────────────────────
    $listaA = $enA->listar($COD);
    assertPorEmpresa((int) $listaA['total'] === 1 && $listaA['filas'][0]['cedula'] === $CED_A,
        'la pantalla de A lista su código con su cédula, una sola vez');
    assertPorEmpresa((int) (new ProveedorCodigoErp())->setSociedad($SIN)->listar($COD)['total'] === 0,
        'y la de una empresa sin listado no lista nada');

    // ── Sin empresa no se escribe ──────────────────────────────────────
    $ninguna = (new ProveedorCodigoErp())->setSociedad(0);
    $r = $ninguna->guardarCodigo('990000089', $CED_A, 'SIN EMPRESA');
    assertPorEmpresa(empty($r['ok']), 'sin empresa no se da de alta un código');
    $lanzo = false;
    try {
        $ninguna->importarDesdeErp([['codigo' => '990000089', 'cedula' => $CED_A, 'nombre' => 'X']]);
    } catch (RuntimeException $e) {
        $lanzo = true;
    }
    assertPorEmpresa($lanzo, 'ni se importa un listado');

    echo "OK: el catálogo de proveedores es de cada empresa\n";
} finally {
    $limpiar();
}
