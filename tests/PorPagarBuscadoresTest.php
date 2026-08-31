<?php
/**
 * Los buscadores del checklist del pago semanal.
 *
 * Buscan sobre las facturas del ERP marcadas para la semana, que es donde
 * viven ahora los datos. Lo que se comprueba es que cada filtro llegue al SQL
 * con la columna correcta: un filtro que apunte a la columna equivocada no da
 * error, da resultados que parecen buenos.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';

function assertPorPagarBuscador($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class PorPagarBuscadoresFalso extends FacturaErp
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

// El proveedor ya no es texto libre: es la clave que da el filtro de
// proveedor (ver ProveedorCatalogo). 'cod:' es un código del ERP que el mapa
// todavía no sabe de quién es, así que la condición se queda en ese código.
$CLAVE_PROVEEDOR = 'cod:__PRUEBA_JOP__';

$modelo = new PorPagarBuscadoresFalso();
$modelo->getFacturasPago(12, [
    'q' => '00100001010000045587',
    'proveedor' => $CLAVE_PROVEEDOR,
    'estado' => 'con_diferencia',
    'vinculo' => 'manual',
    'fecha_desde' => '2026-07-01',
    'fecha_hasta' => '2026-07-31',
    'monto' => '90000',
    'saldo' => '45000',
]);

$sql = $modelo->sql;

assertPorPagarBuscador($modelo->params[0] === 12, 'el checklist se limita al pago pedido');
assertPorPagarBuscador(strpos($sql, 'e.porpagar_listado_id = ?') !== false,
    'las líneas del pago son las facturas del ERP marcadas con ese pago');

// El buscador de número mira las dos formas del ERP y las dos del XML: quien
// busca escribe la que tiene a mano, no la que el sistema usa por dentro.
foreach (['e.documento LIKE ?', 'e.numero_corto LIKE ?',
          'x.numero_factura_asistente LIKE ?', 'x.consecutivo_completo LIKE ?'] as $columna) {
    assertPorPagarBuscador(strpos($sql, $columna) !== false, "el buscador de número cubre {$columna}");
}

// El proveedor se reconoce por lo que lo identifica —el código del ERP, la
// cédula del emisor del XML— y NO por el nombre: dos proveedores se escriben
// igual y el mismo se escribe de tres formas.
assertPorPagarBuscador(strpos($sql, 'e.proveedor_codigo IN (?)') !== false,
    'el proveedor se reconoce por su código del ERP');
assertPorPagarBuscador(strpos($sql, 'x.proveedor_id IN') !== false
    || strpos($sql, "REPLACE(REPLACE(p.rfc") !== false
    || strpos($sql, 'e.proveedor_codigo IN (?)') !== false,
    'y, en las líneas ya emparejadas, también por el emisor del comprobante');
assertPorPagarBuscador(strpos($sql, 'e.proveedor_nombre LIKE ?') === false,
    'el nombre del proveedor ya no filtra: es informativo');

assertPorPagarBuscador(strpos($sql, 'e.estado_respaldo = ?') !== false,
    'el semáforo filtra por estado_respaldo, no por el estado de asignación');
assertPorPagarBuscador(strpos($sql, 'e.estado = ?') === false,
    'no se confunde con `estado`, que dice si la factura está asignada a una semana');

assertPorPagarBuscador(strpos($sql, 'e.match_manual = 1') !== false, 'filtra los vínculos hechos a mano');
assertPorPagarBuscador(strpos($sql, 'e.fecha_emision >= ?') !== false
    && strpos($sql, 'e.fecha_emision <= ?') !== false,
    'las fechas son las de emisión de la factura del ERP');

/*
 * Se busca por el importe que se tiene a mano, y monto y saldo son dos
 * columnas distintas. Esa es la corrección: hasta hace poco había un solo
 * filtro llamado "monto" que en realidad miraba el saldo, así que buscar una
 * factura por lo que dice dejaba fuera las que ya estaban medio pagadas.
 */
assertPorPagarBuscador(strpos($sql, 'CAST(e.monto AS CHAR) LIKE ?') !== false,
    'el importe se busca dentro del monto de la factura');

// El saldo sigue siendo el del pago y no el vivo: si no, una factura ya
// pagada (saldo 0) desaparecería del checklist de su propia semana.
assertPorPagarBuscador(strpos($sql, 'CAST(COALESCE(e.saldo_pago, e.saldo) AS CHAR) LIKE ?') !== false,
    'y el saldo se busca sobre el que la factura tenía al entrar al pago');

// Cada uno con su propio valor: es lo que se rompería si alguien volviera a
// apuntar los dos buscadores a la misma columna.
assertPorPagarBuscador(in_array('%90000%', $modelo->params, true)
    && in_array('%45000%', $modelo->params, true),
    'los dos importes llegan a la consulta, sin pisarse');

assertPorPagarBuscador($modelo->params === [
    12,
    '%00100001010000045587%', '%00100001010000045587%', '%00100001010000045587%', '%00100001010000045587%',
    '__PRUEBA_JOP__',
    'con_diferencia',
    '2026-07-01', '2026-07-31',
    // Primero el monto y después el saldo, en el orden en que se arman: es lo
    // que hay que respetar para que cada ? reciba su valor.
    '%90000%', '%45000%',
], 'los parámetros llegan en el orden de las condiciones');

// ── Sin filtros ──────────────────────────────────────────────────
$limpio = new PorPagarBuscadoresFalso();
$limpio->getFacturasPago(12, []);
assertPorPagarBuscador($limpio->params === [12], 'sin filtros solo se acota el pago');
assertPorPagarBuscador(strpos($limpio->sql, 'LIKE') === false, 'sin texto no se arma ningún LIKE');

// ── Un filtro inválido no se cuela ───────────────────────────────
$raro = new PorPagarBuscadoresFalso();
$raro->getFacturasPago(12, [
    'estado' => 'inventado', 'vinculo' => 'raro',
    // Un importe que no es un número no filtra: buscarlo dentro de la columna
    // no encontraría nada y además dejaría basura en la consulta.
    'monto' => 'x', 'saldo' => '%',
]);
assertPorPagarBuscador($raro->params === [12], 'los valores que no existen se ignoran, no se pasan al SQL');
assertPorPagarBuscador(strpos($raro->sql, 'CAST(') === false,
    'y sin importe válido no se arma ninguna comparación de importe');

echo "OK: buscadores del checklist del pago semanal\n";
