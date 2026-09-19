<?php
/**
 * Los filtros de la cola llevados al borde: vacíos, nulos, arreglos donde se
 * esperaba texto, valores de otro modo, fechas y montos mal escritos, y la
 * paginación con números absurdos.
 *
 * Lo que se protege es lo que la pantalla promete: que un parámetro raro en la
 * URL nunca produzca un SQL distinto del saneado, que la cuenta y la página
 * pregunten lo mismo, y que las pestañas cuenten sin su propio filtro.
 *
 * Sin base: se mira el SQL y los parámetros que el modelo habría mandado.
 */
require_once __DIR__ . '/soporte/SeguimientoSoporte.php';

Prueba::iniciar('filtros, SQL y paginación de Seguimiento en el borde');

const OMISION = [
    'modo' => 'sistema', 'llegada' => '', 'aplicacion' => '', 'vista' => 'pendiente',
    'origen' => '', 'tarea' => '', 'marca' => '', 'clase' => '', 'responsable' => '',
    'proveedor' => '', 'sucursal' => '', 'contexto_id' => 0, 'desde' => '', 'hasta' => '',
    'monto_min' => '', 'condicion_saldo' => '', 'q' => '', 'col_documento' => '',
    'col_proveedor' => '', 'col_monto' => '', 'col_saldo' => '', 'col_respaldo' => '',
    'col_tarea' => '', 'orden' => 'reciente', 'sociedad_id' => 0,
];

function valoresPorOmision(array $filtros, string $como): void
{
    foreach (OMISION as $clave => $esperado) {
        igual($esperado, $filtros[$clave] ?? '(falta)', "{$como}: '{$clave}' cae en su valor por omisión");
    }
}

/**
 * El COUNT aparte (null si no hizo falta) y la consulta de la última página
 * que pidió cola(). El total viaja en la página con COUNT(*) OVER (); el COUNT
 * aparte solo existe cuando se pidió una página más allá de la última.
 */
function consultasDeCola(SeguimientoGrabadora $m): array
{
    $conteo = $m->de('fetchColumn', 'SELECT COUNT(*)');
    $pagina = $m->de('fetchAll', 'ORDER BY');
    verificar($pagina, 'cola() pidió su página');
    verificar(strpos(end($pagina)['sql'], 'COUNT(*) OVER () AS total_filas') !== false, 'la página trae su total');
    return [$conteo ? end($conteo) : null, end($pagina)];
}

/** Filas de página como las devuelve la base: cada una con el total. */
function filasConTotal(int $cuantas, int $total): array
{
    $filas = [];
    for ($i = 0; $i < $cuantas; $i++) {
        $filas[] = Soporte::filaCola(['referencia_id' => (string) ($i + 1), 'total_filas' => (string) $total]);
    }
    return $filas;
}

// ── Lectura de la petición: happy path y valores por omisión ────────────────

Prueba::caso('sin parámetros, cada filtro vale lo de omisión', function () {
    valoresPorOmision(Seguimiento::filtrosDesde([]), 'petición vacía');
});

Prueba::caso('parámetros en null valen lo mismo que ausentes', function () {
    valoresPorOmision(Seguimiento::filtrosDesde(array_fill_keys(array_keys(OMISION), null)), 'todo en null');
});

Prueba::caso('un arreglo donde se esperaba texto (?vista[]=x) se ignora sin avisos de PHP', function () {
    valoresPorOmision(Seguimiento::filtrosDesde(array_fill_keys(array_keys(OMISION), ['x', ['y']])), 'todo arreglos');
});

Prueba::caso('el camino feliz: una barra completa llega saneada', function () {
    $f = Seguimiento::filtrosDesde([
        'modo' => 'sistema', 'vista' => 'revision', 'origen' => 'nota_credito', 'tarea' => 'falta_pdf',
        'marca' => 'mano', 'clase' => 'directa', 'sucursal' => '  CENTRAL ', 'q' => '  FE-1  ',
        'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'orden' => 'proveedor',
        'condicion_saldo' => 'activas', 'col_monto' => '₡1,200.50', 'contexto_id' => '12',
    ], 9);
    igual('revision', $f['vista'], 'vista');
    igual('nota_credito', $f['origen'], 'origen');
    igual('falta_pdf', $f['tarea'], 'tarea');
    igual('mano', $f['marca'], 'marca');
    igual('directa', $f['clase'], 'clase');
    igual('CENTRAL', $f['sucursal'], 'la sucursal llega sin espacios de sobra');
    igual('FE-1', $f['q'], 'el buscador llega sin espacios de sobra');
    igual('proveedor', $f['orden'], 'orden');
    igual('activas', $f['condicion_saldo'], 'saldo');
    igual('1200.50', $f['col_monto'], 'el importe copiado de la pantalla se limpia');
    igual(12, $f['contexto_id'], 'el contexto es un entero');
    igual(9, $f['sociedad_id'], 'la empresa la pone el servidor, no la URL');
});

Prueba::caso('el modo se reconoce con espacios pero no en mayúsculas', function () {
    igual('correo', Seguimiento::filtrosDesde(['modo' => ' correo '])['modo'], 'con espacios');
    igual('sistema', Seguimiento::filtrosDesde(['modo' => 'CORREO'])['modo'], 'en mayúsculas cae en sistema');
    igual('sistema', Seguimiento::modo(null), 'null cae en sistema');
});

Prueba::caso('las pestañas son las del modo', function () {
    igual('lista', Seguimiento::filtrosDesde(['vista' => 'lista'])['vista'], "'lista' existe en sistema");
    igual('pendiente', Seguimiento::filtrosDesde(['modo' => 'correo', 'vista' => 'lista'])['vista'],
        "'lista' no existe en correo");
    igual('todo', Seguimiento::filtrosDesde(['modo' => 'correo', 'vista' => 'todo'])['vista'], "'todo' vale en los dos");
    igual('pendiente', Seguimiento::filtrosDesde(['vista' => ''])['vista'], 'vista vacía');
    igual('pendiente', Seguimiento::filtrosDesde(['vista' => 'REVISION'])['vista'], 'vista en mayúsculas');
});

Prueba::caso('un tipo de documento de otro modo no se cuela', function () {
    igual('', Seguimiento::filtrosDesde(['origen' => 'xml_factura'])['origen'], 'xml en sistema');
    igual('', Seguimiento::filtrosDesde(['modo' => 'correo', 'origen' => 'factura'])['origen'], 'factura en correo');
    igual('xml_nota', Seguimiento::filtrosDesde(['modo' => 'correo', 'origen' => 'xml_nota'])['origen'], 'xml en correo');
});

Prueba::caso('la clase se sanea, se deduplica y desaparece donde no tiene sentido', function () {
    igual('directa,costo', Seguimiento::filtrosDesde(['clase' => 'DIRECTA, costo ,ajuste,,costo'])['clase'],
        'minúsculas, sin espacios, sin ajuste y sin repetidos');
    igual('', Seguimiento::filtrosDesde(['modo' => 'correo', 'clase' => 'directa'])['clase'], 'en correo no hay clase');
    igual('', Seguimiento::filtrosDesde(['origen' => 'factura', 'clase' => 'directa'])['clase'], 'mirando facturas');
});

Prueba::caso('la situación de la nota respeta el tipo de documento y el modo', function () {
    $aplicable = AplicacionNotaCredito::APLICABLE;
    igual('con_nota', Seguimiento::filtrosDesde(['origen' => 'factura', 'aplicacion' => 'con_nota'])['aplicacion'],
        "'con_nota' también es una pregunta sobre facturas");
    igual('', Seguimiento::filtrosDesde(['origen' => 'factura', 'aplicacion' => $aplicable])['aplicacion'],
        'un estado de nota no se le pregunta a una factura');
    igual($aplicable, Seguimiento::filtrosDesde(['origen' => 'nota_credito', 'aplicacion' => $aplicable])['aplicacion'],
        'sí a una nota');
    igual('', Seguimiento::filtrosDesde(['aplicacion' => 'inventada'])['aplicacion'], 'inventada');
    igual('', Seguimiento::filtrosDesde(['modo' => 'correo', 'aplicacion' => 'con_nota'])['aplicacion'], 'en correo');
});

Prueba::caso('cómo llegó y qué falta solo valen en su modo', function () {
    igual('', Seguimiento::filtrosDesde(['llegada' => 'correo'])['llegada'], 'llegada en sistema');
    igual('carga', Seguimiento::filtrosDesde(['modo' => 'correo', 'llegada' => 'carga'])['llegada'], 'llegada en correo');
    igual('', Seguimiento::filtrosDesde(['modo' => 'correo', 'llegada' => 'fax'])['llegada'], 'llegada inventada');
    igual('', Seguimiento::filtrosDesde(['tarea' => 'sin_sistema'])['tarea'], 'tarea de correo en sistema');
    igual('', Seguimiento::filtrosDesde(['modo' => 'correo', 'tarea' => 'falta_pdf'])['tarea'], 'tarea de sistema en correo');
});

Prueba::caso('las fechas: formato estricto, espacios tolerados y rango enderezado', function () {
    igual('', Seguimiento::filtrosDesde(['desde' => '13/09/2026'])['desde'], 'formato local');
    igual('', Seguimiento::filtrosDesde(['desde' => '2026-9-1'])['desde'], 'sin ceros');
    igual('2026-09-01', Seguimiento::filtrosDesde(['desde' => ' 2026-09-01 '])['desde'], 'con espacios');
    $f = Seguimiento::filtrosDesde(['desde' => '2026-09-30', 'hasta' => '2026-09-01']);
    igual(['2026-09-01', '2026-09-30'], [$f['desde'], $f['hasta']], 'al revés se endereza');
    $f = Seguimiento::filtrosDesde(['desde' => '2026-09-30']);
    igual(['2026-09-30', ''], [$f['desde'], $f['hasta']], 'con un solo extremo no se inventa el otro');
});

Prueba::caso('los importes aceptan lo que se copia de la pantalla y nada más', function () {
    $casos = [
        '₡1,200.50' => '1200.50', '$ 75' => '75', '-12.5' => '-12.5', "1\u{00A0}200" => '1200',
        'abc' => '', '%' => '', '_' => '', '1.2.3' => '', '.' => '', '-' => '', '1e5' => '', '' => '',
    ];
    foreach ($casos as $entrada => $esperado) {
        igual($esperado, Seguimiento::filtrosDesde(['col_monto' => $entrada])['col_monto'],
            'importe ' . json_encode((string) $entrada, JSON_UNESCAPED_UNICODE));
    }
});

Prueba::caso('la clave de proveedor exige prefijo y se recorta a 150 caracteres', function () {
    igual('cod:ABC', Seguimiento::filtrosDesde(['proveedor' => 'cod:ABC'])['proveedor'], 'con prefijo');
    igual('', Seguimiento::filtrosDesde(['proveedor' => 'ABC'])['proveedor'], 'sin prefijo');
    igual('', Seguimiento::filtrosDesde(['proveedor' => 'COD:ABC'])['proveedor'], 'prefijo en mayúsculas');
    igual('nom:' . str_repeat('x', 150), Seguimiento::filtrosDesde(['proveedor' => 'nom:' . str_repeat('x', 400)])['proveedor'],
        'nombre larguísimo');
    // Una clave sin valor no llega a resolverse: resolver() devolvería null y
    // condicion() leería índices de null.
    igual('', Seguimiento::filtrosDesde(['proveedor' => 'cod:   '])['proveedor'], 'prefijo sin valor');
    (new SeguimientoGrabadora())->cola(['vista' => 'todo', 'proveedor' => 'cod:   '], 1, 50);
});

Prueba::caso('listas cerradas: saldo, marca, orden y contexto', function () {
    igual('', Seguimiento::filtrosDesde(['condicion_saldo' => 'ACTIVAS'])['condicion_saldo'], 'saldo en mayúsculas');
    igual('', Seguimiento::filtrosDesde(['marca' => 'MANO'])['marca'], 'marca en mayúsculas');
    igual('reciente', Seguimiento::filtrosDesde(['orden' => 'fecha DESC; DROP TABLE seguimiento'])['orden'], 'orden inyectado');
    igual(0, Seguimiento::filtrosDesde(['contexto_id' => 'abc'])['contexto_id'], 'contexto no numérico');
});

// ── Traducción a SQL ────────────────────────────────────────────────────────

Prueba::caso('la empresa es la primera condición, y sin empresa no se acota', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(Seguimiento::filtrosDesde(['vista' => 'todo'], 9), 1, 50);
    [$conteo, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], 'c.sociedad_id = ?') !== false, 'filtra por empresa');
    igual(9, $pagina['params'][0] ?? null, 'la empresa viaja como entero y primero');
    igual(null, $conteo, 'en la primera página no hace falta contar aparte');

    // Una página más allá de la última sí cuenta aparte, y pregunta lo mismo.
    $m = new SeguimientoGrabadora();
    $m->total = 3;
    $m->cola(Seguimiento::filtrosDesde(['vista' => 'todo', 'q' => 'X'], 9), 4, 50);
    [$conteo, $pagina] = consultasDeCola($m);
    igual($conteo['params'], $pagina['params'], 'el COUNT pregunta lo mismo que la página');

    $m = new SeguimientoGrabadora();
    $m->cola(Seguimiento::filtrosDesde(['vista' => 'todo'], 0), 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], 'c.sociedad_id = ?') === false, 'empresa 0 = todas: no hay condición');
});

Prueba::caso('la pestaña y la marca', function () {
    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->cola(['vista' => 'revision'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual(['revision'], $pagina['params'], 'la pestaña viaja como parámetro');
    verificar(strpos($pagina['sql'], "CASE WHEN c.tarea = 'completo' THEN 'cerrada' ELSE 'pendiente' END") !== false,
        'en correo el cálculo es por enganche, no por saldo');

    foreach (['mano' => 's.estado IS NOT NULL', 'auto' => 's.estado IS NULL'] as $marca => $fragmento) {
        $m = new SeguimientoGrabadora();
        $m->cola(['vista' => 'todo', 'marca' => $marca], 1, 50);
        [, $pagina] = consultasDeCola($m);
        verificar(strpos($pagina['sql'], $fragmento) !== false, "marca {$marca}");
    }
});

Prueba::caso('origen, llegada y tarea se descartan si no son del modo aunque lleguen sin sanear', function () {
    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->cola(['vista' => 'todo', 'origen' => 'factura', 'tarea' => 'falta_pdf', 'llegada' => 'fax'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual([], $pagina['params'], 'nada de otro modo llega como parámetro');

    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'llegada' => 'correo'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], 'c.llegada = ?') === false, 'en sistema no se pregunta cómo llegó');

    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->cola(['vista' => 'todo', 'llegada' => 'correo', 'origen' => 'xml_nota'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual(['xml_nota', 'correo'], $pagina['params'], 'en correo sí, en su orden');
});

Prueba::caso('buscadores de texto: q, documento y proveedor', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'q' => 'FE-1', 'col_documento' => 'NC', 'col_proveedor' => 'ACME'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual(['%FE-1%', '%FE-1%', '%FE-1%', '%NC%', '%NC%', '%NC%', '%NC%', '%ACME%', '%ACME%'], $pagina['params'],
        'cada buscador lleva un parámetro por columna, en orden');
});

Prueba::caso('importes, rango de fechas, monto mínimo y respaldo', function () {
    $m = new SeguimientoGrabadora();
    $m->cola([
        'vista' => 'todo', 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'monto_min' => '1500.5',
        'col_monto' => '₡1,200', 'col_saldo' => '$ 7', 'col_respaldo' => 'sin_pdf',
    ], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual(['2026-09-01', '2026-09-30', 1500.5, '%1200%', '%7%'], $pagina['params'], 'parámetros en orden y con su tipo');
    verificar(strpos($pagina['sql'], "COALESCE(c.estado_pdf, '') <> 'no_disponible_historico'") !== false,
        'sin PDF no cuenta los históricos');

    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'col_respaldo' => 'sin_xml'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], "(c.factura_xml_id IS NULL OR c.ruta_xml IS NULL OR c.ruta_xml = '')") !== false,
        'sin XML mira el enganche y la ruta');
});

Prueba::caso('nota de crédito en juego: la pregunta cruzada y el estado exacto', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'aplicacion' => 'con_nota'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], "(c.aplicacion_estado = 'aplicable' OR c.notas_vivas > 0)") !== false, 'con_nota');
    igual([], $pagina['params'], 'con_nota no lleva parámetro');

    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'aplicacion' => AplicacionNotaCredito::APLICABLE], 1, 50);
    [, $pagina] = consultasDeCola($m);
    igual([AplicacionNotaCredito::APLICABLE], $pagina['params'], 'un estado concreto viaja como parámetro');
});

Prueba::caso('proveedor: código y nombre en sistema; cédula e id en correo; vacío si no se reconoce', function () {
    Soporte::proveedorResuelto('cod:P001', ['codigos' => ['P001'], 'nombres' => ['ACME S.A.']]);
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'proveedor' => 'cod:P001'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], '(c.proveedor_codigo IN (?) OR c.proveedor IN (?))') !== false, 'sistema');
    igual(['P001', 'ACME S.A.'], $pagina['params'], 'parámetros del proveedor');

    Soporte::proveedorResuelto('ced:3-101-123456', ['cedula' => '3101123456', 'proveedor_ids' => [5]]);
    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->cola(['vista' => 'todo', 'proveedor' => 'ced:3-101-123456'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], "REPLACE(REPLACE(c.proveedor_cedula, '-', ''), ' ', '') = ?") !== false, 'cédula sin guiones');
    igual(['3101123456', 5], $pagina['params'], 'cédula e id');

    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->cola(['vista' => 'todo', 'proveedor' => 'cod:P001'], 1, 50);
    [, $pagina] = consultasDeCola($m);
    verificar(strpos($pagina['sql'], '1=0') !== false, 'un código del ERP no identifica un comprobante: lista vacía, no entera');
});

// ── Paginación ──────────────────────────────────────────────────────────────

Prueba::caso('la página y el tamaño se acotan', function () {
    $casos = [
        [0, 50, 'LIMIT 50 OFFSET 0'], [-7, 50, 'LIMIT 50 OFFSET 0'], [3, 50, 'LIMIT 50 OFFSET 100'],
        [1, 5, 'LIMIT 10 OFFSET 0'], [1, 999, 'LIMIT 200 OFFSET 0'], ['2', '20', 'LIMIT 20 OFFSET 20'],
        ['1 UNION SELECT 1', 50, 'LIMIT 50 OFFSET 0'],
    ];
    foreach ($casos as [$pagina, $porPagina, $esperado]) {
        $m = new SeguimientoGrabadora();
        $m->cola(['vista' => 'todo'], $pagina, $porPagina);
        [, $consulta] = consultasDeCola($m);
        verificar(substr($consulta['sql'], -strlen($esperado)) === $esperado,
            "página " . json_encode($pagina) . " de " . json_encode($porPagina) . " termina en «{$esperado}»");
    }
});

Prueba::caso('el número de páginas: nunca cero, redondeado hacia arriba, entero', function () {
    $m = new SeguimientoGrabadora();
    $r = $m->cola(['vista' => 'todo'], 1, 50);
    igual([1, 0, []], [$r['paginas'], $r['total'], $r['filas']], 'sin filas: una página, total 0 y una lista, no false');

    foreach ([[50, 1], [51, 2], [101, 3], [17, 1]] as [$total, $paginas]) {
        $m = new SeguimientoGrabadora();
        $m->respuestas['ORDER BY'] = filasConTotal(min(50, $total), $total);
        $r = $m->cola(['vista' => 'todo'], 1, 50);
        igual($paginas, $r['paginas'], "total {$total}");
        igual($total, $r['total'], "el total {$total} es un entero");
        igual(min(50, $total), count($r['filas']), 'las filas de la página');
        verificar(!array_key_exists('total_filas', $r['filas'][0]), 'el total no se queda pegado a cada fila');
    }
});

// ── Resumen de las pestañas ─────────────────────────────────────────────────

Prueba::caso('el resumen cuenta todas las pestañas: ignora la vista y la tarea, conserva lo demás', function () {
    $m = new SeguimientoGrabadora();
    $m->resumen(['vista' => 'pendiente', 'tarea' => 'falta_pdf', 'col_tarea' => 'falta_xml', 'sociedad_id' => 9, 'q' => 'X']);
    $consulta = $m->de('fetchOne')[0];
    igual([9, '%X%', '%X%', '%X%'], $consulta['params'], 'sin vista ni tarea, con empresa y buscador');
});

Prueba::caso('el resumen sin fila son ceros, y con fila son números', function () {
    $m = new SeguimientoGrabadora();
    $cero = $m->resumen(['vista' => 'todo']);
    igual(0, array_sum($cero), 'sin respuesta, todo en cero');
    igual(12, count($cero), 'las doce cuentas siempre están');

    $m = new SeguimientoGrabadora();
    $m->respuestas['COUNT(*) total'] = [
        'total' => '10', 'pendiente' => '4', 'revision' => null, 'lista' => '3', 'cerrada' => '3',
        'falta_xml' => '2', 'falta_pdf' => '1', 'casos_diferencia' => '1', 'monto_diferencia' => '12.50',
        'vencidos' => null, 'a_mano' => '0', 'desajustadas' => '0',
    ];
    $r = $m->resumen(['vista' => 'todo']);
    igual(10, $r['total'], 'total entero');
    igual(0, $r['revision'], 'null es cero');
    igual(12.5, $r['monto_diferencia'], 'el monto es decimal');
});

Prueba::caso('en correo, lo que falta es no estar en el sistema', function () {
    $m = (new SeguimientoGrabadora())->enModo('correo');
    $m->resumen(['vista' => 'todo']);
    verificar(strpos($m->de('fetchOne')[0]['sql'], "c.tarea = 'sin_sistema'") !== false, 'faltante de correo');
});

// ── Un renglón, su bitácora y los desplegables ──────────────────────────────

Prueba::caso('uno(): parámetros tipados y null si no existe', function () {
    $m = new SeguimientoGrabadora();
    igual(null, $m->uno('factura', '12abc'), 'sin fila devuelve null');
    $consulta = $m->de('fetchOne')[0];
    verificar(substr(trim($consulta['sql']), -strlen('WHERE c.origen = ? AND c.referencia_id = ? LIMIT 1'))
        === 'WHERE c.origen = ? AND c.referencia_id = ? LIMIT 1', 'busca por la clave de la unión');
    igual(['factura', 12], $consulta['params'], 'el id viaja como entero');
});

Prueba::caso('bitacora(): el límite es un entero positivo aunque venga sucio', function () {
    foreach ([[0, 'LIMIT 1'], [-5, 'LIMIT 1'], ['25; DROP TABLE seguimiento', 'LIMIT 25'], [50, 'LIMIT 50']] as [$limite, $esperado]) {
        $m = new SeguimientoGrabadora();
        igual([], $m->bitacora('factura', 7, $limite), 'sin filas devuelve lista vacía');
        verificar(substr($m->de('fetchAll')[0]['sql'], -strlen($esperado)) === $esperado, 'límite ' . json_encode($limite));
    }
});

Prueba::caso('dimensiones: sucursales sumadas, sin blancos y en orden natural', function () {
    $m = new SeguimientoGrabadora();
    $m->respuestas['GROUP BY COALESCE(c.proveedor_codigo'] = [
        ['codigo' => 'P1', 'proveedor_id' => null, 'sucursal' => 'Sucursal 10', 'nombre' => 'A', 'n' => '2'],
        ['codigo' => 'P2', 'proveedor_id' => null, 'sucursal' => 'sucursal 2', 'nombre' => 'B', 'n' => '3'],
        ['codigo' => 'P3', 'proveedor_id' => null, 'sucursal' => '   ', 'nombre' => 'C', 'n' => '9'],
        ['codigo' => 'P4', 'proveedor_id' => null, 'sucursal' => 'Sucursal 10', 'nombre' => 'D', 'n' => '1'],
    ];
    $d = $m->dimensionesParaFiltro(0);
    igual(['sucursal 2' => 3, 'Sucursal 10' => 3], $d['sucursales'], 'sumadas y ordenadas');
    verificar(strpos($m->de('fetchAll')[0]['sql'], 'WHERE c.sociedad_id') === false, 'empresa 0 no filtra');
    igual(4, count($d['proveedores']), 'los proveedores salen tal cual');
});


// ── Defectos cerrados: cada uno fue un DEFECTO ABIERTO de docs/REVISION_SEGUIMIENTO.md ──

Prueba::caso('[L8] una fecha imposible no filtra (antes vaciaba la lista sin explicación)', function () {
    foreach (['2026-02-30', '2026-13-01', '0000-00-00', '2025-02-29'] as $fecha) {
        igual('', Seguimiento::filtrosDesde(['desde' => $fecha])['desde'], "desde {$fecha}");
    }
    igual('2024-02-29', Seguimiento::filtrosDesde(['hasta' => '2024-02-29'])['hasta'], 'un 29 de febrero bisiesto sí existe');
});

Prueba::caso('[S7] una página absurda produce un OFFSET entero y queda dentro del rango', function () {
    $m = new SeguimientoGrabadora();
    $m->total = 120;
    $r = $m->cola(['vista' => 'todo'], (int) '99999999999999999999', 50);
    [$conteo, $consulta] = consultasDeCola($m);
    verificar(preg_match('/LIMIT \d+ OFFSET \d+$/', $consulta['sql']) === 1,
        'el SQL termina en un OFFSET entero: «' . substr($consulta['sql'], -40) . '»');
    verificar($conteo !== null, 'para saber cuál es la última hubo que contar aparte');
    igual([3, 3], [$r['pagina'], $r['paginas']], 'se muestra la última página');
    verificar(substr($consulta['sql'], -strlen('LIMIT 50 OFFSET 100')) === 'LIMIT 50 OFFSET 100', 'y se pide la última');

    $m = new SeguimientoGrabadora();
    $r = $m->cola(['vista' => 'todo'], 9, 50);
    igual([1, 1, 0], [$r['pagina'], $r['paginas'], $r['total']], 'una cola vacía se queda en la página 1');
});

Prueba::caso('[L1] la exportación trae hasta TOPE_EXPORTACION filas, no 200', function () {
    $m = new SeguimientoGrabadora();
    (new SeguimientoControllerSonda())->privado('colaParaExportar', $m, ['vista' => 'todo']);
    [, $consulta] = consultasDeCola($m);
    verificar(substr($consulta['sql'], -strlen('LIMIT 5000 OFFSET 0')) === 'LIMIT ' . SeguimientoController::TOPE_EXPORTACION . ' OFFSET 0',
        'pedidas ' . SeguimientoController::TOPE_EXPORTACION . '; el SQL dice «' . substr($consulta['sql'], -20) . '»');

    // La pantalla sigue con su tope: el alto es solo para quien lo pide.
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo'], 1, SeguimientoController::TOPE_EXPORTACION);
    [, $consulta] = consultasDeCola($m);
    verificar(substr($consulta['sql'], -strlen('LIMIT 200 OFFSET 0')) === 'LIMIT 200 OFFSET 0', 'una página de pantalla no pasa de 200');
});

Prueba::caso('[S6] los comodines de LIKE escritos por la persona se buscan literalmente', function () {
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'q' => '50%_A', 'col_documento' => 'a\b', 'col_proveedor' => '100%'], 1, 50);
    [, $consulta] = consultasDeCola($m);
    igual('%50\%\_A%', $consulta['params'][0] ?? null, "buscar «50%_A» no debe traer «50 cualquier cosa A»");
    igual('%a\\\\b%', $consulta['params'][3] ?? null, 'la barra invertida también se busca como texto');
    igual('%100\%%', $consulta['params'][7] ?? null, 'el buscador de proveedor también escapa');
});

Prueba::caso('[L9] un importe con bytes que no son UTF-8 se ignora sin tumbar la pantalla', function () {
    igual('', Seguimiento::filtrosDesde(['col_monto' => "1\xA0200"])['col_monto'], 'importe en otra codificación');
    $m = new SeguimientoGrabadora();
    $m->cola(['vista' => 'todo', 'col_saldo' => "1\xA0200", 'col_monto' => '12%'], 1, 50);
    [, $consulta] = consultasDeCola($m);
    igual([], $consulta['params'], 'ni crudo, sin pasar por filtrosDesde(), llega al LIKE');
});

Prueba::caso('uno(): con empresa, el renglón tiene que ser de ella', function () {
    $m = new SeguimientoGrabadora();
    $m->uno('factura', '12', 4);
    $consulta = $m->de('fetchOne')[0];
    verificar(substr(trim($consulta['sql']), -strlen('AND c.sociedad_id = ? LIMIT 1')) === 'AND c.sociedad_id = ? LIMIT 1',
        'acota por empresa');
    igual(['factura', 12, 4], $consulta['params'], 'la empresa viaja como parámetro');
});

Prueba::terminar();
