<?php
require_once __DIR__ . '/../app/helpers/NotasCreditoVerificador.php';

function assertSameNcv($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nEsperado: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

class NotaCreditoModeloFalso
{
    public $lineas;
    public $facturas;
    public $resultados = [];
    public $verificaciones = [];
    public $cambiosHistorial = [];

    public function __construct(array $lineas, array $facturas)
    {
        $this->lineas = $lineas;
        $this->facturas = $facturas;
    }

    public function getListado($id)
    {
        return ['id' => $id, 'sociedad_cedula' => '3101000000'];
    }

    public function getLineasParaMatching($id)
    {
        return $this->lineas;
    }

    public function getFacturasNcSociedad($cedula)
    {
        return $this->facturas;
    }

    public function actualizarMatch($lineaId, $facturaId, $estado, $diferencia, $metodo, $score, $manual, $motivo, $bloqueo = 0)
    {
        $this->resultados[$lineaId] = compact(
            'facturaId', 'estado', 'diferencia', 'metodo', 'score', 'manual', 'motivo', 'bloqueo'
        );
    }

    public function iniciarVerificacion($listadoId, $origen = 'automatico')
    {
        $this->verificaciones[] = ['listado_id' => $listadoId, 'origen' => $origen];
        return count($this->verificaciones);
    }

    public function registrarCambioVerificacion($verificacionId, array $anterior, array $nuevo)
    {
        $this->cambiosHistorial[] = compact('verificacionId', 'anterior', 'nuevo');
        return true;
    }

    public function finalizarVerificacion($verificacionId, array $stats, $cantidadCambios)
    {
        $this->verificaciones[$verificacionId - 1]['stats'] = $stats;
        $this->verificaciones[$verificacionId - 1]['cantidad_cambios'] = $cantidadCambios;
    }
}

function lineaNcv($id, $proveedor, $monto, $moneda = 'CRC', $fecha = '2026-07-02', $montoConversion = 0)
{
    return [
        'id' => $id,
        'proveedor_nombre' => $proveedor,
        'monto' => $monto,
        'monto_conversion' => $montoConversion,
        'moneda' => $moneda,
        'fecha' => $fecha,
        'fecha_nc_proveedor' => null,
        'nc_proveedor' => null,
        'factura_xml_id' => null,
        'match_manual' => 0,
        'bloqueo_automatico' => 0,
        'estado' => 'sin_respaldo',
    ];
}

function facturaNcv($id, $proveedor, $total, $fecha, $moneda = 'CRC', $numero = '')
{
    return [
        'id' => $id,
        'proveedor_nombre' => $proveedor,
        'proveedor_alias' => null,
        'total' => $total,
        'moneda' => $moneda,
        'fecha_emision' => $fecha,
        'consecutivo_completo' => $numero,
        'numero_factura_asistente' => $numero,
    ];
}

assertSameNcv(
    ['BIMBO', 'COSTA', 'RICA'],
    FacturaMatcher::tokenizarProveedor('BIMBO DE COSTA RICA S.A.'),
    'S.A. no debe producir tokens S y A'
);
assertSameNcv(false, FacturaMatcher::mismoProveedor(
    'BIMBO DE COSTA RICA S.A.',
    'COMERCIAL DINANT DE COSTA RICA, S.A.'
), 'Bimbo y Dinant son proveedores diferentes');
assertSameNcv(false, FacturaMatcher::mismoProveedor(
    'CIAMESA S.A.',
    'COMERCIALIZADORA AMERICANA COAMESA S.A.'
), 'CIAMESA y COAMESA son proveedores diferentes');
assertSameNcv(true, NotasCreditoVerificador::mismoNumeroNc(
    '0000017662',
    ['consecutivo_completo' => '00100001010000017662', 'numero_factura_asistente' => '00017662']
), 'el numero anterior de diez digitos coincide con el nuevo formato de ocho');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(1, 'BIMBO DE COSTA RICA S.A.', 13656.22)],
    [facturaNcv(10, 'COMERCIAL DINANT DE COSTA RICA, S.A.', 13656.22, '2026-07-02')]
);
NotasCreditoVerificador::verificarListado(1, $modelo, 'manual');
assertSameNcv(null, $modelo->resultados[1]['facturaId'], 'no vincula otro proveedor aunque el monto sea exacto');
assertSameNcv('manual', $modelo->verificaciones[0]['origen'], 'el historial conserva el origen de la verificación');
assertSameNcv(1, $modelo->verificaciones[0]['cantidad_cambios'], 'el historial contabiliza las filas modificadas');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(2, 'BIMBO DE COSTA RICA S.A.', 100.00)],
    [
        facturaNcv(20, 'Bimbo de Costa Rica, S.A.', 95.00, '2025-01-01'),
        facturaNcv(21, 'Bimbo de Costa Rica, S.A.', 100.25, '2024-01-01'),
    ]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[2]['facturaId'], 'no vincula el monto más cercano si no es exacto');
assertSameNcv('sin_respaldo', $modelo->resultados[2]['estado'], 'una diferencia de 25 céntimos queda sin respaldo');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(3, 'CIAMESA S.A.', 100.00)],
    [
        facturaNcv(30, 'CIAMESA, S.A.', 100.00, '2026-01-01'),
        facturaNcv(31, 'CIAMESA, S.A.', 100.00, '2026-12-01'),
    ]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[3]['facturaId'], 'varios XML con monto exacto quedan para revisión manual');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(4, 'BIMBO DE COSTA RICA S.A.', 5000.00)],
    [facturaNcv(40, 'Bimbo de Costa Rica, S.A.', 4999.99, '2026-01-01')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[4]['facturaId'], 'rechaza una diferencia CRC de un céntimo');
assertSameNcv('sin_respaldo', $modelo->resultados[4]['estado'], 'un monto no exacto queda sin respaldo');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(5, 'BIMBO DE COSTA RICA S.A.', 5000.00)],
    [facturaNcv(50, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(50, $modelo->resultados[5]['facturaId'], 'acepta un monto CRC exactamente igual');

$modelo = new NotaCreditoModeloFalso(
    [lineaNcv(6, 'PROVEEDOR USD S.A.', 100.00, 'USD', '2026-07-02', 50000.00)],
    [facturaNcv(60, 'PROVEEDOR USD S.A.', 99.99, '2025-01-01', 'USD')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[6]['facturaId'], 'rechaza una diferencia USD de un centavo');

$linea = lineaNcv(7, 'BIMBO DE COSTA RICA S.A.', 100.00);
$linea['nc_proveedor'] = '00100001030000000111';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [
        facturaNcv(70, 'Bimbo de Costa Rica S.A.', 100.50, '2025-01-01', 'CRC', '00100001030000000111'),
        facturaNcv(71, 'Bimbo de Costa Rica S.A.', 100.00, '2025-01-01', 'CRC', '00100001030000000222'),
    ]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[7]['facturaId'], 'el consecutivo exacto no permite vincular un monto diferente');

$linea = lineaNcv(10, 'BIMBO DE COSTA RICA S.A.', 100.00);
$linea['nc_proveedor'] = '00100001030000000111';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(100, 'Bimbo de Costa Rica S.A.', 100.00, '2025-01-01', 'CRC', '00100001030000000111')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(100, $modelo->resultados[10]['facturaId'], 'vincula cuando consecutivo, proveedor y monto son exactos');
assertSameNcv('numero', $modelo->resultados[10]['metodo'], 'registra el método de coincidencia por número');

$linea = lineaNcv(8, 'BIMBO DE COSTA RICA S.A.', 100.00);
$linea['nc_proveedor'] = '00100001030000000333';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(80, 'Bimbo de Costa Rica S.A.', 100.00, '2025-01-01', 'CRC', '00100001030000000444')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[8]['facturaId'], 'sin el consecutivo exacto no usa otra NC del proveedor');

$linea = lineaNcv(9, 'BIMBO DE COSTA RICA S.A.', 100.00);
$linea['nc_proveedor'] = '0010-0001-0300-0000-0555';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(90, 'COMERCIAL DINANT DE COSTA RICA S.A.', 100.00, '2025-01-01', 'CRC', '00100001030000000555')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[9]['facturaId'], 'el consecutivo exacto no permite otro proveedor');

$linea = lineaNcv(11, 'BIMBO DE COSTA RICA S.A.', 100.00);
$linea['factura_xml_id'] = 110;
$linea['match_manual'] = 1;
$linea['xml_total'] = 99.99;
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [
        facturaNcv(110, 'Bimbo de Costa Rica S.A.', 99.99, '2025-01-01'),
        facturaNcv(111, 'Bimbo de Costa Rica S.A.', 100.00, '2025-01-01'),
    ]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(111, $modelo->resultados[11]['facturaId'], 'reemplaza un vínculo manual antiguo cuyo monto no era exacto');
assertSameNcv(false, $modelo->resultados[11]['manual'], 'el reemplazo válido vuelve a ser automático');

// Una nota de ajuste interno no tiene documento electrónico. Aunque haya un
// XML del mismo proveedor con el monto clavado, no se le debe enganchar: ese
// XML es de otra nota y engancharlo la deja a ella sin respaldo.
$linea = lineaNcv(12, 'BIMBO DE COSTA RICA S.A.', 5000.00);
$linea['documento'] = 'NC- 4945';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(120, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[12]['facturaId'], 'una nota de ajuste no se empareja aunque el monto calce');
assertSameNcv('sin_respaldo', $modelo->resultados[12]['estado'], 'la nota de ajuste queda sin respaldo');

// Con consecutivo de NC del proveedor sí se empareja, aunque sea de ajuste:
// exigir consecutivo, proveedor y monto exactos no deja margen a un falso.
$linea = lineaNcv(15, 'BIMBO DE COSTA RICA S.A.', 5000.00);
$linea['documento'] = 'NC- 5031';
$linea['nc_proveedor'] = '00100002030000034572';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(150, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01', 'CRC', '00100002030000034572')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(150, $modelo->resultados[15]['facturaId'], 'una nota de ajuste con consecutivo exacto sí se empareja');

// Pero si el consecutivo no aparece, no cae de vuelta a proveedor y monto.
$linea = lineaNcv(16, 'BIMBO DE COSTA RICA S.A.', 5000.00);
$linea['documento'] = 'NC- 5032';
$linea['nc_proveedor'] = '00100002030000099999';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(160, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01', 'CRC', '00100002030000034572')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(null, $modelo->resultados[16]['facturaId'], 'sin el consecutivo exacto la nota de ajuste no engancha por monto');

// La clase ya guardada manda sobre lo que diga el número.
$linea = lineaNcv(13, 'BIMBO DE COSTA RICA S.A.', 5000.00);
$linea['documento'] = 'NC- 4946';
$linea['clase'] = ClaseNotaCredito::CAMBIO;
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(130, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(130, $modelo->resultados[13]['facturaId'], 'si la clase guardada dice que sí lleva respaldo, se empareja');

// Las demás clases siguen emparejando igual que antes.
$linea = lineaNcv(14, 'BIMBO DE COSTA RICA S.A.', 5000.00);
$linea['documento'] = 'NC- 1-1-D-99900001010000670607-189';
$modelo = new NotaCreditoModeloFalso(
    [$linea],
    [facturaNcv(140, 'Bimbo de Costa Rica, S.A.', 5000.00, '2026-01-01')]
);
NotasCreditoVerificador::verificarListado(1, $modelo);
assertSameNcv(140, $modelo->resultados[14]['facturaId'], 'una nota de costo sí se empareja');

echo "OK: NotasCreditoVerificador\n";
