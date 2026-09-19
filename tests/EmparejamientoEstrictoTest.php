<?php
/**
 * La regla nueva: sin cédula que lo confirme, no se engancha nada.
 *
 * Antes bastaba con que la guarda no se opusiera y el parecido de los nombres
 * hacía el resto. Así se armaron los 29 enganches en los que una factura del
 * sistema terminó con el comprobante de otro proveedor, 294 de ellos figurando
 * como respaldados: dados por resueltos sin estarlo.
 *
 * Acá se comprueban las tres respuestas que importan, sin base de datos: el
 * verificador consulta `ProveedorCodigoErp` solo si la clase existe, así que
 * declararla antes vale como listado de mentira.
 */

class ProveedorCodigoErp
{
    /** @var array codigo => [proveedor_id, hermanos] */
    public static $filas = [];

    public static function hayListado()
    {
        return self::$filas !== [];
    }

    public static function veredicto($codigo, $proveedorIdXml)
    {
        $codigo = trim((string) $codigo);
        $proveedorIdXml = (int) $proveedorIdXml;
        if ($codigo === '' || $proveedorIdXml <= 0) { return 'desconocido'; }
        if (!self::$filas) { return 'desconocido'; }
        $f = self::$filas[$codigo] ?? null;
        if ($f === null || $f['proveedor_id'] !== $proveedorIdXml) { return 'ajeno'; }
        return empty($f['hermanos']) ? 'propio' : 'compartido';
    }
}

require_once __DIR__ . '/../app/helpers/PorPagarVerificador.php';

$fallos = 0;
function comprueba($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) { fwrite(STDERR, "FAIL: {$mensaje}\n"); $fallos++; }
}

/** Un pago con una sola factura y un solo comprobante candidato. */
class ErpDeMentira
{
    public $lineas;
    public $escrito = [];
    public function __construct(array $lineas) { $this->lineas = $lineas; }
    public function getFacturasPagoParaMatching($id) { return $this->lineas; }
    public function actualizarRespaldoLote(array $filas)
    {
        foreach ($filas as $f) { $this->escrito[(int) $f['id']] = $f; }
        return count($filas);
    }
}

class FacturasDeMentira
{
    public $xml;
    public function __construct(array $xml) { $this->xml = $xml; }
    public function getCandidatasParaPago() { return $this->xml; }
}

/**
 * Corre una verificación y devuelve cómo quedó la única factura.
 *
 * @param int $proveedorXml A quién pertenece el comprobante.
 */
function verificar($codigo, $proveedorXml)
{
    $erp = new ErpDeMentira([[
        'id' => 1,
        'documento' => '00100001010000000123',
        'numero_corto' => '00000123',
        'proveedor_codigo' => $codigo,
        'proveedor_nombre' => 'DISTRIBUIDORA LA ESPERANZA S.A.',
        'monto' => 1000.00,
        'estado' => 'sin_respaldo',
        'factura_xml_id' => null,
        'match_manual' => 0,
    ]]);
    $facturas = new FacturasDeMentira([[
        'id' => 77,
        'consecutivo_completo' => '00100001010000000123',
        'numero_factura_asistente' => '00000123',
        'proveedor_id' => $proveedorXml,
        'proveedor_nombre' => 'DISTRIBUIDORA LA ESPERANZA S.A.',
        'total' => 1000.00,
    ]]);
    PorPagarVerificador::verificarListado(1, $erp, $facturas);
    return $erp->escrito[1] ?? [];
}

// ── 1. La cédula confirma: se engancha ─────────────────────────────────────
ProveedorCodigoErp::$filas = ['140000500' => ['proveedor_id' => 9, 'hermanos' => 0]];
$r = verificar('140000500', 9);
comprueba(($r['factura_xml_id'] ?? null) === 77,
    'con la cédula del código, el comprobante se engancha');
comprueba(($r['estado_respaldo'] ?? '') === 'respaldada', 'y queda respaldada');
comprueba(($r['score_proveedor'] ?? 0) == 100.0,
    'el 100 significa cédula confirmada, no nombres parecidos');

// ── 2. La cédula es de otro: no se engancha, por más que el nombre calce ───
//
// Los dos nombres son idénticos a propósito. Antes eso alcanzaba.
ProveedorCodigoErp::$filas = ['140000500' => ['proveedor_id' => 9, 'hermanos' => 0]];
$r = verificar('140000500', 12);
comprueba(array_key_exists('factura_xml_id', $r) && $r['factura_xml_id'] === null,
    'el comprobante de otra cédula no entra, aunque el nombre sea el mismo');
comprueba(($r['estado_respaldo'] ?? '') === 'sin_respaldo',
    'y la factura queda sin respaldo, que es lo que honestamente es');

// ── 3. El código no está en el listado: tampoco ────────────────────────────
ProveedorCodigoErp::$filas = ['140000999' => ['proveedor_id' => 9, 'hermanos' => 0]];
$r = verificar('140000500', 9);
comprueba(array_key_exists('factura_xml_id', $r) && $r['factura_xml_id'] === null,
    'un código que el listado no tiene no empareja con nada');

// ── 4. Cédula repartida entre varios códigos: la decide una persona ────────
//
// El caso de GRUPO BM SP: CEDI, MAYOREO y PROMOCIONES comparten cédula. Que el
// comprobante salió de esa empresa lo dice la cédula; de cuál de sus puntos,
// no lo dice nadie.
ProveedorCodigoErp::$filas = [
    '140000585' => ['proveedor_id' => 9, 'hermanos' => 2],
    '140001688' => ['proveedor_id' => 9, 'hermanos' => 2],
    '140000755' => ['proveedor_id' => 9, 'hermanos' => 2],
];
$r = verificar('140000585', 9);
comprueba(array_key_exists('factura_xml_id', $r) && $r['factura_xml_id'] === null,
    'con la cédula repartida entre tres códigos, no se engancha solo');

// ── 5. Sin listado, este archivo sigue sirviendo suelto ────────────────────
//
// No es la aplicación: ahí el portón impide llegar acá sin listado. Es para
// que el verificador se pueda usar en pruebas y utilidades de línea de
// comandos sin arrastrar la base de datos entera.
ProveedorCodigoErp::$filas = [];
$r = verificar('140000500', 9);
comprueba(($r['factura_xml_id'] ?? null) === 77,
    'sin listado que consultar, se comporta como antes de que la guarda existiera');

if ($fallos > 0) {
    fwrite(STDERR, "{$fallos} fallo(s)\n");
    exit(1);
}
echo "OK: EmparejamientoEstricto\n";
