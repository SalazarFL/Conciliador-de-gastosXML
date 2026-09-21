<?php
/**
 * Lo que la base guarda cifrado, y lo que ya no acepta.
 *
 * Esta clase no tenía prueba y guarda las contraseñas de los buzones, que es
 * la credencial más sensible del sistema: si se rompe, o los buzones dejan de
 * abrir o —peor, y en silencio— las contraseñas vuelven a quedar legibles.
 *
 * Lo que se comprueba, en orden de lo que dolería que se rompiera:
 *
 *  1. Que NO se lee el base64 de antes. Durante un tiempo sí se leía, para que
 *     las cuentas anteriores al cifrado siguieran andando; el precio era que
 *     el camino de lectura de todos los días aceptaba para siempre datos sin
 *     cifrar, y una fila así se lee de la tabla o de un respaldo sin ninguna
 *     clave. Si alguien devuelve esa rampa, esta prueba lo dice.
 *  2. Que lo cifrado se recupera igual, con acentos y bytes crudos.
 *  3. Que dos cifrados del mismo texto son distintos (IV aleatorio): si no,
 *     se vería desde fuera qué cuentas comparten contraseña.
 *  4. Que un texto manipulado no se acepta (el tag de GCM).
 *  5. Que sin clave, cifrar falla con todas las letras en vez de guardar algo
 *     que nadie va a poder leer después.
 *
 * No necesita base de datos.
 *
 * Uso: php tests/SecretoTest.php
 */
require_once __DIR__ . '/../app/helpers/Secreto.php';

$fallos = 0;

function assertSecreto($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

// Una clave propia: la prueba no depende de lo que tenga esta computadora en
// local.php, y así corre igual en una recién instalada.
Secreto::usarClave('clave-de-prueba-' . str_repeat('a', 40));

// ── 1. El base64 de antes ya no se lee ────────────────────────────────────

$viejo = base64_encode('contraseña-del-buzon');
assertSecreto(Secreto::descifrar($viejo) === '',
    'una contraseña en base64 (el formato viejo) NO se lee: devolverla dejaría el '
    . 'agujero abierto para siempre y sin síntomas');
assertSecreto(!Secreto::estaCifrado($viejo),
    'y no se confunde con algo cifrado');

// Texto suelto, ni base64 ni cifrado: tampoco se inventa nada.
assertSecreto(Secreto::descifrar('hola') === '', 'un texto cualquiera no se lee como contraseña');
assertSecreto(Secreto::descifrar('') === '', 'lo vacío sigue vacío');

// ── 2. Ida y vuelta ───────────────────────────────────────────────────────

foreach ([
    'sencilla'          => 'abc123',
    'con acentos y eñe' => 'Contraseña Muy Señora Mía ¡2026!',
    'con bytes crudos'  => "\x00\x01\xff binario \n\t",
    'larga'             => str_repeat('x', 500),
] as $caso => $texto) {
    $cifrado = Secreto::cifrar($texto);
    assertSecreto(Secreto::estaCifrado($cifrado), "lo cifrado lleva el prefijo enc:v1: ({$caso})");
    assertSecreto(strpos($cifrado, $texto) === false,
        "el texto original no aparece dentro de lo guardado ({$caso})");
    assertSecreto(Secreto::descifrar($cifrado) === $texto, "vuelve igual ({$caso})");
}

// ── 3. Dos veces lo mismo no da lo mismo ──────────────────────────────────

$a = Secreto::cifrar('la misma contraseña');
$b = Secreto::cifrar('la misma contraseña');
assertSecreto($a !== $b,
    'dos cifrados del mismo texto son distintos: si no, se vería desde la tabla '
    . 'qué buzones comparten contraseña');
assertSecreto(Secreto::descifrar($a) === Secreto::descifrar($b), 'y los dos se leen igual');

// ── 4. Manipular lo guardado no cuela ─────────────────────────────────────

$cifrado = Secreto::cifrar('intacta');
$crudo = base64_decode(substr($cifrado, strlen('enc:v1:')), true);
$crudo[strlen($crudo) - 1] = ($crudo[strlen($crudo) - 1] === 'A') ? 'B' : 'A';
assertSecreto(Secreto::descifrar('enc:v1:' . base64_encode($crudo)) === '',
    'un texto cifrado al que le cambiaron un byte no se acepta (el tag de GCM)');
assertSecreto(Secreto::descifrar('enc:v1:no-es-base64-valido!!') === '',
    'un prefijo con basura detrás tampoco');
assertSecreto(Secreto::descifrar('enc:v1:' . base64_encode('corto')) === '',
    'ni algo más corto que el IV y el tag juntos');

// ── 5. Con otra clave no se abre ──────────────────────────────────────────

$conLaPrimera = Secreto::cifrar('secreto de la máquina A');
Secreto::usarClave('otra-clave-distinta-' . str_repeat('b', 40));
assertSecreto(Secreto::descifrar($conLaPrimera) === '',
    'lo cifrado con otra clave no se abre: es lo que pasa cuando una computadora '
    . 'no tiene el mismo clave_secretos que las demás');

// ── 6. Sin clave, cifrar avisa en vez de guardar cualquier cosa ───────────

Secreto::usarClave('');
assertSecreto(!Secreto::hayClave(), 'sin clave configurada, hayClave() lo dice');

$avisó = false;
try {
    Secreto::cifrar('algo');
} catch (RuntimeException $e) {
    $avisó = strpos($e->getMessage(), 'clave_secretos') !== false;
}
assertSecreto($avisó,
    'sin clave, cifrar falla nombrando clave_secretos: guardar algo ilegible sería peor');

// Y descifrar algo cifrado tampoco se queda callado.
$avisóAlLeer = false;
try {
    Secreto::descifrar($conLaPrimera);
} catch (RuntimeException $e) {
    $avisóAlLeer = strpos($e->getMessage(), 'clave_secretos') !== false;
}
assertSecreto($avisóAlLeer,
    'y al leer algo cifrado sin tener la clave, dice qué copiar y de dónde');

// Lo que no está cifrado sigue dando vacío aunque no haya clave: no hay nada
// que leer, y eso no es un problema de configuración que haya que gritar.
assertSecreto(Secreto::descifrar(base64_encode('vieja')) === '',
    'sin clave, una contraseña del formato viejo sigue sin leerse');

if ($fallos > 0) {
    fwrite(STDERR, "Secreto: {$fallos} fallas\n");
    exit(1);
}

echo "OK: las contraseñas se guardan cifradas y el base64 de antes ya no se acepta\n";
exit(0);
