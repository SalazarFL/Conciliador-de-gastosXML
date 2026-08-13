<?php
/**
 * Genera un respaldo de la base de datos en la carpeta compartida.
 *
 * Uso: php cli/respaldar_base.php [--motivo=manual|automatico]
 *
 * Corre en la computadora que HOSPEDA la base —ahí el volcado es local y
 * tarda menos de un minuto— y deja el .sql.gz en _TRABAJO/RESPALDOS, que
 * SharePoint sincroniza a las demás máquinas. Así una computadora que no
 * alcanza el servidor puede seguir teniendo la base al día.
 *
 * Lo llaman tres cosas: el botón de Administración → Diagnóstico (que lo
 * lanza en segundo plano para no colgar el navegador), la tarea programada
 * nocturna, y una persona a mano cuando hace falta.
 *
 * Devuelve 0 si el respaldo quedó hecho y 1 si falló.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/RespaldoBase.php';

$motivo = 'manual';
foreach ($argv as $arg) {
    if (strpos($arg, '--motivo=') === 0) {
        $motivo = substr($arg, 9) === 'automatico' ? 'automatico' : 'manual';
    }
}

$estado = RespaldoBase::ejecutar($motivo);

if (($estado['estado'] ?? '') === 'ok') {
    echo "\n", $estado['mensaje'], "\n";
    echo '  Carpeta : ', $estado['carpeta'], "\n";
    echo '  Origen  : ', $estado['origen'], "\n";
    echo '  Tablas  : ', $estado['tablas'], "\n";
    echo '  Tamaño  : ', RespaldoBase::humano($estado['bytes']),
         ' comprimido, ', RespaldoBase::humano($estado['bytes_crudo']), " sin comprimir\n";
    echo '  Duración: ', $estado['segundos'], " s\n";
    if (!empty($estado['borrados'])) {
        echo '  Se borraron ', $estado['borrados'], " respaldo(s) viejo(s).\n";
    }
    echo "\nPara cargarlo en otra computadora:\n";
    echo "  .\\scripts\\copiar-base.ps1 -Desde ultimo\n\n";
    exit(0);
}

echo "\nNo se pudo generar el respaldo:\n  ", ($estado['mensaje'] ?? 'error desconocido'), "\n\n";
exit(1);
