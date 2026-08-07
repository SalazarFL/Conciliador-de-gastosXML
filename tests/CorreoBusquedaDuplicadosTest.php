<?php
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/CorreoController.php';

function assertCorreoBusqueda($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ref = new ReflectionClass(CorreoController::class);
$controller = $ref->newInstanceWithoutConstructor();
$deduplicar = $ref->getMethod('deduplicarCorreosBusqueda');
$deduplicar->setAccessible(true);

$base = [
    'carpeta' => 'INBOX',
    'timestamp' => 1782505269,
    'remitente' => 'caferiobrus1990@gmail.com',
    'asunto' => 'Factura Electrónica No. 00100004010000038489',
];
$resultado = $deduplicar->invoke($controller, [
    ['uid' => 213726] + $base,
    ['uid' => 204711] + $base,
    ['uid' => 151639] + $base,
    ['uid' => 300001, 'carpeta' => 'Archivo/2026'] + $base,
    ['uid' => 213718, 'timestamp' => 1782497959] + $base,
]);

assertCorreoBusqueda(count($resultado) === 3, 'elimina generaciones repetidas solo dentro de la misma carpeta');
assertCorreoBusqueda((int) $resultado[0]['uid'] === 213726, 'conserva la primera fila, que es la generación más reciente');
assertCorreoBusqueda((string) $resultado[1]['carpeta'] === 'Archivo/2026', 'no mezcla copias ubicadas en carpetas distintas');
echo "OK: Búsqueda de correo elimina generaciones duplicadas\n";
