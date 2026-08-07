<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/OrganizadorDocumentos.php';

function assertOrganizador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function borrarOrganizador($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

class FacturaOrganizadorFalsa
{
    public $filas;
    public function __construct(array $filas) { $this->filas = $filas; }
    public function getParaOrganizarArchivos(array $ids = [])
    {
        if (!$ids) return array_values($this->filas);
        return array_values(array_filter($this->filas, function ($fila) use ($ids) {
            return in_array((int) $fila['id'], array_map('intval', $ids), true);
        }));
    }
    public function actualizarUbicacionArchivos($id, $xml, $pdf)
    {
        $this->filas[$id]['ruta_xml'] = $xml;
        $this->filas[$id]['ruta_pdf'] = $pdf;
        $this->filas[$id]['archivo_xml'] = $xml !== '' ? basename($xml) : null;
        $this->filas[$id]['archivo_pdf'] = $pdf !== '' ? basename($pdf) : null;
        return 1;
    }
    public function begin() { return true; }
    public function commit() { return true; }
    public function rollback() { return true; }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_organizador_' . bin2hex(random_bytes(5));
$entrada = $root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Facturas';
mkdir($entrada, 0700, true);

$xmlFactura = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<FacturaElectronica><NumeroConsecutivo>00100001010000000167</NumeroConsecutivo>'
    . '<FechaEmision>2026-07-20T10:00:00-06:00</FechaEmision>'
    . '<Emisor><Nombre>PROVEEDOR</Nombre><Identificacion><Numero>3101000000</Numero></Identificacion></Emisor>'
    . '<ResumenFactura><TotalComprobante>100</TotalComprobante></ResumenFactura></FacturaElectronica>';
$xmlNc = str_replace(['FacturaElectronica', '01010000000167'], ['NotaCreditoElectronica', '03010000000167'], $xmlFactura);

$feXml = $entrada . DIRECTORY_SEPARATOR . 'FE_REGISTRADA.xml';
$fePdf = $entrada . DIRECTORY_SEPARATOR . 'FE_REGISTRADA.pdf';
$diffXml = $entrada . DIRECTORY_SEPARATOR . 'FE_DIFERENCIA.xml';
$diffPdf = $entrada . DIRECTORY_SEPARATOR . 'FE_DIFERENCIA.pdf';
$sinMatchXml = $entrada . DIRECTORY_SEPARATOR . 'FE_SIN_MATCH.xml';
$sinMatchPdf = $entrada . DIRECTORY_SEPARATOR . 'FE_SIN_MATCH.pdf';
$semanalXml = $entrada . DIRECTORY_SEPARATOR . 'FE_PAGO_SEMANAL.xml';
$semanalPdf = $entrada . DIRECTORY_SEPARATOR . 'FE_PAGO_SEMANAL.pdf';
$semanalIncompletaXml = $entrada . DIRECTORY_SEPARATOR . 'FE_PAGO_INCOMPLETO.xml';
$ncSolo = $entrada . DIRECTORY_SEPARATOR . 'NC_SIN_PDF.xml';
file_put_contents($feXml, $xmlFactura); file_put_contents($fePdf, '%PDF par');
file_put_contents($diffXml, $xmlFactura); file_put_contents($diffPdf, '%PDF diferencia');
file_put_contents($sinMatchXml, $xmlFactura); file_put_contents($sinMatchPdf, '%PDF sin match');
file_put_contents($semanalXml, $xmlFactura); file_put_contents($semanalPdf, '%PDF semanal');
file_put_contents($semanalIncompletaXml, $xmlFactura);
file_put_contents($ncSolo, $xmlNc);

$externoXml = $entrada . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.xml';
$externoPdf = $entrada . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.pdf';
$externoNc = $entrada . DIRECTORY_SEPARATOR . 'NC_EXTERNA_SIN_PDF.xml';
$mh = $entrada . DIRECTORY_SEPARATOR . 'respuesta-MH.xml';
file_put_contents($externoXml, $xmlFactura); file_put_contents($externoPdf, '%PDF pendiente');
file_put_contents($externoNc, $xmlNc);
file_put_contents($mh, '<?xml version="1.0"?><MensajeHacienda><Clave>50620072600310100000000100001030000000000000000001</Clave><Mensaje>1</Mensaje></MensajeHacienda>');

$modelo = new FacturaOrganizadorFalsa([
    1 => ['id'=>1, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$feXml, 'ruta_pdf'=>$fePdf, 'con_diferencia'=>0, 'coincide_registro'=>1],
    2 => ['id'=>2, 'tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$ncSolo, 'ruta_pdf'=>null, 'con_diferencia'=>0, 'coincide_registro'=>1],
    3 => ['id'=>3, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$diffXml, 'ruta_pdf'=>$diffPdf, 'con_diferencia'=>1, 'coincide_registro'=>0],
    4 => ['id'=>4, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$sinMatchXml, 'ruta_pdf'=>$sinMatchPdf, 'con_diferencia'=>0, 'coincide_registro'=>0],
    5 => ['id'=>5, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'numero_factura_asistente'=>'0000000167', 'proveedor_nombre'=>'PROVEEDOR', 'ruta_xml'=>$semanalXml, 'ruta_pdf'=>$semanalPdf, 'con_diferencia'=>1, 'coincide_registro'=>0, 'pago_semanal'=>1, 'carpeta_pago'=>'Pago semana 31'],
    6 => ['id'=>6, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$semanalIncompletaXml, 'ruta_pdf'=>null, 'con_diferencia'=>0, 'coincide_registro'=>1, 'pago_semanal'=>1, 'carpeta_pago'=>'Pago semana 31'],
]);

try {
    $resumen = (new OrganizadorDocumentos($root, $modelo))->ejecutar(false, true);
    assertOrganizador(strpos($modelo->filas[1]['ruta_xml'], DIRECTORY_SEPARATOR . 'EN SISTEMA' . DIRECTORY_SEPARATOR) !== false, 'mueve el par registrado a EN SISTEMA');
    assertOrganizador(is_file($modelo->filas[1]['ruta_xml']) && is_file($modelo->filas[1]['ruta_pdf']), 'conserva juntos XML y PDF registrados');
    assertOrganizador(strpos($modelo->filas[2]['ruta_xml'], DIRECTORY_SEPARATOR . 'REVISAR' . DIRECTORY_SEPARATOR) !== false, 'manda la NC sin PDF a REVISAR');
    assertOrganizador($modelo->filas[2]['ruta_pdf'] === '', 'la NC incompleta sigue sin PDF asociado');
    assertOrganizador(strpos($modelo->filas[3]['ruta_xml'], DIRECTORY_SEPARATOR . 'CON DIFERENCIA' . DIRECTORY_SEPARATOR) !== false, 'mueve el par con diferencia');
    assertOrganizador(strpos($modelo->filas[4]['ruta_xml'], DIRECTORY_SEPARATOR . 'PENDIENTES DE PROCESAR' . DIRECTORY_SEPARATOR) !== false, 'un documento completo sin match queda pendiente');
    $carpetaSemanal = $root . DIRECTORY_SEPARATOR . 'PAGOS SEMANALES' . DIRECTORY_SEPARATOR . 'Pago semana 31';
    assertOrganizador(dirname($modelo->filas[5]['ruta_xml']) === $carpetaSemanal, 'el monto diferente no saca el XML de la carpeta semanal');
    assertOrganizador(dirname($modelo->filas[5]['ruta_pdf']) === $carpetaSemanal, 'el monto diferente no saca el PDF de la carpeta semanal');
    assertOrganizador(basename($modelo->filas[5]['ruta_xml']) === 'FE_PROVEEDOR_200726_00000167.xml', 'el XML semanal conserva tipo, proveedor y fecha con ocho dígitos');
    assertOrganizador(basename($modelo->filas[5]['ruta_pdf']) === 'FE_PROVEEDOR_200726_00000167.pdf', 'el PDF semanal conserva tipo, proveedor y fecha con ocho dígitos');
    assertOrganizador(($resumen['pago_semanal'] ?? 0) === 1, 'contabiliza el par enviado al pago semanal');
    assertOrganizador(strpos($modelo->filas[6]['ruta_xml'], DIRECTORY_SEPARATOR . 'REVISAR' . DIRECTORY_SEPARATOR) !== false, 'un match semanal sin PDF permanece en REVISAR');
    assertOrganizador(DocumentoArchivo::normalizarCarpetaPago('../Semana: 31') === 'Semana 31', 'limpia recorridos y caracteres inválidos del nombre de carpeta');
    assertOrganizador(is_file(dirname($modelo->filas[1]['ruta_xml']) . DIRECTORY_SEPARATOR . basename($modelo->filas[1]['ruta_pdf'])), 'el par comparte carpeta');
    assertOrganizador(count(glob($root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Facturas' . DIRECTORY_SEPARATOR . 'PENDIENTES DE PROCESAR' . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.*')) === 2, 'el par externo queda pendiente de procesar');
    assertOrganizador(is_file($root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Notas de crédito' . DIRECTORY_SEPARATOR . 'REVISAR' . DIRECTORY_SEPARATOR . 'NC_EXTERNA_SIN_PDF.xml'), 'la NC externa sin PDF queda en REVISAR');
    assertOrganizador(count(glob($root . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'IGNORADOS' . DIRECTORY_SEPARATOR . 'MENSAJE HACIENDA SOLO' . DIRECTORY_SEPARATOR . '*.xml')) === 1, 'separa el MensajeHacienda aislado');
    assertOrganizador(($resumen['errores'] ?? 1) === 0, 'termina sin errores');

    // La reconciliación acepta un movimiento/renombre manual, actualiza la
    // referencia por hash y no devuelve el par a la estructura automática.
    $xmlManualContenido = str_replace(
        ['00000000167', '</FacturaElectronica>'],
        ['00000000999', '<DetalleUnico>MANUAL-999</DetalleUnico></FacturaElectronica>'],
        $xmlFactura
    );
    $xmlManual = $entrada . DIRECTORY_SEPARATOR . 'FE_MANUAL_ORIGINAL.xml';
    $pdfManual = $entrada . DIRECTORY_SEPARATOR . 'FE_MANUAL_ORIGINAL.pdf';
    file_put_contents($xmlManual, $xmlManualContenido);
    file_put_contents($pdfManual, '%PDF manual unico');
    $modelo->filas[7] = [
        'id'=>7, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20',
        'ruta_xml'=>$xmlManual, 'ruta_pdf'=>$pdfManual,
        'hash_xml'=>hash_file('sha256', $xmlManual), 'hash_pdf'=>hash_file('sha256', $pdfManual),
        'con_diferencia'=>0, 'coincide_registro'=>1,
    ];
    $organizador = new OrganizadorDocumentos($root, $modelo);
    $organizador->organizarIds([7]);
    $manualDir = $root . DIRECTORY_SEPARATOR . 'MIS CARPETAS' . DIRECTORY_SEPARATOR . 'Proveedor especial';
    mkdir($manualDir, 0700, true);
    $xmlElegido = $manualDir . DIRECTORY_SEPARATOR . 'Factura julio editada.xml';
    $pdfElegido = $manualDir . DIRECTORY_SEPARATOR . 'Factura julio editada.pdf';
    rename($modelo->filas[7]['ruta_xml'], $xmlElegido);
    rename($modelo->filas[7]['ruta_pdf'], $pdfElegido);

    $libreXml = $manualDir . DIRECTORY_SEPARATOR . 'ARCHIVO_LIBRE.xml';
    file_put_contents($libreXml, '<documento>sin registrar</documento>');
    $reconciliado = $organizador->reconciliar(false, false, [7]);
    assertOrganizador($modelo->filas[7]['ruta_xml'] === $xmlElegido, 'acepta la nueva ruta elegida para el XML');
    assertOrganizador($modelo->filas[7]['ruta_pdf'] === $pdfElegido, 'acepta la nueva ruta elegida para el PDF');
    assertOrganizador(is_file($xmlElegido) && is_file($pdfElegido), 'no vuelve a mover el par reconciliado');
    assertOrganizador(is_file($libreXml), 'la reconciliación no mueve archivos desconocidos');
    assertOrganizador(($reconciliado['rutas_actualizadas'] ?? 0) === 1, 'actualiza una sola referencia de BD');
    assertOrganizador(($reconciliado['archivos_relocalizados'] ?? 0) === 2, 'detecta XML y PDF relocalizados');
    assertOrganizador(($reconciliado['renombres_detectados'] ?? 0) === 2, 'detecta ambos cambios de nombre');
    assertOrganizador(($reconciliado['errores'] ?? 1) === 0, 'reconcilia sin errores');

    $rapido = $organizador->reconciliar(false, false, [7]);
    assertOrganizador(($rapido['archivos_indexados'] ?? -1) === 0, 'sin rutas faltantes evita recorrer el archivo');
    assertOrganizador(($rapido['hashes_calculados'] ?? -1) === 0, 'sin cambios no vuelve a calcular hashes');

    $completo = $organizador->reconciliar(false, true, [7]);
    assertOrganizador(($completo['hashes_calculados'] ?? 0) > 0, 'la revisión completa renueva el índice de contenido');
    assertOrganizador(!$organizador->requiereReconciliacionCompleta(86400), 'no repite la revisión completa el mismo día');

    // Un PDF histórico sin hash en BD también puede recuperarse usando el
    // hash conservado por el índice completo del día anterior.
    $modelo->filas[7]['hash_pdf'] = '';
    $pdfSeparado = $manualDir . DIRECTORY_SEPARATOR . 'Comprobante proveedor renombrado.pdf';
    rename($modelo->filas[7]['ruta_pdf'], $pdfSeparado);
    $pdfHistorico = $organizador->reconciliar(false, false, [7]);
    assertOrganizador($modelo->filas[7]['ruta_pdf'] === $pdfSeparado, 'recupera por el índice un PDF histórico sin hash en BD');
    assertOrganizador(is_file($pdfSeparado), 'conserva el nombre independiente elegido para el PDF');
    assertOrganizador(($pdfHistorico['rutas_actualizadas'] ?? 0) === 1, 'actualiza la ruta del PDF histórico');

    $modelo->filas[8] = [
        'id'=>8, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20',
        'ruta_xml'=>$root . DIRECTORY_SEPARATOR . 'NO_EXISTE.xml', 'ruta_pdf'=>null,
        'hash_xml'=>str_repeat('a', 64), 'hash_pdf'=>null,
        'con_diferencia'=>0, 'coincide_registro'=>1,
    ];
    $faltanteInicial = $organizador->reconciliar(false, false, [8]);
    $faltanteRepetido = $organizador->reconciliar(false, false, [8]);
    assertOrganizador(($faltanteInicial['archivos_indexados'] ?? 0) > 0, 'busca un faltante nuevo en el árbol');
    assertOrganizador(($faltanteRepetido['archivos_indexados'] ?? -1) === 0, 'no repite el recorrido para un faltante conocido');
    assertOrganizador(!empty($faltanteRepetido['revision_omitida_sin_cambios']), 'marca la revisión omitida por firma sin cambios');
    echo "OK: OrganizadorDocumentos\n";
} finally {
    $raizCache = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($root));
    if (DIRECTORY_SEPARATOR === '\\') $raizCache = strtolower($raizCache);
    $claveCache = substr(sha1($raizCache), 0, 16);
    foreach (['documentos_indice_', 'documentos_estado_'] as $prefijoCache) {
        $cache = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . $prefijoCache . $claveCache . '.json';
        if (is_file($cache)) unlink($cache);
    }
    $lockPrueba = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks'
        . DIRECTORY_SEPARATOR . 'organizador_documentos_' . $claveCache . '.lock';
    if (is_file($lockPrueba)) unlink($lockPrueba);
    borrarOrganizador($root);
}
