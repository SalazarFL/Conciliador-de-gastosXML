<?php
/**
 * Envoltorio para SeleccionRangoTest.js.
 *
 * La selección por rango es JavaScript, así que su prueba también lo es. Pero
 * quien corre las pruebas —a mano y en scripts/verificar-php84.ps1— recoge
 * `tests/*Test.php`: un .js suelto no lo ejecutaría nadie y se pudriría en
 * silencio, que es peor que no tenerlo. Este archivo lo mete en la misma fila
 * que los demás.
 *
 * La aplicación corre en la computadora de cada persona y ahí puede no haber
 * Node: en ese caso se omite con SKIP en vez de fallar, porque no tener Node
 * no es un defecto del sistema. Donde sí lo hay —y en la certificación—, la
 * prueba corre entera.
 */

$raiz = dirname(__DIR__);
$prueba = __DIR__ . DIRECTORY_SEPARATOR . 'SeleccionRangoTest.js';

if (!is_file($prueba)) {
    fwrite(STDERR, "FAIL: falta tests/SeleccionRangoTest.js\n");
    exit(1);
}

/** Node, si esta máquina lo tiene. */
$node = null;
foreach (['node --version'] as $tanteo) {
    $salida = [];
    $codigo = 1;
    @exec($tanteo . ' 2>&1', $salida, $codigo);
    if ($codigo === 0) {
        $node = 'node';
        break;
    }
}

if ($node === null) {
    echo "SKIP: esta máquina no tiene Node; la selección por rango no se pudo probar.\n";
    exit(0);
}

$salida = [];
$codigo = 1;
@exec(
    'cd ' . escapeshellarg($raiz) . ' && ' . $node . ' ' . escapeshellarg($prueba) . ' 2>&1',
    $salida,
    $codigo
);

echo implode("\n", $salida) . "\n";
exit($codigo === 0 ? 0 : 1);
