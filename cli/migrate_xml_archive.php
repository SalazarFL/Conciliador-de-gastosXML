<?php
/** Migra XML históricos desde facturas_xml.xml_contenido al árbol local. */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

$dryRun = in_array('--dry-run', $argv, true);
$raiz = DocumentoArchivo::raizConfigurada();
if ($raiz === '') { fwrite(STDERR, "No hay carpeta raíz configurada.\n"); exit(2); }

$archivo = new DocumentoArchivo($raiz);
$facturas = new Factura();
$tempDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'migration_tmp';
if (!is_dir($tempDir) && !mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "No se pudo crear el directorio temporal.\n"); exit(2);
}

$resumen = ['pendientes_iniciales' => $facturas->contarContenidoEnBase(), 'migrados' => 0, 'errores' => 0, 'detalle' => []];
if ($dryRun) {
    echo json_encode(array_merge($resumen, ['dry_run' => true, 'raiz' => $raiz]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

while (true) {
    $lote = $facturas->getConContenidoParaMigrar(50);
    if (!$lote) { break; }
    foreach ($lote as $fila) {
        $tmp = $tempDir . DIRECTORY_SEPARATOR . 'xml_' . (int) $fila['id'] . '_' . bin2hex(random_bytes(4)) . '.xml';
        $archivado = [];
        try {
            $contenido = (string) $fila['xml_contenido'];
            if ($contenido === '') { throw new RuntimeException('Contenido vacío.'); }
            libxml_use_internal_errors(true);
            if (simplexml_load_string($contenido) === false) { throw new RuntimeException('El contenido histórico no es XML válido.'); }
            $hashContenido = hash('sha256', $contenido);
            if (!empty($fila['hash_xml']) && !hash_equals((string) $fila['hash_xml'], $hashContenido)) {
                throw new RuntimeException('El hash guardado no coincide con el contenido histórico.');
            }
            if (file_put_contents($tmp, $contenido, LOCK_EX) !== strlen($contenido)) {
                throw new RuntimeException('No se pudo preparar el XML temporal.');
            }
            $fila['tipo_documento'] = $fila['tipo_documento'] ?: 'FE';
            $archivado = $archivo->archivar($tmp, null, $fila, (string) ($fila['proveedor_nombre'] ?? 'PROVEEDOR'));
            if (!is_file($archivado['ruta_xml']) || !hash_equals($hashContenido, hash_file('sha256', $archivado['ruta_xml']))) {
                throw new RuntimeException('La copia final no superó la validación SHA-256.');
            }

            $facturas->begin();
            $afectadas = $facturas->confirmarMigracionArchivo((int) $fila['id'], $archivado);
            if ($afectadas !== 1) { throw new RuntimeException('La fila cambió durante la migración.'); }
            $facturas->commit();
            $resumen['migrados']++;
        } catch (Throwable $e) {
            $facturas->rollback();
            DocumentoArchivo::eliminarCreados($archivado);
            $resumen['errores']++;
            $resumen['detalle'][] = ['id' => (int) $fila['id'], 'error' => $e->getMessage()];
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }
    if ($resumen['errores'] > 0) { break; } // conserva blobs y evita repetir indefinidamente
}

$resumen['pendientes_finales'] = $facturas->contarContenidoEnBase();
$resumen['raiz'] = $raiz;
echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($resumen['errores'] > 0 ? 1 : 0);
