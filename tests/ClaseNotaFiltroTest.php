<?php
/**
 * El filtro de clase de nota, compartido por Seguimiento y Notas de crédito.
 *
 * Se eligen varias a la vez porque el trabajo se reparte por clase: una nota
 * directa se persigue distinto que una de diferencia de costo, y "directas y
 * por revisar" es una pregunta corriente que con una sola opción obligaba a
 * recorrer el listado tres veces.
 *
 * Lo que se comprueba es lo que hace útil compartirlo: que las dos pantallas
 * lean la misma cadena, que cada una acote las clases que puede contener —la
 * cola de Seguimiento no tiene notas de 'ajuste', nunca llevan XML— y que lo
 * pedido llegue al SQL como parámetros y no interpolado.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../app/models/ProveedorCatalogo.php';
require_once __DIR__ . '/../app/models/NotaCredito.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertClaseFiltro($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── La lectura de la cadena ─────────────────────────────────────────────────

assertClaseFiltro(ClaseNotaCredito::clasesPedidas('directa,costo') === ['directa', 'costo'],
    'se leen varias clases separadas por comas');
assertClaseFiltro(ClaseNotaCredito::clasesPedidas(' Costo , DIRECTA ') === ['costo', 'directa'],
    'se aceptan con espacios y en cualquier caja');
assertClaseFiltro(ClaseNotaCredito::clasesPedidas('cambio,cambio') === ['cambio'],
    'la misma clase repetida no duplica la condición');
assertClaseFiltro(ClaseNotaCredito::clasesPedidas(['costo', 'cambio']) === ['costo', 'cambio'],
    'también se admite la lista, por si algún formulario manda clase[]');

foreach (['', 'todas', 'x, ,y', 'directa; DROP TABLE', 'DIRECTAS'] as $basura) {
    assertClaseFiltro(ClaseNotaCredito::clasesPedidas($basura) === [],
        "lo que no es una clase se descarta: '{$basura}'");
}

// ── Cada pantalla ofrece las que puede contener ─────────────────────────────
// Los nombres salen de un solo sitio; las listas difieren a propósito.

assertClaseFiltro(array_keys(ClaseNotaCredito::ETIQUETAS)
    === ['directa', 'costo', 'cambio', 'ajuste', 'revisar'],
    'el catálogo tiene las cinco clases que el ERP mezcla');
assertClaseFiltro(!isset(Seguimiento::CLASES['ajuste']),
    'la cola de seguimiento no ofrece las de ajuste: no se persiguen');
foreach (Seguimiento::CLASES as $clave => $etiqueta) {
    assertClaseFiltro($etiqueta === ClaseNotaCredito::ETIQUETAS[$clave],
        "la clase {$clave} se llama igual en las dos pantallas");
}

assertClaseFiltro(Seguimiento::clasesPedidas('directa,ajuste') === ['directa'],
    'una clase que esa cola no contiene se descarta aunque exista');
assertClaseFiltro(ClaseNotaCredito::clasesPedidas('directa,ajuste') === ['directa', 'ajuste'],
    'en notas de crédito sí vale: ahí se ve el acumulado entero');

// ── Llega al SQL de Notas de crédito como parámetros ────────────────────────

class NotaCreditoClaseFalso extends NotaCredito
{
    public $sql = '';
    public $params = [];

    public function __construct() {}

    protected function fetchAll($sql, $params = [])
    {
        $this->sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->params = $params;
        return [];
    }
}

$modelo = new NotaCreditoClaseFalso();

$modelo->getLineasFiltradas(7, ['clase' => 'directa,ajuste']);
assertClaseFiltro(strpos($modelo->sql, 'nl.clase IN (?,?)') !== false,
    'dos clases producen dos marcadores, no una igualdad');
assertClaseFiltro(in_array('directa', $modelo->params, true)
    && in_array('ajuste', $modelo->params, true),
    'las clases viajan como parámetros, no interpoladas');

$modelo->getLineasFiltradas(7, ['clase' => 'costo']);
assertClaseFiltro(strpos($modelo->sql, 'nl.clase IN (?)') !== false,
    'una sola clase se filtra igual que antes del cambio');

// 'todas' era el valor de la opción vacía del desplegable viejo: tiene que
// seguir significando "no filtres" y no "buscá una clase llamada todas".
foreach (['', 'todas'] as $vacio) {
    $modelo->getLineasFiltradas(7, ['clase' => $vacio]);
    assertClaseFiltro(strpos($modelo->sql, 'nl.clase IN') === false,
        "sin clases elegidas no se agrega condición: '{$vacio}'");
}

echo "OK: Filtro de clase de nota\n";
