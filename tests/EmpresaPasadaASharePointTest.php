<?php
/**
 * Lo que pasó el 19/09/2026, para que no vuelva a pasar.
 *
 * Se pasó a SharePoint, desde Configuración, una empresa con 14.043
 * documentos en la carpeta compartida. En los minutos siguientes:
 *
 *   - El organizador pedía las filas de disco SIN la columna `almacen`; la
 *     ruta se resolvía con el ajuste de la empresa —ya SharePoint—, no la
 *     encontraba en el disco y marcó las 14.043 "sin archivo".
 *   - Al salir a buscarlas por su contenido, tomó la primera copia que
 *     encontró, y "PAGOS" va antes que "SISTEMA": repuntó 1.580 documentos a
 *     las copias de entrega de la carpeta del pago.
 *   - Un comprobante se cargó con el botón "Procesar este correo", que no se
 *     bloqueaba mientras trabajaba: seis clics, seis subidas del mismo XML.
 *   - Cada guardado le preguntaba a SharePoint, con una llamada que falla,
 *     si la biblioteca tenía la columna del hash, y bajaba el PDF aunque la
 *     factura ya estuviera archivada.
 *
 * Esta prueba recorre cada una con el código de verdad, contra la base
 * aislada y la biblioteca en memoria. Deja la base como la encontró.
 *
 * Uso: php tests/EmpresaPasadaASharePointTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';
require_once __DIR__ . '/../app/models/CorreoBandeja.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/Proveedor.php';
require_once __DIR__ . '/../app/models/ProveedorCodigoErp.php';
require_once __DIR__ . '/../app/helpers/OrganizadorDocumentos.php';
require_once __DIR__ . '/../app/helpers/XmlDocumentImporter.php';
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
    echo "SKIP: EmpresaPasadaASharePoint (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    echo "SKIP: EmpresaPasadaASharePoint (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
if ((int) $pdo->query('SELECT COUNT(*) FROM facturas_xml')->fetchColumn() > 0) {
    // El organizador recorre TODAS las filas de disco de la base: con otras,
    // lo que se cuenta aquí no sería solo lo de esta prueba.
    echo "SKIP: EmpresaPasadaASharePoint (la base aislada tiene facturas de otra prueba)\n";
    exit(0);
}
$cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
if ($cuentaId <= 0) {
    echo "SKIP: EmpresaPasadaASharePoint (sin cuentas de correo en la base aislada)\n";
    exit(0);
}

/** Otra conexión a la base: lo que sería otra PC, o otra pestaña. */
$otraConexion = function () use ($config) {
    return new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};

$marca = strtoupper(bin2hex(random_bytes(3)));
$empresa = 'HISTORIA ' . $marca;
$cedulaEmpresa = '3109993' . random_int(100, 999);
$cedulaProveedor = '3102' . random_int(100000, 999999);
$codigoProveedor = 'HIS-' . $marca;
$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'historia_' . bin2hex(random_bytes(4));
$estadoGraph = $raiz . DIRECTORY_SEPARATOR . '_estado_graph';
mkdir($estadoGraph, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');

$biblioteca = new GraphEnMemoria();
$nuevoAlmacen = function (array $opciones = []) use ($biblioteca) {
    return new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca, $opciones + ['carpeta_estado' => null, 'registro' => null]
    ));
};
$vigente = $nuevoAlmacen();
AlmacenDocumentos::olvidar();
AlmacenDocumentos::registrar('sharepoint', function () use (&$vigente) { return $vigente; });

$sociedadId = 0;
$facturaIds = [];
$proveedorId = 0;
$proveedorCreado = false;
$bandejaIds = [];
$temporales = [];

try {
    $pdo->prepare("INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)")
        ->execute([$empresa, $cedulaEmpresa]);
    $sociedadId = (int) $pdo->lastInsertId();
    $proveedores = new Proveedor();
    $proveedorCreado = $proveedores->findByRfc($cedulaProveedor) === null;
    $proveedorId = (int) $proveedores->obtenerOCrear($cedulaProveedor, 'PROVEEDOR HISTORIA ' . $marca);
    $pdo->prepare("INSERT INTO proveedor_codigo_erp (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
                   VALUES (?, ?, ?, ?, 'HISTORIA', 'manual')")
        ->execute([$sociedadId, $codigoProveedor, $proveedorId, $cedulaProveedor]);
    ProveedorCodigoErp::olvidarMapa();

    // ── El escenario: tres documentos archivados en disco, dos en un pago ──
    $mes = 'SISTEMA/' . $empresa . '/2026/06 JUNIO/Facturas';
    $pago = 'PAGOS/Pago ' . $marca;
    $escribir = function ($relativa, $contenido) use ($raiz) {
        $absoluta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
        if (!is_dir(dirname($absoluta))) {
            mkdir(dirname($absoluta), 0777, true);
        }
        file_put_contents($absoluta, $contenido);
        return $absoluta;
    };
    foreach (['A', 'B', 'C'] as $letra) {
        $xml = '<FE>' . $letra . $marca . '</FE>';
        $pdf = '%PDF ' . $letra . $marca;
        $escribir("$mes/FE_$letra.xml", $xml);
        $escribir("$mes/FE_$letra.pdf", $pdf);
        if ($letra !== 'C') {
            // La carpeta del pago: COPIAS, con el mismo contenido.
            $escribir("$pago/FE_$letra.xml", $xml);
            $escribir("$pago/FE_$letra.pdf", $pdf);
        }
        $pdo->prepare("INSERT INTO facturas_xml
                (consecutivo_completo, numero_factura_asistente, proveedor_id, sociedad_id, fecha_emision,
                 tipo_documento, ruta_xml, archivo_xml, ruta_pdf, archivo_pdf, hash_xml, hash_pdf, estado_pdf)
                VALUES (?, ?, ?, ?, '2026-06-07', 'FE', ?, ?, ?, ?, ?, ?, 'disponible')")
            ->execute(['HIS' . $marca . $letra, substr($letra . $marca, 0, 8), $proveedorId, $sociedadId,
                "$mes/FE_$letra.xml", "FE_$letra.xml", "$mes/FE_$letra.pdf", "FE_$letra.pdf",
                hash('sha256', $xml), hash('sha256', $pdf)]);
        $facturaIds[$letra] = (int) $pdo->lastInsertId();
    }
    $fila = function ($id) use ($pdo) {
        return $pdo->query('SELECT * FROM facturas_xml WHERE id = ' . (int) $id)->fetch() ?: [];
    };

    // ── 1. Lo nuevo va a SharePoint, lo de antes sigue en disco ─────────
    // Aquel día fue una empresa pasada desde Configuración; desde el
    // 27/09/2026 es la regla para todas. La historia en disco es la misma.
    Model::olvidarMemoria();

    $vistas = (new Factura())->setSociedad(0)->getParaOrganizarArchivos(array_values($facturaIds));
    $deDisco = array_filter($vistas, function ($f) use ($raiz) {
        return strpos((string) $f['ruta_xml'], $raiz) === 0 && is_file($f['ruta_xml']);
    });
    paso(count($vistas) === 3 && count($deDisco) === 3,
        'lo archivado antes de pasar la empresa se sigue leyendo del disco: ' . json_encode(array_column($vistas, 'ruta_xml')));
    $sinColumna = RutaDocumento::hidratarFila(['sociedad_id' => $sociedadId, 'ruta_xml' => "$mes/FE_A.xml"], ['ruta_xml']);
    paso(strpos($sinColumna['ruta_xml'], AlmacenEnGraph::PREFIJO) !== 0,
        'también en una consulta que no pide la columna `almacen`: ' . $sinColumna['ruta_xml']);

    // ── 2. El organizador no marca ni repunta nada ───────────────────────
    $organizador = new OrganizadorDocumentos($raiz, (new Factura())->setSociedad(0));
    $r = $organizador->reconciliar(false, true);
    paso(empty($r['omitido_por_bloqueo']) && (int) $r['rutas_actualizadas'] === 0
        && (int) $r['marcados_sin_archivo'] === 0,
        'la revisión completa no encuentra nada que corregir: ' . json_encode(array_intersect_key($r,
            array_flip(['rutas_actualizadas', 'marcados_sin_archivo', 'archivos_no_encontrados', 'omitido_por_bloqueo']))));
    foreach ($facturaIds as $letra => $id) {
        $f = $fila($id);
        paso($f['ruta_xml'] === "$mes/FE_$letra.xml" && $f['archivo_faltante_en'] === null,
            "$letra sigue en su original y sin marca: " . json_encode([$f['ruta_xml'], $f['archivo_faltante_en']]));
    }

    // ── 3. Si el original se movió, se lo sigue a él y no a la copia ─────
    $otroSitio = 'SISTEMA/' . $empresa . '/REVISAR';
    mkdir($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $otroSitio), 0777, true);
    foreach (['xml', 'pdf'] as $ext) {
        rename($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, "$mes/FE_A.$ext"),
            $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, "$otroSitio/FE_A.$ext"));
    }
    $r = $organizador->reconciliar(false, true);
    $a = $fila($facturaIds['A']);
    paso($a['ruta_xml'] === "$otroSitio/FE_A.xml" && $a['ruta_pdf'] === "$otroSitio/FE_A.pdf",
        'el documento movido se sigue a su original, no a la copia de PAGOS con el mismo contenido: '
        . json_encode([$a['ruta_xml'], $a['ruta_pdf']]));
    paso($a['archivo_faltante_en'] === null, 'y no queda marcado');

    // ── 4. Sin original, la copia sirve: mejor que perderlo ─────────────
    foreach (['xml', 'pdf'] as $ext) {
        unlink($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, "$mes/FE_B.$ext"));
    }
    $r = $organizador->reconciliar(false, true);
    $b = $fila($facturaIds['B']);
    paso($b['ruta_xml'] === "$pago/FE_B.xml",
        'si el original ya no existe en ningún lado, la copia del pago es lo que queda: ' . $b['ruta_xml']);
} catch (Throwable $e) {
    $fallas[] = 'se cortó (organizador): ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── 5. Si falta más de la mitad de golpe, se sospecha de la carpeta ─────
try {
    $falsa = new class {
        public $filas = [];
        public $actualizadas = 0;
        public $marcadas = null;
        public function getParaOrganizarArchivos(array $ids = [])
        {
            return $ids ? array_values(array_intersect_key($this->filas, array_flip($ids))) : array_values($this->filas);
        }
        public function actualizarUbicacionArchivos($id, $xml, $pdf) { $this->actualizadas++; return 1; }
        public function marcarArchivosFaltantes(array $faltan, array $ambito) { $this->marcadas = $faltan; return count($faltan); }
    };
    $ausente = $raiz . DIRECTORY_SEPARATOR . 'NO_MONTADA';
    for ($i = 1; $i <= 250; $i++) {
        $falsa->filas[$i] = ['id' => $i, 'ruta_xml' => $ausente . DIRECTORY_SEPARATOR . "FE_$i.xml",
            'ruta_pdf' => '', 'hash_xml' => hash('sha256', "x$i"), 'hash_pdf' => ''];
    }
    $r = (new OrganizadorDocumentos($raiz, $falsa))->reconciliar(false, true);
    paso(!empty($r['revision_suspendida']) && $falsa->actualizadas === 0 && $falsa->marcadas === null,
        'con 250 de 250 faltando, no se marca ni se reubica nada y se dice por qué: '
        . json_encode(['suspendida' => $r['revision_suspendida'] ?? null, 'actualizadas' => $falsa->actualizadas,
            'marcadas' => $falsa->marcadas === null ? null : count($falsa->marcadas)]));

    $falsa->filas = array_slice($falsa->filas, 0, 5, true);
    $r = (new OrganizadorDocumentos($raiz, $falsa))->reconciliar(false, true);
    paso(empty($r['revision_suspendida']) && is_array($falsa->marcadas) && count($falsa->marcadas) === 5,
        'pocos faltantes sí se marcan: es lo normal');
} catch (Throwable $e) {
    $fallas[] = 'se cortó (defensa): ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── 6. Un correo a la vez ────────────────────────────────────────────────
try {
    $clave = '5061906260031' . substr($cedulaProveedor, 0, 9) . '00100001010000' . random_int(100000, 999999)
        . '1' . random_int(10000000, 99999999);
    $numero = '00100001010000' . random_int(100000, 999999);
    $xmlCorreo = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica">'
        . '<Clave>' . $clave . '</Clave><NumeroConsecutivo>' . $numero . '</NumeroConsecutivo>'
        . '<FechaEmision>2026-09-19T10:00:00-06:00</FechaEmision>'
        . '<Emisor><Nombre>PROVEEDOR HISTORIA ' . $marca . '</Nombre><Identificacion><Numero>' . $cedulaProveedor
        . '</Numero></Identificacion></Emisor>'
        . '<Receptor><Nombre>' . $empresa . '</Nombre><Identificacion><Numero>' . $cedulaEmpresa
        . '</Numero></Identificacion></Receptor>'
        . '<ResumenFactura><CodigoTipoMoneda><CodigoMoneda>CRC</CodigoMoneda></CodigoTipoMoneda>'
        . '<TotalVentaNeta>100.00</TotalVentaNeta><TotalComprobante>113.00</TotalComprobante></ResumenFactura>'
        . '</FacturaElectronica>';
    $pdfCorreo = '%PDF-1.4 historia ' . $marca;
    $mensaje = function () use ($xmlCorreo, $pdfCorreo, $clave, $marca, &$temporales) {
        $x = tempnam(sys_get_temp_dir(), 'his_x_');
        $p = tempnam(sys_get_temp_dir(), 'his_p_');
        file_put_contents($x, $xmlCorreo);
        file_put_contents($p, $pdfCorreo);
        $temporales[] = $x;
        $temporales[] = $p;
        return ['xmls' => [['ruta' => $x, 'nombre' => $clave . '.xml']],
                'pdfs' => [['ruta' => $p, 'nombre' => 'FE_' . $clave . '.pdf']],
                'clave' => 'his-' . $clave, 'remitente' => 'p@example.test', 'asunto' => 'Factura ' . $marca,
                'fecha' => '2026-09-19 10:00:00'];
    };
    $ref = new ReflectionClass(CorreoController::class);
    $procesar = $ref->getMethod('procesarMensaje');
    $procesar->setAccessible(true);
    $correo = $ref->newInstanceWithoutConstructor();
    $bandeja = (new CorreoBandeja())->setSociedad($sociedadId);
    $capturar = function ($m) use ($procesar, $correo, $bandeja, $cuentaId, $sociedadId, $cedulaEmpresa) {
        return $procesar->invoke($correo, $m, $bandeja, new stdClass(), $cuentaId, ['FE'], '', 0,
            [['id' => $sociedadId, 'cedula' => $cedulaEmpresa]], 'manual');
    };
    $subidas = function () use ($biblioteca) {
        return count(preg_grep('~^PUT .*/content~', $biblioteca->registro));
    };

    // Otra ventana ya lo está guardando: tiene el candado.
    $otra = $otraConexion();
    $nombreCandado = 'captura_' . substr(sha1($cuentaId . '|his-' . $clave), 0, 24);
    $tomado = $otra->query("SELECT GET_LOCK(LEFT(CONCAT(DATABASE(), '.$nombreCandado'), 64), 0)")->fetchColumn();
    paso((int) $tomado === 1, 'la otra ventana toma el candado del correo');
    $antes = $subidas();
    $m = $mensaje();
    $r = $capturar($m);
    paso(!empty($r['en_curso']) && $r['filas'] === [] && $subidas() === $antes,
        'el segundo clic no sube nada ni crea filas: ' . json_encode($r['errores']));
    paso(!is_file($m['xmls'][0]['ruta']) && !is_file($m['pdfs'][0]['ruta']),
        'y los adjuntos que bajó para nada no quedan en la PC');
    paso(strpos((string) ($r['errores'][0] ?? ''), 'ya se está guardando') !== false, 'y lo dice con palabras');
    $otra->query("SELECT RELEASE_LOCK(LEFT(CONCAT(DATABASE(), '.$nombreCandado'), 64))");
    $otra = null;

    $r = $capturar($mensaje());
    $bandejaIds[] = (int) ($r['filas'][0]['id'] ?? 0);
    paso(($r['filas'][0]['estado'] ?? '') === 'pendiente' && $subidas() === $antes + 2,
        'cuando termina la otra, se guarda con una subida del XML y una del PDF: ' . json_encode($r['errores']));
    $filaBandeja = $bandeja->getByIds([$bandejaIds[0]], 'pendiente')[0] ?? [];
    paso(strpos((string) ($filaBandeja['archivo_xml'] ?? ''), 'sharepoint:_TRABAJO/BANDEJA/') === 0,
        'la bandeja de la empresa, ya en SharePoint, está en la biblioteca');

    // ── 7. Se importa; y otra vez, ya duplicada, sin bajar el PDF ────────
    $importador = new XmlDocumentImporter($raiz);
    $contexto = ['origen' => 'prueba', 'tipos_permitidos' => ['FE'], 'validar_receptor' => true,
                 'cedula_receptor' => $cedulaEmpresa, 'sociedad_id' => $sociedadId];
    $vigente = $nuevoAlmacen();
    $imp = $importador->importar($filaBandeja['archivo_xml'], $filaBandeja['archivo_pdf'], $contexto);
    $facturaIds['nueva'] = (int) ($imp['id'] ?? 0);
    $nueva = $fila($facturaIds['nueva']);
    paso(($nueva['almacen'] ?? '') === 'sharepoint' && strpos((string) $nueva['ruta_xml'], 'sharepoint:SISTEMA/') === 0
        && strpos((string) $nueva['ruta_pdf'], 'sharepoint:SISTEMA/') === 0,
        'lo nuevo de la empresa va a SharePoint, con su prefijo: ' . json_encode([$nueva['ruta_xml'] ?? '', $imp['estado'] ?? '']));

    $r = $capturar($mensaje());
    $filaBandeja = $bandeja->getByIds([$bandejaIds[0]], 'pendiente')[0] ?? [];
    $vigente = $nuevoAlmacen();
    $antes = count($biblioteca->registro);
    $dup = $importador->importar($filaBandeja['archivo_xml'], $filaBandeja['archivo_pdf'], $contexto);
    $llamadas = array_slice($biblioteca->registro, $antes);
    $pdfBandeja = rawurlencode(basename(substr((string) $filaBandeja['archivo_pdf'], 11)));
    paso(in_array($dup['estado'] ?? '', ['duplicado', 'duplicado_pago'], true)
        && !preg_grep('~' . preg_quote($pdfBandeja, '~') . '.*/content~', $llamadas),
        'volver a cargarla la reconoce y no baja su PDF de la bandeja: ' . json_encode([$dup['estado'] ?? '', $llamadas]));
    paso(count(preg_grep('~^PUT ~', $llamadas)) === 0, 'ni sube nada');
} catch (Throwable $e) {
    $fallas[] = 'se cortó (correo): ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── 8. La columna del hash se averigua una vez, no en cada guardado ─────
try {
    $sinColumna = new GraphEnMemoria();
    $sinColumna->conColumnaHash = false;
    $almacenCon = function () use ($sinColumna, $estadoGraph) {
        return new AlmacenEnGraph(new ClienteGraph(
            ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd-sin-columna'],
            $sinColumna, ['carpeta_estado' => $estadoGraph, 'registro' => null]
        ));
    };
    $local = tempnam(sys_get_temp_dir(), 'his_h_');
    $temporales[] = $local;
    $averiguar = function ($almacen, $nombre) use ($sinColumna, $local) {
        file_put_contents($local, '<FE>' . $nombre . '</FE>');
        $antes = count($sinColumna->registro);
        $almacen->escribir('SISTEMA/H/' . $nombre . '.xml', $local);
        return count(preg_grep('~listItem~', array_slice($sinColumna->registro, $antes)));
    };
    paso($averiguar($almacenCon(), 'uno') >= 1, 'la primera vez se averigua que la biblioteca no tiene la columna');
    paso($averiguar($almacenCon(), 'dos') === 0,
        'la siguiente petición ya lo sabe: ni un viaje más para eso');
    $otro = $almacenCon();
    $otro->olvidarColumnaHash();
    paso($averiguar($otro, 'tres') >= 1, 'y quien acaba de agregar la columna puede pedir que se vuelva a mirar');
} catch (Throwable $e) {
    $fallas[] = 'se cortó (columna): ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── 9. Lo que el reconciliador sigue en SharePoint queda con su prefijo ──
try {
    if (!empty($facturaIds['nueva'])) {
        (new Factura())->seguirEnAlmacen($facturaIds['nueva'], 'xml', 'SISTEMA/' . $empresa . '/MOVIDO/FE_N.xml', 'ID9', 'c9');
        $movida = $fila($facturaIds['nueva']);
        paso($movida['ruta_xml'] === 'sharepoint:SISTEMA/' . $empresa . '/MOVIDO/FE_N.xml',
            'un documento movido en SharePoint se anota con su prefijo: ' . $movida['ruta_xml']);
    }
} catch (Throwable $e) {
    $fallas[] = 'se cortó (seguir): ' . get_class($e) . ': ' . $e->getMessage();
} finally {
    // La base, como estaba.
    foreach ($facturaIds as $id) {
        $pdo->exec('DELETE FROM facturas_xml_referencias WHERE factura_xml_id = ' . (int) $id);
        $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . (int) $id);
    }
    foreach (array_filter($bandejaIds) as $id) {
        $pdo->exec('DELETE FROM correo_bandeja WHERE id = ' . (int) $id);
    }
    $pdo->prepare('DELETE FROM proveedor_codigo_erp WHERE proveedor_codigo = ?')->execute([$codigoProveedor]);
    if ($proveedorCreado && $proveedorId > 0) {
        $pdo->exec('DELETE FROM proveedores WHERE id = ' . $proveedorId);
    }
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
    // Lo que el organizador deja en storage/ para una raíz: esta era de prueba.
    $real = realpath($raiz);
    if ($real !== false) {
        $claveRaiz = substr(sha1(DIRECTORY_SEPARATOR === '\\' ? strtolower($real) : $real), 0, 16);
        foreach (['cache/documentos_indice_', 'cache/documentos_estado_', 'locks/organizador_documentos_'] as $prefijo) {
            foreach (glob(dirname(__DIR__) . '/storage/' . $prefijo . $claveRaiz . '.*') ?: [] as $sobra) {
                @unlink($sobra);
            }
        }
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

echo "OK: pasar a SharePoint una empresa con historia no la da por perdida, y un correo se guarda una sola vez\n";
