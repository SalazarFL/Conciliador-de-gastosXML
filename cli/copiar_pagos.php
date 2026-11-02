<?php
/**
 * Llena las carpetas de pago en SharePoint, ya.
 *
 * Lo lanza en segundo plano la pantalla de Pagos al cargar o cambiar un pago
 * (ver ProcesoDeFondo): sin esto, las copias esperaban a la tarea programada,
 * que corre cada cinco minutos y hacía cien facturas por vuelta. Un pago de 308
 * facturas tardaba veinticinco minutos en quedar listo para mandar.
 *
 * Hace solo eso —nada del resto de la vuelta del reconciliador— y con el mismo
 * candado de copias que la vuelta: si una ya está copiando, la otra no se mete.
 * Que se lance dos veces seguidas no hace daño: la segunda encuentra el
 * candado tomado, o nada que copiar.
 *
 * Uso manual:  php cli\copiar_pagos.php [tope_segundos]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo corre por línea de comandos.\n");
}

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
require_once $root . '/app/core/Model.php';
require_once $root . '/app/helpers/ReconciliadorGraph.php';

$tope = isset($argv[1]) ? max(30, min(3600, (int) $argv[1])) : ReconciliadorGraph::SEGUNDOS_COPIAS_APARTE;

$log = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'copiar_pagos.log';
$anotar = function ($texto) use ($log) {
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $texto . "\n";
    @file_put_contents($log, $linea, FILE_APPEND | LOCK_EX);
    echo $linea;
};

if (!ReconciliadorGraph::hayQueCorrer()) {
    $anotar('SharePoint no está configurado en esta computadora: nada que copiar.');
    exit(0);
}

$inicio = microtime(true);
try {
    $r = (new ReconciliadorGraph(AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO)))->copiarPagos($tope);
} catch (Throwable $e) {
    $anotar('No se pudieron hacer las copias de pago: ' . $e->getMessage());
    exit(1);
}

if (!empty($r['copias_en_otro_proceso'])) {
    $anotar('Otro proceso ya está copiando a las carpetas de pago.');
    exit(0);
}
$anotar(sprintf(
    '%d copiadas, %d ya estaban, %d sin par completo, %d para después, %d errores; %.1f s.',
    $r['copias_pago'], $r['copias_pago_vigentes'], $r['sin_par_completo'],
    $r['copias_pago_para_despues'], $r['errores_copia_pago'], microtime(true) - $inicio
));
foreach ($r['operaciones'] as $operacion) {
    if (isset($operacion['error'])) {
        $anotar('  documento ' . $operacion['documento_id'] . ': ' . $operacion['error']);
    }
}
exit($r['errores_copia_pago'] > 0 ? 1 : 0);
