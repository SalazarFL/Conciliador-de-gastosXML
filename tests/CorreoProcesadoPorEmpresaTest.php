<?php
/**
 * Un buzón compartido: cada empresa lleva su propia cuenta de lo trabajado.
 *
 * correo_cuenta_sociedades permite que un buzón sirva a varias empresas del
 * grupo, y de hecho así es: los tres buzones reciben facturas a nombre de más
 * de una. La marca de "este correo ya lo procesé" vivía por buzón, así que la
 * primera empresa que lo revisara se llevaba los mensajes por delante y la
 * segunda no los volvía a ver — aunque sus facturas estuvieran en esos mismos
 * correos.
 *
 * Ahora la marca es de cada empresa. Lo que sigue valiendo es que DENTRO de
 * una empresa el mismo correo no se trabaja dos veces, que es para lo que la
 * marca existe.
 *
 * Uso: php tests/CorreoProcesadoPorEmpresaTest.php
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Esquema.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/CorreoProcesado.php';

$fallos = 0;
function compruebaProcesado($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        echo 'FAIL: ' . $mensaje . PHP_EOL;
        $fallos++;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: CorreoProcesadoPorEmpresa (sin base de datos disponible)\n";
    exit(0);
}

$marcas = new CorreoProcesado();

// Dos empresas que no existen en los datos reales: la marca es solo un número
// y no hace falta darlas de alta para probar que no se pisan.
$empresaA = 970001;
$empresaB = 970002;
$clave = 'c99:1700000000:424242';

$limpiar = function () use ($pdo, $clave) {
    $pdo->prepare('DELETE FROM correo_procesados WHERE clave = ?')->execute([$clave]);
};

$limpiar();

try {
    $marcas->marcar($clave, $empresaA);

    $deA = $marcas->todas($empresaA);
    $deB = $marcas->todas($empresaB);

    compruebaProcesado(isset($deA[$clave]),
        'la empresa que lo trabajó lo tiene marcado');
    compruebaProcesado(!isset($deB[$clave]),
        'y la otra no: el mismo correo le queda por revisar, con sus propias facturas dentro');

    // Dentro de una empresa la marca sigue evitando el trabajo repetido.
    $marcas->marcar($clave, $empresaA);
    $cuantas = (int) $pdo->query(
        "SELECT COUNT(*) FROM correo_procesados WHERE clave = '{$clave}' AND sociedad_id = {$empresaA}"
    )->fetchColumn();
    compruebaProcesado($cuantas === 1,
        'marcarlo dos veces en la misma empresa no deja dos renglones');

    // Las dos pueden tenerlo marcado a la vez, cada una por su cuenta.
    $marcas->marcar($clave, $empresaB);
    compruebaProcesado(isset($marcas->todas($empresaB)[$clave]),
        'la segunda empresa también puede marcarlo cuando le toque');
    compruebaProcesado(isset($marcas->todas($empresaA)[$clave]),
        'sin que eso le quite la marca a la primera');

    // Y descartar de la bandeja en una no destapa el correo en la otra.
    $marcas->desmarcar([$clave], $empresaA);
    compruebaProcesado(!isset($marcas->todas($empresaA)[$clave]),
        'desmarcar devuelve el correo a la cola de esa empresa');
    compruebaProcesado(isset($marcas->todas($empresaB)[$clave]),
        'y deja en paz la de la otra');
} catch (Throwable $e) {
    $limpiar();
    echo 'FAIL: excepción inesperada: ', $e->getMessage(), PHP_EOL;
    exit(1);
}

$limpiar();

if ($fallos > 0) {
    echo PHP_EOL, $fallos, ' comprobaciones fallaron', PHP_EOL;
    exit(1);
}
echo 'OK: cada empresa lleva su propia cuenta de los correos ya trabajados', PHP_EOL;
