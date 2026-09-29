<?php
/**
 * Una computadora SIN carpeta compartida: la forma de trabajar desde el
 * 27/09/2026, cuando SharePoint pasó a ser obligatorio.
 *
 * Es el reparto de la instalación:
 *
 *   - la PC trabaja con sus propios temporales;
 *   - los XML y los PDF —los que llegan por correo y los de la carpeta del
 *     pago— van a SharePoint, para que todas las PC los vean;
 *   - lo que no es XML ni PDF se queda en esta máquina.
 *
 * Y Nexo Fiscal vive en UNA carpeta de una biblioteca que ya se usaba para
 * otra cosa ("Contabilidad/Nexo Fiscal"), con permiso SOLO sobre esa carpeta:
 * la biblioteca y su raíz le contestan 403, como a una aplicación con
 * Files.SelectedOperations.Selected. Recorre, con los objetos de la
 * aplicación:
 *
 *   correo → bandeja → cola → importación → carpeta del pago → otra PC lo abre
 *
 * y comprueba además que nada toca la raíz de la biblioteca ni sale de la
 * carpeta, que a SharePoint no se sube nada que no sea XML o PDF (ni la nota
 * LEEME), que el respaldo de la base se queda en esta máquina, que sin
 * SharePoint no se archiva en ningún lado y que no quedan temporales.
 *
 * Corre contra la biblioteca en memoria (GraphEnMemoria), sin red. Con
 * XMLCONCILIA_SHAREPOINT_REAL=1 corre el MISMO recorrido contra la biblioteca
 * de app/config/local.graph.php —SharePoint de verdad, con la carpeta que diga
 * ahí— y al terminar borra lo que subió. Eso sale a la red y no es parte de la
 * certificación. Con XMLCONCILIA_SHAREPOINT_CARPETA=RUTA además, lo corre
 * dentro de esa carpeta de la biblioteca: la crea —tiene que no existir— y la
 * borra al terminar.
 *
 * Deja la base como la encontró.
 *
 * Uso: php tests/SinCarpetaCompartidaTest.php
 *      (PowerShell) $env:XMLCONCILIA_SHAREPOINT_REAL='1'; php tests/SinCarpetaCompartidaTest.php
 *      (PowerShell) $env:XMLCONCILIA_SHAREPOINT_CARPETA='_prueba/Nexo Fiscal'; (y lo de arriba)
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/Pago.php';
require_once __DIR__ . '/../app/models/Proveedor.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/helpers/InvoiceImportQueue.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
require_once __DIR__ . '/../app/helpers/ReconciliadorGraph.php';
require_once __DIR__ . '/../app/helpers/PaqueteDocumentos.php';
require_once __DIR__ . '/../app/helpers/RespaldoBase.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function paso($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

$real = getenv('XMLCONCILIA_SHAREPOINT_REAL') === '1';

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: SinCarpetaCompartida (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    echo "SKIP: SinCarpetaCompartida (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
if ((int) $pdo->query('SELECT COUNT(*) FROM facturas_xml WHERE almacen IS NOT NULL')->fetchColumn() > 0) {
    // La tarea de SharePoint recorre TODAS las filas de allá: con otras en la
    // base, lo que se cuenta aquí no sería solo lo de esta prueba.
    echo "SKIP: SinCarpetaCompartida (la base aislada ya tiene documentos en SharePoint de otra prueba)\n";
    exit(0);
}
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
if ($cuentaId <= 0) {
    echo "SKIP: SinCarpetaCompartida (sin cuentas de correo en la base aislada)\n";
    exit(0);
}
if ($real && ClienteGraph::configuracion() === null) {
    fwrite(STDERR, "FAIL: se pidió SharePoint de verdad y esta PC no tiene app/config/local.graph.php\n");
    exit(1);
}

// ── SharePoint: en memoria, o el de verdad ───────────────────────────────

$biblioteca = $real ? new TransporteCurl() : new GraphEnMemoria();

/** Anota cada llamada, para poder decir qué se subió y cuándo NO se salió a la red. */
$red = new class($biblioteca) implements TransporteGraph {
    public $llamadas = [];
    private $destino;
    public function __construct(TransporteGraph $destino) { $this->destino = $destino; }
    public function enviar(array $p)
    {
        $this->llamadas[] = $p['metodo'] . ' ' . $p['url'];
        return $this->destino->enviar($p);
    }
    /** Las rutas que recibieron contenido: desde la raíz o desde la carpeta de Nexo Fiscal. */
    public function subidas()
    {
        $rutas = [];
        foreach ($this->llamadas as $l) {
            if (preg_match('~^(PUT|POST) \S*/(?:root|items/[^/:]+):/(.+?):/(content|createUploadSession)~', $l, $m)) {
                $rutas[] = rawurldecode($m[2]);
            }
        }
        return $rutas;
    }
};

// En memoria: una biblioteca que ya se usaba, con una carpeta para Nexo
// Fiscal que creó el administrador, y el permiso de la aplicación SOLO sobre
// ella. El id de la carpeta es el que él usó para dar el permiso.
$carpetaNexo = 'Contabilidad/Nexo Fiscal';
if (!$real) {
    $biblioteca->crearCarpetaAMano('Contabilidad/Otras cosas de la empresa');
    $idCarpetaNexo = $biblioteca->crearCarpetaAMano($carpetaNexo);
    $biblioteca->soloCarpeta = $carpetaNexo;
}
$credenciales = $real
    ? ClienteGraph::configuracion()
    : ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd',
       'carpeta' => $carpetaNexo, 'carpeta_id' => $idCarpetaNexo];

// Contra SharePoint de verdad, dentro de una carpeta de prueba que se crea
// desde la raíz —como la crearía el administrador— y se borra al final.
$carpetaReal = $real ? trim(str_replace('\\', '/', (string) getenv('XMLCONCILIA_SHAREPOINT_CARPETA')), '/') : '';
$raizReal = null;
if ($carpetaReal !== '') {
    $raizReal = new AlmacenEnGraph(new ClienteGraph($credenciales, $red, ['registro' => null]));
    if ($raizReal->identidad($carpetaReal) !== null) {
        fwrite(STDERR, "FAIL: la carpeta de prueba {$carpetaReal} ya existe en SharePoint: elige una que no exista\n");
        exit(1);
    }
    $raizReal->asegurarCarpeta($carpetaReal);
    $credenciales['carpeta'] = $carpetaReal;
    $credenciales['carpeta_id'] = $raizReal->identidad($carpetaReal)['item_id'];
}
$llamadasDePreparacion = count($red->llamadas);
/** Un almacén recién hecho: lo que tendría otra petición, u otra PC. */
$nuevoAlmacen = function () use ($credenciales, $red, $real) {
    return new AlmacenEnGraph(new ClienteGraph($credenciales, $red,
        $real ? ['registro' => null] : ['carpeta_estado' => null, 'registro' => null]));
};
/** Lo que hay en la biblioteca en esa ruta, preguntado de cero. null si no está. */
$leer = function ($clave) use ($nuevoAlmacen) {
    $fp = $nuevoAlmacen()->abrir($clave);
    if ($fp === false) {
        return null;
    }
    $contenido = stream_get_contents($fp);
    fclose($fp);
    return $contenido;
};

$vigente = $nuevoAlmacen();
AlmacenDocumentos::olvidar();
AlmacenDocumentos::registrar('sharepoint', function () use (&$vigente) { return $vigente; });

// ── Esta PC: sin carpeta compartida ──────────────────────────────────────

RutaDocumento::fijarRaiz('');
$temporalesPc = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sin_carpeta_' . bin2hex(random_bytes(4));
mkdir($temporalesPc, 0777, true);
AlmacenDocumentos::fijarCarpetaTemporales($temporalesPc);
$respaldosLocales = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups'
    . DIRECTORY_SEPARATOR . RespaldoBase::CARPETA;
$habiaRespaldosLocales = is_dir($respaldosLocales);

$marca = strtoupper(bin2hex(random_bytes(3)));
$cedulaEmpresa = '3109991' . random_int(100, 999);
$nombreEmpresa = 'PRUEBA SIN CARPETA ' . $marca;
$cedulaProveedor = '3102' . random_int(100000, 999999);
$codigoProveedor = 'SC-' . $marca;
$carpetaPago = 'PAGO SIN CARPETA ' . $marca;
$erpClave = 'SC-' . $marca;

$sociedadId = 0;
$proveedorId = 0;
$proveedorCreado = false;
$bandejaId = 0;
$importacionId = 0;
$facturaId = 0;
$pagoId = 0;
$temporales = [];
$pendientesAntes = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM almacen_pendientes')->fetchColumn();
$deltaAntes = $pdo->query("SELECT * FROM almacen_delta WHERE almacen = 'sharepoint'")->fetch();

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute([$nombreEmpresa, $cedulaEmpresa]);
    $sociedadId = (int) $pdo->lastInsertId();

    $proveedores = new Proveedor();
    $proveedorCreado = $proveedores->findByRfc($cedulaProveedor) === null;
    $proveedorId = (int) $proveedores->obtenerOCrear($cedulaProveedor, 'PROVEEDOR NUBE ' . $marca);
    $pdo->prepare("INSERT INTO proveedor_codigo_erp (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
                   VALUES (?, ?, ?, ?, 'SIN CARPETA', 'manual')")
        ->execute([$sociedadId, $codigoProveedor, $proveedorId, $cedulaProveedor]);
    ProveedorCodigoErp::olvidarMapa();

    // ── 1. El correo llega y se captura ──────────────────────────────────
    $clave = '5062606260031011234560010000101000000' . random_int(100, 999) . '1' . random_int(10000000, 99999999);
    $numero = '0010000101' . str_pad((string) random_int(1, 99999999), 10, '0', STR_PAD_LEFT);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
        . '<Clave>' . $clave . '</Clave>'
        . '<NumeroConsecutivo>' . $numero . '</NumeroConsecutivo>'
        . '<FechaEmision>2026-06-07T13:09:00-06:00</FechaEmision>'
        . '<Emisor><Nombre>PROVEEDOR NUBE ' . $marca . '</Nombre><Identificacion><Numero>'
        . $cedulaProveedor . '</Numero></Identificacion></Emisor>'
        . '<Receptor><Nombre>' . $nombreEmpresa . '</Nombre><Identificacion><Numero>' . $cedulaEmpresa
        . '</Numero></Identificacion></Receptor>'
        . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
        . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
        . '</FacturaElectronica>';
    $pdf = "%PDF-1.4\n% la factura " . $marca . "\n%%EOF\n";
    $adjuntoXml = tempnam(sys_get_temp_dir(), 'sc_xml_');
    $adjuntoPdf = tempnam(sys_get_temp_dir(), 'sc_pdf_');
    file_put_contents($adjuntoXml, $xml);
    file_put_contents($adjuntoPdf, $pdf);
    $temporales = [$adjuntoXml, $adjuntoPdf];

    $ref = new ReflectionClass(CorreoController::class);
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);
    $bandeja = (new CorreoBandeja())->setSociedad($sociedadId);
    $captura = $procesar->invoke($ref->newInstanceWithoutConstructor(), [
        'xmls' => [['ruta' => $adjuntoXml, 'nombre' => $clave . '.xml']],
        'pdfs' => [['ruta' => $adjuntoPdf, 'nombre' => 'FE_' . $clave . '.pdf']],
        'clave' => 'sc-' . $clave,
        'remitente' => 'proveedor@example.test',
        'asunto' => 'Factura electrónica',
        'fecha' => '2026-06-07 13:09:00',
    ], $bandeja, new stdClass(), $cuentaId, ['FE'], '', 0,
       [['id' => $sociedadId, 'cedula' => $cedulaEmpresa]], 'automatica');

    $bandejaId = (int) ($captura['filas'][0]['id'] ?? 0);
    paso($bandejaId > 0 && ($captura['filas'][0]['estado'] ?? '') === 'pendiente',
        'sin carpeta compartida, el comprobante entra igual a la bandeja: ' . json_encode($captura['errores'] ?? []));
    $fila = $bandeja->getByIds([$bandejaId], 'pendiente')[0] ?? [];
    $rutaBandejaXml = (string) ($fila['archivo_xml'] ?? '');
    $rutaBandejaPdf = (string) ($fila['archivo_pdf'] ?? '');
    paso(strpos($rutaBandejaXml, 'sharepoint:_TRABAJO/BANDEJA/xml/') === 0
        && strpos($rutaBandejaPdf, 'sharepoint:_TRABAJO/BANDEJA/pdf/') === 0,
        'la bandeja está en SharePoint: ' . $rutaBandejaXml . ' · ' . $rutaBandejaPdf);
    paso($leer(substr($rutaBandejaXml, 11)) === $xml && $leer(substr($rutaBandejaPdf, 11)) === $pdf,
        'y el XML y el PDF están ahí, enteros');
    paso(!is_file($adjuntoXml) && !is_file($adjuntoPdf),
        'los adjuntos bajados del correo no quedan en esta máquina');

    // ── 2. Se importa ────────────────────────────────────────────────────
    $cola = new InvoiceImportQueue();
    $inicio = $cola->iniciarImportacion(['archivo_origen' => 'SIN CARPETA', 'total_esperado' => 1,
        'tipo_documento' => 'FE', 'cedula_receptor' => $cedulaEmpresa, 'sociedad_id' => $sociedadId]);
    $importacionId = (int) $inicio['importacion_id'];
    $cola->agregarArchivosLocales($importacionId, [[
        'ruta' => $rutaBandejaXml, 'nombre' => $clave . '.xml', 'ruta_pdf' => $rutaBandejaPdf,
        'correo_cuenta_id' => $cuentaId, 'correo_uid' => 4343, 'correo_carpeta' => 'INBOX',
    ]]);
    $lote = $cola->procesarBatch($importacionId, 5);
    $procesado = $lote['processed'][0] ?? [];
    paso(($procesado['estado'] ?? '') === 'importado',
        'sin carpeta compartida, la cola lo importa: ' . json_encode($procesado));
    $facturaId = (int) ($procesado['factura_id'] ?? 0);

    $cruda = $pdo->query('SELECT ruta_xml, ruta_pdf, almacen, item_xml_id, item_pdf_id
                          FROM facturas_xml WHERE id = ' . $facturaId)->fetch() ?: [];
    $mesSistema = 'SISTEMA/' . $nombreEmpresa . '/2026/06 JUNIO/Facturas/';
    paso(($cruda['almacen'] ?? '') === 'sharepoint' && !empty($cruda['item_xml_id']) && !empty($cruda['item_pdf_id']),
        'la fila dice SharePoint y trae los ids del XML y del PDF: ' . json_encode($cruda));
    paso(strpos((string) ($cruda['ruta_xml'] ?? ''), 'sharepoint:' . $mesSistema) === 0
        && strpos((string) ($cruda['ruta_pdf'] ?? ''), 'sharepoint:' . $mesSistema) === 0,
        'el XML y el PDF originales quedan en SharePoint, en la carpeta de su empresa y su mes: '
        . json_encode($cruda));
    paso($leer(substr((string) $cruda['ruta_xml'], 11)) === $xml
        && $leer(substr((string) $cruda['ruta_pdf'], 11)) === $pdf,
        'y son exactamente los que llegaron');
    paso($leer(substr($rutaBandejaXml, 11)) === null && $leer(substr($rutaBandejaPdf, 11)) === null,
        'lo de la bandeja se borra cuando la base confirmó');
    paso($nuevoAlmacen()->identidad('_TRABAJO/IMPORTACIONES/importacion_' . $importacionId) === null,
        'y la cola no dejó una carpeta vacía en la biblioteca: tomó los archivos de la bandeja');

    // ── 3. La carpeta del pago ───────────────────────────────────────────
    $pdo->prepare('INSERT INTO pagos (sociedad_id, nombre, carpeta_pago) VALUES (?, ?, ?)')
        ->execute([$sociedadId, $carpetaPago, $carpetaPago]);
    $pagoId = (int) $pdo->lastInsertId();
    $pdo->exec('UPDATE facturas_xml SET pago_id = ' . $pagoId . ' WHERE id = ' . $facturaId);
    $pdo->prepare("INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre,
                                             factura_xml_id, estado_respaldo, pago_id)
                   VALUES (?, ?, ?, 'SIN CARPETA', ?, 'respaldada', ?)")
        ->execute([$erpClave, $sociedadId, $codigoProveedor, $facturaId, $pagoId]);

    paso(in_array($carpetaPago, (new Pago())->setSociedad($sociedadId)->carpetasUsadas(), true),
        'el selector de carpeta de Pagos la ofrece sin recorrer la biblioteca');

    Model::olvidarMemoria();
    $vigente = $nuevoAlmacen();
    $vuelta = (new ReconciliadorGraph($vigente))->ejecutar(false);
    paso((int) $vuelta['copias_pago'] === 1 && (int) $vuelta['errores_copia_pago'] === 0,
        'la tarea programada arma la carpeta del pago: ' . json_encode(array_diff_key($vuelta, ['operaciones' => 1])));
    paso(empty($vuelta['sin_enlace_de_cambios']) && empty($vuelta['errores']),
        'y lee los cambios de la carpeta, no de la biblioteca entera: ' . json_encode(array_diff_key($vuelta, ['operaciones' => 1])));
    $copiaXml = 'PAGOS/' . $carpetaPago . '/' . basename(str_replace('\\', '/', (string) $cruda['ruta_xml']));
    $copiaPdf = 'PAGOS/' . $carpetaPago . '/' . basename(str_replace('\\', '/', (string) $cruda['ruta_pdf']));
    paso($leer($copiaXml) === $xml && $leer($copiaPdf) === $pdf,
        'la copia del XML y del PDF está en SharePoint, en PAGOS/' . $carpetaPago);
    paso($leer(substr((string) $cruda['ruta_xml'], 11)) === $xml,
        'y el original sigue en su carpeta: el pago recibe una copia');

    // ── 4. Otra PC, también sin carpeta, lo abre ─────────────────────────
    // Otra PC es otro proceso: nada recordado, ni el almacén ni las filas.
    Model::olvidarMemoria();
    $otraPc = $nuevoAlmacen();
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::registrar('sharepoint', function () use ($otraPc) { return $otraPc; });
    RutaDocumento::fijarRaiz('');
    $documento = (new Factura())->setSociedad($sociedadId)->findById($facturaId);
    foreach (['ruta_xml' => $xml, 'ruta_pdf' => $pdf] as $campo => $esperado) {
        $fp = RutaDocumento::abrirParaLeer((string) ($documento[$campo] ?? ''));
        paso($fp !== false && stream_get_contents($fp) === $esperado,
            'otra PC abre el ' . strtoupper(substr($campo, 5)) . ' desde SharePoint, sin carpeta compartida');
        if (is_resource($fp)) {
            fclose($fp);
        }
    }
    $zip = $temporalesPc . DIRECTORY_SEPARATOR . 'paquete.zip';
    $resumen = PaqueteDocumentos::crear([[
        'archivo_xml' => $documento['ruta_xml'], 'archivo_pdf' => $documento['ruta_pdf'],
        'numero_corto' => '1', 'tipo_doc' => 'FE',
    ]], $zip);
    paso($resumen['xml'] === 1 && $resumen['pdf'] === 1,
        'y arma el ZIP con los dos, bajados a temporales de esa PC: ' . json_encode($resumen));
    @unlink($zip);
    paso(glob(AlmacenDocumentos::carpetaTemporales() . DIRECTORY_SEPARATOR . 'doc_*') === [],
        'los temporales se devuelven y no quedan en la PC');

    // ── 5. A SharePoint solo van XML y PDF ───────────────────────────────
    $subidas = $red->subidas();
    $ajenas = array_values(array_filter($subidas, function ($r) { return !AlmacenEnGraph::esComprobante($r); }));
    paso($subidas !== [] && $ajenas === [],
        'en todo el recorrido solo se subieron XML y PDF: ' . json_encode($ajenas ?: $subidas));
    paso(!preg_grep('~LEEME~', $subidas), 'ni la nota LEEME, que es de la carpeta compartida');
    if ($carpetaReal !== '') {
        paso(!preg_grep('~/drives/[^/]+/root~', array_slice($red->llamadas, $llamadasDePreparacion)),
            'en SharePoint de verdad, con carpeta, nada se dirigió a la raíz de la biblioteca');
    }
    if (!$real) {
        paso(!preg_grep('~/drives/[^/]+/root~', $red->llamadas),
            'nada se dirigió a la raíz de la biblioteca, que con este permiso contesta 403');
        paso($biblioteca->buscar($carpetaNexo . '/SISTEMA/' . $nombreEmpresa) !== null
            && $biblioteca->buscar($carpetaNexo . '/PAGOS/' . $carpetaPago) !== null
            && $biblioteca->buscar('SISTEMA') === null && $biblioteca->buscar('PAGOS') === null,
            'todo quedó dentro de la carpeta de Nexo Fiscal, y nada suelto en la biblioteca');
    }

    $local = tempnam(sys_get_temp_dir(), 'sc_otro_');
    file_put_contents($local, 'no es un comprobante');
    $temporales[] = $local;
    $antes = count($red->llamadas);
    foreach (['SISTEMA/' . $nombreEmpresa . '/LEEME.txt', '_TRABAJO/RESPALDOS/base.sql.gz',
              'PAGOS/' . $carpetaPago . '/listado.xlsx', '_TRABAJO/IMPORTACIONES/lote.zip',
              'PAGOS/' . $carpetaPago . '/pago.csv', 'SISTEMA/sin_extension'] as $ajena) {
        $rechazada = false;
        try {
            $vigente->escribir($ajena, $local);
        } catch (InvalidArgumentException $e) {
            $rechazada = strpos($e->getMessage(), 'solo van XML y PDF') !== false;
        }
        paso($rechazada, 'SharePoint rechaza ' . basename($ajena) . ': se queda en esta computadora');
    }
    paso(count($red->llamadas) === $antes, 'y lo rechaza sin salir a la red');
    paso(AlmacenEnGraph::esComprobante('FE_X.XML') && AlmacenEnGraph::esComprobante('sharepoint:A/FE_X.Pdf'),
        'la extensión se mira sin importar mayúsculas');

    // ── 6. Lo que no es XML ni PDF se queda en esta computadora ─────────
    $carpetaRespaldos = RespaldoBase::carpetaDestino();
    paso(strcasecmp($carpetaRespaldos, $respaldosLocales) === 0 && is_dir($carpetaRespaldos),
        'los respaldos de la base quedan en esta PC: ' . $carpetaRespaldos);

    // ── 7. Una PC sin SharePoint frena con un motivo ─────────────────────
    AlmacenDocumentos::olvidar();
    $xmlSinNube = tempnam(sys_get_temp_dir(), 'sc_sin_nube_');
    file_put_contents($xmlSinNube, $xml);
    $temporales[] = $xmlSinNube;
    $motivo = '';
    try {
        (new DocumentoArchivo())->paraSociedad($sociedadId, $nombreEmpresa)
            ->archivar($xmlSinNube, null, ['tipo_documento' => 'FE', 'fecha_emision' => '2026-06-07',
                'numero_factura_asistente' => '1'], 'PROVEEDOR');
    } catch (RuntimeException $e) {
        $motivo = $e->getMessage();
    }
    paso($motivo === AlmacenDocumentos::SIN_SHAREPOINT,
        'sin SharePoint en esta PC, archivar se frena y dice qué hacer: ' . $motivo);
    paso(!is_dir(getcwd() . DIRECTORY_SEPARATOR . DocumentoArchivo::CARPETA_SISTEMA),
        'sin escribir nada por una ruta relativa');
    $motivo = '';
    try {
        RutaDocumento::carpetaTrabajo('BANDEJA/xml', $sociedadId);
    } catch (RuntimeException $e) {
        $motivo = $e->getMessage();
    }
    paso($motivo !== '', 'la bandeja tampoco se inventa una carpeta de disco');
    paso((new DocumentoArchivo())->carpetasPago() === [],
        'el selector de carpetas de pago queda vacío en vez de fallar');
} catch (Throwable $e) {
    $fallas[] = 'se cortó el recorrido: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    // SharePoint: lo subido. En memoria no hace falta; en la de verdad, sí.
    if ($raizReal !== null) {
        // La carpeta de prueba entera, con todo lo de adentro: la creó esta prueba.
        try {
            $raizReal->borrar($carpetaReal);
            $primera = strtok($carpetaReal, '/');
            if ($primera !== $carpetaReal && $raizReal->identidad($primera) !== null
                && $raizReal->listarCarpeta($primera) === []) {
                $raizReal->borrar($primera);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'AVISO: no se pudo borrar ' . $carpetaReal . ' de SharePoint: ' . $e->getMessage() . "\n");
        }
    } elseif ($real) {
        $limpiar = $nuevoAlmacen();
        foreach (['PAGOS/' . $carpetaPago, 'SISTEMA/' . $nombreEmpresa] as $carpeta) {
            try {
                $limpiar->borrar($carpeta);
            } catch (Throwable $e) {
                fwrite(STDERR, 'AVISO: no se pudo borrar ' . $carpeta . ' de SharePoint: ' . $e->getMessage() . "\n");
            }
        }
        // Las carpetas del camino, solo si quedaron vacías: pueden tener cosas de otros.
        foreach (['PAGOS', 'SISTEMA', '_TRABAJO/BANDEJA/xml/' . date('Y-m'), '_TRABAJO/BANDEJA/pdf/' . date('Y-m'),
                  '_TRABAJO/BANDEJA/xml', '_TRABAJO/BANDEJA/pdf', '_TRABAJO/BANDEJA',
                  '_TRABAJO/IMPORTACIONES/importacion_' . $importacionId, '_TRABAJO/IMPORTACIONES', '_TRABAJO'] as $carpeta) {
            try {
                if ($limpiar->identidad($carpeta) !== null && $limpiar->listarCarpeta($carpeta) === []) {
                    $limpiar->borrar($carpeta);
                }
            } catch (Throwable $e) {
            }
        }
    }

    // La base, como estaba.
    if ($facturaId > 0) {
        $pdo->exec('DELETE FROM almacen_copias_pago WHERE factura_id = ' . $facturaId);
        $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . $facturaId);
    }
    $pdo->prepare('DELETE FROM facturas_erp WHERE clave = ?')->execute([$erpClave]);
    if ($pagoId > 0) {
        $pdo->exec('DELETE FROM pagos WHERE id = ' . $pagoId);
    }
    if ($importacionId > 0) {
        $pdo->exec('DELETE FROM importacion_items WHERE importacion_id = ' . $importacionId);
        $pdo->exec('DELETE FROM importaciones WHERE id = ' . $importacionId);
    }
    if ($bandejaId > 0) {
        $pdo->exec('DELETE FROM correo_bandeja WHERE id = ' . $bandejaId);
    }
    $pdo->exec('DELETE FROM almacen_pendientes WHERE id > ' . $pendientesAntes);
    $pdo->exec("DELETE FROM almacen_delta WHERE almacen = 'sharepoint'");
    if ($deltaAntes) {
        $pdo->prepare('INSERT INTO almacen_delta (' . implode(',', array_keys($deltaAntes)) . ') VALUES ('
            . implode(',', array_fill(0, count($deltaAntes), '?')) . ')')->execute(array_values($deltaAntes));
    }
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute([$codigoProveedor]);
    if ($proveedorCreado && $proveedorId > 0) {
        $pdo->exec('DELETE FROM proveedores WHERE id = ' . $proveedorId);
    }
    if ($sociedadId > 0) {
        $pdo->exec('DELETE FROM sociedades WHERE id = ' . (int) $sociedadId);
    }
    ProveedorCodigoErp::olvidarMapa();
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    RutaDocumento::olvidarRaiz();
    foreach ($temporales as $t) {
        @unlink($t);
    }
    foreach (glob($temporalesPc . DIRECTORY_SEPARATOR . '*') ?: [] as $t) {
        @unlink($t);
    }
    @rmdir($temporalesPc);
    if (!$habiaRespaldosLocales) {
        @rmdir($respaldosLocales);
    }
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo 'OK: sin carpeta compartida, los XML y PDF van a SharePoint'
    . ($real ? ' (la biblioteca de verdad)' : ', a una sola carpeta con permiso solo sobre ella,')
    . " y lo demás se queda en la PC\n";
