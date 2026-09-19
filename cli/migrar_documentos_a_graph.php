<?php
/**
 * Pasa los documentos de una empresa de la carpeta compartida a SharePoint.
 *
 *   php cli/migrar_documentos_a_graph.php --sociedad=N                 cómo va (lo mismo que --estado)
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --todo          inventario, subir, verificar y reescribir
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --inventario
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --subir [--limite=500]
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --verificar [--muestreo=20]
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --reescribir    y, si no falta nada, corta la empresa
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --reintentar    los fallidos vuelven a la fila
 *   php cli/migrar_documentos_a_graph.php --sociedad=N --revertir [--bajando]
 *
 * Con --dry-run cuenta lo que haría sin subir ni escribir nada.
 *
 * Cada paso se puede cortar (Ctrl+C, un corte de luz) y volver a correr: el
 * avance está en la tabla almacen_migracion, no en esta ventana. Tampoco
 * importa desde qué PC se retome, mientras tenga app/config/local.graph.php y
 * la carpeta compartida.
 *
 * Nunca borra nada del disco. Por eso --revertir es seguro.
 *
 * Orden recomendado: la empresa más chica primero, de noche, y una semana
 * mirándola antes de pasar la siguiente. Antes de cortar conviene procesar su
 * bandeja: lo que quede en tránsito termina donde empezó.
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
require_once $root . '/app/helpers/MigracionGraph.php';

$acciones = ['--estado', '--todo', '--inventario', '--subir', '--verificar', '--reescribir', '--reintentar', '--revertir'];
$banderas = ['--dry-run', '--bajando'];
$conValor = ['--sociedad=', '--limite=', '--muestreo='];

$opciones = ['sociedad' => 0, 'limite' => 0, 'muestreo' => 0];
$accion = '--estado';
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, $acciones, true)) {
        $accion = $arg;
        continue;
    }
    if (in_array($arg, $banderas, true)) {
        continue;
    }
    foreach ($conValor as $prefijo) {
        if (strpos($arg, $prefijo) === 0) {
            $opciones[substr($prefijo, 2, -1)] = (int) substr($arg, strlen($prefijo));
            continue 2;
        }
    }
    fwrite(STDERR, "Bandera desconocida: {$arg}\nVálidas: " . implode(' ', array_merge($acciones, $banderas))
        . " --sociedad=N --limite=N --muestreo=N\n");
    exit(2);
}
$dryRun = in_array('--dry-run', $argv, true);
$bajando = in_array('--bajando', $argv, true);

if ($opciones['sociedad'] <= 0) {
    fwrite(STDERR, "Falta --sociedad=N (el id de la empresa en Configuración → Empresas).\n");
    exit(2);
}
if (!AlmacenDocumentos::disponible(AlmacenEnGraph::TIPO)) {
    fwrite(STDERR, "Esta computadora no tiene app/config/local.graph.php: no puede hablar con SharePoint.\n");
    exit(2);
}
if (RutaDocumento::raiz() === '') {
    fwrite(STDERR, "Esta computadora no tiene configurada la carpeta compartida: no hay de dónde leer los documentos.\n");
    exit(2);
}

$imprimir = function ($titulo, array $datos) {
    echo '── ', $titulo, ' ', str_repeat('─', max(0, 60 - mb_strlen($titulo))), PHP_EOL;
    echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
};

try {
    $migracion = new MigracionGraph(
        $opciones['sociedad'],
        AlmacenDocumentos::tipo(AlmacenDocumentos::DISCO),
        AlmacenDocumentos::tipo(AlmacenEnGraph::TIPO),
        null,
        function ($paso, $n) {
            fwrite(STDERR, date('H:i:s') . " {$paso}: {$n}\n");
        }
    );

    $problemas = 0;
    switch ($accion) {
        case '--todo':
            $imprimir('Inventario', $migracion->inventario($dryRun));
            $subida = $migracion->subir($opciones['limite'], $dryRun);
            $imprimir('Subida', $subida);
            if ($subida['cortado'] !== '') {
                // Sin red no tiene sentido verificar ni reescribir: se retoma.
                $problemas++;
                break;
            }
            $imprimir('Verificación', $migracion->verificar(0, $opciones['muestreo'], $dryRun));
            $reescritura = $migracion->reescribir($dryRun);
            $imprimir('Reescritura', $reescritura);
            $problemas += $reescritura['empresa_cortada'] || $dryRun ? 0 : 1;
            break;
        case '--inventario':
            $imprimir('Inventario', $migracion->inventario($dryRun));
            break;
        case '--subir':
            $subida = $migracion->subir($opciones['limite'], $dryRun);
            $imprimir('Subida', $subida);
            $problemas += $subida['cortado'] !== '' ? 1 : 0;
            break;
        case '--verificar':
            $imprimir('Verificación', $migracion->verificar($opciones['limite'], $opciones['muestreo'], $dryRun));
            break;
        case '--reescribir':
            $reescritura = $migracion->reescribir($dryRun);
            $imprimir('Reescritura', $reescritura);
            $problemas += $reescritura['empresa_cortada'] || $dryRun ? 0 : 1;
            break;
        case '--reintentar':
            $imprimir('Reintentar', ['vuelven_a_la_fila' => $migracion->reintentar()]);
            break;
        case '--revertir':
            $reversion = $migracion->revertir($bajando, $dryRun);
            $imprimir('Reversión', $reversion);
            $problemas += $reversion['rehusado'] !== '' ? 1 : 0;
            break;
    }
    $imprimir('Estado', $migracion->estado());
    exit($problemas > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'La migración se detuvo: ' . $e->getMessage() . PHP_EOL
        . 'Lo hecho hasta aquí quedó anotado: se retoma corriendo lo mismo otra vez.' . PHP_EOL);
    exit(2);
}
