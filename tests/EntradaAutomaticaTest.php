<?php
/**
 * La entrada automática: qué entra solo y qué se queda esperando a alguien.
 *
 * Lo que se fija acá es el reparto, que es donde se puede hacer daño de
 * verdad. Un documento que entra cuando no debía queda registrado a nombre de
 * una empresa equivocada; uno que se queda esperando cuando debía entrar hace
 * que su factura del ERP figure «sin respaldo» aunque el XML esté bajado.
 *
 * Las reglas, una por una:
 *
 *   · El comprobante limpio entra, se marca en la Bandeja como importado y sus
 *     copias temporales se borran —ya está archivado en la carpeta de su mes—.
 *   · El rechazado por Hacienda y el de otra cédula NO entran: los tiene que
 *     mirar una persona, y por eso la Bandeja sigue existiendo.
 *   · El que no tiene empresa resuelta tampoco entra. En la tarea programada
 *     no hay sesión de la que deducirla y adivinarla sería meterlo en la
 *     contabilidad de otra.
 *   · Si el importador falla, la fila se queda pendiente CON SUS ARCHIVOS: son
 *     la única copia que hay del comprobante.
 *
 * El importador y la Bandeja son dobles: lo que se prueba es la decisión, no
 * el archivado —que tiene sus propias pruebas— ni la base.
 *
 * Uso: php tests/EntradaAutomaticaTest.php
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/EntradaAutomatica.php';

$fallos = 0;
function verificaEntrada($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

/** Bandeja de mentira: guarda las filas en memoria y anota qué se le pidió. */
class BandejaEntradaFalsa
{
    public $filas = [];
    public $importadas = [];

    public function getByIds(array $ids, $estado = null)
    {
        $salida = [];
        foreach ($ids as $id) {
            $fila = $this->filas[(int) $id] ?? null;
            if ($fila && ($estado === null || $fila['estado'] === $estado)) {
                $salida[] = $fila;
            }
        }
        return $salida;
    }

    public function marcarImportadasGeneral(array $ids)
    {
        foreach ($ids as $id) {
            $this->importadas[] = (int) $id;
            if (isset($this->filas[(int) $id])) {
                $this->filas[(int) $id]['estado'] = 'importada';
            }
        }
        return count($ids);
    }
}

/** Importador de mentira: devuelve lo que se le diga, o revienta si toca. */
class ImporterEntradaFalso
{
    public $respuestas = [];
    public $llamadas = [];
    public $revienta = false;

    public function importar($xml, $pdf = null, array $contexto = [])
    {
        $this->llamadas[] = ['xml' => $xml, 'pdf' => $pdf, 'contexto' => $contexto];
        if ($this->revienta) {
            throw new RuntimeException('La carpeta compartida no responde.');
        }
        return array_shift($this->respuestas)
            ?: ['estado' => 'importado', 'id' => 1, 'tipo_documento' => 'FE', 'pdf_pendiente' => false];
    }
}

/** Una EntradaAutomatica con los dos dobles ya enchufados. */
function entradaCon(BandejaEntradaFalsa $bandeja, ImporterEntradaFalso $importer, array $sociedades = [])
{
    $entrada = new EntradaAutomatica($bandeja, $sociedades);
    $prop = (new ReflectionClass(EntradaAutomatica::class))->getProperty('importer');
    $prop->setAccessible(true);
    $prop->setValue($entrada, $importer);
    return $entrada;
}

/** Un archivo temporal que hace de XML capturado en la Bandeja. */
function archivoCapturado($sufijo)
{
    $ruta = tempnam(sys_get_temp_dir(), 'entrada_' . $sufijo . '_');
    file_put_contents($ruta, '<?xml version="1.0"?><x/>');
    return $ruta;
}

$sociedades = [
    7 => ['id' => 7, 'nombre' => 'GRUPO BM SP S.A', 'cedula' => '3101639680'],
];

// ── El comprobante limpio entra solo ──────────────────────────────────
$xml = archivoCapturado('ok');
$pdf = archivoCapturado('okpdf');
$bandeja = new BandejaEntradaFalsa();
$bandeja->filas[10] = [
    'id' => 10, 'estado' => 'pendiente', 'sociedad_id' => 7, 'cuenta_id' => 3,
    'archivo_xml' => $xml, 'archivo_pdf' => $pdf,
    'numero_corto' => '00004354', 'tipo_doc' => 'FE',
];
$importer = new ImporterEntradaFalso();
$entrada = entradaCon($bandeja, $importer, $sociedades);

$res = $entrada->ingresar(
    ['filas' => [['id' => 10, 'estado' => 'pendiente', 'tipo_doc' => 'FE', 'sociedad_id' => 7]]],
    ['cuenta_id' => 3, 'carpeta' => 'INBOX', 'uidvalidity' => 55, 'uid' => 901, 'fecha_correo' => '2026-09-01 08:00:00']
);

verificaEntrada($res['ingresados'] === 1, 'el comprobante limpio entra sin que nadie lo autorice');
verificaEntrada($bandeja->importadas === [10], 'y su fila de la Bandeja queda marcada como importada');
verificaEntrada(!is_file($xml) && !is_file($pdf),
    'las copias de la Bandeja se borran: el par ya quedó archivado en su carpeta');

$ctx = $importer->llamadas[0]['contexto'] ?? [];
verificaEntrada((int) ($ctx['sociedad_id'] ?? 0) === 7,
    'entra a nombre de la empresa que resolvió la captura, no de la que esté seleccionada');
verificaEntrada((string) ($ctx['cedula_receptor'] ?? '') === '3101639680' && !empty($ctx['validar_receptor']),
    'y se vuelve a comprobar la cédula del receptor contra esa empresa');
verificaEntrada(($ctx['correo_uid'] ?? null) === 901 && ($ctx['correo_carpeta'] ?? '') === 'INBOX',
    'queda anotado de qué correo salió, para poder volver al original');
verificaEntrada(in_array('NC', EntradaAutomatica::TIPOS, true) && in_array('FE', EntradaAutomatica::TIPOS, true),
    'entran facturas y notas de crédito: cada una tiene su listado que cruzar');
verificaEntrada($entrada->hayQueVerificar(), 'entró una factura, así que hay listados que cruzar');

// ── Lo que Hacienda rechazó o no es de la empresa se queda ─────────────
foreach (['rechazada', 'otra_cedula'] as $estado) {
    $bandeja = new BandejaEntradaFalsa();
    $bandeja->filas[20] = [
        'id' => 20, 'estado' => $estado, 'sociedad_id' => 7,
        'archivo_xml' => archivoCapturado('ret'), 'numero_corto' => '00009999', 'tipo_doc' => 'FE',
    ];
    $importer = new ImporterEntradaFalso();
    $res = entradaCon($bandeja, $importer, $sociedades)->ingresar(
        ['filas' => [['id' => 20, 'estado' => $estado, 'tipo_doc' => 'FE', 'sociedad_id' => 7]]]
    );

    verificaEntrada($res['ingresados'] === 0 && $res['retenidos'] === 1,
        "un documento '{$estado}' no entra solo: lo tiene que ver una persona");
    verificaEntrada(empty($importer->llamadas),
        "ni siquiera se le pide al importador que lo intente ('{$estado}')");
    verificaEntrada($bandeja->importadas === [],
        "y su fila sigue en la Bandeja ('{$estado}')");
    @unlink((string) $bandeja->filas[20]['archivo_xml']);
}

// ── Sin empresa resuelta no se registra nada ───────────────────────────
$xml = archivoCapturado('sinsoc');
$bandeja = new BandejaEntradaFalsa();
$bandeja->filas[30] = [
    'id' => 30, 'estado' => 'pendiente', 'sociedad_id' => 0,
    'archivo_xml' => $xml, 'numero_corto' => '00001111', 'tipo_doc' => 'FE',
];
$importer = new ImporterEntradaFalso();
$res = entradaCon($bandeja, $importer, $sociedades)->ingresar(
    ['filas' => [['id' => 30, 'estado' => 'pendiente', 'tipo_doc' => 'FE', 'sociedad_id' => 0]]]
);
verificaEntrada($res['retenidos'] === 1 && empty($importer->llamadas),
    'sin empresa resuelta no entra: adivinarla sería meterlo en la contabilidad de otra');
verificaEntrada(is_file($xml), 'y el XML capturado se conserva para poder resolverlo a mano');
@unlink($xml);

// ── Si el importador falla, no se pierde el comprobante ────────────────
$xml = archivoCapturado('falla');
$pdf = archivoCapturado('fallapdf');
$bandeja = new BandejaEntradaFalsa();
$bandeja->filas[40] = [
    'id' => 40, 'estado' => 'pendiente', 'sociedad_id' => 7,
    'archivo_xml' => $xml, 'archivo_pdf' => $pdf,
    'numero_corto' => '00002222', 'tipo_doc' => 'FE',
];
$importer = new ImporterEntradaFalso();
$importer->revienta = true;
$entrada = entradaCon($bandeja, $importer, $sociedades);
$res = $entrada->ingresar(['filas' => [['id' => 40, 'estado' => 'pendiente', 'tipo_doc' => 'FE', 'sociedad_id' => 7]]]);

verificaEntrada($res['fallidos'] === 1 && $res['ingresados'] === 0, 'un fallo al importar se cuenta como tal');
verificaEntrada($bandeja->filas[40]['estado'] === 'pendiente',
    'la fila se queda pendiente en la Bandeja, lista para reintentarse');
verificaEntrada(is_file($xml) && is_file($pdf),
    'y sus archivos NO se borran: son la única copia que hay del comprobante');
verificaEntrada(!$entrada->hayQueVerificar(),
    'no entró nada, así que no hay listados que cruzar');
verificaEntrada(count($res['errores']) === 1 && strpos($res['errores'][0], '00002222') !== false,
    'el aviso dice de qué documento se trata, no solo que algo falló');
@unlink($xml);
@unlink($pdf);

// ── Una nota de crédito manda a revisar los listados de notas ──────────
$xml = archivoCapturado('nc');
$bandeja = new BandejaEntradaFalsa();
$bandeja->filas[50] = [
    'id' => 50, 'estado' => 'pendiente', 'sociedad_id' => 7,
    'archivo_xml' => $xml, 'numero_corto' => '00003333', 'tipo_doc' => 'NC',
];
$importer = new ImporterEntradaFalso();
$importer->respuestas[] = ['estado' => 'importado', 'id' => 5, 'tipo_documento' => 'NC', 'pdf_pendiente' => true];
$entrada = entradaCon($bandeja, $importer, $sociedades);
$res = $entrada->ingresar(['filas' => [['id' => 50, 'estado' => 'pendiente', 'tipo_doc' => 'NC', 'sociedad_id' => 7]]]);

verificaEntrada($res['ingresados'] === 1 && $res['pdf_pendientes'] === 1,
    'la nota de crédito entra igual, y se anota que su PDF quedó pendiente');
verificaEntrada($entrada->hayQueVerificar(), 'y deja pendiente el cruce de los listados de notas');

// ── El duplicado no se cuenta como documento nuevo ─────────────────────
$xml = archivoCapturado('dup');
$bandeja = new BandejaEntradaFalsa();
$bandeja->filas[60] = [
    'id' => 60, 'estado' => 'pendiente', 'sociedad_id' => 7,
    'archivo_xml' => $xml, 'numero_corto' => '00004444', 'tipo_doc' => 'FE',
];
$importer = new ImporterEntradaFalso();
$importer->respuestas[] = ['estado' => 'duplicado', 'id' => 9, 'tipo_documento' => 'FE', 'pdf_pendiente' => false];
$res = entradaCon($bandeja, $importer, $sociedades)->ingresar(
    ['filas' => [['id' => 60, 'estado' => 'pendiente', 'tipo_doc' => 'FE', 'sociedad_id' => 7]]]
);
verificaEntrada($res['duplicados'] === 1 && $res['ingresados'] === 0,
    'un comprobante que ya estaba cuenta como duplicado, no como entrada nueva');
verificaEntrada($bandeja->importadas === [60],
    'pero su fila igual se cierra: el documento está en el sistema, que es lo que importaba');

// ── El interruptor: encendido salvo que se diga lo contrario ───────────
verificaEntrada(EntradaAutomatica::activa([]) === true,
    'sin configuración escrita, los documentos entran solos');
verificaEntrada(EntradaAutomatica::activa(['entrada_automatica' => false]) === false,
    'y quien la apague vuelve a la revisión manual de siempre');
verificaEntrada(EntradaAutomatica::activa(['entrada_automatica' => true]) === true,
    'encendida explícitamente, sigue encendida');

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} verificación(es) fallaron\n");
    exit(1);
}

echo "OK: entra lo limpio, se queda lo que hay que mirar, y nada se pierde por el camino\n";
