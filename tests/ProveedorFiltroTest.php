<?php
/**
 * El filtro de proveedor: una identidad para todos los listados.
 *
 * Lo que se comprueba es lo que hace útil al filtro: que elegir un proveedor
 * por su código lo encuentre también por su cédula (y al revés), que el mismo
 * proveedor no salga dos veces en el desplegable porque unas filas traigan
 * código y otras el emisor del XML, y que una clave que no existe deje el
 * listado vacío en vez de mostrarlo entero.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/ProveedorCatalogo.php';

function assertProveedorFiltro($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── Lo que no necesita base: la forma de la clave ────────────────────
foreach (['cod:140000003', 'ced:3101011167', 'id:42', 'nom:ACME S.A.'] as $clave) {
    assertProveedorFiltro(ProveedorCatalogo::normalizarClave($clave) === $clave,
        "la clave {$clave} se acepta tal cual");
}
foreach (['', '   ', 'JOP', '140000003', 'select 1', 'raro:1'] as $basura) {
    assertProveedorFiltro(ProveedorCatalogo::normalizarClave($basura) === '',
        "lo que no es una clave se descarta: '{$basura}'");
}

$params = [];
assertProveedorFiltro(ProveedorCatalogo::condicion('', ['codigo' => 'e.cod'], $params) === ''
    && $params === [],
    'sin proveedor elegido no se agrega condición ni parámetros');

// Una clave válida que este listado no puede reconocer (no tiene ninguna de
// sus columnas) deja el listado vacío: mostrarlo entero sería mentir.
$params = [];
assertProveedorFiltro(ProveedorCatalogo::condicion('nom:ACME', ['codigo' => 'e.cod'], $params) === '1=0',
    'una clave que este listado no sabe reconocer no se convierte en "sin filtro"');

// ── Lo que sí necesita base ──────────────────────────────────────────
$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "OK: filtro de proveedor (la parte con base se saltó: sin base disponible)\n";
    exit(0);
}

$CEDULA = '3999999999';
$COD_A  = '__PRUEBA_A__';
$COD_B  = '__PRUEBA_B__';
$NOMBRE = 'PROVEEDOR DE PRUEBA S.A.';

$limpiar = function () use ($pdo, $CEDULA, $COD_A, $COD_B) {
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo IN (?, ?)')
        ->execute([$COD_A, $COD_B]);
    $pdo->prepare('DELETE FROM proveedores WHERE rfc = ?')->execute([$CEDULA]);
};
$limpiar();

try {
    $pdo->prepare('INSERT INTO proveedores (rfc, razon_social, razon_social_normalizada) VALUES (?, ?, ?)')
        ->execute([$CEDULA, $NOMBRE, $NOMBRE]);
    $proveedorId = (int) $pdo->lastInsertId();

    // El mismo proveedor con dos códigos en el ERP: pasa cuando lo dan de
    // alta dos veces, y elegirlo tiene que alcanzar sus facturas de los dos.
    $alta = $pdo->prepare(
        'INSERT INTO proveedor_codigo_erp (proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
         VALUES (?, ?, ?, ?, ?)'
    );
    $alta->execute([$COD_A, $proveedorId, $CEDULA, $NOMBRE, 'manual']);
    $alta->execute([$COD_B, $proveedorId, $CEDULA, $NOMBRE, 'manual']);
    ProveedorCatalogo::olvidar();

    // ── Las dos formas de nombrar al mismo proveedor se entienden ──
    foreach (['cod:' . $COD_A, 'ced:' . $CEDULA, 'id:' . $proveedorId] as $clave) {
        $id = ProveedorCatalogo::resolver($clave);
        assertProveedorFiltro($id['cedula'] === $CEDULA,
            "{$clave} llega a la cédula del proveedor");
        assertProveedorFiltro($id['proveedor_ids'] === [$proveedorId],
            "{$clave} llega al proveedor de los comprobantes");
        assertProveedorFiltro($id['codigos'] === [$COD_A, $COD_B],
            "{$clave} alcanza los DOS códigos del ERP, no solo el elegido");
    }

    // ── La condición usa lo que cada listado tiene a mano ──
    $params = [];
    $sql = ProveedorCatalogo::condicion('cod:' . $COD_A, ['codigo' => 'e.proveedor_codigo'], $params);
    assertProveedorFiltro($sql === '(e.proveedor_codigo IN (?,?))', 'el listado del ERP filtra por sus códigos');
    assertProveedorFiltro($params === [$COD_A, $COD_B], 'y recibe los dos códigos como parámetros');

    $params = [];
    $sql = ProveedorCatalogo::condicion('cod:' . $COD_A, ['proveedor_id' => 'f.proveedor_id'], $params);
    assertProveedorFiltro($sql === '(f.proveedor_id IN (?))',
        'un listado de comprobantes XML reconoce al mismo proveedor por su emisor');
    assertProveedorFiltro($params === [$proveedorId], 'con el id del proveedor como parámetro');

    // La cédula se guarda con guiones en un lado y sin ellos en el otro.
    $params = [];
    $sql = ProveedorCatalogo::condicion('ced:' . $CEDULA, ['cedula' => 'p.rfc'], $params);
    assertProveedorFiltro(strpos($sql, "REPLACE(REPLACE(p.rfc, '-', ''), ' ', '') = ?") !== false,
        'la cédula se compara sin guiones ni espacios');
    assertProveedorFiltro($params === [$CEDULA], 'con la cédula en dígitos');

    // Varias columnas: cualquiera que reconozca al proveedor sirve.
    $params = [];
    $sql = ProveedorCatalogo::condicion('ced:' . $CEDULA, [
        'codigo' => 'e.proveedor_codigo', 'proveedor_id' => 'x.proveedor_id',
    ], $params);
    assertProveedorFiltro(strpos($sql, ' OR ') !== false
        && strpos($sql, 'e.proveedor_codigo IN') !== false
        && strpos($sql, 'x.proveedor_id IN') !== false,
        'con dos columnas, basta con que una reconozca al proveedor');

    // ── El desplegable: una sola opción por proveedor ──
    // Así llegan las filas de Seguimiento: unas traen el código del ERP y
    // otras el emisor del comprobante enlazado. Es el mismo proveedor.
    $opciones = ProveedorCatalogo::opciones([
        ['codigo' => $COD_A, 'nombre' => $NOMBRE, 'n' => 6],
        ['codigo' => $COD_A, 'proveedor_id' => $proveedorId, 'nombre' => $NOMBRE, 'n' => 2],
        ['codigo' => $COD_B, 'nombre' => 'PROVEEDOR DE PRUEBA', 'n' => 1],
    ]);
    $mias = array_values(array_filter($opciones, function ($o) use ($CEDULA) {
        return $o['cedula'] === $CEDULA;
    }));
    assertProveedorFiltro(count($mias) === 1,
        'un proveedor es UNA opción: ni sus dos códigos ni sus dos formas de fila lo duplican');

    $opcion = $mias[0];
    assertProveedorFiltro($opcion['n'] === 9,
        'la cuenta suma todas sus filas (6 + 2 + 1), que es lo que devolverá el filtro');
    assertProveedorFiltro($opcion['cedula'] === $CEDULA,
        'la opción muestra la cédula aunque la fila del listado no la traiga');
    assertProveedorFiltro($opcion['nombre'] === $NOMBRE,
        'de dos escrituras del nombre se queda con la completa');
    assertProveedorFiltro($opcion['codigos'] === [$COD_A, $COD_B],
        'y muestra los dos códigos, que es lo que la gente busca');
    assertProveedorFiltro(strncmp($opcion['clave'], 'cod:', 4) === 0,
        'la clave elegida es la del código: es la que todos los listados saben reconocer');

    // ── Una clave inventada no muestra el listado entero ──
    $params = [];
    $sql = ProveedorCatalogo::condicion('cod:__NO_EXISTE__', ['codigo' => 'e.proveedor_codigo'], $params);
    assertProveedorFiltro($sql === '(e.proveedor_codigo IN (?))' && $params === ['__NO_EXISTE__'],
        'un código desconocido se busca igual: el resultado vacío es la respuesta correcta');

    echo "OK: filtro de proveedor\n";
} finally {
    $limpiar();
    ProveedorCatalogo::olvidar();
}
