<?php
/**
 * La bandeja de líneas en revisión: que lo que un listado no sabe leer deje
 * de desaparecer, y que corregirlo una vez alcance para siempre.
 *
 * Se prueba sin base de datos: lo que importa aquí es que los parsers
 * rescaten la fila en vez de tirarla, y que una corrección guardada se pueda
 * volver a aplicar cuando el reporte de la semana siguiente traiga la misma
 * fila con otro saldo. Eso último es lo que decide si la bandeja es útil o
 * un impuesto semanal.
 */

require_once __DIR__ . '/../app/helpers/LineaRevision.php';
require_once __DIR__ . '/../app/helpers/NotasCreditoCsvParser.php';
require_once __DIR__ . '/../app/helpers/FacturasErpCsvParser.php';

function assertRev($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertRevIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revision_' . getmypid();
@mkdir($tmp, 0777, true);

// ------------------------------------------------------------------
// 1. Notas de crédito: la fila sin "NC-" ya no se pierde
// ------------------------------------------------------------------
//
// Este es el agujero que motivó todo. "NC 4946" (sin guion) y "N/C- 4947"
// son notas perfectamente válidas, pero como la regla es de forma y no de
// contenido desaparecían sin error, sin contarse y sin salir en pantalla:
// el listado decía "1 nota leída" y las otras dos no habían existido nunca.

$csvNotas = $tmp . DIRECTORY_SEPARATOR . 'notas.csv';
file_put_contents($csvNotas, implode("\n", [
    '"Grupo BM SP S.A","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"Notas de Crédito por Proveedor","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"Del 1 de Enero del 2026 al 23 de Julio del 2026","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"Proveedor","","","140000002","","","","","PROVEEDOR UNO S.A.","","","","","","","","","","","","","","","",""',
    '"Sucursal","","CEDI","","","","","","","","","","","","","","","","","","","","","",""',
    '"","NC- 4945","","","","21/06/2026","","","","","ENTRADA-A","","","","","","CRC","","1,000.00","","","250.00","","","1,000.00"',
    '"","NC 4946","","","","21/06/2026","","","","","ENTRADA-B","","","","","","CRC","","2,000.00","","","500.00","","","2,000.00"',
    '"","Total","","","","","","","","","","","","","","","","","3,000.00","","","750.00","","",""',
]) . "\n");

$notas = NotasCreditoCsvParser::parse($csvNotas);

assertRevIgual(1, count($notas['lineas']), 'la nota bien escrita se sigue leyendo');
assertRevIgual(1, count($notas['revision']), 'la nota mal escrita queda en revisión, no se tira');

$rescatada = $notas['revision'][0];
assertRevIgual(7, $rescatada['fila_origen'], 'la fila del archivo se conserva');
assertRev(strpos($rescatada['motivo'], 'NC-') !== false, 'el motivo dice por qué no se pudo leer');
assertRevIgual('NC 4946', $rescatada['campos']['documento'], 'el documento se deduce solo');
assertRevIgual('21/06/2026', $rescatada['campos']['fecha'], 'la fecha se deduce sola');
assertRevIgual('2,000.00', $rescatada['campos']['monto'], 'el monto se deduce solo');
assertRevIgual('500.00', $rescatada['campos']['saldo'], 'el saldo se deduce solo');
assertRevIgual('PROVEEDOR UNO S.A.', $rescatada['campos']['proveedor_nombre'],
    'el proveedor sale del encabezado de la banda');
assertRev($rescatada['firma'] !== '', 'la fila tiene firma');
assertRev(!empty($rescatada['celdas']), 'las celdas crudas viajan con la fila');

// El mobiliario del reporte se sigue descartando: una bandeja llena de
// títulos y totales no la mira nadie.
foreach ($notas['revision'] as $r) {
    assertRev(
        strpos(implode(' ', $r['celdas']), 'Total') === false,
        'los totales del reporte no pueden llegar a la bandeja'
    );
}

// ------------------------------------------------------------------
// 2. La firma no cambia cuando cambia el saldo
// ------------------------------------------------------------------
//
// Es la condición para que recordar sirva de algo: el reporte se vuelve a
// subir completo cada semana y el saldo de una factura baja cada vez que se
// le abona. Una firma que incluyera los importes no reconocería nunca la
// misma línea dos veces.

$semana1 = ['', 'NC 4946', '21/06/2026', 'ENTRADA-B', 'CRC', '2,000.00', '500.00'];
$semana2 = ['', 'NC 4946', '21/06/2026', 'ENTRADA-B', 'CRC', '2,000.00', '0.00'];
$otra    = ['', 'NC 4947', '21/06/2026', 'ENTRADA-B', 'CRC', '2,000.00', '500.00'];

assertRevIgual(
    LineaRevision::firma('notas_credito', $semana1, '140000002'),
    LineaRevision::firma('notas_credito', $semana2, '140000002'),
    'la misma fila con otro saldo conserva su firma'
);
assertRev(
    LineaRevision::firma('notas_credito', $semana1, '140000002')
        !== LineaRevision::firma('notas_credito', $otra, '140000002'),
    'dos documentos distintos no pueden compartir firma'
);
assertRev(
    LineaRevision::firma('notas_credito', $semana1, '140000002')
        !== LineaRevision::firma('notas_credito', $semana1, '140000999'),
    'la misma fila en otro proveedor es otra línea'
);

// ------------------------------------------------------------------
// 3. Reaplicar una corrección: se relee la columna, no se repite el número
// ------------------------------------------------------------------
//
// Guardar los valores pelados serviría una sola vez. La semana siguiente la
// fila viene con el saldo ya cambiado, y reponer el saldo viejo sería
// escribir en el listado un dato falso. Por eso se guarda además de qué
// celda salió cada cosa.

$corregido = [
    'documento' => 'NC- 4946',        // lo escribió la persona: no sale de ninguna celda
    'fecha' => '2026-06-21',          // sale de la celda 2, en otro formato
    'monto' => '2000.00',             // sale de la celda 5
    'saldo' => '500.00',              // sale de la celda 6
];
$mapa = LineaRevision::mapaDeCampos($corregido, $semana1);

assertRevIgual(null, $mapa['documento'], 'lo escrito a mano no se ata a ninguna celda');
assertRevIgual(2, $mapa['fecha'], 'la fecha se reconoce aunque el formato difiera');
assertRevIgual(5, $mapa['monto'], 'el monto se reconoce con separador de miles');
assertRevIgual(6, $mapa['saldo'], 'el saldo se reconoce');

$memoria = ['campos' => $corregido, 'celdas' => $semana1, 'mapa' => $mapa];

$igual = LineaRevision::reaplicar($memoria, $semana1);
assertRevIgual('500.00', $igual['saldo'], 'la fila idéntica se resuelve tal cual');

$nueva = LineaRevision::reaplicar($memoria, $semana2);
assertRev($nueva !== null, 'la fila con otro saldo se puede resolver');
assertRevIgual('0.00', $nueva['saldo'], 'el saldo se relee del archivo de esta semana');
assertRevIgual('2,000.00', $nueva['monto'], 'el monto también se relee de su columna');
assertRevIgual('NC- 4946', $nueva['documento'], 'lo escrito a mano se repone tal cual');

// Si la fila cambió de forma, no se adivina: se vuelve a preguntar. Meter un
// dato sacado de una columna que ya no es la misma sería peor que preguntar.
$deformada = ['', 'NC 4946', '21/06/2026', 'ENTRADA-B', 'CRC', '2,000.00'];
assertRevIgual(null, LineaRevision::reaplicar($memoria, $deformada),
    'una fila con otra cantidad de columnas no se reaplica a ciegas');

// ------------------------------------------------------------------
// 4. Facturas del ERP: el pie de página ya no se come la factura
// ------------------------------------------------------------------
//
// El pie se imprime ENCIMA de la fila que venía saliendo. Cuando arrastraba
// un cambio de sucursal, la fila entera se consumía como encabezado y la
// factura que venía en ella desaparecía sin dejar rastro.

$csvErp = $tmp . DIRECTORY_SEPARATOR . 'facturas.csv';
file_put_contents($csvErp, implode("\n", [
    '"Proveedor","","","140000003","PROVEEDOR UNO S.A.","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","","Sucursal","CEDI","","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","F- 1001","","","","","","12/05/2026","","","","","11/06/2026","","Exter","","¢","","","","100,000.00","","","","","","100,000.00","",""',
    '"Usuario: FCARRERA ","F- 1002","Sucursal","CEDI","","","","13/05/2026","Página: 5","","","","12/06/2026","","Exter","","¢","","","","50,000.00","","","","","","50,000.00","","Impreso: 06/08/2026 17:04:58"',
    '"","Total","","","","","","","","","","","","","","","","150,000.00","","","","","","","","","","150,000.00",""',
    '"","Total General","","","","","","","","","","","","","","","","150,000.00","","","","","","","","","","150,000.00",""',
]) . "\n");

$erp = FacturasErpCsvParser::parseArchivo($csvErp);

assertRevIgual(2, count($erp['facturas']), 'la factura tapada por el pie de página se lee igual');
assertRevIgual('CEDI', $erp['facturas'][1]['sucursal'], 'y conserva la sucursal que traía el pie');
assertRevIgual('1002', $erp['facturas'][1]['documento'], 'con su número');
assertRev($erp['cuadre']['ok'], 'y el total impreso cuadra, que es la prueba de que no falta nada');

// ------------------------------------------------------------------
// 5. Una línea irreconocible ya no tumba el archivo entero
// ------------------------------------------------------------------

$csvRaro = $tmp . DIRECTORY_SEPARATOR . 'facturas_raras.csv';
file_put_contents($csvRaro, implode("\n", [
    '"Proveedor","","","140000003","PROVEEDOR UNO S.A.","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","","Sucursal","CEDI","","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","F- 1001","","","","","","12/05/2026","","","","","11/06/2026","","Exter","","¢","","","","100,000.00","","","","","","100,000.00","",""',
    '"","F- ","","","","","","porquería","","","","","11/06/2026","","Exter","","¢","","","","33,000.00","","","","","","33,000.00","",""',
]) . "\n");

$raro = FacturasErpCsvParser::parseArchivo($csvRaro);

assertRev($raro['ok'], 'una fila ilegible ya no impide importar el resto del archivo');
assertRevIgual(1, count($raro['facturas']), 'la factura buena entra');
assertRevIgual(1, count($raro['revision']), 'la fila ilegible queda en revisión');
assertRevIgual('33,000.00', $raro['revision'][0]['campos']['monto'],
    'con el monto ya deducido para no tener que teclearlo');
assertRevIgual('140000003', $raro['revision'][0]['campos']['proveedor_codigo'],
    'y el proveedor de la banda en la que estaba');

// ------------------------------------------------------------------
// 6. El cuadre no grita por las facturas en dólares
// ------------------------------------------------------------------
//
// El reporte imprime sus subtotales con los dólares ya convertidos a
// colones, y aquí no hay tipo de cambio con qué reproducirlo. Compararlos
// igual hacía sonar "no cuadra" en todas las cargas por algo que sí cuadra.
//
// Las cifras salen de un caso real —una sucursal de un proveedor que factura
// en las dos monedas— pero el proveedor va anónimo: el repositorio es
// público y el nombre no aporta nada a la prueba.

$csvDolar = $tmp . DIRECTORY_SEPARATOR . 'facturas_dolar.csv';
file_put_contents($csvDolar, implode("\n", [
    '"Proveedor","","","140000900","PROVEEDOR EN DOS MONEDAS S.A.","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","","Sucursal","SUCURSAL SUR","","","","","","","","","","","","","","","","","","","","","","","","",""',
    '"","F- 22801514010000017408","","","","","","14/07/2026","","","","","13/08/2026","","Local","","¢","","","","38,985.00","","","","","","0.00","",""',
    '"","F- 22801514010000017414","","","","","","14/07/2026","","","","","13/08/2026","","Local","","$ (","","","","50.85","","","","","","0.00","",""',
    '"","Total","","","","","","","","","","","","","","","","62,172.60","","","","","","","","","","0.00",""',
    '"","Total Proveedor","","","","","","","","","","","","","","","","62,172.60","","","","","","","","","","0.00",""',
]) . "\n");

$dolar = FacturasErpCsvParser::parseArchivo($csvDolar);

assertRevIgual(2, count($dolar['facturas']), 'las dos facturas se leen');
assertRevIgual(0, count($dolar['cuadre']['descuadres']),
    'un grupo con dólares no puede reportarse como descuadre: el reporte lo imprime convertido');
assertRevIgual(2, $dolar['cuadre']['no_comparables'],
    'pero tampoco se ignora en silencio: se cuenta como no comparable');

// Limpieza
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
    @unlink($archivo);
}
@rmdir($tmp);

echo "OK: LineaRevision\n";
