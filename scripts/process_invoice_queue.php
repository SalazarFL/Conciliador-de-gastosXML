<?php
/**
 * Worker CLI para procesar la cola de importacion de facturas.
 *
 * Uso:
 * php scripts/process_invoice_queue.php --importacion=123 --limit=10
 * php scripts/process_invoice_queue.php --limit=10
 */

define('ROOT_PATH', dirname(__DIR__));
define('BASE_PATH', ROOT_PATH);

require_once ROOT_PATH . '/app/core/Model.php';
require_once ROOT_PATH . '/app/helpers/InvoiceImportQueue.php';

$options = getopt('', ['importacion::', 'limit::']);
$importacionId = isset($options['importacion']) ? (int) $options['importacion'] : 0;
$limit = isset($options['limit']) ? max(1, min(50, (int) $options['limit'])) : 10;

try {
    $service = new InvoiceImportQueue();
    $result = $importacionId > 0
        ? $service->procesarBatch($importacionId, $limit)
        : $service->procesarPendientesGlobal($limit);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
