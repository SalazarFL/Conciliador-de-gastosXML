<?php
/**
 * Un comprobante que ya está cargado no vuelve a entrar a la Bandeja.
 *
 * Antes entraba: se marcaba "pendiente", alguien lo importaba, subía y bajaba
 * el par por la red y salía "duplicado". Desde la pantalla eso se veía como un
 * clic que no hacía nada —procesar el correo, importarlo y que no apareciera
 * en ningún lado—, que es de lo que se quejó quien lo usa.
 *
 * Lo que se comprueba aquí:
 *
 *   1. La primera vez entra, como siempre.
 *   2. La segunda no entra, se dice con nombre y fecha, y la fila que había
 *      dejado la captura anterior se va con sus archivos.
 *   3. Si está cargado en OTRA empresa, se dice en cuál. No se importa: la
 *      clave única de facturas_xml no lleva la empresa, así que el mismo
 *      comprobante no puede estar en dos.
 *   4. Si al comprobante cargado le falta un archivo, SÍ entra: la
 *      importación lo vuelve a archivar, que es como se repone un perdido.
 *   5. Y lo mismo al importar, que es la última parada: entre capturar e
 *      importar pueden pasar horas y otra persona pudo meterlo.
 *
 * Uso: php tests/CorreoRepetidaTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/Proveedor.php';

$fallas = [];

function paso($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: CorreoRepetida (sin base de datos disponible)\n";
    exit(0);
}
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
if ($cuentaId <= 0) {
    echo "SKIP: CorreoRepetida (sin cuentas de correo en la base aislada)\n";
    exit(0);
}

$marca = strtoupper(bin2hex(random_bytes(3)));
$cedulaEmpresa = '3109995' . random_int(100, 999);
$cedulaOtra = '3109996' . random_int(100, 999);
$cedulaProveedor = '3102' . random_int(100000, 999999);
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'repetida_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::olvidar();
// Lo nuevo iría a SharePoint; acá lo que se prueba es la repetida, así que
// una carpeta de disco hace de archivo (ver tests/soporte/ArchivoDePrueba.php).
require_once __DIR__ . '/../app/helpers/AlmacenEnDisco.php';
AlmacenDocumentos::fijar(new AlmacenEnDisco());

$sociedadId = 0;
$sociedadOtraId = 0;
$proveedorId = 0;
$proveedorCreado = false;
$bandejaIds = [];
$facturaIds = [];
$temporales = [];

/** El XML de una factura, siempre el mismo para el mismo número. */
$xmlDe = function ($numero, $cedulaReceptor) use ($cedulaProveedor, $marca) {
    $clave = '50619092600310' . substr($cedulaProveedor, 0, 8) . '00100001010000' . $numero
        . '1' . str_pad((string) $numero, 8, '0', STR_PAD_LEFT);
    return ['clave' => $clave, 'consecutivo' => '00100001010000' . $numero,
            'xml' => '<?xml version="1.0" encoding="UTF-8"?>'
        . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
        . '<Clave>' . $clave . '</Clave><NumeroConsecutivo>00100001010000' . $numero . '</NumeroConsecutivo>'
        . '<FechaEmision>2026-09-19T10:00:00-06:00</FechaEmision>'
        . '<Emisor><Nombre>PROVEEDOR REPETIDA ' . $marca . '</Nombre><Identificacion><Numero>'
        . $cedulaProveedor . '</Numero></Identificacion></Emisor>'
        . '<Receptor><Nombre>EMPRESA</Nombre><Identificacion><Numero>' . $cedulaReceptor
        . '</Numero></Identificacion></Receptor>'
        . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
        . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
        . '</FacturaElectronica>'];
};

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute(['REPETIDA ' . $marca, $cedulaEmpresa]);
    $sociedadId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute(['LA OTRA ' . $marca, $cedulaOtra]);
    $sociedadOtraId = (int) $pdo->lastInsertId();

    $proveedores = new Proveedor();
    $proveedorCreado = $proveedores->findByRfc($cedulaProveedor) === null;
    $proveedorId = (int) $proveedores->obtenerOCrear($cedulaProveedor, 'PROVEEDOR REPETIDA ' . $marca);

    $correo = (new ReflectionClass(CorreoController::class))->newInstanceWithoutConstructor();
    $metodo = (new ReflectionClass(CorreoController::class))->getMethod('procesarMensaje');
    $metodo->setAccessible(true);
    $bandeja = (new CorreoBandeja())->setSociedad(0);
    // Acotada a la empresa activa, como la que arma el controlador: si la
    // pregunta "¿ya está cargada?" se acotara igual, nunca vería la de otra
    // empresa y el aviso del punto 3 no saldría nunca.
    $facturas = (new Factura())->setSociedad($sociedadId);

    /** Procesa un correo como lo hace la pantalla: a mano, sin importar. */
    $capturar = function ($numero, $cedulaReceptor, $claveCorreo) use (
        $metodo, $correo, $bandeja, $facturas, $cuentaId, $sociedadId,
        $cedulaEmpresa, $xmlDe, &$temporales
    ) {
        $doc = $xmlDe($numero, $cedulaReceptor);
        $x = tempnam(sys_get_temp_dir(), 'rep_x_');
        $p = tempnam(sys_get_temp_dir(), 'rep_p_');
        file_put_contents($x, $doc['xml']);
        file_put_contents($p, '%PDF-1.4 repetida ' . $numero);
        $temporales[] = $x;
        $temporales[] = $p;
        $mensaje = ['xmls' => [['ruta' => $x, 'nombre' => $doc['clave'] . '.xml']],
                    'pdfs' => [['ruta' => $p, 'nombre' => 'FE_' . $doc['clave'] . '.pdf']],
                    'clave' => $claveCorreo, 'remitente' => 'p@example.test',
                    'asunto' => 'Factura ' . $numero, 'fecha' => '2026-09-19 10:00:00'];

        return $metodo->invoke($correo, $mensaje, $bandeja, $facturas, $cuentaId, ['FE'],
            $cedulaEmpresa, $sociedadId, [], 'manual');
    };

    /** Mete una factura ya archivada, con sus dos archivos en el disco. */
    // El XML tiene que ser BYTE A BYTE el que llega por correo: si no, el
    // hash no coincide y lo que se estaría probando es el camino de respaldo.
    $yaArchivada = function ($numero, $sociedad, $conPdf = true) use (
        $pdo, $raiz, $proveedorId, $xmlDe, $cedulaEmpresa, &$facturaIds
    ) {
        $doc = $xmlDe($numero, $cedulaEmpresa);
        $carpeta = $raiz . DIRECTORY_SEPARATOR . 'SISTEMA';
        if (!is_dir($carpeta)) { mkdir($carpeta, 0777, true); }
        $rutaXml = 'SISTEMA/FE_' . $numero . '.xml';
        $rutaPdf = 'SISTEMA/FE_' . $numero . '.pdf';
        file_put_contents($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaXml), $doc['xml']);
        if ($conPdf) {
            file_put_contents($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaPdf), '%PDF ' . $numero);
        }
        $pdo->prepare("INSERT INTO facturas_xml
                (consecutivo_completo, numero_factura_asistente, proveedor_id, sociedad_id, fecha_emision,
                 tipo_documento, clave, ruta_xml, archivo_xml, ruta_pdf, archivo_pdf, hash_xml, estado_pdf)
                VALUES (?, ?, ?, ?, '2026-09-19', 'FE', ?, ?, ?, ?, ?, ?, 'disponible')")
            ->execute([$doc['consecutivo'], substr((string) $numero, 0, 8), $proveedorId, $sociedad,
                $doc['clave'], $rutaXml, 'FE_' . $numero . '.xml', $rutaPdf, 'FE_' . $numero . '.pdf',
                hash('sha256', $doc['xml'])]);
        $facturaIds[] = (int) $pdo->lastInsertId();
    };

    // ── 1. La primera vez entra ──────────────────────────────────────────
    $r = $capturar('100001', $cedulaEmpresa, 'rep-1-' . $marca);
    foreach (($r['filas'] ?? []) as $f) { $bandejaIds[] = (int) $f['id']; }
    paso((int) $r['nuevas'] === 1 && $r['repetidas'] === [],
        'un comprobante nuevo entra a la Bandeja: ' . json_encode([$r['nuevas'], $r['errores']]));

    // ── 2. Ya cargada en esta empresa: no entra, y limpia lo de antes ────
    $yaArchivada('100001', $sociedadId);
    $r = $capturar('100001', $cedulaEmpresa, 'rep-1-' . $marca);
    paso((int) $r['nuevas'] === 0 && (int) $r['ya_existentes'] === 1 && count($r['repetidas']) === 1,
        'la segunda vez no entra: ' . json_encode([$r['nuevas'], $r['ya_existentes'], $r['repetidas']]));
    $rep = $r['repetidas'][0];
    paso(!empty($rep['misma_empresa']) && $rep['cargada_en'] !== '',
        'y dice que es de esta misma empresa, con la fecha: ' . json_encode($rep));
    paso($bandeja->getByIds($bandejaIds) === [],
        'la fila que había quedado de la captura anterior se va de la Bandeja');
    paso(!is_file($raiz . DIRECTORY_SEPARATOR . '_TRABAJO'),
        'sin dejar el archivo suelto');

    // ── 3. Cargada en otra empresa: lo dice, y tampoco entra ─────────────
    $yaArchivada('100002', $sociedadOtraId);
    $r = $capturar('100002', $cedulaEmpresa, 'rep-2-' . $marca);
    $rep = $r['repetidas'][0] ?? [];
    paso((int) $r['nuevas'] === 0 && count($r['repetidas']) === 1
        && empty($rep['misma_empresa']) && $rep['empresa'] === 'LA OTRA ' . $marca,
        'una cargada en otra empresa se avisa con el nombre de esa empresa: ' . json_encode($r['repetidas']));

    // ── 4. Si le falta un archivo, sí entra, para reponerlo ──────────────
    $yaArchivada('100003', $sociedadId, false);
    $r = $capturar('100003', $cedulaEmpresa, 'rep-3-' . $marca);
    foreach (($r['filas'] ?? []) as $f) { $bandejaIds[] = (int) $f['id']; }
    paso((int) $r['nuevas'] === 1 && $r['repetidas'] === [],
        'a una cargada a la que le falta el PDF se la deja entrar para completarla: '
        . json_encode([$r['nuevas'], $r['repetidas']]));

    // ── 5. La última parada, al importar ─────────────────────────────────
    // La fila de 100003 está pendiente; se completa su factura y se pide
    // importarla: ya no hay nada que hacerle, así que se queda fuera.
    $pdo->prepare('UPDATE facturas_xml SET ruta_pdf = ? WHERE consecutivo_completo = ?')
        ->execute(['SISTEMA/FE_100003.pdf', '00100001010000100003']);
    file_put_contents($raiz . DIRECTORY_SEPARATOR . 'SISTEMA' . DIRECTORY_SEPARATOR . 'FE_100003.pdf', '%PDF 100003');
    $filaPendiente = $bandeja->getByIds($bandejaIds, 'pendiente')[0] ?? [];
    paso(!empty($filaPendiente), 'quedó la fila pendiente para el paso 5');

    if ($filaPendiente) {
        $yaCargado = $facturas->yaCargado((string) $filaPendiente['hash_xml'], '', '', '');
        paso($yaCargado !== null && (int) $yaCargado['sociedad_id'] === $sociedadId
            && EstadoArchivo::de($yaCargado)['entregable'],
            'al importar, la misma regla la reconoce ya completa: ' . json_encode($yaCargado ? [
                $yaCargado['sociedad_id'], $yaCargado['ruta_pdf']] : null));
    }
} catch (Throwable $e) {
    $fallas[] = 'se cortó: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── Limpieza ────────────────────────────────────────────────────────────
try {
    if ($facturaIds) {
        $pdo->exec('DELETE FROM facturas_xml WHERE id IN (' . implode(',', array_map('intval', $facturaIds)) . ')');
    }
    $pdo->prepare('DELETE FROM correo_bandeja WHERE uid_correo LIKE ?')->execute(['rep-%-' . $marca]);
    foreach ([$sociedadId, $sociedadOtraId] as $s) {
        if ($s) { $pdo->exec('DELETE FROM sociedades WHERE id = ' . (int) $s); }
    }
    if ($proveedorCreado) {
        $pdo->prepare('DELETE FROM proveedores WHERE rfc = ?')->execute([$cedulaProveedor]);
    }
} catch (Throwable $e) {
    $fallas[] = 'no se pudo limpiar la base: ' . $e->getMessage();
}
foreach ($temporales as $t) { @unlink($t); }
$borrarArbol = function ($dir) use (&$borrarArbol) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $r = $dir . DIRECTORY_SEPARATOR . $e;
        is_dir($r) ? $borrarArbol($r) : @unlink($r);
    }
    @rmdir($dir);
};
$borrarArbol($raiz);

if ($fallas) {
    echo "FALLA CorreoRepetida:\n";
    foreach ($fallas as $f) { echo '  - ' . $f . "\n"; }
    exit(1);
}
echo "OK CorreoRepetida: lo que ya está cargado se avisa y no vuelve a entrar.\n";
exit(0);
