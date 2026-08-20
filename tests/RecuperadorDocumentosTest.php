<?php
/**
 * Volver a bajar del correo el respaldo de un documento que se perdió.
 *
 * Lo que se comprueba es lo único que hace confiable a esto: que el archivo
 * repuesto sea EL MISMO que se archivó —se acepta por su huella, no por su
 * nombre ni por venir en el mensaje correcto—, que vuelva a su misma ruta y
 * que un mensaje que ya no trae el adjunto bueno no escriba nada.
 *
 * El buzón se sustituye por un doble: acá no se prueba IMAP, se prueba la
 * decisión de qué se acepta y dónde se deja.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/RecuperadorDocumentos.php';

function assertRecuperador($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function borrarArbolRecuperador($dir)
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entrada) {
        $entrada->isDir() ? rmdir($entrada->getPathname()) : unlink($entrada->getPathname());
    }
    rmdir($dir);
}

// ── Dobles ──────────────────────────────────────────────────────────────────

class FacturaRecuperadorFalsa
{
    public $limpiados = [];
    public $actualizados = [];
    public function marcarArchivosFaltantes(array $faltantes, array $ambito = [])
    {
        $this->limpiados = array_merge($this->limpiados, $ambito);
        return count($ambito);
    }
    public function actualizarArchivos($id, array $data)
    {
        $this->actualizados[(int) $id] = $data;
        return 1;
    }
}

class CuentasRecuperadorFalsas
{
    public function configPara($id)
    {
        return (int) $id === 7
            ? ['cuenta_id' => 7, 'host' => 'correo.empresa.com', 'usuario' => 'cxp',
               'password' => 'x', 'carpeta' => 'INBOX']
            : null;
    }
}

/** Contesta con los adjuntos que se le hayan puesto, sin tocar la red. */
class BuzonFalso
{
    public $adjuntosPorUid = [];
    public $carpetasAbiertas = [];
    public $cerrado = false;

    public function conectar() { return true; }
    public function cerrar() { $this->cerrado = true; }

    public function extraerMensaje($uid, $carpeta = '')
    {
        $this->carpetasAbiertas[] = $carpeta;
        $adjuntos = $this->adjuntosPorUid[(int) $uid] ?? [];
        $xmls = [];
        $pdfs = [];
        foreach ($adjuntos as $ruta) {
            // Se copia porque el recuperador borra los temporales al terminar,
            // igual que hace MailFetcher con los adjuntos que baja.
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('adj_', true) . '_' . basename($ruta);
            copy($ruta, $tmp);
            $adj = ['ruta' => $tmp, 'nombre' => basename($ruta)];
            if (strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'xml') $xmls[] = $adj;
            else $pdfs[] = $adj;
        }
        return ['uid' => (int) $uid, 'xmls' => $xmls, 'pdfs' => $pdfs];
    }
}

// ── Preparación ─────────────────────────────────────────────────────────────

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xmlconcilia_recuperador_' . bin2hex(random_bytes(5));
$mes = $raiz . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07 JULIO' . DIRECTORY_SEPARATOR . 'Facturas';
$buzonDir = $raiz . DIRECTORY_SEPARATOR . '_correo';
mkdir($mes, 0700, true);
mkdir($buzonDir, 0700, true);

// Lo que un día se archivó: el par y su huella.
$xmlOriginal = '<?xml version="1.0"?><FacturaElectronica><NumeroConsecutivo>00100001010000000167</NumeroConsecutivo></FacturaElectronica>';
$pdfOriginal = '%PDF el bueno';
$xmlBuzon = $buzonDir . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR.xml';
$pdfBuzon = $buzonDir . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR.pdf';
file_put_contents($xmlBuzon, $xmlOriginal);
file_put_contents($pdfBuzon, $pdfOriginal);

$otroXml = $buzonDir . DIRECTORY_SEPARATOR . 'FE_OTRA.xml';
file_put_contents($otroXml, '<?xml version="1.0"?><FacturaElectronica>otra distinta</FacturaElectronica>');

$rutaXml = $mes . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.xml';
$rutaPdf = $mes . DIRECTORY_SEPARATOR . 'FE_PROVEEDOR_200726_00000167.pdf';

$documento = [
    'id' => 41,
    'tipo_documento' => 'FE',
    'consecutivo_completo' => '00100001010000000167',
    'numero_factura_asistente' => '00000167',
    'fecha_emision' => '2026-07-20',
    'proveedor_nombre' => 'PROVEEDOR',
    'ruta_xml' => $rutaXml,
    'ruta_pdf' => $rutaPdf,
    'hash_xml' => hash('sha256', $xmlOriginal),
    'hash_pdf' => hash('sha256', $pdfOriginal),
    'correo_cuenta_id' => 7,
    'correo_carpeta' => 'INBOX.2026',
    'correo_uid' => 900,
];

$buzon = new BuzonFalso();
$buzon->adjuntosPorUid[900] = [$xmlBuzon, $pdfBuzon];
$facturas = new FacturaRecuperadorFalsa();
$abrir = static function () use ($buzon) { return $buzon; };

try {
    if (!function_exists('imap_open')) {
        // recuperar() se planta antes de nada si no hay extensión imap, que es
        // lo correcto en producción pero deja esta prueba sin nada que probar.
        echo "OMITIDA: RecuperadorDocumentos (falta la extensión imap de PHP)\n";
        borrarArbolRecuperador($raiz);
        exit(0);
    }

    $recuperador = new RecuperadorDocumentos($facturas, new CuentasRecuperadorFalsas(), $raiz, $abrir);

    // ── El par se perdió: vuelve a su misma ruta ────────────────────────────
    $resumen = $recuperador->recuperar([$documento]);

    assertRecuperador($resumen['recuperados'] === 1, 'repone el documento que se había perdido');
    assertRecuperador(is_file($rutaXml) && is_file($rutaPdf),
        'el par vuelve exactamente a la ruta que la base ya tenía guardada');
    assertRecuperador(hash_file('sha256', $rutaXml) === $documento['hash_xml'],
        'lo repuesto es byte por byte lo que se archivó, no otra versión');
    assertRecuperador(hash_file('sha256', $rutaPdf) === $documento['hash_pdf'],
        'y lo mismo con el PDF');
    assertRecuperador(in_array(41, $facturas->limpiados, true),
        'quita la marca de "sin archivo" al documento repuesto');
    assertRecuperador($buzon->carpetasAbiertas === ['INBOX.2026'],
        'busca el mensaje en la carpeta de la que salió, no en la bandeja entera');
    assertRecuperador($buzon->cerrado, 'cierra el buzón al terminar');
    assertRecuperador(count(glob($mes . DIRECTORY_SEPARATOR . '*.partial_*')) === 0,
        'no deja archivos a medias en la carpeta compartida');

    // ── Si ya está, no se toca ─────────────────────────────────────────────
    $antes = filemtime($rutaXml);
    $repetido = $recuperador->recuperar([$documento]);
    assertRecuperador($repetido['ya_estaban'] === 1 && $repetido['recuperados'] === 0,
        'con los archivos en su sitio no vuelve a bajar nada');
    assertRecuperador(filemtime($rutaXml) === $antes, 'ni reescribe el que ya estaba');

    // ── El mensaje ya no trae el bueno: no se escribe nada ─────────────────
    unlink($rutaXml);
    unlink($rutaPdf);
    $buzon->adjuntosPorUid[900] = [$otroXml];
    $ajeno = $recuperador->recuperar([$documento]);
    assertRecuperador($ajeno['sin_coincidencia'] === 1 && $ajeno['recuperados'] === 0,
        'un adjunto que no da la huella guardada no se acepta');
    assertRecuperador(!is_file($rutaXml),
        'y no se escribe un documento parecido en lugar del que falta');

    // ── Sin correo del que salir, no hay nada que hacer ────────────────────
    $buzon->adjuntosPorUid[900] = [$xmlBuzon, $pdfBuzon];
    $sinBuzon = $recuperador->recuperar([array_merge($documento, ['correo_cuenta_id' => 99])]);
    assertRecuperador($sinBuzon['sin_buzon'] === 1,
        'un buzón que ya no está configurado se informa, no revienta');

    // ── Solo falta el PDF: el XML no se toca ───────────────────────────────
    file_put_contents($rutaXml, $xmlOriginal);
    $soloPdf = $recuperador->recuperar([$documento]);
    assertRecuperador($soloPdf['recuperados'] === 1 && is_file($rutaPdf),
        'repone solo la pieza que falta');
    assertRecuperador(hash_file('sha256', $rutaXml) === $documento['hash_xml'],
        'y deja intacta la que estaba');

    // ── Sin ruta guardada: se archiva por su fecha, como uno nuevo ─────────
    unlink($rutaXml);
    unlink($rutaPdf);
    $sinRuta = array_merge($documento, ['id' => 42, 'ruta_xml' => '', 'ruta_pdf' => '']);
    $nuevo = $recuperador->recuperar([$sinRuta]);
    assertRecuperador($nuevo['recuperados'] === 1, 'también repone un documento que nunca se archivó');
    assertRecuperador(isset($facturas->actualizados[42]['ruta_xml'])
        && dirname($facturas->actualizados[42]['ruta_xml']) === $mes,
        'y lo archiva en la carpeta del mes que le toca por fecha y tipo');

    echo "OK: RecuperadorDocumentos\n";
} finally {
    borrarArbolRecuperador($raiz);
}
