<?php
/**
 * Ni los XML ni los PDF se guardan dentro de la base ni dentro del proyecto.
 *
 * Dos cosas que verificar contra la base real:
 *
 *   1. Que la columna que guardaba el XML completo (`xml_contenido`) ya no
 *      exista. Mientras existiera, cualquier código nuevo podía volver a
 *      llenarla sin que nadie se diera cuenta, y la base crecería con
 *      documentos que ya están archivados en la carpeta compartida.
 *
 *   2. Que al guardar una factura la ruta quede RELATIVA, y que al leerla
 *      vuelva expandida al disco de esta computadora. Esta es la pieza que
 *      hace que una fila escrita en una máquina sirva en las demás; si se
 *      rompe, no falla nada visible hoy — falla mañana, en la computadora de
 *      otra persona, que es justo donde cuesta encontrarlo.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/helpers/RutaDocumento.php';

$fallos = 0;
function verificaDoc($condicion, $mensaje)
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
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: DocumentosFueraDeLaBase (sin base de datos disponible)\n";
    exit(0);
}

// ── 1. La base no guarda documentos ───────────────────────────────────
$columnasContenido = $pdo->query(
    "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS c
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND (COLUMN_NAME = 'xml_contenido'
            OR (DATA_TYPE LIKE '%blob%' AND TABLE_NAME LIKE '%factura%'))"
)->fetchAll(PDO::FETCH_COLUMN);

verificaDoc($columnasContenido === [],
    'ninguna columna guarda el contenido de un comprobante: ' . implode(', ', $columnasContenido));

// ── 2. Al guardar se acorta; al leer se expande ───────────────────────
$proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
if ($proveedorId <= 0) {
    echo "SKIP: DocumentosFueraDeLaBase (no hay proveedores para la prueba)\n";
    exit(0);
}

$raizPrueba = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_raiz_prueba';
RutaDocumento::fijarRaiz($raizPrueba);

$relativa = '2026/07 JULIO/Facturas/EN SISTEMA/FE_PRUEBA_RUTAS_010726_00099999.xml';
$absoluta = $raizPrueba . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
$consecutivo = '99999999999999999999';
$facturaId = 0;

try {
    // Se guarda con la ruta completa, como la entrega el archivador.
    $facturaId = (int) (new Factura())->crear([
        'consecutivo_completo' => $consecutivo,
        'tipo_documento' => 'FE',
        'proveedor_id' => $proveedorId,
        'fecha_emision' => '2026-07-01',
        'numero_factura_asistente' => '00099999',
        'subtotal' => 100, 'iva' => 13, 'total' => 113,
        'archivo_xml' => basename($relativa),
        'ruta_xml' => $absoluta,
        'ruta_pdf' => null,
        'hash_xml' => str_repeat('a', 64),
    ]);
    verificaDoc($facturaId > 0, 'la factura de prueba se creó');

    $guardada = $pdo->query("SELECT ruta_xml FROM facturas_xml WHERE id = {$facturaId}")->fetchColumn();
    verificaDoc($guardada === $relativa,
        'en la base quedó la ruta relativa, no la de esta computadora (quedó: ' . var_export($guardada, true) . ')');
    verificaDoc(strpos((string) $guardada, $raizPrueba) === false,
        'la ruta guardada no menciona ninguna carpeta propia de esta computadora');

    $leida = (new Factura())->findById($facturaId);
    verificaDoc($leida['ruta_xml'] === $absoluta,
        'al leerla vuelve expandida al disco de esta computadora');

    // Y desde otra computadora, donde la carpeta compartida está en otro lado.
    RutaDocumento::fijarRaiz('D:\\OtraMaquina\\Docs');
    $desdeOtra = (new Factura())->findById($facturaId);
    verificaDoc(strpos($desdeOtra['ruta_xml'], 'D:') === 0,
        'la misma fila apunta a la carpeta de la otra computadora, sin tocar la base');
    RutaDocumento::fijarRaiz($raizPrueba);

    // Reorganizar el archivo tampoco debe reintroducir rutas completas.
    $nuevaAbs = $raizPrueba . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . 'movida.xml';
    (new Factura())->actualizarUbicacionArchivos($facturaId, $nuevaAbs, '');
    $trasMover = $pdo->query("SELECT ruta_xml FROM facturas_xml WHERE id = {$facturaId}")->fetchColumn();
    verificaDoc($trasMover === '2026/movida.xml',
        'al reorganizar el archivo la ruta sigue guardándose relativa (quedó: ' . var_export($trasMover, true) . ')');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    $fallos++;
} finally {
    if ($facturaId > 0) {
        $pdo->prepare('DELETE FROM facturas_xml WHERE id = ? AND consecutivo_completo = ?')
            ->execute([$facturaId, $consecutivo]);
    }
    RutaDocumento::olvidarRaiz();
}

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}
echo "OK: los comprobantes viven en la carpeta compartida; la base solo guarda dónde\n";
