<?php
/**
 * Vuelve a bajar del correo los documentos que se quedaron sin archivo.
 *
 * Uso:
 *   php cli/recuperar_documentos.php                 lista qué se puede reponer
 *   php cli/recuperar_documentos.php --aplicar       lo repone
 *   php cli/recuperar_documentos.php --aplicar --limite=50
 *   php cli/recuperar_documentos.php --aplicar --ids=120,355,406
 *
 * Sin --aplicar no toca nada: solo cuenta y enseña los primeros.
 *
 * Qué toma: los documentos marcados sin archivo por el organizador (o sin
 * ruta) que conserven el mensaje del que salieron y la huella de su XML. Cada
 * adjunto se acepta únicamente si su contenido da esa huella, así que lo que
 * se repone es el mismo archivo, no uno parecido; y vuelve a su ruta de
 * siempre, no a una nueva.
 *
 * Antes de correrlo conviene revisar el disco, para que la marca esté al día:
 *   php cli/organizar_documentos.php --reconciliar
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
require_once $root . '/app/helpers/RecuperadorDocumentos.php';

$aplicar = in_array('--aplicar', $argv, true);
$limite = 0;
$ids = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--limite=') === 0) {
        $limite = max(0, (int) substr($arg, 9));
    } elseif (strpos($arg, '--ids=') === 0) {
        $ids = array_filter(array_map('intval', explode(',', substr($arg, 6))));
    }
}

// Mantenimiento del archivo entero: aquí no hay una empresa "en curso", y lo
// que se perdió hay que reponerlo sea de la sociedad que sea.
$facturas = (new Factura())->setSociedad(0);
$pendientes = $facturas->getRecuperablesDelCorreo($ids, $limite);

if (!$pendientes) {
    echo "No hay documentos sin archivo que se puedan bajar del correo.\n";
    exit(0);
}

echo count($pendientes), " documento(s) se pueden volver a bajar del correo.\n\n";
foreach (array_slice($pendientes, 0, 15) as $documento) {
    printf("  #%-6d %-22s %-10s %s\n",
        (int) $documento['id'],
        (string) $documento['consecutivo_completo'],
        (string) $documento['fecha_emision'],
        mb_strimwidth((string) $documento['proveedor_nombre'], 0, 40, '…'));
}
if (count($pendientes) > 15) {
    echo '  … y ', count($pendientes) - 15, " más\n";
}

if (!$aplicar) {
    echo "\nNada se ha tocado. Agrega --aplicar para reponerlos.\n";
    exit(0);
}

echo "\nBajando del correo…\n";
$resumen = (new RecuperadorDocumentos($facturas))->recuperar($pendientes);

foreach ($resumen['detalle'] as $linea) {
    if ($linea['estado'] === 'recuperado') {
        continue;
    }
    printf("  #%-6d %-16s %s\n", $linea['id'], $linea['estado'], $linea['mensaje']);
}

echo "\n";
printf("Revisados: %d | Repuestos: %d | Ya estaban: %d\n",
    $resumen['revisados'], $resumen['recuperados'], $resumen['ya_estaban']);
printf("Sin el mensaje: %d | Sin coincidencia de huella: %d | Sin buzón: %d | Errores: %d\n",
    $resumen['sin_mensaje'], $resumen['sin_coincidencia'], $resumen['sin_buzon'], $resumen['errores']);

if ($resumen['recuperados'] > 0) {
    echo "\nCorre ahora `php cli/organizar_documentos.php` para que la carpeta"
       . " del pago semanal reciba su copia.\n";
}
