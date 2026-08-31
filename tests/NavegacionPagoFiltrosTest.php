<?php
/**
 * Las flechas de la tarjeta recorren la lista de la que se venía.
 *
 * La tarjeta de "buscar el electrónico" nace en el checklist de un pago
 * semanal, y ese checklist casi siempre está filtrado —por proveedor, por
 * sucursal, por "sin respaldo"—. Si la tarjeta rearma el pago ENTERO, pasa lo
 * que hacía: las flechas caminan facturas que ya tienen respaldo (que ni
 * siquiera traen el botón para llegar hasta acá) y el marcador dice "3 / 200"
 * sobre una lista que nadie está viendo.
 *
 * Lo que se comprueba es que los filtros lleguen, que vuelvan en el enlace de
 * regreso y en el de las flechas, y que el total que enseña la tarjeta sea el
 * de esa lista y no el del pago completo.
 */
require_once __DIR__ . '/../app/helpers/NavegacionDocumentos.php';

function assertNav($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** El pago, que solo tiene que decir cómo se llama. */
class PorPagarFalso
{
    public function getListado($id)
    {
        return ['id' => (int) $id, 'nombre' => 'Pago 7', 'semana_nombre' => 'Semana 30'];
    }
}

/** Las líneas del pago, que anota con qué filtros se las pidieron. */
class FacturaErpFalso
{
    public $filtrosRecibidos = null;
    public $lineas;

    public function __construct(array $lineas) { $this->lineas = $lineas; }

    public function getFacturasPago($listadoId, array $filtros = [])
    {
        $this->filtrosRecibidos = $filtros;

        // El doble filtra como el de verdad en lo que la prueba necesita:
        // el estado, que es lo que separa "lo que hay que buscar" del pago
        // entero.
        $estado = (string) ($filtros['estado'] ?? '');
        if ($estado === '') {
            return $this->lineas;
        }
        return array_values(array_filter($this->lineas, function ($l) use ($estado) {
            return $l['estado'] === $estado;
        }));
    }
}

$linea = function ($id, $estado) {
    return [
        'id' => $id,
        'documento' => '24000086' . str_pad((string) $id, 2, '0', STR_PAD_LEFT),
        'proveedor_nombre' => 'EXPORTADORA MAYS ZONA LIBRE S.A',
        'fecha_emision' => '2026-07-02',
        'saldo_pago' => 1000.0 + $id,
        'estado' => $estado,
    ];
};

// Un pago de 10 facturas de las que solo 3 están sin respaldo.
$lineas = [];
for ($i = 1; $i <= 10; $i++) {
    $lineas[] = $linea($i, $i % 4 === 0 ? 'sin_respaldo' : 'respaldada');
}
$sinRespaldo = array_values(array_filter($lineas, function ($l) {
    return $l['estado'] === 'sin_respaldo';
}));
assertNav(count($sinRespaldo) === 2, 'el pago de prueba tiene dos facturas sin respaldo');

$erp = new FacturaErpFalso($lineas);
$modelo = function ($nombre) use ($erp) {
    return $nombre === 'FacturaErp' ? $erp : new PorPagarFalso();
};

// ── Se llega con los filtros del checklist puestos ───────────────────
$ctx = NavegacionDocumentos::desde([
    'ctx' => 'pago',
    'ctx_lista' => 7,
    'ctx_item' => '8',
    'ctx_f_estado' => 'sin_respaldo',
    'ctx_f_proveedor' => 'cod:140000003',
], $modelo, '/xmlconcilia/public');

assertNav($ctx !== null, 'con ctx=pago se arma la tarjeta');
assertNav($erp->filtrosRecibidos === ['estado' => 'sin_respaldo', 'proveedor' => 'cod:140000003'],
    'los filtros del checklist llegan tal cual a la consulta de las líneas');
assertNav(count($ctx['items']) === 2,
    'la tarjeta recorre las facturas filtradas, no el pago entero');
assertNav($ctx['total'] === 2,
    'y el total que enseña es el de esa lista');
assertNav($ctx['idx'] === 1,
    'entra apuntando al documento desde el que se pulsó, no al primero');

// ── Los filtros vuelven en las dos direcciones ───────────────────────
assertNav(strpos($ctx['params'], 'ctx_f_estado=sin_respaldo') !== false
    && strpos($ctx['params'], 'ctx_lista=7') !== false,
    'pasar al siguiente con las flechas no rearma otra lista');
assertNav(strpos($ctx['volver'], 'estado=sin_respaldo') !== false
    && strpos($ctx['volver'], 'listado_id=7') !== false,
    '"Volver" cae en el checklist tal como se dejó');

// ── Sin filtros, el pago entero, como antes ──────────────────────────
$erpTodo = new FacturaErpFalso($lineas);
$modeloTodo = function ($nombre) use ($erpTodo) {
    return $nombre === 'FacturaErp' ? $erpTodo : new PorPagarFalso();
};
$ctxTodo = NavegacionDocumentos::desde([
    'ctx' => 'pago', 'ctx_lista' => 7, 'ctx_item' => '1',
], $modeloTodo, '/xmlconcilia/public');

assertNav(count($ctxTodo['items']) === 10 && $ctxTodo['total'] === 10,
    'sin filtros se recorre el pago completo');

// ── Una lista más larga que el tope se marca, no se disfraza ─────────
$muchas = [];
for ($i = 1; $i <= NavegacionDocumentos::TOPE + 40; $i++) { $muchas[] = $linea($i, 'sin_respaldo'); }
$erpMuchas = new FacturaErpFalso($muchas);
$modeloMuchas = function ($nombre) use ($erpMuchas) {
    return $nombre === 'FacturaErp' ? $erpMuchas : new PorPagarFalso();
};
$ctxMuchas = NavegacionDocumentos::desde([
    'ctx' => 'pago', 'ctx_lista' => 7, 'ctx_item' => '1',
], $modeloMuchas, '/xmlconcilia/public');

assertNav(count($ctxMuchas['items']) === NavegacionDocumentos::TOPE,
    'la tarjeta se trae hasta el tope de documentos');
assertNav($ctxMuchas['total'] === NavegacionDocumentos::TOPE + 40,
    'pero dice cuántos hay de verdad, para no dar el tope por total');

echo "OK: NavegacionPagoFiltros\n";
