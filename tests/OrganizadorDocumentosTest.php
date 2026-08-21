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

function huellaArbolOrganizadorTemporales($dir)
{
    $sobras = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entrada) {
        if ($entrada->isFile() && strpos($entrada->getFilename(), '.partial_') !== false) {
            $sobras[] = $entrada->getPathname();
        }
    }
    return $sobras;
}

function huellaArbolOrganizador($dir)
{
    $archivos = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entrada) {
        if ($entrada->isFile()) {
            // Por contenido, no por nombre ni por sitio: mover y renombrar
            // son cosas que ordenar hace a propósito. Lo que no puede pasar
            // es que un contenido deje de existir en el árbol.
            $archivos[] = hash_file('sha256', $entrada->getPathname());
        }
    }
    sort($archivos);
    return $archivos;
}

class FacturaOrganizadorFalsa
{
    public $filas;
    public $lotes = [];
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
    public function actualizarUbicacionArchivosLote(array $filas)
    {
        $this->lotes[] = count($filas);
        foreach ($filas as $fila) {
            $this->actualizarUbicacionArchivos(
                (int) $fila['id'],
                (string) $fila['ruta_xml'],
                (string) $fila['ruta_pdf']
            );
        }
        return count($filas);
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
// Un documento ya archivado con la Ñ en su nombre: el acomodo vuelve a
// limpiar el nombre en cada corrida, y ese barrido se la comía.
$enieXml = $entrada . DIRECTORY_SEPARATOR . 'FE_COMPAÑIA_RIO_JAVA_170726_00016987.xml';
$eniePdf = $entrada . DIRECTORY_SEPARATOR . 'FE_COMPAÑIA_RIO_JAVA_170726_00016987.pdf';
file_put_contents($feXml, $xmlFactura); file_put_contents($fePdf, '%PDF par');
file_put_contents($diffXml, $xmlFactura); file_put_contents($diffPdf, '%PDF diferencia');
file_put_contents($sinMatchXml, $xmlFactura); file_put_contents($sinMatchPdf, '%PDF sin match');
file_put_contents($semanalXml, $xmlFactura); file_put_contents($semanalPdf, '%PDF semanal');
file_put_contents($semanalIncompletaXml, $xmlFactura);
file_put_contents($ncSolo, $xmlNc);
file_put_contents($enieXml, $xmlFactura); file_put_contents($eniePdf, '%PDF eñe');

$externoXml = $entrada . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.xml';
$externoPdf = $entrada . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.pdf';
$externoNc = $entrada . DIRECTORY_SEPARATOR . 'NC_EXTERNA_SIN_PDF.xml';
$mh = $entrada . DIRECTORY_SEPARATOR . 'respuesta-MH.xml';
file_put_contents($externoXml, $xmlFactura); file_put_contents($externoPdf, '%PDF pendiente');
file_put_contents($externoNc, $xmlNc);
file_put_contents($mh, '<?xml version="1.0"?><MensajeHacienda><Clave>50620072600310100000000100001030000000000000000001</Clave><Mensaje>1</Mensaje></MensajeHacienda>');

// Lo que está en tránsito no es archivo: el adjunto que todavía no se importa
// vive en _TRABAJO y otra tabla guarda su ruta. Si el barrido de sueltos se lo
// llevara al mes, ese XML aparecería archivado sin haberse importado nunca y
// la fila de la bandeja quedaría apuntando al vacío.
$bandeja = $root . DIRECTORY_SEPARATOR . '_TRABAJO' . DIRECTORY_SEPARATOR . 'BANDEJA'
         . DIRECTORY_SEPARATOR . 'xml';
mkdir($bandeja, 0700, true);
$xmlBandeja = $bandeja . DIRECTORY_SEPARATOR . 'FE_SIN_IMPORTAR.xml';
file_put_contents($xmlBandeja, $xmlFactura);

$modelo = new FacturaOrganizadorFalsa([
    1 => ['id'=>1, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$feXml, 'ruta_pdf'=>$fePdf, 'con_diferencia'=>0, 'coincide_registro'=>1],
    2 => ['id'=>2, 'tipo_documento'=>'NC', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$ncSolo, 'ruta_pdf'=>null, 'con_diferencia'=>0, 'coincide_registro'=>1],
    3 => ['id'=>3, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$diffXml, 'ruta_pdf'=>$diffPdf, 'con_diferencia'=>1, 'coincide_registro'=>0],
    4 => ['id'=>4, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'ruta_xml'=>$sinMatchXml, 'ruta_pdf'=>$sinMatchPdf, 'con_diferencia'=>0, 'coincide_registro'=>0],
    5 => ['id'=>5, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'numero_factura_asistente'=>'0000000167', 'proveedor_nombre'=>'PROVEEDOR', 'ruta_xml'=>$semanalXml, 'ruta_pdf'=>$semanalPdf, 'con_diferencia'=>1, 'coincide_registro'=>0, 'pago_semanal'=>1, 'carpeta_pago'=>'Pago semana 31'],
    8 => ['id'=>8, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'numero_factura_asistente'=>'00016987', 'proveedor_nombre'=>'COMPAÑIA RIO JAVA', 'ruta_xml'=>$enieXml, 'ruta_pdf'=>$eniePdf, 'con_diferencia'=>0, 'coincide_registro'=>1],
    6 => ['id'=>6, 'tipo_documento'=>'FE', 'fecha_emision'=>'2026-07-20', 'numero_factura_asistente'=>'0000000168', 'proveedor_nombre'=>'FERRETERIA EL CLAVO', 'ruta_xml'=>$semanalIncompletaXml, 'ruta_pdf'=>null, 'con_diferencia'=>0, 'coincide_registro'=>1, 'pago_semanal'=>1, 'carpeta_pago'=>'Pago semana 31'],
]);

try {
    $resumen = (new OrganizadorDocumentos($root, $modelo))->ejecutar(false, true);

    // La carpeta la deciden la fecha de emisión y el tipo, y nada más. El
    // estado de la factura ya no parte el árbol: por eso las cuatro primeras
    // —completa, incompleta, con diferencia y sin match— terminan repartidas
    // solo entre Facturas y Notas de crédito del mes que les toca.
    //
    // Todo eso cuelga de SISTEMA. Los archivos de esta prueba nacen en el
    // árbol viejo, colgado de la raíz, así que de paso comprueba la mudanza:
    // un archivo local de antes del cambio se acomoda solo en la primera
    // corrida y no deja el esqueleto de carpetas atrás.
    $sistema = $root . DIRECTORY_SEPARATOR . 'SISTEMA';
    $julioFacturas = $sistema . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO'
        . DIRECTORY_SEPARATOR . 'Facturas';
    $julioNotas = $sistema . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO'
        . DIRECTORY_SEPARATOR . 'Notas de crédito';

    assertOrganizador(dirname($modelo->filas[1]['ruta_xml']) === $julioFacturas,
        'la factura completa va a la carpeta del mes por tipo');
    assertOrganizador(is_file($modelo->filas[1]['ruta_xml']) && is_file($modelo->filas[1]['ruta_pdf']), 'conserva juntos XML y PDF registrados');
    assertOrganizador(dirname($modelo->filas[2]['ruta_xml']) === $julioNotas,
        'la NC sin PDF va con las notas de crédito, no a una carpeta de estado');
    assertOrganizador($modelo->filas[2]['ruta_pdf'] === '', 'la NC incompleta sigue sin PDF asociado');
    assertOrganizador(dirname($modelo->filas[3]['ruta_xml']) === $julioFacturas,
        'el par con diferencia no se aparta: la diferencia se ve en la aplicación');
    assertOrganizador(dirname($modelo->filas[4]['ruta_xml']) === $julioFacturas,
        'un documento completo sin match tampoco se aparta');
    assertOrganizador(dirname($modelo->filas[1]['ruta_xml']) === dirname($modelo->filas[3]['ruta_xml']),
        'facturas del mismo mes y tipo comparten carpeta, sea cual sea su estado');
    assertOrganizador(($resumen['por_fecha'] ?? 0) > 0, 'contabiliza los movimientos por fecha y tipo');

    // La carpeta del pago recibe una COPIA y el original se queda en la del
    // mes. Antes se mudaba ahí dentro, y entonces esa carpeta era el único
    // ejemplar: borrarla —que es lo que se hace cuando el pago ya se
    // entregó— se llevaba el respaldo de todos sus documentos.
    $carpetaSemanal = $root . DIRECTORY_SEPARATOR . 'PAGOS SEMANALES' . DIRECTORY_SEPARATOR . 'Pago semana 31';
    assertOrganizador(dirname($modelo->filas[5]['ruta_xml']) === $julioFacturas,
        'la base apunta al original del mes, no a la copia de entrega');
    assertOrganizador(is_file($modelo->filas[5]['ruta_xml']) && is_file($modelo->filas[5]['ruta_pdf']),
        'el original sigue en la carpeta del mes');
    assertOrganizador(is_file($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.xml'),
        'el XML de la copia conserva tipo, proveedor y fecha con ocho dígitos');
    assertOrganizador(is_file($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.pdf'),
        'el PDF de la copia conserva tipo, proveedor y fecha con ocho dígitos');
    assertOrganizador(($resumen['copias_pago'] ?? 0) === 1, 'contabiliza la copia dejada en el pago semanal');
    assertOrganizador(($resumen['pago_semanal'] ?? 0) === 1, 'contabiliza el par entregado al pago semanal');

    // Borrar la carpeta de pago ya no le quita el respaldo a nadie: el
    // documento sigue completo y la siguiente corrida repone la copia.
    unlink($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.xml');
    unlink($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.pdf');
    assertOrganizador(is_file($modelo->filas[5]['ruta_xml']),
        'borrar la carpeta de entrega no toca el documento');
    $repuesta = (new OrganizadorDocumentos($root, $modelo))->ejecutar(false, false);
    assertOrganizador(is_file($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.xml')
        && is_file($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.pdf'),
        'la siguiente corrida repone la copia de entrega borrada');
    assertOrganizador(($repuesta['copias_pago'] ?? 0) === 1, 'y la cuenta como copia nueva');

    $sinCambios = (new OrganizadorDocumentos($root, $modelo))->ejecutar(false, false);
    assertOrganizador(($sinCambios['copias_pago'] ?? -1) === 0
        && ($sinCambios['copias_pago_vigentes'] ?? 0) === 1,
        'con la copia en su sitio no se vuelve a copiar nada');

    // Un par incompleto no entra a la carpeta de pago: esa carpeta existe
    // para entregar respaldos, y sin PDF no hay nada que entregar.
    assertOrganizador(dirname($modelo->filas[6]['ruta_xml']) === $julioFacturas,
        'un match semanal sin PDF se queda en la carpeta del mes');
    assertOrganizador(!is_file($carpetaSemanal . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167_DUP.xml'),
        'y no deja copia en la carpeta de pago');

    // Pero no se va en silencio. Que la carpeta tenga menos archivos que el
    // pago facturas es correcto; que nadie pueda saber cuáles faltan, no.
    assertOrganizador(($resumen['sin_par_completo'] ?? 0) === 1,
        'cuenta el par incompleto que no llegó a la carpeta del pago');
    $incompletos = $resumen['incompletos'] ?? [];
    assertOrganizador(count($incompletos) === 1, 'y lo nombra uno por uno');
    assertOrganizador((int) $incompletos[0]['documento_id'] === 6, 'dice de qué documento se trata');
    assertOrganizador($incompletos[0]['numero'] === '0000000168', 'con el número con que se lo busca');
    assertOrganizador($incompletos[0]['proveedor'] === 'FERRETERIA EL CLAVO', 'y su proveedor');
    assertOrganizador($incompletos[0]['falta'] === 'PDF', 'y qué de las dos mitades le falta');
    assertOrganizador($incompletos[0]['falta_pdf'] === true && $incompletos[0]['falta_xml'] === false,
        'distingue cuál falta: al PDF se lo pide el proveedor, al XML se lo recupera');

    // Los que no están en ningún pago no entran a esta lista: no hay carpeta
    // de entrega que llenar, así que no falta nada.
    assertOrganizador(
        count(array_filter($incompletos, fn($d) => (int) $d['documento_id'] !== 6)) === 0,
        'la NC suelta sin PDF no se reporta: no es de ningún pago'
    );
    assertOrganizador(DocumentoArchivo::normalizarCarpetaPago('../Semana: 31') === 'Semana 31', 'limpia recorridos y caracteres inválidos del nombre de carpeta');
    assertOrganizador(is_file(dirname($modelo->filas[1]['ruta_xml']) . DIRECTORY_SEPARATOR . basename($modelo->filas[1]['ruta_pdf'])), 'el par comparte carpeta');
    assertOrganizador(count(glob($julioFacturas . DIRECTORY_SEPARATOR . 'FE_PENDIENTE.*')) === 2, 'el par externo se archiva por su fecha');
    assertOrganizador(is_file($julioNotas . DIRECTORY_SEPARATOR . 'NC_EXTERNA_SIN_PDF.xml'), 'la NC externa sin PDF se archiva con las notas de crédito');
    assertOrganizador(count(glob($sistema . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'IGNORADOS' . DIRECTORY_SEPARATOR . 'MENSAJE HACIENDA SOLO' . DIRECTORY_SEPARATOR . '*.xml')) === 1, 'separa el MensajeHacienda aislado');
    assertOrganizador(is_file($sistema . DIRECTORY_SEPARATOR . 'LEEME - NO MODIFICAR.txt'), 'deja la advertencia dentro de SISTEMA');
    assertOrganizador(!is_dir($root . DIRECTORY_SEPARATOR . '2026'), 'no deja el árbol vacío de donde se mudaron los archivos');
    assertOrganizador(is_dir($root . DIRECTORY_SEPARATOR . 'PAGOS SEMANALES'), 'la carpeta de entrega sigue en la raíz, fuera de SISTEMA');
    assertOrganizador(is_file($xmlBandeja), 'no archiva lo que está en tránsito dentro de _TRABAJO');
    // La Ñ sobrevive al acomodo. El organizador vuelve a limpiar el nombre en
    // cada corrida, y su barrido dejaba fuera la Ñ: deshacía en el primer
    // acomodo lo que el archivado se cuidó de respetar, y el archivo volvía a
    // llamarse FE_COMPA_NIA_RIO_JAVA.
    assertOrganizador(basename($modelo->filas[8]['ruta_xml']) === 'FE_COMPAÑIA_RIO_JAVA_170726_00016987.xml',
        'el acomodo no se come la Ñ del nombre del archivo');
    assertOrganizador(is_file($modelo->filas[8]['ruta_xml']) && is_file($modelo->filas[8]['ruta_pdf']),
        'y el par con Ñ queda donde dice la base');
    assertOrganizador(strpos(basename($modelo->filas[8]['ruta_xml']), '_NIA_') === false,
        'no reaparece el guion bajo que partía COMPAÑIA en dos');

    assertOrganizador(($resumen['errores'] ?? 1) === 0, 'termina sin errores');
    // Con la base en el servidor, una consulta por documento agotaba el tiempo
    // de la petición al ordenar una semana entera (cientos de documentos).
    assertOrganizador(count($modelo->lotes) === 1 && array_sum($modelo->lotes) === ($resumen['movidos'] ?? 0),
        'anota las ubicaciones de una sola vez, no con una consulta por documento');

    // La reconciliación acepta un movimiento/renombre manual, actualiza la
    // referencia por hash y no devuelve el par a la estructura automática.
    $xmlManualContenido = str_replace(
        ['00000000167', '</FacturaElectronica>'],
        ['00000000999', '<DetalleUnico>MANUAL-999</DetalleUnico></FacturaElectronica>'],
        $xmlFactura
    );
    // La corrida anterior vació esta carpeta y la poda se la llevó: para
    // dejar aquí un par nuevo hay que volver a crearla, igual que haría quien
    // copie archivos a mano en la carpeta compartida.
    if (!is_dir($entrada)) {
        mkdir($entrada, 0700, true);
    }
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

    // Previsualizar: dice exactamente qué haría, sin tocar un solo archivo.
    // Es lo que hace que la orden sea segura de dar — se ve antes de confirmar.
    $xmlAntesPrevia = $modelo->filas[7]['ruta_xml'];
    $pdfAntesPrevia = $modelo->filas[7]['ruta_pdf'];
    $previa = $organizador->organizarIds([7], true);
    assertOrganizador(($previa['dry_run'] ?? false) === true, 'la previsualización se identifica como tal');
    assertOrganizador(($previa['movimientos_planificados'] ?? 0) > 0, 'la previsualización anuncia el movimiento pendiente');
    assertOrganizador(($previa['movidos'] ?? -1) === 0, 'la previsualización no mueve nada');
    assertOrganizador(is_file($xmlAntesPrevia) && is_file($pdfAntesPrevia),
        'tras previsualizar, el par sigue exactamente donde estaba');
    assertOrganizador($modelo->filas[7]['ruta_xml'] === $xmlAntesPrevia,
        'la previsualización tampoco toca la base de datos');

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
    // ── Ordenar no borra ───────────────────────────────────────────
    //
    // Esto corre solo, cada pocos minutos, sobre la carpeta compartida de la
    // empresa: lo único que no se puede permitir es que se lleve un archivo por
    // delante. Se compara el árbol entero antes y después —por nombre y
    // tamaño— y no puede faltar ninguno. Aparecer sí: las copias de entrega
    // del pago semanal.
    $antesDeOrdenar = huellaArbolOrganizador($root);
    (new OrganizadorDocumentos($root, $modelo))->ejecutar(false, true);
    $despuesDeOrdenar = huellaArbolOrganizador($root);

    assertOrganizador(!array_diff($antesDeOrdenar, $despuesDeOrdenar),
        'ordenar no hace desaparecer el contenido de ningún archivo');
    assertOrganizador(count($despuesDeOrdenar) >= count($antesDeOrdenar),
        'y si el número cambia es porque se agregaron copias, nunca porque falten');
    assertOrganizador(!huellaArbolOrganizadorTemporales($root),
        'no deja temporales de copia a medio hacer');

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
