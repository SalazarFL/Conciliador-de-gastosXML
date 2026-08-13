<?php
/**
 * Descarte de incidencias del módulo Correo.
 *
 * La lista no bajaba nunca: 836 incidencias acumuladas, la mayoría de cosas
 * que alguien ya miró y resolvió que no dan trabajo —cédulas de otra empresa,
 * rechazos de Hacienda, correos sin PDF—. Una lista que no baja deja de
 * leerse, y con ella se pierden las que sí importan.
 *
 * Lo que esta prueba vigila:
 *   - que la firma distinga incidencias distintas del MISMO correo, porque un
 *     correo trae varias facturas y genera una por cada una;
 *   - que la firma sea la MISMA entre corridas, que es lo que hace que el
 *     descarte sobreviva a reprocesar;
 *   - que el filtro del historial y el de "descartar todas" sean el mismo
 *     código, para que "todas" no alcance filas que el usuario no vio.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/CorreoLote.php';

function assertDescarteCorreo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── 1. La firma ────────────────────────────────────────────────
$correo = ['INBOX', 1704414945, 56515];

$a = CorreoLote::firmaIncidencia('adjunto', $correo[0], $correo[1], $correo[2],
    'La factura 2312 vino sin PDF en este correo');
$b = CorreoLote::firmaIncidencia('adjunto', $correo[0], $correo[1], $correo[2],
    'La factura 2313 vino sin PDF en este correo');
assertDescarteCorreo($a !== $b,
    'dos facturas del mismo correo son incidencias distintas: descartar una no puede descartar la otra');

$mismo = CorreoLote::firmaIncidencia('adjunto', $correo[0], $correo[1], $correo[2],
    'La factura 2312 vino sin PDF en este correo');
assertDescarteCorreo($a === $mismo,
    'la misma incidencia da la misma firma: de eso depende que el descarte sobreviva a reprocesar');

$otroTipo = CorreoLote::firmaIncidencia('rechazado', $correo[0], $correo[1], $correo[2],
    'La factura 2312 vino sin PDF en este correo');
assertDescarteCorreo($a !== $otroTipo, 'el tipo entra en la firma');

$otroCorreo = CorreoLote::firmaIncidencia('adjunto', 'INBOX', 1704414945, 99999,
    'La factura 2312 vino sin PDF en este correo');
assertDescarteCorreo($a !== $otroCorreo, 'el correo entra en la firma');

// El lote NO entra: al reprocesar un rango, el lote es otro y la incidencia
// tiene que reconocerse como la misma.
assertDescarteCorreo(strlen($a) === 40, 'la firma es un sha1 y cabe en la columna');

// ── 2. El filtro compartido ────────────────────────────────────
class CorreoLoteFiltroFalso extends CorreoLote
{
    public function __construct() { /* sin conexión */ }

    public function condiciones($cuentaId, array $filtros)
    {
        $ref = new ReflectionMethod(CorreoLote::class, 'condicionesIncidencia');
        $ref->setAccessible(true);
        return $ref->invoke($this, $cuentaId, $filtros);
    }
}

$modelo = new CorreoLoteFiltroFalso();

[$where, $params] = $modelo->condiciones(3, []);
assertDescarteCorreo(strpos($where, 'x.descartada = 0') !== false,
    'por omisión el historial muestra solo lo pendiente: descartar sirve para dejar de verlo');

[$where] = $modelo->condiciones(3, ['ver' => 'descartadas']);
assertDescarteCorreo(strpos($where, 'x.descartada = 1') !== false, 'se puede mirar lo descartado');

[$where] = $modelo->condiciones(3, ['ver' => 'todas']);
assertDescarteCorreo(strpos($where, 'x.descartada') === false, '"todas" no filtra por descarte');

[$where, $params] = $modelo->condiciones(3, ['tipo' => 'otra_cedula']);
assertDescarteCorreo(strpos($where, 'x.tipo = ?') !== false && in_array('otra_cedula', $params, true),
    'el filtro por tipo es el que permite descartar las 194 cédulas ajenas de una vez');

[$where, $params] = $modelo->condiciones(7, ['q' => 'DEMASA']);
assertDescarteCorreo(in_array(7, $params, true), 'siempre se limita a la cuenta de correo pedida');
assertDescarteCorreo(in_array('%DEMASA%', $params, true), 'la búsqueda libre viaja como LIKE');

echo "OK: las incidencias de correo se pueden descartar y el descarte sobrevive al reproceso\n";
