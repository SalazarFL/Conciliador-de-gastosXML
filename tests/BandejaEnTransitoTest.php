<?php
/**
 * Lo que está de paso se queda en esta computadora; lo que espera, no.
 *
 * Un comprobante que llega por correo con la entrada automática encendida se
 * captura y se archiva en la misma corrida: su paso por la Bandeja dura dos
 * segundos y no lo ve nadie más. Mandarlo a SharePoint para bajarlo acto
 * seguido, archivarlo y borrarlo eran seis viajes de red por comprobante —lo
 * que hacía sentir "pegada" cada carga— y dejaba el archivo suelto allá arriba
 * si la corrida se cortaba en el medio. El 19/09/2026 quedaron ocho así.
 *
 * Pero en cuanto el documento pasa a ESPERAR en la Bandeja —Hacienda lo
 * rechazó, viene a nombre de otra cédula, no se resolvió su empresa, falló al
 * importar— deja de ser cosa de esta máquina: lo va a mirar una persona que
 * puede estar en otra, y desde otra no hay forma de abrir un archivo de este
 * disco. Ahí sí sube.
 *
 * Esta prueba recorre los dos caminos con el código de verdad, contra la base
 * aislada y la biblioteca en memoria. Deja la base como la encontró.
 *
 * Uso: php tests/BandejaEnTransitoTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/Proveedor.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/helpers/EntradaAutomatica.php';
require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
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
    echo "SKIP: BandejaEnTransito (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    echo "SKIP: BandejaEnTransito (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
if ($cuentaId <= 0) {
    echo "SKIP: BandejaEnTransito (sin cuentas de correo en la base aislada)\n";
    exit(0);
}

$marca = strtoupper(bin2hex(random_bytes(3)));
$empresa = 'TRANSITO ' . $marca;
$cedulaEmpresa = '3109994' . random_int(100, 999);
$cedulaProveedor = '3102' . random_int(100000, 999999);
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transito_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');

$biblioteca = new GraphEnMemoria();
$almacenGraph = new AlmacenEnGraph(new ClienteGraph(
    ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
    $biblioteca, ['carpeta_estado' => null, 'registro' => null]
));
AlmacenDocumentos::olvidar();
AlmacenDocumentos::registrar('sharepoint', function () use ($almacenGraph) { return $almacenGraph; });

$sociedadId = 0;
$proveedorCreado = false;
$bandejaIds = [];
$facturaIds = [];
$temporales = [];

/** Los XML/PDF que esta computadora tenga en su zona de paso ahora mismo. */
$enEstaPC = function () {
    $raizLocal = RutaDocumento::raizTrabajoLocal();
    if (!is_dir($raizLocal)) {
        return [];
    }
    $encontrados = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raizLocal, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $archivo) {
        if ($archivo->isFile()) {
            $encontrados[] = $archivo->getPathname();
        }
    }
    return $encontrados;
};

/** Las subidas a la bandeja de la biblioteca (lo que ya no debería pasar). */
$subidasABandeja = function () use ($biblioteca) {
    return array_values(preg_grep('~^PUT .*_TRABAJO/BANDEJA.*/content~', $biblioteca->registro));
};

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute([$empresa, $cedulaEmpresa]);
    $sociedadId = (int) $pdo->lastInsertId();
    $proveedores = new Proveedor();
    $proveedorCreado = $proveedores->findByRfc($cedulaProveedor) === null;
    $proveedorId = (int) $proveedores->obtenerOCrear($cedulaProveedor, 'PROVEEDOR TRANSITO ' . $marca);
    // Sin lista de proveedores cargada no se importa nada: el importador se
    // niega a enganchar a ciegas.
    $pdo->prepare("INSERT INTO proveedor_codigo_erp (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
                   VALUES (?, ?, ?, ?, 'TRANSITO', 'manual')")
        ->execute([$sociedadId, 'TRA-' . $marca, $proveedorId, $cedulaProveedor]);
    ProveedorCodigoErp::olvidarMapa();
    Model::olvidarMemoria();

    $sociedades = [['id' => $sociedadId, 'cedula' => $cedulaEmpresa]];

    /** Un correo con su XML y su PDF, como lo entrega MailFetcher. */
    $mensaje = function ($numero, $cedulaReceptor, $rechazada = false) use (
        $cedulaProveedor, $empresa, $marca, &$temporales
    ) {
        $clave = '50619092600310' . substr($cedulaProveedor, 0, 8) . '00100001010000' . $numero
            . '1' . random_int(10000000, 99999999);
        $consecutivo = '00100001010000' . $numero;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
            . '<Clave>' . $clave . '</Clave><NumeroConsecutivo>' . $consecutivo . '</NumeroConsecutivo>'
            . '<FechaEmision>2026-09-19T10:00:00-06:00</FechaEmision>'
            . '<Emisor><Nombre>PROVEEDOR TRANSITO ' . $marca . '</Nombre><Identificacion><Numero>'
            . $cedulaProveedor . '</Numero></Identificacion></Emisor>'
            . '<Receptor><Nombre>' . $empresa . '</Nombre><Identificacion><Numero>' . $cedulaReceptor
            . '</Numero></Identificacion></Receptor>'
            . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
            . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
            . '</FacturaElectronica>';

        $x = tempnam(sys_get_temp_dir(), 'tra_x_');
        $p = tempnam(sys_get_temp_dir(), 'tra_p_');
        file_put_contents($x, $xml);
        file_put_contents($p, '%PDF-1.4 transito ' . $numero);
        $temporales[] = $x;
        $temporales[] = $p;

        $xmls = [['ruta' => $x, 'nombre' => $clave . '.xml']];
        if ($rechazada) {
            // El mensaje de Hacienda que acompaña al comprobante: es lo que
            // hace que la fila se quede esperando en la Bandeja.
            $r = tempnam(sys_get_temp_dir(), 'tra_r_');
            file_put_contents($r,
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda">'
                . '<Clave>' . $clave . '</Clave><Mensaje>3</Mensaje>'
                . '<DetalleMensaje>Rechazado</DetalleMensaje></MensajeHacienda>');
            $temporales[] = $r;
            $xmls[] = ['ruta' => $r, 'nombre' => 'MH_' . $clave . '.xml'];
        }

        return ['xmls' => $xmls,
                'pdfs' => [['ruta' => $p, 'nombre' => 'FE_' . $clave . '.pdf']],
                'clave' => 'tra-' . $clave, 'remitente' => 'p@example.test',
                'asunto' => 'Factura ' . $numero, 'fecha' => '2026-09-19 10:00:00'];
    };

    $correo = (new ReflectionClass(CorreoController::class))->newInstanceWithoutConstructor();
    $bandeja = (new CorreoBandeja())->setSociedad(0);
    $entrada = new EntradaAutomatica($bandeja, [$sociedadId => ['id' => $sociedadId, 'cedula' => $cedulaEmpresa]]);

    // ── 1. Lo limpio entra solo, sin tocar la bandeja de SharePoint ──────
    $sueltosAntes = count($enEstaPC());
    $r = $correo->capturarMensajeAutomatico(
        $mensaje('100001', $cedulaEmpresa), $cuentaId, $sociedades, $entrada,
        ['carpeta' => 'INBOX', 'uidvalidity' => 1, 'uid' => 1, 'fecha_correo' => '2026-09-19 10:00:00']
    );
    foreach (($r['filas'] ?? []) as $ref) { $bandejaIds[] = (int) $ref['id']; }
    $ingresados = (int) ($r['entrada']['ingresados'] ?? 0);
    paso($ingresados === 1,
        'la factura limpia entra sola: ' . json_encode([$r['entrada'] ?? null, $r['errores'] ?? []]));
    paso($subidasABandeja() === [],
        'y su paso por la Bandeja no sube nada a SharePoint: ' . json_encode($subidasABandeja()));

    $filaBandeja = $bandeja->getByIds($bandejaIds)[0] ?? [];
    paso(($filaBandeja['estado'] ?? '') === 'importada', 'la fila queda como historial de por dónde entró');
    paso(count($enEstaPC()) === $sueltosAntes,
        'y no deja archivos sueltos en esta computadora: ' . json_encode($enEstaPC()));

    $nueva = $pdo->query('SELECT * FROM facturas_xml WHERE sociedad_id = ' . $sociedadId
        . ' ORDER BY id DESC LIMIT 1')->fetch() ?: [];
    $facturaIds[] = (int) ($nueva['id'] ?? 0);
    paso(strpos((string) ($nueva['ruta_xml'] ?? ''), 'sharepoint:SISTEMA/') === 0
        && strpos((string) ($nueva['ruta_pdf'] ?? ''), 'sharepoint:SISTEMA/') === 0,
        'el comprobante SÍ queda archivado en SharePoint: ' . json_encode($nueva['ruta_xml'] ?? null));

    // Y se paga lo justo: subir el XML, subir el PDF y nada más.
    $subidas = preg_grep('~^PUT ~', $biblioteca->registro);
    paso(count($subidas) === 2, 'con dos subidas en total, no seis: ' . json_encode(array_values($subidas)));
    // El tope de red por comprobante. Antes eran once llamadas: subir el par a
    // la bandeja, preguntar por él, bajarlo, archivarlo y borrar las copias.
    paso(count($biblioteca->registro) <= 4,
        'y ' . count($biblioteca->registro) . ' llamadas a la biblioteca en total, que son más de 4: '
        . json_encode($biblioteca->registro));

    // ── 2. Lo que se queda esperando sí sube ─────────────────────────────
    $antes = count($bandejaIds);
    $r = $correo->capturarMensajeAutomatico(
        $mensaje('100002', $cedulaEmpresa, true), $cuentaId, $sociedades, $entrada,
        ['carpeta' => 'INBOX', 'uidvalidity' => 1, 'uid' => 2, 'fecha_correo' => '2026-09-19 10:05:00']
    );
    foreach (($r['filas'] ?? []) as $ref) { $bandejaIds[] = (int) $ref['id']; }
    $idRetenida = (int) ($bandejaIds[$antes] ?? 0);
    $retenida = $bandeja->getByIds([$idRetenida])[0] ?? [];
    paso((int) ($r['entrada']['retenidos'] ?? 0) === 1 && ($retenida['estado'] ?? '') === 'rechazada',
        'la rechazada por Hacienda se queda en la Bandeja: ' . json_encode([$r['entrada'] ?? null, $retenida['estado'] ?? null]));
    paso(strpos((string) ($retenida['archivo_xml'] ?? ''), 'sharepoint:_TRABAJO/BANDEJA/') === 0,
        'y su XML sube, para que lo abra quien la mire desde otra computadora: '
        . json_encode($retenida['archivo_xml'] ?? null));
    paso(RutaDocumento::existe((string) $retenida['archivo_xml']),
        'y el archivo está de verdad donde dice la fila');
    paso(count($enEstaPC()) === $sueltosAntes,
        'sin dejar copia en esta computadora: ' . json_encode($enEstaPC()));

    // ── 3. Lo que quedó a medias de una corrida cortada ──────────────────
    // Se simula lo único que puede dejarlo así: la fila pendiente apuntando a
    // un archivo de esta PC, porque el proceso murió antes de archivarlo.
    $suelto = RutaDocumento::carpetaTrabajoLocal('BANDEJA/xml/2026-09')
        . DIRECTORY_SEPARATOR . uniqid('correo_', true) . '__cortado_' . $marca . '.xml';
    file_put_contents($suelto, '<FE>cortado ' . $marca . '</FE>');
    $pdo->prepare("INSERT INTO correo_bandeja (cuenta_id, sociedad_id, uid_correo, asunto, archivo_xml,
                       numero_corto, tipo_doc, estado, origen)
                   VALUES (?, ?, ?, ?, ?, ?, 'FE', 'pendiente', 'automatica')")
        ->execute([$cuentaId, $sociedadId, 'tra-cortado-' . $marca, 'Cortado ' . $marca,
                   $suelto, substr($marca, 0, 8)]);
    $idCortada = (int) $pdo->lastInsertId();
    $bandejaIds[] = $idCortada;

    $rescatadas = $entrada->rescatarLoQueQuedoAqui();
    $cortada = $bandeja->getByIds([$idCortada])[0] ?? [];
    paso($rescatadas >= 1 && strpos((string) ($cortada['archivo_xml'] ?? ''), 'sharepoint:_TRABAJO/BANDEJA/') === 0,
        'la corrida siguiente lo sube y reapunta la fila: '
        . json_encode([$rescatadas, $cortada['archivo_xml'] ?? null]));
    paso(!is_file($suelto), 'y no queda duplicado en esta computadora');
} catch (Throwable $e) {
    $fallas[] = 'se cortó: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── Limpieza: la base queda como estaba ─────────────────────────────────
try {
    if ($facturaIds) {
        $pdo->exec('DELETE FROM facturas_xml WHERE id IN (' . implode(',', array_map('intval', $facturaIds)) . ')');
    }
    if ($bandejaIds) {
        $pdo->exec('DELETE FROM correo_bandeja WHERE id IN (' . implode(',', array_map('intval', $bandejaIds)) . ')');
    }
    if ($sociedadId) {
        $pdo->exec('DELETE FROM sociedades WHERE id = ' . $sociedadId);
    }
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute(['TRA-' . $marca]);
    if ($proveedorCreado) {
        $pdo->prepare('DELETE FROM proveedores WHERE rfc = ?')->execute([$cedulaProveedor]);
    }
} catch (Throwable $e) {
    $fallas[] = 'no se pudo limpiar la base: ' . $e->getMessage();
}
foreach ($temporales as $t) { @unlink($t); }
if (isset($suelto)) { @unlink($suelto); }
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
    echo "FALLA BandejaEnTransito:\n";
    foreach ($fallas as $f) { echo '  - ' . $f . "\n"; }
    exit(1);
}
echo "OK BandejaEnTransito: lo de paso se queda aquí; lo que espera, sube.\n";
exit(0);
