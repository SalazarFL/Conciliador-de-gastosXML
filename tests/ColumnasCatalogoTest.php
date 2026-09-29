<?php
/**
 * El catálogo de columnas y las vistas dicen lo mismo.
 *
 * Un listado tiene su lista de columnas en app/config/columnas.php y su
 * marcado en la vista. Son dos archivos y se editan por separado, así que lo
 * que hay que vigilar es lo de siempre: que no se separen.
 *
 * Si una columna de fábrica está en el catálogo y no en la vista, el Excel
 * baja una columna que en pantalla no existe. Si está en la vista y no en el
 * catálogo, la casilla para apagarla no aparece y el Excel no la lleva. Las
 * dos fallas son silenciosas —nada revienta, solo sale un archivo que no es lo
 * que se estaba viendo—, y por eso se comprueban aquí.
 *
 * Uso: php tests/ColumnasCatalogoTest.php
 */

require_once __DIR__ . '/../app/helpers/Columnas.php';

$fallos = 0;
function comprueba($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        echo 'FAIL: ' . $mensaje . PHP_EOL;
        $fallos++;
    }
}

$raiz = dirname(__DIR__);

/*
 * Qué vista pinta cada listado.
 *
 * Seguimiento sale dos veces porque la misma pantalla trabaja en dos modos
 * —Sistema y Correo— y cada uno enseña lo suyo: en Correo no hay diferencia
 * contra el ERP que mirar, y en Sistema no hay "cómo llegó". Comparten las
 * columnas de fábrica, que es lo que esta prueba mira en la vista.
 */
$vistas = [
    'facturas'           => 'app/views/facturas/index.php',
    'notasxml'           => 'app/views/notasxml/index.php',
    'seguimiento'        => 'app/views/seguimiento/index.php',
    'seguimiento_correo' => 'app/views/seguimiento/index.php',
    'porpagar'           => 'app/views/porpagar/index.php',
    'facturaserp'        => 'app/views/facturaserp/index.php',
    'devoluciones'       => 'app/views/devoluciones/index.php',
    'notascredito'       => 'app/views/notascredito/index.php',
    'bitacora'           => 'app/views/bitacora/index.php',
];

// ── El catálogo, por sí solo ────────────────────────────────────────────────

foreach (array_keys($vistas) as $listado) {
    $catalogo = Columnas::catalogo($listado);
    comprueba($catalogo !== [], "«{$listado}» está en el catálogo de columnas");
    if (!$catalogo) { continue; }

    foreach ($catalogo as $id => $col) {
        comprueba((bool) preg_match('/^[a-z][a-z0-9_]*$/', $id),
            "«{$listado}.{$id}» es un identificador simple, que va tal cual en el data-col y en la URL");
        comprueba(trim($col['titulo']) !== '',
            "«{$listado}.{$id}» tiene título: es lo que se lee en la casilla y en el Excel");
        comprueba(is_string($col['valor']) || is_callable($col['valor']),
            "«{$listado}.{$id}» sabe de dónde sale su valor");
        comprueba($col['ancho'] > 0, "«{$listado}.{$id}» tiene ancho para el Excel");
    }

    /*
     * Las de fábrica primero y las demás detrás, sin mezclar. La vista escribe
     * a mano las de fábrica y pega el resto de un tirón al final
     * (Columnas::cabecerasExtra): una columna apagada colada en medio saldría
     * dos veces en la tabla, una donde la escribió la vista y otra al final.
     */
    $vistoApagada = false;
    foreach ($catalogo as $id => $col) {
        if (!$col['defecto']) { $vistoApagada = true; continue; }
        comprueba(!$vistoApagada,
            "«{$listado}.{$id}» viene de fábrica, así que va ANTES de las apagadas");
    }

    comprueba(Columnas::porDefecto($listado) !== [],
        "«{$listado}» enciende alguna columna de fábrica");
}

// ── El catálogo contra su vista ─────────────────────────────────────────────

foreach ($vistas as $listado => $ruta) {
    $archivo = $raiz . '/' . $ruta;
    comprueba(is_file($archivo), "la vista de «{$listado}» existe: {$ruta}");
    if (!is_file($archivo)) { continue; }

    $marcado = file_get_contents($archivo);
    $catalogo = Columnas::catalogo($listado);
    $defecto = Columnas::porDefecto($listado);

    preg_match_all('/<th[^>]*data-col="([a-z0-9_]+)"/', $marcado, $m);
    $enCabecera = array_unique($m[1]);
    preg_match_all('/<td[^>]*data-col="([a-z0-9_]+)"/', $marcado, $m);
    $enCeldas = array_unique($m[1]);

    foreach ($defecto as $id) {
        comprueba(in_array($id, $enCabecera, true),
            "«{$listado}»: la cabecera de «{$id}» está en {$ruta}");
        comprueba(in_array($id, $enCeldas, true),
            "«{$listado}»: la celda de «{$id}» está en {$ruta}");
    }

    // Y al revés: nada marcado en la vista que el catálogo no conozca, o sería
    // una columna que no se puede ni apagar ni exportar. Una vista que sirve a
    // dos listados —Seguimiento en sus dos modos— escribe las columnas de los
    // dos, cada una dentro de su modo: basta con que la conozca alguno de los
    // catálogos que comparten la vista.
    $conocidas = [];
    foreach ($vistas as $otro => $otraRuta) {
        if ($otraRuta === $ruta) {
            $conocidas += Columnas::catalogo($otro);
        }
    }
    foreach (array_merge($enCabecera, $enCeldas) as $id) {
        comprueba(isset($conocidas[$id]),
            "«{$listado}»: «{$id}» está marcado en {$ruta} pero no en el catálogo");
    }

    // Las tres piezas que hacen que el botón y el Excel funcionen.
    comprueba(strpos($marcado, 'data-listado=') !== false,
        "«{$listado}»: la tabla de {$ruta} se identifica con data-listado");
    comprueba(strpos($marcado, "partials/columnas.php") !== false,
        "«{$listado}»: {$ruta} incluye el partial que aplica la elección sin parpadeo");
    comprueba(strpos($marcado, 'data-exportar=') !== false,
        "«{$listado}»: {$ruta} tiene el enlace de Exportar que lleva las columnas");
}

// ── Qué columnas se dan por elegidas ────────────────────────────────────────

{
    $defecto = Columnas::porDefecto('facturas');

    comprueba(Columnas::elegidas('facturas', '') === $defecto,
        'sin nada pedido valen las de fábrica');
    comprueba(Columnas::elegidas('facturas', 'no_existe,tampoco') === $defecto,
        'si lo pedido no existe, valen las de fábrica: un Excel de cero columnas no lo quiso nadie');
    comprueba(Columnas::elegidas('facturas', 'total,fecha') === ['total', 'fecha'],
        'las columnas salen en el orden pedido: es el que quien mira le dio a la pantalla');
    comprueba(Columnas::elegidas('facturas', 'total,fecha,total') === ['total', 'fecha'],
        'una columna repetida en la URL no sale dos veces en el Excel');
    comprueba(Columnas::elegidas('facturas', 'total,ojalá; DROP TABLE') === ['total'],
        'lo que llega por la URL se filtra contra el catálogo');
}

// ── La tabla que baja al Excel ──────────────────────────────────────────────

{
    $filas = [
        ['fecha_emision' => '2026-01-05', 'proveedor_nombre' => 'ACME', 'total' => 113.5,
         'saldo_erp' => null, 'archivo_pdf_ok' => 1, 'correo_cuenta_id' => null],
        ['fecha_emision' => '2026-01-06', 'proveedor_nombre' => 'Otra', 'total' => 88.0,
         'saldo_erp' => 10.0, 'archivo_pdf_ok' => 0, 'correo_cuenta_id' => 7],
    ];
    $tabla = Columnas::tabla('facturas', $filas, 'fecha,total,saldo,pdf,origen');

    comprueba($tabla['cabeceras'] === ['Fecha', 'Total', 'Saldo', 'PDF', 'Origen'],
        'las cabeceras del Excel son los títulos de las columnas elegidas');
    comprueba(count($tabla['filas']) === 2, 'baja una fila por renglón');
    foreach ($tabla['filas'] as $i => $fila) {
        comprueba(count($fila) === count($tabla['cabeceras']),
            "la fila {$i} trae tantas celdas como cabeceras");
    }
    comprueba($tabla['filas'][0][2] === 'sin enganche',
        'sin registro del ERP el saldo se dice con palabras: un cero diría que ya se pagó');
    comprueba($tabla['filas'][1][2] === 10.0, 'con enganche baja el número, no un texto');
    comprueba($tabla['filas'][0][4] === 'Carga XML' && $tabla['filas'][1][4] === 'Correo',
        'el origen baja escrito, que en un Excel se filtra y un icono no');
}

// ── El pago pinta el estado en el Excel ─────────────────────────────

{
    $tabla = Columnas::tabla('porpagar', [
        ['estado' => 'respaldada', 'documento' => 'A'],
        ['estado' => 'sin_respaldo', 'documento' => 'B'],
    ], 'estado,documento');

    comprueba(($tabla['estilos'][0][0] ?? 0) === 3, 'una línea respaldada baja en verde');
    comprueba(($tabla['estilos'][1][0] ?? 0) === 2, 'una sin respaldo baja en amarillo');
}

// ── Las columnas que la vista no escribe, las escribe el catálogo ───────────

{
    $html = Columnas::cabecerasExtra('facturas');
    foreach (Columnas::catalogo('facturas') as $id => $col) {
        $esta = strpos($html, 'data-col="' . $id . '"') !== false;
        comprueba($col['defecto'] ? !$esta : $esta,
            "cabecerasExtra dibuja «{$id}» solo si NO viene de fábrica");
    }

    $celdas = Columnas::celdasExtra('facturas', ['pago_nombre' => 'Pago 12']);
    comprueba(strpos($celdas, '>Pago 12<') !== false, 'la celda extra trae su valor');
    comprueba(strpos($celdas, '>—<') !== false, 'y una raya donde no hay dato, no un hueco');

    $peligro = Columnas::celdasExtra('facturas', ['pago_nombre' => '<script>alert(1)</script>']);
    comprueba(strpos($peligro, '<script>') === false,
        'lo que viene de la base se escapa antes de pintarlo');
}

if ($fallos > 0) {
    echo PHP_EOL . $fallos . ' comprobaciones fallaron' . PHP_EOL;
    exit(1);
}
echo 'OK: ColumnasCatalogo (' . count($vistas) . ' listados)' . PHP_EOL;
