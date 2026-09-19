<?php
/**
 * Lo que la gente hace en la biblioteca de SharePoint desde el navegador, y
 * cómo la base se pone al día sola.
 *
 * Contra la base aislada de certificación y la biblioteca en memoria, que
 * cuenta los cambios como el delta de SharePoint:
 *
 *   - la primera vuelta empieza "desde ahora" y no baja nada;
 *   - mover un documento a mano     → la fila sigue al id, con la ruta nueva;
 *   - borrarlo                      → la fila queda marcada sin archivo;
 *   - restaurarlo de la papelera    → se quita la marca;
 *   - renombrar la carpeta del mes  → SharePoint avisa solo de la carpeta, y
 *                                     aun así las filas de dentro se corrigen;
 *   - editar el XML                 → se avisa, porque ya no es el archivado;
 *   - un enlace de cambios vencido  → se reinicia sin perder nada;
 *   - la carpeta del pago           → se arma una vez, y después no se le
 *                                     vuelve a preguntar a SharePoint;
 *   - lo que no es de ninguna fila (la bandeja) no cuesta llamadas;
 *   - dos PC a la vez               → la segunda se abstiene (candado en la
 *                                     base), para el reconciliador y para el
 *                                     organizador.
 *
 * Deja la base como la encontró.
 *
 * Uso: php tests/ReconciliadorGraphTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/ReconciliadorGraph.php';
require_once __DIR__ . '/../app/helpers/OrganizadorDocumentos.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function paso($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    echo "SKIP: ReconciliadorGraph (sin base de datos disponible)\n";
    exit(0);
}
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
    echo "SKIP: ReconciliadorGraph (la base aislada no tiene database/migration_almacen_graph.sql)\n";
    exit(0);
}
$proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
if ($proveedorId <= 0) {
    echo "SKIP: ReconciliadorGraph (sin proveedores en la base aislada)\n";
    exit(0);
}
if ((int) $pdo->query("SELECT COUNT(*) FROM facturas_xml WHERE almacen IS NOT NULL")->fetchColumn() > 0) {
    // La revisión rotativa pasa por TODAS las filas de SharePoint: con otras
    // en la base, lo que se cuenta aquí no sería solo lo de esta prueba.
    echo "SKIP: ReconciliadorGraph (la base aislada ya tiene documentos en SharePoint de otra prueba)\n";
    exit(0);
}

$biblioteca = new GraphEnMemoria();
$vigente = null;
$nuevoAlmacen = function () use ($biblioteca, &$vigente) {
    // Cada vuelta de la tarea es un proceso nuevo: nada recordado de la
    // anterior. Y es EL almacén 'sharepoint' de ese proceso: lo que se baje
    // por la puerta (RutaDocumento) tiene que venir de aquí y no del
    // SharePoint de verdad, si esta PC tiene local.graph.php.
    $vigente = new AlmacenEnGraph(new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $biblioteca, ['carpeta_estado' => null, 'registro' => null]
    ));
    return $vigente;
};
AlmacenDocumentos::olvidar();
AlmacenDocumentos::registrar('sharepoint', function () use (&$vigente) { return $vigente; });
$vuelta = function ($dryRun = false) use ($nuevoAlmacen) {
    Model::olvidarMemoria();
    return (new ReconciliadorGraph($nuevoAlmacen()))->ejecutar($dryRun);
};
$fila = function ($id) use ($pdo) {
    return $pdo->query('SELECT * FROM facturas_xml WHERE id = ' . (int) $id)->fetch() ?: [];
};

$raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reconciliador_' . bin2hex(random_bytes(4));
mkdir($raiz, 0777, true);
RutaDocumento::fijarRaiz($raiz);
AlmacenDocumentos::fijarCarpetaTemporales($raiz . DIRECTORY_SEPARATOR . '_tmp_almacen');

$marca = strtoupper(bin2hex(random_bytes(3)));
$mes = 'SISTEMA/PRUEBA ' . $marca . '/2026/06 JUNIO';
$facturaIds = [];
$pagoId = 0;
$erpClave = 'REC-' . $marca;
$deltaAntes = $pdo->query("SELECT * FROM almacen_delta WHERE almacen = 'sharepoint'")->fetch();
$temporales = [];

try {
    // ── El escenario: tres facturas archivadas en SharePoint ─────────────
    $almacen = $nuevoAlmacen();
    $docs = [];
    foreach (['A', 'B', 'C'] as $letra) {
        $par = [];
        foreach (['xml' => '<FE>' . $letra . $marca . '</FE>', 'pdf' => '%PDF ' . $letra . $marca] as $ext => $contenido) {
            $local = tempnam(sys_get_temp_dir(), 'rec_');
            file_put_contents($local, $contenido);
            $temporales[] = $local;
            $par[$ext] = $almacen->escribir($mes . '/Facturas/FE_' . $letra . '.' . $ext, $local);
        }
        $pdo->prepare("INSERT INTO facturas_xml
                (consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision, tipo_documento,
                 ruta_xml, archivo_xml, ruta_pdf, archivo_pdf, hash_xml, hash_pdf,
                 almacen, item_xml_id, item_pdf_id, etag_xml, etag_pdf)
                VALUES (?, ?, ?, '2026-06-07', 'FE', ?, ?, ?, ?, ?, ?, 'sharepoint', ?, ?, ?, ?)")
            ->execute(['REC' . $marca . $letra, substr($letra . $marca, 0, 8), $proveedorId,
                $par['xml']['ruta'], 'FE_' . $letra . '.xml', $par['pdf']['ruta'], 'FE_' . $letra . '.pdf',
                $par['xml']['hash'], $par['pdf']['hash'],
                $par['xml']['item_id'], $par['pdf']['item_id'], $par['xml']['etag'], $par['pdf']['etag']]);
        $docs[$letra] = (int) $pdo->lastInsertId();
        $facturaIds[] = $docs[$letra];
    }

    // ── 1. La primera vuelta ─────────────────────────────────────────────
    $antes = count($biblioteca->registro);
    $r = $vuelta();
    $llamadas = array_slice($biblioteca->registro, $antes);
    paso($r['delta_iniciado'] && $r['cambios_leidos'] === 0,
        'la primera vuelta empieza desde ahora, sin enumerar la biblioteca: ' . json_encode($r));
    paso($r['documentos_revisados'] === 3 && $r['rutas_actualizadas'] === 0 && $r['marcados_sin_archivo'] === 0,
        'la revisión rotativa repasa las tres y no encuentra nada que corregir: ' . json_encode($r));
    paso(!preg_grep('~/content~', $llamadas), 'y no baja ni un archivo: ' . json_encode($llamadas));
    paso(count(preg_grep('~^POST /\$batch~', $llamadas)) === 1,
        'las seis consultas por id van en un solo lote: ' . json_encode($llamadas));
    $delta = $pdo->query("SELECT * FROM almacen_delta WHERE almacen = 'sharepoint'")->fetch();
    paso($delta && strpos((string) $delta['delta_link'], 'token=') !== false && $delta['drive_id'] === 'd',
        'el enlace de cambios queda en la base, para todas las PC');

    // ── 2. Una vuelta sin novedades ──────────────────────────────────────
    // Lo que entra a la bandeja cambia la biblioteca, pero no es de ninguna
    // fila: no debe costar consultas.
    $local = tempnam(sys_get_temp_dir(), 'rec_');
    file_put_contents($local, '<FE>bandeja</FE>');
    $temporales[] = $local;
    $nuevoAlmacen()->escribir('_TRABAJO/BANDEJA/xml/2026-09/X' . $marca . '.xml', $local);
    $antes = count($biblioteca->registro);
    $r = $vuelta();
    $llamadas = array_slice($biblioteca->registro, $antes);
    paso($r['cambios_leidos'] >= 1 && $r['carpetas_recorridas'] === 0 && $r['rutas_actualizadas'] === 0,
        'la bandeja cambia la biblioteca y no hay nada que seguir: ' . json_encode($r));
    paso(!preg_grep('~/children|/content~', $llamadas), 'ni se recorre ni se baja nada por ella: ' . json_encode($llamadas));

    // ── 3. Alguien mueve el XML de A ─────────────────────────────────────
    $claveA = substr($fila($docs['A'])['ruta_xml'], strlen('sharepoint:'));
    $idA = $biblioteca->moverAMano($claveA, 'SISTEMA/PRUEBA ' . $marca . '/REVISAR');
    $r = $vuelta();
    $a = $fila($docs['A']);
    paso($a['ruta_xml'] === 'sharepoint:SISTEMA/PRUEBA ' . $marca . '/REVISAR/FE_A.xml' && $a['item_xml_id'] === $idA,
        'la fila de A sigue a su id hasta la carpeta nueva: ' . $a['ruta_xml']);
    paso($r['rutas_actualizadas'] === 1 && empty($a['archivo_faltante_en']),
        'y no se lo da por perdido: ' . json_encode($r));

    // ── 4. Alguien borra el PDF de B, y después lo restaura ─────────────
    $idB = $biblioteca->borrarAMano(substr($fila($docs['B'])['ruta_pdf'], strlen('sharepoint:')));
    $r = $vuelta();
    paso(!empty($fila($docs['B'])['archivo_faltante_en']) && $r['marcados_sin_archivo'] === 1,
        'B queda marcada sin archivo, y el listado lo va a decir: ' . json_encode($r));
    $biblioteca->restaurarAMano($idB);
    $r = $vuelta();
    paso(empty($fila($docs['B'])['archivo_faltante_en']) && $r['desmarcados'] === 1,
        'restaurado de la papelera, la marca se quita sola: ' . json_encode($r));

    // ── 5. Alguien renombra la carpeta del mes ───────────────────────────
    $biblioteca->renombrarAMano($mes, '06 JUNIO revisado');
    $r = $vuelta();
    $c = $fila($docs['C']);
    $nuevoMes = 'sharepoint:SISTEMA/PRUEBA ' . $marca . '/2026/06 JUNIO revisado/Facturas/';
    paso(strpos($c['ruta_xml'], $nuevoMes) === 0 && strpos($c['ruta_pdf'], $nuevoMes) === 0
        && strpos($fila($docs['A'])['ruta_pdf'], $nuevoMes) === 0,
        'SharePoint avisó solo de la carpeta, y aun así las filas de dentro se corrigen: ' . $c['ruta_xml']);
    paso($r['carpetas_recorridas'] >= 1 && $r['rutas_actualizadas'] === 5,
        'los cinco archivos que quedaban en el mes: ' . json_encode($r));
    paso(RutaDocumento::existe($c['ruta_xml']) || $biblioteca->contenido(substr($c['ruta_xml'], 11)) !== null,
        'y la ruta nueva lleva de verdad al documento');

    // ── 6. Alguien edita el XML de C ─────────────────────────────────────
    $biblioteca->editarAMano(substr($c['ruta_xml'], 11), '<FE>otra cosa</FE>');
    $r = $vuelta();
    paso($r['editados'] === 1, 'se avisa que el XML ya no es el que se archivó: ' . json_encode($r));
    $r = $vuelta();
    paso($r['editados'] === 0, 'una sola vez: el cTag nuevo queda anotado');

    // ── 7. La carpeta del pago ───────────────────────────────────────────
    $pdo->prepare("INSERT INTO pagos (nombre, carpeta_pago) VALUES (?, ?)")
        ->execute(['Pago ' . $marca, 'Pago ' . $marca]);
    $pagoId = (int) $pdo->lastInsertId();
    $pdo->exec('UPDATE facturas_xml SET pago_id = ' . $pagoId . ' WHERE id = ' . $docs['B']);
    $pdo->prepare("INSERT INTO facturas_erp (clave, proveedor_codigo, proveedor_nombre, factura_xml_id, estado_respaldo, pago_id)
                   VALUES (?, 'REC', 'PRUEBA', ?, 'respaldada', ?)")
        ->execute([$erpClave, $docs['B'], $pagoId]);

    // Lo que ve quien arma el pago en la pantalla: la copia no se hace ahí.
    $organizador = new OrganizadorDocumentos($raiz);
    $enPantalla = $organizador->organizarIds([$docs['B']], false);
    paso((int) ($enPantalla['copias_pago_en_espera'] ?? -1) === 1,
        'la pantalla del pago dice que la copia de SharePoint llega con la tarea: ' . json_encode($enPantalla));

    $r = $vuelta();
    paso($r['copias_pago'] === 1, 'la tarea arma la copia del pago: ' . json_encode($r));
    paso($biblioteca->contenido('PAGOS/Pago ' . $marca . '/' . basename(str_replace('\\', '/',
            (string) $pdo->query('SELECT ruta_xml FROM almacen_copias_pago WHERE factura_id = ' . $docs['B'])->fetchColumn())))
        === '<FE>B' . $marca . '</FE>', 'en la carpeta del pago, en SharePoint, con el contenido del original');
    $antes = count($biblioteca->registro);
    $r = $vuelta();
    $llamadas = array_slice($biblioteca->registro, $antes);
    paso($r['copias_pago_vigentes'] === 1 && $r['copias_pago'] === 0
        && !preg_grep('~PAGOS~', array_map('rawurldecode', $llamadas)),
        'la vuelta siguiente no le vuelve a preguntar a SharePoint por la copia: ' . json_encode($llamadas));

    // ── 8. El enlace de cambios vence ────────────────────────────────────
    $biblioteca->enlaceVencido = true;
    $r = $vuelta();
    $biblioteca->enlaceVencido = false;
    paso($r['delta_reiniciado'] && $r['errores'] === 0, 'un enlace vencido se reinicia sin error: ' . json_encode($r));
    $r = $vuelta();
    paso(!$r['delta_reiniciado'] && $r['errores'] === 0, 'y la vuelta siguiente sigue normal');

    // ── 9. Mirar sin tocar ───────────────────────────────────────────────
    $biblioteca->moverAMano(substr($fila($docs['A'])['ruta_xml'], 11), 'SISTEMA/PRUEBA ' . $marca . '/OTRA');
    $antesDeMirar = $fila($docs['A'])['ruta_xml'];
    $enlaceAntes = $pdo->query("SELECT delta_link FROM almacen_delta WHERE almacen = 'sharepoint'")->fetchColumn();
    $r = $vuelta(true);
    paso($r['rutas_actualizadas'] === 1 && $fila($docs['A'])['ruta_xml'] === $antesDeMirar
        && $pdo->query("SELECT delta_link FROM almacen_delta WHERE almacen = 'sharepoint'")->fetchColumn() === $enlaceAntes,
        'con --dry-run cuenta lo que haría y no toca la base ni el enlace');

    // ── 10. Dos PC a la vez ──────────────────────────────────────────────
    // Otra conexión es otra PC: GET_LOCK es de la conexión.
    $pdo->query("SELECT GET_LOCK(LEFT(CONCAT(DATABASE(), '.reconciliador_sharepoint'), 64), 0)")->fetchColumn();
    $r = $vuelta();
    paso(!empty($r['omitido_por_bloqueo']), 'si otra PC está revisando la biblioteca, esta se abstiene');
    $pdo->query("SELECT RELEASE_LOCK(LEFT(CONCAT(DATABASE(), '.reconciliador_sharepoint'), 64))")->fetchColumn();
    $r = $vuelta();
    paso(empty($r['omitido_por_bloqueo']) && $r['rutas_actualizadas'] === 1,
        'y cuando termina, la siguiente vuelta hace lo pendiente');

    $pdo->query("SELECT GET_LOCK(LEFT(CONCAT(DATABASE(), '.organizador_documentos'), 64), 0)")->fetchColumn();
    $otro = $organizador->organizarIds([$docs['B']], false);
    paso(!empty($otro['omitido_por_bloqueo']), 'el organizador también respeta el candado de las otras PC');
    $pdo->query("SELECT RELEASE_LOCK(LEFT(CONCAT(DATABASE(), '.organizador_documentos'), 64))")->fetchColumn();
    $otro = $organizador->organizarIds([$docs['B']], false);
    paso(empty($otro['omitido_por_bloqueo']), 'y lo retoma cuando se suelta');
} catch (Throwable $e) {
    $fallas[] = 'se cortó la prueba: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
} finally {
    foreach ($facturaIds as $id) {
        $pdo->exec('DELETE FROM almacen_copias_pago WHERE factura_id = ' . (int) $id);
        $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . (int) $id);
    }
    $pdo->prepare('DELETE FROM facturas_erp WHERE clave = ?')->execute([$erpClave]);
    if ($pagoId > 0) {
        $pdo->exec('DELETE FROM pagos WHERE id = ' . $pagoId);
    }
    $pdo->exec("DELETE FROM almacen_delta WHERE almacen = 'sharepoint'");
    if ($deltaAntes) {
        $pdo->prepare('INSERT INTO almacen_delta (' . implode(',', array_keys($deltaAntes)) . ') VALUES ('
            . implode(',', array_fill(0, count($deltaAntes), '?')) . ')')->execute(array_values($deltaAntes));
    }
    AlmacenDocumentos::olvidar();
    AlmacenDocumentos::fijarCarpetaTemporales(null);
    RutaDocumento::olvidarRaiz();
    foreach ($temporales as $t) {
        @unlink($t);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($raiz);
}

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: la base sigue a los documentos de SharePoint por su id, y lo que pasa en la biblioteca se ve solo\n";
