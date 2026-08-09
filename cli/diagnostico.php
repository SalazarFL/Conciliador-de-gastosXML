<?php
/**
 * Revisa la instalación de esta computadora y dice qué hacer con lo que falle.
 *
 * Uso: php cli/diagnostico.php [--json]
 *
 * Pensado para el modelo local: cuando alguien reporta que algo no le
 * funciona, esto se corre en SU computadora y el resultado se puede copiar y
 * pegar. Casi siempre la respuesta ya viene escrita en la salida.
 *
 * La misma revisión está en la aplicación, en Inicio → Diagnóstico, para quien
 * prefiera mandar una captura de pantalla.
 *
 * Devuelve 0 si todo está bien o solo hay avisos, y 1 si hay algún error.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/Diagnostico.php';

$informe = (new Diagnostico())->ejecutar();

if (in_array('--json', $argv, true)) {
    echo json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($informe['estado'] === 'error' ? 1 : 0);
}

$simbolos = ['ok' => '  OK  ', 'aviso' => ' AVISO', 'error' => ' ERROR'];

echo "\nXMLConcilia · diagnóstico de instalación\n";
echo "Equipo: {$informe['equipo']}    {$informe['generado_en']}\n";
echo str_repeat('-', 72), "\n";

foreach ($informe['revisiones'] as $r) {
    printf("[%s]  %s\n", $simbolos[$r['estado']], $r['nombre']);
    echo '         ', $r['detalle'], "\n";
    if ($r['que_hacer'] !== '') {
        foreach (explode("\n", $r['que_hacer']) as $linea) {
            echo '         → ', $linea, "\n";
        }
    }
    echo "\n";
}

echo str_repeat('-', 72), "\n";
echo match ($informe['estado']) {
    'ok' => "Todo en orden.\n",
    'aviso' => "Funciona, pero hay cosas que conviene atender (ver AVISO).\n",
    default => "Hay errores que impiden trabajar (ver ERROR).\n",
};

exit($informe['estado'] === 'error' ? 1 : 0);
