<?php
/**
 * El pago y su listado son una sola ficha, y una sola columna los apunta.
 *
 * Estaban en dos tablas: `pagos` con el nombre y la fecha, `porpagar_listados`
 * con el archivo cargado. Nunca hubo un pago con dos listados ni un listado
 * sin pago, así que era la misma cosa escrita dos veces. Se notaba en los
 * nombres —el listado se bautizaba pegándole "Pago " al del pago, y quedaban
 * filas llamadas "Pago Pago Semana 200826"— y se pagaba en las consultas: cada
 * factura del ERP llevaba `pago_id` y `porpagar_listado_id` diciendo lo mismo.
 *
 * Lo que se fija acá:
 *
 *   · Un solo apuntador. Si mañana alguien vuelve a agregar el segundo, esta
 *     prueba lo dice: dos columnas que significan lo mismo son dos columnas
 *     que se pueden contradecir.
 *   · El archivo se anota sobre el propio pago, sin crear una segunda ficha.
 *   · El contador se cuenta, no se copia.
 *   · Borrar un pago suelta sus facturas en vez de borrarlas —son el registro
 *     del ERP— y suelta también sus comprobantes, para que el organizador los
 *     saque de la carpeta de entrega.
 *
 * Uso: php tests/PagoUnicoTest.php
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Pago.php';

$fallos = 0;
function verificaUnico($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$pdo = null;
try {
    $config = require __DIR__ . '/../app/config/database.php';
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: PagoUnico (sin base de datos disponible)\n";
    exit(0);
}

// Hace falta que el modelo se haya construido al menos una vez: es quien se
// traga la tabla vieja en las instalaciones que vienen de antes.
new Pago();

// ── Una sola ficha ────────────────────────────────────────────────────
$tablaVieja = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'porpagar_listados'"
)->fetchColumn();
verificaUnico($tablaVieja === 0, 'la tabla de listados ya no existe: el pago es su propia ficha');

$columnas = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas_erp'
       AND COLUMN_NAME IN ('pago_id', 'porpagar_listado_id')"
)->fetchAll(PDO::FETCH_COLUMN);
sort($columnas);
verificaUnico($columnas === ['pago_id'],
    'la factura del ERP apunta al pago por una sola columna (tiene: '
    . (implode(', ', $columnas) ?: 'ninguna') . ')');

foreach (['archivo_origen', 'total_lineas', 'archivo_subido_en'] as $columna) {
    $hay = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos'
           AND COLUMN_NAME = '{$columna}'"
    )->fetchColumn();
    verificaUnico($hay === 1, "el pago se quedó con `{$columna}`, que aportaba el listado");
}

// ── Lo que hace el modelo con esa ficha ───────────────────────────────
$sociedadPrueba = 980000 + random_int(0, 9000);
// Acotado a la empresa de la prueba: findById tambien respeta el alcance.
$modelo = (new Pago())->setSociedad($sociedadPrueba);
$creados = [];

$pagoId = (int) $modelo->crear('Urgente proveedores', $sociedadPrueba, '2026-09-08');
$creados[] = $pagoId;

$antes = $modelo->findById($pagoId);
verificaUnico(empty($antes['archivo_origen']) && (int) $antes['total_lineas'] === 0,
    'un pago recién creado no tiene archivo ni facturas todavía');

// El archivo se anota sobre el pago. Antes esto creaba una segunda fila con
// el nombre del pago y "Pago " pegado adelante.
$modelo->registrarArchivo($pagoId, 'Pago_proveedores_080926.xlsx', $sociedadPrueba);
$conArchivo = $modelo->findById($pagoId);
verificaUnico((string) $conArchivo['archivo_origen'] === 'Pago_proveedores_080926.xlsx',
    'el archivo queda anotado en el propio pago, sin ficha aparte');
verificaUnico(!empty($conArchivo['archivo_subido_en']),
    'y con la fecha en que se subió, que no es la de creación del pago');
verificaUnico((string) $conArchivo['nombre'] === 'Urgente proveedores',
    'el nombre del pago no se toca: no se le pega "Pago " adelante');

$conSociedad = (new Pago())->setSociedad($sociedadPrueba)->getConSociedad($pagoId);
verificaUnico($conSociedad !== null && array_key_exists('sociedad_nombre', $conSociedad),
    'getConSociedad trae el pago con el nombre de su empresa');
verificaUnico((new Pago())->setSociedad($sociedadPrueba + 1)->getConSociedad($pagoId) === null,
    'y no lo entrega a otra empresa aunque pida el id');

$detalle = (new Pago())->setSociedad($sociedadPrueba)->getConDetalle(10);
verificaUnico(count($detalle) === 1 && (int) $detalle[0]['id'] === $pagoId,
    'la lista de la pantalla trae los pagos de la empresa en curso');

// ── El contador se cuenta ─────────────────────────────────────────────
$modelo->actualizarTotalLineas($pagoId);
$recontado = $modelo->findById($pagoId);
verificaUnico((int) $recontado['total_lineas'] === 0,
    'sin facturas asignadas el contador es cero, aunque el archivo diga otra cosa');

// ── Borrar suelta, no borra ───────────────────────────────────────────
//
// Se prueba con una factura del ERP de mentira, marcada como si estuviera en
// este pago. Al borrar el pago tiene que seguir existiendo, sin marca.
$clave = '__pago_unico_' . $sociedadPrueba;
$pdo->prepare(
    "INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre,
                               documento, monto, saldo, estado, pago_id, saldo_pago,
                               estado_respaldo)
     VALUES (?, ?, '999', 'PROVEEDOR DE PRUEBA', '00100001010000998877', 1000, 1000,
             'asignada_pago', ?, 1000, 'respaldada')"
)->execute([$clave, $sociedadPrueba, $pagoId]);
$erpId = (int) $pdo->lastInsertId();

$modelo->actualizarTotalLineas($pagoId);
verificaUnico((int) $modelo->findById($pagoId)['total_lineas'] === 1,
    'con una factura marcada, el contador dice una');

$modelo->eliminar($pagoId);

$sobrevive = $pdo->query("SELECT estado, pago_id, saldo_pago, estado_respaldo
                            FROM facturas_erp WHERE id = {$erpId}")->fetch();
verificaUnico($sobrevive !== false,
    'la factura del ERP sobrevive al borrado del pago: es el registro de la empresa');
verificaUnico($sobrevive && $sobrevive['pago_id'] === null && $sobrevive['estado'] === 'pendiente',
    'pero queda suelta, disponible para otro pago');
verificaUnico($sobrevive && $sobrevive['saldo_pago'] === null
    && $sobrevive['estado_respaldo'] === 'sin_respaldo',
    'y se le descongela el saldo y se le borra el respaldo, que eran del pago');

// Se le pregunta a la base y no al modelo: findById() memoriza, y acá lo
// que hay que comprobar es que la fila ya no está.
$quedan = (int) $pdo->query("SELECT COUNT(*) FROM pagos WHERE id = {$pagoId}")->fetchColumn();
verificaUnico($quedan === 0, 'el pago sí desaparece');

// Limpieza.
$pdo->prepare('DELETE FROM facturas_erp WHERE id = ?')->execute([$erpId]);
foreach ($creados as $id) {
    $pdo->prepare('DELETE FROM pagos WHERE id = ?')->execute([(int) $id]);
}

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}

echo "OK: una ficha por pago, una columna que lo apunta\n";
