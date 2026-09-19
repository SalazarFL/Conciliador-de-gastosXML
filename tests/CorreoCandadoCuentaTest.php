<?php
/**
 * Que dos procesos nunca toquen el mismo buzón, y que sí puedan tocar buzones
 * distintos a la vez.
 *
 * Esta es la prueba que sostiene todo el paralelismo. Hablarle al servidor de
 * correo es esperar —105 ms por viaje—, y con 42 buzones esa espera en fila no
 * cabe en ninguna ventana razonable; la salida es correr varias copias del
 * sincronizador. Lo que no puede pasar nunca es que dos copias escriban el
 * índice del mismo buzón: ahí se duplican encabezados y se pisan reindexados.
 *
 * El candado ES el reparto —no hay coordinador— así que si esta prueba se cae,
 * el reparto no existe.
 *
 * Se prueba con procesos de verdad y no con dos manijas dentro del mismo
 * proceso: flock se comporta distinto en cada caso, y lo que hay que garantizar
 * es justamente lo de entre procesos.
 *
 * Dos cosas se hacen aparte de lo obvio, y las dos por lo mismo —que la prueba
 * mida el candado y no la máquina donde corre—:
 *
 *   · Candados propios, en un directorio temporal. Con los de producción esta
 *     prueba fallaba sola: la tarea programada sincroniza 290 s de cada 300,
 *     y mientras haya una sincronización viva el candado de mantenimiento no
 *     se puede tomar, así que la prueba del mantenimiento daba TOMADO donde
 *     esperaba RECHAZADO. No era azar: con cualquier sincronización corriendo
 *     fallaba siempre.
 *   · Se espera a que el proceso de fondo AVISE que ya tiene el candado, en
 *     vez de dormir un rato y suponer que le alcanzó. Arrancar PHP tarda unos
 *     80 ms acá, pero en una máquina cargada puede tardar más que cualquier
 *     siesta que uno elija, y entonces la prueba mediría el arranque.
 *
 * Uso: php tests/CorreoCandadoCuentaTest.php
 */

require_once __DIR__ . '/../app/helpers/CorreoSync.php';

function assertCandado($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$raiz = dirname(__DIR__);

// Los candados de esta prueba, lejos de los que use el servidor.
$dirCandados = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xc_candados_' . getmypid();
@mkdir($dirCandados, 0777, true);

// Un ayudante que toma un candado, avisa, y lo retiene un rato.
$ayudante = tempnam(sys_get_temp_dir(), 'cand') . '.php';
file_put_contents($ayudante, <<<'PHP'
<?php
require_once $argv[1] . '/app/helpers/CorreoSync.php';
CorreoSync::usarDirectorioCandados($argv[5]);
$modo = $argv[2];
$cuenta = (int) $argv[3];
$reten = (float) $argv[4];
$lock = $modo === 'mant'
    ? CorreoSync::adquirirLockMantenimiento()
    : CorreoSync::adquirirLock($cuenta);
if ($lock === null) {
    echo "RECHAZADO\n";
    exit(1);
}
echo "TOMADO\n";
if ($reten > 0) { usleep((int) ($reten * 1000000)); }
CorreoSync::liberarLock($lock);
exit(0);
PHP);

$php = PHP_BINARY;
$correr = function ($modo, $cuenta, $reten) use ($php, $ayudante, $raiz, $dirCandados) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($ayudante) . ' '
         . escapeshellarg($raiz) . ' ' . escapeshellarg($modo) . ' '
         . (int) $cuenta . ' ' . (float) $reten . ' '
         . escapeshellarg($dirCandados);
    return $cmd;
};

/**
 * Arranca uno de fondo y no vuelve hasta que dijo qué le pasó con el candado.
 * Devuelve el handle; el veredicto queda en la posición 2.
 */
$fondo = function ($cmd) {
    $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $descriptores, $tuberias);
    $veredicto = trim((string) fgets($tuberias[1]));
    return [$p, $tuberias, $veredicto];
};
$esperar = function ($h) {
    [$p, $tuberias] = $h;
    stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    proc_close($p);
    return $h[2];
};

// ── Dos procesos, el MISMO buzón: el segundo tiene que rebotar ─────────────

$h = $fondo($correr('cuenta', 7001, 1.0));
assertCandado($h[2] === 'TOMADO', 'el primero sí lo tenía (dio: ' . $h[2] . ')');
$segundo = trim((string) shell_exec($correr('cuenta', 7001, 0)));
assertCandado($segundo === 'RECHAZADO',
    'un segundo proceso NO puede reservar un buzón que ya tiene otro (dio: ' . $segundo . ')');
$esperar($h);

// ── Dos procesos, buzones DISTINTOS: los dos tienen que entrar ─────────────
//
// Sin esto no hay paralelismo: sería el candado global de antes con otro nombre.

$h = $fondo($correr('cuenta', 7002, 1.0));
assertCandado($h[2] === 'TOMADO', 'el primer buzón se reservó (dio: ' . $h[2] . ')');
$otro = trim((string) shell_exec($correr('cuenta', 7003, 0)));
assertCandado($otro === 'TOMADO',
    'dos buzones distintos se sincronizan a la vez (dio: ' . $otro . ')');
$esperar($h);

// ── El buzón se libera al terminar ────────────────────────────────────────

$primero = trim((string) shell_exec($correr('cuenta', 7004, 0)));
$segundo = trim((string) shell_exec($correr('cuenta', 7004, 0)));
assertCandado($primero === 'TOMADO' && $segundo === 'TOMADO',
    'el buzón queda libre para la corrida siguiente');

// ── El mantenimiento excluye a todos los buzones ──────────────────────────
//
// Adelgazar el índice o migrarlo reescribe la tabla entera: ahí no puede haber
// ninguna sincronización viva, ni siquiera de otro buzón.

$h = $fondo($correr('mant', 0, 1.0));
assertCandado($h[2] === 'TOMADO',
    'el mantenimiento arranca cuando no hay ninguna sincronización (dio: ' . $h[2] . ')');
$durante = trim((string) shell_exec($correr('cuenta', 7005, 0)));
assertCandado($durante === 'RECHAZADO',
    'con mantenimiento en curso ningún buzón se sincroniza (dio: ' . $durante . ')');
$esperar($h);

// ── Y al revés: un buzón vivo mantiene fuera al mantenimiento ─────────────

$h = $fondo($correr('cuenta', 7006, 1.0));
assertCandado($h[2] === 'TOMADO', 'el buzón se reservó (dio: ' . $h[2] . ')');
$mant = trim((string) shell_exec($correr('mant', 0, 0)));
assertCandado($mant === 'RECHAZADO',
    'el mantenimiento espera a que no quede ninguna sincronización (dio: ' . $mant . ')');
$esperar($h);

// Limpieza: los candados de la prueba y el ayudante.
@unlink($ayudante);
foreach ((array) glob($dirCandados . DIRECTORY_SEPARATOR . '*.lock') as $lock) {
    @unlink($lock);
}
@rmdir($dirCandados);

echo "OK: Candado por buzón y exclusión del mantenimiento\n";
