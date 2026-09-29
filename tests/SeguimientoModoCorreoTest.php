<?php
/**
 * El modo Correo de la cola de seguimiento.
 *
 * Es la pregunta inversa del modo Sistema: en vez de "qué le falta a este
 * registro del ERP", pregunta "cuál de estos comprobantes XML no está todavía
 * en el ERP". Lo que se comprueba acá es que esa inversión sea de verdad —que
 * la cola salga de facturas_xml y no del reporte—, que el estado se calcule
 * por el enganche y no por un saldo que un comprobante no tiene, y que los dos
 * modos no se pisen: ni las pestañas, ni los tipos de documento, ni la marca
 * a mano de un renglón.
 *
 * Se prueba sin base, capturando el SQL que el modelo arma.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertModoCorreo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Contesta lo justo y guarda el SQL que le pidieron. */
class SeguimientoModoFalso extends Seguimiento
{
    public $sqlFilas = '';
    public $sqlConteo = '';
    public $paramsFilas = [];

    public function __construct() {}

    protected function fetchColumn($sql, $params = [])
    {
        $this->sqlConteo = $sql;
        return 0;
    }

    protected function fetchAll($sql, $params = [])
    {
        $this->sqlFilas = $sql;
        $this->paramsFilas = $params;
        return [];
    }

    protected function fetchOne($sql, $params = [])
    {
        $this->sqlFilas = $sql;
        $this->paramsFilas = $params;
        return null;
    }
}

// ── Las pestañas y las tareas son distintas en cada modo ────────────────────

assertModoCorreo(Seguimiento::modo('correo') === 'correo'
    && Seguimiento::modo('sistema') === 'sistema',
    'los dos modos se reconocen por su nombre');
assertModoCorreo(Seguimiento::modo('inventado') === Seguimiento::MODO_SISTEMA,
    'un modo que no existe cae en el de siempre, no en uno vacío');

$estadosCorreo = Seguimiento::estadosDe('correo');
assertModoCorreo(!isset($estadosCorreo['lista']),
    "el modo Correo no tiene pestaña 'Listas': un comprobante está en el sistema o no");
assertModoCorreo(count($estadosCorreo) === 3
    && isset($estadosCorreo['pendiente'], $estadosCorreo['revision'], $estadosCorreo['cerrada']),
    'el modo Correo tiene las tres pestañas: sin vincular, en seguimiento y cerradas');
assertModoCorreo(count(Seguimiento::estadosDe('sistema')) === 4,
    'el modo Sistema conserva sus cuatro');

assertModoCorreo(array_keys(Seguimiento::tareasDe('correo')) === ['sin_sistema', 'completo'],
    'en el modo Correo lo que falta es una sola cosa: el registro en el sistema');
assertModoCorreo(array_keys(Seguimiento::origenesDe('correo')) === ['xml_factura', 'xml_nota'],
    'los tipos de documento del modo Correo salen de facturas_xml');

// ── La cola sale de los comprobantes, no del reporte ────────────────────────

$correo = (new SeguimientoModoFalso())->enModo('correo');
$correo->cola(Seguimiento::filtrosDesde(['modo' => 'correo', 'vista' => 'todo']), 1, 50);
$sql = $correo->sqlFilas;

assertModoCorreo(strpos($sql, 'FROM facturas_xml x') !== false,
    'la cola del modo Correo arranca de los comprobantes XML cargados');
assertModoCorreo(strpos($sql, 'notas_credito_listados') === false
    && strpos($sql, 'FROM facturas_erp pe') === false,
    'y no del reporte del ERP, que es de donde sale la otra');

// Las dos mitades: facturas y notas, cada una contra su tabla del sistema.
assertModoCorreo(strpos($sql, "'xml_factura' AS origen") !== false
    && strpos($sql, "'xml_nota' AS origen") !== false,
    'entran las facturas y las notas de crédito XML, que es lo que hay cargado');
assertModoCorreo(strpos($sql, "x.tipo_documento = 'NC'") !== false,
    'las notas se reconocen por su tipo de documento');
assertModoCorreo(strpos($sql, 'x.tipo_documento IS NULL') !== false,
    'los comprobantes viejos, de antes de que existiera la columna, cuentan como facturas');

// El enganche: una factura se registra en facturas_erp; una nota, como línea
// del reporte de notas.
assertModoCorreo(strpos($sql, 'FROM facturas_erp WHERE factura_xml_id IS NOT NULL') !== false,
    'una factura XML se da por registrada si alguna fila del ERP la engancha');
assertModoCorreo(strpos($sql, 'FROM notas_credito_lineas WHERE factura_xml_id IS NOT NULL') !== false,
    'y una nota XML, si alguna línea del reporte de notas la engancha');

/*
 * Nada impide que dos registros del ERP apunten al mismo comprobante —pasa
 * cuando alguien carga dos veces el reporte y el emparejador acierta las dos—.
 * Con un JOIN directo ese comprobante saldría dos veces en la cola, contado
 * dos veces en las pestañas y marcable dos veces en la tanda. Agrupar antes,
 * quedándose con el primero, es lo que lo impide.
 */
assertModoCorreo(substr_count($sql, 'GROUP BY factura_xml_id') === 2,
    'el enganche se agrupa por comprobante antes de unirlo, uno por rama');
assertModoCorreo(substr_count($sql, 'MIN(id) AS id') === 2,
    'y se queda con el primer registro de cada uno, no con todos');
assertModoCorreo(strpos($sql, 'LEFT JOIN facturas_erp sis ON sis.id = eng.id') !== false,
    'el registro del sistema se lee del enganche ya agrupado');

// ── El saldo sale del registro del sistema, no del comprobante ──────────────
//
// Un XML dice cuánto se facturó, no cuánto queda por pagar: su saldo es el
// del registro al que está enganchado, y sin enganche no hay ninguno.
assertModoCorreo(strpos($sql, 'COALESCE(sis.saldo_pago, sis.saldo) AS saldo') !== false,
    'la factura toma el saldo del ERP, congelado si entró a un pago');
assertModoCorreo(strpos($sql, 'sis.saldo AS saldo') !== false,
    'y la nota, el de su línea del reporte');
assertModoCorreo(strpos($sql, 'x.total AS saldo') === false,
    'el total del comprobante ya no se hace pasar por saldo');

// ── El estado se calcula por el enganche, no por un saldo que no existe ─────

assertModoCorreo(strpos($sql, "CASE WHEN c.tarea = 'completo' THEN 'cerrada' ELSE 'pendiente' END") !== false,
    'enganchado se cierra solo; sin enganchar se queda en sin vincular');
assertModoCorreo(strpos($sql, "WHEN ABS(c.saldo) <= 0.005 THEN 'cerrada'") === false,
    'el saldo no decide nada acá: un comprobante XML no tiene saldo propio');
assertModoCorreo(strpos($sql, "'lista'") === false,
    "y nunca se calcula 'lista', que en este modo no existe");

// La marca a mano sigue mandando: es lo que deja mover un renglón a
// seguimiento aunque ya esté en el sistema, y al revés.
assertModoCorreo(strpos($sql, 'COALESCE(s.estado,') !== false,
    'la marca a mano manda sobre el cálculo, igual que en el modo Sistema');

// ── El modo Sistema sigue intacto ───────────────────────────────────────────

$sistema = (new SeguimientoModoFalso())->enModo('sistema');
$sistema->cola(Seguimiento::filtrosDesde(['modo' => 'sistema', 'vista' => 'todo']), 1, 50);

assertModoCorreo(strpos($sistema->sqlFilas, 'FROM facturas_erp pe') !== false
    && strpos($sistema->sqlFilas, 'FROM notas_credito_lineas l') !== false,
    'el modo Sistema sigue saliendo del ERP');
assertModoCorreo(strpos($sistema->sqlFilas, 'FROM facturas_xml x') === false,
    'y no se le coló la cola del correo');
assertModoCorreo(strpos($sistema->sqlFilas, "WHEN ABS(c.saldo) <= 0.005 THEN 'cerrada'") !== false,
    'su estado se sigue calculando por el saldo y el respaldo');

// ── Un filtro de un modo no se cuela en el otro ─────────────────────────────

$cruzado = Seguimiento::filtrosDesde([
    'modo' => 'correo',
    'origen' => 'factura',        // el de Sistema
    'vista' => 'lista',           // la pestaña que Correo no tiene
    'tarea' => 'falta_pdf',       // la tarea que Correo no tiene
    'clase' => 'directa',
    'aplicacion' => 'aplicable',
]);
assertModoCorreo($cruzado['origen'] === '',
    "'factura' no es un tipo de documento del modo Correo: se descarta en vez de vaciar la lista");
assertModoCorreo($cruzado['vista'] === 'pendiente',
    "'lista' no es una pestaña del modo Correo: se cae en la primera");
assertModoCorreo($cruzado['tarea'] === '',
    "'falta_pdf' no es una tarea del modo Correo: se descarta");
assertModoCorreo($cruzado['clase'] === '' && $cruzado['aplicacion'] === '',
    'la clase de nota y la situación frente a la factura salen del reporte, no del XML');

$propio = Seguimiento::filtrosDesde([
    'modo' => 'correo', 'origen' => 'xml_nota', 'tarea' => 'sin_sistema',
    'llegada' => 'correo',
]);
assertModoCorreo($propio['origen'] === 'xml_nota' && $propio['tarea'] === 'sin_sistema',
    'los suyos sí pasan');
assertModoCorreo($propio['llegada'] === 'correo',
    'y también por dónde llegó el comprobante');
assertModoCorreo(Seguimiento::filtrosDesde(['modo' => 'sistema', 'llegada' => 'correo'])['llegada'] === '',
    'que en el modo Sistema no significa nada: un registro del ERP lo digitó alguien');

// ── El rango de fechas de emisión ───────────────────────────────────────────

$rango = function (array $extra) {
    return Seguimiento::filtrosDesde(
        array_merge(['modo' => 'correo', 'vista' => 'todo'], $extra));
};

$julio = $rango(['desde' => '2026-07-01', 'hasta' => '2026-07-31']);
assertModoCorreo($julio['desde'] === '2026-07-01' && $julio['hasta'] === '2026-07-31',
    'el rango de fechas llega entero a la consulta');

/*
 * Con dos selectores en la barra es fácil poner el 31 en "desde" y el 1 en
 * "hasta". Tal cual, eso da una lista vacía sin nada que lo explique: parece
 * que no hay documentos de ese mes. Se entiende como lo que se quiso decir.
 */
$alReves = $rango(['desde' => '2026-07-31', 'hasta' => '2026-07-01']);
assertModoCorreo($alReves['desde'] === '2026-07-01' && $alReves['hasta'] === '2026-07-31',
    'un rango al revés se endereza en vez de vaciar la cola');

assertModoCorreo($rango(['desde' => 'ayer', 'hasta' => '31/07/2026'])['desde'] === ''
    && $rango(['desde' => 'ayer', 'hasta' => '31/07/2026'])['hasta'] === '',
    'lo que no es una fecha se descarta, no se cuela en la consulta');

// El filtro se resuelve en SQL, no recortando la página: si no, la cuenta de
// las pestañas hablaría de un período y la lista de otro.
$fechas = (new SeguimientoModoFalso())->enModo('correo');
$fechas->cola($julio, 1, 50);
assertModoCorreo(strpos($fechas->sqlFilas, 'c.fecha >= ?') !== false
    && strpos($fechas->sqlFilas, 'c.fecha <= ?') !== false,
    'el rango se aplica en SQL, sobre la cola entera');
assertModoCorreo(in_array('2026-07-01', $fechas->paramsFilas, true)
    && in_array('2026-07-31', $fechas->paramsFilas, true),
    'y con las fechas que se pidieron');

// En el modo Correo esa fecha es la de emisión del comprobante, que es lo que
// dice la etiqueta del filtro.
$correoSql = (new SeguimientoModoFalso())->enModo('correo');
$correoSql->cola($julio, 1, 50);
assertModoCorreo(strpos($correoSql->sqlFilas, 'x.fecha_emision AS fecha') !== false,
    'la fecha de la cola del modo Correo es la de emisión del XML');

// ── La gestión de los dos modos convive sin pisarse ─────────────────────────

// Los id se repiten entre tablas: la factura 40 del ERP y el comprobante 40
// son documentos distintos, y el origen es lo único que los separa.
assertModoCorreo(
    Seguimiento::normalizarItems(['xml_factura|40', 'factura|40', 'xml_nota|7'])
    === [
        ['origen' => 'xml_factura', 'referencia_id' => 40],
        ['origen' => 'factura', 'referencia_id' => 40],
        ['origen' => 'xml_nota', 'referencia_id' => 7],
    ],
    'los cuatro orígenes entran, y un mismo id en dos tablas son dos renglones'
);
assertModoCorreo(Seguimiento::normalizarItems(['xml_inventado|3']) === [],
    'un origen que no existe se descarta');

// ── Un dato por columna, y la cabecera y las filas cuentan lo mismo ─────────
//
// El saldo vivió plegado bajo el monto, y la fecha, la sucursal y la
// diferencia colgaban de otras celdas. Cada dato tiene ahora su columna, y la
// cabecera, la fila de buscadores y cada renglón tienen que declarar las
// mismas, o la tabla sale corrida.
$vistaSeg = file_get_contents(__DIR__ . '/../app/views/seguimiento/index.php');

preg_match('~<tr class="seg-head-labels">.*?</tr>~s', $vistaSeg, $mCab);
preg_match('~<tr class="seg-search-row">.*?</tr>~s', $vistaSeg, $mBusca);
preg_match('~<tbody>.*</tbody>~s', $vistaSeg, $mCuerpo);
$colsDe = static function ($html, $etiqueta) {
    preg_match_all('~<' . $etiqueta . '[^>]*data-col="([a-z0-9_]+)"~', (string) $html, $m);
    $ids = array_unique($m[1]);
    sort($ids);
    return $ids;
};
$enCabecera = $colsDe($mCab[0] ?? '', 'th');
assertModoCorreo($mCab && $mBusca && $mCuerpo && $enCabecera !== [],
    'la vista tiene cabecera, fila de buscadores y cuerpo');
assertModoCorreo($colsDe($mBusca[0] ?? '', 'th') === $enCabecera,
    'la fila de buscadores tiene un hueco por cada columna de la cabecera');
assertModoCorreo($colsDe($mCuerpo[0] ?? '', 'td') === $enCabecera,
    'cada columna de la cabecera tiene su celda en el renglón, y ninguna de más');
foreach (['fecha', 'sucursal', 'diferencia'] as $suelta) {
    assertModoCorreo(in_array($suelta, $enCabecera, true),
        "«{$suelta}» es una columna propia, no una sub-línea de otra celda");
}
assertModoCorreo($mCab && (bool) preg_match(
        '~<\?php if \(!\$esModoCorreo\): \?>\s*<th[^>]*data-col="notas_aplicables"~', $mCab[0]),
    'la columna de notas aplicables no sale en el modo Correo');
assertModoCorreo($mCab && strpos($mCab[0], 'Columnas::cabecerasExtra') !== false,
    'y detrás de ellas van las que se pueden encender');
assertModoCorreo($mCab && strpos($mCab[0], "'Monto sistema'") !== false
    && strpos($mCab[0], '>Saldo<') !== false,
    'Monto y Saldo son dos columnas, no una');
// El monto del XML va a la par del del sistema, y solo donde hay sistema: en
// el modo Correo el monto de la fila ya es el del comprobante.
assertModoCorreo($mCab && (bool) preg_match(
        '~<\?php if \(!\$esModoCorreo\): \?>\s*<th[^>]*data-col="monto_xml"~', $mCab[0]),
    'la columna Monto XML no sale en el modo Correo');

// La fila de "no hay nada" tiene que cruzar la tabla entera, con todas las
// columnas encendidas: si se quedara en siete, al encender unas cuantas el
// mensaje ocuparía media tabla y el resto saldría en blanco.
require_once __DIR__ . '/../app/helpers/Columnas.php';
preg_match('~<tr class="empty-row">\s*<td colspan="(\d+)"~', $vistaSeg, $mVacia);
$columnasPosibles = count(Columnas::catalogo('seguimiento')) + 2;   // + casilla y acciones
assertModoCorreo($mVacia && (int) $mVacia[1] >= $columnasPosibles,
    'la fila de "no hay nada" cruza la tabla aunque se enciendan todas las columnas');
assertModoCorreo(strpos($vistaSeg, 'name="col_monto"') !== false
    && strpos($vistaSeg, 'name="col_saldo"') !== false,
    'cada una conserva su casilla de búsqueda');
assertModoCorreo(strpos($vistaSeg, 'seg-busca-par') === false,
    'y ya no las comparten apretadas en la misma celda');

echo "OK: modo Correo de seguimiento\n";
