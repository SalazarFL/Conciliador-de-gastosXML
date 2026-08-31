<?php
/**
 * Cuándo la campana dice que el índice del buzón se quedó atrás.
 *
 * El aviso existe porque mientras al índice le falten correos por completar,
 * buscar un número de factura no lo resuelve el índice: hay que ir hasta el
 * servidor de correo, y eso se siente como una búsqueda que no contesta.
 *
 * Lo que se comprueba es la línea fina entre avisar y ser ruido. Construir el
 * índice de un buzón grande son decenas de tandas con la cola llena: avisar
 * de eso sería avisar de que el sistema está funcionando. Lo que hay que
 * contar es la cola que dejó de avanzar sola.
 */
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/helpers/CorreoSync.php';

function assertAviso($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

/** Una tanda de sincronización tal como la devuelve CorreoSync::ejecutar(). */
$tanda = function ($pendientes, $completado) {
    return ['metadatos_pendientes' => $pendientes, 'completado' => $completado];
};

// ── Trabajando: no hay nada que contar ───────────────────────────────
assertAviso(CorreoSync::decisionRezago($tanda(12000, false)) === 'nada',
    'una tanda a medias con cola es la sincronización avanzando, no un problema');
assertAviso(CorreoSync::decisionRezago($tanda(3, false)) === 'nada',
    'quedar tres correos a media vuelta tampoco merece un aviso');

// ── La cola dejó de avanzar ──────────────────────────────────────────
// Dar la tanda por completada con correos en la cola solo pasa cuando no se
// resolvió ninguno y tampoco se acabó el tiempo: nadie la está bajando.
assertAviso(CorreoSync::decisionRezago($tanda(4500, true)) === 'avisar',
    'una tanda completada con cola es una cola parada: eso sí se avisa');
assertAviso(CorreoSync::decisionRezago($tanda(1, true)) === 'avisar',
    'un solo correo atascado también deja las búsquedas yendo al buzón');

// ── Al día: el aviso se retira solo ──────────────────────────────────
assertAviso(CorreoSync::decisionRezago($tanda(0, true)) === 'cerrar',
    'sin cola no hay aviso que sostener');
assertAviso(CorreoSync::decisionRezago($tanda(0, false)) === 'cerrar',
    'la cola vacía cierra el aviso aunque queden carpetas por recorrer: '
    . 'lo que hace lentas las búsquedas es la cola, no las carpetas');

// ── Una tanda sin esas claves no inventa un aviso ────────────────────
assertAviso(CorreoSync::decisionRezago([]) === 'cerrar',
    'sin datos de cola no se avisa de nada');

echo "OK: CorreoIndiceAviso\n";
