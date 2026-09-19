<?php
/**
 * Deja en la campana los recordatorios de Seguimiento que ya vencieron.
 *
 * Uso: php cli/recordatorios.php
 *
 * Es el mismo barrido que hace la campana al abrir una pantalla, pero desde el
 * Programador de tareas de Windows: con una entrada que lo corra cada 10–15
 * minutos, un recordatorio suena a su hora aunque nadie esté navegando, y las
 * cargas de página dejan de tener que hacerlo. No hace falta apagar el de la
 * campana: los dos toman el mismo candado en la base, así que nunca barren a
 * la vez ni avisan dos veces lo mismo.
 *
 * Sale con 0 si terminó (haya o no dejado avisos) y con 1 si no pudo.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

try {
    $dejados = (new Seguimiento())->generarRecordatorios();
    echo date('Y-m-d H:i:s') . " — recordatorios de seguimiento: {$dejados} aviso(s) dejado(s).\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — no se pudieron revisar los recordatorios: ' . $e->getMessage() . "\n");
    exit(1);
}
