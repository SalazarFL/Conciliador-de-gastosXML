<?php
/**
 * Una empresa en SharePoint, de punta a punta, con el código de verdad.
 *
 * Las demás pruebas del almacén miran piezas sueltas. Esta recorre el camino
 * entero que hace un comprobante, con los mismos objetos que usa la
 * aplicación y contra la base aislada de certificación:
 *
 *   correo → bandeja → cola → importación → listado → abrirlo → ZIP →
 *   carpeta del pago → se pierde → se repone del correo
 *
 * y al final, lo que pasa cuando SharePoint no deja deshacer: el borrado
 * queda anotado y la tarea programada lo termina.
 *
 * SharePoint es la biblioteca en memoria (GraphEnMemoria): se comporta como
 * Graph —ids que no cambian, 409 al repetir un nombre, carpetas que se crean
 * solas— sin red ni credenciales. La carpeta compartida es un directorio
 * temporal, y la prueba comprueba que NADA del recorrido cae en él.
 *
 * Deja la base como la encontró: borra todo lo que crea.
 *
 * Uso: php tests/PuntaAPuntaSharePointTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/models/AlmacenPendiente.php';
require_once __DIR__ . '/../app/helpers/InvoiceImportQueue.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/../app/helpers/EstadoArchivo.php';
require_once __DIR__ . '/../app/helpers/PaqueteDocumentos.php';
require_once __DIR__ . '/../app/helpers/RecuperadorDocumentos.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

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
    echo "SKIP: PuntaAPuntaSharePoint (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    // Sin la columna, lo archivado en SharePoint se leería como de disco:
    // no hay recorrido que probar.
    echo "SKIP: PuntaAPuntaSharePoint (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
$emisor = $pdo->query("SELECT id, rfc, razon_social FROM proveedores WHERE rfc <> '' ORDER BY id LIMIT 1")->fetch();
if ($cuentaId <= 0 || !$emisor) {
    echo "SKIP: PuntaAPuntaSharePoint (sin cuentas de correo ni proveedores en la base aislada)\n";
    exit(0);
}

// ── El escenario ──────────────────────────────────────────────────────────

$cedulaEmpresa = '3109990' . random_int(100, 999);
$nombreEmpresa = 'PRUEBA NUBE ' . strtoupper(bin2hex(random_bytes(2)));
$codigoProveedor = 'E2E-' . bin2hex(random_bytes(3));
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e2e_sharepoint_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');

$biblioteca = new GraphEnMemoria();
$sharepoint = new AlmacenEnGraph(new ClienteGraph(
    ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
    $biblioteca, ['carpeta_estado' => null, 'registro' => null]
));
AlmacenDocumentos::olvidar();
AlmacenDocumentos::registrar('sharepoint', function () use ($sharepoint) { return $sharepoint; });

$sociedadId = 0;
$bandejaId = 0;
$importacionId = 0;
$facturaIds = [];
$pendientesAntes = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM almacen_pendientes')->fetchColumn();
$temporales = [];

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute([$nombreEmpresa, $cedulaEmpresa]);
    $sociedadId = (int) $pdo->lastInsertId();
    paso(AlmacenDocumentos::para($sociedadId) === $sharepoint,
        'la empresa de prueba archiva en SharePoint, como todas');

    // El portón del importador: sin listado de proveedores no entra nada.
    $pdo->prepare("INSERT INTO proveedor_codigo_erp (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
                   VALUES (?, ?, ?, ?, 'E2E', 'manual')")
        ->execute([$sociedadId, $codigoProveedor, (int) $emisor['id'], (string) $emisor['rfc']]);
    ProveedorCodigoErp::olvidarMapa();

    // ── 1. El correo llega y se captura ──────────────────────────────────
    $clave = '5062606260031011234560010000101000000' . random_int(100, 999) . '1' . random_int(10000000, 99999999);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
        . '<Clave>' . $clave . '</Clave>'
        . '<NumeroConsecutivo>0010000101000000' . random_int(1000, 9999) . '</NumeroConsecutivo>'
        . '<FechaEmision>2026-06-07T13:09:00-06:00</FechaEmision>'
        . '<Emisor><Nombre>' . htmlspecialchars($emisor['razon_social']) . '</Nombre><Identificacion><Numero>'
        . $emisor['rfc'] . '</Numero></Identificacion></Emisor>'
        . '<Receptor><Nombre>' . $nombreEmpresa . '</Nombre><Identificacion><Numero>' . $cedulaEmpresa
        . '</Numero></Identificacion></Receptor>'
        . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
        . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
        . '</FacturaElectronica>';
    $adjuntoXml = tempnam(sys_get_temp_dir(), 'e2e_xml_');
    $adjuntoPdf = tempnam(sys_get_temp_dir(), 'e2e_pdf_');
    file_put_contents($adjuntoXml, $xml);
    file_put_contents($adjuntoPdf, '%PDF-1.4 la factura');
    $temporales = [$adjuntoXml, $adjuntoPdf];

    $ref = new ReflectionClass(CorreoController::class);
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);
    $bandeja = (new CorreoBandeja())->setSociedad($sociedadId);
    $captura = $procesar->invoke($ref->newInstanceWithoutConstructor(), [
        'xmls' => [['ruta' => $adjuntoXml, 'nombre' => $clave . '.xml']],
        'pdfs' => [['ruta' => $adjuntoPdf, 'nombre' => 'FE_' . $clave . '.pdf']],
        'clave' => 'e2e-' . $clave,
        'remitente' => 'proveedor@example.test',
        'asunto' => 'Factura electrónica',
        'fecha' => '2026-06-07 13:09:00',
    ], $bandeja, new stdClass(), $cuentaId, ['FE'], '', 0,
       [['id' => $sociedadId, 'cedula' => $cedulaEmpresa]], 'automatica');

    $bandejaId = (int) ($captura['filas'][0]['id'] ?? 0);
    paso($bandejaId > 0 && ($captura['filas'][0]['estado'] ?? '') === 'pendiente',
        'el comprobante entra a la bandeja como pendiente: ' . json_encode($captura['errores'] ?? []));
    $fila = $bandeja->getByIds([$bandejaId], 'pendiente')[0] ?? [];
    $rutaBandejaXml = (string) ($fila['archivo_xml'] ?? '');
    $rutaBandejaPdf = (string) ($fila['archivo_pdf'] ?? '');
    paso(strpos($rutaBandejaXml, 'sharepoint:_TRABAJO/BANDEJA/xml/' . date('Y-m') . '/') === 0,
        'la bandeja de una empresa en SharePoint está en SharePoint, con su carpeta del mes: ' . $rutaBandejaXml);
    paso($biblioteca->contenido(substr($rutaBandejaXml, 11)) === $xml,
        'y el XML está ahí, entero');
    paso(!is_file($adjuntoXml), 'el adjunto bajado del correo ya no queda en esta máquina');
    paso(strpos((string) $pdo->query('SELECT archivo_xml FROM correo_bandeja WHERE id = ' . $bandejaId)->fetchColumn(),
        'sharepoint:') === 0, 'en la base la ruta de la bandeja se guarda con su prefijo');

    // ── 2. La cola lo toma donde está, sin copiarlo ─────────────────────
    $cola = new InvoiceImportQueue();
    $inicio = $cola->iniciarImportacion(['archivo_origen' => 'E2E', 'total_esperado' => 1,
        'tipo_documento' => 'FE', 'cedula_receptor' => $cedulaEmpresa, 'sociedad_id' => $sociedadId]);
    $importacionId = (int) $inicio['importacion_id'];
    $subidasAntes = count(array_filter($biblioteca->registro, function ($l) { return strpos($l, 'PUT') === 0; }));
    $encolado = $cola->agregarArchivosLocales($importacionId, [[
        'ruta' => $rutaBandejaXml, 'nombre' => $clave . '.xml', 'ruta_pdf' => $rutaBandejaPdf,
        'correo_cuenta_id' => $cuentaId, 'correo_uid' => 4242, 'correo_carpeta' => 'INBOX',
    ]]);
    $subidasDespues = count(array_filter($biblioteca->registro, function ($l) { return strpos($l, 'PUT') === 0; }));
    paso((int) $encolado['uploaded_count'] === 1 && $encolado['files'][0]['path'] === $rutaBandejaXml,
        'la cola apunta al archivo de la bandeja');
    paso($subidasDespues === $subidasAntes, 'y encolar no subió nada: no hay copia en IMPORTACIONES');

    // ── 3. Se importa ───────────────────────────────────────────────────
    $lote = $cola->procesarBatch($importacionId, 5);
    $procesado = $lote['processed'][0] ?? [];
    paso(($procesado['estado'] ?? '') === 'importado', 'la cola lo importa: ' . json_encode($procesado));
    $facturaId = (int) ($procesado['factura_id'] ?? 0);
    $facturaIds[] = $facturaId;

    $cruda = $pdo->query('SELECT ruta_xml, ruta_pdf, almacen, item_xml_id, item_pdf_id, sociedad_id
                          FROM facturas_xml WHERE id = ' . $facturaId)->fetch() ?: [];
    paso(($cruda['almacen'] ?? '') === 'sharepoint', 'la fila anota que su documento está en SharePoint');
    paso(!empty($cruda['item_xml_id']) && !empty($cruda['item_pdf_id']), 'y los ids de SharePoint del XML y del PDF');
    paso(strpos((string) ($cruda['ruta_xml'] ?? ''),
        'sharepoint:SISTEMA/' . $nombreEmpresa . '/2026/06 JUNIO/Facturas/') === 0,
        'archivado en la carpeta de su empresa y su mes, en SharePoint: ' . ($cruda['ruta_xml'] ?? ''));
    paso($biblioteca->contenido(substr($rutaBandejaXml, 11)) === null
        && $biblioteca->contenido(substr($rutaBandejaPdf, 11)) === null,
        'la copia de la bandeja se borra cuando la base confirmó');

    // ── 4. Se ve en los listados sin salir a la red ─────────────────────
    $facturas = (new Factura())->setSociedad($sociedadId);
    $documento = $facturas->findById($facturaId);
    $llamadas = $biblioteca->llamadas;
    $estado = EstadoArchivo::de($documento);
    paso($estado['entregable'] && !$estado['perdido'] && $biblioteca->llamadas === $llamadas,
        'el listado lo da por completo sin preguntarle nada a SharePoint');

    // ── 5. Se abre ──────────────────────────────────────────────────────
    $fp = RutaDocumento::abrirParaLeer($documento['ruta_xml']);
    paso($fp !== false && stream_get_contents($fp) === $xml, 'se abre y es exactamente el XML que llegó');
    if (is_resource($fp)) {
        fclose($fp);
    }

    // ── 6. Se descarga en ZIP ───────────────────────────────────────────
    $zip = $raiz . DIRECTORY_SEPARATOR . 'paquete.zip';
    $resumen = PaqueteDocumentos::crear([[
        'archivo_xml' => $documento['ruta_xml'], 'archivo_pdf' => $documento['ruta_pdf'],
        'numero_corto' => '1', 'tipo_doc' => 'FE',
    ]], $zip);
    $contenidoZip = new ZipArchive();
    $contenidoZip->open($zip);
    paso($resumen['xml'] === 1 && $resumen['pdf'] === 1 && $contenidoZip->numFiles === 2,
        'el ZIP lleva el par bajado de SharePoint: ' . json_encode($resumen));
    $contenidoZip->close();
    paso(glob(AlmacenDocumentos::carpetaTemporales() . DIRECTORY_SEPARATOR . 'doc_*') === [],
        'y no quedan temporales de la descarga');

    // ── 7. La carpeta del pago ──────────────────────────────────────────
    $archivo = (new DocumentoArchivo($raiz))->paraSociedad($sociedadId, $nombreEmpresa);
    $copia = $archivo->copiarAPago($documento['ruta_xml'], $documento['ruta_pdf'],
        ['tipo_documento' => 'FE', 'fecha_emision' => '2026-06-07', 'numero_factura_asistente' => '1'],
        'Pago E2E', (string) $emisor['razon_social']);
    paso($copia['copiados'] === 2 && strpos($copia['ruta_xml'], 'sharepoint:PAGOS/Pago E2E/') === 0,
        'la carpeta del pago se arma en SharePoint: ' . json_encode($copia));
    paso($biblioteca->contenido(substr($documento['ruta_xml'], 11)) === $xml,
        'y el original se queda donde estaba: el pago recibe una copia');

    // ── 8. Alguien lo borra en SharePoint, y se repone del correo ───────
    $claveXml = substr($documento['ruta_xml'], 11);
    $idBorrado = $biblioteca->buscar($claveXml);
    unset($biblioteca->items[$idBorrado]);
    // Reponer es otra petición: un almacén nuevo, que no recuerda lo que el
    // de antes averiguó sobre ese documento.
    $sharepoint = new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca, ['carpeta_estado' => null, 'registro' => null]
    ));
    AlmacenDocumentos::registrar('sharepoint', function () use ($sharepoint) { return $sharepoint; });
    $pdo->exec('UPDATE facturas_xml SET archivo_faltante_en = NOW(), correo_cuenta_id = ' . $cuentaId
        . ", correo_uid = 4242, correo_carpeta = 'INBOX' WHERE id = " . $facturaId);
    $documento = $facturas->findById($facturaId);
    paso(EstadoArchivo::de($documento)['perdido'], 'marcado por el reconciliador, el listado lo muestra perdido');

    $adjuntoOtraVez = tempnam(sys_get_temp_dir(), 'e2e_rep_');
    file_put_contents($adjuntoOtraVez, $xml);
    $temporales[] = $adjuntoOtraVez;
    $buzon = new class($adjuntoOtraVez, $clave) {
        private $ruta;
        private $clave;
        public function __construct($ruta, $clave) { $this->ruta = $ruta; $this->clave = $clave; }
        public function conectar() { return true; }
        public function cerrar() {}
        public function extraerMensaje($uid, $carpeta = '')
        {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('adj_', true) . '.xml';
            copy($this->ruta, $tmp);
            return ['uid' => (int) $uid, 'xmls' => [['ruta' => $tmp, 'nombre' => $this->clave . '.xml']], 'pdfs' => []];
        }
    };
    $cuentas = new class {
        public function configPara($id)
        {
            return ['cuenta_id' => (int) $id, 'host' => 'h', 'usuario' => 'u', 'password' => 'p', 'carpeta' => 'INBOX'];
        }
    };
    $recuperador = new RecuperadorDocumentos($facturas, $cuentas, $raiz, function () use ($buzon) { return $buzon; });
    $repuesto = $recuperador->recuperar([$documento]);
    paso((int) ($repuesto['recuperados'] ?? 0) === 1, 'se repone desde el correo: ' . json_encode($repuesto['detalle'] ?? $repuesto));
    paso($biblioteca->contenido($claveXml) === $xml, 'y vuelve a su misma ruta de SharePoint, con el mismo contenido');

    // ── 9. Deshacer cuando SharePoint no deja ───────────────────────────
    // La base falló después de subir: hay que borrar lo subido, y SharePoint
    // tampoco contesta. Queda anotado y la tarea programada lo termina.
    $local = tempnam(sys_get_temp_dir(), 'e2e_huerfano_');
    file_put_contents($local, '<FE>huérfano</FE>');
    $temporales[] = $local;
    $subido = $sharepoint->escribir('SISTEMA/' . $nombreEmpresa . '/HUERFANO.xml', $local);
    $caido = new class($biblioteca) implements TransporteGraph {
        private $real;
        public $caido = true;
        public function __construct($real) { $this->real = $real; }
        public function enviar(array $p)
        {
            if ($this->caido && $p['metodo'] === 'DELETE') {
                return ['codigo' => 0, 'cabeceras' => [], 'cuerpo' => '', 'error' => 'Connection timed out'];
            }
            return $this->real->enviar($p);
        }
    };
    $sinRed = new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $caido, ['carpeta_estado' => null, 'registro' => null, 'presupuesto' => 1,
                 'dormir' => function () {}]
    ));
    AlmacenDocumentos::registrar('sharepoint', function () use ($sinRed) { return $sinRed; });
    DocumentoArchivo::eliminarCreados(['xml_creado' => true, 'ruta_xml' => $subido['ruta'], 'item_xml_id' => $subido['item_id']]);
    $anotados = $pdo->query('SELECT * FROM almacen_pendientes WHERE id > ' . $pendientesAntes)->fetchAll();
    paso(count($anotados) === 1 && $anotados[0]['item_id'] === $subido['item_id'] && (int) $anotados[0]['intentos'] === 1,
        'el borrado que no se pudo queda anotado, con su id: ' . json_encode($anotados));
    paso($biblioteca->contenido('SISTEMA/' . $nombreEmpresa . '/HUERFANO.xml') !== null, 'y el archivo sigue ahí');

    $caido->caido = false;
    $drenado = (new AlmacenPendiente())->drenar(50);
    paso($drenado['hechos'] >= 1 && $biblioteca->contenido('SISTEMA/' . $nombreEmpresa . '/HUERFANO.xml') === null,
        'cuando vuelve la red, la tarea programada lo termina');
    paso((int) $pdo->query('SELECT COUNT(*) FROM almacen_pendientes WHERE id > ' . $pendientesAntes)->fetchColumn() === 0,
        'y lo tacha de la lista');

    // ── Nada del recorrido cayó en la carpeta compartida ────────────────
    $enDisco = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strpos($f->getPathname(), '_tmp_almacen') === false && $f->getFilename() !== 'paquete.zip') {
            $enDisco[] = substr($f->getPathname(), strlen($raiz));
        }
    }
    paso($enDisco === [], 'nada del recorrido quedó en la carpeta compartida: ' . json_encode($enDisco));
} catch (Throwable $e) {
    $fallas[] = 'se cortó el recorrido: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    // La base, como estaba.
    foreach (array_filter($facturaIds) as $id) {
        $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . (int) $id);
    }
    if ($importacionId > 0) {
        $pdo->exec('DELETE FROM importacion_items WHERE importacion_id = ' . $importacionId);
        $pdo->exec('DELETE FROM importaciones WHERE id = ' . $importacionId);
    }
    if ($bandejaId > 0) {
        $pdo->exec('DELETE FROM correo_bandeja WHERE id = ' . $bandejaId);
    }
    $pdo->exec('DELETE FROM almacen_pendientes WHERE id > ' . $pendientesAntes);
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute([$codigoProveedor]);
    if ($sociedadId > 0) {
        $pdo->exec('DELETE FROM sociedades WHERE id = ' . $sociedadId);
    }
    ProveedorCodigoErp::olvidarMapa();
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    RutaDocumento::olvidarRaiz();
    foreach ($temporales as $t) {
        @unlink($t);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($raiz);
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: una empresa en SharePoint recorre el camino entero, y lo que falla se termina después\n";
