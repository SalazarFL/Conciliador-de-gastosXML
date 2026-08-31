<?php
/**
 * Qué significa escribir algo en el buscador de un listado de comprobantes.
 *
 * Lo que se comprueba es la distinción que dio origen a esto: un número se
 * busca donde ese número significa algo —el número corto y el consecutivo— y
 * no dentro de la clave, que son cincuenta dígitos con la fecha, la cédula del
 * emisor y un código de seguridad metidos adentro. Buscar la factura 336
 * devolvía 124 comprobantes, 124 claves que en algún punto dicen "336".
 *
 * Dentro del número sí se busca por coincidencia: quien busca puede acordarse
 * de un pedazo, y 00336547 es un resultado legítimo para "336". Que entre esas
 * coincidencias no esté el documento que se venía a buscar es otra pregunta, y
 * la contesta la pantalla (app/views/partials/documento-no-esta.php).
 */
require_once __DIR__ . '/../app/helpers/BusquedaDocumento.php';

function assertBusqueda($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Las columnas del listado de comprobantes, tal como las pasa el modelo. */
function columnasBusqueda()
{
    return [
        'numero' => 'f.numero_factura_asistente',
        'consecutivo' => 'f.consecutivo_completo',
        'clave' => 'f.clave',
        'cedula' => 'p.rfc',
        'texto' => ['p.razon_social', 'f.archivo_xml', 'f.archivo_pdf'],
    ];
}

/** Arma la condición y devuelve [sql, params], que van siempre juntos. */
function busqueda($termino, ?array $columnas = null)
{
    $params = [];
    $sql = BusquedaDocumento::condicion($termino, $columnas ?? columnasBusqueda(), $params);
    return [$sql, $params];
}

// ── Nada que buscar ────────────────────────────────────────────────────────
[$sql, $params] = busqueda('');
assertBusqueda($sql === '' && $params === [],
    'un buscador vacío no filtra nada y no deja parámetros sueltos');

[$sql, $params] = busqueda('   ');
assertBusqueda($sql === '', 'los espacios tampoco son un criterio');

// ── El caso que dio origen a esto: la factura 336 ─────────────────────────
[$sql, $params] = busqueda('336');

assertBusqueda(strpos($sql, 'f.clave') === false,
    'tres dígitos NO se buscan dentro de una clave de cincuenta: ahí coincide cualquier cosa');
assertBusqueda(strpos($sql, 'f.numero_factura_asistente LIKE ?') !== false
    && strpos($sql, 'f.consecutivo_completo LIKE ?') !== false,
    'dentro del número y del consecutivo sí se busca, por coincidencia');
assertBusqueda(count(array_keys($params, '%336%', true)) === 2,
    'con el mismo término en las dos columnas donde ese número significa algo');

// La cédula, entera o nada: es una identidad, no un texto. Con "336" dentro,
// la cédula sola traía otras 69 filas.
assertBusqueda(strpos($sql, "REPLACE(REPLACE(p.rfc") !== false,
    'la cédula se compara entera');
assertBusqueda(in_array('336', $params, true),
    'y sin comodines a los lados');

// ── Acordarse de un pedazo del número ─────────────────────────────────────
[$sql, $params] = busqueda('547');
assertBusqueda(in_array('%547%', $params, true),
    'buscar el final de un número sigue encontrando 00336547: es para lo que sirve');

// ── Pegar el consecutivo entero ───────────────────────────────────────────
[$sql, $params] = busqueda('00200006010000008060');
assertBusqueda(in_array('%00200006010000008060%', $params, true)
    && strpos($sql, 'f.consecutivo_completo LIKE ?') !== false,
    'un consecutivo pegado se busca tal cual dentro de su columna');
assertBusqueda(strpos($sql, 'f.numero_factura_asistente') === false,
    'y no en el número corto, donde no cabe: son ocho caracteres');

// ── Pegar la clave entera ─────────────────────────────────────────────────
$clave = '50620082600310160290700200006010000008060117153837';
[$sql, $params] = busqueda($clave);
assertBusqueda(strpos($sql, 'f.clave LIKE ?') !== false && in_array('%' . $clave . '%', $params, true),
    'una clave pegada sí se busca dentro de la columna clave: es larga y específica');

// ── El límite entre "número de factura" y "clave" ─────────────────────────
[$sql, ] = busqueda(str_repeat('7', BusquedaDocumento::DIGITOS_DE_UN_NUMERO));
assertBusqueda(strpos($sql, 'f.numero_factura_asistente LIKE ?') !== false,
    'hasta el largo de la columna, el término todavía puede ser un número de factura');
[$sql, ] = busqueda(str_repeat('7', BusquedaDocumento::DIGITOS_DE_UN_NUMERO + 1));
assertBusqueda(strpos($sql, 'f.numero_factura_asistente') === false,
    'un dígito más ya no cabe en el número corto');
[$sql, ] = busqueda(str_repeat('7', BusquedaDocumento::DIGITOS_PARA_CLAVE - 1));
assertBusqueda(strpos($sql, 'f.clave') === false,
    'justo por debajo del umbral, la clave sigue fuera');
[$sql, ] = busqueda(str_repeat('7', BusquedaDocumento::DIGITOS_PARA_CLAVE));
assertBusqueda(strpos($sql, 'f.clave LIKE ?') !== false,
    'y a partir del umbral el término ya es lo bastante específico para buscarla');

// ── Texto: lo que el LIKE sí resuelve ─────────────────────────────────────
[$sql, $params] = busqueda('STAR CARS');
assertBusqueda(in_array('%STAR CARS%', $params, true)
    && strpos($sql, 'p.razon_social LIKE ?') !== false,
    'un nombre de proveedor se busca con comodines, que es para lo que sirven');
assertBusqueda(strpos($sql, 'f.archivo_xml LIKE ?') !== false,
    'y también en el nombre del archivo');

[$sql, $params] = busqueda('FE_STAR_CARS_200826_00008060.xml');
assertBusqueda(strpos($sql, 'f.archivo_pdf LIKE ?') !== false,
    'pegar un nombre de archivo sigue encontrando su documento');

// Un número con separadores sigue siendo un número; una letra lo vuelve texto.
assertBusqueda(BusquedaDocumento::esNumero('0010 0001-01.0000008060'),
    'un consecutivo copiado con espacios o guiones sigue siendo un número');
assertBusqueda(!BusquedaDocumento::esNumero('FE_00008060'),
    'una sola letra ya lo convierte en texto');
assertBusqueda(BusquedaDocumento::digitos('0010 0001-01') === '0010000101',
    'de un número con separadores se comparan solo sus dígitos');

// ── Columnas que el listado no tiene ──────────────────────────────────────
[$sql, ] = busqueda('336', ['numero' => 'f.numero_factura_asistente', 'texto' => ['p.razon_social']]);
assertBusqueda(strpos($sql, 'f.clave') === false && strpos($sql, 'p.rfc') === false,
    'solo se busca en las columnas que se pasaron');

[$sql, ] = busqueda('336', []);
assertBusqueda($sql === '1=0',
    'sin columnas donde buscar no se encuentra nada; traer el listado entero sería peor');

// ── Cada ? tiene su valor ─────────────────────────────────────────────────
// Es el defecto clásico de armar SQL así, y deja la consulta rota en cuanto
// alguien agrega una columna.
foreach (['336', '8060', '00200006010000008060', $clave, 'STAR CARS', '3101602907'] as $termino) {
    [$sql, $params] = busqueda($termino);
    assertBusqueda(substr_count($sql, '?') === count($params),
        "los parámetros cuadran con los marcadores para «{$termino}»");
}

echo "OK BusquedaDocumentoTest\n";
