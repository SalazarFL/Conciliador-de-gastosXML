<?php
/**
 * La lista que recorre la tarjeta de "buscar el electrónico de un documento".
 *
 * Lo que se comprueba es lo que la hace confiable: que las flechas recorran
 * exactamente la lista que se estaba viendo —no otra—, que abra en el
 * documento desde el que se pulsó, y que los filtros de la cola de seguimiento
 * viajen con prefijo para no pisarle al listado destino los suyos, que se
 * llaman igual.
 *
 * También cubre el fallo que dejó la tarjeta invisible durante semanas: se
 * pedían las líneas al pago semanal, que dejó de tenerlas cuando la línea pasó
 * a ser la factura del ERP. El Error caía en un catch y no salía la tarjeta.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../app/models/ProveedorCatalogo.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertNavDoc($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── Dobles: contestan lo justo, sin base ────────────────────────────────────

class NavPorPagarFalso
{
    public function getListado($id)
    {
        return $id === 5
            ? ['id' => 5, 'nombre' => 'Pago Semana 33', 'semana_nombre' => 'Semana 33']
            : null;
    }
}

class NavFacturaErpFalso
{
    public $listadoPedido = 0;

    public function getFacturasPago($listadoId)
    {
        $this->listadoPedido = (int) $listadoId;
        return [
            ['id' => 11, 'documento' => '00100001010000045587', 'proveedor_nombre' => 'MARJAVA S.A.',
             'fecha_emision' => '2026-08-12', 'saldo_pago' => 210359.00, 'estado' => 'sin_respaldo'],
            ['id' => 12, 'documento' => '00100001010000045588', 'proveedor_nombre' => 'MARJAVA S.A.',
             'fecha_emision' => '2026-08-13', 'saldo_pago' => 12400.50, 'estado' => 'con_diferencia'],
        ];
    }
}

class NavSeguimientoFalso
{
    public $filtrosRecibidos = [];

    public function cola(array $filtros, $pagina, $porPagina)
    {
        $this->filtrosRecibidos = $filtros;
        return ['filas' => [
            ['origen' => 'factura', 'referencia_id' => 40, 'documento' => 'FAC-1',
             'nc_proveedor' => null, 'proveedor' => 'ACME S.A.', 'fecha' => '2026-08-01',
             'saldo' => 1000.0, 'tarea' => 'falta_xml'],
            ['origen' => 'nota_credito', 'referencia_id' => 40, 'documento' => 'NC- 17-1-000',
             'nc_proveedor' => '', 'proveedor' => 'ACME SOCIEDAD ANONIMA', 'fecha' => '2026-08-02',
             'saldo' => 250.0, 'tarea' => 'diferencia'],
        ]];
    }
}

class NavSociedadFalsa
{
    public function getActiva() { return ['id' => 3]; }
}

$erp = new NavFacturaErpFalso();
$seg = new NavSeguimientoFalso();
$cargar = static function ($nombre) use ($erp, $seg) {
    switch ($nombre) {
        case 'PorPagar':    return new NavPorPagarFalso();
        case 'FacturaErp':  return $erp;
        case 'Seguimiento': return $seg;
        case 'Sociedad':    return new NavSociedadFalsa();
    }
    throw new Exception("Modelo inesperado: {$nombre}");
};
$BASE = 'http://local/xmlconcilia/public';

// ── Sin contexto no hay tarjeta ─────────────────────────────────────────────
// Es lo normal: estas pantallas se abren solas casi siempre.
assertNavDoc(NavegacionDocumentos::desde([], $cargar, $BASE) === null,
    'sin parámetros de contexto no se arma ninguna tarjeta');
assertNavDoc(NavegacionDocumentos::desde(['ctx' => 'inventado'], $cargar, $BASE) === null,
    'un origen que no existe no arma tarjeta');

// ── El pago semanal ─────────────────────────────────────────────────────────
$nav = NavegacionDocumentos::desde(
    ['ctx' => 'pago', 'ctx_lista' => 5, 'ctx_item' => '12'], $cargar, $BASE);

assertNavDoc($nav !== null, 'con listado y línea sí se arma la tarjeta');
assertNavDoc($erp->listadoPedido === 5,
    'las facturas del pago se piden a Facturas ERP, que es donde viven');
assertNavDoc(count($nav['items']) === 2, 'la lista trae las facturas del pago');
assertNavDoc($nav['idx'] === 1, 'abre en la factura desde la que se pulsó, no en la primera');
assertNavDoc($nav['items'][0]['numero'] === '00100001010000045587',
    'el número que se enseña es el documento del ERP');
assertNavDoc($nav['items'][0]['fecha'] === '12/08/2026',
    'la fecha se enseña como se lee, no como la guarda la base');
assertNavDoc($nav['items'][0]['total'] === 210359.00,
    'el monto es el saldo del pago, que es el que enseña el checklist');
assertNavDoc($nav['items'][0]['destino'] === 'facturas',
    'una factura se busca entre los comprobantes de facturas');
assertNavDoc(strpos($nav['volver'], '/por-pagar?listado_id=5') !== false,
    'el enlace de regreso vuelve al pago del que se vino');

// El nombre viejo de los parámetros sigue valiendo: anda en enlaces guardados.
$viejo = NavegacionDocumentos::desde(
    ['pp_listado' => 5, 'pp_linea' => 11], $cargar, $BASE);
assertNavDoc($viejo !== null && $viejo['idx'] === 0,
    'los enlaces con pp_listado/pp_linea siguen abriendo la tarjeta');

// Un listado que ya no existe no revienta la pantalla: no hay tarjeta y ya.
assertNavDoc(NavegacionDocumentos::desde(['ctx' => 'pago', 'ctx_lista' => 99], $cargar, $BASE) === null,
    'un listado borrado no arma tarjeta');

// ── La cola de seguimiento ──────────────────────────────────────────────────
$nav = NavegacionDocumentos::desde([
    'ctx' => 'seguimiento',
    'ctx_item' => 'nota_credito:40',
    'ctx_f_vista' => 'pendiente',
    'ctx_f_q' => 'marjava',
    'ctx_f_clase' => 'directa,revisar',
], $cargar, $BASE);

assertNavDoc($nav !== null, 'la cola de seguimiento también arma tarjeta');
assertNavDoc($seg->filtrosRecibidos['q'] === 'marjava'
    && $seg->filtrosRecibidos['vista'] === 'pendiente'
    && $seg->filtrosRecibidos['clase'] === 'directa,revisar',
    'la cola se rehace con los filtros con los que se estaba mirando');
assertNavDoc($seg->filtrosRecibidos['sociedad_id'] === 3,
    'y acotada a la empresa activa');

// La cola une dos tablas y los id se repiten entre ellas: con el número solo,
// "siguiente" saltaría al documento equivocado.
assertNavDoc($nav['items'][0]['id'] === 'factura:40'
    && $nav['items'][1]['id'] === 'nota_credito:40',
    'cada renglón se identifica por origen e id, no solo por el id');
assertNavDoc($nav['idx'] === 1,
    'abre en el renglón desde el que se pulsó aunque comparta id con otro');

assertNavDoc($nav['items'][0]['destino'] === 'facturas'
    && $nav['items'][1]['destino'] === 'notas-xml',
    'cada documento se busca entre los comprobantes de su clase');
assertNavDoc($nav['items'][0]['estado'] === 'sin_respaldo'
    && $nav['items'][1]['estado'] === 'con_diferencia',
    'la tarea de la cola se traduce al vocabulario del respaldo');

// La nota no trae consecutivo propio: su número es el de la factura que
// corrige, así que se busca por proveedor y no por número.
assertNavDoc($nav['items'][1]['busqueda'] !== 'NC- 17-1-000'
    && stripos($nav['items'][1]['busqueda'], 'ACME') !== false,
    'una nota sin número propio se busca por proveedor, no por su número');

// ── Los filtros viajan con prefijo ──────────────────────────────────────────
// Sin él, el 'q' de esta cola aterrizaría en el buscador del listado de
// facturas —y el listado además lo guardaría como su último filtro.
assertNavDoc(strpos($nav['params'], 'ctx_f_q=marjava') !== false,
    'los filtros del contexto viajan prefijados');
assertNavDoc(strpos($nav['params'], '&q=') === false && strpos($nav['params'], '?q=') === false,
    'ninguno viaja con su nombre pelado, que es el que usa la pantalla destino');
assertNavDoc(strpos($nav['volver'], 'q=marjava') !== false
    && strpos($nav['volver'], 'ctx_f_') === false,
    'el enlace de regreso los devuelve sin prefijo: allá sí son los suyos');

echo "OK: Navegación de documentos\n";
