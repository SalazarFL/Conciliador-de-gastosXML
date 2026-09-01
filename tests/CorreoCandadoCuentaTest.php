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

// Un ayudante que toma un candado, avisa, y lo retiene un rato.
$ayudante = tempnam(sys_get_temp_dir(), 'cand') . '.php';
file_put_contents($ayudante, <<<'PHP'
<?php
require_once $argv[1] . '/app/helpers/CorreoSync.php';
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
$correr = function ($modo, $cuenta, $reten) use ($php, $ayudante, $raiz) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($ayudante) . ' '
         . escapeshellarg($raiz) . ' ' . escapeshellarg($modo) . ' '
         . (int) $cuenta . ' ' . (float) $reten;
    return $cmd;
};

/** Arranca uno de fondo y devuelve su handle. */
$fondo = function ($cmd) {
    $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $descriptores, $tuberias);
    return [$p, $tuberias];
};
$esperar = function ($h) {
    [$p, $tuberias] = $h;
    $salida = stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    proc_close($p);
    return trim($salida);
};

// ── Dos procesos, el MISMO buzón: el segundo tiene que rebotar ─────────────

$h = $fondo($correr('cuenta', 7001, 1.5));
usleep(400000);
$segundo = trim((string) shell_exec($correr('cuenta', 7001, 0)));
assertCandado($segundo === 'RECHAZADO',
    'un segundo proceso NO puede reservar un buzón que ya tiene otro (dio: ' . $segundo . ')');
assertCandado($esperar($h) === 'TOMADO', 'el primero sí lo tenía');

// ── Dos procesos, buzones DISTINTOS: los dos tienen que entrar ─────────────
//
// Sin esto no hay paralelismo: sería el candado global de antes con otro nombre.

$h = $fondo($correr('cuenta', 7002, 1.5));
usleep(400000);
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

$h = $fondo($correr('mant', 0, 1.5));
usleep(400000);
$durante = trim((string) shell_exec($correr('cuenta', 7005, 0)));
assertCandado($durante === 'RECHAZADO',
    'con mantenimiento en curso ningún buzón se sincroniza (dio: ' . $durante . ')');
$esperar($h);

// ── Y al revés: un buzón vivo mantiene fuera al mantenimiento ─────────────

$h = $fondo($correr('cuenta', 7006, 1.5));
usleep(400000);
$mant = trim((string) shell_exec($correr('mant', 0, 0)));
assertCandado($mant === 'RECHAZADO',
    'el mantenimiento espera a que no quede ninguna sincronización (dio: ' . $mant . ')');
$esperar($h);

// Limpieza de los candados de prueba.
@unlink($ayudante);
foreach ([7001, 7002, 7003, 7004, 7005, 7006] as $id) {
    @unlink(CorreoSync::rutaLockCuenta($id));
}

echo "OK: Candado por buzón y exclusión del mantenimiento\n";
