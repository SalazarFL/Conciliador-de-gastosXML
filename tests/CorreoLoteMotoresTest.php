<?php
/**
 * Varios motores trabajan el mismo lote de correo, y nunca más de los que hay.
 *
 * Un solo motor por máquina hacía que un lote grande pasara casi todo el
 * tiempo esperando al servidor de correo: el lote #31, 5.103 correos, tardó
 * tres horas. Ahora trabajan hasta CorreoLote::motores() a la vez, cada uno con
 * su lugar (un lock de archivo) y su conexión. Esto fija:
 *
 *   - cuántos: lo que diga config.json, entre 1 y MOTORES_MAX; 4 si no dice;
 *   - que cada motor toma un lugar distinto y que, llenos, el siguiente rebota
 *     —también desde otro proceso, que es lo que importa en Windows—;
 *   - que "hay un motor en marcha" lo ve cualquiera, aunque ocupe el último
 *     lugar;
 *   - que de los motores que terminan a la vez, solo el que completó el lote
 *     hace el cruce contra los pagos (cerrarTanda devuelve true una vez).
 *
 * Los locks van a una carpeta temporal: la prueba no toca los de verdad.
 */

require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Varios motores por lote');

$carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xc_motores_' . getmypid();
@mkdir($carpeta, 0777, true);
CorreoLote::fijarCarpetaMotores($carpeta);

$configurar = function ($motores) use ($carpeta) {
    $archivo = $carpeta . DIRECTORY_SEPARATOR . 'config.json';
    if ($motores === null) {
        if (is_file($archivo)) {
            unlink($archivo);
        }
        return;
    }
    file_put_contents($archivo, json_encode(['auto_sync' => ['lotes_motores' => $motores]]));
};

Prueba::caso('cuántos motores: 4 si nadie dice nada, y lo que diga config.json dentro del tope', function () use ($configurar) {
    $configurar(null);
    igual(CorreoLote::MOTORES_POR_DEFECTO, CorreoLote::motores(), 'sin configuración');
    $configurar(2);
    igual(2, CorreoLote::motores(), 'lo que pide la configuración');
    $configurar(0);
    igual(1, CorreoLote::motores(), 'nunca menos de uno: un lote sin motores no avanza');
    $configurar(99);
    igual(CorreoLote::MOTORES_MAX, CorreoLote::motores(), 'nunca más del tope de conexiones');
    $configurar(null);
});

Prueba::caso('cada motor toma un lugar distinto, y lleno el cupo el siguiente rebota', function () use ($configurar) {
    $configurar(3);
    verificar(!CorreoLote::hayMotorEnMarcha(), 'al empezar no hay nadie');
    $tomados = [CorreoLote::adquirirLock(), CorreoLote::adquirirLock(), CorreoLote::adquirirLock()];
    verificar(!in_array(null, $tomados, true), 'los tres lugares se toman');
    verificar(CorreoLote::adquirirLock() === null, 'el cuarto rebota: ya hay tres motores');
    verificar(CorreoLote::hayMotorEnMarcha(), 'y se ve que hay motores trabajando');
    CorreoLote::liberarLock($tomados[1]);
    $otro = CorreoLote::adquirirLock();
    verificar($otro !== null, 'al soltarse uno, otro puede entrar');
    CorreoLote::liberarLock($otro);
    CorreoLote::liberarLock($tomados[0]);
    CorreoLote::liberarLock($tomados[2]);
    verificar(!CorreoLote::hayMotorEnMarcha(), 'soltados todos, no queda nadie');
    $configurar(null);
});

// ── Con procesos de verdad: los locks son de archivo, y en Windows lo que
//    cuenta es que se vean entre procesos distintos. ──────────────────────
$ayudante = tempnam(sys_get_temp_dir(), 'mot') . '.php';
file_put_contents($ayudante, <<<'PHP'
<?php
require_once $argv[1] . '/tests/soporte/CorreoSoporte.php';
CorreoLote::fijarCarpetaMotores($argv[2]);
$lock = CorreoLote::adquirirLock((int) $argv[3]);
if ($lock === null) {
    echo "RECHAZADO\n";
    exit(1);
}
echo "TOMADO\n";
usleep((int) ((float) $argv[4] * 1000000));
CorreoLote::liberarLock($lock);
PHP);

Prueba::caso('otro proceso que ocupa el ÚLTIMO lugar se ve, y ese lugar no se puede tomar', function () use ($ayudante, $carpeta, $configurar) {
    $configurar(4);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ayudante) . ' '
        . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg($carpeta) . ' 3 1.5';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    $veredicto = trim((string) fgets($tuberias[1]));
    try {
        igual('TOMADO', $veredicto, 'el otro proceso tomó el lugar 3');
        verificar(CorreoLote::hayMotorEnMarcha(), 'y desde acá se ve que hay un motor en marcha');
        verificar(CorreoLote::adquirirLock(3) === null, 'su lugar no se puede tomar desde otro proceso');
        $libres = [];
        while (($l = CorreoLote::adquirirLock()) !== null) {
            $libres[] = $l;
        }
        igual(3, count($libres), 'quedan exactamente los otros tres');
        foreach ($libres as $l) {
            CorreoLote::liberarLock($l);
        }
    } finally {
        stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        proc_close($p);
        $configurar(null);
    }
    verificar(!CorreoLote::hayMotorEnMarcha(), 'terminado el otro proceso, su lugar queda libre');
});

Prueba::caso('de los motores que terminan a la vez, solo uno completa el lote', function () {
    $pdo = Soporte::$pdo;
    $pdo->respuestas["estado IN ('pendiente','procesando')"] = [['c' => 0]];
    $veces = 0;
    // La base cambia el estado una sola vez: el segundo UPDATE ya no encuentra
    // el lote en 'ejecutando'.
    $pdo->afectadas["SET estado = 'completado'"] = function () use (&$veces) {
        return ++$veces === 1 ? 1 : 0;
    };
    $lotes = new CorreoLote();
    $primero = $lotes->cerrarTanda(50);
    $segundo = $lotes->cerrarTanda(50);
    verificar($primero === true, 'el primero que cierra lo completa');
    verificar($segundo === false, 'el segundo lo ve completo pero no fue él: no repite el cruce contra los pagos');
});

Prueba::caso('con correos todavía en manos de otro motor, cerrar la tanda no completa nada', function () {
    $pdo = Soporte::$pdo;
    $pdo->respuestas["estado IN ('pendiente','procesando')"] = [['c' => 3]];
    verificar((new CorreoLote())->cerrarTanda(50) === false, 'quedan tres procesándose en otro motor');
    verificar(!$pdo->de("SET estado = 'completado'"), 'y ni se intenta marcarlo completado');
});

@unlink($ayudante);
foreach (glob($carpeta . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($carpeta);
CorreoLote::fijarCarpetaMotores(null);

Prueba::terminar();
