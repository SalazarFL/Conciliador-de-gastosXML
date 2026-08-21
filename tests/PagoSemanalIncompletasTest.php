<?php
/**
 * Cuáles facturas no llegaron a la carpeta del pago, dichas con nombre.
 *
 * La carpeta del pago semanal solo recibe pares completos: un documento sin su
 * PDF no se puede entregar, así que no se copia. Eso está bien; lo que estaba
 * mal era el silencio. La carpeta salía con menos archivos que facturas tiene
 * el pago y no había forma de saber cuáles faltaban sin comparar a mano.
 *
 * Acá se comprueba el renglón que se le enseña a quien cargó el pago: que
 * nombre la factura como se la busca —número corto y proveedor—, que diga qué
 * mitad falta, y que cuando son demasiadas avise que la lista está recortada.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PorPagarController.php';

function assertIncompletas($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$ref = new ReflectionClass(PorPagarController::class);
$controlador = $ref->newInstanceWithoutConstructor();
$aviso = $ref->getMethod('avisoIncompletas');
$aviso->setAccessible(true);

/** Como lo devuelve reubicarArchivos() después de acomodar. */
function archivosCon($sinPar, array $incompletos)
{
    return ['movidos' => 0, 'por_fecha' => 0, 'pago_semanal' => 0, 'copias_pago' => 0,
            'sin_par' => $sinPar, 'incompletos' => $incompletos, 'aviso' => ''];
}

function docIncompleto($id, $numero, $proveedor, $falta)
{
    return ['documento_id' => $id, 'numero' => $numero, 'proveedor' => $proveedor,
            'fecha_emision' => '2026-08-20', 'falta' => $falta,
            'falta_xml' => $falta !== 'PDF', 'falta_pdf' => $falta !== 'XML'];
}

// ── Un pago entero completo no dice nada ─────────────────────────
$r = $aviso->invoke($controlador, archivosCon(0, []));
assertIncompletas($r['texto'] === '' && $r['detalle'] === [],
    'sin faltantes no se inventa un aviso');

// ── El caso corriente: el proveedor mandó el XML y nunca el PDF ──
$r = $aviso->invoke($controlador, archivosCon(1, [
    docIncompleto(41, '0000000168', 'FERRETERIA EL CLAVO', 'PDF'),
]));
assertIncompletas(strpos($r['texto'], '1 factura(s) no se copiaron') !== false,
    'dice cuántas se quedaron fuera');
assertIncompletas(strpos($r['texto'], 'les falta el XML o el PDF') !== false,
    'y por qué');

$items = $r['detalle']['aviso_lista']['items'];
assertIncompletas(count($items) === 1, 'nombra la factura');
assertIncompletas(strpos($items[0], '00000168') !== false,
    'con el número corto, que es el que se lee en el listado');
assertIncompletas(strpos($items[0], 'FERRETERIA EL CLAVO') !== false,
    'y el proveedor, que es como se la busca');
assertIncompletas(strpos($items[0], 'falta el PDF') !== false,
    'y qué mitad le falta');
assertIncompletas($r['detalle']['aviso_lista']['titulo'] === 'Sin copiar al pago (1)',
    'la lista lleva su propio título con el total');

// ── Sin número: se identifica igual, no se pierde el renglón ─────
$r = $aviso->invoke($controlador, archivosCon(1, [
    docIncompleto(77, '', '', 'XML y PDF'),
]));
$items = $r['detalle']['aviso_lista']['items'];
assertIncompletas(strpos($items[0], '77') !== false,
    'un documento sin número se nombra por su id antes que quedarse sin nombrar');

// ── Muchas: el total manda, la lista se recorta y lo avisa ───────
$muchos = [];
for ($i = 1; $i <= 50; $i++) {
    $muchos[] = docIncompleto($i, str_pad((string) $i, 10, '0', STR_PAD_LEFT), 'PROVEEDOR ' . $i, 'PDF');
}
$r = $aviso->invoke($controlador, archivosCon(83, $muchos));
assertIncompletas(strpos($r['texto'], '83 factura(s)') !== false,
    'el contador dice cuántas son en total, no cuántas se alcanzaron a nombrar');
assertIncompletas(strpos($r['texto'], 'Se nombran las primeras 50') !== false,
    'y avisa que la lista está recortada, para que nadie la lea como completa');
assertIncompletas($r['detalle']['aviso_lista']['titulo'] === 'Sin copiar al pago (83)',
    'el título también habla del total');

// ── El contador manda aunque la lista venga vacía ────────────────
// Puede pasar si el organizador topó el límite antes de anotar ninguna.
$r = $aviso->invoke($controlador, archivosCon(4, []));
assertIncompletas($r['texto'] !== '', 'con el contador basta para avisar');
assertIncompletas($r['detalle'] === [], 'y sin nombres no se pinta una lista vacía');

echo "OK PagoSemanalIncompletasTest\n";
