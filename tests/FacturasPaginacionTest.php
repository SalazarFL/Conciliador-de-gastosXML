<?php
/**
 * El listado de Facturas XML se recorre entero, página por página.
 *
 * Antes se pintaban las 500 más recientes y a las demás no se llegaba más que
 * acotando los buscadores. Lo que se comprueba acá es que ahora cada página
 * pida solo su tanda, que "Siguiente" aparezca cuando —y solo cuando— hay una
 * página detrás, y que el filtro de respaldo, que no lo resuelve SQL sino el
 * disco compartido, siga dando páginas completas en vez de huecos.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/FacturasController.php';

function assertPaginacion($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Un listado de facturas en memoria, con LIMIT y OFFSET como los de la base. */
class FacturaPaginacionFalsa
{
    public $filas;
    public $pedidos = [];

    public function __construct(array $filas) { $this->filas = $filas; }

    public function contarConImportacion(array $filtros = []) { return count($this->filas); }

    public function buscarConImportacion(array $filtros = [])
    {
        $limite = (int) ($filtros['limite'] ?? 0);
        $offset = max(0, (int) ($filtros['offset'] ?? 0));
        $this->pedidos[] = ['limite' => $limite, 'offset' => $offset];

        return $limite > 0
            ? array_slice($this->filas, $offset, $limite)
            : array_slice($this->filas, $offset);
    }
}

/*
 * El listado se arma en un método interno del controlador: se llega a él sin
 * pasar por index(), que pediría sesión, base y una petición entera.
 */
$controlador = (new ReflectionClass('FacturasController'))->newInstanceWithoutConstructor();
$metodo = new ReflectionMethod('FacturasController', 'listadoPaginado');
$metodo->setAccessible(true);

$paginaDe = function ($modelo, $respaldo, $pagina) use ($controlador, $metodo) {
    return $metodo->invoke($controlador, $modelo, ['q' => ''], $respaldo, $pagina);
};
$POR_PAGINA = FacturasController::POR_PAGINA;

/** Una factura sin archivos en el disco: no tiene par y no está perdida. */
$sinArchivos = function ($id) {
    return ['id' => $id, 'ruta_xml' => '', 'ruta_pdf' => ''];
};

// ── Sin filtro de respaldo: LIMIT y OFFSET, y nada más ───────────────
$total = $POR_PAGINA * 3 + 7;
$filas = [];
for ($i = 1; $i <= $total; $i++) { $filas[] = $sinArchivos($i); }

$modelo = new FacturaPaginacionFalsa($filas);
$r = $paginaDe($modelo, '', 2);

assertPaginacion(count($modelo->pedidos) === 1, 'la página se pide en una sola consulta');
assertPaginacion(
    $modelo->pedidos[0] === ['limite' => $POR_PAGINA, 'offset' => $POR_PAGINA],
    'la página 2 pide su tanda, no el archivo entero'
);
assertPaginacion(count($r['facturas']) === $POR_PAGINA, 'la página trae las filas que caben');
assertPaginacion((int) $r['facturas'][0]['id'] === $POR_PAGINA + 1, 'la página 2 empieza donde acabó la 1');
assertPaginacion($r['paginacion']['total'] === $total, 'se dice cuántas hay en total');
assertPaginacion($r['paginacion']['paginas'] === 4, 'y en cuántas páginas caben');
assertPaginacion($r['paginacion']['hay_siguiente'] === true, 'con más detrás, hay Siguiente');
assertPaginacion($r['paginacion']['truncado'] === false, 'sin filtro de respaldo no se corta nada');

// ── La última página, y una que no existe ────────────────────────────
$r = $paginaDe(new FacturaPaginacionFalsa($filas), '', 4);
assertPaginacion(count($r['facturas']) === 7 && $r['paginacion']['hay_siguiente'] === false,
    'la última página trae el resto y ya no ofrece Siguiente');

$r = $paginaDe(new FacturaPaginacionFalsa($filas), '', 99);
assertPaginacion($r['paginacion']['pagina'] === 4 && count($r['facturas']) === 7,
    'pedir una página que no existe deja en la última, no en una vacía');

// ── Con filtro de respaldo: el disco decide, y la página va completa ──
$tmp = sys_get_temp_dir() . '/facturas_paginacion_' . getmypid();
@mkdir($tmp, 0777, true);
$xml = $tmp . '/comprobante.xml';
$pdf = $tmp . '/comprobante.pdf';
file_put_contents($xml, '<x/>');
file_put_contents($pdf, '%PDF');

// Una de cada tres tiene su par completo en el disco; las otras, nada.
$conPar = 0;
$mezcla = [];
for ($i = 1; $i <= $POR_PAGINA * 5 + 50; $i++) {
    if ($i % 3 === 0) {
        $mezcla[] = ['id' => $i, 'ruta_xml' => $xml, 'ruta_pdf' => $pdf];
        $conPar++;
    } else {
        $mezcla[] = $sinArchivos($i);
    }
}

$modelo = new FacturaPaginacionFalsa($mezcla);
$r = $paginaDe($modelo, 'con_par', 1);

assertPaginacion(count($r['facturas']) === $POR_PAGINA,
    'la primera página del filtro va llena, aunque las que pasan estén salteadas');
foreach ($r['facturas'] as $fila) {
    assertPaginacion((int) $fila['id'] % 3 === 0, 'solo se listan las que tienen su par en el disco');
}
assertPaginacion($r['paginacion']['hay_siguiente'] === true, 'quedan más que pasan el filtro');
assertPaginacion(count($modelo->pedidos) > 1,
    'para llenarla hubo que recorrer más de una tanda del listado');

$modelo = new FacturaPaginacionFalsa($mezcla);
$r = $paginaDe($modelo, 'con_par', 2);
assertPaginacion(
    count($r['facturas']) === $conPar - $POR_PAGINA && $r['paginacion']['hay_siguiente'] === false,
    'la segunda trae el resto de las que pasan y cierra el listado'
);
assertPaginacion($r['paginacion']['total'] === $conPar && $r['paginacion']['paginas'] === 2,
    'recorrido el listado entero, el total del filtro también se sabe');
assertPaginacion($r['paginacion']['truncado'] === false,
    'no hace falta avisar de nada: se revisó hasta el final');

// ── Y cuando la revisión se corta antes que el listado ───────────────
// Ninguna pasa el filtro, así que se revisa hasta el tope y se dice hasta
// dónde se llegó en vez de dar el listado por terminado.
$muchas = [];
for ($i = 1; $i <= FacturasController::MAX_REVISION_RESPALDO * 2; $i++) { $muchas[] = $sinArchivos($i); }

$modelo = new FacturaPaginacionFalsa($muchas);
$r = $paginaDe($modelo, 'con_par', 1);

assertPaginacion($r['facturas'] === [], 'ninguna tiene su par: la página sale vacía');
assertPaginacion($r['paginacion']['revisados'] === FacturasController::MAX_REVISION_RESPALDO,
    'se revisa hasta el tope y ni una fila más');
assertPaginacion($r['paginacion']['truncado'] === true, 'y se avisa de que el listado sigue');
assertPaginacion($r['paginacion']['total'] === null && $r['paginacion']['paginas'] === 0,
    'cuántas hay en total no se sabe, y no se inventa');

@unlink($xml);
@unlink($pdf);
@rmdir($tmp);

echo "OK: FacturasPaginacion\n";
