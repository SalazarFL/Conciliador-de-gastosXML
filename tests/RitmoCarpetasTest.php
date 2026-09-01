<?php
/**
 * Cada cuánto se le pregunta a una carpeta si cambió, y en qué orden.
 *
 * Lo que se comprueba es la línea entre ahorrar viajes y perder correo. Bajar
 * el ritmo de las carpetas de meses cerrados es lo que hace que 42 buzones
 * quepan en el presupuesto; bajárselo a la carpeta equivocada es no ver una
 * factura que llegó. Así que las dos mitades importan igual: que el archivo se
 * enfríe, y que la bandeja de entrada NO se enfríe nunca.
 *
 * Uso: php tests/RitmoCarpetasTest.php
 */
require_once __DIR__ . '/../app/helpers/RitmoCarpetas.php';

function assertRitmo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$dia = 86400;
$viejo = (RitmoCarpetas::DIAS_PARA_ENFRIARSE + 1) * $dia;
$reciente = 2 * $dia;

// ── El ritmo de cada carpeta ───────────────────────────────────────────────

assertRitmo(RitmoCarpetas::ventana(true, $viejo) === RitmoCarpetas::VENTANA_BASE,
    'la carpeta donde entra el correo nunca se enfría, por vieja que parezca');

assertRitmo(RitmoCarpetas::ventana(false, $reciente) === RitmoCarpetas::VENTANA_ACTIVA,
    'una carpeta que recibió algo hace poco sigue en el ritmo de siempre');

assertRitmo(RitmoCarpetas::ventana(false, $viejo) === RitmoCarpetas::VENTANA_ARCHIVO,
    'una carpeta quieta hace semanas pasa al ritmo de archivo');

// En el buzón real son 217 de 323: carpetas que el servidor lista y que nunca
// tuvieron nada. Tratarlas como vivas era quedarse casi sin ahorro.
assertRitmo(RitmoCarpetas::ventana(false, null) === RitmoCarpetas::VENTANA_ARCHIVO,
    'una carpeta mirada muchas veces y sin un solo cambio anotado es archivo');

// Pero eso no puede alcanzar a una que todavía no se sincronizó nunca: esa
// tiene que entrar al índice ya, y de eso se encargan toca() y urgencia().
assertRitmo(RitmoCarpetas::toca(null, RitmoCarpetas::ventana(false, null)),
    'una carpeta nunca sincronizada toca igual, aunque su ventana sea la de archivo');

// El archivo baja el ritmo, no se abandona. Si alguien archiva un correo a
// mano, tiene que aparecer en el índice dentro de la hora.
assertRitmo(RitmoCarpetas::VENTANA_ARCHIVO <= 3600,
    'una carpeta de archivo se sigue revisando al menos una vez por hora');

// ── Cuándo toca ────────────────────────────────────────────────────────────

assertRitmo(RitmoCarpetas::toca(null, RitmoCarpetas::VENTANA_ARCHIVO),
    'una carpeta que nunca se sincronizó toca siempre: es como entra al índice');

assertRitmo(!RitmoCarpetas::toca(30, RitmoCarpetas::VENTANA_ACTIVA),
    'una carpeta vista hace un momento no se vuelve a preguntar');

assertRitmo(RitmoCarpetas::toca(RitmoCarpetas::VENTANA_ACTIVA, RitmoCarpetas::VENTANA_ACTIVA),
    'al cumplirse el plazo justo, toca');

// ── El orden: por urgencia propia, no por antigüedad ───────────────────────
//
// Este es el caso que rompía con un solo ritmo. La carpeta de archivo lleva
// más tiempo sin mirarse en segundos absolutos, pero no está vencida; la
// bandeja sí. Ordenar por antigüedad pura pondría el archivo primero y dejaría
// el correo nuevo esperando.
$cola = RitmoCarpetas::porRevisar([
    ['carpeta' => 'CORREOS 2024/10', 'es_base' => false, 'edad_sync' => 1800, 'edad_cambio' => $viejo],
    ['carpeta' => 'INBOX',           'es_base' => true,  'edad_sync' => 90,   'edad_cambio' => 60],
]);
assertRitmo($cola === ['INBOX'],
    'con la de archivo aún en plazo, solo entra la bandeja aunque lleve menos tiempo sin verse');

$cola = RitmoCarpetas::porRevisar([
    ['carpeta' => 'CORREOS 2024/10', 'es_base' => false, 'edad_sync' => 4000, 'edad_cambio' => $viejo],
    ['carpeta' => 'INBOX',           'es_base' => true,  'edad_sync' => 600,  'edad_cambio' => 60],
]);
assertRitmo($cola[0] === 'INBOX',
    'con las dos vencidas, primero la bandeja: se pasó 10 veces de su plazo y el archivo apenas una');

// Una carpeta que todavía no está vencida no entra en la cola.
$cola = RitmoCarpetas::porRevisar([
    ['carpeta' => 'Recientes', 'es_base' => false, 'edad_sync' => 10, 'edad_cambio' => $reciente],
]);
assertRitmo($cola === [], 'lo que está dentro de su plazo no se pregunta');

// Empate: el desempate por nombre evita que dos carpetas igual de vencidas se
// alternen entre tandas y ninguna llegue nunca a revisarse.
$cola = RitmoCarpetas::porRevisar([
    ['carpeta' => 'B', 'es_base' => false, 'edad_sync' => 600, 'edad_cambio' => $reciente],
    ['carpeta' => 'A', 'es_base' => false, 'edad_sync' => 600, 'edad_cambio' => $reciente],
]);
assertRitmo($cola === ['A', 'B'], 'el orden es estable cuando dos carpetas empatan');

// Las nunca sincronizadas van primero que cualquier vencida.
$cola = RitmoCarpetas::porRevisar([
    ['carpeta' => 'Vieja',  'es_base' => false, 'edad_sync' => 99999, 'edad_cambio' => $viejo],
    ['carpeta' => 'Nueva',  'es_base' => false, 'edad_sync' => null,  'edad_cambio' => null],
]);
assertRitmo($cola[0] === 'Nueva',
    'una carpeta que nunca se indexó va antes que una vencida hace rato');

// ── El resumen que ve el registro ──────────────────────────────────────────

$resumen = RitmoCarpetas::resumen([
    ['carpeta' => 'INBOX',   'es_base' => true,  'edad_cambio' => 60],
    ['carpeta' => 'Activa',  'es_base' => false, 'edad_cambio' => $reciente],
    ['carpeta' => '2024/10', 'es_base' => false, 'edad_cambio' => $viejo],
    ['carpeta' => '2024/11', 'es_base' => false, 'edad_cambio' => $viejo],
]);
assertRitmo($resumen === ['base' => 1, 'activas' => 1, 'archivo' => 2],
    'el resumen cuenta cada carpeta en su ritmo');

// ── El ahorro, con la forma real de los buzones de hoy ─────────────────────
//
// 323 carpetas entre los tres buzones: 3 bandejas de entrada, 18 que
// recibieron algo en las últimas dos semanas, 88 de meses cerrados y 214 que
// el servidor lista pero que nunca tuvieron nada. Antes se preguntaban las 323
// en cada corrida.
$carpetas = [];
for ($i = 0; $i < 3; $i++) {
    $carpetas[] = ['carpeta' => "INBOX{$i}", 'es_base' => true, 'edad_sync' => 999, 'edad_cambio' => 60];
}
for ($i = 0; $i < 18; $i++) {
    $carpetas[] = ['carpeta' => "activa{$i}", 'es_base' => false, 'edad_sync' => 999, 'edad_cambio' => $reciente];
}
// Las frías quedan repartidas dentro de su hora, que es como se acomodan en
// régimen: solo una fracción vence en cada corrida.
$frias = 88 + 214;
for ($i = 0; $i < $frias; $i++) {
    $carpetas[] = ['carpeta' => "fria{$i}", 'es_base' => false,
                   'edad_sync' => (int) ($i * 3600 / $frias),
                   'edad_cambio' => $i < 88 ? $viejo : null];
}
assertRitmo(count($carpetas) === 323, 'el escenario refleja los buzones reales: 323 carpetas');

$cola = RitmoCarpetas::porRevisar($carpetas);
assertRitmo(count($cola) < 90,
    'en régimen se revisa menos de un tercio por corrida (fueron ' . count($cola) . ' de 323)');

// Y lo que no puede pasar nunca: que una bandeja de entrada vencida se quede
// fuera de la tanda porque el archivo le ganó el lugar.
$primeras = array_slice($cola, 0, 3);
sort($primeras);
assertRitmo($primeras === ['INBOX0', 'INBOX1', 'INBOX2'],
    'las tres bandejas de entrada encabezan la cola, por delante de todo el archivo');

echo "OK: Ritmo de revisión de las carpetas del buzón\n";
