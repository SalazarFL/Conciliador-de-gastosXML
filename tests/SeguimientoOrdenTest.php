<?php
/**
 * Cómo se ordena la cola de trabajo, en los dos modos.
 *
 * Salió "Dinero en juego", que era el orden por omisión: encabezar siempre con
 * las mismas facturas grandes escondía al fondo lo recién llegado, que es lo
 * que hay que registrar. Ahora manda la fecha.
 *
 * Y con la fecha al mando aparece un problema que antes casi no se daba:
 * `fecha` es un DATE, así que empatan todas las filas del mismo día. Sin un
 * criterio único al final, cada página —que es una consulta aparte— puede
 * barajar esos empates distinto, y entonces un renglón sale en dos páginas
 * mientras otro no sale en ninguna. De ahí el desempate por (referencia_id,
 * origen), que es único en la unión.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertOrden($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

// ── Lo que se ofrece, y lo que ya no ────────────────────────────────────────

assertOrden(!isset(Seguimiento::ORDENES['monto']),
    'el orden por dinero en juego ya no se ofrece');
assertOrden(Seguimiento::ORDEN_POR_OMISION === 'reciente',
    'la cola sale ordenada por más reciente');
assertOrden(isset(Seguimiento::ORDENES[Seguimiento::ORDEN_POR_OMISION]),
    'el orden por omisión es uno de los que se ofrecen');

// ── Un orden que no existe cae en el de omisión, en los dos modos ───────────
//
// Importa por las sesiones ya abiertas: la barra recuerda el último orden
// elegido, y ahí puede haber guardado 'monto' de antes del cambio.
foreach ([Seguimiento::MODO_SISTEMA, Seguimiento::MODO_CORREO] as $modo) {
    foreach (['', 'monto', 'inventado'] as $pedido) {
        $f = Seguimiento::filtrosDesde(['modo' => $modo, 'orden' => $pedido]);
        assertOrden($f['orden'] === Seguimiento::ORDEN_POR_OMISION,
            "modo {$modo}: el orden '{$pedido}' cae en el de omisión");
    }
    $f = Seguimiento::filtrosDesde(['modo' => $modo, 'orden' => 'proveedor']);
    assertOrden($f['orden'] === 'proveedor', "modo {$modo}: un orden válido se respeta");
}

// ── Todo orden termina con un desempate único ───────────────────────────────

$ordenSql = new ReflectionMethod('Seguimiento', 'ordenSql');
$ordenSql->setAccessible(true);
$modelo = (new ReflectionClass('Seguimiento'))->newInstanceWithoutConstructor();

foreach (array_keys(Seguimiento::ORDENES) as $orden) {
    $sql = $ordenSql->invoke($modelo, $orden);

    assertOrden(strpos($sql, 'c.referencia_id') !== false
        && substr($sql, -strlen('c.origen ASC')) === 'c.origen ASC',
        "el orden '{$orden}' cierra con (referencia_id, origen), que es único en la unión");
    assertOrden(strpos($sql, 'c.en_juego') === false,
        "el orden '{$orden}' ya no ordena por dinero");
}

// Y el que no se reconoce ordena como el de omisión, no como cualquier cosa.
assertOrden($ordenSql->invoke($modelo, 'monto')
    === $ordenSql->invoke($modelo, Seguimiento::ORDEN_POR_OMISION),
    'un orden desconocido ordena igual que el de omisión');

// El de omisión es por fecha descendente: lo último que entró, arriba.
assertOrden(strpos($ordenSql->invoke($modelo, 'reciente'), 'c.fecha DESC') === 0,
    'más reciente ordena por fecha, de la más nueva a la más vieja');
assertOrden(strpos($ordenSql->invoke($modelo, 'antiguedad'), 'c.fecha ASC') === 0,
    'y más antiguo, al revés');

// ── La pantalla ofrece exactamente lo que la consulta sabe hacer ────────────

$vista = file_get_contents(__DIR__ . '/../app/views/seguimiento/index.php');
assertOrden(strpos($vista, 'Seguimiento::ORDENES') !== false,
    'el desplegable se pinta desde la lista del modelo, no desde una copia');
assertOrden(strpos($vista, 'Dinero en juego') === false,
    'y no queda ninguna opción de ordenar por dinero en la pantalla');

echo "OK: orden de la cola (por omisión: " . Seguimiento::ORDENES[Seguimiento::ORDEN_POR_OMISION] . ")\n";
