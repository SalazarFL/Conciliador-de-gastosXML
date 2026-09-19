<?php
/**
 * El enredo llega a la bandeja como un solo bloque y no como líneas sueltas.
 *
 * Es la mitad que hace utilizable lo que decide el parser: dos filas que no se
 * distinguen entre sí no se pueden resolver de a una —la pregunta es "de
 * estas, cuál entra"— así que la bandeja tiene que poder juntarlas, contarlas
 * como un enredo y sacarlas de la lista de líneas sueltas.
 *
 * Toca la base, así que se salta solo si no hay ninguna a mano.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/LineaEnRevision.php';
require_once __DIR__ . '/../app/helpers/FacturasErpCsvParser.php';

function assertEnredo($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

function assertEnredoIgual($esperado, $actual, $mensaje)
{
    if ($esperado !== $actual) {
        fwrite(STDERR, "FAIL: {$mensaje}\nEsperado: " . var_export($esperado, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: FacturasErpEnredoBandeja (sin base de datos disponible)\n";
    exit(0);
}

// Una sociedad que no existe, para no tocar datos de nadie. Se limpia al final
// y también al empezar, por si una corrida anterior murió a la mitad.
$sociedadId = 999777;
$limpiar = function () use ($pdo, $sociedadId) {
    $pdo->prepare('DELETE FROM facturas_erp_revision WHERE sociedad_id = ?')->execute([$sociedadId]);
    $pdo->prepare('DELETE FROM facturas_erp_revision_memoria WHERE sociedad_id = ?')->execute([$sociedadId]);
};

$bandeja = new LineaEnRevision('facturas_erp');
$limpiar();

try {
    // ── Un reporte con dos filas gemelas y una línea que no se pudo leer ──
    //
    // Las dos cosas juntas a propósito: la bandeja tiene que separarlas, no
    // mezclarlas en una sola lista donde el enredo se pierda entre lo demás.
    $fila = function (array $celdas) {
        $f = array_fill(0, 29, '');
        foreach ($celdas as $i => $v) { $f[$i] = $v; }
        return '"' . implode('","', $f) . '"';
    };
    $gemela = function () use ($fila) {
        return [
            $fila([1 => 'F- ', 7 => '27/04/2026', 12 => '27/05/2026', 14 => 'Exter',
                   16 => '¢', 20 => '135,600.00', 26 => '0.00']),
            $fila([22 => '0.00']),
            $fila([1 => '00000000000000000000000000000']),
        ];
    };

    $texto = implode("\r\n", array_merge(
        [$fila([0 => 'Proveedor', 3 => '140000900', 4 => 'PROVEEDOR GEMELO S.A.'])],
        [$fila([1 => 'Documento', 6 => 'Fecha', 10 => 'Vence', 13 => 'Compra',
                15 => 'Moneda', 19 => 'Monto', 21 => 'Saldo'])],
        [$fila([2 => 'Sucursal', 3 => 'Indefinido'])],
        $gemela(),
        $gemela(),
        // Una fila con forma de nada: la línea suelta de siempre.
        [$fila([1 => 'esto no es una factura', 7 => 'ni una fecha'])],
        [$fila([11 => 'Total', 17 => '271,200.00', 27 => '0.00'])],
        [$fila([5 => 'Total Proveedor', 18 => '271,200.00', 26 => '0.00'])],
        [$fila([5 => 'Total General', 18 => '271,200.00', 27 => '0.00'])]
    )) . "\r\n";

    $r = FacturasErpCsvParser::parseTexto($texto, 'enredo.csv');
    assertEnredoIgual(3, count($r['revision']),
        'dos gemelas y una línea ilegible: tres cosas para preguntar');

    // ── Todo eso entra a la bandeja ──
    $insertadas = $bandeja->registrarPendientes($sociedadId, $r['revision']);
    assertEnredoIgual(3, $insertadas, 'las tres tienen que quedar registradas');

    // ── Y sale separado ──
    $enredos = $bandeja->enredosPendientes($sociedadId);
    assertEnredoIgual(1, count($enredos), 'las dos gemelas son un solo enredo');
    assertEnredoIgual(1, $bandeja->contarEnredos($sociedadId), 'y se cuenta como uno');

    $grupo = array_key_first($enredos);
    assertEnredoIgual(2, count($enredos[$grupo]), 'el enredo trae sus dos filas juntas');
    assertEnredoIgual('140000900|SINDOC|2026-04-27|135600.00', $grupo,
        'el grupo es la clave que choca');

    // Las filas del enredo llegan en el orden del reporte: elegir entre ellas
    // exige poder decir "la de arriba", y para eso el orden no puede bailar.
    $filas = array_column($enredos[$grupo], 'fila_origen');
    assertEnredo($filas[0] < $filas[1], 'las filas del enredo van en el orden del archivo');

    // Y con todo lo que hace falta para decidir sin abrir el archivo.
    $primera = $enredos[$grupo][0];
    assertEnredo(!empty($primera['celdas']), 'el enredo conserva las celdas crudas');
    assertEnredoIgual('135600.00', $primera['campos']['monto'], 'y los campos ya leídos');
    assertEnredo(strpos($primera['motivo'], 'trae 2 filas') !== false,
        'el motivo explica el enredo: ' . $primera['motivo']);

    // ── La línea suelta sigue su camino de siempre ──
    $sueltas = $bandeja->pendientes($sociedadId);
    assertEnredoIgual(1, count($sueltas), 'la línea ilegible no se mezcla con el enredo');
    assertEnredoIgual('', (string) $sueltas[0]['grupo'], 'una línea suelta no tiene grupo');

    // Pero el conteo total las cuenta a todas: son tres cosas esperando.
    assertEnredoIgual(3, $bandeja->contarPendientes($sociedadId),
        'el contador de la pantalla cuenta el enredo también');

    // ── Resolver el enredo lo saca de en medio ──
    // Se resuelve por el modelo, que es lo que hace el controlador: una entra
    // y la otra se descarta.
    $bandeja->marcarDescartada($enredos[$grupo][1]['id'], 'Repetida en el reporte.');
    $bandeja->marcarIncluida($enredos[$grupo][0]['id'], $enredos[$grupo][0]['campos'], 0);

    assertEnredoIgual(0, $bandeja->contarEnredos($sociedadId),
        'resuelto el enredo, el listado se puede volver a abrir');
    assertEnredoIgual([], $bandeja->enredosPendientes($sociedadId), 'y ya no aparece en la bandeja');
    assertEnredoIgual(1, count($bandeja->pendientes($sociedadId)),
        'la línea suelta sigue esperando: no se resolvió sola');

    // ── Un grupo que queda con una sola línea deja de ser un enredo ──
    //
    // Pasa si algo falla a mitad de la resolución. Si esa línea no saliera ni
    // entre los enredos —que piden dos para poder elegir— ni entre las sueltas,
    // quedaría invisible y con el listado cerrado por algo que nadie ve.
    $limpiar();
    $bandeja->registrarPendientes($sociedadId, $r['revision']);
    $gemelas = $bandeja->enredosPendientes($sociedadId);
    $clave = array_key_first($gemelas);
    $bandeja->marcarIncluida($gemelas[$clave][0]['id'], $gemelas[$clave][0]['campos'], 0);

    assertEnredoIgual(0, $bandeja->contarEnredos($sociedadId),
        'con una sola línea no hay entre qué elegir: ya no traba el listado');
    assertEnredoIgual([], $bandeja->enredosPendientes($sociedadId), 'y no se muestra como enredo');

    $sueltasAhora = array_column($bandeja->pendientes($sociedadId), 'id');
    assertEnredo(in_array((int) $gemelas[$clave][1]['id'], array_map('intval', $sueltasAhora), true),
        'la que quedó sola pasa a la lista de líneas sueltas, no desaparece');

    // ── El mismo archivo subido otra vez no duplica el enredo ──
    // (la gemela ya resuelta no vuelve, y la que quedó pendiente tampoco se
    //  copia: la bandeja no repregunta lo que ya está preguntando)
    $bandeja->registrarPendientes($sociedadId, $r['revision']);
    assertEnredoIgual(1, count($bandeja->pendientes($sociedadId)),
        'volver a subir el archivo no llena la bandeja de copias');

    // ── La otra salida: arreglarlo en el ERP y volver a exportar ──
    //
    // Si el reporte nuevo ya no trae esas filas, el enredo se retira solo. Sin
    // esto, corregir el origen no destrabaría nada: las filas viejas seguirían
    // pendientes y seguirían cerrando el listado.
    $limpiar();
    $bandeja->registrarPendientes($sociedadId, $r['revision']);
    assertEnredoIgual(1, $bandeja->contarEnredos($sociedadId), 'el enredo vuelve a estar ahí');

    // Una carga que no trae ninguna de esas firmas: el ERP ya las numeró.
    $retirados = $bandeja->retirarEnredosAusentes($sociedadId, ['firma-de-otra-cosa']);
    assertEnredoIgual(2, $retirados, 'se retiran las dos filas del enredo');
    assertEnredoIgual(0, $bandeja->contarEnredos($sociedadId), 'y el listado se destraba');

    // Pero la línea suelta no se toca: es un pendiente, no un bloqueo, y
    // borrarla porque el reporte cambió sería perder la pregunta sin contestar.
    assertEnredoIgual(1, count($bandeja->pendientes($sociedadId)),
        'una línea ilegible sobrevive a la limpieza de enredos');

    // Y un enredo que SÍ vuelve a venir se queda esperando.
    $limpiar();
    $bandeja->registrarPendientes($sociedadId, $r['revision']);
    $presentes = array_column($r['revision'], 'firma');
    assertEnredoIgual(0, $bandeja->retirarEnredosAusentes($sociedadId, $presentes),
        'si el reporte sigue trayendo las filas, el enredo sigue en pie');
    assertEnredoIgual(1, $bandeja->contarEnredos($sociedadId), 'y el listado sigue cerrado');

    echo "OK: FacturasErpEnredoBandeja\n";
} finally {
    $limpiar();
}
